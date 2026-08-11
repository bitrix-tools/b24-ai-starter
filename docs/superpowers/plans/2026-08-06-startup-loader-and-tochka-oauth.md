# Startup Loader and Tochka OAuth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the startup splash with a centered Tochka loader and remove the OAuth redirect mismatch by registering the production callback with Tochka.

**Architecture:** Keep `AppLaunchSplash.vue` as the shared startup boundary already used by setup and dashboard. Replace its current oversized brand block and one-shot swoosh with the compact square brand mark plus two CSS-only orbit rings. OAuth code remains unchanged because it already emits the correct production callback; the matching URI must be registered against the production Tochka OAuth client.

**Tech Stack:** Nuxt 4, Vue 3, scoped CSS, existing `SplashBrandMark.vue`, Django configuration, Tochka OAuth 2.0.

---

### Task 1: Replace the startup splash animation

**Files:**
- Modify: `frontend/app/components/AppLaunchSplash.vue`
- Reuse: `frontend/app/components/SplashBrandMark.vue`

- [ ] **Step 1: Capture the current validation baseline**

Run:

```bash
cd frontend
pnpm lint app/components/AppLaunchSplash.vue app/components/SplashBrandMark.vue
```

Expected: the current files pass lint. Record any pre-existing diagnostics separately.

- [ ] **Step 2: Replace the splash template**

Use the compact 112×112 brand mark and add two decorative rings:

```vue
<script setup lang="ts">
import SplashBrandMark from '~/components/SplashBrandMark.vue'
</script>

<template>
  <div
    class="launch-loader"
    role="status"
    aria-live="polite"
    aria-label="Загрузка приложения"
  >
    <div class="launch-loader__orbit">
      <div class="launch-loader__ring launch-loader__ring--outer" aria-hidden="true" />
      <div class="launch-loader__ring launch-loader__ring--inner" aria-hidden="true" />
      <SplashBrandMark compact />
    </div>

    <p class="launch-loader__label">
      Загрузка приложения…
    </p>
  </div>
</template>
```

- [ ] **Step 3: Implement the two-ring animation**

Replace the existing scoped styles with:

```css
.launch-loader {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  user-select: none;
}

.launch-loader__orbit {
  position: relative;
  display: flex;
  width: 9rem;
  height: 9rem;
  align-items: center;
  justify-content: center;
}

.launch-loader__ring {
  position: absolute;
  border-style: solid;
  border-color: transparent;
  border-radius: 9999px;
  pointer-events: none;
}

.launch-loader__ring--outer {
  inset: 0;
  border-width: 3px;
  border-top-color: #7f42e1;
  border-right-color: rgb(127 66 225 / 35%);
  animation: launch-loader-spin 1.15s linear infinite;
}

.launch-loader__ring--inner {
  inset: 0.5rem;
  border-width: 2px;
  border-bottom-color: #ffdd2d;
  border-left-color: rgb(255 221 45 / 45%);
  animation: launch-loader-spin-reverse 1.6s linear infinite;
}

.launch-loader__label {
  margin: 0;
  color: #6b7280;
  font-size: 0.875rem;
  line-height: 1.4;
}

@keyframes launch-loader-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes launch-loader-spin-reverse {
  to {
    transform: rotate(-360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .launch-loader__ring {
    animation: none;
  }
}
```

- [ ] **Step 4: Run focused lint**

Run:

```bash
cd frontend
pnpm lint app/components/AppLaunchSplash.vue app/components/SplashBrandMark.vue
```

Expected: exit code 0 and no new diagnostics.

- [ ] **Step 5: Build the production frontend**

Run:

```bash
cd frontend
pnpm build
```

Expected: Nuxt production build completes successfully.

### Task 2: Verify startup behavior visually

**Files:**
- Verify: `frontend/app/pages/index.client.vue:125-160`
- Verify: `frontend/app/pages/dashboard.client.vue:1908-1912`

- [ ] **Step 1: Start the existing development stack**

Run from the repository root:

```bash
make dev-python
```

Expected: frontend, Python API, database, and tunnel containers become healthy.

- [ ] **Step 2: Verify the setup startup state**

Open the setup route in a browser and reload it. Confirm:

- the purple square logo is 112×112 px;
- the purple outer ring rotates clockwise;
- the yellow inner ring rotates counter-clockwise;
- `Загрузка приложения…` is centered below the logo;
- the old oversized 224×224 block, side swoosh, and shimmer bar are absent;
- the loader stays centered at desktop and narrow iframe widths.

- [ ] **Step 3: Verify reduced motion**

Emulate `prefers-reduced-motion: reduce` in browser developer tools and reload.

Expected: logo and rings are visible, but neither ring rotates.

- [ ] **Step 4: Verify the connection screen is unchanged**

Proceed to `Подключение Точка Банк`.

Expected: `StepConnect.vue` keeps its current button and waiting-auth spinner; this task does not replace that screen.

### Task 3: Correct the Tochka OAuth registration

**Files:**
- Verify only: `backends/python/api/main/views_tochka.py:232-235`
- Verify only: `backends/python/api/main/tochka/client.py:81-91`
- Production config: `/opt/b24-tochka-app/.env`

- [ ] **Step 1: Confirm the generated production callback**

Run:

```bash
docker exec tochka-api python -c \
  'from config import config; print(config.app_base_url.rstrip("/") + "/api/tochka/callback")'
```

Expected:

```text
https://1daa.ru/tochka/api/tochka/callback
```

- [ ] **Step 2: Register the exact URI with Tochka**

In the Tochka internet bank/API integration settings, open the OAuth application whose client ID starts with `0fb48929` and set:

```text
https://1daa.ru/tochka/api/tochka/callback
```

The value must match character-for-character, use HTTPS, and have no trailing slash.

If the application cannot be edited, create a new OAuth application with this callback and place its credentials in production:

```dotenv
TOCHKA_CLIENT_ID=<new client id>
TOCHKA_CLIENT_SECRET=<new client secret>
```

- [ ] **Step 3: Restart the API only if credentials changed**

Run on production:

```bash
cd /opt/b24-tochka-app
docker-compose -f docker-compose.production.yml up -d --force-recreate api
```

Expected: `tochka-api` is running with the updated environment.

- [ ] **Step 4: Verify OAuth authorization**

Click `Подключить Точка Банк`.

Expected:

- Tochka no longer returns `400 Incorrect redirect uri`;
- the consent page opens;
- after approval, Tochka redirects to the callback;
- the popup closes and the setup proceeds to account selection.

### Task 4: Deploy and verify production

**Files:**
- Deploy: `frontend/app/components/AppLaunchSplash.vue`

- [ ] **Step 1: Deploy the repository**

Run from the repository root:

```bash
make push-server
```

Expected: the frontend image rebuilds and `tochka-frontend` restarts.

- [ ] **Step 2: Verify public health**

Run:

```bash
curl -fsSI https://1daa.ru/tochka/
curl -fsSI https://1daa.ru/tochka/install
```

Expected: both requests return HTTP 200 and include a `frame-ancestors` policy permitting Bitrix24.

- [ ] **Step 3: Verify inside Bitrix24**

Reload the application iframe and confirm the new startup loader appears before setup/dashboard. Complete the Tochka OAuth flow and confirm account selection opens.

No Git commit is included unless the user explicitly requests one.
