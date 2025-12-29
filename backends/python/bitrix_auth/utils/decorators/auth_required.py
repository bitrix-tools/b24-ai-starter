from functools import wraps
from http import HTTPStatus

import jwt

from django.http import JsonResponse, HttpRequest

from b24pysdk.error import BitrixValidationError

from bitrix_auth.models import Bitrix24Account
from bitrix_auth.utils.decorators import placement_required
from main.utils.decorators.collect_request_data import collect_request_data


def auth_required(view_func):
    @wraps(view_func)
    @collect_request_data
    def wrapped(request: HttpRequest, *args, **kwargs):
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
            # Validate placement payload and enrich request data (domain/member_id).
            placement_validator = placement_required(lambda req, *_a, **_kw: req)
            placement_result = placement_validator(request)

            if isinstance(placement_result, JsonResponse):
                return placement_result

            try:
                request.bitrix24_account, _ = Bitrix24Account.update_or_create_from_oauth_placement_data(request.oauth_placement_data)
            except BitrixValidationError as error:
                return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

            return view_func(request, *args, **kwargs)

        return view_func(request, *args, **kwargs)

    return wrapped
