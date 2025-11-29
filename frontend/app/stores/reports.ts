import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useBitrixReport, type ReportFilter, type ReportItem } from '#imports'

export const useReportsStore = defineStore('reports', () => {
    const { fetchReportData } = useBitrixReport()

    const items = ref<ReportItem[]>([])
    const isLoading = ref(false)
    const error = ref<string | null>(null)

    // Current Filter State
    const currentFilter = ref<ReportFilter>({})

    const fetchReports = async (filter: ReportFilter) => {
        console.log('ReportsStore: fetchReports called with filter:', filter)
        isLoading.value = true
        error.value = null
        currentFilter.value = filter

        try {
            console.log('ReportsStore: calling fetchReportData...')
            const data = await fetchReportData(filter)
            console.log('ReportsStore: fetchReportData returned data:', data)

            if (data) {
                items.value = data.items
                console.log('ReportsStore: items set, count:', items.value.length)
            }
        } catch (e: any) {
            console.error('ReportsStore: Error in fetchReports:', e)
            error.value = e.message || 'Failed to fetch reports'
        } finally {
            isLoading.value = false
            console.log('ReportsStore: fetchReports finished, isLoading=false')
        }
    }

    // Getters for specific reports could go here
    // For example, grouping by Employee -> Project -> Task

    const totalItems = computed(() => items.value.length)

    return {
        items,
        isLoading,
        error,
        currentFilter,
        fetchReports,
        totalItems
    }
})
