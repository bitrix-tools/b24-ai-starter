---
name: develop-b24-php
description: Develop backend applications for Bitrix24 using PHP, Symfony, and Bitrix24 PHP SDK. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in PHP.
---

# Develop Bitrix24 PHP Backend

## Quick Start

The PHP backend is built with **Symfony** and uses **bitrix24/b24phpsdk** for Bitrix24 interaction.

### Key Directories

* `backends/php/src/Controller/`: API endpoints.
* `backends/php/src/Service/`: Business logic.
* `backends/php/src/Bitrix24Core/`: Core integration logic (OAuth, Events).

## Creating API Endpoints

Use Symfony attributes for routing and dependency injection.

```php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Bitrix24\SDK\Services\ServiceBuilder;

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

The `ServiceBuilder` is typically available via dependency injection if configured, or you can create it manually for specific contexts (e.g., webhook).

```php
use Bitrix24\SDK\Services\ServiceBuilderFactory;

// From Webhook
$serviceBuilder = ServiceBuilderFactory::createServiceBuilderFromWebhook('webhook_url');

// From OAuth (in a service context, often handled by core logic)
// See Bitrix24ServiceBuilderFactory.php
```

### Common Operations

```php
// CRM Scope
$crm = $serviceBuilder->getCRMScope();
$deals = $crm->deal()->list([], ['ID', 'TITLE']);

// Batch Requests
$batch = $serviceBuilder->getBatchService();
// ... see SDK docs for batch details
```

## Authentication Flow

1. **Installation**: `/api/install` (handled by `AppLifecycleController`) receives OAuth data.
2. **Token Issue**: `/api/getToken` issues a JWT for the frontend.
3. **Requests**: Frontend sends JWT in `Authorization` header. `JwtAuthenticationListener` validates it and sets `jwt_payload` in request attributes.

## Database

* **ORM**: Doctrine.
* **Migrations**: `php bin/console doctrine:migrations:migrate`.
* **Entities**: Located in `src/Entity/` (if any custom entities are added).

## Best Practices

1. **Dependency Injection**: Inject services into controllers.
2. **Logging**: Use `LoggerInterface` for logging.
3. **Error Handling**: Wrap logic in `try/catch` and return `JsonResponse` with error details.
4. **Strict Types**: Use `declare(strict_types=1);`.
