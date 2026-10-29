<script setup lang="ts">
/**
 * Поддержка — переписка, а не разовая форма (перенос из dependent_fields,
 * навык b24-app-ui §13). Клиент пишет, разработчик отвечает из командного
 * центра, окно показывает тред и опрашивает его, пока открыто. Тред привязан
 * к браузеру (useSupport) и продолжается при повторном открытии. Вложение —
 * только к первому сообщению (в скриншоте часто чужие перс. данные).
 */

import type { SupportMessage } from '../stores/api'

const api = useApiStore()
const user = useUserStore()
const support = useSupport()

const isOpen = defineModel<boolean>('open', { default: false })

const MIN_LENGTH = 10
const MAX_FILE_BYTES = 10 * 1024 * 1024
const POLL_MS = 6000

const messages = ref<SupportMessage[]>([])
const ticket = ref(support.ticketId())
const message = ref('')
const contact = ref('')
const contactKnown = ref(false)
const contactDismissed = ref(false)
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const body = ref<HTMLElement | null>(null)

const isSending = ref(false)
const isLoading = ref(false)
const error = ref('')

let pollTimer: ReturnType<typeof setInterval> | null = null

const canSend = computed(() => message.value.trim().length > 0 && !isSending.value)
const charsLeft = computed(() => Math.max(0, MIN_LENGTH - message.value.trim().length))
const greetingName = computed(() => (user.login || '').trim().split(' ')[0] || '')
const isFirst = computed(() => !ticket.value)
const askContact = computed(() =>
  !!ticket.value && !contactKnown.value && !contactDismissed.value && messages.value.length > 0
)

function scrollDown() {
  nextTick(() => {
    if (body.value) body.value.scrollTop = body.value.scrollHeight
  })
}

async function loadThread() {
  if (!ticket.value) return
  try {
    const data = await api.loadSupportThread(ticket.value)
    messages.value = data.messages
    contactKnown.value = !!data.contact
    support.markRead(data.messages)
  } catch {
    support.forgetTicket()
    ticket.value = ''
    messages.value = []
  }
}

function pickFile() {
  fileInput.value?.click()
}
function onFileChosen(event: Event) {
  const chosen = (event.target as HTMLInputElement).files?.[0] || null
  if (chosen && chosen.size > MAX_FILE_BYTES) {
    error.value = 'Файл больше 10 МБ. Пришлите его на info@1daa.ru'
    return
  }
  error.value = ''
  file.value = chosen
}
function dropFile() {
  file.value = null
  if (fileInput.value) fileInput.value.value = ''
}
function humanSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

async function send() {
  if (message.value.trim().length < MIN_LENGTH) {
    error.value = 'Опишите вопрос чуть подробнее — так мы ответим быстрее.'
    return
  }
  error.value = ''
  const text = message.value.trim()
  const fileToSend = isFirst.value ? file.value : null
  const tempId = `tmp-${Date.now()}`

  // Оптимистично показываем сообщение сразу и чистим поле — не ждём сервер,
  // чтобы отправка не «залипала». Настоящее сообщение заменит временное.
  messages.value = [...messages.value, {
    id: tempId,
    author: 'user',
    authorName: user.login || 'Вы',
    text,
    attachment: '',
    createdAt: new Date().toISOString()
  }]
  message.value = ''
  dropFile()
  scrollDown()
  isSending.value = true

  try {
    const created = await api.sendSupportMessage({
      message: text,
      userName: user.login,
      file: fileToSend,
      ticketId: ticket.value || undefined
    })
    if (!ticket.value) {
      ticket.value = created.id
      support.rememberTicket(created.id)
      await loadThread()
      startPoll()
    } else if (created.message) {
      // Замена временного сообщения настоящим по tempId — без дубля.
      messages.value = messages.value.map(m => (m.id === tempId ? created.message! : m))
      support.markRead(messages.value)
    }
    scrollDown()
  } catch (e: any) {
    // Отправка не удалась — убираем временное сообщение и возвращаем текст.
    messages.value = messages.value.filter(m => m.id !== tempId)
    message.value = text
    error.value = e?.data?.error || 'Не удалось отправить сообщение. Напишите на info@1daa.ru'
  } finally {
    isSending.value = false
  }
}

async function saveContact() {
  if (!contact.value.trim() || !ticket.value) return
  isSending.value = true
  try {
    await api.addSupportContact(ticket.value, contact.value)
    contactKnown.value = true
    contact.value = ''
  } catch (e: any) {
    error.value = e?.data?.error || 'Не удалось сохранить контакт'
  } finally {
    isSending.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && !event.shiftKey && canSend.value) {
    event.preventDefault()
    send()
  }
}

function startPoll() {
  stopPoll()
  if (!ticket.value) return
  pollTimer = setInterval(loadThread, POLL_MS)
}
function stopPoll() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = null
}
function close() {
  isOpen.value = false
}

watch(isOpen, async (open) => {
  if (open) {
    error.value = ''
    if (ticket.value) {
      isLoading.value = true
      await loadThread()
      isLoading.value = false
      scrollDown()
      startPoll()
    }
  } else {
    stopPoll()
  }
}, { immediate: true })

onUnmounted(stopPoll)
</script>

<template>
  <div v-if="isOpen" class="fixed bottom-4 right-4 z-50">
    <div class="flex h-[min(44rem,calc(100vh-4rem))] w-[23rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-gray-200">
      <!-- Шапка -->
      <div class="shrink-0 px-5 pt-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-2.5">
            <div class="flex size-9 items-center justify-center rounded-xl bg-violet-500 text-white">
              <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
              </svg>
            </div>
            <div class="text-sm font-bold uppercase tracking-wide text-gray-900">
              1daa.ru <span class="font-normal normal-case text-gray-500">/ Битрикс24</span>
            </div>
          </div>
          <button type="button" class="flex size-7 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100" aria-label="Закрыть" @click="close">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
          </button>
        </div>
        <h2 class="pb-4 pt-3 text-2xl font-semibold">Поддержка</h2>
      </div>

      <!-- Кто отвечает -->
      <div class="flex shrink-0 items-center gap-3 border-y border-gray-100 px-5 py-3.5">
        <div class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-600">АД</div>
        <div class="text-[13px] leading-tight">
          <div class="font-semibold">Антон Дегтярёв</div>
          <div class="text-gray-500">Разработчик приложения</div>
          <div class="mt-0.5 flex items-center gap-1.5 text-gray-500">
            <span class="inline-block size-2 rounded-full bg-green-500" />
            Отвечаем в рабочие часы
          </div>
        </div>
      </div>

      <!-- Переписка -->
      <div ref="body" class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 py-4">
        <div v-if="!messages.length && !isLoading" class="m-auto text-center">
          <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-violet-100 text-violet-600">
            <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
            </svg>
          </div>
          <div class="font-semibold">Здравствуйте<template v-if="greetingName">, {{ greetingName }}</template></div>
          <p class="mt-1 text-sm text-gray-500">
            Напишите, что нужно сделать или что не работает. Ответ придёт сюда же — окно можно закрыть и открыть позже.
          </p>
        </div>

        <div v-else class="flex flex-col gap-2.5">
          <div
            v-for="item in messages"
            :key="item.id"
            class="flex items-end gap-2"
            :class="item.author === 'user' ? 'flex-row-reverse' : 'flex-row'"
          >
            <div v-if="item.author === 'support'" class="flex size-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-[10px] font-semibold text-gray-600">АД</div>
            <div
              class="max-w-[78%] whitespace-pre-wrap break-words rounded-2xl px-3 py-2 text-sm"
              :class="item.author === 'user' ? 'bg-violet-500 text-white' : 'bg-gray-100 text-gray-900'"
            >
              <template v-if="item.text">{{ item.text }}</template>
            </div>
          </div>
        </div>

        <div v-if="askContact" class="mt-3 rounded-2xl border border-gray-200 bg-gray-50 p-3">
          <div class="text-xs font-medium text-gray-700">Куда ответить, если вы закроете окно?</div>
          <div class="mt-2 flex gap-2">
            <input
              v-model="contact"
              class="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="ivan@example.ru или телефон"
              @keydown.enter="saveContact"
            >
            <button
              type="button"
              class="shrink-0 rounded-xl bg-violet-500 px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
              :disabled="!contact.trim() || isSending"
              @click="saveContact"
            >Ок</button>
          </div>
          <button type="button" class="mt-1.5 text-xs text-gray-500 hover:underline" @click="contactDismissed = true">
            Не нужно — отвечайте здесь
          </button>
        </div>

        <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
      </div>

      <!-- Поле ввода -->
      <div class="shrink-0 border-t border-gray-100 px-4 py-3">
        <div v-if="file" class="mb-2 flex items-center justify-between gap-2 rounded-xl bg-gray-100 px-3 py-2 text-xs">
          <span class="truncate">{{ file.name }} · {{ humanSize(file.size) }}</span>
          <button type="button" class="shrink-0 text-gray-500 hover:text-red-600" aria-label="Убрать файл" @click="dropFile">✕</button>
        </div>
        <div class="flex items-end gap-2">
          <input ref="fileInput" type="file" class="hidden" @change="onFileChosen">
          <button
            v-if="isFirst"
            type="button"
            class="flex size-11 shrink-0 items-center justify-center rounded-xl text-gray-500 transition-colors hover:bg-gray-100"
            aria-label="Прикрепить файл"
            @click="pickFile"
          >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48" />
            </svg>
          </button>
          <textarea
            v-model="message"
            rows="1"
            class="max-h-24 min-h-11 flex-1 resize-none rounded-2xl border border-gray-200 px-4 py-3 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
            placeholder="Сообщение"
            @keydown="onKeydown"
          />
          <button
            type="button"
            class="flex size-11 shrink-0 items-center justify-center rounded-xl transition-colors"
            :class="canSend ? 'bg-violet-500 text-white' : 'bg-gray-100 text-gray-400'"
            :disabled="!canSend"
            aria-label="Отправить"
            @click="send"
          >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="m22 2-7 20-4-9-9-4z" /><path d="M22 2 11 13" />
            </svg>
          </button>
        </div>
        <p v-if="charsLeft > 0 && message.trim()" class="mt-1.5 px-1 text-xs text-gray-400">
          Ещё хотя бы {{ charsLeft }} {{ charsLeft === 1 ? 'символ' : charsLeft < 5 ? 'символа' : 'символов' }} — опишите вопрос подробнее.
        </p>
      </div>
    </div>
  </div>
</template>
