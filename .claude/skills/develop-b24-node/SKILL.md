---
name: develop-b24-node
description: Develop backend applications for Bitrix24 using Node.js, Express, and Bitrix24 JS SDK. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in Node.js.
---

# Develop Bitrix24 Node.js Backend

## Quick Start

The Node.js backend runs on **Node 24** with **Express 5** (ES modules, `pnpm` 12). Bitrix24 calls are made with **@bitrix24/b24jssdk** 3.x.

> `@bitrix24/b24jssdk` is **not** a dependency of `backends/node/api` yet. Add it before using the samples below: `pnpm add @bitrix24/b24jssdk` in `backends/node/api` (commit `pnpm-lock.yaml`).

### Key Files

* `backends/node/api/server.js`: Main entry point, DB pool and API routes (`/api/health`, `/api/enum`, `/api/list` under JWT; `/api/install`, `/api/getToken` public).
* `backends/node/api/utils/verifyToken.js`: JWT verification middleware.

## Creating API Endpoints

Use Express routing and the `verifyToken` middleware.

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

1. **Installation**: `/api/install` receives OAuth data. In the starter it only logs the (redacted) body — persisting tokens and binding events is up to you.
2. **Token Issue**: `/api/getToken` issues a JWT (`{ id: 1 }`, 1h, `JWT_SECRET`) using `jsonwebtoken`. The starter does **not** validate Bitrix24 auth data here — add that check before production.
3. **Requests**: Frontend sends JWT in `Authorization` header. `verifyToken` middleware validates it.

## Database

* **Drivers**: `pg` (PostgreSQL) or `mysql2` (MySQL).
* **Configuration**: Based on `DB_TYPE` env var.
* **Connection**: `pool` object in `server.js` (not used by the sample routes yet; there are no migrations in the Node backend).

## Best Practices

1. **Middleware**: Use `verifyToken` for protected routes.
2. **Async/Await**: Use async/await for database and API calls.
3. **Environment**: Use `process.env` for configuration.
