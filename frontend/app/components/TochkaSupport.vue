<script setup lang="ts">
/**
 * Раздел «Поддержка» — inline-форма (по схеме других приложений 1daa.ru,
 * навык b24-app-ui §13). Обращение уходит на бэкенд и пересылается в общий
 * Telegram-канал поддержки. Вложений нет намеренно: в скриншоте из Битрикс24
 * почти всегда персональные данные клиентов портала — для таких случаев почта.
 */

const api = useApiStore()
const user = useUserStore()

const message = ref('')
const contact = ref('')
const isSending = ref(false)
const isSent = ref(false)
const error = ref('')

const canSend = computed(() => message.value.trim().length >= 10 && !isSending.value)
const greetingName = computed(() => (user.login || '').trim().split(' ')[0] || '')

function reset() {
  isSent.value = false
  error.value = ''
}

async function send() {
  if (!canSend.value) return
  error.value = ''
  isSending.value = true
  try {
    await api.sendSupportMessage({
      message: message.value,
      userName: user.login,
      contact: contact.value
    })
    message.value = ''
    contact.value = ''
    isSent.value = true
  } catch (e: any) {
    error.value = e?.data?.error || 'Не удалось отправить обращение. Напишите на info@1daa.ru'
  } finally {
    isSending.value = false
  }
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
    event.preventDefault()
    send()
  }
}
</script>

<template>
  <div class="mx-auto max-w-[1200px]">
    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <!-- Форма -->
      <div class="overflow-hidden rounded-2xl border border-gray-100">
        <div class="bg-gray-900 px-6 py-5 text-white">
          <div class="text-xs text-white/60">1daa.ru · Битрикс24</div>
          <div class="text-lg font-semibold">Поддержка</div>
          <div class="mt-0.5 text-sm text-white/70">Точка Банк. Интеграция</div>
        </div>

        <div class="px-6 py-5">
          <template v-if="!isSent">
            <p class="text-sm text-gray-600">
              <template v-if="greetingName">{{ greetingName }}, напишите</template>
              <template v-else>Напишите</template>, с чем помочь — ответим и разберёмся.
            </p>

            <label class="mt-4 block text-sm font-medium text-gray-700">Сообщение</label>
            <textarea
              v-model="message"
              rows="6"
              class="mt-1 w-full resize-none rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="Опишите вопрос или проблему…"
              @keydown="onKeydown"
            />

            <label class="mt-3 block text-sm font-medium text-gray-700">Как с вами связаться (необязательно)</label>
            <input
              v-model="contact"
              class="mt-1 w-full max-w-[28rem] rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="Почта или телефон"
            >

            <p v-if="error" class="mt-2 text-sm text-red-600">{{ error }}</p>

            <div class="mt-4 flex items-center gap-3">
              <button
                type="button"
                class="rounded-xl bg-violet-500 px-6 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                :disabled="!canSend"
                @click="send"
              >
                <span v-if="isSending">Отправляем…</span>
                <span v-else>Отправить</span>
              </button>
              <span class="text-xs text-gray-400">Ctrl+Enter — отправить</span>
            </div>
          </template>

          <!-- Успех -->
          <div v-else class="py-8 text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-green-50 text-green-600">
              <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
            </div>
            <div class="mt-3 text-base font-semibold">Обращение отправлено</div>
            <p class="mt-1 text-sm text-gray-500">Мы ответим на указанный контакт или в чате портала.</p>
            <button
              type="button"
              class="mt-4 rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold transition-colors hover:bg-gray-50"
              @click="reset"
            >
              Написать ещё
            </button>
          </div>
        </div>
      </div>

      <!-- На связи разработчик -->
      <aside class="h-fit rounded-2xl bg-gray-900 px-5 py-5 text-white">
        <div class="mb-3 flex size-9 items-center justify-center rounded-xl bg-white/10">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
          </svg>
        </div>
        <div class="text-base font-semibold">На связи разработчик</div>
        <p class="mt-1 text-sm text-white/70">
          Поможем с настройкой, разберём ошибку, обсудим вашу задачу. Отвечаем в рабочее время.
        </p>
        <a
          href="mailto:info@1daa.ru"
          class="mt-4 inline-block text-sm font-semibold text-white/90 hover:text-white"
        >
          info@1daa.ru →
        </a>
      </aside>
    </div>
  </div>
</template>
