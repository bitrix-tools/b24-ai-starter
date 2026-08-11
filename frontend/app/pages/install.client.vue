<script setup lang="ts">
import type { IStep } from '#shared/types/base'
import type { B24Frame } from '@bitrix24/b24jssdk'
import { ref, onMounted } from 'vue'
import { sleepAction } from '~/utils/sleep'
import { withoutTrailingSlash } from 'ufo'
import AppLaunchSplash from '~/components/AppLaunchSplash.vue'

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
      const errorHandlerUrl = `${appUrl}/handler/background-some-problem`
      const placementDefs = [
        {
          placement: 'CRM_DEAL_DETAIL_TAB',
          handler: `${appUrl}/handler/placement-crm-deal-detail-tab`,
          title: t('page.install.step.placement.deal_tab_title'),
        },
        {
          placement: 'CRM_LEAD_DETAIL_TAB',
          handler: `${appUrl}/handler/placement-crm-lead-detail-tab`,
          title: t('page.install.step.placement.lead_tab_title'),
        },
      ] as const
      const list = (steps.value.init?.data?.placementList || []) as { placement: string; handler: string }[]
      const batch: { method: string; params: Record<string, unknown> }[] = []
      for (const def of placementDefs) {
        const exists = list.some((item) => item.placement === def.placement && item.handler === def.handler)
        if (exists) {
          batch.push({ method: 'placement.unbind', params: { PLACEMENT: def.placement } })
        }
        batch.push({
          method: 'placement.bind',
          params: {
            PLACEMENT: def.placement,
            HANDLER: def.handler,
            TITLE: def.title,
            OPTIONS: { errorHandlerUrl },
          },
        })
      }
      await $b24.callBatch(batch)
    }
  },
  userFields: {
    caption: t('page.install.step.userFields.caption'),
    action: async () => {
      const typeId = `some_type_${import.meta.dev ? 'dev' : 'prod'}`

      const exists = (steps.value.init?.data?.userFieldTypeList as { USER_TYPE_ID: string }[]).some(item => item.USER_TYPE_ID === typeId)
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
        options: unknown
        title: string
        description: string
      }[]
    }
  }
}

async function makeFinish(): Promise<void> {
  await $b24.installFinish()
}
// endregion ////

// region Lifecycle Hooks ////
onMounted(async () => {
  $logger.info('Hi from install page')

  try {
    await $b24.parent.setTitle(t('page.install.seo.title'))

    for (const step of Object.values(steps.value)) {
      await step.action()
    }
  } catch (error: unknown) {
    processErrorGlobal(error)
  }
})
// endregion ////
</script>

<template>
  <AppLaunchSplash class="min-h-dvh bg-white" />
</template>
