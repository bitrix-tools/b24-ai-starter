import unittest
from datetime import datetime, timezone

from django.utils import timezone as dj_tz

from main.datetime_utils import ensure_aware, parse_bank_datetime


class DatetimeUtilsTest(unittest.TestCase):
    def test_ensure_aware_adds_utc_to_naive_values(self):
        naive = datetime(2026, 8, 6, 12, 0, 0)
        aware = ensure_aware(naive)

        self.assertIsNotNone(aware)
        self.assertTrue(dj_tz.is_aware(aware))
        self.assertEqual(aware.tzinfo, timezone.utc)

    def test_ensure_aware_keeps_existing_timezone(self):
        original = datetime(2026, 8, 6, 12, 0, tzinfo=timezone.utc)
        self.assertIs(ensure_aware(original), original)

    def test_parse_bank_datetime_makes_date_only_values_aware(self):
        parsed = parse_bank_datetime("2026-08-06T00:00:00")

        self.assertTrue(dj_tz.is_aware(parsed))
        self.assertEqual(parsed.year, 2026)
        self.assertEqual(parsed.month, 8)
        self.assertEqual(parsed.day, 6)

    def test_parse_bank_datetime_parses_zulu_suffix(self):
        parsed = parse_bank_datetime("2026-08-06T09:15:00Z")

        self.assertTrue(dj_tz.is_aware(parsed))
        self.assertEqual(parsed.hour, 9)


if __name__ == "__main__":
    unittest.main()
