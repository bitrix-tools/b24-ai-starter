from django.db import migrations, models


class Migration(migrations.Migration):
    dependencies = [
        ("main", "0016_alter_tochkaportalpreferences_deal_matching"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="last_sync_at",
            field=models.DateTimeField(blank=True, null=True),
        ),
    ]
