#!/usr/bin/env bash
# Print the COMPOSE_PROFILES list for a backend, based on .env:
#   DB_TYPE=postgresql|mysql  -> db-postgres | db-mysql
#   ENABLE_RABBITMQ=1         -> queue (+ python-worker for Python)
#
# Usage: scripts/compose-profiles.sh php|python|node|front [env-file]
# Used by the dev-* / prod-* make targets so the list is defined in one place.
set -euo pipefail

backend="${1:?usage: compose-profiles.sh php|python|node|front [env-file]}"
env_file="${2:-.env}"

read_env() {
  grep -E "^$1=" "$env_file" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d "\"'" || true
}

db_type="$(read_env DB_TYPE)"
rabbitmq="$(read_env ENABLE_RABBITMQ)"

profiles="frontend,cloudpub"

case "$backend" in
  php|python|node) profiles="$profiles,$backend" ;;
  front) echo "$profiles"; exit 0 ;;
  *) echo "unknown backend: $backend" >&2; exit 2 ;;
esac

if [ "$db_type" = "mysql" ]; then
  profiles="$profiles,db-mysql"
else
  profiles="$profiles,db-postgres"
fi

if [ "$rabbitmq" = "1" ]; then
  profiles="$profiles,queue"
  if [ "$backend" = "python" ]; then
    profiles="$profiles,python-worker"
  fi
fi

echo "$profiles"
