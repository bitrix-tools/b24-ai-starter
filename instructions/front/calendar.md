# Calendar: Календарная сетка с событиями

> **Встраивание в проект.** Примеры ниже — фрагменты. Готовую страницу кладите в `frontend/app/pages/<name>.client.vue` и стройте по «Шаблону страницы» из [knowledge.md](./knowledge.md): `useAppInit('Name')` → `$initializeB24Frame()` → `initApp($b24, localesI18n, setLocale)` в `onMounted` с `try/catch` + `processErrorGlobal`, данные — через `$b24.actions.v2.*.make()` или методы `useApiStore()` (не `fetch`/`$fetch`), логи — `$logger`. **Не оборачивайте** разметку в `<B24App>` / `<B24DashboardGroup>` — они уже есть в `app/app.vue` и `layouts/`; корень страницы — `<B24DashboardPanel>` (шапка `B24DashboardNavbar` в `#header`, фрагмент — в `#body`). Строки интерфейса выносите в `frontend/i18n/locales/*.json` и выводите через `$t('…')` (здесь они оставлены по-русски для краткости). `vue`-API, сторы и composables импортируются автоматически.

> **⚠️ ВАЖНО ДЛЯ ИИ АГЕНТОВ**: Используйте компоненты **B24*** из Bitrix24 UI Kit для создания календарной сетки!

## 📋 Описание

**Calendar Grid** - кастомная календарная сетка для отображения событий и задач.

### Применение

- 📅 События календаря
- ✅ Задачи с датами
- 📊 График работ
- 🤝 Расписание встреч

---

## 🎯 Базовый пример

```vue
<template>
  <div class="calendar-grid">
    <div class="grid grid-cols-7 gap-2">
      <div v-for="day in weekDays" :key="day" class="text-center font-semibold">
        {{ day }}
      </div>
    </div>

    <div class="grid grid-cols-7 gap-2 mt-2">
      <div
        v-for="day in calendarDays"
        :key="day.key"
        class="min-h-24 p-2 border rounded-lg"
      >
        <div class="font-semibold">{{ day.date.getDate() }}</div>
        <div v-for="event in day.events" :key="event.id" 
             class="text-xs p-1 bg-blue-100 rounded mt-1">
          {{ event.name }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']
const calendarDays = computed(() => {
  // Генерация дней месяца
  return []
})
</script>
```

---

## 💼 Полный пример

```vue
<template>
  <B24Container class="py-8">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2">
        <B24Card>
          <template #header>
            <div class="flex items-center justify-between">
              <ProseH3 class="text-lg font-semibold">{{ monthName }} {{ currentYear }}</ProseH3>
              <div class="flex gap-2">
                <B24Button :icon="ChevronLeftIcon" size="sm" color="air-tertiary" 
                           @click="previousMonth" />
                <B24Button size="sm" color="air-secondary-no-accent" @click="goToToday">
                  Сегодня
                </B24Button>
                <B24Button :icon="ChevronRightIcon" size="sm" color="air-tertiary" 
                           @click="nextMonth" />
              </div>
            </div>
          </template>

          <div class="p-4">
            <div class="grid grid-cols-7 gap-2 mb-2">
              <div v-for="day in weekDays" :key="day" 
                   class="text-center text-sm font-semibold text-gray-600 py-2">
                {{ day }}
              </div>
            </div>

            <div class="grid grid-cols-7 gap-2">
              <div
                v-for="day in calendarDays"
                :key="day.key"
                :class="[
                  'min-h-24 p-2 border rounded-lg cursor-pointer',
                  day.isCurrentMonth ? 'bg-white' : 'bg-gray-50',
                  day.isToday ? 'ring-2 ring-primary' : ''
                ]"
                @click="selectDay(day)"
              >
                <div :class="['text-sm font-semibold', day.isToday ? 'text-primary' : '']">
                  {{ day.date.getDate() }}
                </div>
                <div class="space-y-1 mt-1">
                  <div
                    v-for="event in day.events"
                    :key="event.id"
                    class="text-xs px-2 py-1 bg-blue-100 rounded truncate"
                    @click.stop="openEvent(event)"
                  >
                    {{ event.name }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </B24Card>
      </div>

      <div>
        <B24Card>
          <template #header>
            <ProseH3 class="text-lg font-semibold">События ({{ events.length }})</ProseH3>
          </template>

          <div v-if="events.length === 0" class="text-center py-8 text-gray-500">
            Нет событий
          </div>

          <div v-else class="space-y-3 p-4">
            <div
              v-for="event in sortedEvents"
              :key="event.id"
              class="p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
              @click="openEvent(event)"
            >
              <p class="font-medium">{{ event.name }}</p>
              <p class="text-sm text-gray-600">{{ formatDate(event.date) }}</p>
            </div>
          </div>
        </B24Card>
      </div>
    </div>

    <B24Modal v-model:open="showModal">
      <template #content>
        <B24Card v-if="selectedEvent">
          <template #header>
            <ProseH3 class="text-lg font-semibold">{{ selectedEvent.name }}</ProseH3>
          </template>
          <div class="space-y-3 p-4">
            <p>{{ selectedEvent.description }}</p>
            <p class="text-sm text-gray-600">{{ formatDate(selectedEvent.date) }}</p>
          </div>
        </B24Card>
      </template>
    </B24Modal>
  </B24Container>
</template>

<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import ChevronLeftIcon from '@bitrix24/b24icons-vue/actions/ChevronToTheLeftIcon'
import ChevronRightIcon from '@bitrix24/b24icons-vue/actions/ChevronToTheRightIcon'

interface CalendarEvent {
  id: number
  name: string
  description: string
  date: string // ISO 8601
}

interface CalendarDay {
  key: string
  date: Date
  isCurrentMonth: boolean
  isToday: boolean
  events: CalendarEvent[]
}

const { locale, locales: localesI18n, setLocale } = useI18n()
const { $logger, initApp, processErrorGlobal } = useAppInit('CalendarPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null
const apiStore = useApiStore()

const currentDate = ref(new Date())
const events = ref<CalendarEvent[]>([])
const selectedEvent = ref<CalendarEvent | null>(null)
const showModal = ref(false)
const weekDays = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

const currentYear = computed(() => currentDate.value.getFullYear())
const currentMonth = computed(() => currentDate.value.getMonth())
const monthName = computed(() =>
  currentDate.value.toLocaleDateString(locale.value, { month: 'long' })
)

const calendarDays = computed<CalendarDay[]>(() => {
  const year = currentYear.value
  const month = currentMonth.value
  const lastDay = new Date(year, month + 1, 0)
  const days: CalendarDay[] = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  for (let i = 1; i <= lastDay.getDate(); i++) {
    const date = new Date(year, month, i)
    const dayEvents = events.value.filter((event) => {
      const eventDate = new Date(event.date)
      return eventDate.toDateString() === date.toDateString()
    })

    days.push({
      key: `day-${i}`,
      date,
      isCurrentMonth: true,
      isToday: date.getTime() === today.getTime(),
      events: dayEvents
    })
  }

  return days
})

const sortedEvents = computed(() => {
  return [...events.value].sort((a, b) =>
    new Date(a.date).getTime() - new Date(b.date).getTime()
  )
})

const loadEvents = async () => {
  try {
    // Метод своего бэкенда: добавьте getEvents(year, month): Promise<CalendarEvent[]>
    // в app/stores/api.ts (по образцу getList). Для событий календаря Bitrix24 можно
    // вызвать $b24.actions.v2.call.make({ method: 'calendar.event.get', params: { ... } })
    // и привести DATE_FROM к ISO.
    events.value = await apiStore.getEvents(currentYear.value, currentMonth.value + 1)
  } catch (error) {
    processErrorGlobal(error)
  }
}

const previousMonth = () => {
  currentDate.value = new Date(currentYear.value, currentMonth.value - 1, 1)
}

const nextMonth = () => {
  currentDate.value = new Date(currentYear.value, currentMonth.value + 1, 1)
}

const goToToday = () => {
  currentDate.value = new Date()
}

const selectDay = (day: CalendarDay) => {
  $logger.debug('Selected', { date: day.date })
}

const openEvent = (event: CalendarEvent) => {
  selectedEvent.value = event
  showModal.value = true
}

const formatDate = (dateString: string) => {
  return new Date(dateString).toLocaleString(locale.value)
}

watch([currentYear, currentMonth], () => {
  loadEvents()
})

onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle('Календарь')
    await loadEvents()
  } catch (error) {
    processErrorGlobal(error)
  }
})
</script>
```

---

## 📚 Ресурсы

- **Bitrix24 UI Kit документация**: https://bitrix24.github.io/b24ui/llms-full.txt
- **Bitrix24 UI Kit**: https://bitrix24.github.io/
- **Calendar REST API**: https://apidocs.bitrix24.ru/calendar/

---

**Дата**: Октябрь 2025  
**Версия**: 2.14 (Bitrix24 UI Kit)  
**Компоненты**: B24* (Calendar Grid - кастомный)
