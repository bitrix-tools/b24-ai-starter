<script setup lang="ts">
/**
 * Виджет поддержки в правом нижнем углу (по схеме других приложений 1daa.ru,
 * навык b24-app-ui §13). Обращение уходит на бэкенд и пересылается в общий
 * Telegram-канал поддержки. Вложений нет намеренно: в скриншоте из Битрикс24
 * почти всегда персональные данные клиентов портала — для таких случаев почта.
 */

const isOpen = defineModel<boolean>('open', { default: false })

const api = useApiStore()
const user = useUserStore()

const message = ref('')
const contact = ref('')
const isSending = ref(false)
const isSent = ref(false)
const error = ref('')

const canSend = computed(() => message.value.trim().length >= 10 && !isSending.value)
const greetingName = computed(() => (user.login || '').trim().split(' ')[0] || '')

function toggle() {
  isOpen.value = !isOpen.value
}

function close() {
  isOpen.value = false
  setTimeout(() => {
    isSent.value = false
    error.value = ''
  }, 200)
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
  <div class="fixed bottom-4 right-4 z-50 flex flex-col items-end gap-3">
    <!-- Панель -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div
        v-if="isOpen"
        class="flex w-[23rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-gray-200"
        style="max-height: min(44rem, calc(100vh - 6rem))"
      >
        <!-- Шапка -->
        <div class="flex items-start justify-between gap-3 bg-gray-900 px-5 py-4 text-white">
          <div class="min-w-0">
            <div class="text-xs text-white/60">1daa.ru · Битрикс24</div>
            <div class="text-base font-semibold">Поддержка</div>
            <div class="mt-0.5 text-xs text-white/70">Точка Банк. Интеграция</div>
          </div>
          <button
            type="button"
            class="flex size-8 shrink-0 items-center justify-center rounded-full text-white/70 transition-colors hover:bg-white/10 hover:text-white"
            aria-label="Закрыть"
            @click="close"
          >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18 6 6 18" /><path d="m6 6 12 12" />
            </svg>
          </button>
        </div>

        <!-- Тело -->
        <div class="flex-1 overflow-y-auto px-5 py-4">
          <template v-if="!isSent">
            <p class="text-sm text-gray-600">
              <template v-if="greetingName">{{ greetingName }}, напишите</template>
              <template v-else>Напишите</template>, с чем помочь — ответим и разберёмся.
            </p>

            <textarea
              v-model="message"
              rows="5"
              class="mt-3 w-full resize-none rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="Опишите вопрос или проблему…"
              @keydown="onKeydown"
            />

            <label class="mt-3 block text-xs font-medium text-gray-500">Как с вами связаться (необязательно)</label>
            <input
              v-model="contact"
              class="mt-1 w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
              placeholder="Почта или телефон"
            >

            <p v-if="error" class="mt-2 text-xs text-red-600">{{ error }}</p>

            <button
              type="button"
              class="mt-4 w-full rounded-xl bg-violet-500 px-5 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
              :disabled="!canSend"
              @click="send"
            >
              <span v-if="isSending">Отправляем…</span>
              <span v-else>Отправить</span>
            </button>
            <p class="mt-2 text-center text-xs text-gray-400">
              Или на <a href="mailto:info@1daa.ru" class="text-violet-600 hover:underline">info@1daa.ru</a>
            </p>
          </template>

          <!-- Успех -->
          <div v-else class="py-6 text-center">
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
              @click="close"
            >
              Готово
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Круглая кнопка -->
    <button
      type="button"
      class="flex size-14 items-center justify-center rounded-full bg-violet-500 text-white shadow-lg transition-transform hover:scale-105"
      :aria-label="isOpen ? 'Свернуть поддержку' : 'Открыть поддержку'"
      @click="toggle"
    >
      <svg v-if="!isOpen" viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
      </svg>
      <svg v-else viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 6 6 18" /><path d="m6 6 12 12" />
      </svg>
    </button>
  </div>
</template>
