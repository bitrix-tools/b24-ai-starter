# План проверки

<sub>Last reviewed: 2026-10-08.</sub>

Что проверять и как — после обновления зависимостей (Nuxt 4.6, JS SDK 3, Symfony 7.4, Django 6.1, Node 24, pnpm 12) и фикса переустановки (#8). Уровни идут от дешёвых к дорогим: сначала автоматика, потом Docker, потом реальный портал.

## Уровень 0 — CI (автоматически на каждый PR)

| Джоба | Что ловит | Чего **не** ловит |
| --- | --- | --- |
| Frontend | lint, typecheck, unit-тесты (`frontend/test/`), сборка | Поведение в iframe портала, вызовы REST |
| Node backend | `pnpm install --frozen-lockfile`, синтаксис, `pnpm test` (жизненный цикл установки/событий по HTTP) | Запуск сервера, SQL на реальной БД (`test/accounts.db.test.js` пропускается без `TEST_PG_URL`/`TEST_MYSQL_URL`) |
| Python backend | `manage.py check`, рассинхрон миграций, `manage.py test` (защита install, диспетчеризация событий) | Запуск, OAuth, работу с БД |
| PHP backend | `composer validate` (lock ↔ composer.json) | Установку зависимостей, контейнер Symfony, тесты |
| Repo lint | actionlint, markdownlint | — |

**Пробелы, которые стоит закрыть в CI** (по одному PR, по мере надобности):

- PHP: `composer install` + `bin/console lint:container` + `phpunit` (`backends/php/tests/`). Сейчас блокируется dev-зависимостями из GitHub — нужен `COMPOSER_AUTH` с токеном в секретах.
- Сборка Docker-образов (`docker compose build`) — ловит ошибки Dockerfile, как пропущенный `pnpm-workspace.yaml`.
- Smoke-запуск бэкенда с БД: `/api/health` отвечает 200 без токена, `/api/enum` — 401.

## Уровень 1 — Docker локально (без портала)

Для **каждого** бэкенда: `make dev-init` → `make dev-php` / `dev-python` / `dev-node`.

| Проверка | Как | Ожидание |
| --- | --- | --- |
| Контейнеры поднимаются | `make ps`, `make logs` | Нет рестартов, нет ошибок старта |
| Фронтенд собирается в dev | `make logs` (frontend) | `pnpm install --frozen-lockfile` проходит под pnpm 12, Nuxt стартует |
| Бэкенд жив | `curl http://localhost:<порт>/` | 200 |
| JWT обязателен | `curl .../api/health`, `.../api/enum`, `.../api/list` без токена | `health` — 200 (публичный), `enum` / `list` — 401 |
| Открытые эндпоинты | `POST /api/install` | Не 401 (ошибка валидации ок) |
| `getToken` не выдаёт токен кому угодно | `POST /api/getToken` с выдуманными `DOMAIN`/`member_id`/`AUTH_ID` | `400`/`401`, **не** `200` с токеном |
| Безопасность | `make security-tests` | Все проверки зелёные |
| PHP-тесты | `make test-php` | Все тесты проходят (346 на момент обновления: security + telemetry) |
| Миграции PHP | `make dev-php-db-migrate-status` | Нет непримененных / ошибок |
| Prod-сборка | `make prod-php` / `prod-python` / `prod-node` | Собирается стадия `production`, исходники не смонтированы (`docker compose ... config`), приложение отвечает |

Матрица БД: PHP и Python — PostgreSQL **и** MySQL (`DB_TYPE` в `.env`); Node — PostgreSQL **и** MySQL (`db/accounts.js`; `pnpm test` с `TEST_PG_URL` / `TEST_MYSQL_URL`).

## Уровень 2 — реальный портал Bitrix24

Нужен тестовый портал и туннель (Cloudpub, `make dev-init`). Проверять на одном бэкенде полностью, на остальных — сценарии 1–3.

1. **Установка** — открыть приложение впервые: страница `/install` проходит все шаги (placement, userfield), `/api/install` сохраняет аккаунт.
2. **Переустановка (#8)** — удалить приложение и установить снова. Ожидание: без `SQLSTATE[23505]` / 500. Повторить на PostgreSQL и MySQL.
   - На базе, созданной до #23, сначала `make db-upgrade` (скрипты `infrastructure/database/upgrades/`, проверены на старой схеме PostgreSQL 17 и MySQL 8.4).
3. **Главная страница** — `index`: кнопки «enum» / «list» возвращают данные с бэкенда (JWT через `/api/getToken` работает), смена языка портала меняет язык UI.
   - После фикса выдачи JWT: токен выдаётся сразу после установки (PHP принимает аккаунт в статусе `new`), `/api/getToken` проверяет `AUTH_ID` через OAuth-серверы Bitrix24 (`oauth.bitrix.info`, `oauth.bitrix24.tech`) — убедиться, что они доступны из контейнера бэкенда (сеть/прокси); при недоступности ответ `503`.
4. **Слайдер настроек** — `slider/app-options`: сохранение настроек приложения, закрытие слайдера, pull-событие `reload.options`.
5. **Вкладка сделки** — `handler/placement-crm-deal-detail-tab` в карточке сделки CRM: открывается, `B24*`-компоненты рендерятся, индикатор загрузки (после замены `useDashboard().isLoading` на локальный `ref`).
6. **Пользовательское поле** — `handler/uf.demo`: значение `placement.options.VALUE` читается и сохраняется (после явного приведения типов).
7. **События** — `/api/app-events/` (PHP, Python): `ONAPPINSTALL`, `ONAPPUNINSTALL` доходят, токен проверяется.
8. **Логи** — в консоли браузера логи `LoggerFactory` в dev-режиме видны, в production — нет; токены в логах не появляются.

## Уровень 3 — регрессия процессов

- Dependabot открыл первые PR (через неделю) — CI на них зелёный, игнор TS 7 / Symfony 8 работает.
- `markdownlint` и `actionlint` ловят ошибки (намеренно сломать в черновом PR).

## Известные риски, найденные при обновлении

- **PHP** в CI проверяется только `composer validate`; тесты (`make test-php`, 346) и `lint:container` проверены вручную в `php:8.4-cli`, образ `php-fpm` не собирался — первый кандидат на уровень 1.
- **pnpm 12** не ставит пакеты моложе суток (`minimumReleaseAge`) и требует решения по build-скриптам (`frontend/pnpm-workspace.yaml`, `allowBuilds`). Новый пакет с postinstall сломает `install` до явного разрешения.

## Найденные проблемы в коде (кандидаты в issues)

Найдены при сверке документации с кодом. ✅ — исправлено (см. `CHANGELOG.md`); остальные — в плане работ.

| # | Проблема | Где | Как проверить |
| --- | --- | --- | --- |
| 1 | ✅ `make prod-*` передаёт `FRONTEND_TARGET`, а compose читает `BUILD_TARGET` — образы собираются в dev-режиме; поднимается только профиль бэкенда (без фронтенда и БД) | `makefile`, `docker-compose.yml` | `make prod-php`, затем `docker compose ps` и `docker inspect` target |
| 2 | ✅ `make down-all` ссылается на несуществующий `docker-compose.server.yml` | `makefile` | `make down-all` |
| 3 | ✅ `DOCKER_COMPOSE = docker-compose` (v1), а `dev-init.sh` / `security-tests.sh` используют `docker compose` (v2) — на хосте только с v2 make-цели падают | `makefile`, `scripts/fix-php.sh` | Запуск на чистой машине с Docker Compose v2 |
| 4 | ✅ `make down` не останавливает профили `db-*` и `python-worker` | `makefile` | `make down` → `docker ps` |
| 5 | ✅ `/api/health` публичный в PHP, но под JWT в Python и Node | бэкенды | `curl /api/health` без токена на каждом |
| 6 | ✅ В Node нет `/api/app-events/`, хотя он описан как общий; у PHP есть лишний публичный `/api/custom-b24-events/` | `backends/node/api/server.js`, PHP-контроллеры | Сценарий 7 уровня 2 на Node |
| 7 | ✅ Python при `ENABLE_RABBITMQ=0`: `python-worker` не стартует, события в Celery не обрабатываются | `docker-compose.yml`, `bitrix_events` | Отправить событие с выключенным RabbitMQ |
| 8 | ✅ `fix-php.sh` удаляет `composer.lock` — противоречит политике lock-файлов | `scripts/fix-php.sh` | Код-ревью |
| 9 | ✅ В образе `php-fpm` нет расширения `amqp` (есть только в `php-cli`) — публикация в Messenger из веб-запроса упадёт | `backends/php/docker/php-fpm/Dockerfile` | `docker compose exec api php -m \| grep amqp` |
| 10 | ✅ Для #8 нет миграции существующих БД — только init-скрипты | `infrastructure/database/` | Переустановка на старом томе PostgreSQL и MySQL |
| 11 | ✅ Node `/api/install` — заглушка: токены не сохраняются, события не привязываются; пул БД создаётся, но не используется | `backends/node/api/server.js` | Сценарий 1 уровня 2 на Node |
| 12 | ✅ Ответ `/api/install` различается: Python — JSON `Installation successful`, Node — JSON `All success`, PHP — текст `OK` | бэкенды | `curl -X POST /api/install` |
| 13 | ✅ Python: `DEBUG = True` захардкожен; при пустом `VIRTUAL_HOST` в `ALLOWED_HOSTS` попадает `None` | `backends/python/django/settings.py` | Prod-запуск с пустым `VIRTUAL_HOST` |
| 14 | ✅ Python Dockerfile выполняет `makemigrations` при старте (dev и prod) — миграции генерируются в рантайме | `backends/python/django/Dockerfile` | `git status` после `make dev-python` |
| 15 | ✅ `pnpm translate-ui` ссылается на несуществующий `frontend/tools/`; инструментов сборки статического приложения нет | `frontend/package.json` | `pnpm translate-ui` |
| 16 | Схема БД для PHP создаётся init-скриптами, а единственная Doctrine-миграция не содержит уникального индекса из #8 | `backends/php/migrations/`, `infrastructure/database/` | `make dev-php-db-migrate` на пустой БД без init-скриптов |
| 17 | Смена домена портала не обрабатывается: после переименования PHP `/api/getToken` отвечает 401 «not installed» (в БД старый домен) | `backends/php` (нет обработчика `ONAPPDOMAINCHANGE`/`PortalDomainChanged`) | Переименовать тестовый портал |

### Фронтенд

| # | Проблема | Где |
| --- | --- | --- |
| F1 | ✅ Whitelist в комментарии не содержит `ui_select_change` и `b24_api_call`; эндпоинт телеметрии есть только в PHP | `app/composables/useTelemetry.ts` |
| F2 | ✅ `makeSendPullCommandHandler.bind(this)` — `this` в `<script setup>` не определён | `app/pages/handler/uf.demo.client.vue` |
| F3 | ✅ `clearErrorHref` скопирован со страницы пользовательского поля; `isLoading=false` выставляется дважды | `app/pages/handler/placement-crm-deal-detail-tab.client.vue` |
| F4 | ✅ `isSkipB24`: `!toPath.includes('/')` всегда `false`; пропускаются несуществующие `/eula`, `/render` | `app/middleware/01.app.page.or.slider.global.ts` |
| F5 | ✅ `useSeoMeta({ title: page.title })` — строка, а не геттер: заголовок не обновляется | `app/layouts/slider.vue`, `placement.vue` |
| F6 | ✅ Разные адреса бэкенда по умолчанию: `http://api:8000` и `http://api-need_set:8000` | `server/routes/install.post.ts`, `nuxt.config.ts` |
| F7 | ✅ Только `en.json` и `ru.json` содержат все ключи; в 17 локалях нет новых ключей | `i18n/locales/` |
| F8 | ✅ Страница телеметрии: сырой `<input>`, цвета `text-gray-*` вместо B24-компонентов и `--ui-*`, захардкоженная русская строка | `app/pages/telemetry-test.client.vue` |
| F9 | ✅ `reinitToken` логирует через `console.error` вместо логгера | `app/stores/api.ts` |
| F10 | ✅ `reloadData` до инициализации молча ничего не делает (optional chaining по `b24Helper`) | `app/composables/useAppInit.ts` |
| F11 | ✅ В `user` нет поля `name`: полное имя хранится в `login`, по умолчанию `' '` | `app/stores/user.ts` |

Проблемы безопасности в эту таблицу не вносятся — они передаются мейнтейнерам по [SECURITY.md](../../SECURITY.md).
