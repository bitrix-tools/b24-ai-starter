<script setup lang="ts">
const props = defineProps<{
  dealStages: Array<{ value: string; label: string }>
}>()

const emit = defineEmits<{
  (e: 'next'): void
  (e: 'back'): void
}>()

const APP_LABEL = 'Точка Банк. Интеграция'

const mode = ref<'express' | 'advanced'>('express')

// Express settings
const stageAfterPayment = ref('')
const moveOnPartialPayment = ref(false)

const selectedInvoiceField = ref(`Номер счёта [${APP_LABEL}]`)

// Advanced settings
const matchByInn = ref(true)
const matchByName = ref(true)
const matchByInvoiceNumber = ref(true)
const matchByAmount = ref(true)
const autoWritePaymentData = ref(true)
const handlePartialPayments = ref(true)
const createCustomFields = ref(true)
const advStageAfterPayment = ref('')
const advMoveOnPartialPayment = ref(false)

const stageOptions = computed(() =>
  props.dealStages.length > 0
    ? props.dealStages
    : [{ value: '', label: 'Не перемещать' }]
)

function handleFinish() {
  emit('next')
}
</script>

<template>
  <div class="py-6 px-4">
    <h1 class="text-xl font-bold text-gray-900 mb-1 text-center">Настройка интеграции</h1>
    <p class="text-gray-500 text-sm mb-6 text-center">Настройте правила сопоставления платежей</p>

    <!-- ==================== ЭКСПРЕСС-НАСТРОЙКА ==================== -->
    <div v-if="mode === 'express'">
      <div class="mx-auto bg-white border-2 border-violet-400 rounded-2xl p-5 mb-5" style="width: 580px;">
        <div class="flex items-center gap-3 mb-3">
          <h3 class="font-bold text-gray-900 whitespace-nowrap">Экспресс-настройка</h3>
          <span class="text-[10px] font-semibold bg-green-100 text-green-700 px-2 py-0.5 rounded-full uppercase tracking-wide whitespace-nowrap">рекомендуется</span>
        </div>

        <ul class="space-y-1 mb-4 text-sm text-gray-600">
          <li class="flex items-center gap-2">
            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            <span>Сопоставление по номеру счета и сумме в карточке</span>
          </li>
          <li class="flex items-center gap-2">
            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            <span>Создать полезные пользовательские поля</span>
          </li>
          <li class="flex items-center gap-2">
            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            <span>Автоматическое сопоставление включено</span>
          </li>
        </ul>

        <!-- Поле номера счёта -->
        <div class="mb-3">
          <label class="block text-sm text-gray-600 mb-1">Поле номера счёта в сделке</label>
          <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-green-50 border border-green-200 rounded-lg">
            <span class="text-green-600 text-xs">🔗</span>
            <span class="text-sm text-gray-800">{{ selectedInvoiceField }}</span>
            <button class="text-gray-400 hover:text-red-500 text-base leading-none">&times;</button>
          </div>
        </div>

        <!-- Стадия -->
        <div class="mb-2">
          <label class="block text-sm text-gray-600 mb-1">Переместить сделку на стадию после оплаты</label>
          <div class="relative">
            <select v-model="stageAfterPayment" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm appearance-none focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white">
              <option v-for="opt in stageOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
          </div>
          <p class="text-xs text-gray-400 mt-0.5">Можно изменить позже в Настройках</p>
        </div>

        <label class="flex items-center gap-2 mb-4 cursor-pointer">
          <input v-model="moveOnPartialPayment" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
          <span class="text-sm text-gray-500">Перемещать сделку после частичной оплаты</span>
        </label>

        <div class="flex items-center gap-3">
          <button class="px-5 py-2 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors" @click="$emit('back')">Назад</button>
          <button class="px-5 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors whitespace-nowrap" @click="handleFinish">Экспресс-настройка</button>
        </div>
      </div>

      <div class="text-center">
        <button class="text-sm text-violet-500 hover:text-violet-700 transition-colors" @click="mode = 'advanced'">Расширенные настройки</button>
      </div>
    </div>

    <!-- ==================== РАСШИРЕННЫЕ НАСТРОЙКИ ==================== -->
    <div v-else>
      <div class="mx-auto bg-white border border-gray-200 rounded-2xl p-5 mb-5" style="width: 580px;">
        <h3 class="font-bold text-gray-900 mb-4">Расширенные настройки</h3>

        <!-- Группа: Поиск сделки -->
        <p class="text-sm font-medium text-gray-700 mb-2">Автоматически находить сделку для каждого платежа</p>
        <div class="space-y-2 mb-5 pl-1">
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="matchByInn" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Сопоставить по ИНН контрагента</span>
          </label>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="matchByName" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Сопоставить по названию контрагента</span>
          </label>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="matchByInvoiceNumber" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Сопоставить по номеру счёта в назначении платежа</span>
          </label>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="matchByAmount" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Сопоставить по сумме платежа</span>
          </label>
        </div>

        <hr class="border-gray-100 mb-4" />

        <!-- Группа: Действия -->
        <div class="space-y-2 mb-5 pl-1">
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="autoWritePaymentData" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Автоматически записывать данные платежа в найденную сделку</span>
          </label>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="handlePartialPayments" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Обрабатывать частичные платежи и переплаты</span>
          </label>
          <label class="flex items-center gap-2.5 cursor-pointer">
            <input v-model="createCustomFields" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
            <span class="text-sm text-gray-600">Создать полезные пользовательские поля</span>
          </label>
        </div>

        <hr class="border-gray-100 mb-4" />

        <!-- Стадия -->
        <div class="mb-2">
          <label class="block text-sm text-gray-600 mb-1">Переместить сделку на стадию после оплаты</label>
          <div class="relative">
            <select v-model="advStageAfterPayment" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm appearance-none focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white">
              <option v-for="opt in stageOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
          </div>
        </div>

        <label class="flex items-center gap-2 mb-5 cursor-pointer">
          <input v-model="advMoveOnPartialPayment" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
          <span class="text-sm text-gray-500">Перемещать сделку после частичной оплаты</span>
        </label>

        <!-- Кнопки -->
        <div class="flex items-center gap-3">
          <button class="px-5 py-2 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors" @click="$emit('back')">Назад</button>
          <button class="px-5 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors whitespace-nowrap" @click="handleFinish">Сохранить настройки</button>
        </div>
      </div>

      <div class="text-center">
        <button class="text-sm text-violet-500 hover:text-violet-700 transition-colors" @click="mode = 'express'">Экспресс-настройка</button>
      </div>
    </div>
  </div>
</template>
