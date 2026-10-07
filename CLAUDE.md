# Bitrix24 Starter Kit Guide

This project is a starter kit for building Bitrix24 applications with a Nuxt 4 frontend and a choice of PHP, Python, or Node.js backend.

## Commands

- **Start Environment**:
  - `make dev-php`: Start with PHP backend
  - `make dev-python`: Start with Python backend
  - `make dev-node`: Start with Node.js backend
- **Stop Environment**: `make down`
- **Logs**: `make logs`
- **Security**: `make security-tests`

## Architecture

- **Frontend**: `frontend/` (Nuxt 4, Vue 3, Bitrix24 UI Kit 2.x, JS SDK 3.x)
- **Backend**:
  - `backends/php/` (Symfony 7.4 LTS)
  - `backends/python/` (Django 6.1, b24pysdk)
  - `backends/node/` (Express 5)
- **Infrastructure**: `docker-compose.yml`, `infrastructure/`

## Development Guidelines

1.  **Frontend**:
    - Use `@bitrix24/b24ui-nuxt` components (prefix `B24`).
    - Pages must end in `.client.vue`.
    - Use `useApiStore` for backend calls.
    - JS SDK 3: use `$b24.actions.v2|v3.*.make()` and `LoggerFactory.createForBrowser()` (no `callMethod`, no `LoggerBrowser`).
    - Before committing run in `frontend/`: `pnpm lint && pnpm typecheck && pnpm test && pnpm build` (same as CI).
    - Toolchain: Node 24, pnpm 12, TypeScript 6 (TS 7 is not yet supported by vue-tsc / typescript-eslint).
2.  **Backend**:
    - Implement API endpoints in the chosen backend.
    - Use the provided SDKs for Bitrix24 interaction.
    - Ensure API endpoints are secured with JWT (except `/api/install`, `/api/getToken`, and `/api/app-events/`).
3.  **Bitrix24**:
    - Use `placement.bind` for Widgets.
    - Use `bizproc.robot.add` for Robots.
    - Use `event.bind` for Events.

## Skills

Refer to `.claude/skills/` for detailed guides:
- `manage-b24-environment`: DevOps & Docker
- `develop-b24-frontend`: Frontend Development
- `develop-b24-php`: PHP Backend
- `develop-b24-python`: Python Backend
- `develop-b24-node`: Node.js Backend
- `bitrix24-static-local-app`: Static local applications
- `implement-b24-features`: Widgets, Robots, Events
