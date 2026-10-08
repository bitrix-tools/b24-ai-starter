from http import HTTPStatus

from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.clickjacking import xframe_options_exempt
from django.views.decorators.http import require_POST

from b24pysdk import Config as B24Config
from b24pysdk.integrations.django.decorators import event_required
from b24pysdk.integrations.django.types import EventRequest

from bitrix_events.event_processor import process_bitrix24_event
from config import config


def dispatch_event(event_data, *, queue_enabled: bool) -> JsonResponse:
    """
    Queue the event for python-worker when RabbitMQ is enabled, otherwise process it
    inline — with ENABLE_RABBITMQ=0 there is no broker and no worker, and a queued
    event would never be processed.
    """
    if queue_enabled:
        if not hasattr(process_bitrix24_event, "delay"):
            B24Config().logger.error(
                "Bitrix24 event queue is not configured",
                context={"event_code": event_data.event},
            )
            return JsonResponse({"error": "Queue backend is not configured"}, status=HTTPStatus.SERVICE_UNAVAILABLE)

        process_bitrix24_event.delay(event_data)
        return JsonResponse({"status": "queued"})

    process_bitrix24_event(event_data)
    return JsonResponse({"status": "processed"})


@xframe_options_exempt
@csrf_exempt
@require_POST
@event_required
def app_events(request: EventRequest):
    """Handle a validated Bitrix24 lifecycle event (queued or inline, see dispatch_event)."""
    return dispatch_event(request.oauth_event_data, queue_enabled=config.queue_enabled)
