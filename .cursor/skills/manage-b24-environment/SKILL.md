---
name: manage-b24-environment
description: Manage the Bitrix24 development environment using Docker, Makefile, and Cloudpub. Use this skill when you need to start/stop services, check logs, fix tunnel issues, or manage the database.
---

# Manage Bitrix24 Environment

## Quick Start

The project uses Docker Compose and Makefiles to manage the development environment.

### Common Commands

```bash
# Start the environment (choose one backend)
make dev-php      # For PHP backend
make dev-python   # For Python backend
make dev-node     # For Node.js backend

# Stop all services
make down

# View logs
make logs         # All logs
docker logs api   # Backend logs (container name `api` for every backend)
docker logs frontend # Frontend logs

# Restart specific container
docker restart api
docker restart frontend
```

## Environment Setup

1. **Initial Setup**: Run `make dev-init` to interactively set up the project (select backend, configure `.env`, setup Cloudpub).
2. **Configuration**: All configuration is in `.env`.
    * `CLOUDPUB_TOKEN`: Required for public URL (get from cloudpub.ru).
    * `CLIENT_ID` / `CLIENT_SECRET`: Bitrix24 application credentials.
    * `SERVER_HOST`: Backend URL (e.g., `http://api-php:8000`; services are `api-php`, `api-python`, `api-node`).
    * `DB_TYPE`: `postgresql` (default) or `mysql`; selects the `db-postgres` / `db-mysql` profile.
    * `ENABLE_RABBITMQ`: `1` adds the `queue` profile (and `python-worker` for Python).

## Cloudpub & Tunnels

The project uses Cloudpub to expose the local environment to Bitrix24.

* **Public URL**: Found in `docker logs cloudpubFront` or `.env` (`VIRTUAL_HOST`).
* **Troubleshooting**:
  * If the tunnel is not working, check `CLOUDPUB_TOKEN` in `.env`.
  * The cloudpub image platform comes from `CLOUDPUB_PLATFORM` in `.env` (default `linux/amd64`); adjust it on ARM64 if needed.

## Database Management

* **PHP**: `make dev-php-db-migrate` applies migrations. `make dev-php-init-database` **drops** the database, recreates it and migrates — only for a fresh project.
* **Python**: `python manage.py migrate` runs automatically when the container starts.
* **Node**: no migrations; the database container runs `infrastructure/database/init.sql` (PostgreSQL) or `init-mysql.sql` (MySQL) on first start.
* **Access**:
  * PostgreSQL/MySQL runs as the `database-postgres` / `database-mysql` service (network alias `database`).
  * Backups: `make db-backup` / `make db-restore`.
  * Credentials in `.env` (`DB_USER`, `DB_PASSWORD`, `DB_NAME`).

## Troubleshooting

* **"Cloudpub not starting"**: Check token validity and architecture (amd64 vs arm64).
* **"Frontend not connecting"**: Check `SERVER_HOST` matches the running backend.
* **"JWT Error"**: Ensure `JWT_SECRET` is set and matches between services (if applicable).
