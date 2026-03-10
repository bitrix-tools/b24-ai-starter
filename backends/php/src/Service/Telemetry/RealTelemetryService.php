<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use App\Service\Telemetry\Config\OtlpConfig;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Реальная реализация телеметрии с отправкой через OTLP Logs.
 *
 * Отправляет log-записи в OpenTelemetry Collector через /v1/logs.
 * Данные попадают в ClickHouse otel_logs и далее в Materialized Views,
 * которые питают дашборды Grafana.
 *
 * Ключевые атрибуты:
 * - telemetry.channel = "analytics" | "support" (обязательно для MV-фильтров)
 * - event.name     — имя события (для logs_analytics_mv)
 * - portal.member_id / portal.domain — идентификация портала
 * - session_id     — сессия пользователя
 */
final class RealTelemetryService implements TelemetryInterface
{
    private LoggerProvider $loggerProvider;

    private TracerProvider $tracerProvider;

    private bool $initialized = false;

    public function __construct(
        private readonly OtlpConfig $config,
        private readonly ?AttributeGroupManager $attributeGroupManager = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->initialize();
    }

    /**
     * Инициализация OpenTelemetry Logs SDK.
     */
    private function initialize(): void
    {
        try {
            // Resource: service.name → становится ServiceName в ClickHouse otel_logs
            $resource = ResourceInfoFactory::emptyResource()->merge(
                ResourceInfo::create(Attributes::create([
                    'service.name'           => $this->config->getServiceName(),
                    'service.version'        => $this->config->getServiceVersion(),
                    'deployment.environment' => $this->config->getEnvironment(),
                ])),
            );

            // OTLP HTTP transport → /v1/logs (не /v1/traces!)
            // Используем NativeHttpClient (PHP stream wrapper) вместо CurlMultiClient.
            // OtlpHttpTransportFactory → php-http/discovery → Symfony CurlMultiHandle
            // блокирует на emit() внутри Docker при host.docker.internal.
            // NativeHttpClient использует обычные PHP streams — надёжно и синхронно.
            $httpClient = new Psr18Client(new NativeHttpClient(['timeout' => 5]));
            $transport = (new PsrTransportFactory($httpClient))->create(
                $this->config->getEndpoint().'/v1/logs',
                'application/json',
            );

            $logsExporter = new LogsExporter($transport);

            $this->loggerProvider = LoggerProvider::builder()
                ->addLogRecordProcessor(new SimpleLogRecordProcessor($logsExporter))
                ->setResource($resource)
                ->build();

            // OTLP Traces transport → /v1/traces → ClickHouse otel_traces
            $tracesTransport = (new PsrTransportFactory($httpClient))->create(
                $this->config->getEndpoint().'/v1/traces',
                'application/json',
            );

            $this->tracerProvider = TracerProvider::builder()
                ->addSpanProcessor(new SimpleSpanProcessor(new SpanExporter($tracesTransport)))
                ->setResource($resource)
                ->build();

            $this->initialized = true;

            if ($this->logger instanceof LoggerInterface) {
                $this->logger->info('OpenTelemetry Logs initialized successfully', [
                    'endpoint' => $this->config->getEndpoint().'/v1/logs',
                    'service'  => $this->config->getServiceName(),
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->initialized = false;
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to initialize OpenTelemetry Logs', [
                    'error'  => $throwable->getMessage(),
                    'config' => $this->config->toArray(),
                ]);
            }
        }
    }

    public function trackEvent(string $name, array $attributes = []): void
    {
        if (!$this->initialized) {
            return;
        }

        try {
            // Фильтруем атрибуты согласно активному профилю
            $filteredAttributes = $this->filterAttributes($attributes);

            // Обязательные атрибуты для logs_analytics_mv в ClickHouse
            $logAttributes = array_merge(
                [
                    'telemetry.channel' => 'analytics',
                    'event.name'        => $name,
                ],
                $this->flattenAttributes($filteredAttributes),
            );

            $nowNs = (int) (microtime(true) * 1e9);
            $logRecord = (new LogRecord())
                ->setTimestamp($nowNs)
                ->setObservedTimestamp($nowNs)
                ->setSeverityText('INFO')
                ->setSeverityNumber(Severity::INFO)
                ->setBody('Analytics event: ' . $name)
                ->setAttributes($logAttributes);

            $otelLogger = $this->loggerProvider->getLogger(
                'app.telemetry',
                $this->config->getServiceVersion(),
            );
            $otelLogger->emit($logRecord);
        } catch (\Throwable $throwable) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to track event', [
                    'event' => $name,
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }

    public function trackError(\Throwable $exception, array $context = []): void
    {
        if (!$this->initialized) {
            return;
        }

        try {
            // Фильтруем контекст согласно активному профилю
            $filteredContext = $this->filterAttributes($context);

            // Обязательные атрибуты для logs_support_mv в ClickHouse
            $logAttributes = array_merge(
                [
                    'telemetry.channel' => 'support',
                    'error.class'       => get_class($exception),
                    'error.message'     => $exception->getMessage(),
                    'error.source'      => $exception->getFile().':'.$exception->getLine(),
                ],
                $this->flattenAttributes($filteredContext),
            );

            $nowNs = (int) (microtime(true) * 1e9);
            $logRecord = (new LogRecord())
                ->setTimestamp($nowNs)
                ->setObservedTimestamp($nowNs)
                ->setSeverityText('ERROR')
                ->setSeverityNumber(Severity::ERROR)
                ->setBody('Error: '.$exception->getMessage())
                ->setAttributes($logAttributes);

            $otelLogger = $this->loggerProvider->getLogger(
                'app.telemetry',
                $this->config->getServiceVersion(),
            );
            $otelLogger->emit($logRecord);
        } catch (\Throwable $throwable) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to track error', [
                    'original_exception' => $exception->getMessage(),
                    'tracking_error'     => $throwable->getMessage(),
                ]);
            }
        }
    }

    public function trackOperation(string $name, callable $operation, array $attributes = []): mixed
    {
        if (!$this->initialized) {
            return $operation();
        }

        $tracer = $this->tracerProvider->getTracer(
            'app.telemetry',
            $this->config->getServiceVersion(),
        );

        $span = $tracer->spanBuilder($name)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->startSpan();

        $scope = $span->activate();

        try {
            foreach ($this->flattenAttributes($this->filterAttributes($attributes)) as $key => $value) {
                $span->setAttribute($key, $value);
            }

            $result = $operation();

            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
            throw $throwable;
        } finally {
            $span->end();
            $scope->detach();
        }
    }

    public function isEnabled(): bool
    {
        return $this->initialized;
    }

    public function shutdown(): void
    {
        if (!$this->initialized) {
            return;
        }

        try {
            $this->loggerProvider->shutdown();
            $this->tracerProvider->shutdown();

            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('Telemetry shutdown completed');
            }
        } catch (\Throwable $throwable) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('Failed to shutdown telemetry', [
                    'error' => $throwable->getMessage(),
                ]);
            }
        }
    }

    /**
     * Фильтрует атрибуты согласно активному профилю.
     *
     * @param array<string, mixed> $attributes Исходные атрибуты
     *
     * @return array<string, mixed> Отфильтрованные атрибуты
     */
    private function filterAttributes(array $attributes): array
    {
        if (!$this->attributeGroupManager instanceof AttributeGroupManager) {
            return $attributes;
        }

        $filtered = $this->attributeGroupManager->filterAttributes($attributes);

        if ($this->logger instanceof LoggerInterface) {
            $filteredOut = $this->attributeGroupManager->getFilteredOutAttributes($attributes);
            if ([] !== $filteredOut) {
                $this->logger->debug('Telemetry attributes filtered by profile', [
                    'filtered_out'       => array_keys($filteredOut),
                    'filtered_out_count' => count($filteredOut),
                ]);
            }
        }

        return $filtered;
    }

    /**
     * Преобразует массив атрибутов в плоский вид: скаляры остаются, массивы → JSON.
     * OTel LogRecord принимает только scalar-значения.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, bool|float|int|string>
     */
    private function flattenAttributes(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            if (is_scalar($value) || null === $value) {
                $result[$key] = $value ?? '';
            } elseif (is_array($value)) {
                $result[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $result;
    }
}
