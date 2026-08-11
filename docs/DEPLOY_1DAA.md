# Деплой на https://1daa.ru/tochka/

## Статус проверки (2026-08-06)

| Проверка | Результат |
|----------|-----------|
| Сервер `31.129.97.40` | ✅ доступен (nginx, порты 22/80/443) |
| DNS `1daa.ru` | ❌ **NXDOMAIN** — домен пока не резолвится |
| SSH | ❌ нужен ключ или пароль (с этой машины нет доступа) |

### Что сделать с DNS (Timeweb / reg.ru)

```
A    1daa.ru      →  31.129.97.40
A    www.1daa.ru  →  31.129.97.40
```

Подождать 5–30 мин, проверить:

```bash
dig +short 1daa.ru A
# должно вернуть: 31.129.97.40
```

---

## Деплой на сервере

### 1. Подключиться по SSH

```bash
ssh root@31.129.97.40
```

### 2. Установить Docker (если нет)

```bash
apt update && apt install -y docker.io docker-compose-plugin git
systemctl enable docker && systemctl start docker
```

### 3. Склонировать проект

```bash
mkdir -p /opt && cd /opt
git clone <your-repo-url> b24-tochka-app
cd b24-tochka-app
```

### 4. Настроить `.env`

```bash
cp .env.production.example .env
nano .env
```

Обязательно заполнить:

- `CLIENT_ID`, `CLIENT_SECRET` — Bitrix24
- `JWT_SECRET` — случайная строка 32+ символов
- `DB_PASSWORD` — пароль PostgreSQL
- `TOCHKA_CLIENT_ID`, `TOCHKA_CLIENT_SECRET`
- `DJANGO_SUPERUSER_PASSWORD`

### 5. Запустить

```bash
chmod +x scripts/deploy-1daa-production.sh
./scripts/deploy-1daa-production.sh
```

Или:

```bash
make prod-python
docker exec api python manage.py migrate --noinput
```

Контейнеры слушают **только localhost**:

- Frontend: `127.0.0.1:3010`
- API: `127.0.0.1:8010`
- PostgreSQL: `127.0.0.1:5433`

### 6. Nginx

На сервере уже есть сайт на `/` (CRM Moskitok). Добавьте `/tochka/`:

```bash
sudo cp infrastructure/nginx/1daa.ru.conf.example /etc/nginx/sites-available/1daa.ru
# Отредактируйте root /var/www/1daa под ваш текущий лендинг
sudo ln -sf /etc/nginx/sites-available/1daa.ru /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Или только snippet (если vhost уже есть):

```bash
# scripts/deploy-1daa-production.sh создаст /etc/nginx/snippets/1daa-tochka.conf
# Добавьте в server { listen 443 ssl; server_name 1daa.ru; }:
include snippets/1daa-tochka.conf;
```

### 7. SSL (после того как DNS заработает)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d 1daa.ru -d www.1daa.ru
```

### 8. Cron — синхронизация выписки

```bash
crontab -e
```

```cron
0 */2 * * * docker exec api python manage.py sync_tochka_background >> /var/log/tochka-sync.log 2>&1
```

---

## URL для Bitrix24 и Точки

| | |
|---|---|
| Обработчик | `https://1daa.ru/tochka/` |
| Установка | `https://1daa.ru/tochka/install` |
| OAuth Точки | `https://1daa.ru/tochka/api/tochka/callback` |
| Webhook | `https://1daa.ru/tochka/api/tochka/acquiring/webhook` |

---

## Проверка

```bash
curl -sI https://1daa.ru/tochka/
curl -s https://1daa.ru/tochka/api/health
```

---

## `.env` production

```bash
APP_BASE_PATH=/tochka/
VIRTUAL_HOST=https://1daa.ru/tochka
TOCHKA_FRONTEND_PORT=3010
TOCHKA_API_PORT=8010
BUILD_TARGET=production
```
