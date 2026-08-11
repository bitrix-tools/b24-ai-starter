from datetime import timedelta
from typing import Tuple

import jwt
import uuid

from b24pysdk import AbstractBitrixToken, BitrixApp, BitrixToken, Client
from b24pysdk.bitrix_api.credentials import OAuthPlacementData
from b24pysdk.bitrix_api.events import PortalDomainChangedEvent, OAuthTokenRenewedEvent
from b24pysdk.error import BitrixAPIError, BitrixValidationError
from b24pysdk.utils.functional import Classproperty

from django.db import models
from django.utils import timezone

from config import config


class Bitrix24Account(models.Model, AbstractBitrixToken):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    b24_user_id = models.IntegerField()
    is_b24_user_admin = models.BooleanField(default=False)
    member_id = models.CharField(max_length=255)
    is_master_account = models.BooleanField(null=True)
    domain_url = models.CharField(max_length=255)
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
        unique_together = ("b24_user_id", "domain_url")

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.portal_domain_changed_signal.connect(self.on_portal_domain_changed_event)
        self.oauth_token_renewed_signal.connect(self.on_oauth_token_renewed_event)

    @property
    def domain(self) -> str:
        return self.domain_url

    @domain.setter
    def domain(self, domain: str):
        self.domain_url = domain

    @property
    def auth_token(self) -> str:
        return self.access_token

    @auth_token.setter
    def auth_token(self, auth_token: str):
        self.access_token = auth_token

    @Classproperty
    def bitrix_app(cls) -> BitrixApp:
        return BitrixApp(client_id=config.client_id, client_secret=config.client_secret)

    @property
    def client(self) -> Client:
        return Client(self)

    def on_portal_domain_changed_event(self, _: PortalDomainChangedEvent):
        self.save(update_fields=["portal_url"])

    def on_oauth_token_renewed_event(self, event: OAuthTokenRenewedEvent):
        self.expires = event.renewed_oauth_token.oauth_token.expires
        self.expires_in = event.renewed_oauth_token.oauth_token.expires_in
        self.save(update_fields=["access_token", "refresh_token", "expires", "expires_in"])

    def create_jwt_token(self, minutes: int = 60) -> str:
        now_dt = timezone.now()

        payload = {
            "account_id": str(self.pk),
            "iat": now_dt,
            "exp": now_dt + timedelta(minutes=minutes),
        }

        return jwt.encode(payload, config.jwt_secret, algorithm=config.jwt_algorithm)

    @staticmethod
    def _validate_jwt_token(jwt_token: str) -> uuid.UUID:
        payload = jwt.decode(jwt_token, config.jwt_secret, algorithms=[config.jwt_algorithm])

        for key in ("account_id", "exp", "iat"):
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
            bitrix_token = BitrixToken.from_oauth_placement_data(oauth_placement_data, bitrix_app=cls.bitrix_app)
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
            domain_url=oauth_placement_data.domain,
            b24_user_id=app_info.user_id,
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


def _default_deal_matching() -> dict:
    return {}


class TochkaPortalPreferences(models.Model):
    """Портальные настройки фоновой серверной синхронизации и интерфейса."""

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    member_id = models.CharField(max_length=255, unique=True)
    client_auto_sync_enabled = models.BooleanField(default=False)
    client_auto_sync_interval_minutes = models.PositiveIntegerField(default=15)
    last_sync_at = models.DateTimeField(null=True, blank=True)
    deal_payment_kind_uf_code = models.CharField(max_length=64, blank=True, default="")
    deal_payment_channel_uf_code = models.CharField(max_length=64, blank=True, default="")
    lead_payment_kind_uf_code = models.CharField(max_length=64, blank=True, default="")
    lead_payment_channel_uf_code = models.CharField(max_length=64, blank=True, default="")

    notify_deal_responsible = models.BooleanField(default=True)
    notify_deal_timeline = models.BooleanField(default=True)
    notify_chat_id = models.IntegerField(null=True, blank=True)
    notify_chat_bot_id = models.PositiveIntegerField(
        null=True,
        blank=True,
        help_text="Bitrix24 chat bot ID for imbot.v2.Chat.Message.send; empty = im.message.add as app user",
    )

    invoice_auto_update_stage = models.BooleanField(default=False)
    invoice_stage_id_paid = models.CharField(max_length=128, blank=True, default="")
    invoice_stage_id_partial = models.CharField(max_length=128, blank=True, default="")
    invoice_match_by_inn = models.BooleanField(default=False)
    invoice_match_by_amount = models.BooleanField(default=True)

    deal_matching = models.JSONField(
        default=_default_deal_matching,
        blank=True,
        help_text="Настройки сопоставления платежей со сделками (вкладка «Сопоставление»).",
    )

    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = "tochka_portal_preferences"


class PaymentNotifyLog(models.Model):
    """Идемпотентность уведомлений по банковским платежам (tochka_payments — unmanaged)."""

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    member_id = models.CharField(max_length=255, db_index=True)
    payment_id = models.UUIDField(db_index=True)
    event_kind = models.CharField(max_length=64, db_index=True)
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        db_table = "payment_notify_log"
        constraints = [
            models.UniqueConstraint(
                fields=["payment_id", "event_kind"],
                name="uniq_payment_notify_event",
            )
        ]


class TochkaAcquiringOperation(models.Model):
    """Платёжные ссылки / эквайринг Точки (отдельно от TochkaPayment по выписке)."""

    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    member_id = models.CharField(max_length=255, db_index=True)
    deal_id = models.IntegerField(null=True, blank=True)
    lead_id = models.IntegerField(null=True, blank=True)
    amount = models.DecimalField(max_digits=15, decimal_places=2)
    currency = models.CharField(max_length=3, default="RUB")
    customer_code = models.CharField(max_length=255)
    merchant_id = models.CharField(max_length=255, null=True, blank=True)
    payment_link_id = models.CharField(max_length=255, null=True, blank=True)
    external_operation_id = models.CharField(max_length=255, null=True, blank=True, db_index=True)
    payment_url = models.TextField(null=True, blank=True)
    payment_modes_requested = models.JSONField(default=list, blank=True)
    payment_mode_effective = models.CharField(max_length=32, null=True, blank=True)
    payment_kind = models.CharField(max_length=32, null=True, blank=True)
    purpose = models.TextField(null=True, blank=True)
    status = models.CharField(max_length=32, default="pending")
    webhook_raw = models.TextField(null=True, blank=True)
    duplicate_bank_payment_id = models.UUIDField(null=True, blank=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        db_table = "tochka_acquiring_operations"


class TochkaCredentials(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    member_id = models.CharField(max_length=255, unique=True)
    client_id = models.CharField(max_length=255)
    client_secret = models.CharField(max_length=255)
    access_token = models.TextField(null=True, blank=True)
    refresh_token = models.TextField(null=True, blank=True)
    token_expires_at = models.DateTimeField(null=True, blank=True)
    is_connected = models.BooleanField(default=False)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    def save(self, *args, **kwargs):
        from .datetime_utils import normalize_model_datetimes

        normalize_model_datetimes(self)
        super().save(*args, **kwargs)

    class Meta:
        managed = False
        db_table = "tochka_credentials"


class TochkaPayment(models.Model):
    id = models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False)
    member_id = models.CharField(max_length=255)
    account_id = models.CharField(max_length=255)
    transaction_id = models.CharField(max_length=255, null=True, blank=True)
    payment_slip_number = models.CharField(max_length=255, null=True, blank=True)
    amount = models.DecimalField(max_digits=15, decimal_places=2)
    amount_nat = models.DecimalField(max_digits=15, decimal_places=2, null=True, blank=True)
    currency = models.CharField(max_length=3, default='RUB')
    credit_debit = models.CharField(max_length=10)
    counterparty_name = models.CharField(max_length=255, null=True, blank=True)
    counterparty_inn = models.CharField(max_length=12, null=True, blank=True)
    counterparty_account = models.CharField(max_length=255, null=True, blank=True)
    counterparty_bik = models.CharField(max_length=9, null=True, blank=True)
    purpose = models.TextField(null=True, blank=True)
    invoice_number = models.CharField(max_length=255, null=True, blank=True)
    vat_type = models.CharField(max_length=20, null=True, blank=True)
    transaction_date = models.DateTimeField()
    status = models.CharField(max_length=20, default='pending')
    matched_deal_id = models.IntegerField(null=True, blank=True)
    matched_lead_id = models.IntegerField(null=True, blank=True)
    matched_invoice_id = models.IntegerField(null=True, blank=True)
    # Deal info cache
    deal_title = models.CharField(max_length=255, null=True, blank=True)
    deal_amount = models.DecimalField(max_digits=15, decimal_places=2, null=True, blank=True)
    deal_currency = models.CharField(max_length=3, null=True, blank=True)
    deal_responsible_id = models.IntegerField(null=True, blank=True)
    deal_responsible_name = models.CharField(max_length=255, null=True, blank=True)
    deal_responsible_photo = models.CharField(max_length=500, null=True, blank=True)
    deal_paid_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    deal_paid_percent = models.IntegerField(default=0)
    # Invoice info cache (классический счёт crm.invoice)
    invoice_title = models.CharField(max_length=255, null=True, blank=True)
    invoice_amount = models.DecimalField(max_digits=15, decimal_places=2, null=True, blank=True)
    invoice_currency = models.CharField(max_length=3, null=True, blank=True)
    invoice_account_number = models.CharField(max_length=255, null=True, blank=True)
    invoice_responsible_id = models.IntegerField(null=True, blank=True)
    invoice_responsible_name = models.CharField(max_length=255, null=True, blank=True)
    invoice_responsible_photo = models.CharField(max_length=500, null=True, blank=True)
    invoice_paid_amount = models.DecimalField(max_digits=15, decimal_places=2, default=0)
    invoice_paid_percent = models.IntegerField(default=0)
    ledger_exclude = models.BooleanField(default=False)
    created_at = models.DateTimeField(auto_now_add=True)

    def save(self, *args, **kwargs):
        from .datetime_utils import normalize_model_datetimes

        normalize_model_datetimes(self)
        super().save(*args, **kwargs)

    class Meta:
        managed = False
        db_table = "tochka_payments"
        unique_together = ("member_id", "transaction_id")
