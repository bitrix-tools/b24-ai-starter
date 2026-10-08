<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, onMounted, onUnmounted } from 'vue'
import PlusLIcon from '@bitrix24/b24icons-vue/outline/PlusLIcon'

definePageMeta({
  layout: 'placement'
})

const { t, locales: localesI18n, setLocale } = useI18n()

// region Init ////
const { $logger, initApp, b24Helper, destroyB24Helper, processErrorGlobal } = useAppInit('crm_deal_detail_tab')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const apiStore = useApiStore()
// endregion ////

const isLoading = ref(false)

// region Init ////
const elementList = ref<string[]>([])
const dropdownValue = ref<string[]>([])

const dataList = ref([
  {
    id: '4600',
    date: '2024-03-11T15:30:00',
    status: 'paid',
    email: 'james.anderson@example.com',
    amount: 594
  },
  {
    id: '4599',
    date: '2024-03-11T10:10:00',
    status: 'failed',
    email: 'mia.white@example.com',
    amount: 276
  },
  {
    id: '4598',
    date: '2024-03-11T08:50:00',
    status: 'refunded',
    email: 'william.brown@example.com',
    amount: 315
  },
  {
    id: '4597',
    date: '2024-03-10T19:45:00',
    status: 'paid',
    email: 'emma.davis@example.com',
    amount: 529
  },
  {
    id: '4596',
    date: '2024-03-10T15:55:00',
    status: 'paid',
    email: 'ethan.harris@example.com',
    amount: 639
  }
])
// endregion ////

// region Actions ////
async function makeSomeRandom(timeout: number = 1000) {
  try {
    isLoading.value = true
    await sleepAction(timeout)
    dataList.value = [...dataList.value].sort(() => Math.random() - 0.5)
  } catch (error) {
    processErrorGlobal(error)
  } finally {
    isLoading.value = false
  }
}
// endregion ////

// region Tools ////
async function resizeWindow() {
  await $b24?.parent.fitWindow()
}
// endregion ////

// region Lifecycle Hooks ////
const isInit = ref(false)
onMounted(async () => {
  try {
    isLoading.value = true
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)

    await resizeWindow()

    $logger.info('Hi from crm-deal-detail-tab')

    elementList.value = await apiStore.getList()
    dropdownValue.value = [elementList.value[0] as string]

    isInit.value = true
  } catch (error) {
    processErrorGlobal(error, {
      homePageIsHide: true,
      isShowClearError: true,
      clearErrorHref: '/handler/placement-crm-deal-detail-tab'
    })
  } finally {
    isLoading.value = false
  }
})

onUnmounted(() => {
  if (b24Helper.value) {
    destroyB24Helper()
  }
})
// endregion ////
</script>

<template>
  <div>
    <div v-if="isInit">
      <div class="flex flex-row items-center justify-star gap-[12px]">
        <B24Button
          color="air-primary"
          :icon="PlusLIcon"
          :label="t('placement.crm_deal_detail_tab.action')"
          loading-auto
          @click="makeSomeRandom(1500)"
        />
        <div>
          <B24InputMenu
            id="select"
            v-model="dropdownValue"
            multiple
            class="w-[200px]"
            value-key="value"
            :items="elementList"
            :content="{
              sideOffset: 4,
              collisionPadding: 1
            }"
          />
        </div>
      </div>
      <B24Card
        variant="outline"
        class="flex-1 w-full mt-[12px]"
        :b24ui="{
          header: 'p-[12px] px-[14px] py-[14px] sm:px-[14px] sm:py-[14px]',
          body: 'p-0 sm:px-0 sm:py-0'
        }"
      >
        <B24Table
          :loading="isLoading"
          loading-color="air-primary"
          loading-animation="loading"
          :data="dataList"
          class="flex-1"
        />
      </B24Card>
    </div>
  </div>
</template>
