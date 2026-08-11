import { withoutTrailingSlash } from 'ufo'

export interface TochkaAccount {
  accountId: string
  currency: string
  accountType: string
  accountSubType: string
  description?: string
  customerCode?: string
}

function normalizeTochkaAccount(raw: Record<string, unknown>): TochkaAccount {
  return {
    accountId: String(raw.accountId ?? raw.AccountId ?? ''),
    currency: String(raw.currency ?? raw.Currency ?? ''),
    accountType: String(raw.accountType ?? raw.AccountType ?? ''),
    accountSubType: String(raw.accountSubType ?? raw.AccountSubType ?? ''),
    description: String(raw.description ?? raw.Description ?? '').trim() || undefined,
    customerCode: raw.customerCode != null
      ? String(raw.customerCode)
      : raw.CustomerCode != null
        ? String(raw.CustomerCode)
        : undefined,
  }
}

export interface TochkaBalance {
  accountId: string
  amount: { amount: string; currency: string }
  creditDebitIndicator: string
  type: string
  dateTime: string
}

export interface TochkaStatus {
  connected: boolean
  hasCredentials: boolean
  clientId: string | null
  /** Instant bank push / webhook for statements (env TOCHKA_BANK_PUSH_CONFIGURED) */
  bankPushConfigured?: boolean
  /** Recommended / documented background sync interval in minutes */
  scheduledSyncIntervalMinutes?: number
  clientAutoSyncEnabled?: boolean
  clientAutoSyncIntervalMinutes?: number
  /** Last successful sync timestamp from server (manual or background). */
  lastSyncAt?: string | null
}

export interface AccountDisplaySettings {
  /** Пользовательское название организации (если пусто — из API Точки). */
  organizationName?: string
  prefix: string
  suffix: string
}

export type TochkaSyncParams =
  | { days: number }
  | { startDate: string; endDate: string }

/** Structured filters for the payments list search field. */
export interface PaymentSearchFilters {
  text?: string
  amount?: string
  dateFrom?: string
  dateTo?: string
  counterparty?: string
}

/** Правило перемещения сделки для конкретной воронки. */
export interface DealCategoryStageRule {
  categoryId: number
  stagePaidId: string
  moveOnPartial: boolean
  stagePartialId: string
  allowBackwardMove: boolean
}

/** Настройки блока «Сопоставлять платежи со сделками» (вкладка Сопоставление). */
export interface DealMatchingPreferences {
  enabled: boolean
  autoFindEach: boolean
  byInn: boolean
  byName: boolean
  byInvoiceInPurpose: boolean
  byAmount: boolean
  /** Сопоставление по телефону плательщика (актуально для СБП, где в назначении часто только телефон). */
  byPhonePayer: boolean
  autoWrite: boolean
  partial: boolean
  stagePaidId: string
  /** Воронка для полной оплаты (-1 = не перемещать). */
  stagePaidCategoryId: number
  moveOnPartial: boolean
  stagePartialId: string
  /** Воронка для частичной оплаты (-1 = не перемещать). */
  stagePartialCategoryId: number
  /** Настроить правила перемещения отдельно для каждой воронки. */
  perCategoryEnabled: boolean
  /** Разрешить перемещение на более раннюю стадию (глобальный режим). */
  allowBackwardMove: boolean
  /** Правила перемещения по воронкам (если perCategoryEnabled). */
  categoryRules: DealCategoryStageRule[]
  innUf: string
  nameUf: string
  invoiceUf: string
  phoneUf: string
}

export const DEAL_STAGE_NO_MOVE = -1

export function inferDealCategoryIdFromStage(stageId: string): number {
  const m = /^C(\d+):/.exec(stageId)
  if (m) return Number(m[1])
  return stageId ? 0 : DEAL_STAGE_NO_MOVE
}

export function defaultDealCategoryStageRule(categoryId = 0): DealCategoryStageRule {
  return {
    categoryId,
    stagePaidId: '',
    moveOnPartial: false,
    stagePartialId: '',
    allowBackwardMove: false,
  }
}

export function defaultDealMatchingPreferences(): DealMatchingPreferences {
  return {
    enabled: true,
    autoFindEach: false,
    byInn: false,
    byName: false,
    byInvoiceInPurpose: true,
    byAmount: true,
    byPhonePayer: false,
    autoWrite: true,
    partial: false,
    stagePaidId: '',
    stagePaidCategoryId: DEAL_STAGE_NO_MOVE,
    moveOnPartial: false,
    stagePartialId: '',
    stagePartialCategoryId: DEAL_STAGE_NO_MOVE,
    perCategoryEnabled: false,
    allowBackwardMove: false,
    categoryRules: [],
    innUf: '',
    nameUf: '',
    invoiceUf: '',
    phoneUf: '',
  }
}

export function normalizeDealMatchingPreferences(raw: Partial<DealMatchingPreferences> | null | undefined): DealMatchingPreferences {
  const d = defaultDealMatchingPreferences()
  if (!raw || typeof raw !== 'object') return d
  const o = raw as Record<string, unknown>
  if (typeof o.enabled === 'boolean') d.enabled = o.enabled
  if (typeof o.autoFindEach === 'boolean') d.autoFindEach = o.autoFindEach
  if (typeof o.byInn === 'boolean') d.byInn = o.byInn
  if (typeof o.byName === 'boolean') d.byName = o.byName
  if (typeof o.byInvoiceInPurpose === 'boolean') d.byInvoiceInPurpose = o.byInvoiceInPurpose
  if (typeof o.byAmount === 'boolean') d.byAmount = o.byAmount
  if (typeof o.byPhonePayer === 'boolean') d.byPhonePayer = o.byPhonePayer
  if (typeof o.autoWrite === 'boolean') d.autoWrite = o.autoWrite
  if (typeof o.partial === 'boolean') d.partial = o.partial
  if (typeof o.moveOnPartial === 'boolean') d.moveOnPartial = o.moveOnPartial
  if (typeof o.stagePaidId === 'string') d.stagePaidId = o.stagePaidId
  if (typeof o.stagePartialId === 'string') d.stagePartialId = o.stagePartialId
  if (typeof o.stagePaidCategoryId === 'number') {
    d.stagePaidCategoryId = o.stagePaidCategoryId
  } else if (d.stagePaidId) {
    d.stagePaidCategoryId = inferDealCategoryIdFromStage(d.stagePaidId)
  }
  if (typeof o.stagePartialCategoryId === 'number') {
    d.stagePartialCategoryId = o.stagePartialCategoryId
  } else if (d.stagePartialId) {
    d.stagePartialCategoryId = inferDealCategoryIdFromStage(d.stagePartialId)
  }
  if (!d.stagePaidId) d.stagePaidCategoryId = DEAL_STAGE_NO_MOVE
  if (!d.stagePartialId && !d.moveOnPartial) d.stagePartialCategoryId = DEAL_STAGE_NO_MOVE
  if (typeof o.perCategoryEnabled === 'boolean') d.perCategoryEnabled = o.perCategoryEnabled
  if (typeof o.allowBackwardMove === 'boolean') d.allowBackwardMove = o.allowBackwardMove
  if (Array.isArray(o.categoryRules)) {
    d.categoryRules = o.categoryRules
      .filter((r): r is Record<string, unknown> => !!r && typeof r === 'object')
      .map((r) => ({
        categoryId: Number(r.categoryId ?? 0),
        stagePaidId: typeof r.stagePaidId === 'string' ? r.stagePaidId : '',
        moveOnPartial: Boolean(r.moveOnPartial),
        stagePartialId: typeof r.stagePartialId === 'string' ? r.stagePartialId : '',
        allowBackwardMove: Boolean(r.allowBackwardMove),
      }))
  }
  if (typeof o.innUf === 'string') d.innUf = o.innUf
  if (typeof o.nameUf === 'string') d.nameUf = o.nameUf
  if (typeof o.invoiceUf === 'string') d.invoiceUf = o.invoiceUf
  if (typeof o.phoneUf === 'string') d.phoneUf = o.phoneUf
  return d
}

export interface TochkaPortalPreferences {
  clientAutoSyncEnabled: boolean
  clientAutoSyncIntervalMinutes: number
  lastSyncAt?: string | null
  dealPaymentKindUfCode: string
  dealPaymentChannelUfCode: string
  leadPaymentKindUfCode: string
  leadPaymentChannelUfCode: string
  notifyDealResponsible: boolean
  notifyDealTimeline: boolean
  notifyChatId: number | null
  /** Заполняется на сервере автоматически; в UI не показывается */
  notifyChatBotId: number | null
  invoiceAutoUpdateStage: boolean
  invoiceStageIdPaid: string
  invoiceStageIdPartial: string
  invoiceMatchByInn: boolean
  invoiceMatchByAmount: boolean
  dealMatching: DealMatchingPreferences
}

export interface CrmEntityPaymentsResponse {
  payments: any[]
  sumPaid: number
  entityAmount: number | null
  currencyId: string
  remaining: number | null
  overpayment: number | null
}

export const useTochkaStore = defineStore('tochka', () => {
  const config = useRuntimeConfig()
  const apiUrl = withoutTrailingSlash(config.public.apiUrl)
  const apiStore = useApiStore()

  const status = ref<TochkaStatus>({
    connected: false,
    hasCredentials: false,
    clientId: null,
    bankPushConfigured: false,
    scheduledSyncIntervalMinutes: 120,
  })
  const accounts = ref<TochkaAccount[]>([])
  const balances = ref<Record<string, TochkaBalance[]>>({})
  const selectedAccountSettings = ref<Record<string, AccountDisplaySettings>>({})
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  function _tokenHeader() {
    return { Authorization: `Bearer ${apiStore.tokenJWT}` }
  }

  const $api = $fetch.create({
    baseURL: apiUrl,
    headers: { 'Content-Type': 'application/json' },
  })

  function applyServerLastSync(value: string | null | undefined) {
    if (value) {
      lastSyncDate.value = value
    }
  }

  async function fetchStatus() {
    try {
      const data = await $api<TochkaStatus>('/api/tochka/status', {
        headers: _tokenHeader(),
      })
      status.value = {
        ...data,
        bankPushConfigured: data.bankPushConfigured === true,
        scheduledSyncIntervalMinutes: data.scheduledSyncIntervalMinutes ?? 120,
        clientAutoSyncEnabled: data.clientAutoSyncEnabled === true,
        clientAutoSyncIntervalMinutes: data.clientAutoSyncIntervalMinutes ?? 15,
        lastSyncAt: data.lastSyncAt ?? null,
      }
      applyServerLastSync(data.lastSyncAt)
      if (data.clientAutoSyncEnabled !== undefined) {
        portalPreferences.value.clientAutoSyncEnabled = !!data.clientAutoSyncEnabled
      }
      if (data.clientAutoSyncIntervalMinutes !== undefined) {
        portalPreferences.value.clientAutoSyncIntervalMinutes = Math.max(
          3,
          Math.min(Number(data.clientAutoSyncIntervalMinutes) || 15, 180),
        )
      }
    } catch {
      status.value = {
        connected: false,
        hasCredentials: false,
        clientId: null,
        bankPushConfigured: false,
        scheduledSyncIntervalMinutes: 120,
      }
    }
  }

  async function connect(clientId: string, clientSecret: string) {
    isLoading.value = true
    error.value = null
    try {
      await $api('/api/tochka/connect', {
        method: 'POST',
        headers: _tokenHeader(),
        body: JSON.stringify({ clientId, clientSecret }),
      })
      await fetchStatus()
    } catch (e: any) {
      error.value = e?.data?.error || e?.message || 'Connection failed'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function connectWithEnvCredentials(opts?: { popup?: boolean }): Promise<string | null> {
    isLoading.value = true
    error.value = null
    try {
      const response = await $api<{ success: boolean; authUrl?: string }>('/api/tochka/connect-env', {
        method: 'POST',
        headers: _tokenHeader(),
        body: JSON.stringify(opts?.popup ? { popup: true } : {}),
      })
      return response.authUrl || null
    } catch (e: any) {
      error.value = e?.data?.error || e?.message || 'Connection failed'
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function disconnect() {
    isLoading.value = true
    try {
      await $api('/api/tochka/disconnect', {
        method: 'POST',
        headers: _tokenHeader(),
      })
      status.value = {
        connected: false,
        hasCredentials: false,
        clientId: null,
        bankPushConfigured: false,
        scheduledSyncIntervalMinutes: 120,
      }
      accounts.value = []
      balances.value = {}
    } finally {
      isLoading.value = false
    }
  }

  async function fetchAccounts() {
    isLoading.value = true
    error.value = null
    try {
      const data = await $api<any>('/api/tochka/accounts', {
        headers: _tokenHeader(),
      })
      const rawAccounts = data?.Data?.Account || []
      const list = Array.isArray(rawAccounts) ? rawAccounts : [rawAccounts]
      accounts.value = list.map((item: Record<string, unknown>) => normalizeTochkaAccount(item))
    } catch (e: any) {
      error.value = e?.data?.error || 'Failed to load accounts'
      accounts.value = []
    } finally {
      isLoading.value = false
    }
  }

  async function fetchBalances(accountId: string) {
    try {
      const data = await $api<any>(
        `/api/tochka/accounts/${accountId}/balances`,
        { headers: _tokenHeader() }
      )
      balances.value[accountId] = data?.Data?.Balance || []
    } catch {
      balances.value[accountId] = []
    }
  }

  async function initStatement(
    accountId: string,
    startDate: string,
    endDate: string
  ): Promise<string | null> {
    try {
      const data = await $api<any>(
        `/api/tochka/accounts/${accountId}/statements`,
        {
          method: 'POST',
          headers: _tokenHeader(),
          body: JSON.stringify({ startDate, endDate }),
        }
      )
      return data?.Data?.Statement?.statementId || null
    } catch (e: any) {
      error.value = e?.data?.error || 'Failed to create statement'
      return null
    }
  }

  async function fetchStatement(statementId: string): Promise<any> {
    try {
      const data = await $api<any>(
        `/api/tochka/statements/${statementId}`,
        { headers: _tokenHeader() }
      )
      return data?.Data?.Statement?.[0] || null
    } catch {
      return null
    }
  }

  async function fetchCustomer(): Promise<any> {
    try {
      return await $api<any>('/api/tochka/customer', {
        headers: _tokenHeader(),
      })
    } catch {
      return null
    }
  }

  function setSelectedAccountSettings(settings: Record<string, AccountDisplaySettings>) {
    selectedAccountSettings.value = { ...settings }
  }

  // Payments
  const payments = ref<any[]>([])
  /** Эквайринг с page=1 — подмешиваем на следующих страницах, т.к. API отдаёт строки выписки постранично. */
  const paymentsAcquiringCache = ref<Record<string, unknown>[]>([])
  const paymentsTotal = ref(0)
  const paymentsTotalAmount = ref(0)
  const paymentsCounts = ref<Record<string, number>>({})
  const lastSyncDate = ref<string | null>(null)
  const lastSyncError = ref<string | null>(null)

  const portalPreferences = ref<TochkaPortalPreferences>({
    clientAutoSyncEnabled: false,
    clientAutoSyncIntervalMinutes: 15,
    dealPaymentKindUfCode: '',
    dealPaymentChannelUfCode: '',
    leadPaymentKindUfCode: '',
    leadPaymentChannelUfCode: '',
    notifyDealResponsible: true,
    notifyDealTimeline: true,
    notifyChatId: null,
    notifyChatBotId: null,
    invoiceAutoUpdateStage: false,
    invoiceStageIdPaid: '',
    invoiceStageIdPartial: '',
    invoiceMatchByInn: false,
    invoiceMatchByAmount: true,
    dealMatching: defaultDealMatchingPreferences(),
  })

  async function syncPayments(params: TochkaSyncParams = { days: 30 }): Promise<{ synced: number }> {
    isLoading.value = true
    error.value = null
    lastSyncError.value = null
    const body =
      'startDate' in params
        ? { startDate: params.startDate, endDate: params.endDate }
        : { days: params.days }
    try {
      const resp = await $api<{ synced: number; lastSync: string }>('/api/tochka/sync', {
        method: 'POST',
        headers: _tokenHeader(),
        body: JSON.stringify(body),
      })
      lastSyncDate.value = resp.lastSync
      lastSyncError.value = null
      return resp
    } catch (e: any) {
      const msg = e?.data?.error || 'Sync failed'
      error.value = msg
      lastSyncError.value = msg
      throw e
    } finally {
      isLoading.value = false
    }
  }

  async function fetchCrmEntityPayments(params: {
    dealId?: number
    leadId?: number
    entityAmount?: number | null
    currencyId?: string
  }): Promise<CrmEntityPaymentsResponse> {
    const q = new URLSearchParams()
    if (params.dealId != null) {
      q.set('deal_id', String(params.dealId))
    }
    if (params.leadId != null) {
      q.set('lead_id', String(params.leadId))
    }
    if (params.entityAmount != null && !Number.isNaN(Number(params.entityAmount))) {
      q.set('entity_amount', String(params.entityAmount))
    }
    if (params.currencyId) {
      q.set('currency_id', params.currencyId)
    }
    return await $api<CrmEntityPaymentsResponse>(`/api/tochka/crm-payments?${q}`, {
      headers: _tokenHeader(),
    })
  }

  async function fetchPayments(
    page: number = 1,
    statusFilter: string = 'all',
    filters: PaymentSearchFilters = {},
  ) {
    try {
      const params = new URLSearchParams({ page: String(page), status: statusFilter })
      const text = filters.text?.trim()
      const amount = filters.amount?.trim()
      const counterparty = filters.counterparty?.trim()
      if (text) params.set('search', text)
      if (amount) params.set('amount', amount)
      if (filters.dateFrom) params.set('date_from', filters.dateFrom)
      if (filters.dateTo) params.set('date_to', filters.dateTo)
      if (counterparty) params.set('counterparty', counterparty)
      const resp = await $api<any>(`/api/tochka/payments?${params}`, {
        headers: _tokenHeader(),
      })
      const bank = (resp.payments || []).map((p: Record<string, unknown>) => ({
        ...p,
        payment_source: p.payment_source || 'bank',
      }))
      const acqFromServer = (resp.acquiring_payments || []) as Record<string, unknown>[]
      if (page === 1) {
        paymentsAcquiringCache.value = acqFromServer
      }
      const acq = page === 1 ? acqFromServer : paymentsAcquiringCache.value
      const merged = [...bank, ...acq]
      merged.sort((a, b) => {
        const ta = a.transaction_date ? new Date(String(a.transaction_date)).getTime() : 0
        const tb = b.transaction_date ? new Date(String(b.transaction_date)).getTime() : 0
        return tb - ta
      })
      payments.value = merged
      paymentsTotal.value = resp.total || 0
      paymentsTotalAmount.value = resp.total_amount || 0
      paymentsCounts.value = resp.counts || {}
    } catch {
      payments.value = []
      paymentsAcquiringCache.value = []
    }
  }

  async function paymentAction(paymentId: string, action: string, data?: Record<string, any>) {
    await $api(`/api/tochka/payments/${paymentId}/action`, {
      method: 'POST',
      headers: _tokenHeader(),
      body: JSON.stringify({ action, ...data }),
    })
  }

  async function fetchPortalPreferences() {
    try {
      const data = await $api<TochkaPortalPreferences>('/api/tochka/preferences', {
        headers: _tokenHeader(),
      })
      portalPreferences.value = {
        clientAutoSyncEnabled: !!data.clientAutoSyncEnabled,
        clientAutoSyncIntervalMinutes: Math.max(
          3,
          Math.min(Number(data.clientAutoSyncIntervalMinutes) || 15, 180),
        ),
        lastSyncAt: data.lastSyncAt ?? null,
        dealPaymentKindUfCode: String(data.dealPaymentKindUfCode ?? ''),
        dealPaymentChannelUfCode: String(data.dealPaymentChannelUfCode ?? ''),
        leadPaymentKindUfCode: String(data.leadPaymentKindUfCode ?? ''),
        leadPaymentChannelUfCode: String(data.leadPaymentChannelUfCode ?? ''),
        notifyDealResponsible: data.notifyDealResponsible ?? true,
        notifyDealTimeline: data.notifyDealTimeline ?? true,
        notifyChatId: data.notifyChatId ?? null,
        notifyChatBotId: data.notifyChatBotId ?? null,
        invoiceAutoUpdateStage: !!data.invoiceAutoUpdateStage,
        invoiceStageIdPaid: String(data.invoiceStageIdPaid ?? ''),
        invoiceStageIdPartial: String(data.invoiceStageIdPartial ?? ''),
        invoiceMatchByInn: !!data.invoiceMatchByInn,
        invoiceMatchByAmount: data.invoiceMatchByAmount !== false,
        dealMatching: normalizeDealMatchingPreferences(
          (data as { dealMatching?: Partial<DealMatchingPreferences> }).dealMatching,
        ),
      }
      applyServerLastSync(data.lastSyncAt)
    } catch {
      /* keep defaults */
    }
  }

  async function savePortalPreferences(p: Partial<TochkaPortalPreferences>) {
    const body: Record<string, any> = {
      clientAutoSyncEnabled: p.clientAutoSyncEnabled ?? portalPreferences.value.clientAutoSyncEnabled,
      clientAutoSyncIntervalMinutes:
        p.clientAutoSyncIntervalMinutes ?? portalPreferences.value.clientAutoSyncIntervalMinutes,
      dealPaymentKindUfCode: p.dealPaymentKindUfCode ?? portalPreferences.value.dealPaymentKindUfCode,
      dealPaymentChannelUfCode:
        p.dealPaymentChannelUfCode ?? portalPreferences.value.dealPaymentChannelUfCode,
      leadPaymentKindUfCode: p.leadPaymentKindUfCode ?? portalPreferences.value.leadPaymentKindUfCode,
      leadPaymentChannelUfCode:
        p.leadPaymentChannelUfCode ?? portalPreferences.value.leadPaymentChannelUfCode,
    }
    if ('notifyDealResponsible' in p) body.notifyDealResponsible = p.notifyDealResponsible
    if ('notifyDealTimeline' in p) body.notifyDealTimeline = p.notifyDealTimeline
    if ('notifyChatId' in p) body.notifyChatId = p.notifyChatId
    if ('notifyChatBotId' in p) body.notifyChatBotId = p.notifyChatBotId
    body.invoiceAutoUpdateStage = p.invoiceAutoUpdateStage ?? portalPreferences.value.invoiceAutoUpdateStage
    body.invoiceStageIdPaid = p.invoiceStageIdPaid ?? portalPreferences.value.invoiceStageIdPaid
    body.invoiceStageIdPartial = p.invoiceStageIdPartial ?? portalPreferences.value.invoiceStageIdPartial
    body.invoiceMatchByInn = p.invoiceMatchByInn ?? portalPreferences.value.invoiceMatchByInn
    body.invoiceMatchByAmount = p.invoiceMatchByAmount ?? portalPreferences.value.invoiceMatchByAmount
    if ('dealMatching' in p && p.dealMatching) {
      body.dealMatching = p.dealMatching
    }
    const data = await $api<TochkaPortalPreferences>('/api/tochka/preferences', {
      method: 'POST',
      headers: _tokenHeader(),
      body: JSON.stringify(body),
    })
    portalPreferences.value = {
      clientAutoSyncEnabled: !!data.clientAutoSyncEnabled,
      clientAutoSyncIntervalMinutes: Math.max(
        3,
        Math.min(Number(data.clientAutoSyncIntervalMinutes) || 15, 180),
      ),
      lastSyncAt: data.lastSyncAt ?? portalPreferences.value.lastSyncAt ?? null,
      dealPaymentKindUfCode: String(data.dealPaymentKindUfCode ?? ''),
      dealPaymentChannelUfCode: String(data.dealPaymentChannelUfCode ?? ''),
      leadPaymentKindUfCode: String(data.leadPaymentKindUfCode ?? ''),
      leadPaymentChannelUfCode: String(data.leadPaymentChannelUfCode ?? ''),
      notifyDealResponsible: data.notifyDealResponsible ?? true,
      notifyDealTimeline: data.notifyDealTimeline ?? true,
      notifyChatId: data.notifyChatId ?? null,
      notifyChatBotId: data.notifyChatBotId ?? null,
      invoiceAutoUpdateStage: !!data.invoiceAutoUpdateStage,
      invoiceStageIdPaid: String(data.invoiceStageIdPaid ?? ''),
      invoiceStageIdPartial: String(data.invoiceStageIdPartial ?? ''),
      invoiceMatchByInn: !!data.invoiceMatchByInn,
      invoiceMatchByAmount: data.invoiceMatchByAmount !== false,
      dealMatching: normalizeDealMatchingPreferences(
        (data as { dealMatching?: Partial<DealMatchingPreferences> }).dealMatching,
      ),
    }
  }

  async function fetchAcquiringRetailers(): Promise<any> {
    return await $api('/api/tochka/acquiring/retailers', { headers: _tokenHeader() })
  }

  async function createAcquiringPaymentLink(body: {
    dealId?: number
    leadId?: number
    amount: string | number
    paymentKind: string
    paymentModes: string[]
    merchantId?: string
    purpose?: string
  }): Promise<{
    operationId?: string
    paymentUrl?: string | null
    paymentModesRequested?: string[]
    paymentModesApplied?: string[]
    retailerPaymentModes?: string[] | null
    paymentModesDropped?: string[]
    raw?: any
  }> {
    return await $api('/api/tochka/acquiring/payment-links', {
      method: 'POST',
      headers: _tokenHeader(),
      body: JSON.stringify(body),
    })
  }

  async function refreshAcquiringStatuses(): Promise<{ updated: number }> {
    return await $api<{ updated: number }>('/api/tochka/acquiring/refresh-statuses', {
      method: 'POST',
      headers: _tokenHeader(),
    })
  }

  async function unlinkAcquiringOperation(operationId: string) {
    await $api(`/api/tochka/acquiring/operations/${operationId}/unlink`, {
      method: 'POST',
      headers: _tokenHeader(),
    })
  }

  async function refundAcquiringOperation(operationId: string, body?: { amount?: number }) {
    return await $api<{ success?: boolean; error?: string; code?: string }>(
      `/api/tochka/acquiring/operations/${operationId}/refund`,
      {
        method: 'POST',
        headers: _tokenHeader(),
        body: JSON.stringify(body || {}),
      },
    )
  }

  return {
    status,
    accounts,
    balances,
    selectedAccountSettings,
    payments,
    paymentsTotal,
    paymentsTotalAmount,
    paymentsCounts,
    lastSyncDate,
    lastSyncError,
    portalPreferences,
    isLoading,
    error,
    fetchStatus,
    connect,
    connectWithEnvCredentials,
    disconnect,
    fetchAccounts,
    fetchBalances,
    initStatement,
    fetchStatement,
    fetchCustomer,
    setSelectedAccountSettings,
    syncPayments,
    fetchPayments,
    fetchCrmEntityPayments,
    paymentAction,
    fetchPortalPreferences,
    savePortalPreferences,
    fetchAcquiringRetailers,
    createAcquiringPaymentLink,
    refreshAcquiringStatuses,
    unlinkAcquiringOperation,
    refundAcquiringOperation,
  }
})
