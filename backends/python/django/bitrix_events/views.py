from http import HTTPStatus

from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.clickjacking import xframe_options_exempt
from django.views.decorators.http import require_POST

from b24pysdk import Config as B24Config
from b24pysdk.integrations.django.decorators import event_required
from b24pysdk.integrations.django.types import EventRequest

from bitrix_events.event_processor import process_bitrix24_event


@xframe_options_exempt
@csrf_exempt
@require_POST
@event_required
def app_events(request: EventRequest):
    """Queue a Bitrix24 lifecycle event for asynchronous processing."""
    if not hasattr(process_bitrix24_event, "delay"):
        B24Config().logger.error(
            "Bitrix24 event queue is not configured",
            context={
                "event_code": request.oauth_event_data.event,
            },
        )
        return JsonResponse({"error": "Queue backend is not configured"}, status=HTTPStatus.SERVICE_UNAVAILABLE)

    process_bitrix24_event.delay(request.oauth_event_data)

    return JsonResponse({"status": "queued"})
