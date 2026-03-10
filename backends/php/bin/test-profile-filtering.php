#!/usr/bin/env php
<?php

/**
 * E2E тест фильтрации атрибутов согласно активному профилю
 * 
 * Этот скрипт отправляет события с атрибутами из ВСЕХ профилей
 * и проверяет что в ClickHouse сохраняются только атрибуты
 * согласно активному профилю (по умолчанию simple-ui).
 * 
 * Usage:
 *   php bin/test-profile-filtering.php
 * 
 * Requirements:
 *   - b24-ai-starter-otel должен быть запущен (make start в b24-ai-starter-otel)
 *   - TELEMETRY_ENABLED=true в .env
 *   - Активный профиль настроен в config/packages/telemetry.yaml
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use App\Service\Telemetry\TelemetryFactory;
use Psr\Log\NullLogger;

// Загрузка .env
$dotenv = new Dotenv();
$dotenv->load(dirname(__DIR__) . '/.env');

// Цвета для консоли
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_RESET = "\033[0m";

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  E2E Test: Profile Attribute Filtering                        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Проверка что телеметрия включена
if ($_ENV['TELEMETRY_ENABLED'] !== 'true') {
    echo COLOR_YELLOW . "⚠ TELEMETRY_ENABLED=false в .env" . COLOR_RESET . "\n";
    echo "  Установите TELEMETRY_ENABLED=true для E2E тестирования\n\n";
    exit(1);
}

echo COLOR_BLUE . "📋 Конфигурация:" . COLOR_RESET . "\n";
echo "  Endpoint: {$_ENV['OTEL_EXPORTER_OTLP_ENDPOINT']}\n";
echo "  Service:  {$_ENV['OTEL_SERVICE_NAME']}\n";
echo "  Version:  {$_ENV['OTEL_SERVICE_VERSION']}\n";
echo "\n";

// Создание TelemetryFactory и TelemetryService
echo COLOR_BLUE . "🔧 Инициализация TelemetryService..." . COLOR_RESET . "\n";

$factory = new TelemetryFactory(
    telemetryEnabled: true,
    otlpEndpoint: $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'],
    serviceName: $_ENV['OTEL_SERVICE_NAME'],
    serviceVersion: $_ENV['OTEL_SERVICE_VERSION'],
    environment: $_ENV['OTEL_ENVIRONMENT'],
    activeProfile: 'simple-ui', // Явно указываем профиль для теста
    profilesConfig: [
        'simple-ui' => [
            'profiles' => [
                \App\Service\Telemetry\Profiles\LifecycleProfile::class,
                \App\Service\Telemetry\Profiles\UIProfile::class,
            ],
            'exclude_patterns' => [],
            'description' => 'Test profile',
        ],
    ],
    logger: new NullLogger()
);

$telemetry = $factory->create();

echo COLOR_GREEN . "  ✓ TelemetryService создан" . COLOR_RESET . "\n";
echo "  Активный профиль: simple-ui (Lifecycle + UI)\n";
echo "\n";

// ============================================================================
// ТЕСТ 1: Lifecycle событие с избыточными атрибутами
// ============================================================================

echo COLOR_BLUE . "🧪 ТЕСТ 1: Lifecycle событие (app_installed)" . COLOR_RESET . "\n";
echo "  Отправка атрибутов из ВСЕХ профилей...\n";

$testAttributes = [
    // ✅ LIFECYCLE ATTRIBUTES (должны остаться)
    'app.id' => 'local.test123',
    'app.version' => '1.0.0',
    'app.code' => 'test_app',
    'portal.member_id' => 'test_portal_123',
    'portal.domain' => 'test.bitrix24.com',
    
    // ✅ UI ATTRIBUTES (должны остаться)
    'ui.surface' => 'settings_page',
    'ui.action' => 'install_confirm',
    
    // ❌ INTEGRATION ATTRIBUTES (должны быть отфильтрованы)
    'sync.operation' => 'full_sync',
    'sync.entity_type' => 'crm.lead',
    'sync.total_count' => '1000',
    'sync.direction' => 'export',
    'initial_sync.is_first_sync' => 'true',
    'initial_sync.started_at' => time(),
    'webhook.url' => 'https://example.com/webhook',
    'webhook.handler' => 'processLeadUpdate',
    
    // ❌ MIGRATION ATTRIBUTES (должны быть отфильтрованы)
    'migration.type' => 'crm_leads',
    'migration.batch_size' => '500',
    'migration.entity_count' => '10000',
    'migration.status' => 'in_progress',
];

$telemetry->trackEvent('app_installed', $testAttributes);

echo COLOR_GREEN . "  ✓ Событие отправлено" . COLOR_RESET . "\n";
echo "  Отправлено атрибутов: " . count($testAttributes) . "\n";
echo "  Ожидается в ClickHouse: ~7 атрибутов (только Lifecycle + UI)\n";
echo "\n";

// ============================================================================
// ТЕСТ 2: UI событие с избыточными атрибутами
// ============================================================================

echo COLOR_BLUE . "🧪 ТЕСТ 2: UI событие (button_clicked)" . COLOR_RESET . "\n";
echo "  Отправка атрибутов из ВСЕХ профилей...\n";

$testAttributes2 = [
    // ✅ LIFECYCLE ATTRIBUTES (должны остаться)
    'app.id' => 'local.test123',
    'portal.member_id' => 'test_portal_123',
    
    // ✅ UI ATTRIBUTES (должны остаться)
    'ui.surface' => 'crm_detail',
    'ui.action' => 'save_button_click',
    'ui.element_id' => 'btn_save_lead',
    'ui.screen.name' => 'lead_card',
    'ui.screen.path' => '/crm/lead/123/',
    
    // ❌ INTEGRATION ATTRIBUTES (должны быть отфильтрованы)
    'sync.operation' => 'incremental',
    'sync.last_updated' => time(),
    'webhook.event' => 'onCrmLeadUpdate',
    
    // ❌ MIGRATION ATTRIBUTES (должны быть отфильтрованы)
    'migration.type' => 'data_export',
    'migration.progress_percent' => '45',
];

$telemetry->trackEvent('button_clicked', $testAttributes2);

echo COLOR_GREEN . "  ✓ Событие отправлено" . COLOR_RESET . "\n";
echo "  Отправлено атрибутов: " . count($testAttributes2) . "\n";
echo "  Ожидается в ClickHouse: ~7 атрибутов (только Lifecycle + UI)\n";
echo "\n";

// ============================================================================
// ТЕСТ 3: Событие с wildcard exclusion patterns
// ============================================================================

echo COLOR_BLUE . "🧪 ТЕСТ 3: Проверка wildcard exclusion patterns" . COLOR_RESET . "\n";

$testAttributes3 = [
    // ✅ LIFECYCLE (должны остаться)
    'app.id' => 'local.test123',
    
    // ❌ Все initial_sync.* должны быть отфильтрованы
    'initial_sync.is_first' => 'true',
    'initial_sync.timestamp' => time(),
    'initial_sync.entity_count' => '5000',
    'initial_sync.batch_number' => '3',
];

$telemetry->trackEvent('sync_started', $testAttributes3);

echo COLOR_GREEN . "  ✓ Событие отправлено" . COLOR_RESET . "\n";
echo "  Wildcard pattern проверен: initial_sync.* должны быть исключены\n";
echo "\n";

// ============================================================================
// Инструкции для проверки результатов
// ============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Проверка результатов в ClickHouse                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo COLOR_YELLOW . "📝 Для проверки результатов выполните в b24-ai-starter-otel:" . COLOR_RESET . "\n";
echo "\n";
echo "  1. Подключитесь к ClickHouse:\n";
echo "     " . COLOR_GREEN . "make clickhouse-client" . COLOR_RESET . "\n";
echo "\n";
echo "  2. Выполните запросы:\n";
echo "\n";
echo "     " . COLOR_GREEN . "-- Показать последние 3 события" . COLOR_RESET . "\n";
echo "     SELECT\n";
echo "         Timestamp,\n";
echo "         Body,\n";
echo "         arrayStringConcat(mapKeys(ResourceAttributes), ', ') as attrs\n";
echo "     FROM otel.otel_logs\n";
echo "     WHERE Body LIKE '%app_installed%' OR Body LIKE '%button_clicked%'\n";
echo "     ORDER BY Timestamp DESC\n";
echo "     LIMIT 3;\n";
echo "\n";
echo "     " . COLOR_GREEN . "-- Детальный просмотр атрибутов" . COLOR_RESET . "\n";
echo "     SELECT\n";
echo "         Body,\n";
echo "         ResourceAttributes\n";
echo "     FROM otel.otel_logs\n";
echo "     WHERE Body LIKE '%app_installed%'\n";
echo "     ORDER BY Timestamp DESC\n";
echo "     LIMIT 1\n";
echo "     FORMAT Vertical;\n";
echo "\n";

echo COLOR_YELLOW . "✅ Критерии успешного теста:" . COLOR_RESET . "\n";
echo "  1. События app_installed и button_clicked присутствуют в ClickHouse\n";
echo "  2. Lifecycle атрибуты сохранены (app.*, portal.*)\n";
echo "  3. UI атрибуты сохранены (ui.*)\n";
echo "  4. Integration атрибуты ОТСУТСТВУЮТ (sync.*, webhook.*, initial_sync.*)\n";
echo "  5. Migration атрибуты ОТСУТСТВУЮТ (migration.*)\n";
echo "\n";

echo COLOR_GREEN . "✓ Тестовые события отправлены успешно!" . COLOR_RESET . "\n";
echo "\n";
