---
name: navigate-b24-project
description: Understand the Bitrix24 Starter Kit project structure. Use this skill to find where specific code (frontend, backend, infrastructure) is located.
---

# Navigate Bitrix24 Project

## Project Structure

The project is a monorepo containing frontend, multiple backend options, and infrastructure configuration.

```text
b24-ai-starter/
├── frontend/                 # Nuxt 3 Frontend
│   ├── app/                  # Application source code
│   │   ├── pages/            # Pages (.client.vue)
│   │   ├── components/       # UI Components
│   │   ├── stores/           # Pinia Stores
│   │   └── composables/      # Shared Logic
│   └── nuxt.config.ts        # Nuxt Configuration
│
├── backends/                 # Backend Implementations
│   ├── php/                  # Symfony 7 + PHP SDK
│   │   ├── src/              # Source code
│   │   └── docker/           # PHP-specific Docker config
│   ├── python/               # Django + b24pysdk
│   │   └── django/           # Django project and Dockerfile
│   └── node/                 # Express + Node.js SDK
│       ├── api/              # Express app
│       └── Dockerfile        # Node Docker config
│
├── infrastructure/           # Shared Infrastructure
│   └── database/             # SQL init scripts
│
├── instructions/             # AI Agent Instructions (Source of Truth)
│   ├── knowledge.md          # Central Knowledge Base
│   ├── [lang]/               # Language-specific guides
│   └── bitrix24/             # Platform guides
│
├── scripts/                  # Helper scripts (dev-init, versioning)
├── docker-compose.yml        # Main Docker Compose file
├── makefile                  # Development commands
└── README.md                 # Project Overview
```

## Key Locations by Task

| Task | Location |
|------|----------|
| **Frontend UI** | `frontend/app/components/` (use B24UI) |
| **Frontend Pages** | `frontend/app/pages/` (must be `.client.vue`) |
| **Frontend API Logic** | `frontend/app/stores/` or `frontend/app/composables/` |
| **PHP Endpoints** | `backends/php/src/Controller/` |
| **PHP Logic** | `backends/php/src/Service/` |
| **Python Endpoints** | `backends/python/django/main/views.py` |
| **Python Auth/Models** | `backends/python/django/bitrix_auth/models.py` |
| **Python Events** | `backends/python/django/bitrix_events/` |
| **Node.js Endpoints** | `backends/node/api/server.js` |
| **Database Schema** | `infrastructure/database/init-mysql.sql` (or PostgreSQL) |
| **Env Variables** | `.env` (copied from `.env.example`) |

## Documentation

*   **General**: `instructions/knowledge.md`
*   **Frontend**: `instructions/front/knowledge.md`
*   **PHP**: `instructions/php/knowledge.md`
*   **Python**: `instructions/python/knowledge.md`
*   **Node.js**: `instructions/node/knowledge.md`
