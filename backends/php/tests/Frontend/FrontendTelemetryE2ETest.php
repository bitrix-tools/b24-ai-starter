<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Frontend;

use App\Service\JwtService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E-тест эндпоинта POST /api/telemetry/event (Sprint 8, Step 8.4).
 *
 * Требует запущенной инфраструктуры b24-ai-starter-otel:
 *   cd ../b24-ai-starter-otel && make up
 *
 * Что проверяется:
 * - Счастливый путь: событие попадает в ClickHouse (SpanName = event_name)
 * - Whitelist-блокировка (400) — в ClickHouse ничего не добавляется
 * - Отсутствие JWT (401) — в ClickHouse ничего не добавляется
 *
 * Запуск:
 *   make test-telemetry-frontend-e2e
 *
 * @group e2e
 */
#[Group('e2e')]
class FrontendTelemetryE2ETest extends WebTestCase
{
    private const string CLICKHOUSE_HOSTS = 'host.docker.internal:8123';
    private const int POLL_INTERVAL_MS    = 500;
    private const int POLL_MAX_ATTEMPTS   = 24;  // 24 × 500 ms = 12 s

    private static ?string $clickhouseHost = null;

    // ------------------------------------------------------------------
    // Bootstrap
    // ------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        foreach (explode(',', self::CLICKHOUSE_HOSTS) as $host) {
            $host = trim($host);
            $url = "http://{$host}/ping";

            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $body = @file_get_contents($url, false, $ctx);

            if ($body !== false && str_contains($body, 'Ok')) {
                self::$clickhouseHost = $host;
                break;
            }
        }

        // Note: If ClickHouse is not reachable, $clickhouseHost will be null
        // and each test will be skipped in setUp() method
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$clickhouseHost === null) {
            self::markTestSkipped('ClickHouse is not reachable — E2E test skipped.');
        }
    }

    // ------------------------------------------------------------------
    // Happy path
    // ------------------------------------------------------------------

    #[Test]
    public function happyPath_eventAppearsInClickHouse(): void
    {
        // Уникальное событие для этого запуска, чтобы не пересекаться с другими тестами
        $uniqueSuffix = uniqid('e2e_', true);
        $customAttribute = 'e2e.test.run_id';
        $customValue = $uniqueSuffix;

        $client = static::createClient();
        $jwt = $this->generateJwt($client, 'e2e-portal.bitrix24.ru', 'e2e-member-001');

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$jwt}",
                'HTTP_X_SESSION_ID'  => 'e2e-session-' . $uniqueSuffix,
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode([
                'event_name' => 'page_view',
                'attributes' => [
                    $customAttribute  => $customValue,
                    'ui.path'         => '/e2e-test',
                ],
                'client_timestamp_ms' => (int) (microtime(true) * 1000),
            ]),
        );

        self::assertResponseStatusCodeSame(204, 'Endpoint should return 204 for valid frontend event');

        // Ждём появления события в ClickHouse с повторными попытками
        $sql = sprintf(
            "SELECT COUNT(*) FROM telemetry.otel_traces WHERE SpanName = 'page_view' AND mapContains(SpanAttributes, '%s') AND SpanAttributes['%s'] = '%s'",
            $customAttribute,
            $customAttribute,
            $customValue
        );

        $found = $this->pollClickHouse($sql, expectedCount: 1);

        self::assertTrue(
            $found,
            sprintf(
                'Expected page_view event with %s=%s to appear in ClickHouse within %d seconds',
                $customAttribute,
                $customValue,
                (self::POLL_MAX_ATTEMPTS * self::POLL_INTERVAL_MS) / 1000
            )
        );
    }

    // ------------------------------------------------------------------
    // 400 — whitelist blocker: ничего не попадает в ClickHouse
    // ------------------------------------------------------------------

    #[Test]
    public function whitelistBlocker_nothingAppearsInClickHouse(): void
    {
        $blockedEvent = 'custom_hack_event_' . uniqid();

        $client = static::createClient();
        $jwt = $this->generateJwt($client, 'e2e-portal.bitrix24.ru');

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: [
                'HTTP_AUTHORIZATION' => "Bearer {$jwt}",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: json_encode([
                'event_name' => $blockedEvent,
                'attributes' => ['e2e.blocked' => 'yes'],
            ]),
        );

        self::assertResponseStatusCodeSame(400);

        // Проверяем, что в ClickHouse нет этого события
        $sql = sprintf(
            "SELECT COUNT(*) FROM telemetry.otel_traces WHERE SpanName = '%s'",
            $blockedEvent
        );

        $count = $this->queryClickHouseCount($sql);
        self::assertSame(0, $count, 'Rejected event must not appear in ClickHouse');
    }

    // ------------------------------------------------------------------
    // 401 — нет JWT: ничего не попадает в ClickHouse
    // ------------------------------------------------------------------

    #[Test]
    public function noJwt_nothingAppearsInClickHouse(): void
    {
        $uniqueAttr = 'e2e.no_auth.' . uniqid();

        $client = static::createClient();

        $client->request(
            method: 'POST',
            uri: '/api/telemetry/event',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'event_name' => 'page_view',
                'attributes' => [$uniqueAttr => 'should_not_exist'],
            ]),
        );

        self::assertResponseStatusCodeSame(401);

        $sql = sprintf(
            "SELECT COUNT(*) FROM telemetry.otel_traces WHERE mapContains(SpanAttributes, '%s')",
            $uniqueAttr
        );

        $count = $this->queryClickHouseCount($sql);
        self::assertSame(0, $count, 'Unauthenticated event must not appear in ClickHouse');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function generateJwt(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $domain = 'test.bitrix24.ru',
        string $memberId = 'member-test',
    ): string {
        /** @var JwtService $jwtService */
        $jwtService = $client->getContainer()->get(JwtService::class);

        return $jwtService->generateToken($domain, $memberId);
    }

    /**
     * Опрашивает ClickHouse пока COUNT(*) >= $expectedCount или не истечёт время ожидания.
     */
    private function pollClickHouse(string $sql, int $expectedCount = 1): bool
    {
        for ($attempt = 1; $attempt <= self::POLL_MAX_ATTEMPTS; ++$attempt) {
            $count = $this->queryClickHouseCount($sql);

            if ($count >= $expectedCount) {
                return true;
            }

            usleep(self::POLL_INTERVAL_MS * 1000);
        }

        return false;
    }

    /**
     * Выполнить COUNT(*) запрос к ClickHouse через HTTP API.
     *
     * @return int Результат COUNT(*) или 0 при ошибке
     */
    private function queryClickHouseCount(string $sql): int
    {
        $url = sprintf(
            'http://%s/?query=%s&output_format_json_quote_64bit_integers=0',
            self::$clickhouseHost,
            urlencode($sql)
        );

        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $result = @file_get_contents($url, false, $ctx);

        if ($result === false) {
            return 0;
        }

        return (int) trim($result);
    }
}
