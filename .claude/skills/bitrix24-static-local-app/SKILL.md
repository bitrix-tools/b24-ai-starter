---
name: bitrix24-static-local-app
description: Builds and packages Bitrix24 static local application archives from this project. Use when the user asks to generate a static app zip, debug 404 on app_local/index.html, or validate archive structure for Bitrix24 upload.
---

# Bitrix24 Static Local App (Nuxt)

## Purpose

Use this skill to produce a valid Bitrix24 static local app archive from this repository and avoid startup 404 errors.

## Quick Workflow

1. Build static frontend:
   - `cd frontend`
   - `pnpm install` (if needed)
   - `pnpm run archive:static`
2. Upload archive:
   - `frontend/artifacts/b24-static-local-app.zip`
3. Reinstall app in Bitrix24 after re-upload.

## Mandatory Checks Before Upload

- Archive root contains `index.html`
- Archive root contains `_nuxt/`
- No extra top-level folder (`frontend/`, `dist/`, `.output/`)
- `index.html` uses relative assets (`./_nuxt/...`, `./favicon.ico`)

## Project-Specific Implementation Notes

- Static build scripts are in `frontend/package.json`:
  - `build:static`
  - `pack:static`
  - `archive:static`
- Packaging script:
  - `frontend/tools/pack-static-app.mjs`
- Router static behavior:
  - `frontend/app/router.options.ts`
- Startup path normalization for Bitrix24 `app_local` path:
  - `frontend/app/middleware/01.app.page.or.slider.global.ts`

## Critical Note (Path Fix)

If Bitrix24 opens app by URL like:

`/bXXXX/app_local/<hash>/index.html?DOMAIN=...`

Nuxt may throw:

`[nuxt] error caught during app initialization ... Page not found ...`

To prevent this, keep both protections:

1. Hash routing for static build in `app/router.options.ts` (`hashMode` in static mode).
2. Middleware normalization in `01.app.page.or.slider.global.ts`:
   - detect `to.path` with `/app_local/` or ending `/index.html`
   - redirect to `/` with `replace: true`

Without these two protections, archive can be valid but app still fails on startup with 404.

## Troubleshooting

- **Still seeing old JS filename in stacktrace**:
  - Browser/portal cache is stale. Reupload archive, reinstall app, open in incognito.
- **404 right after upload**:
  - Verify zip root structure (most common issue).
- **App loads but API calls fail**:
  - For static mode this project is frontend-only via B24 JS SDK; backend is intentionally disabled.

## References

- Bitrix24 static local app docs:
  - <https://raw.githubusercontent.com/bitrix24/b24restdocs/main/local-integrations/static-local-app.md>
- Bitrix24 JS SDK docs index:
  - <https://bitrix24.github.io/b24jssdk/llms.txt>
