"""Минимальные вызовы Bitrix24 REST по access_token портала (без встраивания в oauth flow)."""

from __future__ import annotations

import logging
import time
from typing import Any, Dict, Optional

import requests

from .models import Bitrix24Account
from .tochka_bot_avatar import tochka_chat_bot_avatar_base64

logger = logging.getLogger(__name__)


def bitrix_rest_call(account: Bitrix24Account, method: str, params: Dict[str, Any]) -> Dict[str, Any]:
    base = (account.domain_url or "").strip()
    if not base.startswith("http"):
        base = f"https://{base}"
    url = f"{base.rstrip('/')}/rest/{method}"
    body = {**params, "auth": account.access_token}
    r = requests.post(url, json=body, timeout=45)
    try:
        return r.json()
    except Exception:
        return {"_http_error": r.text, "_status": r.status_code}


def _sync_entity_payment_uf(
    account: Bitrix24Account,
    method: str,
    entity_id: int,
    *,
    payment_kind: str | None = None,
    channel_code: str | None = None,
    kind_uf: str | None = None,
    channel_uf: str | None = None,
) -> None:
    fields: Dict[str, Any] = {}
    if kind_uf and payment_kind:
        fields[kind_uf] = payment_kind
    if channel_uf and channel_code:
        fields[channel_uf] = channel_code
    if not fields:
        return
    bitrix_rest_call(account, method, {"id": entity_id, "fields": fields})


def sync_deal_payment_uf(
    account: Bitrix24Account,
    deal_id: int,
    *,
    payment_kind: str | None = None,
    channel_code: str | None = None,
    kind_uf: str | None = None,
    channel_uf: str | None = None,
) -> None:
    _sync_entity_payment_uf(
        account, "crm.deal.update", deal_id,
        payment_kind=payment_kind, channel_code=channel_code,
        kind_uf=kind_uf, channel_uf=channel_uf,
    )


def sync_lead_payment_uf(
    account: Bitrix24Account,
    lead_id: int,
    *,
    payment_kind: str | None = None,
    channel_code: str | None = None,
    kind_uf: str | None = None,
    channel_uf: str | None = None,
) -> None:
    _sync_entity_payment_uf(
        account, "crm.lead.update", lead_id,
        payment_kind=payment_kind, channel_code=channel_code,
        kind_uf=kind_uf, channel_uf=channel_uf,
    )


def crm_item_get(account: Bitrix24Account, entity_type_id: int, item_id: int) -> Dict[str, Any]:
    return bitrix_rest_call(
        account,
        "crm.item.get",
        {"entityTypeId": int(entity_type_id), "id": int(item_id)},
    )


def crm_item_list(
    account: Bitrix24Account,
    entity_type_id: int,
    filter_dict: Dict[str, Any],
    *,
    select: list[str] | None = None,
    start: int = 0,
    limit: int = 50,
) -> Dict[str, Any]:
    params: Dict[str, Any] = {
        "entityTypeId": int(entity_type_id),
        "filter": filter_dict,
        "start": start,
    }
    if select:
        params["select"] = select
    # Bitrix accepts limit in some versions
    params["limit"] = limit
    return bitrix_rest_call(account, "crm.item.list", params)


def crm_item_update(
    account: Bitrix24Account,
    entity_type_id: int,
    item_id: int,
    fields: Dict[str, Any],
) -> Dict[str, Any]:
    return bitrix_rest_call(
        account,
        "crm.item.update",
        {"entityTypeId": int(entity_type_id), "id": int(item_id), "fields": fields},
    )


# --- Классические счета CRM (crm.invoice.*, статусы INVOICE_STATUS) ---


def crm_invoice_get(account: Bitrix24Account, invoice_id: int) -> Dict[str, Any]:
    return bitrix_rest_call(account, "crm.invoice.get", {"id": int(invoice_id)})


def crm_invoice_list(
    account: Bitrix24Account,
    filter_dict: Dict[str, Any],
    *,
    select: list[str] | None = None,
    order: Dict[str, str] | None = None,
    start: int = 0,
) -> Dict[str, Any]:
    params: Dict[str, Any] = {"filter": filter_dict, "start": start}
    if select:
        params["select"] = select
    if order:
        params["order"] = order
    return bitrix_rest_call(account, "crm.invoice.list", params)


def crm_invoice_update(
    account: Bitrix24Account, invoice_id: int, fields: Dict[str, Any]
) -> Dict[str, Any]:
    return bitrix_rest_call(account, "crm.invoice.update", {"id": int(invoice_id), "fields": fields})


def portal_base_url(account: Bitrix24Account) -> str:
    u = (account.domain_url or "").strip()
    if not u.startswith("http"):
        u = f"https://{u}"
    return u.rstrip("/")


def crm_deal_public_path(deal_id: int) -> str:
    return f"/crm/deal/details/{int(deal_id)}/"


def crm_invoice_spa_public_path(invoice_item_id: int, entity_type_id: int = 31) -> str:
    """Карточка универсального счёта (смарт-процесс), как в UI Bitrix24."""
    return f"/crm/type/{int(entity_type_id)}/details/{int(invoice_item_id)}/"


def crm_company_public_path(company_id: int) -> str:
    return f"/crm/company/details/{int(company_id)}/"


def crm_contact_public_path(contact_id: int) -> str:
    return f"/crm/contact/details/{int(contact_id)}/"


def crm_deal_get(account: Bitrix24Account, deal_id: int) -> Dict[str, Any]:
    return bitrix_rest_call(account, "crm.deal.get", {"id": int(deal_id)})


def crm_company_list(
    account: Bitrix24Account,
    filter_dict: Dict[str, Any],
    *,
    select: list[str] | None = None,
    limit: int = 5,
) -> Dict[str, Any]:
    params: Dict[str, Any] = {"filter": filter_dict, "start": 0}
    if select:
        params["select"] = select
    params["limit"] = limit
    return bitrix_rest_call(account, "crm.company.list", params)


def crm_contact_list(
    account: Bitrix24Account,
    filter_dict: Dict[str, Any],
    *,
    select: list[str] | None = None,
    limit: int = 5,
) -> Dict[str, Any]:
    params: Dict[str, Any] = {"filter": filter_dict, "start": 0}
    if select:
        params["select"] = select
    params["limit"] = limit
    return bitrix_rest_call(account, "crm.contact.list", params)


def crm_requisite_list(
    account: Bitrix24Account,
    filter_dict: Dict[str, Any],
    *,
    select: list[str] | None = None,
    limit: int = 10,
) -> Dict[str, Any]:
    """Реквизиты (crm.requisite.*): ИНН и т.д. в RQ_INN, привязка к компании/контакту — ENTITY_TYPE_ID / ENTITY_ID."""
    params: Dict[str, Any] = {"filter": filter_dict, "start": 0}
    if select:
        params["select"] = select
    params["limit"] = limit
    return bitrix_rest_call(account, "crm.requisite.list", params)


def im_message_add(
    account: Bitrix24Account,
    dialog_id: str,
    message: str,
    *,
    url_preview: str = "Y",
    system: bool = False,
    attach: Any = None,
) -> Dict[str, Any]:
    """Чат: DIALOG_ID вида chat123; личка: числовой ID пользователя Bitrix24.

    ATTACH — карточка (BLOCKS), визуально как у бота/приложения; при наличии ATTACH в MESSAGE можно передать \\u200b.
    """
    params: Dict[str, Any] = {
        "DIALOG_ID": dialog_id,
        "MESSAGE": message,
        "SYSTEM": "Y" if system else "N",
        "URL_PREVIEW": url_preview,
    }
    if attach is not None:
        params["ATTACH"] = attach
    return bitrix_rest_call(account, "im.message.add", params)


def imbot_v2_chat_message_send(
    account: Bitrix24Account,
    *,
    bot_id: int,
    dialog_id: str,
    message: str | None = None,
    attach: Any = None,
    system: bool = False,
    url_preview: bool = True,
) -> Dict[str, Any]:
    """Сообщение от имени чат-бота (класс --bot в UI). OAuth: botId + auth токена приложения."""
    fields: Dict[str, Any] = {"urlPreview": url_preview}
    if message is not None:
        fields["message"] = message
    if attach is not None:
        fields["attach"] = attach
    if system:
        fields["system"] = True
    return bitrix_rest_call(
        account,
        "imbot.v2.Chat.Message.send",
        {
            "botId": int(bot_id),
            "dialogId": dialog_id,
            "fields": fields,
        },
    )


# Стабильный code внутри приложения: повторный вызов imbot.v2.Bot.register идемпотентен (тот же бот).
TOCHKA_INTEGRATION_BOT_CODE = "tochka_bank_integration"

_avatar_push_at: dict[str, float] = {}


def _tochka_bot_register_properties() -> Dict[str, Any]:
    props: Dict[str, Any] = {
        "name": "Точка Банк",
        "workPosition": "Уведомления об оплатах",
    }
    b64 = tochka_chat_bot_avatar_base64()
    if b64:
        props["avatar"] = b64
    return props


def register_tochka_integration_chat_bot(account: Bitrix24Account) -> Dict[str, Any]:
    """Регистрация чат-бота приложения (OAuth). Нужны scope с imbot / чат-ботами."""
    return bitrix_rest_call(
        account,
        "imbot.v2.Bot.register",
        {
            "fields": {
                "code": TOCHKA_INTEGRATION_BOT_CODE,
                "type": "bot",
                "eventMode": "fetch",
                "properties": _tochka_bot_register_properties(),
            }
        },
    )


def push_tochka_integration_chat_bot_avatar(
    account: Bitrix24Account,
    bot_id: int,
    member_id: str,
    *,
    force: bool = False,
) -> None:
    """Имя/должность/аватар через imbot.v2.Bot.update (повторный register профиль не обновляет)."""
    props: Dict[str, Any] = {
        "name": "Точка Банк",
        "workPosition": "Уведомления об оплатах",
    }
    b64 = tochka_chat_bot_avatar_base64()
    if b64:
        props["avatar"] = b64
    if not force:
        now = time.time()
        prev = _avatar_push_at.get(member_id, 0.0)
        if now - prev < 86_400:
            return
    r = bitrix_rest_call(
        account,
        "imbot.v2.Bot.update",
        {
            "botId": int(bot_id),
            "fields": {
                "properties": props,
            },
        },
    )
    if r.get("error"):
        logger.warning(
            "imbot.v2.Bot.update (bot profile): %s %s",
            r.get("error"),
            r.get("error_description"),
        )
    else:
        _avatar_push_at[member_id] = time.time()


def im_chat_user_add(
    account: Bitrix24Account,
    chat_id: int,
    user_ids: list[int],
    *,
    hide_history: str = "N",
) -> Dict[str, Any]:
    """Пригласить пользователей (в т.ч. бота) в групповой чат. Нужны права участника чата."""
    return bitrix_rest_call(
        account,
        "im.chat.user.add",
        {
            "CHAT_ID": int(chat_id),
            "USERS": [int(x) for x in user_ids],
            "HIDE_HISTORY": hide_history,
        },
    )


def ensure_tochka_integration_chat_bot_id(account: Bitrix24Account) -> Optional[int]:
    """Возвращает bot.id после register; при ошибке API — None. Подтягивает аватар через Bot.update."""
    r = register_tochka_integration_chat_bot(account)
    if r.get("error"):
        return None
    bot = (r.get("result") or {}).get("bot") or {}
    try:
        bid = int(bot["id"])
    except (KeyError, TypeError, ValueError):
        return None
    mid = getattr(account, "member_id", None) or ""
    if mid:
        push_tochka_integration_chat_bot_avatar(account, bid, mid, force=True)
    return bid


def im_notify_system_add(
    account: Bitrix24Account,
    user_id: int,
    message: str,
    *,
    tag: str | None = None,
    attach: Any = None,
) -> Dict[str, Any]:
    """Системное уведомление пользователю (колокольчик / лента «Системное уведомление»).

    ATTACH — структурированная карточка (BLOCKS / GRID), см. документацию Bitrix24 «Attachments in Messages».
    """
    params: Dict[str, Any] = {"USER_ID": int(user_id), "MESSAGE": message}
    if tag:
        params["TAG"] = tag[:255]
    if attach is not None:
        params["ATTACH"] = attach
    return bitrix_rest_call(account, "im.notify.system.add", params)


def crm_timeline_comment_add(
    account: Bitrix24Account,
    *,
    entity_type: str,
    entity_id: int,
    comment: str,
) -> Dict[str, Any]:
    """ENTITY_TYPE: deal, lead, contact, company, dynamic_…"""
    return bitrix_rest_call(
        account,
        "crm.timeline.comment.add",
        {
            "fields": {
                "ENTITY_ID": int(entity_id),
                "ENTITY_TYPE": entity_type,
                "COMMENT": comment,
            }
        },
    )
