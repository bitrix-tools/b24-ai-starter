import logging

from django.http import JsonResponse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST
from django.views.decorators.clickjacking import xframe_options_exempt

from .utils.decorators import auth_required, log_errors
from .utils import AuthorizedRequest
from .bitrix_rest import ensure_tochka_integration_chat_bot_id
from .models import ApplicationInstallation, TochkaPortalPreferences

from config import load_config

__all__ = [
    "root",
    "health",
    "get_enum",
    "get_list",
    "install",
    "get_token",
]

config = load_config()
logger = logging.getLogger(__name__)


@xframe_options_exempt
@require_GET
@log_errors("root")
@auth_required
def root(request: AuthorizedRequest):
    return JsonResponse({"message": "Python Backend is running"})


@xframe_options_exempt
@require_GET
@log_errors("health")
@auth_required
def health(request: AuthorizedRequest):
    return JsonResponse({
        "status": "healthy",
        "backend": "python",
        "timestamp": timezone.now().timestamp(),
    })


@xframe_options_exempt
@require_GET
@log_errors("get_enum")
@auth_required
def get_enum(request: AuthorizedRequest):
    options = ["option 1", "option 2", "option 3"]
    return JsonResponse(options, safe=False)


@xframe_options_exempt
@require_GET
@log_errors("get_list")
@auth_required
def get_list(request: AuthorizedRequest):
    elements = ["element 1", "element 2", "element 3"]
    return JsonResponse(elements, safe=False)


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("install")
@auth_required
def install(request: AuthorizedRequest):
    bitrix24_account = request.bitrix24_account

    ApplicationInstallation.objects.update_or_create(
        bitrix_24_account=bitrix24_account,
        defaults={
            "status": bitrix24_account.status,
            "portal_license_family": "",
            "application_token": bitrix24_account.application_token,
        },
    )

    try:
        prefs, _ = TochkaPortalPreferences.objects.get_or_create(
            member_id=bitrix24_account.member_id,
            defaults={
                "client_auto_sync_interval_minutes": 15,
                "client_auto_sync_enabled": True,
            },
        )
        if not prefs.notify_chat_bot_id:
            bid = ensure_tochka_integration_chat_bot_id(bitrix24_account)
            if bid:
                prefs.notify_chat_bot_id = bid
                prefs.save(update_fields=["notify_chat_bot_id"])
            else:
                logger.warning(
                    "imbot.v2.Bot.register did not return bot id (add imbot scope / reinstall app)"
                )
    except Exception:
        logger.exception("ensure chat bot on install")

    return JsonResponse({"message": "Installation successful"})


@xframe_options_exempt
@csrf_exempt
@require_POST
@log_errors("get_token")
@auth_required
def get_token(request: AuthorizedRequest):
    return JsonResponse({"token": request.bitrix24_account.create_jwt_token()})
