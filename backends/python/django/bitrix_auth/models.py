from contextlib import contextmanager
from datetime import timedelta
from typing import Any, Iterator, Optional, Dict

import jwt
import uuid

from b24pysdk import AbstractBitrixToken
from b24pysdk.errors import (
    BitrixAPIError,
    BitrixValidationError,
)
from b24pysdk.events import OAuthTokenRenewedEvent, PortalDomainChangedEvent
from b24pysdk.utils.types import JSONDict, Timeout

from django.db import models, transaction
from django.utils import timezone

from config import config

__all__ = [
    "ApplicationInstallation",
    "Bitrix24Account",
]


class Bitrix24Account(models.Model, AbstractBitrixToken):
    """
    Saved Bitrix24 user identity plus OAuth tokens for this application.

    The account status describes whether this concrete user token can be used
    by the backend. The application installation status lives in
    `ApplicationInstallation` and describes the portal-level app lifecycle.
    """

    class Status(models.TextChoices):
        """
        Availability of this saved Bitrix24 user token.

        `ACTIVE` means the token belongs to the current installation and can be used for REST API calls.
        `INACTIVE` means the Bitrix24 user can still exist, but this saved token must not be used.
        `DELETED` means Bitrix24 reported that the user itself cannot be authorized anymore.
        """

        ACTIVE = "active", "Active"
        INACTIVE = "inactive", "Inactive"
        DELETED = "deleted", "Deleted"

        @property
        def is_active(self) -> bool:
            return self == self.ACTIVE

        @property
        def is_inactive(self) -> bool:
            return self == self.INACTIVE

        @property
        def is_deleted(self) -> bool:
            return self == self.DELETED

    _ERROR_DESCRIPTION_STATUS_MAP: Dict[str, Status] = {
        "Current user can't be authorized in this context": Status.DELETED,
        "Unable to authorize user": Status.DELETED,
        "The user does not have access to the application.": Status.INACTIVE,
        "Unable to get application by token": Status.INACTIVE,
    }
    _ERROR_STATUS_MAP: Dict[str, Status] = {
        "EXPIRED_TOKEN": Status.INACTIVE,
        "INVALID_GRANT": Status.INACTIVE,
        "WRONG_CLIENT": Status.INACTIVE,
    }

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    b24_user_id = models.IntegerField()
    is_b24_user_admin = models.BooleanField(default=False)
    member_id = models.CharField(max_length=255)
    is_master_account = models.BooleanField(default=False, help_text="True for the user who installed the app; False for additional user tokens.")
    domain = models.CharField(max_length=255, db_column="domain_url")
    status = models.CharField(max_length=50, choices=Status, default=Status.INACTIVE)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    updated_at_utc = models.DateTimeField(auto_now=True)
    application_version = models.IntegerField()
    comment = models.TextField(null=True, help_text="Internal developer notes")
    auth_token = models.CharField(max_length=255, null=True)
    refresh_token = models.CharField(max_length=255, null=True)
    expires = models.DateTimeField(null=True)
    expires_in = models.IntegerField(null=True)
    current_scope = models.JSONField(null=True)

    class Meta:
        unique_together = ("b24_user_id", "domain")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.portal_domain_changed_signal.connect(self._on_portal_domain_changed_event)
        self.oauth_token_renewed_signal.connect(self._on_oauth_token_renewed_event)

    def _on_portal_domain_changed_event(self, _: PortalDomainChangedEvent):
        self.save(update_fields=("domain",))

    def _on_oauth_token_renewed_event(self, _: OAuthTokenRenewedEvent):
        self.save(update_fields=("auth_token", "refresh_token", "expires", "expires_in"))

    @property
    def status_enum(self) -> Status:
        """Return `status` as a typed enum instead of a raw database string."""
        return self.Status(self.status)

    @property
    def is_active(self) -> bool:
        """Return True when the saved token is allowed to call Bitrix24."""
        return self.status_enum.is_active

    def create_jwt_token(self, minutes: int = 60) -> str:
        """Create a short-lived backend JWT bound to this Bitrix24 account."""

        now_dt = timezone.now()

        payload = {
            "account_id": str(self.pk),
            "exp": now_dt + timedelta(minutes=minutes),
        }

        return jwt.encode(payload, config.jwt_secret, algorithm=config.jwt_algorithm)

    @staticmethod
    def _validate_jwt_token(jwt_token: str) -> uuid.UUID:
        """Validate a backend JWT and return the embedded account id."""
        payload = jwt.decode(jwt_token, config.jwt_secret, algorithms=[config.jwt_algorithm])

        for key in ("account_id", "exp"):
            if key not in payload:
                raise BitrixValidationError("Invalid JWT token")

        return uuid.UUID(payload["account_id"])

    @classmethod
    def get_from_jwt_token(cls, jwt_token: str) -> "Bitrix24Account":
        """Load the Bitrix24 account referenced by a valid backend JWT."""
        account_uuid = cls._validate_jwt_token(jwt_token)
        return cls.objects.get(pk=account_uuid)

    def call_method(
            self,
            api_method: str,
            params: Optional[JSONDict] = None,
            timeout: Timeout = None,
            **kwargs,
    ) -> JSONDict:
        """Call Bitrix24 REST API and sync local statuses on API errors."""
        with self._sync_status_from_api_error():
            return super().call_method(api_method=api_method, params=params, timeout=timeout, **kwargs)

    def create_application_installation(self, request_params: Optional[JSONDict] = None) -> "ApplicationInstallation":
        """"""
        request_params = request_params or {}

        with transaction.atomic():
            now_dt = timezone.now()

            ApplicationInstallation.objects.filter(
                bitrix_24_account__member_id=self.member_id,
            ).update(
                status=ApplicationInstallation.Status.DELETED,
                update_at_utc=now_dt,
            )

            Bitrix24Account.objects.filter(
                member_id=self.member_id,
            ).exclude(
                pk=self.pk,
            ).filter(
                status=Bitrix24Account.Status.ACTIVE,
            ).update(
                status=Bitrix24Account.Status.INACTIVE,
                is_master_account=False,
                updated_at_utc=now_dt,
            )

            self.status = self.Status.ACTIVE
            self.is_master_account = True
            self.save(update_fields=(
                "status",
                "is_master_account",
                "updated_at_utc",
            ))

            return ApplicationInstallation.objects.create(
                bitrix_24_account=self,
                status=ApplicationInstallation.Status.NEW,
                portal_license_family=request_params.get("LICENSE_FAMILY"),
            )

    def get_application_installation(self) -> Optional["ApplicationInstallation"]:
        """"""
        return (
            ApplicationInstallation.objects
            .filter(bitrix_24_account__member_id=self.member_id)
            .exclude(status=ApplicationInstallation.Status.DELETED)
            .order_by("-created_at_utc")
            .first()
        )

    @classmethod
    def resolve_status_by_api_error(cls, error: BitrixAPIError) -> Optional[Status]:
        """"""
        return (
            cls._ERROR_DESCRIPTION_STATUS_MAP.get(error.error_description)
            or cls._ERROR_STATUS_MAP.get(error.error.upper())
        )

    def sync_status_from_api_error(self, error: BitrixAPIError) -> Optional[Status]:
        """"""

        new_status = self.resolve_status_by_api_error(error)

        if not new_status or self.status == new_status:
            return None

        self.status = new_status
        self.comment = str(error.json_response)
        self.save(update_fields=("status", "comment", "updated_at_utc"))

        return self.status

    @contextmanager
    def _sync_status_from_api_error(self) -> Iterator[None]:
        """"""
        try:
            yield
        except BitrixAPIError as error:
            self.sync_status_from_api_error(error)
            application_installation = self.get_application_installation()

            if application_installation is not None:
                application_installation.sync_status_from_api_error(error)

            raise


class ApplicationInstallation(models.Model):
    """
    Portal-level lifecycle record for this Bitrix24 application installation.

    One installation belongs to the master Bitrix24 account that installed the
    app. Its status answers whether the app is installed and usable on the
    portal, while `Bitrix24Account.status` answers whether a concrete user's
    saved token is usable.
    """

    class Status(models.TextChoices):
        """Installation state of this application on a Bitrix24 portal."""

        NEW = "new", "New"  # started the installation procedure, but have not yet finalized, there is no "installation completed"
        ACTIVE = "active", "Active"  # active portal, there is a connection to B24
        DELETED = "deleted", "Deleted"  # the app has been removed from the portal
        BLOCKED = "blocked", "Blocked"  # lost connection with the portal or the developer forcibly deactivated the account

    _ERROR_DESCRIPTION_STATUS_MAP: Dict[str, Status] = {
        "Application not installed": Status.DELETED,
        "REST is available only on commercial plans.": Status.BLOCKED,
        "Access denied! Available only on extended plans": Status.BLOCKED,
        "License check failed.": Status.BLOCKED,
    }
    _ERROR_STATUS_MAP: Dict[str, Status] = {
        "PAYMENT_REQUIRED": Status.BLOCKED,
    }

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    status = models.CharField(max_length=50, choices=Status, default=Status.NEW)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    update_at_utc = models.DateTimeField(auto_now=True)
    bitrix_24_account = models.ForeignKey(Bitrix24Account, on_delete=models.CASCADE, help_text="Master Bitrix24 account that installed the application.")
    contact_person_id = models.UUIDField(null=True, help_text="Optional client contact person GUID (if provided).")
    bitrix_24_partner_contact_person_id = models.UUIDField(null=True, help_text="Optional partner contact person GUID.")
    bitrix_24_partner_id = models.UUIDField(null=True, help_text="Optional partner GUID supporting the portal.")
    external_id = models.CharField(max_length=255, null=True, help_text="External CRM/ERP identifier linked to the installation.")
    portal_license_family = models.CharField(max_length=255, null=True, help_text="Portal license family snapshot for analytics.")
    portal_users_count = models.IntegerField(null=True, help_text="Portal users count snapshot for analytics.")
    application_token = models.CharField(max_length=255, null=True, help_text="Application token mirrored for convenience.")
    comment = models.TextField(null=True, help_text="Internal developer notes about the installation.")
    status_code = models.JSONField(null=True)

    def set_status(self, status: Status, comment: Optional[str], status_code: Any = None):
        """Persist a new installation status and optional diagnostic payload."""
        self.status = status
        self.comment = comment
        self.status_code = status_code
        self.save(update_fields=("status", "comment", "status_code", "update_at_utc"))

    def mark_as_installed(
            self,
            application_token: Optional[str] = None,
    ):
        """Mark the portal installation as active after Bitrix24 confirms it."""

        self.status = self.Status.ACTIVE
        self.status_code = None

        update_fields = ("status", "status_code", "update_at_utc")

        if application_token is not None:
            self.application_token = application_token
            update_fields += ("application_token",)

        self.save(update_fields=update_fields)

    @classmethod
    def resolve_status_by_api_error(cls, error: BitrixAPIError) -> Optional[Status]:
        """"""
        return (
            cls._ERROR_DESCRIPTION_STATUS_MAP.get(error.error_description)
            or cls._ERROR_STATUS_MAP.get(error.error.upper())
        )

    def sync_status_from_api_error(self, error: BitrixAPIError) -> Optional[Status]:
        """"""

        new_status = self.resolve_status_by_api_error(error)

        if not new_status:
            return None

        if self.status != new_status:
            self.set_status(new_status, str(error.json_response), {"status_code": error.status_code, "json_response": error.json_response})

        account_updates: Dict = {
            "status": Bitrix24Account.Status.INACTIVE,
            "updated_at_utc": timezone.now(),
        }

        if new_status == self.Status.DELETED:
            account_updates["is_master_account"] = False

        Bitrix24Account.objects.filter(
            member_id=self.bitrix_24_account.member_id,
            status=Bitrix24Account.Status.ACTIVE,
        ).update(
            **account_updates,
        )

        return new_status


