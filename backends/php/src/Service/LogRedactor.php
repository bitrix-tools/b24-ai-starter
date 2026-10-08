<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Masks Bitrix24 credentials in request payloads so they can be logged.
 *
 * Same key list as `redactSensitive()` in the Node backend. Matching is
 * case-insensitive and recursive (event payloads nest tokens under `auth`).
 */
final class LogRedactor
{
    private const array SENSITIVE_KEYS = [
        'auth_id',
        'refresh_id',
        'refresh_token',
        'access_token',
        'application_token',
    ];

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
