# B24Table: Универсальная таблица с данными

> **⚠️ ВАЖНО ДЛЯ ИИ АГЕНТОВ**: Используйте компонент **B24Table** из Bitrix24 UI Kit, а НЕ UTable из Nuxt UI!

## 📋 Описание

**B24Table** - мощный компонент для отображения табличных данных, построенный на TanStack Table.

### Применение

- 📊 Списки любых CRM объектов
- 📈 Отчёты и аналитика
- 📝 Логи и история операций
- 🛒 Каталоги товаров и услуг

---

## 🎯 Базовый пример

### Минимальный код

```vue
<template>
  <B24Table :columns="columns" :data="data" />
</template>

<script setup>
// Колонки в формате TanStack Table: accessorKey + header
const columns = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Название' }
]

const data = ref([
  { id: 1, name: 'Объект 1' },
  { id: 2, name: 'Объект 2' }
])
</script>
```

---

## 💼 Полный пример: Таблица с данными

```vue
<template>
  <B24App>
    <B24Container class="py-8">
      <!-- Заголовок -->
      <div class="mb-8">
        <h1 class="text-3xl font-bold">📊 Список объектов</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">
          Табличное представление данных
        </p>
      </div>

      <!-- Панель действий -->
      <div class="flex items-center justify-between mb-6">
        <div class="flex gap-2">
          <B24Button
            :icon="PlusIcon"
            color="air-primary"
            @click="addItem"
          >
            Добавить
          </B24Button>
          
          <B24Button
            :icon="DownloadIcon"
            color="air-secondary-no-accent"
            @click="exportData"
          >
            Экспорт
          </B24Button>
        </div>

        <!-- Поиск -->
        <B24Input
          v-model="searchQuery"
          :icon="SearchIcon"
          placeholder="Поиск..."
          class="w-64"
        />
      </div>

      <!-- Статистика -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <B24Card>
          <div class="flex items-center justify-between p-4">
            <div>
              <p class="text-sm text-gray-600">Всего</p>
              <p class="text-2xl font-bold">{{ total }}</p>
            </div>
            <ChartIcon class="w-8 h-8 text-primary" />
          </div>
        </B24Card>

        <B24Card>
          <div class="flex items-center justify-between p-4">
            <div>
              <p class="text-sm text-gray-600">На странице</p>
              <p class="text-2xl font-bold">{{ data.length }}</p>
            </div>
            <DocumentIcon class="w-8 h-8 text-blue-500" />
          </div>
        </B24Card>

        <B24Card>
          <div class="flex items-center justify-between p-4">
            <div>
              <p class="text-sm text-gray-600">Выбрано</p>
              <p class="text-2xl font-bold">{{ selectedRows.length }}</p>
            </div>
            <CheckIcon class="w-8 h-8 text-green-500" />
          </div>
        </B24Card>
      </div>

      <!-- Таблица -->
      <B24Card>
        <B24Table
          v-model:row-selection="rowSelection"
          :columns="columns"
          :data="filteredData"
          :loading="loading"
          class="w-full"
        >
          <!-- ID колонка -->
          <template #id-cell="{ row }">
            <span class="font-mono text-sm text-gray-500">
              #{{ row.original.id }}
            </span>
          </template>

          <!-- Название -->
          <template #name-cell="{ row }">
            <div class="flex items-center gap-2">
              <span class="font-medium">{{ row.original.name }}</span>
            </div>
          </template>

          <!-- Статус -->
          <template #status-cell="{ row }">
            <B24Badge
              :color="getStatusColor(row.original.status)"
            >
              {{ row.original.status }}
            </B24Badge>
          </template>

          <!-- Дата -->
          <template #date-cell="{ row }">
            <span class="text-sm text-gray-600">
              {{ formatDate(row.original.date) }}
            </span>
          </template>

          <!-- Действия -->
          <template #actions-cell="{ row }">
            <div class="flex gap-1">
              <B24Button
                :icon="EyeIcon"
                size="xs"
                color="air-tertiary"
                @click="viewItem(row.original)"
              />
              <B24Button
                :icon="EditIcon"
                size="xs"
                color="air-tertiary"
                @click="editItem(row.original)"
              />
              <B24Button
                :icon="TrashIcon"
                size="xs"
                color="air-tertiary"
                @click="deleteItem(row.original)"
              />
            </div>
          </template>
        </B24Table>

        <!-- Пагинация -->
        <div v-if="total > pageSize" class="flex justify-center mt-6 border-t pt-6">
          <B24Pagination
            v-model:page="page"
            :total="total"
            :items-per-page="pageSize"
            show-edges
          />
        </div>
      </B24Card>

      <!-- Массовые действия -->
      <B24Card v-if="selectedRows.length > 0" class="mt-6">
        <div class="flex items-center justify-between p-4">
          <p class="font-semibold">
            Выбрано: {{ selectedRows.length }} объектов
          </p>
          <div class="flex gap-2">
            <B24Button
              color="air-secondary-no-accent"
              @click="bulkEdit"
            >
              Массовое редактирование
            </B24Button>
            <B24Button
              color="air-secondary-no-accent"
              @click="bulkDelete"
            >
              Удалить выбранные
            </B24Button>
          </div>
        </div>
      </B24Card>
    </B24Container>
  </B24App>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, h, resolveComponent } from 'vue'
import type { TableColumn } from '@bitrix24/b24ui-nuxt'
import PlusIcon from '@bitrix24/b24icons-vue/button/PlusIcon'
import DownloadIcon from '@bitrix24/b24icons-vue/outline/DownloadIcon'
import SearchIcon from '@bitrix24/b24icons-vue/outline/SearchIcon'
import ChartIcon from '@bitrix24/b24icons-vue/outline/GraphsDiagramIcon'
import DocumentIcon from '@bitrix24/b24icons-vue/main/DocumentIcon'
import CheckIcon from '@bitrix24/b24icons-vue/main/CheckIcon'
import EyeIcon from '@bitrix24/b24icons-vue/main/OpenedEyeIcon'
import EditIcon from '@bitrix24/b24icons-vue/button/EditIcon'
import TrashIcon from '@bitrix24/b24icons-vue/outline/TrashcanIcon'

interface Item {
  id: number
  name: string
  status: string
  date: string
}

// State
const data = ref<Item[]>([])
// Состояние выбора строк TanStack: { [rowId]: true }
const rowSelection = ref<Record<string, boolean>>({})
const selectedRows = computed(() => filteredData.value.filter((_, index) => rowSelection.value[index]))
const loading = ref(false)
const searchQuery = ref('')
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)

// Columns (формат TanStack Table)
const B24Checkbox = resolveComponent('B24Checkbox')

const columns: TableColumn<Item>[] = [
  {
    id: 'select',
    header: ({ table }) => h(B24Checkbox, {
      'modelValue': table.getIsSomePageRowsSelected() ? 'indeterminate' : table.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => table.toggleAllPageRowsSelected(!!value)
    }),
    cell: ({ row }) => h(B24Checkbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value)
    })
  },
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Название' },
  { accessorKey: 'status', header: 'Статус' },
  { accessorKey: 'date', header: 'Дата' },
  { id: 'actions', header: 'Действия' }
]

// Filtered data
const filteredData = computed(() => {
  if (!searchQuery.value) return data.value
  
  const query = searchQuery.value.toLowerCase()
  return data.value.filter(item =>
    item.name.toLowerCase().includes(query)
  )
})

// Methods
const loadData = async () => {
  loading.value = true
  
  try {
    // Замените на ваш API endpoint
    const params = new URLSearchParams({
      page: page.value.toString(),
      pageSize: pageSize.value.toString(),
      search: searchQuery.value
    })
    
    const response = await fetch(`/api/items?${params}`)
    const result = await response.json()
    
    data.value = result.items
    total.value = result.total
  } catch (error) {
    console.error('Error loading data:', error)
    useToast().add({
      title: 'Ошибка',
      description: 'Не удалось загрузить данные',
      color: 'air-primary-alert'
    })
  } finally {
    loading.value = false
  }
}

const formatDate = (dateString: string) => {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString('ru-RU')
}

const getStatusColor = (status: string) => {
  const colors = {
    'NEW': 'air-primary',
    'IN_PROGRESS': 'air-primary-warning',
    'COMPLETED': 'air-primary-success',
    'CANCELLED': 'air-primary-alert'
  } as const
  return colors[status as keyof typeof colors] || 'air-secondary'
}

// Actions
const addItem = () => {
  console.log('Add item')
}

const viewItem = (item: Item) => {
  console.log('View:', item)
}

const editItem = (item: Item) => {
  console.log('Edit:', item)
}

const deleteItem = async (item: Item) => {
  if (!confirm(`Удалить объект #${item.id}?`)) return
  
  try {
    await fetch(`/api/items/${item.id}`, { method: 'DELETE' })
    useToast().add({
      title: 'Удалено',
      color: 'air-primary-success'
    })
    await loadData()
  } catch (error) {
    useToast().add({
      title: 'Ошибка',
      color: 'air-primary-alert'
    })
  }
}

const bulkEdit = () => {
  console.log('Bulk edit:', selectedRows.value)
}

const bulkDelete = async () => {
  if (!confirm(`Удалить ${selectedRows.value.length} объектов?`)) return
  
  // Реализуйте массовое удаление
}

const exportData = () => {
  // Экспорт в CSV/Excel
  const csv = data.value.map(row => 
    Object.values(row).join(',')
  ).join('\n')
  
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'data.csv'
  link.click()
}

// Watchers
// (для debounce используйте, например, watchDebounced из @vueuse/core)
watch([page, searchQuery], () => {
  loadData()
})

// Lifecycle
onMounted(() => {
  loadData()
})
</script>
```

---

## 🎨 Кастомизация

### Сортировка

Состояние сортировки — `SortingState` из TanStack Table, передаётся через `v-model:sorting`.
Переключение сортировки по клику на заголовок реализуется в `header` колонки (`column.toggleSorting()`).

```vue
<template>
  <B24Table v-model:sorting="sorting" :columns="columns" :data="data" />
</template>

<script setup>
const sorting = ref([{ id: 'id', desc: false }])

const columns = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Название' }
]
</script>
```

### Выбор строк

```vue
<template>
  <B24Table
    v-model:row-selection="rowSelection"
    :columns="columns"
    :data="data"
  />
</template>

<script setup>
// { [rowId]: true } — колонку с чекбоксами (id: 'select') добавьте в columns, см. полный пример выше
const rowSelection = ref({})
</script>
```

### Loading состояние

```vue
<template>
  <B24Table
    :columns="columns"
    :data="data"
    :loading="loading"
  />
</template>
```

---

## 📊 Props

```typescript
// TableColumn<T> = ColumnDef<T> из TanStack Table
import type { TableColumn } from '@bitrix24/b24ui-nuxt'

const column: TableColumn<Item> = {
  accessorKey: 'name',     // Ключ данных (или id для вычисляемых колонок)
  header: 'Название',      // Заголовок колонки (строка или функция рендеринга)
  cell: ({ row }) => row.getValue('name'), // Кастомная функция рендеринга
  enableSorting: true      // Разрешить сортировку
}
// Слоты ячеек/заголовков: #<id>-cell, #<id>-header
```

---

## 🔗 Интеграция с Backend

```typescript
const loadData = async () => {
  // Любой backend
  const response = await fetch('/api/items', {
    method: 'GET',
    headers: { 'Content-Type': 'application/json' }
  })
  data.value = await response.json()
}
```

---

## 📚 Ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **B24Table** - см. строку 34488 в полной документации
- **TanStack Table**: https://tanstack.com/table/latest
- **REST API**: https://apidocs.bitrix24.ru/

---

**Дата**: Октябрь 2025  
**Версия**: 2.14 (Bitrix24 UI Kit)  
**Компонент**: B24Table (НЕ UTable!)
