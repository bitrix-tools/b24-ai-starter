import json
from functools import wraps


def collect_request_data(view_func):
    """
    Decorator that collects GET and POST parameters into request.data
    Supports both single values and lists for parameters
    """

    @wraps(view_func)
    def wrapper(request, *args, **kwargs):
        if hasattr(request, "data"):
            return view_func(request, *args, **kwargs)

        try:
            request.data = json.loads(request.body)
        except (ValueError, AttributeError):
            request.data = {}

        for src in (request.GET, request.POST):
            for key, values in src.lists():
                request.data[key] = values if len(values) > 1 else values[0]

        return view_func(request, *args, **kwargs)

    return wrapper
