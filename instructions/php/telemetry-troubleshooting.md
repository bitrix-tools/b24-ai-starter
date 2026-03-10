# Телеметрия: устранение неполадок

> Sprint 6.3 — Руководство по диагностике и решению типичных проблем с телеметрией.

---

## Содержание

1. [События не отправляются](#1-события-не-отправляются)
2. [OTel Collector недоступен](#2-otel-collector-недоступен)
3. [Производительность деградировала](#3-производительность-деградировала)
4. [Ошибки конфигурации](#4-ошибки-конфигурации)
5. [Проблемы с профилями и фильтрацией](#5-проблемы-с-профилями-и-фильтрацией)
6. [Проблемы с session.id](#6-проблемы-с-sessionid)
7. [Данные не появляются в Grafana / ClickHouse](#7-данные-не-появляются-в-grafana--clickhouse)
8. [Spans не появляются в otel_traces](#8-spans-не-появляются-в-otel_traces)
9. [FAQ](#9-faq)

---

## 1. События не отправляются

### Симптом

Код содержит вызовы `trackEvent()`, но данные не появляются в ClickHouse.

### Диагностика

**Шаг 1: проверить, включена ли телеметрия**

```bash
# Проверить переменную окружения в контейнере
docker compose exec php-fpm env | grep TELEMETRY
# Ожидаемый вывод:
# TELEMETRY_ENABLED=true
# OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

**Шаг 2: проверить, какой класс используется**

```bash
# В PHP контейнере
docker compose run --rm php-fpm php bin/console debug:container App\\Service\\Telemetry\\TelemetryInterface
```

Ожидаемый вывод при включённой телеметрии:
```
Service ID   App\Service\Telemetry\TelemetryInterface
Class        App\Service\Telemetry\RealTelemetryService
```

Если видите `NullTelemetryService` — телеметрия выключена (`TELEMETRY_ENABLED=false`).

**Шаг 3: проверить логи**

```bash
docker compose logs php-fpm 2>&1 | grep -i telemetry | tail -20
docker compose logs otel-collector     | tail -20
```

### Решения

| Причина                             | Решение                                                   |
|-------------------------------------|-----------------------------------------------------------|
| `TELEMETRY_ENABLED=false`           | Установить `TELEMETRY_ENABLED=true` в `.env.local`        |
| Кэш Symfony устарел                 | `php bin/console cache:clear`                             |
| Неправильный `OTLP_ENDPOINT`        | Проверить URL коллектора (см. раздел 2)                   |
| Исключение в `initialize()`         | Проверить логи PHP — ошибка инициализации OTel SDK        |
| `$telemetry` — не тот объект        | `debug:container` как выше                                |

---

## 2. OTel Collector недоступен

### Симптом

В логах PHP появляются ошибки типа:
```
Connection refused http://otel-collector:4318/v1/traces
Failed to export spans
cURL error 7: Failed to connect
```

При этом `trackEvent()` не бросает исключений — ошибки только логируются.

### Диагностика

```bash
# Проверить, запущен ли коллектор
docker compose ps otel-collector

# Проверить доступность с хоста
curl -s -o /dev/null -w '%{http_code}' http://localhost:4318/v1/traces
# Ожидается: 200 или 405 (метод не поддерживается — это нормально)

# Проверить с PHP-контейнера
docker compose exec php-fpm curl -s http://otel-collector:4318/v1/traces
# Должен вернуть ответ, а не "Connection refused"

# Проверить, экспортируется ли порт
docker compose port otel-collector 4318
```

### Решения

**Если коллектор не запущен:**
```bash
cd ../b24-ai-starter-otel
make up
# или
docker compose up -d otel-collector
```

**Если неправильный хост в `.env.local`:**

```dotenv
# Для запуска в Docker compose (обычная конфигурация)
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318

# Для запуска PHP локально (вне Docker)
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```

**Если используете внешний коллектор:**
```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-collector.example.com:4318
```

**Телеметрия при недоступном коллекторе:**  
`RealTelemetryService` перехватывает ошибки соединения и логирует их без прерывания работы приложения. Запросы пользователей продолжат обрабатываться нормально.

---

## 3. Производительность деградировала

### Симптом

После включения телеметрии время ответа увеличилось. Пользователи замечают задержки.

### Диагностика

```bash
# Измерить время ответа с телеметрией и без
ab -n 100 -c 10 https://your-app.example.com/api/list

# Проверить overhead через атрибуты action.duration_ms в ClickHouse
SELECT
    SpanName,
    avg(toFloat64(Attributes['action.duration_ms'])) AS avg_ms,
    quantile(0.95)(toFloat64(Attributes['action.duration_ms'])) AS p95_ms,
    count() AS requests
FROM otel_traces
WHERE timestamp > now() - INTERVAL 1 HOUR
GROUP BY SpanName
ORDER BY avg_ms DESC;
```

### Типичные причины и решения

**Причина 1: Синхронная отправка блокирует запрос**

`SimpleSpanProcessor` отправляет данные синхронно в конце спана. При недоступном коллекторе — ждёт timeout.

Решение — убедиться что коллектор доступен и быстро отвечает:
```bash
curl -w '\nTime: %{time_total}s\n' http://otel-collector:4318/v1/traces
# Должно быть < 10ms в локальной сети
```

**Причина 2: Слишком много событий**

Если `trackEvent()` вызывается в tight loop:

```php
// ❌ Неправильно: телеметрия в цикле
foreach ($records as $record) {
    $this->telemetry->trackEvent('record_processed', ['id' => $record->id]);
}

// ✅ Правильно: одно суммарное событие
$start = hrtime(true);
foreach ($records as $record) {
    // обработка без телеметрии
}
$this->telemetry->trackEvent('batch_processed', [
    'action.name'        => 'process_records',
    'action.status'      => 'completed',
    'action.duration_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
    'batch.size'         => count($records),
]);
```

**Причина 3: Тяжёлые атрибуты**

```php
// ❌ Неправильно: сериализация большого объекта
$this->telemetry->trackEvent('event', [
    'debug.full_response' => json_encode($hugeApiResponse),
]);

// ✅ Правильно: только нужные поля
$this->telemetry->trackEvent('event', [
    'api.method'     => 'crm.deal.list',
    'api.status'     => 'success',
    'api.items_count' => count($hugeApiResponse['result']),
]);
```

**Причина 4: Профиль `development` в production**

```bash
# Проверить активный профиль
docker compose exec php-fpm php bin/console debug:container --parameter=telemetry.active_profile
```

Если видите `development` — переключите на `simple-ui`:
```yaml
# config/packages/telemetry.yaml
parameters:
    telemetry.active_profile: 'simple-ui'
```

---

## 4. Ошибки конфигурации

### `The "telemetry.active_profile" parameter is not defined`

```bash
# Очистить кэш
docker compose exec php-fpm php bin/console cache:clear

# Проверить файл конфигурации
docker compose exec php-fpm php bin/console lint:yaml config/packages/telemetry.yaml
```

### `Class "App\Service\Telemetry\Profiles\XxxProfile" not found`

Кастомный профиль ссылается на несуществующий класс:
```yaml
# ❌ Неправильно
my-profile:
    profiles:
        - App\Service\Telemetry\Profiles\NonExistentProfile
```

Список доступных профилей:
```bash
ls backends/php/src/Service/Telemetry/Profiles/
# LifecycleProfile.php  UIProfile.php  IntegrationProfile.php  MigrationProfile.php
```

### `Invalid YAML in telemetry.yaml`

```bash
# Проверить синтаксис YAML
php bin/console lint:yaml config/packages/telemetry.yaml
```

Частая ошибка — неправильная индентация:
```yaml
# ❌ Неправильно
parameters:
  telemetry.active_profile: 'simple-ui'  # 2 пробела

# ✅ Правильно
parameters:
    telemetry.active_profile: 'simple-ui'  # 4 пробела
```

### `TelemetryFactory: missing OTEL_EXPORTER_OTLP_ENDPOINT`

Endpoint не задан — проверить `.env.local`:
```dotenv
OTEL_EXPORTER_OTLP_ENDPOINT=http://otel-collector:4318
```

После изменения `.env.local` очистить кэш:
```bash
php bin/console cache:clear
```

---

## 5. Проблемы с профилями и фильтрацией

### Атрибуты не фильтруются (лишние данные отправляются)

```bash
# Проверить активный профиль
php bin/console debug:container --parameter=telemetry.active_profile

# Проверить вывод debug логов
# Установить level: debug в monolog и смотреть:
# "Telemetry attributes filtered by profile" — должен появиться если есть filtered_out атрибуты
```

```bash
# Очистить кэш после изменения профиля
rm -rf var/cache/*
php bin/console cache:warmup
```

### Нужные атрибуты не приходят в ClickHouse

Возможно, атрибут исключён в `exclude_patterns`:

```yaml
# config/packages/telemetry.yaml — проверить exclude_patterns
simple-ui:
    profiles: [...]
    exclude_patterns:
        - 'session.*'   # ← если здесь session.*, то session.id не пройдёт
```

Решение — убрать лишний паттерн или использовать другой профиль без этого исключения.

### Запуск тестов конфигурации

```bash
make test-telemetry-config
# или
vendor/bin/phpunit tests/Telemetry/Config/TelemetryConfigTest.php -v
```

---

## 6. Проблемы с session.id

### `session.id` не проставляется

**Причина**: `TelemetryRequestSubscriber` не зарегистрирован.

```bash
# Проверить регистрацию подписчика
php bin/console debug:event-dispatcher kernel.request | grep -i telemetry
```

Если не найден — проверить что файл существует и тегирован:
```bash
ls backends/php/src/EventSubscriber/TelemetryRequestSubscriber.php
```

**Причина**: читается не тот атрибут запроса.

```php
// ✅ Правильное имя атрибута
$sessionId = $request->attributes->get('telemetry_session_id');

// ❌ Типичная опечатка
$sessionId = $request->attributes->get('session_id');  // не тот ключ
```

### `session.id` меняется на каждом запросе

Это нормально для **серверного поведения по умолчанию** — каждый запрос PHP это новый процесс.

Для привязки к пользовательской сессии фронт должен передавать `X-Session-ID` заголовок:

```javascript
// JavaScript (фронтенд)
const sessionId = localStorage.getItem('telemetry_session_id')
    ?? crypto.randomUUID();
localStorage.setItem('telemetry_session_id', sessionId);

fetch('/api/list', {
    headers: { 'X-Session-ID': sessionId }
});
```

Subscriber автоматически использует этот заголовок если он есть.

---

## 7. Данные не появляются в Grafana / ClickHouse

### Шаг 1: проверить что события дошли до коллектора

```bash
# Логи коллектора — должны быть строки "Exporting ..."
docker compose logs otel-collector | grep -i export | tail -10
```

### Шаг 2: проверить что ClickHouse получил данные

```bash
# Подключиться к ClickHouse
docker compose exec clickhouse clickhouse-client

# Проверить последние записи
SELECT count(), max(Timestamp) FROM otel_traces;

# Если пустая таблица — данные не дошли до ClickHouse
```

### Шаг 3: проверить конфигурацию экспортёра в коллекторе

```yaml
# b24-ai-starter-otel/otel-collector/config.yaml
exporters:
  clickhouse:
    endpoint: tcp://clickhouse:9000?dial_timeout=10s
    database: otel
    ttl: 72h
    # traces_table_name должна совпадать с именем таблицы в ClickHouse
    traces_table_name: otel_traces
```

### Шаг 4: проверить дашборд в Grafana

```bash
# Открыть Grafana
open http://localhost:3000
# Логин: admin / admin (по умолчанию)
# Dashboard: OTel Traces
```

Если данные в ClickHouse есть, но в Grafana нет — проверить datasource:

1. Grafana → Connections → Data Sources → ClickHouse
2. Test connection → должно быть OK
3. Проверить что в запросах дашборда правильная база данных (`telemetry`) и таблица (`otel_logs` / `otel_traces`)

---

## 8. Spans не появляются в otel_traces

### Симптом

`trackOperation()` вызывается в коде, но таблица `otel_traces` остаётся пустой, тогда как `otel_logs` заполняется нормально.

### Диагностика

**Шаг 1: проверить pipeline traces в коллекторе**

```bash
# Логи коллектора — искать ошибки экспорта traces
docker compose logs otel-collector | grep -i "trace\|error" | tail -20
```

**Шаг 2: проверить что /v1/traces доступен**

```bash
# С хоста
curl -s -o /dev/null -w '%{http_code}' http://localhost:4318/v1/traces
# С PHP-контейнера
docker compose exec php-fpm curl -s http://otel-collector:4318/v1/traces
```

**Шаг 3: проверить таблицу в ClickHouse**

```bash
docker compose exec clickhouse clickhouse-client \
  --user telemetry_user --password changeme_secure_password \
  --query "SELECT count(), max(Timestamp) FROM telemetry.otel_traces"
```

**Шаг 4: убедиться что используется `trackOperation()`, а не `trackEvent()`**

```php
// ✅ ПИШЕТ в otel_traces
$result = $this->telemetry->trackOperation('my.operation', fn () => $this->doWork());

// ❌ ПИШЕТ в otel_logs (не otel_traces!)
$this->telemetry->trackEvent('my_event', ['duration_ms' => 100]);
```

### Решения

| Причина | Решение |
|---|---|
| `TracerProvider` не инициализировался | Проверить `RealTelemetryService` — ошибки в `initialize()` в PHP-логах |
| Коллектор не принимает `/v1/traces` | Проверить `traces:` pipeline в `otel-collector/config.yaml` |
| Таблица `otel_traces` не создана в БД | Применить `clickhouse/schema/04_otel_traces.sql` |
| Используется `NullTelemetryService` | `TELEMETRY_ENABLED=false` — включить телеметрию |

**Применить/пересоздать схему traces:**

```bash
docker compose exec clickhouse clickhouse-client \
  --user telemetry_user --password changeme_secure_password \
  < backends/php/../../../b24-ai-starter-otel/clickhouse/schema/04_otel_traces.sql
```

---

## 9. FAQ

### Q: Телеметрия влияет на логи Monolog?

**A**: Да, но минимально. `MonologOTelHandler` отправляет лог-записи уровня WARNING и выше в OTel Collector параллельно с основными логами. Это не заменяет файловые логи — данные дублируются.

### Q: Как сбросить все spans если что-то зависло?

```bash
# Перезапустить PHP-FPM (сбросит буферы)
docker compose restart php-fpm

# Перезапустить коллектор
docker compose restart otel-collector
```

### Q: Можно ли отключить телеметрию только для одного эндпоинта?

**A**: Лучший способ — использовать `isEnabled()` и guard clause:

```php
if (!$this->telemetry->isEnabled()) {
    return $this->processWithoutTelemetry($request);
}
```

Но правильнее — не отключать для конкретного эндпоинта, а просто не вызывать `trackEvent()` там где это не нужно.

### Q: Почему атрибуты типа `float` не сохраняются в ClickHouse?

**A**: OpenTelemetry хранит атрибуты как строки в ClickHouse-схеме по умолчанию. Передавайте числа как `int` или `string`:

```php
// ❌ float может потеряться
'action.duration_ms' => 123.456,

// ✅ int или string
'action.duration_ms' => (int) round($ms),
'amount'             => number_format($amount, 2),
```

### Q: Как тестировать телеметрию в unit-тестах?

**A**: Используйте mock `TelemetryInterface`:

```php
use App\Service\Telemetry\TelemetryInterface;
use PHPUnit\Framework\TestCase;

class MyServiceTest extends TestCase
{
    public function testActionSendsEvent(): void
    {
        $telemetry = $this->createMock(TelemetryInterface::class);
        $telemetry->expects($this->once())
            ->method('trackEvent')
            ->with('action_completed', $this->arrayHasKey('action.name'));

        $service = new MyService($telemetry);
        $service->performAction('test');
    }
}
```

Или используйте `NullTelemetryService` для тестов где события не важны:

```php
use App\Service\Telemetry\NullTelemetryService;

$service = new MyService(new NullTelemetryService());
```

### Q: Как убедиться что shutdown() вызывается перед завершением CLI команды?

**A**: Для CLI команд (Symfony Console) важно вызвать `shutdown()` явно или через деструктор:

```php
// В конце команды
$this->telemetry->shutdown();
```

Или зарегистрировать через `register_shutdown_function` в `TelemetryFactory` — это уже сделано для HTTP-запросов через `kernel.terminate`.

---

## Быстрая диагностика (cheatsheet)

```bash
# 1. Включена ли телеметрия?
docker compose exec php-fpm env | grep TELEMETRY_ENABLED

# 2. Какой класс используется?
docker compose exec php-fpm php bin/console debug:container App\\Service\\Telemetry\\TelemetryInterface

# 3. Доступен ли коллектор?
docker compose exec php-fpm curl -s http://otel-collector:4318/v1/traces

# 4. Есть ли данные в ClickHouse?
docker compose exec clickhouse clickhouse-client \
  --user telemetry_user --password changeme_secure_password \
  --query "SELECT count() FROM telemetry.otel_logs"

# 4b. Есть ли трейсы (spans из trackOperation)?
docker compose exec clickhouse clickhouse-client \
  --user telemetry_user --password changeme_secure_password \
  --query "SELECT count() FROM telemetry.otel_traces"

# 5. Какой активный профиль?
docker compose exec php-fpm php bin/console debug:container --parameter=telemetry.active_profile

# 6. Ошибки в логах PHP?
docker compose logs php-fpm 2>&1 | grep -i "telemetry\|otel\|otlp" | tail -20

# 7. Запустить тесты телеметрии
make test-telemetry
```

---

## См. также

- [telemetry-quickstart.md](telemetry-quickstart.md) — быстрый старт и конфигурация
- [telemetry-profiles-config.md](telemetry-profiles-config.md) — конфигурация профилей
- [telemetry-examples-ui-apps.md](telemetry-examples-ui-apps.md) — примеры интеграции
- [telemetry-integration-points.md](telemetry-integration-points.md) — все точки интеграции
