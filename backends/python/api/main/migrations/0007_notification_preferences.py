from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0006_applicationinstallation_bitrix24account_and_more"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="notify_deal_responsible",
            field=models.BooleanField(default=True),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="notify_deal_timeline",
            field=models.BooleanField(default=True),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="notify_chat_id",
            field=models.IntegerField(null=True, blank=True),
        ),
    ]
