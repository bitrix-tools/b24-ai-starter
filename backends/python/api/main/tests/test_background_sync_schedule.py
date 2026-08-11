import unittest
from datetime import datetime, timedelta, timezone

from main.background_sync_schedule import is_background_sync_due


class BackgroundSyncScheduleTest(unittest.TestCase):
    now = datetime(2026, 8, 6, 12, 0, tzinfo=timezone.utc)

    def test_disabled_portal_is_not_due(self):
        self.assertFalse(
            is_background_sync_due(
                enabled=False,
                interval_minutes=15,
                last_attempt_at=self.now - timedelta(hours=1),
                now=self.now,
            )
        )

    def test_force_bypasses_enabled_flag_and_interval(self):
        self.assertTrue(
            is_background_sync_due(
                enabled=False,
                interval_minutes=180,
                last_attempt_at=self.now,
                now=self.now,
                force=True,
            )
        )

    def test_portal_is_due_only_after_its_interval(self):
        self.assertFalse(
            is_background_sync_due(
                enabled=True,
                interval_minutes=15,
                last_attempt_at=self.now - timedelta(minutes=14),
                now=self.now,
            )
        )
        self.assertTrue(
            is_background_sync_due(
                enabled=True,
                interval_minutes=15,
                last_attempt_at=self.now - timedelta(minutes=15),
                now=self.now,
            )
        )

    def test_interval_is_clamped_to_supported_bounds(self):
        self.assertFalse(
            is_background_sync_due(
                enabled=True,
                interval_minutes=1,
                last_attempt_at=self.now - timedelta(minutes=2),
                now=self.now,
            )
        )
        self.assertTrue(
            is_background_sync_due(
                enabled=True,
                interval_minutes=500,
                last_attempt_at=self.now - timedelta(minutes=180),
                now=self.now,
            )
        )

    def test_missing_last_attempt_is_due(self):
        self.assertTrue(
            is_background_sync_due(
                enabled=True,
                interval_minutes=15,
                last_attempt_at=None,
                now=self.now,
            )
        )

if __name__ == "__main__":
    unittest.main()
