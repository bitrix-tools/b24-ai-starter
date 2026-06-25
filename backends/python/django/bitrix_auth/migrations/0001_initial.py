import b24pysdk.credentials.bitrix_token
import django.db.models.deletion
import uuid
from django.db import migrations, models


class Migration(migrations.Migration):

    initial = True

    dependencies = [
    ]

    operations = [
        migrations.CreateModel(
            name='Bitrix24Account',
            fields=[
                ('id', models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ('b24_user_id', models.IntegerField()),
                ('is_b24_user_admin', models.BooleanField(default=False)),
                ('member_id', models.CharField(max_length=255)),
                ('is_master_account', models.BooleanField(default=False, help_text='True for the user who installed the app; False for additional user tokens.')),
                ('domain', models.CharField(db_column='domain_url', max_length=255)),
                ('status', models.CharField(choices=[('active', 'Active'), ('inactive', 'Inactive'), ('deleted', 'Deleted')], default='inactive', max_length=50)),
                ('created_at_utc', models.DateTimeField(auto_now_add=True)),
                ('updated_at_utc', models.DateTimeField(auto_now=True)),
                ('application_version', models.IntegerField()),
                ('comment', models.TextField(help_text='Internal developer notes', null=True)),
                ('auth_token', models.CharField(max_length=255, null=True)),
                ('refresh_token', models.CharField(max_length=255, null=True)),
                ('expires', models.DateTimeField(null=True)),
                ('expires_in', models.IntegerField(null=True)),
                ('current_scope', models.JSONField(null=True)),
            ],
            options={
                'unique_together': {('b24_user_id', 'domain')},
            },
            bases=(models.Model, b24pysdk.credentials.bitrix_token.AbstractBitrixToken),
        ),
        migrations.CreateModel(
            name='ApplicationInstallation',
            fields=[
                ('id', models.UUIDField(default=uuid.uuid4, editable=False, primary_key=True, serialize=False)),
                ('status', models.CharField(choices=[('new', 'New'), ('active', 'Active'), ('deleted', 'Deleted'), ('blocked', 'Blocked')], default='new', max_length=50)),
                ('created_at_utc', models.DateTimeField(auto_now_add=True)),
                ('update_at_utc', models.DateTimeField(auto_now=True)),
                ('contact_person_id', models.UUIDField(help_text='Optional client contact person GUID (if provided).', null=True)),
                ('bitrix_24_partner_contact_person_id', models.UUIDField(help_text='Optional partner contact person GUID.', null=True)),
                ('bitrix_24_partner_id', models.UUIDField(help_text='Optional partner GUID supporting the portal.', null=True)),
                ('external_id', models.CharField(help_text='External CRM/ERP identifier linked to the installation.', max_length=255, null=True)),
                ('portal_license_family', models.CharField(help_text='Portal license family snapshot for analytics.', max_length=255, null=True)),
                ('portal_users_count', models.IntegerField(help_text='Portal users count snapshot for analytics.', null=True)),
                ('application_token', models.CharField(help_text='Application token mirrored for convenience.', max_length=255, null=True)),
                ('comment', models.TextField(help_text='Internal developer notes about the installation.', null=True)),
                ('status_code', models.JSONField(null=True)),
                ('bitrix_24_account', models.ForeignKey(help_text='Master Bitrix24 account that installed the application.', on_delete=django.db.models.deletion.CASCADE, to='bitrix_auth.bitrix24account')),
            ],
        ),
    ]
