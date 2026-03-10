# Примеры телеметрии для UI-приложений (тип 1)

> Sprint 6.2 — Готовые примеры интеграции для типовых сценариев UI-Centric приложений.  
> Все примеры используют профиль `simple-ui` и рассчитаны на copy-paste в реальный проект.

> **Два сигнала телеметрии:**  
> - `trackEvent()` / `trackError()` → **`otel_logs`** — бизнес-события, lifecycle, ошибки  
> - `trackOperation()` → **`otel_traces`** — замер длительности внешних вызовов (Bitrix24 API, AI, платёжный шлюз)  
>
> В примерах ниже используется старый паттерн с `hrtime()` и `trackEvent('bitrix_api_call', ...)`.  
> Для новых проектов рекомендуется заменять вызовы Bitrix24 / внешних API на `trackOperation()` — см. раздел 5.

---

## Содержание

1. [CRM виджет](#1-crm-виджет)
2. [Платёжная система](#2-платёжная-система)
3. [SMS-провайдер](#3-sms-провайдер)
4. [Встройка в карточку сделки](#4-встройка-в-карточку-сделки)
5. [Вызовы внешних API через trackOperation](#5-вызовы-внешних-api-через-trackoperation)

---

## 1. CRM виджет

**Сценарий**: виджет встроен в CRM и показывает список объектов. Пользователь открывает виджет, взаимодействует с элементами, система отвечает на события Bitrix24.

### Шаги и события

```
[пользователь открывает виджет]
    → app_opened

[нажатие кнопки "Создать"]
    → action_initiated (action.name=create_record)

[запись создана через API]
    → bitrix_api_call (api.method=crm.lead.add)
    → action_completed (action.name=create_record)

[Bitrix24 присылает OnCrmLeadAdd]
    → b24_event_processed (b24.event_code=OnCrmLeadAdd)
```

### Реализация

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Telemetry\TelemetryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CRM виджет — контроллер с телеметрией.
 */
final class CrmWidgetController extends AbstractController
{
    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly \Bitrix24\SDK\Services\ServiceBuilder $serviceBuilder,
    ) {}

    /**
     * Загрузка списка лидов — вызывается при открытии виджета.
     */
    #[Route('/widget/crm/leads', methods: ['GET'])]
    public function getLeads(Request $request): JsonResponse
    {
        $sessionId   = $request->attributes->get('telemetry_session_id');
        $memberId    = $request->attributes->get('portal_member_id');
        $domain      = $request->attributes->get('portal_domain');

        // Событие открытия виджета
        $this->telemetry->trackEvent('app_opened', [
            'ui.endpoint'      => '/widget/crm/leads',
            'ui.method'        => 'GET',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
            'portal.domain'    => $domain,
        ]);

        // Вызов Bitrix24 API с замером времени
        $apiStart = hrtime(true);
        try {
            $leads = $this->serviceBuilder->getCRMScope()->lead()->list([], [], [], 50)->getLeads();
            $apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);

            $this->telemetry->trackEvent('bitrix_api_call', [
                'api.provider'    => 'bitrix24',
                'api.method'      => 'crm.lead.list',
                'api.duration_ms' => $apiDurationMs,
                'api.status'      => 'success',
                'portal.domain'   => $domain,
            ]);
        } catch (\Throwable $e) {
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'crm_lead_list',
                'portal.member_id' => $memberId,
            ]);
            return $this->json(['error' => 'Failed to load leads'], 500);
        }

        return $this->json(['leads' => $leads, 'count' => count($leads)]);
    }

    /**
     * Создание лида — вызывается по нажатию кнопки на фронте.
     */
    #[Route('/widget/crm/leads', methods: ['POST'])]
    public function createLead(Request $request): JsonResponse
    {
        $sessionId   = $request->attributes->get('telemetry_session_id');
        $memberId    = $request->attributes->get('portal_member_id');
        $domain      = $request->attributes->get('portal_domain');

        $actionStart = hrtime(true);

        // Сигнал: пользователь инициировал действие
        $this->telemetry->trackEvent('action_initiated', [
            'action.name'      => 'create_crm_lead',
            'action.type'      => 'user_action',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
        ]);

        try {
            $data = $request->toArray();

            // Вызов Bitrix24 API
            $apiStart = hrtime(true);
            $result = $this->serviceBuilder->getCRMScope()->lead()->add([
                'TITLE'  => $data['title'],
                'NAME'   => $data['name'] ?? '',
                'PHONE'  => [['VALUE' => $data['phone'] ?? '', 'VALUE_TYPE' => 'WORK']],
            ]);
            $apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);

            $this->telemetry->trackEvent('bitrix_api_call', [
                'api.provider'    => 'bitrix24',
                'api.method'      => 'crm.lead.add',
                'api.duration_ms' => $apiDurationMs,
                'api.status'      => 'success',
                'portal.domain'   => $domain,
            ]);

            // Действие успешно завершено
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'create_crm_lead',
                'action.status'      => 'completed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
                'portal.member_id'   => $memberId,
                'b24.lead_id'        => (string) $result->getId(),
            ]);

            return $this->json(['id' => $result->getId()], 201);
        } catch (\Throwable $e) {
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'create_crm_lead',
                'action.status'      => 'failed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
            ]);
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'create_crm_lead',
                'portal.member_id' => $memberId,
            ]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

### Обработчик события Bitrix24

```php
// B24EventsController::processLeadAdd()
$start = hrtime(true);

try {
    // ... бизнес-логика обработки лида ...
    $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

    $this->telemetry->trackEvent('b24_event_processed', [
        'action.name'        => 'process_crm_lead_add',
        'action.type'        => 'b24_event_handler',
        'action.status'      => 'completed',
        'action.duration_ms' => $durationMs,
        'b24.event_code'     => 'OnCrmLeadAdd',
        'b24.lead_id'        => (string) $leadId,
        'portal.member_id'   => $memberId,
    ]);
} catch (\Throwable $e) {
    $this->telemetry->trackError($e, [
        'error.category'  => 'api_error',
        'action.name'     => 'process_crm_lead_add',
        'portal.member_id' => $memberId,
    ]);
}
```

---

## 2. Платёжная система

**Сценарий**: пользователь инициирует платёж, приложение вызывает внешний платёжный API и возвращает результат.

### Шаги и события

```
[пользователь нажал "Оплатить"]
    → action_initiated (action.name=initiate_payment)

[вызов внешнего платёжного API]
    → external_api_call (api.provider=payment-gateway)

[платёж подтверждён]
    → action_completed (action.name=initiate_payment, action.status=completed)

[ошибка платежа]
    → action_completed (action.name=initiate_payment, action.status=failed)
    → trackError (error.category=api_error)
```

### Реализация

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Telemetry\TelemetryInterface;

/**
 * Сервис обработки платежей с телеметрией.
 */
final class PaymentService
{
    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly PaymentGatewayClient $gateway,
    ) {}

    /**
     * Инициирует платёж и отслеживает весь жизненный цикл.
     *
     * @param array{amount: float, currency: string, order_id: string} $paymentData
     */
    public function initiatePayment(array $paymentData, string $memberId, string $sessionId): array
    {
        $actionStart = hrtime(true);

        // Пользователь инициировал платёж
        $this->telemetry->trackEvent('action_initiated', [
            'action.name'      => 'initiate_payment',
            'action.type'      => 'payment',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
        ]);

        try {
            // Вызов внешнего платёжного шлюза
            $apiStart = hrtime(true);
            $gatewayResponse = $this->gateway->charge([
                'amount'   => $paymentData['amount'],
                'currency' => $paymentData['currency'],
                'order_id' => $paymentData['order_id'],
            ]);
            $apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);

            $this->telemetry->trackEvent('external_api_call', [
                'api.provider'    => 'payment-gateway',
                'api.method'      => 'charge',
                'api.duration_ms' => $apiDurationMs,
                'api.status'      => 'success',
            ]);

            // Платёж успешен
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'initiate_payment',
                'action.status'      => 'completed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
                'portal.member_id'   => $memberId,
            ]);

            return ['status' => 'success', 'transaction_id' => $gatewayResponse->transactionId];

        } catch (PaymentDeclinedException $e) {
            // Платёж отклонён — не ошибка системы, но нужно отфиксировать
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'initiate_payment',
                'action.status'      => 'declined',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
                'portal.member_id'   => $memberId,
            ]);
            return ['status' => 'declined', 'reason' => $e->getMessage()];

        } catch (\Throwable $e) {
            // Системная ошибка
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'initiate_payment',
                'action.status'      => 'failed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
            ]);
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'initiate_payment',
                'portal.member_id' => $memberId,
            ]);
            throw $e;
        }
    }
}
```

### Ключевые принципы примера

- `action_initiated` — **всегда** при начале операции инициированной пользователем
- `external_api_call` — для любых вызовов за пределы приложения (не только Bitrix24)
- `action_completed` — **всегда** в конце, даже при ошибку (статус: `completed` / `declined` / `failed`)
- `trackError` — дополнительно при системных ошибках, не при бизнес-отказах

---

## 3. SMS-провайдер

**Сценарий**: пользователь запускает отправку SMS из интерфейса приложения, система отслеживает статус доставки.

### Шаги и события

```
[пользователь нажал "Отправить SMS"]
    → action_initiated (action.name=send_sms)

[вызов SMS API]
    → external_api_call (api.provider=sms-gateway)

[SMS принято шлюзом]
    → action_completed (action.name=send_sms, action.status=accepted)

[вебхук от шлюза: SMS доставлено]
    → b24_event_processed (action.name=sms_delivery_confirmed)
```

### Реализация

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Telemetry\TelemetryInterface;

/**
 * SMS-сервис с телеметрией.
 */
final class SmsService
{
    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly SmsGatewayClient $smsGateway,
    ) {}

    /**
     * Отправка SMS с отслеживанием через телеметрию.
     */
    public function sendSms(
        string $phone,
        string $message,
        string $memberId,
        string $sessionId,
    ): array {
        $actionStart = hrtime(true);

        $this->telemetry->trackEvent('action_initiated', [
            'action.name'      => 'send_sms',
            'action.type'      => 'notification',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
        ]);

        try {
            $apiStart = hrtime(true);
            $response = $this->smsGateway->send($phone, $message);
            $apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);

            $this->telemetry->trackEvent('external_api_call', [
                'api.provider'    => 'sms-gateway',
                'api.method'      => 'send',
                'api.duration_ms' => $apiDurationMs,
                'api.status'      => 'success',
            ]);

            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'send_sms',
                'action.status'      => 'accepted',  // шлюз принял, но SMS ещё не доставлено
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
                'portal.member_id'   => $memberId,
            ]);

            return ['status' => 'accepted', 'message_id' => $response->messageId];

        } catch (\Throwable $e) {
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'send_sms',
                'action.status'      => 'failed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
            ]);
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'send_sms',
                'portal.member_id' => $memberId,
            ]);
            throw $e;
        }
    }

    /**
     * Обработка вебхука о доставке SMS.
     * Вызывается когда SMS-шлюз присылает статус доставки.
     */
    public function handleDeliveryWebhook(string $messageId, string $status): void
    {
        $start = hrtime(true);

        // ... обновление статуса в БД ...

        $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $this->telemetry->trackEvent('b24_event_processed', [
            'action.name'        => 'sms_delivery_webhook',
            'action.type'        => 'webhook_handler',
            'action.status'      => 'completed',
            'action.duration_ms' => $durationMs,
        ]);
    }
}
```

---

## 4. Встройка в карточку сделки

**Сценарий**: приложение встроено в карточку сделки (Deal) через placement. Пользователь видит данные из внешней системы, может выполнять действия.

### Шаги и события

```
[карточка сделки открыта, placement загружен]
    → app_opened

[загрузка данных сделки через API]
    → bitrix_api_call (api.method=crm.deal.get)

[пользователь нажал "Синхронизировать"]
    → action_initiated (action.name=sync_deal_to_external)

[запись создана во внешней системе]
    → external_api_call (api.provider=external-crm)

[данные сделки обновлены в Bitrix24]
    → bitrix_api_call (api.method=crm.deal.update)

[синхронизация завершена]
    → action_completed (action.name=sync_deal_to_external)
```

### Реализация

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Telemetry\TelemetryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Контроллер для placement в карточке сделки.
 */
final class DealCardController extends AbstractController
{
    public function __construct(
        private readonly TelemetryInterface $telemetry,
        private readonly \Bitrix24\SDK\Services\ServiceBuilder $serviceBuilder,
        private readonly ExternalCrmClient $externalCrm,
    ) {}

    /**
     * Загрузка данных для placement в карточке сделки.
     */
    #[Route('/placement/deal-card', methods: ['GET'])]
    public function loadDealCard(Request $request): JsonResponse
    {
        $sessionId   = $request->attributes->get('telemetry_session_id');
        $memberId    = $request->attributes->get('portal_member_id');
        $domain      = $request->attributes->get('portal_domain');
        $dealId      = (int) $request->query->get('deal_id');

        // Placement загружен
        $this->telemetry->trackEvent('app_opened', [
            'ui.endpoint'      => '/placement/deal-card',
            'ui.method'        => 'GET',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
            'portal.domain'    => $domain,
        ]);

        // Загружаем данные сделки
        $apiStart = hrtime(true);
        try {
            $deal = $this->serviceBuilder->getCRMScope()->deal()->get($dealId)->getDeal();
            $apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);

            $this->telemetry->trackEvent('bitrix_api_call', [
                'api.provider'    => 'bitrix24',
                'api.method'      => 'crm.deal.get',
                'api.duration_ms' => $apiDurationMs,
                'api.status'      => 'success',
                'portal.domain'   => $domain,
            ]);
        } catch (\Throwable $e) {
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'crm_deal_get',
                'portal.member_id' => $memberId,
            ]);
            return $this->json(['error' => 'Failed to load deal'], 500);
        }

        return $this->json(['deal' => $deal]);
    }

    /**
     * Синхронизация сделки во внешнюю CRM.
     */
    #[Route('/placement/deal-card/sync', methods: ['POST'])]
    public function syncDeal(Request $request): JsonResponse
    {
        $sessionId   = $request->attributes->get('telemetry_session_id');
        $memberId    = $request->attributes->get('portal_member_id');
        $domain      = $request->attributes->get('portal_domain');
        $dealId      = (int) $request->toArray()['deal_id'];

        $actionStart = hrtime(true);

        $this->telemetry->trackEvent('action_initiated', [
            'action.name'      => 'sync_deal_to_external',
            'action.type'      => 'sync',
            'session.id'       => $sessionId,
            'portal.member_id' => $memberId,
        ]);

        try {
            // Шаг 1: получить данные сделки
            $apiStart = hrtime(true);
            $deal = $this->serviceBuilder->getCRMScope()->deal()->get($dealId)->getDeal();
            $this->telemetry->trackEvent('bitrix_api_call', [
                'api.provider'    => 'bitrix24',
                'api.method'      => 'crm.deal.get',
                'api.duration_ms' => (int) round((hrtime(true) - $apiStart) / 1_000_000),
                'api.status'      => 'success',
                'portal.domain'   => $domain,
            ]);

            // Шаг 2: создать запись во внешней CRM
            $extStart = hrtime(true);
            $externalId = $this->externalCrm->createOpportunity($deal);
            $this->telemetry->trackEvent('external_api_call', [
                'api.provider'    => 'external-crm',
                'api.method'      => 'create_opportunity',
                'api.duration_ms' => (int) round((hrtime(true) - $extStart) / 1_000_000),
                'api.status'      => 'success',
            ]);

            // Шаг 3: обновить сделку в Bitrix24 с external ID
            $updateStart = hrtime(true);
            $this->serviceBuilder->getCRMScope()->deal()->update($dealId, [
                'UF_EXTERNAL_ID' => $externalId,
            ]);
            $this->telemetry->trackEvent('bitrix_api_call', [
                'api.provider'    => 'bitrix24',
                'api.method'      => 'crm.deal.update',
                'api.duration_ms' => (int) round((hrtime(true) - $updateStart) / 1_000_000),
                'api.status'      => 'success',
                'portal.domain'   => $domain,
            ]);

            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'sync_deal_to_external',
                'action.status'      => 'completed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
                'portal.member_id'   => $memberId,
            ]);

            return $this->json(['external_id' => $externalId]);

        } catch (\Throwable $e) {
            $totalDurationMs = (int) round((hrtime(true) - $actionStart) / 1_000_000);
            $this->telemetry->trackEvent('action_completed', [
                'action.name'        => 'sync_deal_to_external',
                'action.status'      => 'failed',
                'action.duration_ms' => $totalDurationMs,
                'session.id'         => $sessionId,
            ]);
            $this->telemetry->trackError($e, [
                'error.category'  => 'api_error',
                'action.name'     => 'sync_deal_to_external',
                'portal.member_id' => $memberId,
            ]);
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
```

---

## Общие паттерны

### Шаблон action с замером времени

```php
$actionStart = hrtime(true);

$this->telemetry->trackEvent('action_initiated', [
    'action.name' => 'my_action',
    'action.type' => 'user_action',
    'session.id'  => $sessionId,
]);

try {
    // ... логика ...

    $this->telemetry->trackEvent('action_completed', [
        'action.name'        => 'my_action',
        'action.status'      => 'completed',
        'action.duration_ms' => (int) round((hrtime(true) - $actionStart) / 1_000_000),
        'session.id'         => $sessionId,
    ]);
} catch (\Throwable $e) {
    $this->telemetry->trackEvent('action_completed', [
        'action.name'        => 'my_action',
        'action.status'      => 'failed',
        'action.duration_ms' => (int) round((hrtime(true) - $actionStart) / 1_000_000),
        'session.id'         => $sessionId,
    ]);
    $this->telemetry->trackError($e, [
        'error.category' => 'internal_error',
        'action.name'    => 'my_action',
    ]);
    throw $e;
}
```

### Значения `action.status`

| Статус      | Когда использовать                                       |
|-------------|----------------------------------------------------------|
| `completed` | Операция выполнена успешно                               |
| `failed`    | Системная ошибка (exception, недоступность API)          |
| `declined`  | Бизнес-отказ (нет прав, недостаточно средств, лимит)     |
| `accepted`  | Принято к обработке, финальный статус придёт асинхронно  |
| `cancelled` | Операция отменена пользователем                          |

### Значения `error.category`

| Категория          | Тип ошибки                          |
|--------------------|-------------------------------------|
| `validation_error` | Неверные входные данные             |
| `auth_error`       | Ошибки JWT/авторизации              |
| `not_found`        | EntityNotFoundException              |
| `api_error`        | Ошибки Bitrix24 SDK / внешних API   |
| `internal_error`   | Всё остальное                       |

---

---

## 5. Вызовы внешних API через trackOperation

Раздел показывает как переписать типовые паттерны с `hrtime()` + `trackEvent()` на `trackOperation()`. Данные при этом попадают в `otel_traces` (а не `otel_logs`) с точными полями `Duration`, `StartTime`, `EndTime`.

### Bitrix24 REST API

```php
// ❌ Старый паттерн: ручной hrtime, данные в otel_logs
$apiStart = hrtime(true);
$contact = $sb->getCRMScope()->contact()->get($contactId)->contact();
$apiDurationMs = (int) round((hrtime(true) - $apiStart) / 1_000_000);
$this->telemetry->trackEvent('bitrix_api_call', [
    'api.provider'    => 'bitrix24',
    'api.method'      => 'crm.contact.get',
    'api.duration_ms' => $apiDurationMs,
    'api.status'      => 'success',
    'portal.domain'   => $domain,
]);

// ✅ Новый паттерн: trackOperation, данные в otel_traces с Duration
$contact = $this->telemetry->trackOperation(
    'bitrix24.crm.contact.get',
    fn () => $sb->getCRMScope()->contact()->get($contactId)->contact(),
    ['portal.member_id' => $memberId, 'b24.contact_id' => (string) $contactId],
);
// Исключения recordException() записываются автоматически
```

### Внешний платёжный шлюз

```php
// ✅ Вызов payment gateway с автоматическим замером
$response = $this->telemetry->trackOperation(
    'payment.gateway.charge',
    fn () => $this->gateway->charge([
        'amount'   => $paymentData['amount'],
        'currency' => $paymentData['currency'],
        'order_id' => $paymentData['order_id'],
    ]),
    ['portal.member_id' => $memberId],
);
```

### AI API (Claude / GPT)

```php
// ✅ Вызов AI провайдера — в otel_traces видна реальная latency
$completion = $this->telemetry->trackOperation(
    'ai.claude.messages.create',
    fn () => $this->claudeClient->messages()->create([
        'model'      => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 1024,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]),
    [
        'ai.model'         => 'claude-3-5-sonnet-20241022',
        'portal.member_id' => $memberId,
    ],
);
```

### Вложенные spans (waterfall в Grafana)

```php
// Внешний span: вся бизнес-операция целиком
$this->telemetry->trackOperation('b24.onCrmContactAdd', function () use ($contactId, $memberId, $sb) {

    // Вложенный span: чтение из Bitrix24
    $contact = $this->telemetry->trackOperation(
        'bitrix24.crm.contact.get',
        fn () => $sb->getCRMScope()->contact()->get($contactId)->contact(),
        ['b24.contact_id' => (string) $contactId],
    );

    // Вложенный span: AI-классификация
    $category = $this->telemetry->trackOperation(
        'ai.classify.contact',
        fn () => $this->aiService->classifyContact($contact),
        ['ai.model' => 'claude-3-5-haiku'],
    );

    // Вложенный span: сохранение результата
    $this->telemetry->trackOperation(
        'db.contact.upsert',
        fn () => $this->repo->upsert($contact, $category),
    );

}, ['portal.member_id' => $memberId, 'b24.contact_id' => (string) $contactId]);
```

---

## Проверка событий в ClickHouse

После запуска примеров проверьте что события попали в хранилище:

```sql
-- Трейсы: все spans за последний час (otel_traces)
SELECT
    toDateTime(Timestamp)  AS ts,
    SpanName,
    Duration / 1e6         AS duration_ms,
    StatusCode,
    SpanAttributes['portal.member_id'] AS member_id
FROM telemetry.otel_traces
WHERE Timestamp > now() - INTERVAL 1 HOUR
ORDER BY Timestamp DESC
LIMIT 50;

-- Логи: бизнес-события за последний час (otel_logs)
SELECT
    toDateTime(Timestamp)          AS ts,
    SeverityText,
    LogAttributes['event.name']    AS event_name,
    LogAttributes['portal.member_id'] AS member_id
FROM telemetry.otel_logs
WHERE Timestamp > now() - INTERVAL 1 HOUR
ORDER BY Timestamp DESC
LIMIT 50;

-- Производительность внешних вызовов по span'ам
SELECT
    SpanName,
    count()                          AS calls,
    avg(Duration / 1e6)              AS avg_ms,
    quantile(0.95)(Duration / 1e6)   AS p95_ms,
    countIf(StatusCode = 'STATUS_ERROR') AS errors
FROM telemetry.otel_traces
WHERE Timestamp > now() - INTERVAL 24 HOUR
GROUP BY SpanName
ORDER BY avg_ms DESC;
```

---

## См. также

- [telemetry-quickstart.md](telemetry-quickstart.md) — быстрый старт, конфигурация и `trackOperation()` reference
- [telemetry-integration-points.md](telemetry-integration-points.md) — полный список точек интеграции
- [telemetry-profiles-config.md](telemetry-profiles-config.md) — конфигурация профилей
- [telemetry-troubleshooting.md](telemetry-troubleshooting.md) — решение проблем


