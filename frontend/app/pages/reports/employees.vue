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
          <h1 class="text-2xl font-bold text-gray-900">Отчёт по сотрудникам</h1>
        </div>
        
        <div class="flex space-x-3">
          <button @click="fetchData" :disabled="isLoading" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors flex items-center">
            <svg v-if="isLoading" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isLoading ? 'Загрузка...' : 'Сформировать' }}
          </button>
          <button @click="handleExport" :disabled="isExporting" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors flex items-center">
            <svg v-if="isExporting" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            {{ isExporting ? 'Экспорт...' : 'Экспорт в Excel' }}
          </button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-6 grid grid-cols-1 md:grid-cols-4 gap-4">
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
          <label class="block text-sm font-medium text-gray-700 mb-1">Проект</label>
          <select v-model="filters.projectId" class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500 shadow-sm">
            <option value="">Все проекты</option>
            <option v-for="project in projects" :key="project.id" :value="project.id">
              {{ project.name }}
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
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Учитываемые</th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Неучитываемые</th>
                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Всего</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <template v-if="groupedData.length === 0 && !isLoading">
                <tr>
                  <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                    Нет данных для отображения. Нажмите "Сформировать".
                  </td>
                </tr>
              </template>
              
              <template v-for="employee in groupedData" :key="employee.key">
                <!-- Level 1: Employee -->
                <tr class="bg-gray-100 cursor-pointer hover:bg-gray-200 transition-colors" @click="toggle(employee.key)">
                  <td colspan="2" class="px-6 py-3 font-bold text-gray-900 flex items-center">
                    <button class="mr-2 text-gray-500 focus:outline-none">
                      <svg :class="{'transform rotate-90': expandedKeys.has(employee.key)}" class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                      </svg>
                    </button>
                    👤 Сотрудник: {{ employee.name }} (ID: {{ employee.id }})
                  </td>
                  <td class="px-6 py-3 text-right font-bold text-green-600">{{ employee.billableHours.toFixed(2) }} ч.</td>
                  <td class="px-6 py-3 text-right font-bold text-gray-600">{{ employee.nonBillableHours.toFixed(2) }} ч.</td>
                  <td class="px-6 py-3 text-right font-bold text-blue-600">{{ employee.totalHours.toFixed(2) }} ч.</td>
                  <td></td>
                </tr>

                <template v-if="expandedKeys.has(employee.key)">
                  <template v-for="project in employee.projects" :key="project.key">
                    <!-- Level 2: Project -->
                    <tr class="bg-gray-50 cursor-pointer hover:bg-gray-100 transition-colors" @click="toggle(project.key)">
                      <td colspan="2" class="px-6 py-2 pl-10 font-semibold text-gray-800 flex items-center">
                        <button class="mr-2 text-gray-500 focus:outline-none">
                          <svg :class="{'transform rotate-90': expandedKeys.has(project.key)}" class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                          </svg>
                        </button>
                        📁 Проект: {{ project.name }}
                      </td>
                      <td class="px-6 py-2 text-right font-semibold text-green-600">{{ project.billableHours.toFixed(2) }} ч.</td>
                      <td class="px-6 py-2 text-right font-semibold text-gray-600">{{ project.nonBillableHours.toFixed(2) }} ч.</td>
                      <td class="px-6 py-2 text-right font-semibold text-blue-600">{{ project.totalHours.toFixed(2) }} ч.</td>
                      <td></td>
                    </tr>

                    <template v-if="expandedKeys.has(project.key)">
                      <template v-for="task in project.tasks" :key="task.key">
                        <!-- Level 3: Task (Hierarchy) -->
                        <tr class="cursor-pointer hover:bg-gray-50 transition-colors" @click="toggle(task.key)">
                          <td colspan="2" class="px-6 py-2 pl-14 text-sm font-medium text-gray-700 flex items-center">
                             <button class="mr-2 text-gray-400 focus:outline-none">
                               <svg :class="{'transform rotate-90': expandedKeys.has(task.key)}" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                               </svg>
                             </button>
                             📝 {{ task.name }}
                             <span v-if="task.hierarchy.length > 0" class="text-xs text-gray-400 ml-2">
                               ({{ task.hierarchy.join(' > ') }})
                             </span>
                          </td>
                          <td class="px-6 py-2 text-right text-sm font-medium text-green-600">{{ task.billableHours.toFixed(2) }} ч.</td>
                          <td class="px-6 py-2 text-right text-sm font-medium text-gray-600">{{ task.nonBillableHours.toFixed(2) }} ч.</td>
                          <td class="px-6 py-2 text-right text-sm font-medium text-blue-600">{{ task.totalHours.toFixed(2) }} ч.</td>
                          <td></td>
                        </tr>

                        <!-- Level 4: Time Entries -->
                        <template v-if="expandedKeys.has(task.key)">
                          <tr v-for="entry in task.entries" :key="entry.id" class="hover:bg-blue-50 transition-colors">
                            <td class="px-6 py-2 pl-20 text-sm text-gray-600">
                              ⏱ {{ entry.entryTitle || 'Метка #' + entry.id }}
                            </td>
                            <td class="px-6 py-2 text-sm text-gray-500">{{ entry.taskId }}</td>
                            <td class="px-6 py-2 text-right text-sm" :class="entry.type === 'Учитываемые' ? 'text-green-600 font-medium' : 'text-gray-400'">
                              {{ entry.type === 'Учитываемые' ? entry.hours.toFixed(2) : '—' }}
                            </td>
                            <td class="px-6 py-2 text-right text-sm" :class="entry.type === 'Неучитываемые' ? 'text-gray-600 font-medium' : 'text-gray-400'">
                              {{ entry.type === 'Неучитываемые' ? entry.hours.toFixed(2) : '—' }}
                            </td>
                            <td class="px-6 py-2 text-right text-sm font-medium text-blue-600">
                              {{ entry.hours.toFixed(2) }}
                            </td>
                            <td class="px-6 py-2 text-sm text-gray-500">
                              {{ new Date(entry.date).toLocaleDateString() }}
                            </td>
                          </tr>
                        </template>
                      </template>
                    </template>
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
import { useExcelExport } from '~/composables/useExcelExport'

const { t, locales: localesI18n, setLocale } = useI18n()
const { initApp, processErrorGlobal } = useAppInit('EmployeesReport')
const { $initializeB24Frame } = useNuxtApp()

useHead({
  title: 'Отчёт по сотрудникам'
})

const store = useReportsStore()
const apiStore = useApiStore()
const { items, isLoading, error } = storeToRefs(store)

const users = ref<{ id: string, name: string }[]>([])
const projects = ref<{ id: string, name: string }[]>([])
const filters = ref({
  employeeId: '',
  projectId: '',
  dateFrom: '',
  dateTo: ''
})

onMounted(async () => {
  try {
    const $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    
    // Load users and projects for filter
    const [usersData, projectsData] = await Promise.all([
      apiStore.getUsers(),
      apiStore.getProjects()
    ])
    users.value = usersData.items
    projects.value = projectsData.items
    
    // Debug schema (temporary)
    const schema = await apiStore.getDebugSchema()
    console.log('Smart Process Schema:', schema)
  } catch (e) {
    console.error('Failed to initialize Bitrix24 frame or load data:', e)
    processErrorGlobal(e)
  }
})

const fetchData = () => {
  store.fetchReports(filters.value)
}

const { isExporting, exportReport } = useExcelExport()

const handleExport = () => {
  exportReport('employees', filters.value)
}

// Accordion Logic
const expandedKeys = reactive(new Set<string>())
const toggle = (key: string) => {
  if (expandedKeys.has(key)) {
    expandedKeys.delete(key)
  } else {
    expandedKeys.add(key)
  }
}

// Grouping Logic with Hours Calculation
const groupedData = computed(() => {
  if (!items.value.length) return []

  // Create employee name mapping from users
  const userMap = new Map<string, string>()
  console.log('EmployeesReport: Users loaded:', users.value.length, users.value)
  
  users.value.forEach(user => {
    // Ensure ID is string for consistent mapping
    userMap.set(String(user.id), user.name)
  })
  
  // Create project map
  const projectMap = new Map<string, string>()
  projects.value.forEach(p => {
    projectMap.set(String(p.id), p.name)
    // Also map by name just in case
    projectMap.set(p.name, p.name)
  })
  
  console.log('EmployeesReport: User map created:', Object.fromEntries(userMap))

  const employeesMap = new Map<string, any>()

  items.value.forEach(item => {
    const empId = item.employeeId ? String(item.employeeId) : 'unknown'
    // Use employeeName from backend if available, otherwise use mapped name, finally fallback to ID
    const mappedName = userMap.get(empId)
    const empName = item.employeeName || mappedName || `User ${empId}`
    
    // Debug first few items
    if (employeesMap.size < 3) {
        console.log(`EmployeesReport: Mapping item ${item.id}: empId=${empId}, backendName=${item.employeeName}, mappedName=${mappedName}, final=${empName}`)
    }

    if (!employeesMap.has(empId)) {
      employeesMap.set(empId, { 
        key: `emp_${empId}`,
        id: empId, 
        name: empName, 
        projects: new Map(),
        billableHours: 0,
        nonBillableHours: 0,
        totalHours: 0
      })
    }
    const emp = employeesMap.get(empId)

    // Use projectId for grouping if available, otherwise use projectName
    const projKey = item.projectId || item.projectName || 'Не определён'
    // Try to find project name in map by ID, then by name, then fallback to item.projectName
    const mappedProjName = item.projectId ? projectMap.get(String(item.projectId)) : null
    const projName = mappedProjName || item.projectName || 'Не определён'
    
    if (!emp.projects.has(projKey)) {
      emp.projects.set(projKey, { 
        key: `emp_${empId}_proj_${projKey}`,
        name: projName, 
        tasks: new Map(),
        billableHours: 0,
        nonBillableHours: 0,
        totalHours: 0
      })
    }
    const proj = emp.projects.get(projKey)

    const taskId = item.taskId
    if (!proj.tasks.has(taskId)) {
      proj.tasks.set(taskId, {
        key: `emp_${empId}_proj_${projKey}_task_${taskId}`,
        id: taskId,
        name: item.taskTitle || item.taskName,
        hierarchy: item.hierarchyTitles.slice(0, -1),
        entries: [],
        billableHours: 0,
        nonBillableHours: 0,
        totalHours: 0
      })
    }
    const task = proj.tasks.get(taskId)
    task.entries.push(item)
    
    // Calculate hours
    const hours = item.hours || 0
    const isBillable = item.type === 'Учитываемые'
    
    if (isBillable) {
      task.billableHours += hours
      proj.billableHours += hours
      emp.billableHours += hours
    } else {
      task.nonBillableHours += hours
      proj.nonBillableHours += hours
      emp.nonBillableHours += hours
    }
    
    task.totalHours += hours
    proj.totalHours += hours
    emp.totalHours += hours
  })

  // Convert Maps to Arrays for template
  return Array.from(employeesMap.values()).map((emp: any) => ({
    ...emp,
    projects: Array.from(emp.projects.values()).map((proj: any) => ({
      ...proj,
      tasks: Array.from(proj.tasks.values())
    }))
  }))
})
</script>
