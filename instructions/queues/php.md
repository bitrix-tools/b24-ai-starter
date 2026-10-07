# 🐘 PHP + RabbitMQ

Эталонная интеграция через Symfony Messenger. Перед началом убедитесь, что в `.env` включён RabbitMQ и заполнены переменные (`RABBITMQ_USER`, `RABBITMQ_PASSWORD`, `RABBITMQ_PREFETCH`, `RABBITMQ_DSN`).

## 1. Зависимости
```bash
make composer require symfony/messenger symfony/amqp-messenger
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

В `.env` добавьте (синтаксис `%env()%` работает только в YAML-конфигах Symfony, не в `.env`):
```
MESSENGER_TRANSPORT_DSN=amqp://queue_user:queue_password@rabbitmq:5672/%2f
```
Значение должно совпадать с `RABBITMQ_DSN`; можно и сразу указать `dsn: '%env(RABBITMQ_DSN)%'` в `messenger.yaml`. Транспорт `amqp://` требует PHP-расширение `amqp` (в образе `php-cli` оно установлено, в `php-fpm` — нет: добавьте его в `backends/php/docker/php-fpm/Dockerfile`, если публикуете сообщения из веб-запросов).

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

