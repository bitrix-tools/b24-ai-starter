<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\EventSubscriber\TelemetryRequestSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Тесты context propagation (session.id) (Sprint 5, Step 5.7).
 *
 * Проверяет TelemetryRequestSubscriber:
 * - session.id устанавливается в request attributes
 * - Заголовок X-Session-ID имеет приоритет
 * - Генерируется уникальный UUID v4 при отсутствии заголовка
 * - Sub-requests игнорируются (не получают session.id от subscriber)
 * - Интеграционно: health эндпоинт доступен с заголовком сессии
 */
class SessionContextTest extends TestCase
{
    private function makeEvent(
        Request $request,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, $requestType);
    }

    // ------------------------------------------------------------------
    // TelemetryRequestSubscriber
    // ------------------------------------------------------------------

    #[Test]
    public function sessionIdSetFromXSessionIdHeader(): void
    {
        // Arrange
        $request = Request::create('/api/health');
        $request->headers->set('X-Session-ID', 'my-frontend-session-123');

        $subscriber = new TelemetryRequestSubscriber();

        // Act
        $subscriber->onKernelRequest($this->makeEvent($request));

        // Assert
        $this->assertSame('my-frontend-session-123', $request->attributes->get('telemetry_session_id'));
    }

    #[Test]
    public function sessionIdGeneratedWhenHeaderAbsent(): void
    {
        // Arrange
        $request = Request::create('/api/health');

        $subscriber = new TelemetryRequestSubscriber();

        // Act
        $subscriber->onKernelRequest($this->makeEvent($request));

        // Assert — атрибут установлен
        $sessionId = $request->attributes->get('telemetry_session_id');
        $this->assertNotNull($sessionId);
        $this->assertNotEmpty($sessionId);
    }

    #[Test]
    public function generatedSessionIdIsUuidV4(): void
    {
        // Arrange
        $request = Request::create('/api/health');
        $subscriber = new TelemetryRequestSubscriber();

        // Act
        $subscriber->onKernelRequest($this->makeEvent($request));

        // Assert — валидный UUID v4 формат
        $sessionId = $request->attributes->get('telemetry_session_id');
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($uuidPattern, $sessionId);
    }

    #[Test]
    public function eachRequestGetsUniqueSessionId(): void
    {
        // Два разных запроса без заголовка должны получить разные session.id
        $subscriber = new TelemetryRequestSubscriber();

        $request1 = Request::create('/api/health');
        $request2 = Request::create('/api/list');

        $subscriber->onKernelRequest($this->makeEvent($request1));
        $subscriber->onKernelRequest($this->makeEvent($request2));

        $sessionId1 = $request1->attributes->get('telemetry_session_id');
        $sessionId2 = $request2->attributes->get('telemetry_session_id');

        $this->assertNotSame($sessionId1, $sessionId2);
    }

    #[Test]
    public function subRequestsAreIgnored(): void
    {
        // Arrange — sub-request не должен получать session.id от subscriber
        $request = Request::create('/api/health');
        $subscriber = new TelemetryRequestSubscriber();

        // Act — тип SUB_REQUEST
        $subscriber->onKernelRequest(
            $this->makeEvent($request, HttpKernelInterface::SUB_REQUEST)
        );

        // Assert — атрибут НЕ должен быть установлен subscriber'ом
        $this->assertNull($request->attributes->get('telemetry_session_id'));
    }

    #[Test]
    public function emptyXSessionIdHeaderTriggersGeneration(): void
    {
        // Пустой заголовок = нет значения, должен сгенерировать новый ID
        $request = Request::create('/api/health');
        $request->headers->set('X-Session-ID', '');

        $subscriber = new TelemetryRequestSubscriber();
        $subscriber->onKernelRequest($this->makeEvent($request));

        $sessionId = $request->attributes->get('telemetry_session_id');
        $this->assertNotEmpty($sessionId);
        // Пустой заголовок не должен быть записан как session.id
        $this->assertNotSame('', $sessionId);
    }

    #[Test]
    public function subscriberListensToKernelRequestEvent(): void
    {
        // Проверяем, что subscriber подписан на правильное событие
        $subscribedEvents = TelemetryRequestSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('kernel.request', $subscribedEvents);
    }
}

/**
 * Интеграционные тесты session context через WebTestCase.
 */
class SessionContextWebTest extends WebTestCase
{
    #[Test]
    public function sessionIdHeaderAcceptedByHealthEndpoint(): void
    {
        $client = static::createClient();
        $sessionId = 'integration-session-' . uniqid();

        $client->request('GET', '/api/health', [], [], [
            'HTTP_X_SESSION_ID' => $sessionId,
        ]);

        // Assert — запрос выполнился успешно, заголовок не мешает
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function requestWithoutSessionIdAlsoWorks(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health');

        // Assert — работает и без заголовка (subscriber генерирует ID автоматически)
        $this->assertResponseIsSuccessful();
    }
}
