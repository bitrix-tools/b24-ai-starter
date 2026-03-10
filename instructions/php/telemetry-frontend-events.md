# Фронтенд-события через PHP-прокси

> Sprint 8 — реализация фронтенд-телеметрии через безопасный PHP-эндпоинт.

## Содержание

1. [Зачем PHP-прокси?](#1-зачем-php-прокси)
2. [Архитектура потока данных](#2-архитектура-потока-данных)
3. [PHP-эндпоинт](#3-php-эндпоинт)
4. [Whitelist событий](#4-whitelist-событий)
5. [Composable useTelemetry](#5-composable-usetelemetry)
6. [Nuxt-плагин (автоматические события)](#6-nuxt-плагин-автоматические-события)
7. [Ручная отправка событий](#7-ручная-отправка-событий)
8. [Enrichment атрибутов](#8-enrichment-атрибутов)
9. [Запуск тестов](#9-запуск-тестов)

---

## 1. Зачем PHP-прокси?

Нельзя отправлять события напрямую из браузера в OTel Collector:

| Проблема | Решение через PHP-прокси |
|---|---|
| OTel Collector не имеет авторизации | JWT проверяется в JwtAuthenticationListener |
| Браузер может подделать любое событие | Whitelist + валидация DTO |
| CORS и порт Collector недоступны снаружи | Один домен, один порт |
| Невозможно обогатить атрибутами из JWT | PHP читает `jwt_member_id`, `jwt_domain` |

---

## 2. Архитектура потока данных

```
Браузер (Nuxt 3)
    │
    │  POST /api/telemetry/event
    │  Bearer <JWT>
    │  { "event_name": "page_view", "attributes": {...} }
    │
    ▼
PHP TelemetryController
    ├── JwtAuthenticationListener (401 если нет/невалидный JWT)
    ├── FrontendTelemetryEvent::fromArray() (400 если не в whitelist)
    ├── Обогащение: event.source, session.id, portal.domain, portal.member_id
    └── TelemetryInterface::trackEvent()
            │
            ▼
    OTel Collector  →  ClickHouse  →  Grafana
```

---

## 3. PHP-эндпоинт

**Route:** `POST /api/telemetry/event`

**Auth:** JWT (автоматически через `JwtAuthenticationListener`)

**Тело запроса (JSON):**

```json
{
  "event_name": "page_view",
  "attributes": {
    "ui.path": "/crm/leads",
    "ui.route_name": "crm-leads"
  },
  "client_timestamp_ms": 1740000000000
}
```

| Поле | Тип | Обязательно | Ограничения |
|---|---|---|---|
| `event_name` | string | ✅ | Только из whitelist |
| `attributes` | object | ❌ | Макс. 30 ключей; ключи `[a-z0-9._]+`; значения ≤ 512 символов |
| `client_timestamp_ms` | int | ❌ | Временная метка браузера (мс) |

**Ответы:**

| Код | Когда |
|---|---|
| `204 No Content` | Событие принято (или телеметрия выключена) |
| `400 Bad Request` | Неизвестное событие; превышен лимит атрибутов; некорректные ключи |
| `401 Unauthorized` | Отсутствует или невалидный JWT |

> **Принцип надёжности**: 500 никогда не возвращается из-за сбоя телеметрии.

---

## 4. Whitelist событий

Задаётся в `config/packages/telemetry.yaml`:

```yaml
parameters:
  telemetry.frontend_event_whitelist:
    - 'page_view'
    - 'ui_button_click'
    - 'ui_form_submit'
    - 'ui_error'
    - 'app_frame_loaded'
```

**Расширение whitelist** — просто добавьте новое имя в список:

```yaml
  telemetry.frontend_event_whitelist:
    - 'page_view'
    - 'ui_button_click'
    - 'ui_form_submit'
    - 'ui_error'
    - 'app_frame_loaded'
    - 'crm_deal_opened'     # ← добавить своё событие
    - 'crm_deal_saved'
```

Без перезапуска кода на frontend — только изменение конфига.

---

## 5. Composable useTelemetry

Расположен в `frontend/app/composables/useTelemetry.ts`.

### Использование

```ts
// В любом компоненте или странице
const { track } = useTelemetry()

// Клик по кнопке
function onSave() {
  track('ui_button_click', {
    'ui.component': 'btn-save',
    'ui.context':   'deal-form',
  })
}

// Отправка формы
function onSubmit() {
  track('ui_form_submit', {
    'ui.form': 'create-deal',
  })
}
```

### Как работает

- **fire-and-forget** — ошибки отправки логируются в `console.warn` (dev-режим), не бросаются наружу
- **Очередь до 10 событий** — если JWT ещё не готов, события буферизуются и сбрасываются после инициализации
- **Session ID** — автоматически генерируется и хранится в `sessionStorage` (уникален для каждой вкладки)

---

## 6. Nuxt-плагин (автоматические события)

`frontend/app/plugins/telemetry.client.ts` подключает три автоматических tracked-события:

| Событие | Триггер |
|---|---|
| `app_frame_loaded` | `app:mounted` — однократно при запуске |
| `page_view` | `router.afterEach()` — при каждом переходе |
| `ui_error` | `window.onerror` + `unhandledrejection` |

Плагин также наблюдает за `apiStore.isInitTokenJWT` и при готовности JWT сбрасывает накопленную очередь событий через `onJwtReady()`.

**Плагин подключается автоматически** — Nuxt обнаруживает файлы в `app/plugins/` по соглашению о наименовании (суффикс `.client.ts` — только на клиенте).

---

## 7. Ручная отправка событий

По умолчанию все события из whitelist доступны для ручной отправки:

```ts
const { track } = useTelemetry()

// Загрузка приложения (помимо автоматической)
track('app_frame_loaded', {
  'app.version': '1.2.3',
})

// Ошибка с контекстом
track('ui_error', {
  'error.type':    'validation_error',
  'error.message': 'Email is required',
  'ui.form':       'user-profile-form',
})
```

---

## 8. Enrichment атрибутов

PHP автоматически добавляет к каждому событию:

| Атрибут | Источник |
|---|---|
| `event.source` | `"frontend"` (всегда) |
| `session.id` | `X-Session-ID` заголовок или `telemetry_session_id` request attribute |
| `portal.member_id` | JWT payload → `jwt_member_id` |
| `portal.domain` | JWT payload → `jwt_domain` |
| `client.timestamp_ms` | `client_timestamp_ms` из тела запроса (если указан) |

В ClickHouse события фронтенда легко выделить по фильтру:

```sql
SELECT *
FROM telemetry.otel_traces
WHERE SpanAttributes['event.source'] = 'frontend'
  AND SpanName = 'page_view'
ORDER BY Timestamp DESC
LIMIT 100
```

---

## 9. Запуск тестов

### Юнит-тесты (без инфраструктуры)

```bash
make test-telemetry-frontend-events
```

В набор входят:
- `FrontendTelemetryEventTest` — тесты DTO (whitelist, лимиты атрибутов, форматы ключей)
- `TelemetryControllerTest` — тесты HTTP-эндпоинта (204 / 400 / 401, resilience)

### E2E-тест (требует b24-ai-starter-otel)

```bash
# 1. Запустить инфраструктуру наблюдаемости
cd ../b24-ai-starter-otel && make up

# 2. Запустить E2E-тест
cd ../b24-ai-starter-ru && make test-telemetry-frontend-e2e
```

E2E-тест проверяет:
1. Событие `page_view` появляется в ClickHouse (`telemetry.otel_traces`) в течение 12 секунд
2. Заблокированное (не в whitelist) событие → 400 → в ClickHouse ничего нет
3. Без JWT → 401 → в ClickHouse ничего нет

---

## Связанные файлы

| Файл | Описание |
|---|---|
| `src/DTO/FrontendTelemetryEvent.php` | DTO с валидацией |
| `src/Controller/TelemetryController.php` | HTTP-эндпоинт |
| `config/packages/telemetry.yaml` | Whitelist событий |
| `config/services.yaml` | DI-конфигурация контроллера |
| `tests/Telemetry/Frontend/FrontendTelemetryEventTest.php` | Юнит-тесты DTO |
| `tests/Telemetry/Frontend/TelemetryControllerTest.php` | Юнит/интеграционные тесты эндпоинта |
| `tests/Telemetry/Frontend/FrontendTelemetryE2ETest.php` | E2E-тест с реальным ClickHouse |
| `frontend/app/composables/useTelemetry.ts` | Vue composable |
| `frontend/app/plugins/telemetry.client.ts` | Nuxt-плагин (автособытия) |
