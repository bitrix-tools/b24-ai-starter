import json
import uuid
from decimal import Decimal

from django.db import transaction
from django.db.models import Q
from django.http import JsonResponse
from django.views.decorators.clickjacking import xframe_options_exempt
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST

from config import load_config

from .bitrix_rest import bitrix_rest_call, sync_deal_payment_uf, sync_lead_payment_uf
from .ledger import ACQUIRING_CANCELLED, ACQUIRING_PAID_STATUS, ACQUIRING_REFUNDED, acquiring_row_channel
from .models import Bitrix24Account, TochkaAcquiringOperation, TochkaPayment, TochkaPortalPreferences
from .tochka_acquiring_webhook import decode_acquiring_webhook_body
from .utils.decorators import auth_required, log_errors
from .utils import AuthorizedRequest
from .views_tochka import _get_tochka_client

config = load_config()

_ALLOWED_PAYMENT_KINDS = frozenset({"full", "remainder", "advance"})

_KNOWN_PAYMENT_MODES = frozenset({"card", "sbp", "tinkoff", "dolyame"})


def _normalize_payment_mode_value(val: object) -> str | None:
    if val is None:
        return None
    m = str(val).strip().lower()
    aliases = {
        "card": "card",
        "sbp": "sbp",
        "tinkoff": "tinkoff",
        "t-pay": "tinkoff",
        "t_pay": "tinkoff",
        "tpay": "tinkoff",
        "dolyame": "dolyame",
    }
    if m in aliases:
        return aliases[m]
    if m in _KNOWN_PAYMENT_MODES:
        return m
    return None


def _payment_modes_from_retailer(retailer: dict) -> set[str]:
    """См. Get Retailers → paymentModes у торговой точки."""
    raw = (
        retailer.get("paymentModes")
        or retailer.get("PaymentModes")
        or retailer.get("payment_mode")
        or retailer.get("PaymentMode")
    )
    if raw is None:
        return set()
    if isinstance(raw, str):
        raw = [raw]
    if isinstance(raw, dict):
        raw = list(raw.values())
    out: set[str] = set()
    for item in raw:
        if isinstance(item, dict):
            v = item.get("code") or item.get("value") or item.get("name") or item.get("mode")
            n = _normalize_payment_mode_value(v) if v is not None else None
        else:
            n = _normalize_payment_mode_value(item)
        if n:
            out.add(n)
    return out


def _pick_retailer(active_retailers: list, merchant_id: object | None) -> dict | None:
    if not active_retailers:
        return None
    if merchant_id not in (None, ""):
        mid = str(merchant_id)
        for r in active_retailers:
            if not isinstance(r, dict):
                continue
            if str(r.get("merchantId") or r.get("merchant_id") or "") == mid:
                return r
    return active_retailers[0] if isinstance(active_retailers[0], dict) else None


def _dedupe_modes_preserve_order(seq: list[str]) -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for x in seq:
        if x not in seen:
            seen.add(x)
            out.append(x)
    return out


def _effective_payment_modes_for_payload(requested: list[str], allowed: set[str]) -> list[str]:
    """
    Оставляем только способы, разрешённые для торговой точки (Get Retailers).
    Если Точка не вернула paymentModes — не фильтруем (старое поведение).
    """
    req = [_normalize_payment_mode_value(str(x)) for x in requested]
    req = [x for x in req if x]
    req = _dedupe_modes_preserve_order(req)
    if not req:
        req = ["card", "sbp"]
    if not allowed:
        return req
    eff = [m for m in req if m in allowed]
    if eff:
        return eff
    fallback = [m for m in ("card", "sbp") if m in allowed]
    return fallback if fallback else _dedupe_modes_preserve_order(sorted(allowed))


def _extract_business_customer_code(customer_payload: dict) -> str | None:
    data = customer_payload.get("Data") or customer_payload
    customers = data.get("Customer") or data.get("Customers") or []
    if isinstance(customers, dict):
        customers = [customers]
    fallback = None
    for c in customers:
        if not isinstance(c, dict):
            continue
        code = c.get("customerCode") or c.get("CustomerCode")
        ctype = c.get("customerType") or c.get("CustomerType")
        if ctype == "Business":
            return code
        if code and not fallback:
            fallback = code
    return fallback


def _webhook_internal_status(claims: dict) -> str:
    raw = (claims.get("status") or "").upper()
    if raw in ("APPROVED", "EXECUTED", "CONFIRMED", "SUCCESS", "COMPLETED"):
        return ACQUIRING_PAID_STATUS
    if raw == "AUTHORIZED":
        return "authorized"
    if raw in ("REJECTED", "DECLINED", "FAILED", "CANCELLED", "REVERSED"):
        return "cancelled"
    return "pending"


def _extract_primary_contact_id(r: dict) -> int | None:
    cid = r.get("CONTACT_ID") or r.get("contactId")
    if cid not in (None, "", 0, "0"):
        try:
            return int(cid)
        except (TypeError, ValueError):
            pass
    raw = r.get("CONTACT_IDS") or r.get("contactIds")
    if isinstance(raw, str) and raw.strip():
        for part in raw.split(","):
            part = part.strip()
            if not part:
                continue
            try:
                return int(part)
            except ValueError:
                continue
    if isinstance(raw, (list, tuple)) and raw:
        first = raw[0]
        if isinstance(first, dict):
            i = first.get("CONTACT_ID") or first.get("id") or first.get("ID")
            if i is not None:
                try:
                    return int(i)
                except (TypeError, ValueError):
                    return None
        try:
            return int(first)
        except (TypeError, ValueError):
            pass
    return None


def _fetch_crm_entity_info(member_id: str, deal_id: int | None, lead_id: int | None) -> dict:
    """Fetch deal/lead info from Bitrix24 to populate bank payment cache fields."""
    account = Bitrix24Account.objects.filter(member_id=member_id).first()
    if not account:
        return {}

    entity_method = "crm.deal.get" if deal_id else "crm.lead.get" if lead_id else None
    entity_crm_id = deal_id or lead_id
    if not entity_method or not entity_crm_id:
        return {}

    resp = bitrix_rest_call(account, entity_method, {"id": entity_crm_id})
    r = resp.get("result", {})
    if not r:
        return {}

    info = {
        "title": r.get("TITLE", ""),
        "amount": float(r.get("OPPORTUNITY", 0) or 0),
        "currency": r.get("CURRENCY_ID", "RUB"),
        "responsibleId": r.get("ASSIGNED_BY_ID"),
        "responsibleName": "",
        "responsiblePhoto": "",
        "contactId": None,
        "contactFullName": "",
    }

    contact_id = _extract_primary_contact_id(r)
    if contact_id:
        c_resp = bitrix_rest_call(account, "crm.contact.get", {"id": contact_id})
        c = c_resp.get("result") or {}
        if isinstance(c, dict) and c:
            fn = f"{c.get('NAME') or ''} {c.get('LAST_NAME') or ''}".strip()
            info["contactId"] = contact_id
            info["contactFullName"] = fn or (c.get("NAME") or "")

    resp_id = r.get("ASSIGNED_BY_ID")
    if resp_id:
        user_resp = bitrix_rest_call(account, "user.get", {"ID": resp_id})
        users = user_resp.get("result", [])
        if users and isinstance(users, list) and users[0]:
            u = users[0]
            name = f"{u.get('NAME', '')} {u.get('LAST_NAME', '')}".strip()
            info["responsibleName"] = name
            info["responsiblePhoto"] = u.get("PERSONAL_PHOTO", "") or ""

    return info


def _apply_entity_info_to_payment(p: TochkaPayment, entity_info: dict, deal_id: int | None, lead_id: int | None) -> list[str]:
    """Fill deal/lead cache fields on a bank payment. Returns list of updated field names."""
    updated_fields = []
    if deal_id:
        p.matched_deal_id = deal_id
        p.deal_title = entity_info.get("title", "")
        p.deal_amount = Decimal(str(entity_info.get("amount", 0) or 0))
        p.deal_currency = entity_info.get("currency", "RUB")
        resp_id = entity_info.get("responsibleId")
        p.deal_responsible_id = int(resp_id) if resp_id else None
        p.deal_responsible_name = entity_info.get("responsibleName", "")
        p.deal_responsible_photo = entity_info.get("responsiblePhoto", "")
        deal_amount = float(entity_info.get("amount", 0) or 0)
        payment_amount = float(p.amount)
        p.deal_paid_amount = Decimal(str(payment_amount))
        p.deal_paid_percent = min(100, int((payment_amount / deal_amount * 100) if deal_amount > 0 else 100))
        updated_fields = [
            "matched_deal_id", "deal_title", "deal_amount", "deal_currency",
            "deal_responsible_id", "deal_responsible_name", "deal_responsible_photo",
            "deal_paid_amount", "deal_paid_percent",
        ]
    elif lead_id:
        p.matched_lead_id = lead_id
        updated_fields = ["matched_lead_id"]
    return updated_fields


def _dedupe_bank_payment_for_acquiring(op: TochkaAcquiringOperation) -> None:
    if op.status != ACQUIRING_PAID_STATUS:
        return
    entity_id = op.deal_id or op.lead_id
    if not entity_id:
        return

    amount = op.amount
    entity_field = "matched_deal_id" if op.deal_id else "matched_lead_id"

    already_matched = TochkaPayment.objects.filter(
        member_id=op.member_id,
        credit_debit="credit",
        ledger_exclude=False,
        **{entity_field: entity_id},
    )
    for p in already_matched:
        if abs(p.amount - amount) <= Decimal("0.02"):
            p.ledger_exclude = True
            p.save(update_fields=["ledger_exclude"])
            TochkaAcquiringOperation.objects.filter(pk=op.pk).update(duplicate_bank_payment_id=p.id)
            return

    from datetime import timedelta
    from django.utils import timezone
    lookback = op.created_at - timedelta(days=1)
    lookahead = (op.updated_at or timezone.now()) + timedelta(days=7)
    unmatched = (
        TochkaPayment.objects.filter(
            member_id=op.member_id,
            credit_debit="credit",
            status="pending",
            ledger_exclude=False,
            transaction_date__gte=lookback,
            transaction_date__lte=lookahead,
        )
        .filter(Q(**{f"{entity_field}__isnull": True}) | Q(**{entity_field: None}))
        .order_by("-transaction_date")
    )
    for p in unmatched:
        if abs(p.amount - amount) <= Decimal("0.02"):
            entity_info = _fetch_crm_entity_info(op.member_id, op.deal_id, op.lead_id)
            entity_fields = _apply_entity_info_to_payment(p, entity_info, op.deal_id, op.lead_id)

            p.status = "applied" if op.deal_id else "matched"
            p.ledger_exclude = True
            save_fields = ["status", "ledger_exclude"] + entity_fields
            p.save(update_fields=save_fields)
            TochkaAcquiringOperation.objects.filter(pk=op.pk).update(duplicate_bank_payment_id=p.id)
            try:
                from .payment_notifications import notify_payment_matched

                notify_payment_matched(op.member_id, p)
            except Exception as e:
                print(f"[PAYMENT_NOTIFY] {e}")
            return

    op.refresh_from_db(fields=["duplicate_bank_payment_id"])
    if op.duplicate_bank_payment_id:
        return
    try:
        from .payment_notifications import notify_acquiring_link_paid

        notify_acquiring_link_paid(op)
    except Exception as e:
        print(f"[ACQ_NOTIFY] {e}")


def _push_uf_after_paid(op: TochkaAcquiringOperation) -> None:
    if op.status != ACQUIRING_PAID_STATUS:
        return
    if not op.deal_id and not op.lead_id:
        return
    account = Bitrix24Account.objects.filter(member_id=op.member_id).first()
    if not account:
        return
    prefs = TochkaPortalPreferences.objects.filter(member_id=op.member_id).first()
    if not prefs:
        return
    channel = acquiring_row_channel(op)
    if op.deal_id:
        sync_deal_payment_uf(
            account,
            op.deal_id,
            payment_kind=op.payment_kind,
            channel_code=channel,
            kind_uf=(prefs.deal_payment_kind_uf_code or "").strip() or None,
            channel_uf=(prefs.deal_payment_channel_uf_code or "").strip() or None,
        )
    if op.lead_id:
        sync_lead_payment_uf(
            account,
            op.lead_id,
            payment_kind=op.payment_kind,
            channel_code=channel,
            kind_uf=(prefs.lead_payment_kind_uf_code or "").strip() or None,
            channel_uf=(prefs.lead_payment_channel_uf_code or "").strip() or None,
        )


@csrf_exempt
@require_POST
@log_errors("tochka_acquiring_webhook")
def tochka_acquiring_webhook(request):
    """Публичный вебхук acquiringInternetPayment (тело — JWT, Content-Type text/plain)."""
    raw = request.body.decode("utf-8", errors="replace").strip()
    if not raw:
        return JsonResponse({"ok": False}, status=400)

    dev_secret = (config.tochka_webhook_dev_secret or "").strip()
    if dev_secret and request.headers.get("X-Tochka-Webhook-Dev") == dev_secret:
        try:
            claims = json.loads(raw)
        except json.JSONDecodeError:
            return JsonResponse({"ok": False}, status=400)
    else:
        try:
            claims = decode_acquiring_webhook_body(raw)
        except Exception:
            return JsonResponse({"ok": False}, status=401)

    if claims.get("webhookType") != "acquiringInternetPayment":
        return JsonResponse({"ok": True})

    operation_id = claims.get("operationId") or claims.get("operation_id")
    if not operation_id:
        return JsonResponse({"ok": True})

    payment_type = claims.get("paymentType") or claims.get("payment_type") or claims.get("paymentMode")
    new_status = _webhook_internal_status(claims)

    with transaction.atomic():
        op = (
            TochkaAcquiringOperation.objects.select_for_update()
            .filter(external_operation_id=str(operation_id))
            .first()
        )
        if not op:
            cust = claims.get("customerCode")
            if cust:
                op = (
                    TochkaAcquiringOperation.objects.select_for_update()
                    .filter(
                        customer_code=str(cust),
                        status="pending",
                    )
                    .filter(Q(external_operation_id__isnull=True) | Q(external_operation_id=""))
                    .order_by("-created_at")
                    .first()
                )
            if not op:
                return JsonResponse({"ok": True})

        prev_paid = op.status == ACQUIRING_PAID_STATUS
        op.webhook_raw = json.dumps(claims, ensure_ascii=False)
        op.external_operation_id = str(operation_id)
        if payment_type:
            op.payment_mode_effective = str(payment_type).lower()
        op.status = new_status
        op.save()

        if op.status == ACQUIRING_PAID_STATUS and not prev_paid:
            _dedupe_bank_payment_for_acquiring(op)
            _push_uf_after_paid(op)

    return JsonResponse({"ok": True})


@xframe_options_exempt
@require_GET
@log_errors("tochka_acquiring_retailers")
@auth_required
def tochka_acquiring_retailers(request: AuthorizedRequest):
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)
    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)
    try:
        cust = client.get_customer()
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        cust_code = _extract_business_customer_code(cust)
        if not cust_code:
            return JsonResponse({"error": "Could not resolve customerCode"}, status=400)
        data = client.get_acquiring_retailers(cust_code)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_acquiring_create_payment_link")
@auth_required
def tochka_acquiring_create_payment_link(request: AuthorizedRequest):
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)
    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    body = json.loads(request.body) if request.body else {}
    deal_id = body.get("dealId")
    lead_id = body.get("leadId")
    amount_raw = body.get("amount")
    payment_kind = (body.get("paymentKind") or body.get("payment_kind") or "").strip().lower()
    modes = body.get("paymentModes") or body.get("payment_modes") or ["card", "sbp"]
    merchant_id = body.get("merchantId") or body.get("merchant_id")
    purpose = (body.get("purpose") or "").strip()

    if amount_raw is None:
        return JsonResponse({"error": "amount required"}, status=400)
    try:
        amount = Decimal(str(amount_raw))
    except Exception:
        return JsonResponse({"error": "invalid amount"}, status=400)
    if amount <= 0:
        return JsonResponse({"error": "amount must be positive"}, status=400)

    has_deal = deal_id not in (None, "")
    has_lead = lead_id not in (None, "")
    if has_deal == has_lead:
        return JsonResponse({"error": "Specify exactly one of dealId, leadId"}, status=400)
    if payment_kind not in _ALLOWED_PAYMENT_KINDS:
        return JsonResponse({"error": "invalid paymentKind"}, status=400)

    try:
        if has_deal:
            deal_id_int = int(deal_id)
            lead_id_int = None
        else:
            deal_id_int = None
            lead_id_int = int(lead_id)
    except (TypeError, ValueError):
        return JsonResponse({"error": "invalid deal or lead id"}, status=400)

    if not isinstance(modes, list) or not modes:
        modes = ["card", "sbp"]

    try:
        cust = client.get_customer()
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        cust_code = _extract_business_customer_code(cust)
        if not cust_code:
            print(f"[ACQ] customer data (no Business found): {json.dumps(cust, ensure_ascii=False, default=str)[:1500]}")
            return JsonResponse({"error": "Could not resolve customerCode from Tochka"}, status=400)

        retailers_resp = client.get_acquiring_retailers(cust_code)
        r_data = retailers_resp.get("Data", retailers_resp)
        retailers = r_data.get("Retailer") or r_data.get("Retailers") or []
        if isinstance(retailers, dict):
            retailers = [retailers]

        active = [r for r in retailers if isinstance(r, dict)
                  and str(r.get("isActive", "")).lower() == "true"
                  and r.get("status") == "REG"]

        if not active:
            closed = [r for r in retailers if isinstance(r, dict)]
            statuses = ", ".join(f"{r.get('name','?')} ({r.get('status','?')})" for r in closed) or "нет"
            return JsonResponse({
                "error": f"Интернет-эквайринг не активен. Торговые точки: {statuses}. "
                         "Подключите эквайринг в интернет-банке Точки: Сервисы → Интернет-эквайринг.",
            }, status=400)

        if not merchant_id:
            merchant_id = active[0].get("merchantId")

        retailer_obj = _pick_retailer(active, merchant_id)
        allowed_modes = _payment_modes_from_retailer(retailer_obj) if retailer_obj else set()
        effective_modes = _effective_payment_modes_for_payload(
            [str(m) for m in modes],
            allowed_modes,
        )

        print(
            f"[ACQ] create link paymentModes requested={modes!r} "
            f"allowed_by_retailer={sorted(allowed_modes) if allowed_modes else '—'} "
            f"effective={effective_modes!r}",
            flush=True,
        )

        payload: dict = {
            "amount": float(amount),
            "customerCode": cust_code,
            "purpose": purpose or (f"Оплата сделки #{deal_id_int}" if deal_id_int else f"Оплата лида #{lead_id_int}"),
            "paymentMode": effective_modes,
        }
        if merchant_id:
            payload["merchantId"] = str(merchant_id)

        api_resp = client.create_acquiring_payment(payload)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()

        inner = api_resp.get("Data", api_resp)
        if isinstance(inner, list) and inner:
            inner = inner[0]
        if not isinstance(inner, dict):
            inner = {}

        op = TochkaAcquiringOperation(
            member_id=member_id,
            deal_id=deal_id_int,
            lead_id=lead_id_int,
            amount=amount,
            currency="RUB",
            customer_code=cust_code,
            merchant_id=str(merchant_id) if merchant_id else None,
            payment_link_id=str(inner.get("paymentLinkId") or inner.get("payment_link_id") or "") or None,
            external_operation_id=str(inner.get("operationId") or inner.get("operation_id") or "") or None,
            payment_url=inner.get("paymentLink") or inner.get("payment_link") or inner.get("PaymentLink"),
            payment_modes_requested=effective_modes,
            payment_kind=payment_kind,
            purpose=payload["purpose"],
            status="pending",
        )
        op.save()

        user_norm = [_normalize_payment_mode_value(str(x)) for x in modes]
        user_norm = [x for x in user_norm if x]
        dropped = sorted(set(user_norm) - set(effective_modes))

        return JsonResponse(
            {
                "operationId": str(op.id),
                "paymentUrl": op.payment_url,
                "paymentModesRequested": user_norm,
                "paymentModesApplied": effective_modes,
                "retailerPaymentModes": sorted(allowed_modes) if allowed_modes else None,
                "paymentModesDropped": dropped,
                "raw": api_resp,
            }
        )
    except Exception as e:
        err_str = str(e)
        if "403" in err_str and "consent" in err_str.lower():
            return JsonResponse({
                "error": "Для создания платёжных ссылок требуется обновить разрешения. Переподключите аккаунт Точки.",
                "code": "consent_upgrade_required",
            }, status=403)
        return JsonResponse({"error": err_str}, status=400)


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_acquiring_refresh_statuses")
@auth_required
def tochka_acquiring_refresh_statuses(request: AuthorizedRequest):
    """Poll Tochka API for pending acquiring operations and update statuses."""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)
    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    pending_ops = list(
        TochkaAcquiringOperation.objects.filter(
            member_id=member_id,
            status="pending",
        ).exclude(
            Q(external_operation_id__isnull=True) | Q(external_operation_id="")
        )
    )

    paid_unlinked = list(
        TochkaAcquiringOperation.objects.filter(
            member_id=member_id,
            status=ACQUIRING_PAID_STATUS,
            duplicate_bank_payment_id__isnull=True,
        ).filter(Q(deal_id__isnull=False) | Q(lead_id__isnull=False))
    )
    for op in paid_unlinked:
        _dedupe_bank_payment_for_acquiring(op)

    if not pending_ops:
        return JsonResponse({"updated": 0, "deduped": len(paid_unlinked)})

    updated_count = 0
    for op in pending_ops:
        try:
            resp = client.get_acquiring_payment(op.external_operation_id)
            creds.access_token = client.access_token
            creds.token_expires_at = client.token_expires_at

            inner = resp.get("Data", resp)
            operations = inner.get("Operation") or inner.get("Operations") or []
            if isinstance(operations, list) and operations:
                inner = operations[0]
            elif isinstance(inner, list) and inner:
                inner = inner[0]
            if not isinstance(inner, dict):
                continue

            raw_status = (
                inner.get("status") or inner.get("Status") or ""
            ).upper()
            payment_type = (
                inner.get("paymentType") or inner.get("PaymentType")
                or inner.get("paymentMode") or inner.get("PaymentMode")
            )
            if isinstance(payment_type, list):
                payment_type = payment_type[0] if payment_type else None
            new_status = _webhook_internal_status({"status": raw_status})
            print(f"[ACQ_REFRESH] op={op.id} raw_status={raw_status} -> new_status={new_status}")

            if new_status == op.status:
                continue

            with transaction.atomic():
                op_locked = (
                    TochkaAcquiringOperation.objects.select_for_update()
                    .filter(pk=op.pk, status="pending")
                    .first()
                )
                if not op_locked:
                    continue

                prev_paid = op_locked.status == ACQUIRING_PAID_STATUS
                op_locked.status = new_status
                if payment_type:
                    op_locked.payment_mode_effective = str(payment_type).lower()
                op_locked.save()

                if op_locked.status == ACQUIRING_PAID_STATUS and not prev_paid:
                    _dedupe_bank_payment_for_acquiring(op_locked)
                    _push_uf_after_paid(op_locked)

                updated_count += 1
        except Exception as e:
            print(f"[ACQ_REFRESH] Error polling operation {op.id}: {e}")
            continue

    creds.save()
    return JsonResponse({"updated": updated_count, "deduped": len(paid_unlinked)})


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_acquiring_unlink")
@auth_required
def tochka_acquiring_unlink(request: AuthorizedRequest, operation_id: uuid.UUID):
    member_id = request.bitrix24_account.member_id

    with transaction.atomic():
        op = (
            TochkaAcquiringOperation.objects.select_for_update()
            .filter(id=operation_id, member_id=member_id)
            .first()
        )
        if not op:
            return JsonResponse({"error": "Operation not found"}, status=404)
        if op.status == ACQUIRING_CANCELLED:
            return JsonResponse({"error": "Already cancelled"}, status=400)

        if op.status == ACQUIRING_PAID_STATUS:
            if not op.deal_id and not op.lead_id:
                return JsonResponse({"error": "Not linked to CRM entity"}, status=400)
            op.deal_id = None
            op.lead_id = None
            op.save(update_fields=["deal_id", "lead_id"])
            return JsonResponse({"success": True, "detached": True})

        op.status = "cancelled"
        op.save(update_fields=["status"])
    return JsonResponse({"success": True})


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_acquiring_refund")
@auth_required
def tochka_acquiring_refund(request: AuthorizedRequest, operation_id: uuid.UUID):
    """Возврат по платёжной ссылке (Refund Payment Operation в API Точки)."""
    member_id = request.bitrix24_account.member_id
    try:
        body = json.loads(request.body) if request.body else {}
    except json.JSONDecodeError:
        body = {}
    amount_raw = body.get("amount")

    op = TochkaAcquiringOperation.objects.filter(id=operation_id, member_id=member_id).first()
    if not op:
        return JsonResponse({"error": "Operation not found"}, status=404)
    if op.status == ACQUIRING_REFUNDED:
        return JsonResponse({"error": "Already refunded"}, status=400)
    if op.status != ACQUIRING_PAID_STATUS:
        return JsonResponse({"error": "Refund is only available for paid operations"}, status=400)
    ext = (op.external_operation_id or "").strip()
    if not ext:
        return JsonResponse({"error": "Missing Tochka operation id"}, status=400)

    try:
        amt_full = float(op.amount)
    except (TypeError, ValueError):
        return JsonResponse({"error": "Invalid operation amount"}, status=400)

    if amount_raw is not None and amount_raw != "":
        try:
            amt = float(amount_raw)
        except (TypeError, ValueError):
            return JsonResponse({"error": "Invalid amount"}, status=400)
        if amt <= 0 or amt > amt_full:
            return JsonResponse({"error": "Invalid amount"}, status=400)
    else:
        amt = amt_full

    client, creds = _get_tochka_client(member_id)
    if not client or not creds or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        resp = client.refund_acquiring_payment(ext, amount=amt)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
    except Exception as e:
        err_str = str(e)
        if "403" in err_str and "consent" in err_str.lower():
            return JsonResponse(
                {
                    "error": "Нужно обновить разрешения Точки для возврата. Переподключите аккаунт.",
                    "code": "consent_upgrade_required",
                },
                status=403,
            )
        return JsonResponse({"error": err_str, "code": "tochka_error"}, status=400)

    op.status = ACQUIRING_REFUNDED
    op.save()

    return JsonResponse({"success": True, "raw": resp})
