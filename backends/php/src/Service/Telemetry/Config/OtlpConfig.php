<?php

declare(strict_types=1);

namespace App\Service\Telemetry\Config;

/**
 * Конфигурация OTLP экспорта.
 *
 * Хранит и валидирует настройки для отправки телеметрии через OTLP.
 */
final class OtlpConfig
{
    private const DEFAULT_TIMEOUT_MS = 10000;

    // 10 секунд
    private const DEFAULT_RETRY_ATTEMPTS = 3;

    private const DEFAULT_RETRY_DELAY_MS = 1000;

    // 1 секунда
    private const DEFAULT_BATCH_SIZE = 100;

    private const DEFAULT_BATCH_TIMEOUT_MS = 5000; // 5 секунд

    private string $endpoint;

    private string $serviceName;

    private string $serviceVersion;

    private string $environment;

    private int $timeoutMs;

    private int $retryAttempts;

    private int $retryDelayMs;

    private int $batchSize;

    private int $batchTimeoutMs;

    /**
     * @param string   $endpoint       OTLP endpoint URL (e.g., http://localhost:4318)
     * @param string   $serviceName    Service name for identification
     * @param string   $serviceVersion Service version
     * @param string   $environment    Environment name (development, staging, production)
     * @param int|null $timeoutMs      Connection timeout in milliseconds
     * @param int|null $retryAttempts  Number of retry attempts on failure
     * @param int|null $retryDelayMs   Delay between retries in milliseconds
     * @param int|null $batchSize      Number of spans to batch before sending
     * @param int|null $batchTimeoutMs Max time to wait for batch fill in milliseconds
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $endpoint,
        string $serviceName,
        string $serviceVersion,
        string $environment,
        ?int $timeoutMs = null,
        ?int $retryAttempts = null,
        ?int $retryDelayMs = null,
        ?int $batchSize = null,
        ?int $batchTimeoutMs = null,
    ) {
        $this->validateEndpoint($endpoint);
        $this->validateServiceName($serviceName);
        $this->validateServiceVersion($serviceVersion);
        $this->validateEnvironment($environment);

        $this->endpoint = $endpoint;
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
        $this->environment = $environment;
        $this->timeoutMs = $timeoutMs ?? self::DEFAULT_TIMEOUT_MS;
        $this->retryAttempts = $retryAttempts ?? self::DEFAULT_RETRY_ATTEMPTS;
        $this->retryDelayMs = $retryDelayMs ?? self::DEFAULT_RETRY_DELAY_MS;
        $this->batchSize = $batchSize ?? self::DEFAULT_BATCH_SIZE;
        $this->batchTimeoutMs = $batchTimeoutMs ?? self::DEFAULT_BATCH_TIMEOUT_MS;

        $this->validateTimeouts();
        $this->validateRetrySettings();
        $this->validateBatchSettings();
    }

    /**
     * Создать конфигурацию из переменных окружения.
     */
    public static function fromEnvironment(): self
    {
        $endpoint = getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://localhost:4318';
        $serviceName = getenv('OTEL_SERVICE_NAME') ?: 'b24-app';
        $serviceVersion = getenv('OTEL_SERVICE_VERSION') ?: '1.0.0';
        $environment = getenv('OTEL_ENVIRONMENT') ?: 'development';

        $timeoutMs = getenv('OTEL_EXPORTER_OTLP_TIMEOUT');
        $retryAttempts = getenv('OTEL_EXPORTER_OTLP_RETRY_ATTEMPTS');
        $retryDelayMs = getenv('OTEL_EXPORTER_OTLP_RETRY_DELAY');
        $batchSize = getenv('OTEL_EXPORTER_OTLP_BATCH_SIZE');
        $batchTimeoutMs = getenv('OTEL_EXPORTER_OTLP_BATCH_TIMEOUT');

        return new self(
            $endpoint,
            $serviceName,
            $serviceVersion,
            $environment,
            false !== $timeoutMs ? (int) $timeoutMs : null,
            false !== $retryAttempts ? (int) $retryAttempts : null,
            false !== $retryDelayMs ? (int) $retryDelayMs : null,
            false !== $batchSize ? (int) $batchSize : null,
            false !== $batchTimeoutMs ? (int) $batchTimeoutMs : null,
        );
    }

    // Getters

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function getServiceVersion(): string
    {
        return $this->serviceVersion;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    public function getRetryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function getBatchTimeoutMs(): int
    {
        return $this->batchTimeoutMs;
    }

    // Validation methods

    private function validateEndpoint(string $endpoint): void
    {
        if ('' === $endpoint || '0' === $endpoint) {
            throw new \InvalidArgumentException('Endpoint cannot be empty');
        }

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid endpoint URL: '.$endpoint);
        }

        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Endpoint must use http or https scheme: '.$endpoint);
        }
    }

    private function validateServiceName(string $serviceName): void
    {
        if ('' === $serviceName || '0' === $serviceName) {
            throw new \InvalidArgumentException('Service name cannot be empty');
        }

        if (strlen($serviceName) > 255) {
            throw new \InvalidArgumentException('Service name cannot exceed 255 characters');
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $serviceName)) {
            throw new \InvalidArgumentException('Service name must contain only alphanumeric characters and hyphens, and cannot start or end with a hyphen');
        }
    }

    private function validateServiceVersion(string $serviceVersion): void
    {
        if ('' === $serviceVersion || '0' === $serviceVersion) {
            throw new \InvalidArgumentException('Service version cannot be empty');
        }

        if (strlen($serviceVersion) > 100) {
            throw new \InvalidArgumentException('Service version cannot exceed 100 characters');
        }
    }

    private function validateEnvironment(string $environment): void
    {
        if ('' === $environment || '0' === $environment) {
            throw new \InvalidArgumentException('Environment cannot be empty');
        }

        $validEnvironments = ['development', 'dev', 'staging', 'stage', 'production', 'prod', 'test'];
        if (!in_array(strtolower($environment), $validEnvironments, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid environment: %s. ', $environment).'Must be one of: '.implode(', ', $validEnvironments));
        }
    }

    private function validateTimeouts(): void
    {
        if ($this->timeoutMs < 1000 || $this->timeoutMs > 60000) {
            throw new \InvalidArgumentException('Timeout must be between 1000 and 60000 milliseconds, got '.$this->timeoutMs);
        }

        if ($this->batchTimeoutMs < 100 || $this->batchTimeoutMs > 30000) {
            throw new \InvalidArgumentException('Batch timeout must be between 100 and 30000 milliseconds, got '.$this->batchTimeoutMs);
        }
    }

    private function validateRetrySettings(): void
    {
        if ($this->retryAttempts < 0 || $this->retryAttempts > 10) {
            throw new \InvalidArgumentException('Retry attempts must be between 0 and 10, got '.$this->retryAttempts);
        }

        if ($this->retryDelayMs < 100 || $this->retryDelayMs > 10000) {
            throw new \InvalidArgumentException('Retry delay must be between 100 and 10000 milliseconds, got '.$this->retryDelayMs);
        }
    }

    private function validateBatchSettings(): void
    {
        if ($this->batchSize < 1 || $this->batchSize > 1000) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 1000, got '.$this->batchSize);
        }
    }

    /**
     * Конвертировать конфигурацию в массив для логирования.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'service_name' => $this->serviceName,
            'service_version' => $this->serviceVersion,
            'environment' => $this->environment,
            'timeout_ms' => $this->timeoutMs,
            'retry_attempts' => $this->retryAttempts,
            'retry_delay_ms' => $this->retryDelayMs,
            'batch_size' => $this->batchSize,
            'batch_timeout_ms' => $this->batchTimeoutMs,
        ];
    }
}
