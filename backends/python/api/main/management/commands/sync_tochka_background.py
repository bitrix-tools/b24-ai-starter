from django.core.management.base import BaseCommand
from django.utils import timezone

from main.background_sync_schedule import is_background_sync_due
from main.models import TochkaCredentials, TochkaPortalPreferences
from main.tochka_sync_logic import _get_tochka_client, run_tochka_sync


class Command(BaseCommand):
    help = (
        "Sync due Tochka portals using their saved background-sync interval. "
        "Run every minute from cron; use --force for an immediate administrator run."
    )

    def add_arguments(self, parser):
        parser.add_argument(
            "--days",
            type=int,
            default=2,
            help="Calendar days to look back per portal (default 2, light on API)",
        )
        parser.add_argument(
            "--force",
            action="store_true",
            help="Sync every connected portal regardless of its enabled flag and interval",
        )

    def handle(self, *args, **options):
        days = max(int(options["days"]), 1)
        force = bool(options["force"])
        credentials = list(TochkaCredentials.objects.filter(is_connected=True))
        preferences = {
            pref.member_id: pref
            for pref in TochkaPortalPreferences.objects.all()
        }
        now = timezone.now()
        due_credentials = []

        for creds in credentials:
            pref = preferences.get(creds.member_id)
            if is_background_sync_due(
                enabled=bool(pref and pref.client_auto_sync_enabled),
                interval_minutes=pref.client_auto_sync_interval_minutes if pref else 15,
                last_attempt_at=creds.updated_at,
                now=now,
                force=force,
            ):
                due_credentials.append(creds)

        self.stdout.write(
            self.style.NOTICE(
                "sync_tochka_background: "
                f"{len(due_credentials)}/{len(credentials)} due connected member(s), "
                f"days={days}, force={force}"
            )
        )

        for creds in due_credentials:
            member_id = creds.member_id
            try:
                TochkaCredentials.objects.filter(pk=creds.pk).update(updated_at=timezone.now())
                result = run_tochka_sync(member_id, days=days)
                self.stdout.write(self.style.SUCCESS(f"  {member_id}: synced {result['synced']}"))
            except Exception as e:
                error_msg = str(e)
                _client, c2 = _get_tochka_client(member_id)
                if c2 and (
                    "Re-authorization required" in error_msg or "Token refresh failed" in error_msg
                ):
                    c2.is_connected = False
                    c2.access_token = None
                    c2.refresh_token = None
                    c2.save()
                    self.stderr.write(self.style.ERROR(f"  {member_id}: token expired, disconnected"))
                else:
                    self.stderr.write(self.style.ERROR(f"  {member_id}: {error_msg}"))
