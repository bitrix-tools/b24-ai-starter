<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { onMounted } from 'vue'

definePageMeta({ layout: 'setup' })

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({ title: t('page.index.seo.title') })

const { $logger, initApp, processErrorGlobal } = useAppInit('IndexPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const tochkaStore = useTochkaStore()

const isInit = ref(false)
/** 1 — подключение и счета, 2 — готово */
const currentStep = ref(1)
/** Внутри шага «Подключение»: OAuth → выбор счетов → авто-настройка */
const connectionPhase = ref<'connect' | 'accounts' | 'auto'>('connect')

const steps = [
  { id: 1, label: 'Подключение' },
  { id: 2, label: 'Готово' },
]

const CRM_FIELDS = [
  { FIELD_NAME: 'UF_CRM_TOCHKA_INVOICE', EDIT_FORM_LABEL: { ru: 'Номер счёта [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'string' },
  { FIELD_NAME: 'UF_CRM_TOCHKA_AMOUNT', EDIT_FORM_LABEL: { ru: 'Сумма оплаты [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'double' },
  { FIELD_NAME: 'UF_CRM_TOCHKA_DATE', EDIT_FORM_LABEL: { ru: 'Дата оплаты [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'date' },
  { FIELD_NAME: 'UF_CRM_TOCHKA_PURPOSE', EDIT_FORM_LABEL: { ru: 'Назначение платежа [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'string' },
  { FIELD_NAME: 'UF_CRM_TOCHKA_INN', EDIT_FORM_LABEL: { ru: 'ИНН контрагента [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'string' },
  { FIELD_NAME: 'UF_CRM_TOCHKA_COMPANY', EDIT_FORM_LABEL: { ru: 'Контрагент [Точка Банк. Интеграция]' }, USER_TYPE_ID: 'string' },
]

async function createCrmFields() {
  if (!$b24) return
  for (const field of CRM_FIELDS) {
    try {
      await $b24.callMethod('crm.deal.userfield.add', {
        fields: {
          FIELD_NAME: field.FIELD_NAME,
          USER_TYPE_ID: field.USER_TYPE_ID,
          EDIT_FORM_LABEL: field.EDIT_FORM_LABEL,
          LIST_COLUMN_LABEL: field.EDIT_FORM_LABEL,
          SHOW_IN_LIST: 'Y',
          IS_SEARCHABLE: 'Y',
        },
      })
    } catch {
      /* поле уже существует */
    }
  }
}

function onConnectNext() {
  connectionPhase.value = 'accounts'
}

function onAccountsNext() {
  connectionPhase.value = 'auto'
}

function onAutoSetupDone() {
  currentStep.value = 2
}

function finishSetup() {
  navigateTo('/dashboard')
}

onMounted(async () => {
  try {
    const urlParams = new URLSearchParams(window.location.search)
    const tochkaConnected = urlParams.get('tochka_connected')
    const tochkaError = urlParams.get('tochka_error')

    const isTochkaOAuthPopup = window.name === 'tochka_auth' || !!window.opener
    if (isTochkaOAuthPopup && (tochkaConnected === 'true' || tochkaError)) {
      if (window.opener) {
        if (tochkaConnected === 'true') {
          window.opener.postMessage?.({ type: 'tochka_connected', tochkaAuth: 'ok' }, '*')
        } else if (tochkaError) {
          window.opener.postMessage?.({ type: 'tochka_error', error: tochkaError }, '*')
        }
      }
      setTimeout(() => window.close(), 300)
      return
    }

    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle(t('page.index.seo.title'))

    if (tochkaConnected === 'true') {
      window.history.replaceState({}, '', window.location.pathname)
    }

    await tochkaStore.fetchStatus()

    const forceSetup = urlParams.get('setup') === '1'

    if (tochkaStore.status.connected && !forceSetup) {
      navigateTo('/dashboard')
      return
    }

    if (tochkaStore.status.connected && forceSetup) {
      connectionPhase.value = 'accounts'
    }

    if (tochkaConnected === 'true') {
      connectionPhase.value = 'accounts'
    }

    isInit.value = true
  } catch (error) {
    processErrorGlobal(error)
  }
})
</script>

<template>
  <div
    v-if="!isInit"
    class="fixed inset-0 z-[100] min-h-[100dvh] bg-white"
    aria-hidden="true"
  />

  <div v-else class="setup-page min-h-screen bg-white w-full">
    <SetupStepper :current-step="currentStep" :steps="steps" />

    <div class="setup-page__body">
      <template v-if="currentStep === 1">
        <SetupStepConnect
          v-if="connectionPhase === 'connect'"
          @next="onConnectNext"
        />

        <SetupStepAccounts
          v-else-if="connectionPhase === 'accounts'"
          @next="onAccountsNext"
        />

        <SetupStepAutoSetup
          v-else
          :create-crm-fields="createCrmFields"
          @done="onAutoSetupDone"
        />
      </template>

      <SetupStepComplete
        v-else
        @finish="finishSetup"
      />
    </div>
  </div>
</template>

<style scoped>
.setup-page {
  display: block;
  width: 100%;
  box-sizing: border-box;
}

.setup-page__body {
  display: block;
  width: 100%;
  max-width: 960px;
  margin: 0 auto;
  padding: 0 1rem 2rem;
  box-sizing: border-box;
}
</style>
