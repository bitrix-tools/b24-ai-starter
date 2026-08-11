"""Убрать неиспользуемое поле invoice_entity_type_id (классические счета crm.invoice)."""

from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0008_invoice_stage_prefs"),
    ]

    operations = [
        migrations.SeparateDatabaseAndState(
            database_operations=[
                migrations.RunSQL(
                    sql="""
                    ALTER TABLE tochka_portal_preferences
                    DROP COLUMN IF EXISTS invoice_entity_type_id;
                    """,
                    reverse_sql=migrations.RunSQL.noop,
                ),
            ],
            state_operations=[
                migrations.RemoveField(
                    model_name="tochkaportalpreferences",
                    name="invoice_entity_type_id",
                ),
            ],
        ),
    ]
