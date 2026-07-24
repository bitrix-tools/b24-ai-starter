---
name: develop-b24-frontend
description: Develop frontend applications for Bitrix24 using Nuxt 4, Bitrix24 UI Kit, and JS SDK. Use this skill when you need to create pages, components, or interact with Bitrix24 API from the frontend.
---

# Develop Bitrix24 Frontend

## Quick Start

The frontend is built with **Nuxt 4** (`ssr: false`, runs inside a Bitrix24 iframe) and uses **@bitrix24/b24ui-nuxt** (UI Kit) and **@bitrix24/b24jssdk-nuxt** (JS SDK).

### Key Directories

*   `frontend/app/pages/`: Application pages (must end with `.client.vue` for client-side rendering).
*   `frontend/app/layouts/`: Page layouts (`default.vue` wraps `B24SidebarLayout`; also `placement.vue`, `slider.vue`, `uf-placement.vue`).
*   `frontend/app/components/`: Reusable components (e.g., `BackendStatus.vue`, `Logo.vue`).
*   `frontend/app/stores/`: Pinia stores (`api`, `appSettings`, `userSettings`, `user`, `page`).
*   `frontend/app/composables/`: Shared logic (`useAppInit`, `useBackend`, `useTelemetry`).
*   `frontend/app/middleware/`: Route middleware (e.g., page/slider detection).
*   `frontend/app/assets/css/main.css`: Single entry CSS (`@import "tailwindcss"; @import "@bitrix24/b24ui-nuxt";`).
*   `frontend/server/routes/`: Nitro server routes (e.g., `install.post.ts`).
*   `frontend/shared/types/`: Shared TypeScript types.
*   `frontend/i18n/`: Locales and i18n map.

## Bitrix24 UI Kit

**ALWAYS** use `B24` prefixed components from `@bitrix24/b24ui-nuxt`. Do NOT use standard HTML elements or other UI libraries if a B24 component exists.

### Common Components

```vue
<template>
  <B24App> <!-- Mandatory wrapper -->
    <B24Card>
      <template #header>
        <h3 class="text-lg font-semibold">Title</h3>
      </template>
      
      <B24Form :state="state" @submit="onSubmit">
        <B24FormField label="Name" name="name" required>
          <B24Input v-model="state.name" />
        </B24FormField>
        
        <B24Button type="submit" color="air-primary" :loading="isLoading">
          Save
        </B24Button>
      </B24Form>
    </B24Card>
  </B24App>
</template>
```

*   **Buttons**: `<B24Button color="air-primary" />`
*   **Inputs**: `<B24Input />`, `<B24Select />`, `<B24Textarea />`
*   **Layout**: `<B24Card>`, `<B24Container>`, `<B24SidebarLayout>`
*   **Feedback**: `<B24Toast />` (use `useToast()`), `<B24Modal />`, `<B24Alert />`

## Bitrix24 JS SDK

Use the SDK to interact with Bitrix24.

### Initialization

```typescript
// In a component or composable
const { $initializeB24Frame } = useNuxtApp()
const $b24 = await $initializeB24Frame()
```

### API Calls

```typescript
// Single method
const result = await $b24.callMethod('crm.deal.get', { id: 123 })

// Batch method
const batch = await $b24.callBatch({
  deals: { method: 'crm.deal.list', params: { select: ['ID', 'TITLE'] } },
  users: { method: 'user.get', params: { ID: 1 } }
})
const data = batch.getData()
```

### UI Interaction

```typescript
// Open slider
const url = $b24.slider.getUrl('/crm/deal/details/123/')
await $b24.slider.openPath(url)

// Select user
const user = await $b24.dialog.selectUser()
```

## State Management (Pinia)

Use stores for API interaction and global state.

```typescript
// stores/api.ts
const apiStore = useApiStore()
await apiStore.init($b24) // Initialize with B24 frame
const data = await apiStore.getList() // Call backend API
```

## Backend Interaction

*   **Frontend -> Backend**: Use `$api` (wrapper around `$fetch` with JWT).
*   **Authentication**: Handled automatically by `apiStore` and `useAppInit`.
*   **Base URL**: Proxied in dev, relative in prod.

## Best Practices

1.  **Client-only**: All pages must be `.client.vue` as the app runs in an iframe.
2.  **B24App**: Always wrap the root of your page/layout in `<B24App>`.
3.  **Error Handling**: Use `try/catch` and `useToast()` to show errors.
4.  **Icons**: Import from `@bitrix24/b24icons-vue`.
