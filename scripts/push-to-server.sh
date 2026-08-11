#!/usr/bin/env bash
# Локальный запуск (на Mac): загрузка проекта на сервер и деплой.
#
#   chmod +x scripts/push-to-server.sh
#   ./scripts/push-to-server.sh
#
# Требует SSH-доступ: root@31.129.97.40 (пароль из Timeweb или ключ)

set -euo pipefail

SERVER="${DEPLOY_SERVER:-root@31.129.97.40}"
REMOTE_DIR="${DEPLOY_REMOTE_DIR:-/opt/b24-tochka-app}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Upload $ROOT -> ${SERVER}:${REMOTE_DIR}"

ssh "$SERVER" "mkdir -p ${REMOTE_DIR}"

rsync -avz --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude 'frontend/node_modules' \
  --exclude 'frontend/.output' \
  --exclude 'frontend/.nuxt' \
  --exclude 'frontend/.pnpm-store' \
  --exclude 'logs' \
  --exclude '.cursor' \
  --exclude '__pycache__' \
  --exclude '*.pyc' \
  --exclude '.env' \
  "$ROOT/" "${SERVER}:${REMOTE_DIR}/"

if [[ -f "$ROOT/.env" ]]; then
  echo "==> Upload .env (production secrets)"
  scp "$ROOT/.env" "${SERVER}:${REMOTE_DIR}/.env"
elif [[ -f "$ROOT/.env.production.example" ]]; then
  echo "==> .env не найден — на сервере будет использован .env.production.example"
  ssh "$SERVER" "test -f ${REMOTE_DIR}/.env || cp ${REMOTE_DIR}/.env.production.example ${REMOTE_DIR}/.env"
fi

echo "==> Run deploy on server..."
ssh "$SERVER" "cd ${REMOTE_DIR} && chmod +x scripts/*.sh && ./scripts/deploy-1daa-production.sh"

echo ""
echo "Done. Open: https://1daa.ru/tochka/"
