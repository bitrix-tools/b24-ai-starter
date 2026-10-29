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

from .models import SupportMessage, SupportTicket

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


# --- Переписка: тред поверх обращения ---------------------------------------
# Обращение — первое сообщение клиента (текст в SupportTicket), ответы и
# продолжение разговора лежат в SupportMessage. Отдельной записи на первый
# текст нет, поэтому старые обращения показываются как есть.


def first_bubble(ticket: SupportTicket) -> dict:
    return {
        "id": f"ticket-{ticket.id}",
        "author": SupportMessage.AUTHOR_USER,
        "authorName": ticket.user_name or "Вы",
        "text": ticket.message,
        "attachment": "",
        "createdAt": ticket.created_at.isoformat(),
    }


def _attachment_url(file_name: str) -> str:
    # У Точки нет хранилища картинок — вложений в треде не бывает.
    return ""


def message_json(message: SupportMessage, ticket: SupportTicket) -> dict:
    if message.author == SupportMessage.AUTHOR_SUPPORT:
        name = message.author_name or "Поддержка"
    else:
        name = message.author_name or ticket.user_name or "Вы"
    return {
        "id": str(message.id),
        "author": message.author,
        "authorName": name,
        "text": message.text,
        "attachment": _attachment_url(message.attachment),
        "createdAt": message.created_at.isoformat(),
    }


def thread(ticket: SupportTicket) -> list:
    out = [first_bubble(ticket)]
    out.extend(message_json(m, ticket) for m in ticket.messages.all())
    return out


def add_user_message(ticket: SupportTicket, text: str, *, user_name: str = "") -> SupportMessage:
    """Дослать сообщение клиента в существующий тред; продолжение дублируем в Telegram."""
    message = SupportMessage.objects.create(
        ticket=ticket,
        author=SupportMessage.AUTHOR_USER,
        author_name=user_name.strip()[:255],
        text=text.strip(),
    )
    if is_configured():
        header = f"💬 Ещё сообщение по обращению от {ticket.domain_url or ticket.member_id}"
        body = f"{header}\n\n{text.strip()[:MESSAGE_LIMIT]}"
        payload = urllib.parse.urlencode({
            "chat_id": config.support_telegram_chat_id,
            "text": body,
            "disable_web_page_preview": "true",
        }).encode("utf-8")
        err = _post(
            TELEGRAM_API.format(token=config.support_telegram_token),
            payload,
            "application/x-www-form-urlencoded",
        )
        if err:
            logger.warning("продолжение обращения %s не ушло в мессенджер: %s", ticket.id, err)
    return message


def add_support_reply(ticket: SupportTicket, text: str, *, author_name: str = "", attachment: str = "") -> SupportMessage:
    """Ответ поддержки в тред (из панели/командного центра); клиент видит при опросе."""
    return SupportMessage.objects.create(
        ticket=ticket,
        author=SupportMessage.AUTHOR_SUPPORT,
        author_name=author_name.strip()[:255],
        text=text.strip(),
        attachment=attachment,
    )


def is_unanswered(ticket: SupportTicket) -> bool:
    """Ждёт ли обращение ответа: последнее слово за клиентом."""
    last_support = None
    last_user = ticket.created_at
    for message in ticket.messages.all():
        if message.author == SupportMessage.AUTHOR_SUPPORT:
            if last_support is None or message.created_at > last_support:
                last_support = message.created_at
        elif message.created_at > last_user:
            last_user = message.created_at
    return last_support is None or last_user > last_support
