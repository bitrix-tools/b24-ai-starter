<?php

declare(strict_types=1);

namespace App\Service\Telemetry;

use Symfony\Component\HttpFoundation\Request;

/**
 * Trait для управления session.id в контексте телеметрии (Sprint 5, Step 5.3).
 *
 * Предоставляет методы для получения session.id из:
 * 1. Request attributes (установленных TelemetryRequestSubscriber)
 * 2. Заголовка X-Session-ID (передан с фронтенда)
 * 3. Генерации нового уникального ID (fallback)
 *
 * Использование: добавить trait в контроллер или сервис, который имеет доступ к Request.
 */
trait SessionContextTrait
{
    /**
     * Получает session.id для текущего запроса.
     *
     * Приоритет:
     * 1. Request attribute 'telemetry_session_id' (установлен TelemetryRequestSubscriber)
     * 2. HTTP заголовок X-Session-ID (от фронтенда)
     * 3. Генерация нового UUID-подобного идентификатора (fallback)
     */
    protected function getSessionId(Request $request): string
    {
        // 1. Проверяем request attribute (установлен подписчиком)
        $sessionId = $request->attributes->get('telemetry_session_id');
        if (is_string($sessionId) && '' !== $sessionId) {
            return $sessionId;
        }

        // 2. Проверяем HTTP заголовок от фронтенда
        $headerSessionId = $request->headers->get('X-Session-ID');
        if (is_string($headerSessionId) && '' !== $headerSessionId) {
            return $headerSessionId;
        }

        // 3. Генерируем новый ID (fallback для случаев без подписчика)
        return $this->generateSessionId();
    }

    /**
     * Генерирует уникальный session ID в формате UUID v4.
     */
    private function generateSessionId(): string
    {
        $bytes = random_bytes(16);
        // Устанавливаем версию (4) и variant (10xxxxxx)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Извлекает portal.member_id из JWT payload в request attributes.
     */
    protected function getMemberIdFromRequest(Request $request): string
    {
        $jwtPayload = $request->attributes->get('jwt_payload');
        if (is_array($jwtPayload) && isset($jwtPayload['member_id'])) {
            return (string) $jwtPayload['member_id'];
        }

        return '';
    }

    /**
     * Извлекает portal.domain из JWT payload в request attributes.
     */
    protected function getDomainFromRequest(Request $request): string
    {
        $jwtPayload = $request->attributes->get('jwt_payload');
        if (is_array($jwtPayload) && isset($jwtPayload['domain'])) {
            return (string) $jwtPayload['domain'];
        }

        return '';
    }
}
