# 🐘 PHP Backend: Общие знания для разработки с Битрикс24

## 📋 Обзор

Этот файл содержит **общую информацию по разработке PHP-приложений** для Битрикс24, не зависящую от конкретных задач. Для специфических инструкций обратитесь к соответствующим файлам в этой папке.

---

## 🚀 PHP экосистема для Битрикс24

### Основные инструменты

#### Bitrix24 PHP SDK
- **Библиотека**: `bitrix24/b24phpsdk` 
- **Версия в стартере**: `dev-v3-dev` (ветка v3, через `mesilov/bitrix24-php-lib` 0.5.2)
- **Требования стартера**: PHP 8.4, Symfony 7.4 LTS, Doctrine ORM 3
- **Лицензия**: MIT

#### Composer пакеты (типичные зависимости)
```json
{
  "require": {
    "mesilov/bitrix24-php-lib": "^0.5.2",
    "symfony/framework-bundle": "7.4.*",
    "doctrine/orm": "^3.7.4",
    "symfony/monolog-bundle": "^3.11.2"
  },
  "require-dev": {
    "phpstan/phpstan": "^1.12.34",
    "rector/rector": "^1.2.10",
    "friendsofphp/php-cs-fixer": "^3.95.27",
    "phpunit/phpunit": "^11.5.57"
  }
}
```

### Типичная архитектура PHP-проекта

> Фактическая структура стартера: `backends/php/src/{Controller,Service,Bitrix24Core,EventListener,EventSubscriber,DTO,Infrastructure}`, конфиги в `config/`, миграции в `migrations/`. Ниже — обобщённая схема.

```
project/
├── public/
│   ├── index.php           # Точка входа
│   └── webhook.php         # Обработчик вебхуков
├── src/
│   ├── Controllers/        # HTTP контроллеры
│   ├── Services/          # Бизнес-логика
│   ├── Models/            # Модели данных  
│   └── Config/            # Конфигурация
├── vendor/                # Composer зависимости
├── composer.json
└── .env                   # Переменные окружения
```

---

## 🔧 Основные паттерны разработки

### 1. Инициализация SDK

#### Webhook (простые интеграции)
```php
<?php
use Bitrix24\SDK\Services\ServiceBuilderFactory;

$serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook(
    'https://your-portal.bitrix24.com/rest/1/webhook_key/'
);
```

#### OAuth приложение (полноценные приложения)

В стартере `ServiceBuilder` для OAuth собирает `App\Bitrix24Core\Bitrix24ServiceBuilderFactory` (внедряется через DI):

```php
<?php
use App\Bitrix24Core\Bitrix24ServiceBuilderFactory;

// токены портала берутся из сохранённого Bitrix24Account
$serviceBuilder = $this->bitrix24ServiceBuilderFactory->createFromStoredTokenForDomain($domainUrl);

// также: createFromFrontendPayload(FrontendPayload) и createFromIncomingEvent(EventInterface)
```

Внутри используется `(new ServiceBuilderFactory($eventDispatcher, $logger))->init(...)` / `initFromAccount(...)` из SDK.

### 2. Работа с данными CRM

#### Типичный CRUD для сделок
```php
// Получение списка
$deals = $serviceBuilder->getCRMScope()->deal()->list(
    order: ['ID' => 'DESC'],
    filter: ['STAGE_ID' => 'NEW'],
    select: ['ID', 'TITLE', 'OPPORTUNITY', 'STAGE_ID']
)->getDeals();

// Получение одной записи
$deal = $serviceBuilder->getCRMScope()->deal()->get(123)->deal();

// Создание
$newDealId = $serviceBuilder->getCRMScope()->deal()->add([
    'TITLE' => 'Новая сделка',
    'OPPORTUNITY' => 100000,
    'CURRENCY_ID' => 'RUB'
])->getId();

// Обновление
$serviceBuilder->getCRMScope()->deal()->update(
    123,
    ['STAGE_ID' => 'WON']
);
```

### 3. Batch-запросы (оптимизация)

У сервисов сущностей есть свойство `batch` — генераторы, которые сами разбивают работу на batch-запросы по 50 команд:

```php
$dealBatch = $serviceBuilder->getCRMScope()->deal()->batch;

// Чтение всех сделок без ручной пагинации
foreach ($dealBatch->list(['ID' => 'ASC'], ['STAGE_ID' => 'NEW'], ['ID', 'TITLE']) as $deal) {
    // $deal — DealItemResult
}

// Массовое создание
foreach ($dealBatch->add([
    ['TITLE' => 'Сделка 1'],
    ['TITLE' => 'Сделка 2'],
]) as $addedItemResult) {
    $id = $addedItemResult->getId();
}
```

### 4. Обработка ошибок

```php
use Bitrix24\SDK\Core\Exceptions\BaseException;

try {
    $deal = $serviceBuilder->getCRMScope()->deal()->get(999999);
} catch (BaseException $e) {
    // Логирование ошибки
    error_log("Bitrix24 API Error: " . $e->getMessage());
    
    // Пользовательское сообщение
    return ['error' => 'Сделка не найдена'];
}
```

---

## 🏗️ Архитектурные подходы

### 1. MVC паттерн

#### Controller
```php
<?php
namespace App\Controllers;

class DealController 
{
    public function __construct(
        private DealService $dealService
    ) {}
    
    public function list(): array 
    {
        return $this->dealService->getActiveDeals();
    }
    
    public function show(int $id): array 
    {
        return $this->dealService->getDealById($id);
    }
}
```

#### Service (бизнес-логика)
```php
<?php
namespace App\Services;

class DealService 
{
    public function __construct(
        private ServiceBuilder $b24Service
    ) {}
    
    public function getActiveDeals(): array 
    {
        $result = $this->b24Service->getCRMScope()->deal()->list(
            order: [],
            filter: ['STAGE_ID' => ['NEW', 'PREPARATION', 'PROPOSAL']],
            select: ['ID', 'TITLE', 'OPPORTUNITY', 'STAGE_ID']
        );
        
        return $this->formatDealsForFrontend($result->getDeals());
    }
    
    public function formatDealsForFrontend(array $deals): array 
    {
        // Форматирование данных для фронтенда
        return array_map(function($deal) {
            return [
                'id' => $deal->ID,
                'title' => $deal->TITLE,
                'amount' => number_format($deal->OPPORTUNITY, 0, ',', ' '),
                'stage' => $deal->STAGE_ID
            ];
        }, $deals);
    }
}
```

### 2. Repository паттерн

```php
<?php
namespace App\Repositories;

interface DealRepositoryInterface 
{
    public function findById(int $id): ?Deal;
    public function findByStage(string $stage): array;
    public function save(Deal $deal): int;
}

class Bitrix24DealRepository implements DealRepositoryInterface 
{
    public function __construct(
        private ServiceBuilder $serviceBuilder
    ) {}
    
    public function findById(int $id): ?Deal 
    {
        try {
            $dealData = $this->serviceBuilder->getCRMScope()->deal()->get($id)->deal();
            return Deal::fromBitrix24Data($dealData);
        } catch (BaseException) {
            return null;
        }
    }
    
    public function findByStage(string $stage): array 
    {
        $result = $this->serviceBuilder->getCRMScope()->deal()->list(
            order: [],
            filter: ['STAGE_ID' => $stage],
            select: ['ID', 'TITLE', 'OPPORTUNITY', 'STAGE_ID']
        );
        
        return array_map(
            fn($data) => Deal::fromBitrix24Data($data),
            $result->getDeals()
        );
    }
}
```

---

## 🔐 Безопасность и best practices

### 1. Валидация и санитизация

```php
function validateDealData(array $data): array 
{
    $errors = [];
    
    // Обязательные поля
    if (empty($data['TITLE'])) {
        $errors[] = 'Название сделки обязательно';
    }
    
    // Валидация суммы
    if (isset($data['OPPORTUNITY'])) {
        if (!is_numeric($data['OPPORTUNITY']) || $data['OPPORTUNITY'] < 0) {
            $errors[] = 'Сумма должна быть положительным числом';
        }
    }
    
    // Санитизация строк
    if (isset($data['TITLE'])) {
        $data['TITLE'] = htmlspecialchars(trim($data['TITLE']));
    }
    
    return ['data' => $data, 'errors' => $errors];
}
```

### 2. Кэширование

```php
use Psr\Cache\CacheItemPoolInterface;

class CachedDealService 
{
    public function __construct(
        private DealService $dealService,
        private CacheItemPoolInterface $cache
    ) {}
    
    public function getDealById(int $id): ?array 
    {
        $cacheKey = "deal_{$id}";
        $cacheItem = $this->cache->getItem($cacheKey);
        
        if (!$cacheItem->isHit()) {
            $deal = $this->dealService->getDealById($id);
            $cacheItem->set($deal);
            $cacheItem->expiresAfter(300); // 5 минут
            $this->cache->save($cacheItem);
        }
        
        return $cacheItem->get();
    }
}
```

### 3. Логирование

```php
use Psr\Log\LoggerInterface;

class LoggingDealService 
{
    public function __construct(
        private DealService $dealService,
        private LoggerInterface $logger
    ) {}
    
    public function createDeal(array $data): int 
    {
        $this->logger->info('Creating new deal', ['data' => $data]);
        
        try {
            $dealId = $this->dealService->createDeal($data);
            $this->logger->info('Deal created successfully', ['id' => $dealId]);
            return $dealId;
        } catch (Exception $e) {
            $this->logger->error('Failed to create deal', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }
}
```

---

## 🐇 Очереди и фоновые задачи

Подробный чеклист по настройке Messenger + RabbitMQ вынесен в отдельный файл: [`instructions/queues/php.md`](../queues/php.md).

## 🧪 Тестирование

### Unit тесты

```php
<?php
use PHPUnit\Framework\TestCase;

class DealServiceTest extends TestCase 
{
    private DealService $dealService;
    
    protected function setUp(): void 
    {
        // Mock Bitrix24 service
        $mockB24Service = $this->createMock(ServiceBuilder::class);
        $this->dealService = new DealService($mockB24Service);
    }
    
    public function testFormatDealsForFrontend(): void 
    {
        $deals = [
            (object)['ID' => 1, 'TITLE' => 'Test Deal', 'OPPORTUNITY' => 50000]
        ];
        
        $result = $this->dealService->formatDealsForFrontend($deals);
        
        $this->assertEquals('50 000', $result[0]['amount']);
        $this->assertEquals('Test Deal', $result[0]['title']);
    }
}
```

### Integration тесты

```php
class Bitrix24IntegrationTest extends TestCase 
{
    public function testCreateAndRetrieveDeal(): void 
    {
        $serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook(
            $_ENV['BITRIX24_WEBHOOK_URL']
        );
        
        // Создаем тестовую сделку
        $dealId = $serviceBuilder->getCRMScope()->deal()->add([
            'TITLE' => 'Test Deal ' . time(),
            'OPPORTUNITY' => 1000
        ])->getId();
        
        $this->assertIsInt($dealId);
        
        // Получаем созданную сделку
        $deal = $serviceBuilder->getCRMScope()->deal()->get($dealId)->deal();
        $this->assertEquals('1000', $deal->OPPORTUNITY);
        
        // Удаляем тестовую сделку
        $serviceBuilder->getCRMScope()->deal()->delete($dealId);
    }
}
```

---

## 📊 Мониторинг и производительность

### 1. Профилирование запросов

```php
class ProfilingB24Service 
{
    private array $queryLog = [];
    
    public function logQuery(string $method, array $params, float $duration): void 
    {
        $this->queryLog[] = [
            'method' => $method,
            'params' => $params,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ];
    }
    
    public function getSlowQueries(float $threshold = 1.0): array 
    {
        return array_filter(
            $this->queryLog,
            fn($query) => $query['duration'] > $threshold
        );
    }
}
```

### 2. Rate limiting

```php
class RateLimitedB24Service 
{
    private int $requestCount = 0;
    private float $lastRequest = 0;
    
    public function makeRequest(callable $request): mixed 
    {
        // Битрикс24 лимит: ~2 запроса в секунду
        $now = microtime(true);
        if ($now - $this->lastRequest < 0.5) {
            usleep(500000); // Ждем 0.5 секунды
        }
        
        $this->lastRequest = microtime(true);
        $this->requestCount++;
        
        return $request();
    }
}
```

---

## 🔗 Интеграция с современными PHP-фреймворками

### Symfony

В стартере `ServiceBuilder` по вебхуку не регистрируется; для OAuth используется `App\Bitrix24Core\Bitrix24ServiceBuilderFactory`. Пример для вебхука:

```yaml
# config/services.yaml
services:
  Bitrix24\SDK\Services\ServiceBuilder:
    factory: ['Bitrix24\SDK\Services\ServiceBuilderFactory', 'createServiceBuilderFromWebhook']
    arguments:
      - '%env(BITRIX24_WEBHOOK_URL)%'
```

### Laravel

```php
// config/services.php
'bitrix24' => [
    'webhook_url' => env('BITRIX24_WEBHOOK_URL'),
],

// AppServiceProvider.php
public function register(): void
{
    $this->app->singleton(ServiceBuilder::class, function () {
        return ServiceBuilderFactory::createServiceBuilderFromWebhook(
            config('services.bitrix24.webhook_url')
        );
    });
}
```

---

## 📚 Специфические инструкции

### Детальные руководства в этой папке:

**➡️ SDK и API интеграция:** [`bitrix24-php-sdk.md`](bitrix24-php-sdk.md)

**➡️ Code Review стандарты:** [`code-review.md`](code-review.md)

---

## ⚠️ Часто встречающиеся проблемы

### 1. Превышение лимитов API

**Проблема:** Слишком много запросов к API
**Решение:** Использовать batch-запросы и кэширование

### 2. Работа с большими объемами данных

**Проблема:** Таймауты при загрузке больших списков
**Решение:** Использовать пагинацию и генераторы PHP

### 3. Управление токенами OAuth

**Проблема:** Токены истекают и приложение падает
**Решение:** Автоматическое обновление токенов через refresh token

---

*Обновлено: 7 октября 2026*
*Версия: 2.0 - Модульная архитектура знаний*