from django.conf import settings
from django.http import JsonResponse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST
from django.views.decorators.clickjacking import xframe_options_exempt
from http import HTTPStatus

from b24pysdk.error import BitrixAPIError

from bitrix_auth.utils.decorators import auth_required, event_required
from bitrix_auth.utils.functions import ensure_onappuninstall_subscription
from bitrix_auth.utils.types import EventRequest
from .utils import AuthorizedRequest
from bitrix_auth.models import ApplicationInstallation, Bitrix24Account
from config import config

__all__ = [
    "root",
    "health",
    "get_enum",
    "get_list",
    "install",
    "get_token",
    "on_app_uninstall",
]


@xframe_options_exempt
@require_GET
@auth_required
def root(request: AuthorizedRequest):
    return JsonResponse({"message": "Python Backend is running"})


@xframe_options_exempt
@require_GET
@auth_required
def health(request: AuthorizedRequest):
    return JsonResponse({
        "status": "healthy",
        "backend": "python",
        "timestamp": timezone.now().timestamp(),
    })


@xframe_options_exempt
@require_GET
@auth_required
def get_enum(request: AuthorizedRequest):
    options = ["option 1", "option 2", "option 3"]
    return JsonResponse(options, safe=False)


@xframe_options_exempt
@require_GET
@auth_required
def get_list(request: AuthorizedRequest):
    elements = ["element 1", "element 2", "element 3"]
    return JsonResponse(elements, safe=False)


@xframe_options_exempt
@csrf_exempt
@require_POST
@auth_required
def install(request: AuthorizedRequest):
    bitrix24_account = request.bitrix24_account

    ApplicationInstallation.objects.create(
        bitrix_24_account=bitrix24_account,
        defaults={
            "status": bitrix24_account.status,
            "portal_license_family": "",
            "application_token": bitrix24_account.application_token,
        },
    )

    handler_url = f"{config.app_base_url.rstrip('/')}/api/events/onappuninstall"

    ensure_onappuninstall_subscription(bitrix24_account, handler_url)

    return JsonResponse({"message": "Installation successful"})


@xframe_options_exempt
@csrf_exempt
@require_POST
@auth_required
def get_token(request: AuthorizedRequest):
    return JsonResponse({"token": request.bitrix24_account.create_jwt_token()})


@xframe_options_exempt
@csrf_exempt
@require_POST
@event_required(validate=True)
def on_app_uninstall(request: EventRequest):
    try:
        installation = ApplicationInstallation.objects.get(bitrix_24_account__member_id=request.oauth_event_data.auth.member_id)

        installation.status = ApplicationInstallation.STATUS_DELETED
        installation.save(update_fields=["status"])

        bitrix_account = installation.bitrix_24_account
        bitrix_account.status = Bitrix24Account.STATUS_DELETED
        bitrix_account.save(update_fields=["status"])

    except ApplicationInstallation.DoesNotExist:
        return JsonResponse({"error": "Installation not found"}, status=HTTPStatus.NOT_FOUND)

    return JsonResponse({"message": "Application uninstalled"})
