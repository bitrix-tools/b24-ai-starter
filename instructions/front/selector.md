# B24SelectMenu: Селектор объектов с фильтрами

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
    multiple
    searchable
    placeholder="Выберите объекты..."
  />
</template>

<script setup>
const options = [
  { value: 1, label: 'Объект 1' },
  { value: 2, label: 'Объект 2' }
]

const selected = ref([])
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
          <h3 class="text-lg font-semibold">Фильтры</h3>
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
            <h3 class="text-lg font-semibold">
              Выберите объекты ({{ items.length }} доступно)
            </h3>
          </template>

          <div class="p-4">
            <B24SelectMenu
              v-model="selected"
              :items="options"
              :loading="loading"
              multiple
              searchable
              placeholder="Выберите..."
            />
          </div>
        </B24Card>

        <!-- Selected Items -->
        <B24Card v-if="selected.length > 0">
          <template #header>
            <div class="flex justify-between">
              <h3 class="text-lg font-semibold">Выбрано: {{ selected.length }}</h3>
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
import { SearchIcon } from '@bitrix24/b24icons'

const items = ref([])
const selected = ref([])
const loading = ref(false)
const filters = ref({ search: '', category: '' })

const options = computed(() => {
  return items.value.map(item => ({
    value: item.id,
    label: item.name
  }))
})

const loadItems = async () => {
  loading.value = true
  try {
    const response = await fetch('/api/items')
    items.value = await response.json()
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
  console.log('Bulk action:', selected.value)
}

onMounted(() => loadItems())
</script>
```

---

## 📚 Ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **B24SelectMenu** - см. строку 29676 в полной документации
- **REST API**: https://apidocs.bitrix24.ru/

---

**Дата**: Октябрь 2025  
**Версия**: 2.0 (Bitrix24 UI Kit)  
**Компонент**: B24SelectMenu (НЕ USelectMenu!)
