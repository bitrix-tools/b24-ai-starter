---
name: navigate-b24-project
description: Understand the Bitrix24 Starter Kit project structure. Use this skill to find where specific code (frontend, backend, infrastructure) is located.
---

# Navigate Bitrix24 Project

## Project Structure

The project is a monorepo containing frontend, multiple backend options, and infrastructure configuration.

```text
b24-ai-starter/
├── frontend/                 # Nuxt 4 Frontend
│   ├── app/                  # Application source code
│   │   ├── pages/            # Pages (.client.vue)
│   │   ├── components/       # UI Components
│   │   ├── stores/           # Pinia Stores
│   │   └── composables/      # Shared Logic
│   └── nuxt.config.ts        # Nuxt Configuration
│
├── backends/                 # Backend Implementations
│   ├── php/                  # Symfony 7.4 LTS + PHP SDK
│   │   ├── src/              # Source code
│   │   ├── tests/            # PHPUnit (Security, Telemetry)
│   │   ├── config/           # Symfony config, Doctrine mappings
│   │   ├── migrations/       # Doctrine migrations
│   │   └── docker/           # PHP-specific Docker config
│   ├── python/               # Django 6.1 + b24pysdk
│   │   └── django/           # Django project (main/, bitrix_auth/, bitrix_events/, tests/) and Dockerfile
│   └── node/                 # Express 5 (Node 24)
│       └── api/              # Express app (app.js, server.js, db/, utils/, test/) and Dockerfile
│
├── infrastructure/           # Shared Infrastructure
│   └── database/             # SQL init scripts (init.sql, init-mysql.sql) + upgrades/ (make db-upgrade)
│
├── instructions/             # AI Agent Instructions (Source of Truth)
│   ├── knowledge.md          # Central Knowledge Base
│   ├── front/ php/ python/ node/  # Stack-specific guides
│   ├── bitrix24/             # Platform guides (widgets, robots, MCP)
│   ├── queues/               # RabbitMQ / Celery guides
│   └── versioning/           # Versioning guides
│
├── scripts/                  # Helper scripts (dev-init, compose-profiles, versioning, security)
├── docker-compose.yml        # Main Docker Compose file
├── docker-compose.prod.yml   # Production overrides (make prod-*)
├── makefile                  # Development commands
└── README.md                 # Project Overview
```

## Key Locations by Task

| Task | Location |
| ------ | ---------- |
| **Frontend UI** | `frontend/app/components/` (use B24UI) |
| **Frontend Pages** | `frontend/app/pages/` (must be `.client.vue`) |
| **Frontend API Logic** | `frontend/app/stores/` or `frontend/app/composables/` |
| **PHP Endpoints** | `backends/php/src/Controller/` |
| **PHP Logic** | `backends/php/src/Service/` |
| **Python Endpoints** | `backends/python/django/main/views.py` |
| **Python Auth/Models** | `backends/python/django/bitrix_auth/models.py` |
| **Python Events** | `backends/python/django/bitrix_events/` |
| **PHP Auth (JWT)** | `backends/php/src/EventListener/JwtAuthenticationListener.php` |
| **PHP Install/Lifecycle** | `backends/php/src/Bitrix24Core/` |
| **Node.js Endpoints** | `backends/node/api/app.js` (`server.js` = pool + listen) |
| **Node.js Accounts DB** | `backends/node/api/db/accounts.js` |
| **Node.js Auth (JWT)** | `backends/node/api/utils/verifyToken.js` |
| **Database Schema** | PHP: `backends/php/migrations/`; Python: Django migrations; Node: `infrastructure/database/init.sql` / `init-mysql.sql` |
| **Env Variables** | `.env` (copied from `.env.example`) |

## Documentation

* **General**: `instructions/knowledge.md`
* **Frontend**: `instructions/front/knowledge.md`
* **PHP**: `instructions/php/knowledge.md`
* **Python**: `instructions/python/knowledge.md`
* **Node.js**: `instructions/node/knowledge.md`
