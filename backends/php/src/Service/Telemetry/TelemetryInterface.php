<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

/**
 * Интерфейс сервиса телеметрии.
 *
 * Определяет контракт для отслеживания событий и ошибок в приложении.
 * Поддерживает паттерн Null Object для zero overhead при отключенной телеметрии.
 */
interface TelemetryInterface
{
    /**
     * Отслеживание события с произвольными атрибутами.
     *
     * @param string               $name       Имя события (snake_case)
     * @param array<string, mixed> $attributes Атрибуты события (плоский ассоциативный массив)
     */
    public function trackEvent(string $name, array $attributes = []): void;

    /**
     * Отслеживание ошибки/исключения.
     *
     * @param \Throwable           $throwable Исключение для логирования
     * @param array<string, mixed> $context   Дополнительный контекст ошибки
     */
    public function trackError(\Throwable $throwable, array $context = []): void;

    /**
     * Проверка активности телеметрии.
     *
     * @return bool True если телеметрия включена и отправляет данные
     */
    public function isEnabled(): bool;

    /**
     * Замеряет длительность выполнения операции и записывает trace-span в otel_traces.
     *
     * Используйте вместо trackEvent() когда важна длительность: вызовы AI-API,
     * обращения к Bitrix24, тяжёлые вычисления. Span автоматически содержит
     * StartTime, EndTime и Duration, видимые в Grafana.
     *
     * @template T
     * @param string               $name       Имя span'а в формате «сервис.действие» (e.g. 'ai.claude.request')
     * @param callable(): T        $operation  Операция для выполнения и замера
     * @param array<string, mixed> $attributes SpanAttributes (модель, portal_id и т.п.)
     * @return T Возвращает результат $operation без изменений
     * @throws \Throwable Пробрасывает исключения из $operation после записи в span
     */
    public function trackOperation(string $name, callable $operation, array $attributes = []): mixed;

    /**
     * Завершает работу телеметрии и отправляет оставшиеся данные.
     *
     * Вызывается при завершении работы приложения для гарантированной
     * отправки всех накопленных событий.
     */
    public function shutdown(): void;
}
