<script setup lang="ts">
import { usePageStore } from '~/stores/page'

// region Init ////
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
      pageWrapper: 'flex flex-col h-[calc(100vh-0px)] min-h-full lg:grid lg:grid-cols-12 lg:gap-[22px] lg:pt-0 px-0 pb-[calc(53px+20px)]',
      container: 'p-0 lg:p-0 mt-0',
      containerWrapper: '',
      pageBottomWrapper: 'flex-0 relative'
    }"
  >
    <!-- Content -->
    <slot />

    <template v-if="!!slots['footer']" #content-bottom>
      <AppFooterBar>
        <slot name="footer" />
      </AppFooterBar>
    </template>
  </B24SidebarLayout>
</template>

<style scoped>
.--app {
  scrollbar-gutter: auto;
}
</style>
