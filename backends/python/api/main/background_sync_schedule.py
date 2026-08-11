from datetime import datetime, timedelta
from typing import Optional


MIN_SYNC_INTERVAL_MINUTES = 3
MAX_SYNC_INTERVAL_MINUTES = 180


def is_background_sync_due(
    *,
    enabled: bool,
    interval_minutes: int,
    last_attempt_at: Optional[datetime],
    now: datetime,
    force: bool = False,
) -> bool:
    if force:
        return True
    if not enabled:
        return False
    if last_attempt_at is None:
        return True

    interval = max(
        MIN_SYNC_INTERVAL_MINUTES,
        min(int(interval_minutes), MAX_SYNC_INTERVAL_MINUTES),
    )
    if last_attempt_at.tzinfo is None and now.tzinfo is not None:
        last_attempt_at = last_attempt_at.replace(tzinfo=now.tzinfo)

    return last_attempt_at <= now - timedelta(minutes=interval)
