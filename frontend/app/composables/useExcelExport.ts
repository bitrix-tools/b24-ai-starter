import { useApiStore } from '~/stores/api'

export const useExcelExport = () => {
    const apiStore = useApiStore()
    const isExporting = ref(false)

    const exportReport = async (reportType: 'employees' | 'projects', filters: any) => {
        isExporting.value = true

        try {
            const jwt = apiStore.jwt
            if (!jwt) {
                throw new Error('JWT token not found')
            }

            // Build query params
            const params = new URLSearchParams()
            if (filters.employeeId) params.append('employeeId', filters.employeeId)
            if (filters.projectId) params.append('projectId', filters.projectId)
            if (filters.dateFrom) params.append('dateFrom', filters.dateFrom)
            if (filters.dateTo) params.append('dateTo', filters.dateTo)

            const url = `/api/export/${reportType}?${params.toString()}`

            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${jwt}`
                }
            })

            if (!response.ok) {
                throw new Error(`Export failed: ${response.statusText}`)
            }

            // Download file
            const blob = await response.blob()
            const filename = reportType === 'employees'
                ? 'Отчет_по_сотрудникам.xlsx'
                : 'Отчет_по_проектам.xlsx'

            const link = document.createElement('a')
            link.href = window.URL.createObjectURL(blob)
            link.download = filename
            link.click()

            window.URL.revokeObjectURL(link.href)
        } catch (error) {
            console.error('Export error:', error)
            alert('Ошибка при экспорте отчета')
        } finally {
            isExporting.value = false
        }
    }

    return {
        isExporting,
        exportReport
    }
}
