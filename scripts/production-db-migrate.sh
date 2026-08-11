#!/usr/bin/env bash
# Развёртывание схемы БД на сервере (PostgreSQL + Django migrations).
#
# Использование:
#   ./scripts/production-db-migrate.sh init          # базовые таблицы + django migrate
#   ./scripts/production-db-migrate.sh full          # полный schema-production.sql (свежий сервер)
#   ./scripts/production-db-migrate.sh migrate-only  # только django migrate (БД уже с init.sql)
#
# Переменные окружения (или из .env):
#   DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
#   DATABASE_URL=postgresql://user:pass@host:5432/dbname

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="${1:-init}"

if [[ -f "$ROOT/.env" ]]; then
  set -a
  # shellcheck disable=SC1091
  source "$ROOT/.env"
  set +a
fi

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_NAME:-appdb}"
DB_USER="${DB_USER:-appuser}"
export PGPASSWORD="${DB_PASSWORD:-apppass}"

psql_base() {
  psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" "$@"
}

run_django_migrate() {
  echo "→ Django migrate..."
  if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -qx 'api'; then
    docker exec api python manage.py migrate --noinput
  elif [[ -f "$ROOT/backends/python/api/manage.py" ]]; then
    cd "$ROOT/backends/python/api"
    python manage.py migrate --noinput
  else
    echo "Не найден manage.py и контейнер api. Запустите migrate вручную." >&2
    exit 1
  fi
  echo "✓ Миграции Django применены"
}

case "$MODE" in
  init)
    echo "→ Применяем infrastructure/database/init.sql..."
    psql_base -f "$ROOT/infrastructure/database/init.sql"
    run_django_migrate
    ;;
  full)
    echo "→ Применяем infrastructure/database/schema-production.sql..."
    psql_base -f "$ROOT/infrastructure/database/schema-production.sql"
    echo "✓ Полная схема загружена (django_migrations уже в дампе)"
    ;;
  migrate-only)
    run_django_migrate
    ;;
  *)
    echo "Неизвестный режим: $MODE (init | full | migrate-only)" >&2
    exit 1
    ;;
esac

echo "Готово."
