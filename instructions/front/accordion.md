# B24Accordion: Раскрывающийся список объектов

> **Встраивание в проект.** Примеры ниже — фрагменты. Готовую страницу кладите в `frontend/app/pages/<name>.client.vue` и стройте по «Шаблону страницы» из [knowledge.md](./knowledge.md): `useAppInit('Name')` → `$initializeB24Frame()` → `initApp($b24, localesI18n, setLocale)` в `onMounted` с `try/catch` + `processErrorGlobal`, данные — через `$b24.actions.v2.*.make()` или методы `useApiStore()` (не `fetch`/`$fetch`), логи — `$logger`. **Не оборачивайте** разметку в `<B24App>` / `<B24DashboardGroup>` — они уже есть в `app/app.vue` и `layouts/`; корень страницы — `<B24DashboardPanel>` (шапка `B24DashboardNavbar` в `#header`, фрагмент — в `#body`). Строки интерфейса выносите в `frontend/i18n/locales/*.json` и выводите через `$t('…')` (здесь они оставлены по-русски для краткости). `vue`-API, сторы и composables импортируются автоматически.

> **⚠️ ВАЖНО ДЛЯ ИИ АГЕНТОВ**: Используйте компонент **B24Accordion** из Bitrix24 UI Kit, а НЕ UAccordion из Nuxt UI!

## 📋 Описание

**B24Accordion** - компонент для отображения раскрывающегося списка элементов.

### Применение

- 📝 FAQ и часто задаваемые вопросы
- 📊 Списки объектов с деталями
- 📁 Группировка данных по категориям
- 🔍 Просмотр детальной информации

---

## 🎯 Базовый пример

### Минимальный код

```vue
<template>
  <B24Accordion :items="items" />
</template>

<script setup lang="ts">
const items = ref([
  {
    label: 'Объект 1',
    content: 'Описание объекта 1'
  },
  {
    label: 'Объект 2',
    content: 'Описание объекта 2'
  }
])
</script>
```

---

## 💼 Полный пример: Список объектов с данными

```vue
<template>
    <B24Container class="py-8">
      <!-- Заголовок -->
      <div class="mb-8">
        <ProseH1 class="text-3xl font-bold">📚 Список объектов</ProseH1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">
          Раскрывающийся список с детальной информацией
        </p>
      </div>

      <!-- Панель управления -->
      <B24Card class="mb-6">
        <div class="flex items-center justify-between">
          <div class="flex gap-2">
            <B24Button 
              :icon="PlusIcon" 
              color="air-primary"
              @click="addItem"
            >
              Добавить
            </B24Button>
            
            <B24Button 
              :icon="RefreshIcon"
              color="air-secondary-no-accent"
              @click="refreshItems"
            >
              Обновить
            </B24Button>
          </div>

          <B24FormField label="" class="m-0">
            <B24Input
              v-model="searchQuery"
              :icon="SearchIcon"
              placeholder="Поиск..."
              class="w-64"
            />
          </B24FormField>
        </div>
      </B24Card>

      <!-- Статистика -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <B24Card>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">Всего объектов</p>
              <p class="text-2xl font-bold">{{ totalItems }}</p>
            </div>
            <ChartIcon class="w-8 h-8 text-primary" />
          </div>
        </B24Card>

        <B24Card>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">Активных</p>
              <p class="text-2xl font-bold text-green-600">{{ activeItems }}</p>
            </div>
            <CheckIcon class="w-8 h-8 text-green-500" />
          </div>
        </B24Card>

        <B24Card>
          <div class="flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400">Найдено</p>
              <p class="text-2xl font-bold">{{ filteredItems.length }}</p>
            </div>
            <SearchIcon class="w-8 h-8 text-blue-500" />
          </div>
        </B24Card>
      </div>

      <!-- Accordion -->
      <B24Card v-if="loading" class="text-center py-8">
        <LoadingIcon class="w-8 h-8 animate-spin mx-auto text-primary" />
        <p class="mt-2 text-gray-600">Загрузка...</p>
      </B24Card>

      <B24Accordion
        v-else
        :items="accordionItems"
        type="multiple"
        class="space-y-2"
      >
        <!-- Кастомный контент для каждого элемента -->
        <template v-for="(item, index) in filteredItems" :key="item.id" #[`item-${index}`]>
          <div class="p-4 space-y-4">
            <!-- Основная информация -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">ID</p>
                <p class="font-semibold">#{{ item.id }}</p>
              </div>
              <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Статус</p>
                <B24Badge :color="getStatusColor(item.status)">
                  {{ item.status }}
                </B24Badge>
              </div>
            </div>

            <!-- Описание -->
            <div>
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Описание</p>
              <p class="text-gray-900 dark:text-white">{{ item.description }}</p>
            </div>

            <!-- Метаданные -->
            <div class="grid grid-cols-2 gap-4 pt-4 border-t">
              <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Создан</p>
                <p class="text-sm">{{ formatDate(item.createdAt) }}</p>
              </div>
              <div>
                <p class="text-sm text-gray-600 dark:text-gray-400">Обновлён</p>
                <p class="text-sm">{{ formatDate(item.updatedAt) }}</p>
              </div>
            </div>

            <!-- Действия -->
            <div class="flex gap-2 pt-4 border-t">
              <B24Button
                :icon="EditIcon"
                color="air-primary"
                size="sm"
                @click="editItem(item)"
              >
                Редактировать
              </B24Button>
              
              <B24Button
                :icon="TrashIcon"
                color="air-secondary-no-accent"
                size="sm"
                @click="deleteItem(item)"
              >
                Удалить
              </B24Button>
              
              <B24Button
                :icon="CopyIcon"
                color="air-tertiary"
                size="sm"
                @click="duplicateItem(item)"
              >
                Дублировать
              </B24Button>
            </div>
          </div>
        </template>
      </B24Accordion>

      <!-- Пусто -->
      <B24Card v-if="!loading && filteredItems.length === 0" class="text-center py-8">
        <InboxIcon class="w-12 h-12 mx-auto text-gray-400 mb-4" />
        <ProseH3 class="text-lg font-semibold mb-2">Нет объектов</ProseH3>
        <p class="text-gray-600 mb-4">Создайте первый объект</p>
        <B24Button :icon="PlusIcon" color="air-primary" @click="addItem">
          Создать
        </B24Button>
      </B24Card>
    </B24Container>
</template>

<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import PlusIcon from '@bitrix24/b24icons-vue/button/PlusIcon'
import RefreshIcon from '@bitrix24/b24icons-vue/main/RefreshIcon'
import SearchIcon from '@bitrix24/b24icons-vue/outline/SearchIcon'
import ChartIcon from '@bitrix24/b24icons-vue/outline/GraphsDiagramIcon'
import CheckIcon from '@bitrix24/b24icons-vue/main/CheckIcon'
import LoadingIcon from '@bitrix24/b24icons-vue/animated/LoaderWaitIcon'
import EditIcon from '@bitrix24/b24icons-vue/button/EditIcon'
import TrashIcon from '@bitrix24/b24icons-vue/outline/TrashcanIcon'
import CopyIcon from '@bitrix24/b24icons-vue/outline/CopyIcon'
import InboxIcon from '@bitrix24/b24icons-vue/outline/BoxIcon'

interface Item {
  id: number
  name: string
  description: string
  status: string
  createdAt: string
  updatedAt: string
}

const { locales: localesI18n, setLocale } = useI18n()
const { $logger, initApp, processErrorGlobal } = useAppInit('AccordionPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null
const apiStore = useApiStore()
const toast = useToast()

// State
const items = ref<Item[]>([])
const searchQuery = ref('')
const loading = ref(false)

// Загрузка данных
const loadItems = async () => {
  loading.value = true

  try {
    // Метод своего бэкенда: добавьте getItems(): Promise<Item[]> в app/stores/api.ts
    // (по образцу getList, с headers: authHeaders()). Либо читайте данные Bitrix24
    // через $b24.actions.v2.call.make({ method: '...' }).
    items.value = await apiStore.getItems()
  } catch (error) {
    $logger.error('Error loading items', { error })
    toast.add({
      title: 'Ошибка',
      description: 'Не удалось загрузить данные',
      color: 'air-primary-alert'
    })
  } finally {
    loading.value = false
  }
}

// Фильтрация
const filteredItems = computed(() => {
  if (!searchQuery.value) return items.value
  
  const query = searchQuery.value.toLowerCase()
  return items.value.filter(item => 
    item.name.toLowerCase().includes(query) ||
    item.description.toLowerCase().includes(query)
  )
})

// Accordion items
const accordionItems = computed(() => {
  return filteredItems.value.map((item, index) => ({
    label: item.name,
    value: `item-${index}`,
    slot: `item-${index}`,
    icon: getStatusIcon(item.status)
  }))
})

// Статистика
const totalItems = computed(() => items.value.length)
const activeItems = computed(() => 
  items.value.filter(item => item.status === 'ACTIVE').length
)

// Утилиты
const formatDate = (dateString: string) => {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('ru-RU')
}

const getStatusColor = (status: string) => {
  const colors = {
    'ACTIVE': 'air-primary-success',
    'PENDING': 'air-primary-warning',
    'INACTIVE': 'air-secondary',
    'ARCHIVED': 'air-primary-alert'
  } as const
  return colors[status as keyof typeof colors] || 'air-secondary'
}

const getStatusIcon = (status: string) => {
  const icons: Record<string, typeof InboxIcon> = {
    'ACTIVE': CheckIcon,
    'PENDING': LoadingIcon,
    'INACTIVE': InboxIcon,
    'ARCHIVED': TrashIcon
  }
  return icons[status] || InboxIcon
}

// Actions
const refreshItems = () => {
  loadItems()
}

const addItem = () => {
  $logger.debug('Add item')
  // Реализуйте создание нового объекта
}

const editItem = (item: Item) => {
  $logger.debug('Edit item', { item })
  // Реализуйте редактирование
}

const deleteItem = async (item: Item) => {
  if (!confirm(`Удалить объект "${item.name}"?`)) return
  
  try {
    await apiStore.deleteItem(item.id) // метод бэкенда в app/stores/api.ts

    toast.add({
      title: 'Удалено',
      description: `Объект "${item.name}" удалён`,
      color: 'air-primary-success'
    })
    
    await loadItems()
  } catch (error) {
    $logger.error('Error deleting item', { error })
    toast.add({
      title: 'Ошибка',
      description: 'Не удалось удалить объект',
      color: 'air-primary-alert'
    })
  }
}

const duplicateItem = (item: Item) => {
  $logger.debug('Duplicate item', { item })
  // Реализуйте дублирование
}

// Lifecycle
onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle('Список объектов')
    await loadItems()
  } catch (error) {
    processErrorGlobal(error)
  }
})
</script>
```

---

## 🎨 Кастомизация

### Множественное раскрытие

```vue
<template>
  <!-- Позволяет открыть несколько элементов одновременно -->
  <B24Accordion :items="items" type="multiple" />
</template>
```

### Кастомные иконки

```vue
<script setup lang="ts">
import RocketIcon from '@bitrix24/b24icons-vue/main/RocketIcon'
import StarIcon from '@bitrix24/b24icons-vue/outline/FavoriteIcon'

const items = ref([
  {
    label: 'С иконкой',
    content: 'Содержимое',
    icon: RocketIcon,
    trailingIcon: StarIcon
  }
])
</script>
```

### Отключенные элементы

```vue
<script setup lang="ts">
const items = ref([
  {
    label: 'Активный',
    content: 'Можно раскрыть'
  },
  {
    label: 'Отключенный',
    content: 'Нельзя раскрыть',
    disabled: true
  }
])
</script>
```

---

## 💡 Use Cases

### 1. FAQ список

```vue
<template>
  <B24Accordion :items="faqItems" />
</template>

<script setup lang="ts">
const faqItems = ref([
  {
    label: 'Как начать работу с Bitrix24?',
    content: 'Bitrix24 - это онлайн-сервис для управления компанией...'
  },
  {
    label: 'Какие тарифы доступны?',
    content: 'Bitrix24 предлагает несколько тарифов для разных типов бизнеса...'
  }
])
</script>
```

### 2. Группировка данных

```vue
<template>
  <B24Accordion :items="groupedItems" type="multiple">
    <template v-for="(group, index) in groups" :key="group.id" #[`group-${index}`]>
      <div class="space-y-2">
        <div v-for="item in group.items" :key="item.id" class="p-2 bg-gray-50 rounded">
          {{ item.name }}
        </div>
      </div>
    </template>
  </B24Accordion>
</template>
```

---

## 🔗 Интеграция с Backend

### Загрузка данных

```typescript
// Вариант 1: REST API Bitrix24 через JS SDK (из браузера, авторизация берётся из фрейма)
const response = await $b24.actions.v2.call.make({
  method: 'crm.deal.list',
  params: { select: ['ID', 'TITLE'] }
})
if (!response.isSuccess) {
  throw new Error(response.getErrorMessages().join('; '))
}
const deals = response.getData()?.result ?? []

// Вариант 2: свой бэкенд — метод в app/stores/api.ts (JWT добавляется через authHeaders())
const apiItems = await useApiStore().getList()
```

Прямые `fetch('https://<portal>/rest/...')` и `fetch('/api/...')` из страниц не используйте.

---

## 📊 Props

```typescript
interface AccordionItem {
  label?: string              // Заголовок элемента
  content?: string            // Содержимое (или используйте slot)
  value?: string              // Уникальное значение
  icon?: Component            // Иконка слева
  trailingIcon?: Component    // Иконка справа
  disabled?: boolean          // Отключить элемент
  slot?: string               // Имя слота для кастомного контента
  class?: string              // CSS классы
}
```

---

## 🎯 Best Practices

### ✅ Рекомендуется

1. **Используйте slots для сложного контента**
   ```vue
   <template #item-0>
     <CustomComponent />
   </template>
   ```

2. **Добавляйте поиск для больших списков**
   ```vue
   <B24Input v-model="search" placeholder="Поиск..." />
   ```

3. **Показывайте loading состояние**
   ```vue
   <div v-if="loading">Загрузка...</div>
   ```

### ❌ Избегайте

1. **Не создавайте слишком глубокую вложенность** (max 2-3 уровня)
2. **Не размещайте большие объёмы данных** в content prop (используйте slots)
3. **Не забывайте про unique keys** при использовании v-for

---

## 📚 Дополнительные ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **B24Accordion** - см. строку 4020 в полной документации
- **Bitrix24 Icons**: https://bitrix24.github.io/b24icons/
- **REST API**: https://apidocs.bitrix24.ru/

---

**Дата**: Октябрь 2025  
**Версия**: 2.14 (Bitrix24 UI Kit)  
**Компонент**: B24Accordion (НЕ UAccordion!)
