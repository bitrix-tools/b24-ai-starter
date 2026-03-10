<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { onMounted, getCurrentInstance } from 'vue'
import { useDashboard } from '@bitrix24/b24ui-nuxt/utils/dashboard'

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({
  title: t('page.index.seo.title')
})

// region Init ////
const { $logger, initApp, processErrorGlobal } = useAppInit('IndexPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const apiStore = useApiStore()
const route = useRoute()
const { track } = useTelemetry()
// endregion ////

// region Actions ////
async function getEnums() {
  track('ui_button_click', { 'ui.button_id': 'get_enums', 'ui.path': route.path })
  const enums = await apiStore.getEnum()

  $logger.info(enums)
}

async function getItems() {
  track('ui_button_click', { 'ui.button_id': 'get_items', 'ui.path': route.path })
  const items = await apiStore.getList()

  $logger.info(items)
}
// endregion ////

const { contextId, isLoading: isLoadingState, load } = useDashboard({ isLoading: ref(false), load: () => {} })
const isLoading = computed({
  get: () => isLoadingState?.value === true,
  set: (value: boolean) => {
    $logger.info(load, value, contextId, isLoadingState?.value)
    load?.(value, contextId)
  }
})

// region Lifecycle Hooks ////
const isInit = ref(false)
onMounted(async () => {
  $logger.info('Hi from index page')

  try {
    isLoading.value = true
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)

    await $b24.parent.setTitle(t('page.index.seo.title'))

    isInit.value = true
  } catch (error) {
    processErrorGlobal(error)
  } finally {
    isLoading.value = false
  }
})
// endregion ////
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-16 h-[calc(100vh-200px)]">
    <B24Card
      v-if="isInit"
      :b24ui="{
        footer: 'flex flex-row flex-wrap items-center justify-start gap-2'
      }"
    >
      <template #header>
        <ProseH2>{{ $t('page.index.message.title') }}</ProseH2>
        <ProseP>{{ $t('page.index.message.line1') }}</ProseP>
      </template>

      <BackendStatus />

      <template #footer>
        <B24Button label="getEnums" loading-auto @click="getEnums" />
        <B24Button label="getItems" loading-auto @click="getItems" />
      </template>
    </B24Card>
  </div>
</template>
