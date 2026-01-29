from functools import wraps
from http import HTTPStatus
from typing import TYPE_CHECKING, Union

import jwt

from django.http import JsonResponse, HttpRequest

from b24pysdk.error import BitrixValidationError

from bitrix_auth.models import Bitrix24Account
from bitrix_auth.utils.decorators import placement_required
from config import config
from ._collect_request_data import collect_request_data

if TYPE_CHECKING:
    from bitrix_auth.utils.types import AppInfoPlacementDataRequest


def auth_required(view_func):
    """
    Authenticate a view either via JWT bearer token or placement validation fallback.

    If a valid JWT is provided, the related `Bitrix24Account` is attached to the request.
    Otherwise, placement payload is validated and used to upsert the account before invoking the view.
    """
    @wraps(view_func)
    @collect_request_data
    def wrapper(request: HttpRequest, *args, **kwargs):
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
            # Validate placement payload and enrich request data (domain/member_id).
            placement_validator = placement_required(lambda req, *_args, **_kwargs: req, validate=True, bitrix_app=config.bitrix_app)
            request_or_response: Union[JsonResponse, "AppInfoPlacementDataRequest"] = placement_validator(request)

            if isinstance(request_or_response, JsonResponse):
                return request_or_response

            try:

                defaults = {
                    "member_id": request_or_response.oauth_placement_data.member_id,
                    "status": request_or_response.oauth_placement_data.status,
                    "auth_token": request_or_response.oauth_placement_data.oauth_token.access_token,
                    "refresh_token": request_or_response.oauth_placement_data.oauth_token.refresh_token,
                    "expires": int(request_or_response.oauth_placement_data.oauth_token.expires.timestamp()),
                    "application_version": request_or_response.app_info.install.version,
                    "expires_in": request_or_response.oauth_placement_data.oauth_token.expires_in,
                }

                bitrix24_account, is_created = Bitrix24Account.objects.update_or_create(
                    domain=request_or_response.oauth_placement_data.domain,
                    b24_user_id=request_or_response.app_info.user_id,
                    defaults=defaults,
                )
            except BitrixValidationError as error:
                return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

            request.bitrix24_account = bitrix24_account
            return view_func(request, *args, **kwargs)

    return wrapper
