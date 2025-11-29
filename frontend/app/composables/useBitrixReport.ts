import { useFetch } from '#app'

export interface ReportFilter {
    dateFrom?: string
    dateTo?: string
    employeeId?: string
    projectName?: string
    projectId?: string
}

export interface ReportItem {
    id: number
    entryTitle?: string | null
    taskId: string
    taskName: string
    taskTitle?: string | null
    projectId?: string | null
    projectName: string
    hierarchyIds: string[]
    hierarchyTitles: string[]
    hours: number
    type: 'Учитываемые' | 'Неучитываемые'
    date: string
    employeeId: string
    employeeName?: string | null
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
            const url = `${baseUrl}/api/reports/data`
            console.log('fetchReportData: Sending request to', url)
            console.log('fetchReportData: filter params =', filter)
            console.log('fetchReportData: baseURL =', baseUrl)

            const response = await $fetch<{ items: ReportItem[], count: number }>('/api/reports/data', {
                baseURL: baseUrl,
                method: 'GET',
                params: filter,
                headers: {
                    Authorization: `Bearer ${apiStore.tokenJWT}`
                }
            })

            console.log('fetchReportData: Response received, items count =', response.count)
            return response
        } catch (error: any) {
            console.error('Error fetching report data:', error)
            console.error('Error details:', {
                message: error.message,
                statusCode: error.statusCode,
                statusMessage: error.statusMessage,
                data: error.data,
                cause: error.cause
            })
            throw error
        }
    }

    return {
        fetchReportData
    }
}
