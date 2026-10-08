<script setup lang="ts">
import type { NuxtError } from '#app'
import { createError as createH3Error } from 'h3'
import type { ProcessErrorData } from '~/composables/useAppInit'

const props = defineProps<{
  error: NuxtError
}>()

useHead({
  meta: [
    { name: 'viewport', content: 'width=device-width, initial-scale=1' }
  ],
  link: [],
  htmlAttrs: { lang: 'en' }
})

const getError = computed(() => {
  if (props?.error?.message === 'Unable to initialize Bitrix24Frame library!') {
    return createH3Error({
      statusCode: 500,
      statusMessage: 'Well done!',
      message: 'Now paste this URL into the B24 app settings',
      cause: props?.error
    })
  }

  return props?.error
})

// Options passed by pages through processErrorGlobal(error, { isShowClearError, clearErrorHref, ... })
const errorData = computed(() => (props?.error?.data ?? {}) as ProcessErrorData)

const clear = computed(() => errorData.value.isShowClearError
  ? { label: errorData.value.clearErrorTitle }
  : false
)
const redirect = computed(() => errorData.value.clearErrorHref ?? '/')
</script>

<template>
  <B24App>
    <NuxtLoadingIndicator color="var(--ui-color-accent-main-primary)" :height="2" />

    <B24SidebarLayout :use-light-content="false">
      <B24Card class="mt-[2px]">
        <B24Error
          :error="getError"
          :clear="clear"
          :redirect="redirect"
        />
      </B24Card>
    </B24SidebarLayout>
  </B24App>
</template>
