<script setup lang="ts">
/**
 * Виджет поддержки в правом нижнем углу (по схеме других приложений 1daa.ru,
 * навык b24-app-ui §13) — мессенджер-стиль. Открывается пунктом меню
 * «Поддержка». Обращение уходит на бэкенд и пересылается в общий Telegram-канал;
 * контакт спрашиваем вторым шагом, уже после отправки.
 */

const isOpen = defineModel<boolean>('open', { default: false })

const api = useApiStore()
const user = useUserStore()

const message = ref('')
const contact = ref('')
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const isSending = ref(false)
const isSent = ref(false)
const isContactSaved = ref(false)
const ticketId = ref('')
const error = ref('')

const MAX_FILE_BYTES = 10 * 1024 * 1024

const canSend = computed(() => message.value.trim().length >= 10 && !isSending.value)
const greetingName = computed(() => (user.login || '').trim().split(' ')[0] || '')

function close() {
  isOpen.value = false
  setTimeout(() => {
    isSent.value = false
    isContactSaved.value = false
    ticketId.value = ''
    error.value = ''
  }, 200)
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
  if (!canSend.value) return
  error.value = ''
  isSending.value = true
  try {
    const created = await api.sendSupportMessage({
      message: message.value,
      userName: user.login,
      file: file.value
    })
    ticketId.value = created.id
    message.value = ''
    dropFile()
    isSent.value = true
  } catch (e: any) {
    error.value = e?.data?.error || 'Не удалось отправить обращение. Напишите на info@1daa.ru'
  } finally {
    isSending.value = false
  }
}

async function saveContact() {
  if (!contact.value.trim() || !ticketId.value) return
  isSending.value = true
  try {
    await api.addSupportContact(ticketId.value, contact.value)
    isContactSaved.value = true
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
              1daa.ru
              <span class="font-normal normal-case text-gray-500">/ Битрикс24</span>
            </div>
          </div>
          <button
            type="button"
            class="flex size-7 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100"
            aria-label="Закрыть"
            @click="close"
          >
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

      <!-- Тело -->
      <div class="flex min-h-0 flex-1 flex-col justify-center overflow-y-auto px-5 py-5">
        <template v-if="isSent">
          <div class="py-6 text-center">
            <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-green-50 text-green-600">
              <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
            </div>
            <div class="font-semibold">Обращение отправлено</div>
            <p class="mt-1 text-sm text-gray-500">Осталось понять, куда ответить.</p>
          </div>

          <template v-if="!isContactSaved">
            <label class="mb-1 block text-sm font-medium">Почта или телефон</label>
            <input
              v-model="contact"
              class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="ivan@example.ru"
              @keydown.enter="saveContact"
            >
            <p class="mt-2 text-xs leading-relaxed text-gray-400">
              Без контакта ответим в чате портала — это дольше.
            </p>
            <div class="mt-3 flex gap-2">
              <button
                type="button"
                class="rounded-xl bg-violet-500 px-5 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                :disabled="!contact.trim() || isSending"
                @click="saveContact"
              >Отправить контакт</button>
              <button
                type="button"
                class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold transition-colors hover:bg-gray-50"
                :disabled="isSending"
                @click="close"
              >Не нужно</button>
            </div>
          </template>

          <p v-else class="text-center text-sm text-gray-500">Контакт записан — ответим на него.</p>
        </template>

        <template v-else>
          <div class="text-center">
            <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-violet-100 text-violet-600">
              <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
              </svg>
            </div>
            <div class="font-semibold">
              Здравствуйте<template v-if="greetingName">, {{ greetingName }}</template>
            </div>
            <p class="mt-1 text-sm text-gray-500">
              Напишите, что нужно сделать или что не работает. Ответим на указанный контакт.
            </p>
          </div>
          <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>
        </template>
      </div>

      <!-- Поле ввода -->
      <div v-if="!isSent" class="shrink-0 border-t border-gray-100 px-4 py-3">
        <div v-if="file" class="mb-2 flex items-center justify-between gap-2 rounded-xl bg-gray-100 px-3 py-2 text-xs">
          <span class="truncate">{{ file.name }} · {{ humanSize(file.size) }}</span>
          <button type="button" class="shrink-0 text-gray-500 hover:text-red-600" aria-label="Убрать файл" @click="dropFile">✕</button>
        </div>
        <div class="flex items-end gap-2">
          <input ref="fileInput" type="file" class="hidden" @change="onFileChosen">
          <button
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
      </div>

      <div v-else class="shrink-0 border-t border-gray-100 px-4 py-3">
        <button
          type="button"
          class="w-full rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold transition-colors hover:bg-gray-50"
          @click="close"
        >Закрыть</button>
      </div>
    </div>
  </div>
</template>
