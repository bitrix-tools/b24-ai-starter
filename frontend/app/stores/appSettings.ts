import type { B24Frame, TypeEnumAppStatus } from '@bitrix24/b24jssdk'

/**
 * Some info about App
 */
export const useAppSettingsStore = defineStore(
  'appSettings',
  () => {
    let $b24: null | B24Frame = null

    // region State ////
    type ConfigType = {
      someValue_1: number
      someValue_2: string
      isSomeOption: boolean
    }
    type CombinedConfigTyp = ConfigType & { [key: string]: any }

    const version = ref(0)
    const status = ref<TypeEnumAppStatus>('Free')
    const isTrial = ref(true)
    const configSettings = reactive<CombinedConfigTyp>({
      someValue_1: 130,
      someValue_2: 'app some text',
      isSomeOption: false
    })
    // endregion ////

    // region Actions ////
    function setB24(b24: B24Frame) {
      $b24 = b24
    }

    /**
     * Initialize store from batch response data
     * @param data - Raw data from Bitrix24 API
     * @param data.version
     * @param data.status
     * @param data.configSettings
     */
    function initFromBatch(data: {
      version?: number
      status?: TypeEnumAppStatus
      configSettings?: Record<string, any>
    }) {
      if (data.status) {
        status.value = data.status
        isTrial.value = status.value === 'Trial'
      }

      if (data.version) {
        version.value = data.version
      }

      if (data.configSettings) {
        Object.assign(configSettings, data.configSettings)
      }
    }

    /**
     * Save settings to Bitrix24
     */
    const saveSettings = async () => {
      if ($b24 === null) {
        console.error('B24 non init. Use appSettings.setB24()')
        return
      }

      return $b24.actions.v2.call.make({
        method: 'app.option.set',
        params: {
          configSettings: { ...configSettings }
        }
      })
    }
    // endregion ////

    return {
      status,
      version,
      isTrial,
      setB24,
      initFromBatch,
      saveSettings,
      configSettings
    }
  }
)
