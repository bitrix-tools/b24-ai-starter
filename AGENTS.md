# AGENTS.md

<sub>Last reviewed: 2026-10-08.</sub>

Единый источник правды для ИИ-агентов и людей, работающих с репозиторием `b24-ai-starter`. `CLAUDE.md` — ссылка на этот файл. Подробные руководства лежат в `.github/contributing/` и `instructions/` — загружай их только когда они относятся к задаче.

## Обзор проекта

Стартер для приложений Bitrix24: фронтенд на Nuxt 4 (работает внутри iframe Bitrix24) и один бэкенд на выбор — PHP, Python или Node.js. Всё поднимается через Docker Compose и `makefile`.

| Часть | Путь | Стек |
| --- | --- | --- |
| Фронтенд | `frontend/` | Nuxt 4.6, Vue 3, `@bitrix24/b24ui-nuxt` 2.14, `@bitrix24/b24jssdk-nuxt` 3.x, Pinia 4, i18n, Tailwind 4 |
| PHP | `backends/php/` | Symfony 7.4 LTS, Doctrine ORM 3, `bitrix24/b24phpsdk`, OpenTelemetry |
| Python | `backends/python/django/` | Django 6.1, `b24pysdk` 1.3, Celery |
| Node.js | `backends/node/api/` | Node 24, Express 5, `pg` / `mysql2`, REST Bitrix24 через `fetch` (`@bitrix24/b24jssdk` не установлен) |
| Инфраструктура | `docker-compose.yml`, `infrastructure/` | PostgreSQL / MySQL, RabbitMQ, Cloudpub |

Тулчейн: Node 24, pnpm 12 (поле `packageManager`), TypeScript 6.0, PHP 8.4, Python 3.13.

## Команды

```bash
make dev-init                 # первичная настройка (.env, выбор бэкенда и БД)
make dev-php | dev-python | dev-node
make down                     # остановить окружение
make logs
make security-tests
make php-cli-lint-phpstan     # PHP: phpstan / rector / cs-fixer — см. make help
make test-php                 # PHP: все unit-тесты (tests/Security + tests/Telemetry)
```

Фронтенд (в `frontend/`) — те же шаги, что в CI:

```bash
pnpm install --frozen-lockfile
pnpm lint && pnpm typecheck && pnpm test && pnpm build
```

Бэкенды в CI: Node — `node --check` + `pnpm test`; Python — `manage.py check` + `makemigrations --check` + `manage.py test`; PHP — `composer validate` + `composer install` + `lint:container --env=prod` + `phpunit`; Docker — сборка production-образов и smoke Node + PostgreSQL.

## Ключевые соглашения

- **Фронтенд**: только компоненты `B24*` из `@bitrix24/b24ui-nuxt` (+ `Prose*` для текста; `B24App` уже в `app.vue` — не вкладывать); страницы — `*.client.vue`, инициализация через `useAppInit` (`initApp`, `processErrorGlobal`); вызовы бэкенда — через `useApiStore`; строки — через i18n (`frontend/i18n/locales/`, эталон `en.json`).
- **JS SDK 3**: `$b24.actions.v2|v3.*.make()` (нет `callMethod` / `callBatch`), логгер — `LoggerFactory.createForBrowser(name, isDev)`, вызовы `logger.info('message', { context })`.
- **Бэкенд**: все эндпоинты защищены JWT, кроме `/api/health`, `/api/install`, `/api/getToken` и `/api/app-events/` (в PHP публичен также `/api/custom-b24-events/` — см. `PUBLIC_ROUTES` в `JwtAuthenticationListener`). `/api/install` во всех бэкендах отвечает `{"message": "Installation successful"}`. `/api/getToken` выдаёт JWT только после проверки `AUTH_ID` OAuth-сервером Bitrix24 (`app.info`) (PHP — `FrontendAuthVerifier`, Node — `verifyFrontendAuth.js`, Python — `auth_required`); не ослаблять. Не логировать OAuth-токены и URL вебхуков: тело запроса — только через редактор (PHP `LogRedactor::redact()`, Node `redactSensitive()`).
- **Bitrix24**: виджеты — `placement.bind`, роботы — `bizproc.robot.add`, события — `event.bind`.
- **Conventional Commits**: `feat`, `fix`, `perf`, `security`, `deps`, `refactor`, `build`, `docs`, `test`, `ci`, `chore`. Область — часть репозитория: `feat(frontend): …`, `deps(php): …`. Заголовок понятен без чтения диффа.
- **CHANGELOG**: заметное изменение — запись в `## [Unreleased]` в [CHANGELOG.md](CHANGELOG.md) в том же PR.
- **GitHub Actions** закреплены по полному SHA с комментарием `# vX.Y.Z`; Dependabot обновляет их еженедельно. Не добавляй ссылки вида `@vN`.
- **Зависимости**: Dependabot — еженедельно, minor/patch группами, мажоры по одному. Правила ручного обновления и известные блокеры (TypeScript 7, Symfony 8) — [.github/contributing/dependencies.md](.github/contributing/dependencies.md).

## Навыки агентов (`.claude/skills/`)

Канонические навыки лежат в `.claude/skills/`; `.cursor/skills/` — точная копия (после правок: `rm -rf .cursor/skills && cp -a .claude/skills .cursor/skills`). Загружай по теме, не всё сразу.

| Навык | Когда |
| --- | --- |
| `navigate-b24-project` | Где что лежит |
| `manage-b24-environment` | Docker, make, Cloudpub, БД |
| `develop-b24-frontend` | Страницы, компоненты, JS SDK |
| `develop-b24-php` / `develop-b24-python` / `develop-b24-node` | Бэкенд на выбранном языке |
| `implement-b24-features` | Виджеты, роботы, события, очереди |
| `bitrix24-mcp-server` | Поиск методов REST API через MCP |

## Документация

| Где | Что |
| --- | --- |
| `instructions/knowledge.md` | Общая база знаний |
| `instructions/front/` | Фронтенд: JS SDK, UI Kit, рецепты компонентов |
| `instructions/{php,python,node}/` | Бэкенды, включая `code-review.md` для каждого |
| `instructions/bitrix24/`, `instructions/queues/` | Виджеты, роботы, MCP; очереди |
| `.github/contributing/` | Процессы: ревью, зависимости, [план проверки](.github/contributing/testing.md) и [план живой проверки](.github/contributing/live-test-plan.md) и известные проблемы кода |

Документация обновляется **в том же PR**, что и код. Устаревшая документация — такой же баг, как упавший тест: агенты копируют примеры из `instructions/` дословно.

## PR Review Checklist

```text
PR Review:
- [ ] Заголовок и коммиты — Conventional Commits; запись в CHANGELOG.md, если изменение заметно
- [ ] CI зелёный: frontend (lint · typecheck · test · build), бэкенды, repo-lint
- [ ] Фронтенд: только B24*-компоненты, страницы *.client.vue, нет удалённых API SDK v2 (callMethod, LoggerBrowser)
- [ ] Бэкенд: новые эндпоинты под JWT; токены и URL вебхуков не попадают в логи
- [ ] Изменение реализовано во всех бэкендах, где оно применимо, или явно описано, почему нет
- [ ] instructions/ и .claude/skills/ обновлены, если изменился описанный в них паттерн; .cursor/skills/ синхронизирован
- [ ] Новые зависимости обоснованы; lock-файлы обновлены инструментом, а не руками
- [ ] Новые GitHub Actions закреплены по SHA
```

Подробно — [.github/contributing/review.md](.github/contributing/review.md).

## Сколько ревью нужно изменению

- **Полное ревью** (`/code-review` + ручная проверка в запущенном окружении): аутентификация и JWT, установка приложения и OAuth, обработчики событий Bitrix24, миграции БД, всё, что связано с безопасностью.
- **`/code-review` достаточно**: документация, навыки, конфигурация CI, стили, обновления зависимостей без изменения кода. Если всплыло поведенческое изменение — эскалируй.

Тяжёлое ревью на всё подряд — это не тщательность: оно переписывает одни и те же файлы по кругу и порождает работу вместо поиска проблем.

## Git Workflow

- Ветка от `master`, PR в `master`; PR сливаются squash-ом, поэтому несколько коммитов в ветке — нормально.
- Не переписывай историю чужих веток.

## Before Submitting

- [ ] Локальные проверки затронутых частей проходят (см. «Команды»)
- [ ] Документация и навыки обновлены в том же PR
- [ ] Запись в `CHANGELOG.md`
- [ ] Если менял `AGENTS.md` или `.github/contributing/*`, обнови штамп `Last reviewed` на сегодняшнюю дату
- [ ] Коммиты — Conventional Commits

## Безопасность

Уязвимости — только через приватный канал, см. [SECURITY.md](SECURITY.md).
