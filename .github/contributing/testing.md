# План проверки

<sub>Last reviewed: 2026-10-07.</sub>

Что проверять и как — после обновления зависимостей (Nuxt 4.6, JS SDK 3, Symfony 7.4, Django 6.1, Node 24, pnpm 12) и фикса переустановки (#8). Уровни идут от дешёвых к дорогим: сначала автоматика, потом Docker, потом реальный портал.

## Уровень 0 — CI (автоматически на каждый PR)

| Джоба | Что ловит | Чего **не** ловит |
| --- | --- | --- |
| Frontend | lint, typecheck, unit-тесты (`frontend/test/`), сборка | Поведение в iframe портала, вызовы REST |
| Node backend | `pnpm install --frozen-lockfile`, синтаксис `server.js` | Запуск сервера, JWT, работу с БД |
| Python backend | `manage.py check`, рассинхрон миграций | Запуск, OAuth, события |
| PHP backend | `composer validate` (lock ↔ composer.json) | Установку зависимостей, контейнер Symfony, тесты |
| Repo lint | actionlint, markdownlint | — |

**Пробелы, которые стоит закрыть в CI** (по одному PR, по мере надобности):

- PHP: `composer install` + `bin/console lint:container` + `phpunit` (`backends/php/tests/`). Сейчас блокируется dev-зависимостями из GitHub — нужен `COMPOSER_AUTH` с токеном в секретах.
- Сборка Docker-образов (`docker compose build`) — ловит ошибки Dockerfile, как пропущенный `pnpm-workspace.yaml`.
- Smoke-запуск бэкенда с БД: `/api/health` отвечает 401 без токена.

## Уровень 1 — Docker локально (без портала)

Для **каждого** бэкенда: `make dev-init` → `make dev-php` / `dev-python` / `dev-node`.

| Проверка | Как | Ожидание |
| --- | --- | --- |
| Контейнеры поднимаются | `make ps`, `make logs` | Нет рестартов, нет ошибок старта |
| Фронтенд собирается в dev | `make logs` (frontend) | `pnpm install --frozen-lockfile` проходит под pnpm 12, Nuxt стартует |
| Бэкенд жив | `curl http://localhost:<порт>/` | 200 |
| JWT обязателен | `curl .../api/health`, `.../api/enum`, `.../api/list` без токена | 401 |
| Открытые эндпоинты | `POST /api/install`, `POST /api/getToken` | Не 401 (ошибка валидации ок) |
| Безопасность | `make security-tests` | Все проверки зелёные |
| PHP-телеметрия | `make test-telemetry` | Тесты проходят на Symfony 7.4 |
| Миграции PHP | `make dev-php-db-migrate-status` | Нет непримененных / ошибок |
| Prod-сборка | `make prod-php` (и др.) | Образы собираются (`FRONTEND_TARGET=production`) |

Матрица БД: PHP и Python — PostgreSQL **и** MySQL (`DB_TYPE` в `.env`); Node — то, что поддерживает `server.js`.

## Уровень 2 — реальный портал Bitrix24

Нужен тестовый портал и туннель (Cloudpub, `make dev-init`). Проверять на одном бэкенде полностью, на остальных — сценарии 1–3.

1. **Установка** — открыть приложение впервые: страница `/install` проходит все шаги (placement, userfield), `/api/install` сохраняет аккаунт.
2. **Переустановка (#8)** — удалить приложение и установить снова. Ожидание: без `SQLSTATE[23505]` / 500. Повторить на PostgreSQL и MySQL.
   - ⚠️ Индекс из #8 создаётся только на **пустом** томе БД (init-скрипты). Существующую базу нужно пересоздать (`make down` + удалить volume) или мигрировать вручную.
3. **Главная страница** — `index`: кнопки «enum» / «list» возвращают данные с бэкенда (JWT через `/api/getToken` работает), смена языка портала меняет язык UI.
4. **Слайдер настроек** — `slider/app-options`: сохранение настроек приложения, закрытие слайдера, pull-событие `reload.options`.
5. **Вкладка сделки** — `handler/placement-crm-deal-detail-tab` в карточке сделки CRM: открывается, `B24*`-компоненты рендерятся, индикатор загрузки (после замены `useDashboard().isLoading` на локальный `ref`).
6. **Пользовательское поле** — `handler/uf.demo`: значение `placement.options.VALUE` читается и сохраняется (после явного приведения типов).
7. **События** — `/api/app-events/` (PHP, Python): `ONAPPINSTALL`, `ONAPPUNINSTALL` доходят, токен проверяется.
8. **Логи** — в консоли браузера логи `LoggerFactory` в dev-режиме видны, в production — нет; токены в логах не появляются.

## Уровень 3 — регрессия процессов

- Dependabot открыл первые PR (через неделю) — CI на них зелёный, игнор TS 7 / Symfony 8 работает.
- `markdownlint` и `actionlint` ловят ошибки (намеренно сломать в черновом PR).

## Известные риски, найденные при обновлении

- **PHP** локально не запускался: только `composer validate` и разрешение зависимостей. Это первый кандидат на уровень 1.
- **Node-бэкенд** не реализует `/api/app-events/`, в отличие от PHP и Python — события Bitrix24 с ним не обрабатываются.
- **pnpm 12** не ставит пакеты моложе суток (`minimumReleaseAge`) и требует решения по build-скриптам (`frontend/pnpm-workspace.yaml`, `allowBuilds`). Новый пакет с postinstall сломает `install` до явного разрешения.
