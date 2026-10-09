<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { onMounted } from 'vue'
import SettingsIcon from '@bitrix24/b24icons-vue/outline/SettingsIcon'

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({
  title: t('page.index.seo.title')
})

// region Init ////
const { $logger, initApp, processErrorGlobal } = useAppInit('IndexPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const apiStore = useApiStore()
const user = useUserStore()
const route = useRoute()
const { track } = useTelemetry()
// endregion ////

// region Actions ////
async function getEnums() {
  track('ui_button_click', { 'ui.button_id': 'get_enums', 'ui.path': route.path })
  const enums = await apiStore.getEnum()

  $logger.info('enums', { enums })
}

async function getItems() {
  track('ui_button_click', { 'ui.button_id': 'get_items', 'ui.path': route.path })
  const items = await apiStore.getList()

  $logger.info('items', { items })
}

/** Application settings open in a Bitrix24 slider (see slider/app-options, admins only). */
async function openSettings() {
  await $b24?.slider.openSliderAppPage({ place: 'app-options', bx24_width: 650 })
}
// endregion ////

const isLoading = ref(false)

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
  <B24DashboardPanel id="home" :b24ui="{ body: 'p-4 sm:p-5 scrollbar-transparent' }">
    <template #header>
      <B24DashboardNavbar :title="$t('page.index.seo.title')">
        <template #right>
          <B24Button
            v-if="isInit && user.isAdmin"
            size="sm"
            color="air-secondary"
            :icon="SettingsIcon"
            :label="$t('layout.nav.settings')"
            @click="openSettings"
          />
        </template>
      </B24DashboardNavbar>

      <B24DashboardToolbar v-if="isInit">
        <template #left>
          <B24Button label="getEnums" color="air-secondary" loading-auto @click="getEnums" />
          <B24Button label="getItems" color="air-secondary" loading-auto @click="getItems" />
        </template>
      </B24DashboardToolbar>
    </template>

    <template #body>
      <div v-if="isLoading" class="flex flex-col gap-4">
        <B24Skeleton class="h-[90px] w-full" />
        <B24Skeleton class="h-[60px] w-full" />
      </div>
      <div v-else-if="isInit" class="flex flex-col gap-4 max-w-[900px]">
        <div>
          <ProseH2 class="mb-1">
            {{ $t('page.index.message.title') }}
          </ProseH2>
          <ProseP accent="less" class="mb-0">
            {{ $t('page.index.message.line1') }}
          </ProseP>
        </div>
        <BackendStatus />
      </div>
    </template>
  </B24DashboardPanel>
</template>
