from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0002_tochka_payment_slip_number"),
    ]

    operations = [
        migrations.RunSQL(
            "ALTER TABLE tochka_payments ADD COLUMN IF NOT EXISTS matched_lead_id INTEGER;",
            reverse_sql="ALTER TABLE tochka_payments DROP COLUMN IF EXISTS matched_lead_id;",
        ),
    ]
