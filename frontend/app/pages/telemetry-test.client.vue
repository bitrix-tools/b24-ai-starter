<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, reactive, onMounted } from 'vue'

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({ title: t('page.telemetry-test.seo.title') })

// region Init ////
const { $logger, initApp, processErrorGlobal } = useAppInit('TelemetryTestPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const apiStore = useApiStore()
const route = useRoute()
const { track } = useTelemetry()
// endregion ////

// region State ////
type Status = 'idle' | 'ok' | 'error'

interface ActionState {
  status: Status
  result: string
}

function makeState(): ActionState {
  return { status: 'idle', result: '' }
}

const state = reactive({
  backend:       makeState(),
  buttonClick:   makeState(),
  selectChange:  makeState(),
  formSubmit:    makeState(),
  errorTrack:    makeState(),
  b24User:       makeState(),
  b24Status:     makeState(),
  b24Placement:  makeState(),
})

const selectValue = ref<string[]>([])
const selectItems = ['option_alpha', 'option_beta', 'option_gamma', 'option_delta']
const formText    = ref('')

const isInit = ref(false)
// endregion ////

// region Helpers ////
function setOk(s: ActionState, result: string) {
  s.status = 'ok'
  s.result = result
}
function setErr(s: ActionState, err: unknown) {
  s.status = 'error'
  s.result = err instanceof Error ? err.message : String(err)
}
function statusClass(status: Status): string {
  if (status === 'ok')    return 'text-sm font-medium text-green-600'
  if (status === 'error') return 'text-sm font-medium text-red-600'
  return 'text-sm text-gray-400'
}
function statusLabel(status: Status): string {
  if (status === 'ok')    return t('page.telemetry-test.status.ok')
  if (status === 'error') return t('page.telemetry-test.status.error')
  return t('page.telemetry-test.status.idle')
}
// endregion ////

// region Actions — Backend ////
async function runBackendEvents() {
  state.backend.status = 'idle'
  state.backend.result = ''
  try {
    const res = await apiStore.telemetryTest()
    setOk(state.backend, `Fired ${res.fired_count} events: ${res.fired_events.join(', ')}`)
  } catch (err) {
    setErr(state.backend, err)
  }
}
// endregion ////

// region Actions — Frontend UI ////
function doButtonClick() {
  track('ui_button_click', {
    'ui.button_id': 'telemetry_test_button',
    'ui.path': route.path,
  })
  setOk(state.buttonClick, 'ui_button_click sent')
}

function onSelectChange(val: string[]) {
  track('ui_select_change', {
    'ui.field_id': 'telemetry_test_select',
    'ui.selected_count': String(val.length),
    'ui.path': route.path,
  })
  setOk(state.selectChange, `ui_select_change sent (selected: ${val.join(', ') || '—'})`)
}

function doFormSubmit() {
  track('ui_form_submit', {
    'ui.form': 'telemetry_test_form',
    'ui.path': route.path,
  })
  setOk(state.formSubmit, 'ui_form_submit sent')
}

function doErrorTrack() {
  track('ui_error', {
    'error.type':    'test_error',
    'error.message': '[TelemetryTest] Soft frontend test error',
    'ui.path':       route.path,
  })
  setOk(state.errorTrack, 'ui_error sent (soft, no real exception)')
}
// endregion ////

// region Actions — Bitrix24 API ////
async function doB24UserCurrent() {
  state.b24User.status = 'idle'
  state.b24User.result = ''
  try {
    track('b24_api_call', { 'b24.method': 'user.current', 'ui.path': route.path })
    const res = await $b24?.callMethod('user.current', {})
    const data = res?.getData?.() ?? res
    const user = Array.isArray(data?.result) ? data.result[0] : data?.result
    setOk(state.b24User, `user.current → ID: ${user?.ID}, name: ${user?.NAME} ${user?.LAST_NAME}`)
  } catch (err) {
    setErr(state.b24User, err)
  }
}

async function doB24StatusList() {
  state.b24Status.status = 'idle'
  state.b24Status.result = ''
  try {
    track('b24_api_call', { 'b24.method': 'crm.status.list', 'ui.path': route.path })
    const res = await $b24?.callMethod('crm.status.list', {})
    const data = res?.getData?.() ?? res
    const count = Array.isArray(data?.result) ? data.result.length : '?'
    setOk(state.b24Status, `crm.status.list → ${count} статусов`)
  } catch (err) {
    setErr(state.b24Status, err)
  }
}

async function doB24PlacementInfo() {
  state.b24Placement.status = 'idle'
  state.b24Placement.result = ''
  try {
    track('b24_api_call', { 'b24.method': 'placement.info', 'ui.path': route.path })
    const placement = $b24?.placement?.title ?? 'n/a'
    const options   = JSON.stringify($b24?.placement?.options ?? {})
    setOk(state.b24Placement, `placement: ${placement}, options: ${options}`)
  } catch (err) {
    setErr(state.b24Placement, err)
  }
}
// endregion ////

// region Lifecycle ////
onMounted(async () => {
  try {
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle(t('page.telemetry-test.seo.title'))
    isInit.value = true

    // Автоматически запускаем бэкенд-события при открытии страницы
    await runBackendEvents()
  } catch (error) {
    processErrorGlobal(error)
  }
})
// endregion ////
</script>

<template>
  <div class="flex flex-col gap-4 p-4 max-w-[900px] mx-auto">

    <!-- Header -->
    <div class="flex flex-row items-center gap-3">
      <B24Button
        size="sm"
        color="air-secondary"
        :label="$t('page.telemetry-test.action.back')"
        @click="$router.push('/')"
      />
      <div>
        <ProseH2 class="mb-0">{{ $t('page.telemetry-test.title') }}</ProseH2>
        <ProseP class="text-sm text-gray-500 mb-0">{{ $t('page.telemetry-test.description') }}</ProseP>
      </div>
    </div>

    <div v-if="isInit" class="flex flex-col gap-4">

      <!-- Section 1: Backend Events -->
      <B24Card>
        <template #header>
          <ProseH3 class="mb-0">{{ $t('page.telemetry-test.section.backend') }}</ProseH3>
          <ProseP class="text-sm text-gray-500 mb-0">{{ $t('page.telemetry-test.section.backend_desc') }}</ProseP>
        </template>
        <div class="flex flex-col gap-2">
          <div class="flex flex-row items-center gap-3 flex-wrap">
            <B24Button :label="$t('page.telemetry-test.action.run_backend')" loading-auto @click="runBackendEvents" />
            <span :class="statusClass(state.backend.status)">{{ statusLabel(state.backend.status) }}</span>
          </div>
          <ProseP v-if="state.backend.result" class="text-xs text-gray-500 mb-0 break-all">{{ state.backend.result }}</ProseP>
        </div>
      </B24Card>

      <!-- Section 2: Frontend Events -->
      <B24Card>
        <template #header>
          <ProseH3 class="mb-0">{{ $t('page.telemetry-test.section.frontend') }}</ProseH3>
          <ProseP class="text-sm text-gray-500 mb-0">{{ $t('page.telemetry-test.section.frontend_desc') }}</ProseP>
        </template>
        <div class="flex flex-col gap-4">

          <!-- ui_button_click -->
          <div class="flex flex-row items-center gap-3 flex-wrap">
            <B24Button :label="$t('page.telemetry-test.action.button_click')" color="air-primary" @click="doButtonClick" />
            <span :class="statusClass(state.buttonClick.status)">{{ statusLabel(state.buttonClick.status) }}</span>
          </div>

          <!-- ui_select_change -->
          <div class="flex flex-col gap-1">
            <ProseP class="text-sm font-medium mb-0">{{ $t('page.telemetry-test.label.select') }}</ProseP>
            <div class="flex flex-row items-center gap-3 flex-wrap">
              <B24InputMenu
                v-model="selectValue"
                multiple
                class="w-[240px]"
                :items="selectItems"
                @update:model-value="onSelectChange"
              />
              <span :class="statusClass(state.selectChange.status)">{{ statusLabel(state.selectChange.status) }}</span>
            </div>
            <ProseP v-if="state.selectChange.result" class="text-xs text-gray-500 mb-0">{{ state.selectChange.result }}</ProseP>
          </div>

          <!-- ui_form_submit -->
          <form @submit.prevent="doFormSubmit" class="flex flex-col gap-2">
            <ProseP class="text-sm font-medium mb-0">{{ $t('page.telemetry-test.label.form_field') }}</ProseP>
            <div class="flex flex-row items-center gap-3 flex-wrap">
              <input
                v-model="formText"
                class="w-[240px] rounded border border-gray-300 px-3 py-1.5 text-sm outline-none focus:border-blue-400"
                placeholder="test value"
              />
              <B24Button type="submit" :label="$t('page.telemetry-test.action.form_submit')" color="air-secondary" />
              <span :class="statusClass(state.formSubmit.status)">{{ statusLabel(state.formSubmit.status) }}</span>
            </div>
          </form>

          <!-- ui_error -->
          <div class="flex flex-row items-center gap-3 flex-wrap">
            <B24Button :label="$t('page.telemetry-test.action.error_track')" color="air-primary-alert" @click="doErrorTrack" />
            <span :class="statusClass(state.errorTrack.status)">{{ statusLabel(state.errorTrack.status) }}</span>
          </div>

        </div>
      </B24Card>

      <!-- Section 3: Bitrix24 API Calls -->
      <B24Card>
        <template #header>
          <ProseH3 class="mb-0">{{ $t('page.telemetry-test.section.b24api') }}</ProseH3>
          <ProseP class="text-sm text-gray-500 mb-0">{{ $t('page.telemetry-test.section.b24api_desc') }}</ProseP>
        </template>
        <div class="flex flex-col gap-3">

          <div class="flex flex-col gap-1">
            <div class="flex flex-row items-center gap-3 flex-wrap">
              <B24Button :label="$t('page.telemetry-test.action.b24_user')" loading-auto @click="doB24UserCurrent" />
              <span :class="statusClass(state.b24User.status)">{{ statusLabel(state.b24User.status) }}</span>
            </div>
            <ProseP v-if="state.b24User.result" class="text-xs text-gray-500 mb-0 break-all">{{ state.b24User.result }}</ProseP>
          </div>

          <div class="flex flex-col gap-1">
            <div class="flex flex-row items-center gap-3 flex-wrap">
              <B24Button :label="$t('page.telemetry-test.action.b24_status')" loading-auto @click="doB24StatusList" />
              <span :class="statusClass(state.b24Status.status)">{{ statusLabel(state.b24Status.status) }}</span>
            </div>
            <ProseP v-if="state.b24Status.result" class="text-xs text-gray-500 mb-0 break-all">{{ state.b24Status.result }}</ProseP>
          </div>

          <div class="flex flex-col gap-1">
            <div class="flex flex-row items-center gap-3 flex-wrap">
              <B24Button :label="$t('page.telemetry-test.action.b24_placement')" loading-auto @click="doB24PlacementInfo" />
              <span :class="statusClass(state.b24Placement.status)">{{ statusLabel(state.b24Placement.status) }}</span>
            </div>
            <ProseP v-if="state.b24Placement.result" class="text-xs text-gray-500 mb-0 break-all">{{ state.b24Placement.result }}</ProseP>
          </div>

        </div>
      </B24Card>

    </div>
  </div>
</template>
