from http import HTTPStatus

from django.http import JsonResponse
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_POST
from django.views.decorators.clickjacking import xframe_options_exempt

from bitrix_auth.decorators.auth_required import auth_required
from bitrix_auth.types import AuthorizedRequest
from config import config

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
    """Return a basic authenticated backend availability response."""
    return JsonResponse({"message": "Python Backend is running"})


@xframe_options_exempt
@require_GET
def health(request):
    """Public liveness probe (same in every backend): no auth, no sensitive data."""
    return JsonResponse({
        "status": "healthy",
        "backend": "python",
        "timestamp": timezone.now().timestamp(),
    })


@xframe_options_exempt
@require_GET
@auth_required
def get_enum(request: AuthorizedRequest):
    """Return sample enum options for the starter frontend."""
    options = ["option 1", "option 2", "option 3"]
    return JsonResponse(options, safe=False)


@xframe_options_exempt
@require_GET
@auth_required
def get_list(request: AuthorizedRequest):
    """Return sample list items for the starter frontend."""
    elements = ["element 1", "element 2", "element 3"]
    return JsonResponse(elements, safe=False)


@xframe_options_exempt
@csrf_exempt
@require_POST
@auth_required
def get_token(request: AuthorizedRequest):
    """Issue a backend JWT for the authenticated Bitrix24 account."""
    return JsonResponse({"token": request.bitrix24_account.create_jwt_token()})


@xframe_options_exempt
@csrf_exempt
@require_POST
@auth_required
def install(request: AuthorizedRequest):
    """Register lifecycle event handlers during Bitrix24 app installation."""

    # Installation must come with fresh Bitrix24 auth data from the install page,
    # validated by auth_required — never with a previously issued JWT.
    if request.headers.get("Authorization", "").lower().startswith("bearer "):
        return JsonResponse({"error": "Installation expects Bitrix24 auth data, not a JWT"}, status=HTTPStatus.BAD_REQUEST)

    bitrix24_account = request.bitrix24_account

    # Bitrix24 lets only portal administrators install applications.
    if not bitrix24_account.is_b24_user_admin:
        return JsonResponse({"error": "Only a portal administrator can install the application"}, status=HTTPStatus.FORBIDDEN)

    bitrix24_account.create_application_installation(request.params)

    handler_url = f"{config.app_base_url.rstrip('/')}/api/app-events/"
    client = bitrix24_account.get_client()

    client.call_batch([
        client.event.bind(event=event, handler=handler_url)
        for event in ("ONAPPINSTALL", "ONAPPUNINSTALL")
    ]).call()

    return JsonResponse({"message": "Installation successful"})
