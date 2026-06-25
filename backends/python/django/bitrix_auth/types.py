from typing import TYPE_CHECKING

from b24pysdk.integrations.django.types import CollectedParamsRequest

if TYPE_CHECKING:
    from bitrix_auth.models import Bitrix24Account


class AuthorizedRequest(CollectedParamsRequest):
    bitrix24_account: "Bitrix24Account"
