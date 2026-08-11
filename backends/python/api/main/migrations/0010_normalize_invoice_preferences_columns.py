"""Нормализовать имена и состав invoice-колонок в tochka_portal_preferences."""

from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ("main", "0009_remove_invoice_entity_type_id"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
            DO $$
            BEGIN
              -- Legacy -> current names (если таблица из старой версии)
              IF EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='auto_update_smart_invoice_status'
              ) AND NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='invoice_auto_update_stage'
              ) THEN
                ALTER TABLE tochka_portal_preferences
                  RENAME COLUMN auto_update_smart_invoice_status TO invoice_auto_update_stage;
              END IF;

              IF EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='invoice_stage_paid_id'
              ) AND NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='invoice_stage_id_paid'
              ) THEN
                ALTER TABLE tochka_portal_preferences
                  RENAME COLUMN invoice_stage_paid_id TO invoice_stage_id_paid;
              END IF;

              IF EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='invoice_stage_partial_id'
              ) AND NOT EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_name='tochka_portal_preferences' AND column_name='invoice_stage_id_partial'
              ) THEN
                ALTER TABLE tochka_portal_preferences
                  RENAME COLUMN invoice_stage_partial_id TO invoice_stage_id_partial;
              END IF;

              -- Unused legacy column
              ALTER TABLE tochka_portal_preferences
                DROP COLUMN IF EXISTS smart_invoice_entity_type_id;

              -- Ensure required flags exist
              ALTER TABLE tochka_portal_preferences
                ADD COLUMN IF NOT EXISTS invoice_match_by_inn boolean NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS invoice_match_by_amount boolean NOT NULL DEFAULT true;

              -- Ensure stage/status columns exist on all environments
              ALTER TABLE tochka_portal_preferences
                ADD COLUMN IF NOT EXISTS invoice_auto_update_stage boolean NOT NULL DEFAULT false,
                ADD COLUMN IF NOT EXISTS invoice_stage_id_paid varchar(128) NOT NULL DEFAULT '',
                ADD COLUMN IF NOT EXISTS invoice_stage_id_partial varchar(128) NOT NULL DEFAULT '';
            END
            $$;
            """,
            reverse_sql=migrations.RunSQL.noop,
        ),
    ]
