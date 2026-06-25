# 🐍 Python Backend: Руководство по Django-интеграции с Bitrix24

## 📋 Обзор

- Python backend живёт в `backends/python/django` и построен на Django. FastAPI в проекте не используется.
- Приложение `main` обслуживает REST-точки `/api*`, `bitrix_auth` хранит авторизацию и модели, `bitrix_events` обрабатывает события установки.
- Аутентификация комбинирует OAuth Bitrix24 (через `b24pysdk`) и собственные JWT, которые выпускаются на основе записей в таблице `bitrix24account`.
- Ниже описаны настройки, жизненный цикл запросов и практики разработки для поддержки и расширения текущей реализации.

---

## ⚙️ Стек и зависимости

### Основные технологии
- **Django** — веб-фреймворк, управляющий middleware, ORM, admin и точками входа WSGI/ASGI.
- **b24pysdk[signals]** — SDK для общения с Bitrix24 (OAuth, REST, события); extra `signals` нужен для сохранения обновленных OAuth-токенов.
- **PostgreSQL + psycopg2-binary** — БД по умолчанию, используется напрямую из Django.
- **PyJWT** — генерация и валидация внутренних JWT-токенов.
- **django-cors-headers** — заголовки CORS/X-Frame для работы внутри интерфейса Bitrix24.
- **environs** — загрузка конфигурации из `.env` / переменных окружения.
- **gunicorn** — WSGI-сервер в прод-режиме (см. `Dockerfile`).

### requirements.txt (`backends/python/django/requirements.txt`)
```txt
Django
psycopg2-binary
django-cors-headers
PyJWT
gunicorn
environs
b24pysdk[signals]==1.2.0
celery==5.4.0
kombu==5.3.5
```

---

## 🗂️ Структура проекта
```text
backends/python/django/
├── asgi.py / wsgi.py          # стандартные точки входа Django
├── config.py                  # dataclass Config + загрузка .env
├── Dockerfile                 # multi-stage (dev/prod)
├── manage.py                  # CLI Django
├── requirements.txt
├── settings.py / urls.py      # глобальные настройки и маршрутизация
├── bitrix_auth/               # Bitrix24Account, ApplicationInstallation, auth_required
├── bitrix_events/             # /api/app-events/ и Celery processor
└── main/                      # /api*, /api/health и т.д.
```

> Таблицы `bitrix24account` и `application_installation` описаны Django-моделями в `bitrix_auth/models.py`; схема Python backend создается и обновляется через Django migrations.

---

## 🔧 Конфигурация

### `config.py`
`Config` агрегирует параметры окружения через `environs.Env` и экспортируется как синглтон `config`. Все остальные модули (включая `settings.py` и модели) берут значения только отсюда.

| Переменная        | Назначение                                      | Значение по умолчанию |
|-------------------|--------------------------------------------------|-----------------------|
| `BUILD_TARGET`    | `dev`/`production`; управляет `DEBUG`            | `dev`                 |
| `DB_NAME`         | имя БД                                          | `appdb`               |
| `DB_USER`         | пользователь БД                                 | `appuser`             |
| `DB_PASSWORD`     | пароль БД                                       | `apppass`             |
| `DB_HOST` / `PORT`| адрес PostgreSQL (`database`/`5432` в Docker)    | `database` / `5432`   |
| `CLOUDPUB_TOKEN`  | токен CloudPub                                  | пусто                 |
| `JWT_SECRET`      | используется и как `SECRET_KEY` Django           | `default_jwt_secret`  |
| `JWT_ALGORITHM`   | алгоритм подписи JWT                            | `HS256`               |
| `CLIENT_ID`       | OAuth client ID приложения Bitrix24             | `client_id`           |
| `CLIENT_SECRET`   | OAuth client secret                             | `client_secret`       |
| `VIRTUAL_HOST`    | внешний URL; попадает в `CSRF_TRUSTED_ORIGINS`   | `app_base_url`        |

Доп. переменные (например, `ENABLE_RABBITMQ`) читаются Makefile'ом при запуске docker compose.

### `settings.py`
- `SECRET_KEY = config.jwt_secret`, `DEBUG` определяется `BUILD_TARGET`.
- `ALLOWED_HOSTS` и `CSRF_TRUSTED_ORIGINS` автоматически формируются из `VIRTUAL_HOST`, запасные домены — `localhost`, `api-python`.
- `INSTALLED_APPS` включает стандартный набор Django + `corsheaders` + `main`.
- `MIDDLEWARE` начинается с `CorsMiddleware`, чтобы корректно проставлять заголовки.
- `DATABASES['default']` использует `django.db.backends.postgresql_psycopg2` и параметры `Config`.
- `CORS_ALLOW_ALL_ORIGINS = True` — удобно для dev, но в проде лучше задавать белый список.

```python
INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "corsheaders",
    "main",
]
```

---

## 🚀 Запуск и локальная разработка

### Docker / Makefile
- `make dev-python` — основной сценарий, поднимает профили `frontend,python,cloudpub` (+ `queue`, если в `.env` `ENABLE_RABBITMQ=1`).
- `make prod-python` — собирает и запускает только Python backend в production-режиме.

### Без Docker
```bash
cd backends/python/django
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python manage.py migrate --noinput
python manage.py runserver 0.0.0.0:8000
```
Конвейер в `Dockerfile` автоматически запускает `makemigrations`, `migrate` и `createsuperuser --noinput`, но локально эти команды можно выполнять вручную.

### Dockerfile (кратко)
- **base**: `python:3.11-slim`, устанавливает `postgresql-client` и Python-зависимости.
- **dev**: монтирует проект как volume и запускает `runserver` после миграций.
- **prod**: копирует код в образ и стартует Gunicorn (`gunicorn wsgi:application --bind 0.0.0.0:8000`).

---

## 🧱 Приложение `main`

### URL-маршруты (`main/urls.py`)
| Метод | Путь             | View        | Описание |
|-------|------------------|-------------|----------|
| GET   | `/api`           | `root`      | Быстрый ответ «Python Backend is running»|
| GET   | `/api/health`    | `health`    | Health-check со статусом и timestamp |
| GET   | `/api/enum`      | `get_enum`  | Возвращает статический список опций |
| GET   | `/api/list`      | `get_list`  | Возвращает статический список элементов |
| POST  | `/api/install`   | `install`   | Создание/обновление `ApplicationInstallation` |
| POST  | `/api/getToken`  | `get_token` | Выдача нового JWT |
| POST  | `/api/app-events/` | `app_events` | Публичный endpoint событий Bitrix24 |

Все обработчики помечены `@xframe_options_exempt`, чтобы их можно было встраивать в iframe Bitrix24.

### Views (`main/views.py`)
- Простые GET-эндпоинты служат шаблоном — можно расширять их под нужды проекта.
- `install` сохраняет `ApplicationInstallation` для портала Bitrix24, используя поля из `request.bitrix24_account`.
- `get_token` вызывает `Bitrix24Account.create_jwt_token()` (TTL по умолчанию 60 минут).
- Защищенные view декорированы `@auth_required`, а неожиданные ошибки сериализует `LogErrorsMiddleware`.

### Декораторы и `AuthorizedRequest`
- `AuthorizedRequest` дополняет `HttpRequest` полем `bitrix24_account` для удобных type hints.
- `collect_request_data` объединяет JSON-тело, GET и POST-параметры в `request.data`, аккуратно обрабатывая списки значений.
- `auth_required`:
  1. Ищет заголовок `Authorization: Bearer <jwt>`.
  2. При наличии JWT вызывает `Bitrix24Account.get_from_jwt_token()` и кладёт объект в `request.bitrix24_account`.
  3. Если заголовок отсутствует — валидирует OAuth placement payload через SDK, создаёт или обновляет `Bitrix24Account` и кладёт объект в `request.bitrix24_account`.
  4. Все ошибки (`DoesNotExist`, `ExpiredSignature`, `BitrixValidationError`) переводятся в JSON-ответы со статусами 400/401.
### Модели (`bitrix_auth/models.py`)
- `Bitrix24Account` наследует `AbstractBitrixToken` и связан с таблицей `bitrix24account` (UUID PK). Важные методы:
  - `bitrix_app` — класс-свойство, строящее `BitrixApp` из `CLIENT_ID/CLIENT_SECRET`.
  - `get_client()` — враппер над `b24pysdk.Client` для работы с REST API.
  - `call_method(...)` — низкоуровневый REST-вызов с синхронизацией статусов по API/refresh ошибкам.
  - `create_jwt_token(minutes=60)` / `get_from_jwt_token` — выпуск и проверка внутренних токенов PyJWT.
  - Обработчики сигналов (`portal_domain_changed_signal`, `oauth_token_renewed_signal`) синхронизируют поля записи при событиях Bitrix24.
- `ApplicationInstallation` хранит статус установки приложения на портале и связан `ForeignKey` с мастер-аккаунтом `Bitrix24Account`.

### Админ-панель (`main/admin.py`)
- Обе модели зарегистрированы с динамическим `list_display`; поле `id` только для чтения.
- Суперпользователь для dev создаётся автоматически (команда `createsuperuser --noinput` в Docker). URL админки — `/api/admin/`.

---

## 🔄 Жизненный цикл установки и выдачи токенов
1. Bitrix24 вызывает backend и передаёт payload OAuth placement.
2. `collect_request_data` кладёт JSON + query-параметры в `request.data`.
3. `auth_required` валидирует OAuth placement payload через SDK и создаёт или обновляет `Bitrix24Account`.
4. После успешной авторизации:
   - `install` создаёт/обновляет `ApplicationInstallation`.
   - `get_token` выпускает JWT и отдаёт его клиенту.
5. Фронтенд сохраняет JWT и передаёт его в заголовке `Authorization` при всех будущих запросах; `auth_required` в этом случае просто валидирует токен и не обращается к API Bitrix24.

---

## 🛡️ Безопасность
- Храните `JWT_SECRET`, OAuth-ключи и параметры БД в `.env` / секретах CI/CD. В продакшене не используйте дефолтные значения.
- Регулярно обновляйте JWT (TTL задаётся параметром `minutes` в `create_jwt_token`). При истечении срока фронтенд должен заново вызвать `/api/getToken` или повторить OAuth-поток.
- `CSRF_TRUSTED_ORIGINS` формируется автоматически, но при работе с несколькими доменами Bitrix24 лучше явно перечислить их в `VIRTUAL_HOST` или расширить логику.
- В продакшене задайте `CORS_ALLOWED_ORIGINS` / `CORS_ALLOW_CREDENTIALS`, чтобы ограничить источники запросов.
- Подключите централизованный сбор логов (Sentry/ELK). Сейчас неожиданные ошибки сериализует `LogErrorsMiddleware`.

---

## 📦 Развёртывание
- Docker-образ собирается из `python:3.11-slim`. Следите, чтобы в `requirements.txt` не было лишних пакетов, иначе образ разрастётся.
- Перед деплоем обновите `.env`: параметры БД, OAuth, JWT, `VIRTUAL_HOST`.
- `docker compose --env-file .env up --build` использует указанные профили (`COMPOSE_PROFILES=python` для прод-режима).
- В Kubernetes/аналогах выполняйте `python manage.py migrate` отдельным job, чтобы исключить гонки миграций.

---

## 🧪 Тестирование (рекомендации)
- Настройте `pytest` + `pytest-django` или используйте встроенный `manage.py test`.
- Покройте:
  - `auth_required` (ветки JWT vs OAuth payload, ошибки PyJWT, BitrixValidationError).
  - `Bitrix24Account.create_jwt_token` / `get_from_jwt_token` (некорректный секрет, истечение срока).
  - Views `install`/`get_token` с mock'ами моделей и SDK.
- Для интеграционных тестов можно использовать `django.test.Client` и monkeypatch `b24pysdk`.

---

## 🐞 Траблшутинг
- **`Invalid JWT token`** — секрет в `.env` не совпадает с тем, что использовался при выдаче токена. Перевыпустите JWT через `/api/getToken`.
- **`JWT token has expired`** — увеличьте TTL или настроьте автообновление токена на фронте.
- **Ошибки CSRF/iframe** — проверьте `VIRTUAL_HOST` и корректность домена портала.
- **`BitrixValidationError`** при установке — проверьте, что в payload есть обязательные поля (`domain`, `member_id`, `auth[access_token]`, и т.д.).
- **Проблемы с БД** — убедитесь, что контейнер `database` запущен и доступен по `DB_HOST`.

---

## 📚 Дополнительные материалы
- `instructions/python/bitrix24-python-sdk.md` — детали работы с SDK и примеры REST-запросов.
- `instructions/python/code-review.md` — чек-лист ревью Python-кода.
- `instructions/queues/python.md` — рекомендации по фоновой обработке (Celery/RabbitMQ).
- `README.md` и `makefile` в корне описывают общую структуру docker-профилей и сценарии запуска стенда.

*Обновлено: 5 декабря 2025 года.*
