<script setup lang="ts">
import { ref } from 'vue';
import { B24Button, B24TextField, B24DatePicker } from '@bitrix24/b24ui-nuxt';

const { t } = useI18n();

// Filters
const employee = ref('');
const project = ref('');
const date = ref(null);

// Report data
const reportData = ref(null);
const isLoading = ref(false);

const generateReport = async () => {
  isLoading.value = true;
  // TODO: Fetch data from the backend
  // For now, we'll just simulate a delay and show mock data
  await new Promise(resolve => setTimeout(resolve, 1000));
  reportData.value = {
    message: 'Это тестовые данные. Здесь будет результат из API.',
    filters: {
      employee: employee.value,
      project: project.value,
      date: date.value,
    },
  };
  isLoading.value = false;
};
</script>

<template>
  <div class="p-4">
    <h1 class="text-2xl font-bold mb-4">{{ t('reports.report1.title') }}</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
      <B24TextField
        v-model="employee"
        :label="t('reports.report1.filters.employee')"
        />
      <B24TextField
        v-model="project"
        :label="t('reports.report1.filters.project')"
      />
      <B24DatePicker
        v-model="date"
        :label="t('reports.report1.filters.date')"
      />
    </div>

    <B24Button @click="generateReport" :loading="isLoading">
      {{ t('reports.report1.generate') }}
    </B24Button>

    <div v-if="reportData" class="mt-4 p-4 bg-gray-100 rounded">
      <h2 class="text-xl font-bold mb-2">{{ t('reports.report1.results') }}</h2>
      <pre>{{ JSON.stringify(reportData, null, 2) }}</pre>
    </div>
  </div>
</template>

<i18n lang="json">
{
  "ru": {
    "reports": {
      "report1": {
        "title": "Отчет №1: Метки времени по сотрудникам и проектам",
        "filters": {
          "employee": "Сотрудник",
          "project": "Проект/Группа",
          "date": "Дата"
        },
        "generate": "Сформировать",
        "results": "Результаты отчета"
      }
    }
  },
  "en": {
    "reports": {
      "report1": {
        "title": "Report #1: Timestamps by Employees and Projects",
        "filters": {
          "employee": "Employee",
          "project": "Project/Group",
          "date": "Date"
        },
        "generate": "Generate",
        "results": "Report Results"
      }
    }
  }
}
</i18n>
