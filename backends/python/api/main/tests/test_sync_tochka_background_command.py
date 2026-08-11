import os
import unittest
from datetime import datetime, timedelta, timezone
from types import SimpleNamespace
from unittest.mock import Mock, patch

os.environ.setdefault("DJANGO_SETTINGS_MODULE", "settings")

import django

django.setup()

from main.management.commands import sync_tochka_background as command_module


class SyncTochkaBackgroundCommandTest(unittest.TestCase):
    now = datetime(2026, 8, 6, 12, 0, tzinfo=timezone.utc)

    @staticmethod
    def _credential(pk, member_id, updated_at):
        return SimpleNamespace(pk=pk, member_id=member_id, updated_at=updated_at)

    @staticmethod
    def _preference(member_id, enabled, interval=15):
        return SimpleNamespace(
            member_id=member_id,
            client_auto_sync_enabled=enabled,
            client_auto_sync_interval_minutes=interval,
        )

    def test_normal_run_filters_by_preferences_and_continues_after_failure(self):
        old = self.now - timedelta(minutes=20)
        credentials = [
            self._credential(1, "missing", old),
            self._credential(2, "disabled", old),
            self._credential(3, "early", self.now - timedelta(minutes=5)),
            self._credential(4, "fails", old),
            self._credential(5, "due", old),
        ]
        preferences = [
            self._preference("disabled", False),
            self._preference("early", True),
            self._preference("fails", True),
            self._preference("due", True),
        ]
        events = []
        credentials_model = Mock()
        preferences_model = Mock()

        def filter_credentials(**kwargs):
            if kwargs == {"is_connected": True}:
                return credentials
            pk = kwargs["pk"]
            queryset = Mock()
            queryset.update.side_effect = lambda **_changes: events.append(("update", pk))
            return queryset

        credentials_model.objects.filter.side_effect = filter_credentials
        preferences_model.objects.all.return_value = preferences

        def run_sync(member_id, *, days):
            events.append(("sync", member_id, days))
            if member_id == "fails":
                raise RuntimeError("bank unavailable")
            return {"synced": 1}

        with patch.object(command_module, "TochkaCredentials", credentials_model), \
             patch.object(command_module, "TochkaPortalPreferences", preferences_model), \
             patch.object(command_module.timezone, "now", return_value=self.now), \
             patch.object(command_module, "run_tochka_sync", side_effect=run_sync), \
             patch.object(command_module, "_get_tochka_client", return_value=(None, None)):
            command_module.Command().handle(days=2, force=False)

        self.assertEqual(
            events,
            [
                ("update", 4),
                ("sync", "fails", 2),
                ("update", 5),
                ("sync", "due", 2),
            ],
        )

    def test_force_syncs_connected_portal_without_preferences(self):
        credentials = [self._credential(1, "forced", self.now)]
        credentials_model = Mock()
        preferences_model = Mock()
        update_queryset = Mock()
        credentials_model.objects.filter.side_effect = [
            credentials,
            update_queryset,
        ]
        preferences_model.objects.all.return_value = []

        with patch.object(command_module, "TochkaCredentials", credentials_model), \
             patch.object(command_module, "TochkaPortalPreferences", preferences_model), \
             patch.object(command_module.timezone, "now", return_value=self.now), \
             patch.object(
                command_module,
                "run_tochka_sync",
                return_value={"synced": 1},
             ) as run_sync:
            command_module.Command().handle(days=1, force=True)

        update_queryset.update.assert_called_once_with(updated_at=self.now)
        run_sync.assert_called_once_with("forced", days=1)


if __name__ == "__main__":
    unittest.main()
