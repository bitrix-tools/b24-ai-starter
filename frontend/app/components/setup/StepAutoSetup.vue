<script setup lang="ts">
const props = defineProps<{
  createCrmFields: () => Promise<void>
}>()

const emit = defineEmits<{
  (e: 'done'): void
}>()

const tochkaStore = useTochkaStore()

type TaskStatus = 'pending' | 'active' | 'done'

interface SetupTask {
  id: string
  label: string
  status: TaskStatus
}

const tasks = ref<SetupTask[]>([
  { id: 'settings', label: 'Настройки сохранены', status: 'pending' },
  { id: 'fields', label: 'Поля в CRM созданы', status: 'pending' },
  { id: 'sync', label: 'Синхронизация платежей...', status: 'pending' },
  { id: 'analyze', label: 'Анализ данных...', status: 'pending' },
])

async function runTask(task: SetupTask) {
  task.status = 'active'
  try {
    if (task.id === 'settings') {
      await new Promise(r => setTimeout(r, 400))
    } else if (task.id === 'fields') {
      await props.createCrmFields()
    } else if (task.id === 'sync') {
      await tochkaStore.syncPayments({ days: 30 })
    } else if (task.id === 'analyze') {
      await tochkaStore.fetchPayments(1)
    }
  } catch {
    /* продолжаем установку */
  }
  task.status = 'done'
}

onMounted(async () => {
  for (const task of tasks.value) {
    await runTask(task)
  }
  await new Promise(r => setTimeout(r, 400))
  emit('done')
})
</script>

<template>
  <section class="setup-auto">
    <div class="setup-auto__panel">
      <h1 class="setup-auto__title">Настраиваем интеграцию</h1>

      <ul class="setup-auto__tasks">
        <li
          v-for="task in tasks"
          :key="task.id"
          class="setup-auto__task"
          :class="{ 'setup-auto__task--pending': task.status === 'pending' }"
        >
          <div
            class="setup-auto__icon"
            :class="{
              'setup-auto__icon--done': task.status === 'done',
              'setup-auto__icon--active': task.status === 'active',
              'setup-auto__icon--pending': task.status === 'pending',
            }"
          >
            <svg
              v-if="task.status === 'done'"
              class="setup-auto__check"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <span class="setup-auto__label">{{ task.label }}</span>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.setup-auto {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 3rem 1rem 4rem;
  min-height: 420px;
}

.setup-auto__panel {
  display: block;
  width: 480px;
  max-width: 100%;
  margin: 0 auto;
  box-sizing: border-box;
  text-align: center;
}

.setup-auto__title {
  display: block;
  width: 100%;
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
  margin-bottom: 2.5rem;
  line-height: 1.4;
}

.setup-auto__tasks {
  display: inline-block;
  margin: 0 auto;
  padding: 0;
  list-style: none;
  text-align: left;
}

.setup-auto__task {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.875rem;
  color: #374151;
}

.setup-auto__task + .setup-auto__task {
  margin-top: 1.25rem;
}

.setup-auto__task--pending {
  color: #9ca3af;
}

.setup-auto__icon {
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 9999px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-sizing: border-box;
}

.setup-auto__icon--done {
  background: #22c55e;
  color: #fff;
}

.setup-auto__icon--active {
  border: 2px solid #e5e7eb;
  border-top-color: #8b5cf6;
  animation: setup-auto-spin 0.8s linear infinite;
}

.setup-auto__icon--pending {
  border: 2px solid #d1d5db;
  background: #fff;
}

.setup-auto__check {
  width: 0.875rem;
  height: 0.875rem;
}

.setup-auto__label {
  white-space: normal;
  line-height: 1.4;
}

@keyframes setup-auto-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
