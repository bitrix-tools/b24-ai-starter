.PHONY: help dev-init create-version delete-version dev-front dev-php dev-python dev-node prod-php prod-python prod-node status ps down down-all logs logs-nginxproxy clean composer-install composer-update composer-dumpautoload composer db-create db-migrate db-migrate-create db-schema-update db-schema-validate queue-up queue-down

# Variables
DOCKER_COMPOSE = docker compose

# Default target - show help
help: ## Show this help message
	@echo "🚀 Bitrix24 AI Starter - Available Commands"
	@echo "=========================================="
	@echo ""
	@echo "📋 Setup & Initialization:"
	@echo "  dev-init          Interactive project setup (recommended)"
	@echo "  create-version    Clone current project into versions/<name>"
	@echo "  delete-version    Remove versions/<name>"
	@echo ""
	@echo "🛠  Development:"
	@echo "  dev-front         Start frontend only"
	@echo "  dev-php           Start with PHP backend"
	@echo "  dev-python        Start with Python backend"
	@echo "  dev-node          Start with Node.js backend"
	@echo ""
	@echo "🚀 Production:"
	@echo "  prod-php          Deploy PHP backend to production"
	@echo "  prod-python       Deploy Python backend to production"
	@echo "  prod-node         Deploy Node.js backend to production"
	@echo "  db-migrate-init   PostgreSQL init.sql + Django migrate"
	@echo "  db-migrate-full   Full schema-production.sql dump"
	@echo "  db-migrate-python Django migrate only (updates)"
	@echo ""
	@echo "🐘 PHP Tools:"
	@echo "  composer-install  Install PHP dependencies"
	@echo "  composer-update   Update PHP dependencies"
	@echo "  php-cli-sh        Access PHP CLI container shell"
	@echo ""
	@echo "🗄  Database (PHP):"
	@echo "  dev-php-init-database    Initialize PHP database"
	@echo "  dev-php-db-create        Create PHP database"
	@echo "  dev-php-db-migrate       Run PHP migrations"
	@echo ""
	@echo "🔍 Monitoring:"
	@echo "  status            Show Docker stats"
	@echo "  ps                Watch Docker processes"
	@echo "  logs              Show all container logs"
	@echo ""
	@echo "🧹 Cleanup:"
	@echo "  down              Stop all containers and remove orphans"
	@echo "  clean             Complete Docker cleanup (containers, networks, volumes)"
	@echo "  down-all          Stop all containers including server compose"
	@echo ""
	@echo "📨 Queues:"
	@echo "  queue-up          Start RabbitMQ only (profile queue)"
	@echo "  queue-down        Stop RabbitMQ only"
	@echo ""
	@echo "🔧 Troubleshooting:"
	@echo "  fix-php           Fix PHP backend dependencies"
	@echo ""
	@echo "🛡  Security:"
	@echo "  security-scan     Run dependency vulnerability audit"
	@echo "  security-tests    Run orchestrated security test suite"
	@echo ""
	@echo "💡 Quick start: make dev-init"
	@echo ""

.DEFAULT_GOAL := help

# Initialization
dev-init:
	@echo "🚀 Initializing Bitrix24 AI Starter project..."
	@./scripts/dev-init.sh

create-version:
	@echo "📂 Creating a new project version..."
	@./scripts/create-version.sh $(VERSION)

delete-version:
	@echo "🗑 Removing a project version..."
	@./scripts/delete-version.sh $(VERSION)

fix-php:
	@echo "🔧 Fixing PHP backend dependencies..."
	@./scripts/fix-php.sh

# Development
dev-front:
	@echo "Starting frontend"
	COMPOSE_PROFILES=frontend,cloudpub docker compose --env-file .env up --build

## PHP
dev-php:
	@echo "Starting dev php"
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,php,cloudpub,queue,$$DB_PROFILE"; \
	else \
	  PROFILES="frontend,php,cloudpub,$$DB_PROFILE"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES docker compose --env-file .env up --build

# work with composer
.PHONY: composer-install
composer-install:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli composer install

.PHONY: composer-update
composer-update:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli composer update

.PHONY: composer-dumpautoload
composer-dumpautoload:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli composer dumpautoload

# call composer with any parameters
# make composer install
# make composer "install --no-dev"
# make composer require symfony/http-client
.PHONY: composer
composer:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli composer $(filter-out $@,$(MAKECMDGOALS))

.PHONY: php-cli-sh
php-cli-sh:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli sh

php-cli-app-example:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli bin/console app:example

# linters
php-cli-lint-phpstan:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli vendor/bin/phpstan --memory-limit=2G analyse -vvv

.PHONY: lint-rector
lint-rector:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli vendor/bin/rector process --dry-run

.PHONY: lint-rector-fix
lint-rector-fix:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli vendor/bin/rector process

.PHONY: lint-cs-fixer
lint-cs-fixer:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli vendor/bin/php-cs-fixer check --verbose --diff

.PHONY: lint-cs-fixer-fix
lint-cs-fixer-fix:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli vendor/bin/php-cs-fixer fix --verbose --diff

.PHONY: security-scan
security-scan:
	@./scripts/security-scan.sh

.PHONY: security-tests
security-tests:
	@./scripts/security-tests.sh $(SECURITY_TESTS_ARGS)

# Doctrine/Symfony database commands

# ATTENTION!
# This command drop database and create new database with empty structure with default tables
# You must call this command only for new project!
.PHONY: dev-php-init-database
dev-php-init-database: dev-php-db-drop dev-php-db-create dev-php-db-migrate

.PHONY: dev-php-db-create dev-php-db-drop dev-php-db-migrate dev-php-db-migrate-create dev-php-db-schema-update dev-php-db-schema-validate
dev-php-db-create:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:database:create --if-not-exists

dev-php-db-drop:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:database:drop --force --if-exists

dev-php-db-migrate:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:migrations:migrate --no-interaction

dev-php-db-migrate-create:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console make:migration --no-interaction

dev-php-db-migrate-status:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:migrations:status

dev-php-db-schema-update:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:schema:update --force

dev-php-db-schema-validate:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	COMPOSE_PROFILES=php-cli,$$DB_PROFILE $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:schema:validate

## Python
dev-python:
	@echo "Starting dev python"
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,python,cloudpub,queue,$$DB_PROFILE"; \
	else \
	  PROFILES="frontend,python,cloudpub,$$DB_PROFILE"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES docker compose --env-file .env up --build

## NodeJs
dev-node:
	@echo "Starting dev node"
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then DB_PROFILE="db-mysql"; else DB_PROFILE="db-postgres"; fi; \
	ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,node,cloudpub,queue,$$DB_PROFILE"; \
	else \
	  PROFILES="frontend,node,cloudpub,$$DB_PROFILE"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES docker compose --env-file .env up --build

# Production
prod-php:
	@echo "Starting prod php environment"
	COMPOSE_PROFILES=php FRONTEND_TARGET=production docker compose up --build -d

prod-python:
	@echo "Starting prod python environment (1daa.ru/tochka/)"
	BUILD_TARGET=production COMPOSE_PROFILES=python,frontend,db-postgres \
		docker compose -f docker-compose.yml -f docker-compose.prod.yml up --build -d

deploy-1daa:
	@chmod +x scripts/deploy-1daa-production.sh scripts/push-to-server.sh scripts/server-bootstrap.sh
	@./scripts/push-to-server.sh

push-server:
	@chmod +x scripts/push-to-server.sh
	@./scripts/push-to-server.sh

prod-node:
	@echo "Starting prod node environment"
	COMPOSE_PROFILES=node FRONTEND_TARGET=production docker compose up --build -d

# Production database (PostgreSQL + Django)
.PHONY: db-migrate-init db-migrate-full db-migrate-python
db-migrate-init:
	@./scripts/production-db-migrate.sh init

db-migrate-full:
	@./scripts/production-db-migrate.sh full

db-migrate-python:
	@./scripts/production-db-migrate.sh migrate-only

# Utils
status:
	docker stats

ps:
	watch -n 2 docker ps

down:
	@echo "🛑 Останавливаем все контейнеры..."
	COMPOSE_PROFILES=frontend,php,python,node,cloudpub,queue,db-postgres,db-mysql docker compose down --remove-orphans || true
	docker container stop $$(docker container ls -q --filter "name=b24" --filter "name=frontend" --filter "name=api" --filter "name=cloudpub") 2>/dev/null || true

queue-up:
	@echo "▶️ Запускаем только RabbitMQ"
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" != "1" ]; then \
	  echo "⚠️  ENABLE_RABBITMQ=0 в .env — сервис запустится, но переменные стоит обновить"; \
	fi; \
	COMPOSE_PROFILES=queue docker compose --env-file .env up rabbitmq --build -d

queue-down:
	@echo "⏹ Останавливаем только RabbitMQ"
	COMPOSE_PROFILES=queue docker compose --env-file .env stop rabbitmq || true

down-all:
	docker compose down --remove-orphans
	docker compose -f docker-compose.server.yml down --remove-orphans

clean:
	@echo "🧹 Полная очистка Docker окружения..."
	docker-compose down --remove-orphans --volumes || true
	docker container rm -f $$(docker container ls -aq --filter "name=b24") 2>/dev/null || true
	docker network prune -f
	docker volume prune -f
	@echo "✓ Очистка завершена"

logs:
	docker compose logs -f

logs-nginxproxy:
	docker compose logs -f docker-compose.server.yml

# Database operations
db-backup:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then \
	  COMPOSE_PROFILES=db-mysql docker compose exec -T database-mysql sh -lc "exec mysqldump -u\"$${DB_USER:-appuser}\" -p\"$${DB_PASSWORD:-apppass}\" \"$${DB_NAME:-appdb}\"" > backup_$(shell date +%Y%m%d_%H%M%S).sql; \
	else \
	  COMPOSE_PROFILES=db-postgres docker compose exec -T database-postgres pg_dump -U $${DB_USER:-appuser} $${DB_NAME:-appdb} > backup_$(shell date +%Y%m%d_%H%M%S).sql; \
	fi

db-restore:
	@DB_TYPE_VALUE=$$(grep -E '^DB_TYPE=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$DB_TYPE_VALUE" ]; then DB_TYPE_VALUE=postgresql; fi; \
	if [ "$$DB_TYPE_VALUE" = "mysql" ]; then \
	  COMPOSE_PROFILES=db-mysql docker compose exec -T database-mysql sh -lc "exec mysql -u\"$${DB_USER:-appuser}\" -p\"$${DB_PASSWORD:-apppass}\" \"$${DB_NAME:-appdb}\"" < $(file); \
	else \
	  COMPOSE_PROFILES=db-postgres docker compose exec -T database-postgres psql -U $${DB_USER:-appuser} $${DB_NAME:-appdb} < $(file); \
	fi

