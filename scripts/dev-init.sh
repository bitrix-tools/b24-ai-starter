#!/bin/bash

set -euo pipefail

# Цвета для вывода (отключаем, если stdout не TTY)
if [ -t 1 ]; then
    ESC="$(printf '\033')"
    RED="${ESC}[0;31m"
    GREEN="${ESC}[0;32m"
    YELLOW="${ESC}[1;33m"
    BLUE="${ESC}[0;34m"
    NC="${ESC}[0m"
else
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    NC=''
fi

# Helpers for environment manipulations
set_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" .env 2>/dev/null; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s|^${key}=.*|${key}=${value}|" .env
        else
            sed -i "s|^${key}=.*|${key}=${value}|" .env
        fi
    else
        echo "${key}=${value}" >> .env
    fi
}

get_env_var() {
    local key="$1"
    local default_value="$2"
    local current_value

    current_value=$(grep -E "^${key}=" .env 2>/dev/null | tail -n1 | cut -d= -f2- )
    if [ -z "$current_value" ]; then
        echo "$default_value"
    else
        echo "$current_value"
    fi
}

generate_random_string() {
    local length="${1:-8}"
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex $((length / 2))
    else
        tr -dc 'a-z0-9' </dev/urandom | head -c "$length"
    fi
}

# Функция для вывода заголовков
print_header() {
    echo ""
    echo -e "${BLUE}===============================================${NC}"
    echo -e "${BLUE} $1${NC}"
    echo -e "${BLUE}===============================================${NC}"
    echo ""
}

# Функция для вывода успешных сообщений
print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# Функция для вывода предупреждений
print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Функция для вывода ошибок
print_error() {
    echo -e "${RED}✗ $1${NC}"
}

# Определяем пути
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSIONS_DIR="$REPO_ROOT/versions"
WORKDIR="$REPO_ROOT"
SELECTED_VERSION=""
ACTIVE_CONTEXT_LABEL="корневая копия"

# Обработка аргументов/переменных
REQUESTED_VERSION=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        -v|--version)
            shift
            if [ $# -eq 0 ]; then
                print_error "Для параметра --version укажите имя версии (или root)"
                exit 1
            fi
            REQUESTED_VERSION="$1"
            shift
            ;;
        -h|--help)
            echo "Использование: $0 [--version <имя>|root]"
            exit 0
            ;;
        *)
            print_error "Неизвестный аргумент: $1"
            exit 1
            ;;
    esac
done

if [ -z "$REQUESTED_VERSION" ] && [ -n "${DEV_INIT_VERSION:-}" ]; then
    REQUESTED_VERSION="${DEV_INIT_VERSION}"
fi

# Сканируем доступные версии
AVAILABLE_VERSIONS=()
if [ -d "$VERSIONS_DIR" ]; then
    while IFS= read -r dir; do
        [ -z "$dir" ] && continue
        AVAILABLE_VERSIONS+=("$(basename "$dir")")
    done < <(find "$VERSIONS_DIR" -mindepth 1 -maxdepth 1 -type d | sort)
fi

DEFAULT_VERSION_FROM_ENV=""
if [ -f "$REPO_ROOT/.env" ]; then
    if grep -qE '^APP_VERSION=' "$REPO_ROOT/.env"; then
        DEFAULT_VERSION_FROM_ENV=$(grep -E '^APP_VERSION=' "$REPO_ROOT/.env" | tail -n1 | cut -d= -f2- | tr -d '[:space:]')
    fi
fi

select_version_interactively() {
    local total="$1"
    local default_choice="$2"
    local choice=""

    while true; do
        read -p "Введите номер [${default_choice}]: " choice
        choice=${choice:-$default_choice}

        if [[ "$choice" =~ ^[0-9]+$ ]] && [ "$choice" -ge 0 ] && [ "$choice" -le "$total" ]; then
            echo "$choice"
            return 0
        fi

        print_warning "Некорректный выбор, попробуйте снова."
    done
}

if [ ${#AVAILABLE_VERSIONS[@]} -gt 0 ]; then
    TOTAL_OPTIONS=${#AVAILABLE_VERSIONS[@]}
    DEFAULT_CHOICE=0

    if [ -n "$DEFAULT_VERSION_FROM_ENV" ]; then
        for idx in "${!AVAILABLE_VERSIONS[@]}"; do
            if [ "${AVAILABLE_VERSIONS[$idx]}" = "$DEFAULT_VERSION_FROM_ENV" ]; then
                DEFAULT_CHOICE=$((idx + 1))
                break
            fi
        done
    fi

    if [ -n "$REQUESTED_VERSION" ]; then
        REQ_LOWER=$(printf '%s' "$REQUESTED_VERSION" | tr '[:upper:]' '[:lower:]')
        if [ "$REQ_LOWER" = "root" ]; then
            SELECTED_VERSION=""
        else
            FOUND=false
            for name in "${AVAILABLE_VERSIONS[@]}"; do
                if [ "$name" = "$REQUESTED_VERSION" ]; then
                    FOUND=true
                    break
                fi
            done

            if [ "$FOUND" = false ]; then
                print_error "Версия '$REQUESTED_VERSION' не найдена в каталоге versions/"
                exit 1
            fi

            SELECTED_VERSION="$REQUESTED_VERSION"
        fi
    else
        print_header "📦 Обнаружены версии проекта"
        ROOT_SUFFIX=""
        if [ "$DEFAULT_CHOICE" -eq 0 ]; then
            ROOT_SUFFIX=" (по умолчанию)"
        fi
        echo "0) Корневая копия репозитория${ROOT_SUFFIX}"
        for idx in "${!AVAILABLE_VERSIONS[@]}"; do
            num=$((idx + 1))
            suffix=""
            if [ "$num" -eq "$DEFAULT_CHOICE" ]; then
                suffix=" (по умолчанию)"
            fi
            echo "${num}) versions/${AVAILABLE_VERSIONS[$idx]}${suffix}"
        done

        SELECTED_NUMBER=$(select_version_interactively "$TOTAL_OPTIONS" "$DEFAULT_CHOICE")
        if [ "$SELECTED_NUMBER" -eq 0 ]; then
            SELECTED_VERSION=""
        else
            array_index=$((SELECTED_NUMBER - 1))
            SELECTED_VERSION="${AVAILABLE_VERSIONS[$array_index]}"
        fi
    fi
else
    if [ -n "$REQUESTED_VERSION" ]; then
        REQ_LOWER=$(printf '%s' "$REQUESTED_VERSION" | tr '[:upper:]' '[:lower:]')
        if [ "$REQ_LOWER" != "root" ]; then
            print_error "Каталог versions/ отсутствует, невозможно выбрать '$REQUESTED_VERSION'"
            exit 1
        fi
    fi
fi

if [ -n "$SELECTED_VERSION" ]; then
    WORKDIR="$VERSIONS_DIR/$SELECTED_VERSION"
    if [ ! -d "$WORKDIR" ]; then
        print_error "Каталог versions/$SELECTED_VERSION не найден"
        exit 1
    fi
    ACTIVE_CONTEXT_LABEL="versions/$SELECTED_VERSION"
else
    WORKDIR="$REPO_ROOT"
    ACTIVE_CONTEXT_LABEL="корневая копия"
fi

cd "$WORKDIR"

print_header "📁 Контекст: $ACTIVE_CONTEXT_LABEL"

# Проверяем наличие .env файла
if [ ! -f ".env" ]; then
    print_warning "Файл .env не найден. Копируем из .env.example..."
    if [ -f ".env.example" ]; then
        cp .env.example .env
        print_success "Файл .env создан из .env.example"
        print_warning "ВАЖНО: Обязательно обновите CloudPub API токен в файле .env!"
    else
        print_error "Файл .env.example не найден!"
        print_error "Создайте файл .env.example с необходимыми переменными окружения"
        exit 1
    fi
else
    print_success "Файл .env найден"
fi

print_header "🚀 Bitrix24 AI Starter - Инициализация проекта (${ACTIVE_CONTEXT_LABEL})"

# 1. Запрос API ключа CloudPub
print_header "🔑 Настройка CloudPub"

# Проверяем существующий ключ в .env
EXISTING_TOKEN=$(grep "CLOUDPUB_TOKEN=" .env | cut -d"'" -f2 2>/dev/null || true)
if [ ! -z "$EXISTING_TOKEN" ] && [ "$EXISTING_TOKEN" != "your_cloudpub_token_here" ]; then
    echo "Найден существующий API ключ CloudPub в .env"
    read -p "Использовать существующий ключ? (y/n, по умолчанию y): " USE_EXISTING
    USE_EXISTING=${USE_EXISTING:-y}
    
    if [[ "$USE_EXISTING" =~ ^[Yy]$ ]]; then
        CLOUDPUB_TOKEN="$EXISTING_TOKEN"
        print_success "Используем существующий API ключ CloudPub"
    else
        echo "Введите новый API ключ CloudPub:"
        echo "(Получить можно на https://cloudpub.ru/)"
        read -p "CloudPub API Token: " CLOUDPUB_TOKEN
    fi
else
    echo "Введите ваш API ключ CloudPub:"
    echo "(Получить можно на https://cloudpub.ru/)"
    read -p "CloudPub API Token: " CLOUDPUB_TOKEN
fi

if [ -z "$CLOUDPUB_TOKEN" ]; then
    print_error "API ключ CloudPub обязателен!"
    exit 1
fi

# Обновляем .env файл с токеном CloudPub
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' "s/CLOUDPUB_TOKEN='your_cloudpub_token_here'/CLOUDPUB_TOKEN='$CLOUDPUB_TOKEN'/" .env
else
    # Linux
    sed -i "s/CLOUDPUB_TOKEN='your_cloudpub_token_here'/CLOUDPUB_TOKEN='$CLOUDPUB_TOKEN'/" .env
fi

print_success "API ключ CloudPub сохранен в .env"

# 2. Выбор языка бэкенда
print_header "🛠 Выбор бэкенда"
echo "Выберите язык для бэкенда:"
echo "1) PHP (Symfony)"
echo "2) Python (Django)" 
echo "3) Node.js (Express)"
echo ""
read -p "Введите номер (1-3): " BACKEND_CHOICE

case $BACKEND_CHOICE in
    1)
        BACKEND="php"
        SERVER_HOST="http://api-php:8000"
        ;;
    2)
        BACKEND="python"
        SERVER_HOST="http://api-python:8000"
        ;;
    3)
        BACKEND="node"
        SERVER_HOST="http://api-node:8000"
        ;;
    *)
        print_error "Неверный выбор! Используется PHP по умолчанию."
        BACKEND="php"
        SERVER_HOST="http://api-php:8000"
        ;;
esac

print_success "Выбран бэкенд: $BACKEND"

# Обновляем SERVER_HOST в .env (для внутреннего взаимодействия контейнеров)
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS
    sed -i '' "s|SERVER_HOST='http://api-some:8000'|SERVER_HOST='$SERVER_HOST'|" .env
else
    # Linux
    sed -i "s|SERVER_HOST='http://api-some:8000'|SERVER_HOST='$SERVER_HOST'|" .env
fi

print_success "SERVER_HOST обновлен в .env: $SERVER_HOST"

print_header "🐇 Настройка RabbitMQ"
read -p "Включить RabbitMQ для фоновых задач? (y/N, по умолчанию n): " RABBITMQ_TOGGLE
RABBITMQ_TOGGLE=${RABBITMQ_TOGGLE:-n}

RABBITMQ_ENABLED="0"

if [[ "$RABBITMQ_TOGGLE" =~ ^[Yy]$ ]]; then
    RABBITMQ_ENABLED="1"

    print_header "⚙ Режим настройки RabbitMQ"
    echo "1) Автоматически (рекомендуется)"
    echo "2) Вручную"
    read -p "Выберите режим [1]: " RABBITMQ_MODE
    RABBITMQ_MODE=${RABBITMQ_MODE:-1}

    if [ "$RABBITMQ_MODE" -eq 1 ]; then
        RABBITMQ_USER="queue_$(generate_random_string 6)"
        RABBITMQ_PASSWORD="$(generate_random_string 12)"
        RABBITMQ_PREFETCH="5"

        print_success "RabbitMQ будет настроен автоматически"
        echo "Имя пользователя: $RABBITMQ_USER"
        echo "Пароль: $RABBITMQ_PASSWORD"
        echo "Prefetch: $RABBITMQ_PREFETCH"
    else
        EXISTING_RABBITMQ_USER=$(get_env_var "RABBITMQ_USER" "queue_user")
        read -p "Имя пользователя [${EXISTING_RABBITMQ_USER}]: " RABBITMQ_USER
        RABBITMQ_USER=${RABBITMQ_USER:-$EXISTING_RABBITMQ_USER}

        EXISTING_RABBITMQ_PASSWORD=$(get_env_var "RABBITMQ_PASSWORD" "queue_password")
        read -p "Пароль [${EXISTING_RABBITMQ_PASSWORD}]: " RABBITMQ_PASSWORD
        RABBITMQ_PASSWORD=${RABBITMQ_PASSWORD:-$EXISTING_RABBITMQ_PASSWORD}

        EXISTING_RABBITMQ_PREFETCH=$(get_env_var "RABBITMQ_PREFETCH" "5")
        read -p "Prefetch (размер выборки сообщений) [${EXISTING_RABBITMQ_PREFETCH}]: " RABBITMQ_PREFETCH
        RABBITMQ_PREFETCH=${RABBITMQ_PREFETCH:-$EXISTING_RABBITMQ_PREFETCH}
    fi
else
    print_warning "RabbitMQ будет отключен. Вы сможете включить его позднее вручную."
fi

set_env_var "ENABLE_RABBITMQ" "$RABBITMQ_ENABLED"

if [ "$RABBITMQ_ENABLED" = "1" ]; then
    set_env_var "RABBITMQ_USER" "$RABBITMQ_USER"
    set_env_var "RABBITMQ_PASSWORD" "$RABBITMQ_PASSWORD"
    set_env_var "RABBITMQ_PREFETCH" "$RABBITMQ_PREFETCH"
    set_env_var "RABBITMQ_DSN" "amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f"
    print_success "Параметры RabbitMQ сохранены в .env"
fi

# Удаляем неиспользуемые папки бэкендов и инструкций
print_header "🗂 Очистка неиспользуемых бэкендов и инструкций"

# Очищаем папки бэкендов
print_warning "Очистка неиспользуемых папок бэкендов..."
cd backends

for backend_dir in php python node; do
    if [ "$backend_dir" != "$BACKEND" ] && [ -d "$backend_dir" ]; then
        print_warning "Удаляем папку backends/$backend_dir..."

        # если не хочется удалять, можно закомментировать следующую строку
        rm -rf "$backend_dir"
        
        print_success "Папка backends/$backend_dir удалена"
    fi
done

cd ..

# Очищаем папки инструкций для неиспользуемых бэкендов
print_warning "Очистка неиспользуемых папок инструкций..."
cd instructions

for instruction_dir in php python node; do
    if [ "$instruction_dir" != "$BACKEND" ] && [ -d "$instruction_dir" ]; then
        print_warning "Удаляем папку instructions/$instruction_dir..."

        # если не хочется удалять, можно закомментировать следующую строку  
        rm -rf "$instruction_dir"
        
        print_success "Папка instructions/$instruction_dir удалена"
    fi
done

cd ..

# 3. Дополнительные настройки для Python
if [ "$BACKEND" = "python" ]; then
    print_header "🐍 Дополнительные настройки Django"
    
    read -p "Имя администратора Django (по умолчанию: admin): " DJANGO_USERNAME
    DJANGO_USERNAME=${DJANGO_USERNAME:-admin}
    
    read -p "Email администратора Django (по умолчанию: admin@example.com): " DJANGO_EMAIL
    DJANGO_EMAIL=${DJANGO_EMAIL:-admin@example.com}
    
    read -s -p "Пароль администратора Django (по умолчанию: admin123): " DJANGO_PASSWORD
    DJANGO_PASSWORD=${DJANGO_PASSWORD:-admin123}
    echo ""
    
    # Обновляем настройки Django в .env
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s/DJANGO_SUPERUSER_USERNAME=\"admin\"/DJANGO_SUPERUSER_USERNAME=\"$DJANGO_USERNAME\"/" .env
        sed -i '' "s/DJANGO_SUPERUSER_EMAIL=\"admin@example.com\"/DJANGO_SUPERUSER_EMAIL=\"$DJANGO_EMAIL\"/" .env
        sed -i '' "s/DJANGO_SUPERUSER_PASSWORD=\"password\"/DJANGO_SUPERUSER_PASSWORD=\"$DJANGO_PASSWORD\"/" .env
    else
        # Linux
        sed -i "s/DJANGO_SUPERUSER_USERNAME=\"admin\"/DJANGO_SUPERUSER_USERNAME=\"$DJANGO_USERNAME\"/" .env
        sed -i "s/DJANGO_SUPERUSER_EMAIL=\"admin@example.com\"/DJANGO_SUPERUSER_EMAIL=\"$DJANGO_EMAIL\"/" .env
        sed -i "s/DJANGO_SUPERUSER_PASSWORD=\"password\"/DJANGO_SUPERUSER_PASSWORD=\"$DJANGO_PASSWORD\"/" .env
    fi
    
    print_success "Настройки Django обновлены"
fi

# 4. Двухэтапный запуск контейнеров
print_header "🐳 Двухэтапный запуск Docker контейнеров"

# Создаем временный файл для сохранения вывода
TEMP_OUTPUT="/tmp/docker_output_$$"

# Сначала очищаем все контейнеры и сети для чистого старта
print_warning "Очистка предыдущих контейнеров и сетей..."
docker-compose down --remove-orphans --volumes > /dev/null 2>&1 || true
docker container rm -f $(docker container ls -aq --filter "name=b24-ai-starter\|frontend\|cloudpub") > /dev/null 2>&1 || true
# Более агрессивная очистка сетей
docker network rm b24-ai-starter_internal-net > /dev/null 2>&1 || true
docker network prune -f > /dev/null 2>&1 || true
docker volume prune -f > /dev/null 2>&1 || true
sleep 5  # Даём больше времени Docker'у для полной очистки

# Дополнительно удаляем стандартные контейнеры, если они зависли с прошлого запуска
for stuck_container in frontend cloudpubFront; do
    if docker ps -a --format '{{.Names}}' | grep -qx "$stuck_container"; then
        print_warning "Удаляем зависший контейнер $stuck_container..."
        docker rm -f "$stuck_container" > /dev/null 2>&1 || true
    fi
done

# ЭТАП 1: Запускаем только CloudPub и минимальный frontend для получения домена
print_header "🌐 ЭТАП 1: Получение CloudPub домена"
echo "Запускаем CloudPub для получения публичного домена..."
echo "Важно: запускаем только frontend + CloudPub для получения домена, БД не нужна"

# Запускаем только frontend и cloudpub без БД - этого достаточно для получения домена
if ! COMPOSE_PROFILES=frontend,cloudpub docker compose up frontend cloudpub --build -d > "$TEMP_OUTPUT" 2>&1; then
    print_error "Не удалось запустить контейнеры frontend и cloudpub на первом этапе."
    if [ -s "$TEMP_OUTPUT" ]; then
        echo ""
        echo "=== Вывод docker compose ==="
        cat "$TEMP_OUTPUT"
        echo "=== Конец вывода ==="
    fi
    exit 1
fi

# Ждем запуск CloudPub
print_warning "Ожидание запуска CloudPub..."
CLOUDPUB_STARTED=false
for i in {1..30}; do
    # Ищем контейнер по правильному имени cloudpubFront
    if docker ps --filter "name=cloudpubFront" --format "{{.Names}}" | grep -q cloudpubFront; then
        print_success "CloudPub контейнер запущен!"
        CLOUDPUB_STARTED=true
        break
    fi
    
    # Показываем прогресс каждые 10 секунд
    if [ $((i % 5)) -eq 0 ]; then
        echo "Попытка $i/30: ожидание CloudPub контейнера..."
    fi
    
    if [ $i -eq 30 ]; then
        print_error "CloudPub не запустился за 60 секунд!"
        echo "Вывод Docker сборки:"
        cat "$TEMP_OUTPUT"
        echo -e "\n=== Статус всех контейнеров ==="
        docker ps -a
        echo -e "\n=== Логи CloudPub (если контейнер существует) ==="
        docker logs cloudpubFront 2>/dev/null || echo "Контейнер cloudpubFront не найден"
        echo -e "\n=== Docker сети ==="
        docker network ls
        
        # Не выходим сразу, а проверим, может домен все же есть в логах
        print_warning "Проверяем, может домен все же был получен..."
        if docker container ls -a --filter "name=cloudpubFront" --format "{{.Names}}" | grep -q cloudpubFront; then
            CLOUDPUB_LOGS=$(docker logs cloudpubFront 2>&1)
            if echo "$CLOUDPUB_LOGS" | grep -q "https://.*\.cloudpub\."; then
                print_warning "Контейнер не запущен, но домен найден в логах!"
                break
            fi
        fi
        
        exit 1
    fi
    
    sleep 2
done

# 5. Получение домена от CloudPub
print_header "🌐 Получение домена CloudPub"

print_warning "Ищем домен CloudPub в выводе сборки и логах..."

CLOUDPUB_DOMAIN=""

# Сначала ищем в выводе сборки
if [ -f "$TEMP_OUTPUT" ]; then
    CLOUDPUB_DOMAIN=$(grep -o 'https://[a-zA-Z0-9.-]*\.cloudpub\.[a-z]*' "$TEMP_OUTPUT" | head -1 || true)
fi

# Если не найден в выводе сборки, ищем в логах контейнера
if [ -z "$CLOUDPUB_DOMAIN" ]; then
    print_warning "Домен не найден в выводе сборки, проверяем логи контейнера..."
    
    # Проверяем наличие контейнера cloudpubFront
    CLOUDPUB_CONTAINER=$(docker container ls -a --filter "name=cloudpubFront" --format "{{.Names}}")
    
    if [ ! -z "$CLOUDPUB_CONTAINER" ]; then
        FOUND_IN_LOGS=false
        # Ждем, чтобы CloudPub успел зарегистрировать сервис
        for i in {1..15}; do
            sleep 3
            CLOUDPUB_LOGS=$(docker logs cloudpubFront 2>&1 || true)
            
            # Ищем строку регистрации сервиса (несколько вариантов)
            if echo "$CLOUDPUB_LOGS" | grep -q "Сервис зарегистрирован\|Сервис опубликован\|https://.*\.cloudpub\."; then
                # Пробуем несколько паттернов для извлечения домена
                CLOUDPUB_DOMAIN=$(echo "$CLOUDPUB_LOGS" | grep -o 'https://[a-zA-Z0-9.-]*\.cloudpub\.[a-z]*' | head -1 || true)
                
                if [ ! -z "$CLOUDPUB_DOMAIN" ]; then
                    print_success "CloudPub сервис зарегистрирован: $CLOUDPUB_DOMAIN"
                    FOUND_IN_LOGS=true
                    break
                fi
            fi
            
            # Проверяем на ошибки API ключа
            if echo "$CLOUDPUB_LOGS" | grep -q "Неверный ключ API\|Invalid API key\|401\|403"; then
                print_error "Неверный API ключ CloudPub!"
                print_warning "Пожалуйста, проверьте ваш API ключ на https://cloudpub.ru/"
                print_warning "Обновите CLOUDPUB_TOKEN в файле .env с правильным ключом"
                print_warning "После этого перезапустите контейнеры командой: make down && make dev-$BACKEND"
                exit 1
            fi
            
            # Показываем прогресс
            echo "Попытка $i/15: ждем регистрации CloudPub сервиса..."
            if [ $i -eq 5 ] || [ $i -eq 10 ]; then
                echo "Текущие логи CloudPub:"
                echo "$CLOUDPUB_LOGS" | tail -5
                echo ""
            fi
        done

        if [ "$FOUND_IN_LOGS" = false ]; then
            print_error "Не удалось извлечь домен из логов CloudPub."
            echo ""
            echo "Советы:"
            echo "  • Убедитесь, что API ключ CloudPub активен и не превышен лимит."
            echo "  • Проверьте логи: docker logs cloudpubFront"
            echo "  • Попробуйте перезапустить: make down && docker rm -f cloudpubFront frontend && make dev-init"
            exit 1
        fi
    else
        print_error "Контейнер cloudpubFront не найден!"
        echo "Доступные контейнеры:"
        docker ps -a --format "table {{.Names}}\t{{.Status}}"
        exit 1
    fi
fi

# Очищаем временный файл
[ -f "$TEMP_OUTPUT" ] && rm "$TEMP_OUTPUT"

if [ ! -z "$CLOUDPUB_DOMAIN" ]; then
    print_success "Найден CloudPub домен: $CLOUDPUB_DOMAIN"
    
    # Обновляем VIRTUAL_HOST в .env (публичный домен для внешних подключений)
    if [[ "$OSTYPE" == "darwin"* ]]; then
        # macOS
        sed -i '' "s|VIRTUAL_HOST='.*'|VIRTUAL_HOST='$CLOUDPUB_DOMAIN'|" .env
    else
        # Linux
        sed -i "s|VIRTUAL_HOST='.*'|VIRTUAL_HOST='$CLOUDPUB_DOMAIN'|" .env
    fi
    
    print_success "VIRTUAL_HOST обновлен в .env: $CLOUDPUB_DOMAIN"
    
    # ЭТАП 2: Перезапускаем все сервисы с правильными переменными окружения
    print_header "🔄 ЭТАП 2: Перезапуск с правильными переменными"
    print_warning "Останавливаем контейнеры для перезапуска с новым доменом..."
    make down > /dev/null 2>&1
    
    echo "Запускаем полный стек для бэкенда: $BACKEND"
    case $BACKEND in
        "php")
            echo "Запуск: make dev-php"
            make dev-php &
            DOCKER_PID=$!
            ;;
        "python")
            echo "Запуск: make dev-python" 
            make dev-python &
            DOCKER_PID=$!
            ;;
        "node")
            echo "Запуск: make dev-node"
            make dev-node &
            DOCKER_PID=$!
            ;;
    esac
    
    print_warning "Ожидание запуска всех сервисов с новым доменом..."
    sleep 20
    
    # Проверяем, что все сервисы запустились
    if docker ps --filter "name=cloudpubFront" --format "{{.Names}}" | grep -q cloudpubFront && docker ps --filter "name=frontend" --format "{{.Names}}" | grep -q frontend; then
        print_success "Все контейнеры успешно перезапущены с правильным доменом!"
        
        # Инициализация базы данных для PHP после успешного запуска
        if [ "$BACKEND" = "php" ]; then
            print_header "🗄 Настройка PHP и базы данных"
            
            print_warning "Ждем инициализации PHP контейнера..."
            sleep 10
            
            print_warning "Очистка и переустановка PHP зависимостей..."
            # Удаляем проблемные зависимости и переустанавливаем
            docker exec -i $(docker ps | grep api | awk '{print $1}') rm -rf /var/www/vendor /var/www/composer.lock 2>/dev/null || true
            
            if make composer-install 2>&1 | grep -q "Installation failed\|Fatal error\|Error:"; then
                print_warning "Стандартная установка не удалась, пробуем принудительную переустановку..."
                make composer-install --ignore-platform-reqs 2>/dev/null || true
            fi
            
            # Проверяем, что composer install прошел успешно
            if docker exec $(docker ps | grep api | awk '{print $1}') test -f /var/www/vendor/autoload.php 2>/dev/null; then
                print_success "PHP зависимости установлены успешно"
                
                print_warning "Инициализируем структуру базы данных..."
                if make dev-php-init-database > /dev/null 2>&1; then
                    print_success "База данных PHP инициализирована"
                else
                    print_warning "Проблемы с инициализацией БД. Выполните вручную: make dev-php-init-database"
                fi
            else
                print_error "Не удалось установить PHP зависимости"
                print_warning "Выполните вручную после запуска:"
                print_warning "  make composer-install"
                print_warning "  make dev-php-init-database"
            fi
        fi
        
    else
        print_warning "Возможны проблемы с перезапуском. Проверьте статус контейнеров."
    fi
    
else
    print_warning "CloudPub домен не найден автоматически."
    print_error "Без домена CloudPub невозможно правильно настроить фронтенд!"
    
    if docker ps --filter "name=cloudpubFront" --format "{{.Names}}" | grep -q cloudpubFront; then
        echo "CloudPub контейнер запущен, но возможны проблемы с API ключом."
        echo "Проверьте логи: ${YELLOW}docker logs cloudpubFront${NC}"
    else
        echo "CloudPub контейнер не запущен."
        echo "Проверьте статус: ${YELLOW}docker ps -a --filter name=cloudpubFront${NC}"
    fi
    echo ""
    echo "Для исправления:"
    echo "1. Убедитесь, что API ключ правильный в .env файле"
    echo "2. Перезапустите: ${YELLOW}make down && ./scripts/dev-init.sh${NC}"
    echo "3. Или получите домен вручную и обновите VIRTUAL_HOST в .env"
    exit 1
fi

# 6. Финальные инструкции
print_header "🎉 Инициализация завершена!"

echo -e "${GREEN}🎉 Проект успешно инициализирован с двухэтапным запуском!${NC}"
echo ""
echo "✅ Что сделано:"
echo "   - Получен CloudPub домен: ${BLUE}$(grep VIRTUAL_HOST .env | cut -d"'" -f2)${NC}"
echo "   - Обновлены переменные окружения"
echo "   - Запущены все контейнеры с правильным доменом"
if [ "$RABBITMQ_ENABLED" = "1" ]; then
    RABBITMQ_USER_SUMMARY="${RABBITMQ_USER:-$(get_env_var "RABBITMQ_USER" "queue_user")}"
    RABBITMQ_PASSWORD_SUMMARY="${RABBITMQ_PASSWORD:-$(get_env_var "RABBITMQ_PASSWORD" "queue_password")}"
    RABBITMQ_PREFETCH_SUMMARY="${RABBITMQ_PREFETCH:-$(get_env_var "RABBITMQ_PREFETCH" "5")}"
    echo "   - Запущен RabbitMQ (профиль queue включён)"
    echo "   - Учётные данные RabbitMQ: ${BLUE}${RABBITMQ_USER_SUMMARY}:${RABBITMQ_PASSWORD_SUMMARY}${NC} (prefetch ${RABBITMQ_PREFETCH_SUMMARY})"
fi
if [ "$BACKEND" = "php" ]; then
echo "   - Настроена база данных PHP"
fi
echo ""
echo "🔗 Ваше приложение доступно по адресу:"
echo "   ${BLUE}$(grep VIRTUAL_HOST .env | cut -d"'" -f2)${NC}"
echo ""
echo "📝 Следующие шаги:"
echo "1. Создайте локальное приложение в Bitrix24:"
echo "   - Bitrix24 → Developer Resources → Other → Local Applications"
echo "   - Your handler path: $(grep VIRTUAL_HOST .env | cut -d"'" -f2)"
echo "   - Initial Installation path: $(grep VIRTUAL_HOST .env | cut -d"'" -f2)/install"
echo "   - Permissions: crm, user_brief, pull, placement, userfieldconfig"
echo ""
echo "2. После создания приложения, получите CLIENT_ID и CLIENT_SECRET и обновите их в .env"
echo "3. Перезапустите контейнеры для применения изменений: make down && make dev-$BACKEND"
echo ""

if [ "$BACKEND" = "python" ]; then
    echo "5. Django админ-панель будет доступна по адресу:"
    echo "   ${BLUE}\$VIRTUAL_HOST/api/admin${NC}"
    echo "   Логин: $DJANGO_USERNAME"
    echo "   Пароль: [скрыт]"
    echo ""
fi

echo "Для остановки контейнеров используйте:"
echo "   ${YELLOW}make down${NC}"
echo ""
echo "Для просмотра логов используйте:"
echo "   ${YELLOW}docker-compose logs -f${NC}"
echo ""
print_success "Удачной разработки! 🚀"