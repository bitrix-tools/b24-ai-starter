#!/usr/bin/env php
<?php

/**
 * E2E тест интеграции Monolog → OpenTelemetry → ClickHouse.
 *
 * Этот скрипт:
 * 1. Создаёт Monolog Logger с MonologOTelHandler
 * 2. Отправляет логи различных уровней через Monolog
 * 3. Выводит инструкции для проверки в ClickHouse
 *
 * Usage:
 *   php bin/test-monolog-e2e.php
 *
 * Requirements:
 *   - b24-ai-starter-otel должен быть запущен
 *   - TELEMETRY_ENABLED=true в .env
 */

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

use App\Service\Telemetry\MonologOTelHandler;
use App\Service\Telemetry\TelemetryFactory;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

// Загрузка .env
$dotenv = new Dotenv();
$dotenv->load(dirname(__DIR__).'/.env');

// Цвета для консоли
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_RED = "\033[31m";
const COLOR_RESET = "\033[0m";

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  E2E Test: Monolog → OpenTelemetry → ClickHouse              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Проверка что телеметрия включена
if ('true' !== $_ENV['TELEMETRY_ENABLED']) {
    echo COLOR_YELLOW.'⚠ TELEMETRY_ENABLED=false в .env'.COLOR_RESET."\n";
    echo "  Установите TELEMETRY_ENABLED=true для E2E тестирования\n\n";
    exit(1);
}

echo COLOR_BLUE.'📋 Конфигурация:'.COLOR_RESET."\n";
echo "  Endpoint: {$_ENV['OTEL_EXPORTER_OTLP_ENDPOINT']}\n";
echo "  Service:  {$_ENV['OTEL_SERVICE_NAME']}\n";
echo "  Version:  {$_ENV['OTEL_SERVICE_VERSION']}\n";
echo "\n";

// Создание TelemetryService
echo COLOR_BLUE.'🔧 Инициализация...'.COLOR_RESET."\n";

$factory = new TelemetryFactory(
    telemetryEnabled: true,
    otlpEndpoint: $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'],
    serviceName: $_ENV['OTEL_SERVICE_NAME'],
    serviceVersion: $_ENV['OTEL_SERVICE_VERSION'],
    environment: $_ENV['OTEL_ENVIRONMENT'],
    activeProfile: 'simple-ui',
    profilesConfig: [],
    logger: new NullLogger(),
);

$telemetry = $factory->create();

echo COLOR_GREEN.'  ✓ TelemetryService создан'.COLOR_RESET."\n";

// Создание Monolog Logger с OTel Handler
$logger = new Logger('test-channel');
$otelHandler = new MonologOTelHandler($telemetry, Level::Debug, bubble: true);
$logger->pushHandler($otelHandler);

echo COLOR_GREEN.'  ✓ Monolog Logger настроен с MonologOTelHandler'.COLOR_RESET."\n";
echo "\n";

// Генерируем уникальный идентификатор сессии для фильтрации в ClickHouse
$sessionId = 'test_session_'.time().'_'.random_int(1000, 9999);

echo COLOR_BLUE.'🔑 Session ID для тестов: '.COLOR_RESET.COLOR_YELLOW.$sessionId.COLOR_RESET."\n";
echo "   Используйте этот ID для поиска событий в ClickHouse\n\n";

// ============================================================================
// ТЕСТ 1: INFO лог без exception
// ============================================================================

echo COLOR_BLUE.'🧪 ТЕСТ 1: INFO лог'.COLOR_RESET."\n";

$logger->info('User login successful', [
    'session_id' => $sessionId,
    'user_id' => 12345,
    'ip_address' => '192.168.1.100',
    'user_agent' => 'Mozilla/5.0',
]);

echo COLOR_GREEN.'  ✓ INFO лог отправлен'.COLOR_RESET."\n";
echo "    Message: 'User login successful'\n";
echo "    Expected: trackEvent('log.info.test-channel')\n";
echo "\n";

// ============================================================================
// ТЕСТ 2: WARNING лог с массивами
// ============================================================================

echo COLOR_BLUE.'🧪 ТЕСТ 2: WARNING лог с массивами'.COLOR_RESET."\n";

$logger->warning('Rate limit exceeded', [
    'session_id' => $sessionId,
    'limits' => [
        'requests_per_minute' => 100,
        'current_count' => 105,
    ],
    'blocked_for_seconds' => 60,
]);

echo COLOR_GREEN.'  ✓ WARNING лог отправлен'.COLOR_RESET."\n";
echo "    Message: 'Rate limit exceeded'\n";
echo "    Expected: trackEvent('log.warning.test-channel')\n";
echo "    Context: массив limits должен быть в JSON\n";
echo "\n";

// ============================================================================
// ТЕСТ 3: ERROR лог с exception
// ============================================================================

echo COLOR_BLUE.'🧪 ТЕСТ 3: ERROR лог с exception'.COLOR_RESET."\n";

try {
    throw new RuntimeException('Database connection failed', 500);
} catch (Throwable $e) {
    $logger->error('Critical database error', [
        'session_id' => $sessionId,
        'exception' => $e,
        'database' => 'main_db',
        'retry_count' => 3,
    ]);
}

echo COLOR_GREEN.'  ✓ ERROR лог отправлен'.COLOR_RESET."\n";
echo "    Message: 'Critical database error'\n";
echo "    Expected: trackError(\$exception, \$context)\n";
echo "    Exception: RuntimeException with code 500\n";
echo "\n";

// ============================================================================
// ТЕСТ 4: DEBUG лог с null и scalar значениями
// ============================================================================

echo COLOR_BLUE.'🧪 ТЕСТ 4: DEBUG лог с различными типами'.COLOR_RESET."\n";

$logger->debug('Cache operation', [
    'session_id' => $sessionId,
    'cache_key' => 'user:12345:profile',
    'cache_hit' => true,
    'ttl' => 3600,
    'value' => null, // null должен нормализоваться в ''
]);

echo COLOR_GREEN.'  ✓ DEBUG лог отправлен'.COLOR_RESET."\n";
echo "    Message: 'Cache operation'\n";
echo "    Expected: trackEvent('log.debug.test-channel')\n";
echo "    Context: null значение должно стать пустой строкой\n";
echo "\n";

// ============================================================================
// ТЕСТ 5: CRITICAL лог
// ============================================================================

echo COLOR_BLUE.'🧪 ТЕСТ 5: CRITICAL лог'.COLOR_RESET."\n";

$logger->critical('Service unavailable', [
    'session_id' => $sessionId,
    'service' => 'payment-gateway',
    'downtime_seconds' => 120,
]);

echo COLOR_GREEN.'  ✓ CRITICAL лог отправлен'.COLOR_RESET."\n";
echo "    Message: 'Service unavailable'\n";
echo "    Expected: trackEvent('log.critical.test-channel')\n";
echo "    Severity: FATAL\n";
echo "\n";

// Даём время на отправку событий
sleep(1);

// ============================================================================
// Инструкции для проверки результатов
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Проверка результатов в ClickHouse                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo COLOR_YELLOW.'📝 Для проверки результатов выполните в b24-ai-starter-otel:'.COLOR_RESET."\n";
echo "\n";
echo "  1. Подключитесь к ClickHouse:\n";
echo '     '.COLOR_GREEN.'make clickhouse-client'.COLOR_RESET."\n";
echo "\n";
echo "  2. Поиск событий по Session ID:\n";
echo "\n";
echo '     '.COLOR_GREEN.'-- Показать все тестовые события'.COLOR_RESET."\n";
echo "     SELECT\n";
echo "         Timestamp,\n";
echo "         Body,\n";
echo "         ResourceAttributes['log.level'] as level,\n";
echo "         ResourceAttributes['log.severity'] as severity,\n";
echo "         ResourceAttributes['log.channel'] as channel\n";
echo "     FROM otel.otel_logs\n";
echo "     WHERE ResourceAttributes['context.session_id'] = '".COLOR_YELLOW.$sessionId.COLOR_RESET."'\n";
echo "     ORDER BY Timestamp DESC;\n";
echo "\n";
echo '     '.COLOR_GREEN.'-- Детальный просмотр одного события'.COLOR_RESET."\n";
echo "     SELECT *\n";
echo "     FROM otel.otel_logs\n";
echo "     WHERE ResourceAttributes['context.session_id'] = '".COLOR_YELLOW.$sessionId.COLOR_RESET."'\n";
echo "     ORDER BY Timestamp DESC\n";
echo "     LIMIT 1\n";
echo "     FORMAT Vertical;\n";
echo "\n";
echo '     '.COLOR_GREEN.'-- Подсчёт событий по уровням'.COLOR_RESET."\n";
echo "     SELECT\n";
echo "         ResourceAttributes['log.level'] as level,\n";
echo "         count() as count\n";
echo "     FROM otel.otel_logs\n";
echo "     WHERE ResourceAttributes['context.session_id'] = '".COLOR_YELLOW.$sessionId.COLOR_RESET."'\n";
echo "     GROUP BY level\n";
echo "     ORDER BY level;\n";
echo "\n";

echo COLOR_YELLOW.'✅ Критерии успешного теста:'.COLOR_RESET."\n";
echo '  1. В ClickHouse найдено 5 событий с session_id = '.COLOR_YELLOW.$sessionId.COLOR_RESET."\n";
echo "  2. События разных уровней: DEBUG, INFO, WARNING, ERROR, CRITICAL\n";
echo "  3. Severity правильно маппится:\n";
echo "     - DEBUG → DEBUG\n";
echo "     - INFO → INFO\n";
echo "     - WARNING → WARN\n";
echo "     - ERROR → ERROR (с exception атрибутами)\n";
echo "     - CRITICAL → FATAL\n";
echo "  4. Context атрибуты сохранены с префиксом 'context.'\n";
echo "  5. Массивы преобразованы в JSON строки\n";
echo "  6. Null значения преобразованы в пустые строки\n";
echo "  7. Exception для ERROR лога содержит:\n";
echo "     - exception.type = 'RuntimeException'\n";
echo "     - exception.message = 'Database connection failed'\n";
echo "     - exception.code = '500'\n";
echo "\n";

echo COLOR_GREEN.'✓ Тестовые события отправлены успешно!'.COLOR_RESET."\n";
echo COLOR_BLUE.'⏱  Подождите 2-3 секунды для обработки в OTel Collector'.COLOR_RESET."\n";
echo "\n";
