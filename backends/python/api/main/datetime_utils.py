from __future__ import annotations

from datetime import datetime, timezone

from django.db import models
from django.utils import timezone as dj_tz


def ensure_aware(value: datetime | None) -> datetime | None:
    """Return a timezone-aware datetime (UTC) or None."""
    if value is None:
        return None
    if dj_tz.is_aware(value):
        return value
    return dj_tz.make_aware(value, timezone=timezone.utc)


def parse_bank_datetime(value: str | None) -> datetime:
    """Parse Tochka statement timestamps; fall back to current UTC time."""
    if not value:
        return dj_tz.now()
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return dj_tz.now()
    return ensure_aware(parsed) or dj_tz.now()


def normalize_model_datetimes(instance: models.Model) -> None:
    """Make naive DateTimeField values on a model instance timezone-aware."""
    for field in instance._meta.get_fields():
        if not isinstance(field, models.DateTimeField):
            continue
        current = getattr(instance, field.attname, None)
        if current is not None and dj_tz.is_naive(current):
            setattr(instance, field.attname, ensure_aware(current))
