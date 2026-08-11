<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

const emit = defineEmits<{
  (e: 'finish'): void
}>()

let finishTimer: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  finishTimer = setTimeout(() => emit('finish'), 2500)
})

onUnmounted(() => {
  if (finishTimer) clearTimeout(finishTimer)
})
</script>

<template>
  <section class="setup-complete">
    <div class="setup-complete__panel">
      <div class="setup-complete__icon-wrap">
        <div class="setup-complete__icon">
          <svg class="setup-complete__check" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
        </div>
      </div>

      <h1 class="setup-complete__title">Готово!</h1>
      <p class="setup-complete__text">
        Интеграция с Точка Банком настроена. Платежи будут автоматически синхронизироваться с CRM.
      </p>

      <div class="setup-complete__summary">
        <h3 class="setup-complete__summary-title">Что будет происходить:</h3>
        <ul class="setup-complete__summary-list">
          <li>
            <span class="setup-complete__bullet">✓</span>
            <span>Входящие платежи будут автоматически импортироваться</span>
          </li>
          <li>
            <span class="setup-complete__bullet">✓</span>
            <span>Платежи будут привязываться к сделкам по номеру счёта</span>
          </li>
          <li>
            <span class="setup-complete__bullet">✓</span>
            <span>Статусы оплат будут обновляться автоматически</span>
          </li>
        </ul>
      </div>

      <div class="setup-complete__actions">
        <button type="button" class="setup-complete__btn" @click="$emit('finish')">
          Перейти к приложению
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.setup-complete {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 2rem 1rem 3rem;
}

.setup-complete__panel {
  display: block;
  width: 560px;
  max-width: 100%;
  margin: 0 auto;
  box-sizing: border-box;
  text-align: center;
}

.setup-complete__icon-wrap {
  display: flex;
  justify-content: center;
  margin-bottom: 1.5rem;
}

.setup-complete__icon {
  width: 5rem;
  height: 5rem;
  border-radius: 9999px;
  background: #dcfce7;
  display: flex;
  align-items: center;
  justify-content: center;
}

.setup-complete__check {
  width: 2.5rem;
  height: 2.5rem;
  color: #22c55e;
}

.setup-complete__title {
  display: block;
  width: 100%;
  font-size: 1.5rem;
  font-weight: 700;
  color: #111827;
  margin-bottom: 0.5rem;
}

.setup-complete__text {
  display: block;
  width: 100%;
  font-size: 0.875rem;
  color: #6b7280;
  line-height: 1.6;
  margin-bottom: 2rem;
  white-space: normal;
}

.setup-complete__summary {
  display: block;
  width: 100%;
  background: #f9fafb;
  border-radius: 0.75rem;
  padding: 1.5rem;
  margin-bottom: 2rem;
  text-align: left;
  box-sizing: border-box;
}

.setup-complete__summary-title {
  font-size: 0.875rem;
  font-weight: 600;
  color: #1f2937;
  margin-bottom: 1rem;
}

.setup-complete__summary-list {
  list-style: none;
  padding: 0;
  margin: 0;
  font-size: 0.875rem;
  color: #4b5563;
}

.setup-complete__summary-list li {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.setup-complete__summary-list li + li {
  margin-top: 0.75rem;
}

.setup-complete__bullet {
  color: #22c55e;
  flex-shrink: 0;
  margin-top: 0.125rem;
}

.setup-complete__actions {
  display: flex;
  justify-content: center;
  width: 100%;
}

.setup-complete__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem 2rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #fff;
  background: #8b5cf6;
  border: none;
  border-radius: 0.75rem;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.setup-complete__btn:hover {
  background: #7c3aed;
}
</style>
