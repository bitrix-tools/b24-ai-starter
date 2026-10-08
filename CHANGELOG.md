# Changelog

Все заметные изменения стартера. Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
заголовки коммитов — [Conventional Commits](https://www.conventionalcommits.org/ru/v1.0.0/).
Записи добавляются в `## [Unreleased]` в том же PR, что и изменение.

## [Unreleased]

### Security

- `/api/getToken` (PHP, Node) больше не выдаёт JWT по одним лишь присланным `DOMAIN`/`member_id`: `AUTH_ID` проверяется OAuth-сервером Bitrix24 (`app.info` — должен вернуть `client_id` приложения и те же домен и `member_id`), PHP дополнительно требует установленное приложение на портале. Та же проверка, что в Python (b24pysdk).
- PHP: тела запросов в логах проходят через `LogRedactor` — OAuth-токены и `application_token` маскируются.
- PHP: из `backends/php/.env` удалён закоммиченный заголовок авторизации OTEL-экспортёра; значение задаётся в `.env.local`. Токен остаётся в истории git — его владелец должен его отозвать.
- Node: `redactSensitive()` маскирует ключи без учёта регистра, список совпадает с PHP `LogRedactor`.

### Fixed

- Python: `DEBUG` берётся из `BUILD_TARGET` (был захардкожен `True`, в т.ч. в production); пустой `VIRTUAL_HOST` больше не ломает `ALLOWED_HOSTS`; разрешены внутренние имена `api`/`api-python`.
- Python: контейнер не выполняет `makemigrations` при старте; ошибка `migrate` больше не маскируется.
- Python: при `ENABLE_RABBITMQ=0` события `/api/app-events/` обрабатываются сразу (раньше уходили в Celery без брокера и терялись).
- Python: `/api/install` не принимает JWT и требует администратора портала. Добавлены тесты (`manage.py test`, в CI).
- `/api/health` публичный во всех бэкендах (раньше в Python и Node требовал JWT) и отвечает `{status, backend, timestamp}`.
- `/api/install` во всех бэкендах отвечает JSON `{"message": "Installation successful"}` (PHP раньше — текст `OK`, Node — `{"message": "All success"}`).
- `make prod-*` собирали образы в dev-режиме (передавался неиспользуемый `FRONTEND_TARGET`) и поднимали только бэкенд. Теперь подключается `docker-compose.prod.yml`: стадия `production` образов, без монтирования исходников, тот же набор профилей (фронтенд, БД, очередь), что в `dev-*`. Стадия Python-образа переименована `prod` → `production`.
- Профили для `dev-*` / `prod-*` вычисляются в одном месте — `scripts/compose-profiles.sh`.
- make выбирает `docker compose` (v2), если он есть, иначе `docker-compose` (v1).
- `make down` останавливает все профили (включая БД и `python-worker`); `down-all` больше не ссылается на несуществующий `docker-compose.server.yml`; удалена цель `logs-nginxproxy`.
- `make fix-php` больше не удаляет `composer.lock`.
- PHP: `phpunit` без параметров падал из-за пересекающихся testsuite и `failOnWarning`; добавлен suite по умолчанию `all` (security + telemetry, 346 тестов) и цели `make test-php`, `make test-php-security`; аннотации тестов переведены на атрибуты PHPUnit.
- PHP: в образ `php-fpm` добавлено расширение `amqp` (было только в `php-cli`) — Messenger/AMQP из веб-запросов.
- PHP не запускался после обновления зависимостей: убраны опции `proxy_dir`/`proxy_auto_generate`, которых нет в doctrine-bundle 3; `bitrix24/b24phpsdk` закреплён на коммите, совместимом с `mesilov/bitrix24-php-lib` 0.5.2; добавлен обязательный `OTEL_TELEMETRY_PROFILE=simple-ui`; `.env` снова парсится (значение с пробелом без кавычек).

### Dependencies

- frontend: `@bitrix24/b24jssdk` / `-nuxt` 3.0, `@bitrix24/b24ui-nuxt` 2.14, Nuxt 4.6, Pinia 4, ESLint 10, Vitest 5, TypeScript 6.0 (TS 7 пока не поддерживается `vue-tsc` и `typescript-eslint`).
- node backend: Express 5.2, dotenv 18, pg, mysql2 и др.
- python backend: Django 6.1, b24pysdk 1.3.0, celery 5.6.3, kombu 5.6.2.
- php backend: Symfony 7.3 (EOL) → 7.4 LTS, `prefer-stable: true`.
- Node 20 → 24, pnpm 9 → 12 (Dockerfile и CI).

### Changed

- Node: бэкенд больше не заглушка — `app.js` (`createApp()` с внедряемыми зависимостями), `server.js` (пул БД + `listen`), `db/accounts.js` (общая таблица `bitrix24account`, PostgreSQL и MySQL). `/api/install` проверяет `AUTH_ID` OAuth-сервером, сохраняет аккаунт и привязывает `ONAPPINSTALL`/`ONAPPUNINSTALL`; добавлен `/api/app-events/` (`ONAPPUNINSTALL` — только с сохранённым `application_token`); `/api/getToken` сначала ищет установленный аккаунт локально. Тесты — `pnpm test` (`node --test`).
- frontend: `LoggerBrowser` → `LoggerFactory.createForBrowser()` (JS SDK 3); состояние загрузки — локальный `ref` вместо удалённого `useDashboard().isLoading`.

### Docs

- `instructions/front/*` приведены к b24jssdk 3 и b24ui 2.14 (иконки, цвета `air-*`, таблицы на TanStack-колонках и др.).
- `AGENTS.md` — единый источник правды для агентов и контрибьюторов; `CLAUDE.md` — ссылка на него.
- `SECURITY.md`, `.github/contributing/`, Dependabot, пины GitHub Actions по SHA, actionlint и markdownlint в CI.
