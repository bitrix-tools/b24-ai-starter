from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import jwt

from config import config


_STATE_PURPOSE = "tochka_oauth"
_STATE_LIFETIME = timedelta(minutes=15)


class InvalidOAuthState(ValueError):
    pass


@dataclass(frozen=True)
class OAuthState:
    member_id: str
    popup: bool


def create_oauth_state(member_id: str, popup: bool) -> str:
    now = datetime.now(timezone.utc)
    return jwt.encode(
        {
            "purpose": _STATE_PURPOSE,
            "member_id": member_id,
            "popup": bool(popup),
            "iat": now,
            "exp": now + _STATE_LIFETIME,
        },
        config.jwt_secret,
        algorithm=config.jwt_algorithm,
    )


def read_oauth_state(state: str) -> OAuthState:
    try:
        payload = jwt.decode(
            state,
            config.jwt_secret,
            algorithms=[config.jwt_algorithm],
            options={"require": ["exp", "iat"]},
        )
    except jwt.InvalidTokenError as error:
        raise InvalidOAuthState("Invalid or expired OAuth state") from error

    member_id = payload.get("member_id")
    if payload.get("purpose") != _STATE_PURPOSE or not isinstance(member_id, str) or not member_id:
        raise InvalidOAuthState("Invalid OAuth state payload")

    return OAuthState(member_id=member_id, popup=bool(payload.get("popup")))
