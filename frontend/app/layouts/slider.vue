<script setup lang="ts">
import { usePageStore } from '~/stores/page'

// region Init ////
useHead({
  bodyAttrs: {
    class: `light` // 'dark' | 'light' | 'edge-dark' | 'edge-light'
  }
})

const slots = defineSlots()

const page = usePageStore()
// getters, not values: pages set page.title after the layout is created
useSeoMeta({
  title: () => page.title,
  description: () => page.description
})
// endregion ////
</script>

<template>
  <B24SidebarLayout
    :use-light-content="false"
    :b24ui="{
      root: '',
      pageWrapper: 'flex flex-col h-[calc(100vh-0px)] min-h-full  lg:grid lg:grid-cols-12 lg:gap-[22px] lg:pt-0 lg:px-[20px] lg:ps-[20px] lg:pe-[20px] px-[20px] ps-[20px] pe-[20px] pb-[calc(53px+20px)]',
      container: 'mt-[20px]',
      containerWrapper: 'mt-[20px]',
      pageBottomWrapper: 'flex-0 relative'
    }"
  >
    <!-- Header -->
    <template #content-top>
      <div class="w-full flex flex-col gap-[4px]">
        <div class="flex items-center gap-[12px]">
          <div class="w-full flex items-center gap-[20px]">
            <ProseH2 class="font-(--ui-font-weight-semi-bold) mb-0 text-(length:--ui-font-size-4xl)/[calc(var(--ui-font-size-4xl)+2px)]">
              {{ page.title }}
            </ProseH2>
            <slot name="top-actions-start" />
          </div>
          <div
            v-if="!!slots['top-actions-end']"
            class="flex-1 hidden sm:flex flex-row items-center justify-end gap-[12px]"
          >
            <slot name="top-actions-end" />
          </div>
        </div>
        <ProseP v-if="page.description.length > 0" small accent="less" class="mb-0">
          {{ page.description }}
        </ProseP>
      </div>
    </template>

    <!-- Content -->
    <slot />

    <template v-if="!!slots['footer']" #content-bottom>
      <div class="absolute inset-x-0 bottom-0 light bg-(--popup-window-background-color) fixed flex items-center justify-center gap-3 border-t-1 border-t-(--ui-color-divider-less) shadow-top-md py-[9px] px-2 pr-(--scrollbar-width)">
        <div class="flex flex-row gap-[10px]">
          <slot name="footer" />
        </div>
      </div>
    </template>
  </B24SidebarLayout>
</template>

<style scoped>
.--app {
  scrollbar-gutter: auto;
}
</style>
