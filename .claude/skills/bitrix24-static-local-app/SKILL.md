---
name: bitrix24-static-local-app
description: Builds and packages Bitrix24 static local application archives from this project. Use when the user asks to generate a static app zip, debug 404 on app_local/index.html, or validate archive structure for Bitrix24 upload.
---

# Bitrix24 Static Local App (Nuxt)

## Purpose

Use this skill to produce a valid Bitrix24 static local app archive from this repository and avoid startup 404 errors.

## Current Status

The static-build tooling is **not present in this repository yet**: `frontend/package.json` has no `build:static` / `pack:static` / `archive:static` scripts, and there is no `frontend/tools/pack-static-app.mjs` or `frontend/app/router.options.ts`. Do not tell the user to run them — add them first if a static archive is required.

The frontend is already client-only (`ssr: false` in `frontend/nuxt.config.ts`), so `pnpm generate` (Nuxt) produces a static site in `frontend/.output/public/`.

## Quick Workflow

1. Build static frontend:
   - `cd frontend`
   - `pnpm install --frozen-lockfile`
   - `pnpm generate`
2. Zip the **contents** of `frontend/.output/public/` (not the folder itself).
3. Upload the archive in Bitrix24 and reinstall the app after every re-upload.

## Mandatory Checks Before Upload

- Archive root contains `index.html`
- Archive root contains `_nuxt/`
- No extra top-level folder (`frontend/`, `dist/`, `.output/`)
- `index.html` uses relative assets (`./_nuxt/...`, `./favicon.ico`) — requires a relative `app.baseURL` / `app.buildAssetsDir` for the static build, which is not configured yet

## Project-Specific Implementation Notes

- Startup middleware (page/slider detection) lives in:
  - `frontend/app/middleware/01.app.page.or.slider.global.ts`
- Static mode needs, in addition:
  - hash routing for the static build (e.g. `router.options.ts` with `hashMode`)
  - path normalization for Bitrix24 `app_local` URLs (see below)

## Critical Note (Path Fix)

If Bitrix24 opens app by URL like:

`/bXXXX/app_local/<hash>/index.html?DOMAIN=...`

Nuxt may throw:

`[nuxt] error caught during app initialization ... Page not found ...`

To prevent this, add both protections (neither is implemented yet):

1. Hash routing for the static build in `frontend/app/router.options.ts` (`hashMode` in static mode).
2. Middleware normalization in `frontend/app/middleware/01.app.page.or.slider.global.ts`:
   - detect `to.path` with `/app_local/` or ending `/index.html`
   - redirect to `/` with `replace: true`

Without these two protections, archive can be valid but app still fails on startup with 404.

## Troubleshooting

- **Still seeing old JS filename in stacktrace**:
  - Browser/portal cache is stale. Reupload archive, reinstall app, open in incognito.
- **404 right after upload**:
  - Verify zip root structure (most common issue).
- **App loads but API calls fail**:
  - A static app has no backend: call Bitrix24 only via the JS SDK (`$b24.actions.v2|v3.*.make()`); `useApiStore` backend calls will not work.

## References

- Bitrix24 static local app docs:
  - <https://raw.githubusercontent.com/bitrix24/b24restdocs/main/local-integrations/static-local-app.md>
- Bitrix24 JS SDK docs index:
  - <https://bitrix24.github.io/b24jssdk/llms.txt>
