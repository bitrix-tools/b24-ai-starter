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
          <h1 class="text-2xl font-bold text-gray-900">Отчёт по проектам</h1>
        </div>
        
        <div class="flex space-x-3">
          <button @click="fetchData" :disabled="isLoading" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors flex items-center">
            <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isLoading ? 'Загрузка...' : 'Сформировать' }}
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Проект</label>
          <input v-model="filters.projectName" type="text" placeholder="Название проекта" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Сотрудник</label>
          <select v-model="filters.employeeId" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm">
            <option value="">Все сотрудники</option>
            <option v-for="user in users" :key="user.id" :value="user.id">
              {{ user.name }}
            </option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Дата с</label>
          <input v-model="filters.dateFrom" type="date" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Дата по</label>
          <input v-model="filters.dateTo" type="date" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm" />
        </div>
      </div>

      <!-- Stats -->
      <div v-if="items.length > 0" class="mb-4 text-sm text-gray-600">
        В отчёт попало <span class="font-bold text-gray-900">{{ items.length }}</span> временных меток.
      </div>

      <!-- Error -->
      <div v-if="error" class="bg-red-50 text-red-700 p-4 rounded-xl mb-6 border border-red-100">
        {{ error }}
      </div>

      <!-- Table -->
      <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название задачи / Метка</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID задачи</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Часы</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Тип</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <template v-if="groupedData.length === 0 && !isLoading">
                <tr>
                  <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                    Нет данных для отображения. Нажмите "Сформировать".
                  </td>
                </tr>
              </template>
              
              <template v-for="project in groupedData" :key="project.name">
                <!-- Level 1: Project -->
                <tr class="bg-gray-100">
                  <td colspan="5" class="px-6 py-3 font-bold text-gray-900">
                    📁 Проект: {{ project.name }}
                  </td>
                </tr>

                <template v-for="employee in project.employees" :key="employee.id">
                  <!-- Level 2: Employee -->
                  <tr class="bg-gray-50">
                    <td colspan="5" class="px-6 py-2 pl-10 font-semibold text-gray-800">
                      👤 Сотрудник: {{ employee.name }} (ID: {{ employee.id }})
                    </td>
                  </tr>

                  <template v-for="task in employee.tasks" :key="task.id">
                    <!-- Level 3: Task (Hierarchy) -->
                    <tr>
                      <td colspan="5" class="px-6 py-2 pl-14 text-sm font-medium text-gray-700">
                         📝 {{ task.name }}
                         <span v-if="task.hierarchy.length > 0" class="text-xs text-gray-400 ml-2">
                           ({{ task.hierarchy.join(' > ') }})
                         </span>
                      </td>
                    </tr>

                    <!-- Level 4: Time Entries -->
                    <tr v-for="entry in task.entries" :key="entry.id" class="hover:bg-green-50 transition-colors">
                      <td class="px-6 py-2 pl-20 text-sm text-gray-600">
                        ⏱ Метка #{{ entry.id }}
                      </td>
                      <td class="px-6 py-2 text-sm text-gray-500">{{ entry.taskId }}</td>
                      <td class="px-6 py-2 text-sm font-medium" :class="entry.type === 'Учитываемые' ? 'text-green-600' : 'text-gray-500'">
                        {{ entry.hours }} ч.
                      </td>
                      <td class="px-6 py-2 text-sm text-gray-500">
                        <span class="px-2 py-1 rounded-full text-xs" :class="entry.type === 'Учитываемые' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                          {{ entry.type }}
                        </span>
                      </td>
                      <td class="px-6 py-2 text-sm text-gray-500">
                        {{ new Date(entry.date).toLocaleDateString() }}
                      </td>
                    </tr>
                  </template>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useReportsStore } from '~/stores/reports'
import { useApiStore } from '~/stores/api'
import { storeToRefs } from 'pinia'
import type { B24Frame } from '@bitrix24/b24jssdk'

const { t, locales: localesI18n, setLocale } = useI18n()
const { initApp, processErrorGlobal } = useAppInit('ProjectsReport')
const { $initializeB24Frame } = useNuxtApp()

useHead({
  title: 'Отчёт по проектам'
})

const store = useReportsStore()
const apiStore = useApiStore()
const { items, isLoading, error } = storeToRefs(store)

const users = ref<{ id: string, name: string }[]>([])
const filters = ref({
  employeeId: '',
  projectName: '',
  dateFrom: '',
  dateTo: ''
})

onMounted(async () => {
  try {
    const $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    
    // Load users for filter
    const usersData = await apiStore.getUsers()
    users.value = usersData.items
  } catch (e) {
    console.error('Failed to initialize Bitrix24 frame or load data:', e)
    processErrorGlobal(e)
  }
})

const fetchData = () => {
  store.fetchReports(filters.value)
}

// Grouping Logic for Project -> Employee -> Task
const groupedData = computed(() => {
  if (!items.value.length) return []

  const projectsMap = new Map<string, any>()

  items.value.forEach(item => {
    const projName = item.projectName || 'Не определён'
    
    if (!projectsMap.has(projName)) {
      projectsMap.set(projName, { name: projName, employees: new Map() })
    }
    const proj = projectsMap.get(projName)

    const empId = item.employeeId || 'unknown'
    const empName = item.employeeId ? `User ${item.employeeId}` : 'Неизвестный'

    if (!proj.employees.has(empId)) {
      proj.employees.set(empId, { id: empId, name: empName, tasks: new Map() })
    }
    const emp = proj.employees.get(empId)

    const taskId = item.taskId
    if (!emp.tasks.has(taskId)) {
      emp.tasks.set(taskId, {
        id: taskId,
        name: item.taskName,
        hierarchy: item.hierarchyTitles.slice(0, -1),
        entries: []
      })
    }
    const task = emp.tasks.get(taskId)
    task.entries.push(item)
  })

  // Convert Maps to Arrays
  return Array.from(projectsMap.values()).map((proj: any) => ({
    ...proj,
    employees: Array.from(proj.employees.values()).map((emp: any) => ({
      ...emp,
      tasks: Array.from(emp.tasks.values())
    }))
  }))
})
</script>
