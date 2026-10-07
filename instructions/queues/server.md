# 🐇 RabbitMQ: сервер очередей

## Зачем он нужен
- Хранит события Bitrix24 и фоновые задачи, чтобы разгрузить веб-приложение.
- Работает в Docker профиле `queue`, поэтому запускается только по запросу.

## Как включить
1. Запустите `make dev-init`.
2. Ответьте «y» на вопрос «Включить RabbitMQ для фоновых задач?».
3. Выберите режим:
   - **Автоматически** – скрипт сгенерирует логин, пароль и prefetch, покажет их на экране и внесёт в `.env`.
   - **Вручную** – можно задать значения самостоятельно.

Флаги и переменные:
```
ENABLE_RABBITMQ=1
RABBITMQ_USER=queue_xxx
RABBITMQ_PASSWORD=***
RABBITMQ_PREFETCH=5
RABBITMQ_DSN=amqp://queue_xxx:...@rabbitmq:5672/%2f
```

## Docker Compose
- Сервис `rabbitmq` использует образ `rabbitmq:3.13-management-alpine`, порты `5672` и `15672`.
- Том `rabbitmq_data` хранит сообщения, `logs/rabbitmq/` – логи.
- `make dev-php`, `make dev-python` и `make dev-node` сами добавляют профиль `queue`, если в `.env` задано `ENABLE_RABBITMQ=1` (для Python — ещё и `python-worker`).

## Доступ
- AMQP: `amqp://rabbitmq:5672`
- Панель управления: <http://localhost:15672> (используйте креды из `.env`)

## Ручной запуск/остановка
- `make queue-up` — стартует только сервис RabbitMQ (подтянет профиль `queue`, даже если основной стек выключен).
- `make queue-down` — останавливает (`stop`) только RabbitMQ, остальные контейнеры продолжают работать.
- Перед запуском убедитесь, что в `.env` задано `ENABLE_RABBITMQ=1`: иначе `make queue-up` выведет предупреждение, сервис поднимется, но переменные окружения не будут заполнены автоматически.

## Что делать дальше
- Настройте бэкенд в соответствии с выбранным стеком:
  - [PHP + Messenger](./php.md)
  - [Python + Celery](./python.md)
  - [Node.js + amqplib](./node.md)

