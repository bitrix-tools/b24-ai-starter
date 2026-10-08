import { test } from 'node:test';
import assert from 'node:assert/strict';
import { verifyFrontendAuth, FrontendAuthError, oauthServers, OAUTH_SERVERS } from '../utils/verifyFrontendAuth.js';

const CLIENT_ID = 'local.abc123.456';
const payload = (override = {}) => ({
  DOMAIN: 'example.bitrix24.ru',
  member_id: 'member-1',
  AUTH_ID: 'valid-token',
  REFRESH_ID: 'refresh-secret',
  ...override,
});
const appInfo = (override = {}) => ({
  client_id: CLIENT_ID,
  user_id: 1,
  ...override,
  install: { domain: 'example.bitrix24.ru', member_id: 'member-1', installed: true, ...override.install },
});
const answering = (body) => async () => ({ json: async () => body });
const options = (fetchImpl) => ({ clientId: CLIENT_ID, fetchImpl, servers: oauthServers('') });

async function rejectsWith(promise, status) {
  await assert.rejects(promise, (error) => error instanceof FrontendAuthError && error.status === status);
}

test('rejects incomplete or non-string payload without calling the OAuth server', async () => {
  const fetchImpl = () => assert.fail('OAuth server must not be called');
  for (const override of [{ DOMAIN: '' }, { member_id: '' }, { AUTH_ID: '' }, { DOMAIN: ['x'] }, { AUTH_ID: { a: 1 } }]) {
    await rejectsWith(verifyFrontendAuth(payload(override), options(fetchImpl)), 400);
  }
});

test('asks only Bitrix24 OAuth servers — never the portal from the request — and never sends the refresh token', async () => {
  const urls = [];
  const fetchImpl = async (url) => {
    urls.push(url);
    return { json: async () => ({ result: appInfo() }) };
  };

  await verifyFrontendAuth(payload({ DOMAIN: 'attacker-controlled.example.com' }), options(fetchImpl)).catch(() => {});
  await verifyFrontendAuth(payload(), options(fetchImpl));

  for (const url of urls) {
    assert.ok(OAUTH_SERVERS.some((server) => url.startsWith(`${server}rest/app.info/`)), url);
    assert.ok(!url.includes('refresh-secret'), url);
  }
  assert.ok(urls.at(-1).includes('auth=valid-token'));
});

test('asks the configured server first, then the other region', async () => {
  assert.deepEqual(oauthServers('https://oauth.bitrix24.tech'), ['https://oauth.bitrix24.tech/', 'https://oauth.bitrix.info/']);

  const urls = [];
  const fetchImpl = async (url) => {
    urls.push(url);
    return { json: async () => (urls.length === 1 ? { error: 'invalid_token' } : { result: appInfo() }) };
  };
  const result = await verifyFrontendAuth(payload(), options(fetchImpl));

  assert.deepEqual({ domain: result.domain, memberId: result.memberId }, { domain: 'example.bitrix24.ru', memberId: 'member-1' });
  assert.equal(urls.length, 2);
  assert.notEqual(new URL(urls[0]).host, new URL(urls[1]).host);
});

test('rejects what the OAuth servers do not confirm', async () => {
  const cases = {
    'expired or fake token': { error: 'expired_token' },
    'token of another application': { result: appInfo({ client_id: 'local.other.app' }) },
    'token of another portal': { result: appInfo({ install: { domain: 'attacker.bitrix24.ru' } }) },
    'member_id of another portal': { result: appInfo({ install: { member_id: 'member-2' } }) },
    'app not installed': { result: appInfo({ install: { installed: false } }) },
  };
  for (const [name, body] of Object.entries(cases)) {
    await rejectsWith(verifyFrontendAuth(payload(), options(answering(body))), 401)
      .catch((error) => assert.fail(`${name}: ${error.message}`));
  }
});

test('reports 503, not 401, when no OAuth server can be reached', async () => {
  const fetchImpl = async () => { throw new Error('ECONNREFUSED'); };
  await rejectsWith(verifyFrontendAuth(payload(), options(fetchImpl)), 503);
});

test('accepts a token the OAuth server confirms for this app and portal', async () => {
  const result = await verifyFrontendAuth(payload({ DOMAIN: 'https://Example.Bitrix24.ru/' }), options(answering({ result: appInfo() })));
  assert.deepEqual({ domain: result.domain, memberId: result.memberId }, { domain: 'example.bitrix24.ru', memberId: 'member-1' });
});
