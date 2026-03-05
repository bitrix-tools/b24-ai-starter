# Стартер-кит для разработки приложений Bitrix24 с помощью AI-агентов

Этот проект предназначен для помощи разработчикам в создании приложений для Bitrix24 с использованием AI-агентов. Он включает как готовую кодовую базу, так и набор подробных инструкций для AI-агентов.

Ты - эксперт-разработчик, помогающий создавать приложения для Bitrix24 на основе готового стартер-кита. Твой проект находится в репозитории: `https://github.com/bitrix-tools/b24-ai-starter`

## 🎯 Что предоставляет стартер-кит:

- **Три варианта бэкенда** на выбор (PHP, Python, Node.js)
- **Готовый фронтенд** на Nuxt 3 с интеграцией Bitrix24 UI Kit
- **Воркеры** для фоновых задач
- **Docker-контейнеры** для быстрого развертывания
- **Готовые SDK** и общие утилиты для работы с Bitrix24 API
- **Makefile** для удобства разработки
- **Документированные API endpoints**
- **📚 Модульные инструкции** для AI-агентов в папке `instructions/`
- **♻️ Поддержка версионности** через `scripts/create-version.sh` и расширенную инициализацию `dev-init`
- **🔐 Готовая аутентификация и безопасность**
- **🎨 Интеграция с Bitrix24 UI Kit и JS SDK**
- **🐇 RabbitMQ сервис** с готовой интеграцией и инструкциями для всех стеков

Разработчики могут легко добавлять собственные бэкенды, просто создав папку в `backends/` с соответствующей структурой.

## 🤖 Инструкции для AI-агентов

**📚 Центральный узел знаний:** [`instructions/knowledge.md`](./instructions/knowledge.md) - главная точка входа для AI-агентов с языково-агностической информацией и навигацией по специализированным разделам.

**🏗️ Модульная архитектура инструкций:**

```
instructions/
├── knowledge.md              # 🎯 Центральный узел - начните отсюда!
├── php/knowledge.md          # 🐘 Общие знания по PHP
├── python/knowledge.md       # 🐍 Общие знания по Python  
├── node/knowledge.md         # 🟢 Общие знания по Node.js
├── queues/                   # 🐇 Очереди и фоновые задачи
├── frontend/knowledge.md     # 🎨 Общие знания по frontend
├── bitrix24/                 # 🏢 Платформенные инструкции
├── versioning/               # 🏢 Инструкции для создания версий
└── [язык]/[специфика].md     # 📋 Детальные инструкции
```

**💡 Как работать с инструкциями:**
1. **Начните** с `knowledge.md` для общего понимания
2. **Выберите** технологический стек  
3. **Изучите** `[язык]/knowledge.md` для языковых особенностей
4. **Обращайтесь** к специфическим инструкциям по мере необходимости

## ♻️ Версионность проекта

- 📄 **Промпт для агентов:** [`instructions/versioning/create-version-prompt.md`](./instructions/versioning/create-version-prompt.md) — описывает сценарий «создать V2», чеклист проверки и способы переключения
- 🛠 **Создание версии:** `./scripts/create-version.sh v2` копирует текущий фронтенд/бэкенд/инфраструктуру и прописывает `APP_VERSION=v2` в `.env`
- 📟 **Вызов через Make:** `make create-version VERSION=v2` и `make delete-version VERSION=v2` проксируют соответствующие скрипты (без `VERSION` включается интерактивный режим)
- 🗑 **Удаление версии:** `./scripts/delete-version.sh v2` удаляет папку `versions/v2` и при необходимости убирает `APP_VERSION` из `.env`
- 🚀 **Запуск нужной копии:** `make dev-init` автоматически предложит выбрать один из каталогов `versions/*`, либо можно явно указать `DEV_INIT_VERSION=v2 make dev-init` или `./scripts/dev-init.sh --version v2`
- 🧹 **Работа с git:** по умолчанию `versions/` участвует в истории; если версия нужна только локально — добавьте каталог в `.gitignore` перед коммитом

## 🏗️ Основные компоненты

**Обязательные права доступа**: `crm`, `user_brief`, `pull`, `placement`, `userfieldconfig`

**Для разработки используется:**
- Cloudpub для публичного HTTPS доступа
- Docker для контейнеризации

## 📁 Структура проекта

```text
b24-ai-starter/
├── frontend/                 # Nuxt 3 фронтенд с Bitrix24 UI Kit
├── backends/                 # Три варианта бэкенда на выбор
│   ├── php/                  # Symfony + PHP SDK
│   ├── python/               # Django + b24pysdk
│   └── node/                 # Express + Node.js
├── infrastructure/
│   └── database/             # PostgreSQL/MySQL init-скрипты
├── instructions/             # 📚 Модульные инструкции для AI-агентов
│   ├── knowledge.md          # Центральный узел знаний
│   ├── php/                  # PHP-специфичные инструкции
│   ├── python/               # Python-специфичные инструкции
│   ├── node/                 # Node.js-специфичные инструкции
│   ├── frontend/             # Frontend-специфичные инструкции
│   ├── versioning/           # Инструкции для версионности проекта
│   ├── queues/               # Инструкции для сервиса очередей RabbitMQ
│   └── bitrix24/             # Платформенные инструкции
├── .cursor/
│   └── skills/               # Skills для Cursor Agent
├── .claude/
│   └── skills/               # Skills для Claude Code
├── logs/                     # Логи вне контейнеров
├── versions/                 # Версии проекта
├── README.md                 # 🤖 Главный промпт для AI
└── docker-compose.yml        # Docker конфигурация
```

## 🤖 Skills для AI-агентов

Проект содержит готовые skills для двух сред:

- `.cursor/skills/` — инструкции и сценарии для Cursor Agent
- `.claude/skills/` — зеркальные инструкции для Claude Code

Skills покрывают навигацию по проекту, работу с окружением и разработку по каждому стеку (frontend/php/python/node), чтобы ускорить типовые задачи и унифицировать поведение ассистентов.

## 🚀 Быстрый старт

Текущий проект содержит полнофункциональную заготовку приложения, которую можно использовать как в качестве локального приложения, так и в качестве тиражного решения Маркетплейс.

Последовательность действий для запуска разработки:

1. Воспользуйтесь нижеописанной **автоматической инициализацией** для быстрого старта. Она создаст технический домен на Cloudpub, запустит Docker контейнеры и настроит окружение. Чтобы проверить, что всё работает, надо будет открыть в браузере технический домен. Поскольку приложение должно работать внутри Битрикс24, то при открытии в браузере вы увидите сообщение об ошибке, но с комментарием, что это нормальная ситуация и надо открыть тот же URL внутри Битрикс24. Это значит, что окружение настроено правильно, но приложение пока не получило токены авторизации от Битрикс24.

2. Зная технический домен, вы можете добавить его в настройки локального приложения в вашем портале Битрикс24 или в карточку приложения в кабинете разработчик https://vendors.bitrix24.ru, чтобы продолжить разработку и тестирование.

- **Основной URL приложения**: `[технический-домен]/`
- **URL установки приложения**: `[технический-домен]/install`
- **Разрешения (скоупы)**: `crm`, `user_brief`, `pull`, `placement`, `userfieldconfig`

После добавления приложения вы получите необходимые параметры CLIENT_ID и CLIENT_SECRET для вашего приложения. Вставьте их в соответствующие переменные окружения в файле `.env` и перезапустите Docker контейнеры командами `make down` и затем `make dev-php` (или `make dev-python` / `make dev-node` в зависимости от выбранного бэкенда).  Подробнее про [добавление локального приложения](https://apidocs.bitrix24.ru/local-integrations/serverside-local-app-with-ui.html) и про [добавление тиражного приложения](https://apidocs.bitrix24.ru/market/preparing-to-publish/how-to-add-app.html)

3. Переустановите приложение в вашем портале Битрикс24.

Теперь вы готовы начать разработку вашего приложения для Bitrix24 на базе текущего проекта!

### Автоматическая инициализация (рекомендуется)

```bash
# Запуск интерактивного мастера настройки
make dev-init
```

**Мастер автоматически:**
- Запросит API ключ CloudPub
- Позволит выбрать бэкенд (PHP/Python/Node.js)
- Удалит неиспользуемые папки бэкендов
- Настроит переменные окружения
- Получит публичный домен от CloudPub
- Запустит Docker контейнеры

### Ручная настройка (вместо автоматической настройки)

```bash
# Скопируйте и настройте переменные окружения
cp .env.example .env

# Разработка с PHP бэкендом
make dev-php

# Разработка с Python бэкендом
make dev-python

# Разработка с Node.js бэкендом
make dev-node

# Остановка всех сервисов
make down

# Продакшн с PHP
make prod-php

# Продакшн с Python
make prod-python

# Продакшн с Node.js
make prod-node

# Только база данных + фронтенд (для тестирования)
COMPOSE_PROFILES= docker-compose up database frontend

# Полный стек
COMPOSE_PROFILES=php,worker docker-compose up -d
```

### Запуск в production режиме

Для использования в production-среде настоятельно рекомендуется внести свои значения в переменные окружения:

JWT_SECRET - для шифрования JWT-токенов обмена данными между frontend и backend.
DB_USER - имя пользователя базы данных PostgreSQL
DB_PASSWORD - пароль пользователя базы данных PostgreSQL
DB_NAME - имя базы данных PostgreSQL
BUILD_TARGET установить в `production` - для сборки фронтенда в production режиме.
DJANGO_SUPERUSER_USERNAME - имя суперпользователя Django в случае backend на Python
DJANGO_SUPERUSER_EMAIL - email суперпользователя Django.
DJANGO_SUPERUSER_PASSWORD - пароль суперпользователя Django.

## 🛠️ Технологический стек

### Frontend

- **Nuxt 3** (Vue 3, TypeScript)
- **Bitrix24 UI Kit** (`@bitrix24/b24ui-nuxt`)
- **Bitrix24 JS SDK** (`@bitrix24/b24jssdk-nuxt`)
- **Pinia** (управление состоянием)
- **i18n** (многоязычность)
- **TailwindCSS**

### Backend (на выбор)

- **PHP**: Symfony 7, Doctrine ORM, PHP SDK для Bitrix24
- **Python**: Django, Python SDK для Bitrix24
- **Node.js**: Express, pg (PostgreSQL), JWT, JS SDK для Bitrix24

### Infrastructure

- **Docker & Docker Compose**
- **PostgreSQL 17**
- **Cloudpub** (ngrok-like) для туннелирования
- **Nginx** (production)

### Особенности различных backend

#### PHP

Если вы используете Windows и api-php не запускается, попробуйте пересохранить файл `backends/php/docker/php-fpm/docker-entrypoint.sh`

#### Python

**Django админ-панель будет доступна по адресу**: `https://<VIRTUAL_HOST>/api/admin`
    (логин: `<DJANGO_SUPERUSER_USERNAME>`, пароль: `<DJANGO_SUPERUSER_PASSWORD>`)

## 🛡️ Проверка безопасности

### Оркестратор security-тестов

- `make security-tests` запускает `scripts/security-tests.sh`, который в Docker-окружении выполняет аудит зависимостей, статические анализаторы и общие проверки (Semgrep OWASP Top 10, Gitleaks, Trivy).
- При запуске скрипт автоматически определяет активный backend (PHP/Python/Node) и фронтенд, поэтому разработчику не нужно вручную выбирать команды.
- Доступны профили:
  - `quick` (по умолчанию) — dependency audit + Semgrep.
  - `full` — быстрый профиль + phpstan/bandit/eslint, а также Gitleaks и Trivy.
  - `custom` — интерактивный выбор шагов.
- В интерактивном режиме шаги с найденными уязвимостями помечаются как «предупреждение», чтобы новички видели результат, но пайплайн продолжал работу; в `--ci` режиме такие шаги считаются ошибкой.
- Отчёты сохраняются в `reports/security/<timestamp>/...`. Строгий режим CI включается флагом `--ci`, дополнительный параметр `--allow-fail` позволяет завершить скрипт с кодом `0` даже при ошибках.
- Через `make` можно передать параметры: `make security-tests SECURITY_TESTS_ARGS="--profile full --allow-fail"`.

### Быстрый аудит зависимостей

- Выполните `make security-scan`, чтобы вручную запустить `scripts/security-scan.sh`.
- Скрипт проводит `composer audit --locked --format=json` внутри контейнера `php-cli`, если в проекте есть `backends/php`.
- Для фронтенда выполняется `pnpm audit --prod --json` в контейнере `frontend`.
- JSON-отчёты сохраняются в `reports/security/php-composer.json` и `reports/security/frontend-pnpm.json`.
- По умолчанию скрипт завершает работу с ненулевым кодом, если одна из проверок нашла уязвимости. Включите мягкий режим через `SECURITY_SCAN_ALLOW_FAILURES=1 make security-scan` (или `./scripts/security-scan.sh --allow-fail`), чтобы всегда получать код выхода `0`.
- Команда ничего не запускает автоматически; её можно добавлять в локальные чеклисты перед релизом или запускать в CI вручную.

## 🐇 Очереди и RabbitMQ

- `make dev-init` может автоматически развернуть контейнер RabbitMQ и сгенерировать учётные данные (сохраняются в `.env`).
- Брокер доступен в профиле `queue` (AMQP `5672`, панель `15672`).
- Для ручного старта/остановки используйте `make queue-up` и `make queue-down`.
- Подробности и чеклисты по каждому стеку:
  - Сервис и переменные окружения — `instructions/queues/server.md`
  - PHP + Messenger — `instructions/queues/php.md`
  - Python + Celery — `instructions/queues/python.md`
  - Node.js + amqplib — `instructions/queues/node.md`

## 📚 SDK документация

### Bitrix24 JS SDK

- Используется через `@bitrix24/b24jssdk-nuxt`
- Документация: см. [`instructions/frontend/bitrix24-js-sdk.md`](./instructions/frontend/bitrix24-js-sdk.md) в проекте

### Bitrix24 UI Kit

- Компоненты через `@bitrix24/b24ui-nuxt`
- Документация: см. [`instructions/frontend/bitrix24-ui-kit.md`](./instructions/frontend/bitrix24-ui-kit.md) в проекте

### PHP SDK

- Используется в `backends/php/`
- Документация: см. [`instructions/php/bitrix24-php-sdk.md`](./instructions/php/bitrix24-php-sdk.md) в проекте

### Python SDK (b24pysdk)

- Используется в `backends/python/`
- Документация: см. [`instructions/python/bitrix24-python-sdk.md`](./instructions/python/bitrix24-python-sdk.md) в проекте

## 🔐 Аутентификация и безопасность

### JWT токены

Все API endpoints (кроме `/api/install` и `/api/getToken`) требуют JWT токен в заголовке:

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

3. **Защищенные endpoints**:
   - Проверяют JWT токен через middleware/decorators
   - Извлекают `bitrix24_account` из токена
   - Предоставляют доступ к Bitrix24 API через SDK

## 🔌 API Endpoints

### Общие принципы

Все запросы (кроме `/api/install`, `/api/getToken`) передают JWT в заголовках.

Пример:

```javascript
const {data, error} = await $fetch('/api/protected-route', {
  method: 'GET',
  headers: {
    Authorization: `Bearer ${someJWT}`
  }
});
```

Сервер проверяет каждый запрос (кроме `/api/install`, `/api/getToken`) на наличие действительного JWT токена.

Сервер возвращает ответ в формате `JSON`.

При возникновении ошибки сервер устанавливает код ответа `401`, `404` или `500` и возвращает описание ошибки в следующем формате:

```json
{
  "error": "Internal server error"
}
```

### `/api/health`

Указывает статус бэкенда.

- **Метод**: `GET`
- **Параметры**: нет
- **Ответ**:
  - `status`: `string` - статус сервера
  - `backend`: `string` - тип бэкенда (php/python/node)
  - `timestamp`: `number` - временная метка

Пример ответа:

```json
{
  "status": "healthy",
  "backend": "php",
  "timestamp": 1760611967
}
```

Тестирование:

```bash
curl http://localhost:8000/api/health
```

### `/api/enum`

Возвращает перечисление опций.

- **Метод**: `GET`
- **Параметры**: нет
- **Ответ**: `string[]` - массив строк с опциями

Пример ответа:

```json
[
  "option 1",
  "option 2", 
  "option 3"
]
```

Тестирование:

```bash
curl http://localhost:8000/api/enum
```

### `/api/list`

Возвращает список элементов.

- **Метод**: `GET`
- **Параметры**: нет
- **Ответ**: `string[]` - массив строк с элементами

Пример ответа:

```json
[
  "element 1",
  "element 2",
  "element 3"
]
```

Тестирование:

```bash
curl http://localhost:8000/api/list
```

### `/api/install`

Вызывается из фронтенд клиента при установке приложения.

**JWT токен не передается.**

- **Метод**: `POST`
- **Параметры**:
  - `DOMAIN`: `string` - домен портала Bitrix24
  - `PROTOCOL`: `number` - протокол (0 - HTTP, 1 - HTTPS)
  - `LANG`: `string` - язык интерфейса
  - `APP_SID`: `string` - идентификатор сессии приложения
  - `AUTH_ID`: `string` - токен авторизации
  - `AUTH_EXPIRES`: `number` - время истечения токена
  - `REFRESH_ID`: `string` - токен обновления
  - `member_id`: `string` - ID участника
  - `user_id`: `number` - ID пользователя
  - `PLACEMENT`: `string` - размещение приложения
  - `PLACEMENT_OPTIONS`: `object` - опции размещения
- **Ответ**:
  - `message`: `string` - сообщение о результате

Пример ответа:

```json
{
  "message": "Installation successful"
}
```

Тестирование:

```bash
curl -X POST http://localhost:8000/api/install \
  -H "Content-Type: application/json" \
  -d '{"AUTH_ID":"27exx66815","AUTH_EXPIRES":3600,"REFRESH_ID":"176xxxe","member_id":"a3xxx22","user_id":"1","PLACEMENT":"DEFAULT","PLACEMENT_OPTIONS":"{\"any\":\"6\/\"}"}'
```

### `/api/getToken`

Вызывается фронтендом для получения JWT токена от бэкенда.

На вход передаются данные авторизации от Bitrix24.

Время жизни токена: **1 час**.

**JWT токен не передается.**

- **Метод**: `POST`
- **Параметры**:
  - `DOMAIN`: `string` - домен портала Bitrix24
  - `PROTOCOL`: `number` - протокол (0 - HTTP, 1 - HTTPS)
  - `LANG`: `string` - язык интерфейса
  - `APP_SID`: `string` - идентификатор сессии приложения
  - `AUTH_ID`: `string` - токен авторизации
  - `AUTH_EXPIRES`: `number` - время истечения токена
  - `REFRESH_ID`: `string` - токен обновления
  - `member_id`: `string` - ID участника
  - `user_id`: `number` - ID пользователя
- **Ответ**:
  - `token`: `string` - JWT токен для дальнейших запросов

Пример ответа:

```json
{
  "token": "AIHBdxxxLLL"
}
```

Тестирование:

```bash
curl -X POST http://localhost:8000/api/getToken \
  -H "Content-Type: application/json" \
  -d '{"AUTH_ID":"27exx66815","AUTH_EXPIRES":3600,"REFRESH_ID":"176xxxe","member_id":"a3xxx22","user_id":1}'
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
@log_errors("my_endpoint")
@auth_required
def my_endpoint(request: AuthorizedRequest):
    # Bitrix24 клиент доступен через:
    client = request.bitrix24_account.client
    
    # Вызов Bitrix24 API:
    response = client._bitrix_token.call_method(
        api_method='method.name',
        params={'param': 'value'}
    )
    
    return JsonResponse({'data': 'value'})
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

## 🎨 Frontend структура и работа с Bitrix24

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

## ⚠️ Встройки (Widgets), события (Events) и роботы (Robots)

### Важно для AI-агентов

Если в описании приложения упоминаются **встройки** (widgets), **события** (events) или **роботы** (robots), обязательно изучи следующую документацию:

**Встройки (Widgets):**

- **Онлайн:** [API Reference: Widgets](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/widgets)
- **Инструкция:** [Разработка приложения с встройками](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/ai-instructions-widget-app.md)

**События (Events):**

- **Онлайн:** [API Reference: Events](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/events)
- Регистрация через `event.bind` в процессе установки
- Обработка через публичный endpoint `/api/app-events` (без JWT)
- Поддержка `application/x-www-form-urlencoded` и JSON

**Роботы (Robots):**

- **Инструкция:** [Создание роботов для бизнес-процессов](https://github.com/bitrix-tools/ai-hackathon-starter-full/blob/main/instructions/ai-instructions-robot.md)
- Регистрация через `bizproc.robot.add`
- Обработка через публичный endpoint `/api/robot-handler` (без JWT)
- Поддержка различных форматов данных от Bitrix24

### Ключевые моменты реализации

1. **Публичные endpoints** - События и роботы не используют JWT
2. **Обработка форматов данных** - Поддержка form-urlencoded и JSON
3. **Правильная структура OAuth** - Создание `OAuthPlacementData` для SDK
4. **Регистрация в установке** - Добавление в `install.client.vue`

## 📚 Дополнительные ресурсы

### AI-агенты и инструкции

- **📚 [Центральный узел знаний](./instructions/knowledge.md)** - главная точка входа для AI-агентов

#### Модульная архитектура знаний

**Backend-специфичные инструкции:**
- **🐘 [PHP Knowledge](./instructions/php/knowledge.md)** - общие знания по PHP-разработке
  - [PHP SDK](./instructions/php/bitrix24-php-sdk.md) - работа с PHP SDK
  - [Code Review](./instructions/php/code-review.md) - стандарты качества
- **🐍 [Python Knowledge](./instructions/python/knowledge.md)** - общие знания по Python-разработке  
  - [Python SDK](./instructions/python/bitrix24-python-sdk.md) - работа с Python SDK
  - [Code Review](./instructions/python/code-review.md) - стандарты качества
- **🟢 [Node.js Knowledge](./instructions/node/knowledge.md)** - общие знания по Node.js-разработке
  - [Code Review](./instructions/node/code-review.md) - стандарты качества

**Frontend-специфичные инструкции:**
- **🎨 [Frontend Knowledge](./instructions/front/knowledge.md)** - общие знания по frontend-разработке
  - [UI Kit](./instructions/front/bitrix24-ui-kit.md) - работа с UI Kit
  - [JS SDK](./instructions/front/bitrix24-js-sdk.md) - работа с JS SDK
  - [Компоненты](./instructions/front/) - детальные инструкции по компонентам

**Платформенные инструкции:**
- **🏢 [Bitrix24 Platform](./instructions/bitrix24/)** - специфика платформы
  - [CRM роботы](./instructions/bitrix24/crm-robot.md)
  - [Виджеты](./instructions/bitrix24/widget.md)

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

## 🤝 Участие в разработке

Этот стартер-кит создан для облегчения разработки приложений Bitrix24 с помощью AI-агентов. Вы можете:

1. **Использовать готовые инструкции** для обучения AI-агентов
2. **Дорабатывать существующие SDK** примеры
3. **Добавлять новые бэкенды** в папку `backends/`
4. **Улучшать документацию** и инструкции

---

**ВАЖНО:** При работе над задачей пользователя учитывай:

- Архитектуру выбранного бэкенда (PHP/Python/Node.js)
- Существующие паттерны и структуру проекта
- Использование Bitrix24 UI Kit для фронтенда
- Правильную обработку ошибок и типизацию
- Соответствие стилю кода проекта

## 📄 Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](./LICENSE) для подробностей.
