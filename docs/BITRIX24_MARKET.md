# Публикация «Точка Банк. Интеграция» в Bitrix24.Market

Чеклист для развёртывания на **своём сервере** и подачи приложения в маркетплейс Bitrix24.

---

## 1. Инфраструктура на сервере

### Минимальные требования

| Компонент | Назначение |
|-----------|------------|
| **HTTPS-домен** | Один домен `1daa.ru`, приложения по пути (`/tochka/`, `/elba/`) |
| **PostgreSQL 15+** | На том же VPS (Docker), отдельная managed БД не нужна |
| **Python API** | Django (`backends/python/api`), порт 8000 |
| **Nuxt frontend** | Сборка `BUILD_TARGET=production`, `APP_BASE_PATH=/tochka/` |
| **Reverse proxy** | nginx: один SSL на домен, маршрутизация по path |

### Переменные окружения (`.env`)

Скопируйте `.env.example` → `.env` и заполните:

```bash
# Bitrix24 приложение (из кабинета разработчика)
CLIENT_ID=...
CLIENT_SECRET=...
SCOPE=crm,user_brief,pull,placement,userfieldconfig,im

# Публичный URL приложения (обязательно HTTPS)
# Схема как у 1os.su/tochka/ — один домен, приложения по пути (один SSL-сертификат)
APP_BASE_PATH=/tochka/
VIRTUAL_HOST=https://1daa.ru/tochka

# JWT между фронтом и API
JWT_SECRET=<случайная строка 32+ символов>

# PostgreSQL
DB_TYPE=postgresql
DB_NAME=appdb
DB_USER=appuser
DB_PASSWORD=<strong password>
DB_HOST=database
DB_PORT=5432

# Точка Банк (OAuth + API)
TOCHKA_CLIENT_ID=...
TOCHKA_CLIENT_SECRET=...
TOCHKA_SANDBOX=0

# Production
BUILD_TARGET=production
NODE_ENV=production

# Фоновая синхронизация (cron на сервере)
TOCHKA_SCHEDULED_SYNC_INTERVAL_MINUTES=120
TOCHKA_BANK_PUSH_CONFIGURED=0
```

### База данных

**Вариант A — по шагам (рекомендуется при обновлениях):**

```bash
chmod +x scripts/production-db-migrate.sh
./scripts/production-db-migrate.sh init
```

1. `init.sql` — таблицы Bitrix24 + Tochka (выписка, credentials)
2. `python manage.py migrate` — Django-таблицы и миграции 0001–0016

**Вариант B — полный дамп (чистый сервер):**

```bash
./scripts/production-db-migrate.sh full
```

Файл: `infrastructure/database/schema-production.sql`

**Только обновление миграций после деплоя новой версии:**

```bash
./scripts/production-db-migrate.sh migrate-only
```

### Запуск production (Docker)

```bash
BUILD_TARGET=production docker compose --profile python --profile frontend up -d --build
```

Или через Makefile:

```bash
make prod-python
```

### Cron — фоновая синхронизация выписки

```cron
# каждые 2 часа (согласуйте с TOCHKA_SCHEDULED_SYNC_INTERVAL_MINUTES)
0 */2 * * * docker exec api python manage.py sync_tochka_background
```

---

### URL на одном домене (как 1os.su)

```
https://1daa.ru/              ← лендинг компании (1DAA)
https://1daa.ru/tochka/       ← «Точка Банк. Интеграция»
https://1daa.ru/tochka/install
https://1daa.ru/tochka/api/...
https://1daa.ru/elba/         ← следующее приложение (будущее)
```

**Один SSL-сертификат** на `1daa.ru` покрывает все пути — поддомены не нужны.

Пример nginx: `infrastructure/nginx/1daa.ru.conf.example`

---

## 2. Настройка приложения в Bitrix24

### URL в карточке приложения

| Поле | Значение |
|------|----------|
| **Путь вашего обработчика** | `https://1daa.ru/tochka/` |
| **Путь для первоначальной установки** | `https://1daa.ru/tochka/install` |
| **Путь для OAuth (если отдельно)** | не требуется — используется `/tochka/api/install` |

### OAuth callback Точка Банк

В личном кабинете Точки укажите redirect URI:

```
https://1daa.ru/tochka/api/tochka/callback
```

### Webhook эквайринга

```
https://1daa.ru/tochka/api/tochka/acquiring/webhook
```

### Права доступа (scope)

```
crm, user_brief, pull, placement, userfieldconfig, im
```

### Placements (при установке)

Автоматически регистрируются на `/install`:

- `CRM_DEAL_DETAIL_TAB` — вкладка «Платежи» в сделке
- `CRM_LEAD_DETAIL_TAB` — вкладка «Платежи» в лиде

---

## 3. Проверка перед публикацией

- [ ] HTTPS без смешанного контента
- [ ] Установка: `/install` → мастер подключения Точки → dashboard
- [ ] OAuth Точки: popup закрывается, счета выбираются
- [ ] Синхронизация выписки и отображение платежей
- [ ] Привязка платежа к сделке / счёту CRM
- [ ] Эквайринг: создание ссылки из карточки сделки (если подключён)
- [ ] Удаление приложения не ломает портал (повторная установка работает)
- [ ] `GET https://1daa.ru/tochka/api/health` → 200

---

## 4. Маркетплейс Bitrix24 — материалы

### Обязательно подготовить

1. **Название:** Точка Банк. Интеграция  
2. **Краткое описание** (до 300 символов): синхронизация платежей Точки с CRM, привязка к сделкам, эквайринг  
3. **Полное описание:** функции из мастера установки + настройки в dashboard  
4. **Скриншоты:** установка, список платежей, карточка сделки, настройки (мин. 3–5 шт., 1280×720)  
5. **Иконка приложения:** 250×250 PNG  
6. **Политика конфиденциальности:** URL на вашем сайте  
7. **Поддержка:** email + сайт/Telegram  
8. **Категория:** CRM / Финансы / Интеграции  
9. **Тип:** Серверное приложение (не static local)

### Техническая модерация

- Сервер приложения доступен 24/7 из интернета  
- `CLIENT_ID` / `CLIENT_SECRET` production-приложения (не dev-туннель)  
- Нет hardcoded секретов в репозитории  
- Логи не содержат access_token / refresh_token  

### Тестовый сценарий для модератора

1. Установить приложение на тестовый портал  
2. Пройти мастер: Подключение → счета → автонастройка → Готово  
3. Открыть «Платежи», нажать «Синхронизировать»  
4. Открыть сделку → вкладка с платежами  

---

## 5. Структура миграций Django

| Файл | Содержание |
|------|------------|
| `0001` | `tochka_portal_preferences` |
| `0002–0003` | SQL-поля выписки (payment_slip, lead) |
| `0004` | эквайринг + ledger_exclude |
| `0005–0007` | UF-коды, уведомления |
| `0008–0010` | настройки счетов CRM |
| `0011` | `payment_notify_log` |
| `0012–0013` | chat bot для уведомлений |
| `0014` | статус applied для deal-bound |
| `0015–0016` | deal_matching JSON |

Просмотр статуса:

```bash
docker exec api python manage.py showmigrations main
```

---

## 6. Полезные команды

```bash
# Health
curl -s https://1daa.ru/tochka/api/health

# Логи
make logs

# Остановка
make down
```

---

## Контакты и ссылки

- [Документация REST Bitrix24](https://apidocs.bitrix24.ru/)
- [Кабинет разработчика Bitrix24](https://vendors.bitrix24.ru/)
- [API Точка Банк](https://developers.tochka.com/)
