import uuid

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0017_tochkaportalpreferences_last_sync_at"),
    ]

    operations = [
        migrations.CreateModel(
            name="SupportTicket",
            fields=[
                ("id", models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ("member_id", models.CharField(db_index=True, max_length=64)),
                ("domain_url", models.CharField(blank=True, default="", max_length=255)),
                ("user_id", models.IntegerField(blank=True, null=True)),
                ("user_name", models.CharField(blank=True, default="", max_length=255)),
                ("contact", models.CharField(blank=True, default="", max_length=255)),
                ("message", models.TextField()),
                ("attachment_name", models.CharField(blank=True, default="", max_length=255)),
                ("attachment_size", models.IntegerField(default=0)),
                ("delivered", models.BooleanField(default=False)),
                ("delivery_error", models.TextField(blank=True, default="")),
                ("created_at", models.DateTimeField(auto_now_add=True)),
            ],
            options={
                "db_table": "support_tickets",
            },
        ),
        migrations.AddIndex(
            model_name="supportticket",
            index=models.Index(fields=["member_id", "-created_at"], name="support_member_created_idx"),
        ),
    ]
