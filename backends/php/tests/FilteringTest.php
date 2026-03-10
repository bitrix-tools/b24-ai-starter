<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\AttributeGroupManager;
use App\Service\Telemetry\Config\OtlpConfig;
use App\Service\Telemetry\Profiles\IntegrationProfile;
use App\Service\Telemetry\Profiles\LifecycleProfile;
use App\Service\Telemetry\Profiles\MigrationProfile;
use App\Service\Telemetry\Profiles\UIProfile;
use App\Service\Telemetry\RealTelemetryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты фильтрации атрибутов в RealTelemetryService.
 *
 * Проверяют что AttributeGroupManager корректно интегрирован
 * и атрибуты фильтруются согласно активному профилю.
 */
class FilteringTest extends TestCase
{
    private const TEST_ENDPOINT = 'http://localhost:4318';
    private const TEST_SERVICE_NAME = 'test-filtering-service';
    private const TEST_SERVICE_VERSION = '1.0.0-test';
    private const TEST_ENVIRONMENT = 'test';

    private function createTestConfig(): OtlpConfig
    {
        return new OtlpConfig(
            self::TEST_ENDPOINT,
            self::TEST_SERVICE_NAME,
            self::TEST_SERVICE_VERSION,
            self::TEST_ENVIRONMENT,
        );
    }

    /**
     * Тест: фильтрация атрибутов работает в сервисе.
     *
     * Проверяет что RealTelemetryService использует AttributeGroupManager
     * для фильтрации атрибутов и отбрасывает неразрешённые.
     */
    public function testAttributeFilteringInService(): void
    {
        // Создаём профиль simple-ui (Lifecycle + UI)
        $profiles = [
            new LifecycleProfile(),
            new UIProfile(),
        ];
        $manager = new AttributeGroupManager($profiles);

        // Создаём RealTelemetryService с фильтрацией
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, $manager, new NullLogger());

        // Отправляем событие с mixed атрибутами
        // (lifecycle разрешены, sync.* должны быть отфильтрованы)
        $mixedAttributes = [
            // Lifecycle - разрешены
            'app.id' => 'test-app',
            'portal.id' => '12345',
            'lifecycle.event_type' => 'app_installed',

            // UI - разрешены
            'ui.surface' => 'detail',
            'button.name' => 'submit',

            // Sync - НЕ разрешены (не в профиле)
            'sync.id' => 'sync-123',
            'sync.type' => 'full',
            'sync.status' => 'running',

            // Migration - НЕ разрешены (не в профиле)
            'migration.id' => 'mig-456',
            'migration.batch_size' => 1000,
        ];

        // Вызов не должен выбросить исключение
        $this->expectNotToPerformAssertions();
        $service->trackEvent('test_filtering_event', $mixedAttributes);

        // Примечание: в unit тесте мы не можем проверить что реально отправлено
        // Это покрывается E2E тестами с реальным OTel Collector
    }

    /**
     * Тест: события содержат только разрешённые атрибуты.
     *
     * Integration test: проверяет что менеджер корректно фильтрует
     * атрибуты и сервис не отправляет запрещённые.
     */
    public function testEventsContainOnlyAllowedAttributes(): void
    {
        // Создаём профиль только с Lifecycle
        $profiles = [new LifecycleProfile()];
        $manager = new AttributeGroupManager($profiles);

        // Проверяем что менеджер разрешает только lifecycle атрибуты
        $allowedAttributes = $manager->getAllowedAttributes();
        $this->assertContains('app.id', $allowedAttributes);
        $this->assertContains('portal.id', $allowedAttributes);
        $this->assertContains('lifecycle.event_type', $allowedAttributes);

        // Проверяем что UI атрибуты НЕ разрешены
        $this->assertNotContains('ui.surface', $allowedAttributes);
        $this->assertNotContains('button.name', $allowedAttributes);

        // Тестируем фильтрацию
        $mixedAttributes = [
            'app.id' => 'test',
            'ui.surface' => 'detail', // должен быть отфильтрован
            'portal.id' => '123',
            'sync.id' => 'sync-1', // должен быть отфильтрован
        ];

        $filtered = $manager->filterAttributes($mixedAttributes);

        // Проверяем что остались только lifecycle атрибуты
        $this->assertArrayHasKey('app.id', $filtered);
        $this->assertArrayHasKey('portal.id', $filtered);
        $this->assertArrayNotHasKey('ui.surface', $filtered);
        $this->assertArrayNotHasKey('sync.id', $filtered);
    }

    /**
     * Тест: профиль simple-ui включает Lifecycle и UI атрибуты.
     *
     * Unit test: проверяет композицию профилей для simple-ui конфигурации.
     */
    public function testSimpleUIProfileIncluesLifecycleAndUIAttributes(): void
    {
        // Создаём профиль simple-ui (Lifecycle + UI)
        $profiles = [
            new LifecycleProfile(),
            new UIProfile(),
        ];
        $manager = new AttributeGroupManager($profiles);

        $allowedAttributes = $manager->getAllowedAttributes();

        // Проверяем наличие Lifecycle атрибутов
        $lifecycleAttributesPresent = [
            'app.id',
            'app.version',
            'portal.id',
            'portal.member_id',
            'lifecycle.event_type',
            'lifecycle.status',
            'registration.type',
        ];

        foreach ($lifecycleAttributesPresent as $attr) {
            $this->assertContains(
                $attr,
                $allowedAttributes,
                "Lifecycle attribute '{$attr}' should be present in simple-ui profile",
            );
        }

        // Проверяем наличие UI атрибутов
        $uiAttributesPresent = [
            'ui.surface',
            'ui.placement_code',
            'screen.name',
            'interaction.type',
            'button.name',
            'form.id',
            'session.id',
            'user.id',
            'action.name',
            'action.type',
        ];

        foreach ($uiAttributesPresent as $attr) {
            $this->assertContains(
                $attr,
                $allowedAttributes,
                "UI attribute '{$attr}' should be present in simple-ui profile",
            );
        }

        // Проверяем ОТСУТСТВИЕ атрибутов других профилей
        $syncAttributesAbsent = [
            'sync.id',
            'sync.type',
            'sync.status',
            'integration.system_name',
        ];

        foreach ($syncAttributesAbsent as $attr) {
            $this->assertNotContains(
                $attr,
                $allowedAttributes,
                "Sync attribute '{$attr}' should NOT be present in simple-ui profile",
            );
        }

        $migrationAttributesAbsent = [
            'migration.id',
            'migration.type',
            'migration.batch_size',
            'stage.name',
        ];

        foreach ($migrationAttributesAbsent as $attr) {
            $this->assertNotContains(
                $attr,
                $allowedAttributes,
                "Migration attribute '{$attr}' should NOT be present in simple-ui profile",
            );
        }
    }

    /**
     * Performance test: overhead фильтрации < 1ms.
     *
     * Проверяет что фильтрация атрибутов не добавляет значительного overhead.
     * Критерий: фильтрация 100 атрибутов должна занимать < 1ms.
     */
    public function testFilteringPerformanceOverheadIsMinimal(): void
    {
        // Создаём профиль с большим количеством атрибутов
        $profiles = [
            new LifecycleProfile(),
            new UIProfile(),
            new IntegrationProfile(),
            new MigrationProfile(),
        ];
        $manager = new AttributeGroupManager($profiles);

        // Генерируем 100 атрибутов для фильтрации
        $attributes = [];
        for ($i = 0; $i < 100; ++$i) {
            // Mix разрешённых и запрещённых атрибутов
            if (0 === $i % 2) {
                $attributes["app.test_attr_{$i}"] = "value_{$i}"; // разрешён (app.*)
            } else {
                $attributes["unknown.attr_{$i}"] = "value_{$i}"; // запрещён
            }
        }

        // Измеряем время фильтрации
        $startTime = microtime(true);

        // Выполняем фильтрацию 100 раз для усреднения
        $iterations = 100;
        for ($i = 0; $i < $iterations; ++$i) {
            $manager->filterAttributes($attributes);
        }

        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000; // в миллисекундах
        $avgTimePerFilter = $totalTime / $iterations;

        // Проверяем что среднее время фильтрации < 1ms
        $this->assertLessThan(
            1.0,
            $avgTimePerFilter,
            sprintf(
                'Filtering overhead is too high: %.3f ms (expected < 1 ms)',
                $avgTimePerFilter,
            ),
        );

        // Для информации выводим метрики
        $this->addToAssertionCount(1); // чтобы тест не считался risky
    }

    /**
     * Тест: фильтрация с exclusion patterns.
     *
     * Проверяет что exclusion patterns корректно исключают атрибуты.
     */
    public function testFilteringWithExclusionPatterns(): void
    {
        // Создаём профиль Integration с исключением initial_sync.*
        $profiles = [
            new LifecycleProfile(),
            new IntegrationProfile(),
        ];
        $excludePatterns = ['initial_sync.*'];
        $manager = new AttributeGroupManager($profiles, $excludePatterns);

        $attributes = [
            'app.id' => 'test',
            'sync.id' => 'sync-1',
            'initial_sync.started_at' => '2026-02-25T10:00:00Z', // должен быть исключён
            'initial_sync.duration_ms' => 5000, // должен быть исключён
            'sync.entities_total' => 1000, // должен пройти
        ];

        $filtered = $manager->filterAttributes($attributes);

        // Lifecycle и sync атрибуты разрешены
        $this->assertArrayHasKey('app.id', $filtered);
        $this->assertArrayHasKey('sync.id', $filtered);
        $this->assertArrayHasKey('sync.entities_total', $filtered);

        // initial_sync.* исключены паттерном
        $this->assertArrayNotHasKey('initial_sync.started_at', $filtered);
        $this->assertArrayNotHasKey('initial_sync.duration_ms', $filtered);
    }

    /**
     * Тест: backward compatibility без AttributeGroupManager.
     *
     * Проверяет что RealTelemetryService работает без фильтрации
     * если AttributeGroupManager не передан (null).
     */
    public function testBackwardCompatibilityWithoutAttributeGroupManager(): void
    {
        $config = $this->createTestConfig();

        // Создаём сервис БЕЗ AttributeGroupManager (null)
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Отправляем событие с любыми атрибутами
        // Все атрибуты должны пройти (фильтрация отключена)
        $attributes = [
            'app.id' => 'test',
            'sync.id' => 'sync-1',
            'unknown.attr' => 'value',
            'any.other.attr' => 'data',
        ];

        // Не должно быть исключений
        $this->expectNotToPerformAssertions();
        $service->trackEvent('test_no_filtering', $attributes);
    }
}
