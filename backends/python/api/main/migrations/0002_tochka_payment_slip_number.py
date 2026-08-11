from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0001_tochka_portal_preferences"),
    ]

    operations = [
        migrations.RunSQL(
            "ALTER TABLE tochka_payments ADD COLUMN IF NOT EXISTS payment_slip_number VARCHAR(255);",
            reverse_sql="ALTER TABLE tochka_payments DROP COLUMN IF EXISTS payment_slip_number;",
        ),
    ]
