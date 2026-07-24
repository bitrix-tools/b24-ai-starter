# Bitrix24 Starter Kit Guide

This project is a starter kit for building Bitrix24 applications with a Nuxt 3 frontend and a choice of PHP, Python, or Node.js backend.

## Commands

- **Start Environment**:
  - `make dev-php`: Start with PHP backend
  - `make dev-python`: Start with Python backend
  - `make dev-node`: Start with Node.js backend
- **Stop Environment**: `make down`
- **Logs**: `make logs`
- **Security**: `make security-tests`

## Architecture

- **Frontend**: `frontend/` (Nuxt 3, Vue 3, Bitrix24 UI Kit)
- **Backend**:
  - `backends/php/` (Symfony 7)
  - `backends/python/` (Django)
  - `backends/node/` (Express)
- **Infrastructure**: `docker-compose.yml`, `infrastructure/`

## Development Guidelines

1.  **Frontend**:
    - Use `@bitrix24/b24ui-nuxt` components (prefix `B24`).
    - Pages must end in `.client.vue`.
    - Use `useApiStore` for backend calls.
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
