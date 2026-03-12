<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\FrontendTelemetryEvent;
use App\Service\Telemetry\SessionContextTrait;
use App\Service\Telemetry\TelemetryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Входная точка фронтенд-телеметрии (Sprint 8).
 *
 * Принимает события из Nuxt-фронтенда, валидирует их по whitelist,
 * обогащает атрибутами из JWT и транслирует в TelemetryInterface.
 *
 * Защита:
 * - JWT авторизация обеспечивается JwtAuthenticationListener (автоматически)
 * - Имена событий проверяются по whitelist из конфигурации
 * - Атрибуты ограничены по количеству и длине значений
 *
 * Принцип надёжности:
 * - Никогда не возвращает 5xx из-за сбоя телеметрии
 * - Сбой trackEvent() логируется, но не блокирует ответ 204
 */
final class TelemetryController extends AbstractController
{
    use SessionContextTrait;

    /**
     * @param list<string> $frontendEventWhitelist Список допустимых имён событий (из telemetry.yaml)
     */
    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly LoggerInterface $logger,
        private readonly array $frontendEventWhitelist,
    ) {
    }

    /**
     * Принять событие от фронтенда и транслировать в телеметрию.
     *
     * POST /api/telemetry/event
     *
     * Тело запроса (JSON):
     * {
     *   "event_name": "page_view",          // обязательно, из whitelist
     *   "attributes": {                      // опционально, max 30 ключей
     *     "ui.path": "/crm/leads",
     *     "ui.route_name": "crm-leads"
     *   },
     *   "client_timestamp_ms": 1740000000000 // опционально
     * }
     *
     * Ответы:
     * - 204 No Content  — событие принято (или телеметрия выключена)
     * - 400 Bad Request — невалидный payload (неизвестное событие, превышение лимитов)
     * - 401 Unauthorized — отсутствует или невалидный JWT (от JwtAuthenticationListener)
     */
    #[Route('/api/telemetry/event', name: 'telemetry_frontend_event', methods: ['POST'])]
    public function trackFrontendEvent(Request $request): Response
    {
        // Разбираем JSON-тело запроса
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            return new JsonResponse(['error' => 'Invalid JSON: '.$jsonException->getMessage()], 400);
        }

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Request body must be a JSON object'], 400);
        }

        // Валидируем DTO (whitelist + ограничения)
        try {
            $event = FrontendTelemetryEvent::fromArray($data, $this->frontendEventWhitelist);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $this->logger->debug('TelemetryController: invalid frontend event', [
                'error' => $invalidArgumentException->getMessage(),
                'data' => $data,
            ]);

            return new JsonResponse(['error' => $invalidArgumentException->getMessage()], 400);
        }

        // Обогащаем атрибутами из JWT и session context
        // jwt_domain и jwt_member_id устанавливаются JwtAuthenticationListener
        $enrichedAttributes = array_merge(
            $event->getAttributes(),
            [
                'event.source' => 'frontend',
                'session.id' => $this->getSessionId($request),
                'portal.member_id' => (string) ($request->attributes->get('jwt_member_id') ?? ''),
                'portal.domain' => (string) ($request->attributes->get('jwt_domain') ?? ''),
            ],
        );

        // Если фронт передал client_timestamp_ms — добавляем как диагностический атрибут
        if (null !== $event->getClientTimestampMs()) {
            $enrichedAttributes['client.timestamp_ms'] = (string) $event->getClientTimestampMs();
        }

        // Отправляем в телеметрию; сбои не должны влиять на ответ
        try {
            $this->telemetry->trackEvent($event->getEventName(), $enrichedAttributes);
        } catch (\Throwable $throwable) {
            // Логируем, но возвращаем 204 — телеметрия не должна ломать UI
            $this->logger->error('TelemetryController: trackEvent failed', [
                'event_name' => $event->getEventName(),
                'error' => $throwable->getMessage(),
            ]);
        }

        return new Response(null, 204);
    }
}
