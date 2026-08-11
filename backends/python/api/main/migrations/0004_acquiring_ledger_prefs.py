import uuid

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0003_tochka_matched_lead_id"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="deal_payment_kind_uf_code",
            field=models.CharField(blank=True, default="", max_length=64),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="deal_payment_channel_uf_code",
            field=models.CharField(blank=True, default="", max_length=64),
        ),
        migrations.CreateModel(
            name="TochkaAcquiringOperation",
            fields=[
                ("id", models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ("member_id", models.CharField(db_index=True, max_length=255)),
                ("deal_id", models.IntegerField(blank=True, null=True)),
                ("lead_id", models.IntegerField(blank=True, null=True)),
                ("amount", models.DecimalField(decimal_places=2, max_digits=15)),
                ("currency", models.CharField(default="RUB", max_length=3)),
                ("customer_code", models.CharField(max_length=255)),
                ("merchant_id", models.CharField(blank=True, max_length=255, null=True)),
                ("payment_link_id", models.CharField(blank=True, max_length=255, null=True)),
                ("external_operation_id", models.CharField(blank=True, db_index=True, max_length=255, null=True)),
                ("payment_url", models.TextField(blank=True, null=True)),
                ("payment_modes_requested", models.JSONField(blank=True, default=list)),
                ("payment_mode_effective", models.CharField(blank=True, max_length=32, null=True)),
                ("payment_kind", models.CharField(blank=True, max_length=32, null=True)),
                ("purpose", models.TextField(blank=True, null=True)),
                ("status", models.CharField(default="pending", max_length=32)),
                ("webhook_raw", models.TextField(blank=True, null=True)),
                ("duplicate_bank_payment_id", models.UUIDField(blank=True, null=True)),
                ("created_at", models.DateTimeField(auto_now_add=True)),
                ("updated_at", models.DateTimeField(auto_now=True)),
            ],
            options={
                "db_table": "tochka_acquiring_operations",
            },
        ),
        migrations.RunSQL(
            "ALTER TABLE tochka_payments ADD COLUMN IF NOT EXISTS ledger_exclude BOOLEAN NOT NULL DEFAULT FALSE;",
            reverse_sql="ALTER TABLE tochka_payments DROP COLUMN IF EXISTS ledger_exclude;",
        ),
    ]
