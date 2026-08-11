"""Shared Tochka payment sync (HTTP handler and background jobs)."""
from __future__ import annotations

import re
from datetime import datetime, timedelta
from typing import Tuple
from zoneinfo import ZoneInfo

from django.utils import timezone as dj_tz

from .datetime_utils import ensure_aware, normalize_model_datetimes, parse_bank_datetime
from .models import TochkaCredentials, TochkaPayment, TochkaPortalPreferences
from .tochka import TochkaClient


def _get_tochka_client(member_id: str) -> Tuple[TochkaClient | None, TochkaCredentials | None]:
    try:
        creds = TochkaCredentials.objects.get(member_id=member_id)
        client = TochkaClient(
            client_id=creds.client_id,
            client_secret=creds.client_secret,
            access_token=creds.access_token,
            refresh_token=creds.refresh_token,
            token_expires_at=creds.token_expires_at,
        )
        return client, creds
    except TochkaCredentials.DoesNotExist:
        return None, None


def _persist_client_tokens(credentials: TochkaCredentials, client: TochkaClient) -> None:
    TochkaCredentials.objects.filter(pk=credentials.pk).update(
        access_token=client.access_token,
        refresh_token=client.refresh_token,
        token_expires_at=ensure_aware(client.token_expires_at),
        updated_at=dj_tz.now(),
    )


def _payment_slip_number_from_tx(tx: dict) -> str | None:
    """Номер платёжного поручения / документа из тела транзакции (разные варианты схемы Точки)."""
    direct_keys = (
        "documentNumber",
        "DocumentNumber",
        "paymentOrderNumber",
        "PaymentOrderNumber",
        "paymentOrder",
        "PaymentOrder",
        "reference",
        "Reference",
        "paymentReference",
        "PaymentReference",
        "transactionReference",
        "TransactionReference",
        "acctSvcrRef",
        "AcctSvcrRef",
        "documentRef",
        "DocumentRef",
    )
    for k in direct_keys:
        v = tx.get(k)
        if v is not None and str(v).strip():
            return str(v).strip()
    doc = tx.get("Document") or tx.get("document")
    if isinstance(doc, dict):
        for k in ("number", "Number", "documentNumber", "DocumentNumber"):
            v = doc.get(k)
            if v is not None and str(v).strip():
                return str(v).strip()
    refs = tx.get("References") or tx.get("references") or tx.get("Refs") or tx.get("refs")
    if isinstance(refs, dict):
        for k in (
            "paymentOrderNumber",
            "documentNumber",
            "endToEndId",
            "EndToEndId",
            "transactionReference",
        ):
            v = refs.get(k)
            if v is not None and str(v).strip():
                return str(v).strip()
    return None


def _extract_invoice_number(purpose: str) -> str | None:
    if not purpose:
        return None
    patterns = [
        # «Оплата счета №_43», «счёт № 46», «invoice #12»
        r'(?:сч(?:ет|ёта?)|invoice|inv)[^\d]{0,24}?(\d+)',
        r'(?:по\s+сч|оплата\s+сч)[^\d]{0,24}?(\d+)',
        # «№_43», «№*43» — частый формат в назначении платежа
        r'№[^\d]*(\d+)',
    ]
    for p in patterns:
        m = re.search(p, purpose, re.IGNORECASE)
        if m:
            return m.group(1)
    return None


def _extract_vat(purpose: str) -> str:
    if not purpose:
        return ""
    lower = purpose.lower()
    if "без ндс" in lower or "без нд" in lower or "ндс не предусмотрен" in lower:
        return "Без НДС"
    if "ндс 20" in lower or "ндс20" in lower:
        return "20%"
    if "ндс 10" in lower or "ндс10" in lower:
        return "10%"
    if "ндс" in lower and "не предусмотр" not in lower:
        return "НДС"
    return "Без НДС"


def run_tochka_sync(
    member_id: str,
    *,
    days: int | None = None,
    start_date_calendar: str | None = None,
    end_date_calendar: str | None = None,
) -> dict:
    """
    Pull statements for all accounts and upsert TochkaPayment rows.

    Pass either (start_date_calendar, end_date_calendar) as YYYY-MM-DD,
    or days (lookback from now). Default days=30 if neither range given.

    Returns: {"synced": int, "lastSync": iso str}
    Raises: ValueError, Exception from API; may set creds disconnected on token failure (caller handles).
    """
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        raise ValueError("Not connected to Tochka")

    client.ensure_token()

    if start_date_calendar and end_date_calendar:
        moscow = ZoneInfo("Europe/Moscow")
        try:
            d_start = datetime.strptime(str(start_date_calendar)[:10], "%Y-%m-%d").replace(tzinfo=moscow)
            d_end = datetime.strptime(str(end_date_calendar)[:10], "%Y-%m-%d").replace(tzinfo=moscow)
        except ValueError as e:
            raise ValueError("Некорректные startDate или endDate (ожидается YYYY-MM-DD)") from e
        if d_start > d_end:
            raise ValueError("startDate не может быть позже endDate")
        start_date = d_start.isoformat()
        end_date = datetime.combine(
            d_end.date(),
            datetime.max.time().replace(microsecond=0),
            tzinfo=moscow,
        ).isoformat()
    else:
        d = int(days) if days is not None else 30
        d = max(d, 1)
        moscow = ZoneInfo("Europe/Moscow")
        today = dj_tz.now().astimezone(moscow).date()
        end_date = datetime.combine(today, datetime.min.time(), tzinfo=moscow).isoformat()
        start_day = today - timedelta(days=d)
        start_date = datetime.combine(start_day, datetime.min.time(), tzinfo=moscow).isoformat()

    accounts_resp = client.get_accounts()
    accounts = accounts_resp.get("Data", {}).get("Account", [])

    total_synced = 0
    new_payment_ids: list = []

    for account in accounts:
        account_id = account.get("accountId")
        if not account_id:
            continue

        try:
            transactions = client.sync_statement(account_id, start_date, end_date)
        except Exception as e:
            print(f"Sync error for {account_id}: {e}")
            continue

        for tx in transactions:
            tx_id = tx.get("transactionId", "")

            amount_obj = tx.get("Amount") or tx.get("amount") or {}
            amount = amount_obj.get("amount", 0)
            amount_nat = amount_obj.get("amountNat")
            currency = amount_obj.get("currency", "RUB")

            credit_debit = tx.get("creditDebitIndicator", "credit").lower()
            purpose = tx.get("description", "") or tx.get("transactionInformation", "")

            if credit_debit == "debit":
                counterparty = tx.get("CreditorParty") or tx.get("counterparty") or {}
                cp_account_obj = tx.get("CreditorAccount") or {}
                cp_agent = tx.get("CreditorAgent") or {}
            else:
                counterparty = tx.get("DebtorParty") or tx.get("counterparty") or {}
                cp_account_obj = tx.get("DebtorAccount") or {}
                cp_agent = tx.get("DebtorAgent") or {}

            cp_name = counterparty.get("name", "")
            cp_inn = counterparty.get("inn", "")
            cp_account = cp_account_obj.get("identification", "")
            cp_bik = cp_agent.get("identification", "")

            tx_date_str = tx.get("documentProcessDate") or tx.get("valueDateTime") or tx.get("bookingDateTime") or ""
            tx_date = parse_bank_datetime(tx_date_str)

            invoice_num = _extract_invoice_number(purpose)
            vat = _extract_vat(purpose)
            slip_no = _payment_slip_number_from_tx(tx)

            payment_defaults = {
                "account_id": account_id,
                "payment_slip_number": slip_no,
                "amount": float(amount),
                "amount_nat": float(amount_nat) if amount_nat else None,
                "currency": currency,
                "credit_debit": credit_debit,
                "counterparty_name": cp_name,
                "counterparty_inn": cp_inn,
                "counterparty_account": cp_account,
                "counterparty_bik": cp_bik,
                "purpose": purpose,
                "invoice_number": invoice_num,
                "vat_type": vat,
                "transaction_date": tx_date,
            }
            existing = TochkaPayment.objects.filter(
                member_id=member_id,
                transaction_id=tx_id,
            ).first()
            if existing:
                TochkaPayment.objects.filter(pk=existing.pk).update(**payment_defaults)
                obj = existing
                created = False
            else:
                obj = TochkaPayment(
                    member_id=member_id,
                    transaction_id=tx_id,
                    created_at=dj_tz.now(),
                    **payment_defaults,
                )
                normalize_model_datetimes(obj)
                obj.save(force_insert=True)
                created = True
            if created and credit_debit == "credit":
                new_payment_ids.append(obj.pk)
            total_synced += 1

    _persist_client_tokens(creds, client)

    last_sync_utc = dj_tz.now()
    TochkaPortalPreferences.objects.update_or_create(
        member_id=member_id,
        defaults={"client_auto_sync_interval_minutes": 15},
    )
    TochkaPortalPreferences.objects.filter(member_id=member_id).update(last_sync_at=last_sync_utc)

    return {
        "synced": total_synced,
        "lastSync": last_sync_utc.isoformat().replace("+00:00", "Z"),
        "new_payment_ids": new_payment_ids,
    }
