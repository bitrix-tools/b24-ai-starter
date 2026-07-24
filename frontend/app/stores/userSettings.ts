import type { B24Frame } from '@bitrix24/b24jssdk'

/**
 * Some data for user
 */
export const useUserSettingsStore = defineStore(
  'userSettings',
  () => {
    let $b24: null | B24Frame = null

    // region State ////
    type ConfigType = {
      someValue_1: number
      someValue_2: string
      isSomeOption: boolean
    }
    type CombinedConfigTyp = ConfigType & { [key: string]: any }

    const configSettings = reactive<CombinedConfigTyp>({
      someValue_1: 30,
      someValue_2: 'some text',
      isSomeOption: true
    })
    // endregion ////

    // region Actions ////
    function setB24(b24: B24Frame) {
      $b24 = b24
    }

    /**
     * Initialize store from batch response data
     * @param data - Raw data from Bitrix24 API
     * @param data.configSettings
     */
    const initFromBatch = (data: {
      configSettings?: Record<string, any>
    }) => {
      if (data.configSettings) {
        Object.assign(configSettings, data.configSettings)
      }
    }

    /**
     * Save settings to Bitrix24
     */
    const saveSettings = async () => {
      if ($b24 === null) {
        console.error('B24 non init. Use userSettings.setB24()')
        return
      }

      return $b24.actions.v2.call.make({
        method: 'user.option.set',
        params: {
          configSettings: { ...configSettings }
        }
      })
    }

    // endregion ////

    return {
      configSettings,
      setB24,
      initFromBatch,
      saveSettings
    }
  }
)
