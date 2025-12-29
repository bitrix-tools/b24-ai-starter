import logging
from functools import wraps
from http import HTTPStatus
from typing import Tuple, Union, cast

import requests
from django.http import HttpRequest, JsonResponse

from b24pysdk.bitrix_api.credentials import OAuthPlacementData
from b24pysdk.error import BitrixValidationError
from b24pysdk.utils.types import JSONDict

LoggerType = Union[logging.Logger, logging.LoggerAdapter]

BITRIX_AUTH_SERVER = "https://oauth.bitrix.info"


def _get_portal_domain_and_member_id(
        data: dict,
        *,
        logger: LoggerType = logging.getLogger(__name__),
) -> Tuple[Union[str, None], Union[str, None]]:
    """
    Resolve portal domain and member_id from placement payload.
    """
    domain = data.get("DOMAIN") or data.get("domain")
    member_id = data.get("member_id")
    auth_id = data.get("AUTH_ID")

    if domain and member_id:
        return domain, member_id

    if member_id is None and auth_id:
        try:
            response = requests.get(
                f"{BITRIX_AUTH_SERVER}/rest/app.info",
                params={"auth": auth_id},
                timeout=10,
            )
            response.raise_for_status()
            payload = response.json()
            install = payload.get("result", {}).get("install", {})

            domain = install.get("domain")
            member_id = install.get("member_id")
        except (requests.RequestException, ValueError, TypeError) as error:
            logger.warning("Failed to fetch portal info via app.info", extra={"error": str(error)})
            return None, None

    return domain, member_id


def placement_required(view_func):
    """
    Validate placement request data (iframe scenario).

    Ensures required placement fields exist and member/domain are resolved.
    """

    @wraps(view_func)
    def wrapped(request: HttpRequest, *args, **kwargs):
        placement = request.data.get("PLACEMENT")
        auth_id = request.data.get("AUTH_ID")

        if not placement:
            return JsonResponse({"error": "Missing PLACEMENT"}, status=HTTPStatus.BAD_REQUEST)

        if not auth_id:
            return JsonResponse({"error": "Missing AUTH_ID"}, status=HTTPStatus.BAD_REQUEST)

        domain, member_id = _get_portal_domain_and_member_id(request.data)

        if not domain or not member_id:
            return JsonResponse({"error": "Invalid placement auth data"}, status=HTTPStatus.BAD_REQUEST)

        request.data["DOMAIN"] = domain
        request.data["member_id"] = member_id

        try:
            request.oauth_placement_data = OAuthPlacementData.from_dict(cast(JSONDict, request.data))
        except BitrixValidationError as error:
            return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

        return view_func(request, *args, **kwargs)

    return wrapped
