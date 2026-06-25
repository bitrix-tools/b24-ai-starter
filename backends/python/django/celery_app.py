import os

from celery import Celery

os.environ.setdefault("DJANGO_SETTINGS_MODULE", "settings")

broker_url = os.getenv("CELERY_BROKER_URL", os.getenv("RABBITMQ_DSN", "amqp://queue_user:queue_password@rabbitmq:5672//"))

celery_app = Celery("b24_app", broker=broker_url)
celery_app.config_from_object("django.conf:settings", namespace="CELERY")
celery_app.conf.imports = ("bitrix_events.event_processor",)
celery_app.autodiscover_tasks()
celery_app.conf.task_acks_late = True
celery_app.conf.worker_prefetch_multiplier = int(os.getenv("RABBITMQ_PREFETCH", "5"))
