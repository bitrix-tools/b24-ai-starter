<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\Config\OtlpConfig;
use App\Service\Telemetry\RealTelemetryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты для RealTelemetryService.
 */
class RealTelemetryServiceTest extends TestCase
{
    private const TEST_ENDPOINT = 'http://localhost:4318';
    private const TEST_SERVICE_NAME = 'test-service';
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

    public function testServiceInitializesWithoutErrors(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Проверяем что сервис был инициализирован
        // Даже если collector недоступен, инициализация должна пройти без исключений
        $this->assertInstanceOf(RealTelemetryService::class, $service);
    }

    public function testIsEnabledReturnsBooleanValue(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $result = $service->isEnabled();
        $this->assertIsBool($result);
    }

    public function testTrackEventDoesNotThrowException(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Вызов не должен выбросить исключение, даже если collector недоступен
        $this->expectNotToPerformAssertions();
        $service->trackEvent('test_event', [
            'test_key' => 'test_value',
            'numeric_key' => 42,
            'bool_key' => true,
        ]);
    }

    public function testTrackEventWithArrayAttributes(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackEvent('test_event_with_array', [
            'simple' => 'value',
            'array_data' => ['key1' => 'value1', 'key2' => 'value2'],
            'nested' => ['level1' => ['level2' => 'deep']],
        ]);
    }

    public function testTrackEventWithEmptyAttributes(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackEvent('event_without_attributes');
    }

    public function testTrackErrorDoesNotThrowException(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $exception = new \Exception('Test exception message');

        $this->expectNotToPerformAssertions();
        $service->trackError($exception, [
            'user_id' => 123,
            'context' => 'test_context',
        ]);
    }

    public function testTrackErrorWithEmptyContext(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $exception = new \Exception('Test exception');

        $this->expectNotToPerformAssertions();
        $service->trackError($exception);
    }

    public function testGracefulDegradationWhenCollectorUnavailable(): void
    {
        // Используем недоступный но валидный endpoint
        $config = new OtlpConfig(
            'http://localhost:65535',
            self::TEST_SERVICE_NAME,
            self::TEST_SERVICE_VERSION,
            self::TEST_ENVIRONMENT,
        );
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Сервис должен создаться без исключений
        $this->assertInstanceOf(RealTelemetryService::class, $service);

        // Методы не должны выбрасывать исключения (но могут не отправлять данные)
        $service->trackEvent('test_event', ['key' => 'value']);
        $service->trackError(new \Exception('Test error'));

        // Проверяем что не было исключений
        $this->assertTrue(true);
    }

    public function testMultipleEventsTracking(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Отправляем несколько событий подряд
        $this->expectNotToPerformAssertions();
        for ($i = 0; $i < 5; ++$i) {
            $service->trackEvent("test_event_{$i}", [
                'iteration' => $i,
                'timestamp' => time(),
            ]);
        }
    }

    public function testTrackEventWithSpecialCharactersInName(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackEvent('event_with_special_chars_123', [
            'key' => 'value',
        ]);
    }

    public function testTrackEventWithNullValue(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackEvent('event_with_null', [
            'nullable_field' => null,
            'string_field' => 'value',
        ]);
    }

    // -------------------------------------------------------------------------
    // trackOperation() — RealTelemetryService
    // -------------------------------------------------------------------------

    public function testTrackOperationReturnsCallableResult(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $result = $service->trackOperation('test.operation', fn () => 'result_value');

        $this->assertSame('result_value', $result);
    }

    public function testTrackOperationReturnsIntResult(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $result = $service->trackOperation('test.int_result', fn () => 99);

        $this->assertSame(99, $result);
    }

    public function testTrackOperationReturnsArrayResult(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $expected = ['a' => 1, 'b' => 'two'];
        $result = $service->trackOperation('test.array_result', fn () => $expected);

        $this->assertSame($expected, $result);
    }

    public function testTrackOperationReturnsNullResult(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $result = $service->trackOperation('test.null_result', fn () => null);

        $this->assertNull($result);
    }

    public function testTrackOperationExecutesCallableExactlyOnce(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $callCount = 0;
        $service->trackOperation('test.count', function () use (&$callCount): void {
            ++$callCount;
        });

        $this->assertSame(1, $callCount);
    }

    public function testTrackOperationRethrowsException(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $originalException = new \RuntimeException('upstream failure', 500);

        try {
            $service->trackOperation('failing.operation', function () use ($originalException): never {
                throw $originalException;
            });
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($originalException, $e, 'Must rethrow the exact same exception instance');
        }
    }

    public function testTrackOperationRethrowsArbitraryThrowable(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('logic error');

        $service->trackOperation('test.logic_error', function (): never {
            throw new \LogicException('logic error');
        });
    }

    public function testTrackOperationWithAttributesDoesNotThrow(): void
    {
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackOperation(
            'bitrix24.crm.contact.get',
            fn () => ['id' => 1, 'name' => 'Test'],
            [
                'portal.member_id' => 'member_123',
                'b24.contact_id'   => '42',
                'ai.model'         => 'claude-3',
            ],
        );
    }

    public function testTrackOperationWithArrayAttributesDoesNotThrow(): void
    {
        // Массивы в атрибутах должны проходить через flattenAttributes (→ JSON)
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $this->expectNotToPerformAssertions();
        $service->trackOperation(
            'test.array_attrs',
            fn () => null,
            ['nested' => ['key' => 'value']],
        );
    }

    public function testTrackOperationWithUnavailableCollectorDoesNotThrowOnInit(): void
    {
        // Если коллектор недоступен — сам вызов TracerProvider может упасть при emit,
        // но initialized=true установлен до первого emit. Важно: метод не должен
        // выбрасывать исключение — только логировать ошибку отправки.
        $config = new OtlpConfig(
            'http://localhost:65535',
            self::TEST_SERVICE_NAME,
            self::TEST_SERVICE_VERSION,
            self::TEST_ENVIRONMENT,
        );
        $service = new RealTelemetryService($config, null, new NullLogger());

        // Операция выполняется и возвращает результат вне зависимости от доступности коллектора
        $result = $service->trackOperation('test.unreachable_collector', fn () => 'value');

        $this->assertSame('value', $result);
    }

    public function testTrackOperationNestedCallWorks(): void
    {
        // Вложенный trackOperation: внутренний span привязывается к контексту внешнего
        $config = $this->createTestConfig();
        $service = new RealTelemetryService($config, null, new NullLogger());

        $innerResult = null;
        $outerResult = $service->trackOperation('outer.span', function () use ($service, &$innerResult): string {
            $innerResult = $service->trackOperation('inner.span', fn () => 'inner');

            return 'outer';
        });

        $this->assertSame('outer', $outerResult);
        $this->assertSame('inner', $innerResult);
    }
}
