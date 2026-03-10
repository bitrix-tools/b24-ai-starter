<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog Handler для отправки логов в OpenTelemetry.
 *
 * Автоматически преобразует Monolog записи в события телеметрии.
 * Поддерживает все уровни логирования и graceful degradation при отключенной телеметрии.
 *
 * Usage:
 * ```php
 * $handler = new MonologOTelHandler($telemetryService, Level::Info);
 * $logger->pushHandler($handler);
 * ```
 */
class MonologOTelHandler extends AbstractProcessingHandler
{
    /**
     * Флаг для защиты от рекурсии.
     *
     * TelemetryFactory::create() логирует через $this->logger в процессе инициализации.
     * Это вызывает MonologOTelHandler → lazy proxy TelemetryInterface → TelemetryFactory снова.
     * Symfony VarExporter бросает "LazyObjectState::$realInstance not initialized" → 500.
     * Статический флаг разрывает цикл: повторный вызов write() во время инициализации пропускается.
     */
    private static bool $handling = false;

    /**
     * Маппинг уровней Monolog → OpenTelemetry severity.
     *
     * @var array<string, string>
     */
    private const SEVERITY_MAP = [
        'DEBUG' => 'DEBUG',
        'INFO' => 'INFO',
        'NOTICE' => 'INFO',
        'WARNING' => 'WARN',
        'ERROR' => 'ERROR',
        'CRITICAL' => 'FATAL',
        'ALERT' => 'FATAL',
        'EMERGENCY' => 'FATAL',
    ];

    public function __construct(
        private readonly TelemetryInterface $telemetry,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
    }

    /**
     * Обработка записи лога и отправка в телеметрию.
     *
     * @param LogRecord $record Запись лога от Monolog
     */
    protected function write(LogRecord $record): void
    {
        // Защита от рекурсии: если мы уже обрабатываем запись (например, TelemetryFactory
        // логирует в процессе инициализации), пропускаем повторный вызов.
        if (self::$handling) {
            return;
        }

        self::$handling = true;
        try {
            // Если телеметрия отключена - ничего не делаем (graceful degradation)
            if (!$this->telemetry->isEnabled()) {
                return;
            }

            // Определяем тип события по уровню лога
            $isError = $record->level->value >= Level::Error->value;

            if ($isError && isset($record->context['exception']) && $record->context['exception'] instanceof \Throwable) {
                // Если это ERROR+ уровень с исключением - используем trackError
                $exception = $record->context['exception'];
                $context = $this->prepareContext($record);
                unset($context['exception']); // Исключение передается отдельно

                $this->telemetry->trackError($exception, $context);
            } else {
                // Для остальных случаев - используем trackEvent
                $eventName = $this->buildEventName($record);
                $attributes = $this->prepareAttributes($record);

                $this->telemetry->trackEvent($eventName, $attributes);
            }
        } finally {
            self::$handling = false;
        }
    }

    /**
     * Генерирует имя события на основе уровня и канала лога.
     *
     * @param LogRecord $record Запись лога
     *
     * @return string Имя события в формате "log.{level}.{channel}"
     */
    private function buildEventName(LogRecord $record): string
    {
        $level = strtolower($record->level->getName());
        $channel = strtolower($record->channel);

        return "log.{$level}.{$channel}";
    }

    /**
     * Подготавливает атрибуты для trackEvent из записи лога.
     *
     * @param LogRecord $record Запись лога
     *
     * @return array<string, mixed> Атрибуты события
     */
    private function prepareAttributes(LogRecord $record): array
    {
        $attributes = [
            'telemetry.channel' => 'system',
            'log.level' => $record->level->getName(),
            'log.severity' => self::SEVERITY_MAP[$record->level->getName()] ?? 'INFO',
            'log.channel' => $record->channel,
            'log.message' => $record->message,
            'log.timestamp' => $record->datetime->getTimestamp(),
        ];

        // Добавляем контекст
        foreach ($record->context as $key => $value) {
            // Пропускаем exception - он обрабатывается отдельно
            if ($key === 'exception') {
                continue;
            }

            // Преобразуем сложные типы в строки
            $attributes["context.{$key}"] = $this->normalizeValue($value);
        }

        // Добавляем extra данные
        foreach ($record->extra as $key => $value) {
            $attributes["extra.{$key}"] = $this->normalizeValue($value);
        }

        return $attributes;
    }

    /**
     * Подготавливает контекст для trackError из записи лога.
     *
     * @param LogRecord $record Запись лога
     *
     * @return array<string, mixed> Контекст ошибки
     */
    private function prepareContext(LogRecord $record): array
    {
        $context = [
            'telemetry.channel' => 'system',
            'log.level' => $record->level->getName(),
            'log.severity' => self::SEVERITY_MAP[$record->level->getName()] ?? 'ERROR',
            'log.channel' => $record->channel,
            'log.message' => $record->message,
            'log.timestamp' => $record->datetime->getTimestamp(),
        ];

        // Добавляем весь контекст (кроме exception)
        foreach ($record->context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }
            $context["context.{$key}"] = $this->normalizeValue($value);
        }

        // Добавляем extra данные
        foreach ($record->extra as $key => $value) {
            $context["extra.{$key}"] = $this->normalizeValue($value);
        }

        return $context;
    }

    /**
     * Нормализует значение для безопасной передачи в телеметрию.
     *
     * Преобразует сложные типы (объекты, массивы) в строки.
     *
     * @param mixed $value Исходное значение
     *
     * @return string|int|float|bool Нормализованное значение
     */
    private function normalizeValue(mixed $value): string|int|float|bool
    {
        if (is_scalar($value) || $value === null) {
            return $value ?? '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return get_class($value);
        }

        if (is_resource($value)) {
            return sprintf('resource(%s)', get_resource_type($value));
        }

        return 'unknown';
    }
}
