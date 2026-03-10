/**
 * telemetry.client.ts — Nuxt-плагин для автоматической фронтенд-телеметрии.
 *
 * Sprint 8, Step 8.6.
 *
 * Что делает:
 * 1. app_frame_loaded — однократно при монтировании приложения
 * 2. page_view        — при каждой навигации через router.afterEach()
 * 3. ui_error         — при необработанных JS ошибках (window.onerror + unhandledrejection)
 * 4. Сбрасывает очередь накопленных событий когда JWT становится доступен
 *
 * Ограничения:
 * - Работает только на клиенте (.client.ts суффикс)
 * - Зависит от useApiStore (tokenJWT) и useTelemetry composable
 * - Ошибки внутри плагина не прерывают работу приложения
 */

export default defineNuxtPlugin((nuxtApp) => {
  const router = useRouter()
  const apiStore = useApiStore()
  const { track, onJwtReady } = useTelemetry()

  // ------------------------------------------------------------------
  // 1. app_frame_loaded — одно событие при запуске приложения
  // ------------------------------------------------------------------
  nuxtApp.hook('app:mounted', () => {
    track('app_frame_loaded', {
      'ui.user_agent': navigator.userAgent.slice(0, 200),
    })
  })

  // ------------------------------------------------------------------
  // 2. page_view — при каждой навигации
  // ------------------------------------------------------------------
  router.afterEach((to, from) => {
    // Пропускаем первый переход при SSR-гидрации (from.name === undefined и initialLoad)
    if (from.name === undefined && to.name === from.name) return

    track('page_view', {
      'ui.path': to.path,
      'ui.route_name': (to.name as string) ?? '',
      ...(to.params && Object.keys(to.params).length > 0
        ? { 'ui.route_params': JSON.stringify(to.params).slice(0, 200) }
        : {}),
    })
  })

  // ------------------------------------------------------------------
  // 3. ui_error — необработанные JS-ошибки
  // ------------------------------------------------------------------
  window.addEventListener('error', (event: ErrorEvent) => {
    // Пропускаем ошибки загрузки ресурсов (img, script, link)
    if (event.target instanceof HTMLElement && event.target !== window) return

    track('ui_error', {
      'error.type': 'uncaught_exception',
      'error.message': (event.message ?? '').slice(0, 200),
      'error.filename': (event.filename ?? '').slice(0, 200),
    })
  })

  window.addEventListener('unhandledrejection', (event: PromiseRejectionEvent) => {
    const reason = event.reason

    const message = reason instanceof Error
      ? reason.message
      : typeof reason === 'string'
        ? reason
        : String(reason)

    track('ui_error', {
      'error.type': 'unhandled_rejection',
      'error.message': message.slice(0, 200),
    })
  })

  // ------------------------------------------------------------------
  // 4. Сброс очереди при готовности JWT
  // ------------------------------------------------------------------
  // Используем watch из Pinia/Vue; срабатывает когда isInitTokenJWT меняется на true
  watch(
    () => apiStore.isInitTokenJWT,
    async (ready) => {
      if (ready) {
        await onJwtReady()
      }
    },
    { immediate: true },
  )
})
