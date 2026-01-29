from datetime import timedelta

import jwt
import uuid

from b24pysdk import AbstractBitrixToken, BitrixToken
from b24pysdk.bitrix_api.credentials import OAuthPlacementData
from b24pysdk.bitrix_api.events import PortalDomainChangedEvent, OAuthTokenRenewedEvent
from b24pysdk.error import BitrixAPIError, BitrixValidationError

from django.db import models
from django.utils import timezone

from config import config


class Bitrix24Account(models.Model, AbstractBitrixToken):
    STATUS_NEW = "new"
    STATUS_ACTIVE = "active"
    STATUS_DELETED = "deleted"
    STATUS_BLOCKED = "blocked"
    STATUS_CHOICES = [
        (STATUS_NEW, "New"),
        (STATUS_ACTIVE, "Active"),
        (STATUS_DELETED, "Deleted"),
        (STATUS_BLOCKED, "Blocked"),
    ]

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    b24_user_id = models.IntegerField()
    is_b24_user_admin = models.BooleanField(default=False)
    member_id = models.CharField(max_length=255)
    is_master_account = models.BooleanField(null=True, help_text="True for the user who installed the app; False for additional user tokens.")
    domain = models.CharField(max_length=255, db_column="domain_url")
    status = models.CharField(max_length=50, choices=STATUS_CHOICES, default=STATUS_NEW)
    application_token = models.CharField(max_length=255, null=True)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    updated_at_utc = models.DateTimeField(auto_now=True)
    application_version = models.IntegerField()
    comment = models.TextField(null=True, help_text="Internal developer notes")
    auth_token = models.CharField(max_length=255, null=True, db_column="access_token")
    refresh_token = models.CharField(max_length=255, null=True)
    expires = models.IntegerField(null=True)
    expires_in = models.IntegerField(null=True)
    current_scope = models.JSONField(null=True)

    class Meta:
        managed = False
        db_table = "bitrix24account"
        unique_together = ("b24_user_id", "domain")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.portal_domain_changed_signal.connect(self._on_portal_domain_changed_event)
        self.oauth_token_renewed_signal.connect(self._on_oauth_token_renewed_event)

    def _on_portal_domain_changed_event(self, _: PortalDomainChangedEvent):
        self.save(update_fields=["domain"])

    def _on_oauth_token_renewed_event(self, _event: OAuthTokenRenewedEvent):
        self.save(update_fields=["auth_token", "refresh_token", "expires", "expires_in"])

    def create_jwt_token(self, minutes: int = 60) -> str:
        now_dt = timezone.now()

        payload = {
            "account_id": str(self.pk),
            "exp": now_dt + timedelta(minutes=minutes),
        }

        return jwt.encode(payload, config.jwt_secret, algorithm=config.jwt_algorithm)

    @staticmethod
    def _validate_jwt_token(jwt_token: str) -> uuid.UUID:
        payload = jwt.decode(jwt_token, config.jwt_secret, algorithms=[config.jwt_algorithm])

        for key in ("account_id", "exp"):
            if key not in payload:
                raise BitrixValidationError("Invalid JWT token")

        return uuid.UUID(payload["account_id"])

    @classmethod
    def get_from_jwt_token(cls, jwt_token: str) -> "Bitrix24Account":
        account_uuid = cls._validate_jwt_token(jwt_token)
        return cls.objects.get(pk=account_uuid)


class ApplicationInstallation(models.Model):
    STATUS_NEW = "new"
    STATUS_ACTIVE = "active"
    STATUS_DELETED = "deleted"
    STATUS_BLOCKED = "blocked"
    STATUS_CHOICES = [
        (STATUS_NEW, "New"),
        (STATUS_ACTIVE, "Active"),
        (STATUS_DELETED, "Deleted"),
        (STATUS_BLOCKED, "Blocked"),
    ]

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    status = models.CharField(max_length=50, choices=STATUS_CHOICES, default=STATUS_NEW)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    update_at_utc = models.DateTimeField(auto_now=True)
    bitrix_24_account = models.OneToOneField(Bitrix24Account, on_delete=models.CASCADE, help_text="Master Bitrix24 account that installed the application.")
    contact_person_id = models.UUIDField(null=True, help_text="Optional client contact person GUID (if provided).")
    bitrix_24_partner_contact_person_id = models.UUIDField(null=True, help_text="Optional partner contact person GUID.")
    bitrix_24_partner_id = models.UUIDField(null=True, help_text="Optional partner GUID supporting the portal.")
    external_id = models.CharField(max_length=255, null=True, help_text="External CRM/ERP identifier linked to the installation.")
    portal_license_family = models.CharField(max_length=255, help_text="Portal license family snapshot for analytics.")
    portal_users_count = models.IntegerField(null=True, help_text="Portal users count snapshot for analytics.")
    application_token = models.CharField(max_length=255, null=True, help_text="Application token mirrored for convenience.")
    comment = models.TextField(null=True, help_text="Internal developer notes about the installation.")
    status_code = models.JSONField(null=True)

    class Meta:
        managed = False
        db_table = "application_installation"
