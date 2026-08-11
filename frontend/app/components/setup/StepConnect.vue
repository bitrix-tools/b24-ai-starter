<script setup lang="ts">
import SplashBrandMark from '~/components/SplashBrandMark.vue'

const emit = defineEmits<{
  (e: 'next'): void
}>()

const tochkaStore = useTochkaStore()

const isConnecting = ref(false)
const isWaitingAuth = ref(false)
const pendingAuthUrl = ref<string | null>(null)
const checkInterval = ref<ReturnType<typeof setInterval> | null>(null)
const error = ref('')

function handleAuthSuccess() {
  isWaitingAuth.value = false
  isConnecting.value = false
  if (checkInterval.value) {
    clearInterval(checkInterval.value)
    checkInterval.value = null
  }
  emit('next')
}

async function openAuthWindow(authUrl: string) {
  const authWindow = window.open(authUrl, 'tochka_auth', 'width=600,height=700,scrollbars=yes')
  isWaitingAuth.value = true

  checkInterval.value = setInterval(async () => {
    if (authWindow?.closed) {
      if (checkInterval.value) clearInterval(checkInterval.value)
      checkInterval.value = null
      isConnecting.value = false
      isWaitingAuth.value = false

      await tochkaStore.fetchStatus()
      if (tochkaStore.status.connected) {
        handleAuthSuccess()
      } else {
        error.value = 'Авторизация не завершена. Нажмите «Подключить» ещё раз.'
      }
    }
  }, 500)
}

async function handleConnect() {
  error.value = ''
  isConnecting.value = true

  try {
    const authUrl = await tochkaStore.connectWithEnvCredentials({ popup: true })

    if (authUrl) {
      pendingAuthUrl.value = authUrl
      await openAuthWindow(authUrl)
    }
  } catch {
    error.value = tochkaStore.error || 'Не удалось подключиться к Точке'
    isConnecting.value = false
  }
}

function handleRetry() {
  error.value = ''
  if (pendingAuthUrl.value) {
    openAuthWindow(pendingAuthUrl.value)
  } else {
    handleConnect()
  }
}

function handleMessage(event: MessageEvent) {
  if (event.data?.type === 'tochka_connected' || event.data?.tochkaAuth === 'ok') {
    tochkaStore.fetchStatus().then(() => {
      if (tochkaStore.status.connected) {
        handleAuthSuccess()
      }
    })
  } else if (event.data?.type === 'tochka_error') {
    isWaitingAuth.value = false
    isConnecting.value = false
    if (checkInterval.value) clearInterval(checkInterval.value)
    error.value = decodeURIComponent(event.data.error || 'Ошибка авторизации')
  }
}

onMounted(() => {
  window.addEventListener('message', handleMessage)

  const urlParams = new URLSearchParams(window.location.search)
  const tochkaConnected = urlParams.get('tochka_connected')

  if (tochkaConnected === 'true' && window.name !== 'tochka_auth' && !window.opener) {
    window.history.replaceState({}, '', window.location.pathname)
    tochkaStore.fetchStatus().then(() => {
      if (tochkaStore.status.connected) {
        handleAuthSuccess()
      }
    })
  }
})

onUnmounted(() => {
  window.removeEventListener('message', handleMessage)
  if (checkInterval.value) clearInterval(checkInterval.value)
})
</script>

<template>
  <section class="setup-connect">
    <div class="setup-connect__panel">
      <div class="setup-connect__logo">
        <div class="setup-connect__logo-ring">
          <div
            v-if="isWaitingAuth"
            class="setup-connect__logo-spinner"
            aria-hidden="true"
          />
          <SplashBrandMark compact />
        </div>
      </div>

      <h1 class="setup-connect__title">Подключение Точка Банк</h1>

      <p v-if="isWaitingAuth" class="setup-connect__text">
        Завершите авторизацию в окне Точка Банк. Окно закроется автоматически после успешного подключения.
      </p>
      <p v-else class="setup-connect__text">
        Авторизуйтесь через сайт Точки
      </p>

      <p v-if="error" class="setup-connect__error">
        {{ error }}
      </p>

      <div class="setup-connect__actions">
        <button
          v-if="isWaitingAuth"
          type="button"
          class="setup-connect__btn setup-connect__btn--secondary"
          @click="handleRetry"
        >
          Открыть окно снова
        </button>
        <button
          v-else
          type="button"
          class="setup-connect__btn setup-connect__btn--primary"
          :disabled="isConnecting"
          @click="handleConnect"
        >
          {{ isConnecting ? 'Подключение...' : 'Подключить Точка Банк' }}
        </button>
      </div>

      <p v-if="!isWaitingAuth" class="setup-connect__hint">
        Вы будете перенаправлены на страницу авторизации Точка Банка
      </p>
    </div>
  </section>
</template>

<style scoped>
.setup-connect {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 4rem 1rem;
  min-height: 500px;
}

.setup-connect__panel {
  display: block;
  width: 560px;
  max-width: 100%;
  margin: 0 auto;
  text-align: center;
  box-sizing: border-box;
}

.setup-connect__logo {
  display: block;
  width: 100%;
  margin-bottom: 1.25rem;
}

.setup-connect__logo-ring {
  position: relative;
  display: inline-block;
}

.setup-connect__logo-spinner {
  position: absolute;
  inset: -4px;
  border: 2px solid #e5e7eb;
  border-top-color: #8b5cf6;
  border-radius: 1.35rem;
  animation: setup-connect-spin 0.8s linear infinite;
  pointer-events: none;
}

.setup-connect__title,
.setup-connect__text,
.setup-connect__error,
.setup-connect__hint {
  display: block;
  width: 100%;
  box-sizing: border-box;
  margin-left: auto;
  margin-right: auto;
  white-space: normal;
  word-break: normal;
  overflow-wrap: normal;
}

.setup-connect__title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #111827;
  margin-bottom: 0.5rem;
  line-height: 1.4;
}

.setup-connect__text {
  font-size: 0.875rem;
  color: #6b7280;
  line-height: 1.6;
  margin-bottom: 2rem;
}

.setup-connect__error {
  font-size: 0.875rem;
  color: #dc2626;
  background: #fef2f2;
  border-radius: 0.75rem;
  padding: 0.75rem 1.25rem;
  line-height: 1.6;
  margin-bottom: 1.5rem;
  text-align: center;
}

.setup-connect__actions {
  display: flex;
  justify-content: center;
  width: 100%;
  margin-bottom: 0.5rem;
}

.setup-connect__btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.625rem 2rem;
  font-size: 0.875rem;
  font-weight: 500;
  border-radius: 0.75rem;
  border: none;
  cursor: pointer;
  white-space: nowrap;
  transition: background-color 0.15s ease, opacity 0.15s ease;
}

.setup-connect__btn--primary {
  background: #8b5cf6;
  color: #fff;
}

.setup-connect__btn--primary:hover:not(:disabled) {
  background: #7c3aed;
}

.setup-connect__btn--primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.setup-connect__btn--secondary {
  background: #fff;
  color: #374151;
  border: 1px solid #e5e7eb;
}

.setup-connect__btn--secondary:hover {
  background: #f9fafb;
}

.setup-connect__hint {
  font-size: 0.75rem;
  color: #9ca3af;
  line-height: 1.6;
  margin-top: 2rem;
}

@keyframes setup-connect-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
