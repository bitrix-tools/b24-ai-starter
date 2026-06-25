# 🐍 Python + RabbitMQ

Пример настройки Celery для обработчиков фоновых задач. Предполагается существующий Django/ASGI backend из стартера.

## 1. Зависимости
`backends/python/django/requirements.txt`:
```
celery==5.4.0
kombu==5.3.5
```

Установите и пересоберите контейнер:
```bash
docker compose build api-python
```

## 2. Конфигурация Celery (`backends/python/django/celery_app.py`)
```python
import os
from celery import Celery

broker_url = os.getenv(
    "CELERY_BROKER_URL",
    os.getenv("RABBITMQ_DSN", "amqp://queue_user:queue_password@rabbitmq:5672//"),
)

celery_app = Celery("b24_app", broker=broker_url)
celery_app.conf.task_acks_late = True
celery_app.conf.worker_prefetch_multiplier = int(
    os.getenv("RABBITMQ_PREFETCH", "5")
)
```

## 3. Задачи (`backends/python/django/bitrix_events/event_processor.py`)
```python
from celery_app import celery_app

def process_bitrix24_event(event: OAuthEventData):
    Bitrix24EventProcessor(event).process()
```

## 4. Публикация заданий
```python
from bitrix_events.event_processor import process_bitrix24_event

def app_events(request):
    process_bitrix24_event.delay(request.oauth_event_data)
    return JsonResponse({"status": "queued"})
```

## 5. Переменные окружения
```
CELERY_BROKER_URL=${RABBITMQ_DSN}
```

## 6. Запуск воркера
```bash
COMPOSE_PROFILES=python,queue docker compose --env-file .env run --rm \
  api-python celery -A celery_app.celery_app worker --loglevel=info
```

> Для продакшна вынесите воркер в отдельный сервис Docker или управляйте им через Supervisor/systemd.

