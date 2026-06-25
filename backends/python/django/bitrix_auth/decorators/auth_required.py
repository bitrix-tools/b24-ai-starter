from functools import wraps
from http import HTTPStatus
from typing import Callable, Concatenate, ParamSpec, cast

import jwt

from django.db import IntegrityError
from django.http import HttpResponse, JsonResponse

from b24pysdk import Config as B24Config, BitrixToken
from b24pysdk.errors import BitrixSDKException, BitrixValidationError
from b24pysdk.integrations.django.decorators import collect_request_params
from b24pysdk.integrations.django.decorators.placement_required import validate_placement_request
from b24pysdk.integrations.django.types import CollectedParamsRequest

from bitrix_auth.models import Bitrix24Account
from bitrix_auth.types import AuthorizedRequest
from config import config

P = ParamSpec("P")


def auth_required(view_func: Callable[Concatenate[AuthorizedRequest, P], HttpResponse]) -> Callable[Concatenate[CollectedParamsRequest, P], HttpResponse]:
    """
    Authenticate a view either via JWT bearer token or placement validation fallback.

    If a valid JWT is provided, the related `Bitrix24Account` is attached to the request.
    Otherwise, placement payload is validated and used to upsert the account before invoking the view.
    """

    @wraps(view_func)
    @collect_request_params
    def wrapper(request: CollectedParamsRequest, *args: P.args, **kwargs: P.kwargs) -> HttpResponse:
        auth = request.headers.get("Authorization")

        if isinstance(auth, str) and auth.lower().startswith("bearer "):
            jwt_token = auth[len("bearer "):]

            try:
                bitrix24_account = Bitrix24Account.get_from_jwt_token(jwt_token)

            except Bitrix24Account.DoesNotExist:
                return JsonResponse({"error": "Invalid JWT token"}, status=HTTPStatus.UNAUTHORIZED)

            except jwt.ExpiredSignatureError:
                return JsonResponse({"error": "JWT token has expired"}, status=HTTPStatus.UNAUTHORIZED)

            except jwt.InvalidTokenError:
                return JsonResponse({"error": "Invalid JWT token"}, status=HTTPStatus.UNAUTHORIZED)

            except BitrixValidationError as error:
                return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

            else:
                if not bitrix24_account.is_active:
                    return JsonResponse({"error": "Bitrix24 account is not active"}, status=HTTPStatus.FORBIDDEN)

                authorized_request = cast(AuthorizedRequest, request)
                authorized_request.bitrix24_account = bitrix24_account

                return view_func(authorized_request, *args, **kwargs)

        else:
            try:
                oauth_placement_data = validate_placement_request(request, bitrix_app=config.bitrix_app)
                app_info = oauth_placement_data.get_app_info(config.bitrix_app)

                bitrix_token = BitrixToken.from_oauth_placement_data(oauth_placement_data=oauth_placement_data, bitrix_app=config.bitrix_app)
                is_b24_user_admin = bitrix_token.get_client().user.admin().result

                try:
                    bitrix24_account, _ = Bitrix24Account.objects.update_or_create(
                        domain=oauth_placement_data.domain,
                        b24_user_id=app_info.user_id,
                        defaults={
                            "member_id": oauth_placement_data.member_id,
                            "status": Bitrix24Account.Status.ACTIVE,
                            "auth_token": oauth_placement_data.oauth_token.access_token,
                            "refresh_token": oauth_placement_data.oauth_token.refresh_token,
                            "expires": oauth_placement_data.oauth_token.expires,
                            "application_version": app_info.install.version,
                            "expires_in": oauth_placement_data.oauth_token.expires_in,
                            "current_scope": app_info.scope,
                            "is_b24_user_admin": is_b24_user_admin,
                        },
                    )

                except IntegrityError:
                    bitrix24_account = Bitrix24Account.objects.get(
                        domain=oauth_placement_data.domain,
                        b24_user_id=app_info.user_id,
                    )

            except BitrixValidationError as error:
                B24Config().logger.info(
                    "Bitrix24 placement request validation failed",
                    context={
                        "error": error.message,
                    },
                )
                return JsonResponse({"error": error.message}, status=HTTPStatus.UNAUTHORIZED)

            except BitrixSDKException as error:
                B24Config().logger.warning(
                    "Bitrix24 SDK error during auth request processing",
                    context={
                        "error": error.message,
                    },
                )
                return JsonResponse({"error": "Internal server error"}, status=HTTPStatus.INTERNAL_SERVER_ERROR)

            except Exception as error:  # noqa: BLE001
                B24Config().logger.error(
                    "Unexpected error during auth request processing",
                    context={
                        "error": str(error),
                    },
                )
                return JsonResponse({"error": "Internal server error"}, status=HTTPStatus.INTERNAL_SERVER_ERROR)

            authorized_request = cast(AuthorizedRequest, request)
            authorized_request.bitrix24_account = bitrix24_account

            return view_func(authorized_request, *args, **kwargs)

    return wrapper
