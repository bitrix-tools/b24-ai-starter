import { LoggerFactory } from '@bitrix24/b24jssdk'
import type { B24Frame } from '@bitrix24/b24jssdk'
import type { RouteLocationNormalized } from 'vue-router'

const $logger = LoggerFactory.createForBrowser(
  'middleware:app.page.or.slider.global',
  import.meta.dev
)

const baseDir = '/'

/**
 * Routes that are opened outside Bitrix24 (no B24Frame) — e.g. a public EULA page.
 * The starter has none yet; add a prefix here when you create such a page.
 */
const SKIP_B24_PREFIXES = [`${baseDir}eula`, `${baseDir}render`]

function isSkipB24(toPath: string): boolean {
  return SKIP_B24_PREFIXES.some(prefix => toPath.startsWith(prefix))
}

export default defineNuxtRouteMiddleware(async (
  to: RouteLocationNormalized,
  from: RouteLocationNormalized
) => {
  const isUseB24Frame = useState('isUseB24Frame', () => true)

  /**
   * @memo skip middleware on server
   */
  if (import.meta.server) {
    return
  }

  $logger.debug('start', {
    to: to.path,
    from: from.path
  })

  if (isSkipB24(to.path)) {
    isUseB24Frame.value = false
    $logger.debug('skip')
    return Promise.resolve()
  }

  try {
    const { $initializeB24Frame } = useNuxtApp()
    const $b24: B24Frame = await $initializeB24Frame()

    $logger.debug('placement.options', { options: $b24.placement.options })
    if ($b24.placement.options?.place) {
      const optionsPlace = String($b24.placement.options.place)
      let goTo: null | string = null

      if (optionsPlace === 'app-options') {
        goTo = `${baseDir}slider/app-options`
      }

      if (
        null !== goTo
        && to.path !== goTo
      ) {
        $logger.debug('redirect', { goTo })
        return navigateTo(goTo)
      }
    }

    $logger.debug('stop')
  } catch (error: any) {
    const appError = createError({
      statusCode: 404,
      statusMessage: error?.message || error,
      data: { description: 'Problem in middleware' },
      cause: error,
      fatal: true
    })

    $logger.error('Problem in middleware', { error: appError })

    showError(appError)
    return Promise.reject(appError)
  }
})
