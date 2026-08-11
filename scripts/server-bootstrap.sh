#!/usr/bin/env bash
# Полная установка Docker на чистом Ubuntu-сервере (Timeweb VPS).
# Запуск на сервере: bash scripts/server-bootstrap.sh

set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Запустите от root: sudo bash scripts/server-bootstrap.sh" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

if ! command -v docker >/dev/null 2>&1; then
  echo "==> Install Docker..."
  apt-get update -qq
  apt-get install -y -qq ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
  systemctl enable docker
  systemctl start docker
fi

echo "==> Docker: $(docker --version)"
echo "==> Compose: $(docker compose version)"

# Swap 2G если RAM < 5G и swap нет
if ! swapon --show | grep -q .; then
  if [[ $(free -m | awk '/^Mem:/{print $2}') -lt 5120 ]]; then
    echo "==> Add 2G swap..."
    fallocate -l 2G /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=2048
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  fi
fi

echo "Bootstrap OK. Next: ./scripts/deploy-1daa-production.sh"
