import type { B24Frame } from '@bitrix24/b24jssdk'

export interface DealCategoryOption {
  id: number
  name: string
}

export interface DealStageOption {
  value: string
  label: string
}

export interface DealCategoriesAndStages {
  categories: DealCategoryOption[]
  stagesByCategory: Record<number, DealStageOption[]>
  allStages: DealStageOption[]
}

const STAGE_NONE: DealStageOption = { value: '', label: 'Не перемещать' }

/**
 * Стадии сделок для селектов настроек.
 * Раньше было N+1 REST-вызовов (список воронок + stage.list на каждую) — десятки/сотни запросов.
 * Через callBatchByChunk — по 50 команд в одном batch к порталу.
 */
export async function fetchDealCategoriesAndStages(
  $b24: B24Frame,
): Promise<DealCategoriesAndStages> {
  const categories: DealCategoryOption[] = []
  const stagesByCategory: Record<number, DealStageOption[]> = {}
  const allStages: DealStageOption[] = [STAGE_NONE]

  const categoriesResp = await $b24.callMethod('crm.dealcategory.list')
  const rawCategories = [
    { ID: 0, NAME: 'Основная воронка' },
    ...(categoriesResp.getData().result || []),
  ]

  const batchCalls = rawCategories.map((cat) => ({
    method: 'crm.dealcategory.stage.list' as const,
    params: { id: cat.ID },
  }))

  const batchResult = await $b24.callBatchByChunk(batchCalls, true)
  const lists = batchResult.getData() as unknown[]

  lists.forEach((stageList, index) => {
    const cat = rawCategories[index]
    if (!cat) return
    const categoryId = Number(cat.ID)
    categories.push({ id: categoryId, name: String(cat.NAME || 'Воронка') })
    const categoryStages: DealStageOption[] = [STAGE_NONE]
    const rows = Array.isArray(stageList) ? stageList : []
    for (const s of rows as Array<{ STATUS_ID: string; NAME: string }>) {
      const option = {
        value: s.STATUS_ID,
        label: s.NAME,
      }
      categoryStages.push(option)
      allStages.push({
        value: s.STATUS_ID,
        label: `${cat.NAME}: ${s.NAME}`,
      })
    }
    stagesByCategory[categoryId] = categoryStages
  })

  return { categories, stagesByCategory, allStages }
}

export async function fetchDealStagesForSettings(
  $b24: B24Frame,
): Promise<DealStageOption[]> {
  const data = await fetchDealCategoriesAndStages($b24)
  return data.allStages
}
