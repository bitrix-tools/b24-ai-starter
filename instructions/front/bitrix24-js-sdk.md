# Инструкция для ИИ агентов: @bitrix24/b24jssdk

## Краткая сводка

**@bitrix24/b24jssdk** — официальный JavaScript SDK для работы с [REST API Bitrix24](https://github.com/bitrix-tools/b24-rest-docs). Предназначен для создания приложений, интеграций и автоматизации бизнес-процессов в экосистеме Bitrix24.

**Ключевые возможности:**
- 🔄 Вызов REST API методов Bitrix24 (фронтенд iframe / бэкенд через webhook)
- 🖼️ Управление UI: слайдеры, диалоги, изменение размера фрейма, заголовки
- 📦 Helpers: профили, валюты, лицензии, опции, платежи
- 📡 Pull Client для real-time коммуникации
- 🔐 Автоматическое управление OAuth токенами и refresh

**Версия:** `^2.0.0` | **Лицензия:** MIT | **Node.js:** `^18.0.0 || ^20.0.0 || >=22.0.0`

⚠️ **С версии 0.4.0 поддерживаются только ESM и UMD** (CommonJS удален)

---

## Установка

### Node.js / Frontend (ESM)
```bash
npm install @bitrix24/b24jssdk
```

### Browser (UMD через CDN)
```html
<script src="https://unpkg.com/@bitrix24/b24jssdk@latest/dist/umd/index.min.js"></script>
```

### Nuxt 3
```bash
npx nuxi module add @bitrix24/b24jssdk-nuxt
```

**Документация:**
- [Установка Node.js](https://github.com/bitrix24/b24jssdk/blob/main/docs/guide/getting-started.md)
- [Установка UMD](https://github.com/bitrix24/b24jssdk/blob/main/docs/guide/getting-started-umd.md)
- [Установка Nuxt](https://github.com/bitrix24/b24jssdk/blob/main/docs/guide/getting-started-nuxt.md)

**Примеры проектов:**
- [Пример Hook Node.js](https://github.com/bitrix24/b24sdk-examples/tree/main/js/05-node-hook)
- [Пример Nuxt Frame](https://github.com/bitrix24/b24sdk-examples/tree/main/js/02-nuxt-hook)
- [Пример OAuth приложения](https://github.com/bitrix24/b24sdk-examples/tree/main/js/08-nuxt-oauth)

---

## Основные требования для разработки

### MCP server

Для уточнения методов и параметров REST API используйте **Bitrix24 MCP server**.  
См. также: [инструкция по MCP](../bitrix24/mcp.md) и официальная страница https://apidocs.bitrix24.ru/sdk/mcp.html

### 1. Выбор режима работы

#### **B24Frame** — для приложений в iframe Bitrix24
Используется для приложений, встроенных в портал Bitrix24 через placement.

```typescript
import { initializeB24Frame, B24Frame } from '@bitrix24/b24jssdk'

let $b24: B24Frame
$b24 = await initializeB24Frame() // Всегда await перед использованием!

// Вызов REST API
const result = await $b24.actions.v2.call.make({ method: 'crm.deal.list', params: { select: ['ID', 'TITLE'] } })

// Очистка при размонтировании
$b24.destroy()
```

📚 **Ссылки:**
- [Инициализация B24Frame](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/frame.ts)
- [Документация initializeB24Frame](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/frame-initialize-b24-frame.md)
- [Loader B24Frame](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/loader-b24frame.ts)

#### **B24Hook** — для серверных скриптов через webhook
Используется для бэкенд-сервисов, скриптов, интеграций.

```typescript
import { B24Hook } from '@bitrix24/b24jssdk'

// Создание из URL webhook
const $b24 = B24Hook.fromWebhookUrl('https://your_domain.bitrix24.com/rest/1/k32t88gf3azpmwv3')

// Или через параметры
const $b24 = new B24Hook({
  b24Url: 'https://your_domain.bitrix24.com',
  userId: 123,
  secret: 'k32t88gf3azpmwv3'
})

$b24.offClientSideWarning() // Отключить warning о клиентской стороне (только для Node.js!)
```

⚠️ **ВАЖНО:** B24Hook НЕ использовать на фронтенде! Только на сервере/бэкенде.

📚 **Ссылки:**
- [B24Hook контроллер](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/hook/controller.ts)
- [Документация B24Hook](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/hook-index.md)
- [Пример Hook Node.js](https://github.com/bitrix24/b24jssdk/blob/main/docs/guide/example-hook-node-work.md)

#### **B24OAuth** — для OAuth приложений (экспериментально)
Используется для приложений с OAuth авторизацией (не стабильная реализация).

```typescript
import { B24OAuth } from '@bitrix24/b24jssdk'

const $b24 = new B24OAuth({
  clientId: 'your_client_id',
  clientSecret: 'your_client_secret'
})

// Обработка OAuth колбэка
await $b24.auth.handleOAuthCallback(callbackParams)
```

⚠️ **ВНИМАНИЕ:** B24OAuth находится в разработке. Для продакшена рекомендуется использовать B24Frame или B24Hook.

📚 **Ссылки:**
- [B24OAuth контроллер](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/oauth/controller.ts)
- [OAuth Auth Manager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/oauth/auth.ts)
- [Пример OAuth приложения](https://github.com/bitrix24/b24sdk-examples/tree/main/js/08-nuxt-oauth)

---

### 2. Вызов REST API методов

#### Базовые методы (AbstractB24)

> ⚠️ **Актуальный API — `$b24.actions.v{2,3}.*.make()`.** Старые хелперы
> `callMethod`, `callBatch`, `callListMethod`, `fetchListMethod` — **устаревшие**, не используйте их.
> Соответствие: `callMethod → actions.v2.call.make`, `callBatch → actions.v2.batch.make`,
> `callListMethod → actions.v2.callList.make`, `fetchListMethod → actions.v2.fetchList.make`.

```typescript
// Одиночный вызов
const result = await $b24.actions.v2.call.make({ method: 'crm.deal.get', params: { id: 123 } })

// Batch (объект с ключами)
const batch = await $b24.actions.v2.batch.make({
  calls: {
    deals: { method: 'crm.deal.list', params: { select: ['ID'] } },
    contacts: { method: 'crm.contact.list', params: { select: ['ID'] } }
  },
  options: { isHaltOnError: true }
})

// Batch массивом
const batchArr = await $b24.actions.v2.batch.make({
  calls: [
    ['crm.deal.list', { select: ['ID'] }],
    ['crm.contact.list', { select: ['ID'] }]
  ],
  options: { isHaltOnError: true }
})
```

📚 **Ссылки:**
- [AbstractB24 класс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/abstract-b24.ts)
- [Документация AbstractB24](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/core-abstract-b24.md)

#### Работа с большими списками

**Стратегии:**
- `actions.v2.callList.make` — загружает весь список в память (< 1000 записей)
- `actions.v2.fetchList.make` — stream по чанкам (рекомендуется для больших данных)
- `actions.v2.call.make` с ручной пагинацией — полный контроль

```typescript
// callList (всё в память)
const list = await $b24.actions.v2.callList.make({
  method: 'crm.deal.list',
  params: { select: ['ID', 'TITLE'] },
  idKey: 'ID',
  customKeyForResult: 'items'
})
const items = list.getData()

// fetchList (потоковая загрузка)
for await (const chunk of $b24.actions.v2.fetchList.make({
  method: 'crm.item.list',
  params: {
    entityTypeId: 4, // company
    select: ['id', 'title']
  },
  idKey: 'id',
  customKeyForResult: 'items'
})) {
  console.log(`Получено ${chunk.length} записей`)
}
```

📚 **Ссылки:**
- [Примеры работы со списками](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/README-AI.md#b-large-datasets-fetchlistmethod-streaming-by-chunks)

---

### 3. Обработка ошибок

```typescript
import { AjaxError, AjaxResult, Result } from '@bitrix24/b24jssdk'

try {
  const response: AjaxResult = await $b24.actions.v2.call.make({ method: 'crm.deal.get', params: { id: 999 } })
  
  if (response.isSuccess) {
    const data = response.getData()
  }
} catch (error) {
  if (error instanceof AjaxError) {
    console.error(`Ошибка API: ${error.code}`, error.message, error.status)
    console.error('Request:', error.requestInfo)
  }
}
```

📚 **Ссылки:**
- [AjaxError класс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/ajax-error.ts)
- [AjaxResult класс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/ajax-result.ts)
- [Result класс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/result.ts)
- [Документация AjaxResult](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/core-ajax-result.md)

---

### 4. Работа с UI (только B24Frame)

#### Слайдеры

```typescript
// Открыть слайдер с порталом
const url = $b24.slider.getUrl('/crm/deal/details/123')
const result = await $b24.slider.openPath(url, 1640) // ширина

// Открыть страницу приложения в слайдере
await $b24.slider.openSliderAppPage({ customParam: 'value' })
await $b24.slider.closeSliderAppPage()
```

📚 **Ссылки:**
- [Slider Manager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/slider.ts)
- [Документация Slider](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/frame-slider.md)

#### Диалоги

```typescript
// Выбор пользователя
const user = await $b24.dialog.selectUser()
const users = await $b24.dialog.selectUsers()
```

📚 **Ссылки:**
- [Dialog Manager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/dialog.ts)
- [Документация Dialog](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/frame-dialog.md)

#### Управление родительским окном

```typescript
await $b24.parent.fitWindow() // Подогнать размер под контент
await $b24.parent.resizeWindow(800, 600)
await $b24.parent.setTitle('Мое приложение')
await $b24.parent.closeApplication()
await $b24.parent.scrollParentWindow(0)
```

📚 **Ссылки:**
- [Parent Manager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/parent.ts)
- [Документация Parent](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/frame-parent.md)

#### Настройки приложения/пользователя

```typescript
// App-level
await $b24.options.appSet('myKey', 'value')
const value = $b24.options.appGet('myKey')

// User-level
await $b24.options.userSet('theme', 'dark')
const theme = $b24.options.userGet('theme')
```

📚 **Ссылки:**
- [Options Manager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/options.ts)
- [Документация Options](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/frame-options.md)

---

### 5. Helpers и Pull Client

```typescript
import { useB24Helper, LoadDataType } from '@bitrix24/b24jssdk'

const {
  initB24Helper,
  getB24Helper,
  usePullClient,
  useSubscribePullClient,
  startPullClient,
  destroyB24Helper
} = useB24Helper()

// Инициализация после B24Frame
const $b24 = await initializeB24Frame()
await initB24Helper($b24, [
  LoadDataType.Profile,
  LoadDataType.App,
  LoadDataType.Currency
])

// Доступ к данным
const helper = getB24Helper()
const userId = helper.profileInfo.data.id
const currencyName = helper.currency.getCurrencyFullName('USD', 'en')

// Pull Client
usePullClient()
useSubscribePullClient((message) => {
  console.log('Pull message:', message)
}, 'application')
startPullClient()
```

📚 **Ссылки:**
- [useB24Helper](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/use-b24-helper.ts)
- [B24HelperManager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/helper-manager.ts)
- [CurrencyManager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/currency-manager.ts)
- [ProfileManager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/profile-manager.ts)
- [Pull Client](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/pullClient/client.ts)
- [Документация Pull Client](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/pull-client.md)

---

### 6. Utilities

```typescript
import { Type, Text, LoggerBrowser, EnumCrmEntityTypeId } from '@bitrix24/b24jssdk'

// Type helpers
Type.isStringFilled('test') // true
Type.isNumber(123) // true

// Text utilities
const dt = Text.toDateTime('2024-01-01T10:00:00Z') // Luxon DateTime
const uuid = Text.getUuidRfc4122()
const num = Text.numberFormat(12345.67, 2, '.', ' ') // "12 345.67"

// Logger
const logger = LoggerBrowser.build('MyApp', true) // isDev = true
logger.info('message', data)
logger.error('error', error)
```

📚 **Ссылки:**
- [Type utilities](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/tools/type.ts)
- [Text utilities](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/tools/text.ts)
- [LoggerBrowser](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/logger/browser.ts)
- [Документация Tools](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/tools-type.md)

---

### 7. Типы и Enums

SDK предоставляет типы TypeScript для всех компонентов:

```typescript
import { 
  EnumCrmEntityTypeId,
  EnumCrmEntityType,
  type TypeB24,
  type AuthData,
  type AjaxResultParams
} from '@bitrix24/b24jssdk'

// Использование enum для CRM сущностей
const dealId = EnumCrmEntityTypeId.deal // 2
const companyId = EnumCrmEntityTypeId.company // 4

// TypeB24 интерфейс для типизации B24Frame/B24Hook
function processB24(b24: TypeB24) {
  // ...
}
```

📚 **Ссылки на типы:**
- [TypeB24 интерфейс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/b24.ts)
- [TypeHttp интерфейс](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/http.ts)
- [AuthData типы](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/auth.ts)
- [CRM Entity Types](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/crm/entity-type.ts)
- [Common типы](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/common.ts)
- [Payloads типы](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/payloads.ts)
- [User типы](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/user.ts)
- [Placement типы](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/placement/index.ts)
- [Все типы в папке types/](https://github.com/bitrix24/b24jssdk/tree/main/packages/jssdk/src/types)

---

### 8. Управление лимитами REST API

SDK автоматически управляет лимитами запросов через `RestrictionManager`:

```typescript
import { RestrictionManagerParamsForEnterprise } from '@bitrix24/b24jssdk'

// Получить HTTP клиент
const http = $b24.getHttpClient()

// Для Enterprise тарифа можно увеличить лимиты
// (автоматически делается через LicenseManager в useB24Helper)
http.setRestrictionManagerParams(RestrictionManagerParamsForEnterprise)

// Проверить текущие параметры
const params = http.getRestrictionManagerParams()
```

**Лимиты по умолчанию:**
- Batch размер: 50 команд
- Throttling: автоматическая задержка при превышении

📚 **Ссылки:**
- [RestrictionManager](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/restriction-manager.ts)
- [RestrictionManager параметры](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/http.ts)
- [Документация RestrictionManager](https://github.com/bitrix24/b24jssdk/blob/main/docs/reference/core-restriction-manager.md)
- [Лимиты Bitrix24 REST API](https://github.com/bitrix-tools/b24-rest-docs/blob/main/limits.md)

---

## Шаги при ошибках и сложностях

### 1️⃣ **Изучить исходный код класса/метода**

Все основные классы доступны на GitHub:

| Компонент | Ссылка на исходный код |
|-----------|----------------------|
| **B24Frame** | [frame/frame.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/frame.ts) |
| **B24Hook** | [hook/controller.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/hook/controller.ts) |
| **AbstractB24** | [core/abstract-b24.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/abstract-b24.ts) |
| **Http** | [core/http/controller.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/controller.ts) |
| **AjaxError** | [core/http/ajax-error.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/ajax-error.ts) |
| **AjaxResult** | [core/http/ajax-result.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/ajax-result.ts) |
| **Result** | [core/result.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/result.ts) |
| **RestrictionManager** | [core/http/restriction-manager.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/restriction-manager.ts) |
| **Auth (Frame)** | [frame/auth.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/auth.ts) |
| **Auth (Hook)** | [hook/auth.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/hook/auth.ts) |
| **Slider** | [frame/slider.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/slider.ts) |
| **Dialog** | [frame/dialog.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/dialog.ts) |
| **Parent** | [frame/parent.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/parent.ts) |
| **Placement** | [frame/placement.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/placement.ts) |
| **Options** | [frame/options.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/frame/options.ts) |
| **useB24Helper** | [helper/use-b24-helper.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/use-b24-helper.ts) |
| **B24HelperManager** | [helper/helper-manager.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/helper-manager.ts) |
| **CurrencyManager** | [helper/currency-manager.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/currency-manager.ts) |
| **ProfileManager** | [helper/profile-manager.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/helper/profile-manager.ts) |
| **Pull Client** | [pullClient/client.ts](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/pullClient/client.ts) |
| **Types** | [types/](https://github.com/bitrix24/b24jssdk/tree/main/packages/jssdk/src/types) |

### 2️⃣ **Проверить официальную документацию SDK**

- 📖 [Документация по всем классам](https://bitrix24.github.io/b24jssdk/)
- 📖 [Справочник Reference](https://github.com/bitrix24/b24jssdk/tree/main/docs/reference)
- 📄 [README-AI.md (подробный гайд для ИИ)](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/README-AI.md)

### 3️⃣ **Обратиться к документации Bitrix24 REST API**

Если проблема связана с вызовом конкретного REST метода:

- 🔗 [Официальная документация REST API Bitrix24](https://github.com/bitrix-tools/b24-rest-docs)
- 🔗 [Онлайн версия документации](https://apidocs.bitrix24.com/)
- 🔗 [CRM методы](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/crm)
- 🔗 [Методы работы со списками](https://github.com/bitrix-tools/b24-rest-docs/tree/main/api-reference/lists)

### 4️⃣ **Изучить примеры использования**

- 💡 [Официальные примеры проектов](https://github.com/bitrix24/b24sdk-examples)
- 💡 [Пример Hook Node.js](https://github.com/bitrix24/b24sdk-examples/tree/main/js/05-node-hook)
- 💡 [Пример Nuxt Frame](https://github.com/bitrix24/b24sdk-examples/tree/main/js/02-nuxt-hook)
- 💡 [Пример OAuth](https://github.com/bitrix24/b24sdk-examples/tree/main/js/08-nuxt-oauth)

### 5️⃣ **Проверить CHANGELOG**

- 📋 [CHANGELOG.md](https://github.com/bitrix24/b24jssdk/blob/main/CHANGELOG.md) — история изменений и breaking changes

### 6️⃣ **Создать Issue на GitHub**

Если проблема не решена:
- 🐛 [Создать Issue](https://github.com/bitrix24/b24jssdk/issues)

---

## Типичные ошибки и решения

| Ошибка | Причина | Решение |
|--------|---------|---------|
| `B24Frame is not initialized` | Не вызван `await initializeB24Frame()` | Всегда вызывать `await initializeB24Frame()` перед использованием |
| `invalid_token` / `expired_token` | Токен истек | SDK автоматически обновляет токены. Проверить `$b24.auth.refreshAuth()` |
| `B24Hook warning on client` | Используется B24Hook на фронтенде | Переместить на сервер или использовать `B24Frame` |
| `Batch limit exceeded` | Слишком большой batch | Использовать `actions.v2.batchByChunk.make()` или уменьшить размер |
| `isMore() returns false` | Нет следующей страницы | Проверить условие `while (result.isMore())` |

📚 **Ссылки:**
- [Типы ошибок](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/types/auth.ts)
- [Обработка ошибок HTTP](https://github.com/bitrix24/b24jssdk/blob/main/packages/jssdk/src/core/http/ajax-error.ts)

---

## Дополнительные ресурсы

### Официальная документация
- 📖 [Сайт документации](https://bitrix24.github.io/b24jssdk/)
- 📦 [NPM пакет](https://www.npmjs.com/package/@bitrix24/b24jssdk)
- 🔗 [GitHub репозиторий](https://github.com/bitrix24/b24jssdk)

### REST API Bitrix24
- 📚 [Документация REST API](https://github.com/bitrix-tools/b24-rest-docs)
- 🌐 [API Reference Online](https://apidocs.bitrix24.com/)

### Примеры проектов
- 🎯 [b24sdk-examples](https://github.com/bitrix24/b24sdk-examples)

---

## Best Practices для ИИ агентов

### Паттерны инициализации

#### Фронтенд (B24Frame)
```typescript
import { initializeB24Frame, B24Frame } from '@bitrix24/b24jssdk'

let $b24: B24Frame

async function init() {
  try {
    $b24 = await initializeB24Frame()
    
    // Инициализация helpers (опционально)
    const { initB24Helper } = useB24Helper()
    await initB24Helper($b24, [LoadDataType.Profile, LoadDataType.App])
    
    // Ваша логика
  } catch (error) {
    console.error('Init error:', error)
  }
}

function cleanup() {
  const { destroyB24Helper } = useB24Helper()
  destroyB24Helper()
  $b24?.destroy()
}
```

#### Бэкенд (B24Hook)
```typescript
import { B24Hook, LoggerBrowser } from '@bitrix24/b24jssdk'

const logger = LoggerBrowser.build('App', true)

const $b24 = B24Hook.fromWebhookUrl(process.env.B24_WEBHOOK_URL!)
$b24.setLogger(logger)
$b24.offClientSideWarning()

// Использование
async function getData() {
  try {
    const result = await $b24.actions.v2.call.make({ method: 'crm.deal.list', params: { select: ['ID'] } })
    return result.getData()
  } catch (error) {
    logger.error('API error:', error)
    throw error
  }
}
```

### Рекомендации

✅ **Всегда:**
- Использовать `await initializeB24Frame()` перед работой с B24Frame
- Вызывать `$b24.destroy()` при размонтировании компонента
- Использовать `try-catch` для обработки `AjaxError`
- Для больших списков (>1000) использовать `actions.v2.fetchList.make()` вместо `actions.v2.callList.make()`
- Проверять `response.isSuccess` перед обработкой данных
- Использовать TypeScript типы для безопасности
- Логировать ошибки через `LoggerBrowser`

❌ **Никогда:**
- Не использовать B24Hook на клиенте (только сервер!)
- Не забывать `await` перед асинхронными вызовами
- Не игнорировать ошибки (всегда обрабатывать через `catch`)
- Не использовать CommonJS (с версии 0.4.0 только ESM/UMD)
- Не забывать вызывать `destroy()` при очистке
- Не превышать лимиты batch (по умолчанию 50 команд)

🔍 **При ошибках:**
1. Проверить исходный код класса в GitHub
2. Изучить документацию метода
3. Проверить примеры использования
4. Обратиться к REST API документации Bitrix24
5. Проверить CHANGELOG на breaking changes

### Оптимизация производительности

1. **Batch запросы** — группировать связанные запросы
2. **actions.v2.fetchList.make** — для больших данных использовать потоковую загрузку
3. **RestrictionManager** — автоматически управляет throttling
4. **Кэширование** — сохранять результаты через `options.appSet/userSet`

---

**Версия документа:** 1.0  
**Дата:** 2025-10-23  
**SDK версия:** 2.0.0
