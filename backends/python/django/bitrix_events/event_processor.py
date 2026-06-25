from collections.abc import Callable

from django.utils import timezone

from b24pysdk import Config as B24Config
from b24pysdk.credentials import OAuthEventData

from bitrix_auth.models import ApplicationInstallation, Bitrix24Account

try:
    from celery_app import celery_app
except ModuleNotFoundError as error:
    if error.name != "celery":
        raise

    celery_app = None


class Bitrix24EventProcessor:
    """Apply Bitrix24 lifecycle events to local records."""

    def __init__(self, oauth_event_data: OAuthEventData):
        self.oauth_event_data = oauth_event_data
        self.logger = B24Config().logger

    @property
    def event(self) -> str:
        return self.oauth_event_data.event

    def get_handler_by_event(self) -> Callable[[], None] | None:
        handler_name = f"{self.event.lower()}_handler"
        return getattr(self, handler_name, None)

    def process(self):
        handler = self.get_handler_by_event()

        if handler is None:
            self.logger.error(
                "Unknown Bitrix24 lifecycle event received",
                context={
                    "event_code": self.event,
                    "member_id": self.oauth_event_data.auth.member_id,
                },
            )
            return

        handler()

    def _get_current_installation(self) -> ApplicationInstallation | None:
        return (
            ApplicationInstallation.objects
            .select_related("bitrix_24_account")
            .filter(bitrix_24_account__member_id=self.oauth_event_data.auth.member_id)
            .exclude(status=ApplicationInstallation.Status.DELETED)
            .order_by("-created_at_utc")
            .first()
        )

    def onappinstall_handler(self):
        installation = self._get_current_installation()

        if installation is None:
            self.logger.warning(
                "Bitrix24 lifecycle event received for unknown installation",
                context={
                    "event_code": self.event,
                    "member_id": self.oauth_event_data.auth.member_id,
                },
            )
            return

        installation.mark_as_installed(
            application_token=self.oauth_event_data.auth.application_token,
        )

    def onappuninstall_handler(self):
        installation = self._get_current_installation()

        if installation is None:
            self.logger.warning(
                "Bitrix24 lifecycle event received for unknown installation",
                context={
                    "event_code": self.event,
                    "member_id": self.oauth_event_data.auth.member_id,
                },
            )
            return

        if not (self.oauth_event_data.auth.application_token and installation.application_token == self.oauth_event_data.auth.application_token):
            self.logger.warning(
                "Application uninstall event token mismatch",
                context={
                    "member_id": self.oauth_event_data.auth.member_id,
                    "installation_id": str(installation.pk),
                },
            )
            return

        now_dt = timezone.now()
        installation.status = ApplicationInstallation.Status.DELETED
        installation.save(update_fields=("status", "update_at_utc"))

        Bitrix24Account.objects.filter(member_id=self.oauth_event_data.auth.member_id).exclude(
            status=Bitrix24Account.Status.DELETED,
        ).update(
            status=Bitrix24Account.Status.INACTIVE,
            is_master_account=False,
            updated_at_utc=now_dt,
        )


def process_bitrix24_event(event: OAuthEventData):
    """Process a validated Bitrix24 lifecycle event."""
    Bitrix24EventProcessor(event).process()


if celery_app is not None:
    process_bitrix24_event = celery_app.task(name="bitrix24.process_event")(process_bitrix24_event)
