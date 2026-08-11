import uuid

from django.db import migrations, models


class Migration(migrations.Migration):

    initial = True
    dependencies = []

    operations = [
        migrations.CreateModel(
            name='TochkaPortalPreferences',
            fields=[
                ('id', models.UUIDField(primary_key=True, default=uuid.uuid4, editable=False, serialize=False)),
                ('member_id', models.CharField(max_length=255, unique=True)),
                ('client_auto_sync_enabled', models.BooleanField(default=False)),
                ('client_auto_sync_interval_minutes', models.PositiveIntegerField(default=15)),
                ('updated_at', models.DateTimeField(auto_now=True)),
            ],
            options={
                'db_table': 'tochka_portal_preferences',
            },
        ),
    ]
