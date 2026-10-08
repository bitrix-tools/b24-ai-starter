#!/bin/bash

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

echo "🔧 Исправление проблем с PHP бэкендом"
echo "======================================"

# Останавливаем все контейнеры
print_warning "Останавливаем все контейнеры..."
make down >/dev/null 2>&1 || true

# Удаляем только vendor: composer.lock — источник версий, entrypoint php-fpm
# выполнит `composer install` по нему. Обновление версий — `make composer-update`.
print_warning "Очищаем vendor..."
rm -rf backends/php/vendor

print_success "vendor очищен"

# Перезапускаем PHP контейнеры
print_warning "Запускаем PHP контейнеры с исправленными зависимостями..."
make dev-php

print_success "Готово! PHP контейнеры должны запуститься корректно"