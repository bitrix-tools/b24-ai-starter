"""Обращения в поддержку из приложения.

Права здесь не проверяются намеренно: написать в поддержку вправе любой, кто
открыл приложение. Обращение записывается в базу и пересылается в общий
Telegram-канал поддержки 1daa.ru.
"""

import json
import logging
from http import HTTPStatus
from typing import Any, Dict

from django.conf import settings as django_settings
from django.http import JsonResponse
from django.views.decorators.clickjacking import xframe_options_exempt
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST

from . import support
from .models import SupportTicket
from .utils import AuthorizedRequest
from .utils.decorators import auth_required, log_errors

logger = logging.getLogger(__name__)

SUPPORT_MIN_LENGTH = 10
SUPPORT_MAX_LENGTH = 8000


def _bad(message: str) -> JsonResponse:
    return JsonResponse({"error": message}, status=HTTPStatus.BAD_REQUEST)


def _payload(request) -> Dict[str, Any]:
    try:
        return json.loads(request.body or b"{}")
    except (ValueError, TypeError):
        return {}


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("support_submit")
@auth_required
def support_submit(request: AuthorizedRequest):
    """Обращение в поддержку прямо из приложения.

    Отвечаем успехом, даже если переслать владельцу не удалось: обращение уже
    записано, и человеку незачем видеть ошибку доставки.
    """

    account = request.bitrix24_account

    upload = request.FILES.get("file") if request.FILES else None
    if upload is not None or request.POST:
        payload: Dict[str, Any] = {key: request.POST.get(key) for key in request.POST}
    else:
        payload = _payload(request)

    message = str(payload.get("message") or "").strip()

    if len(message) < SUPPORT_MIN_LENGTH:
        return _bad("Опишите вопрос чуть подробнее — так мы ответим быстрее")
    if len(message) > SUPPORT_MAX_LENGTH:
        return _bad(
            "Слишком длинное сообщение. Напишите на info@1daa.ru — там можно с вложениями"
        )

    attachment = None
    if upload is not None:
        limit = getattr(django_settings, "SUPPORT_UPLOAD_MAX_BYTES", 10 * 1024 * 1024)
        if upload.size > limit:
            return _bad(
                f"Файл больше {limit // (1024 * 1024)} МБ. Пришлите его на info@1daa.ru"
            )
        attachment = (upload.name, upload.read())

    ticket = support.submit(
        member_id=account.member_id,
        domain_url=account.domain_url,
        message=message,
        contact=str(payload.get("contact") or "")[:255],
        user_id=account.b24_user_id,
        user_name=str(payload.get("userName") or "")[:255],
        attachment=attachment,
    )

    if not ticket.delivered:
        logger.error(
            "Обращение %s записано, но не доставлено: %s",
            ticket.id, ticket.delivery_error,
        )

    return JsonResponse({"id": str(ticket.id), "status": "accepted"})


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("support_contact")
@auth_required
def support_contact(request: AuthorizedRequest, ticket_id):
    """Контакт для ответа, добавленный уже после отправки обращения."""

    account = request.bitrix24_account
    contact = str(_payload(request).get("contact") or "").strip()[:255]
    if not contact:
        return _bad("Укажите почту или телефон")

    ticket = SupportTicket.objects.filter(
        member_id=account.member_id, pk=ticket_id,
    ).first()
    if ticket is None:
        return _bad("Обращение не найдено")

    support.attach_contact(ticket, contact)

    return JsonResponse({"id": str(ticket.id), "status": "saved"})
