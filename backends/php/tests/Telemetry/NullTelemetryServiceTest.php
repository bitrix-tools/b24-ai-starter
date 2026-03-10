<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\NullTelemetryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для NullTelemetryService.
 *
 * Проверяем, что Null Object реализация:
 * - Не выбрасывает исключений
 * - Возвращает корректные значения
 * - Не имеет side effects
 */
class NullTelemetryServiceTest extends TestCase
{
    private NullTelemetryService $service;

    protected function setUp(): void
    {
        $this->service = new NullTelemetryService();
    }

    #[Test]
    public function trackEventDoesNotThrowException(): void
    {
        // Arrange & Act & Assert - не должно быть исключений
        $this->service->trackEvent('test_event');
        $this->service->trackEvent('test_event', ['key' => 'value']);
        $this->service->trackEvent('test_event', [
            'complex' => ['nested' => 'data'],
            'number' => 42,
            'bool' => true,
        ]);

        $this->assertTrue(true); // Если дошли сюда - все ок
    }

    #[Test]
    public function trackErrorDoesNotThrowException(): void
    {
        // Arrange
        $exception = new \Exception('Test exception');

        // Act & Assert - не должно быть исключений
        $this->service->trackError($exception);
        $this->service->trackError($exception, ['context' => 'data']);

        $this->assertTrue(true); // Если дошли сюда - все ок
    }

    #[Test]
    public function isEnabledReturnsFalse(): void
    {
        // Act
        $result = $this->service->isEnabled();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function multipleCallsHaveNoSideEffects(): void
    {
        // Arrange - проверяем что множественные вызовы безопасны
        $exception = new \Exception('Test');

        // Act - вызываем методы множество раз
        for ($i = 0; $i < 1000; ++$i) {
            $this->service->trackEvent('event_'.$i, ['iteration' => $i]);
            $this->service->trackError($exception, ['iteration' => $i]);
        }

        // Assert - проверяем что состояние не изменилось
        $this->assertFalse($this->service->isEnabled());
        $this->assertTrue(true); // Нет утечек памяти или исключений
    }

    #[Test]
    public function serviceImplementsTelemetryInterface(): void
    {
        // Assert
        $this->assertInstanceOf(
            \App\Service\Telemetry\TelemetryInterface::class,
            $this->service,
        );
    }

    // -------------------------------------------------------------------------
    // trackOperation() — Null Object поведение
    // -------------------------------------------------------------------------

    #[Test]
    public function trackOperationExecutesCallableAndReturnsResult(): void
    {
        // Null Object должен просто выполнить операцию и вернуть результат
        $result = $this->service->trackOperation('test.operation', fn () => 42);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function trackOperationReturnsStringResult(): void
    {
        $result = $this->service->trackOperation('test.string', fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    #[Test]
    public function trackOperationReturnsNullResult(): void
    {
        $result = $this->service->trackOperation('test.void', fn () => null);

        $this->assertNull($result);
    }

    #[Test]
    public function trackOperationReturnsArrayResult(): void
    {
        $expected = ['key' => 'value', 'number' => 42];

        $result = $this->service->trackOperation('test.array', fn () => $expected);

        $this->assertSame($expected, $result);
    }

    #[Test]
    public function trackOperationRethrowsException(): void
    {
        // Null Object не должен подавлять исключения из операции
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operation failed');

        $this->service->trackOperation('failing.operation', function (): never {
            throw new \RuntimeException('operation failed');
        });
    }

    #[Test]
    public function trackOperationIgnoresAttributes(): void
    {
        // Атрибуты принимаются, но не используются — не должно быть исключений
        $result = $this->service->trackOperation(
            'test.with_attrs',
            fn () => 'ok',
            ['model' => 'gpt-4', 'portal.member_id' => 'p123'],
        );

        $this->assertSame('ok', $result);
    }

    #[Test]
    public function trackOperationHasZeroOverheadVerification(): void
    {
        // Проверяем что операция выполняется ровно один раз (не 0, не 2)
        $callCount = 0;
        $this->service->trackOperation('test.count', function () use (&$callCount): void {
            ++$callCount;
        });

        $this->assertSame(1, $callCount);
    }
}
