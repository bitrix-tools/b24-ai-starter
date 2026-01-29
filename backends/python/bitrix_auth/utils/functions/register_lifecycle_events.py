import logging
from typing import Text

from django.conf import settings

from b24pysdk import AbstractBitrixToken
from b24pysdk.error import BitrixAPIError

_logger = getattr(settings, "logger", logging.getLogger(__name__))


def ensure_onappuninstall_subscription(bitrix_token: AbstractBitrixToken, handler_url: Text):
    """
    Ensure ONAPPUNINSTALL handler is bound for the given token.

    Unbinds existing subscription for the same handler URL and binds a fresh one.
    """
    try:
        _ = bitrix_token.get_client().event.unbind(event="ONAPPUNINSTALL", handler=handler_url).result
    except BitrixAPIError as error:
        _logger.info("Unbind ONAPPUNINSTALL failed (ignored)", extra={"handler": handler_url, "error": str(error)})

    try:
        _ = bitrix_token.get_client().event.bind(event="ONAPPUNINSTALL", handler=handler_url).result
    except BitrixAPIError as error:
        _logger.info("Bind ONAPPUNINSTALL failed", extra={"handler": handler_url, "error": str(error)})
        raise
