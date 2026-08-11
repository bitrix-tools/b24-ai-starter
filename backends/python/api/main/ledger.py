"""Единый ledger: выписка (TochkaPayment) + эквайринг (TochkaAcquiringOperation)."""

from __future__ import annotations

from datetime import datetime
from decimal import Decimal
from typing import Any

from django.db.models import Sum

from .models import TochkaAcquiringOperation, TochkaPayment


def resolve_acquiring_account_id(op: TochkaAcquiringOperation) -> str:
    """Номер счёта зачисления: при дедупе с выпиской — с банковской строки, иначе последний счёт по порталу."""
    dup_id = getattr(op, "duplicate_bank_payment_id", None)
    if dup_id:
        p = TochkaPayment.objects.filter(pk=dup_id).only("account_id").first()
        if p and (p.account_id or "").strip():
            return p.account_id.strip()
    last = (
        TochkaPayment.objects.filter(member_id=op.member_id, credit_debit="credit")
        .order_by("-transaction_date")
        .values_list("account_id", flat=True)
        .first()
    )
    return (last or "").strip()

ACQUIRING_PAID_STATUS = "paid"
ACQUIRING_CANCELLED = "cancelled"
ACQUIRING_REFUNDED = "refunded"


def acquiring_action_flags(op: TochkaAcquiringOperation) -> dict[str, bool]:
    """Какие действия доступны для операции эквайринга (дашборд / CRM)."""
    st = op.status
    paid = st == ACQUIRING_PAID_STATUS
    refunded = st == ACQUIRING_REFUNDED
    cancelled = st == ACQUIRING_CANCELLED
    has_ext = bool((op.external_operation_id or "").strip())
    has_entity = op.deal_id is not None or op.lead_id is not None
    can_cancel_link = not cancelled and not refunded and not paid
    can_unlink_deal = paid and has_entity and not refunded
    can_refund = paid and has_ext and not refunded
    return {
        "can_cancel_link": can_cancel_link,
        "can_unlink_deal": can_unlink_deal,
        "can_refund": can_refund,
    }


def normalize_payment_type_to_channel(payment_type: str | None) -> str:
    """Нормализация paymentType / paymentMode из Точки в код channel для UI и UF."""
    if not payment_type:
        return "payment_link"
    pt = str(payment_type).lower().strip()
    mapping = {
        "card": "card",
        "sbp": "sbp",
        "tinkoff": "mir_pay",
        "dolyame": "dolyame",
    }
    return mapping.get(pt, pt)


def payment_mode_label_ru(payment_type: str | None) -> str:
    """Русские подписи способа оплаты для уведомлений и таймлайна (коды API — латиница)."""
    if not payment_type:
        return ""
    pt = str(payment_type).lower().strip()
    mapping = {
        "card": "Карта",
        "sbp": "СБП",
        "tinkoff": "T-Pay / Мир",
        "mir_pay": "T-Pay / Мир",
        "dolyame": "Долями",
        "payment_link": "Платёжная ссылка",
    }
    return mapping.get(pt, payment_type)


def acquiring_row_channel(op: TochkaAcquiringOperation) -> str:
    if op.payment_mode_effective:
        return normalize_payment_type_to_channel(op.payment_mode_effective)
    modes = op.payment_modes_requested or []
    if isinstance(modes, list) and len(modes) == 1:
        return normalize_payment_type_to_channel(str(modes[0]))
    return "payment_link"


def acquiring_row_status_display(op: TochkaAcquiringOperation) -> str:
    if op.status == ACQUIRING_PAID_STATUS:
        return "applied"
    if op.status == ACQUIRING_REFUNDED:
        return "refunded"
    if op.status == "authorized":
        # До списания: привязка к сделке — «применён» в смысле учёта в CRM; к лиду — «сопоставлен».
        return "applied" if op.deal_id else "matched"
    if op.status == "pending":
        return "pending"
    if op.status == ACQUIRING_CANCELLED:
        return "ignored"
    return op.status or "pending"


def normalize_bank_payment_status(row: dict[str, Any]) -> None:
    """В API/UI: привязка банковской строки к сделке — «applied», не «matched» (лид/счёт остаются matched)."""
    if row.get("status") == "matched" and row.get("matched_deal_id"):
        row["status"] = "applied"


def serialize_bank_row(p: dict[str, Any]) -> dict[str, Any]:
    row = dict(p)
    row["id"] = str(row["id"])
    row["amount"] = float(row["amount"]) if row["amount"] else 0
    row["amount_nat"] = float(row["amount_nat"]) if row["amount_nat"] else None
    row["deal_amount"] = float(row["deal_amount"]) if row["deal_amount"] else None
    if row.get("transaction_date"):
        row["transaction_date"] = row["transaction_date"].isoformat()
    normalize_bank_payment_status(row)
    row["source"] = "bank_statement"
    row["channel"] = "bank_account"
    row["payment_kind"] = None
    row["payment_method"] = "bank_transfer"
    row["can_unlink"] = True
    return row


def serialize_acquiring_payment_row(
    op: TochkaAcquiringOperation,
    entity_info: dict[str, Any] | None,
) -> dict[str, Any]:
    """Строка для `/api/tochka/payments` (как выписка). Банковский дубль скрывается через ledger_exclude."""
    ts: datetime = op.updated_at if op.status == ACQUIRING_PAID_STATUS else op.created_at
    info = entity_info or {}
    acc_id = resolve_acquiring_account_id(op)
    cname = (info.get("contactFullName") or "").strip()
    cid = info.get("contactId")
    deal_amt = info.get("amount")
    try:
        da = float(deal_amt) if deal_amt is not None else 0.0
    except (TypeError, ValueError):
        da = 0.0
    paid = float(op.amount)
    pct = min(100, int(round(paid / da * 100))) if da > 0 else (100 if paid > 0 else 0)
    flags = acquiring_action_flags(op)
    return {
        "id": str(op.id),
        "account_id": acc_id or "—",
        "transaction_id": op.external_operation_id or "",
        "payment_slip_number": (op.payment_link_id or op.external_operation_id or "")[:255],
        "amount": paid,
        "amount_nat": None,
        "currency": op.currency or "RUB",
        "credit_debit": "credit",
        "counterparty_name": cname or None,
        "counterparty_contact_id": int(cid) if cid is not None else None,
        "counterparty_inn": None,
        "counterparty_account": None,
        "counterparty_bik": None,
        "purpose": op.purpose or "",
        "invoice_number": None,
        "vat_type": "Без НДС",
        "transaction_date": ts.isoformat() if ts else None,
        "status": acquiring_row_status_display(op),
        "matched_deal_id": op.deal_id,
        "matched_lead_id": op.lead_id,
        "matched_invoice_id": None,
        "deal_title": (info.get("title") or "").strip() or None,
        "deal_amount": float(da) if da else None,
        "deal_currency": info.get("currency") or op.currency or "RUB",
        "deal_responsible_id": int(info["responsibleId"]) if info.get("responsibleId") else None,
        "deal_responsible_name": info.get("responsibleName") or "",
        "deal_responsible_photo": info.get("responsiblePhoto") or "",
        "deal_paid_amount": paid,
        "deal_paid_percent": pct,
        "invoice_title": None,
        "invoice_amount": None,
        "invoice_currency": None,
        "invoice_account_number": None,
        "invoice_responsible_id": None,
        "invoice_responsible_name": None,
        "invoice_responsible_photo": None,
        "invoice_paid_amount": None,
        "invoice_paid_percent": None,
        "payment_source": "acquiring",
        **flags,
    }


def serialize_acquiring_row(
    op: TochkaAcquiringOperation,
    entity_info: dict[str, Any] | None = None,
) -> dict[str, Any]:
    ts: datetime = op.updated_at if op.status == ACQUIRING_PAID_STATUS else op.created_at
    info = entity_info or {}
    acc_id = resolve_acquiring_account_id(op)
    cname = (info.get("contactFullName") or "").strip()
    cid = info.get("contactId")
    flags = acquiring_action_flags(op)
    return {
        "id": str(op.id),
        "account_id": acc_id,
        "transaction_id": op.external_operation_id or "",
        "payment_slip_number": op.payment_link_id or "",
        "amount": float(op.amount),
        "amount_nat": None,
        "currency": op.currency,
        "credit_debit": "credit",
        "counterparty_name": cname or None,
        "counterparty_contact_id": int(cid) if cid is not None else None,
        "counterparty_inn": None,
        "counterparty_account": None,
        "counterparty_bik": None,
        "purpose": op.purpose or "",
        "invoice_number": None,
        "vat_type": None,
        "transaction_date": ts.isoformat(),
        "status": acquiring_row_status_display(op),
        "matched_deal_id": op.deal_id,
        "matched_lead_id": op.lead_id,
        "matched_invoice_id": None,
        "deal_title": None,
        "deal_amount": None,
        "deal_currency": op.currency,
        "source": "acquiring",
        "channel": acquiring_row_channel(op),
        "payment_kind": op.payment_kind or None,
        "payment_method": acquiring_row_channel(op),
        "payment_url": op.payment_url,
        "acquiring_status": op.status,
        "can_unlink": flags["can_cancel_link"] or flags["can_unlink_deal"],
        "can_refund": flags["can_refund"],
    }


def build_crm_entity_payments(
    member_id: str,
    *,
    deal_id: int | None,
    lead_id: int | None,
) -> tuple[list[dict[str, Any]], Decimal]:
    bank_qs = TochkaPayment.objects.filter(
        member_id=member_id,
        credit_debit="credit",
        ledger_exclude=False,
    )
    acq_qs = TochkaAcquiringOperation.objects.filter(member_id=member_id).exclude(
        status=ACQUIRING_CANCELLED
    )

    if deal_id is not None:
        bank_qs = bank_qs.filter(matched_deal_id=deal_id)
        acq_qs = acq_qs.filter(deal_id=deal_id)
    elif lead_id is not None:
        bank_qs = bank_qs.filter(matched_lead_id=lead_id)
        acq_qs = acq_qs.filter(lead_id=lead_id)

    bank_rows = list(
        bank_qs.order_by("-transaction_date").values(
            "id",
            "account_id",
            "transaction_id",
            "payment_slip_number",
            "amount",
            "amount_nat",
            "currency",
            "credit_debit",
            "counterparty_name",
            "counterparty_inn",
            "counterparty_account",
            "counterparty_bik",
            "purpose",
            "invoice_number",
            "vat_type",
            "transaction_date",
            "status",
            "matched_deal_id",
            "matched_lead_id",
            "matched_invoice_id",
            "deal_title",
            "deal_amount",
            "deal_currency",
        )
    )
    acq_rows = list(acq_qs.order_by("-created_at"))

    from .views_acquiring import _fetch_crm_entity_info

    entity_cache: dict[tuple[int | None, int | None], dict[str, Any]] = {}

    def _entity_for_op(op: TochkaAcquiringOperation) -> dict[str, Any]:
        key = (op.deal_id, op.lead_id)
        if key not in entity_cache:
            entity_cache[key] = _fetch_crm_entity_info(member_id, op.deal_id, op.lead_id)
        return entity_cache[key]

    merged: list[dict[str, Any]] = [serialize_bank_row(p) for p in bank_rows]
    merged.extend(serialize_acquiring_row(op, _entity_for_op(op)) for op in acq_rows)

    def _sort_key(r: dict[str, Any]) -> str:
        return r.get("transaction_date") or ""

    merged.sort(key=_sort_key, reverse=True)

    from .models import Bitrix24Account
    from .payment_notifications import enrich_bank_payment_rows_by_inn

    _acc = Bitrix24Account.objects.filter(member_id=member_id).first()
    enrich_bank_payment_rows_by_inn(_acc, merged)

    bank_sum = bank_qs.aggregate(s=Sum("amount"))["s"] or Decimal("0")
    acq_sum = sum(
        (op.amount for op in acq_rows if op.status == ACQUIRING_PAID_STATUS),
        start=Decimal("0"),
    )
    total_paid = bank_sum + acq_sum

    return merged, total_paid
