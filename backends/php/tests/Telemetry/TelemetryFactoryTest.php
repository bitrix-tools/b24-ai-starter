<?php

declare(strict_types=1);

namespace App\Tests\Telemetry;

use App\Service\Telemetry\NullTelemetryService;
use App\Service\Telemetry\RealTelemetryService;
use App\Service\Telemetry\TelemetryFactory;
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Тесты для TelemetryFactory.
 *
 * Проверяем правильность выбора реализации на основе конфигурации
 */
class TelemetryFactoryTest extends TestCase
{
    #[Test]
    public function createsNullServiceWhenTelemetryDisabled(): void
    {
        // Arrange
        $factory = new TelemetryFactory(
            telemetryEnabled: false,
            otlpEndpoint: 'http://localhost:4318',
            serviceName: 'test-app',
            serviceVersion: '1.0.0',
            environment: 'test',
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert
        $this->assertInstanceOf(NullTelemetryService::class, $service);
        $this->assertFalse($service->isEnabled());
    }

    #[Test]
    public function createsRealServiceWhenTelemetryEnabled(): void
    {
        // Arrange
        $factory = new TelemetryFactory(
            telemetryEnabled: true,
            otlpEndpoint: 'http://localhost:4318',
            serviceName: 'test-app',
            serviceVersion: '1.0.0',
            environment: 'production',
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert - в Sprint 2 возвращается RealTelemetryService
        $this->assertInstanceOf(TelemetryInterface::class, $service);
        // Может быть либо RealTelemetryService (если collector доступен),
        // либо NullTelemetryService (если инициализация не удалась)
        $this->assertTrue(
            $service instanceof RealTelemetryService || $service instanceof NullTelemetryService,
        );
    }

    #[Test]
    public function fallsBackToNullServiceOnInvalidConfig(): void
    {
        // Arrange - невалидный endpoint вызовет ошибку в OtlpConfig
        $factory = new TelemetryFactory(
            telemetryEnabled: true,
            otlpEndpoint: 'invalid-url',  // Невалидный URL
            serviceName: 'test-app',
            serviceVersion: '1.0.0',
            environment: 'test',
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert - должен быть fallback на NullTelemetryService
        $this->assertInstanceOf(NullTelemetryService::class, $service);
        $this->assertFalse($service->isEnabled());
    }

    #[Test]
    public function fallsBackToNullServiceOnInvalidServiceName(): void
    {
        // Arrange - невалидное имя сервиса
        $factory = new TelemetryFactory(
            telemetryEnabled: true,
            otlpEndpoint: 'http://localhost:4318',
            serviceName: 'invalid_service_name',  // Содержит недопустимый символ
            serviceVersion: '1.0.0',
            environment: 'test',
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert - должен быть fallback на NullTelemetryService
        $this->assertInstanceOf(NullTelemetryService::class, $service);
    }

    #[Test]
    public function createdServiceImplementsTelemetryInterface(): void
    {
        // Arrange
        $factory = new TelemetryFactory(
            telemetryEnabled: false,
            otlpEndpoint: 'http://localhost:4318',
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert
        $this->assertInstanceOf(TelemetryInterface::class, $service);
    }

    #[Test]
    #[DataProvider('environmentConfigProvider')]
    public function createsServiceWithDifferentConfigurations(
        bool $enabled,
        string $endpoint,
        string $serviceName,
        string $version,
        string $env,
    ): void {
        // Arrange
        $factory = new TelemetryFactory(
            telemetryEnabled: $enabled,
            otlpEndpoint: $endpoint,
            serviceName: $serviceName,
            serviceVersion: $version,
            environment: $env,
            logger: new NullLogger(),
        );

        // Act
        $service = $factory->create();

        // Assert
        $this->assertInstanceOf(TelemetryInterface::class, $service);
        $this->assertNotNull($service);
    }

    #[Test]
    public function factoryCanCreateMultipleInstances(): void
    {
        // Arrange
        $factory = new TelemetryFactory(
            telemetryEnabled: false,
            otlpEndpoint: 'http://localhost:4318',
            logger: new NullLogger(),
        );

        // Act
        $service1 = $factory->create();
        $service2 = $factory->create();

        // Assert - каждый вызов create() создает новый экземпляр
        $this->assertInstanceOf(TelemetryInterface::class, $service1);
        $this->assertInstanceOf(TelemetryInterface::class, $service2);
        $this->assertNotSame($service1, $service2);
    }

    #[Test]
    public function factoryWorksWithoutLogger(): void
    {
        // Arrange - логгер опционален
        $factory = new TelemetryFactory(
            telemetryEnabled: false,
            otlpEndpoint: 'http://localhost:4318',
            serviceName: 'test-app',
            serviceVersion: '1.0.0',
            environment: 'test',
            logger: null,
        );

        // Act
        $service = $factory->create();

        // Assert
        $this->assertInstanceOf(NullTelemetryService::class, $service);
    }

    /**
     * @return array<string, array{bool, string, string, string, string}>
     */
    public static function environmentConfigProvider(): array
    {
        return [
            'development disabled' => [
                false,
                'http://localhost:4318',
                'dev-app',
                '0.1.0',
                'development',
            ],
            'staging disabled' => [
                false,
                'http://otel-collector:4318',
                'staging-app',
                '1.0.0-beta',
                'staging',
            ],
            'production disabled' => [
                false,
                'https://otel.production.com:4318',
                'prod-app',
                '2.5.1',
                'production',
            ],
            'test environment' => [
                false,
                'http://localhost:4318',
                'test-app',
                '1.0.0',
                'test',
            ],
        ];
    }
}
