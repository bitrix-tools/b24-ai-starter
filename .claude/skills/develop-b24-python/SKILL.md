---
name: develop-b24-python
description: Develop backend applications for Bitrix24 using Python, Django, and b24pysdk. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in Python.
---

# Develop Bitrix24 Python Backend

## Quick Start

The Python backend is built with **Django** and uses **b24pysdk** for Bitrix24 interaction.

### Key Directories

*   `backends/python/django/main/views.py`: API endpoints.
*   `backends/python/django/bitrix_auth/models.py`: `Bitrix24Account` and `ApplicationInstallation`.
*   `backends/python/django/bitrix_auth/decorators/`: Authentication decorators.
*   `backends/python/django/bitrix_events/`: Bitrix24 lifecycle event processing.

## Creating API Endpoints

Use the `@auth_required` decorator to handle authentication (JWT or OAuth).

```python
from django.http import JsonResponse
from django.views.decorators.http import require_GET
from django.views.decorators.clickjacking import xframe_options_exempt
from bitrix_auth.decorators.auth_required import auth_required
from bitrix_auth.types import AuthorizedRequest

@xframe_options_exempt
@require_GET
@auth_required
def my_endpoint(request: AuthorizedRequest):
    # Access Bitrix24 account
    b24_account = request.bitrix24_account
    
    # Use the client to call Bitrix24 API
    client = b24_account.get_client()
    deals = client.crm.deal.list(select=["ID", "TITLE"]).result
    
    return JsonResponse({"data": deals})
```

## Bitrix24 Interaction (b24pysdk)

Use `request.bitrix24_account.get_client()` for typed SDK calls. Low-level REST calls through `Bitrix24Account.call_method(...)` automatically synchronize local account and installation statuses on Bitrix24 API errors.

### Common Operations

```python
# Call a single method
result = client.crm.deal.get(bitrix_id=123).result

# Batch request (if supported by SDK wrapper, otherwise use call_batch)
# Check b24pysdk documentation for specific batch syntax
```

## Authentication Flow

1.  **Installation**: `/api/install` receives OAuth data, creates or updates `Bitrix24Account`, creates a new `ApplicationInstallation`, and registers lifecycle events.
2.  **Token Issue**: `/api/getToken` issues a JWT for the frontend.
3.  **Requests**: Frontend sends JWT in `Authorization` header. `@auth_required` validates it and populates `request.bitrix24_account`.
4.  **Events**: `/api/app-events/` receives Bitrix24 lifecycle events and queues them through Celery.

## Database

*   **Models**: Defined in `bitrix_auth/models.py`.
*   **Migrations**: Run automatically in Docker, or manually via `python manage.py makemigrations` / `migrate`.
*   **Bitrix24Account**: Stores tokens and portal info.

## Best Practices

1.  **Decorators**: Use `@xframe_options_exempt` and `@auth_required` for protected API views.
2.  **Typing**: Use `AuthorizedRequest` for type hinting.
3.  **Error Handling**: Unhandled view errors are serialized by `LogErrorsMiddleware`; handle expected business errors inside the view.
4.  **Async**: Django views are synchronous by default. For long operations, use Celery (see `instructions/queues/python.md`).
