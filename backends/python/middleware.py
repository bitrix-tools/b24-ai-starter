from http import HTTPStatus

from django.http import JsonResponse

from config import config


class LogErrorsMiddleware:
    """
    Catch unhandled view errors, log them, and return a JSON 500 response.
    """

    def __init__(self, get_response):
        self.get_response = get_response
        self.logger = config.logger

    def __call__(self, request):
        try:
            return self.get_response(request)
        except Exception as exc:  # noqa: BLE001 - intentionally catch all to serialize
            resolver = getattr(request, "resolver_match", None)
            view_name = getattr(resolver, "view_name", None)
            view_func = getattr(resolver, "func", None)
            view_ref = view_name or getattr(view_func, "__name__", None) or "<unknown_view>"

            self.logger.exception(
                "Unhandled exception in %s %s (%s)",
                request.method,
                request.path,
                view_ref,
            )

            return JsonResponse({"error": str(exc)}, status=HTTPStatus.INTERNAL_SERVER_ERROR)
