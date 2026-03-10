<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Подписчик для установки session.id в контекст телеметрии (Sprint 5, Step 5.7).
 *
 * Выполняется на каждый HTTP запрос до того, как контроллеры начнут обработку.
 * Устанавливает атрибут 'telemetry_session_id' в Request, который:
 * 1. Берётся из заголовка X-Session-ID (если передан с фронтенда)
 * 2. Генерируется как новый UUID v4 (если заголовок не передан)
 *
 * Атрибут доступен через SessionContextTrait::getSessionId($request)
 * во всех контроллерах, которые используют этот trait.
 */
final class TelemetryRequestSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // Приоритет 20 — после SecurityListener, но до контроллеров
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Только главные запросы, не sub-request'ы
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // 1. Пробуем взять session.id из заголовка (передан фронтендом)
        $headerSessionId = $request->headers->get('X-Session-ID');
        if (is_string($headerSessionId) && $headerSessionId !== '') {
            $request->attributes->set('telemetry_session_id', $headerSessionId);

            return;
        }

        // 2. Генерируем новый UUID v4 для этого запроса
        $request->attributes->set('telemetry_session_id', $this->generateUuid4());
    }

    /**
     * Генерирует UUID v4.
     */
    private function generateUuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
