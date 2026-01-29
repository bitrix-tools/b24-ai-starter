from functools import wraps
from http import HTTPStatus
from typing import TYPE_CHECKING, Any, Callable, Optional, TypeVar, Union, cast

from django.http import JsonResponse

from b24pysdk.bitrix_api.credentials import OAuthEventData, OAuthWorkflowData
from b24pysdk.error import BitrixValidationError

from bitrix_auth.models import ApplicationInstallation
from bitrix_auth.utils.decorators._collect_request_data import collect_request_data

if TYPE_CHECKING:
    from bitrix_auth.utils.types import CollectedDataRequest, EventRequest, WorkflowRequest

_FT = TypeVar("_FT", bound=Callable[..., Any])


def _validate_event_request(
    request: "CollectedDataRequest",
) -> Union["EventRequest", "WorkflowRequest", JsonResponse]:
    """
    Validate event payload (OAuthEventData or OAuthWorkflowData) and ensure application_token matches installation.
    """
    is_workflow = "workflow_id" in request.data

    try:
        if is_workflow:
            oauth_workflow_data = OAuthWorkflowData.from_dict(request.data)
            auth = oauth_workflow_data.auth

            application_installation = ApplicationInstallation.objects.get(bitrix_24_account__member_id=auth.member_id)

            if not (auth.application_token and application_installation.application_token == auth.application_token):
                return JsonResponse({"error": "Invalid workflow auth data"}, status=HTTPStatus.UNAUTHORIZED)

            request.oauth_workflow_data = oauth_workflow_data
            return cast("WorkflowRequest", request)
        else:
            oauth_event_data = OAuthEventData.from_dict(request.data)
            auth = oauth_event_data.auth

            application_installation = ApplicationInstallation.objects.get(bitrix_24_account__member_id=auth.member_id)

            if not (auth.application_token and application_installation.application_token == auth.application_token):
                return JsonResponse({"error": "Invalid event auth data"}, status=HTTPStatus.UNAUTHORIZED)

            request.oauth_event_data = oauth_event_data
            return cast("EventRequest", request)

    except (BitrixValidationError, ApplicationInstallation.DoesNotExist) as error:
        return JsonResponse({"error": str(error)}, status=HTTPStatus.UNAUTHORIZED)


def event_required(
    view_func: Optional[_FT] = None,
    /,
    *,
    validate: bool = False,
) -> Union[_FT, Callable[[_FT], _FT]]:
    """
    Decorator to aggregate request data and optionally validate incoming Bitrix event payloads.

    When validation is enabled, attaches parsed OAuth event data to the request or returns
    JsonResponse on failure before invoking the wrapped view.
    """

    def decorator(func: _FT) -> _FT:
        @wraps(func)
        @collect_request_data
        def wrapper(request: "CollectedDataRequest", *args: Any, **kwargs: Any):
            if validate:
                request_or_response: Union["EventRequest", "WorkflowRequest", JsonResponse] = _validate_event_request(request)
                if isinstance(request_or_response, JsonResponse):
                    return request_or_response

                request = cast(Union["EventRequest", "WorkflowRequest"], request_or_response)

            return func(request, *args, **kwargs)

        return wrapper

    if view_func is None:
        return decorator

    return decorator(view_func)
