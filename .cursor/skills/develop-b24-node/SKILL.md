---
name: develop-b24-node
description: Develop backend applications for Bitrix24 using Node.js, Express, and Bitrix24 JS SDK. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in Node.js.
---

# Develop Bitrix24 Node.js Backend

## Quick Start

The Node.js backend runs on **Node 24** with **Express 5** (ES modules, `pnpm` 12). The starter calls Bitrix24 REST directly via `fetch` (`utils/bitrix24Rest.js`); for richer usage **@bitrix24/b24jssdk** 3.x is recommended.

> `@bitrix24/b24jssdk` is **not** a dependency of `backends/node/api` yet. Add it before using the SDK samples below: `pnpm add @bitrix24/b24jssdk` in `backends/node/api` (commit `pnpm-lock.yaml`).

### Key Files

* `backends/node/api/app.js`: `createApp({ accounts, clientId, jwtSecret, appUrl, fetchImpl, oauthServers, logger })` — all routes, dependencies injected (tests pass fakes). Public: `/api/health`, `/api/install`, `/api/getToken`, `/api/app-events/`; under JWT: `/api/enum`, `/api/list`.
* `backends/node/api/server.js`: Entry point — creates the DB pool (`DB_TYPE`), wires `createApp()` and calls `listen`.
* `backends/node/api/db/accounts.js`: Repository over the shared `bitrix24account` table (`infrastructure/database/init*.sql`, same as PHP), PostgreSQL and MySQL.
* `backends/node/api/utils/bitrix24Rest.js`: Minimal Bitrix24 REST client (`callRest`).
* `backends/node/api/utils/verifyFrontendAuth.js`: `AUTH_ID` / `access_token` confirmation on the Bitrix24 OAuth server.
* `backends/node/api/utils/verifyToken.js`: JWT verification middleware.
* `backends/node/api/test/`: `pnpm test` (`node --test`) — `verifyFrontendAuth.test.js`, `app.test.js` (full lifecycle over HTTP), `accounts.db.test.js` (real DBs when `TEST_PG_URL` / `TEST_MYSQL_URL` are set, skipped otherwise).

## Creating API Endpoints

Add routes inside `createApp()` in `app.js` and protect them with the `verifyToken` middleware.

```javascript
import verifyToken from './utils/verifyToken.js';

app.get('/api/my-endpoint', verifyToken, async (req, res) => {
  // verifyToken puts the decoded JWT payload into req.user
  const payload = req.user;

  res.json({ data: 'value' });
});
```

## Bitrix24 Interaction (JS SDK)

On the server use `B24Hook` (webhook). `B24Frame` works only inside the Bitrix24 iframe (frontend). For logging use `LoggerFactory` (`LoggerBrowser` was removed in v3).

### Initialization

```javascript
import { B24Hook } from '@bitrix24/b24jssdk';

// From a webhook URL: https://<portal>.bitrix24.<tld>/rest/<userId>/<secret>
const b24 = B24Hook.fromWebhookUrl(process.env.B24_WEBHOOK_URL);

// ...or from parameters
// const b24 = new B24Hook({ b24Url: 'https://your-portal.bitrix24.com', userId: 1, secret: 'webhook_token' });

b24.offClientSideWarning(); // server-side only: silence the client-side warning
```

### Common Operations

> The REST API is `b24.actions.v{2,3}.*.make()`. The legacy
> `callMethod` / `callBatch` helpers were removed in JS SDK 3.

```javascript
// Single call
const res = await b24.actions.v2.call.make({
  method: 'crm.deal.get',
  params: { id: 123 }
});
if (!res.isSuccess) throw new Error(res.getErrorMessages().join('; '));
const deal = res.getData().result;

// Full list (loads everything into memory)
const listRes = await b24.actions.v2.callList.make({
  method: 'crm.deal.list',
  params: { select: ['ID', 'TITLE'] },
  idKey: 'ID',
  customKeyForResult: 'items'
});
const deals = listRes.getData();

// Batch (array or named-object form)
const batchRes = await b24.actions.v2.batch.make({
  calls: [
    ['crm.deal.get', { id: 1 }],
    ['crm.deal.get', { id: 2 }]
  ],
  options: { isHaltOnError: true }
});
```

## Authentication Flow

1. **Installation**: `/api/install` confirms `AUTH_ID` on the Bitrix24 OAuth server, stores the account (status `new`) and binds `ONAPPINSTALL`/`ONAPPUNINSTALL` to `${NUXT_PUBLIC_API_URL}/api/app-events/`. Responds `{"message": "Installation successful"}`.
   * `/api/app-events/` `ONAPPINSTALL`: the event's `access_token` is confirmed on the OAuth server, `application_token` is stored, status `active`.
   * `/api/app-events/` `ONAPPUNINSTALL`: accepted only with the stored `application_token`, status `deleted`.
2. **Token Issue**: `/api/getToken` first calls `utils/verifyFrontendAuth.js`: the caller's `AUTH_ID` is checked by the Bitrix24 OAuth server (`/rest/app.info/` on `oauth.bitrix.info` or `oauth.bitrix24.tech` — fixed trusted hosts, never the portal from the request; `B24_OAUTH_SERVER_URL` is asked first, then the other region). It must return our `CLIENT_ID`, the same `DOMAIN`/`member_id` and `install.installed: true`. `/api/getToken` checks for an installed account (`new`/`active`) in the local DB before calling the OAuth server. Only then a JWT `{ domain, member_id }` (1h, `JWT_SECRET`) is issued. Errors: 400 (incomplete payload), 401 (not confirmed), 503 (OAuth server unreachable). Tests: `pnpm test` (`node --test`, `test/`).
3. **Requests**: Frontend sends JWT in `Authorization` header. `verifyToken` middleware validates it.

## Database

* **Drivers**: `pg` (PostgreSQL) or `mysql2` (MySQL).
* **Configuration**: Based on `DB_TYPE` env var.
* **Connection**: `pool` in `server.js`, wrapped by `createDb()` and `createAccountsRepository()` (`db/accounts.js`). The schema comes from `infrastructure/database/init*.sql`; there are no migrations in the Node backend.

## Best Practices

1. **Middleware**: Use `verifyToken` for protected routes.
2. **Async/Await**: Use async/await for database and API calls.
3. **Environment**: Use `process.env` for configuration.
