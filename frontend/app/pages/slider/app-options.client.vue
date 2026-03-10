<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import type { AccordionItem } from '@bitrix24/b24ui-nuxt'
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useDashboard } from '@bitrix24/b24ui-nuxt/utils/dashboard'
import { usePageStore } from '~/stores/page'
import { useUserStore } from '~/stores/user'
import { useAppSettingsStore } from '~/stores/appSettings'
import { AjaxError } from '@bitrix24/b24jssdk'
import ListIcon from '@bitrix24/b24icons-vue/main/ListIcon'
import CloudErrorIcon from '@bitrix24/b24icons-vue/main/CloudErrorIcon'
import ClockWithArrowIcon from '@bitrix24/b24icons-vue/main/ClockWithArrowIcon'

definePageMeta({
  layout: false
})

const { t, locales: localesI18n, setLocale } = useI18n()
const page = usePageStore()
const toast = useToast()

// region Init ////
const { $logger, moduleId, initApp, destroyB24Helper, usePullClient, startPullClient, processErrorGlobal } = useAppInit('SliderAppOptionsPage')
const appSettings = useAppSettingsStore()
const user = useUserStore()
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null
const { track } = useTelemetry()

const someList = ref([
  15, 30, 60, 90, 120, 180
])

const someValue_1 = ref(appSettings.configSettings.someValue_1)
const someValue_2 = ref(appSettings.configSettings.someValue_2)
const isSomeOption = ref(appSettings.configSettings.isSomeOption)
const activeInfoItem = ref(['0'])
const infoItems = computed(() => [
  {
    label: t('page.app-options.option.history.title'),
    icon: ClockWithArrowIcon,
    slot: 'history'
  },
  {
    label: t('page.app-options.option.present.title'),
    icon: ListIcon,
    slot: 'present'
  }
] satisfies AccordionItem[])
// endregion ////

const { contextId, isLoading: isLoadingState, load } = useDashboard({ isLoading: ref(false), load: () => {} })
const isLoading = computed({
  get: () => isLoadingState?.value === true,
  set: (value: boolean) => {
    load?.(value, contextId)
  }
})

// region Actions ////
function initData() {
  someValue_1.value = appSettings.configSettings.someValue_1
  someValue_2.value = appSettings.configSettings.someValue_2
  isSomeOption.value = appSettings.configSettings.isSomeOption
}

async function makeSave() {
  track('ui_button_click', { 'ui.button_id': 'app_options_save' })
  try {
    isLoading.value = true

    appSettings.configSettings.someValue_1 = someValue_1.value
    appSettings.configSettings.someValue_2 = someValue_2.value
    appSettings.configSettings.isSomeOption = isSomeOption.value

    await appSettings.saveSettings()

    await makeSendPullCommand('reload.options', { from: 'app.options' })
    await makeClose()
  } catch (error) {
    $logger.error(error)

    let title = t('page.app-options.error.title')
    let description = ''

    if (error instanceof AjaxError) {
      title = `[${error.name}] ${error.code} (${error.status})`
      description = `${error.message}`
    } else if (error instanceof Error) {
      description = error.message
    } else {
      description = error as string
    }

    toast.add({
      title: title,
      description,
      color: 'air-primary-alert',
      icon: CloudErrorIcon
    })
  } finally {
    isLoading.value = false
  }
}

async function makeSendPullCommand(command: string, params: Record<string, any> = {}) {
  try {
    $logger.warn('>> pull.send >>>', {
      COMMAND: command,
      PARAMS: params,
      MODULE_ID: moduleId
    })

    await $b24?.callMethod(
      'pull.application.event.add',
      {
        COMMAND: command,
        PARAMS: params,
        MODULE_ID: moduleId
      }
    )
  } catch (error) {
    processErrorGlobal(error)
  }
}

async function makeClose() {
  await $b24?.parent.closeApplication()
}

async function makeCancel() {
  track('ui_button_click', { 'ui.button_id': 'app_options_cancel' })
  await $b24?.parent.closeApplication()
}
// endregion ////

// region Lifecycle Hooks ////
onMounted(async () => {
  $logger.info('Hi from slider/app-options')

  try {
    isLoading.value = true

    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)

    if( !user.isAdmin ) {
      throw new Error(t('page.app-options.error.notAdmin'))
    }

    page.title = t('page.app-options.seo.title')
    page.description = t('page.app-options.seo.description')

    usePullClient()
    startPullClient()

    initData()
  } catch (error) {
    processErrorGlobal(error)
  } finally {
    isLoading.value = false
  }
})

onUnmounted(() => {
  destroyB24Helper()
})
// endregion ////
</script>

<template>
  <NuxtLayout name="slider">
    <B24Accordion
      v-if="isLoading === false"
      v-model="activeInfoItem"
      type="multiple"
      :items="infoItems"
      :b24ui="{
          root: 'light',
          item: 'mb-[16px] last:mb-0 bg-(--ui-color-bg-content-primary) rounded-(--ui-border-radius-md) border-b-0',
          trigger: 'py-[20px] px-[20px]',
          label: 'text-(length:--ui-font-size-2xl) text-(-ui-color-text-primary)',
          leadingIcon: 'text-(--ui-color-base-60)',
          trailingIcon: 'text-(-ui-color-text-primary)',
        }"
    >
      <template #history>
        <div class="px-4 pb-[12px]">
          <B24Separator class="mb-3" />
          <div class="flex flex-col items-start justify-between gap-[18px]">
            <B24Alert
              color="air-secondary"
              :description="$t('page.app-options.option.history.alert')"
              :b24ui="{ description: 'text-(--ui-color-base-70)' }"
            />

            <B24FormField
              class="w-[190px]"
              :description="$t('page.app-options.option.history.property_1')"
            >
              <B24Select
                v-model="someValue_1"
                :items="someList"
                size="lg"
                class="w-full"
              />
            </B24FormField>
            <B24FormField
              class="w-[190px]"
              :description="$t('page.app-options.option.history.property_2')"
            >
              <B24Input
                v-model="someValue_2"
                size="lg"
                class="w-full"
              />
            </B24FormField>
            <B24FormField
              class="w-[190px]"
              :description="$t('page.app-options.option.history.property_bool')"
            >
              <B24Switch
                v-model="isSomeOption"
                size="lg"
              />
            </B24FormField>
          </div>
        </div>
      </template>
      <template #present>
        <div class="px-4 pb-[12px]">
          <B24Separator class="mb-3" />
          <div class="flex flex-col items-start justify-between gap-[18px]">
            <B24Alert
              color="air-secondary"
              :description="$t('page.app-options.option.present.alert')"
              :b24ui="{ description: 'text-(--ui-color-base-70)' }"
            />
          </div>
        </div>
      </template>
    </B24Accordion>
    <template #footer>
      <B24Button
        :label="t('page.app-options.actions.save')"
        color="air-primary-success"
        loading-auto
        @click.stop="makeSave"
      />
      <B24Button
        :label="t('page.app-options.actions.cancel')"
        color="air-tertiary"
        @click.stop="makeCancel"
      />
    </template>
  </NuxtLayout>
</template>
