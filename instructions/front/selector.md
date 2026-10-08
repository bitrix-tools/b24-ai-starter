# B24SelectMenu: Селектор объектов с фильтрами

> **Встраивание в проект.** Примеры ниже — фрагменты. Готовую страницу кладите в `frontend/app/pages/<name>.client.vue` и стройте по «Шаблону страницы» из [knowledge.md](./knowledge.md): `useAppInit('Name')` → `$initializeB24Frame()` → `initApp($b24, localesI18n, setLocale)` в `onMounted` с `try/catch` + `processErrorGlobal`, данные — через `$b24.actions.v2.*.make()` или методы `useApiStore()` (не `fetch`/`$fetch`), логи — `$logger`. **Не оборачивайте** разметку в `<B24App>` / `<B24SidebarLayout>` — они уже есть в `app/app.vue` и `layouts/`. Строки интерфейса выносите в `frontend/i18n/locales/*.json` и выводите через `$t('…')` (здесь они оставлены по-русски для краткости). `vue`-API, сторы и composables импортируются автоматически.

> **⚠️ ВАЖНО ДЛЯ ИИ АГЕНТОВ**: Используйте **B24SelectMenu** из Bitrix24 UI Kit, а НЕ USelectMenu из Nuxt UI!

## 📋 Описание

**B24SelectMenu** - компонент для выбора объектов с поиском и фильтрацией.

### Применение

- 👥 Выбор контактов, компаний, пользователей
- 🔍 Фильтры в отчётах
- ✅ Массовые операции
- 🔗 Связывание объектов

---

## 🎯 Базовый пример

```vue
<template>
  <B24SelectMenu
    v-model="selected"
    :items="options"
    value-key="value"
    multiple
    :search-input="{ placeholder: 'Поиск...' }"
    placeholder="Выберите объекты..."
  />
</template>

<script setup lang="ts">
const options = [
  { value: 1, label: 'Объект 1' },
  { value: 2, label: 'Объект 2' }
]

// с value-key="value" в v-model попадают значения, а не объекты целиком
const selected = ref<number[]>([])
</script>
```

---

## 💼 Полный пример

```vue
<template>
  <B24Container class="py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Filters -->
      <B24Card>
        <template #header>
          <ProseH3 class="text-lg font-semibold">Фильтры</ProseH3>
        </template>

        <div class="space-y-4 p-4">
          <B24FormField label="Поиск">
            <B24Input
              v-model="filters.search"
              :icon="SearchIcon"
              @update:model-value="applyFilters"
            />
          </B24FormField>

          <B24FormField label="Категория">
            <B24Select
              v-model="filters.category"
              :items="categoryOptions"
              @update:model-value="applyFilters"
            />
          </B24FormField>

          <div class="flex gap-2">
            <B24Button block color="air-primary" @click="applyFilters">
              Применить
            </B24Button>
            <B24Button block color="air-secondary-no-accent" @click="resetFilters">
              Сбросить
            </B24Button>
          </div>
        </div>
      </B24Card>

      <!-- Selector -->
      <div class="lg:col-span-2 space-y-6">
        <B24Card>
          <template #header>
            <ProseH3 class="text-lg font-semibold">
              Выберите объекты ({{ items.length }} доступно)
            </ProseH3>
          </template>

          <div class="p-4">
            <B24SelectMenu
              v-model="selected"
              :items="options"
              value-key="value"
              :loading="loading"
              multiple
              :search-input="{ placeholder: 'Поиск...' }"
              placeholder="Выберите..."
            />
          </div>
        </B24Card>

        <!-- Selected Items -->
        <B24Card v-if="selected.length > 0">
          <template #header>
            <div class="flex justify-between">
              <ProseH3 class="text-lg font-semibold">Выбрано: {{ selected.length }}</ProseH3>
              <B24Button size="sm" color="air-tertiary" @click="selected = []">
                Очистить
              </B24Button>
            </div>
          </template>

          <div class="p-4">
            <div class="flex gap-2">
              <B24Select
                v-model="bulkAction"
                :items="actionOptions"
                placeholder="Выберите действие"
                class="flex-1"
              />
              <B24Button color="air-primary" @click="performBulkAction">
                Выполнить
              </B24Button>
            </div>
          </div>
        </B24Card>
      </div>
    </div>
  </B24Container>
</template>

<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import SearchIcon from '@bitrix24/b24icons-vue/outline/SearchIcon'

interface Item {
  id: number
  name: string
}

const { locales: localesI18n, setLocale } = useI18n()
const { $logger, initApp, processErrorGlobal } = useAppInit('SelectorPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null
const { track } = useTelemetry()

const items = ref<Item[]>([])
const selected = ref<number[]>([])
const loading = ref(false)
const filters = ref({ search: '', category: '' })
const bulkAction = ref<string>()

const categoryOptions = [
  { value: '', label: 'Все' },
  { value: '0', label: 'Общая воронка' }
]
const actionOptions = [
  { value: 'export', label: 'Экспорт' },
  { value: 'archive', label: 'В архив' }
]

const options = computed(() => {
  return items.value.map(item => ({
    value: item.id,
    label: item.name
  }))
})

const loadItems = async () => {
  if (!$b24) return
  loading.value = true
  try {
    const filter: Record<string, string> = {}
    if (filters.value.search) filter['%TITLE'] = filters.value.search
    if (filters.value.category) filter['CATEGORY_ID'] = filters.value.category

    const response = await $b24.actions.v2.call.make<{ ID: string, TITLE: string }[]>({
      method: 'crm.deal.list',
      params: { select: ['ID', 'TITLE'], filter }
    })
    if (!response.isSuccess) {
      throw new Error(response.getErrorMessages().join('; '))
    }
    items.value = (response.getData()?.result ?? []).map(deal => ({ id: Number(deal.ID), name: deal.TITLE }))
  } catch (error) {
    processErrorGlobal(error)
  } finally {
    loading.value = false
  }
}

const applyFilters = () => loadItems()
const resetFilters = () => {
  filters.value = { search: '', category: '' }
  loadItems()
}

const performBulkAction = () => {
  track('ui_button_click', { 'ui.button_id': 'selector_bulk_action' })
  $logger.info('Bulk action', { action: bulkAction.value, selected: selected.value })
}

onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await loadItems()
  } catch (error) {
    processErrorGlobal(error)
  }
})
</script>
```

---

## 📚 Ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **B24SelectMenu** - см. строку 29676 в полной документации
- **REST API**: https://apidocs.bitrix24.ru/

---

**Дата**: Октябрь 2025  
**Версия**: 2.14 (Bitrix24 UI Kit)  
**Компонент**: B24SelectMenu (НЕ USelectMenu!)
