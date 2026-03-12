<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Integration;

use App\Service\Telemetry\SessionContextTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Тесты UI событий и SessionContextTrait (Sprint 5, Step 5.3).
 *
 * Проверяет:
 * - SessionContextTrait корректно определяет session.id
 * - Приоритеты источников session.id (attribute > header > generate)
 * - Генерируемый session.id является валидным UUID v4
 * - API эндпоинты работают корректно с телеметрией UI
 */
class UIEventsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Анонимный класс-помощник для тестирования trait
    // ------------------------------------------------------------------

    private function makeTraitInstance(): object
    {
        return new class () {
            use SessionContextTrait;

            public function publicGetSessionId(Request $request): string
            {
                return $this->getSessionId($request);
            }

            public function publicGetMemberId(Request $request): string
            {
                return $this->getMemberIdFromRequest($request);
            }

            public function publicGetDomain(Request $request): string
            {
                return $this->getDomainFromRequest($request);
            }
        };
    }

    // ------------------------------------------------------------------
    // SessionContextTrait: источники session.id
    // ------------------------------------------------------------------

    #[Test]
    public function attributeSessionIdTakesPriorityOverHeader(): void
    {
        // Arrange
        $request = Request::create('/api/list');
        $request->attributes->set('telemetry_session_id', 'attr-session-123');
        $request->headers->set('X-Session-ID', 'header-session-456');

        // Act
        $sessionId = $this->makeTraitInstance()->publicGetSessionId($request);

        // Assert — атрибут должен иметь приоритет
        $this->assertSame('attr-session-123', $sessionId);
    }

    #[Test]
    public function headerSessionIdUsedWhenNoAttribute(): void
    {
        // Arrange — только заголовок, без атрибута
        $request = Request::create('/api/list');
        $request->headers->set('X-Session-ID', 'header-session-xyz');

        // Act
        $sessionId = $this->makeTraitInstance()->publicGetSessionId($request);

        // Assert
        $this->assertSame('header-session-xyz', $sessionId);
    }

    #[Test]
    public function newSessionIdGeneratedWhenNoSourceAvailable(): void
    {
        // Arrange — нет ни атрибута, ни заголовка
        $request = Request::create('/api/list');

        // Act
        $sessionId = $this->makeTraitInstance()->publicGetSessionId($request);

        // Assert — должен быть сгенерирован непустой ID
        $this->assertNotEmpty($sessionId);
    }

    #[Test]
    public function generatedSessionIdIsValidUuidV4Format(): void
    {
        // Arrange
        $request = Request::create('/api/list');

        // Act — два разных запроса дают разные ID
        $sessionId1 = $this->makeTraitInstance()->publicGetSessionId($request);
        $sessionId2 = $this->makeTraitInstance()->publicGetSessionId($request);

        // Assert — UUID v4 формат
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($uuidPattern, $sessionId1);
        $this->assertMatchesRegularExpression($uuidPattern, $sessionId2);

        // И они уникальны
        $this->assertNotSame($sessionId1, $sessionId2);
    }

    #[Test]
    public function memberIdExtractedFromJwtPayload(): void
    {
        // Arrange
        $request = Request::create('/api/list');
        $request->attributes->set('jwt_payload', [
            'member_id' => 'abc123-member',
            'domain' => 'test.bitrix24.ru',
        ]);

        // Act
        $memberId = $this->makeTraitInstance()->publicGetMemberId($request);

        // Assert
        $this->assertSame('abc123-member', $memberId);
    }

    #[Test]
    public function domainExtractedFromJwtPayload(): void
    {
        // Arrange
        $request = Request::create('/api/list');
        $request->attributes->set('jwt_payload', [
            'member_id' => 'abc123',
            'domain' => 'example.bitrix24.ru',
        ]);

        // Act
        $domain = $this->makeTraitInstance()->publicGetDomain($request);

        // Assert
        $this->assertSame('example.bitrix24.ru', $domain);
    }

    #[Test]
    public function emptyStringReturnedWhenJwtPayloadMissing(): void
    {
        // Arrange — нет jwt_payload в атрибутах
        $request = Request::create('/api/health');

        // Act
        $instance = $this->makeTraitInstance();
        $memberId = $instance->publicGetMemberId($request);
        $domain = $instance->publicGetDomain($request);

        // Assert — пустые строки (не null, не исключение)
        $this->assertSame('', $memberId);
        $this->assertSame('', $domain);
    }

    #[Test]
    public function emptyAttributeSessionIdFallsBackToHeader(): void
    {
        // Arrange — пустой атрибут должен использовать заголовок
        $request = Request::create('/api/list');
        $request->attributes->set('telemetry_session_id', '');
        $request->headers->set('X-Session-ID', 'fallback-header-session');

        // Act
        $sessionId = $this->makeTraitInstance()->publicGetSessionId($request);

        // Assert
        $this->assertSame('fallback-header-session', $sessionId);
    }
}

/**
 * Web тесты UI эндпоинтов с телеметрией.
 */
class UIEventsWebTest extends WebTestCase
{
    #[Test]
    public function apiListEndpointWorksWithTelemetry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/list', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid',
        ]);

        // Assert — 401 ожидается (JWT невалидный), но не 500 (телеметрия не ломает)
        $this->assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function healthEndpointWorksWithTelemetry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        // Assert — health публичный эндпоинт
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function sessionIdHeaderPassedThroughRequest(): void
    {
        $client = static::createClient();
        $sessionId = 'test-session-'.uniqid();

        $client->request('GET', '/api/health', [], [], [
            'HTTP_X_SESSION_ID' => $sessionId,
        ]);

        // Assert — запрос выполнился успешно (заголовок не ломает ничего)
        $this->assertResponseIsSuccessful();
    }
}
