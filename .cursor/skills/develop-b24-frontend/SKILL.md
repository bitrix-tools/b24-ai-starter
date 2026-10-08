---
name: develop-b24-frontend
description: Develop frontend applications for Bitrix24 using Nuxt 4, Bitrix24 UI Kit, and JS SDK. Use this skill when you need to create pages, components, or interact with Bitrix24 API from the frontend.
---

# Develop Bitrix24 Frontend

## Quick Start

The frontend is built with **Nuxt 4** (`ssr: false`, runs inside a Bitrix24 iframe) and uses **@bitrix24/b24ui-nuxt** (UI Kit) and **@bitrix24/b24jssdk-nuxt** (JS SDK).

### Stack versions

| Package | Version |
| --- | --- |
| `nuxt` | 4.6 (Node ≥ 22.22, the project uses Node 24) |
| `@bitrix24/b24jssdk`, `@bitrix24/b24jssdk-nuxt` | 3.x |
| `@bitrix24/b24ui-nuxt` | 2.14 |
| `@bitrix24/b24icons-vue` | 2.x |
| `pinia` / `@pinia/nuxt` | 4.x / 1.x |
| `@nuxtjs/i18n` | 10.x |
| `tailwindcss` | 4.x |
| `typescript` | 6.0 (TS 7 is not supported yet by `vue-tsc` and `typescript-eslint`) |
| `eslint` / `vitest` | 10 / 5 |

Package manager: **pnpm 12** (pinned via `packageManager` in `frontend/package.json`).

### Commands (run in `frontend/`)

```bash
pnpm install --frozen-lockfile
pnpm lint        # ESLint (@nuxt/eslint)
pnpm typecheck   # nuxt typecheck (vue-tsc)
pnpm test        # Vitest unit tests (frontend/test/)
pnpm build
```

CI (`.github/workflows/ci.yml`) runs exactly these steps — run them before committing.

### Key Directories

* `frontend/app/pages/`: Application pages (must end with `.client.vue` for client-side rendering).
* `frontend/app/layouts/`: Page layouts (`default.vue` wraps `B24SidebarLayout`; also `placement.vue`, `slider.vue`, `uf-placement.vue`).
* `frontend/app/components/`: Reusable components (e.g., `BackendStatus.vue`, `Logo.vue`).
* `frontend/app/stores/`: Pinia stores (`api`, `appSettings`, `userSettings`, `user`, `page`).
* `frontend/app/composables/`: Shared logic (`useAppInit`, `useBackend`, `useTelemetry`).
* `frontend/app/middleware/`: Route middleware (e.g., page/slider detection).
* `frontend/app/assets/css/main.css`: Single entry CSS (`@import "tailwindcss"; @import "@bitrix24/b24ui-nuxt";`).
* `frontend/server/routes/`: Nitro server routes (e.g., `install.post.ts`).
* `frontend/shared/types/`: Shared TypeScript types.
* `frontend/i18n/`: `locales/*.json` and `i18n.map.ts`.
* `frontend/test/`: Vitest unit tests.

## App shell (already in place — do not duplicate)

* `app/app.vue` renders `<B24App :locale>` → `<B24DashboardGroup>` → `<NuxtLayout>` → `<NuxtPage>` for the whole app. **Never** add `<B24App>` to a page, layout or component — nesting it is a bug.
* Layouts already contain `<B24SidebarLayout>`; pages only render their content.

| Layout | Used by | How it is selected |
| --- | --- | --- |
| `default` | standalone app pages (`index`, `telemetry-test`) | nothing (default) |
| `placement` | widget pages, e.g. `handler/placement-crm-deal-detail-tab` | `definePageMeta({ layout: 'placement' })` |
| `uf-placement` | user-field type handler `handler/uf.demo` | `definePageMeta({ layout: 'uf-placement' })` |
| `slider` | slider pages, e.g. `slider/app-options` (header from `usePageStore`, `#footer` slot for buttons) | `definePageMeta({ layout: false })` + `<NuxtLayout name="slider">` in the template, so the page can fill its named slots |

`install.client.vue` uses the default layout but renders a full-screen progress screen.

## Page pattern

Every Bitrix24 page follows the same skeleton (see `app/pages/index.client.vue`, `handler/*.client.vue`, `slider/app-options.client.vue`):

```vue
<script setup lang="ts">
import type { B24Frame } from '@bitrix24/b24jssdk'

definePageMeta({ layout: 'placement' }) // omit for the default layout

const { t, locales: localesI18n, setLocale } = useI18n()

// region Init ////
const { $logger, initApp, processErrorGlobal, b24Helper, destroyB24Helper } = useAppInit('MyPage')
const { $initializeB24Frame } = useNuxtApp()
let $b24: null | B24Frame = null

const apiStore = useApiStore()
const { track } = useTelemetry()
// endregion ////

const isLoading = ref(false)
const isInit = ref(false)
const items = ref<string[]>([])

async function reload() {
  track('ui_button_click', { 'ui.button_id': 'my_page_reload' })
  items.value = await apiStore.getList()
}

onMounted(async () => {
  try {
    isLoading.value = true
    $b24 = await $initializeB24Frame()
    await initApp($b24, localesI18n, setLocale) // locale, B24 helper, stores, JWT (apiStore.init)
    await $b24.parent.setTitle(t('page.myPage.seo.title'))

    items.value = await apiStore.getList()
    $logger.info('Hi from my page')
    isInit.value = true
  } catch (error) {
    processErrorGlobal(error) // logs + showError() -> app/error.vue
  } finally {
    isLoading.value = false
  }
})

onUnmounted(() => {
  if (b24Helper.value) {
    destroyB24Helper()
  }
})
</script>

<template>
  <B24Card v-if="isInit">
    <B24Button :label="$t('page.myPage.action.reload')" loading-auto @click="reload" />
    <B24Table :data="items.map(name => ({ name }))" :loading="isLoading" />
  </B24Card>
</template>
```

Notes:

* `$b24` is obtained inside `onMounted` (or with top-level `await`, as `install.client.vue` does); never call it before `$initializeB24Frame()` resolves.
* `useAppInit()` also returns `moduleId`, `reloadData`, `initLang`, `usePullClient`, `useSubscribePullClient`, `startPullClient` (pull events — see `handler/uf.demo` and `slider/app-options`).
* `install.client.vue` calls only `initLang` (there is no JWT before installation), runs the steps (`app.info`/`placement.get` batch → `placement.bind` → `userfieldtype.add|update` → `apiStore.postInstall` → `$b24.installFinish()`).
* Placement pages resize the frame: `$b24.parent.fitWindow()` / `$b24.parent.resizeWindowAuto()`.
* For non-fatal errors (e.g. a failed save) show a toast: `useToast().add({ title, description, color: 'air-primary-alert' })`.

## Routing and placements

* File-based routes in `app/pages/`; every page file ends with `.client.vue` (`ssr: false`).
* Handlers registered in Bitrix24 point to `${appUrl}/handler/<name>`; put those pages in `app/pages/handler/` and register them in the install steps (`placement.bind`, `userfieldtype.add`).
* `app/middleware/01.app.page.or.slider.global.ts` runs on every navigation: initializes the frame and, if `$b24.placement.options.place === 'app-options'`, redirects to `/slider/app-options`. Add new `place` → route mappings there. Slider pages are opened with `$b24.slider.openSliderAppPage({ place: 'app-options', bx24_width: 650 })`.

## Bitrix24 UI Kit

**ALWAYS** use `B24` prefixed components from `@bitrix24/b24ui-nuxt` (and `Prose*` for typography: `ProseH2`, `ProseP`, …). Do NOT use raw HTML controls or other UI libraries if a B24 component exists.

* **Buttons**: `<B24Button color="air-primary" loading-auto />` (`loading-auto` shows a spinner while the async `@click` handler runs)
* **Inputs**: `<B24Input />`, `<B24InputNumber />`, `<B24Select />`, `<B24SelectMenu />`, `<B24InputMenu />`, `<B24Switch />`, `<B24Textarea />` inside `<B24FormField>`
* **Layout / data**: `<B24Card>`, `<B24Accordion>`, `<B24Table>`, `<B24Separator>`
* **Feedback**: `useToast()` (the toaster is provided by `B24App`), `<B24Alert />`, `<B24Advice />`, `<B24Modal />`
* Colors are `air-*` tokens (`air-primary`, `air-primary-success`, `air-primary-alert`, `air-secondary`, `air-tertiary`); style with Tailwind 4 and `--ui-*` CSS variables, overrides via the `b24ui` prop.
* Icons: default imports from `@bitrix24/b24icons-vue/<group>/<Name>Icon`, passed as `:icon="PlusLIcon"`.

## Bitrix24 JS SDK

> The canonical API is `$b24.actions.v{2,3}.*.make()`. The legacy helpers
> `callMethod` / `callBatch` / `callListMethod` / `fetchListMethod` are deprecated.

```typescript
// Single method
const response = await $b24.actions.v2.call.make({ method: 'crm.deal.get', params: { id: 123 } })
if (!response.isSuccess) {
  throw new Error(response.getErrorMessages().join('; '))
}
const deal = response.getData()?.result

// Batch method
const batch = await $b24.actions.v2.batch.make({
  calls: {
    appInfo: { method: 'app.info' },
    placementList: { method: 'placement.get' }
  }
})
const data = batch.getData()
```

### Logging

SDK 3 removed `LoggerBrowser`. In pages use `$logger` from `useAppInit('PageName')`; elsewhere create one with `LoggerFactory`:

```typescript
import { LoggerFactory } from '@bitrix24/b24jssdk'

const $logger = LoggerFactory.createForBrowser('MyComposable', import.meta.dev)

$logger.debug('Init data', { data })
$logger.info('Hi from page')
$logger.warning('Locale is not supported', { lang })
$logger.error('Request failed', { error })
```

Methods: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` — `(message, context?)` where context is an object. There is no `log()` / `warn()`.

## Stores and backend calls

* Pinia setup stores in `app/stores/` (auto-imported): `useApiStore`, `useAppSettingsStore` / `useUserSettingsStore` (`configSettings` + `saveSettings()` → `app.option.set` / `user.option.set`), `useUserStore` (`id`, `login`, `isAdmin`), `usePageStore` (`title`, `description` shown by the `slider` layout).
* **Backend calls go through `useApiStore`** — never raw `$fetch` in pages. `initApp()` calls `apiStore.init($b24)`, which posts the auth data to `/api/getToken` and keeps the JWT in `tokenJWT`. To add an endpoint, add a method next to `getList` in `app/stores/api.ts` that calls `$api('/api/…', { headers: authHeaders() })` and export it from the store.
* `useBackend()` wraps the health check (`BackendStatus.vue`).
* **Telemetry**: `const { track } = useTelemetry(); track('ui_button_click', { 'ui.button_id': 'save' })`. Fire-and-forget; enabled by `NUXT_PUBLIC_TELEMETRY_ENABLED=true`; events are queued until the JWT is ready. Allowed event names are whitelisted by the PHP backend (`page_view`, `ui_button_click`, `ui_select_change`, `ui_form_submit`, `ui_error`, `app_frame_loaded`, `b24_api_call`); attribute values must be strings. `plugins/telemetry.client.ts` already sends `app_frame_loaded`, `page_view` and `ui_error`.

## i18n

* All UI strings via `t('…')` in script / `$t('…')` in templates. Keys live in `frontend/i18n/locales/<code>.json`, grouped as `page.<name>.*`, `components.<name>.*`, etc.
* Locales are listed in `frontend/i18n/i18n.map.ts` (19: `en`, `de`, `la`, `br`, `fr`, `it`, `pl`, `ru`, `ua`, `tr`, `sc`, `tc`, `ja`, `vn`, `id`, `ms`, `th`, `ar`, `kz`). `en.json` is the source of truth and the fallback locale; add every new key to `en.json` and `ru.json` at least (missing keys fall back to English). The locale is set from `$b24.getLang()` by `initApp`/`initLang`.
* Known gap: the `translate-ui` script in `package.json` points to `tools/translate.ui.ts`, which does not exist — translate other locales manually.

## Code style and tests

* Nuxt 4 layout: app code in `frontend/app/`, `~/` = `app/` (`~/stores/page`, `~/utils/sleep`); shared types via `#shared/types/...`. Components, composables, stores and `app/utils/*` are auto-imported; `vue` APIs too.
* ESLint (`@nuxt/eslint`): `@typescript-eslint/no-explicit-any` is an error outside a few grandfathered files — use `unknown` and narrow; component names must be multi-word.
* Unit tests: Vitest, plain Node environment (`frontend/vitest.config.ts`, `test/**/*.{test,spec}.ts`, example `test/units.spec.ts`). Test framework-free helpers in `app/utils/` (import by relative path); Nuxt-runtime components/stores are not covered (no `@nuxt/test-utils`).

## Best Practices

1. **Client-only**: All pages must be `.client.vue` as the app runs in an iframe.
2. **No extra shells**: `B24App` and `B24SidebarLayout` come from `app.vue` and the layouts.
3. **Error Handling**: `try/catch` + `processErrorGlobal(error)` for fatal init errors; `useToast()` for recoverable ones; log with `$logger.error(msg, { error })`.
4. **Loading state**: keep it in a local `ref(false)` and pass to `:loading` (or use `loading-auto` on buttons). `useDashboard()` from b24ui no longer provides `isLoading`/`load`.
5. **Placement options** are typed as `unknown` values — convert explicitly (`String($b24.placement.options?.VALUE ?? '')`).
6. **Docs**: detailed guides live in `instructions/front/` (`knowledge.md`, `bitrix24-js-sdk.md`, component recipes). SDK v2→v3 migration: <https://bitrix24.github.io/b24jssdk/docs/getting-started/migration/v3/>
