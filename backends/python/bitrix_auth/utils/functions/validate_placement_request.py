from http import HTTPStatus
from typing import Optional, cast

from django.http import HttpRequest, JsonResponse

from b24pysdk.bitrix_api.credentials import OAuthPlacementData
from b24pysdk.error import (
    BitrixAPIError,
    BitrixOAuthException,
    BitrixValidationError,
)
from b24pysdk.utils.types import JSONDict
from django.conf import settings
from config import config


def validate_placement_request(request: HttpRequest, client_id: Optional[str] = None) -> Optional[JsonResponse]:
    """
    Validate placement payload and enrich request with resolved domain/member.
    Returns JsonResponse on failure, otherwise None.
    """
    placement = request.data.get("PLACEMENT")
    auth_id = request.data.get("AUTH_ID")
    if client_id:
        request.data["CLIENT_ID"] = client_id

    if not placement:
        return JsonResponse({"error": "Missing PLACEMENT"}, status=HTTPStatus.BAD_REQUEST)

    if not auth_id:
        return JsonResponse({"error": "Missing AUTH_ID"}, status=HTTPStatus.BAD_REQUEST)

    try:
        app_info = config.bitrix_app.get_app_info(auth_id).result
        domain = app_info.install.domain
        member_id = app_info.install.member_id
    except (BitrixAPIError, BitrixOAuthException) as error:
        settings.logger.warning("Failed to fetch portal info via SDK", extra={"error": str(error)})
        return JsonResponse({"error": "Invalid placement auth data"}, status=HTTPStatus.BAD_REQUEST)

    if not domain or not member_id:
        return JsonResponse({"error": "Invalid placement auth data"}, status=HTTPStatus.BAD_REQUEST)

    request.data["DOMAIN"] = domain
    request.data["member_id"] = member_id

    try:
        request.oauth_placement_data = OAuthPlacementData.from_dict(cast(JSONDict, request.data))
    except BitrixValidationError as error:
        return JsonResponse({"error": str(error)}, status=HTTPStatus.BAD_REQUEST)

    return None
