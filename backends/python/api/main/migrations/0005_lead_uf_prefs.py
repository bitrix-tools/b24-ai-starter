from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0004_acquiring_ledger_prefs"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="lead_payment_kind_uf_code",
            field=models.CharField(blank=True, default="", max_length=64),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="lead_payment_channel_uf_code",
            field=models.CharField(blank=True, default="", max_length=64),
        ),
    ]
