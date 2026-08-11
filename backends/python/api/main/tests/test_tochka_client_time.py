import unittest
from datetime import datetime, timedelta, timezone
from unittest.mock import patch

from main.tochka.client import TochkaClient


class TochkaClientTimeTest(unittest.TestCase):
    @patch("main.tochka.client.datetime")
    def test_aware_database_expiry_is_compared_with_aware_current_time(self, datetime_mock):
        now = datetime(2026, 8, 6, 12, 0, tzinfo=timezone.utc)
        datetime_mock.now.side_effect = (
            lambda tz=None: now if tz is not None else now.replace(tzinfo=None)
        )
        client = TochkaClient(
            client_id="client",
            client_secret="secret",
            access_token="token",
            token_expires_at=now + timedelta(minutes=10),
        )

        self.assertTrue(client._is_token_valid())
        datetime_mock.now.assert_called_once_with(timezone.utc)

    @patch("main.tochka.client.datetime")
    def test_legacy_naive_expiry_is_treated_as_utc(self, datetime_mock):
        now = datetime(2026, 8, 6, 12, 0, tzinfo=timezone.utc)
        datetime_mock.now.return_value = now
        client = TochkaClient(
            client_id="client",
            client_secret="secret",
            access_token="token",
            token_expires_at=(now + timedelta(minutes=10)).replace(tzinfo=None),
        )

        self.assertTrue(client._is_token_valid())


if __name__ == "__main__":
    unittest.main()
