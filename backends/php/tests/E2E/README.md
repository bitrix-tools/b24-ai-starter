# End-to-End Telemetry Testing

## Назначение

End-to-End тесты проверяют полный цикл отправки телеметрии:
1. Событие генерируется в PHP приложении
2. Отправляется в OTel Collector через OTLP
3. Сохраняется в ClickHouse
4. Доступно для запросов

## Предварительные требования

### 1. Запуск инфраструктуры b24-ai-starter-otel

```bash
cd /path/to/b24-ai-starter-otel
make up
```

Это запустит:
- OTel Collector (порт 4318 - OTLP HTTP)
- ClickHouse (порт 9000, HTTP 8123)
- Grafana (порт 3000)

### 2. Проверка доступности

```bash
# Проверить что Collector принимает запросы
curl http://localhost:4318/v1/traces

# Проверить ClickHouse
curl http://localhost:8123/ping
```

## Запуск E2E тестов

### Автоматический тест (рекомендуется)

```bash
cd /path/to/b24-ai-starter-ru
make test-telemetry-e2e
```

Эта команда запускает автоматизированный скрипт, который:
1. ✅ Проверяет доступность ClickHouse
2. ✅ Проверяет доступность OTel Collector
3. ✅ Отправляет тестовое событие через PHP
4. ✅ Ждет обработки (3 секунды)
5. ✅ Проверяет наличие события в ClickHouse
6. ✅ Выводит результат теста

**Успешный вывод:**
```
================================
✓ E2E Test PASSED
================================
```

### Прямой запуск скрипта

```bash
cd backends/php
./tests/Telemetry/E2E/run-e2e-test.sh
```

### Ручное тестирование

#### Шаг 1: Включить телеметрию

Отредактировать `backends/php/.env`:
```env
TELEMETRY_ENABLED=true
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
```

#### Шаг 2: Отправить тестовое событие

```bash
# Запустить PHP CLI контейнер
make php-cli-sh

# В контейнере выполнить:
php -r "
require_once 'vendor/autoload.php';
\$kernel = new \App\Kernel('dev', true);
\$kernel->boot();
\$telemetry = \$kernel->getContainer()->get('App\Service\Telemetry\TelemetryInterface');
\$telemetry->trackEvent('e2e_test_event', [
    'test_id' => 'manual_' . time(),
    'source' => 'manual_test',
    'timestamp' => time(),
]);
echo \"Event sent!\n\";
"
```

#### Шаг 3: Проверить в ClickHouse

```bash
# Подключиться к ClickHouse
docker exec -it clickhouse-server clickhouse-client

# Выполнить запрос
SELECT 
    Timestamp,
    ServiceName,
    SpanName,
    SpanKind,
    Duration
FROM otel.otel_traces
WHERE SpanName = 'e2e_test_event'
ORDER BY Timestamp DESC
LIMIT 10;
```

#### Шаг 4: Проверить в Grafana

1. Открыть http://localhost:3000
2. Логин: admin / admin
3. Перейти в Explore
4. Выбрать ClickHouse datasource
5. Выполнить запрос:

```sql
SELECT * FROM otel.otel_traces 
WHERE SpanName = 'e2e_test_event'
ORDER BY Timestamp DESC
LIMIT 10
```

## Проверка атрибутов

События должны содержать стандартные атрибуты:

- `service.name` = b24-app (или значение из .env)
- `service.version` = 1.0.0 (или значение из .env)
- `deployment.environment` = development (или значение из .env)
- `host.name` = <имя хоста>
- `process.pid` = <PID процесса>

Плюс пользовательские атрибуты из вызова `trackEvent()`.

## Проверка производительности

### Латентность отправки

Событие должно появиться в ClickHouse в течение нескольких секунд после отправки.

```sql
-- Проверить временную задержку
SELECT 
    SpanName,
    Timestamp,
    now() - Timestamp AS age_seconds
FROM otel.otel_traces
WHERE SpanName = 'e2e_test_event'
ORDER BY Timestamp DESC
LIMIT 1;
```

### Throughput

Проверить что система справляется с нагрузкой:

```bash
# Отправить 100 событий
for i in {1..100}; do
    php -r "/* код отправки события */";
done

# Проверить что все события дошли
SELECT COUNT(*) FROM otel.otel_traces 
WHERE SpanName = 'e2e_test_event' 
AND Timestamp > now() - INTERVAL 1 MINUTE;
```

## Troubleshooting

### События не доходят до ClickHouse

1. Проверить логи Collector:
```bash
docker logs otel-collector
```

2. Проверить что Collector принимает данные:
```bash
curl -v http://localhost:4318/v1/traces \
  -H "Content-Type: application/json" \
  -d '{}'
```

3. Проверить логи PHP приложения:
```bash
tail -f backends/php/var/log/dev.log | grep OpenTelemetry
```

### Ошибки в PHP приложении

Если `TELEMETRY_ENABLED=true` вызывает ошибки:
- Проверить что все зависимости установлены: `composer install`
- Проверить версии пакетов OpenTelemetry
- Проверить логи для деталей ошибки

### ClickHouse недоступен

```bash
# Проверить статус контейнера
docker ps | grep clickhouse

# Перезапустить инфраструктуру
cd /path/to/b24-ai-starter-otel
make down
make up
```

## Критерии успеха E2E тестов

- ✅ События успешно отправляются из PHP
- ✅ OTel Collector получает и обрабатывает события
- ✅ События сохраняются в ClickHouse
- ✅ Все стандартные атрибуты присутствуют
- ✅ Пользовательские атрибуты корректно сохраняются
- ✅ Латентность доставки < 5 секунд
- ✅ Система обрабатывает нагрузку без потерь данных

## Дальнейшие шаги

После успешного прохождения E2E тестов:
1. Sprint 2 считается завершенным
2. Можно переходить к Sprint 3 (система профилей)
3. Рекомендуется задокументировать результаты тестирования
