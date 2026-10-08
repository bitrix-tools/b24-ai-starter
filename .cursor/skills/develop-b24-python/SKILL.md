---
name: develop-b24-python
description: Develop backend applications for Bitrix24 using Python, Django, and b24pysdk. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in Python.
---

# Develop Bitrix24 Python Backend

## Quick Start

The Python backend is built with **Django 6.1** and uses **b24pysdk 1.3** (`b24pysdk[signals,django]`) for Bitrix24 interaction.

### Key Directories

* `backends/python/django/main/views.py` + `main/urls.py`: API endpoints (`/api`, `/api/health`, `/api/enum`, `/api/list`, `/api/install`, `/api/getToken`; no trailing slash). Public: `/api/health`, `/api/install`, `/api/getToken`, `/api/app-events/`; `GET /api`, `/api/enum`, `/api/list` need JWT.
* `backends/python/django/bitrix_auth/models.py`: `Bitrix24Account` and `ApplicationInstallation`.
* `backends/python/django/bitrix_auth/decorators/`: Authentication decorators.
* `backends/python/django/bitrix_events/`: Bitrix24 lifecycle event processing (`/api/app-events/`).
* `backends/python/django/middleware.py`: `LogErrorsMiddleware`.
* `backends/python/django/celery_app.py`: Celery app.

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

# Batch request: pass prepared (not yet called) requests to call_batch
# (same pattern as install() in main/views.py)
client.call_batch([
    client.crm.deal.get(bitrix_id=1),
    client.crm.deal.get(bitrix_id=2),
]).call()
```

## Authentication Flow

1. **Installation**: `/api/install` (protected by `@auth_required`, which validates the Bitrix24 OAuth data) receives OAuth data, creates or updates `Bitrix24Account`, creates a new `ApplicationInstallation`, and registers lifecycle events.
2. **Token Issue**: `/api/getToken` is also behind `@auth_required` — the caller's Bitrix24 auth data is verified through b24pysdk before a JWT is issued via `Bitrix24Account.create_jwt_token()`.
3. **Requests**: Frontend sends JWT in `Authorization` header. `@auth_required` validates it and populates `request.bitrix24_account`.
4. **Events**: `/api/app-events/` receives Bitrix24 lifecycle events; with `ENABLE_RABBITMQ=1` they are queued through Celery (`python-worker`), otherwise processed inline (`bitrix_events/views.py: dispatch_event`).
5. **Install guards**: `/api/install` rejects a Bearer JWT (needs fresh Bitrix24 auth data) and requires a portal administrator.

## Database

* **Models**: Defined in `bitrix_auth/models.py`.
* **Migrations**: create with `python manage.py makemigrations` and commit them; the container only runs `migrate` (CI fails on missing migrations: `makemigrations --check`).
* **Tests**: `python manage.py test` — DB-free `SimpleTestCase`s in `tests/` (run in CI).
* **Bitrix24Account**: Stores tokens and portal info.

## Best Practices

1. **Decorators**: Use `@xframe_options_exempt` and `@auth_required` for protected API views.
2. **Typing**: Use `AuthorizedRequest` for type hinting.
3. **Error Handling**: Unhandled view errors are serialized by `LogErrorsMiddleware`; handle expected business errors inside the view.
4. **Async**: Django views are synchronous by default. For long operations, use Celery (see `instructions/queues/python.md`).
