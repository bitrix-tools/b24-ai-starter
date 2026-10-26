"""Уведомления об оплатах: групповой чат (notify_chat_id), таймлайн CRM."""

from __future__ import annotations

import logging
import re
from typing import Any
from datetime import datetime
from decimal import Decimal

from django.db import IntegrityError

from .bitrix_rest import (
    bitrix_rest_call,
    crm_deal_get,
    crm_item_get,
    crm_timeline_comment_add,
    im_message_add,
    imbot_v2_chat_message_send,
    im_notify_system_add,
    portal_base_url,
    crm_company_public_path,
    crm_contact_public_path,
    crm_deal_public_path,
    crm_requisite_list,
    crm_invoice_spa_public_path,
)
from .ledger import payment_mode_label_ru
from .models import (
    Bitrix24Account,
    PaymentNotifyLog,
    TochkaAcquiringOperation,
    TochkaPayment,
    TochkaPortalPreferences,
)

logger = logging.getLogger(__name__)

INVOICE_SPA_ENTITY_TYPE_ID = 31

# GRID ROW: WIDTH — ширина колонки подписи (NAME) в px (~30% карточки при типичной ширине ~360–400px).
_GRID_NAME_COL_WIDTH_PX = 110

EVENT_MATCHED = "matched"
EVENT_UNMATCHED = "unmatched_pending"
EVENT_ACQUIRING_PAID = "acquiring_link_paid"


def _fmt_money(amount: Decimal | float, currency: str = "RUB") -> str:
    try:
        a = float(amount)
    except (TypeError, ValueError):
        a = 0.0
    parts = f"{a:.2f}".split(".")
    intp = parts[0]
    try:
        intp_fmt = "{:,}".format(int(intp)).replace(",", " ")
    except ValueError:
        intp_fmt = intp
    frac = parts[1] if len(parts) > 1 else "00"
    sym = "₽" if (currency or "RUB") == "RUB" else (currency or "")
    return f"{intp_fmt},{frac} {sym}".strip()


def _fmt_money_group_chat(amount: Decimal | float, currency: str = "RUB") -> str:
    """Сумма в карточке группового чата: без группировки тысяч (как на референс-скрине)."""
    if (currency or "RUB") != "RUB":
        return _fmt_money(amount, currency)
    try:
        d = Decimal(str(amount))
    except (ArithmeticError, ValueError):
        d = Decimal("0")
    q = d.quantize(Decimal("0.01"))
    if q == q.to_integral_value():
        return f"{int(q)} ₽"
    frac = int((q * 100) % 100)
    return f"{int(q)},{frac:02d} ₽"


def _group_chat_card_attach_from_bb(message_bb: str) -> dict[str, Any]:
    """Карточка im.message.add: один блок MESSAGE, без GRID (не путать с уведомлением ответственному)."""
    return {
        "ID": 1,
        "COLOR_TOKEN": "base",
        "BLOCKS": [{"MESSAGE": message_bb}],
    }


def _group_chat_lines_to_bbcode(lines: list[str]) -> str:
    """Склейка строк карточки в BB-code для ATTACH (пустая строка → двойной перенос)."""
    return "[br]".join(lines)


def _group_chat_lines_to_plain(lines: list[str]) -> str:
    return "\u200b\n" + "\n".join(lines)


def _fmt_dt(dt: datetime | None) -> str:
    if not dt:
        return "—"
    if dt.tzinfo:
        dt = dt.astimezone()
    return dt.strftime("%d.%m.%Y %H:%M")


def _fmt_dt_date_only(dt: datetime | None) -> str:
    """Дата как в примерах уведомлений (без времени)."""
    if not dt:
        return "—"
    if dt.tzinfo:
        dt = dt.astimezone()
    return dt.strftime("%d.%m.%Y")


def _expected_match_amount(payment: TochkaPayment) -> Decimal | None:
    """Ожидаемая сумма по сделке/счёту для проверки переплаты."""
    if payment.matched_deal_id and payment.deal_amount is not None:
        try:
            return Decimal(str(payment.deal_amount))
        except (ArithmeticError, ValueError):
            return None
    if payment.matched_invoice_id and payment.invoice_amount is not None:
        try:
            return Decimal(str(payment.invoice_amount))
        except (ArithmeticError, ValueError):
            return None
    return None


def _is_overpayment(payment: TochkaPayment) -> bool:
    exp = _expected_match_amount(payment)
    if exp is None or exp <= 0:
        return False
    try:
        paid = Decimal(str(payment.amount))
    except (ArithmeticError, ValueError):
        return False
    return paid > exp + Decimal("0.005")


def _bb_link(base: str, path: str, label: str) -> str:
    url = f"{base}{path}"
    return f"[url={url}]{label}[/url]"


def _bb_sanitize_attach_grid_value(text: str | None) -> str:
    """Не ломать BB-коды в VALUE блоков ATTACH/GRID (квадратные скобки в тексте пользователя)."""
    t = (text or "").strip()
    if not t:
        return "—"
    return t.replace("[", "(").replace("]", ")")


def _attach_grid_row(name: str, value: str) -> dict[str, Any]:
    """Строка GRID DISPLAY=ROW: WIDTH задаёт ширину левой колонки (NAME), правая занимает остаток (~70%)."""
    return {
        "NAME": name,
        "VALUE": value,
        "DISPLAY": "ROW",
        "WIDTH": _GRID_NAME_COL_WIDTH_PX,
    }


def _system_notify_message_spaced_below_app_header(message: str) -> str:
    """Пустая строка под подписью приложения в колокольчике (ведущие \\n API обрезает — используем [br])."""
    msg = (message or "").strip()
    if not msg:
        return ""
    return f"[br][br]{msg}"


def _responsible_overpayment_attach_deal(
    base: str,
    *,
    paid_s: str,
    payer: str,
    purpose_plain: str,
    deal_id: int,
    deal_title: str,
    exp_s: str,
    over_s: str,
) -> dict[str, Any]:
    """Карточка ATTACH для im.notify.system.add: GRID + подвал (док. Bitrix24 «GRID Block», DISPLAY ROW)."""
    path = crm_deal_public_path(int(deal_id))
    url = f"{base}{path}"
    deal_val = f"[url={url}]{deal_title}[/url]"
    return {
        "ID": 1,
        "COLOR_TOKEN": "alert",
        "BLOCKS": [
            {"MESSAGE": "[B]Переплата по сделке[/B]"},
            {
                "GRID": [
                    _attach_grid_row("Сумма", paid_s),
                    _attach_grid_row("Плательщик", _bb_sanitize_attach_grid_value(payer)),
                    _attach_grid_row("Сделка", deal_val),
                    _attach_grid_row("Назначение", _bb_sanitize_attach_grid_value(purpose_plain)),
                ]
            },
            {"MESSAGE": f"Сумма сделки: {exp_s} — переплата: [B]{over_s}[/B]"},
        ],
    }


def _responsible_overpayment_attach_invoice(
    base: str,
    *,
    paid_s: str,
    payer: str,
    purpose_plain: str,
    invoice_item_id: int,
    invoice_title: str,
    exp_s: str,
    over_s: str,
) -> dict[str, Any]:
    path = crm_invoice_spa_public_path(int(invoice_item_id))
    url = f"{base}{path}"
    inv_val = f"[url={url}]{invoice_title}[/url]"
    return {
        "ID": 1,
        "COLOR_TOKEN": "alert",
        "BLOCKS": [
            {"MESSAGE": "[B]Переплата по счёту[/B]"},
            {
                "GRID": [
                    _attach_grid_row("Сумма", paid_s),
                    _attach_grid_row("Плательщик", _bb_sanitize_attach_grid_value(payer)),
                    _attach_grid_row("Счёт", inv_val),
                    _attach_grid_row("Назначение", _bb_sanitize_attach_grid_value(purpose_plain)),
                ]
            },
            {"MESSAGE": f"Сумма по счёту: {exp_s} — переплата: [B]{over_s}[/B]"},
        ],
    }


def clear_notify_logs_for_payment(payment_id) -> None:
    PaymentNotifyLog.objects.filter(payment_id=payment_id).delete()


def _try_acquire_notify_slot(member_id: str, payment_id, event_kind: str) -> bool:
    try:
        PaymentNotifyLog.objects.create(
            member_id=member_id,
            payment_id=payment_id,
            event_kind=event_kind,
        )
        return True
    except IntegrityError:
        return False


def resolve_deal_id_from_invoice_spa(account: Bitrix24Account, invoice_item_id: int) -> int | None:
    """Попытка получить ID сделки из элемента универсального счёта (crm.item, type 31)."""
    r = crm_item_get(account, INVOICE_SPA_ENTITY_TYPE_ID, int(invoice_item_id))
    item = (r.get("result") or {}).get("item") or r.get("result") or {}
    if not isinstance(item, dict):
        return None
    for key in ("parentId2", "parentId2Id", "PARENT_ID_2"):
        v = item.get(key)
        if v is None:
            continue
        try:
            return int(v)
        except (TypeError, ValueError):
            continue
    return None


def _portal(account: Bitrix24Account) -> str:
    return portal_base_url(account)


def _purpose_text(purpose: str | None) -> str:
    p = (purpose or "").strip() or "—"
    return f"Назначение платежа: {p}"


def _purpose_timeline(purpose: str | None) -> str:
    p = (purpose or "").strip() or "—"
    return f"Назначение: {p}"


def _fmt_money_timeline_rub(amount: Decimal | float) -> str:
    """Как в примере таймлайна: 30 000.00 руб."""
    try:
        a = float(amount)
    except (TypeError, ValueError):
        a = 0.0
    parts = f"{a:.2f}".split(".")
    intp = parts[0]
    try:
        intp_fmt = "{:,}".format(int(intp)).replace(",", " ")
    except ValueError:
        intp_fmt = intp
    frac = parts[1] if len(parts) > 1 else "00"
    return f"{intp_fmt}.{frac} руб."


def _purpose_looks_like_qr(purpose: str | None) -> bool:
    p = (purpose or "").lower()
    return "qr" in p or "кюар" in p or "по qr" in p


_PHONE_RE = re.compile(
    r"(?:\+7|8)\s*\(?(\d{3})\)?\s*(\d{3})[\s\-]?(\d{2})[\s\-]?(\d{2})|(?<!\d)(\d{10})(?!\d)"
)

def _format_payer_display_name(inn: str | None, name: str | None) -> str:
    """Убрать дублирующий ИНН из начала названия (остальное — как в выписке, полное «ИП …»)."""
    inn = (inn or "").strip()
    raw = (name or "").strip()
    if not raw and not inn:
        return "—"
    s = raw
    if inn and s.startswith(inn):
        s = s[len(inn) :].lstrip(" \t.,;-")
    s = re.sub(r"\s+", " ", s).strip()
    return s if s else "—"


def _extract_phones_from_text(text: str | None) -> list[str]:
    if not text:
        return []
    found: list[str] = []
    for m in _PHONE_RE.finditer(text):
        g = m.group(0)
        if g:
            found.append(g.strip())
    return found[:3]


# crm.requisite: ENTITY_TYPE_ID — контакт 3, компания 4 (CCrmOwnerEntity)
_REQUISITE_ENTITY_CONTACT = 3
_REQUISITE_ENTITY_COMPANY = 4


def find_crm_entity_by_inn(account: Bitrix24Account, inn: str) -> tuple[str, int] | None:
    """('company', id) или ('contact', id) по ИНН в реквизитах (crm.requisite), не по полям карточки."""
    inn = (inn or "").strip()
    if len(inn) < 8:
        return None
    companies: list[int] = []
    contacts: list[int] = []
    for fl in ({"=RQ_INN": inn}, {"RQ_INN": inn}):
        r = crm_requisite_list(
            account,
            fl,
            select=["ID", "ENTITY_ID", "ENTITY_TYPE_ID"],
            limit=20,
        )
        if r.get("error"):
            logger.warning(
                "crm.requisite.list (INN): %s %s",
                r.get("error"),
                r.get("error_description"),
            )
            continue
        rows = r.get("result") or []
        for row in rows:
            if not isinstance(row, dict):
                continue
            et = row.get("ENTITY_TYPE_ID")
            eid = row.get("ENTITY_ID")
            if eid is None or et is None:
                continue
            try:
                et_i = int(et)
                eid_i = int(eid)
            except (TypeError, ValueError):
                continue
            if et_i == _REQUISITE_ENTITY_COMPANY and eid_i not in companies:
                companies.append(eid_i)
            elif et_i == _REQUISITE_ENTITY_CONTACT and eid_i not in contacts:
                contacts.append(eid_i)
        if companies or contacts:
            break
    if companies:
        return ("company", companies[0])
    if contacts:
        return ("contact", contacts[0])
    return None


def enrich_bank_payment_rows_by_inn(account: Bitrix24Account | None, rows: list[dict]) -> None:
    """Для строк банковской выписки подставляет counterparty_company_id / counterparty_contact_id по ИНН (crm.requisite).

    Оплаты по ссылке (эквайринг, source/payment_source = acquiring) не изменяем — там контрагент из сделки/лида.
    """
    if not account or not rows:
        return
    bank_sources = frozenset({"bank", "bank_statement"})
    inns: set[str] = set()
    for row in rows:
        src = row.get("payment_source") or row.get("source") or ""
        if src not in bank_sources:
            continue
        inn = (row.get("counterparty_inn") or "").strip()
        if len(inn) >= 8:
            inns.add(inn)
    cache: dict[str, tuple[str, int] | None] = {}
    for inn in inns:
        cache[inn] = find_crm_entity_by_inn(account, inn)
    for row in rows:
        src = row.get("payment_source") or row.get("source") or ""
        if src not in bank_sources:
            continue
        inn = (row.get("counterparty_inn") or "").strip()
        hit = cache.get(inn)
        if not hit:
            continue
        kind, eid = hit
        if kind == "company":
            row["counterparty_company_id"] = eid
        else:
            row["counterparty_contact_id"] = eid


def _extract_item_dict(r: dict) -> dict:
    raw = r.get("result") or {}
    if isinstance(raw, dict) and "item" in raw:
        item = raw.get("item")
        return item if isinstance(item, dict) else {}
    return raw if isinstance(raw, dict) else {}


def resolve_company_contact_ids(
    account: Bitrix24Account, payment: TochkaPayment
) -> tuple[int | None, int | None]:
    """COMPANY_ID и CONTACT_ID из сделки, лида или смарт-счёта (если есть в ответе API)."""
    if payment.matched_deal_id:
        r = crm_deal_get(account, int(payment.matched_deal_id))
        d = r.get("result") or {}
        cid = d.get("COMPANY_ID") or d.get("companyId")
        ctid = d.get("CONTACT_ID") or d.get("contactId")
        out_c = out_ct = None
        if cid:
            try:
                out_c = int(cid)
            except (TypeError, ValueError):
                pass
        if ctid:
            try:
                out_ct = int(ctid)
            except (TypeError, ValueError):
                pass
        return out_c, out_ct
    if payment.matched_lead_id:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(payment.matched_lead_id)})
        d = r.get("result") or {}
        cid = d.get("COMPANY_ID") or d.get("companyId")
        ctid = d.get("CONTACT_ID") or d.get("contactId")
        out_c = out_ct = None
        if cid:
            try:
                out_c = int(cid)
            except (TypeError, ValueError):
                pass
        if ctid:
            try:
                out_ct = int(ctid)
            except (TypeError, ValueError):
                pass
        return out_c, out_ct
    if payment.matched_invoice_id:
        r = crm_item_get(account, INVOICE_SPA_ENTITY_TYPE_ID, int(payment.matched_invoice_id))
        item = _extract_item_dict(r)
        out_c = out_ct = None
        for kc in ("companyId", "COMPANY_ID", "parentId7"):
            v = item.get(kc)
            if v:
                try:
                    out_c = int(v)
                    break
                except (TypeError, ValueError):
                    continue
        for kt in ("contactId", "CONTACT_ID", "parentId6"):
            v = item.get(kt)
            if v:
                try:
                    out_ct = int(v)
                    break
                except (TypeError, ValueError):
                    continue
        return out_c, out_ct
    return None, None


def _effective_deal_id_for_links(account: Bitrix24Account, payment: TochkaPayment) -> int | None:
    if payment.matched_deal_id:
        return int(payment.matched_deal_id)
    if payment.matched_invoice_id:
        return resolve_deal_id_from_invoice_spa(account, int(payment.matched_invoice_id))
    return None


def _qr_extra_lines(account: Bitrix24Account, payment: TochkaPayment, base: str) -> list[str]:
    if not _purpose_looks_like_qr(payment.purpose):
        return []
    lines: list[str] = []
    phones = _extract_phones_from_text(payment.purpose or "")
    if phones:
        lines.append(f"Телефон: {phones[0]}")
    deal_id = _effective_deal_id_for_links(account, payment)
    if deal_id:
        dt = (payment.deal_title or "").strip() or f"Сделка #{deal_id}"
        lines.append(f"Сделка: {_bb_link(base, crm_deal_public_path(int(deal_id)), dt)}")
        r = crm_deal_get(account, int(deal_id))
        row = r.get("result") or {}
        cid = row.get("CONTACT_ID") or row.get("contactId")
        if cid:
            try:
                cid_int = int(cid)
            except (TypeError, ValueError):
                cid_int = None
            if cid_int:
                cr = bitrix_rest_call(account, "crm.contact.get", {"id": cid_int})
                c = cr.get("result") or {}
                cname = f"{c.get('NAME', '')} {c.get('LAST_NAME', '')}".strip() or f"Контакт #{cid_int}"
                lines.append(f"Контакт: {_bb_link(base, crm_contact_public_path(cid_int), cname)}")
    return lines


def _collect_group_chat_lines(
    account: Bitrix24Account,
    payment: TochkaPayment,
    *,
    matched: bool,
) -> list[str]:
    """Строки карточки группового чата (порядок как на референс-скрине: блок реквизитов, пустая строка, блок назначения)."""
    base = _portal(account)
    cur = payment.currency or "RUB"
    lines: list[str] = []
    if matched:
        lines.append("Платеж разнесен")
        lines.append("")
        exp = _expected_match_amount(payment)
        if _is_overpayment(payment) and exp is not None:
            lines.append(
                "Переплата: получено "
                f"{_fmt_money_group_chat(payment.amount, cur)}, ожидалось "
                f"{_fmt_money_group_chat(exp, cur)}"
            )
    else:
        lines.append("Не удалось разнести")
        lines.append("")

    slip = (payment.payment_slip_number or "").strip() or "—"
    lines.append(f"Банковский ордер № {slip}")
    lines.append(_fmt_dt(payment.transaction_date))
    lines.append(_fmt_money_group_chat(payment.amount, cur))

    inv_id = payment.matched_invoice_id
    deal_id = payment.matched_deal_id
    if inv_id and not deal_id:
        rd = resolve_deal_id_from_invoice_spa(account, int(inv_id))
        if rd:
            deal_id = rd

    inn = (payment.counterparty_inn or "").strip()
    name = (payment.counterparty_name or "").strip()
    payer_label = _format_payer_display_name(inn, name)

    if inv_id:
        inv_title = (payment.invoice_title or "").strip()
        num = (payment.invoice_number or "").strip() or str(inv_id)
        inv_label = inv_title if inv_title else f"Счет № {num}"
        lines.append(
            f"Счет № {num}: {_bb_link(base, crm_invoice_spa_public_path(int(inv_id)), inv_label)}"
        )

    if deal_id:
        dt = (payment.deal_title or "").strip() or f"Сделка #{deal_id}"
        lines.append(f"Сделка: {_bb_link(base, crm_deal_public_path(int(deal_id)), dt)}")

    lines.append("")

    hit = find_crm_entity_by_inn(account, inn) if inn else None
    if inn:
        lines.append(f"ИНН: {inn}")
    if hit:
        k, eid = hit
        path = crm_company_public_path(eid) if k == "company" else crm_contact_public_path(eid)
        link_text = payer_label if payer_label != "—" else ((name or "").strip() or "—")
        lines.append(f"Плательщик: {_bb_link(base, path, link_text)}")
    elif payer_label != "—" or name:
        lines.append(f"Плательщик: {payer_label}")

    acc = (payment.counterparty_account or payment.account_id or "").strip() or "—"
    lines.append(f"Поступил на счет {acc}")

    purpose_raw = (payment.purpose or "").strip()
    if purpose_raw:
        lines.append(purpose_raw)

    lines.extend(_qr_extra_lines(account, payment, base))
    return lines


def build_group_chat_message_body(
    account: Bitrix24Account,
    payment: TochkaPayment,
    *,
    matched: bool,
) -> str:
    """Плоский текст (совместимость); для отправки в чат используйте карточку ATTACH."""
    return _group_chat_lines_to_plain(_collect_group_chat_lines(account, payment, matched=matched))


def build_group_chat_card_attach(
    account: Bitrix24Account,
    payment: TochkaPayment,
    *,
    matched: bool,
) -> dict[str, Any]:
    """Вложение ATTACH для im.message.add, если чат-бот не задан (fallback)."""
    bb = _group_chat_lines_to_bbcode(_collect_group_chat_lines(account, payment, matched=matched))
    return _group_chat_card_attach_from_bb(bb)


def _fmt_amount_card(amount: Decimal | float, currency: str = "RUB") -> str:
    """Сумма для карточки: с группировкой тысяч, без ,00 у целых (16 200 ₽)."""
    s = _fmt_money(amount, currency)
    return s.replace(",00 ", " ", 1) if ",00 " in s else s


def _group_chat_main_plain_and_attach(
    account: Bitrix24Account,
    payment: TochkaPayment,
    *,
    matched: bool,
) -> tuple[str, dict[str, Any]]:
    """Структурная карточка платежа: цветная полоса (success/warning), заголовок с
    эмодзи, крупная сумма, реквизиты в GRID, назначение отдельным блоком.
    main_plain — текст для чат-бота; card — ATTACH для im.message.add (fallback без бота)."""
    base = _portal(account)
    cur = payment.currency or "RUB"
    amount_s = _fmt_amount_card(payment.amount, cur)
    date_s = _fmt_dt_date_only(payment.transaction_date)
    slip = (payment.payment_slip_number or "").strip() or "—"
    acc = (payment.counterparty_account or payment.account_id or "").strip() or "—"
    purpose_raw = (payment.purpose or "").strip()

    inv_id = payment.matched_invoice_id
    deal_id = payment.matched_deal_id
    if inv_id and not deal_id:
        rd = resolve_deal_id_from_invoice_spa(account, int(inv_id))
        if rd:
            deal_id = rd

    inn = (payment.counterparty_inn or "").strip()
    name = (payment.counterparty_name or "").strip()
    payer_label = _format_payer_display_name(inn, name)
    hit = find_crm_entity_by_inn(account, inn) if inn else None
    if hit:
        kind, eid = hit
        path = crm_company_public_path(eid) if kind == "company" else crm_contact_public_path(eid)
        payer_plain = payer_label if payer_label != "—" else (name or "—")
        payer_bb = _bb_link(base, path, payer_plain)
    else:
        payer_plain = payer_label if (payer_label != "—" or name) else ""
        payer_bb = _bb_sanitize_attach_grid_value(payer_plain) if payer_plain else ""

    doc_bb = None
    if inv_id:
        num = (payment.invoice_number or "").strip() or str(inv_id)
        inv_title = (payment.invoice_title or "").strip() or f"Счет № {num}"
        doc_bb = _bb_link(base, crm_invoice_spa_public_path(int(inv_id)), inv_title)
    deal_bb = None
    if deal_id:
        deal_title = (payment.deal_title or "").strip() or f"Сделка #{deal_id}"
        deal_bb = _bb_link(base, crm_deal_public_path(int(deal_id)), deal_title)

    exp = _expected_match_amount(payment)
    over = matched and _is_overpayment(payment) and exp is not None
    exp_s = _fmt_amount_card(exp, cur) if exp is not None else ""

    if matched:
        emoji, title, color = "✅", "Платёж разнесён", "success"
    else:
        emoji, title, color = "⚠️", "Не удалось разнести", "warning"

    purpose_safe = _bb_sanitize_attach_grid_value(purpose_raw) if purpose_raw else ""
    qr_lines = _qr_extra_lines(account, payment, base)

    # ---- ATTACH-карточка (fallback без чат-бота) ----
    grid: list[dict[str, Any]] = [
        _attach_grid_row("Дата", date_s),
        _attach_grid_row("Ордер", f"№ {slip}"),
    ]
    if payer_bb:
        grid.append(_attach_grid_row("Плательщик", payer_bb))
    if inn:
        grid.append(_attach_grid_row("ИНН", inn))
    if matched:
        if doc_bb:
            grid.append(_attach_grid_row("Счёт", doc_bb))
        if deal_bb:
            grid.append(_attach_grid_row("Сделка", deal_bb))
    else:
        grid.append(_attach_grid_row("На счёт", acc))

    blocks: list[dict[str, Any]] = [
        {"MESSAGE": f"[B]{emoji} {title}[/B]"},
        {"MESSAGE": f"[B]{amount_s}[/B]"},
    ]
    if over:
        blocks.append({"MESSAGE": f"⚠️ Переплата: получено [B]{amount_s}[/B], ожидалось {exp_s}"})
    blocks.append({"GRID": grid})
    if purpose_safe:
        blocks.append({"MESSAGE": f"📝 [B]Назначение[/B][br]{purpose_safe}"})
    if qr_lines:
        blocks.append({"MESSAGE": "[br]".join(qr_lines)})

    card = {"ID": 1, "COLOR_TOKEN": color, "BLOCKS": blocks}

    # ---- plain-текст (путь чат-бота) ----
    pl: list[str] = [f"{emoji} [B]{title}[/B]", "", f"[B]{amount_s}[/B]"]
    if over:
        pl.append(f"⚠️ Переплата: получено {amount_s}, ожидалось {exp_s}")
    pl.append("")
    pl.append(f"📅 {date_s}")
    pl.append(f"🏦 Ордер № {slip}")
    if payer_plain:
        pl.append(f"👤 {payer_plain}")
    if inn:
        pl.append(f"🧾 ИНН {inn}")
    if matched:
        if doc_bb:
            pl.append(f"📄 {doc_bb}")
        if deal_bb:
            pl.append(f"📁 {deal_bb}")
    else:
        pl.append(f"💳 {acc}")
    if purpose_raw:
        pl.append("")
        pl.append(f"📝 {purpose_raw}")
    pl.extend(qr_lines)
    main_plain = "\n".join(pl)

    return main_plain, card


def build_timeline_matched_body(account: Bitrix24Account, payment: TochkaPayment) -> str:
    """Таймлайн сделки/лида: эмодзи и сводка, без реквизитов ордера."""
    lines: list[str] = []
    exp = _expected_match_amount(payment)
    if _is_overpayment(payment) and exp is not None:
        paid_s = _fmt_money_timeline_rub(payment.amount)
        exp_s = _fmt_money_timeline_rub(exp)
        lines.extend(
            [
                "💰 Получен платёж",
                "⚠️ ПЕРЕПЛАТА",
                f"💵 {paid_s} из {exp_s}",
                "",
            ]
        )
    cur = payment.currency or "RUB"
    amt_disp = _fmt_money_timeline_rub(payment.amount) if cur == "RUB" else _fmt_money(payment.amount, cur)
    inn_v = (payment.counterparty_inn or "").strip()
    lines.extend(
        [
            f"Сумма: {amt_disp}",
            f"Дата: {_fmt_dt_date_only(payment.transaction_date)}",
            f"ИНН: {inn_v or '—'}",
            f"Плательщик: {_format_payer_display_name(inn_v, payment.counterparty_name)}",
            _purpose_timeline(payment.purpose),
        ]
    )
    return "\n".join(lines)


def build_timeline_unbound_body(payment: TochkaPayment, unbound_from: str | None) -> str:
    title = _unbound_title(unbound_from)
    inn_u = (payment.counterparty_inn or "").strip()
    lines = [
        f"❌ {title}",
        "",
        f"Сумма: {_fmt_money_timeline_rub(payment.amount)}",
        f"Дата: {_fmt_dt_date_only(payment.transaction_date)}",
        f"ИНН: {inn_u or '—'}",
        f"Плательщик: {_format_payer_display_name(inn_u, payment.counterparty_name)}",
        _purpose_timeline(payment.purpose),
    ]
    return "\n".join(lines)


def _collect_unbound_chat_lines(payment: TochkaPayment, unbound_from: str | None) -> list[str]:
    title = _unbound_title(unbound_from)
    inn_u = (payment.counterparty_inn or "").strip()
    return [
        title,
        "",
        f"Сумма: {_fmt_money_timeline_rub(payment.amount)}",
        f"Дата: {_fmt_dt_date_only(payment.transaction_date)}",
        f"ИНН: {inn_u or '—'}",
        f"Плательщик: {_format_payer_display_name(inn_u, payment.counterparty_name)}",
        _purpose_text(payment.purpose),
    ]


def build_unbound_chat_body(payment: TochkaPayment, unbound_from: str | None) -> str:
    """Краткий текст отвязки для группового чата (плоский; отправка — через карточку ATTACH)."""
    return _group_chat_lines_to_plain(_collect_unbound_chat_lines(payment, unbound_from))


def build_message_body(
    account: Bitrix24Account,
    payment: TochkaPayment,
    *,
    matched: bool,
) -> str:
    """Совместимость: текст для группового чата."""
    return build_group_chat_message_body(account, payment, matched=matched)


def _responsible_user_id(
    account: Bitrix24Account | None,
    payment: TochkaPayment,
) -> int | None:
    if payment.matched_deal_id and payment.deal_responsible_id:
        return int(payment.deal_responsible_id)
    if payment.matched_invoice_id and payment.invoice_responsible_id:
        return int(payment.invoice_responsible_id)
    if payment.matched_lead_id and account:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(payment.matched_lead_id)})
        row = r.get("result") or {}
        aid = row.get("ASSIGNED_BY_ID")
        if aid:
            try:
                return int(aid)
            except (TypeError, ValueError):
                pass
    return None


def build_responsible_matched_system_notify(
    account: Bitrix24Account,
    payment: TochkaPayment,
) -> tuple[str, dict[str, Any] | None]:
    """Текст MESSAGE и опционально ATTACH для im.notify.system.add.

    При переплате по сделке/счёту детали уходят во вложение (GRID), первая строка — краткий заголовок со ссылкой.
    Структура ATTACH — REST «Attachments in Messages»; перед текстом — [br][br], т.к. ведущие переводы строк API обрезает.
    В GRID для ROW задан WIDTH колонки NAME (~30% карточки в px по документации).

    Подпись «от какого приложения» в колокольчике задаётся порталом для OAuth-приложения, не этим текстом.
    """
    base = _portal(account)
    cur = payment.currency or "RUB"
    paid_s = _fmt_money(payment.amount, cur)
    payer = _format_payer_display_name(payment.counterparty_inn, payment.counterparty_name)
    purpose_plain = (payment.purpose or "").strip() or "—"

    deal_id = _effective_deal_id_for_links(account, payment)
    lead_id = payment.matched_lead_id

    exp_amt = _expected_match_amount(payment)
    if _is_overpayment(payment) and exp_amt is not None:
        exp = exp_amt
        try:
            paid_dec = Decimal(str(payment.amount))
            over_dec = paid_dec - exp
            if over_dec < 0:
                over_dec = Decimal("0")
        except (ArithmeticError, ValueError):
            over_dec = Decimal("0")
        exp_s = _fmt_money(exp, cur)
        over_s = _fmt_money(over_dec, cur)

        if deal_id:
            deal_title = (payment.deal_title or "").strip() or f"Сделка #{deal_id}"
            deal_link = _bb_link(base, crm_deal_public_path(int(deal_id)), deal_title)
            head = f"Платёж [B]{paid_s}[/B] — переплата по сделке {deal_link}"
            attach = _responsible_overpayment_attach_deal(
                base,
                paid_s=paid_s,
                payer=payer,
                purpose_plain=purpose_plain,
                deal_id=int(deal_id),
                deal_title=deal_title,
                exp_s=exp_s,
                over_s=over_s,
            )
            return _system_notify_message_spaced_below_app_header(head), attach

        if payment.matched_invoice_id:
            inv_id = int(payment.matched_invoice_id)
            inv_title = (payment.invoice_title or "").strip() or f"Счёт № {inv_id}"
            inv_link = _bb_link(base, crm_invoice_spa_public_path(inv_id), inv_title)
            head = f"Платёж [B]{paid_s}[/B] — переплата по счёту {inv_link}"
            attach = _responsible_overpayment_attach_invoice(
                base,
                paid_s=paid_s,
                payer=payer,
                purpose_plain=purpose_plain,
                invoice_item_id=inv_id,
                invoice_title=inv_title,
                exp_s=exp_s,
                over_s=over_s,
            )
            return _system_notify_message_spaced_below_app_header(head), attach

    if deal_id:
        deal_title = (payment.deal_title or "").strip() or f"Сделка #{deal_id}"
        deal_link = _bb_link(base, crm_deal_public_path(int(deal_id)), deal_title)
        lines = [
            f"Платёж [B]{paid_s}[/B] — по сделке {deal_link}",
            "",
            f"Сумма: {paid_s}",
            f"Плательщик: {payer}",
            f"Сделка: {deal_link}",
        ]
        return _system_notify_message_spaced_below_app_header("\n".join(lines)), None

    if payment.matched_invoice_id:
        inv_id = int(payment.matched_invoice_id)
        inv_title = (payment.invoice_title or "").strip() or f"Счёт № {inv_id}"
        inv_link = _bb_link(base, crm_invoice_spa_public_path(inv_id), inv_title)
        lines = [
            f"Платёж [B]{paid_s}[/B] — по счёту {inv_link}",
            "",
            f"Сумма: {paid_s}",
            f"Плательщик: {payer}",
            f"Счёт: {inv_link}",
        ]
        return _system_notify_message_spaced_below_app_header("\n".join(lines)), None

    if lead_id:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(lead_id)})
        lead_title = (r.get("result") or {}).get("TITLE") or f"Лид #{lead_id}"
        lead_link = _bb_link(base, f"/crm/lead/details/{int(lead_id)}/", str(lead_title).strip() or f"Лид #{lead_id}")
        lines = [
            f"Платёж [B]{paid_s}[/B] — по лиду {lead_link}",
            "",
            f"Сумма: {paid_s}",
            f"Плательщик: {payer}",
            f"Лид: {lead_link}",
        ]
        return _system_notify_message_spaced_below_app_header("\n".join(lines)), None

    return _system_notify_message_spaced_below_app_header(
        f"Платёж [B]{paid_s}[/B]\n\n"
        f"Сумма: {paid_s}\n"
        f"Плательщик: {payer}"
    ), None


def _send_responsible_system_notify_safe(
    account: Bitrix24Account,
    user_id: int,
    message: str,
    *,
    tag: str,
    attach: dict[str, Any] | None = None,
) -> None:
    r = im_notify_system_add(account, user_id, message, tag=tag, attach=attach)
    if r.get("error"):
        logger.warning(
            "[NOTIFY] im.notify.system.add error: %s %s",
            r.get("error"),
            r.get("error_description"),
        )


def _send_im_safe(
    account: Bitrix24Account,
    dialog_id: str,
    text: str,
    *,
    system: bool = False,
    attach: dict[str, Any] | None = None,
) -> None:
    msg = text
    if attach is not None and not system:
        msg = text if (text and text.strip() and text.strip() != "\u200b") else "\u200b"
    r = im_message_add(account, dialog_id, msg, system=system, attach=attach)
    if r.get("error"):
        logger.warning("[NOTIFY] im.message.add error: %s %s", r.get("error"), r.get("error_description"))


def _chat_dialog_id(chat_id) -> str:
    return f"chat{int(chat_id)}" if not str(chat_id).startswith("chat") else str(chat_id)


def _send_group_chat_payment_messages(
    account: Bitrix24Account,
    chat_id,
    *,
    main_plain: str,
    card_attach: dict[str, Any],
    chat_bot_id: int | None,
) -> None:
    """Чат-бот: imbot.v2.Chat.Message.send. Иначе im.message.add + ATTACH (одно сообщение — без дубля «Платеж обработан»)."""
    dlg = _chat_dialog_id(chat_id)
    if chat_bot_id:
        # От имени бота: белый фон + статус «Платеж обработан». Та же ATTACH-карточка
        # (message — zero-width, иначе продублируется текстом над карточкой).
        r = imbot_v2_chat_message_send(
            account,
            bot_id=int(chat_bot_id),
            dialog_id=dlg,
            message="​",
            attach=card_attach,
        )
        if r.get("error"):
            logger.warning(
                "[NOTIFY] imbot.v2.Chat.Message.send error: %s %s",
                r.get("error"),
                r.get("error_description"),
            )
        return

    _send_im_safe(account, dlg, "", attach=card_attach)


def _send_timeline_safe(
    account: Bitrix24Account,
    entity_type: str,
    entity_id: int,
    text: str,
) -> None:
    r = crm_timeline_comment_add(account, entity_type=entity_type, entity_id=entity_id, comment=text)
    if r.get("error"):
        logger.warning(
            "[NOTIFY] crm.timeline.comment.add error: %s %s",
            r.get("error"),
            r.get("error_description"),
        )


def _has_timeline_target(account: Bitrix24Account, payment: TochkaPayment) -> bool:
    if payment.matched_deal_id or payment.matched_lead_id:
        return True
    if payment.matched_invoice_id:
        return resolve_deal_id_from_invoice_spa(account, int(payment.matched_invoice_id)) is not None
    return False


def notify_payment_matched(member_id: str, payment: TochkaPayment) -> None:
    if payment.status not in ("matched", "applied"):
        return
    if payment.credit_debit != "credit":
        return

    prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account or not prefs:
        return

    uid = _responsible_user_id(account, payment)
    will_timeline = prefs.notify_deal_timeline and _has_timeline_target(account, payment)
    responsible_ok = bool(prefs.notify_deal_responsible and uid)
    if not prefs.notify_chat_id and not will_timeline and not responsible_ok:
        return

    if not _try_acquire_notify_slot(member_id, payment.id, EVENT_MATCHED):
        return

    timeline_text = build_timeline_matched_body(account, payment)
    main_plain, card = _group_chat_main_plain_and_attach(account, payment, matched=True)

    chat_id = prefs.notify_chat_id
    if chat_id:
        _send_group_chat_payment_messages(
            account,
            chat_id,
            main_plain=main_plain,
            card_attach=card,
            chat_bot_id=prefs.notify_chat_bot_id,
        )

    if responsible_ok and uid:
        rmsg, rattach = build_responsible_matched_system_notify(account, payment)
        _send_responsible_system_notify_safe(
            account,
            int(uid),
            rmsg,
            tag=f"tochka_payment_{payment.id}_matched",
            attach=rattach,
        )

    if prefs.notify_deal_timeline:
        if payment.matched_deal_id:
            _send_timeline_safe(account, "deal", int(payment.matched_deal_id), timeline_text)
        elif payment.matched_lead_id:
            _send_timeline_safe(account, "lead", int(payment.matched_lead_id), timeline_text)
        elif payment.matched_invoice_id:
            did = resolve_deal_id_from_invoice_spa(account, int(payment.matched_invoice_id))
            if did:
                _send_timeline_safe(account, "deal", int(did), timeline_text)


def notify_payment_unmatched_new(member_id: str, payment: TochkaPayment) -> None:
    """Один раз на платёж: не разнесён (после появления новой строки выписки)."""
    if payment.status != "pending":
        return
    if payment.credit_debit != "credit":
        return
    if payment.matched_deal_id or payment.matched_lead_id or payment.matched_invoice_id:
        return

    prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account or not prefs:
        return
    if not prefs.notify_chat_id:
        return

    if not _try_acquire_notify_slot(member_id, payment.id, EVENT_UNMATCHED):
        return

    main_plain, card = _group_chat_main_plain_and_attach(account, payment, matched=False)

    chat_id = prefs.notify_chat_id
    if chat_id:
        _send_group_chat_payment_messages(
            account,
            chat_id,
            main_plain=main_plain,
            card_attach=card,
            chat_bot_id=prefs.notify_chat_bot_id,
        )


def _unbound_title(unbound_from: str | None) -> str:
    if unbound_from == "invoice":
        return "Платёж отвязан от счёта"
    if unbound_from == "lead":
        return "Платёж отвязан от лида"
    return "Платёж отвязан от сделки"


def notify_payment_unbound(
    member_id: str,
    payment: TochkaPayment,
    *,
    previous_responsible_user_id: int | None = None,
    unbound_from: str | None = None,
    previous_deal_id: int | None = None,
    previous_lead_id: int | None = None,
    previous_invoice_id: int | None = None,
) -> None:
    """Отвязка: групповой чат и/или таймлайн; unbound_from: deal | invoice | lead."""
    _ = previous_responsible_user_id
    prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account or not prefs:
        return

    timeline_ok = bool(
        prefs.notify_deal_timeline
        and (previous_deal_id or previous_lead_id)
    )
    if not prefs.notify_chat_id and not timeline_ok:
        return

    timeline_text = build_timeline_unbound_body(payment, unbound_from)
    u_lines = _collect_unbound_chat_lines(payment, unbound_from)
    u_plain = "\n".join(u_lines)
    u_attach = _group_chat_card_attach_from_bb(_group_chat_lines_to_bbcode(u_lines))

    chat_id = prefs.notify_chat_id
    if chat_id:
        dlg = _chat_dialog_id(chat_id)
        bid = prefs.notify_chat_bot_id
        if bid:
            r = imbot_v2_chat_message_send(
                account, bot_id=int(bid), dialog_id=dlg, message=u_plain
            )
            if r.get("error"):
                logger.warning(
                    "[NOTIFY] imbot unbound chat error: %s %s",
                    r.get("error"),
                    r.get("error_description"),
                )
        else:
            _send_im_safe(account, dlg, "", attach=u_attach)

    if timeline_ok:
        if previous_deal_id:
            _send_timeline_safe(account, "deal", int(previous_deal_id), timeline_text)
        elif previous_lead_id:
            _send_timeline_safe(account, "lead", int(previous_lead_id), timeline_text)


def _responsible_uid_acquiring(
    account: Bitrix24Account, op: TochkaAcquiringOperation
) -> int | None:
    if op.deal_id:
        r = bitrix_rest_call(account, "crm.deal.get", {"id": int(op.deal_id)})
        aid = (r.get("result") or {}).get("ASSIGNED_BY_ID")
        if aid:
            try:
                return int(aid)
            except (TypeError, ValueError):
                pass
    if op.lead_id:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(op.lead_id)})
        aid = (r.get("result") or {}).get("ASSIGNED_BY_ID")
        if aid:
            try:
                return int(aid)
            except (TypeError, ValueError):
                pass
    return None


def _collect_acquiring_group_chat_lines(
    account: Bitrix24Account, op: TochkaAcquiringOperation
) -> list[str]:
    base = _portal(account)
    cur = op.currency or "RUB"
    lines: list[str] = [
        "Оплата по платёжной ссылке",
        "",
        _fmt_money_group_chat(op.amount, cur),
    ]
    if op.deal_id:
        r = bitrix_rest_call(account, "crm.deal.get", {"id": int(op.deal_id)})
        title = ((r.get("result") or {}).get("TITLE") or "").strip() or f"Сделка #{op.deal_id}"
        lines.append(f"Сделка: {_bb_link(base, crm_deal_public_path(int(op.deal_id)), title)}")
    elif op.lead_id:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(op.lead_id)})
        title = ((r.get("result") or {}).get("TITLE") or "").strip() or f"Лид #{op.lead_id}"
        lines.append(
            f"Лид: {_bb_link(base, f'/crm/lead/details/{int(op.lead_id)}/', title)}"
        )
    mode = (op.payment_mode_effective or "").strip()
    if mode:
        lines.append(f"Способ: {payment_mode_label_ru(mode)}")
    pur = (op.purpose or "").strip()
    if pur:
        lines.append(_purpose_text(pur))
    return lines


def build_timeline_acquiring_body(
    account: Bitrix24Account, op: TochkaAcquiringOperation
) -> str:
    _ = account
    amt = _fmt_money_timeline_rub(op.amount)
    lines = [
        "💰 Оплата по платёжной ссылке",
        "",
        f"Сумма: {amt}",
    ]
    if op.payment_mode_effective:
        lines.append(f"Способ: {payment_mode_label_ru(op.payment_mode_effective)}")
    lines.append(_purpose_timeline(op.purpose))
    return "\n".join(lines)


def build_responsible_acquiring_system_notify(
    account: Bitrix24Account, op: TochkaAcquiringOperation
) -> tuple[str, dict[str, Any] | None]:
    base = _portal(account)
    cur = op.currency or "RUB"
    paid_s = _fmt_money(op.amount, cur)
    purpose_plain = (op.purpose or "").strip() or "—"
    if op.deal_id:
        r = bitrix_rest_call(account, "crm.deal.get", {"id": int(op.deal_id)})
        title = ((r.get("result") or {}).get("TITLE") or "").strip() or f"Сделка #{op.deal_id}"
        deal_link = _bb_link(base, crm_deal_public_path(int(op.deal_id)), title)
        body = (
            f"Оплата по ссылке [B]{paid_s}[/B] — по сделке {deal_link}\n\n"
            f"Сумма: {paid_s}\n"
            f"Назначение: {purpose_plain}\n"
            f"Сделка: {deal_link}"
        )
        return _system_notify_message_spaced_below_app_header(body), None
    if op.lead_id:
        r = bitrix_rest_call(account, "crm.lead.get", {"id": int(op.lead_id)})
        title = ((r.get("result") or {}).get("TITLE") or "").strip() or f"Лид #{op.lead_id}"
        lead_link = _bb_link(base, f"/crm/lead/details/{int(op.lead_id)}/", title)
        body = (
            f"Оплата по ссылке [B]{paid_s}[/B] — по лиду {lead_link}\n\n"
            f"Сумма: {paid_s}\n"
            f"Назначение: {purpose_plain}\n"
            f"Лид: {lead_link}"
        )
        return _system_notify_message_spaced_below_app_header(body), None
    return (
        _system_notify_message_spaced_below_app_header(
            f"Оплата по ссылке [B]{paid_s}[/B]\n\nСумма: {paid_s}\nНазначение: {purpose_plain}"
        ),
        None,
    )


def notify_acquiring_link_paid(op: TochkaAcquiringOperation) -> None:
    """Оплата по ссылке прошла, а строка выписки ещё не сопоставлена — те же каналы, что и для выписки."""
    if op.status != "paid":
        return
    member_id = op.member_id
    prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account or not prefs:
        return

    uid = _responsible_uid_acquiring(account, op)
    will_timeline = prefs.notify_deal_timeline and bool(op.deal_id or op.lead_id)
    responsible_ok = bool(prefs.notify_deal_responsible and uid)
    if not prefs.notify_chat_id and not will_timeline and not responsible_ok:
        return

    if not _try_acquire_notify_slot(member_id, op.id, EVENT_ACQUIRING_PAID):
        return

    if prefs.notify_chat_id:
        lines = _collect_acquiring_group_chat_lines(account, op)
        main_plain = "\n".join(lines)
        card = _group_chat_card_attach_from_bb(_group_chat_lines_to_bbcode(lines))
        _send_group_chat_payment_messages(
            account,
            prefs.notify_chat_id,
            main_plain=main_plain,
            card_attach=card,
            chat_bot_id=prefs.notify_chat_bot_id,
        )

    if responsible_ok and uid:
        rmsg, rattach = build_responsible_acquiring_system_notify(account, op)
        _send_responsible_system_notify_safe(
            account,
            int(uid),
            rmsg,
            tag=f"tochka_acquiring_{op.id}_paid",
            attach=rattach,
        )

    if will_timeline:
        txt = build_timeline_acquiring_body(account, op)
        if op.deal_id:
            _send_timeline_safe(account, "deal", int(op.deal_id), txt)
        elif op.lead_id:
            _send_timeline_safe(account, "lead", int(op.lead_id), txt)
