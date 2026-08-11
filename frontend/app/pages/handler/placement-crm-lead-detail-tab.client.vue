<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'

definePageMeta({
  layout: 'placement',
})

const { t, locales: localesI18n, setLocale } = useI18n()

const { $logger, initApp, b24Helper, destroyB24Helper, processErrorGlobal } = useAppInit('crm_lead_detail_tab')
const { $initializeB24Frame } = useNuxtApp()

const b24Frame = shallowRef<B24Frame | null>(null)
const entityId = ref<number | null>(null)
const isInit = ref(false)

onMounted(async () => {
  try {
    const $b24 = await $initializeB24Frame()
    b24Frame.value = $b24
    await initApp($b24, localesI18n, setLocale)

    const opt = $b24.placement.options || {}
    const raw = opt.ID ?? opt.id
    entityId.value = raw != null && raw !== '' ? Number(raw) : NaN
    if (Number.isNaN(entityId.value)) {
      entityId.value = null
    }

    await $b24.parent.fitWindow()
    $logger.info('crm-lead-detail-tab', opt)
    isInit.value = true
  } catch (error) {
    processErrorGlobal(error, {
      homePageIsHide: true,
      isShowClearError: true,
      clearErrorHref: '/handler/uf.demo.html',
    })
  }
})

onUnmounted(() => {
  if (b24Helper.value) {
    destroyB24Helper()
  }
})
</script>

<template>
  <div class="min-h-full min-w-0">
    <div v-if="isInit && b24Frame && entityId != null">
      <CrmEntityPaymentsTab :b24="b24Frame" entity-type="lead" :entity-id="entityId" />
    </div>
    <div v-else-if="isInit" class="text-sm text-gray-500">
      {{ t('placement.crm_entity_payments.bad_entity_id') }}
    </div>
  </div>
</template>
