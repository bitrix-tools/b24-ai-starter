/**
 * useTelemetry — composable для отправки событий фронтенда на PHP-эндпоинт.
 *
 * Sprint 8, Step 8.5.
 *
 * Архитектура:
 * - Отправляет события на POST /api/telemetry/event (PHP-прокси → OTel Collector)
 * - Требует инициализированного JWT из apiStore
 * - Если JWT ещё не готов — буферизует до 10 событий и отправляет их после инициализации
 * - Fire-and-forget: ошибки логируются в console.warn, не бросаются наружу
 * - Каждая вкладка имеет уникальный X-Session-ID, хранящийся в sessionStorage
 *
 * Использование:
 * ```ts
 * const { track } = useTelemetry()
 *
 * track('page_view', { 'ui.path': '/crm/leads', 'ui.route_name': 'crm-leads' })
 * track('ui_button_click', { 'ui.component': 'btn-save' })
 * ```
 *
 * Допустимые event_name (whitelist из telemetry.yaml):
 * - page_view
 * - ui_button_click
 * - ui_form_submit
 * - ui_error
 * - app_frame_loaded
 */

const MAX_QUEUE_SIZE = 10
const SESSION_STORAGE_KEY = 'telemetry_session_id'

/**
 * Payload события для отправки на бэкенд.
 */
interface TelemetryEventPayload {
  event_name: string
  attributes?: Record<string, string>
  client_timestamp_ms?: number
}

/**
 * Singleton composable — состояние разделяется между всеми вызовами.
 */
const pendingQueue: TelemetryEventPayload[] = []
let sessionId: string | null = null

function getOrCreateSessionId(): string {
  if (sessionId) return sessionId

  if (typeof window !== 'undefined') {
    const stored = window.sessionStorage.getItem(SESSION_STORAGE_KEY)
    if (stored) {
      sessionId = stored
      return sessionId
    }
    // Генерируем новый UUID v4 для этой вкладки
    const id = crypto.randomUUID()
    window.sessionStorage.setItem(SESSION_STORAGE_KEY, id)
    sessionId = id
  } else {
    sessionId = 'ssr-no-session'
  }

  return sessionId
}

export const useTelemetry = () => {
  const apiStore = useApiStore()

  const config = useRuntimeConfig()
  const apiUrl = (config.public.apiUrl as string).replace(/\/$/, '')

  /**
   * Отправить одно событие напрямую (без буферизации).
   * Вызывается только когда JWT готов.
   */
  async function sendEvent(payload: TelemetryEventPayload): Promise<void> {
    const jwt = apiStore.tokenJWT
    if (!jwt || jwt.length < 3) return

    try {
      await $fetch(`${apiUrl}/api/telemetry/event`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${jwt}`,
          'X-Session-ID': getOrCreateSessionId(),
        },
        body: JSON.stringify(payload),
      })
    } catch (err) {
      // Телеметрия не должна прерывать работу UI
      if (import.meta.dev) {
        console.warn('[useTelemetry] Failed to send event:', payload.event_name, err)
      }
    }
  }

  /**
   * Сбросить очередь накопленных событий.
   * Вызывается после инициализации JWT.
   */
  async function flushQueue(): Promise<void> {
    while (pendingQueue.length > 0) {
      const event = pendingQueue.shift()
      if (event) {
        await sendEvent(event)
      }
    }
  }

  /**
   * Публичный API: отправить событие телеметрии.
   *
   * Отправка происходит только если NUXT_PUBLIC_TELEMETRY_ENABLED=true в окружении.
   *
   * @param eventName  Имя события (должно быть в whitelist)
   * @param attributes Дополнительные атрибуты (ключи: [a-z0-9._], значения: max 512 chars)
   */
  function track(
    eventName: string,
    attributes?: Record<string, string>,
  ): void {
    // Проверяем флаг включения телеметрии (из NUXT_PUBLIC_TELEMETRY_ENABLED).
    // Nuxt парсит env-значения через JSON.parse, поэтому 'true' → boolean true.
    // String(true) === 'true', String('true') === 'true' — оба варианта проходят.
    if (String(config.public.telemetryEnabled) !== 'true') return

    const payload: TelemetryEventPayload = {
      event_name: eventName,
      client_timestamp_ms: Date.now(),
      ...(attributes && Object.keys(attributes).length > 0 ? { attributes } : {}),
    }

    if (!apiStore.isInitTokenJWT) {
      // JWT ещё не готов — буферизуем (с ограничением размера очереди)
      if (pendingQueue.length < MAX_QUEUE_SIZE) {
        pendingQueue.push(payload)
      } else if (import.meta.dev) {
        console.warn('[useTelemetry] Queue full, dropping event:', eventName)
      }
      return
    }

    // JWT готов — отправляем сразу, fire-and-forget
    sendEvent(payload).catch(() => {
      // уже обработано внутри sendEvent
    })
  }

  /**
   * Вызвать после инициализации JWT чтобы отправить накопленные события.
   * Обычно вызывается из плагина telemetry.client.ts.
   */
  async function onJwtReady(): Promise<void> {
    await flushQueue()
  }

  return {
    track,
    onJwtReady,
  }
}
