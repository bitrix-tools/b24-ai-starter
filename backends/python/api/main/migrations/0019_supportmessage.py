import uuid

import django.db.models.deletion
from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0018_supportticket"),
    ]

    operations = [
        migrations.CreateModel(
            name="SupportMessage",
            fields=[
                ("id", models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ("author", models.CharField(choices=[("user", "Клиент"), ("support", "Поддержка")], default="support", max_length=16)),
                ("author_name", models.CharField(blank=True, default="", max_length=255)),
                ("text", models.TextField(blank=True, default="")),
                ("attachment", models.CharField(blank=True, default="", max_length=128)),
                ("created_at", models.DateTimeField(auto_now_add=True)),
                ("ticket", models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name="messages", to="main.supportticket")),
            ],
            options={
                "db_table": "support_message",
                "ordering": ["created_at"],
            },
        ),
        migrations.AddIndex(
            model_name="supportmessage",
            index=models.Index(fields=["ticket", "created_at"], name="support_msg_ticket_idx"),
        ),
    ]
