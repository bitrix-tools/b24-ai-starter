<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Frontend;

use App\Service\JwtService;
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Тесты HTTP-эндпоинта POST /api/telemetry/event (Sprint 8, Step 8.3).
 *
 * Проверяет:
 * - 204 при валидном JWT + корректном теле
 * - 401 при отсутствии или невалидном JWT
 * - 400 при неизвестном event_name (не в whitelist)
 * - 400 при превышении лимита атрибутов (>30)
 * - 400 при некорректном JSON
 * - 204 (а не 500) если trackEvent() бросил исключение
 * - trackEvent() вызывается с нужными обогащёнными атрибутами
 */
class TelemetryControllerTest extends WebTestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Создать тестовый JWT с помощью реального JwtService из контейнера.
     * Принимает уже созданный $client, чтобы не бутить ядро дважды.
     */
    private function makeJwt(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $domain = 'test.bitrix24.ru',
        string $memberId = 'member-test-001',
    ): string {
        /** @var JwtService $jwtService */
        $jwtService = $client->getContainer()->get(JwtService::class);

        return $jwtService->generateToken($domain, $memberId);
    }

    /**
     * Заменить TelemetryInterface в контейнере на заданный mock/stub.
     *
     * Должно вызываться ПОСЛЕ createClient() и ДО request().
     *
     * @return MockObject&TelemetryInterface
     */
    private function injectTelemetryMock(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        bool $shouldTrackBeCalled = false,
        ?string $expectedEventName = null,
    ): MockObject {
        /** @var MockObject&TelemetryInterface $mock */
        $mock = $this->createMock(TelemetryInterface::class);
        $mock->method('isEnabled')->willReturn(true);

        if ($shouldTrackBeCalled && $expectedEventName !== null) {
            $mock->expects($this->once())
                ->method('trackEvent')
                ->with(
                    $this->equalTo($expectedEventName),
                    $this->arrayHasKey('event.source'),
                );
        } elseif (!$shouldTrackBeCalled) {
            $mock->expects($this->never())->method('trackEvent');
        }

        $client->getContainer()->set(TelemetryInterface::class, $mock);

        return $mock;
    }

    // ------------------------------------------------------------------
    // 204 No Content — happy path
    // ------------------------------------------------------------------

    #[Test]
    public function returns204_validJwtAndKnownEvent(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: true, expectedEventName: 'page_view');

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'event_name' => 'page_view',
                'attributes' => ['ui.path' => '/crm/leads'],
                'client_timestamp_ms' => 1740000000000,
            ]),
        );

        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function returns204_noAttributesOrTimestamp(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: true, expectedEventName: 'app_frame_loaded');

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['event_name' => 'app_frame_loaded']),
        );

        self::assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function enrichedAttributes_containEventSourceFrontend(): void
    {
        $client = static::createClient();

        /** @var MockObject&TelemetryInterface $mock */
        $mock = $this->createMock(TelemetryInterface::class);
        $mock->method('isEnabled')->willReturn(true);

        $capturedAttributes = [];
        $mock->expects($this->once())
            ->method('trackEvent')
            ->willReturnCallback(function (string $name, array $attrs) use (&$capturedAttributes): void {
                $capturedAttributes = $attrs;
            });

        $client->getContainer()->set(TelemetryInterface::class, $mock);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client, 'portal.bitrix24.ru', 'mem-42'),
                'HTTP_X_SESSION_ID' => 'sess-abc123',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'event_name' => 'ui_button_click',
                'attributes' => ['ui.component' => 'btn-save'],
                'client_timestamp_ms' => 1740000000001,
            ]),
        );

        self::assertResponseStatusCodeSame(204);
        self::assertSame('frontend', $capturedAttributes['event.source'] ?? '');
        self::assertSame('btn-save', $capturedAttributes['ui.component'] ?? '');
        self::assertSame('portal.bitrix24.ru', $capturedAttributes['portal.domain'] ?? '');
        self::assertSame('mem-42', $capturedAttributes['portal.member_id'] ?? '');
        self::assertSame('1740000000001', $capturedAttributes['client.timestamp_ms'] ?? '');
    }

    // ------------------------------------------------------------------
    // 401 Unauthorized — JWT validation
    // ------------------------------------------------------------------

    #[Test]
    public function returns401_noAuthorizationHeader(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['event_name' => 'page_view']),
        );

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function returns401_invalidJwt(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer this.is.invalid.jwt.token',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['event_name' => 'page_view']),
        );

        self::assertResponseStatusCodeSame(401);
    }

    // ------------------------------------------------------------------
    // 400 Bad Request — payload validation
    // ------------------------------------------------------------------

    #[Test]
    public function returns400_unknownEventName(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['event_name' => 'custom_hack_event']),
        );

        self::assertResponseStatusCodeSame(400);

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertStringContainsString('Unknown event name', $body['error'] ?? '');
    }

    #[Test]
    public function returns400_tooManyAttributes(): void
    {
        $attributes = [];
        for ($i = 1; $i <= 31; ++$i) {
            $attributes['attr.' . $i] = 'value';
        }

        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'event_name' => 'page_view',
                'attributes' => $attributes,
            ]),
        );

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function returns400_invalidJson(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: '{not valid json',
        );

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function returns400_missingEventName(): void
    {
        $client = static::createClient();
        $this->injectTelemetryMock($client, shouldTrackBeCalled: false);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['attributes' => ['ui.path' => '/test']]),
        );

        self::assertResponseStatusCodeSame(400);
    }

    // ------------------------------------------------------------------
    // Telemetry failure resilience
    // ------------------------------------------------------------------

    #[Test]
    public function returns204_evenIfTrackEventThrows(): void
    {
        $client = static::createClient();

        /** @var MockObject&TelemetryInterface $mock */
        $mock = $this->createMock(TelemetryInterface::class);
        $mock->method('isEnabled')->willReturn(true);
        $mock->method('trackEvent')->willThrowException(new \RuntimeException('OTel collector unreachable'));

        $client->getContainer()->set(TelemetryInterface::class, $mock);

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->makeJwt($client),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['event_name' => 'page_view']),
        );

        // Телеметрия упала — но UI не должен получить 500
        self::assertResponseStatusCodeSame(204);
    }
}
