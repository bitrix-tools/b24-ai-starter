import logging
from functools import wraps
from http import HTTPStatus
from typing import TYPE_CHECKING, Any, Callable, Optional, TypeVar, Union, cast

from django.conf import settings
from django.http import JsonResponse

from b24pysdk import BitrixToken, BitrixApp
from b24pysdk.bitrix_api.credentials import OAuthPlacementData
from b24pysdk.error import BitrixAPIError, BitrixValidationError

from bitrix_auth.utils.decorators._collect_request_data import collect_request_data

if TYPE_CHECKING:
    from bitrix_auth.utils.types import CollectedDataRequest, AppInfoPlacementDataRequest

_FT = TypeVar("_FT", bound=Callable[..., Any])

_logger = getattr(settings, "logger", logging.getLogger(__name__))


def _validate_placement_request(
    request: "CollectedDataRequest",
    *,
    bitrix_app: BitrixApp,
) -> Union["AppInfoPlacementDataRequest", JsonResponse]:
    """
    Validate placement payload and enrich request with resolved domain/member.
    Returns JsonResponse on failure, otherwise returns validated request.
    """
    try:
        oauth_placement_data = OAuthPlacementData.from_dict(request.data)
        bitrix_token = BitrixToken.from_oauth_placement_data(oauth_placement_data, bitrix_app=bitrix_app)
        app_info = bitrix_token.get_app_info().result

        if not (oauth_placement_data.validate_against_app_info(app_info) and app_info.client_id == bitrix_app.client_id):
            return JsonResponse({"error": "Invalid placement auth data"}, status=HTTPStatus.UNAUTHORIZED)

    except BitrixValidationError as error:
        return JsonResponse({"error": str(error)}, status=HTTPStatus.UNAUTHORIZED)

    except BitrixAPIError as error:
        _logger.info(f"Failed to fetch portal info via SDK: {error.message}")
        return JsonResponse({"error": error.message}, status=error.status_code)

    else:
        request.app_info = app_info
        request.oauth_placement_data = oauth_placement_data

        return cast(AppInfoPlacementDataRequest, request)


def placement_required(
    view_func: Optional[_FT] = None,
    /,
    *,
    validate: bool = False,
    bitrix_app: Optional[BitrixApp] = None,
) -> Union[_FT, Callable[[_FT], _FT]]:
    """
    Decorator to normalize placement requests and optionally validate Bitrix auth payloads.

    When `validate` is True, the request is enriched with OAuth placement data and application info
    or short-circuits with a JsonResponse on validation failure.
    """

    if validate and bitrix_app is None:
        raise ValueError("bitrix_app is required when validate is True")

    def decorator(func: _FT) -> _FT:
        @wraps(func)
        @collect_request_data
        def wrapper(request: "CollectedDataRequest", *args: Any, **kwargs: Any):
            if validate:
                request_or_response: Union["AppInfoPlacementDataRequest", JsonResponse] = _validate_placement_request(request, bitrix_app=bitrix_app)

                if isinstance(request_or_response, JsonResponse):
                    return request_or_response

            return func(request, *args, **kwargs)

        return wrapper

    if view_func is None:
        return decorator

    return decorator(view_func)
