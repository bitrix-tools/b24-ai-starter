.PHONY: help dev-init create-version delete-version dev-front dev-php dev-python dev-node prod-php prod-python prod-node status ps down down-all logs logs-nginxproxy clean composer-install composer-update composer-dumpautoload composer db-create db-migrate db-migrate-create db-schema-update db-schema-validate queue-up queue-down test-telemetry test-telemetry-null test-telemetry-real test-telemetry-config test-telemetry-factory test-telemetry-di test-telemetry-integration test-telemetry-profiles test-telemetry-attribute-groups test-telemetry-filtering test-telemetry-monolog test-telemetry-monolog-e2e test-telemetry-profiles-e2e test-telemetry-e2e test-telemetry-app-events test-telemetry-app-lifecycle test-telemetry-ui-events test-telemetry-action-events test-telemetry-api-calls test-telemetry-error-tracking test-telemetry-session-context test-telemetry-frontend-events test-telemetry-frontend-e2e

# Variables
DOCKER_COMPOSE = docker-compose
CURRENT_UID := $(shell id -u):$(shell id -g)

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
	@echo "🧪 Testing:"
	@echo "  test-telemetry              Run all telemetry tests"
	@echo "  test-telemetry-null         Run NullTelemetryService tests"
	@echo "  test-telemetry-real         Run RealTelemetryService tests"
	@echo "  test-telemetry-config       Run OTLP configuration tests"
	@echo "  test-telemetry-factory      Run TelemetryFactory tests"
	@echo "  test-telemetry-di           Run Dependency Injection integration tests"
	@echo "  test-telemetry-integration  Run telemetry integration tests"
	@echo "  test-telemetry-profiles     Run telemetry profiles tests"
	@echo "  test-telemetry-attribute-groups  Run AttributeGroupManager tests"
	@echo "  test-telemetry-filtering    Run attribute filtering tests"
	@echo "  test-telemetry-monolog      Run Monolog integration tests"
	@echo "  test-telemetry-monolog-e2e  Run E2E Monolog test (requires b24-ai-starter-otel)"
	@echo "  test-telemetry-profiles-e2e Run E2E profile filtering test (requires b24-ai-starter-otel)"
	@echo "  test-telemetry-e2e          Run E2E tests (requires b24-ai-starter-otel)"
	@echo "  test-telemetry-app-events   Run all application integration point tests"
	@echo "  test-telemetry-app-lifecycle Run app install/uninstall lifecycle tests"
	@echo "  test-telemetry-ui-events    Run UI events and session context trait tests"
	@echo "  test-telemetry-action-events Run B24 event handler action tracking tests"
	@echo "  test-telemetry-api-calls    Run Bitrix24 API call tracking tests"
	@echo "  test-telemetry-error-tracking Run exception listener error tracking tests"
	@echo "  test-telemetry-session-context Run session ID propagation tests"
	@echo "  test-telemetry-frontend-events Run frontend telemetry endpoint tests (Sprint 8)"
	@echo "  test-telemetry-frontend-e2e  Run frontend telemetry full-flow E2E test (requires b24-ai-starter-otel)"
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
	COMPOSE_PROFILES=frontend,cloudpub $(DOCKER_COMPOSE) --env-file .env up --build

## PHP
dev-php:
	@echo "Starting dev php"
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,php,cloudpub,queue"; \
	else \
	  PROFILES="frontend,php,cloudpub"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env up --build

# work with composer
.PHONY: composer-install
composer-install:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --user $(CURRENT_UID) --workdir /var/www php-cli composer install

.PHONY: composer-update
composer-update:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --user $(CURRENT_UID) --workdir /var/www php-cli composer update

.PHONY: composer-dumpautoload
composer-dumpautoload:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --user $(CURRENT_UID) --workdir /var/www php-cli composer dumpautoload

# call composer with any parameters
# make composer install
# make composer "install --no-dev"
# make composer require symfony/http-client
.PHONY: composer
composer:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --user $(CURRENT_UID) --workdir /var/www php-cli composer $(filter-out $@,$(MAKECMDGOALS))

.PHONY: php-cli-sh
php-cli-sh:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --user $(CURRENT_UID) --workdir /var/www php-cli sh

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

# Telemetry Testing
.PHONY: test-telemetry
test-telemetry: ## Run all telemetry tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --configuration phpunit.xml.dist

.PHONY: test-telemetry-null
test-telemetry-null: ## Run NullTelemetryService tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-null-service

.PHONY: test-telemetry-real
test-telemetry-real: ## Run RealTelemetryService tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-real-service

.PHONY: test-telemetry-config
test-telemetry-config: ## Run OTLP configuration tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-config

.PHONY: test-telemetry-factory
test-telemetry-factory: ## Run TelemetryFactory tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-factory

.PHONY: test-telemetry-di
test-telemetry-di: ## Run Dependency Injection integration tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-di

.PHONY: test-telemetry-integration
test-telemetry-integration: ## Run telemetry integration tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-integration

.PHONY: test-telemetry-profiles
test-telemetry-profiles: ## Run telemetry profiles tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-profiles

.PHONY: test-telemetry-attribute-groups
test-telemetry-attribute-groups: ## Run AttributeGroupManager tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-attribute-groups

.PHONY: test-telemetry-filtering
test-telemetry-filtering: ## Run attribute filtering tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-filtering

.PHONY: test-telemetry-monolog
test-telemetry-monolog: ## Run Monolog integration tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-monolog

.PHONY: test-telemetry-monolog-e2e
test-telemetry-monolog-e2e: ## Run E2E Monolog test (requires b24-ai-starter-otel running)
	@echo "🚀 Checking b24-ai-starter-otel infrastructure..."
	@cd ../b24-ai-starter-otel && $(DOCKER_COMPOSE) ps | grep -q "Up" || (echo "❌ b24-ai-starter-otel not running. Start with: cd ../b24-ai-starter-otel && make start" && exit 1)
	@echo "✓ Infrastructure is running"
	@echo ""
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run -e TELEMETRY_ENABLED=true --workdir /var/www php-cli php bin/test-monolog-e2e.php

.PHONY: test-telemetry-profiles-e2e
test-telemetry-profiles-e2e: ## Run E2E profile filtering test (requires b24-ai-starter-otel running)
	@echo "🚀 Checking b24-ai-starter-otel infrastructure..."
	@cd ../b24-ai-starter-otel && $(DOCKER_COMPOSE) ps | grep -q "Up" || (echo "❌ b24-ai-starter-otel not running. Start with: cd ../b24-ai-starter-otel && make start" && exit 1)
	@echo "✓ Infrastructure is running"
	@echo ""
	@echo "🧪 Running E2E Profile Filtering Test..."
	@echo "   Profile: simple-ui (Lifecycle + UI only)"
	@echo ""
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run -e TELEMETRY_ENABLED=true --workdir /var/www php-cli php bin/test-profile-filtering.php

.PHONY: test-telemetry-e2e
test-telemetry-e2e: ## Run end-to-end telemetry tests (requires b24-ai-starter-otel running)
	@echo "🚀 Running E2E Telemetry Test"
	@cd backends/php && ./tests/Telemetry/E2E/run-e2e-test.sh

.PHONY: test-telemetry-app-events
test-telemetry-app-events: ## Run all application integration point tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-app-events

.PHONY: test-telemetry-app-lifecycle
test-telemetry-app-lifecycle: ## Run app install/uninstall lifecycle tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-app-lifecycle

.PHONY: test-telemetry-ui-events
test-telemetry-ui-events: ## Run UI events and session context trait tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-ui-events

.PHONY: test-telemetry-action-events
test-telemetry-action-events: ## Run B24 event handler action tracking tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-action-events

.PHONY: test-telemetry-api-calls
test-telemetry-api-calls: ## Run Bitrix24 API call tracking tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-api-calls

.PHONY: test-telemetry-error-tracking
test-telemetry-error-tracking: ## Run exception listener error tracking tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-error-tracking

.PHONY: test-telemetry-session-context
test-telemetry-session-context: ## Run session ID propagation tests
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-session-context

.PHONY: test-telemetry-frontend-events
test-telemetry-frontend-events: ## Run frontend telemetry endpoint tests (DTO unit + controller WebTest)
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-frontend-events

.PHONY: test-telemetry-frontend-e2e
test-telemetry-frontend-e2e: ## Run frontend telemetry full-flow E2E test (requires b24-ai-starter-otel running)
	@echo "Убедитесь что b24-ai-starter-otel запущен: cd ../b24-ai-starter-otel && make up"
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run \
		-e TELEMETRY_ENABLED=true \
		-e OTEL_EXPORTER_OTLP_ENDPOINT=http://host.docker.internal:4318 \
		-e CLICKHOUSE_USER=$${CLICKHOUSE_USER:-telemetry_user} \
		-e CLICKHOUSE_PASSWORD=$${CLICKHOUSE_PASSWORD:-changeme_secure_password} \
		--workdir /var/www php-cli vendor/bin/phpunit --testsuite telemetry-frontend-e2e

# Doctrine/Symfony database commands

# ATTENTION!
# This command drop database and create new database with empty structure with default tables
# You must call this command only for new project!
.PHONY: dev-php-init-database
dev-php-init-database: dev-php-db-drop dev-php-db-create dev-php-db-migrate

.PHONY: dev-php-db-create dev-php-db-drop dev-php-db-migrate dev-php-db-migrate-create dev-php-db-schema-update dev-php-db-schema-validate
dev-php-db-create:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:database:create --if-not-exists

dev-php-db-drop:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:database:drop --force --if-exists

dev-php-db-migrate:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:migrations:migrate --no-interaction

dev-php-db-migrate-create:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console make:migration --no-interaction

dev-php-db-migrate-status:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:migrations:status

dev-php-db-schema-update:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:schema:update --force

dev-php-db-schema-validate:
	COMPOSE_PROFILES=php-cli $(DOCKER_COMPOSE) run --rm --workdir /var/www php-cli php bin/console doctrine:schema:validate

## Python
dev-python:
	@echo "Starting dev python"
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,python,cloudpub,queue"; \
	else \
	  PROFILES="frontend,python,cloudpub"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env up --build

## NodeJs
dev-node:
	@echo "Starting dev node"
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,node,cloudpub,queue"; \
	else \
	  PROFILES="frontend,node,cloudpub"; \
	fi; \
	COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env up --build

# Production
prod-php:
	@echo "Starting prod php environment"
	COMPOSE_PROFILES=php FRONTEND_TARGET=production $(DOCKER_COMPOSE) up --build -d

prod-python:
	@echo "Starting prod python environment"
	COMPOSE_PROFILES=python FRONTEND_TARGET=production $(DOCKER_COMPOSE) up --build -d

prod-node:
	@echo "Starting prod node environment"
	COMPOSE_PROFILES=node FRONTEND_TARGET=production $(DOCKER_COMPOSE) up --build -d

# Utils
status:
	docker stats

ps:
	watch -n 2 docker ps

down:
	@echo "🛑 Останавливаем все контейнеры..."
	COMPOSE_PROFILES=frontend,php,python,node,cloudpub,queue $(DOCKER_COMPOSE) down --remove-orphans || true
	docker container stop $$(docker container ls -q --filter "name=b24" --filter "name=frontend" --filter "name=api" --filter "name=cloudpub") 2>/dev/null || true

queue-up:
	@echo "▶️ Запускаем только RabbitMQ"
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" != "1" ]; then \
	  echo "⚠️  ENABLE_RABBITMQ=0 в .env — сервис запустится, но переменные стоит обновить"; \
	fi; \
	COMPOSE_PROFILES=queue $(DOCKER_COMPOSE) --env-file .env up rabbitmq --build -d

queue-down:
	@echo "⏹ Останавливаем только RabbitMQ"
	COMPOSE_PROFILES=queue $(DOCKER_COMPOSE) --env-file .env stop rabbitmq || true

down-all:
	$(DOCKER_COMPOSE) down --remove-orphans
	$(DOCKER_COMPOSE) -f docker-compose.server.yml down --remove-orphans

clean:
	@echo "🧹 Полная очистка Docker окружения..."
	$(DOCKER_COMPOSE) down --remove-orphans --volumes || true
	docker container rm -f $$(docker container ls -aq --filter "name=b24") 2>/dev/null || true
	docker network prune -f
	docker volume prune -f
	@echo "✓ Очистка завершена"

logs:
	$(DOCKER_COMPOSE) logs -f

logs-nginxproxy:
	$(DOCKER_COMPOSE) logs -f docker-compose.server.yml

# Database operations
db-backup:
	$(DOCKER_COMPOSE) exec database pg_dump -U appuser appdb > backup_$(shell date +%Y%m%d_%H%M%S).sql

db-restore:
	$(DOCKER_COMPOSE) exec -T database psql -U appuser appdb < $(file)

# ============================================================================
# Docker Container Management Commands
# ============================================================================
# Эти команды предназначены для более гранулярного управления контейнерами
# и не будут перенесены в основной starter kit

.PHONY: build-containers start-containers stop-containers remove-containers

# Сборка контейнеров без запуска
build-containers:
	@echo "🔨 Собираем Docker контейнеры..."
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,php,queue"; \
	else \
	  PROFILES="frontend,php"; \
	fi; \
	LOCAL_IP_VALUE=$$(grep -E '^LOCAL_IP=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	if [ -z "$$LOCAL_IP_VALUE" ]; then \
	  echo "⚠️  LOCAL_IP не задан в .env, используется 0.0.0.0 (все интерфейсы)"; \
	  COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env build; \
	else \
	  echo "ℹ️  Используется LOCAL_IP: $$LOCAL_IP_VALUE"; \
	  LOCAL_IP=$$LOCAL_IP_VALUE COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env build; \
	fi
	@echo "✓ Сборка завершена"

# Запуск контейнеров в режиме демона
start-containers:
	@echo "▶️  Запускаем контейнеры в режиме демона..."
	@ENABLE_RABBITMQ_VALUE=$$(grep -E '^ENABLE_RABBITMQ=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	LOCAL_IP_VALUE=$$(grep -E '^LOCAL_IP=' .env 2>/dev/null | tail -n1 | cut -d= -f2); \
	VIRTUAL_HOST_VALUE=$$(grep -E '^VIRTUAL_HOST=' .env 2>/dev/null | tail -n1 | cut -d= -f2 | tr -d "'\""); \
	if [ -z "$$ENABLE_RABBITMQ_VALUE" ]; then ENABLE_RABBITMQ_VALUE=0; fi; \
	if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	  PROFILES="frontend,php,queue"; \
	else \
	  PROFILES="frontend,php"; \
	fi; \
	if [ -z "$$LOCAL_IP_VALUE" ]; then \
	  echo "⚠️  LOCAL_IP не задан в .env, используется 0.0.0.0 (все интерфейсы)"; \
	  COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env up -d; \
	else \
	  LOCAL_IP=$$LOCAL_IP_VALUE COMPOSE_PROFILES=$$PROFILES $(DOCKER_COMPOSE) --env-file .env up -d; \
	fi; \
	echo ""; \
	echo "✓ Контейнеры запущены"; \
	echo ""; \
	echo "🌐 Локальный доступ:"; \
	if [ -n "$$LOCAL_IP_VALUE" ]; then \
	  echo "   Frontend:  http://$$LOCAL_IP_VALUE:3000"; \
	  echo "   API (PHP): http://$$LOCAL_IP_VALUE:8000"; \
	  echo "   Database:  postgresql://$$LOCAL_IP_VALUE:5432"; \
	  if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	    echo "   RabbitMQ:  http://$$LOCAL_IP_VALUE:15672"; \
	  fi; \
	else \
	  echo "   Frontend:  http://localhost:3000"; \
	  echo "   API (PHP): http://localhost:8000"; \
	  echo "   Database:  postgresql://localhost:5432"; \
	  if [ "$$ENABLE_RABBITMQ_VALUE" = "1" ]; then \
	    echo "   RabbitMQ:  http://localhost:15672"; \
	  fi; \
	fi; \
	if [ -n "$$VIRTUAL_HOST_VALUE" ]; then \
	  echo ""; \
	  echo "🌎 Публичный доступ (через cloudpub на хосте):"; \
	  echo "   $$VIRTUAL_HOST_VALUE"; \
	fi; \
	echo ""; \
	echo "📊 Статус контейнеров:"
	@$(DOCKER_COMPOSE) ps

# Остановка контейнеров
stop-containers:
	@echo "⏹  Останавливаем контейнеры..."
	@COMPOSE_PROFILES=frontend,php,python,node,queue $(DOCKER_COMPOSE) stop
	@echo "✓ Контейнеры остановлены"

# Удаление контейнеров (остановленных и запущенных)
remove-containers:
	@echo "🗑️  Удаляем контейнеры..."
	@COMPOSE_PROFILES=frontend,php,python,node,queue $(DOCKER_COMPOSE) down --remove-orphans
	@docker container rm -f $$(docker container ls -aq --filter "name=frontend" --filter "name=api" --filter "name=rabbitmq" --filter "name=database" --filter "name=php-cli") 2>/dev/null || true
	@echo "✓ Контейнеры удалены"