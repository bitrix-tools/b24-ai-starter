from functools import wraps
from http import HTTPStatus

import jwt

from django.http import JsonResponse

from b24pysdk.errors import BitrixValidationError
from b24pysdk.integrations.django.decorators import collect_request_params
from b24pysdk.integrations.django.decorators.placement_required import validate_placement_request
from b24pysdk.integrations.django.types import CollectedParamsRequest

from bitrix_auth.models import Bitrix24Account
from config import config


def auth_required(view_func):
    """
    Authenticate a view either via JWT bearer token or placement validation fallback.

    If a valid JWT is provided, the related `Bitrix24Account` is attached to the request.
    Otherwise, placement payload is validated and used to upsert the account before invoking the view.
    """
    @wraps(view_func)
    @collect_request_params
    def wrapper(request: CollectedParamsRequest, *args, **kwargs):
        auth = request.headers.get("Authorization")

        if isinstance(auth, str) and auth.lower().startswith("bearer "):
            jwt_token = auth[len("bearer "):]

            try:
                request.bitrix24_account = Bitrix24Account.get_from_jwt_token(jwt_token)

            except Bitrix24Account.DoesNotExist:
                return JsonResponse({"error": "Invalid JWT token"}, status=HTTPStatus.UNAUTHORIZED)

            except jwt.ExpiredSignatureError:
                return JsonResponse({"error": "JWT token has expired"}, status=HTTPStatus.UNAUTHORIZED)

            except jwt.InvalidTokenError:
                return JsonResponse({"error": "Invalid JWT token"}, status=HTTPStatus.UNAUTHORIZED)

            except BitrixValidationError as error:
                return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

            else:
                return view_func(request, *args, **kwargs)

        else:
            try:
                oauth_placement_data = validate_placement_request(request, bitrix_app=config.bitrix_app)
                app_info = oauth_placement_data.get_app_info(config.bitrix_app)

                defaults = {
                    "member_id": oauth_placement_data.member_id,
                    "status": oauth_placement_data.status,
                    "auth_token": oauth_placement_data.oauth_token.access_token,
                    "refresh_token": oauth_placement_data.oauth_token.refresh_token,
                    "expires": int(oauth_placement_data.oauth_token.expires.timestamp()),
                    "application_version": app_info.install.version,
                    "expires_in": oauth_placement_data.oauth_token.expires_in,
                }

                bitrix24_account, _ = Bitrix24Account.objects.update_or_create(
                    domain=oauth_placement_data.domain,
                    b24_user_id=app_info.user_id,
                    defaults=defaults,
                )

            except BitrixValidationError as error:
                return JsonResponse({"error": error.message}, status=HTTPStatus.BAD_REQUEST)

            request.bitrix24_account = bitrix24_account

            return view_func(request, *args, **kwargs)

    return wrapper
