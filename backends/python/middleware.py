from http import HTTPStatus
from typing import Callable

from django.conf import settings
from django.http import HttpRequest, HttpResponse, JsonResponse


class LogErrorsMiddleware:
    """
    Catch unhandled view errors, log them, and return a JSON 500 response.
    """

    def __init__(self, get_response: Callable[[HttpRequest], HttpResponse]):
        self.get_response = get_response

    def __call__(self, request: HttpRequest) -> HttpResponse:
        try:
            return self.get_response(request)
        except Exception as exc:  # noqa: BLE001 - intentionally catch all to serialize
            resolver = getattr(request, "resolver_match", None)
            view_name = getattr(resolver, "view_name", None)
            view_func = getattr(resolver, "func", None)
            view_ref = view_name or getattr(view_func, "__name__", None) or "<unknown_view>"

            settings.logger.error(f"Unhandled exception in {request.method} {request.path} ({view_ref})")

            return JsonResponse({"error": str(exc)}, status=HTTPStatus.INTERNAL_SERVER_ERROR)
