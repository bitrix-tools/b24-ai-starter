# 🟢 Node.js + RabbitMQ

Пример интеграции на базе `amqplib`. Работает как с JavaScript, так и с TypeScript (ниже TS-файлы).

## 1. Зависимости
```bash
pnpm add amqplib
pnpm add -D typescript tsx @types/node # если используете TS
```

## 2. Клиент (`src/queue/rabbitmq.ts`)
```typescript
import amqp, { Connection, Channel } from "amqplib";

export class RabbitMQClient {
  private connection?: Connection;
  private channel?: Channel;

  async connect(url: string): Promise<Channel> {
    this.connection = await amqp.connect(url);
    this.channel = await this.connection.createChannel();
    return this.channel;
  }

  async close(): Promise<void> {
    await this.channel?.close();
    await this.connection?.close();
  }
}
```

## 3. Публикатор (`src/services/queuePublisher.ts`)
```typescript
import { RabbitMQClient } from "../queue/rabbitmq";

export const publishEvent = async (
  queue: string,
  payload: Record<string, unknown>,
): Promise<void> => {
  const client = new RabbitMQClient();
  const channel = await client.connect(process.env.RABBITMQ_DSN!);

  await channel.assertQueue(queue, { durable: true });
  channel.sendToQueue(queue, Buffer.from(JSON.stringify(payload)), {
    persistent: true,
  });

  await client.close();
};
```

## 4. Консюмер (`workers/eventWorker.ts`)
```typescript
import { RabbitMQClient } from "../src/queue/rabbitmq";

const QUEUE = "bitrix24.events";

async function bootstrap() {
  const client = new RabbitMQClient();
  const channel = await client.connect(process.env.RABBITMQ_DSN!);

  await channel.assertQueue(QUEUE, { durable: true });
  channel.prefetch(Number(process.env.RABBITMQ_PREFETCH || "5"));

  channel.consume(QUEUE, async (message) => {
    if (!message) {
      return;
    }

    const payload = JSON.parse(message.content.toString());
    // TODO: обработайте событие Bitrix24

    channel.ack(message);
  });
}

bootstrap().catch((error) => {
  console.error("Worker failed", error);
  process.exit(1);
});
```

## 5. Переменные окружения
```
RABBITMQ_DSN=amqp://queue_user:queue_password@rabbitmq:5672/
```

## 6. Запуск воркера
```bash
COMPOSE_PROFILES=node,queue docker compose --env-file .env run --rm \
  api-node node workers/eventWorker.js
```

> Добавьте отдельный Docker-сервис или используйте pm2, если требуется постоянный фоновой процесс.

