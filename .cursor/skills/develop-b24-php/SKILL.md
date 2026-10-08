---
name: develop-b24-php
description: Develop backend applications for Bitrix24 using PHP, Symfony, and Bitrix24 PHP SDK. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in PHP.
---

# Develop Bitrix24 PHP Backend

## Quick Start

The PHP backend is built with **Symfony 7.4 LTS** (PHP 8.4, Doctrine ORM 3) and uses **bitrix24/b24phpsdk** for Bitrix24 interaction.

### Key Directories

* `backends/php/src/Controller/`: API endpoints (`ApiController`: `/api/getToken`, `/api/list`, `/api/enum`, `/api/health`; `B24EventsController`: `/api/custom-b24-events/`; `TelemetryController`).
* `backends/php/src/Service/`: Business logic (`JwtService`, `Telemetry/`).
* `backends/php/src/Bitrix24Core/`: Core integration logic (`AppLifecycleController` for `/api/install`, `AppLifecycleEventController` for `/api/app-events/`, `Bitrix24ServiceBuilderFactory`).
* `backends/php/src/EventListener/JwtAuthenticationListener.php`: JWT check; public routes are listed in `PUBLIC_ROUTES` (prefix match).

## Creating API Endpoints

Use Symfony attributes for routing and dependency injection.

```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class MyController extends AbstractController
{
    #[Route('/api/my-endpoint', name: 'api_my_endpoint', methods: ['GET'])]
    public function myEndpoint(Request $request): JsonResponse
    {
        // JWT payload is available in request attributes
        $jwtPayload = $request->attributes->get('jwt_payload');
        
        // Return JSON response
        return new JsonResponse(['data' => 'value'], 200);
    }
}
```

## Bitrix24 Interaction (PHP SDK)

Use `ServiceBuilder` to interact with Bitrix24 API.

### Initialization

For the portal that installed the app, inject `App\Bitrix24Core\Bitrix24ServiceBuilderFactory` and use `createFromStoredTokenForDomain()`, `createFromFrontendPayload()` or `createFromIncomingEvent()`. For a webhook, use the SDK factory directly.

```php
use Bitrix24\SDK\Services\ServiceBuilderFactory;

// From Webhook
$serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook('webhook_url');

// From stored OAuth tokens (inject Bitrix24ServiceBuilderFactory)
$serviceBuilder = $this->bitrix24ServiceBuilderFactory->createFromStoredTokenForDomain($domainUrl);
```

### Common Operations

```php
// CRM Scope
$crm = $serviceBuilder->getCRMScope();
// list(array $order, array $filter, array $select, int $startItem = 0)
$deals = $crm->deal()->list([], [], ['ID', 'TITLE'])->getDeals();

// Batch: generator over all items, paged automatically
foreach ($crm->deal()->batch->list([], [], ['ID', 'TITLE']) as $deal) {
    // ...
}
```

## Authentication Flow

1. **Installation**: `/api/install` (handled by `AppLifecycleController`) receives OAuth data.
2. **Token Issue**: `/api/getToken` (`ApiController`) calls `App\Bitrix24Core\FrontendAuthVerifier`: first the portal must have an installed account here (`member_id` + `DOMAIN`, status `new`/`active`) — checked locally, no network call; then the caller's `AUTH_ID` is checked by the Bitrix24 OAuth server (`/rest/app.info/`; the SDK's `DefaultOAuthServerUrl` first, then the other region — fixed trusted hosts, never the portal from the request). It must return our `client_id`, the same `DOMAIN`/`member_id` and `install.installed: true`. Errors: 400 / 401 / 503 (OAuth server unreachable). The refresh token is never sent, so verification cannot renew the stored tokens. Only then a JWT is issued; otherwise 400/401. Never log request bodies directly — wrap them in `App\Service\LogRedactor::redact()`. Tests: `tests/Security/`.
3. **Requests**: Frontend sends JWT in `Authorization` header. `JwtAuthenticationListener` validates it and sets `jwt_payload`, `jwt_domain`, `jwt_member_id` in request attributes. Note: in PHP `/api/health` is also public.

## Database

* **ORM**: Doctrine.
* **Migrations**: `backends/php/migrations/`; run `make dev-php-db-migrate` (or `php bin/console doctrine:migrations:migrate` inside the container).
* **Entities**: Bitrix24 account/installation entities come from `mesilov/bitrix24-php-lib` (mapping in `config/doctrine/`); there is no `src/Entity/` yet — create it for custom entities.

## Best Practices

1. **Dependency Injection**: Inject services into controllers.
2. **Logging**: Use `LoggerInterface` for logging.
3. **Error Handling**: Wrap logic in `try/catch` and return `JsonResponse` with error details.
4. **Strict Types**: Use `declare(strict_types=1);`.
