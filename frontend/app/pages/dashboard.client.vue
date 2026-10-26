<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import { nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import {
  defaultDealMatchingPreferences,
  defaultDealCategoryStageRule,
  normalizeDealMatchingPreferences,
  DEAL_STAGE_NO_MOVE,
} from '~/stores/tochka'
import type { DealMatchingPreferences, PaymentSearchFilters } from '~/stores/tochka'
import { fetchDealCategoriesAndStages } from '~/utils/crmDealStages'
import type { DealCategoryOption, DealStageOption } from '~/utils/crmDealStages'

definePageMeta({ layout: 'setup' })

const { t, locales: localesI18n, setLocale } = useI18n()

useHead({ title: 'Точка Банк' })

const { $logger, initApp, processErrorGlobal } = useAppInit('DashboardPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const tochkaStore = useTochkaStore()

const isInit = ref(false)
const isLoadingData = ref(false)
/** Ручная синхронизация (к кнопкам и «жёлтой» точке); фоновая не блокирует таблицу и не меняет точку на жёлтую. */
const isManualSyncing = ref(false)
const isBackgroundSyncing = ref(false)
const syncError = ref('')
const activeTab = ref<'payments' | 'accounts' | 'settings'>('payments')
const dealStages = ref<DealStageOption[]>([])
const dealCategories = ref<DealCategoryOption[]>([])
const dealStagesByCategory = ref<Record<number, DealStageOption[]>>({})
const showSyncModal = ref(false)
const syncDate = ref(new Date().toISOString().slice(0, 10))
const syncDateEnd = ref(new Date().toISOString().slice(0, 10))
/** Окно по умолчанию для кнопки «Синхронизировать» (как на бэкенде). */
const SYNC_QUICK_DAYS = 30

// Settings state
const settingsSubTab = ref<'matching' | 'automation' | 'fields' | 'paymentCrm' | 'sync' | 'notifications'>('matching')
const syncPrefEnabled = ref(false)
const syncPrefMinutes = ref(15)

let clientSyncTimer: ReturnType<typeof setInterval> | null = null

function pullSyncPrefsFromStore() {
  syncPrefEnabled.value = tochkaStore.portalPreferences.clientAutoSyncEnabled
  syncPrefMinutes.value = tochkaStore.portalPreferences.clientAutoSyncIntervalMinutes
}

const dealPaymentKindUfCode = ref('')
const dealPaymentChannelUfCode = ref('')
const dealUserFields = ref<Array<{ code: string; label: string }>>([])
const isLoadingDealUf = ref(false)
const isCreatingKindUf = ref(false)
const isCreatingChannelUf = ref(false)

const ufKindSearch = ref('')
const ufChannelSearch = ref('')
const showKindDropdown = ref(false)
const showChannelDropdown = ref(false)

const acquiringStatusLoading = ref(false)
const acquiringActiveRetailers = ref<Array<{ name: string; merchantId: string }>>([])

async function loadAcquiringStatus() {
  if (!tochkaStore.status.connected) {
    acquiringActiveRetailers.value = []
    return
  }
  acquiringStatusLoading.value = true
  try {
    const data = await tochkaStore.fetchAcquiringRetailers()
    const inner = (data?.Data ?? data) as Record<string, unknown>
    let retailers = inner?.Retailer ?? inner?.Retailers ?? []
    if (!Array.isArray(retailers)) retailers = retailers ? [retailers] : []
    acquiringActiveRetailers.value = retailers
      .filter(
        (x: unknown) =>
          typeof x === 'object' &&
          x !== null &&
          String((x as Record<string, unknown>).isActive ?? '').toLowerCase() === 'true' &&
          (x as Record<string, unknown>).status === 'REG',
      )
      .map((x: unknown) => {
        const r = x as Record<string, unknown>
        return {
          name: String(r.name ?? r.Name ?? 'Торговая точка'),
          merchantId: String(r.merchantId ?? r.merchant_id ?? ''),
        }
      })
  } catch {
    acquiringActiveRetailers.value = []
  } finally {
    acquiringStatusLoading.value = false
  }
}

const acquiringIsActive = computed(() => acquiringActiveRetailers.value.length > 0)

const filteredKindFields = computed(() => {
  const q = ufKindSearch.value.toLowerCase().trim()
  if (!q) return dealUserFields.value.slice(0, 15)
  return dealUserFields.value.filter(
    f => f.label.toLowerCase().includes(q) || f.code.toLowerCase().includes(q)
  ).slice(0, 15)
})

const filteredChannelFields = computed(() => {
  const q = ufChannelSearch.value.toLowerCase().trim()
  if (!q) return dealUserFields.value.slice(0, 15)
  return dealUserFields.value.filter(
    f => f.label.toLowerCase().includes(q) || f.code.toLowerCase().includes(q)
  ).slice(0, 15)
})

function selectKindField(f: { code: string; label: string }) {
  dealPaymentKindUfCode.value = f.code
  ufKindSearch.value = f.label
  showKindDropdown.value = false
}

function selectChannelField(f: { code: string; label: string }) {
  dealPaymentChannelUfCode.value = f.code
  ufChannelSearch.value = f.label
  showChannelDropdown.value = false
}

function closeKindDropdown() {
  showKindDropdown.value = false
}

function closeChannelDropdown() {
  showChannelDropdown.value = false
}

async function loadDealUserFields() {
  if (!$b24) return
  isLoadingDealUf.value = true
  try {
    const resp = await $b24.callMethod('crm.deal.userfield.list', {
      filter: { LANG: 'ru' },
    })
    const list = resp.getData().result || []
    dealUserFields.value = list.map((f: any) => {
      const label =
        (typeof f.EDIT_FORM_LABEL === 'string' && f.EDIT_FORM_LABEL.trim()) ||
        (typeof f.LIST_COLUMN_LABEL === 'string' && f.LIST_COLUMN_LABEL.trim()) ||
        f.FIELD_NAME
      return { code: f.FIELD_NAME, label }
    })
  } catch {
    dealUserFields.value = []
  } finally {
    isLoadingDealUf.value = false
  }
}

async function createCrmUserField(fieldName: string, label: string): Promise<string | null> {
  if (!$b24) return null
  const ufFields = {
    FIELD_NAME: fieldName,
    USER_TYPE_ID: 'string',
    EDIT_FORM_LABEL: { ru: label, en: label },
    LIST_COLUMN_LABEL: { ru: label, en: label },
    SETTINGS: { DEFAULT_VALUE: '' },
  }
  let created = false
  try {
    const resp = await $b24.callMethod('crm.deal.userfield.add', { fields: ufFields })
    if (resp.getData().result) created = true
  } catch (e: any) {
    $logger.error('Failed to create deal UF', e)
  }
  try {
    const resp = await $b24.callMethod('crm.lead.userfield.add', { fields: ufFields })
    if (resp.getData().result) created = true
  } catch (e: any) {
    $logger.error('Failed to create lead UF', e)
  }
  if (created) {
    await loadDealUserFields()
    return `UF_CRM_${fieldName}`
  }
  return null
}

async function createPaymentKindUf() {
  isCreatingKindUf.value = true
  const code = await createCrmUserField('TOCHKA_PAYMENT_KIND', 'Вид оплаты [Точка]')
  if (code) dealPaymentKindUfCode.value = code
  isCreatingKindUf.value = false
}

async function createPaymentChannelUf() {
  isCreatingChannelUf.value = true
  const code = await createCrmUserField('TOCHKA_PAYMENT_CHANNEL', 'Способ оплаты [Точка]')
  if (code) dealPaymentChannelUfCode.value = code
  isCreatingChannelUf.value = false
}

function pullPortalFieldPrefsFromStore() {
  dealPaymentKindUfCode.value = tochkaStore.portalPreferences.dealPaymentKindUfCode || ''
  dealPaymentChannelUfCode.value = tochkaStore.portalPreferences.dealPaymentChannelUfCode || ''
  const kindField = dealUserFields.value.find(f => f.code === dealPaymentKindUfCode.value)
  const channelField = dealUserFields.value.find(f => f.code === dealPaymentChannelUfCode.value)
  ufKindSearch.value = kindField?.label || dealPaymentKindUfCode.value
  ufChannelSearch.value = channelField?.label || dealPaymentChannelUfCode.value
}

async function savePortalUfCodes() {
  const kindCode = dealPaymentKindUfCode.value.trim()
  const channelCode = dealPaymentChannelUfCode.value.trim()
  await tochkaStore.savePortalPreferences({
    clientAutoSyncEnabled: tochkaStore.portalPreferences.clientAutoSyncEnabled,
    clientAutoSyncIntervalMinutes: tochkaStore.portalPreferences.clientAutoSyncIntervalMinutes,
    dealPaymentKindUfCode: kindCode,
    dealPaymentChannelUfCode: channelCode,
    leadPaymentKindUfCode: kindCode,
    leadPaymentChannelUfCode: channelCode,
  })
  pullPortalFieldPrefsFromStore()
  settingsSaved.value = true
  setTimeout(() => {
    settingsSaved.value = false
  }, 3000)
}

function clearClientSyncTimer() {
  if (clientSyncTimer) {
    clearInterval(clientSyncTimer)
    clientSyncTimer = null
  }
}

async function runClientAutoSyncTick() {
  if (!tochkaStore.portalPreferences.clientAutoSyncEnabled) return
  if (isManualSyncing.value) return
  if (isBackgroundSyncing.value) return
  isBackgroundSyncing.value = true
  try {
    await tochkaStore.syncPayments({ days: 2 })
    if (activeTab.value === 'payments') {
      await tochkaStore.fetchPayments(currentPage.value, paymentFilter.value, paymentSearchFilters.value)
    }
  } catch {
    /* lastSyncError в store */
  } finally {
    isBackgroundSyncing.value = false
  }
}

function restartClientSyncTimer() {
  clearClientSyncTimer()
  if (!tochkaStore.portalPreferences.clientAutoSyncEnabled) return
  const min = Math.max(3, tochkaStore.portalPreferences.clientAutoSyncIntervalMinutes || 15)
  clientSyncTimer = setInterval(runClientAutoSyncTick, min * 60_000)
}

async function saveSyncPreferences() {
  await tochkaStore.savePortalPreferences({
    clientAutoSyncEnabled: syncPrefEnabled.value,
    clientAutoSyncIntervalMinutes: syncPrefMinutes.value,
  })
  pullSyncPrefsFromStore()
  restartClientSyncTimer()
  if (tochkaStore.portalPreferences.clientAutoSyncEnabled) {
    void runClientAutoSyncTick()
  }
  settingsSaved.value = true
  setTimeout(() => { settingsSaved.value = false }, 3000)
}
const settingsSaved = ref(false)
const settingsError = ref('')

const notifyDealResponsible = ref(true)
const notifyDealTimeline = ref(true)
const notifyChatEnabled = ref(false)
const notifyChatId = ref<number | null>(null)
const availableChats = ref<Array<{ id: number; title: string }>>([])
const isLoadingChats = ref(false)
const isCreatingChat = ref(false)
const chatLoadError = ref('')

function pullNotifyPrefsFromStore() {
  notifyDealResponsible.value = tochkaStore.portalPreferences.notifyDealResponsible
  notifyDealTimeline.value = tochkaStore.portalPreferences.notifyDealTimeline
  notifyChatId.value = tochkaStore.portalPreferences.notifyChatId
  notifyChatEnabled.value = !!tochkaStore.portalPreferences.notifyChatId
}

function parseChatsFromItems(items: any[]): Array<{ id: number; title: string }> {
  const chats: Array<{ id: number; title: string }> = []
  const seen = new Set<number>()
  for (const item of items) {
    const chatId = Number(item.chat_id || item.chat?.id || 0)
    if (!chatId || Number.isNaN(chatId) || seen.has(chatId)) continue

    const type = item.type || item.chat?.type || ''
    if (type === 'user' || type === 'open' || type === 'copilot') continue

    const title = item.title || item.chat?.name || item.chat?.title || item.name || ''
    seen.add(chatId)
    chats.push({ id: chatId, title: title || `Чат #${chatId}` })
  }
  return chats
}

async function loadAvailableChats() {
  if (!$b24) return
  isLoadingChats.value = true
  chatLoadError.value = ''
  try {
    const resp = await $b24.callMethod('im.recent.list', {
      SKIP_DIALOG: 'Y',
      SKIP_OPENLINES: 'Y',
      SKIP_UNDISTRIBUTED_OPENLINES: 'Y',
      ONLY_COPILOT: 'N',
      ONLY_CHANNEL: 'N',
      LIMIT: 200,
    })
    const data = resp.getData() as any
    $logger.log('[loadAvailableChats] im.recent.list response:', data)

    if (data.error) {
      $logger.error('im.recent.list API error:', data.error, data.error_description)
      if (String(data.error).includes('insufficient_scope')) {
        chatLoadError.value = 'Нет разрешения «im». Переустановите приложение, чтобы добавить scope im для работы с чатами.'
      } else {
        chatLoadError.value = `Ошибка API: ${data.error_description || data.error}`
      }
      return
    }

    const resultObj = data.result ?? data
    const rawItems = resultObj?.items || []
    const items: any[] = Array.isArray(rawItems) ? rawItems : Object.values(rawItems)
    let chats = parseChatsFromItems(items)

    if (chats.length === 0 && items.length === 0) {
      $logger.log('[loadAvailableChats] im.recent.list returned 0 items, trying im.search.chat.list fallback')
      chats = await loadChatsFallback()
    }

    availableChats.value = chats
    if (chats.length === 0) {
      chatLoadError.value = 'Групповые чаты не найдены. Создайте новый чат.'
    }
  } catch (e: any) {
    $logger.error('Failed to load chats', e)
    const errMsg = String(e?.message || e?.data?.error_description || '')
    if (errMsg.includes('insufficient_scope')) {
      chatLoadError.value = 'Нет разрешения «im». Переустановите приложение, чтобы добавить scope im для работы с чатами.'
    } else {
      chatLoadError.value = errMsg || 'Не удалось загрузить чаты'
    }
  } finally {
    isLoadingChats.value = false
  }
}

async function loadChatsFallback(): Promise<Array<{ id: number; title: string }>> {
  if (!$b24) return []
  try {
    const resp = await $b24.callMethod('im.search.chat.list', { FIND: 'чат', LIMIT: 50 })
    const data = resp.getData() as any
    $logger.log('[loadChatsFallback] im.search.chat.list response:', data)
    if (data.error) return []
    const resultArr = data.result ?? data
    const items: any[] = Array.isArray(resultArr) ? resultArr : resultArr?.items || Object.values(resultArr)
    const chats: Array<{ id: number; title: string }> = []
    const seen = new Set<number>()
    for (const item of items) {
      const chatId = Number(item.id || item.chat_id || 0)
      if (!chatId || Number.isNaN(chatId) || seen.has(chatId)) continue
      const title = item.name || item.title || ''
      seen.add(chatId)
      chats.push({ id: chatId, title: title || `Чат #${chatId}` })
    }
    return chats
  } catch (e: any) {
    $logger.error('Fallback im.search.chat.list failed', e)
    return []
  }
}

function generateChatAvatar(): string {
  const canvas = document.createElement('canvas')
  canvas.width = 200
  canvas.height = 200
  const ctx = canvas.getContext('2d')!
  ctx.fillStyle = '#7C3AED'
  ctx.beginPath()
  ctx.roundRect(0, 0, 200, 200, 40)
  ctx.fill()
  ctx.fillStyle = '#FFFFFF'
  ctx.font = 'bold 120px Arial, sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  ctx.fillText('Т', 100, 108)
  return canvas.toDataURL('image/png').replace('data:image/png;base64,', '')
}

const NOTIFY_CHAT_TITLE = 'Уведомления об оплатах'

async function createNotificationChat() {
  if (!$b24) return
  isCreatingChat.value = true
  try {
    const resp = await $b24.callMethod('im.chat.add', {
      TYPE: 'CHAT',
      TITLE: NOTIFY_CHAT_TITLE,
      DESCRIPTION: 'Уведомления о входящих платежах из интеграции Точка Банк',
      COLOR: 'PURPLE',
      AVATAR: generateChatAvatar(),
      MESSAGE: 'Чат создан для уведомлений об оплатах. Добавьте сюда нужных сотрудников.',
    })
    const chatId = resp.getData().result
    if (chatId) {
      const newId = Number(chatId)
      notifyChatId.value = newId
      notifyChatEnabled.value = true
      if (!availableChats.value.some(c => c.id === newId)) {
        availableChats.value.push({
          id: newId,
          title: NOTIFY_CHAT_TITLE,
        })
      }
      await loadAvailableChats()
    }
  } catch (e: any) {
    $logger.error('Failed to create notification chat', e)
  } finally {
    isCreatingChat.value = false
  }
}

async function saveNotificationPreferences() {
  await tochkaStore.savePortalPreferences({
    notifyDealResponsible: notifyDealResponsible.value,
    notifyDealTimeline: notifyDealTimeline.value,
    notifyChatId: notifyChatEnabled.value ? notifyChatId.value : null,
  })
  pullNotifyPrefsFromStore()
  settingsSaved.value = true
  setTimeout(() => { settingsSaved.value = false }, 3000)
}
const dealMatching = ref<DealMatchingPreferences>(defaultDealMatchingPreferences())

const ufMatchInnSearch = ref('')
const ufMatchNameSearch = ref('')
const ufMatchInvoiceSearch = ref('')
const ufMatchPhoneSearch = ref('')
const showMatchInnDropdown = ref(false)
const showMatchNameDropdown = ref(false)
const showMatchInvoiceDropdown = ref(false)
const showMatchPhoneDropdown = ref(false)
const isCreatingMatchInnUf = ref(false)
const isCreatingMatchNameUf = ref(false)
const isCreatingMatchInvoiceUf = ref(false)
const isCreatingMatchPhoneUf = ref(false)

function filterDealUfBySearch(search: string) {
  const q = search.toLowerCase().trim()
  const list = dealUserFields.value
  if (!q) return list.slice(0, 15)
  return list
    .filter(f => f.label.toLowerCase().includes(q) || f.code.toLowerCase().includes(q))
    .slice(0, 15)
}

const filteredMatchInnFields = computed(() => filterDealUfBySearch(ufMatchInnSearch.value))
const filteredMatchNameFields = computed(() => filterDealUfBySearch(ufMatchNameSearch.value))
const filteredMatchInvoiceFields = computed(() => filterDealUfBySearch(ufMatchInvoiceSearch.value))
const filteredMatchPhoneFields = computed(() => filterDealUfBySearch(ufMatchPhoneSearch.value))

function displayDealUfLabel(code: string) {
  if (!code) return ''
  const f = dealUserFields.value.find(x => x.code === code)
  return f?.label || code
}

function syncUfSearchLabelsFromDealMatching() {
  const dm = dealMatching.value
  ufMatchInnSearch.value = dm.innUf ? displayDealUfLabel(dm.innUf) : ''
  ufMatchNameSearch.value = dm.nameUf ? displayDealUfLabel(dm.nameUf) : ''
  ufMatchInvoiceSearch.value = dm.invoiceUf ? displayDealUfLabel(dm.invoiceUf) : ''
  ufMatchPhoneSearch.value = dm.phoneUf ? displayDealUfLabel(dm.phoneUf) : ''
}

function pullDealMatchingFromStore() {
  dealMatching.value = normalizeDealMatchingPreferences(tochkaStore.portalPreferences.dealMatching)
  syncUfSearchLabelsFromDealMatching()
}

function closeMatchInnDropdown() {
  setTimeout(() => { showMatchInnDropdown.value = false }, 150)
}
function closeMatchNameDropdown() {
  setTimeout(() => { showMatchNameDropdown.value = false }, 150)
}
function closeMatchInvoiceDropdown() {
  setTimeout(() => { showMatchInvoiceDropdown.value = false }, 150)
}
function closeMatchPhoneDropdown() {
  setTimeout(() => { showMatchPhoneDropdown.value = false }, 150)
}

function selectMatchInnField(f: { code: string; label: string }) {
  dealMatching.value.innUf = f.code
  ufMatchInnSearch.value = f.label
  showMatchInnDropdown.value = false
}
function selectMatchNameField(f: { code: string; label: string }) {
  dealMatching.value.nameUf = f.code
  ufMatchNameSearch.value = f.label
  showMatchNameDropdown.value = false
}
function selectMatchInvoiceField(f: { code: string; label: string }) {
  dealMatching.value.invoiceUf = f.code
  ufMatchInvoiceSearch.value = f.label
  showMatchInvoiceDropdown.value = false
}

function clearMatchInnField() {
  dealMatching.value.innUf = ''
  ufMatchInnSearch.value = ''
}
function clearMatchNameField() {
  dealMatching.value.nameUf = ''
  ufMatchNameSearch.value = ''
}
function clearMatchInvoiceField() {
  dealMatching.value.invoiceUf = ''
  ufMatchInvoiceSearch.value = ''
}
function selectMatchPhoneField(f: { code: string; label: string }) {
  dealMatching.value.phoneUf = f.code
  ufMatchPhoneSearch.value = f.label
  showMatchPhoneDropdown.value = false
}
function clearMatchPhoneField() {
  dealMatching.value.phoneUf = ''
  ufMatchPhoneSearch.value = ''
}

async function createDealMatchInnUf() {
  isCreatingMatchInnUf.value = true
  try {
    const code = await createCrmUserField(
      'TOCHKA_DEAL_CP_INN',
      'ИНН контрагента [Точка Банк. Интеграция]',
    )
    if (code) {
      dealMatching.value.innUf = code
      await loadDealUserFields()
      syncUfSearchLabelsFromDealMatching()
    }
  } finally {
    isCreatingMatchInnUf.value = false
  }
}

async function createDealMatchNameUf() {
  isCreatingMatchNameUf.value = true
  try {
    const code = await createCrmUserField(
      'TOCHKA_DEAL_COUNTERPARTY',
      'Контрагент [Точка Банк. Интеграция]',
    )
    if (code) {
      dealMatching.value.nameUf = code
      await loadDealUserFields()
      syncUfSearchLabelsFromDealMatching()
    }
  } finally {
    isCreatingMatchNameUf.value = false
  }
}

async function createDealMatchInvoiceUf() {
  isCreatingMatchInvoiceUf.value = true
  try {
    const code = await createCrmUserField(
      'TOCHKA_DEAL_INV_NUM',
      'Номер счёта [Точка Банк. Интеграция]',
    )
    if (code) {
      dealMatching.value.invoiceUf = code
      await loadDealUserFields()
      syncUfSearchLabelsFromDealMatching()
    }
  } finally {
    isCreatingMatchInvoiceUf.value = false
  }
}

async function createDealMatchPhoneUf() {
  isCreatingMatchPhoneUf.value = true
  try {
    const code = await createCrmUserField(
      'TOCHKA_DEAL_PAYER_PHONE',
      'Телефон плательщика [Точка Банк. Интеграция]',
    )
    if (code) {
      dealMatching.value.phoneUf = code
      await loadDealUserFields()
      syncUfSearchLabelsFromDealMatching()
    }
  } finally {
    isCreatingMatchPhoneUf.value = false
  }
}

// Invoice settings — классические счета CRM (crm.invoice, INVOICE_STATUS)
const autoUpdateInvoiceStatus = ref(false)
const invoiceStatusPaid = ref('')
const invoiceStatusPartialPaid = ref('')
const invoiceMatchByInn = ref(false)
const invoiceMatchByAmount = ref(true)
const invoiceStatuses = ref<Array<{ value: string; label: string }>>([])

function pullInvoicePrefsFromStore() {
  const p = tochkaStore.portalPreferences
  autoUpdateInvoiceStatus.value = p.invoiceAutoUpdateStage
  invoiceStatusPaid.value = p.invoiceStageIdPaid || ''
  invoiceStatusPartialPaid.value = p.invoiceStageIdPartial || ''
  invoiceMatchByInn.value = p.invoiceMatchByInn
  invoiceMatchByAmount.value = p.invoiceMatchByAmount
  pullDealMatchingFromStore()
}

// Account management
const showAddAccountModal = ref(false)
const showEditAccountModal = ref(false)
const editingAccount = ref<{ accountId: string; organizationName: string; prefix: string; suffix: string; active: boolean } | null>(null)
const accountMenuOpen = ref<string | null>(null)

function getAccountOrganizationName(account: { accountId: string; description?: string }): string {
  const custom = tochkaStore.selectedAccountSettings[account.accountId]?.organizationName?.trim()
  if (custom) return custom
  return account.description?.trim() || '—'
}

function getAccountPrefixDisplay(accountId: string): string {
  const v = tochkaStore.selectedAccountSettings[accountId]?.prefix?.trim()
  return v || '—'
}

function getAccountSuffixDisplay(accountId: string): string {
  const v = tochkaStore.selectedAccountSettings[accountId]?.suffix?.trim()
  return v || '—'
}

function openEditAccount(account: { accountId: string; description?: string }) {
  const saved = tochkaStore.selectedAccountSettings[account.accountId]
  editingAccount.value = {
    accountId: account.accountId,
    organizationName: saved?.organizationName?.trim() || account.description?.trim() || '',
    prefix: saved?.prefix || '',
    suffix: saved?.suffix || '',
    active: true,
  }
  showEditAccountModal.value = true
  accountMenuOpen.value = null
}

function closeEditAccount() {
  showEditAccountModal.value = false
  editingAccount.value = null
}

function saveEditAccount() {
  if (!editingAccount.value) return
  const { accountId, organizationName, prefix, suffix } = editingAccount.value
  tochkaStore.setSelectedAccountSettings({
    ...tochkaStore.selectedAccountSettings,
    [accountId]: {
      organizationName: organizationName.trim(),
      prefix,
      suffix,
    },
  })
  closeEditAccount()
}

function deleteAccount(accountId: string) {
  accountMenuOpen.value = null
}

function toggleAccountMenu(accountId: string) {
  accountMenuOpen.value = accountMenuOpen.value === accountId ? null : accountId
}

async function syncSingleAccount(accountId: string) {
  accountMenuOpen.value = null
  isLoadingData.value = true
  try {
    await tochkaStore.fetchAccounts()
    await tochkaStore.fetchBalances(accountId)
  } catch (e) {
    $logger.error('Failed to sync account', e)
  } finally {
    isLoadingData.value = false
  }
}

async function checkSingleAccount(accountId: string) {
  accountMenuOpen.value = null
  await tochkaStore.fetchBalances(accountId)
}

const isSavingSettings = ref(false)

async function saveSettings() {
  settingsError.value = ''
  isSavingSettings.value = true
  try {
    if (settingsSubTab.value === 'sync') {
      await saveSyncPreferences()
      return
    }
    if (settingsSubTab.value === 'paymentCrm') {
      await savePortalUfCodes()
      return
    }
    if (settingsSubTab.value === 'notifications') {
      await saveNotificationPreferences()
      return
    }
    if (settingsSubTab.value === 'matching' || settingsSubTab.value === 'automation') {
      await tochkaStore.savePortalPreferences({
        ...(settingsSubTab.value === 'automation'
          ? {
              invoiceAutoUpdateStage: autoUpdateInvoiceStatus.value,
              invoiceStageIdPaid: invoiceStatusPaid.value,
              invoiceStageIdPartial: invoiceStatusPartialPaid.value,
              invoiceMatchByInn: invoiceMatchByInn.value,
              invoiceMatchByAmount: invoiceMatchByAmount.value,
            }
          : {}),
        dealMatching: normalizeDealMatchingPreferences(dealMatching.value),
      })
      if (settingsSubTab.value === 'automation') {
        pullInvoicePrefsFromStore()
      }
      settingsSaved.value = true
      setTimeout(() => { settingsSaved.value = false }, 3000)
      return
    }
    settingsSaved.value = true
    setTimeout(() => { settingsSaved.value = false }, 3000)
  } catch (e: any) {
    $logger.error('Save settings failed', e)
    settingsError.value = e?.data?.error || e?.message || 'Не удалось сохранить настройки'
    setTimeout(() => { settingsError.value = '' }, 6000)
  } finally {
    isSavingSettings.value = false
  }
}

// Payment search & pagination
type PaymentSearchChipKey = 'text' | 'amount' | 'counterparty' | 'date'

interface PaymentSearchChip {
  key: PaymentSearchChipKey
  label: string
}

const paymentSearchFilters = ref<PaymentSearchFilters>({})
const paymentSearchDraft = ref('')
const paymentSearchOpen = ref(false)
const paymentSearchInputRef = ref<HTMLInputElement | null>(null)
const currentPage = ref(1)

function togglePaymentSearch() {
  paymentSearchOpen.value = !paymentSearchOpen.value
}

function closePaymentSearch() {
  paymentSearchOpen.value = false
}

watch(paymentSearchOpen, (open) => {
  if (open) {
    nextTick(() => paymentSearchInputRef.value?.focus())
  }
})

function parseFlexibleIsoDate(raw: string): string | null {
  const s = raw.trim()
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s
  const m = s.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/)
  if (!m) return null
  const day = m[1].padStart(2, '0')
  const month = m[2].padStart(2, '0')
  return `${m[3]}-${month}-${day}`
}

function formatPaymentSearchDate(iso: string): string {
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return iso
  return `${m[3]}.${m[2]}.${m[1]}`
}

function formatPaymentSearchAmount(raw: string): string {
  const n = Number(String(raw).replace(/\s/g, '').replace(',', '.'))
  if (Number.isNaN(n)) return raw
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(n)
}

function parsePaymentSearchDateValue(raw: string): Pick<PaymentSearchFilters, 'dateFrom' | 'dateTo'> | null {
  const trimmed = raw.trim()
  if (!trimmed) return null

  let fromStr = trimmed
  let toStr: string | undefined

  if (trimmed.includes('..')) {
    ;[fromStr, toStr] = trimmed.split('..', 2).map(part => part.trim())
  } else {
    const rangeMatch = trimmed.match(/^(\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2})\s*[-–—]\s*(\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2})$/)
    if (rangeMatch) {
      fromStr = rangeMatch[1]
      toStr = rangeMatch[2]
    }
  }

  const dateFrom = parseFlexibleIsoDate(fromStr)
  if (!dateFrom) return null
  const dateTo = toStr ? parseFlexibleIsoDate(toStr) : dateFrom
  if (!dateTo) return null
  return { dateFrom, dateTo }
}

function parsePaymentSearchInput(raw: string): Partial<PaymentSearchFilters> | null {
  const s = raw.trim()
  if (!s) return null

  const amountMatch = s.match(/^(?:сумма|amount)\s*[:=]\s*(.+)$/i)
  if (amountMatch) {
    return { amount: amountMatch[1].trim().replace(/\s/g, '').replace(',', '.') }
  }

  const counterpartyMatch = s.match(/^(?:контрагент|counterparty)\s*[:=]\s*(.+)$/i)
  if (counterpartyMatch) {
    return { counterparty: counterpartyMatch[1].trim() }
  }

  const dateMatch = s.match(/^(?:дата|date)\s*[:=]\s*(.+)$/i)
  if (dateMatch) {
    const parsed = parsePaymentSearchDateValue(dateMatch[1])
    return parsed || null
  }

  return { text: s }
}

const paymentSearchChips = computed((): PaymentSearchChip[] => {
  const chips: PaymentSearchChip[] = []
  const f = paymentSearchFilters.value

  if (f.text?.trim()) {
    chips.push({ key: 'text', label: f.text.trim() })
  }
  if (f.amount?.trim()) {
    chips.push({ key: 'amount', label: `Сумма: ${formatPaymentSearchAmount(f.amount)}` })
  }
  if (f.counterparty?.trim()) {
    chips.push({ key: 'counterparty', label: `Контрагент: ${f.counterparty.trim()}` })
  }
  if (f.dateFrom) {
    const fromLabel = formatPaymentSearchDate(f.dateFrom)
    const toLabel = f.dateTo ? formatPaymentSearchDate(f.dateTo) : fromLabel
    const label = fromLabel === toLabel ? `Дата: ${fromLabel}` : `Дата: ${fromLabel} — ${toLabel}`
    chips.push({ key: 'date', label })
  }

  return chips
})

const hasActivePaymentSearch = computed(() => paymentSearchChips.value.length > 0)

function removePaymentSearchChip(key: PaymentSearchChipKey) {
  const next: PaymentSearchFilters = { ...paymentSearchFilters.value }
  if (key === 'date') {
    delete next.dateFrom
    delete next.dateTo
  } else {
    delete next[key]
  }
  paymentSearchFilters.value = next
  currentPage.value = 1
  void loadPayments()
}

function commitPaymentSearchDraft() {
  const parsed = parsePaymentSearchInput(paymentSearchDraft.value)
  if (!parsed) return

  const next: PaymentSearchFilters = { ...paymentSearchFilters.value, ...parsed }
  paymentSearchFilters.value = next
  paymentSearchDraft.value = ''
  currentPage.value = 1
  void loadPayments()
}

function onPaymentSearchBackspace() {
  if (paymentSearchDraft.value !== '' || paymentSearchChips.value.length === 0) return
  const last = paymentSearchChips.value[paymentSearchChips.value.length - 1]
  removePaymentSearchChip(last.key)
}

// Bind to deal modal
const showBindModal = ref(false)
const bindingPayment = ref<any>(null)
const dealSearchQuery = ref('')
const dealSearchResults = ref<any[]>([])
const selectedDeal = ref<any>(null)
const isSearchingDeals = ref(false)
const paymentActionMenu = ref<string | null>(null)
const acquiringActionMenu = ref<string | null>(null)

/** Модалка подтверждения отвязки (сделка / счёт / эквайринг). */
type UnbindConfirmKind = 'deal' | 'invoice' | 'acquiring_deal' | 'acquiring_lead'
const showUnbindConfirmModal = ref(false)
const unbindConfirmKind = ref<UnbindConfirmKind | null>(null)
const unbindConfirmPaymentId = ref<string | null>(null)

const unbindConfirmMessage = computed(() => {
  switch (unbindConfirmKind.value) {
    case 'deal':
    case 'acquiring_deal':
      return 'Отвязать платёж? Пользовательские поля сделки будут очищены.'
    case 'acquiring_lead':
      return 'Отвязать платёж? Пользовательские поля лида будут очищены.'
    case 'invoice':
      return 'Отвязать платёж? Данные привязки к счёту в CRM будут сброшены.'
    default:
      return ''
  }
})

function openUnbindConfirmModal(kind: UnbindConfirmKind, paymentId: string) {
  unbindConfirmKind.value = kind
  unbindConfirmPaymentId.value = paymentId
  showUnbindConfirmModal.value = true
  paymentActionMenu.value = null
  acquiringActionMenu.value = null
}

function closeUnbindConfirmModal() {
  showUnbindConfirmModal.value = false
  unbindConfirmKind.value = null
  unbindConfirmPaymentId.value = null
}

async function confirmUnbindPayment() {
  const id = unbindConfirmPaymentId.value
  const kind = unbindConfirmKind.value
  if (!id || !kind) return
  closeUnbindConfirmModal()
  try {
    if (kind === 'deal') {
      await tochkaStore.paymentAction(id, 'unbind')
    } else if (kind === 'invoice') {
      await tochkaStore.paymentAction(id, 'unbind_invoice')
    } else {
      await tochkaStore.unlinkAcquiringOperation(id)
    }
    await loadPayments()
  } catch (e) {
    $logger.error('Unbind failed', e)
  }
}

// Bind to invoice modal (классические счета crm.invoice)
const showBindInvoiceModal = ref(false)
const bindingInvoicePayment = ref<any>(null)
const invoiceSearchQuery = ref('')
const invoiceSearchResults = ref<any[]>([])
const selectedInvoice = ref<any>(null)
const isSearchingInvoices = ref(false)

function openBindModal(payment: any) {
  bindingPayment.value = payment
  dealSearchQuery.value = ''
  dealSearchResults.value = []
  selectedDeal.value = null
  showBindModal.value = true
  paymentActionMenu.value = null
}

function closeBindModal() {
  showBindModal.value = false
  bindingPayment.value = null
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function searchDeals() {
  if (searchTimeout) clearTimeout(searchTimeout)
  
  if (!$b24 || !dealSearchQuery.value.trim()) {
    dealSearchResults.value = []
    return
  }
  
  searchTimeout = setTimeout(async () => {
    isSearchingDeals.value = true
    try {
      // Search by title or ID
      const query = dealSearchQuery.value.trim()
      const isNumeric = /^\d+$/.test(query)
      
      const filter = isNumeric 
        ? { ID: query }
        : { '%TITLE': query }
      
      const resp = await $b24.callMethod('crm.deal.list', {
        filter,
        select: ['ID', 'TITLE', 'OPPORTUNITY', 'CURRENCY_ID', 'COMPANY_ID', 'CONTACT_ID', 'ASSIGNED_BY_ID'],
        order: { ID: 'DESC' },
        start: 0,
      })
      dealSearchResults.value = resp.getData().result || []
    } catch {
      dealSearchResults.value = []
    } finally {
      isSearchingDeals.value = false
    }
  }, 300)
}

async function bindPaymentToDeal() {
  if (!bindingPayment.value || !selectedDeal.value || !$b24) return
  try {
    // Get responsible user info
    let responsibleName = ''
    let responsiblePhoto = ''
    if (selectedDeal.value.ASSIGNED_BY_ID) {
      try {
        const userResp = await $b24.callMethod('user.get', { ID: selectedDeal.value.ASSIGNED_BY_ID })
        const user = userResp.getData().result?.[0]
        if (user) {
          responsibleName = `${user.NAME || ''} ${user.LAST_NAME || ''}`.trim()
          responsiblePhoto = user.PERSONAL_PHOTO || ''
        }
      } catch {}
    }
    
    await tochkaStore.paymentAction(bindingPayment.value.id, 'bind', {
      dealId: selectedDeal.value.ID,
      dealInfo: {
        title: selectedDeal.value.TITLE,
        amount: selectedDeal.value.OPPORTUNITY,
        currency: selectedDeal.value.CURRENCY_ID || 'RUB',
        responsibleId: selectedDeal.value.ASSIGNED_BY_ID,
        responsibleName,
        responsiblePhoto,
      }
    })
    await loadPayments()
    closeBindModal()
  } catch (e) {
    $logger.error('Bind failed', e)
  }
}

async function ignorePayment(paymentId: string) {
  paymentActionMenu.value = null
  try {
    await tochkaStore.paymentAction(paymentId, 'ignore')
    await loadPayments()
  } catch (e) {
    $logger.error('Ignore failed', e)
  }
}

function togglePaymentMenu(paymentId: string) {
  showSyncStatusPopover.value = false
  acquiringActionMenu.value = null
  paymentActionMenu.value = paymentActionMenu.value === paymentId ? null : paymentId
}

function toggleAcquiringMenu(rowId: string) {
  showSyncStatusPopover.value = false
  paymentActionMenu.value = null
  acquiringActionMenu.value = acquiringActionMenu.value === rowId ? null : rowId
}

async function onAcquiringCancelLink(p: { id: string }) {
  if (!confirm('Отменить платёжную ссылку?')) return
  acquiringActionMenu.value = null
  try {
    await tochkaStore.unlinkAcquiringOperation(p.id)
    await loadPayments()
  } catch (e) {
    $logger.error('Acquiring cancel link failed', e)
  }
}

function onAcquiringUnlinkDeal(p: { id: string; matched_deal_id?: number | null; matched_lead_id?: number | null }) {
  const kind: UnbindConfirmKind = p.matched_deal_id ? 'acquiring_deal' : 'acquiring_lead'
  openUnbindConfirmModal(kind, p.id)
}

async function onAcquiringRefund(p: { id: string }) {
  if (!confirm('Оформить возврат по этой оплате через Точку?')) return
  acquiringActionMenu.value = null
  try {
    await tochkaStore.refundAcquiringOperation(p.id)
    await loadPayments()
  } catch (e: any) {
    $logger.error('Acquiring refund failed', e)
    const msg = e?.data?.error || e?.message || 'Ошибка возврата'
    alert(msg)
  }
}

// Invoice binding functions
function openBindInvoiceModal(payment: any) {
  bindingInvoicePayment.value = payment
  invoiceSearchQuery.value = ''
  invoiceSearchResults.value = []
  selectedInvoice.value = null
  showBindInvoiceModal.value = true
  paymentActionMenu.value = null
}

function closeBindInvoiceModal() {
  showBindInvoiceModal.value = false
  bindingInvoicePayment.value = null
}

let invoiceSearchTimeout: ReturnType<typeof setTimeout> | null = null

function searchInvoices() {
  if (invoiceSearchTimeout) clearTimeout(invoiceSearchTimeout)
  
  if (!$b24 || !invoiceSearchQuery.value.trim()) {
    invoiceSearchResults.value = []
    return
  }
  
  invoiceSearchTimeout = setTimeout(async () => {
    isSearchingInvoices.value = true
    try {
      const query = invoiceSearchQuery.value.trim()
      const isNumeric = /^\d+$/.test(query)
      
      // Классические счета: crm.invoice.list
      const qn = isNumeric ? Number(query) : NaN
      const filter = isNumeric && !Number.isNaN(qn)
        ? { ID: qn }
        : { '%ORDER_TOPIC': query }
      const resp = await $b24.callMethod('crm.invoice.list', {
        filter,
        select: ['ID', 'ORDER_TOPIC', 'PRICE', 'CURRENCY_ID', 'ACCOUNT_NUMBER', 'RESPONSIBLE_ID', 'STATUS_ID'],
        order: { DATE_INSERT: 'DESC' },
        start: 0,
      })
      const raw = resp.getData().result || []
      const items = Array.isArray(raw) ? raw : []
      invoiceSearchResults.value = items.map((inv: any) => ({
        id: inv.ID,
        title: inv.ORDER_TOPIC || `Счёт #${inv.ID}`,
        opportunity: inv.PRICE,
        currencyId: inv.CURRENCY_ID,
        accountNumber: inv.ACCOUNT_NUMBER || '',
        assignedById: inv.RESPONSIBLE_ID,
      }))
    } catch {
      invoiceSearchResults.value = []
    } finally {
      isSearchingInvoices.value = false
    }
  }, 300)
}

async function bindPaymentToInvoice() {
  if (!bindingInvoicePayment.value || !selectedInvoice.value || !$b24) return
  try {
    // Get responsible user info
    let responsibleName = ''
    let responsiblePhoto = ''
    if (selectedInvoice.value.assignedById) {
      try {
        const userResp = await $b24.callMethod('user.get', { ID: selectedInvoice.value.assignedById })
        const user = userResp.getData().result?.[0]
        if (user) {
          responsibleName = `${user.NAME || ''} ${user.LAST_NAME || ''}`.trim()
          responsiblePhoto = user.PERSONAL_PHOTO || ''
        }
      } catch {}
    }
    
    await tochkaStore.paymentAction(bindingInvoicePayment.value.id, 'bind_invoice', {
      invoiceId: selectedInvoice.value.id,
      invoiceInfo: {
        title: selectedInvoice.value.title,
        amount: selectedInvoice.value.opportunity,
        currency: selectedInvoice.value.currencyId || 'RUB',
        accountNumber: selectedInvoice.value.accountNumber || '',
        responsibleId: selectedInvoice.value.assignedById,
        responsibleName,
        responsiblePhoto,
      }
    })
    await loadPayments()
    closeBindInvoiceModal()
  } catch (e) {
    $logger.error('Bind invoice failed', e)
  }
}

async function openInvoice(invoiceId: number) {
  if (!$b24) return

  const path = `/crm/invoice/details/${invoiceId}/`

  try {
    const url = $b24.slider.getUrl(path)
    await $b24.slider.openPath(url)
  } catch (e) {
    console.error('Failed to open invoice:', e)
  }
}

/** Извлечь номер счёта из текста назначения платежа (как на бэкенде). */
function extractInvoiceNumberFromPurpose(purpose: string | null | undefined): string | null {
  if (!purpose?.trim()) return null
  const text = purpose.trim()
  const patterns = [
    /(?:сч(?:ет|ёта?)|invoice|inv)[^\d]{0,24}?(\d+)/i,
    /(?:по\s+сч|оплата\s+сч)[^\d]{0,24}?(\d+)/i,
    /№[^\d]*(\d+)/,
  ]
  for (const re of patterns) {
    const m = re.exec(text)
    if (m?.[1]) return m[1]
  }
  return null
}

/** Номер счёта для колонки «Счёт №»: из выписки или из привязанного CRM-счёта. */
function paymentInvoiceNumber(p: {
  invoice_number?: string | null
  purpose?: string | null
  matched_invoice_id?: number | null
  invoice_title?: string | null
}): string | null {
  const stored = p.invoice_number?.trim()
  if (stored) return stored
  const fromPurpose = extractInvoiceNumberFromPurpose(p.purpose)
  if (fromPurpose) return fromPurpose
  if (!p.matched_invoice_id) return null
  const title = p.invoice_title?.trim() || ''
  const fromTitle = /(?:№|#)\s*(\d+)/i.exec(title)?.[1]
  if (fromTitle) return fromTitle
  return String(p.matched_invoice_id)
}

function paymentInvoiceFoundLabel(p: {
  invoice_number?: string | null
  matched_invoice_id?: number | null
  invoice_title?: string | null
}): string | null {
  const num = paymentInvoiceNumber(p)
  return num ? `Счёт №${num}` : null
}

function paymentChannelTitle(p: { payment_source?: string | null }): string {
  if (p.payment_source === 'acquiring') return 'Оплата по ссылке'
  return 'Банковский перевод'
}

function paymentReceivedAtLabel(iso: string | null | undefined): string {
  if (!iso) return ''
  let s = iso.trim()
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s) && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
    s = `${s}Z`
  }
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return ''
  const datePart = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
  const timePart = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', hour12: false })
  return `${datePart} Получено в ${timePart}`
}

function paymentSlipLabel(p: {
  payment_slip_number?: string | null
  transaction_date?: string | null
}): string | null {
  const num = p.payment_slip_number?.trim()
  if (!num) return null
  const dateKey = p.transaction_date?.split('T')[0]
  const datePart = dateKey ? formatDateHeader(dateKey) : ''
  return datePart ? `№ ${num} от ${datePart}` : `№ ${num}`
}

async function openDeal(dealId: number) {
  if (!$b24) return
  
  const path = `/crm/deal/details/${dealId}/`
  console.log('Opening deal:', path)
  
  try {
    // Use slider.getUrl to create proper URL object, then openPath
    const url = $b24.slider.getUrl(path)
    console.log('Opening URL:', url.toString())
    const result = await $b24.slider.openPath(url)
    console.log('openPath result:', result)
  } catch (e) {
    console.error('Failed to open deal:', e)
  }
}

async function openContact(contactId: number) {
  if (!$b24) return
  const path = `/crm/contact/details/${contactId}/`
  try {
    const url = $b24.slider.getUrl(path)
    await $b24.slider.openPath(url)
  } catch (e) {
    console.error('Failed to open contact:', e)
  }
}

async function openCompany(companyId: number) {
  if (!$b24) return
  const path = `/crm/company/details/${companyId}/`
  try {
    const url = $b24.slider.getUrl(path)
    await $b24.slider.openPath(url)
  } catch (e) {
    console.error('Failed to open company:', e)
  }
}

const creatingCounterpartyPaymentId = ref<string | null>(null)

function isIndividualInn(inn: string | null | undefined): boolean {
  const digits = String(inn ?? '').replace(/\D/g, '')
  return digits.length === 12
}

function canCreateCounterpartyFromPayment(p: {
  payment_source?: string | null
  counterparty_company_id?: number | null
  counterparty_contact_id?: number | null
  counterparty_name?: string | null
}): boolean {
  if (p.payment_source === 'acquiring') return false
  if (p.counterparty_company_id || p.counterparty_contact_id) return false
  return Boolean((p.counterparty_name || '').trim())
}

function createCounterpartyLabel(p: { counterparty_inn?: string | null }): string {
  return isIndividualInn(p.counterparty_inn) ? 'Создать контакт' : 'Создать компанию'
}

async function getRequisitePresetId(entityTypeId: number): Promise<number | null> {
  if (!$b24) return null
  const resp = await $b24.callMethod('crm.requisite.preset.list', {
    filter: { ENTITY_TYPE_ID: entityTypeId },
  })
  const presets = resp.getData().result || []
  const first = Array.isArray(presets) ? presets.find((row: { ID?: number }) => row?.ID) : null
  return first?.ID ? Number(first.ID) : null
}

async function addInnRequisite(entityTypeId: number, entityId: number, name: string, inn: string) {
  if (!$b24) return
  const presetId = await getRequisitePresetId(entityTypeId)
  if (!presetId) return
  const fields: Record<string, unknown> = {
    ENTITY_TYPE_ID: entityTypeId,
    ENTITY_ID: entityId,
    PRESET_ID: presetId,
    NAME: name.slice(0, 150) || 'Реквизиты',
    RQ_INN: inn,
  }
  if (entityTypeId === 4) {
    fields.RQ_COMPANY_NAME = name
  }
  await $b24.callMethod('crm.requisite.add', { fields })
}

async function createCounterpartyFromPayment(p: {
  id: string
  counterparty_name?: string | null
  counterparty_inn?: string | null
  counterparty_company_id?: number | null
  counterparty_contact_id?: number | null
}) {
  if (!$b24 || creatingCounterpartyPaymentId.value) return
  const name = (p.counterparty_name || '').trim()
  if (!name) return

  creatingCounterpartyPaymentId.value = p.id
  try {
    const inn = (p.counterparty_inn || '').replace(/\D/g, '')
    const asContact = isIndividualInn(inn)

    if (asContact) {
      const resp = await $b24.callMethod('crm.contact.add', {
        fields: { NAME: name, OPENED: 'Y' },
      })
      const contactId = Number(resp.getData().result)
      if (!contactId) throw new Error('No contact id')
      if (inn.length >= 10) await addInnRequisite(3, contactId, name, inn)
      p.counterparty_contact_id = contactId
      await openContact(contactId)
    } else {
      const resp = await $b24.callMethod('crm.company.add', {
        fields: { TITLE: name, OPENED: 'Y' },
      })
      const companyId = Number(resp.getData().result)
      if (!companyId) throw new Error('No company id')
      if (inn.length >= 10) await addInnRequisite(4, companyId, name, inn)
      p.counterparty_company_id = companyId
      await openCompany(companyId)
    }
  } catch (e) {
    $logger.error('createCounterpartyFromPayment', e)
  } finally {
    creatingCounterpartyPaymentId.value = null
  }
}

// Payment filters
const paymentFilter = ref('all')
const paymentFilters = [
  { key: 'all', label: 'Всего', color: 'text-gray-700 bg-gray-100' },
  { key: 'pending', label: 'Ожидает', color: 'text-blue-700 bg-blue-50' },
  { key: 'matched', label: 'Сопоставлен', color: 'text-amber-900 bg-amber-50' },
  { key: 'applied', label: 'Применен', color: 'text-green-800 bg-green-50' },
  { key: 'unprocessed', label: 'Требует проверки', color: 'text-orange-800 bg-orange-50' },
  { key: 'error', label: 'Ошибка', color: 'text-red-800 bg-red-50' },
  { key: 'ignored', label: 'Игнорирован', color: 'text-gray-700 bg-slate-100' },
]

async function loadBankData() {
  if (!tochkaStore.status.connected) return
  isLoadingData.value = true
  try {
    await tochkaStore.fetchAccounts()
    await Promise.allSettled(
      tochkaStore.accounts.map(acc => tochkaStore.fetchBalances(acc.accountId))
    )
  } catch (e) {
    $logger.error('Failed to load bank data', e)
  } finally {
    isLoadingData.value = false
  }
}

async function quickSyncPayments() {
  isManualSyncing.value = true
  syncError.value = ''
  try {
    await tochkaStore.syncPayments({ days: SYNC_QUICK_DAYS })
    await tochkaStore.fetchPayments(1, paymentFilter.value, paymentSearchFilters.value)
    currentPage.value = 1
  } catch (e: any) {
    syncError.value = tochkaStore.lastSyncError || tochkaStore.error || 'Ошибка синхронизации'
    $logger.error('Sync failed', e)
  } finally {
    isManualSyncing.value = false
  }
}

async function submitStatementPeriodSync() {
  if (new Date(syncDate.value) > new Date(syncDateEnd.value)) {
    syncError.value = 'Дата «с» не может быть позже даты «по»'
    return
  }
  showSyncModal.value = false
  isManualSyncing.value = true
  syncError.value = ''
  try {
    await tochkaStore.syncPayments({ startDate: syncDate.value, endDate: syncDateEnd.value })
    await tochkaStore.fetchPayments(1, paymentFilter.value, paymentSearchFilters.value)
    currentPage.value = 1
  } catch (e: any) {
    syncError.value = tochkaStore.lastSyncError || tochkaStore.error || 'Ошибка синхронизации'
    $logger.error('Sync failed', e)
  } finally {
    isManualSyncing.value = false
  }
}

function openSyncModal() {
  const today = new Date().toISOString().slice(0, 10)
  const from = new Date()
  from.setDate(from.getDate() - SYNC_QUICK_DAYS)
  syncDate.value = from.toISOString().slice(0, 10)
  syncDateEnd.value = today
  showSyncModal.value = true
}

async function loadPayments() {
  await tochkaStore.fetchPayments(currentPage.value, paymentFilter.value, paymentSearchFilters.value)
}

/** Идентификатор счёта Точки часто приходит как «номер счёта/БИК». */
function parseTochkaAccountId(accountId: string | null | undefined): { accountNumber: string; bik: string | null } {
  if (!accountId) return { accountNumber: '', bik: null }
  const s = String(accountId).trim()
  const slash = s.indexOf('/')
  if (slash === -1) return { accountNumber: s, bik: null }
  const num = s.slice(0, slash).trim()
  const bik = s.slice(slash + 1).trim()
  return { accountNumber: num, bik: bik || null }
}

/** Подсказка: полный номер и БИК. */
function accountIdTitle(accountId: string | null | undefined): string {
  if (!accountId) return ''
  const { accountNumber, bik } = parseTochkaAccountId(accountId)
  if (!accountNumber && !bik) return ''
  if (bik) return `${accountNumber} / ${bik}`
  return accountNumber
}

/** Последние 4 цифры номера счёта (без БИК). */
function accountNumberLastFour(accountId: string | null | undefined): string {
  const { accountNumber } = parseTochkaAccountId(accountId)
  if (!accountNumber) return '—'
  const digits = accountNumber.replace(/\D/g, '')
  if (digits.length >= 4) return digits.slice(-4)
  return accountNumber.length >= 4 ? accountNumber.slice(-4) : accountNumber || '—'
}

function formatCurrency(val: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 2 }).format(val)
}

function formatDate(iso: string): string {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

function formatDateTime(iso: string): string {
  if (!iso) return ''
  let s = iso.trim()
  // Нормализуем «наивный» ISO с сервера как UTC, чтобы в браузере показать локаль пользователя
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s) && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(s)) {
    s = `${s}Z`
  }
  const d = new Date(s)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
}

const bankPushConfigured = computed(() => tochkaStore.status.bankPushConfigured === true)

/** Жёлтый индикатор: идёт любая синхронизация (ручная или фоновая по расписанию из настроек). */
const syncInProgress = computed(
  () => isManualSyncing.value || isBackgroundSyncing.value,
)

/** Второй блок всплывающего окна: как подгружаются платежи, если push в банке не настроен. */
function bankDeliveryInfoForPopover(): string {
  const cli = tochkaStore.portalPreferences.clientAutoSyncIntervalMinutes
  const autoOn = tochkaStore.portalPreferences.clientAutoSyncEnabled

  if (autoOn) {
    return `Фоновая синхронизация включена: сервер запрашивает выписку у банка каждые ${cli} мин. и продолжает работу при закрытом приложении. Пока дашборд открыт, он дополнительно обновляет данные с тем же интервалом. Мгновенная доставка из банка (push) не настроена.`
  }

  return 'Мгновенная доставка из банка (push) не настроена. Подгрузите платежи кнопками «Синхронизировать» и «Загрузить выписку» или включите фоновую синхронизацию в настройках. После включения сервер продолжает синхронизацию даже при закрытом приложении; интервал берётся из настройки. Сервер проверяет расписание каждую минуту.'
}

const syncStatusDotClass = computed(() => {
  if (syncInProgress.value) return 'bg-amber-400 animate-pulse'
  if (tochkaStore.lastSyncError) return 'bg-red-500'
  if (tochkaStore.lastSyncDate) return 'bg-green-500'
  if (tochkaStore.portalPreferences.clientAutoSyncEnabled) return 'bg-green-400'
  return 'bg-gray-300'
})

/** Блоки текста для всплывающего окна статуса (как меню «Привязать»). */
const syncStatusPopoverBlocks = computed(() => {
  const blocks: string[] = []
  if (syncInProgress.value) {
    blocks.push(
      isBackgroundSyncing.value && !isManualSyncing.value
        ? 'Идёт синхронизация (постоянная или по кнопке)...'
        : 'Идёт синхронизация...',
    )
    return blocks
  }
  if (tochkaStore.lastSyncError) {
    blocks.push(tochkaStore.lastSyncError)
    return blocks
  }
  if (tochkaStore.lastSyncDate) {
    blocks.push(
      `Синхронизация выполнена. Последний раз: ${formatDateTime(tochkaStore.lastSyncDate)} (ваше локальное время).`,
    )
    if (!bankPushConfigured.value) blocks.push(bankDeliveryInfoForPopover())
    return blocks
  }
  if (tochkaStore.portalPreferences.clientAutoSyncEnabled) {
    blocks.push(bankDeliveryInfoForPopover())
    blocks.push('Синхронизация в этой сессии ещё не завершалась успешно. Сервер продолжит опрос банка по расписанию.')
    return blocks
  }
  if (!bankPushConfigured.value) {
    blocks.push(
      `${bankDeliveryInfoForPopover()} Синхронизация в этой сессии ещё не завершалась успешно.`,
    )
    return blocks
  }
  blocks.push('Синхронизация ещё не выполнялась')
  return blocks
})

const showSyncStatusPopover = ref(false)

function toggleSyncStatusPopover() {
  paymentActionMenu.value = null
  acquiringActionMenu.value = null
  showSyncStatusPopover.value = !showSyncStatusPopover.value
}

function formatDateHeader(iso: string): string {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

const groupedPayments = computed(() => {
  const groups: Record<string, { payments: any[]; total: number }> = {}
  for (const p of tochkaStore.payments) {
    const dateKey = p.transaction_date?.split('T')[0] || 'unknown'
    if (!groups[dateKey]) groups[dateKey] = { payments: [], total: 0 }
    groups[dateKey].payments.push(p)
    groups[dateKey].total += p.amount || 0
  }
  return groups
})

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    pending: 'Ожидает',
    matched: 'Сопоставлен',
    applied: 'Применен',
    refunded: 'Возвращён',
    unprocessed: 'Требует проверки',
    error: 'Ошибка',
    ignored: 'Игнорирован',
  }
  return map[s] || s
}

function statusColor(s: string): string {
  const map: Record<string, string> = {
    pending: 'bg-blue-50 text-blue-800',
    matched: 'bg-amber-50 text-amber-900',
    applied: 'bg-green-50 text-green-800',
    refunded: 'bg-violet-50 text-violet-800',
    unprocessed: 'bg-orange-50 text-orange-800',
    error: 'bg-red-50 text-red-800',
    ignored: 'bg-slate-100 text-gray-700',
  }
  return map[s] || 'bg-gray-100 text-gray-500'
}

async function loadDealStages() {
  if (!$b24) return
  try {
    const data = await fetchDealCategoriesAndStages($b24)
    dealStages.value = data.allStages
    dealCategories.value = data.categories
    dealStagesByCategory.value = data.stagesByCategory
  } catch {
    dealStages.value = [{ value: '', label: 'Не перемещать' }]
    dealCategories.value = []
    dealStagesByCategory.value = {}
  }
}

function stagesForCategory(categoryId: number): DealStageOption[] {
  if (categoryId === DEAL_STAGE_NO_MOVE) {
    return [{ value: '', label: '—' }]
  }
  return dealStagesByCategory.value[categoryId] || [{ value: '', label: 'Не перемещать' }]
}

function stagesForSelectedFunnel(categoryId: number): DealStageOption[] {
  return stagesForCategory(categoryId).filter(s => s.value !== '')
}

function categoryLabel(categoryId: number): string {
  return dealCategories.value.find(c => c.id === categoryId)?.name || `Воронка ${categoryId}`
}

function availableCategoriesForRule(currentCategoryId: number): DealCategoryOption[] {
  const used = new Set(
    dealMatching.value.categoryRules
      .map(r => r.categoryId)
      .filter(id => id !== currentCategoryId),
  )
  return dealCategories.value.filter(c => !used.has(c.id))
}

function addCategoryStageRule() {
  const next = dealCategories.value.find(
    c => !dealMatching.value.categoryRules.some(r => r.categoryId === c.id),
  )
  if (!next) return
  dealMatching.value.categoryRules.push(defaultDealCategoryStageRule(next.id))
}

function removeCategoryStageRule(index: number) {
  dealMatching.value.categoryRules.splice(index, 1)
}

const canAddCategoryStageRule = computed(() =>
  dealMatching.value.categoryRules.length < dealCategories.value.length,
)

const settingsSelectClass =
  'settings-select block w-full min-w-[12rem] rounded-lg border border-gray-200 bg-white px-3 py-2.5 pr-9 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-violet-400'

const funnelSelectOptions = computed(() => [
  { id: DEAL_STAGE_NO_MOVE, name: 'Не перемещать' },
  ...dealCategories.value,
])

function isFunnelSelected(categoryId: number): boolean {
  return categoryId !== DEAL_STAGE_NO_MOVE
}

function onGlobalPaidFunnelChange() {
  if (!isFunnelSelected(dealMatching.value.stagePaidCategoryId)) {
    dealMatching.value.stagePaidId = ''
    return
  }
  const stages = stagesForCategory(dealMatching.value.stagePaidCategoryId)
  if (!stages.some(s => s.value === dealMatching.value.stagePaidId)) {
    dealMatching.value.stagePaidId = ''
  }
}

function onGlobalPartialFunnelChange() {
  if (!dealMatching.value.moveOnPartial || !isFunnelSelected(dealMatching.value.stagePartialCategoryId)) {
    dealMatching.value.stagePartialId = ''
    if (!dealMatching.value.moveOnPartial) {
      dealMatching.value.stagePartialCategoryId = DEAL_STAGE_NO_MOVE
    }
    return
  }
  const stages = stagesForCategory(dealMatching.value.stagePartialCategoryId)
  if (!stages.some(s => s.value === dealMatching.value.stagePartialId)) {
    dealMatching.value.stagePartialId = ''
  }
}

function onRuleFunnelChange(rule: { categoryId: number; stagePaidId: string; stagePartialId: string }) {
  const stages = stagesForCategory(rule.categoryId)
  if (!stages.some(s => s.value === rule.stagePaidId)) rule.stagePaidId = ''
  if (!stages.some(s => s.value === rule.stagePartialId)) rule.stagePartialId = ''
}

watch(
  () => dealMatching.value.moveOnPartial,
  (enabled) => {
    if (!enabled) {
      dealMatching.value.stagePartialCategoryId = DEAL_STAGE_NO_MOVE
      dealMatching.value.stagePartialId = ''
    }
  },
)

/** Статусы классических счетов CRM (INVOICE_STATUS). */
async function loadInvoiceStatuses() {
  if (!$b24) return
  const fallback = [
    { value: 'P', label: 'Оплачен' },
    { value: 'D', label: 'Не оплачен' },
    { value: 'N', label: 'Новый' },
  ]
  try {
    const resp = await $b24.callMethod('crm.status.list', { filter: { ENTITY_ID: 'INVOICE_STATUS' } })
    const statuses: Array<{ value: string; label: string }> = []
    for (const s of (resp.getData().result || [])) {
      statuses.push({ value: s.STATUS_ID, label: s.NAME })
    }
    invoiceStatuses.value = statuses.length > 0 ? statuses : fallback
    if (!invoiceStatusPaid.value) {
      const paid = invoiceStatuses.value.find(s => s.value === 'P')
      if (paid) invoiceStatusPaid.value = paid.value
    }
    if (!invoiceStatusPartialPaid.value) {
      const partial = invoiceStatuses.value.find(s => s.value === 'PP' || s.value === 'P')
      if (partial) invoiceStatusPartialPaid.value = partial.value
    }
  } catch {
    invoiceStatuses.value = fallback
  }
}

function formatBalance(accountId: string): string {
  const balances = tochkaStore.balances[accountId] || []
  const b = balances.find((b: any) => b.type === 'ClosingAvailable' || b.type === 'OpeningAvailable')
  if (!b?.amount) return '—'
  const num = parseFloat(b.amount.amount)
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: b.amount.currency || 'RUB',
    minimumFractionDigits: 2,
  }).format(num)
}

function closeMenus() {
  accountMenuOpen.value = null
  showSyncStatusPopover.value = false
  paymentSearchOpen.value = false
}

watch(settingsSubTab, async (t) => {
  if (t === 'sync') pullSyncPrefsFromStore()
  if (t === 'fields' || t === 'paymentCrm') {
    pullPortalFieldPrefsFromStore()
  }
  if (t === 'paymentCrm') {
    void loadAcquiringStatus()
  }
  if (t === 'paymentCrm' && dealUserFields.value.length === 0) {
    await loadDealUserFields()
    pullPortalFieldPrefsFromStore()
  }
  if (t === 'notifications') {
    pullNotifyPrefsFromStore()
    void loadAvailableChats()
  }
  if (t === 'matching') {
    pullDealMatchingFromStore()
    if (dealUserFields.value.length === 0) {
      void loadDealUserFields().then(() => {
        syncUfSearchLabelsFromDealMatching()
      })
    }
  }
  if (t === 'automation') {
    pullInvoicePrefsFromStore()
    pullDealMatchingFromStore()
    void loadInvoiceStatuses()
    void loadDealStages()
  }
})

onMounted(async () => {
  document.addEventListener('click', closeMenus)
  try {
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale)
    await $b24.parent.setTitle('Точка Банк')
    await tochkaStore.fetchStatus()
    if (!tochkaStore.status.connected) {
      navigateTo('/')
      return
    }
    // Сразу убираем заставку; тяжёлые CRM/Точка запросы — в фоне (сотни REST вызовов раньше блокировали UI)
    isInit.value = true
    void Promise.all([
      loadBankData(),
      tochkaStore.fetchPortalPreferences(),
      loadDealStages(),
      loadInvoiceStatuses(),
      tochkaStore.fetchPayments(1),
    ])
      .then(() => {
        pullSyncPrefsFromStore()
        pullPortalFieldPrefsFromStore()
        pullNotifyPrefsFromStore()
        pullInvoicePrefsFromStore()
        restartClientSyncTimer()
      })
      .catch((e) => {
        $logger.error('Dashboard background load failed', e)
      })
  } catch (error) {
    processErrorGlobal(error)
  }
})

onBeforeUnmount(() => {
  clearClientSyncTimer()
  document.removeEventListener('click', closeMenus)
})
</script>

<template>
  <div
    v-if="!isInit"
    class="fixed inset-0 z-[100] min-h-[100dvh] bg-white"
    aria-hidden="true"
  />

  <div v-else class="min-h-screen bg-white w-full min-w-0 max-w-full overflow-x-hidden">
    <!-- Tabs -->
    <div class="border-b border-gray-200">
      <nav class="flex gap-6 px-6">
        <button
          v-for="tab in [
            { key: 'payments', label: 'Платежи' },
            { key: 'accounts', label: 'Банковские счета' },
            { key: 'settings', label: 'Настройки' },
          ]"
          :key="tab.key"
          class="py-3 text-sm font-medium border-b-2 transition-colors"
          :class="activeTab === tab.key
            ? 'border-violet-500 text-violet-600'
            : 'border-transparent text-gray-500 hover:text-gray-700'"
          @click="activeTab = tab.key as any"
        >
          {{ tab.label }}
        </button>
      </nav>
    </div>

    <div class="px-6 py-5 w-full min-w-0 max-w-full box-border">

      <!-- ============ ПЛАТЕЖИ ============ -->
      <div v-if="activeTab === 'payments'">
        <div class="flex flex-col gap-3 mb-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0 flex-1">
            <h2 class="text-lg font-bold text-gray-900 whitespace-nowrap shrink-0">Платежи</h2>
            <button
              type="button"
              class="relative shrink-0 p-2 rounded-lg border transition-colors"
              :class="paymentSearchOpen || hasActivePaymentSearch
                ? 'border-violet-400 bg-violet-50 text-violet-600'
                : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50'"
              aria-label="Поиск платежей"
              @click.stop="togglePaymentSearch"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <span
                v-if="hasActivePaymentSearch"
                class="absolute -top-0.5 -right-0.5 w-2 h-2 rounded-full bg-violet-500"
              />
            </button>
            <div class="flex flex-wrap items-center gap-2 min-w-0">
              <button
                v-for="f in paymentFilters"
                :key="f.key"
                class="px-3 py-1 rounded-full text-xs font-medium border transition-colors whitespace-nowrap"
                :class="paymentFilter === f.key
                  ? 'border-violet-500 bg-violet-50 text-violet-700'
                  : `border-gray-200 text-gray-500 hover:border-gray-300 ${f.color}`"
                @click="paymentFilter = f.key; currentPage = 1; loadPayments()"
              >
                {{ f.label }}: {{ f.key === 'all' ? (tochkaStore.paymentsCounts.all || 0) : (tochkaStore.paymentsCounts[f.key] || 0) }}
              </button>
            </div>
          </div>
          <div class="flex flex-col items-end gap-2 sm:flex-row sm:items-center sm:flex-wrap sm:justify-end shrink-0">
            <div class="relative order-2 sm:order-1">
              <button
                type="button"
                class="flex items-center gap-2 rounded-lg px-1.5 py-1 -mx-0.5 hover:bg-gray-50 transition-colors text-left"
                aria-haspopup="true"
                :aria-expanded="showSyncStatusPopover"
                @click.stop="toggleSyncStatusPopover"
              >
                <span
                  v-if="tochkaStore.lastSyncDate"
                  class="text-xs text-gray-500 whitespace-nowrap"
                >{{ formatDateTime(tochkaStore.lastSyncDate) }}</span>
                <span
                  v-else-if="tochkaStore.portalPreferences.clientAutoSyncEnabled"
                  class="text-xs text-gray-500 whitespace-nowrap"
                >Фоновая синхронизация включена</span>
                <span
                  v-else
                  class="text-xs text-gray-400 whitespace-nowrap"
                >Нет данных о синхронизации</span>
                <span
                  class="w-2 h-2 rounded-full shrink-0"
                  :class="syncStatusDotClass"
                />
              </button>
              <div
                v-if="showSyncStatusPopover"
                class="absolute right-0 top-full mt-1 z-30 w-80 max-w-[min(100vw-2rem,20rem)] bg-gray-800 text-white rounded-xl shadow-lg py-1.5 border border-gray-700/80"
                role="dialog"
                aria-label="Статус синхронизации"
                @click.stop
              >
                <template v-for="(block, i) in syncStatusPopoverBlocks" :key="i">
                  <hr v-if="i > 0" class="my-0 border-0 border-t border-gray-700">
                  <p
                    class="px-3 py-2.5 text-sm leading-relaxed"
                    :class="i === syncStatusPopoverBlocks.length - 1 && syncStatusPopoverBlocks.length > 1 && !bankPushConfigured ? 'text-gray-300' : 'text-white'"
                  >
                    {{ block }}
                  </p>
                </template>
              </div>
            </div>
            <div class="flex items-center gap-2 order-1 sm:order-2">
              <button
                type="button"
                class="px-3 py-2 border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors whitespace-nowrap disabled:opacity-50"
                :disabled="isManualSyncing"
                @click="openSyncModal"
              >
                Загрузить выписку
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2 whitespace-nowrap disabled:opacity-50"
                :disabled="isManualSyncing"
                @click="quickSyncPayments"
              >
                <svg v-if="isManualSyncing" class="w-4 h-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                <svg v-else class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                Синхронизировать
              </button>
            </div>
          </div>
          </div>

          <div
            v-if="paymentSearchOpen"
            class="relative w-full"
            @click.stop
          >
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none z-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <div
              class="flex flex-wrap items-center gap-1.5 pl-9 pr-9 py-1.5 min-h-[38px] border border-gray-200 rounded-lg text-sm w-full focus-within:ring-2 focus-within:ring-violet-400 focus-within:border-transparent bg-white"
            >
              <span
                v-for="chip in paymentSearchChips"
                :key="chip.key + chip.label"
                class="inline-flex items-center gap-1 max-w-[14rem] px-2 py-0.5 rounded-md bg-violet-50 text-violet-800 text-xs shrink-0"
              >
                <span class="truncate">{{ chip.label }}</span>
                <button
                  type="button"
                  class="shrink-0 text-violet-500 hover:text-violet-800 leading-none"
                  aria-label="Удалить фильтр"
                  @click="removePaymentSearchChip(chip.key)"
                >
                  ×
                </button>
              </span>
              <input
                ref="paymentSearchInputRef"
                v-model="paymentSearchDraft"
                type="text"
                placeholder="Назначение, ИНН… сумма:, дата:, контрагент:"
                class="flex-1 min-w-[12rem] border-0 p-0 py-0.5 bg-transparent focus:outline-none focus:ring-0 text-sm"
                @keydown.enter.prevent="commitPaymentSearchDraft"
                @keydown.backspace="onPaymentSearchBackspace"
                @keydown.esc.prevent="closePaymentSearch"
              />
            </div>
            <button
              type="button"
              class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-400 hover:text-gray-600"
              aria-label="Закрыть поиск"
              @click="closePaymentSearch"
            >
              ×
            </button>
          </div>
        </div>

        <!-- Syncing state -->
        <div v-if="isManualSyncing" class="flex flex-col items-center justify-center py-20">
          <div class="relative w-20 h-20 mb-4">
            <div class="absolute inset-0 rounded-full border-4 border-gray-200" />
            <div class="absolute inset-0 rounded-full border-4 border-transparent border-t-violet-500 animate-spin" />
            <div class="absolute inset-0 flex items-center justify-center">
              <svg class="w-8 h-8 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
            </div>
          </div>
          <p class="text-lg text-gray-400">Синхронизация платежей...</p>
        </div>

        <!-- Payments table -->
        <div v-else-if="tochkaStore.payments.length > 0">
          <!-- Total amount -->
          <div class="text-sm text-violet-600 font-medium mb-2">{{ formatCurrency(tochkaStore.paymentsTotalAmount) }}</div>

          <!-- Payments table -->
          <div class="overflow-x-auto -mx-1 px-1">
          <table class="w-full min-w-[1320px] text-sm table-fixed">
            <colgroup>
              <col class="w-[3rem]" />
              <col class="w-[5.5rem]" />
              <col class="w-[7.5rem]" />
              <col class="w-[6.5rem]" />
              <col class="w-[4.5rem]" />
              <col class="w-[5.5rem]" />
              <col class="w-[22rem]" />
              <col class="w-[14rem]" />
              <col class="w-[7rem]" />
              <col class="w-[11rem]" />
              <col class="w-[7.5rem]" />
            </colgroup>
            <thead class="payments-table-head">
              <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wide">
                <th class="py-2">Счет</th>
                <th class="py-2">№Пл.Док.</th>
                <th class="py-2">Сумма</th>
                <th class="py-2">Статус</th>
                <th class="py-2">Счёт №</th>
                <th class="py-2">НДС</th>
                <th class="py-2 px-3 pr-4">Назначение платежа</th>
                <th class="py-2 pl-3">Контрагент</th>
                <th class="py-2">ИНН</th>
                <th class="py-2">Сделка</th>
                <th class="py-2"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(group, dateKey) in groupedPayments" :key="dateKey">
                <!-- Date row -->
                <tr>
                  <td colspan="11" class="pt-5 pb-2">
                    <div class="flex items-center gap-2 text-sm font-medium text-violet-600">
                      <span>{{ formatCurrency(group.total) }}</span>
                      <span>{{ formatDateHeader(dateKey) }}</span>
                    </div>
                  </td>
                </tr>
                <!-- Payment rows -->
                <tr
                  v-for="p in group.payments"
                  :key="p.id"
                  class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors"
                >
                  <td
                    class="py-3 text-gray-400 text-xs font-mono"
                    :title="accountIdTitle(p.account_id)"
                  >
                    {{ accountNumberLastFour(p.account_id) }}
                  </td>
                  <td class="py-3 text-gray-600 text-xs font-mono whitespace-nowrap max-w-[7rem] truncate" :title="p.payment_slip_number || ''">{{ p.payment_slip_number || '—' }}</td>
                  <td class="py-3 font-semibold text-gray-900 whitespace-nowrap">{{ formatCurrency(p.amount) }}</td>
                  <td class="py-3">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" :class="statusColor(p.status)">{{ statusLabel(p.status) }}</span>
                  </td>
                  <td class="py-3 overflow-visible">
                    <template v-if="paymentInvoiceNumber(p)">
                      <div class="relative group inline-flex">
                        <button
                          v-if="p.matched_invoice_id"
                          type="button"
                          class="inline-flex min-w-[2rem] items-center justify-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-gray-700 transition-colors hover:bg-violet-100 hover:text-violet-700"
                          @click="openInvoice(p.matched_invoice_id)"
                        >
                          {{ paymentInvoiceNumber(p) }}
                        </button>
                        <span
                          v-else
                          class="inline-flex min-w-[2rem] cursor-default items-center justify-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-gray-700"
                        >
                          {{ paymentInvoiceNumber(p) }}
                        </span>

                        <div
                          class="pointer-events-none absolute left-0 top-full z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] opacity-0 invisible transition-all duration-200 group-hover:opacity-100 group-hover:visible"
                        >
                          <div class="relative rounded-xl bg-gray-900 p-4 text-left text-white shadow-xl">
                            <div class="absolute -top-1.5 left-5 h-3 w-3 rotate-45 bg-gray-900" />

                            <div class="mb-3 flex items-start justify-between gap-3">
                              <div class="flex min-w-0 items-center gap-2">
                                <svg class="h-4 w-4 shrink-0 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                </svg>
                                <span class="text-sm text-gray-200">{{ paymentChannelTitle(p) }}</span>
                              </div>
                              <span class="shrink-0 text-right text-[11px] leading-snug text-gray-400">
                                {{ paymentReceivedAtLabel(p.transaction_date) }}
                              </span>
                            </div>

                            <div class="mb-3 text-2xl font-semibold tabular-nums">
                              {{ formatCurrency(p.amount) }}
                            </div>

                            <hr class="mb-3 border-gray-700" />

                            <div class="space-y-2 text-sm">
                              <div class="flex justify-between gap-4">
                                <span class="shrink-0 text-gray-400">Плательщик</span>
                                <span class="text-right text-gray-100">{{ p.counterparty_name || '—' }}</span>
                              </div>
                              <div class="flex justify-between gap-4">
                                <span class="shrink-0 text-gray-400">ИНН</span>
                                <span class="text-right font-mono text-gray-100">{{ p.counterparty_inn || '—' }}</span>
                              </div>
                              <div v-if="paymentSlipLabel(p)" class="flex justify-between gap-4">
                                <span class="shrink-0 text-gray-400">Платёжное поручение</span>
                                <span class="text-right text-gray-100">{{ paymentSlipLabel(p) }}</span>
                              </div>
                            </div>

                            <div v-if="paymentInvoiceFoundLabel(p)" class="mt-4 border-t border-gray-700 pt-3">
                              <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                Найдено в назначении
                              </div>
                              <div class="text-sm font-medium text-white">
                                {{ paymentInvoiceFoundLabel(p) }}
                              </div>
                            </div>

                            <div class="mt-3">
                              <div class="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                Назначение платежа
                              </div>
                              <div class="text-sm leading-snug text-gray-300">
                                {{ p.purpose || '—' }}
                              </div>
                            </div>

                            <div
                              v-if="p.matched_invoice_id && p.invoice_title"
                              class="mt-3 border-t border-gray-700 pt-3 text-xs text-violet-300"
                            >
                              В CRM: {{ p.invoice_title }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </template>
                    <span v-else class="text-gray-300">—</span>
                  </td>
                  <td class="py-3 text-gray-400 text-xs whitespace-nowrap">{{ p.vat_type || 'Без НДС' }}</td>
                  <td class="py-3 px-3 pr-4 align-middle max-w-0">
                    <div class="payments-purpose-cell">{{ p.purpose || '—' }}</div>
                  </td>
                  <td class="py-3 pl-3 text-gray-700 text-xs align-top">
                    <div class="flex flex-col gap-1 min-w-0">
                    <!-- Эквайринг: как раньше — контакт из сделки/лида -->
                    <template v-if="p.payment_source === 'acquiring' && p.counterparty_contact_id">
                      <button
                        type="button"
                        class="text-left text-violet-700 hover:underline break-words"
                        @click="openContact(p.counterparty_contact_id)"
                      >
                        {{ p.counterparty_name || '—' }}
                      </button>
                    </template>
                    <!-- Выписка: компания по ИНН из реквизитов CRM -->
                    <template v-else-if="p.counterparty_company_id">
                      <button
                        type="button"
                        class="text-left text-violet-700 hover:underline break-words"
                        @click="openCompany(p.counterparty_company_id)"
                      >
                        {{ p.counterparty_name || '—' }}
                      </button>
                    </template>
                    <template v-else-if="p.counterparty_contact_id">
                      <button
                        type="button"
                        class="text-left text-violet-700 hover:underline break-words"
                        @click="openContact(p.counterparty_contact_id)"
                      >
                        {{ p.counterparty_name || '—' }}
                      </button>
                    </template>
                    <template v-else>
                      <span class="break-words leading-snug">{{ p.counterparty_name || '—' }}</span>
                      <button
                        v-if="canCreateCounterpartyFromPayment(p)"
                        type="button"
                        class="inline-flex w-fit items-center gap-1 rounded-md border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-700 hover:bg-violet-100 disabled:opacity-50"
                        :disabled="creatingCounterpartyPaymentId === p.id"
                        @click="createCounterpartyFromPayment(p)"
                      >
                        <span v-if="creatingCounterpartyPaymentId === p.id">…</span>
                        <template v-else>+ {{ createCounterpartyLabel(p) }}</template>
                      </button>
                    </template>
                    </div>
                  </td>
                  <td class="py-3 text-gray-500 font-mono text-xs">
                    <template v-if="p.payment_source !== 'acquiring' && p.counterparty_company_id && p.counterparty_inn">
                      <button
                        type="button"
                        class="hover:text-violet-700 hover:underline"
                        @click="openCompany(p.counterparty_company_id)"
                      >
                        {{ p.counterparty_inn }}
                      </button>
                    </template>
                    <template v-else>
                      {{ p.counterparty_inn || '—' }}
                    </template>
                  </td>
                  <td class="py-3">
                    <template v-if="p.matched_deal_id">
                      <div class="relative group">
                        <a 
                          class="flex items-center gap-2 cursor-pointer hover:opacity-80 transition-opacity"
                          @click="openDeal(p.matched_deal_id)"
                        >
                          <!-- Avatar -->
                          <span v-if="p.deal_responsible_photo" class="w-7 h-7 rounded-full overflow-hidden flex-shrink-0 border border-gray-200">
                            <img :src="p.deal_responsible_photo" class="w-full h-full object-cover" />
                          </span>
                          <span v-else class="w-7 h-7 rounded-full bg-violet-500 flex items-center justify-center text-xs text-white flex-shrink-0">
                            {{ (p.deal_responsible_name || '?')[0] }}
                          </span>
                          <!-- Deal info -->
                          <div class="flex flex-col min-w-0">
                            <span class="text-sm text-gray-800 truncate">{{ p.deal_title || `Сделка #${p.matched_deal_id}` }}</span>
                            <span class="text-xs text-gray-500">{{ p.deal_amount ? formatCurrency(p.deal_amount) : '' }}</span>
                          </div>
                        </a>
                        <!-- Hover card -->
                        <div class="absolute right-0 bottom-full mb-2 w-64 bg-gray-900 text-white rounded-xl shadow-xl z-30 p-4 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 pointer-events-none">
                          <div class="flex items-center justify-between mb-2">
                            <span class="text-sm">Оплата сделки</span>
                            <span class="font-semibold" :class="p.deal_paid_percent >= 100 ? 'text-green-400' : 'text-white'">{{ p.deal_paid_percent }}%</span>
                            <span class="text-sm" :class="p.deal_paid_percent >= 100 ? 'text-green-400' : 'text-red-400'">
                              {{ p.deal_paid_percent >= 100 ? '+' : '' }}{{ formatCurrency(p.deal_paid_amount || p.amount) }}
                            </span>
                          </div>
                          <!-- Progress bar -->
                          <div class="h-1.5 bg-gray-700 rounded-full overflow-hidden mb-3">
                            <div
                              class="h-full rounded-full transition-all"
                              :class="p.deal_paid_percent >= 100 ? 'bg-green-500' : 'bg-red-500'"
                              :style="{ width: `${Math.min(p.deal_paid_percent || 0, 100)}%` }"
                            />
                          </div>
                          <!-- Payment info -->
                          <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                              <span class="w-2 h-2 rounded-full" :class="p.deal_paid_percent >= 100 ? 'bg-green-500' : 'bg-red-500'" />
                              <span class="text-gray-400">{{ formatDate(p.transaction_date) }}</span>
                            </div>
                            <span>{{ formatCurrency(p.amount) }}</span>
                          </div>
                        </div>
                      </div>
                    </template>
                    <template v-else>
                      <span class="text-gray-300">—</span>
                    </template>
                  </td>
                  <td class="py-3 text-right">
                    <div class="flex items-center gap-1 justify-end">
                      <template v-if="p.payment_source === 'acquiring'">
                        <div
                          v-if="p.can_cancel_link || p.can_unlink_deal || p.can_refund"
                          class="relative inline-flex"
                        >
                          <button
                            type="button"
                            class="inline-flex items-center gap-1 px-2.5 py-1 border border-gray-300 hover:border-violet-400 hover:bg-violet-50 text-gray-700 text-xs font-medium rounded-md transition-colors whitespace-nowrap"
                            @click.stop="toggleAcquiringMenu(p.id)"
                          >
                            Действия
                            <svg class="w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                          </button>
                          <div
                            v-if="acquiringActionMenu === p.id"
                            class="absolute right-0 top-full mt-1 w-56 overflow-hidden rounded-lg bg-gray-800 text-white shadow-lg z-20"
                          >
                            <button
                              v-if="p.can_cancel_link"
                              type="button"
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors"
                              @click="onAcquiringCancelLink(p)"
                            >
                              Отменить ссылку
                            </button>
                            <button
                              v-if="p.can_unlink_deal"
                              type="button"
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors"
                              @click="onAcquiringUnlinkDeal(p)"
                            >
                              {{ p.matched_deal_id ? 'Отвязать от сделки' : 'Отвязать от лида' }}
                            </button>
                            <button
                              v-if="p.can_refund"
                              type="button"
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors"
                              @click="onAcquiringRefund(p)"
                            >
                              Возврат
                            </button>
                          </div>
                        </div>
                        <span v-else class="text-gray-300">—</span>
                      </template>
                      <!-- Not bound: show Bind buttons -->
                      <template v-else-if="!p.matched_deal_id && !p.matched_invoice_id && p.status !== 'ignored'">
                        <div class="relative">
                          <button
                            class="inline-flex items-center gap-1 px-2.5 py-1 bg-violet-500 hover:bg-violet-600 text-white text-xs font-medium rounded-md transition-colors whitespace-nowrap"
                            @click.stop="togglePaymentMenu(p.id)"
                          >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                            Привязать
                            <svg class="w-3 h-3 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                          </button>
                          <div
                            v-if="paymentActionMenu === p.id"
                            class="absolute right-0 top-full mt-1 w-52 overflow-hidden rounded-lg bg-gray-800 text-white shadow-lg z-20"
                          >
                            <button
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors"
                              @click="openBindModal(p)"
                            >
                              Привязать к сделке
                            </button>
                            <button
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors"
                              @click="openBindInvoiceModal(p)"
                            >
                              Привязать к счёту
                            </button>
                            <hr class="my-0 border-gray-700" />
                            <button
                              class="w-full text-left px-3 py-2.5 text-sm hover:bg-gray-700 transition-colors text-gray-400"
                              @click="ignorePayment(p.id)"
                            >
                              Игнорировать
                            </button>
                          </div>
                        </div>
                      </template>
                      <!-- Bound to deal: show Unbind button -->
                      <template v-else-if="p.matched_deal_id">
                        <button
                          class="inline-flex items-center gap-1 px-2.5 py-1 border border-gray-300 hover:border-red-300 hover:bg-red-50 text-gray-600 hover:text-red-600 text-xs font-medium rounded-md transition-colors whitespace-nowrap"
                          @click="openUnbindConfirmModal('deal', p.id)"
                        >
                          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                          Отвязать
                        </button>
                      </template>
                      <!-- Bound to invoice: show Unbind button -->
                      <template v-else-if="p.matched_invoice_id">
                        <button
                          class="inline-flex items-center gap-1 px-2.5 py-1 border border-gray-300 hover:border-red-300 hover:bg-red-50 text-gray-600 hover:text-red-600 text-xs font-medium rounded-md transition-colors whitespace-nowrap"
                          @click="openUnbindConfirmModal('invoice', p.id)"
                        >
                          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                          Отвязать
                        </button>
                      </template>
                      <!-- Ignored -->
                      <template v-else-if="p.status === 'ignored'">
                        <span class="text-gray-400 text-xs">Игнорирован</span>
                      </template>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          </div>

          <!-- Pagination -->
          <div v-if="tochkaStore.paymentsTotal > 50" class="flex items-center justify-center gap-2 mt-4">
            <button
              class="px-3 py-1 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40"
              :disabled="currentPage <= 1"
              @click="currentPage--; loadPayments()"
            >
              Назад
            </button>
            <span class="text-sm text-gray-500">{{ currentPage }} / {{ Math.ceil(tochkaStore.paymentsTotal / 50) }}</span>
            <button
              class="px-3 py-1 text-sm border border-gray-200 rounded-lg hover:bg-gray-50 disabled:opacity-40"
              :disabled="currentPage >= Math.ceil(tochkaStore.paymentsTotal / 50)"
              @click="currentPage++; loadPayments()"
            >
              Далее
            </button>
          </div>
        </div>

        <!-- Empty state -->
        <div v-else class="flex flex-col items-center justify-center py-16 text-gray-400">
          <svg class="w-12 h-12 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <p class="text-sm">Платежи не найдены</p>
          <p class="text-xs mt-1">Синхронизируйте последние 30 дней или загрузите выписку за период</p>
        </div>

        <!-- Sync error -->
        <div v-if="syncError" class="mt-4 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          {{ syncError }}
        </div>

        <!-- Sync modal -->
        <div v-if="showSyncModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="showSyncModal = false">
          <div class="bg-white rounded-2xl shadow-xl w-[400px] p-6">
            <div class="flex items-center justify-between mb-5">
              <h3 class="text-lg font-bold text-gray-900">Загрузить выписку</h3>
              <button class="text-gray-400 hover:text-gray-600 text-xl" @click="showSyncModal = false">&times;</button>
            </div>
            <div class="space-y-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">Номер счёта:</label>
                <div class="text-sm text-gray-500 font-mono">{{ accountIdTitle(tochkaStore.accounts[0]?.accountId) || 'Все счета' }}</div>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Дата с:</label>
                <input
                  v-model="syncDate"
                  type="date"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400"
                />
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Дата по:</label>
                <input
                  v-model="syncDateEnd"
                  type="date"
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400"
                />
              </div>
            </div>
            <div class="flex items-center gap-3 mt-6">
              <button
                class="px-5 py-2.5 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50"
                :disabled="isManualSyncing"
                @click="submitStatementPeriodSync"
              >
                Загрузить
              </button>
              <button
                class="px-5 py-2.5 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors"
                @click="showSyncModal = false"
              >
                Отмена
              </button>
            </div>
          </div>
        </div>

        <!-- Отвязка платежа: Teleport + центрирование через transform (flex в iframe Bitrix24 схлопывал ширину карточки в «полоску») -->
        <Teleport to="body">
          <div
            v-if="showUnbindConfirmModal"
            class="pointer-events-auto fixed inset-0 z-[10000]"
          >
            <div
              class="absolute inset-0 bg-black/50"
              aria-hidden="true"
              @click="closeUnbindConfirmModal"
            />
            <div
              class="fixed left-1/2 top-1/2 z-[10001] box-border flex max-h-[min(90dvh,100vh)] w-[min(36rem,calc(100vw-2rem))] min-w-[min(18rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-2xl bg-white shadow-[0_8px_30px_rgba(0,0,0,0.12)]"
              role="dialog"
              aria-modal="true"
              aria-labelledby="unbind-modal-title"
              @click.stop
            >
              <div class="flex items-start justify-between gap-3 px-8 pt-8">
                <h3 id="unbind-modal-title" class="text-xl font-semibold leading-tight text-slate-800">
                  Отвязка платежа
                </h3>
                <button
                  type="button"
                  class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#f8f9fa] text-xl font-light leading-none text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-600"
                  aria-label="Закрыть"
                  @click="closeUnbindConfirmModal"
                >
                  ×
                </button>
              </div>
              <p class="mt-6 min-h-0 max-h-[min(40vh,16rem)] overflow-y-auto px-8 text-[15px] leading-relaxed text-gray-500">
                {{ unbindConfirmMessage }}
              </p>
              <hr class="mt-8 border-0 border-t border-gray-200" />
              <div class="flex flex-wrap items-center justify-start gap-3 px-8 py-6">
                <B24Button
                  label="Отмена"
                  type="button"
                  color="air-secondary"
                  size="md"
                  class="[--ui-btn-margin-left:0] ![--ui-btn-background:#f3e8ff] ![--ui-btn-background-hover:#ede9fe] ![--ui-btn-background-active:#e9d5ff] ![--ui-btn-border-color:#f3e8ff] ![--ui-btn-border-color-hover:#ede9fe] ![--ui-btn-border-color-active:#e9d5ff] ![--ui-btn-color:#7c3aed] ![--ui-btn-color-hover:#7c3aed] ![--ui-btn-color-active:#7c3aed]"
                  @click="closeUnbindConfirmModal"
                />
                <B24Button
                  label="OK"
                  type="button"
                  color="air-ai"
                  size="md"
                  class="[--ui-btn-margin-left:0] ![--ui-btn-background:#7c3aed] ![--ui-btn-background-hover:#6d28d9] ![--ui-btn-background-active:#5b21b6] ![--ui-btn-border-color:#7c3aed] ![--ui-btn-border-color-hover:#6d28d9] ![--ui-btn-border-color-active:#5b21b6] ![--ui-btn-color:#fff] ![--ui-btn-color-hover:#fff] ![--ui-btn-color-active:#fff]"
                  @click="confirmUnbindPayment"
                />
              </div>
            </div>
          </div>
        </Teleport>

        <!-- Bind to deal modal -->
        <div v-if="showBindModal && bindingPayment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="closeBindModal">
          <div class="bg-white rounded-2xl shadow-xl w-[480px] p-6">
            <div class="flex items-center justify-between mb-5">
              <h3 class="text-lg font-bold text-gray-900">Привязка к сделке</h3>
              <button class="text-gray-400 hover:text-gray-600 text-xl" @click="closeBindModal">&times;</button>
            </div>
            
            <!-- Payment info -->
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl mb-5">
              <div>
                <div class="text-sm text-gray-700 font-medium truncate max-w-[280px]">{{ bindingPayment.counterparty_name }}</div>
                <div class="text-xs text-gray-400 mt-0.5">{{ bindingPayment.purpose?.slice(0, 50) }}{{ bindingPayment.purpose?.length > 50 ? '...' : '' }}</div>
              </div>
              <div class="text-lg font-semibold text-gray-900">{{ formatCurrency(bindingPayment.amount) }}</div>
            </div>
            
            <!-- Deal search -->
            <div class="mb-5">
              <label class="block text-sm text-gray-700 mb-2">Поиск сделки</label>
              <div class="relative">
                <input
                  v-model="dealSearchQuery"
                  type="text"
                  placeholder="Введите название сделки..."
                  class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400"
                  @input="searchDeals"
                />
                <svg v-if="isSearchingDeals" class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                <svg v-else class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
              </div>
              
              <!-- Search results dropdown -->
              <div v-if="dealSearchResults.length > 0 && !selectedDeal" class="mt-2 border border-gray-200 rounded-xl overflow-hidden max-h-48 overflow-y-auto">
                <button
                  v-for="deal in dealSearchResults"
                  :key="deal.ID"
                  class="w-full text-left px-4 py-3 hover:bg-gray-50 border-b border-gray-100 last:border-b-0 transition-colors"
                  @click="selectedDeal = deal"
                >
                  <div class="text-sm font-medium text-gray-800">{{ deal.TITLE }}</div>
                  <div class="text-xs text-gray-500">ID: {{ deal.ID }} · {{ deal.OPPORTUNITY }} {{ deal.CURRENCY_ID }}</div>
                </button>
              </div>
            </div>
            
            <!-- Selected deal preview -->
            <div v-if="selectedDeal" class="p-4 bg-violet-50 border border-violet-200 rounded-xl mb-5 flex items-center justify-between">
              <div>
                <div class="text-sm font-medium text-violet-800">{{ selectedDeal.TITLE }}</div>
                <div class="text-xs text-violet-600 mt-0.5">ID: {{ selectedDeal.ID }} · Сумма: {{ selectedDeal.OPPORTUNITY }} {{ selectedDeal.CURRENCY_ID }}</div>
              </div>
              <button class="text-violet-400 hover:text-violet-600" @click="selectedDeal = null">&times;</button>
            </div>
            
            <div class="flex items-center gap-3">
              <button
                class="px-5 py-2.5 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors"
                @click="closeBindModal"
              >
                Отмена
              </button>
              <button
                class="px-5 py-2.5 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="!selectedDeal"
                @click="bindPaymentToDeal"
              >
                Привязать
              </button>
            </div>
          </div>
        </div>

        <!-- Bind to invoice modal -->
        <div v-if="showBindInvoiceModal && bindingInvoicePayment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="closeBindInvoiceModal">
          <div class="bg-white rounded-2xl shadow-xl w-[480px] p-6">
            <div class="flex items-center justify-between mb-5">
              <h3 class="text-lg font-bold text-gray-900">Привязка к счёту</h3>
              <button class="text-gray-400 hover:text-gray-600 text-xl" @click="closeBindInvoiceModal">&times;</button>
            </div>
            
            <!-- Payment info -->
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl mb-5">
              <div>
                <div class="text-sm text-gray-700 font-medium truncate max-w-[280px]">{{ bindingInvoicePayment.counterparty_name }}</div>
                <div class="text-xs text-gray-400 mt-0.5">{{ bindingInvoicePayment.purpose?.slice(0, 50) }}{{ bindingInvoicePayment.purpose?.length > 50 ? '...' : '' }}</div>
              </div>
              <div class="text-lg font-semibold text-gray-900">{{ formatCurrency(bindingInvoicePayment.amount) }}</div>
            </div>
            
            <!-- Invoice search -->
            <div class="mb-5">
              <label class="block text-sm text-gray-700 mb-2">Поиск счёта</label>
              <div class="relative">
                <input
                  v-model="invoiceSearchQuery"
                  type="text"
                  placeholder="Введите название или номер счёта..."
                  class="w-full border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"
                  @input="searchInvoices"
                />
                <svg v-if="isSearchingInvoices" class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                <svg v-else class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
              </div>
              
              <!-- Search results dropdown -->
              <div v-if="invoiceSearchResults.length > 0 && !selectedInvoice" class="mt-2 border border-gray-200 rounded-xl overflow-hidden max-h-48 overflow-y-auto">
                <button
                  v-for="invoice in invoiceSearchResults"
                  :key="invoice.id"
                  class="w-full text-left px-4 py-3 hover:bg-gray-50 border-b border-gray-100 last:border-b-0 transition-colors"
                  @click="selectedInvoice = invoice"
                >
                  <div class="text-sm font-medium text-gray-800">{{ invoice.title }}</div>
                  <div class="text-xs text-gray-500">ID: {{ invoice.id }} · {{ invoice.opportunity }} {{ invoice.currencyId }}</div>
                </button>
              </div>
            </div>
            
            <!-- Selected invoice preview -->
            <div v-if="selectedInvoice" class="p-4 bg-blue-50 border border-blue-200 rounded-xl mb-5 flex items-center justify-between">
              <div>
                <div class="text-sm font-medium text-blue-800">{{ selectedInvoice.title }}</div>
                <div class="text-xs text-blue-600 mt-0.5">ID: {{ selectedInvoice.id }} · Сумма: {{ selectedInvoice.opportunity }} {{ selectedInvoice.currencyId }}</div>
              </div>
              <button class="text-blue-400 hover:text-blue-600" @click="selectedInvoice = null">&times;</button>
            </div>
            
            <div class="flex items-center gap-3">
              <button
                class="px-5 py-2.5 border border-gray-200 text-gray-700 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors"
                @click="closeBindInvoiceModal"
              >
                Отмена
              </button>
              <button
                class="px-5 py-2.5 bg-blue-500 hover:bg-blue-600 text-white text-sm font-medium rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="!selectedInvoice"
                @click="bindPaymentToInvoice"
              >
                Привязать
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ БАНКОВСКИЕ СЧЕТА ============ -->
      <div v-else-if="activeTab === 'accounts'">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-lg font-bold text-gray-900">Банковские счета</h2>
          <div class="flex items-center gap-2">
            <button
              class="px-4 py-2 border border-gray-200 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-50 transition-colors"
              :disabled="isLoadingData"
              @click="loadBankData"
            >
              Обновить
            </button>
            <button
              class="px-4 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition-colors"
              @click="showAddAccountModal = true"
            >
              Добавить счёт
            </button>
          </div>
        </div>

        <div v-if="isLoadingData" class="flex items-center justify-center py-12">
          <div class="animate-spin w-6 h-6 border-3 border-violet-500 border-t-transparent rounded-full" />
        </div>

        <!-- Таблица счетов -->
        <div v-else-if="tochkaStore.accounts.length > 0">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-200 text-left text-xs text-gray-500 uppercase tracking-wide">
                <th class="py-2 pr-4">#</th>
                <th class="py-2 pr-4">Название организации</th>
                <th class="py-2 pr-4">Номер счёта</th>
                <th class="py-2 pr-4">БИК</th>
                <th class="py-2 pr-4">Банк</th>
                <th class="py-2 pr-4">Префикс</th>
                <th class="py-2 pr-4">Суффикс</th>
                <th class="py-2 pr-4">Статус</th>
                <th class="py-2 text-right">Действия</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(account, idx) in tochkaStore.accounts"
                :key="account.accountId"
                class="border-b border-gray-100 hover:bg-gray-50 transition-colors"
              >
                <td class="py-3 pr-4 text-gray-400">{{ idx + 1 }}</td>
                <td class="py-3 pr-4 text-gray-800 max-w-[12rem] truncate" :title="getAccountOrganizationName(account)">
                  {{ getAccountOrganizationName(account) }}
                </td>
                <td class="py-3 pr-4 font-mono text-gray-800">{{ parseTochkaAccountId(account.accountId).accountNumber || '—' }}</td>
                <td class="py-3 pr-4 font-mono text-gray-600">{{ parseTochkaAccountId(account.accountId).bik || '—' }}</td>
                <td class="py-3 pr-4 text-gray-600">Точка</td>
                <td class="py-3 pr-4 text-gray-600">{{ getAccountPrefixDisplay(account.accountId) }}</td>
                <td class="py-3 pr-4 text-gray-600">{{ getAccountSuffixDisplay(account.accountId) }}</td>
                <td class="py-3 pr-4">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500" />
                    Активен
                  </span>
                </td>
                <td class="py-3 text-right">
                  <div class="relative inline-block">
                    <button
                      class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors text-gray-400 hover:text-gray-600"
                      @click.stop="toggleAccountMenu(account.accountId)"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" /></svg>
                    </button>
                    <!-- Dropdown menu -->
                    <div
                      v-if="accountMenuOpen === account.accountId"
                      class="absolute right-0 top-full mt-1 w-48 bg-white border border-gray-200 rounded-xl shadow-lg z-10 py-1"
                    >
                      <button class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2" @click="syncSingleAccount(account.accountId)">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        Синхронизировать
                      </button>
                      <button class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2" @click="checkSingleAccount(account.accountId)">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Проверить
                      </button>
                      <button class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2" @click="openEditAccount(account)">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        Настройки
                      </button>
                      <hr class="my-1 border-gray-100" />
                      <button class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center gap-2" @click="deleteAccount(account.accountId)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                        Удалить счёт
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-else class="text-center text-gray-400 py-12">Банковские счета не найдены</div>

        <!-- Modal: Добавить счёт -->
        <div v-if="showAddAccountModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="showAddAccountModal = false">
          <div class="bg-white rounded-2xl shadow-xl w-[440px] p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-bold text-gray-900">Выбор счетов</h3>
              <button class="text-gray-400 hover:text-gray-600" @click="showAddAccountModal = false">&times;</button>
            </div>
            <p class="text-gray-400 text-sm text-center py-8">Нет доступных счетов</p>
            <hr class="my-4 border-gray-100" />
            <div class="flex items-center gap-3">
              <button class="px-4 py-2 border border-violet-300 text-violet-600 text-sm font-medium rounded-xl hover:bg-violet-50 transition-colors" @click="showAddAccountModal = false">Отмена</button>
              <button class="px-4 py-2 bg-violet-400 text-white text-sm font-medium rounded-xl opacity-50 cursor-not-allowed">Добавить выбранные</button>
            </div>
          </div>
        </div>

        <!-- Modal: Редактирование счёта -->
        <div v-if="showEditAccountModal && editingAccount" class="fixed inset-0 z-50 flex items-center justify-center bg-black/30" @click.self="closeEditAccount">
          <div class="bg-white rounded-2xl shadow-xl w-[440px] p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-lg font-bold text-gray-900">Редактирование счёта</h3>
              <button class="text-gray-400 hover:text-gray-600 text-xl" @click="closeEditAccount">&times;</button>
            </div>
            <div class="space-y-4">
              <div>
                <label class="block text-sm text-gray-700 mb-1">Название организации <span class="text-red-500">*</span></label>
                <input v-model="editingAccount.organizationName" type="text" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400" />
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Номер счёта без БИК</label>
                <input
                  :value="parseTochkaAccountId(editingAccount.accountId).accountNumber || '—'"
                  type="text"
                  readonly
                  class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm font-mono bg-gray-50 text-gray-600 cursor-default"
                />
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Префикс номера счёта</label>
                <input v-model="editingAccount.prefix" type="text" placeholder="И, СЧ-, или пусто" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400" />
                <p class="text-xs text-gray-400 mt-1">Префикс перед номером счёта в сделке, например «СЧ-» для «СЧ-315».</p>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Суффикс номера счёта</label>
                <input v-model="editingAccount.suffix" type="text" placeholder="-А, /2025, или пусто" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400" />
                <p class="text-xs text-gray-400 mt-1">Суффикс после номера счёта в сделке, например «-А» для «315-А».</p>
              </div>
              <div>
                <label class="block text-sm text-gray-700 mb-1">Статус</label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="editingAccount.active" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400" />
                  <span class="text-sm text-gray-600">Счёт активен</span>
                </label>
              </div>
            </div>
            <hr class="my-5 border-gray-100" />
            <div class="flex items-center gap-3">
              <button class="px-4 py-2 border border-violet-300 text-violet-600 text-sm font-medium rounded-xl hover:bg-violet-50 transition-colors" @click="closeEditAccount">Отмена</button>
              <button class="px-4 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-xl transition-colors" @click="saveEditAccount">Сохранить</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ НАСТРОЙКИ ============ -->
      <div v-else-if="activeTab === 'settings'" class="w-full min-w-0 max-w-full">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-2 mb-4 w-full min-w-0">
          <h2 class="text-lg font-bold text-gray-900 shrink-0">Настройки интеграции</h2>
          <button
            class="px-5 py-2 bg-violet-500 hover:bg-violet-600 text-white text-sm font-medium rounded-lg transition-colors whitespace-nowrap shrink-0 self-start sm:self-auto disabled:opacity-50"
            :disabled="isSavingSettings"
            @click="saveSettings"
          >
            <span v-if="isSavingSettings">Сохранение…</span>
            <span v-else>Сохранить</span>
          </button>
        </div>

        <!-- Sub-tabs -->
        <div class="flex flex-wrap gap-1 mb-6 bg-gray-100 rounded-lg p-1 w-full min-w-0">
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[8rem] sm:flex-none text-center"
            :class="settingsSubTab === 'matching' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'matching'"
          >
            Сопоставление
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[8rem] sm:flex-none text-center"
            :class="settingsSubTab === 'automation' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'automation'"
          >
            Автоматизация
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[8rem] sm:flex-none text-center"
            :class="settingsSubTab === 'fields' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'fields'"
          >
            Поля
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[9rem] sm:flex-none text-center"
            :class="settingsSubTab === 'paymentCrm' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'paymentCrm'"
          >
            {{ t('dashboard.settings_subtab_payment_crm') }}
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[8rem] sm:flex-none text-center"
            :class="settingsSubTab === 'sync' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'sync'"
          >
            Синхронизация
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm font-medium rounded-md transition-colors whitespace-nowrap flex-1 min-w-[8rem] sm:flex-none text-center"
            :class="settingsSubTab === 'notifications' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
            @click="settingsSubTab = 'notifications'; loadAvailableChats()"
          >
            Уведомления
          </button>
        </div>

        <!-- Сопоставление -->
        <div v-if="settingsSubTab === 'matching'" class="space-y-3 w-full min-w-0">
          <!-- Сопоставление платежей со сделками -->
          <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5 space-y-5">
            <div class="flex flex-col w-full min-w-0">
              <h3 class="text-sm font-semibold text-gray-900">Сопоставлять платежи со сделками</h3>
              <p class="w-full min-w-0 text-xs text-gray-500 mt-1 leading-relaxed">
                Поиск сделки по данным из выписки Точки и автоматическая привязка платежа. Настройки автоматизации — на вкладке «Автоматизация».
              </p>
            </div>

            <!-- Поиск сделки -->
            <div class="space-y-3">
              <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Поиск сделки</h4>
              <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="dealMatching.enabled" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                <span class="min-w-0">
                  <span class="text-sm text-gray-900 font-medium">Автоматический поиск сделок</span>
                  <span class="block text-xs text-gray-500 mt-0.5">Платежи из банка автоматически привязываются к сделкам в CRM</span>
                </span>
              </label>

              <div v-if="dealMatching.enabled" class="space-y-4 pt-1 border-t border-gray-200/90">
                <div v-if="isLoadingDealUf" class="text-sm text-gray-400">{{ t('dashboard.loading_uf') }}</div>

                <label class="flex items-start gap-3 cursor-pointer">
                  <input v-model="dealMatching.autoFindEach" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                  <span class="text-sm text-gray-800 font-medium">Автоматически находить сделку для каждого платежа</span>
                </label>

                <div class="space-y-3">
                  <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="dealMatching.byInvoiceInPurpose" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                    <span class="text-sm text-gray-700 flex flex-wrap items-center gap-2">
                      Искать по номеру счёта в назначении платежа
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800">Рекомендуется</span>
                    </span>
                  </label>
                  <div v-if="dealMatching.byInvoiceInPurpose" class="pl-0 sm:pl-7 space-y-1.5">
                    <span class="text-xs text-gray-500">Поле сделки</span>
                    <div class="flex flex-wrap items-start gap-2">
                      <template v-if="dealMatching.invoiceUf">
                        <span class="inline-flex items-center gap-1.5 pl-2 pr-1 py-1.5 rounded-md text-sm bg-emerald-50 border border-emerald-200 text-gray-800 max-w-full min-w-0">
                          <span class="text-emerald-600 shrink-0" aria-hidden="true">🔗</span>
                          <span class="truncate min-w-0">{{ displayDealUfLabel(dealMatching.invoiceUf) }}</span>
                          <button type="button" class="shrink-0 text-gray-400 hover:text-red-600 px-1 leading-none" aria-label="Убрать поле" @click="clearMatchInvoiceField">×</button>
                        </span>
                      </template>
                      <template v-else>
                        <div class="relative flex-1 min-w-[200px] max-w-[340px]">
                          <input
                            v-model="ufMatchInvoiceSearch"
                            type="text"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                            placeholder="Выберите поле сделки"
                            @focus="showMatchInvoiceDropdown = true"
                            @blur="closeMatchInvoiceDropdown"
                          />
                          <div
                            v-if="showMatchInvoiceDropdown && filteredMatchInvoiceFields.length > 0"
                            class="absolute z-20 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                          >
                            <button
                              v-for="f in filteredMatchInvoiceFields"
                              :key="f.code"
                              type="button"
                              class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                              @mousedown.prevent="selectMatchInvoiceField(f)"
                            >
                              <div class="font-medium text-gray-800">{{ f.label }}</div>
                              <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                            </button>
                          </div>
                        </div>
                        <button
                          type="button"
                          class="shrink-0 px-3 py-2 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                          :disabled="isCreatingMatchInvoiceUf"
                          @click="createDealMatchInvoiceUf"
                        >
                          <span v-if="isCreatingMatchInvoiceUf">…</span>
                          <span v-else>+ Создать</span>
                        </button>
                      </template>
                    </div>
                  </div>
                </div>

            <label class="flex items-start gap-3 cursor-pointer">
              <input v-model="dealMatching.byInn" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
              <span class="text-sm text-gray-600">Сопоставлять по ИНН контрагента</span>
            </label>
            <div v-if="dealMatching.byInn" class="pl-0 sm:pl-7 space-y-1.5">
              <span class="text-xs text-gray-500">Поле</span>
              <div class="flex flex-wrap items-start gap-2">
                <template v-if="dealMatching.innUf">
                  <span class="inline-flex items-center gap-1.5 pl-2 pr-1 py-1.5 rounded-md text-sm bg-emerald-50 border border-emerald-200 text-gray-800 max-w-full min-w-0">
                    <span class="text-emerald-600 shrink-0" aria-hidden="true">🔗</span>
                    <span class="truncate min-w-0">{{ displayDealUfLabel(dealMatching.innUf) }}</span>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-red-600 px-1 leading-none" aria-label="Убрать поле" @click="clearMatchInnField">×</button>
                  </span>
                </template>
                <template v-else>
                  <div class="relative flex-1 min-w-[200px] max-w-[340px]">
                    <input
                      v-model="ufMatchInnSearch"
                      type="text"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                      placeholder="Выберите поле сделки"
                      @focus="showMatchInnDropdown = true"
                      @blur="closeMatchInnDropdown"
                    />
                    <div
                      v-if="showMatchInnDropdown && filteredMatchInnFields.length > 0"
                      class="absolute z-20 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                    >
                      <button
                        v-for="f in filteredMatchInnFields"
                        :key="f.code"
                        type="button"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                        @mousedown.prevent="selectMatchInnField(f)"
                      >
                        <div class="font-medium text-gray-800">{{ f.label }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 px-3 py-2 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                    :disabled="isCreatingMatchInnUf"
                    @click="createDealMatchInnUf"
                  >
                    <span v-if="isCreatingMatchInnUf">…</span>
                    <span v-else>+ Создать</span>
                  </button>
                </template>
              </div>
            </div>

            <label class="flex items-start gap-3 cursor-pointer">
              <input v-model="dealMatching.byName" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
              <span class="text-sm text-gray-600">Сопоставлять по названию контрагента</span>
            </label>
            <div v-if="dealMatching.byName" class="pl-0 sm:pl-7 space-y-1.5">
              <span class="text-xs text-gray-500">Поле</span>
              <div class="flex flex-wrap items-start gap-2">
                <template v-if="dealMatching.nameUf">
                  <span class="inline-flex items-center gap-1.5 pl-2 pr-1 py-1.5 rounded-md text-sm bg-emerald-50 border border-emerald-200 text-gray-800 max-w-full min-w-0">
                    <span class="text-emerald-600 shrink-0" aria-hidden="true">🔗</span>
                    <span class="truncate min-w-0">{{ displayDealUfLabel(dealMatching.nameUf) }}</span>
                    <button type="button" class="shrink-0 text-gray-400 hover:text-red-600 px-1 leading-none" aria-label="Убрать поле" @click="clearMatchNameField">×</button>
                  </span>
                </template>
                <template v-else>
                  <div class="relative flex-1 min-w-[200px] max-w-[340px]">
                    <input
                      v-model="ufMatchNameSearch"
                      type="text"
                      class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                      placeholder="Выберите поле сделки"
                      @focus="showMatchNameDropdown = true"
                      @blur="closeMatchNameDropdown"
                    />
                    <div
                      v-if="showMatchNameDropdown && filteredMatchNameFields.length > 0"
                      class="absolute z-20 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                    >
                      <button
                        v-for="f in filteredMatchNameFields"
                        :key="f.code"
                        type="button"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                        @mousedown.prevent="selectMatchNameField(f)"
                      >
                        <div class="font-medium text-gray-800">{{ f.label }}</div>
                        <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 px-3 py-2 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                    :disabled="isCreatingMatchNameUf"
                    @click="createDealMatchNameUf"
                  >
                    <span v-if="isCreatingMatchNameUf">…</span>
                    <span v-else>+ Создать</span>
                  </button>
                </template>
              </div>
            </div>

                <label class="flex items-start gap-3 cursor-pointer">
                  <input v-model="dealMatching.byAmount" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                  <span class="min-w-0">
                    <span class="text-sm text-gray-700 flex flex-wrap items-center gap-2">
                      Проверять совпадение суммы
                      <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800">Рекомендуется</span>
                    </span>
                    <span class="block text-xs text-gray-500 mt-0.5">Сумма платежа и сумма в сделке должны совпасть, иначе совпадение не засчитается</span>
                  </span>
                </label>

                <div class="space-y-2">
                  <label class="flex items-start gap-3 cursor-pointer">
                    <input v-model="dealMatching.byPhonePayer" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                    <span class="min-w-0">
                      <span class="text-sm text-gray-700 flex flex-wrap items-center gap-2">
                        Искать по телефону плательщика
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800">СБП</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide bg-emerald-100 text-emerald-800">Рекомендуется</span>
                      </span>
                      <span class="block text-xs text-gray-500 mt-0.5">Для переводов по СБП в назначении часто указывается только телефон — это основной способ привязки таких платежей</span>
                    </span>
                  </label>
                  <div v-if="dealMatching.byPhonePayer" class="pl-0 sm:pl-7 space-y-1.5">
                    <span class="text-xs text-gray-500">Поле сделки (телефон)</span>
                    <div class="flex flex-wrap items-start gap-2">
                      <template v-if="dealMatching.phoneUf">
                        <span class="inline-flex items-center gap-1.5 pl-2 pr-1 py-1.5 rounded-md text-sm bg-emerald-50 border border-emerald-200 text-gray-800 max-w-full min-w-0">
                          <span class="text-emerald-600 shrink-0" aria-hidden="true">🔗</span>
                          <span class="truncate min-w-0">{{ displayDealUfLabel(dealMatching.phoneUf) }}</span>
                          <button type="button" class="shrink-0 text-gray-400 hover:text-red-600 px-1 leading-none" aria-label="Убрать поле" @click="clearMatchPhoneField">×</button>
                        </span>
                      </template>
                      <template v-else>
                        <div class="relative flex-1 min-w-[200px] max-w-[340px]">
                          <input
                            v-model="ufMatchPhoneSearch"
                            type="text"
                            class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                            placeholder="Выберите поле сделки"
                            @focus="showMatchPhoneDropdown = true"
                            @blur="closeMatchPhoneDropdown"
                          />
                          <div
                            v-if="showMatchPhoneDropdown && filteredMatchPhoneFields.length > 0"
                            class="absolute z-20 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                          >
                            <button
                              v-for="f in filteredMatchPhoneFields"
                              :key="f.code"
                              type="button"
                              class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                              @mousedown.prevent="selectMatchPhoneField(f)"
                            >
                              <div class="font-medium text-gray-800">{{ f.label }}</div>
                              <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                            </button>
                          </div>
                        </div>
                        <button
                          type="button"
                          class="shrink-0 px-3 py-2 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                          :disabled="isCreatingMatchPhoneUf"
                          @click="createDealMatchPhoneUf"
                        >
                          <span v-if="isCreatingMatchPhoneUf">…</span>
                          <span v-else>+ Создать</span>
                        </button>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Автоматическое применение -->
            <div v-if="dealMatching.enabled" class="space-y-3 border-t border-gray-200 pt-4">
              <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Автоматическое применение</h4>
              <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="dealMatching.autoWrite" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                <span class="min-w-0">
                  <span class="text-sm text-gray-900 font-medium">Автоматически привязывать платежи к сделкам</span>
                  <span class="block text-xs text-gray-500 mt-0.5">Без ручного подтверждения — данные платежа сразу попадают в найденную сделку</span>
                </span>
              </label>

              <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="dealMatching.partial" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                <span class="text-sm text-gray-600">Обрабатывать частичные платежи и переплаты</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Автоматизация -->
        <div v-else-if="settingsSubTab === 'automation'" class="space-y-4 w-full min-w-0">
          <!-- Оплата по счёту -->
          <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 sm:p-5 space-y-4">
            <div class="w-full min-w-0">
              <h3 class="text-sm font-semibold text-gray-900">Оплата по счёту</h3>
              <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                Автоматическое обновление статуса счёта в CRM и правила поиска счёта при поступлении платежа из банка.
              </p>
            </div>

            <div class="space-y-3">
              <label class="flex items-center gap-3 cursor-pointer w-full min-w-0">
                <input v-model="autoUpdateInvoiceStatus" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 shrink-0" />
                <span class="text-sm text-gray-800 font-medium">Автоматически обновлять статус счёта</span>
              </label>

              <div v-if="autoUpdateInvoiceStatus" class="flex flex-col gap-3 w-full max-w-2xl">
                <div class="flex items-center gap-4 w-full min-w-0">
                  <span class="text-sm text-gray-700 w-[13rem] shrink-0">Статус при полной оплате</span>
                  <div class="flex-1 min-w-[10rem] max-w-xs">
                    <select v-model="invoiceStatusPaid" :class="settingsSelectClass">
                      <option v-if="invoiceStatuses.length === 0" value="" disabled>Загрузка статусов…</option>
                      <option v-for="s in invoiceStatuses" :key="`paid-status-${s.value}`" :value="s.value">{{ s.label }}</option>
                    </select>
                  </div>
                </div>
                <div class="flex items-center gap-4 w-full min-w-0">
                  <span class="text-sm text-gray-700 w-[13rem] shrink-0">Статус при частичной оплате</span>
                  <div class="flex-1 min-w-[10rem] max-w-xs">
                    <select v-model="invoiceStatusPartialPaid" :class="settingsSelectClass">
                      <option v-if="invoiceStatuses.length === 0" value="" disabled>Загрузка статусов…</option>
                      <option v-for="s in invoiceStatuses" :key="`partial-status-${s.value}`" :value="s.value">{{ s.label }}</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-2 border-t border-gray-200 pt-4">
              <p class="text-sm text-gray-800 font-medium">Поиск счёта по платежу</p>
              <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="invoiceMatchByAmount" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                <span class="text-sm text-gray-600">Сопоставлять по сумме счёта</span>
              </label>
              <label class="flex items-start gap-3 cursor-pointer">
                <input v-model="invoiceMatchByInn" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                <span class="text-sm text-gray-600">Фильтровать по ИНН из реквизитов компании</span>
              </label>
            </div>
          </div>

          <!-- Перемещение сделки -->
          <div class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 sm:p-5 space-y-4">
            <div class="w-full min-w-0">
              <h3 class="text-sm font-semibold text-gray-900">Перемещение сделки</h3>
              <p class="text-xs text-gray-500 mt-1 leading-relaxed">
                После привязки платежа к сделке система может автоматически перевести её на нужную стадию —
                например, на «Оплачено» при полной оплате или на промежуточную стадию при частичной.
                Сделка остаётся в своей воронке, меняется только этап.
              </p>
            </div>

            <!-- Общие правила -->
            <div v-if="!dealMatching.perCategoryEnabled" class="rounded-lg border border-gray-200 bg-white overflow-hidden">
              <table class="w-full text-sm">
                <thead>
                  <tr class="border-b border-gray-100 bg-gray-50/80 text-left text-xs text-gray-500 uppercase tracking-wide">
                    <th class="py-2.5 px-3 font-medium w-[28%]">Событие</th>
                    <th class="py-2.5 px-3 font-medium w-[36%]">Воронка</th>
                    <th class="py-2.5 px-3 font-medium">Стадия после оплаты</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="border-b border-gray-100">
                    <td class="py-3 px-3 text-gray-800 font-medium align-top">Полная оплата</td>
                    <td class="py-3 px-3 align-top">
                      <div class="relative min-w-[10rem]">
                        <select
                          v-model.number="dealMatching.stagePaidCategoryId"
                          :class="settingsSelectClass"
                          @change="onGlobalPaidFunnelChange"
                        >
                          <option v-for="cat in funnelSelectOptions" :key="`paid-cat-${cat.id}`" :value="cat.id">{{ cat.name }}</option>
                        </select>
                      </div>
                    </td>
                    <td class="py-3 px-3 align-top">
                      <div class="relative min-w-[10rem]" :class="{ 'opacity-50 pointer-events-none': !isFunnelSelected(dealMatching.stagePaidCategoryId) }">
                        <select
                          v-model="dealMatching.stagePaidId"
                          :class="settingsSelectClass"
                          :disabled="!isFunnelSelected(dealMatching.stagePaidCategoryId)"
                        >
                          <option v-if="!isFunnelSelected(dealMatching.stagePaidCategoryId)" value="">—</option>
                          <option
                            v-for="opt in stagesForSelectedFunnel(dealMatching.stagePaidCategoryId)"
                            :key="`paid-stage-${opt.value}`"
                            :value="opt.value"
                          >
                            {{ opt.label }}
                          </option>
                        </select>
                      </div>
                    </td>
                  </tr>
                  <tr>
                    <td class="py-3 px-3 text-gray-800 font-medium align-top">
                      <span class="block">Частичная оплата</span>
                      <label class="mt-2 flex items-center gap-2 cursor-pointer font-normal">
                        <input v-model="dealMatching.moveOnPartial" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 shrink-0" />
                        <span class="text-xs text-gray-500">Перемещать при частичной оплате</span>
                      </label>
                    </td>
                    <td class="py-3 px-3 align-top">
                      <div class="relative min-w-[10rem]" :class="{ 'opacity-50 pointer-events-none': !dealMatching.moveOnPartial }">
                        <select
                          v-model.number="dealMatching.stagePartialCategoryId"
                          :class="settingsSelectClass"
                          :disabled="!dealMatching.moveOnPartial"
                          @change="onGlobalPartialFunnelChange"
                        >
                          <option v-for="cat in funnelSelectOptions" :key="`partial-cat-${cat.id}`" :value="cat.id">{{ cat.name }}</option>
                        </select>
                      </div>
                    </td>
                    <td class="py-3 px-3 align-top">
                      <div
                        class="relative min-w-[10rem]"
                        :class="{ 'opacity-50 pointer-events-none': !dealMatching.moveOnPartial || !isFunnelSelected(dealMatching.stagePartialCategoryId) }"
                      >
                        <select
                          v-model="dealMatching.stagePartialId"
                          :class="settingsSelectClass"
                          :disabled="!dealMatching.moveOnPartial || !isFunnelSelected(dealMatching.stagePartialCategoryId)"
                        >
                          <option v-if="!isFunnelSelected(dealMatching.stagePartialCategoryId)" value="">—</option>
                          <option
                            v-for="opt in stagesForSelectedFunnel(dealMatching.stagePartialCategoryId)"
                            :key="`partial-stage-${opt.value}`"
                            :value="opt.value"
                          >
                            {{ opt.label }}
                          </option>
                        </select>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <label v-if="!dealMatching.perCategoryEnabled" class="flex items-start gap-3 cursor-pointer">
              <input v-model="dealMatching.allowBackwardMove" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
              <span class="text-sm text-gray-600">Разрешить перемещение на более раннюю стадию</span>
            </label>

            <label class="flex items-start gap-3 cursor-pointer">
              <input v-model="dealMatching.perCategoryEnabled" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
              <span class="min-w-0">
                <span class="text-sm text-gray-900 font-medium">Настроить отдельно по каждой воронке</span>
                <span class="block text-xs text-gray-500 mt-0.5">Разные стадии для основной воронки, дополнительных направлений и т.д.</span>
              </span>
            </label>

            <!-- Правила по воронкам -->
            <div v-if="dealMatching.perCategoryEnabled" class="space-y-3">
              <div
                v-for="(rule, ruleIndex) in dealMatching.categoryRules"
                :key="`${rule.categoryId}-${ruleIndex}`"
                class="rounded-lg border border-gray-200 bg-white p-4 space-y-3"
              >
                <div class="flex items-center gap-2">
                  <div class="relative flex-1 min-w-0">
                    <select
                      v-model.number="rule.categoryId"
                      :class="settingsSelectClass"
                      @change="onRuleFunnelChange(rule)"
                    >
                      <option
                        v-for="cat in [{ id: rule.categoryId, name: categoryLabel(rule.categoryId) }, ...availableCategoriesForRule(rule.categoryId)]"
                        :key="`rule-cat-${ruleIndex}-${cat.id}`"
                        :value="cat.id"
                      >
                        {{ cat.name }}
                      </option>
                    </select>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors"
                    aria-label="Удалить правило воронки"
                    @click="removeCategoryStageRule(ruleIndex)"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                  </button>
                </div>

                <table class="w-full text-sm">
                  <thead>
                    <tr class="border-b border-gray-100 text-left text-xs text-gray-500 uppercase tracking-wide">
                      <th class="py-2 pr-3 font-medium w-[36%]">Событие</th>
                      <th class="py-2 font-medium">Стадия после оплаты</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr class="border-b border-gray-100">
                      <td class="py-2.5 pr-3 text-gray-800">Полная оплата</td>
                      <td class="py-2.5">
                        <div class="relative">
                          <select v-model="rule.stagePaidId" :class="settingsSelectClass">
                            <option v-for="opt in stagesForCategory(rule.categoryId)" :key="`rule-paid-${ruleIndex}-${opt.value}`" :value="opt.value">{{ opt.label }}</option>
                          </select>
                        </div>
                      </td>
                    </tr>
                    <tr>
                      <td class="py-2.5 pr-3 text-gray-800">
                        <span class="block">Частичная оплата</span>
                        <label class="mt-1.5 flex items-center gap-2 cursor-pointer">
                          <input v-model="rule.moveOnPartial" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 shrink-0" />
                          <span class="text-xs text-gray-500">Перемещать</span>
                        </label>
                      </td>
                      <td class="py-2.5">
                        <div class="relative" :class="{ 'opacity-50 pointer-events-none': !rule.moveOnPartial }">
                          <select v-model="rule.stagePartialId" :class="settingsSelectClass">
                            <option v-for="opt in stagesForCategory(rule.categoryId)" :key="`rule-partial-${ruleIndex}-${opt.value}`" :value="opt.value">{{ opt.label }}</option>
                          </select>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>

                <label class="flex items-start gap-3 cursor-pointer">
                  <input v-model="rule.allowBackwardMove" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0" />
                  <span class="text-xs text-gray-600">Разрешить перемещение на более раннюю стадию</span>
                </label>
              </div>

              <button
                v-if="canAddCategoryStageRule && dealMatching.categoryRules.length > 0"
                type="button"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-700 transition-colors"
                @click="addCategoryStageRule"
              >
                <span class="text-lg leading-none">+</span>
                Добавить новую воронку
              </button>

              <p v-else-if="dealCategories.length === 0" class="text-xs text-gray-400">
                Список воронок загружается…
              </p>

              <button
                v-else-if="dealMatching.categoryRules.length === 0"
                type="button"
                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 transition-colors"
                @click="addCategoryStageRule"
              >
                <span class="text-lg leading-none">+</span>
                Добавить новую воронку
              </button>
            </div>

            <p class="text-xs text-gray-400 leading-relaxed">
              Автоперемещение работает только внутри воронки сделки — в другие воронки сделки не переносятся.
              Выберите «Не перемещать», если стадию менять не нужно.
            </p>
          </div>
        </div>

        <!-- Поля -->
        <div v-else-if="settingsSubTab === 'fields'" class="space-y-3 w-full min-w-0">
          <p class="text-sm text-gray-500 mb-4">Пользовательские поля в сделках CRM, созданные приложением:</p>
          <div class="space-y-2">
            <div v-for="field in [
              'Номер счёта [Точка Банк. Интеграция]',
              'Сумма оплаты [Точка Банк. Интеграция]',
              'Дата оплаты [Точка Банк. Интеграция]',
              'Назначение платежа [Точка Банк. Интеграция]',
              'ИНН контрагента [Точка Банк. Интеграция]',
              'Контрагент [Точка Банк. Интеграция]',
              'Телефон плательщика [Точка Банк. Интеграция]',
            ]" :key="field" class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg">
              <span class="text-green-600 text-xs">🔗</span>
              <span class="text-sm text-gray-700 whitespace-nowrap">{{ field }}</span>
            </div>
          </div>
        </div>

        <!-- Эквайринг — подключение и поля CRM -->
        <div v-else-if="settingsSubTab === 'paymentCrm'" class="w-full min-w-0 space-y-6">
          <div class="rounded-2xl border border-blue-200 bg-blue-50/70 p-5 sm:p-6 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <h3 class="text-base font-semibold text-gray-900">
                {{ t('dashboard.acquiring_setup_title') }}
              </h3>
              <button
                type="button"
                class="shrink-0 px-3 py-1.5 text-xs font-medium text-violet-700 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                :disabled="acquiringStatusLoading || !tochkaStore.status.connected"
                @click="loadAcquiringStatus"
              >
                {{ acquiringStatusLoading ? t('dashboard.acquiring_status_loading') : t('dashboard.acquiring_refresh') }}
              </button>
            </div>

            <div
              v-if="!tochkaStore.status.connected"
              class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
              {{ t('dashboard.acquiring_not_connected_tochka') }}
            </div>
            <div
              v-else-if="acquiringStatusLoading"
              class="text-sm text-gray-500"
            >
              {{ t('dashboard.acquiring_status_loading') }}
            </div>
            <div
              v-else-if="acquiringIsActive"
              class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900 space-y-1"
            >
              <p>{{ t('dashboard.acquiring_status_connected') }}</p>
              <ul class="text-xs text-green-800 list-disc pl-4 space-y-0.5">
                <li v-for="r in acquiringActiveRetailers" :key="r.merchantId || r.name">
                  {{ r.name }}<span v-if="r.merchantId" class="font-mono text-green-700"> · Merchant ID: {{ r.merchantId }}</span>
                </li>
              </ul>
            </div>
            <div
              v-else
              class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
            >
              {{ t('dashboard.acquiring_status_not_connected') }}
            </div>

            <p class="text-sm text-blue-950 leading-relaxed">
              {{ t('placement.crm_entity_payments.create_link_acquiring_required_intro') }}
            </p>
            <ol class="list-decimal list-inside space-y-2 text-sm text-blue-900 leading-relaxed">
              <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step1') }}</li>
              <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step2') }}</li>
              <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step3') }}</li>
              <li>{{ t('dashboard.acquiring_setup_step4') }}</li>
            </ol>
            <p class="text-sm text-blue-900 leading-relaxed">
              {{ t('placement.crm_entity_payments.create_link_acquiring_required_merchant') }}
            </p>
            <p class="text-sm text-blue-900 leading-relaxed">
              {{ t('placement.crm_entity_payments.create_link_acquiring_required_support') }}
            </p>
            <p class="text-xs text-blue-800 leading-relaxed border-t border-blue-200 pt-3">
              {{ t('placement.crm_entity_payments.create_link_modes_tochka_help') }}
            </p>
          </div>

          <div>
            <h3 class="text-sm font-semibold text-gray-900 mb-1">{{ t('dashboard.acquiring_crm_fields_title') }}</h3>
            <p class="text-sm text-gray-500 leading-relaxed">
              {{ t('dashboard.payment_crm_intro') }}
            </p>
          </div>

          <div v-if="isLoadingDealUf" class="text-sm text-gray-400">{{ t('dashboard.loading_uf') }}</div>

          <div v-else class="space-y-6">
            <!-- Вид оплаты -->
            <div>
              <label class="block text-sm text-gray-800 font-medium mb-2">
                {{ t('placement.crm_entity_payments.settings_uf_kind') }}
              </label>
              <div class="flex flex-wrap items-start gap-3">
                <div class="relative" style="width: 300px;">
                  <input
                    v-model="ufKindSearch"
                    type="text"
                    class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                    :placeholder="t('dashboard.uf_search_placeholder')"
                    @focus="showKindDropdown = true"
                    @blur="closeKindDropdown"
                  />
                  <div
                    v-if="showKindDropdown && filteredKindFields.length > 0"
                    class="absolute z-10 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                  >
                    <button
                      v-for="f in filteredKindFields"
                      :key="f.code"
                      type="button"
                      class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                      @mousedown.prevent="selectKindField(f)"
                    >
                      <div class="font-medium text-gray-800">{{ f.label }}</div>
                      <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                    </button>
                  </div>
                </div>
                <button
                  type="button"
                  class="shrink-0 px-3 py-2.5 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                  :disabled="isCreatingKindUf"
                  @click="createPaymentKindUf"
                >
                  <span v-if="isCreatingKindUf">…</span>
                  <span v-else>{{ t('dashboard.create_uf') }}</span>
                </button>
              </div>
              <p v-if="dealPaymentKindUfCode" class="mt-2 text-xs text-green-600 font-mono">
                {{ t('dashboard.uf_selected') }}: {{ dealPaymentKindUfCode }}
              </p>
              <p class="mt-1 text-xs text-gray-400">{{ t('dashboard.payment_crm_kind_hint') }}</p>
            </div>

            <!-- Способ оплаты -->
            <div>
              <label class="block text-sm text-gray-800 font-medium mb-2">
                {{ t('placement.crm_entity_payments.settings_uf_channel') }}
              </label>
              <div class="flex flex-wrap items-start gap-3">
                <div class="relative" style="width: 300px;">
                  <input
                    v-model="ufChannelSearch"
                    type="text"
                    class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                    :placeholder="t('dashboard.uf_search_placeholder')"
                    @focus="showChannelDropdown = true"
                    @blur="closeChannelDropdown"
                  />
                  <div
                    v-if="showChannelDropdown && filteredChannelFields.length > 0"
                    class="absolute z-10 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg"
                  >
                    <button
                      v-for="f in filteredChannelFields"
                      :key="f.code"
                      type="button"
                      class="w-full text-left px-3 py-2 text-sm hover:bg-violet-50 border-b border-gray-100 last:border-b-0"
                      @mousedown.prevent="selectChannelField(f)"
                    >
                      <div class="font-medium text-gray-800">{{ f.label }}</div>
                      <div class="text-xs text-gray-400 font-mono">{{ f.code }}</div>
                    </button>
                  </div>
                </div>
                <button
                  type="button"
                  class="shrink-0 px-3 py-2.5 text-sm font-medium text-violet-600 border border-violet-300 rounded-lg hover:bg-violet-50 disabled:opacity-50"
                  :disabled="isCreatingChannelUf"
                  @click="createPaymentChannelUf"
                >
                  <span v-if="isCreatingChannelUf">…</span>
                  <span v-else>{{ t('dashboard.create_uf') }}</span>
                </button>
              </div>
              <p v-if="dealPaymentChannelUfCode" class="mt-2 text-xs text-green-600 font-mono">
                {{ t('dashboard.uf_selected') }}: {{ dealPaymentChannelUfCode }}
              </p>
              <p class="mt-1 text-xs text-gray-400">{{ t('dashboard.payment_crm_channel_hint') }}</p>
            </div>
          </div>
        </div>

        <!-- Синхронизация (вкладка после «Поля») -->
        <div v-else-if="settingsSubTab === 'sync'" class="w-full min-w-0 space-y-6">
          <div class="w-full min-w-0 rounded-2xl border border-violet-200 bg-violet-50/50 p-6 sm:p-8 space-y-5">
            <div class="w-full min-w-0">
              <h3 class="text-lg font-bold text-gray-900">Синхронизация</h3>
              <p class="text-sm text-gray-700 mt-3 leading-relaxed">
                При включённой настройке сервер по выбранному интервалу запрашивает у банка операции за последние 2 календарных дня
                и продолжает синхронизацию даже при закрытом приложении. Открытый дашборд дополнительно обновляет данные в интерфейсе.
                В шапке раздела «Платежи» индикатор рядом со временем означает:
              </p>
              <ul class="mt-3 text-sm text-gray-700 list-disc pl-5 space-y-2 marker:text-gray-400">
                <li><span class="text-amber-600 font-semibold">жёлтый</span> — идёт синхронизация</li>
                <li><span class="text-green-600 font-semibold">зелёный</span> — последняя синхронизация завершилась успешно</li>
                <li><span class="text-red-600 font-semibold">красный</span> — ошибка при обращении к банку</li>
              </ul>
              <p class="text-sm text-gray-700 mt-3 leading-relaxed">
                Время последней успешной синхронизации показывается в часовом поясе вашего браузера.
              </p>
            </div>
            <label class="flex items-start gap-3 cursor-pointer w-full min-w-0">
              <input v-model="syncPrefEnabled" type="checkbox" class="w-4 h-4 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-1 shrink-0" />
              <span class="text-sm text-gray-900 font-medium leading-snug min-w-0">Включить фоновую синхронизацию</span>
            </label>
            <div class="w-full min-w-0">
              <label
                for="sync-pref-minutes"
                class="block w-full min-w-0 text-sm font-medium text-gray-800 mb-2"
              >Интервал между запросами к банку (минуты, от 3 до 180)</label>
              <input
                id="sync-pref-minutes"
                v-model.number="syncPrefMinutes"
                type="number"
                min="3"
                max="180"
                class="block w-full sm:w-48 max-w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
              />
            </div>
          </div>
        </div>

        <!-- Уведомления -->
        <div v-else-if="settingsSubTab === 'notifications'" class="w-full min-w-0 space-y-5">
          <label class="flex items-start gap-3 cursor-pointer">
            <input
              v-model="notifyDealResponsible"
              type="checkbox"
              class="w-5 h-5 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0"
            />
            <div class="min-w-0">
              <span class="block text-sm text-gray-900 font-medium">Уведомление ответственному за сделку</span>
              <span class="block text-sm text-gray-500 mt-0.5">Системное уведомление Bitrix24 ответственному (переплата — с блоком итога). Не дублирует групповой чат.</span>
            </div>
          </label>

          <!-- Комментарий в таймлайн -->
          <label class="flex items-start gap-3 cursor-pointer">
            <input
              v-model="notifyDealTimeline"
              type="checkbox"
              class="w-5 h-5 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0"
            />
            <div class="min-w-0">
              <span class="block text-sm text-gray-900 font-medium">Комментарий в таймлайн сделки</span>
              <span class="block text-sm text-gray-500 mt-0.5">Текст уведомления дублируется в таймлайне карточки CRM (сделка/лид), не в личные сообщения</span>
            </div>
          </label>

          <hr class="border-gray-100" />

          <!-- Чат для уведомлений -->
          <div class="space-y-3">
            <label class="flex items-start gap-3 cursor-pointer">
              <input
                v-model="notifyChatEnabled"
                type="checkbox"
                class="w-5 h-5 rounded border-gray-300 text-violet-500 focus:ring-violet-400 mt-0.5 shrink-0"
              />
              <div class="min-w-0">
                <span class="block text-sm text-gray-900 font-medium">Чат для уведомлений</span>
                <span class="block text-sm text-gray-500 mt-0.5">Сообщения об оплатах и отвязках отправляются только в этот групповой чат (не в личку и не в «Заметки»). Добавьте сюда нужных сотрудников. При сохранении настроек приложение пытается добавить в чат бота «Точка Банк»; если не получилось (нет прав или бот уже в чате), пригласите его вручную из поиска контактов.</span>
              </div>
            </label>

            <div v-if="notifyChatEnabled" class="pl-8 space-y-3">
              <div v-if="isLoadingChats" class="text-sm text-gray-400">Загрузка чатов…</div>
              <template v-else>
                <div v-if="chatLoadError && availableChats.length === 0" class="flex items-center gap-3 px-3 py-2.5 bg-amber-50 border border-amber-200 rounded-lg">
                  <span class="text-sm text-amber-700">{{ chatLoadError }}</span>
                  <button
                    type="button"
                    class="shrink-0 text-sm font-medium text-violet-600 hover:text-violet-700 underline"
                    @click="loadAvailableChats"
                  >
                    Повторить
                  </button>
                </div>
                <div v-if="availableChats.length > 0" class="relative" style="max-width: 360px;">
                  <select
                    v-model.number="notifyChatId"
                    class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm appearance-none focus:outline-none focus:ring-2 focus:ring-violet-400 bg-white"
                  >
                    <option :value="null" disabled>Выберите чат</option>
                    <option v-for="chat in availableChats" :key="chat.id" :value="chat.id">
                      {{ chat.title }}
                    </option>
                  </select>
                  <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                  </svg>
                </div>
                <button
                  type="button"
                  class="px-4 py-2.5 text-sm font-medium text-white bg-violet-500 hover:bg-violet-600 rounded-lg transition-colors disabled:opacity-50"
                  :disabled="isCreatingChat"
                  @click="createNotificationChat"
                >
                  <span v-if="isCreatingChat">Создание…</span>
                  <span v-else>Создать чат</span>
                </button>
              </template>
            </div>
          </div>
        </div>

        <!-- Уведомление о сохранении -->
        <div v-if="settingsSaved" class="mt-6 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700">
          Настройки сохранены
        </div>
        <div v-if="settingsError" class="mt-6 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
          {{ settingsError }}
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
.settings-select {
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 0.75rem center;
  background-size: 1rem;
}

.payments-table-head th {
  position: sticky;
  top: 0;
  z-index: 20;
  background-color: #fff;
  box-shadow: 0 1px 0 0 rgb(229 231 235);
}

.payments-purpose-cell {
  color: rgb(75 85 99);
  font-size: 0.75rem;
  line-height: 1.375;
  overflow-wrap: anywhere;
  word-break: break-word;
  white-space: normal;
}
</style>
