# B24Form: Форма редактирования объекта

> **Встраивание в проект.** Примеры ниже — фрагменты. Готовую страницу кладите в `frontend/app/pages/<name>.client.vue` и стройте по «Шаблону страницы» из [knowledge.md](./knowledge.md): `useAppInit('Name')` → `$initializeB24Frame()` → `initApp($b24, localesI18n, setLocale)` в `onMounted` с `try/catch` + `processErrorGlobal`, данные — через `$b24.actions.v2.*.make()` или методы `useApiStore()` (не `fetch`/`$fetch`), логи — `$logger`. **Не оборачивайте** разметку в `<B24App>` / `<B24SidebarLayout>` — они уже есть в `app/app.vue` и `layouts/`. Строки интерфейса выносите в `frontend/i18n/locales/*.json` и выводите через `$t('…')` (здесь они оставлены по-русски для краткости). `vue`-API, сторы и composables импортируются автоматически.

> **⚠️ ВАЖНО ДЛЯ ИИ АГЕНТОВ**: Используйте **B24Form** и **B24FormField** из Bitrix24 UI Kit, а НЕ UForm/UFormGroup из Nuxt UI!

## 📋 Описание

**B24Form** - компонент для создания и редактирования объектов с валидацией.

### Применение

- 📝 Детальные карточки объектов
- ➕ Формы создания новых записей
- ⚙️ Настройки и конфигурация
- 🔄 Многошаговые формы (wizards)

---

## 🎯 Базовый пример

```vue
<template>
  <B24Form :state="state" @submit="onSubmit">
    <B24FormField label="Название" name="name">
      <B24Input v-model="state.name" />
    </B24FormField>
    
    <B24Button type="submit" color="air-primary">
      Сохранить
    </B24Button>
  </B24Form>
</template>

<script setup lang="ts">
import type { FormSubmitEvent } from '@bitrix24/b24ui-nuxt'

const { $logger } = useAppInit('FormPage')
const { track } = useTelemetry()

const state = reactive({
  name: ''
})

const onSubmit = async (event: FormSubmitEvent<typeof state>) => {
  track('ui_form_submit', { 'ui.form': 'basic_form' })
  $logger.info('Submitted', { data: event.data })
}
</script>
```

---

## 💼 Полный пример: Карточка объекта

```vue
<template>
    <B24Container class="py-8">
      <div v-if="loading" class="flex items-center justify-center min-h-screen">
        <LoadingIcon class="w-8 h-8 animate-spin" />
      </div>

      <div v-else class="space-y-6">
        <!-- Header -->
        <B24Card>
          <div class="flex items-start justify-between gap-4 p-4">
            <div class="flex-1">
              <B24Input
                v-model="form.title"
                size="xl"
                placeholder="Название объекта"
                class="text-2xl font-bold"
              />
              <div class="flex items-center gap-4 mt-3 text-sm text-gray-600">
                <CalendarIcon />
                <span>Создан: {{ formatDate(item.createdAt) }}</span>
              </div>
            </div>

            <div class="flex items-center gap-2">
              <B24Button
                :icon="CheckIcon"
                color="air-primary"
                :loading="saving"
                @click="saveItem"
              >
                Сохранить
              </B24Button>

              <B24Button
                :icon="CloseIcon"
                color="air-secondary-no-accent"
                :disabled="saving"
                @click="cancelEdit"
              >
                Отменить
              </B24Button>
            </div>
          </div>
        </B24Card>

        <!-- Form -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="lg:col-span-2 space-y-6">
            <B24Card>
              <template #header>
                <ProseH3 class="text-lg font-semibold">Основная информация</ProseH3>
              </template>

              <div class="space-y-4 p-4">
                <B24FormField label="Статус" required>
                  <B24Select
                    v-model="form.status"
                    :items="statusOptions"
                    placeholder="Выберите статус"
                  />
                </B24FormField>

                <B24FormField label="Сумма">
                  <div class="flex gap-2">
                    <B24Input
                      v-model="form.amount"
                      type="number"
                      placeholder="0"
                      class="flex-1"
                    />
                    <B24Select
                      v-model="form.currency"
                      :items="currencyOptions"
                      class="w-32"
                    />
                  </div>
                </B24FormField>

                <div class="grid grid-cols-2 gap-4">
                  <B24FormField label="Дата начала">
                    <B24Input
                      v-model="form.startDate"
                      type="date"
                    />
                  </B24FormField>

                  <B24FormField label="Дата завершения">
                    <B24Input
                      v-model="form.endDate"
                      type="date"
                    />
                  </B24FormField>
                </div>
              </div>
            </B24Card>

            <B24Card>
              <template #header>
                <ProseH3 class="text-lg font-semibold">Дополнительно</ProseH3>
              </template>

              <div class="p-4">
                <B24FormField label="Описание">
                  <B24Textarea
                    v-model="form.description"
                    :rows="4"
                    placeholder="Добавьте описание..."
                  />
                </B24FormField>
              </div>
            </B24Card>
          </div>

          <!-- Sidebar -->
          <div class="space-y-6">
            <B24Card>
              <template #header>
                <ProseH3 class="text-lg font-semibold">Информация</ProseH3>
              </template>

              <div class="space-y-3 text-sm p-4">
                <div class="flex justify-between">
                  <span class="text-gray-600">ID:</span>
                  <span class="font-semibold">#{{ item.id }}</span>
                </div>
              </div>
            </B24Card>

            <B24Card>
              <template #header>
                <ProseH3 class="text-lg font-semibold">Действия</ProseH3>
              </template>

              <div class="space-y-2 p-4">
                <B24Button
                  block
                  color="air-secondary-no-accent"
                  :icon="CopyIcon"
                  @click="duplicate"
                >
                  Дублировать
                </B24Button>
                
                <B24Button
                  block
                  color="air-secondary-no-accent"
                  :icon="TrashIcon"
                  @click="deleteItem"
                >
                  Удалить
                </B24Button>
              </div>
            </B24Card>
          </div>
        </div>
      </div>
    </B24Container>
</template>

<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import LoadingIcon from '@bitrix24/b24icons-vue/animated/LoaderWaitIcon'
import CalendarIcon from '@bitrix24/b24icons-vue/outline/CalendarIcon'
import CheckIcon from '@bitrix24/b24icons-vue/main/CheckIcon'
import CloseIcon from '@bitrix24/b24icons-vue/actions/Cross30Icon'
import CopyIcon from '@bitrix24/b24icons-vue/outline/CopyIcon'
import TrashIcon from '@bitrix24/b24icons-vue/outline/TrashcanIcon'

const { locale, locales: localesI18n, setLocale } = useI18n()
const { $logger, initApp, processErrorGlobal } = useAppInit('ItemCardPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null
const apiStore = useApiStore()
const toast = useToast()
const { track } = useTelemetry()

const item = ref({ id: 1, createdAt: new Date().toISOString() })
const form = reactive({
  title: '',
  status: '',
  amount: 0,
  currency: 'RUB',
  startDate: '',
  endDate: '',
  description: ''
})

const loading = ref(false)
const saving = ref(false)

const statusOptions = [
  { value: 'NEW', label: 'Новый' },
  { value: 'IN_PROGRESS', label: 'В работе' },
  { value: 'COMPLETED', label: 'Завершён' }
]

const currencyOptions = [
  { value: 'RUB', label: '₽ RUB' },
  { value: 'USD', label: '$ USD' },
  { value: 'EUR', label: '€ EUR' }
]

const saveItem = async () => {
  track('ui_button_click', { 'ui.button_id': 'item_card_save' })
  saving.value = true
  try {
    // Метод своего бэкенда в app/stores/api.ts (по образцу getList, с headers: authHeaders()).
    // Для сущностей Bitrix24 — $b24.actions.v2.call.make({ method: 'crm.item.update', params: { ... } })
    await apiStore.updateItem(item.value.id, { ...form })
    toast.add({ title: 'Сохранено', color: 'air-primary-success' })
  } catch (error) {
    $logger.error('Failed to save item', { error })
    toast.add({
      title: 'Ошибка',
      description: error instanceof Error ? error.message : String(error),
      color: 'air-primary-alert'
    })
  } finally {
    saving.value = false
  }
}

const cancelEdit = () => {
  // Reload data
}

const duplicate = () => {
  $logger.debug('Duplicate', { id: item.value.id })
}

const deleteItem = () => {
  $logger.debug('Delete', { id: item.value.id })
}

const formatDate = (date: string) => new Date(date).toLocaleString(locale.value)

onMounted(async () => {
  try {
    loading.value = true
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle('Карточка объекта')
  } catch (error) {
    processErrorGlobal(error)
  } finally {
    loading.value = false
  }
})
</script>
```

---

## 🎨 Важные отличия от Nuxt UI

### ⚠️ FormField вместо FormGroup!

```vue
<!-- Nuxt UI (НЕ используйте!) -->
<UFormGroup label="Email">
  <UInput />
</UFormGroup>

<!-- Bitrix24 UI Kit (используйте!) -->
<B24FormField label="Email">
  <B24Input />
</B24FormField>
```

---

## 📚 Ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **B24Form** - см. строку 17044 в полной документации
- **B24FormField** - см. строку 17902 в полной документации
- **REST API**: https://apidocs.bitrix24.ru/

---

**Дата**: Октябрь 2025  
**Версия**: 2.14 (Bitrix24 UI Kit)  
**Компоненты**: B24Form, B24FormField (НЕ UForm/UFormGroup!)
