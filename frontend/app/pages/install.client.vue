<script setup lang="ts">
import type { ProgressProps } from '@bitrix24/b24ui-nuxt'
import type { IStep } from '#shared/types/base'
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, onMounted } from 'vue'
import { sleepAction } from '~/utils/sleep'
import { withoutTrailingSlash } from 'ufo'
import Logo from '~/components/Logo.vue'

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({
  title: t('page.install.seo.title')
})

// region Init ////
const config = useRuntimeConfig()
const appUrl = withoutTrailingSlash(config.public.appUrl)

const { $logger, initLang, processErrorGlobal } = useAppInit('Install')
const { $initializeB24Frame } = useNuxtApp()
const $b24: B24Frame = await $initializeB24Frame()
await initLang($b24, localesI18n, setLocale)

// Логируем конфигурацию для отладки
$logger.log('Installation started', {
  appUrl,
  configPublicAppUrl: config.public.appUrl,
  configPublicApiUrl: config.public.apiUrl,
  isDev: import.meta.dev
})

const confetti = useConfetti()

const isShowDebug = ref(false)

const progressColor = ref<ProgressProps['color']>('air-primary')
const progressValue = ref<null | number>(null)

const apiStore = useApiStore()
// endregion ////

// region Steps ////
const steps = ref<Record<string, IStep>>({
  init: {
    caption: t('page.install.step.init.caption'),
    action: makeInit
  },
  demo: {
    caption: t('page.install.step.demo.caption'),
    action: async () => {
      return sleepAction(1000)
    }
  },
  // events: {
  //   caption: t('page.install.step.events.caption'),
  //   action: async () => {
  //     /**
  //      * Registering onAppInstall | onAppUninstall
  //      */
  //     await $b24.callBatch([
  //       {
  //         method: 'event.unbind',
  //         params: {
  //           event: 'ONAPPINSTALL',
  //           handler: `${appUrl}/api/event/onAppInstall`
  //         }
  //       },
  //       {
  //         method: 'event.unbind',
  //         params: {
  //           event: 'ONAPPUNINSTALL',
  //           handler: `${appUrl}/api/event/onAppUninstall`
  //         }
  //       },
  //       {
  //         method: 'event.bind',
  //         params: {
  //           event: 'ONAPPINSTALL',
  //           handler: `${appUrl}/api/event/onAppInstall`
  //         }
  //       },
  //       {
  //         method: 'event.bind',
  //         params: {
  //           event: 'ONAPPUNINSTALL',
  //           handler: `${appUrl}/api/event/onAppUninstall`
  //         }
  //       }
  //     ])
  //   }
  // },
  placement: {
    caption: t('page.install.step.placement.caption'),
    action: async () => {
      const key = {
        placement: 'CRM_DEAL_DETAIL_TAB',
        handler: `${appUrl}/handler/placement-crm-deal-detail-tab`
      }
      const exists = (steps.value.init?.data?.placementList as { placement: string, handler: string }[]).some(item => item.placement === key.placement)

      // Логируем для отладки
      $logger.log('Placement registration', {
        appUrl,
        placement: key.placement,
        handler: key.handler,
        exists,
        existingPlacements: steps.value.init?.data?.placementList
      })

      // Всегда делаем unbind если placement существует, затем bind с новым handler
      if (exists) {
        await $b24.callBatch([
          {
            method: 'placement.unbind',
            params: {
              PLACEMENT: key.placement
            }
          },
          {
            method: 'placement.bind',
            params: {
              PLACEMENT: key.placement,
              HANDLER: key.handler,
              TITLE: '[demo] Some Tab',
              OPTIONS: {
                errorHandlerUrl: `${appUrl}/handler/background-some-problem`
              }
            }
          }
        ])

        return
      }

      await $b24.callBatch([
        {
          method: 'placement.bind',
          params: {
            PLACEMENT: key.placement,
            HANDLER: key.handler,
            TITLE: '[demo] Some Tab',
            OPTIONS: {
              errorHandlerUrl: `${appUrl}/handler/background-some-problem`
            }
          }
        }
      ])
    }
  },
  userFields: {
    caption: t('page.install.step.userFields.caption'),
    action: async () => {
      const typeId = `some_type_${import.meta.dev ? 'dev' : 'prod'}`

      const exists = (steps.value.init?.data?.userFieldTypeList as { USER_TYPE_ID: string }[]).some(item => item.USER_TYPE_ID === typeId)

      // Логируем для отладки
      $logger.log('UserField registration', {
        appUrl,
        typeId,
        handler: `${appUrl}/handler/uf.demo`,
        exists,
        existingUserFieldTypes: steps.value.init?.data?.userFieldTypeList
      })

      if (exists) {
        await $b24.callBatch([
          {
            method: 'userfieldtype.update',
            params: {
              USER_TYPE_ID: typeId,
              HANDLER: `${appUrl}/handler/uf.demo`,
              TITLE: `[${import.meta.dev ? 'dev' : 'prod'}] Some Type`,
              DESCRIPTION: `Some Description`,
              OPTIONS: {
                height: 105
              }
            }
          }
        ], false)

        return
      }

      await $b24.callBatch([
        {
          method: 'userfieldtype.add',
          params: {
            USER_TYPE_ID: typeId,
            HANDLER: `${appUrl}/handler/uf.demo`,
            TITLE: `[${import.meta.dev ? 'dev' : 'prod'}] Some Type`,
            DESCRIPTION: `Some Description`,
            OPTIONS: {
              height: 105
            }
          }
        }
      ], false)
    }
  },
  // crm: {
  //   caption: t('page.install.step.crm.caption'),
  //   action: async () => {
  //     /**
  //      * Some actions for crm
  //      */
  //     if (steps.value.crm) {
  //       steps.value.crm.data = {
  //         par31: 'val31',
  //         par32: 'val32'
  //       }
  //     }
  //     return sleepAction()
  //   }
  // },
  serverSide: {
    caption: t('page.install.step.serverSide.caption'),
    action: async () => {
      const authData = $b24.auth.getAuthData()

      if(authData === false) {
        throw new Error('Some problem with auth. See App logic')
      }

      await apiStore.postInstall({
        DOMAIN: withoutTrailingSlash(authData.domain).replace('https://', '').replace('http://', ''),
        PROTOCOL: authData.domain.includes('https://') ? 1 : 0,
        LICENSE: steps.value.init?.data?.appInfo.LICENSE,
        LICENSE_FAMILY: steps.value.init?.data?.appInfo.LICENSE_FAMILY,
        LANG: $b24.getLang(),
        APP_SID: $b24.getAppSid(),
        AUTH_ID: authData.access_token,
        AUTH_EXPIRES: authData.expires_in,
        REFRESH_ID: authData.refresh_token,
        REFRESH_TOKEN: authData.refresh_token,
        member_id: authData.member_id,
        user_id: Number(steps.value.init?.data?.profile.ID),
        status: steps.value.init?.data?.appInfo.STATUS,
        appVersion: Number(steps.value.init?.data?.appInfo.VERSION),
        appCode: steps.value.init?.data?.appInfo.CODE,
        appId: Number(steps.value.init?.data?.appInfo.ID),
        PLACEMENT: $b24.placement.title,
        PLACEMENT_OPTIONS: $b24.placement.options
      })
    }
  },
  finish: {
    caption: t('page.install.step.finish.caption'),
    action: makeFinish
  }
})
const stepCode = ref<string>('init' as const)
// endregion ////

// region Actions ////
async function makeInit(): Promise<void> {
  if (steps.value.init) {
    const response = await $b24.callBatch({
      appInfo: { method: 'app.info' },
      profile: { method: 'profile' },
      userFieldTypeList: { method: 'userfieldtype.list' },
      placementList: { method: 'placement.get' }
    })

    steps.value.init.data = response.getData() as {
      appInfo: {
        ID: number
        CODE: string
        VERSION: string
        STATUS: string
        LICENSE: string
        LICENSE_FAMILY: string
        INSTALLED: boolean
      },
      profile: {
        ID: number
        ADMIN: boolean
        LAST_NAME?: string
        NAME?: string
      }
      userFieldTypeList: {
        USER_TYPE_ID: string
        HANDLER: string
        TITLE: string
        DESCRIPTION: string
      }[]
      placementList: {
        placement: string
        userId: number
        handler: string
        options: any
        title: string
        description: string
      }[]
    }
  }
}

async function makeFinish(): Promise<void> {
  progressColor.value = 'air-primary-success'
  progressValue.value = 100

  confetti.fire()
  await sleepAction(3000)

  await $b24.installFinish()
}

const stepsData = computed(() => {
  return Object.entries(steps.value).map(([index, row]) => {
    return {
      step: index,
      data: row?.data
    }
  })
})
// endregion ////

// region Lifecycle Hooks ////
onMounted(async () => {
  $logger.info('Hi from install page')

  try {
    await $b24.parent.setTitle(t('page.install.seo.title'))

    for (const [key, step] of Object.entries(steps.value)) {
      stepCode.value = key
      await step.action()
    }
  } catch (error: any) {
    processErrorGlobal(error)
  }
})
// endregion ////
</script>

<template>
  <div class="mx-3 flex flex-col items-center justify-center gap-1 h-dvh">
    <Logo
      class="size-[208px]"
      :class="[
        stepCode === 'finish' ? 'text-(--ui-color-accent-main-success)' : 'text-(--ui-color-accent-soft-green-1)'
      ]"
    />
    <B24Progress
      v-model="progressValue"
      size="xs"
      animation="elastic"
      :color="progressColor"
      class="w-1/2 sm:w-1/3"
    />
    <div class="mt-6 flex flex-col items-center justify-center gap-2">
      <ProseH1 class="text-nowrap mb-0">
        {{ $t('page.install.ui.title') }}
      </ProseH1>
      <ProseP small accent="less">
        {{ steps[stepCode]?.caption || '...' }}
      </ProseP>
    </div>

    <ProsePre v-if="isShowDebug">
      {{ stepsData }}
    </ProsePre>
  </div>
</template>
