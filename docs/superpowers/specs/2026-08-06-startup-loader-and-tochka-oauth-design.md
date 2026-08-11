# Startup loader and Tochka OAuth

## Scope

1. Replace only the application startup splash with a branded loader.
2. Keep the existing account connection screen unchanged.
3. Resolve the Tochka OAuth redirect URI mismatch operationally.

## Startup loader

- Reuse the existing `AppLaunchSplash` entry point so both setup and dashboard
  startup states receive the same loader.
- Display the existing compact purple square Tochka logo at 112×112 px.
- Add two orbiting decorative arcs:
  - outer purple arc;
  - inner yellow arc rotating in the opposite direction.
- Keep the background white and show the text `Загрузка приложения…`.
- Avoid layout movement while initialization completes.
- Under `prefers-reduced-motion: reduce`, show a static logo and arcs.
- Do not change the loading state shown after clicking `Подключить Точка Банк`.

## OAuth redirect URI

The backend currently generates:

`https://1daa.ru/tochka/api/tochka/callback`

The deployed `APP_BASE_URL` and the authorization request use that exact value.
Tochka returns `400 Incorrect redirect uri` when this value differs from the
redirect URI registered for OAuth client `0fb48929…`.

The correct resolution is one of:

1. Update the registered redirect URI for the existing OAuth client to the
   exact value above, without a trailing slash.
2. If the existing client cannot be edited, create a new OAuth client with
   that redirect URI and update `TOCHKA_CLIENT_ID` and
   `TOCHKA_CLIENT_SECRET` in production.

No code-side redirect substitution or fallback will be added because the
authorization and token exchange requests must use the same URI registered
with Tochka.

## Verification

- Setup and dashboard startup show the new loader.
- The logo remains centered at desktop and narrow iframe widths.
- Reduced-motion mode has no rotating animation.
- Existing setup connection screen remains unchanged.
- The public callback URL is reachable.
- After the registered redirect URI is corrected, Tochka authorization no
  longer returns HTTP 400 and redirects to the callback.
