<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

/**
 * Null Object реализация телеметрии.
 *
 * Используется когда TELEMETRY_ENABLED=false.
 * Обеспечивает zero overhead - все методы пустые, без side effects.
 */
final class NullTelemetryService implements TelemetryInterface
{
    public function trackEvent(string $name, array $attributes = []): void
    {
        // Intentionally empty - zero overhead
    }

    public function trackError(\Throwable $throwable, array $context = []): void
    {
        // Intentionally empty - zero overhead
    }

    public function trackOperation(string $name, callable $operation, array $attributes = []): mixed
    {
        // Intentionally empty - zero overhead, operation is still executed
        return $operation();
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function shutdown(): void
    {
        // Intentionally empty - nothing to shutdown
    }
}
