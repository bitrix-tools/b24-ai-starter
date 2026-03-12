<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * DTO для фронтенд-события телеметрии.
 *
 * Валидирует и хранит данные, пришедшие от Nuxt-фронтенда через
 * POST /api/telemetry/event. Защищает систему:
 * - от произвольных имён событий (whitelist)
 * - от payload-флуда (max 30 атрибутов)
 * - от слишком длинных значений (max 512 символов)
 * - от некорректных ключей (только [a-z0-9._])
 */
final class FrontendTelemetryEvent
{
    /** Максимальное количество атрибутов в одном событии */
    private const int MAX_ATTRIBUTES = 30;

    /** Максимальная длина значения атрибута (символов) */
    private const int MAX_VALUE_LENGTH = 512;

    /** Паттерн допустимых ключей атрибутов */
    private const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._]*$/';

    /**
     * @param string               $eventName         Имя события из whitelist
     * @param array<string, mixed> $attributes        Атрибуты события
     * @param int|null             $clientTimestampMs Unix timestamp в миллисекундах (с фронта)
     */
    private function __construct(
        private readonly string $eventName,
        private readonly array $attributes,
        private readonly ?int $clientTimestampMs,
    ) {
    }

    /**
     * Создать DTO из сырого массива данных запроса.
     *
     * @param array<string, mixed> $data      Декодированное тело POST-запроса
     * @param list<string>         $whitelist Допустимые имена событий из конфигурации
     *
     * @throws \InvalidArgumentException При ошибке валидации
     */
    public static function fromArray(array $data, array $whitelist): self
    {
        // Проверка имени события
        if (!isset($data['event_name']) || !is_string($data['event_name'])) {
            throw new \InvalidArgumentException('Missing or invalid "event_name" field');
        }

        $eventName = $data['event_name'];

        if (!in_array($eventName, $whitelist, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown event name "%s". Allowed: %s', $eventName, implode(', ', $whitelist)));
        }

        // Проверка атрибутов
        $attributes = [];
        if (isset($data['attributes'])) {
            if (!is_array($data['attributes'])) {
                throw new \InvalidArgumentException('"attributes" must be an object/array');
            }

            if (count($data['attributes']) > self::MAX_ATTRIBUTES) {
                throw new \InvalidArgumentException(sprintf('Too many attributes: %d (max %d)', count($data['attributes']), self::MAX_ATTRIBUTES));
            }

            foreach ($data['attributes'] as $key => $value) {
                if (!is_string($key)) {
                    throw new \InvalidArgumentException('Attribute keys must be strings');
                }

                if (!preg_match(self::KEY_PATTERN, $key)) {
                    throw new \InvalidArgumentException(sprintf('Invalid attribute key "%s". Allowed: lowercase letters, digits, dots, underscores', $key));
                }

                $stringValue = is_scalar($value) ? (string) $value : json_encode($value);

                if (mb_strlen($stringValue) > self::MAX_VALUE_LENGTH) {
                    throw new \InvalidArgumentException(sprintf('Value of attribute "%s" is too long (%d chars, max %d)', $key, mb_strlen($stringValue), self::MAX_VALUE_LENGTH));
                }

                $attributes[$key] = $stringValue;
            }
        }

        // client_timestamp_ms — опциональный
        $clientTimestampMs = null;
        if (isset($data['client_timestamp_ms'])) {
            if (!is_int($data['client_timestamp_ms']) && !is_float($data['client_timestamp_ms'])) {
                throw new \InvalidArgumentException('"client_timestamp_ms" must be a number');
            }

            $clientTimestampMs = (int) $data['client_timestamp_ms'];
        }

        return new self($eventName, $attributes, $clientTimestampMs);
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getClientTimestampMs(): ?int
    {
        return $this->clientTimestampMs;
    }
}
