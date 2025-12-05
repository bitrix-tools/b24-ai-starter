#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPORT_DIR="$ROOT_DIR/reports/security"
ENV_FILE="$ROOT_DIR/.env"
COMPOSE_BIN="docker compose"

ALLOW_FAILURES=0
if [[ "${1:-}" == "--allow-fail" ]]; then
  ALLOW_FAILURES=1
  shift
fi

if [[ "${SECURITY_SCAN_ALLOW_FAILURES:-0}" == "1" ]]; then
  ALLOW_FAILURES=1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "❌ Не найден файл .env. Сначала выполните make dev-init или создайте .env."
  exit 1
fi

mkdir -p "$REPORT_DIR"

FAILED_STEPS=()

run_step() {
  local name="$1"
  local command="$2"

  echo "▶ $name"
  if eval "$command"; then
    echo "✔ $name завершён"
  else
    local exit_code=$?
    echo "✖ $name завершился с ошибкой (код $exit_code)"
    FAILED_STEPS+=("$name")
  fi
  echo ""
}

skip_step() {
  local name="$1"
  local reason="$2"
  echo "⏭ $name — пропущен: $reason"
  echo ""
}

PHP_DIR="$ROOT_DIR/backends/php"
if [[ -d "$PHP_DIR" && -f "$PHP_DIR/composer.json" ]]; then
  run_step \
    "PHP composer audit" \
    "COMPOSE_PROFILES=php-cli $COMPOSE_BIN --env-file \"$ENV_FILE\" run --rm --workdir /var/www php-cli \
      bash -c 'composer audit --locked --format=json' \
      | tee \"$REPORT_DIR/php-composer.json\""
else
  skip_step "PHP composer audit" "нет каталога backends/php"
fi

FRONTEND_DIR="$ROOT_DIR/frontend"
if [[ -d "$FRONTEND_DIR" && -f "$FRONTEND_DIR/package.json" ]]; then
  run_step \
    "Frontend pnpm audit" \
    "COMPOSE_PROFILES=frontend $COMPOSE_BIN --env-file \"$ENV_FILE\" run --rm frontend \
      sh -c 'pnpm audit --prod --json' \
      | tee \"$REPORT_DIR/frontend-pnpm.json\""
else
  skip_step "Frontend pnpm audit" "нет каталога frontend"
fi

if [[ ${#FAILED_STEPS[@]} -gt 0 ]]; then
  echo "⚠ Найдены проблемы на этапах: ${FAILED_STEPS[*]}"
  if [[ "$ALLOW_FAILURES" == "1" ]]; then
    echo "ℹ️ Включён мягкий режим (allow-fail), скрипт завершается с кодом 0."
  else
    echo "ℹ️ Чтобы проигнорировать код выхода, выполните SECURITY_SCAN_ALLOW_FAILURES=1 make security-scan или ./scripts/security-scan.sh --allow-fail."
    exit 2
  fi
fi

echo "✅ Security-scan завершён; отчёты сохранены в $REPORT_DIR"

