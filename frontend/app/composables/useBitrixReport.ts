import { useFetch } from '#app'

export interface ReportFilter {
    dateFrom?: string
    dateTo?: string
    employeeId?: string
    projectName?: string
}

export interface ReportItem {
    id: number
    taskId: string
    taskName: string
    projectName: string
    hierarchyIds: string[]
    hierarchyTitles: string[]
    hours: number
    type: 'Учитываемые' | 'Неучитываемые'
    date: string
    employeeId: string
}

export const useBitrixReport = () => {
    const config = useRuntimeConfig()
    const baseUrl = config.public.apiUrl || ''

    const fetchReportData = async (filter: ReportFilter) => {
        const apiStore = useApiStore()

        console.log('fetchReportData: tokenJWT =', apiStore.tokenJWT)
        console.log('fetchReportData: isInitTokenJWT =', apiStore.isInitTokenJWT)

        if (!apiStore.tokenJWT) {
            console.error('JWT token is not initialized!')
            throw new Error('JWT token is not initialized. Please ensure the app is opened from Bitrix24.')
        }

        try {
            const response = await $fetch<{ items: ReportItem[], count: number }>('/api/reports/data', {
                baseURL: baseUrl,
                method: 'GET',
                params: filter,
                headers: {
                    Authorization: `Bearer ${apiStore.tokenJWT}`
                }
            })

            return { data: response, pending: false }
        } catch (error) {
            console.error('Error fetching report data:', error)
            throw error
        }
    }

    return {
        fetchReportData
    }
}
