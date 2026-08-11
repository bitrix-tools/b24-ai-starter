from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0007_notification_preferences"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_entity_type_id",
            field=models.PositiveIntegerField(default=31),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_auto_update_stage",
            field=models.BooleanField(default=False),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_stage_id_paid",
            field=models.CharField(blank=True, default="", max_length=128),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_stage_id_partial",
            field=models.CharField(blank=True, default="", max_length=128),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_match_by_inn",
            field=models.BooleanField(default=False),
        ),
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="invoice_match_by_amount",
            field=models.BooleanField(default=True),
        ),
    ]
