<?php

declare(strict_types=1);

namespace App\Tests\Telemetry\Config;

use App\Service\Telemetry\Config\OtlpConfig;
use PHPUnit\Framework\TestCase;

/**
 * Тесты для OtlpConfig.
 */
class OtlpConfigTest extends TestCase
{
    private const VALID_ENDPOINT = 'http://localhost:4318';
    private const VALID_SERVICE_NAME = 'test-service';
    private const VALID_SERVICE_VERSION = '1.0.0';
    private const VALID_ENVIRONMENT = 'test';

    public function testCreateConfigWithValidParameters(): void
    {
        $config = new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );

        $this->assertSame(self::VALID_ENDPOINT, $config->getEndpoint());
        $this->assertSame(self::VALID_SERVICE_NAME, $config->getServiceName());
        $this->assertSame(self::VALID_SERVICE_VERSION, $config->getServiceVersion());
        $this->assertSame(self::VALID_ENVIRONMENT, $config->getEnvironment());
    }

    public function testDefaultValuesAreApplied(): void
    {
        $config = new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );

        // Проверяем дефолтные значения
        $this->assertSame(10000, $config->getTimeoutMs());
        $this->assertSame(3, $config->getRetryAttempts());
        $this->assertSame(1000, $config->getRetryDelayMs());
        $this->assertSame(100, $config->getBatchSize());
        $this->assertSame(5000, $config->getBatchTimeoutMs());
    }

    public function testCustomValuesOverrideDefaults(): void
    {
        $config = new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            5000,  // timeoutMs
            5,     // retryAttempts
            2000,  // retryDelayMs
            50,    // batchSize
            3000,   // batchTimeoutMs
        );

        $this->assertSame(5000, $config->getTimeoutMs());
        $this->assertSame(5, $config->getRetryAttempts());
        $this->assertSame(2000, $config->getRetryDelayMs());
        $this->assertSame(50, $config->getBatchSize());
        $this->assertSame(3000, $config->getBatchTimeoutMs());
    }

    public function testInvalidEndpointThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid endpoint URL');

        new OtlpConfig(
            'not-a-valid-url',
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testEmptyEndpointThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint cannot be empty');

        new OtlpConfig(
            '',
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testInvalidEndpointSchemeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Endpoint must use http or https scheme');

        new OtlpConfig(
            'ftp://localhost:4318',
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testEmptyServiceNameThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name cannot be empty');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            '',
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testInvalidServiceNameFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name must contain only alphanumeric characters');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            'invalid_service_name',  // underscore not allowed
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testServiceNameTooLongThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name cannot exceed 255 characters');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            str_repeat('a', 256),
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
        );
    }

    public function testEmptyServiceVersionThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service version cannot be empty');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            '',
            self::VALID_ENVIRONMENT,
        );
    }

    public function testServiceVersionTooLongThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service version cannot exceed 100 characters');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            str_repeat('1', 101),
            self::VALID_ENVIRONMENT,
        );
    }

    public function testEmptyEnvironmentThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment cannot be empty');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            '',
        );
    }

    public function testInvalidEnvironmentThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid environment');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            'invalid-env',
        );
    }

    /**
     * @dataProvider validEnvironmentProvider
     */
    public function testValidEnvironments(string $environment): void
    {
        $config = new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            $environment,
        );

        $this->assertSame($environment, $config->getEnvironment());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validEnvironmentProvider(): array
    {
        return [
            'development' => ['development'],
            'dev' => ['dev'],
            'staging' => ['staging'],
            'stage' => ['stage'],
            'production' => ['production'],
            'prod' => ['prod'],
            'test' => ['test'],
            'PRODUCTION uppercase' => ['PRODUCTION'], // case insensitive check
            'TEST uppercase' => ['TEST'],
        ];
    }

    public function testTimeoutTooLowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1000 and 60000 milliseconds');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            999,  // Too low
        );
    }

    public function testTimeoutTooHighThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1000 and 60000 milliseconds');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            60001,  // Too high
        );
    }

    public function testRetryAttemptsTooLowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry attempts must be between 0 and 10');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            null,
            -1,  // Negative not allowed
        );
    }

    public function testRetryAttemptsTooHighThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry attempts must be between 0 and 10');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            null,
            11,  // Too many
        );
    }

    public function testBatchSizeTooLowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be between 1 and 1000');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            null,
            null,
            null,
            0,  // Too low
        );
    }

    public function testBatchSizeTooHighThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Batch size must be between 1 and 1000');

        new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            null,
            null,
            null,
            1001,  // Too high
        );
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $config = new OtlpConfig(
            self::VALID_ENDPOINT,
            self::VALID_SERVICE_NAME,
            self::VALID_SERVICE_VERSION,
            self::VALID_ENVIRONMENT,
            5000,
            5,
            2000,
            50,
            3000,
        );

        $array = $config->toArray();

        $this->assertIsArray($array);
        $this->assertSame(self::VALID_ENDPOINT, $array['endpoint']);
        $this->assertSame(self::VALID_SERVICE_NAME, $array['service_name']);
        $this->assertSame(self::VALID_SERVICE_VERSION, $array['service_version']);
        $this->assertSame(self::VALID_ENVIRONMENT, $array['environment']);
        $this->assertSame(5000, $array['timeout_ms']);
        $this->assertSame(5, $array['retry_attempts']);
        $this->assertSame(2000, $array['retry_delay_ms']);
        $this->assertSame(50, $array['batch_size']);
        $this->assertSame(3000, $array['batch_timeout_ms']);
    }

    public function testFromEnvironmentWithDefaults(): void
    {
        // Устанавливаем минимальные переменные окружения
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://test:4318');
        putenv('OTEL_SERVICE_NAME=env-service');
        putenv('OTEL_SERVICE_VERSION=2.0.0');
        putenv('OTEL_ENVIRONMENT=production');

        $config = OtlpConfig::fromEnvironment();

        $this->assertSame('http://test:4318', $config->getEndpoint());
        $this->assertSame('env-service', $config->getServiceName());
        $this->assertSame('2.0.0', $config->getServiceVersion());
        $this->assertSame('production', $config->getEnvironment());

        // Проверяем дефолты
        $this->assertSame(10000, $config->getTimeoutMs());
        $this->assertSame(3, $config->getRetryAttempts());

        // Очищаем переменные
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_SERVICE_VERSION');
        putenv('OTEL_ENVIRONMENT');
    }

    public function testFromEnvironmentWithCustomValues(): void
    {
        // Устанавливаем все переменные
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://custom:4318');
        putenv('OTEL_SERVICE_NAME=custom-service');
        putenv('OTEL_SERVICE_VERSION=3.0.0');
        putenv('OTEL_ENVIRONMENT=staging');
        putenv('OTEL_EXPORTER_OTLP_TIMEOUT=15000');
        putenv('OTEL_EXPORTER_OTLP_RETRY_ATTEMPTS=5');
        putenv('OTEL_EXPORTER_OTLP_RETRY_DELAY=2000');
        putenv('OTEL_EXPORTER_OTLP_BATCH_SIZE=200');
        putenv('OTEL_EXPORTER_OTLP_BATCH_TIMEOUT=8000');

        $config = OtlpConfig::fromEnvironment();

        $this->assertSame('http://custom:4318', $config->getEndpoint());
        $this->assertSame('custom-service', $config->getServiceName());
        $this->assertSame('3.0.0', $config->getServiceVersion());
        $this->assertSame('staging', $config->getEnvironment());
        $this->assertSame(15000, $config->getTimeoutMs());
        $this->assertSame(5, $config->getRetryAttempts());
        $this->assertSame(2000, $config->getRetryDelayMs());
        $this->assertSame(200, $config->getBatchSize());
        $this->assertSame(8000, $config->getBatchTimeoutMs());

        // Очищаем переменные
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_SERVICE_VERSION');
        putenv('OTEL_ENVIRONMENT');
        putenv('OTEL_EXPORTER_OTLP_TIMEOUT');
        putenv('OTEL_EXPORTER_OTLP_RETRY_ATTEMPTS');
        putenv('OTEL_EXPORTER_OTLP_RETRY_DELAY');
        putenv('OTEL_EXPORTER_OTLP_BATCH_SIZE');
        putenv('OTEL_EXPORTER_OTLP_BATCH_TIMEOUT');
    }
}
