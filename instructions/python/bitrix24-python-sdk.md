# Промпт для ИИ агента: Bitrix24 Starter Kit

Ты - эксперт-разработчик, помогающий создавать приложения для Bitrix24 на основе готового стартер-кита. Твой проект находится в репозитории: `https://github.com/bitrix-tools/ai-hackathon-starter-full`

## 📋 Описание приложения пользователя

**ВАЖНО: Здесь пользователь должен описать свое приложение:**

<!--
Пример описания:
Приложение для управления задачами сотрудников:
- Просмотр списка задач текущего пользователя с фильтрацией
- Создание и редактирование задач через слайдер Bitrix24
- Динамическая пагинация (по 50 задач)
- Отображение исполнителя, крайнего срока и статуса
-->

---

## 🏗️ Архитектура проекта

### Общая структура

```
starter-kit/
├── frontend/               # Nuxt 3 + Vue 3 frontend
├── backends/               # Три варианта бэкенда на выбор
│   ├── php/               # Symfony + PHP SDK
│   ├── python/            # Django + b24pysdk
│   └── node/              # Express + Node.js
├── infrastructure/
│   └── database/          # PostgreSQL/MySQL init scripts
├── instructions/          # Инструкции для ИИ агентов
└── logs/                   # Логи вне контейнеров
```

### Технологический стек

### MCP server

Для уточнения методов и параметров REST API используйте **Bitrix24 MCP server**.  
См. также: [инструкция по MCP](../bitrix24/mcp.md) и официальная страница https://apidocs.bitrix24.ru/sdk/mcp.html

**Frontend:**
- Nuxt 3 (Vue 3, TypeScript)
- Bitrix24 UI Kit (`@bitrix24/b24ui-nuxt`)
- Bitrix24 JS SDK (`@bitrix24/b24jssdk-nuxt`)
- Pinia (state management)
- i18n (многоязычность)
- TailwindCSS

**Backend (на выбор):**
- **PHP**: Symfony 7, Doctrine ORM, PHP SDK для Bitrix24
- **Python**: Django, b24pysdk
- **Node.js**: Express, PostgreSQL/MySQL, JWT

**Infrastructure:**
- Docker & Docker Compose
- PostgreSQL 17 / MySQL 8.4
- Cloudpub (ngrok-like) для туннелирования
- Nginx (production)

### Запуск проекта

Проект использует Docker Compose с профилями для выбора бэкенда:

```bash
# PHP backend
make dev-php

# Python backend
make dev-python

# Node.js backend
make dev-node
```

**На macOS**: В `docker-compose.yml` для сервиса `cloudpub` используется `platform: linux/amd64` для эмуляции на ARM64.

## 🚀 Пошаговая инструкция по развертыванию

### Шаг 1: Подготовка окружения

1. **Установите Docker и Docker Compose**
   - Убедитесь, что Docker Desktop установлен и запущен
   - Проверьте версию: `docker --version` и `docker compose version`

2. **Клонируйте репозиторий** (если еще не сделано)
   ```bash
   git clone https://github.com/bitrix-tools/ai-hackathon-starter-full.git
   cd ai-hackathon-starter-full
   ```

### Шаг 2: Настройка переменных окружения

1. **Создайте файл `.env` из примера**
   ```bash
   cp .env.example .env
   ```

2. **Откройте файл `.env` и заполните обязательные переменные:**

   ⚠️ **ОБЯЗАТЕЛЬНО**: Получите и укажите свой API ключ Cloudpub:
   - Зарегистрируйтесь на [Cloudpub](https://cloudpub.ru) или используйте другой туннелинг-сервис
   - Скопируйте ваш API токен
   - Добавьте в `.env`:
     ```env
     CLOUDPUB_TOKEN=ваш_токен_здесь
     ```

   **Для PHP бэкенда:**
   ```env
   SERVER_HOST=http://api-php:8000
   CLOUDPUB_TOKEN=ваш_токен_cloudpub
   CLIENT_ID=local.xxx  # Получите после регистрации приложения в Bitrix24
   CLIENT_SECRET=xxx    # Получите после регистрации приложения в Bitrix24
   SCOPE=crm,user_brief,pull,placement,userfieldconfig
   ```

   **Для Python бэкенда:**
   ```env
   SERVER_HOST=http://api-python:8000
   CLOUDPUB_TOKEN=ваш_токен_cloudpub
   DJANGO_SUPERUSER_USERNAME=admin
   DJANGO_SUPERUSER_EMAIL=admin@example.com
   DJANGO_SUPERUSER_PASSWORD=admin123
   CLIENT_ID=local.xxx  # Получите после регистрации приложения в Bitrix24
   CLIENT_SECRET=xxx    # Получите после регистрации приложения в Bitrix24
   SCOPE=crm,user_brief,pull,placement,userfieldconfig
   ```

   **Для Node.js бэкенда:**
   ```env
   SERVER_HOST=http://api-node:8000
   CLOUDPUB_TOKEN=ваш_токен_cloudpub
   CLIENT_ID=local.xxx  # Получите после регистрации приложения в Bitrix24
   CLIENT_SECRET=xxx    # Получите после регистрации приложения в Bitrix24
   SCOPE=crm,user_brief,pull,placement,userfieldconfig
   ```

   **Общие настройки:**
   ```env
   # База данных (значения по умолчанию можно оставить)
   DB_NAME=appdb
   DB_USER=appuser
   DB_PASSWORD=apppass
   
   # VIRTUAL_HOST заполните после запуска (см. Шаг 4)
   VIRTUAL_HOST=https://your-domain.cloudpub.com
   ```

### Шаг 3: Запуск Docker контейнеров

Выберите нужный бэкенд и запустите:

**Для PHP:**
```bash
make dev-php
```

**Для Python:**
```bash
make dev-python
```

**Для Node.js:**
```bash
make dev-node
```

**Что происходит:**
- Собираются Docker образы
- Запускаются сервисы: database, frontend, backend (api-php/api-python/api-node), cloudpub
- База данных автоматически инициализируется
- Cloudpub создает публичный HTTPS URL

**Примечание по CloudPub и архитектуре:**
Для сервиса `cloudpub` используйте переменные окружения, а не хардкод в `docker-compose.yml`:
```env
CLOUDPUB_IMAGE=cloudpub/cloudpub:latest
CLOUDPUB_PLATFORM=linux/amd64
```

Для ARM64 можно задать:
```env
CLOUDPUB_IMAGE=cloudpub/cloudpub:latest-arm64
CLOUDPUB_PLATFORM=linux/arm64
```

### Шаг 4: Получение публичного URL

1. **После запуска найдите публичный URL в логах:**
   ```bash
   docker logs cloudpubFront --tail 50
   ```
   
   Пример вывода:
   ```
   cloudpubApiPhp  | http://frontend:3000 -> https://inanely-muscular-wagtail.cloudpub.com:443
   ```

2. **Скопируйте полученный URL** (например: `https://inanely-muscular-wagtail.cloudpub.com`)

3. **Обновите `.env` файл:**
   ```env
   VIRTUAL_HOST=https://inanely-muscular-wagtail.cloudpub.com
   ```

4. **Перезапустите контейнеры** для применения изменений:
   ```bash
   make down
   make dev-php  # или dev-python, dev-node
   ```

### Шаг 5: Инициализация базы данных (только для PHP)

Для PHP бэкенда необходимо применить миграции:

```bash
make dev-php-init-database
```

Для Python и Node.js база данных инициализируется автоматически при первом запуске.

### Шаг 6: Регистрация приложения в Bitrix24

1. **Откройте ваш портал Bitrix24**
   - Перейдите в: **Лев меню → Developer Resources → Other → Local Applications**

2. **Создайте новое локальное приложение**

3. **Заполните параметры:**
   - ✅ **Server** — включите (да)
   - **Your handler path**: `https://ваш-домен.cloudpub.com` (из Шага 4)
   - **Initial Installation path**: `https://ваш-домен.cloudpub.com/install`
   - **Menu item text**: Название вашего приложения
   - **Assign permissions (scope)**: 
     ```
     crm,user_brief,pull,placement,userfieldconfig
     ```
     ⚠️ Если используете задачи, добавьте также: `tasks,user`

4. **Сохраните настройки**

5. **Скопируйте полученные данные:**
   - `Application ID (client_id)` — например: `local.6901c_xxxxxxx`
   - `Application key (client_secret)` — например: `vXpv64o_xxxxxxx`

6. **Обновите `.env` файл** (для PHP):
   ```env
   CLIENT_ID=local.6901c_xxxxxxx
   CLIENT_SECRET=vXpv64o_xxxxxxx
   ```

7. **Перезапустите контейнеры** (для PHP):
   ```bash
   make down
   make dev-php
   ```

### Шаг 7: Установка приложения в Bitrix24

1. **В Bitrix24 найдите ваше приложение** в списке локальных приложений

2. **Нажмите "Установить"** или перейдите по ссылке установки

3. **Дождитесь завершения установки**
   - Откроется страница `/install`
   - Выполнятся все шаги установки
   - Приложение будет готово к работе

### Шаг 8: Проверка работы

1. **Проверьте health endpoint:**
   ```bash
   curl http://localhost:8000/api/health
   ```
   
   Ожидаемый ответ:
   ```json
   {
     "status": "healthy",
     "backend": "php|python|node",
     "timestamp": 1760611967
   }
   ```

2. **Проверьте логи:**
   ```bash
   # Все сервисы
   make logs
   
   # Конкретный сервис
   docker logs api --tail 50
   docker logs frontend --tail 50
   ```

3. **Откройте приложение в Bitrix24**
   - Найдите приложение в меню
   - Убедитесь, что интерфейс загружается корректно

### Частые проблемы при развертывании

**Проблема: Cloudpub не запускается**
- ✅ Убедитесь, что `CLOUDPUB_TOKEN` указан в `.env`
- ✅ Проверьте, что токен действителен
- ✅ На macOS проверьте `platform: linux/amd64` в `docker-compose.yml`

**Проблема: Ошибка подключения к базе данных**
- ✅ Убедитесь, что контейнер `database` запущен: `docker ps`
- ✅ Проверьте переменные `DB_NAME`, `DB_USER`, `DB_PASSWORD` в `.env`

**Проблема: Frontend не подключается к backend**
- ✅ Проверьте `SERVER_HOST` в `.env` (должен соответствовать выбранному бэкенду)
- ✅ Убедитесь, что `VIRTUAL_HOST` указан корректно
- ✅ Проверьте, что все контейнеры в одной сети: `docker network ls`

**Проблема: Приложение не устанавливается в Bitrix24**
- ✅ Проверьте, что URL доступен по HTTPS
- ✅ Убедитесь, что `/install` endpoint отвечает
- ✅ Проверьте логи: `docker logs api --tail 100`
- ✅ Убедитесь, что scope указаны правильно

**Проблема: JWT токен не генерируется**
- ✅ Проверьте, что `CLIENT_ID` и `CLIENT_SECRET` указаны (для PHP)
- ✅ Убедитесь, что база данных инициализирована
- ✅ Проверьте логи API на ошибки

### Остановка и перезапуск

```bash
# Остановить все контейнеры
make down

# Остановить и удалить volumes (ОСТОРОЖНО: удалит данные БД)
docker compose down -v

# Перезапустить с нуля
make down
make dev-php  # или dev-python, dev-node
```

### Production развертывание

Для production используйте соответствующие команды:

```bash
# PHP
make prod-php

# Python
make prod-python

# Node.js
make prod-node
```

⚠️ **Важно для production:**
- Настройте реальный домен вместо Cloudpub
- Настройте SSL сертификаты
- Используйте надежные пароли
- Настройте резервное копирование базы данных
- Используйте переменные окружения из secure хранилища

## 🔐 Аутентификация и безопасность

### JWT токены

Все API endpoints (кроме `/api/install`, `/api/getToken` и `/api/app-events/`) требуют JWT токен в заголовке:

```javascript
Authorization: `Bearer ${tokenJWT}`
```

### Процесс аутентификации

1. **Установка приложения** (`/api/install`):
   - Получает данные из Bitrix24 (`DOMAIN`, `AUTH_ID`, `REFRESH_TOKEN`, `member_id`, `user_id`, и т.д.)
   - Сохраняет данные установки в БД
   - **НЕ требует JWT**

2. **Получение токена** (`/api/getToken`):
   - Принимает данные аутентификации Bitrix24
   - Генерирует JWT токен (TTL: 1 час)
   - Сохраняет связь с Bitrix24 аккаунтом
   - **НЕ требует JWT**

3. **События Bitrix24** (`/api/app-events/`):
   - Принимает lifecycle-события приложения от Bitrix24
   - Передает обработку в Celery worker
   - **НЕ требует JWT**

4. **Защищенные endpoints**:
   - Проверяют JWT токен через middleware/decorators
   - Извлекают `bitrix24_account` из токена
   - Предоставляют доступ к Bitrix24 API через SDK

## 📡 API Endpoints

### Базовые endpoints (реализованы во всех бэкендах)

#### `/api/health` (GET)
Проверка состояния бэкенда.

**Ответ:**
```json
{
  "status": "healthy",
  "backend": "php|python|node",
  "timestamp": 1760611967
}
```

#### `/api/enum` (GET)
Возвращает массив строк с вариантами.

**Ответ:**
```json
["option 1", "option 2", "option 3"]
```

#### `/api/list` (GET)
Возвращает массив строк с элементами.

**Ответ:**
```json
["element 1", "element 2", "element 3"]
```

#### `/api/install` (POST)
Вызывается при установке приложения.

**Входные параметры:**
```json
{
  "DOMAIN": "string",
  "PROTOCOL": 0|1,
  "LANG": "string",
  "APP_SID": "string",
  "AUTH_ID": "string",
  "AUTH_EXPIRES": 3600,
  "REFRESH_ID": "string",
  "member_id": "string",
  "user_id": 1,
  "PLACEMENT": "string",
  "PLACEMENT_OPTIONS": {}
}
```

**Ответ:**
```json
{
  "message": "Installation successful"
}
```

#### `/api/getToken` (POST)
Генерирует JWT токен для дальнейших запросов.

**Входные параметры:**
```json
{
  "DOMAIN": "string",
  "PROTOCOL": 0|1,
  "LANG": "string",
  "APP_SID": "string",
  "AUTH_ID": "string",
  "AUTH_EXPIRES": 3600,
  "REFRESH_ID": "string",
  "member_id": "string",
  "user_id": 1
}
```

**Ответ:**
```json
{
  "token": "AIHBdxxxLLL"
}
```

### Пример добавления нового endpoint

**PHP (Symfony):**
```php
#[Route('/api/my-endpoint', name: 'api_my_endpoint', methods: ['GET'])]
public function myEndpoint(Request $request): JsonResponse
{
    // JWT payload доступен через:
    $jwtPayload = $request->attributes->get('jwt_payload');
    
    // Bitrix24 аккаунт через:
    // $bitrix24Account = ...
    
    return new JsonResponse(['data' => 'value'], 200);
}
```

**Python (Django):**
```python
@xframe_options_exempt
@require_GET
@auth_required
def my_endpoint(request: AuthorizedRequest):
    # Bitrix24 клиент доступен через:
    client = request.bitrix24_account.get_client()
    
    # Вызов Bitrix24 API:
    profile = client.profile().value
    
    return JsonResponse({'user_id': profile.bitrix_id, 'name': profile.name})
```

**Node.js (Express):**
```javascript
app.get('/api/my-endpoint', verifyToken, async (req, res) => {
  // JWT payload доступен через:
  const jwtPayload = req.jwtPayload;
  
  // Bitrix24 API вызовы...
  
  res.json({ data: 'value' });
});
```

## 🎨 Frontend структура

### Основные директории

**`app/pages/`** - Страницы приложения:
- `index.client.vue` - Главная страница
- `install.client.vue` - Страница установки
- `*.client.vue` - Клиентские страницы (SSR отключен)

**`app/stores/`** - Pinia stores:
- `api.ts` - API методы и JWT управление
- `user.ts` - Данные пользователя
- `appSettings.ts` - Настройки приложения
- `userSettings.ts` - Пользовательские настройки

**`app/composables/`**:
- `useAppInit.ts` - Инициализация приложения, загрузка данных через batch
- `useBackend.ts` - Работа с бэкендом

**`app/middleware/`**:
- `01.app.page.or.slider.global.ts` - Глобальный middleware для инициализации B24Frame и обработки placement

**`app/layouts/`**:
- `default.vue` - Основной layout
- `placement.vue` - Layout для placement
- `slider.vue` - Layout для слайдеров
- `uf-placement.vue` - Layout для user fields

### Работа с Bitrix24 JS SDK

```typescript
// Получение B24Frame
const { $initializeB24Frame } = useNuxtApp()
const $b24: B24Frame = await $initializeB24Frame()

// Batch запросы
const response = await $b24.callBatch({
  appInfo: { method: 'app.info' },
  profile: { method: 'profile' }
})
const data = response.getData()

// Одиночные вызовы
const result = await $b24.callMethod('method.name', { param: 'value' })

// Работа с аутентификацией
const authData = $b24.auth.getAuthData()

// Открытие слайдеров
await $b24.slider.openPath('/path/to/page')
```

### Работа с API store

```typescript
const apiStore = useApiStore()

// Инициализация (после инициализации B24Frame)
await apiStore.init($b24)

// Запросы с автоматической передачей JWT
const data = await apiStore.getList()
const enumData = await apiStore.getEnum()

// Добавление нового метода в store
// В app/stores/api.ts:
const myMethod = async (): Promise<MyType> => {
  return await $api('/api/my-endpoint', {
    headers: {
      Authorization: `Bearer ${tokenJWT.value}`
    }
  })
}
```

### Использование Bitrix24 UI Kit

Компоненты доступны автоматически через `@bitrix24/b24ui-nuxt`:

```vue
<template>
  <B24Card>
    <template #header>
      <h1>Заголовок</h1>
    </template>
    
    <B24Button
      label="Кнопка"
      color="air-primary"
      @click="handleClick"
    />
    
    <B24Input
      v-model="inputValue"
      placeholder="Введите текст"
    />
    
    <B24Badge
      label="Статус"
      color="air-primary-success"
    />
    
    <B24Avatar
      :src="photoUrl"
      size="md"
    />
  </B24Card>
</template>
```

## 🔧 Конфигурация

### Переменные окружения (.env)

```env
# Cloudpub
CLOUDPUB_TOKEN=your_token_here

# Backend URL
SERVER_HOST=http://api-php:8000  # или api-python, api-node

# Public URL (для Bitrix24)
VIRTUAL_HOST=https://your-domain.cloudpub.com

# Database
DB_NAME=appdb
DB_USER=appuser
DB_PASSWORD=apppass

# PHP specific
CLIENT_ID=local.xxx
CLIENT_SECRET=xxx
SCOPE=crm,user_brief,pull,placement,userfieldconfig

# Python specific
DJANGO_SUPERUSER_USERNAME=admin
DJANGO_SUPERUSER_EMAIL=admin@example.com
DJANGO_SUPERUSER_PASSWORD=admin123
```

### Bitrix24 приложение

В настройках локального приложения Bitrix24:

- **Your handler path**: `https://your-domain.cloudpub.com`
- **Initial Installation path**: `https://your-domain.cloudpub.com/install`
- **Assign permissions**: `crm`, `user_brief`, `pull`, `placement`, `userfieldconfig` (минимум)

После сохранения получаете:
- `Application ID (client_id)`
- `Application key (client_secret)`

## 📚 SDK документация

### Bitrix24 JS SDK
- Используется через `@bitrix24/b24jssdk-nuxt`
- Документация: см. `AI-AGENT-GUIDE-JSSDK.md` в проекте

### Bitrix24 UI Kit
- Компоненты через `@bitrix24/b24ui-nuxt`
- Документация: см. `AI-AGENT-GUIDE-UIKIT.md` и `BITRIX24_UIKIT_*.md` в проекте

### PHP SDK
- Используется в `backends/php/`
- Документация: см. `AI-AGENT-GUIDE-PHPSDK.md` в проекте

### Python SDK (b24pysdk)
- Используется в `backends/python/`
- Документация: см. `AI_AGENT_GUIDE_PYSDK.md` в проекте

## 📖 Дополнительные инструкции и примеры

В репозитории проекта находятся подробные инструкции и примеры использования различных компонентов. При работе над задачей обязательно обращайся к ним для понимания правильных паттернов и лучших практик.

📁 **Расположение инструкций:**
- **В репозитории GitHub:** [https://github.com/bitrix-tools/ai-hackathon-starter-full/tree/main/instructions](https://github.com/bitrix-tools/ai-hackathon-starter-full/tree/main/instructions)
- **Локально после клонирования:** `./instructions/` в корне проекта

Все инструкции доступны как онлайн, так и локально после клонирования репозитория.

### Примеры использования SDK

**Python SDK:**
- **Онлайн:** [Примеры использования Python SDK (b24pysdk)](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/PYTHON_SDK_EXAMPLES.md)
- **Локально:** `instructions/PYTHON_SDK_EXAMPLES.md`
  - Работа с задачами (tasks)
  - Работа с пользователями (users)
  - Работа с CRM сущностями
  - Batch запросы
  - Обработка ошибок

**PHP SDK:**
- **Онлайн:** [Примеры использования PHP SDK](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/PHP_SDK_EXAMPLES.md)
- **Локально:** `instructions/PHP_SDK_EXAMPLES.md`
  - Работа с Bitrix24ServiceBuilder
  - Вызовы API методов
  - Работа с событиями
  - Batch запросы
  - Интеграция с Symfony

**Node.js SDK:**
- **Онлайн:** [Примеры использования Node.js SDK](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/NODE_SDK_EXAMPLES.md)
- **Локально:** `instructions/NODE_SDK_EXAMPLES.md`
  - Прямые вызовы REST API
  - Обработка токенов
  - Работа с базой данных
  - Асинхронные операции

### Примеры использования UI Kit

- **Онлайн:** [Примеры компонентов Bitrix24 UI Kit](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/UIKIT_EXAMPLES.md)
- **Локально:** `instructions/UIKIT_EXAMPLES.md`
  - Карточки и контейнеры (B24Card, B24Container)
  - Формы и поля ввода (B24Input, B24Select, B24Textarea)
  - Кнопки и действия (B24Button, B24ButtonGroup)
  - Навигация и меню (B24Tabs, B24Menu)
  - Таблицы и списки (B24Table, B24List)
  - Уведомления и модальные окна (B24Alert, B24Modal)
  - Календари и даты (B24Calendar, B24DatePicker)
  - Аватары и бейджи (B24Avatar, B24Badge)
  - Настройки приложения (B24SettingsPage)
  - Placement компоненты

### Стандарты кода и проверка качества

**Python:**
- **Онлайн:** [Инструкции по проверке кода Python](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/PYTHON_CODE_REVIEW_INSTRUCTION.md)
- **Локально:** `instructions/PYTHON_CODE_REVIEW_INSTRUCTION.md`
  - Использование black, flake8, pylint
  - Типизация с помощью mypy
  - Структура Django проекта
  - Стиль кода (PEP 8)
  - Команды для проверки: `make python-lint`, `make python-format`

**PHP:**
- **Онлайн:** [Инструкции по проверке кода PHP](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/PHP_CODE_REVIEW_INSTRUCTION.md)
- **Локально:** `instructions/PHP_CODE_REVIEW_INSTRUCTION.md`
  - Использование PHP CS Fixer, PHPStan
  - Стиль кода (PSR-12)
  - Структура Symfony проекта
  - Команды для проверки: `make php-lint`, `make php-analyze`

**Node.js/TypeScript:**
- **Онлайн:** [Инструкции по проверке кода Node.js/TypeScript](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/nodejs-code-review-instruction.md
- **Локально:** `instructions/nodejs-code-review-instruction.md`
  - Использование ESLint, Prettier
  - TypeScript strict mode
  - Структура Express проекта
  - Команды для проверки: `make node-lint`, `make node-format`

**Frontend (Vue/TypeScript):**
- Использование ESLint, Prettier
  - Vue 3 Composition API паттерны
  - TypeScript типизация
  - Команды для проверки: `make frontend-lint`, `make frontend-format`

### Встройки (Widgets) и события (Events)

⚠️ **ВАЖНО:** Если в описании приложения пользователя упоминаются **встройки** (widgets) или **события** (events), обязательно изучи следующую документацию:

**Документация по встройкам Bitrix24:**
- **Онлайн:** [API Reference: Widgets](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/widgets)
- Содержит полное описание API методов для работы с встройками
- Примеры использования различных типов виджетов

**Инструкция по разработке приложения с встройками:**
- **Онлайн:** [Инструкция по разработке приложения с встройками](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/ai-instructions-widget-app.md)
- **Локально:** `instructions/ai-instructions-widget-app.md`
- Подробное руководство по интеграции встроек в приложение
- Примеры кода и лучшие практики

**Документация по событиям Bitrix24 REST API:**
- **Онлайн:** [API Reference: Events](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/events)
- Полное описание API методов для работы с событиями
- Список доступных событий и их параметры

#### Инструкция по регистрации событий в стартере

Если в описании приложения пользователя упоминаются **события** или **регистрация событий**, следуй следующей инструкции:

**1. Регистрация событий во время установки:**

В Python backend события регистрируются в `/api/install` через `b24pysdk` client:

```python
handler_url = f"{config.app_base_url.rstrip('/')}/api/app-events/"
client = bitrix24_account.get_client()

for event in ("ONAPPINSTALL", "ONAPPUNINSTALL"):
    client.event.bind(event=event, handler=handler_url).call()
```

**2. Обработка событий на бэкенде:**

В стартере уже есть готовая инфраструктура для обработки событий:

**PHP бэкенд:**
- Контроллер: `backends/php/src/Bitrix24Core/Controller/AppLifecycleEventController.php`
- Endpoint: `/api/app-events` (POST)
- Метод уже обрабатывает события `OnApplicationInstall` и `OnApplicationUninstall`
- Route зарегистрирован как публичный (не требует JWT) в `JwtAuthenticationListener`

Пример обработки в PHP:
```php
#[Route('/api/app-events', name: 'b24_events', methods: ['POST'])]
public function process(Request $incomingRequest): Response
{
    // Проверка валидности события Bitrix24
    if (!RemoteEventsFactory::isCanProcess($incomingRequest)) {
        throw new InvalidArgumentException('Invalid event request');
    }
    
    // Обработка события
    // OnApplicationInstall / OnApplicationUninstall
    // ...
    
    return new Response('OK', 200);
}
```

**Python бэкенд:**
В стартере уже есть endpoint и processor событий:

```python
@xframe_options_exempt
@csrf_exempt
@require_POST
@event_required
def app_events(request: EventRequest):
    process_bitrix24_event.delay(request.oauth_event_data)
    return JsonResponse({"status": "queued"})
```

Актуальная реализация находится в `backends/python/django/bitrix_events/views.py` и `backends/python/django/bitrix_events/event_processor.py`.

**Node.js бэкенд:**
```javascript
app.post('/api/app-events/', async (req, res) => {
  // Обработка события от Bitrix24
  // Проверка валидности запроса
  // Обработка OnApplicationInstall / OnApplicationUninstall
  res.json({ status: 'OK' });
});
```

**3. Важные моменты:**

- **Публичный endpoint:** Endpoint для обработки событий (`/api/app-events/`) должен быть публичным (без JWT), так как Bitrix24 отправляет события напрямую
- **URL handler:** Handler URL должен быть доступен извне (через Cloudpub или публичный домен)
- **Проверка валидности:** Всегда проверяй валидность входящего события (используй `RemoteEventsFactory::isCanProcess` в PHP SDK)
- **Идемпотентность:** События могут прийти несколько раз, обработка должна быть идемпотентной
- **Список событий:** Изучи доступные события в [документации REST API Events](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/events)

⚠️ **Критически важно - структура данных SDK:**

При обработке событий Bitrix24 передает данные в формате `application/x-www-form-urlencoded` с вложенными ключами вида `auth[access_token]`, `data[FIELDS][ID]` и т.д. Для создания `OAuthPlacementData` (Python) или аналогичных структур в других языках:

1. **Обязательно проверь структуру данных SDK:** Изучи исходный код SDK стартера для выбранного языка (Python/PHP/Node.js) в файлах обработки событий - там уже есть рабочие примеры правильной структуры
2. **Python (b24pysdk):** `OAuthPlacementData.from_dict()` требует плоскую структуру с полями: `DOMAIN`, `PROTOCOL` (1 для HTTPS), `LANG`, `APP_SID`, `AUTH_ID`, `REFRESH_ID`, `AUTH_EXPIRES` (timestamp), `member_id`, `status`
3. **Типы данных:** Убедись, что используешь правильные типы (например, `expires` должен быть `BIGINT`, а не `INTEGER` для больших timestamp значений)
4. **Форматы полей API:** Проверь документацию Bitrix24 REST API - некоторые поля требуют массивы (например, `UF_CRM_TASK` для связи задачи со сделкой должно быть `['D_123']`, а не `'D_123'`)
5. **Логирование:** Добавь детальное логирование для отладки - логируй входящие данные события, структуру данных перед созданием OAuth объекта, ответы API

**4. Примеры событий для регистрации:**

- `ONAPPINSTALL` — установка приложения
- `ONAPPUNINSTALL` — удаление приложения
- `ONCRMDEALADD` — добавление сделки
- `ONCRMDEALUPDATE` — обновление сделки
- `ONTASKADD` — добавление задачи
- И многие другие (см. документацию)

**Проверка описания приложения:**
Перед началом разработки проверь описание приложения пользователя (раздел "📋 Описание приложения пользователя"). Если встречаются слова:
- "встройка", "виджет", "widget"
- "событие", "event", "регистрация событий"
- "placement", "placement-опции"
- "интеграция в интерфейс Bitrix24"
- "webhook", "callback"

То обязательно изучи указанные выше инструкции и документацию перед реализацией функционала.

### Важные замечания

⚠️ **Перед реализацией функционала:**
1. **Проверь описание приложения пользователя** — если упоминаются встройки (widgets) или события (events), обязательно изучи инструкции из раздела "Встройки (Widgets) и события (Events)" выше
2. Изучи соответствующие примеры SDK для выбранного языка
3. Проверь существующие паттерны в проекте
4. Убедись, что используешь правильные методы и компоненты
5. После написания кода запусти проверку стандартов качества

✅ **Всегда следуй:**
- Паттернам проекта (не изобретай велосипед)
- Стандартам кода для выбранного языка
- Использованию Bitrix24 UI Kit компонентов для фронтенда
- Правильной обработке ошибок и типизации

## 🚀 Рекомендации по разработке

### Добавление нового функционала

1. **Backend endpoint:**
   - Добавь endpoint в соответствующий контроллер/views
   - Используй декораторы/middleware для аутентификации
   - Верни JSON ответ

2. **Frontend API метод:**
   - Добавь метод в `app/stores/api.ts`
   - Используй `$api` с JWT заголовком
   - Обработай ошибки

3. **Frontend страница/компонент:**
   - Создай `.vue` файл в `app/pages/` или `app/components/`
   - Используй Bitrix24 UI Kit компоненты
   - Интегрируй с API store

### Лучшие практики

1. **Обработка ошибок:**
   - Используй `processErrorGlobal` из `useAppInit` для глобальных ошибок
   - Логируй через `$logger` из composable
   - Возвращай понятные сообщения об ошибках

2. **Типизация:**
   - Используй TypeScript для типизации
   - Определяй интерфейсы для API ответов
   - Используй `AuthorizedRequest` в Python, JWT payload в PHP/Node

3. **Состояние:**
   - Используй Pinia stores для глобального состояния
   - Reactivity через Vue 3 Composition API
   - Кэшируй данные где необходимо

4. **Производительность:**
   - Используй batch запросы где возможно
   - Ленивая загрузка компонентов
   - Оптимизация изображений и ассетов

## 🐛 Отладка

### Логи

Логи доступны вне контейнеров в `logs/`:
- `logs/php/` - PHP бэкенд
- `logs/python/` - Python бэкенд
- `logs/node/` - Node.js бэкенд
- `logs/postgres/` - База данных

### Проверка работы

```bash
# Логи всех сервисов
make logs

# Логи конкретного сервиса
docker logs api --tail 50
docker logs frontend --tail 50

# Проверка health
curl http://localhost:8000/api/health
```

### Частые проблемы

1. **Проблемы с JWT:**
   - Проверь, что токен передается в заголовке
   - Проверь срок действия токена (1 час)
   - Вызови `apiStore.reinitToken()` для обновления

2. **Проблемы с Bitrix24 API:**
   - Проверь права доступа (scopes) в настройках приложения
   - Проверь правильность метода API
   - Используй логи для отладки запросов

3. **Проблемы с Docker:**
   - Проверь, что все сервисы запущены: `docker ps`
   - Перезапусти контейнеры: `make down && make dev-*`
   - Проверь переменные окружения в `.env`

## 📝 Заметки для разработки

- Проект поддерживает hot-reload в development режиме
- Frontend использует SSR=false (только клиентский рендеринг)
- Все API запросы проксируются через Nuxt dev proxy
- База данных инициализируется автоматически при первом запуске
- Cloudpub предоставляет публичный HTTPS URL для разработки

---

**ВАЖНО:** При работе над задачей пользователя учитывай:
- Архитектуру выбранного бэкенда (PHP/Python/Node.js)
- Существующие паттерны и структуру проекта
- Использование Bitrix24 UI Kit для фронтенда
- Правильную обработку ошибок и типизацию
- Соответствие стилю кода проекта
