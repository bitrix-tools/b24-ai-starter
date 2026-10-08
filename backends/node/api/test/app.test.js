import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import jwt from 'jsonwebtoken';

process.env.JWT_SECRET = 'test-secret';
const { createApp } = await import('../app.js');

const CLIENT_ID = 'local.abc123.456';
const DOMAIN = 'example.bitrix24.ru';
const MEMBER_ID = 'member-1';
const APP_URL = 'https://app.example.com';

/** In-memory stand-in for db/accounts.js (the SQL itself is tested in accounts.db.test.js). */
function memoryAccounts() {
  const rows = [];
  const installed = (r) => ['new', 'active'].includes(r.status);
  return {
    rows,
    async findInstalled(memberId, domain) {
      return rows.filter((r) => r.member_id === memberId && r.domain_url === domain && installed(r));
    },
    async saveInstall(a) {
      const row = rows.find((r) => r.b24_user_id === a.b24UserId && r.domain_url === a.domain && installed(r));
      if (row) Object.assign(row, { auth_token_access_token: a.accessToken });
      else rows.push({ b24_user_id: a.b24UserId, member_id: a.memberId, domain_url: a.domain, status: 'new', is_b24_user_admin: a.isAdmin, auth_token_access_token: a.accessToken });
    },
    async activate(memberId, domain, token) {
      const hit = rows.filter((r) => r.member_id === memberId && r.domain_url === domain && installed(r));
      hit.forEach((r) => Object.assign(r, { status: 'active', application_token: token }));
      return hit.length;
    },
    async markDeleted(memberId, token) {
      const hit = rows.filter((r) => r.member_id === memberId && r.application_token === token && installed(r));
      hit.forEach((r) => { r.status = 'deleted'; });
      return hit.length;
    },
  };
}

/** Fake Bitrix24: OAuth server (app.info) + portal REST (user.admin, event.*). */
function fakeBitrix24() {
  const calls = [];
  const fetchImpl = async (url, init = {}) => {
    const u = new URL(url);
    calls.push({ host: u.host, path: u.pathname, body: init.body ? JSON.parse(init.body) : null, auth: u.searchParams.get('auth') });
    if (u.pathname === '/rest/app.info/') {
      const ok = u.searchParams.get('auth') === 'valid-token';
      return { json: async () => (ok ? { result: {
        client_id: CLIENT_ID, user_id: 7,
        install: { domain: DOMAIN, member_id: MEMBER_ID, installed: true, version: 3, client_endpoint: `https://${DOMAIN}/rest/` },
      } } : { error: 'invalid_token' }) };
    }
    if (u.host === DOMAIN && u.pathname === '/rest/user.admin.json') return { json: async () => ({ result: true }) };
    if (u.host === DOMAIN && u.pathname.startsWith('/rest/event.')) return { json: async () => ({ result: true }) };
    throw new Error(`unexpected call ${url}`);
  };
  return { calls, fetchImpl };
}

let server;
let base;
let accounts;
let bitrix;
const quiet = { log() {}, warn() {}, error() {} };

before(async () => {
  accounts = memoryAccounts();
  bitrix = fakeBitrix24();
  const app = createApp({ accounts, clientId: CLIENT_ID, jwtSecret: 'test-secret', appUrl: APP_URL, fetchImpl: bitrix.fetchImpl, logger: quiet });
  await new Promise((resolve) => { server = app.listen(0, resolve); });
  base = `http://127.0.0.1:${server.address().port}`;
});

after(() => server.close());

const post = (path, body, form = false) => fetch(base + path, {
  method: 'POST',
  headers: { 'Content-Type': form ? 'application/x-www-form-urlencoded' : 'application/json' },
  body: form ? new URLSearchParams(body) : JSON.stringify(body),
});
const frontendPayload = (override = {}) => ({ DOMAIN, member_id: MEMBER_ID, AUTH_ID: 'valid-token', REFRESH_ID: 'r', AUTH_EXPIRES: 3600, ...override });
const eventBody = (event, auth) => ({
  event, 'auth[domain]': DOMAIN, 'auth[member_id]': MEMBER_ID, 'auth[application_token]': 'app-token', ...auth,
});

test('health is public; data routes need a JWT', async () => {
  const health = await fetch(`${base}/api/health`);
  assert.equal(health.status, 200);
  assert.equal((await health.json()).backend, 'node');
  assert.equal((await fetch(`${base}/api/enum`)).status, 401);
});

test('getToken refuses a portal without an installation — without any network call', async () => {
  const before = bitrix.calls.length;
  const res = await post('/api/getToken', frontendPayload());
  assert.equal(res.status, 401);
  assert.equal(bitrix.calls.length, before);
});

test('install rejects a token the OAuth server does not confirm', async () => {
  const res = await post('/api/install', frontendPayload({ AUTH_ID: 'forged' }));
  assert.equal(res.status, 401);
  assert.equal(accounts.rows.length, 0);
});

test('install stores the account and binds lifecycle events to /api/app-events/', async () => {
  const res = await post('/api/install', frontendPayload());
  assert.equal(res.status, 200);
  assert.deepEqual(await res.json(), { message: 'Installation successful' });

  assert.equal(accounts.rows.length, 1);
  assert.deepEqual(
    { user: accounts.rows[0].b24_user_id, status: accounts.rows[0].status, admin: accounts.rows[0].is_b24_user_admin },
    { user: 7, status: 'new', admin: true },
  );
  const binds = bitrix.calls.filter((c) => c.path === '/rest/event.bind.json').map((c) => [c.body.event, c.body.handler]);
  assert.deepEqual(binds, [
    ['ONAPPINSTALL', `${APP_URL}/api/app-events/`],
    ['ONAPPUNINSTALL', `${APP_URL}/api/app-events/`],
  ]);
});

test('getToken issues a JWT for the installed portal', async () => {
  const res = await post('/api/getToken', frontendPayload());
  assert.equal(res.status, 200);
  const claims = jwt.verify((await res.json()).token, 'test-secret');
  assert.deepEqual({ domain: claims.domain, member_id: claims.member_id }, { domain: DOMAIN, member_id: MEMBER_ID });
});

test('ONAPPINSTALL is accepted only with a confirmed access token, then activates the account', async () => {
  const forged = await post('/api/app-events/', eventBody('ONAPPINSTALL', { 'auth[access_token]': 'forged' }), true);
  assert.equal(forged.status, 401);
  assert.equal(accounts.rows[0].status, 'new');

  const ok = await post('/api/app-events/', eventBody('ONAPPINSTALL', { 'auth[access_token]': 'valid-token' }), true);
  assert.equal(ok.status, 200);
  assert.deepEqual({ status: accounts.rows[0].status, token: accounts.rows[0].application_token }, { status: 'active', token: 'app-token' });
});

test('ONAPPUNINSTALL needs the stored application token; afterwards no JWT is issued', async () => {
  const forged = await post('/api/app-events/', eventBody('ONAPPUNINSTALL', { 'auth[application_token]': 'guess' }), true);
  assert.equal(forged.status, 401);
  assert.equal(accounts.rows[0].status, 'active');

  const ok = await post('/api/app-events/', eventBody('ONAPPUNINSTALL', {}), true);
  assert.equal(ok.status, 200);
  assert.equal(accounts.rows[0].status, 'deleted');

  assert.equal((await post('/api/getToken', frontendPayload())).status, 401);
});

test('unknown events are rejected', async () => {
  const res = await post('/api/app-events/', eventBody('ONCRMDEALADD', {}), true);
  assert.equal(res.status, 400);
});
