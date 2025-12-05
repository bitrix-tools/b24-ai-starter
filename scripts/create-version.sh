#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
VERSIONS_DIR="$ROOT_DIR/versions"
ENV_FILE="$ROOT_DIR/.env"

if [ -t 1 ]; then
    INFO_PREFIX="ℹ️ "
    SUCCESS_PREFIX="✅ "
    WARN_PREFIX="⚠️ "
    ERROR_PREFIX="❌ "
else
    INFO_PREFIX="[i] "
    SUCCESS_PREFIX="[ok] "
    WARN_PREFIX="[warn] "
    ERROR_PREFIX="[err] "
fi

info() { echo "${INFO_PREFIX}$1"; }
success() { echo "${SUCCESS_PREFIX}$1"; }
warn() { echo "${WARN_PREFIX}$1"; }
error() { echo "${ERROR_PREFIX}$1" >&2; exit 1; }

command -v rsync >/dev/null 2>&1 || error "rsync не найден. Установите его и повторите попытку."

mkdir -p "$VERSIONS_DIR"

if git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if [ -n "$(git -C "$ROOT_DIR" status --porcelain)" ]; then
        warn "В рабочем дереве есть непроиндексированные изменения. Убедитесь, что вы готовы копировать их в новую версию."
    else
        info "Git рабочее дерево чистое."
    fi
fi

CURRENT_VERSION="текущая рабочая копия"
if [ -f "$ENV_FILE" ]; then
    if CURRENT_ENV_VERSION=$(grep -E '^APP_VERSION=' "$ENV_FILE" | tail -n1 | cut -d= -f2-); then
        if [ -n "$CURRENT_ENV_VERSION" ]; then
            CURRENT_VERSION="$CURRENT_ENV_VERSION"
        fi
    fi
else
    warn "Файл .env не найден — пропускаю чтение и обновление APP_VERSION."
fi

NEW_VERSION="${1:-}"
if [ -z "$NEW_VERSION" ]; then
    read -rp "Введите имя новой версии (например, v2): " NEW_VERSION
fi
NEW_VERSION="$(echo "$NEW_VERSION" | tr -d '[:space:]')"
[ -z "$NEW_VERSION" ] && error "Имя версии не может быть пустым."

TARGET_DIR="$VERSIONS_DIR/$NEW_VERSION"
[ -e "$TARGET_DIR" ] && error "Версия $NEW_VERSION уже существует: $TARGET_DIR"

info "Текущая версия: $CURRENT_VERSION"
info "Создаю новую версию: $NEW_VERSION"

RSYNC_OPTS=(
    "-a"
    "--exclude=.git/"
    "--exclude=versions/"
    "--exclude=node_modules/"
    "--exclude=.nuxt/"
    "--exclude=vendor/"
    "--exclude=.venv/"
    "--exclude=logs/"
)

rsync "${RSYNC_OPTS[@]}" "$ROOT_DIR/" "$TARGET_DIR/"
success "Папка versions/$NEW_VERSION создана."

if [ -f "$ENV_FILE" ]; then
    tmp_env="$(mktemp)"
    cleanup() { rm -f "$tmp_env"; }
    trap cleanup EXIT

    if grep -q '^APP_VERSION=' "$ENV_FILE"; then
        sed "s/^APP_VERSION=.*/APP_VERSION=$NEW_VERSION/" "$ENV_FILE" >"$tmp_env"
    else
        cat "$ENV_FILE" >"$tmp_env"
        printf '\nAPP_VERSION=%s\n' "$NEW_VERSION" >>"$tmp_env"
    fi

    mv "$tmp_env" "$ENV_FILE"
    trap - EXIT
    success ".env обновлён: APP_VERSION=$NEW_VERSION"
fi

cat <<EOF

Следующие шаги:
1. Перейдите в $TARGET_DIR
2. Запустите нужный стек разработки (например, "make dev-php")
3. Убедитесь, что версия работает, и только затем продолжайте разработку

По умолчанию каталог versions/ попадает в историю Git. Если хотите держать версии только локально, добавьте его в .gitignore или удаляйте перед коммитом.

EOF

success "Версия $NEW_VERSION готова к работе."

