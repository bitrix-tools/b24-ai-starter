"""Банковские строки, привязанные к сделке: status matched → applied."""

from django.db import migrations


class Migration(migrations.Migration):
    dependencies = [
        ("main", "0013_alter_tochkaportalpreferences_notify_chat_bot_id"),
    ]

    operations = [
        migrations.RunSQL(
            sql="""
                UPDATE tochka_payments
                SET status = 'applied'
                WHERE status = 'matched'
                  AND matched_deal_id IS NOT NULL;
            """,
            reverse_sql="""
                UPDATE tochka_payments
                SET status = 'matched'
                WHERE status = 'applied'
                  AND matched_deal_id IS NOT NULL;
            """,
        ),
    ]
