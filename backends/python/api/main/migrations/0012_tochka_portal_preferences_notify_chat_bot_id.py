from django.db import migrations, models


class Migration(migrations.Migration):
    dependencies = [
        ("main", "0011_payment_notify_log"),
    ]

    operations = [
        migrations.AddField(
            model_name="tochkaportalpreferences",
            name="notify_chat_bot_id",
            field=models.PositiveIntegerField(
                null=True,
                blank=True,
                help_text="ID чат-бота Bitrix24 для imbot.v2.Chat.Message.send; если пусто — im.message.add",
            ),
        ),
    ]
