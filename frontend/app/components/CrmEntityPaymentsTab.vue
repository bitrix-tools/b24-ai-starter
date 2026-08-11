<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'
import type { CrmEntityPaymentsResponse } from '~/stores/tochka'
import DoubleArrowCounterClockwiseScrumIcon from '@bitrix24/b24icons-vue/main/DoubleArrowCounterClockwiseScrumIcon'
import CopyIcon from '@bitrix24/b24icons-vue/outline/CopyIcon'

const props = defineProps<{
  b24: B24Frame
  entityType: 'deal' | 'lead'
  entityId: number
}>()

const { t, locale } = useI18n()
const tochkaStore = useTochkaStore()

type CrmEntityMeta = {
  title: string
  beginDate: string | null
  dateCreate: string | null
}

const ledger = ref<CrmEntityPaymentsResponse | null>(null)
const entityOpportunity = ref(0)
const currencyId = ref('RUB')
const loading = ref(false)
const refreshLoading = ref(false)
const unlinkingId = ref<string | null>(null)
const refundingId = ref<string | null>(null)

const showCreateLinkModal = ref(false)
const createLinkLoading = ref(false)
const createLinkAmount = ref('')
const createLinkKind = ref<'full' | 'remainder' | 'advance'>('full')
const linkModeCard = ref(true)
const linkModeSbp = ref(true)
const linkModeMir = ref(false)
const linkModeDolyame = ref(false)
const createLinkMerchantId = ref('')
const createLinkPurpose = ref('')
const crmEntityInfo = ref<CrmEntityMeta | null>(null)
const purposeManuallyEdited = ref(false)
const createLinkError = ref('')
const consentUpgradeRequired = ref(false)
const reconnectLoading = ref(false)

/** Способы оплаты, разрешённые для выбранной торговой точки (Get Retailers → paymentModes). null = ещё не загрузили. */
const createLinkRetailerModes = ref<Set<string> | null>(null)
/** В Точке нет активной торговой точки интернет-эквайринга. */
const acquiringSetupRequired = ref(false)
const paymentLinkHint = ref('')
const paymentLinkCopiedId = ref<string | null>(null)
let paymentLinkCopiedTimer: ReturnType<typeof setTimeout> | null = null

function openPaymentUrl(url: string | null | undefined) {
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}

async function copyPaymentUrl(url: string | null | undefined, rowId: string | number) {
  if (!url) return
  const write = async () => {
    await navigator.clipboard.writeText(url)
  }
  try {
    await write()
  } catch {
    try {
      const ta = document.createElement('textarea')
      ta.value = url
      ta.setAttribute('readonly', '')
      ta.style.position = 'fixed'
      ta.style.left = '-9999px'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
    } catch {
      return
    }
  }
  paymentLinkCopiedId.value = String(rowId)
  if (paymentLinkCopiedTimer) clearTimeout(paymentLinkCopiedTimer)
  paymentLinkCopiedTimer = setTimeout(() => {
    paymentLinkCopiedId.value = null
  }, 2000)
}

function normalizePaymentModeToken(val: unknown): string | null {
  const m = String(val ?? '')
    .trim()
    .toLowerCase()
  const aliases: Record<string, string> = {
    't-pay': 'tinkoff',
    t_pay: 'tinkoff',
    tpay: 'tinkoff',
  }
  if (aliases[m]) return aliases[m]
  if (['card', 'sbp', 'tinkoff', 'dolyame'].includes(m)) return m
  return null
}

function paymentModesFromRetailer(r: Record<string, unknown>): Set<string> {
  const raw = r.paymentModes ?? r.PaymentModes
  const set = new Set<string>()
  if (!Array.isArray(raw)) return set
  for (const item of raw) {
    if (typeof item === 'object' && item !== null) {
      const o = item as Record<string, unknown>
      const v = o.code ?? o.value ?? o.name ?? o.mode
      const n = normalizePaymentModeToken(v)
      if (n) set.add(n)
    } else {
      const n = normalizePaymentModeToken(item)
      if (n) set.add(n)
    }
  }
  return set
}

async function loadRetailersForModal() {
  if (!tochkaStore.status.connected) {
    createLinkRetailerModes.value = null
    acquiringSetupRequired.value = false
    return
  }
  try {
    const data = await tochkaStore.fetchAcquiringRetailers()
    const inner = (data?.Data ?? data) as Record<string, unknown>
    let retailers = inner?.Retailer ?? inner?.Retailers ?? []
    if (!Array.isArray(retailers)) retailers = retailers ? [retailers] : []
    const active = retailers.filter(
      (x: unknown) =>
        typeof x === 'object' &&
        x !== null &&
        String((x as Record<string, unknown>).isActive ?? '')
          .toLowerCase() === 'true' &&
        (x as Record<string, unknown>).status === 'REG',
    ) as Record<string, unknown>[]
    if (!active.length) {
      createLinkRetailerModes.value = null
      acquiringSetupRequired.value = true
      return
    }
    acquiringSetupRequired.value = false
    const mid = createLinkMerchantId.value.trim()
    let picked: Record<string, unknown> | undefined
    if (mid) {
      picked = active.find((x) => String(x.merchantId ?? x.merchant_id ?? '') === mid)
    }
    if (!picked && active.length) picked = active[0]
    if (!picked) {
      createLinkRetailerModes.value = null
      acquiringSetupRequired.value = true
      return
    }
    const s = paymentModesFromRetailer(picked)
    /* Пустой массив paymentModes у Точки = не ограничиваем чекбоксы */
    createLinkRetailerModes.value = s.size === 0 ? null : s
  } catch {
    createLinkRetailerModes.value = null
    acquiringSetupRequired.value = false
  }
}

const retailerMirAvailable = computed(() => {
  const s = createLinkRetailerModes.value
  if (!s || s.size === 0) return true
  return s.has('tinkoff')
})

const retailerDolyameAvailable = computed(() => {
  const s = createLinkRetailerModes.value
  if (!s || s.size === 0) return true
  return s.has('dolyame')
})

const createLinkLowAmountForExtraModes = computed(() => {
  const a = parseFloat(String(createLinkAmount.value).replace(',', '.'))
  if (Number.isNaN(a)) return false
  return a < 100 && (linkModeMir.value || linkModeDolyame.value)
})

watch([retailerMirAvailable, retailerDolyameAvailable], () => {
  if (!retailerMirAvailable.value) linkModeMir.value = false
  if (!retailerDolyameAvailable.value) linkModeDolyame.value = false
})

function formatCurrency(val: number, currency: string = 'RUB') {
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: currency || 'RUB',
      minimumFractionDigits: 2,
    }).format(val)
  } catch {
    return `${val.toFixed(2)} ${currency}`
  }
}

function formatDateHeader(iso: string): string {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

const RU_MONTHS_GEN = [
  'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
  'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря',
]

function parseBitrixDate(raw?: string | null): Date | null {
  if (!raw || typeof raw !== 'string') return null
  const s = raw.trim()
  if (!s) return null
  let d = new Date(s)
  if (!Number.isNaN(d.getTime())) return d
  const m = /^(\d{2})\.(\d{2})\.(\d{4})/.exec(s)
  if (m) {
    d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]))
    if (!Number.isNaN(d.getTime())) return d
  }
  return null
}

function purposeEffectiveDate(): Date {
  const info = crmEntityInfo.value
  const d1 = parseBitrixDate(info?.beginDate)
  if (d1) return d1
  const d2 = parseBitrixDate(info?.dateCreate)
  if (d2) return d2
  return new Date()
}

function formatPurposeDate(d: Date): string {
  if ((locale.value || '').toString().startsWith('en')) {
    return d.toLocaleDateString('en-US', { day: 'numeric', month: 'long', year: 'numeric' })
  }
  const day = d.getDate()
  const month = RU_MONTHS_GEN[d.getMonth()]
  const year = d.getFullYear()
  return `${day} ${month} ${year} года`
}

function buildDefaultPurpose(): string {
  const dateStr = formatPurposeDate(purposeEffectiveDate())
  const kind = createLinkKind.value
  const et = props.entityType
  const key = `placement.crm_entity_payments.create_link_purpose_pattern_${et}_${kind}`
  return t(key, { no: props.entityId, date: dateStr })
}

function onPurposeUserInput() {
  purposeManuallyEdited.value = true
}

function appendPurposeFragment(fragment: string) {
  const cur = createLinkPurpose.value
  createLinkPurpose.value = cur && !/\s$/.test(cur) ? `${cur} ${fragment}` : `${cur}${fragment}`
  purposeManuallyEdited.value = true
}

function applyPurposeTemplate() {
  purposeManuallyEdited.value = false
  createLinkPurpose.value = buildDefaultPurpose()
}

function insertSnippetTitle() {
  const title = (crmEntityInfo.value?.title || '').trim()
  if (!title) return
  appendPurposeFragment(`«${title}»`)
}

function insertSnippetNo() {
  appendPurposeFragment(`№${props.entityId}`)
}

function insertSnippetDate() {
  const dateStr = formatPurposeDate(purposeEffectiveDate())
  appendPurposeFragment(
    t('placement.crm_entity_payments.create_link_purpose_snippet_date', { date: dateStr }),
  )
}

function insertPercent(n: 30 | 50 | 70) {
  appendPurposeFragment(`${n}%`)
}

function clearPurposeField() {
  createLinkPurpose.value = ''
  purposeManuallyEdited.value = true
}

function statusLabel(s: string): string {
  const map: Record<string, string> = {
    pending: t('placement.crm_entity_payments.status.pending'),
    matched: t('placement.crm_entity_payments.status.matched'),
    applied: t('placement.crm_entity_payments.status.applied'),
    refunded: t('placement.crm_entity_payments.status.refunded'),
    error: t('placement.crm_entity_payments.status.error'),
    ignored: t('placement.crm_entity_payments.status.ignored'),
  }
  return map[s] || s
}

function statusColor(s: string): string {
  const map: Record<string, string> = {
    pending: 'bg-yellow-50 text-yellow-700',
    matched: 'bg-green-50 text-green-600',
    applied: 'bg-green-50 text-green-700',
    refunded: 'bg-violet-50 text-violet-800',
    error: 'bg-red-50 text-red-700',
    ignored: 'bg-gray-100 text-gray-500',
  }
  return map[s] || 'bg-gray-100 text-gray-500'
}

function channelLabel(code: string): string {
  const key = `placement.crm_entity_payments.channel.${code}`
  const translated = t(key)
  return translated === key ? code : translated
}

function paymentKindLabel(kind: string | null | undefined): string {
  if (!kind) return '—'
  const key = `placement.crm_entity_payments.payment_kind.${kind}`
  const translated = t(key)
  return translated === key ? kind : translated
}

/** Ссылка «Оплатить» — только пока ссылка не оплачена (после оплаты скрываем). */
function showPaymentLink(p: { payment_url?: string | null; source?: string; acquiring_status?: string | null }) {
  if (!p.payment_url) return false
  if (p.source !== 'acquiring') return true
  const s = (p.acquiring_status || '').toLowerCase()
  return s !== 'paid' && s !== 'cancelled'
}

const groupedPayments = computed(() => {
  const list = ledger.value?.payments || []
  const groups: Record<string, { payments: any[]; total: number }> = {}
  for (const p of list) {
    const dateKey = p.transaction_date?.split('T')[0] || 'unknown'
    if (!groups[dateKey]) groups[dateKey] = { payments: [], total: 0 }
    groups[dateKey].payments.push(p)
    groups[dateKey].total += p.amount || 0
  }
  return groups
})

async function loadCrmEntityAmounts() {
  const method = props.entityType === 'deal' ? 'crm.deal.get' : 'crm.lead.get'
  const resp = await props.b24.callMethod(method, { id: props.entityId })
  const r = resp.getData().result
  entityOpportunity.value = parseFloat(String(r?.OPPORTUNITY ?? 0)) || 0
  currencyId.value = r?.CURRENCY_ID || 'RUB'
  crmEntityInfo.value = {
    title: String(r?.TITLE ?? '').trim(),
    beginDate: r?.BEGINDATE != null ? String(r.BEGINDATE) : null,
    dateCreate: r?.DATE_CREATE != null ? String(r.DATE_CREATE) : null,
  }
}

async function loadLedger() {
  loading.value = true
  try {
    await tochkaStore.fetchStatus()
    await loadCrmEntityAmounts()
    const dealId = props.entityType === 'deal' ? props.entityId : undefined
    const leadId = props.entityType === 'lead' ? props.entityId : undefined
    ledger.value = await tochkaStore.fetchCrmEntityPayments({
      dealId,
      leadId,
      entityAmount: entityOpportunity.value,
      currencyId: currencyId.value,
    })
  } catch {
    ledger.value = null
  } finally {
    loading.value = false
  }
}

async function onRefresh() {
  refreshLoading.value = true
  try {
    if (tochkaStore.status.connected) {
      try {
        await tochkaStore.refreshAcquiringStatuses()
      } catch {
        /* acquiring refresh optional */
      }
      try {
        await tochkaStore.syncPayments({ days: 14 })
      } catch {
        /* sync optional */
      }
    }
    await loadLedger()
  } finally {
    refreshLoading.value = false
  }
}

async function openCreateLinkModal() {
  createLinkError.value = ''
  paymentLinkHint.value = ''
  createLinkRetailerModes.value = null
  acquiringSetupRequired.value = false
  createLinkAmount.value =
    ledger.value?.remaining != null && ledger.value.remaining > 0
      ? String(ledger.value.remaining)
      : entityOpportunity.value
        ? String(entityOpportunity.value)
        : ''
  createLinkKind.value = 'full'
  linkModeCard.value = true
  linkModeSbp.value = true
  linkModeMir.value = false
  linkModeDolyame.value = false
  createLinkMerchantId.value = ''
  createLinkPurpose.value = ''
  purposeManuallyEdited.value = false
  showCreateLinkModal.value = true
  try {
    await Promise.all([
      loadCrmEntityAmounts(),
      loadRetailersForModal(),
    ])
  } catch {
    /* оставляем прошлые суммы/мету с последней загрузки */
  }
  if (!purposeManuallyEdited.value) {
    createLinkPurpose.value = buildDefaultPurpose()
  }
}

async function submitCreateLink() {
  createLinkError.value = ''
  const modes: string[] = []
  if (linkModeCard.value) modes.push('card')
  if (linkModeSbp.value) modes.push('sbp')
  if (linkModeMir.value) modes.push('tinkoff')
  if (linkModeDolyame.value) modes.push('dolyame')
  if (!modes.length) {
    createLinkError.value = t('placement.crm_entity_payments.create_link_modes_required')
    return
  }
  createLinkLoading.value = true
  try {
    const created = await tochkaStore.createAcquiringPaymentLink({
      ...(props.entityType === 'deal'
        ? { dealId: props.entityId }
        : { leadId: props.entityId }),
      amount: createLinkAmount.value,
      paymentKind: createLinkKind.value,
      paymentModes: modes,
      merchantId: createLinkMerchantId.value.trim() || undefined,
      purpose: createLinkPurpose.value.trim() || undefined,
    })
    if (created.paymentModesDropped?.length) {
      paymentLinkHint.value = t('placement.crm_entity_payments.create_link_modes_dropped_hint', {
        list: created.paymentModesDropped.join(', '),
      })
    } else {
      paymentLinkHint.value = ''
    }
    showCreateLinkModal.value = false
    await loadLedger()
  } catch (e: any) {
    if (e?.data?.code === 'consent_upgrade_required') {
      consentUpgradeRequired.value = true
      createLinkError.value = e?.data?.error || t('placement.crm_entity_payments.consent_upgrade_required')
    } else {
      createLinkError.value = e?.data?.error || e?.message || t('placement.crm_entity_payments.create_link_failed')
    }
  } finally {
    createLinkLoading.value = false
  }
}

async function reconnectTochka() {
  reconnectLoading.value = true
  try {
    const authUrl = await tochkaStore.connectWithEnvCredentials({ popup: true })
    if (!authUrl) return
    const popup = window.open(authUrl, 'tochka_auth', 'width=600,height=700')

    const onMessage = (event: MessageEvent) => {
      if (event.data?.tochkaAuth) {
        window.removeEventListener('message', onMessage)
        consentUpgradeRequired.value = false
        createLinkError.value = ''
        reconnectLoading.value = false
        void loadLedger()
      }
    }
    window.addEventListener('message', onMessage)

    const poll = setInterval(() => {
      if (!popup || popup.closed) {
        clearInterval(poll)
        window.removeEventListener('message', onMessage)
        reconnectLoading.value = false
        void tochkaStore.fetchStatus()
      }
    }, 500)
  } catch (e: any) {
    createLinkError.value = e?.data?.error || e?.message || 'Reconnect failed'
    reconnectLoading.value = false
  }
}

async function onUnlink(payment: { id: string; source?: string }) {
  unlinkingId.value = payment.id
  try {
    if (payment.source === 'acquiring') {
      await tochkaStore.unlinkAcquiringOperation(payment.id)
    } else {
      const action = props.entityType === 'deal' ? 'unbind' : 'unbind_lead'
      await tochkaStore.paymentAction(payment.id, action)
    }
    await loadLedger()
  } finally {
    unlinkingId.value = null
  }
}

async function onRefundAcquiring(payment: { id: string; source?: string }) {
  if (payment.source !== 'acquiring') return
  if (!confirm(t('placement.crm_entity_payments.refund_confirm'))) return
  refundingId.value = payment.id
  try {
    await tochkaStore.refundAcquiringOperation(payment.id)
    await loadLedger()
  } catch (e: any) {
    const msg = e?.data?.error || e?.message || t('placement.crm_entity_payments.refund_failed')
    alert(msg)
  } finally {
    refundingId.value = null
  }
}

async function openContact(contactId: number) {
  try {
    const path = `/crm/contact/details/${contactId}/`
    const url = props.b24.slider.getUrl(path)
    await props.b24.slider.openPath(url)
  } catch (e) {
    console.error('openContact', e)
  }
}

async function openCompany(companyId: number) {
  try {
    const path = `/crm/company/details/${companyId}/`
    const url = props.b24.slider.getUrl(path)
    await props.b24.slider.openPath(url)
  } catch (e) {
    console.error('openCompany', e)
  }
}

const headerRatio = computed(() => {
  const sum = ledger.value?.sumPaid ?? 0
  const entity = ledger.value?.entityAmount
  const cur = ledger.value?.currencyId || currencyId.value
  if (entity != null && !Number.isNaN(entity)) {
    return `${formatCurrency(sum, cur)} / ${formatCurrency(entity, cur)}`
  }
  return formatCurrency(sum, cur)
})

const overpayment = computed(() => ledger.value?.overpayment ?? 0)

const canInsertTitleSnippet = computed(() => !!(crmEntityInfo.value?.title || '').trim())

onMounted(() => {
  void loadLedger()
})

watch(
  () => [props.entityType, props.entityId] as const,
  () => {
    void loadLedger()
  },
)

watch(createLinkKind, () => {
  if (showCreateLinkModal.value && !purposeManuallyEdited.value) {
    createLinkPurpose.value = buildDefaultPurpose()
  }
})
</script>

<template>
  <div class="crm-entity-payments min-h-full min-w-0 bg-white px-4 py-4 text-sm text-gray-900 sm:px-5 sm:py-5 space-y-4">
      <div v-if="!tochkaStore.status.connected" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">
        {{ t('placement.crm_entity_payments.not_connected') }}
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-medium text-gray-900">
          {{ entityType === 'deal' ? t('placement.crm_entity_payments.title_deal') : t('placement.crm_entity_payments.title_lead') }}
        </h2>
        <div class="flex items-center gap-3">
          <span class="text-sm tabular-nums text-gray-800">{{ headerRatio }}</span>
          <B24Button
            v-if="tochkaStore.status.connected"
            color="air-secondary"
            size="sm"
            :label="t('placement.crm_entity_payments.create_link_open')"
            @click="openCreateLinkModal"
          />
          <B24Button
            color="air-primary"
            size="sm"
            :icon="DoubleArrowCounterClockwiseScrumIcon"
            :loading="refreshLoading"
            :label="t('placement.crm_entity_payments.refresh')"
            @click="onRefresh"
          />
        </div>
      </div>

      <div
        v-if="overpayment > 0"
        class="rounded-lg bg-red-600 text-white px-3 py-2.5 font-medium tabular-nums"
      >
        {{ t('placement.crm_entity_payments.overpayment') }} +{{ formatCurrency(overpayment, ledger?.currencyId || currencyId) }}
      </div>

      <div
        v-if="paymentLinkHint"
        class="rounded-lg border border-amber-200 bg-amber-50/90 px-3 py-2 text-xs text-amber-950"
      >
        {{ paymentLinkHint }}
      </div>

      <div v-if="consentUpgradeRequired" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 space-y-2">
        <p class="text-sm font-medium text-amber-900">
          {{ t('placement.crm_entity_payments.consent_upgrade_required') }}
        </p>
        <B24Button
          color="air-primary"
          size="sm"
          :loading="reconnectLoading"
          :label="t('placement.crm_entity_payments.consent_upgrade_button')"
          @click="reconnectTochka"
        />
      </div>

      <div v-if="createLinkError && showCreateLinkModal && !consentUpgradeRequired" class="text-sm text-red-600">
        {{ createLinkError }}
      </div>

      <div
        v-if="showCreateLinkModal"
        class="rounded-xl border border-violet-200 bg-violet-50/60 p-4 space-y-3 shadow-sm"
      >
        <h3 class="text-sm font-semibold text-gray-900">
          {{ t('placement.crm_entity_payments.create_link_title') }}
        </h3>

        <div
          v-if="acquiringSetupRequired"
          class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 space-y-2 text-sm text-blue-950"
        >
          <p class="font-semibold">
            {{ t('placement.crm_entity_payments.create_link_acquiring_required_title') }}
          </p>
          <p class="text-xs leading-relaxed text-blue-900">
            {{ t('placement.crm_entity_payments.create_link_acquiring_required_intro') }}
          </p>
          <ol class="list-decimal list-inside space-y-1 text-xs leading-relaxed text-blue-900">
            <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step1') }}</li>
            <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step2') }}</li>
            <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step3') }}</li>
            <li>{{ t('placement.crm_entity_payments.create_link_acquiring_required_step4') }}</li>
          </ol>
          <p class="text-xs leading-relaxed text-blue-800">
            {{ t('placement.crm_entity_payments.create_link_acquiring_required_merchant') }}
          </p>
          <p class="text-xs leading-relaxed text-blue-800">
            {{ t('placement.crm_entity_payments.create_link_acquiring_required_support') }}
          </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-xs text-gray-600">
            {{ t('placement.crm_entity_payments.create_link_amount') }}
            <input
              v-model="createLinkAmount"
              type="text"
              inputmode="decimal"
              class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
            />
          </label>
          <label class="block text-xs text-gray-600">
            {{ t('placement.crm_entity_payments.create_link_kind') }}
            <select v-model="createLinkKind" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option value="full">{{ t('placement.crm_entity_payments.payment_kind.full') }}</option>
              <option value="remainder">{{ t('placement.crm_entity_payments.payment_kind.remainder') }}</option>
              <option value="advance">{{ t('placement.crm_entity_payments.payment_kind.advance') }}</option>
            </select>
          </label>
          <label class="block text-xs text-gray-600 sm:col-span-2">
            {{ t('placement.crm_entity_payments.create_link_merchant_hint') }}
            <input
              v-model="createLinkMerchantId"
              type="text"
              class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
              :placeholder="t('placement.crm_entity_payments.create_link_merchant_placeholder')"
              @blur="loadRetailersForModal"
            />
          </label>
          <label class="block text-xs text-gray-600 sm:col-span-2">
            {{ t('placement.crm_entity_payments.create_link_purpose') }}
            <textarea
              v-model="createLinkPurpose"
              rows="3"
              class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm resize-y min-h-[4rem]"
              @input="onPurposeUserInput"
            />
            <p class="mt-1.5 text-[11px] leading-snug text-gray-500">
              {{ t('placement.crm_entity_payments.create_link_purpose_hint') }}
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
              <span class="text-[11px] text-gray-500 shrink-0">{{ t('placement.crm_entity_payments.create_link_purpose_snippets') }}</span>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] text-violet-800 hover:bg-violet-50 disabled:opacity-40 disabled:pointer-events-none"
                :disabled="!canInsertTitleSnippet"
                @click="insertSnippetTitle"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_title') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] text-violet-800 hover:bg-violet-50"
                @click="insertSnippetNo"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_no', { no: entityId }) }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] text-violet-800 hover:bg-violet-50"
                :title="formatPurposeDate(purposeEffectiveDate())"
                @click="insertSnippetDate"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_date_btn') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] tabular-nums text-violet-800 hover:bg-violet-50"
                @click="insertPercent(30)"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_pct_30') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] tabular-nums text-violet-800 hover:bg-violet-50"
                @click="insertPercent(50)"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_pct_50') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-violet-200 bg-white px-2 py-0.5 text-[11px] tabular-nums text-violet-800 hover:bg-violet-50"
                @click="insertPercent(70)"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_snippet_pct_70') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-700 hover:bg-gray-50 ml-1"
                @click="applyPurposeTemplate"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_apply_template') }}
              </button>
              <button
                type="button"
                class="inline-flex items-center rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-600 hover:bg-gray-50"
                @click="clearPurposeField"
              >
                {{ t('placement.crm_entity_payments.create_link_purpose_clear') }}
              </button>
            </div>
          </label>
        </div>
        <div class="text-xs font-medium text-gray-700">
          {{ t('placement.crm_entity_payments.create_link_modes') }}
        </div>
        <div class="flex flex-wrap gap-4 text-sm">
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input v-model="linkModeCard" type="checkbox" class="rounded border-gray-300" />
            {{ t('placement.crm_entity_payments.channel.card') }}
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input v-model="linkModeSbp" type="checkbox" class="rounded border-gray-300" />
            {{ t('placement.crm_entity_payments.channel.sbp') }}
          </label>
          <label
            class="inline-flex items-center gap-2"
            :class="retailerMirAvailable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'"
          >
            <input
              v-model="linkModeMir"
              type="checkbox"
              class="rounded border-gray-300"
              :disabled="!retailerMirAvailable"
            />
            {{ t('placement.crm_entity_payments.channel.mir_pay') }}
          </label>
          <label
            class="inline-flex items-center gap-2"
            :class="retailerDolyameAvailable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'"
          >
            <input
              v-model="linkModeDolyame"
              type="checkbox"
              class="rounded border-gray-300"
              :disabled="!retailerDolyameAvailable"
            />
            {{ t('placement.crm_entity_payments.channel.dolyame') }}
          </label>
        </div>
        <p class="text-[11px] leading-snug text-gray-500 mt-1">
          {{ t('placement.crm_entity_payments.create_link_modes_tochka_help') }}
        </p>
        <p
          v-if="createLinkRetailerModes && createLinkRetailerModes.size > 0 && (!retailerMirAvailable || !retailerDolyameAvailable)"
          class="text-[11px] leading-snug text-amber-800 mt-1"
        >
          {{ t('placement.crm_entity_payments.create_link_modes_retailer_missing') }}
        </p>
        <p
          v-if="createLinkLowAmountForExtraModes"
          class="text-[11px] leading-snug text-amber-800 mt-1"
        >
          {{ t('placement.crm_entity_payments.create_link_low_amount_modes') }}
        </p>
        <div class="flex flex-wrap gap-2 pt-1">
          <B24Button
            color="air-primary"
            size="sm"
            :loading="createLinkLoading"
            :disabled="acquiringSetupRequired"
            :label="t('placement.crm_entity_payments.create_link_submit')"
            @click="submitCreateLink"
          />
          <B24Button
            color="air-secondary"
            size="sm"
            :label="t('placement.crm_entity_payments.create_link_cancel')"
            @click="showCreateLinkModal = false"
          />
        </div>
      </div>

      <div v-if="loading && !ledger" class="py-10 text-center text-gray-500">
        {{ t('placement.crm_entity_payments.loading') }}
      </div>

      <div
        v-else-if="ledger && ledger.payments.length === 0"
        class="py-10 text-center text-gray-500 border-b border-gray-100"
      >
        {{ t('placement.crm_entity_payments.empty') }}
      </div>

      <div v-else-if="ledger" class="overflow-x-auto">
        <table class="w-full min-w-[1180px] table-fixed border-collapse text-sm">
          <colgroup>
            <col class="w-[260px]" />
            <col class="w-[120px]" />
            <col class="w-[120px]" />
            <col class="w-[130px]" />
            <col class="w-[130px]" />
            <col class="w-[90px]" />
            <col class="w-[240px]" />
            <col class="w-[120px]" />
            <col class="w-[120px]" />
          </colgroup>
          <thead>
            <tr class="border-b border-gray-200 bg-white text-left text-xs font-normal text-gray-500 uppercase tracking-wide">
              <th class="py-2.5 px-3">{{ t('placement.crm_entity_payments.col.amount') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.status') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.channel') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.payment_kind') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.invoice') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.vat') }}</th>
              <th class="py-2.5 px-2 min-w-[140px]">{{ t('placement.crm_entity_payments.col.counterparty') }}</th>
              <th class="py-2.5 px-2">{{ t('placement.crm_entity_payments.col.inn') }}</th>
              <th class="py-2.5 px-2 w-24 text-right" />
            </tr>
          </thead>
          <tbody>
            <template v-for="(group, dateKey) in groupedPayments" :key="dateKey">
              <tr>
                <td colspan="9" class="py-3 px-2">
                  <div class="flex w-full items-center gap-3">
                    <div class="min-h-px flex-1 bg-violet-200" />
                    <span class="shrink-0 text-sm font-medium text-violet-600">
                      {{ dateKey === 'unknown' ? '—' : formatDateHeader(`${dateKey}T12:00:00`) }}
                    </span>
                    <div class="min-h-px flex-1 bg-violet-200" />
                  </div>
                </td>
              </tr>
              <tr
                v-for="p in group.payments"
                :key="p.id"
                class="bg-white"
              >
                <td class="py-2.5 px-3 font-semibold whitespace-nowrap tabular-nums text-gray-900">
                  <span>{{ formatCurrency(p.amount, ledger.currencyId || currencyId) }}</span>
                  <span v-if="showPaymentLink(p)" class="ml-2 inline-flex flex-wrap items-center gap-1.5 align-middle">
                    <B24Button
                      color="air-secondary"
                      size="sm"
                      :label="t('placement.crm_entity_payments.link_pay')"
                      class="inline-flex shrink-0"
                      @click="openPaymentUrl(p.payment_url)"
                    />
                    <B24Button
                      color="air-secondary"
                      size="sm"
                      :icon="CopyIcon"
                      :aria-label="t('placement.crm_entity_payments.link_copy')"
                      :title="
                        paymentLinkCopiedId === String(p.id)
                          ? t('placement.crm_entity_payments.link_copied')
                          : t('placement.crm_entity_payments.link_copy')
                      "
                      class="inline-flex shrink-0 !min-w-0 !px-2"
                      @click="copyPaymentUrl(p.payment_url, p.id)"
                    />
                  </span>
                </td>
                <td class="py-2.5 px-2">
                  <span
                    class="relative inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
                    :class="statusColor(p.status)"
                  >
                    {{ statusLabel(p.status) }}
                    <span
                      v-if="p.status === 'applied'"
                      class="absolute -right-0.5 -top-0.5 h-1.5 w-1.5 rounded-full bg-red-500 ring-2 ring-white"
                      aria-hidden="true"
                    />
                  </span>
                </td>
                <td class="py-2.5 px-2 text-gray-700 text-xs">
                  {{ channelLabel(p.channel || '') }}
                </td>
                <td class="py-2.5 px-2 text-gray-600 text-xs whitespace-nowrap">
                  {{ paymentKindLabel(p.payment_kind) }}
                </td>
                <td class="py-2.5 px-2 text-gray-600 text-xs max-w-[120px] truncate" :title="p.invoice_number || ''">
                  {{ p.invoice_number || '—' }}
                </td>
                <td class="py-2.5 px-2 text-gray-500 text-xs whitespace-nowrap">
                  {{ p.vat_type || t('placement.crm_entity_payments.vat_none') }}
                </td>
                <td class="py-2.5 px-2 text-gray-800 text-xs truncate max-w-[220px]" :title="p.counterparty_name || ''">
                  <template v-if="p.source === 'acquiring' && p.counterparty_contact_id">
                    <button
                      type="button"
                      class="text-left text-violet-700 hover:underline truncate max-w-full"
                      @click="openContact(p.counterparty_contact_id)"
                    >
                      {{ p.counterparty_name || '—' }}
                    </button>
                  </template>
                  <template v-else-if="p.counterparty_company_id">
                    <button
                      type="button"
                      class="text-left text-violet-700 hover:underline truncate max-w-full"
                      @click="openCompany(p.counterparty_company_id)"
                    >
                      {{ p.counterparty_name || '—' }}
                    </button>
                  </template>
                  <template v-else-if="p.counterparty_contact_id">
                    <button
                      type="button"
                      class="text-left text-violet-700 hover:underline truncate max-w-full"
                      @click="openContact(p.counterparty_contact_id)"
                    >
                      {{ p.counterparty_name || '—' }}
                    </button>
                  </template>
                  <template v-else>
                    {{ p.counterparty_name || '—' }}
                  </template>
                </td>
                <td class="py-2.5 px-2 text-gray-500 font-mono text-xs">
                  <template v-if="p.source !== 'acquiring' && p.counterparty_company_id && p.counterparty_inn">
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
                <td class="py-2.5 px-2 text-right align-middle">
                  <div class="inline-flex flex-wrap items-center justify-end gap-1">
                    <B24Button
                      v-if="p.can_unlink"
                      size="sm"
                      color="air-secondary"
                      :label="t('placement.crm_entity_payments.unlink_action')"
                      :loading="unlinkingId === p.id"
                      :aria-label="t('placement.crm_entity_payments.unlink')"
                      class="inline-flex"
                      @click="onUnlink(p)"
                    />
                    <B24Button
                      v-if="p.can_refund"
                      size="sm"
                      color="air-secondary"
                      :label="t('placement.crm_entity_payments.refund_action')"
                      :loading="refundingId === p.id"
                      :aria-label="t('placement.crm_entity_payments.refund_action')"
                      class="inline-flex"
                      @click="onRefundAcquiring(p)"
                    />
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
  </div>
</template>
