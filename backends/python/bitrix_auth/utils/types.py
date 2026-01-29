from typing import TYPE_CHECKING, Dict

from django.http import HttpRequest

if TYPE_CHECKING:
    from b24pysdk.bitrix_api.credentials import OAuthPlacementData, OAuthEventData, OAuthWorkflowData
    from b24pysdk.bitrix_api.responses import B24AppInfoResult
    from bitrix_auth.models import Bitrix24Account


class CollectedDataRequest(HttpRequest):
    data: Dict


class PlacementDataRequest(CollectedDataRequest):
    oauth_placement_data: "OAuthPlacementData"


class AppInfoPlacementDataRequest(PlacementDataRequest):
    app_info: "B24AppInfoResult"


class AuthorizedRequest(HttpRequest):
    bitrix24_account: "Bitrix24Account"


class EventRequest(CollectedDataRequest):
    oauth_event_data: "OAuthEventData"


class WorkflowRequest(CollectedDataRequest):
    oauth_workflow_data: "OAuthWorkflowData"
