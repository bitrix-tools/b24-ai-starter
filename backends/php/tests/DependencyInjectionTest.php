<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\MonologOTelHandler;
use App\Service\Telemetry\TelemetryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Интеграционные тесты для Dependency Injection телеметрии.
 *
 * Проверяем, что DI контейнер Symfony корректно конфигурирован:
 * - TelemetryInterface резолвится без ошибок
 * - Singleton behaviour (один экземпляр на запрос)
 * - Конфигурация читается из переменных окружения
 */
class DependencyInjectionTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function telemetryInterfaceCanBeResolvedFromContainer(): void
    {
        // Arrange
        $container = static::getContainer();

        // Act
        $telemetry = $container->get(TelemetryInterface::class);

        // Assert
        $this->assertInstanceOf(TelemetryInterface::class, $telemetry);
        $this->assertNotNull($telemetry);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function telemetryServiceIsSingleton(): void
    {
        // Arrange
        $container = static::getContainer();

        // Act - получаем сервис дважды
        $telemetry1 = $container->get(TelemetryInterface::class);
        $telemetry2 = $container->get(TelemetryInterface::class);

        // Assert - должен быть один и тот же экземпляр
        $this->assertSame($telemetry1, $telemetry2);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function telemetryServiceIsEnabledReflectsConfiguration(): void
    {
        // Arrange
        $container = static::getContainer();
        $telemetry = $container->get(TelemetryInterface::class);

        // Act
        $isEnabled = $telemetry->isEnabled();

        // Assert - по умолчанию TELEMETRY_ENABLED=false в тестовом окружении
        $this->assertFalse($isEnabled);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function telemetryServiceCanTrackEventsWithoutErrors(): void
    {
        // Arrange
        $container = static::getContainer();
        $telemetry = $container->get(TelemetryInterface::class);

        // Act & Assert - не должно быть исключений
        $telemetry->trackEvent('test_event_from_di', [
            'source' => 'dependency_injection_test',
            'timestamp' => time(),
        ]);

        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function telemetryServiceCanTrackErrorsWithoutExceptions(): void
    {
        // Arrange
        $container = static::getContainer();
        $telemetry = $container->get(TelemetryInterface::class);
        $testException = new \RuntimeException('Test exception for telemetry');

        // Act & Assert - не должно быть исключений
        $telemetry->trackError($testException, [
            'context' => 'dependency_injection_test',
        ]);

        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function monologOtelHandlerCanBeResolvedFromContainer(): void
    {
        // Arrange
        $container = static::getContainer();

        // Act
        $handler = $container->get(MonologOTelHandler::class);

        // Assert
        $this->assertInstanceOf(MonologOTelHandler::class, $handler);
    }
}
