"""Декодирование JWT вебхуков Точки (acquiringInternetPayment)."""

from __future__ import annotations

from typing import Any, Dict

import jwt
import requests
from jwt import PyJWK

from config import load_config

_config = load_config()


def _jwk_from_response(data: Any) -> PyJWK:
    if isinstance(data, dict) and "keys" in data and isinstance(data["keys"], list) and data["keys"]:
        return PyJWK.from_dict(data["keys"][0])
    if isinstance(data, dict):
        return PyJWK.from_dict(data)
    raise ValueError("Invalid JWK payload from Tochka")


def decode_acquiring_webhook_body(raw_body: str) -> Dict[str, Any]:
    """Проверка подписи JWS и возврат payload claims."""
    r = requests.get(_config.tochka_webhook_jwk_url, timeout=15)
    r.raise_for_status()
    key = _jwk_from_response(r.json())
    decoded = jwt.decode(
        raw_body.strip(),
        key,
        algorithms=["RS256"],
        options={"verify_aud": False},
    )
    return dict(decoded)
