from datetime import timedelta
from typing import Tuple

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
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    bitrix_id = models.IntegerField(db_column="b24_user_id")
    is_b24_user_admin = models.BooleanField(default=False)
    member_id = models.CharField(max_length=255)
    is_master_account = models.BooleanField(null=True)
    domain = models.CharField(max_length=255, db_column="domain_url")
    status = models.CharField(max_length=50)
    application_token = models.CharField(max_length=255, null=True)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    updated_at_utc = models.DateTimeField(auto_now=True)
    application_version = models.IntegerField()
    comment = models.TextField(null=True)
    access_token = models.CharField(max_length=255, null=True)
    refresh_token = models.CharField(max_length=255, null=True)
    expires = models.IntegerField(null=True)
    expires_in = models.IntegerField(null=True)
    current_scope = models.JSONField(null=True)

    class Meta:
        managed = False
        db_table = "bitrix24account"
        unique_together = ("bitrix_id", "domain")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.portal_domain_changed_signal.connect(self._on_portal_domain_changed_event)
        self.oauth_token_renewed_signal.connect(self._on_oauth_token_renewed_event)

    @property
    def auth_token(self) -> str:
        return self.access_token

    @auth_token.setter
    def auth_token(self, auth_token: str):
        self.access_token = auth_token

    def _on_portal_domain_changed_event(self, _: PortalDomainChangedEvent):
        self.save(update_fields=["portal_url"])

    def _on_oauth_token_renewed_event(self, _event: OAuthTokenRenewedEvent):
        self.save(update_fields=["access_token", "refresh_token", "expires", "expires_in"])

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

    @classmethod
    def update_or_create_from_oauth_placement_data(cls, oauth_placement_data: "OAuthPlacementData") -> Tuple["Bitrix24Account", bool]:
        """Create or update Bitrix24Account"""

        try:
            bitrix_token = BitrixToken.from_oauth_placement_data(oauth_placement_data, bitrix_app=config.bitrix_app)
            app_info = bitrix_token.get_app_info().result
        except BitrixAPIError as error:
            raise BitrixValidationError(error.message) from error

        defaults = {
            "member_id": oauth_placement_data.member_id,
            "status": oauth_placement_data.status,
            "access_token": oauth_placement_data.oauth_token.access_token,
            "refresh_token": oauth_placement_data.oauth_token.refresh_token,
            "expires": int(oauth_placement_data.oauth_token.expires.timestamp()),
            "application_version": app_info.install.version,
        }

        bitrix24_account, is_created = cls.objects.update_or_create(
            domain=oauth_placement_data.domain,
            bitrix_id=app_info.user_id,
            defaults=defaults,
        )

        return bitrix24_account, is_created


class ApplicationInstallation(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    status = models.CharField(max_length=50)
    created_at_utc = models.DateTimeField(auto_now_add=True)
    update_at_utc = models.DateTimeField(auto_now=True)
    bitrix_24_account = models.OneToOneField(Bitrix24Account, on_delete=models.CASCADE, db_column="bitrix_24_account_id")
    contact_person_id = models.UUIDField(null=True)
    bitrix_24_partner_contact_person_id = models.UUIDField(null=True)
    bitrix_24_partner_id = models.UUIDField(null=True)
    external_id = models.CharField(max_length=255, null=True)
    portal_license_family = models.CharField(max_length=255)
    portal_users_count = models.IntegerField(null=True)
    application_token = models.CharField(max_length=255, null=True)
    comment = models.TextField(null=True)
    status_code = models.JSONField(null=True)

    class Meta:
        managed = False
        db_table = "application_installation"
