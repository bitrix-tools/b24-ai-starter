# Bitrix24 UI Kit — Руководство для ИИ агентов

## Краткая сводка

**Bitrix24 UI Kit** (`@bitrix24/b24ui-nuxt`) — это UI-библиотека для разработки веб-приложений через REST API Bitrix24 на базе Nuxt и Vue. Библиотека предоставляет готовые компоненты, повторяющие дизайн Bitrix24, что позволяет разработчикам быстро создавать красивые и консистентные приложения.

Проект УЖЕ содержит необходимую базовую структуру и настройки для frontend на базе Nuxt с интеграцией Bitrix24 UI Kit. При добавлении функционала нужно придерживаться этой структуры и использовать компоненты из `@bitrix24/b24ui-nuxt`.

**Актуальная структура проекта:**
```
frontend/
├── app/
│   ├── app.config.ts          # Конфигурация B24UI (colorMode и т.д.)
│   ├── app.vue                # Корневой компонент (B24App + B24DashboardGroup)
│   ├── error.vue              # Страница ошибки
│   ├── assets/css/main.css    # Единственный CSS-вход (Tailwind + b24ui)
│   ├── components/            # Компоненты приложения
│   │   ├── BackendStatus.vue
│   │   └── Logo.vue
│   ├── composables/           # Переиспользуемая логика
│   │   ├── useAppInit.ts      # Инициализация приложения (B24Frame + helper)
│   │   ├── useBackend.ts      # Проверка состояния бэкенда
│   │   └── useTelemetry.ts    # Телеметрия
│   ├── layouts/              # Шаблоны страниц
│   │   ├── default.vue        # B24SidebarLayout (базовый)
│   │   ├── placement.vue      # Для виджетов (placement)
│   │   ├── slider.vue         # Для слайдеров
│   │   └── uf-placement.vue   # Для пользовательских типов полей
│   ├── middleware/           # Route middleware
│   ├── pages/                 # Страницы Nuxt (только *.client.vue)
│   │   ├── index.client.vue
│   │   ├── install.client.vue
│   │   ├── handler/
│   │   └── slider/
│   ├── plugins/              # Nuxt-плагины (telemetry.client.ts)
│   ├── stores/                # Pinia stores (api, appSettings, userSettings, user, page)
│   └── utils/                # Утилиты (авто-импорт)
├── server/                   # Nitro server routes (install.post.ts)
├── shared/                   # Общие типы (shared/types)
├── nuxt.config.ts            # Конфигурация Nuxt (ssr: false)
├── package.json              # Зависимости
└── i18n/                     # Интернационализация
    ├── locales/
    └── i18n.map.ts
```

**Ключевые особенности:**
- Основан на Nuxt UI, но адаптирован под дизайн-систему Bitrix24
- Использует Tailwind CSS 4 и Tailwind Variants для стилизации
- Поддерживает Nuxt 4+ и Vue 3 + Vite
- Включает 80+ компонентов и composables
- Интегрируется с `@bitrix24/b24icons-vue` (1400+ иконок)

**Важно:** Всегда используйте компоненты именно из `@bitrix24/b24ui-nuxt`, а НЕ из Nuxt UI!

## Особенности настройки frontend проекта с B24UI

**nuxt.config.ts:**
```ts
export default defineNuxtConfig({
  modules: ['@bitrix24/b24ui-nuxt']
})
```

**assets/css/main.css:**
```css
@import "tailwindcss";
@import "@bitrix24/b24ui-nuxt";
```

**app.vue:**
```vue
<template>
  <B24App :locale="locales[locale]">
    <NuxtLoadingIndicator color="var(--ui-color-design-filled-warning-bg)" :height="3" />
    <B24DashboardGroup>
      <NuxtLayout>
        <NuxtPage />
      </NuxtLayout>
    </B24DashboardGroup>
  </B24App>
</template>
```

## Основные требования

### 1. Обязательное использование B24App

Оборачивайте приложение в `<B24App>` для корректной работы Toast, Tooltip, Modal и программных оверлеев:

```vue
<template>
  <B24App>
    <!-- ваш контент -->
  </B24App>
</template>
```

📖 [Компонент B24App](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/App.vue)

### 2. Префикс компонентов

Все компоненты используют префикс `B24`:
- `<B24Button>`, `<B24Input>`, `<B24Modal>`, `<B24Form>` и т.д.

### 3. Работа с иконками

Иконки импортируются из `@bitrix24/b24icons-vue`:

```vue
<script setup>
import RocketIcon from '@bitrix24/b24icons-vue/main/RocketIcon'
</script>

<template>
  <B24Button :icon="RocketIcon" label="Запуск" />
</template>
```

📖 [Полный список иконок](https://bitrix24.github.io/b24icons/)  
📖 [Метаданные иконок](https://github.com/bitrix24/b24icons-vue/blob/main/dist/metadata.json)  
📖 [Интеграция иконок](https://github.com/bitrix24/b24ui/blob/main/docs/content/docs/1.getting-started/6.integrations/1.icons/1.nuxt.md)

### 4. Страницы и шаблоны страниц

Приложение в базовом виде содержит примеры реализации некоторых страниц в папке `pages/`:

- index.client.vue — главная страница приложения
- install.client.vue — страница установки приложения
- slider/app-options.client.vue — страница настроек приложения в слайдере
- handler/placement-crm-deal-detail-tab.client.vue — пример страницы для встройки в карточку сделки
- handler/uf.demo.client.vue — пример страницы для встройки пользовательского типа поля
- handler/background-some-problem-client.vue - пример страницы об ошибке

Важно: все страницы выполняются ТОЛЬКО на клиенте, поскольку frontend будет собираться в виде статики для показа внутри фрейма Bitrix24. Поэтому все файлы страниц имеют суффикс `.client.vue`.

При создании новых страниц подбирай подходящий шаблон из `layouts/` для консистентного внешнего вида. Обычно используется `default.vue`, который уже содержит `B24SidebarLayout`. 

- placement.vue — для страниц реализующих виджеты;
- slider.vue - для страниц реализующих слайдеры, открывающиеся вне текущего iframe интерфейса приложения;
- uf-placement.vue - для страниц реализующих встройку пользовательских типов полей. [Подробности про этот тип встройки виджетов](https://github.com/bitrix-tools/b24-rest-docs/blob/main//api-reference/widgets/user-field/index.md?plain=1).

### 5. Стилизация через b24ui и class

Компоненты поддерживают два способа кастомизации:

**Через `b24ui` prop** (для слотов внутри компонента):
```vue
<B24Button 
  :b24ui="{ 
    baseLine: 'justify-center', 
    leadingIcon: 'text-red-500' 
  }" 
/>
```

**Через `class` prop** (для корневого элемента):
```vue
<B24Button class="font-bold rounded-full" />
```

Старайтесь избегать глобальных CSS переопределений, чтобы не нарушить дизайн-систему, а также избегайте лишних стилей оформления без необходимости - компоненты по умолчанию уже стилизованы согласно гайдлайнам Bitrix24.

📖 [Кастомизация компонентов](https://github.com/bitrix24/b24ui/blob/main/docs/content/docs/1.getting-started/5.theme/3.components.md)

### 6. Варианты темы (theme variants)

Компоненты используют систему вариантов. Основные параметры:

**color:** `air-primary`, `air-secondary-no-accent`, `air-primary-success`, `air-primary-alert`, `air-primary-warning`, `air-primary-copilot`, `air-secondary-accent`, `air-tertiary` и др.

**size:** `xss`, `xs`, `sm`, `md`, `lg`, `xl` (зависит от компонента)

**Пример:**
```vue
<B24Button 
  color="air-primary" 
  size="md" 
  rounded
  loading-auto
/>
```

📖 [Все theme файлы](https://github.com/bitrix24/b24ui/tree/main/src/theme)

## Основные компоненты и заготовки для типовых сценариев

Для реализации пользовательских сценариев в интерфейсе необходимо ориентироваться на заготовки в файлах accordion.md, calendar.md, form.md, selector.md, settings-page.md, table-and-grid.md. Если нужный сценарий этими заготовками реализовать нельзя, следует использовать компоненты из этого раздела.

### Кнопки и действия

**B24Button** — основная кнопка  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Button.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/button.ts)  
```vue
<B24Button 
  label="Сохранить"
  color="air-primary"
  size="md"
  :icon="SaveIcon"
  loading-auto
  @click="handleSave"
/>
```

**B24FieldGroup** — группа кнопок  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/FieldGroup.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/field-group.ts)

### Формы и ввод

**B24Input** — текстовый инпут  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Input.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/input.ts)  
```vue
<B24Input 
  v-model.trim="email"
  type="email"
  placeholder="Email"
  color="air-primary"
  size="md"
/>
```

**B24Textarea** — многострочный ввод  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Textarea.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/textarea.ts)

**B24Select** — выпадающий список  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Select.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/select.ts)  
```vue
<B24Select
  v-model="selected"
  :items="[
    { label: 'Опция 1', value: '1' },
    { label: 'Опция 2', value: '2' }
  ]"
  placeholder="Выберите..."
/>
```

**B24Checkbox** — чекбокс  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Checkbox.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/checkbox.ts)

**B24RadioGroup** — группа радио-кнопок  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/RadioGroup.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/radio-group.ts)

**B24Switch** — переключатель  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Switch.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/switch.ts)

**B24Form + B24FormField** — форма с валидацией (Zod, Valibot, Yup и др.)  
📖 [Исходный код Form](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Form.vue)  
📖 [Исходный код FormField](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/FormField.vue)  
📖 [Theme Form](https://github.com/bitrix24/b24ui/blob/main/src/theme/form.ts)  
📖 [Theme FormField](https://github.com/bitrix24/b24ui/blob/main/src/theme/form-field.ts)  
📖 [Документация](https://github.com/bitrix24/b24ui/blob/main/docs/content/docs/2.components/form.md)

```vue
<B24Form :state="state" :schema="schema" @submit="onSubmit">
  <B24FormField name="email" label="Email" required>
    <B24Input v-model="state.email" type="email" />
  </B24FormField>
  
  <B24Button type="submit" color="air-primary" loading-auto>
    Отправить
  </B24Button>
</B24Form>
```

### Оверлеи и уведомления

**B24Modal** — модальное окно  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Modal.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/modal.ts)  
📖 [Документация](https://github.com/bitrix24/b24ui/blob/main/docs/content/docs/2.components/modal.md)

```vue
<B24Modal title="Заголовок" description="Описание">
  <B24Button label="Открыть" />
  
  <template #body>
    <p>Содержимое модалки</p>
  </template>
  
  <template #footer="{ close }">
    <B24Button color="air-primary" @click="save">Сохранить</B24Button>
    <B24Button color="air-tertiary" @click="close">Отмена</B24Button>
  </template>
</B24Modal>
```

**B24Slideover** — боковая панель  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Slideover.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/slideover.ts)

**B24Toast** — уведомления (обычно через `useToast()`)  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Toast.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/toast.ts)

**B24Tooltip** — всплывающие подсказки  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Tooltip.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/tooltip.ts)

**B24Popover** — всплывающий контейнер  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Popover.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/popover.ts)

### Навигация и меню

**B24NavigationMenu** — навигационное меню  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/NavigationMenu.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/navigation-menu.ts)

**B24DropdownMenu** — выпадающее меню  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/DropdownMenu.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/dropdown-menu.ts)

**B24Tabs** — вкладки  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Tabs.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/tabs.ts)

**B24Breadcrumb** — хлебные крошки  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Breadcrumb.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/breadcrumb.ts)

### Макеты (Layouts)

**B24SidebarLayout** — основной layout с боковой панелью  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/SidebarLayout.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/sidebar-layout.ts)

Этот компонент УЖЕ используется в качестве основы базовой страницы в шаблоне layouts/default.vue проекта. Если страница приложения создается на базе этого шаблона, НЕЛЬЗЯ использовать B24SidebarLayout внутри страницы повторно.

**Правила размещения контента в B24SidebarLayout**
- Компоненты страницы рендерятся внутри `<slot>` компонента `B24SidebarLayout` через активный `layout`.
- Размещайте контент непосредственно в `slot` — без дополнительных обёрток для центрирования (`flex items-center justify-center`, `min-h-screen`, `min-h-dvh`, `max-w-*` контейнеры).
- Layout уже обеспечивает корректные отступы, фон и поведение прокрутки.
- Используйте `B24Card` (или другие компоненты B24) напрямую как корневой элемент шаблона страницы.
- Для состояний загрузки используйте `B24Skeleton` вместо скрытия всей страницы.
- ❌ Неправильно: `<div class="flex items-center justify-center min-h-screen"><B24Card class="max-w-md">…</B24Card></div>`
- ✅ Правильно: `<B24Card>…</B24Card>` (или `<div class="flex flex-col gap-4 p-6"><B24Card>…</B24Card></div> `для страниц с несколькими карточками)

Связанные компоненты:
- **B24Sidebar** — [исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Sidebar.vue)
- **B24SidebarHeader** — [исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/SidebarHeader.vue)
- **B24SidebarBody** — [исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/SidebarBody.vue)
- **B24SidebarFooter** — [исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/SidebarFooter.vue)
- **B24Navbar** — [исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Navbar.vue)

### Отображение данных

**B24Avatar / B24AvatarGroup** — аватары  
📖 [Avatar: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Avatar.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/avatar.ts)  
📖 [AvatarGroup: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/AvatarGroup.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/avatar-group.ts)

**B24Badge** — бейджи  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Badge.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/badge.ts)

**B24Chip** — числовые метки  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Chip.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/chip.ts)

**B24Card** — карточка  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Card.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/card.ts)

**B24Alert / B24Advice** — предупреждения и советы  
📖 [Alert: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Alert.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/alert.ts)  
📖 [Advice: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Advice.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/advice.ts)

**B24Table / B24TableWrapper** — таблицы  
📖 [Table: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Table.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/table.ts)  
📖 [TableWrapper: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/TableWrapper.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/table-wrapper.ts)

**B24DescriptionList** — список описаний  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/DescriptionList.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/description-list.ts)

**B24Skeleton** — skeleton loader  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Skeleton.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/skeleton.ts)

**B24Empty** — пустое состояние  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Empty.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/empty.ts)

### Другие компоненты

**B24Progress** — прогресс-бар  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Progress.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/progress.ts)

**B24Range** — слайдер  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Range.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/range.ts)

**B24Calendar** — календарь  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Calendar.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/calendar.ts)

**B24Countdown** — таймер обратного отсчета  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Countdown.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/countdown.ts)

**B24Accordion / B24Collapsible** — раскрывающиеся блоки  
📖 [Accordion: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Accordion.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/accordion.ts)  
📖 [Collapsible: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Collapsible.vue) / [theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/collapsible.ts)

**B24Separator** — разделитель  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Separator.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/separator.ts)

**B24Kbd** — клавиша клавиатуры  
📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/components/Kbd.vue)  
📖 [Theme](https://github.com/bitrix24/b24ui/blob/main/src/theme/kbd.ts)

## Основные Composables

### useToast()

Управление уведомлениями.

📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/useToast.ts)

```ts
const toast = useToast()

// Добавить уведомление
toast.add({ 
  title: 'Успех', 
  description: 'Данные сохранены',
  color: 'air-primary-success' 
})

// Обновить
toast.update(id, { title: 'Обновлено' })

// Удалить
toast.remove(id)

// Очистить все
toast.clear()
```

### useOverlay()

Программное управление модальными окнами и slideоvers.

📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/useOverlay.ts)

```ts
const overlay = useOverlay()

// Создать экземпляр
const modal = overlay.create(MyModalComponent, { 
  props: { title: 'Заголовок' },
  destroyOnClose: true
})

// Открыть и получить результат
const opened = modal.open({ additionalProp: 'value' })
const result = await opened.result // ждет emit('close', payload)

// Закрыть
modal.close(resultValue)

// Обновить props на лету
modal.patch({ title: 'Новый заголовок' })
```

### defineShortcuts()

Регистрация глобальных горячих клавиш.

📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/defineShortcuts.ts)

```ts
defineShortcuts({
  'meta_k': () => openCommandPalette(),
  'escape': () => closeAllOverlays(),
  'ctrl_s': (e) => { e.preventDefault(); save() }
})
```

### useLocale() / defineLocale()

Интернационализация.

📖 [useLocale: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/useLocale.ts)  
📖 [defineLocale: исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/defineLocale.ts)

### useConfetti()

Конфетти-анимация.

📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/useConfetti.ts)

```ts
const confetti = useConfetti()
confetti.fire()
```

### useFormField()

Интеграция с формой для кастомных компонентов.

📖 [Исходный код](https://github.com/bitrix24/b24ui/blob/main/src/runtime/composables/useFormField.ts)

## Что делать при ошибках

### 1. Проверьте установку и конфигурацию

Убедитесь, что:
- `@bitrix24/b24ui-nuxt` установлен
- Модуль добавлен в `nuxt.config.ts`
- CSS импортирован
- Приложение обернуто в `<B24App>`

### 2. Проверьте правильность использования компонента

1. **Найдите исходный код компонента** в GitHub:
   - Перейдите в [src/runtime/components/](https://github.com/bitrix24/b24ui/tree/main/src/runtime/components)
   - Откройте нужный компонент (например, `Button.vue`)
   - Изучите `Props`, `Slots`, `Emits`

2. **Проверьте theme** компонента:
   - Перейдите в [src/theme/](https://github.com/bitrix24/b24ui/tree/main/src/theme)
   - Откройте файл темы (например, `button.ts`)
   - Проверьте доступные `variants`, `slots`, `defaultVariants`

3. **Изучите документацию** (если есть):
   - [Основная документация](https://bitrix24.github.io/b24ui/)
   - [Файлы документации](https://github.com/bitrix24/b24ui/tree/main/docs/content/docs/2.components)

### 3. Проверьте совместимость версий

```json
{
  "dependencies": {
    "@bitrix24/b24ui-nuxt": "^2.0.0",
    "@bitrix24/b24icons-vue": "^2.0.0",
    "nuxt": "^4.0.0",
    "vue": "^3.0.0"
  }
}
```

### 4. Типичные проблемы и решения

**Проблема:** Компоненты не рендерятся  
**Решение:** Проверьте наличие `<B24App>` в корне приложения

**Проблема:** Toast/Tooltip/Modal не работают  
**Решение:** Убедитесь, что используется `<B24App>` (предоставляет `OverlayProvider`)

**Проблема:** Иконки не отображаются  
**Решение:** Проверьте правильность импорта из `@bitrix24/b24icons-vue/category/IconName`

**Проблема:** Стили не применяются  
**Решение:** Убедитесь, что CSS импортирован (`@import "@bitrix24/b24ui-nuxt"`)

**Проблема:** TypeScript ошибки  
**Решение:** Проверьте типы в исходниках компонента или theme файле

### 5. Ссылки для диагностики

- **Исходный код модуля:** [src/module.ts](https://github.com/bitrix24/b24ui/blob/main/src/module.ts)
- **Все компоненты:** [src/runtime/components/](https://github.com/bitrix24/b24ui/tree/main/src/runtime/components)
- **Все composables:** [src/runtime/composables/](https://github.com/bitrix24/b24ui/tree/main/src/runtime/composables)
- **Все темы:** [src/theme/](https://github.com/bitrix24/b24ui/tree/main/src/theme)
- **Примеры использования:** [playgrounds/demo/app/](https://github.com/bitrix24/b24ui/tree/main/playgrounds/demo/app)
- **Тесты компонентов:** [test/components/](https://github.com/bitrix24/b24ui/tree/main/test/components)

## Полезные ресурсы

- 📚 [Официальная документация](https://bitrix24.github.io/b24ui/)
- 🎨 [Иконки Bitrix24](https://bitrix24.github.io/b24icons/)
- 💾 [GitHub репозиторий](https://github.com/bitrix24/b24ui)
- 🎮 [Demo приложение](https://bitrix24.github.io/b24ui/demo/)
- 🚀 [Starter template](https://github.com/bitrix24/starter-b24ui)
- 📖 [README для ИИ (расширенный)](https://github.com/bitrix24/b24ui/blob/main/README-AI.md)

## Принципы работы с UI Kit

1. **Всегда используйте B24 префикс** — это компоненты Bitrix24 UI, а не Nuxt UI
2. **Оборачивайте в B24App** — обязательно для работы оверлеев и уведомлений
3. **Используйте b24ui prop** — для точной кастомизации слотов компонента
4. **Следуйте дизайн-системе** — используйте предустановленные цвета (`air-*`) и размеры
5. **Проверяйте theme** — для понимания доступных вариантов и слотов
6. **Изучайте исходники** — они хорошо документированы и содержат TypeScript типы

---

## 💾 Управление состоянием

### 1. Pinia Store (рекомендуется для Vue/Nuxt)

В проекте используется Pinia для управления состоянием. Создавайте stores в папке `composables/` или `stores/` используя Composition API:

```typescript
// composables/useDeals.ts
export const useDealsStore = defineStore('deals', () => {
  // Состояние
  const deals = ref<Deal[]>([]);
  const currentDeal = ref<Deal | null>(null);
  const isLoading = ref(false);
  const error = ref<string | null>(null);
  
  // Фильтры
  const filters = ref<DealFilters>({
    stage: null,
    search: '',
    dateFrom: null,
    dateTo: null
  });

  // Геттеры
  const filteredDeals = computed(() => {
    let result = deals.value;
    
    if (filters.value.stage) {
      result = result.filter(deal => deal.stageId === filters.value.stage);
    }
    
    if (filters.value.search) {
      const search = filters.value.search.toLowerCase();
      result = result.filter(deal => 
        deal.title.toLowerCase().includes(search)
      );
    }
    
    return result;
  });

  // Действия
  async function fetchDeals() {
    isLoading.value = true;
    error.value = null;
    
    try {
      const { data } = await $fetch<{data: Deal[]}>('/api/deals');
      deals.value = data;
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'Failed to fetch deals';
    } finally {
      isLoading.value = false;
    }
  }

  return {
    // State
    deals: readonly(deals),
    currentDeal: readonly(currentDeal),
    isLoading: readonly(isLoading),
    error: readonly(error),
    filters,
    
    // Getters
    filteredDeals,
    
    // Actions
    fetchDeals
  };
});
```

### 2. Composables для переиспользования логики

```typescript
// composables/useApi.ts
export function useApi() {
  const isLoading = ref(false);
  const error = ref<string | null>(null);

  async function apiCall<T>(
    url: string, 
    options?: RequestInit
  ): Promise<T | null> {
    isLoading.value = true;
    error.value = null;
    
    try {
      const response = await $fetch<T>(url, options);
      return response;
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'API call failed';
      return null;
    } finally {
      isLoading.value = false;
    }
  }

  return {
    isLoading: readonly(isLoading),
    error: readonly(error),
    apiCall
  };
}
```

---

## 🎨 Стилизация с Tailwind CSS 4

Проект использует **Tailwind CSS 4** через Vite плагин, что отличается от классической конфигурации:

### Конфигурация

**nuxt.config.ts:**
```typescript
import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  vite: {
    plugins: [tailwindcss()]
  }
})
```

**assets/css/main.css:**
```css
@import "tailwindcss";
@import "@bitrix24/b24ui-nuxt";

@theme static {
  /* Кастомные утилиты здесь */
}
```

### Расширение тем Tailwind

```css
@theme {
  --color-primary-50: theme(colors.blue.50);
  --color-primary-500: theme(colors.blue.500);
  --color-primary-900: theme(colors.blue.900);
  
  --font-family-brand: ui-serif, serif;
  
  --spacing-18: 4.5rem;
}
```

---

## 📱 Адаптивный дизайн

### Breakpoints и сетки

```vue
<!-- Адаптивная сетка -->
<template>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <!-- Карточки -->
    <B24Card
      v-for="item in items"
      :key="item.id"
      class="p-4"
    >
      {{ item.title }}
    </B24Card>
  </div>
</template>
```

### Мобильная навигация с B24UI

> ⚠️ Иконки в B24UI — это импортируемые компоненты (`:icon="Icon"`), а НЕ строковый проп `name`.
> Цвета задаются через систему `air-*`, а не через `variant="ghost"` / `color="red"`.

```vue
<!-- components/MobileNavigation.vue -->
<script setup lang="ts">
import BurgerMenuIcon from '@bitrix24/b24icons-vue/main/BurgerMenuIcon'

const isOpen = ref(false)
</script>

<template>
  <div class="lg:hidden">
    <!-- Мобильное меню -->
    <B24Button
      :icon="BurgerMenuIcon"
      color="air-tertiary"
      @click="isOpen = !isOpen"
    />

    <!-- Выдвижная панель -->
    <B24Slideover v-model:open="isOpen" side="left">
      <template #body>
        <nav class="flex flex-col gap-2">
          <NuxtLink
            v-for="item in navigation"
            :key="item.to"
            :to="item.to"
            class="block px-4 py-2"
            @click="isOpen = false"
          >
            {{ item.label }}
          </NuxtLink>
        </nav>
      </template>
    </B24Slideover>
  </div>
</template>
```

---

## ⚡ Производительность

### 1. Ленивая загрузка компонентов

```vue
<script setup>
// Ленивая загрузка тяжелых компонентов
const LazyChart = defineAsyncComponent(() => import('~/components/Chart.vue'));
const LazyDataTable = defineAsyncComponent(() => import('~/components/DataTable.vue'));

const showChart = ref(false);
</script>

<template>
  <div>
    <!-- Основной контент загружается сразу -->
    <B24Card class="mb-4">
      <B24Button @click="showChart = true" v-if="!showChart">
        Показать график
      </B24Button>
    </B24Card>
    
    <!-- Тяжелые компоненты загружаются по требованию -->
    <LazyChart v-if="showChart" :data="chartData" />
  </div>
</template>
```

### 2. Паттерны списков с B24UI

```vue
<!-- components/DealList.vue -->
<template>
  <B24Container class="py-8">
    <!-- Заголовок и действия -->
    <div class="mb-6 flex items-center justify-between">
      <h1 class="text-2xl font-bold">Сделки</h1>
      <B24Button @click="openCreateModal">
        Создать сделку
      </B24Button>
    </div>

    <!-- Фильтры -->
    <B24Card class="mb-6 p-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <B24Select
          v-model="filters.stage"
          :items="stageItems"
          placeholder="Выберите стадию"
        />
        <B24Input
          v-model="filters.search"
          :leading-icon="SearchIcon"
          placeholder="Поиск по названию"
        />
        <B24Button
          color="air-tertiary"
          @click="clearFilters"
        >
          Очистить фильтры
        </B24Button>
      </div>
    </B24Card>

    <!-- Таблица -->
    <B24Card>
      <B24Table
        :data="filteredDeals"
        :columns="columns"
        :loading="isLoading"
      >
        <template #actions-cell="{ row }">
          <div class="flex gap-2">
            <B24Button size="sm" color="air-secondary" @click="editDeal(row.original.id)">
              Редактировать
            </B24Button>
            <B24Button
              size="sm"
              color="air-primary-alert"
              @click="deleteDeal(row.original.id)"
            >
              Удалить
            </B24Button>
          </div>
        </template>
      </B24Table>
    </B24Card>
  </B24Container>
</template>
```

> ℹ️ `B24Select` использует проп `:items` (не `:options`), `B24Input` — проп `:leading-icon` с иконкой-компонентом,
> `B24Table` — проп `:data` и слоты вида `#<column>-cell`. Точные пропы всегда сверяйте с исходником компонента и его theme-файлом.

---

## 🔧 Утилиты и помощники

### Форматтеры данных

```typescript
// utils/formatters.ts
export const formatters = {
  // Форматирование валюты
  currency(amount: number, currency: string = 'RUB'): string {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: 2
    }).format(amount);
  },

  // Форматирование даты
  date(date: string | Date, format: 'short' | 'long' = 'short'): string {
    const d = typeof date === 'string' ? new Date(date) : date;
    
    if (format === 'short') {
      return d.toLocaleDateString('ru-RU');
    }
    
    return d.toLocaleDateString('ru-RU', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  },

  // Относительное время  
  timeAgo(date: string | Date): string {
    const d = typeof date === 'string' ? new Date(date) : date;
    const now = new Date();
    const diff = now.getTime() - d.getTime();
    
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);
    
    if (minutes < 1) return 'только что';
    if (minutes < 60) return `${minutes} мин. назад`;
    if (hours < 24) return `${hours} ч. назад`;
    if (days < 7) return `${days} дн. назад`;
    
    return d.toLocaleDateString('ru-RU');
  },

  // Сокращение текста
  truncate(text: string, length: number = 100): string {
    if (text.length <= length) return text;
    return text.slice(0, length) + '...';
  }
};
```

---

## 🎯 Best practices

### 1. Компонентная архитектура

- **Используйте B24 компоненты** вместо нативных HTML элементов
- **Разделяйте** презентационные и контейнерные компоненты  
- **Создавайте** переиспользуемые композиции из B24UI компонентов
- **Документируйте** API компонентов через props и emits

### 2. Управление состоянием

- **Локальное состояние** для UI логики компонента
- **Pinia Store** для глобального состояния приложения
- **Composables** для переиспользуемой логики
- **Избегайте** prop drilling, используйте provide/inject

### 3. Производительность

- **Ленивая загрузка** компонентов и маршрутов
- **Виртуализация** для больших списков (через B24Table)
- **Мемоизация** вычислений через computed
- **Дебаунс** для пользовательского ввода

### 4. Доступность

- **B24UI** уже включает ARIA атрибуты
- **Семантические** компоненты (B24Card, B24Table)
- **Клавиатурная** навигация работает из коробки
- **Контрастность** цветов соответствует дизайн-системе

---

**Версия:** 2.1.0  
**Последнее обновление:** Ноябрь 2025  
**Лицензия:** MIT

