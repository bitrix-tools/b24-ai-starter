import os
import unittest
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import Mock, patch

os.environ.setdefault("DJANGO_SETTINGS_MODULE", "settings")

import django

django.setup()

from main.tochka_sync_logic import _persist_client_tokens


class TochkaSyncTokenPersistenceTest(unittest.TestCase):
    def test_rotated_refresh_token_is_persisted(self):
        credentials = SimpleNamespace(pk="cred-1")
        client = SimpleNamespace(
            access_token="new-access",
            refresh_token="new-refresh",
            token_expires_at=datetime(2026, 8, 7, 12, 0, tzinfo=timezone.utc),
        )
        queryset = Mock()

        with patch("main.tochka_sync_logic.TochkaCredentials") as credentials_model, patch(
            "main.tochka_sync_logic.dj_tz.now",
            return_value=datetime(2026, 8, 6, 13, 0, tzinfo=timezone.utc),
        ):
            credentials_model.objects.filter.return_value = queryset
            _persist_client_tokens(credentials, client)

        credentials_model.objects.filter.assert_called_once_with(pk="cred-1")
        queryset.update.assert_called_once_with(
            access_token="new-access",
            refresh_token="new-refresh",
            token_expires_at=client.token_expires_at,
            updated_at=datetime(2026, 8, 6, 13, 0, tzinfo=timezone.utc),
        )


if __name__ == "__main__":
    unittest.main()
