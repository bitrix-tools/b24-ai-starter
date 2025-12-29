from django.http import JsonResponse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST
from django.views.decorators.clickjacking import xframe_options_exempt

from bitrix_auth.utils.decorators import auth_required
from .utils import AuthorizedRequest
from bitrix_auth.models import ApplicationInstallation

__all__ = [
    "root",
    "health",
    "get_enum",
    "get_list",
    "install",
    "get_token",
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

    ApplicationInstallation.objects.update_or_create(
        bitrix_24_account=bitrix24_account,
        defaults={
            "status": bitrix24_account.status,
            "portal_license_family": "",
            "application_token": bitrix24_account.application_token,
        },
    )

    return JsonResponse({"message": "Installation successful"})


@xframe_options_exempt
@csrf_exempt
@require_POST
@auth_required
def get_token(request: AuthorizedRequest):
    return JsonResponse({"token": request.bitrix24_account.create_jwt_token()})
