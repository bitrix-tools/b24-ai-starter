from http import HTTPStatus
from typing import Callable

from django.conf import settings
from django.http import HttpRequest, HttpResponse, JsonResponse

from b24pysdk import errors as bitrix_errors
from b24pysdk.errors.oauth import BitrixOAuthException

BITRIX_SDK_ERROR_TYPES = tuple(
    error_type
    for error_type in (
        bitrix_errors.BitrixAPIError,
        getattr(bitrix_errors, "BitrixAPIException", None),
        BitrixOAuthException,
    )
    if error_type is not None
)


class LogErrorsMiddleware:
    """
    Catch unhandled view errors, log them, and return a JSON 500 response.
    """

    def __init__(self, get_response: Callable[[HttpRequest], HttpResponse]):
        self.get_response = get_response

    def __call__(self, request: HttpRequest) -> HttpResponse:
        try:
            return self.get_response(request)
        except BITRIX_SDK_ERROR_TYPES as exc:
            resolver = getattr(request, "resolver_match", None)
            view_name = getattr(resolver, "view_name", None)
            view_func = getattr(resolver, "func", None)
            view_ref = view_name or getattr(view_func, "__name__", None) or "<unknown_view>"

            log_message = f"Bitrix24 SDK exception in {request.method} {request.path} ({view_ref}): {exc}"

            if isinstance(exc, bitrix_errors.BitrixAPIError):
                settings.logger.error(log_message)
            else:
                settings.logger.warning(log_message)

            return JsonResponse({"error": str(exc)}, status=HTTPStatus.INTERNAL_SERVER_ERROR)

        except Exception as exc:  # noqa: BLE001 - intentionally catch all to serialize
            resolver = getattr(request, "resolver_match", None)
            view_name = getattr(resolver, "view_name", None)
            view_func = getattr(resolver, "func", None)
            view_ref = view_name or getattr(view_func, "__name__", None) or "<unknown_view>"

            settings.logger.error(f"Unhandled exception in {request.method} {request.path} ({view_ref})")

            return JsonResponse({"error": str(exc)}, status=HTTPStatus.INTERNAL_SERVER_ERROR)
