<template>
  <div class="min-h-screen bg-gray-50 p-6">
    <div class="max-w-full mx-auto">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div class="flex items-center space-x-4">
          <NuxtLink to="/" class="p-2 rounded-lg hover:bg-gray-200 text-gray-600 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </NuxtLink>
          <h1 class="text-2xl font-bold text-gray-900">Ежемесячный табель</h1>
        </div>
        
        <div class="flex space-x-3">
          <button @click="fetchData" :disabled="isLoading" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 transition-colors flex items-center">
            <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isLoading ? 'Загрузка...' : 'Сформировать' }}
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Месяц</label>
          <input v-model="filters.dateFrom" type="date" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm" />
          <p class="text-xs text-gray-500 mt-1">Выберите любую дату месяца</p>
        </div>
        <!-- Hidden To Date, calculated automatically -->
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 border border-red-100">
        {{ error }}
      </div>

      <!-- Calendar Table -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 border-collapse">
          <thead class="bg-gray-50">
            <tr>
              <th rowspan="2" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider sticky left-0 bg-gray-50 z-10 border-r">Сотрудник</th>
              <th colspan="3" class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-b">Сводка</th>
              <th :colspan="daysInMonth.length" class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase tracking-wider border-b">
                {{ monthName }}
              </th>
            </tr>
            <tr>
              <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 border-r">Учтено</th>
              <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 border-r">Не учт.</th>
              <th class="px-2 py-2 text-center text-xs font-medium text-gray-500 border-r font-bold">Всего</th>
              
              <th v-for="day in daysInMonth" :key="day.date" 
                  class="px-1 py-2 text-center text-xs font-medium text-gray-500 min-w-[30px]"
                  :class="{'bg-red-50 text-red-600': day.isWeekend}">
                {{ day.day }}
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="row in timesheetData" :key="row.employeeId" class="hover:bg-gray-50">
              <td class="px-4 py-2 text-sm font-medium text-gray-900 sticky left-0 bg-white z-10 border-r">
                {{ row.employeeName }}
              </td>
              <td class="px-2 py-2 text-center text-sm text-green-600 border-r bg-green-50">{{ row.totalBillable.toFixed(1) }}</td>
              <td class="px-2 py-2 text-center text-sm text-gray-500 border-r">{{ row.totalNonBillable.toFixed(1) }}</td>
              <td class="px-2 py-2 text-center text-sm font-bold text-gray-900 border-r">{{ row.total.toFixed(1) }}</td>

              <td v-for="day in daysInMonth" :key="day.date" 
                  class="px-1 py-2 text-center text-sm border-r cursor-pointer hover:bg-blue-100 transition-colors relative group"
                  :class="{'bg-red-50': day.isWeekend}"
                  @click="openDetails(row.employeeId, day.date)">
                
                <span v-if="row.days[day.date]" :class="{'font-bold text-blue-600': row.days[day.date] > 0}">
                  {{ row.days[day.date].toFixed(1) }}
                </span>
                <span v-else class="text-gray-300">-</span>

                <!-- Simple Tooltip -->
                <div v-if="row.days[day.date]" class="hidden group-hover:block absolute bottom-full left-1/2 transform -translate-x-1/2 bg-gray-800 text-white text-xs rounded px-2 py-1 mb-1 whitespace-nowrap z-20">
                  Нажмите для деталей
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Details Modal -->
      <!-- Details Modal -->
      <div v-if="selectedCell" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="selectedCell = null">
        <div class="bg-white rounded-xl shadow-xl p-4 mx-4 max-w-lg w-full max-h-[80vh] overflow-y-auto">
          <div class="flex justify-between items-center mb-4">
            <h3 class="text-base font-bold text-gray-900">
              {{ selectedCell.employeeName }}<br>
              <span class="text-sm font-normal text-gray-500">{{ new Date(selectedCell.date).toLocaleDateString() }}</span>
            </h3>
            <button @click="selectedCell = null" class="text-gray-500 hover:text-gray-700 bg-gray-100 p-1 rounded-full">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          
          <div class="space-y-4">
            <div v-for="entry in selectedCell.entries" :key="entry.id" class="border-b pb-2 last:border-0">
              <div class="flex justify-between items-start">
                <div>
                  <p class="font-medium text-gray-900">{{ entry.entryTitle || 'Метка #' + entry.id }}</p>
                  <p class="text-xs text-gray-600">{{ entry.taskTitle || entry.taskName }}</p>
                  <p class="text-xs text-gray-500">{{ entry.projectName }}</p>
                </div>
                <div class="text-right">
                  <span class="font-bold text-blue-600">{{ entry.hours }} ч.</span>
                  <p class="text-xs text-gray-400">{{ entry.type }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { useReportsStore } from '~/stores/reports'
import { storeToRefs } from 'pinia'
import type { B24Frame } from '@bitrix24/b24jssdk'

const { t, locales: localesI18n, setLocale } = useI18n()
const { initApp, processErrorGlobal } = useAppInit('TimesheetReport')
const { $initializeB24Frame } = useNuxtApp()

useHead({
  title: 'Ежемесячный табель'
})

const store = useReportsStore()
const apiStore = useApiStore()
const { items, isLoading, error } = storeToRefs(store)

const users = ref<{ id: string, name: string }[]>([])

onMounted(async () => {
  try {
    const $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    
    // Load users for mapping
    const usersData = await apiStore.getUsers()
    users.value = usersData.items
  } catch (e) {
    console.error('Failed to initialize Bitrix24 frame:', e)
    processErrorGlobal(e)
  }
})

const filters = ref({
  dateFrom: new Date().toISOString().split('T')[0], // Default to today
  dateTo: ''
})

// Helper to get days in month
interface DayInfo {
  day: number
  date: string
  isWeekend: boolean
}

const daysInMonth = computed<DayInfo[]>(() => {
  if (!filters.value.dateFrom) return []
  
  const date = new Date(filters.value.dateFrom)
  const year = date.getFullYear()
  const month = date.getMonth()
  
  const days: DayInfo[] = []
  const lastDay = new Date(year, month + 1, 0).getDate()
  
  for (let i = 1; i <= lastDay; i++) {
    const d = new Date(year, month, i)
    days.push({
      day: i,
      date: d.toISOString().split('T')[0],
      isWeekend: d.getDay() === 0 || d.getDay() === 6
    })
  }
  return days
})

const monthName = computed(() => {
  if (!filters.value.dateFrom) return ''
  return new Date(filters.value.dateFrom).toLocaleString('ru-RU', { month: 'long', year: 'numeric' })
})

const fetchData = () => {
  if (!filters.value.dateFrom) return

  // Calculate start and end of month
  const date = new Date(filters.value.dateFrom)
  const year = date.getFullYear()
  const month = date.getMonth()
  
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0) // Last day of current month

  // Update filters for API
  const apiFilters = {
    dateFrom: firstDay.toISOString(),
    dateTo: lastDay.toISOString()
  }
  
  store.fetchReports(apiFilters)
}

// Timesheet Data Processing
interface EmployeeData {
  employeeId: string
  employeeName: string
  totalBillable: number
  totalNonBillable: number
  total: number
  days: Record<string, number>
  entries: Record<string, any[]>
}

const timesheetData = computed(() => {
  if (!items.value.length) return []

  // Create employee name mapping from users
  const userMap = new Map<string, string>()
  users.value.forEach(user => {
    userMap.set(String(user.id), user.name)
  })

  const employeesMap = new Map<string, EmployeeData>()

  items.value.forEach(item => {
    const empId = item.employeeId ? String(item.employeeId) : 'unknown'
    // Use employeeName from backend if available, otherwise use mapped name, finally fallback to ID
    const empName = item.employeeName || userMap.get(empId) || `User ${empId}`
    
    if (!employeesMap.has(empId)) {
      employeesMap.set(empId, {
        employeeId: empId,
        employeeName: empName,
        totalBillable: 0,
        totalNonBillable: 0,
        total: 0,
        days: {}, // date -> hours
        entries: {} // date -> [items]
      })
    }
    
    const emp = employeesMap.get(empId)!
    const dateKey = item.date.split('T')[0] // Assuming ISO string from backend
    
    // Sum hours
    const hours = Number(item.hours)
    emp.total += hours
    if (item.type === 'Учитываемые') {
      emp.totalBillable += hours
    } else {
      emp.totalNonBillable += hours
    }

    // Day sum
    if (!emp.days[dateKey]) emp.days[dateKey] = 0
    emp.days[dateKey] += hours

    // Store entries for popup
    if (!emp.entries[dateKey]) emp.entries[dateKey] = []
    emp.entries[dateKey].push(item)
  })

  return Array.from(employeesMap.values())
})

// Modal Logic
interface TimesheetCellDetails {
  employeeName: string
  date: string
  entries: any[]
}
const selectedCell = ref<TimesheetCellDetails | null>(null)

const openDetails = (employeeId: string, date: string) => {
  const emp = timesheetData.value.find(e => e.employeeId === employeeId)
  if (emp && emp.entries[date]) {
    selectedCell.value = {
      employeeName: emp.employeeName,
      date: date,
      entries: emp.entries[date]
    }
  }
}
</script>
