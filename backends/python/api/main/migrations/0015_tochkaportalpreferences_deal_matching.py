from django.db import migrations, models


class Migration(migrations.Migration):
    dependencies = [
        ("main", "0014_deal_bound_payments_status_applied"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="deal_matching",
            field=models.JSONField(blank=True, default=dict),
        ),
    ]
