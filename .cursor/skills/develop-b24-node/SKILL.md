---
name: develop-b24-node
description: Develop backend applications for Bitrix24 using Node.js, Express, and Bitrix24 JS SDK. Use this skill when you need to create API endpoints, work with Bitrix24 data, or manage authentication in Node.js.
---

# Develop Bitrix24 Node.js Backend

## Quick Start

The Node.js backend is built with **Express** and uses **@bitrix24/b24jssdk** for Bitrix24 interaction.

### Key Files

*   `backends/node/api/server.js`: Main entry point and API routes.
*   `backends/node/api/utils/verifyToken.js`: JWT verification middleware.

## Creating API Endpoints

Use Express routing and the `verifyToken` middleware.

```javascript
import verifyToken from './utils/verifyToken.js';

app.get('/api/my-endpoint', verifyToken, async (req, res) => {
  // JWT payload is available in req.user (if verifyToken adds it, check implementation)
  // or you can decode it manually if needed
  
  res.json({ data: 'value' });
});
```

## Bitrix24 Interaction (JS SDK)

Use `@bitrix24/b24jssdk` (specifically `B24Hook` for backend or `B24Frame` if rendering UI, but usually backend uses Hook or OAuth).

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

> The canonical REST API is `b24.actions.v{2,3}.*.make()`. The older
> `callMethod` / `callBatch` helpers are deprecated — do not use them.

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

1.  **Installation**: `/api/install` receives OAuth data.
2.  **Token Issue**: `/api/getToken` issues a JWT for the frontend using `jsonwebtoken`.
3.  **Requests**: Frontend sends JWT in `Authorization` header. `verifyToken` middleware validates it.

## Database

*   **Drivers**: `pg` (PostgreSQL) or `mysql2` (MySQL).
*   **Configuration**: Based on `DB_TYPE` env var.
*   **Connection**: `pool` object in `server.js`.

## Best Practices

1.  **Middleware**: Use `verifyToken` for protected routes.
2.  **Async/Await**: Use async/await for database and API calls.
3.  **Environment**: Use `process.env` for configuration.
