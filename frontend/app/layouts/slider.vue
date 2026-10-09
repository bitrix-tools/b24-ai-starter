<script setup lang="ts">
import { usePageStore } from '~/stores/page'

/**
 * Pages opened in a Bitrix24 slider (`$b24.slider.openSliderAppPage`). No sidebar:
 * the slider is already a side panel. Title/description come from the page store.
 */
const slots = defineSlots()

const page = usePageStore()
// getters, not values: pages set page.title after the layout is created
useSeoMeta({
  title: () => page.title,
  description: () => page.description
})
</script>

<template>
  <B24DashboardGroup unit="px" storage="local">
    <B24DashboardPanel id="slider" :b24ui="{ body: 'p-4 sm:p-5 pb-[calc(53px+20px)] sm:pb-[calc(53px+20px)] scrollbar-transparent' }">
      <template #header>
        <B24DashboardNavbar :title="page.title" :toggle="false">
          <template #right>
            <slot name="top-actions-end" />
          </template>
        </B24DashboardNavbar>
        <B24DashboardToolbar v-if="page.description || !!slots['top-actions-start']">
          <template #left>
            <ProseP v-if="page.description" small accent="less" class="mb-0">
              {{ page.description }}
            </ProseP>
            <slot name="top-actions-start" />
          </template>
        </B24DashboardToolbar>
      </template>

      <template #body>
        <slot />
      </template>
    </B24DashboardPanel>

    <AppFooterBar v-if="!!slots['footer']">
      <slot name="footer" />
    </AppFooterBar>
  </B24DashboardGroup>
</template>
