"""Смена статуса классического счёта CRM (crm.invoice) при оплате и автопоиск по сумме/ИНН."""

from __future__ import annotations

from decimal import Decimal

from django.db.models import Sum

from .bitrix_rest import (
    bitrix_rest_call,
    crm_invoice_get,
    crm_invoice_list,
    crm_invoice_update,
)
from .models import Bitrix24Account, TochkaPayment, TochkaPortalPreferences


def _sum_matched_payments_for_invoice(member_id: str, invoice_id: int) -> Decimal:
    agg = TochkaPayment.objects.filter(
        member_id=member_id,
        matched_invoice_id=invoice_id,
        status="matched",
        credit_debit="credit",
    ).aggregate(x=Sum("amount"))
    return Decimal(str(agg["x"] or 0))


def _invoice_result_payload(r: dict) -> dict:
    inv = r.get("result")
    if isinstance(inv, list) and inv:
        inv = inv[0]
    return inv if isinstance(inv, dict) else {}


def _resolve_invoice_amount(
    member_id: str,
    invoice_id: int,
    account: Bitrix24Account,
) -> Decimal | None:
    row = (
        TochkaPayment.objects.filter(member_id=member_id, matched_invoice_id=invoice_id)
        .exclude(invoice_amount__isnull=True)
        .first()
    )
    if row and row.invoice_amount is not None:
        return Decimal(str(row.invoice_amount))
    r = crm_invoice_get(account, invoice_id)
    inv = _invoice_result_payload(r)
    if not inv:
        return None
    price = inv.get("PRICE") if "PRICE" in inv else inv.get("price")
    if price is None:
        return None
    try:
        return Decimal(str(price))
    except Exception:
        return None


def maybe_update_invoice_stage_for_invoice(member_id: str, invoice_id: int) -> None:
    """Если в настройках включено — выставить STATUS_ID классического счёта по полной/частичной оплате."""
    prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
    if not prefs or not prefs.invoice_auto_update_stage:
        return
    paid_stage = (prefs.invoice_stage_id_paid or "").strip()
    partial_stage = (prefs.invoice_stage_id_partial or "").strip()
    if not paid_stage and not partial_stage:
        return
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account:
        return
    total = _sum_matched_payments_for_invoice(member_id, invoice_id)
    inv_amt = _resolve_invoice_amount(member_id, invoice_id, account)

    target_stage: str | None = None
    if inv_amt is not None and inv_amt > 0:
        if total + Decimal("0.02") >= inv_amt:
            target_stage = paid_stage or partial_stage
        elif total > 0:
            target_stage = partial_stage or paid_stage
    else:
        if total > 0:
            target_stage = partial_stage or paid_stage

    if not target_stage:
        return

    r = crm_invoice_get(account, invoice_id)
    inv = _invoice_result_payload(r)
    if not inv:
        return
    current = str(inv.get("STATUS_ID") or inv.get("statusId") or "")
    if current == target_stage:
        return
    crm_invoice_update(account, invoice_id, {"STATUS_ID": target_stage})


def _company_inn(account: Bitrix24Account, company_id: int) -> str:
    r = bitrix_rest_call(account, "crm.company.get", {"id": company_id})
    c = r.get("result", {}) or {}
    return str(c.get("RQ_INN") or c.get("UF_CRM_INN") or "").strip()


def _pick_invoices_for_amount(account: Bitrix24Account, amount: Decimal) -> list[dict]:
    amt = float(amount)
    r = crm_invoice_list(
        account,
        {"=PRICE": amt},
        select=[
            "ID",
            "ORDER_TOPIC",
            "PRICE",
            "CURRENCY_ID",
            "STATUS_ID",
            "ACCOUNT_NUMBER",
            "RESPONSIBLE_ID",
            "COMPANY_ID",
        ],
    )
    raw = r.get("result")
    if isinstance(raw, list):
        items = raw
    elif isinstance(raw, dict):
        items = raw.get("items") or raw.get("invoices") or []
    else:
        items = []
    return [x for x in items if isinstance(x, dict)]


def try_auto_match_invoice_for_payment(payment: TochkaPayment) -> bool:
    """Привязка выписки к классическому счёту: crm.invoice.list по сумме и опционально ИНН компании."""
    prefs = TochkaPortalPreferences.objects.filter(member_id=payment.member_id).first()
    if not prefs:
        return False
    if not prefs.invoice_match_by_amount and not prefs.invoice_match_by_inn:
        return False
    if not prefs.invoice_match_by_amount:
        return False
    if payment.status != "pending" or payment.matched_invoice_id:
        return False
    if payment.credit_debit != "credit":
        return False

    account = Bitrix24Account.objects.filter(member_id=payment.member_id).first()
    if not account:
        return False
    items = _pick_invoices_for_amount(account, payment.amount)
    if not items:
        return False

    inn_pay = (payment.counterparty_inn or "").strip()
    chosen: dict | None = None
    if prefs.invoice_match_by_inn and inn_pay:
        for it in items:
            cid = it.get("COMPANY_ID") or it.get("companyId") or it.get("UF_COMPANY_ID")
            if not cid:
                continue
            try:
                inn_c = _company_inn(account, int(cid))
            except Exception:
                continue
            if inn_c and inn_c == inn_pay:
                chosen = it
                break
        if chosen is None:
            return False
    else:
        if len(items) != 1:
            return False
        chosen = items[0]

    iid = chosen.get("ID") or chosen.get("id")
    if not iid:
        return False
    iid = int(iid)

    r = crm_invoice_get(account, iid)
    inv = _invoice_result_payload(r)
    if not inv:
        return False

    payment.matched_invoice_id = iid
    payment.matched_deal_id = None
    payment.matched_lead_id = None
    payment.invoice_title = str(
        inv.get("ORDER_TOPIC") or inv.get("orderTopic") or inv.get("TITLE") or ""
    )
    price = inv.get("PRICE") if "PRICE" in inv else inv.get("price")
    payment.invoice_amount = float(price) if price is not None else None
    payment.invoice_currency = str(inv.get("CURRENCY_ID") or inv.get("currencyId") or "RUB")
    payment.invoice_account_number = str(inv.get("ACCOUNT_NUMBER") or inv.get("accountNumber") or "")
    aid = inv.get("RESPONSIBLE_ID") or inv.get("responsibleId")
    payment.invoice_responsible_id = int(aid) if aid else None
    payment.invoice_responsible_name = ""
    payment.invoice_responsible_photo = ""
    inv_amt = float(payment.invoice_amount or 0)
    pay_amt = float(payment.amount)
    payment.invoice_paid_amount = Decimal(str(pay_amt)).quantize(Decimal("0.01"))
    payment.invoice_paid_percent = min(100, int((pay_amt / inv_amt * 100) if inv_amt > 0 else 100))
    payment.status = "matched"
    payment.save()
    maybe_update_invoice_stage_for_invoice(payment.member_id, iid)
    try:
        from .payment_notifications import notify_payment_matched

        notify_payment_matched(payment.member_id, payment)
    except Exception as e:
        print(f"[PAYMENT_NOTIFY] {e}")
    return True


def try_auto_match_invoices_for_member(member_id: str, limit: int = 50) -> int:
    """Обработать необработанные входящие платежи — автопривязка к счетам. Возвращает число привязок."""
    qs = (
        TochkaPayment.objects.filter(
            member_id=member_id,
            status="pending",
            credit_debit="credit",
        )
        .filter(matched_invoice_id__isnull=True)
        .order_by("-transaction_date")[:limit]
    )
    n = 0
    for p in qs:
        if try_auto_match_invoice_for_payment(p):
            n += 1
    return n
