<script setup lang="ts">
import * as locales from '@bitrix24/b24ui-nuxt/locale'

// region Init ////
const { locale, defaultLocale } = useI18n()
const lang = computed(() => locales[locale.value]?.code || defaultLocale)
const dir = computed(() => locales[locale.value]?.dir || 'ltr')

useHead({
  htmlAttrs: { lang, dir }
})
// endregion ////
</script>

<template>
  <B24App :locale="locales[locale]">
    <NuxtLoadingIndicator color="var(--ui-color-design-filled-warning-bg)" :height="3" />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </B24App>
</template>
