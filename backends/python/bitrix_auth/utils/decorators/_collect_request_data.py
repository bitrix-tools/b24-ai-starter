import json
from functools import wraps
from typing import Any, Callable, TypeVar, cast

from django.http import HttpRequest

from bitrix_auth.utils.types import CollectedDataRequest

_FT = TypeVar("_FT", bound=Callable[..., Any])


def collect_request_data(view_func: _FT) -> _FT:
    """
    Decorate a view to ensure all request payload is available in `request.data`.

    The decorator merges JSON body, query params and form data into a single dictionary,
    normalizing multi-value parameters into lists.
    """

    @wraps(view_func)
    def wrapper(request: HttpRequest, *args: Any, **kwargs: Any):
        if hasattr(request, "data"):
            return view_func(request, *args, **kwargs)

        try:
            request.data = json.loads(request.body)
        except json.JSONDecodeError:
            request.data = {}

        for src in (request.GET, request.POST):
            for key, values in src.lists():
                request.data[key] = values if len(values) > 1 else values[0]

        return view_func(cast(CollectedDataRequest, request), *args, **kwargs)

    return cast(_FT, wrapper)
