import uuid

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0010_normalize_invoice_preferences_columns"),
    ]

    operations = [
        migrations.CreateModel(
            name="PaymentNotifyLog",
            fields=[
                ("id", models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ("member_id", models.CharField(db_index=True, max_length=255)),
                ("payment_id", models.UUIDField(db_index=True)),
                ("event_kind", models.CharField(db_index=True, max_length=64)),
                ("created_at", models.DateTimeField(auto_now_add=True)),
            ],
            options={
                "db_table": "payment_notify_log",
            },
        ),
        migrations.AddConstraint(
            model_name="paymentnotifylog",
            constraint=models.UniqueConstraint(
                fields=("payment_id", "event_kind"),
                name="uniq_payment_notify_event",
            ),
        ),
    ]
