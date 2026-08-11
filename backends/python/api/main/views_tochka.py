import json
import logging
from datetime import date
from decimal import Decimal, InvalidOperation
from typing import Optional, Tuple

from django.http import JsonResponse, HttpResponseRedirect, HttpResponse
from django.db.models import Q, Count, Sum
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST, require_http_methods
from django.views.decorators.clickjacking import xframe_options_exempt

from .utils.decorators import auth_required, log_errors
from .utils import AuthorizedRequest
from .ledger import (
    ACQUIRING_PAID_STATUS,
    ACQUIRING_REFUNDED,
    build_crm_entity_payments,
    normalize_bank_payment_status,
    serialize_acquiring_payment_row,
)
from .payment_notifications import enrich_bank_payment_rows_by_inn
from .bitrix_rest import (
    bitrix_rest_call,
    ensure_tochka_integration_chat_bot_id,
    im_chat_user_add,
    push_tochka_integration_chat_bot_avatar,
)
from .models import (
    Bitrix24Account,
    TochkaAcquiringOperation,
    TochkaCredentials,
    TochkaPayment,
    TochkaPortalPreferences,
)
from .tochka import TochkaClient
from .tochka_oauth_state import (
    InvalidOAuthState,
    create_oauth_state,
    read_oauth_state,
)
from .tochka_sync_logic import run_tochka_sync, _extract_invoice_number
from config import load_config

config = load_config()
_prefs_logger = logging.getLogger(__name__)


def _customer_display_name(customer: dict) -> str:
    for key in ("fullName", "FullName", "shortName", "ShortName", "name", "Name"):
        val = customer.get(key)
        if val and str(val).strip():
            return str(val).strip()
    return ""


def _list_tochka_customers(customer_payload: dict) -> list[dict]:
    data = customer_payload.get("Data") or customer_payload
    customers = data.get("Customer") or data.get("Customers") or []
    if isinstance(customers, dict):
        return [customers]
    return [c for c in customers if isinstance(c, dict)]


def _enrich_accounts_with_organization_names(accounts_payload: dict, customer_payload: dict) -> dict:
    """Подставляет название организации (fullName клиента) в description счёта."""
    customers = _list_tochka_customers(customer_payload)
    names_by_code: dict[str, str] = {}
    business_name = ""
    any_name = ""
    for c in customers:
        code = c.get("customerCode") or c.get("CustomerCode")
        name = _customer_display_name(c)
        if code and name:
            names_by_code[str(code)] = name
        ctype = c.get("customerType") or c.get("CustomerType")
        if ctype == "Business" and name:
            business_name = business_name or name
        if name:
            any_name = any_name or name

    default_name = business_name or any_name
    data = accounts_payload.setdefault("Data", {})
    accounts = data.get("Account") or []
    if isinstance(accounts, dict):
        accounts = [accounts]

    for acc in accounts:
        if not isinstance(acc, dict):
            continue
        existing = (acc.get("description") or acc.get("Description") or "").strip()
        if existing:
            acc["description"] = existing
            continue
        code = acc.get("customerCode") or acc.get("CustomerCode")
        name = names_by_code.get(str(code)) if code else None
        if not name:
            name = default_name
        if name:
            acc["description"] = name

    data["Account"] = accounts
    return accounts_payload


def _default_deal_matching_payload() -> dict:
    return {
        "enabled": True,
        "autoFindEach": False,
        "byInn": False,
        "byName": False,
        "byInvoiceInPurpose": True,
        "byAmount": True,
        "byPhonePayer": False,
        "autoWrite": True,
        "partial": False,
        "stagePaidId": "",
        "stagePaidCategoryId": -1,
        "moveOnPartial": False,
        "stagePartialId": "",
        "stagePartialCategoryId": -1,
        "perCategoryEnabled": False,
        "allowBackwardMove": False,
        "categoryRules": [],
        "innUf": "",
        "nameUf": "",
        "invoiceUf": "",
        "phoneUf": "",
    }


def _normalize_deal_matching(raw: Optional[dict]) -> dict:
    d = _default_deal_matching_payload()
    if isinstance(raw, dict):
        for key in d:
            if key in raw:
                d[key] = raw[key]
    d["enabled"] = bool(d.get("enabled", True))
    d["autoFindEach"] = bool(d.get("autoFindEach", False))
    d["byInn"] = bool(d.get("byInn", False))
    d["byName"] = bool(d.get("byName", False))
    d["byInvoiceInPurpose"] = bool(d.get("byInvoiceInPurpose", True))
    d["byAmount"] = bool(d.get("byAmount", True))
    d["byPhonePayer"] = bool(d.get("byPhonePayer", False))
    d["autoWrite"] = bool(d.get("autoWrite", True))
    d["partial"] = bool(d.get("partial", False))
    d["moveOnPartial"] = bool(d.get("moveOnPartial", False))
    d["stagePaidId"] = str(d.get("stagePaidId") or "")[:128]
    d["stagePartialId"] = str(d.get("stagePartialId") or "")[:128]
    try:
        d["stagePaidCategoryId"] = int(d.get("stagePaidCategoryId", -1))
    except (TypeError, ValueError):
        d["stagePaidCategoryId"] = -1
    try:
        d["stagePartialCategoryId"] = int(d.get("stagePartialCategoryId", -1))
    except (TypeError, ValueError):
        d["stagePartialCategoryId"] = -1
    if not d["stagePaidId"]:
        d["stagePaidCategoryId"] = -1
    if not d.get("moveOnPartial") and not d["stagePartialId"]:
        d["stagePartialCategoryId"] = -1
    d["perCategoryEnabled"] = bool(d.get("perCategoryEnabled", False))
    d["allowBackwardMove"] = bool(d.get("allowBackwardMove", False))
    raw_rules = d.get("categoryRules")
    rules: list = []
    if isinstance(raw_rules, list):
        for item in raw_rules[:32]:
            if not isinstance(item, dict):
                continue
            try:
                category_id = int(item.get("categoryId", 0))
            except (TypeError, ValueError):
                category_id = 0
            rules.append({
                "categoryId": category_id,
                "stagePaidId": str(item.get("stagePaidId") or "")[:128],
                "moveOnPartial": bool(item.get("moveOnPartial", False)),
                "stagePartialId": str(item.get("stagePartialId") or "")[:128],
                "allowBackwardMove": bool(item.get("allowBackwardMove", False)),
            })
    d["categoryRules"] = rules
    d["innUf"] = str(d.get("innUf") or "").strip()[:64]
    d["nameUf"] = str(d.get("nameUf") or "").strip()[:64]
    d["invoiceUf"] = str(d.get("invoiceUf") or "").strip()[:64]
    d["phoneUf"] = str(d.get("phoneUf") or "").strip()[:64]
    return d


def _try_invite_tochka_bot_to_chat(account: Bitrix24Account, prefs: TochkaPortalPreferences) -> None:
    """Добавить зарегистрированного бота приложения в выбранный чат уведомлений (im.chat.user.add)."""
    cid = prefs.notify_chat_id
    bid = prefs.notify_chat_bot_id
    if not cid or not bid:
        return
    r = im_chat_user_add(account, int(cid), [int(bid)], hide_history="N")
    if r.get("error"):
        _prefs_logger.warning(
            "im.chat.user.add (tochka bot): %s %s",
            r.get("error"),
            r.get("error_description"),
        )

_POPUP_CLOSE_HTML = """<!DOCTYPE html>
<html><head><title>Tochka</title></head>
<body style="display:none">
<script>
  if (window.opener) {
    window.opener.postMessage({ type: 'tochka_connected', tochkaAuth: 'ok' }, '*');
  }
  window.close();
</script>
</body></html>"""

_POPUP_ERROR_HTML = """<!DOCTYPE html>
<html><head><title>Tochka</title></head>
<body style="font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">
<p>Не удалось завершить авторизацию. Вернитесь в приложение и повторите попытку.</p>
<script>
  if (window.opener) {
    window.opener.postMessage({
      type: 'tochka_error',
      error: 'Не удалось завершить авторизацию в Точке'
    }, '*');
  }
  setTimeout(function(){window.close()},2000);
</script>
</body></html>"""


def _get_tochka_client(member_id: str) -> Tuple[TochkaClient, TochkaCredentials] | Tuple[None, None]:
    """Get TochkaClient for member_id if credentials exist"""
    try:
        creds = TochkaCredentials.objects.get(member_id=member_id)
        client = TochkaClient(
            client_id=creds.client_id,
            client_secret=creds.client_secret,
            access_token=creds.access_token,
            refresh_token=creds.refresh_token,
            token_expires_at=creds.token_expires_at,
            sandbox=config.tochka_sandbox,
        )
        return client, creds
    except TochkaCredentials.DoesNotExist:
        return None, None


def _get_redirect_uri() -> str:
    """Get OAuth callback redirect URI"""
    base_url = config.app_base_url.rstrip('/')
    return f"{base_url}/api/tochka/callback"


@xframe_options_exempt
@require_GET
@log_errors("tochka_status")
@auth_required
def tochka_status(request: AuthorizedRequest):
    """Get Tochka connection status"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if creds:
        prefs = TochkaPortalPreferences.objects.filter(member_id=member_id).first()
        last_sync_at = prefs.last_sync_at if prefs and prefs.last_sync_at else None
        return JsonResponse({
            "connected": creds.is_connected,
            "hasCredentials": True,
            "clientId": creds.client_id[:8] + "..." if creds.client_id else None,
            "bankPushConfigured": config.tochka_bank_push_configured,
            "scheduledSyncIntervalMinutes": config.tochka_scheduled_sync_interval_minutes,
            "clientAutoSyncEnabled": bool(prefs and prefs.client_auto_sync_enabled),
            "clientAutoSyncIntervalMinutes": prefs.client_auto_sync_interval_minutes if prefs else 15,
            "lastSyncAt": last_sync_at.isoformat().replace("+00:00", "Z") if last_sync_at else None,
        })

    return JsonResponse({
        "connected": False,
        "hasCredentials": False,
        "clientId": None,
        "bankPushConfigured": config.tochka_bank_push_configured,
        "scheduledSyncIntervalMinutes": config.tochka_scheduled_sync_interval_minutes,
    })


@xframe_options_exempt
@csrf_exempt
@require_http_methods(["GET", "POST"])
@log_errors("tochka_preferences")
@auth_required
def tochka_preferences(request: AuthorizedRequest):
    """Автообновление платежей в открытом интерфейсе (интервал задаёт пользователь)."""
    member_id = request.bitrix24_account.member_id

    if request.method == "GET":
        prefs, _ = TochkaPortalPreferences.objects.get_or_create(
            member_id=member_id,
            defaults={"client_auto_sync_interval_minutes": 15},
        )
        bot_just_registered = False
        if not prefs.notify_chat_bot_id:
            try:
                bid = ensure_tochka_integration_chat_bot_id(request.bitrix24_account)
                if bid:
                    prefs.notify_chat_bot_id = bid
                    prefs.save(update_fields=["notify_chat_bot_id"])
                    bot_just_registered = True
            except Exception:
                _prefs_logger.exception("ensure chat bot on preferences GET")
        if prefs.notify_chat_id and prefs.notify_chat_bot_id and bot_just_registered:
            try:
                _try_invite_tochka_bot_to_chat(request.bitrix24_account, prefs)
            except Exception:
                _prefs_logger.exception("invite tochka bot to chat on preferences GET")
        if prefs.notify_chat_bot_id:
            try:
                push_tochka_integration_chat_bot_avatar(
                    request.bitrix24_account,
                    int(prefs.notify_chat_bot_id),
                    member_id,
                    force=False,
                )
            except Exception:
                _prefs_logger.exception("push tochka bot avatar on preferences GET")
        return JsonResponse({
            "clientAutoSyncEnabled": prefs.client_auto_sync_enabled,
            "clientAutoSyncIntervalMinutes": prefs.client_auto_sync_interval_minutes,
            "lastSyncAt": prefs.last_sync_at.isoformat().replace("+00:00", "Z") if prefs.last_sync_at else None,
            "dealPaymentKindUfCode": prefs.deal_payment_kind_uf_code or "",
            "dealPaymentChannelUfCode": prefs.deal_payment_channel_uf_code or "",
            "leadPaymentKindUfCode": prefs.lead_payment_kind_uf_code or "",
            "leadPaymentChannelUfCode": prefs.lead_payment_channel_uf_code or "",
            "notifyDealResponsible": prefs.notify_deal_responsible,
            "notifyDealTimeline": prefs.notify_deal_timeline,
            "notifyChatId": prefs.notify_chat_id,
            "notifyChatBotId": prefs.notify_chat_bot_id,
            "invoiceAutoUpdateStage": prefs.invoice_auto_update_stage,
            "invoiceStageIdPaid": prefs.invoice_stage_id_paid or "",
            "invoiceStageIdPartial": prefs.invoice_stage_id_partial or "",
            "invoiceMatchByInn": prefs.invoice_match_by_inn,
            "invoiceMatchByAmount": prefs.invoice_match_by_amount,
            "dealMatching": _normalize_deal_matching(
                prefs.deal_matching if isinstance(prefs.deal_matching, dict) else {}
            ),
        })

    body = json.loads(request.body) if request.body else {}
    enabled = bool(body.get("clientAutoSyncEnabled"))
    try:
        minutes = int(body.get("clientAutoSyncIntervalMinutes", 15))
    except (TypeError, ValueError):
        minutes = 15
    minutes = max(3, min(minutes, 180))

    prefs, _ = TochkaPortalPreferences.objects.get_or_create(
        member_id=member_id,
        defaults={"client_auto_sync_interval_minutes": 15},
    )
    kind_uf = prefs.deal_payment_kind_uf_code or ""
    ch_uf = prefs.deal_payment_channel_uf_code or ""
    lead_kind_uf = prefs.lead_payment_kind_uf_code or ""
    lead_ch_uf = prefs.lead_payment_channel_uf_code or ""
    if "dealPaymentKindUfCode" in body:
        kind_uf = str(body.get("dealPaymentKindUfCode") or "").strip()[:64]
    if "dealPaymentChannelUfCode" in body:
        ch_uf = str(body.get("dealPaymentChannelUfCode") or "").strip()[:64]
    if "leadPaymentKindUfCode" in body:
        lead_kind_uf = str(body.get("leadPaymentKindUfCode") or "").strip()[:64]
    if "leadPaymentChannelUfCode" in body:
        lead_ch_uf = str(body.get("leadPaymentChannelUfCode") or "").strip()[:64]

    if "notifyDealResponsible" in body:
        prefs.notify_deal_responsible = bool(body["notifyDealResponsible"])
    if "notifyDealTimeline" in body:
        prefs.notify_deal_timeline = bool(body["notifyDealTimeline"])
    if "notifyChatId" in body:
        val = body["notifyChatId"]
        prefs.notify_chat_id = int(val) if val else None
    if "notifyChatBotId" in body:
        val = body["notifyChatBotId"]
        prefs.notify_chat_bot_id = int(val) if val else None

    if "invoiceAutoUpdateStage" in body:
        prefs.invoice_auto_update_stage = bool(body.get("invoiceAutoUpdateStage"))
    if "invoiceStageIdPaid" in body:
        prefs.invoice_stage_id_paid = str(body.get("invoiceStageIdPaid") or "").strip()[:128]
    if "invoiceStageIdPartial" in body:
        prefs.invoice_stage_id_partial = str(body.get("invoiceStageIdPartial") or "").strip()[:128]
    if "invoiceMatchByInn" in body:
        prefs.invoice_match_by_inn = bool(body.get("invoiceMatchByInn"))
    if "invoiceMatchByAmount" in body:
        prefs.invoice_match_by_amount = bool(body.get("invoiceMatchByAmount"))

    if "dealMatching" in body and isinstance(body.get("dealMatching"), dict):
        cur = _normalize_deal_matching(
            prefs.deal_matching if isinstance(prefs.deal_matching, dict) else {}
        )
        inc = body["dealMatching"]
        for key in cur:
            if key in inc:
                cur[key] = inc[key]
        prefs.deal_matching = _normalize_deal_matching(cur)

    prefs.client_auto_sync_enabled = enabled
    prefs.client_auto_sync_interval_minutes = minutes
    prefs.deal_payment_kind_uf_code = kind_uf
    prefs.deal_payment_channel_uf_code = ch_uf
    prefs.lead_payment_kind_uf_code = lead_kind_uf
    prefs.lead_payment_channel_uf_code = lead_ch_uf

    if prefs.notify_chat_id and not prefs.notify_chat_bot_id:
        nb = ensure_tochka_integration_chat_bot_id(request.bitrix24_account)
        if nb:
            prefs.notify_chat_bot_id = nb

    prefs.save()

    if (
        prefs.notify_chat_id
        and prefs.notify_chat_bot_id
        and "notifyChatId" in body
        and body.get("notifyChatId")
    ):
        try:
            _try_invite_tochka_bot_to_chat(request.bitrix24_account, prefs)
        except Exception:
            _prefs_logger.exception("invite tochka bot to chat on preferences POST")

    return JsonResponse({
        "clientAutoSyncEnabled": prefs.client_auto_sync_enabled,
        "clientAutoSyncIntervalMinutes": prefs.client_auto_sync_interval_minutes,
        "lastSyncAt": prefs.last_sync_at.isoformat().replace("+00:00", "Z") if prefs.last_sync_at else None,
        "dealPaymentKindUfCode": prefs.deal_payment_kind_uf_code or "",
        "dealPaymentChannelUfCode": prefs.deal_payment_channel_uf_code or "",
        "leadPaymentKindUfCode": prefs.lead_payment_kind_uf_code or "",
        "leadPaymentChannelUfCode": prefs.lead_payment_channel_uf_code or "",
        "notifyDealResponsible": prefs.notify_deal_responsible,
        "notifyDealTimeline": prefs.notify_deal_timeline,
        "notifyChatId": prefs.notify_chat_id,
        "notifyChatBotId": prefs.notify_chat_bot_id,
        "invoiceAutoUpdateStage": prefs.invoice_auto_update_stage,
        "invoiceStageIdPaid": prefs.invoice_stage_id_paid or "",
        "invoiceStageIdPartial": prefs.invoice_stage_id_partial or "",
        "invoiceMatchByInn": prefs.invoice_match_by_inn,
        "invoiceMatchByAmount": prefs.invoice_match_by_amount,
        "dealMatching": _normalize_deal_matching(
            prefs.deal_matching if isinstance(prefs.deal_matching, dict) else {}
        ),
    })


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_connect")
@auth_required
def tochka_connect(request: AuthorizedRequest):
    """Connect to Tochka with provided credentials"""
    member_id = request.bitrix24_account.member_id

    try:
        body = json.loads(request.body)
        client_id = body.get("clientId")
        client_secret = body.get("clientSecret")

        if not client_id or not client_secret:
            return JsonResponse({"error": "clientId and clientSecret required"}, status=400)

        client = TochkaClient(client_id=client_id, client_secret=client_secret)
        token_data = client.get_token()

        TochkaCredentials.objects.update_or_create(
            member_id=member_id,
            defaults={
                "client_id": client_id,
                "client_secret": client_secret,
                "access_token": token_data["access_token"],
                "token_expires_at": token_data["token_expires_at"],
                "is_connected": True,
            },
        )

        return JsonResponse({"success": True})

    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_connect_env")
@auth_required
def tochka_connect_env(request: AuthorizedRequest):
    """Start OAuth flow - returns authorization URL"""
    member_id = request.bitrix24_account.member_id

    client_id = getattr(config, 'tochka_client_id', None)
    client_secret = getattr(config, 'tochka_client_secret', None)

    if not client_id or not client_secret:
        return JsonResponse({"error": "TOCHKA_CLIENT_ID and TOCHKA_CLIENT_SECRET not configured"}, status=400)

    try:
        body = json.loads(request.body) if request.body else {}
        is_popup = body.get("popup", False)

        state = create_oauth_state(member_id, popup=is_popup)

        # Save credentials without token (will be filled after callback)
        TochkaCredentials.objects.update_or_create(
            member_id=member_id,
            defaults={
                "client_id": client_id,
                "client_secret": client_secret,
                "is_connected": False,
            },
        )

        # Create consent and generate authorization URL
        client = TochkaClient(client_id=client_id, client_secret=client_secret)
        consent_id = client.create_consent()
        redirect_uri = _get_redirect_uri()
        auth_url = client.get_authorization_url(redirect_uri, state, consent_id)

        return JsonResponse({
            "success": True,
            "authUrl": auth_url,
        })

    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@require_GET
@log_errors("tochka_callback")
def tochka_callback(request):
    """OAuth callback - exchange code for token"""
    code = request.GET.get("code")
    state = request.GET.get("state")
    error = request.GET.get("error")
    error_description = request.GET.get("error_description")

    # Check for OAuth errors
    if error:
        return HttpResponseRedirect(f"{config.app_base_url}/?tochka_error={error_description or error}")

    if not code or not state:
        return HttpResponseRedirect(f"{config.app_base_url}/?tochka_error=missing_code_or_state")

    try:
        state_data = read_oauth_state(state)
        credentials = TochkaCredentials.objects.get(member_id=state_data.member_id)
    except (InvalidOAuthState, TochkaCredentials.DoesNotExist):
        return HttpResponseRedirect(f"{config.app_base_url}/?tochka_error=invalid_state")

    member_id = state_data.member_id
    client_id = credentials.client_id
    client_secret = credentials.client_secret

    try:
        # Exchange code for token
        client = TochkaClient(client_id=client_id, client_secret=client_secret)
        redirect_uri = _get_redirect_uri()
        token_data = client.exchange_code_for_token(code, redirect_uri)

        # Save tokens
        TochkaCredentials.objects.update_or_create(
            member_id=member_id,
            defaults={
                "client_id": client_id,
                "client_secret": client_secret,
                "access_token": token_data["access_token"],
                "refresh_token": token_data.get("refresh_token"),
                "token_expires_at": token_data["token_expires_at"],
                "is_connected": True,
            },
        )

        if state_data.popup:
            return HttpResponse(_POPUP_CLOSE_HTML, content_type="text/html")
        return HttpResponseRedirect(f"{config.app_base_url}/?tochka_connected=true")

    except Exception as e:
        if state_data.popup:
            _prefs_logger.exception("Tochka OAuth token exchange failed")
            return HttpResponse(_POPUP_ERROR_HTML, content_type="text/html")
        return HttpResponseRedirect(f"{config.app_base_url}/?tochka_error={str(e)}")


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_disconnect")
@auth_required
def tochka_disconnect(request: AuthorizedRequest):
    """Disconnect from Tochka"""
    member_id = request.bitrix24_account.member_id

    try:
        creds = TochkaCredentials.objects.get(member_id=member_id)
        creds.is_connected = False
        creds.access_token = None
        creds.save()
        return JsonResponse({"success": True})
    except TochkaCredentials.DoesNotExist:
        return JsonResponse({"success": True})


@xframe_options_exempt
@require_GET
@log_errors("tochka_accounts")
@auth_required
def tochka_accounts(request: AuthorizedRequest):
    """Get Tochka accounts"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        data = client.get_accounts()
        try:
            customer_data = client.get_customer()
            creds.access_token = client.access_token
            creds.token_expires_at = client.token_expires_at
            data = _enrich_accounts_with_organization_names(data, customer_data)
        except Exception as e:
            _prefs_logger.warning("tochka_accounts: customer lookup failed: %s", e)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        error_msg = str(e)
        # If token refresh failed, mark as disconnected
        if "Re-authorization required" in error_msg or "Token refresh failed" in error_msg:
            creds.is_connected = False
            creds.access_token = None
            creds.refresh_token = None
            creds.save()
            return JsonResponse({"error": "Token expired. Please reconnect to Tochka.", "needReconnect": True}, status=401)
        return JsonResponse({"error": error_msg}, status=400)


@xframe_options_exempt
@require_GET
@log_errors("tochka_balances")
@auth_required
def tochka_balances(request: AuthorizedRequest, account_id: str):
    """Get account balances"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        data = client.get_balances(account_id)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_init_statement")
@auth_required
def tochka_init_statement(request: AuthorizedRequest, account_id: str):
    """Create statement request"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        body = json.loads(request.body)
        start_date = body.get("startDate")
        end_date = body.get("endDate")

        data = client.create_statement(account_id, start_date, end_date)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@require_GET
@log_errors("tochka_get_statement")
@auth_required
def tochka_get_statement(request: AuthorizedRequest, statement_id: str):
    """Get statement by ID"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        data = client.get_statement(statement_id)
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


@xframe_options_exempt
@require_GET
@log_errors("tochka_customer")
@auth_required
def tochka_customer(request: AuthorizedRequest):
    """Get customer info"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        data = client.get_customer()
        creds.access_token = client.access_token
        creds.token_expires_at = client.token_expires_at
        creds.save()
        return JsonResponse(data)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)


def _run_acquiring_dedupe_after_sync(member_id: str) -> None:
    """After statement sync, match paid acquiring ops with newly arrived bank payments."""
    from .views_acquiring import _dedupe_bank_payment_for_acquiring
    paid_unlinked = TochkaAcquiringOperation.objects.filter(
        member_id=member_id,
        status=ACQUIRING_PAID_STATUS,
        duplicate_bank_payment_id__isnull=True,
    ).filter(Q(deal_id__isnull=False) | Q(lead_id__isnull=False))
    for op in paid_unlinked:
        try:
            _dedupe_bank_payment_for_acquiring(op)
        except Exception as e:
            print(f"[SYNC_DEDUPE] Error for op {op.id}: {e}")


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_sync")
@auth_required
def tochka_sync(request: AuthorizedRequest):
    """Sync payments from Tochka — fetch statements and save transactions"""
    member_id = request.bitrix24_account.member_id
    client, creds = _get_tochka_client(member_id)

    if not client or not creds.is_connected:
        return JsonResponse({"error": "Not connected to Tochka"}, status=400)

    try:
        body = json.loads(request.body) if request.body else {}
        start_raw = body.get("startDate")
        end_raw = body.get("endDate")

        if start_raw and end_raw:
            result = run_tochka_sync(
                member_id,
                start_date_calendar=str(start_raw),
                end_date_calendar=str(end_raw),
            )
        else:
            try:
                days = int(body.get("days", 30))
            except (TypeError, ValueError):
                days = 30
            days = max(days, 1)
            result = run_tochka_sync(member_id, days=days)

        _run_acquiring_dedupe_after_sync(member_id)
        try:
            from .invoice_crm import try_auto_match_invoices_for_member
            try_auto_match_invoices_for_member(member_id)
        except Exception as e:
            print(f"[INVOICE_AUTO_MATCH] {e}")

        try:
            from .payment_notifications import notify_payment_unmatched_new

            for pid in result.get("new_payment_ids") or []:
                p = TochkaPayment.objects.filter(pk=pid, member_id=member_id).first()
                if p and p.status == "pending" and p.credit_debit == "credit":
                    notify_payment_unmatched_new(member_id, p)
        except Exception as e:
            print(f"[PAYMENT_NOTIFY] {e}")

        return JsonResponse(result)

    except ValueError as e:
        return JsonResponse({"error": str(e)}, status=400)

    except Exception as e:
        error_msg = str(e)
        # If token refresh failed, mark as disconnected
        if "Re-authorization required" in error_msg or "Token refresh failed" in error_msg:
            creds.is_connected = False
            creds.access_token = None
            creds.refresh_token = None
            creds.save()
            return JsonResponse({"error": "Token expired. Please reconnect to Tochka.", "needReconnect": True}, status=401)
        return JsonResponse({"error": error_msg}, status=400)


@xframe_options_exempt
@require_GET
@log_errors("tochka_crm_entity_payments")
@auth_required
def tochka_crm_entity_payments(request: AuthorizedRequest):
    """Ledger: bank (выписка) payments attached to a CRM deal or lead."""
    member_id = request.bitrix24_account.member_id
    deal_id = request.GET.get("deal_id")
    lead_id = request.GET.get("lead_id")

    has_deal = deal_id not in (None, "")
    has_lead = lead_id not in (None, "")
    if has_deal == has_lead:
        return JsonResponse({"error": "Specify exactly one of deal_id, lead_id"}, status=400)

    try:
        did = int(deal_id) if has_deal else None
        lid = int(lead_id) if has_lead else None
    except (TypeError, ValueError):
        return JsonResponse({"error": "Invalid deal_id or lead_id"}, status=400)
    rows, sum_dec = build_crm_entity_payments(member_id, deal_id=did, lead_id=lid)
    sum_paid = float(sum_dec)

    entity_amount_raw = request.GET.get("entity_amount")
    entity_amount = None
    if entity_amount_raw not in (None, ""):
        try:
            entity_amount = float(entity_amount_raw)
        except (TypeError, ValueError):
            pass

    if entity_amount is None and has_deal:
        for p in rows:
            da = p.get("deal_amount")
            if da is not None:
                entity_amount = da
                break

    currency_id = request.GET.get("currency_id") or "RUB"

    remaining = None
    overpayment = None
    if entity_amount is not None:
        remaining = max(Decimal(0), Decimal(str(entity_amount)) - Decimal(str(sum_paid)))
        overpayment = max(Decimal(0), Decimal(str(sum_paid)) - Decimal(str(entity_amount)))
        remaining = float(remaining)
        overpayment = float(overpayment)

    return JsonResponse({
        "payments": rows,
        "sumPaid": sum_paid,
        "entityAmount": entity_amount,
        "currencyId": currency_id,
        "remaining": remaining,
        "overpayment": overpayment,
    })


def _parse_iso_date(raw: str) -> date | None:
    raw = (raw or "").strip()
    if not raw:
        return None
    try:
        return date.fromisoformat(raw)
    except ValueError:
        return None


def _parse_payment_amount(raw: str) -> Decimal | None:
    raw = (raw or "").strip().replace(" ", "").replace(",", ".")
    if not raw:
        return None
    try:
        return Decimal(raw)
    except InvalidOperation:
        return None


def _apply_bank_payment_list_filters(
    qs,
    *,
    search: str = "",
    amount: str = "",
    date_from: str = "",
    date_to: str = "",
    counterparty: str = "",
):
    counterparty = (counterparty or "").strip()
    if counterparty:
        qs = qs.filter(counterparty_name__icontains=counterparty)

    parsed_amount = _parse_payment_amount(amount)
    if parsed_amount is not None:
        qs = qs.filter(amount=parsed_amount)

    d_from = _parse_iso_date(date_from)
    if d_from:
        qs = qs.filter(transaction_date__date__gte=d_from)

    d_to = _parse_iso_date(date_to)
    if d_to:
        qs = qs.filter(transaction_date__date__lte=d_to)

    search = (search or "").strip()
    if search:
        text_q = (
            Q(purpose__icontains=search)
            | Q(counterparty_inn__icontains=search)
            | Q(invoice_number__icontains=search)
            | Q(payment_slip_number__icontains=search)
        )
        if not counterparty:
            text_q |= Q(counterparty_name__icontains=search)
        qs = qs.filter(text_q)

    return qs


def _apply_acquiring_payment_list_filters(
    acq_qs,
    *,
    search: str = "",
    amount: str = "",
    date_from: str = "",
    date_to: str = "",
    counterparty: str = "",
):
    parsed_amount = _parse_payment_amount(amount)
    if parsed_amount is not None:
        acq_qs = acq_qs.filter(amount=parsed_amount)

    d_from = _parse_iso_date(date_from)
    if d_from:
        acq_qs = acq_qs.filter(updated_at__date__gte=d_from)

    d_to = _parse_iso_date(date_to)
    if d_to:
        acq_qs = acq_qs.filter(updated_at__date__lte=d_to)

    search = (search or "").strip()
    if search:
        acq_qs = acq_qs.filter(purpose__icontains=search)

    return acq_qs


@xframe_options_exempt
@require_GET
@log_errors("tochka_payments")
@auth_required
def tochka_payments(request: AuthorizedRequest):
    """Get paginated list of payments with filters"""
    member_id = request.bitrix24_account.member_id

    page = int(request.GET.get("page", 1))
    per_page = int(request.GET.get("per_page", 50))
    status_filter = request.GET.get("status", "all")
    search = request.GET.get("search", "").strip()
    amount = request.GET.get("amount", "").strip()
    date_from = request.GET.get("date_from", "").strip()
    date_to = request.GET.get("date_to", "").strip()
    counterparty = request.GET.get("counterparty", "").strip()

    list_filter_kwargs = {
        "search": search,
        "amount": amount,
        "date_from": date_from,
        "date_to": date_to,
        "counterparty": counterparty,
    }

    # Only show incoming payments (credit). Скрываем строки, «поглощённые» дедупликацией с эквайрингом
    # (ledger_exclude=True — иначе платёж пропадал из списка вместе с исключённым эквайрингом).
    qs = TochkaPayment.objects.filter(
        member_id=member_id, credit_debit="credit", ledger_exclude=False
    ).order_by("-transaction_date")

    if status_filter and status_filter != "all":
        qs = qs.filter(status=status_filter)

    qs = _apply_bank_payment_list_filters(qs, **list_filter_kwargs)

    total = qs.count()

    # Filter counts (only credit payments, без скрытых дублей эквайринга)
    all_qs = TochkaPayment.objects.filter(
        member_id=member_id, credit_debit="credit", ledger_exclude=False
    )
    counts = dict(all_qs.values_list("status").annotate(c=Count("id")).values_list("status", "c"))

    total_amount = qs.filter(credit_debit="credit").aggregate(s=Sum("amount"))["s"] or 0

    offset = (page - 1) * per_page
    payments = list(
        qs[offset: offset + per_page].values(
            "id", "account_id", "transaction_id", "payment_slip_number", "amount", "amount_nat",
            "currency", "credit_debit", "counterparty_name", "counterparty_inn",
            "counterparty_account", "counterparty_bik", "purpose", "invoice_number",
            "vat_type", "transaction_date", "status", "matched_deal_id", "matched_lead_id", "matched_invoice_id",
            "deal_title", "deal_amount", "deal_currency", "deal_responsible_id",
            "deal_responsible_name", "deal_responsible_photo", "deal_paid_amount", "deal_paid_percent",
            "invoice_title", "invoice_amount", "invoice_currency", "invoice_account_number",
            "invoice_responsible_id", "invoice_responsible_name", "invoice_responsible_photo",
            "invoice_paid_amount", "invoice_paid_percent",
        )
    )

    for p in payments:
        p["id"] = str(p["id"])
        p["amount"] = float(p["amount"]) if p["amount"] else 0
        p["amount_nat"] = float(p["amount_nat"]) if p["amount_nat"] else None
        p["deal_amount"] = float(p["deal_amount"]) if p["deal_amount"] else None
        p["deal_paid_amount"] = float(p["deal_paid_amount"]) if p["deal_paid_amount"] else 0
        p["invoice_amount"] = float(p["invoice_amount"]) if p["invoice_amount"] else None
        p["invoice_paid_amount"] = float(p["invoice_paid_amount"]) if p["invoice_paid_amount"] else 0
        if p["transaction_date"]:
            p["transaction_date"] = p["transaction_date"].isoformat()
        normalize_bank_payment_status(p)
        p["payment_source"] = "bank"
        if not p.get("invoice_number") and p.get("purpose"):
            inv = _extract_invoice_number(str(p["purpose"]))
            if inv:
                p["invoice_number"] = inv

    _portal = Bitrix24Account.objects.filter(member_id=member_id).first()
    enrich_bank_payment_rows_by_inn(_portal, payments)

    # Оплаты по ссылке без строки в выписке (пока банк не прислал тот же платёж)
    acquiring_only: list[dict] = []
    acquiring_count = 0
    acq_sum_all = Decimal("0")
    show_acquiring = status_filter in ("all", "applied")
    if show_acquiring:
        from .views_acquiring import _fetch_crm_entity_info

        # Оплаченные по ссылке + возвращённые (статус refunded), чтобы строка не пропадала из списка.
        acq_qs = (
            TochkaAcquiringOperation.objects.filter(member_id=member_id)
            .filter(Q(status=ACQUIRING_PAID_STATUS) | Q(status=ACQUIRING_REFUNDED))
            .order_by("-updated_at")
        )
        acq_qs = _apply_acquiring_payment_list_filters(acq_qs, **list_filter_kwargs)
        acquiring_count = acq_qs.count()
        acq_paid_for_chips = TochkaAcquiringOperation.objects.filter(
            member_id=member_id,
            status=ACQUIRING_PAID_STATUS,
        ).count()
        acq_sum_all = (
            TochkaAcquiringOperation.objects.filter(member_id=member_id, status=ACQUIRING_PAID_STATUS).aggregate(
                s=Sum("amount")
            )["s"]
            or Decimal("0")
        )
        if page == 1:
            _crm_info_cache: dict[tuple[int | None, int | None], dict] = {}

            def _cached_crm_info(did: int | None, lid: int | None) -> dict:
                key = (did, lid)
                if key not in _crm_info_cache:
                    _crm_info_cache[key] = _fetch_crm_entity_info(member_id, did, lid)
                return _crm_info_cache[key]

            for op in acq_qs[:300]:
                info = _cached_crm_info(op.deal_id, op.lead_id)
                acquiring_only.append(serialize_acquiring_payment_row(op, info))

            if counterparty:
                cp = counterparty.casefold()
                acquiring_only = [
                    row
                    for row in acquiring_only
                    if cp in (row.get("counterparty_name") or "").casefold()
                    or cp in (row.get("purpose") or "").casefold()
                ]

    return JsonResponse({
        "payments": payments,
        "acquiring_payments": acquiring_only,
        "acquiring_count": acquiring_count,
        "total": total + (acquiring_count if show_acquiring else 0),
        "page": page,
        "per_page": per_page,
        "total_amount": float(total_amount) + float(acq_sum_all),
        "counts": {
            "all": sum(counts.values()) + (acquiring_count if show_acquiring else 0),
            "pending": counts.get("pending", 0),
            "matched": counts.get("matched", 0),
            "applied": counts.get("applied", 0) + (acq_paid_for_chips if show_acquiring else 0),
            "unprocessed": counts.get("unprocessed", 0),
            "error": counts.get("error", 0),
            "ignored": counts.get("ignored", 0),
        },
    })


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("tochka_payment_action")
@auth_required
def tochka_payment_action(request: AuthorizedRequest, payment_id: str):
    """Update payment status (ignore, etc)"""
    member_id = request.bitrix24_account.member_id

    try:
        body = json.loads(request.body)
        action = body.get("action")

        payment = TochkaPayment.objects.get(id=payment_id, member_id=member_id)

        print(f"Payment action: {action}, body: {body}")
        prev_unbind_responsible_id: int | None = None
        prev_deal_id: int | None = None
        prev_lead_id: int | None = None
        prev_invoice_id: int | None = None

        if action == "ignore":
            payment.status = "ignored"
        elif action == "pending":
            payment.status = "pending"
        elif action == "bind":
            deal_id = body.get("dealId")
            if not deal_id:
                return JsonResponse({"error": "dealId required"}, status=400)
            
            # Get deal info from request (frontend fetches from Bitrix24)
            deal_info = body.get("dealInfo", {})
            print(f"Deal info received: {deal_info}")
            payment.matched_deal_id = int(deal_id)
            payment.matched_lead_id = None
            payment.deal_title = deal_info.get("title", "")
            payment.deal_amount = float(deal_info.get("amount", 0)) if deal_info.get("amount") else None
            payment.deal_currency = deal_info.get("currency", "RUB")
            payment.deal_responsible_id = int(deal_info.get("responsibleId")) if deal_info.get("responsibleId") else None
            payment.deal_responsible_name = deal_info.get("responsibleName", "")
            payment.deal_responsible_photo = deal_info.get("responsiblePhoto", "")
            
            # Calculate paid amount and percent
            deal_amount = float(deal_info.get("amount", 0)) if deal_info.get("amount") else 0
            payment_amount = float(payment.amount)
            payment.deal_paid_amount = payment_amount
            payment.deal_paid_percent = min(100, int((payment_amount / deal_amount * 100) if deal_amount > 0 else 100))
            payment.status = "applied"
        elif action == "unbind":
            prev_deal_id = payment.matched_deal_id
            prev_unbind_responsible_id = payment.deal_responsible_id
            payment.matched_deal_id = None
            payment.deal_title = None
            payment.deal_amount = None
            payment.deal_currency = None
            payment.deal_responsible_id = None
            payment.deal_responsible_name = None
            payment.deal_responsible_photo = None
            payment.deal_paid_amount = 0
            payment.deal_paid_percent = 0
            payment.status = "pending"
        elif action == "bind_invoice":
            invoice_id = body.get("invoiceId")
            if not invoice_id:
                return JsonResponse({"error": "invoiceId required"}, status=400)
            
            # Get invoice info from request (frontend fetches from Bitrix24)
            invoice_info = body.get("invoiceInfo", {})
            print(f"Invoice info received: {invoice_info}")
            payment.matched_invoice_id = int(invoice_id)
            payment.matched_lead_id = None
            payment.invoice_title = invoice_info.get("title", "")
            payment.invoice_amount = float(invoice_info.get("amount", 0)) if invoice_info.get("amount") else None
            payment.invoice_currency = invoice_info.get("currency", "RUB")
            payment.invoice_account_number = invoice_info.get("accountNumber", "")
            payment.invoice_responsible_id = int(invoice_info.get("responsibleId")) if invoice_info.get("responsibleId") else None
            payment.invoice_responsible_name = invoice_info.get("responsibleName", "")
            payment.invoice_responsible_photo = invoice_info.get("responsiblePhoto", "")
            
            # Calculate paid amount and percent
            invoice_amount = float(invoice_info.get("amount", 0)) if invoice_info.get("amount") else 0
            payment_amount = float(payment.amount)
            payment.invoice_paid_amount = payment_amount
            payment.invoice_paid_percent = min(100, int((payment_amount / invoice_amount * 100) if invoice_amount > 0 else 100))
            payment.status = "matched"
        elif action == "unbind_invoice":
            prev_invoice_id = payment.matched_invoice_id
            prev_unbind_responsible_id = payment.invoice_responsible_id
            payment.matched_invoice_id = None
            payment.invoice_title = None
            payment.invoice_amount = None
            payment.invoice_currency = None
            payment.invoice_account_number = None
            payment.invoice_responsible_id = None
            payment.invoice_responsible_name = None
            payment.invoice_responsible_photo = None
            payment.invoice_paid_amount = 0
            payment.invoice_paid_percent = 0
            payment.status = "pending"
        elif action == "bind_lead":
            lid = body.get("leadId")
            if not lid:
                return JsonResponse({"error": "leadId required"}, status=400)
            payment.matched_lead_id = int(lid)
            payment.matched_deal_id = None
            payment.matched_invoice_id = None
            payment.deal_title = None
            payment.deal_amount = None
            payment.deal_currency = None
            payment.deal_responsible_id = None
            payment.deal_responsible_name = None
            payment.deal_responsible_photo = None
            payment.deal_paid_amount = 0
            payment.deal_paid_percent = 0
            payment.invoice_title = None
            payment.invoice_amount = None
            payment.invoice_currency = None
            payment.invoice_account_number = None
            payment.invoice_responsible_id = None
            payment.invoice_responsible_name = None
            payment.invoice_responsible_photo = None
            payment.invoice_paid_amount = 0
            payment.invoice_paid_percent = 0
            payment.status = "matched"
        elif action == "unbind_lead":
            prev_lead_id = payment.matched_lead_id
            lid = payment.matched_lead_id
            if lid:
                acc = Bitrix24Account.objects.filter(member_id=member_id).first()
                if acc:
                    r = bitrix_rest_call(acc, "crm.lead.get", {"id": int(lid)})
                    aid = (r.get("result") or {}).get("ASSIGNED_BY_ID")
                    if aid:
                        try:
                            prev_unbind_responsible_id = int(aid)
                        except (TypeError, ValueError):
                            prev_unbind_responsible_id = None
            payment.matched_lead_id = None
            payment.status = "pending"
        else:
            return JsonResponse({"error": "Unknown action"}, status=400)

        payment.save()
        if action == "bind_invoice" and payment.matched_invoice_id:
            from .invoice_crm import maybe_update_invoice_stage_for_invoice
            maybe_update_invoice_stage_for_invoice(member_id, int(payment.matched_invoice_id))

        try:
            from .payment_notifications import (
                clear_notify_logs_for_payment,
                notify_payment_matched,
                notify_payment_unbound,
            )

            if action in ("bind", "bind_invoice", "bind_lead") and payment.status in (
                "matched",
                "applied",
            ):
                notify_payment_matched(member_id, payment)
            elif action in ("unbind", "unbind_invoice", "unbind_lead"):
                clear_notify_logs_for_payment(payment.id)
                unbound_from = (
                    "deal"
                    if action == "unbind"
                    else ("invoice" if action == "unbind_invoice" else "lead")
                )
                notify_payment_unbound(
                    member_id,
                    payment,
                    previous_responsible_user_id=prev_unbind_responsible_id,
                    unbound_from=unbound_from,
                    previous_deal_id=int(prev_deal_id) if prev_deal_id else None,
                    previous_lead_id=int(prev_lead_id) if prev_lead_id else None,
                    previous_invoice_id=int(prev_invoice_id) if prev_invoice_id else None,
                )
        except Exception as e:
            print(f"[PAYMENT_NOTIFY] {e}")

        return JsonResponse({"success": True})
    except TochkaPayment.DoesNotExist:
        return JsonResponse({"error": "Payment not found"}, status=404)
    except Exception as e:
        return JsonResponse({"error": str(e)}, status=400)
