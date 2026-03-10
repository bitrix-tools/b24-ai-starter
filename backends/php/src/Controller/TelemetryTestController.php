<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Telemetry\SessionContextTrait;
use App\Service\Telemetry\TelemetryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TelemetryTestController — эндпоинт для визуального тестирования телеметрии.
 *
 * При вызове GET /api/telemetry/test регистрирует по одному событию каждого
 * поддерживаемого типа на стороне бэкенда. Предназначен исключительно для
 * ручной проверки потока данных: фронт → PHP → OTel Collector → ClickHouse → Grafana.
 *
 * ⚠️ Не использовать в продакшне как постоянный эндпоинт.
 */
class TelemetryTestController extends AbstractController
{
    use SessionContextTrait;

    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/telemetry/test', name: 'telemetry_test', methods: ['GET'])]
    public function runTest(Request $request): JsonResponse
    {
        $this->logger->info('TelemetryTestController.runTest.start');

        $sessionId  = $this->getSessionId($request);
        $memberId   = $this->getMemberIdFromRequest($request);
        $domain     = $this->getDomainFromRequest($request);
        $baseAttrs  = [
            'test'             => 'true',
            'test.source'      => 'telemetry_test_page',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
            'portal.domain'    => $domain,
        ];

        $firedEvents = [];

        // 1. app_opened — открытие приложения
        $this->telemetry->trackEvent('app_opened', array_merge($baseAttrs, [
            'ui.endpoint' => '/api/telemetry/test',
            'ui.method'   => 'GET',
        ]));
        $firedEvents[] = 'app_opened';

        // 2. api_list_called — вызов API-эндпоинта
        $this->telemetry->trackEvent('api_list_called', array_merge($baseAttrs, [
            'endpoint' => '/api/telemetry/test',
            'method'   => 'GET',
        ]));
        $firedEvents[] = 'api_list_called';

        // 3. bitrix_api_call — имитация серверного вызова B24 REST API
        $this->telemetry->trackEvent('bitrix_api_call', array_merge($baseAttrs, [
            'api.provider'    => 'bitrix24',
            'api.method'      => 'crm.deal.list',
            'api.duration_ms' => '12',
            'api.status'      => 'success',
        ]));
        $firedEvents[] = 'bitrix_api_call';

        // 4. b24_event_action_initiated — начало обработки входящего события B24
        $this->telemetry->trackEvent('b24_event_action_initiated', array_merge($baseAttrs, [
            'b24.event'  => 'ONCRMDEALUPDATE',
            'b24.entity' => 'deal',
        ]));
        $firedEvents[] = 'b24_event_action_initiated';

        // 5. b24_event_processed — успешное завершение обработки события
        $this->telemetry->trackEvent('b24_event_processed', array_merge($baseAttrs, [
            'b24.event'           => 'ONCRMDEALUPDATE',
            'action.name'         => 'process_crm_deal_update',
            'action.type'         => 'b24_event_handler',
            'action.status'       => 'completed',
            'action.duration_ms'  => '55',
        ]));
        $firedEvents[] = 'b24_event_processed';

        // 6. screen_view — просмотр экрана / раздела в приложении
        $this->telemetry->trackEvent('screen_view', array_merge($baseAttrs, [
            'screen.name' => 'telemetry_test',
        ]));
        $firedEvents[] = 'screen_view';

        // 7. trackError — мягкая тестовая ошибка (не прерывает работу)
        $testException = new \RuntimeException('[TelemetryTest] Soft test error — ignore in production');
        $this->telemetry->trackError($testException, array_merge($baseAttrs, [
            'error.category' => 'telemetry_test',
            'error.soft'     => 'true',
        ]));
        $firedEvents[] = 'trackError(RuntimeException)';

        // 8. trackOperation — простая имитация операции (→ otel_traces)
        $this->telemetry->trackOperation(
            'test.simple_operation',
            function () use (&$firedEvents): void {
                // Имитация работы: небольшая пауза
                usleep(5_000); // 5 ms
                $firedEvents[] = 'trackOperation(test.simple_operation)';
            },
            array_merge($baseAttrs, ['operation.type' => 'test'])
        );

        // 9. trackOperation — вложенный span: внешняя операция содержит внутреннюю (→ otel_traces)
        $this->telemetry->trackOperation(
            'test.outer_operation',
            function () use (&$firedEvents): void {
                usleep(3_000); // 3 ms
                $this->telemetry->trackOperation(
                    'test.inner_operation',
                    function () use (&$firedEvents): void {
                        usleep(2_000); // 2 ms
                        $firedEvents[] = 'trackOperation(test.inner_operation)';
                    },
                    ['operation.type' => 'test', 'operation.nested' => 'true']
                );
                $firedEvents[] = 'trackOperation(test.outer_operation)';
            },
            array_merge($baseAttrs, ['operation.type' => 'test', 'operation.has_children' => 'true'])
        );

        $this->logger->info('TelemetryTestController.runTest.finish', ['fired_events' => $firedEvents]);

        return new JsonResponse([
            'status'        => 'ok',
            'fired_events'  => $firedEvents,
            'fired_count'   => count($firedEvents),
            'session_id'    => $sessionId,
            'portal_domain' => $domain,
        ]);
    }
}
