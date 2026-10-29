"""Доставка обращений из формы поддержки (Telegram).

Обращение всегда сначала записывается в базу и только потом пересылается
владельцу: канал доставки может быть не настроен или отвалиться, и тогда
сообщение клиента исчезло бы бесследно — притом что он открыл форму именно
потому, что у него что-то не работает. Портировано из эталонного приложения
1daa.ru (навык b24-app-ui), общий Telegram-канал поддержки (TG_BOT_TOKEN/TG_CHAT_ID).
"""

from __future__ import annotations

import json
import logging
import mimetypes
import urllib.error
import urllib.parse
import urllib.request
import uuid as uuid_module
from typing import Optional, Tuple

from config import config

from .models import SupportTicket

logger = logging.getLogger(__name__)

TELEGRAM_API = "https://api.telegram.org/bot{token}/sendMessage"
TELEGRAM_DOCUMENT_API = "https://api.telegram.org/bot{token}/sendDocument"
REQUEST_TIMEOUT_SECONDS = 10

#: Телеграм режет сообщения длиннее 4096 символов. Режем сами; полный текст
#: остаётся в базе.
MESSAGE_LIMIT = 3500

APP_TITLE = "Точка Банк. Интеграция"


def is_configured() -> bool:
    return bool(config.support_telegram_token and config.support_telegram_chat_id)


def format_message(ticket: SupportTicket) -> str:
    lines = [
        f"🆘 Обращение из приложения «{APP_TITLE}»",
        "",
        f"Портал: {ticket.domain_url or ticket.member_id}",
    ]
    if ticket.user_name or ticket.user_id:
        lines.append(f"От кого: {ticket.user_name or '—'} (id {ticket.user_id or '—'})")
    if ticket.contact:
        lines.append(f"Связь: {ticket.contact}")
    lines.append("")

    text = ticket.message.strip()
    if len(text) > MESSAGE_LIMIT:
        text = text[:MESSAGE_LIMIT] + "… (обрезано, полный текст в админке)"
    lines.append(text)

    return "\n".join(lines)


def _multipart(fields: dict, file_name: str, file_bytes: bytes) -> Tuple[bytes, str]:
    boundary = f"----tochkafile{uuid_module.uuid4().hex}"
    line_break = b"\r\n"
    body = bytearray()

    for name, value in fields.items():
        body += b"--" + boundary.encode() + line_break
        body += f'Content-Disposition: form-data; name="{name}"'.encode() + line_break
        body += line_break
        body += str(value).encode("utf-8") + line_break

    content_type = mimetypes.guess_type(file_name)[0] or "application/octet-stream"
    body += b"--" + boundary.encode() + line_break
    body += (
        f'Content-Disposition: form-data; name="document"; filename="{file_name}"'
    ).encode("utf-8") + line_break
    body += f"Content-Type: {content_type}".encode() + line_break
    body += line_break
    body += file_bytes + line_break
    body += b"--" + boundary.encode() + b"--" + line_break

    return bytes(body), f"multipart/form-data; boundary={boundary}"


def _post(url: str, data: bytes, content_type: str) -> Optional[str]:
    """POST к Телеграму. Возвращает текст ошибки или None."""

    request = urllib.request.Request(url, data=data)
    request.add_header("Content-Type", content_type)

    try:
        with urllib.request.urlopen(request, timeout=REQUEST_TIMEOUT_SECONDS) as response:
            body = json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as error:
        try:
            detail = error.read().decode("utf-8")[:300]
        except Exception:  # noqa: BLE001
            detail = str(error)
        logger.error("Телеграм отказал: %s", detail)
        return detail
    except Exception as error:  # noqa: BLE001
        logger.exception("Запрос к Телеграму не прошёл")
        return str(error)

    if not body.get("ok"):
        return str(body.get("description") or body)

    return None


def deliver_attachment(ticket: SupportTicket, file_name: str, file_bytes: bytes) -> Optional[str]:
    if not is_configured():
        return "канал доставки не настроен"

    caption = f"Вложение к обращению от {ticket.domain_url or ticket.member_id}"
    data, content_type = _multipart(
        {"chat_id": config.support_telegram_chat_id, "caption": caption[:1000]},
        file_name,
        file_bytes,
    )

    return _post(
        TELEGRAM_DOCUMENT_API.format(token=config.support_telegram_token),
        data,
        content_type,
    )


def deliver(ticket: SupportTicket) -> Optional[str]:
    if not is_configured():
        return "канал доставки не настроен"

    payload = urllib.parse.urlencode({
        "chat_id": config.support_telegram_chat_id,
        "text": format_message(ticket),
        "disable_web_page_preview": "true",
    }).encode("utf-8")

    return _post(
        TELEGRAM_API.format(token=config.support_telegram_token),
        payload,
        "application/x-www-form-urlencoded",
    )


def submit(
    *,
    member_id: str,
    domain_url: str,
    message: str,
    contact: str = "",
    user_id: Optional[int] = None,
    user_name: str = "",
    attachment: Optional[Tuple[str, bytes]] = None,
) -> SupportTicket:
    """Сохраняет обращение и пытается его переслать вместе с вложением."""

    file_name = attachment[0] if attachment else ""

    ticket = SupportTicket.objects.create(
        member_id=member_id,
        domain_url=domain_url,
        message=message.strip(),
        contact=contact.strip(),
        user_id=user_id,
        user_name=user_name.strip(),
        attachment_name=file_name[:255],
        attachment_size=len(attachment[1]) if attachment else 0,
    )

    error = deliver(ticket)

    if not error and attachment:
        attachment_error = deliver_attachment(ticket, attachment[0], attachment[1])
        if attachment_error:
            error = f"текст доставлен, вложение — нет: {attachment_error}"

    ticket.delivered = error is None
    ticket.delivery_error = error or ""
    ticket.save(update_fields=["delivered", "delivery_error"])

    return ticket


def attach_contact(ticket: SupportTicket, contact: str) -> Optional[str]:
    ticket.contact = contact.strip()[:255]
    ticket.save(update_fields=["contact"])

    if not is_configured():
        return "канал доставки не настроен"

    text = (
        f"☎️ Контакт для ответа: {ticket.contact}\n"
        f"Портал: {ticket.domain_url or ticket.member_id}"
    )
    payload = urllib.parse.urlencode({
        "chat_id": config.support_telegram_chat_id,
        "text": text,
        "disable_web_page_preview": "true",
    }).encode("utf-8")

    return _post(
        TELEGRAM_API.format(token=config.support_telegram_token),
        payload,
        "application/x-www-form-urlencoded",
    )
