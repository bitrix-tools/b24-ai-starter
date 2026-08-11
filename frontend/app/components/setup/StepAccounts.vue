<script setup lang="ts">
interface AccountSettings {
  prefix: string
  suffix: string
}

const emit = defineEmits<{
  (e: 'next'): void
}>()

const tochkaStore = useTochkaStore()

const isLoading = ref(true)
const selectedAccounts = ref<Map<string, AccountSettings>>(new Map())
const expandedAccounts = ref<Set<string>>(new Set())

onMounted(async () => {
  if (tochkaStore.status.connected) {
    await tochkaStore.fetchAccounts()
    for (const acc of tochkaStore.accounts) {
      selectedAccounts.value.set(acc.accountId, { prefix: '', suffix: '' })
      await tochkaStore.fetchBalances(acc.accountId)
    }
  }
  isLoading.value = false
})

function toggleAccount(accountId: string) {
  if (selectedAccounts.value.has(accountId)) {
    selectedAccounts.value.delete(accountId)
    expandedAccounts.value.delete(accountId)
  } else {
    selectedAccounts.value.set(accountId, { prefix: '', suffix: '' })
    expandedAccounts.value.add(accountId)
  }
}

function toggleExpanded(accountId: string, e: Event) {
  e.stopPropagation()
  if (expandedAccounts.value.has(accountId)) {
    expandedAccounts.value.delete(accountId)
  } else {
    expandedAccounts.value.add(accountId)
  }
}

function getSettings(accountId: string): AccountSettings {
  return selectedAccounts.value.get(accountId) ?? { prefix: '', suffix: '' }
}

function updatePrefix(accountId: string, value: string) {
  const s = selectedAccounts.value.get(accountId)
  if (s) selectedAccounts.value.set(accountId, { ...s, prefix: value })
}

function updateSuffix(accountId: string, value: string) {
  const s = selectedAccounts.value.get(accountId)
  if (s) selectedAccounts.value.set(accountId, { ...s, suffix: value })
}

function formatDisplayNumber(accountId: string): string {
  const s = getSettings(accountId)
  const pre = s.prefix ? `${s.prefix}` : ''
  const suf = s.suffix ? `${s.suffix}` : ''
  return pre ? `${pre}${accountId}${suf}` : suf ? `${accountId}${suf}` : accountId
}

function formatAmount(value: string, currency: string): string {
  const num = parseFloat(value)
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: currency || 'RUB',
    minimumFractionDigits: 2,
  }).format(num)
}

function getBalance(accountId: string) {
  const balances = tochkaStore.balances[accountId] || []
  const b = balances.find((b: any) => b.type === 'ClosingAvailable' || b.type === 'OpeningAvailable')
  return b?.amount || null
}

function handleNext() {
  const settings: Record<string, AccountSettings> = {}
  selectedAccounts.value.forEach((s, id) => { settings[id] = s })
  tochkaStore.setSelectedAccountSettings(settings)
  emit('next')
}
</script>

<template>
  <section class="setup-accounts">
    <div class="setup-accounts__panel">
      <h1 class="setup-accounts__title">Выберите счета для синхронизации</h1>
      <p class="setup-accounts__text">
        Отметьте расчётные счета, на которые приходят платежи от клиентов
      </p>

      <div v-if="isLoading" class="setup-accounts__loading">
        <div class="setup-accounts__spinner" />
      </div>

      <div v-else-if="!tochkaStore.status.connected" class="setup-accounts__empty">
        <p class="setup-accounts__empty-title">Точка Банк не подключен</p>
        <p class="setup-accounts__empty-text">Обновите страницу или начните подключение заново</p>
      </div>

      <div v-else-if="tochkaStore.accounts.length > 0" class="setup-accounts__list">
        <div
          v-for="account in tochkaStore.accounts"
          :key="account.accountId"
          class="setup-accounts__card"
          :class="{ 'setup-accounts__card--selected': selectedAccounts.has(account.accountId) }"
        >
          <div
            class="setup-accounts__card-row"
            @click="toggleAccount(account.accountId)"
          >
            <div
              class="setup-accounts__checkbox"
              :class="{ 'setup-accounts__checkbox--checked': selectedAccounts.has(account.accountId) }"
            >
              <svg
                v-if="selectedAccounts.has(account.accountId)"
                class="setup-accounts__check-icon"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
              </svg>
            </div>

            <div class="setup-accounts__info">
              <div class="setup-accounts__account-id">
                {{ account.accountId }}{{ account.accountId?.includes('/') ? '' : ` / ${account.accountType || ''}` }}
              </div>
              <div class="setup-accounts__meta">
                <span>{{ account.currency }}</span>
                <span>{{ account.description || account.accountType }}</span>
              </div>
              <div
                v-if="selectedAccounts.has(account.accountId)"
                class="setup-accounts__display-number"
                :title="formatDisplayNumber(account.accountId)"
              >
                {{ formatDisplayNumber(account.accountId) }}
              </div>
            </div>

            <div class="setup-accounts__balance">
              <div v-if="getBalance(account.accountId)" class="setup-accounts__balance-amount">
                {{ formatAmount(getBalance(account.accountId).amount, getBalance(account.accountId).currency) }}
              </div>
              <div class="setup-accounts__balance-currency">{{ account.currency }}</div>
            </div>
          </div>

          <div
            v-if="selectedAccounts.has(account.accountId)"
            class="setup-accounts__extra"
            @click.stop
          >
            <button
              type="button"
              class="setup-accounts__extra-toggle"
              @click="toggleExpanded(account.accountId, $event)"
            >
              Дополнительно
              <span>{{ expandedAccounts.has(account.accountId) ? '▲' : '▼' }}</span>
            </button>
            <div v-show="expandedAccounts.has(account.accountId)" class="setup-accounts__extra-fields">
              <div>
                <label class="setup-accounts__label">Префикс номера счёта</label>
                <input
                  :value="getSettings(account.accountId).prefix"
                  type="text"
                  placeholder="И, СЧ-, или пусто"
                  class="setup-accounts__input"
                  @input="updatePrefix(account.accountId, ($event.target as HTMLInputElement).value)"
                />
              </div>
              <div>
                <label class="setup-accounts__label">Суффикс номера счёта</label>
                <input
                  :value="getSettings(account.accountId).suffix"
                  type="text"
                  placeholder="-А, /2025, или пусто"
                  class="setup-accounts__input"
                  @input="updateSuffix(account.accountId, ($event.target as HTMLInputElement).value)"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="setup-accounts__empty">
        <p class="setup-accounts__empty-title">Счета не найдены</p>
      </div>

      <div class="setup-accounts__actions">
        <button
          type="button"
          class="setup-accounts__btn"
          :disabled="selectedAccounts.size === 0 && tochkaStore.accounts.length > 0"
          @click="handleNext"
        >
          Продолжить
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.setup-accounts {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 2rem 1rem 3rem;
  min-height: 400px;
}

.setup-accounts__panel {
  display: block;
  width: 640px;
  max-width: 100%;
  margin: 0 auto;
  box-sizing: border-box;
}

.setup-accounts__title,
.setup-accounts__text,
.setup-accounts__empty-title,
.setup-accounts__empty-text {
  display: block;
  width: 100%;
  box-sizing: border-box;
  white-space: normal;
  word-break: normal;
}

.setup-accounts__title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
  text-align: center;
  margin-bottom: 0.5rem;
  line-height: 1.4;
}

.setup-accounts__text {
  font-size: 0.875rem;
  color: #6b7280;
  text-align: center;
  line-height: 1.6;
  margin-bottom: 2rem;
}

.setup-accounts__loading {
  display: flex;
  justify-content: center;
  padding: 3rem 0;
}

.setup-accounts__spinner {
  width: 2rem;
  height: 2rem;
  border: 4px solid #8b5cf6;
  border-top-color: transparent;
  border-radius: 9999px;
  animation: setup-accounts-spin 0.8s linear infinite;
}

.setup-accounts__empty {
  text-align: center;
  padding: 3rem 0;
}

.setup-accounts__empty-title {
  color: #6b7280;
  margin-bottom: 0.5rem;
}

.setup-accounts__empty-text {
  font-size: 0.875rem;
  color: #9ca3af;
}

.setup-accounts__list {
  display: block;
  width: 100%;
  margin-bottom: 2rem;
}

.setup-accounts__list > * + * {
  margin-top: 0.75rem;
}

.setup-accounts__card {
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  overflow: hidden;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.setup-accounts__card--selected {
  border-color: #8b5cf6;
  background: rgb(139 92 246 / 0.05);
}

.setup-accounts__card-row {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1rem;
  cursor: pointer;
}

.setup-accounts__checkbox {
  width: 1.25rem;
  height: 1.25rem;
  border: 2px solid #d1d5db;
  border-radius: 0.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-top: 0.125rem;
}

.setup-accounts__checkbox--checked {
  border-color: #8b5cf6;
  background: #8b5cf6;
}

.setup-accounts__check-icon {
  width: 0.75rem;
  height: 0.75rem;
  color: #fff;
}

.setup-accounts__info {
  flex: 1;
  min-width: 0;
}

.setup-accounts__account-id {
  font-family: ui-monospace, monospace;
  font-size: 0.875rem;
  color: #1f2937;
  word-break: break-all;
}

.setup-accounts__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: #6b7280;
}

.setup-accounts__display-number {
  margin-top: 0.5rem;
  padding: 0.375rem 0.75rem;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
  color: #4b5563;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.setup-accounts__balance {
  text-align: right;
  flex-shrink: 0;
}

.setup-accounts__balance-amount {
  font-size: 0.875rem;
  font-weight: 600;
  color: #1f2937;
  white-space: nowrap;
}

.setup-accounts__balance-currency {
  font-size: 0.75rem;
  color: #9ca3af;
}

.setup-accounts__extra {
  border-top: 1px solid rgb(139 92 246 / 0.2);
  padding: 0.5rem 1rem 1rem;
  background: rgb(255 255 255 / 0.5);
}

.setup-accounts__extra-toggle {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  font-size: 0.75rem;
  color: #6b7280;
  background: none;
  border: none;
  cursor: pointer;
  margin-bottom: 0.75rem;
  padding: 0;
}

.setup-accounts__extra-toggle:hover {
  color: #374151;
}

.setup-accounts__extra-fields > * + * {
  margin-top: 0.75rem;
}

.setup-accounts__label {
  display: block;
  font-size: 0.75rem;
  color: #6b7280;
  margin-bottom: 0.25rem;
}

.setup-accounts__input {
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  box-sizing: border-box;
}

.setup-accounts__input:focus {
  outline: none;
  box-shadow: 0 0 0 2px rgb(139 92 246 / 0.4);
}

.setup-accounts__actions {
  display: flex;
  justify-content: center;
  width: 100%;
}

.setup-accounts__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.625rem 2rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: #fff;
  background: #8b5cf6;
  border: none;
  border-radius: 0.75rem;
  cursor: pointer;
  white-space: nowrap;
  transition: background-color 0.15s ease, opacity 0.15s ease;
}

.setup-accounts__btn:hover:not(:disabled) {
  background: #7c3aed;
}

.setup-accounts__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@keyframes setup-accounts-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
