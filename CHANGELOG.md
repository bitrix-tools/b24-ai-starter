# Changelog

Все заметные изменения стартера. Формат — [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
заголовки коммитов — [Conventional Commits](https://www.conventionalcommits.org/ru/v1.0.0/).
Записи добавляются в `## [Unreleased]` в том же PR, что и изменение.

## [Unreleased]

### Dependencies

- frontend: `@bitrix24/b24jssdk` / `-nuxt` 3.0, `@bitrix24/b24ui-nuxt` 2.14, Nuxt 4.6, Pinia 4, ESLint 10, Vitest 5, TypeScript 6.0 (TS 7 пока не поддерживается `vue-tsc` и `typescript-eslint`).
- node backend: Express 5.2, dotenv 18, pg, mysql2 и др.
- python backend: Django 6.1, b24pysdk 1.3.0, celery 5.6.3, kombu 5.6.2.
- php backend: Symfony 7.3 (EOL) → 7.4 LTS, `prefer-stable: true`.
- Node 20 → 24, pnpm 9 → 12 (Dockerfile и CI).

### Changed

- frontend: `LoggerBrowser` → `LoggerFactory.createForBrowser()` (JS SDK 3); состояние загрузки — локальный `ref` вместо удалённого `useDashboard().isLoading`.

### Docs

- `instructions/front/*` приведены к b24jssdk 3 и b24ui 2.14 (иконки, цвета `air-*`, таблицы на TanStack-колонках и др.).
- `AGENTS.md` — единый источник правды для агентов и контрибьюторов; `CLAUDE.md` — ссылка на него.
- `SECURITY.md`, `.github/contributing/`, Dependabot, пины GitHub Actions по SHA, actionlint и markdownlint в CI.
