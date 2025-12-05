# 🐘 PHP + RabbitMQ

Эталонная интеграция через Symfony Messenger. Перед началом убедитесь, что в `.env` включён RabbitMQ и заполнены переменные (`RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_PREFETCH`, `RABBITMQ_DSN`).

## 1. Зависимости
```bash
composer require symfony/messenger enqueue/amqp-ext
```

## 2. Конфигурация (`config/packages/messenger.yaml`)
```yaml
framework:
  messenger:
    transports:
      async:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        options:
          prefetch_count: '%env(int:RABBITMQ_PREFETCH)%'
    routing:
      App\Message\Bitrix24EventMessage: async
```

В `.env` добавьте:
```
MESSENGER_TRANSPORT_DSN=amqp://%env(RABBITMQ_USER)%:%env(RABBITMQ_PASSWORD)%@rabbitmq:5672/%2f
```

## 3. Сообщение и обработчик
```php
// src/Message/Bitrix24EventMessage.php
namespace App\Message;

final class Bitrix24EventMessage
{
    public function __construct(
        public readonly string $eventCode,
        public readonly array $payload,
    ) {}
}
```

```php
// src/MessageHandler/Bitrix24EventMessageHandler.php
namespace App\MessageHandler;

use App\Message\Bitrix24EventMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class Bitrix24EventMessageHandler
{
    public function __construct(
        private readonly Bitrix24ServiceBuilderFactory $factory,
    ) {}

    public function __invoke(Bitrix24EventMessage $message): void
    {
        $service = $this->factory->createFromStoredTokenForDomain(
            $message->payload['domain']
        );

        // Пример: загрузка контакта из Bitrix24
        $service->getCRMScope()->contact()->get(
            (int) $message->payload['contactId']
        );
    }
}
```

## 4. Публикация сообщений
```php
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\Bitrix24EventMessage;

final class B24EventsController extends AbstractController
{
    public function __construct(private MessageBusInterface $bus) {}

    public function processEvent(Request $request): JsonResponse
    {
        $payload = $request->request->all();
        $this->bus->dispatch(
            new Bitrix24EventMessage($payload['event'], $payload)
        );
        return new JsonResponse(['status' => 'queued']);
    }
}
```

## 5. Запуск воркера
```bash
COMPOSE_PROFILES=php-cli,queue docker compose run --rm php-cli \
  php bin/console messenger:consume async --time-limit=3600
```

### Совет
Добавьте собственный сервис `php-worker` в `docker-compose.override.yml`, если хотите постоянный фоновой процесс.

