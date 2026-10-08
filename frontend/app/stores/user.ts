/**
 * Some info about User
 * @memo not save to settings
 */
export const useUserStore = defineStore(
  'user',
  () => {
    // region State ////
    const id = ref(0)
    const fullName = ref('')
    const isAdmin = ref(false)
    // endregion ////

    // region Actions ////
    /**
     * Initialize store from batch response data
     * @param data - Raw data from Bitrix24 API
     * @param data.id
     * @param data.name
     * @param data.lastName
     * @param data.isAdmin
     */
    function initFromBatch(data: {
      id?: number
      name?: string
      lastName?: string
      isAdmin?: boolean
    }) {
      id.value = data?.id ?? 0
      fullName.value = [data?.name, data?.lastName].filter(Boolean).join(' ')
      isAdmin.value = data.isAdmin || false
    }
    // endregion ////

    return {
      id,
      fullName,
      isAdmin,
      initFromBatch
    }
  }
)
