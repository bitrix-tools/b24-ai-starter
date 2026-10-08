/**
 * Proves that a `/api/getToken` request comes from a user of a Bitrix24 portal
 * where THIS application is installed, before a JWT is issued.
 *
 * `AUTH_ID` is checked by the Bitrix24 OAuth server (`app.info`) — a fixed,
 * trusted host, never the portal named in the request (the caller controls
 * that). The answer must name OUR `client_id`, the same domain and `member_id`
 * the request claims, and an installed app. This is the check b24pysdk's
 * `validate_placement_request` does; it works for local and Marketplace apps.
 *
 * OAuth servers are per region. `B24_OAUTH_SERVER_URL` (if set) is asked first,
 * then the other region. The refresh token is never sent.
 *
 * The Node starter does not store installations (see `/api/install`), so unlike
 * the PHP backend it cannot additionally require a completed local install.
 */

export const OAUTH_SERVERS = ['https://oauth.bitrix.info/', 'https://oauth.bitrix24.tech/'];

export class FrontendAuthError extends Error {
  constructor(message, status, cause) {
    super(message, { cause });
    this.status = status;
  }
}

const str = (value) => (typeof value === 'string' || typeof value === 'number' ? String(value) : '');

export function normalizeDomain(domain) {
  return str(domain)
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, '')
    .replace(/\/+$/, '');
}

export function oauthServers(configured = process.env.B24_OAUTH_SERVER_URL) {
  const list = configured ? [configured, ...OAUTH_SERVERS] : OAUTH_SERVERS;
  return [...new Set(list.map((url) => `${url.replace(/\/+$/, '')}/`))];
}

/**
 * Check an access token on the Bitrix24 OAuth server and return its `app.info`
 * result if it belongs to OUR app, installed on `domain` with `memberId`.
 *
 * @param {string} accessToken
 * @param {{ domain: string, memberId: string, clientId: string, fetchImpl?: typeof fetch, timeoutMs?: number, servers?: string[] }} options
 * @returns {Promise<Record<string, any>>} app.info `result` (client_id, user_id, install.client_endpoint, …)
 */
export async function confirmAccessToken(accessToken, { domain, memberId, clientId, fetchImpl = fetch, timeoutMs = 5000, servers = oauthServers() }) {
  if (!clientId) {
    throw new FrontendAuthError('CLIENT_ID is not configured on the backend', 500);
  }

  const appInfo = await fetchAppInfo(accessToken, { fetchImpl, timeoutMs, servers });

  if (
    appInfo.client_id !== clientId
    || normalizeDomain(appInfo.install?.domain) !== normalizeDomain(domain)
    || appInfo.install?.member_id !== memberId
    || appInfo.install?.installed !== true
  ) {
    throw new FrontendAuthError('Invalid Bitrix24 credentials', 401);
  }

  return appInfo;
}

/**
 * @param {Record<string, unknown>} payload body of /api/getToken or /api/install
 * @param {{ clientId: string, fetchImpl?: typeof fetch, timeoutMs?: number, servers?: string[] }} options
 * @returns {Promise<{ domain: string, memberId: string, appInfo: Record<string, any> }>}
 */
export async function verifyFrontendAuth(payload, options) {
  const domain = normalizeDomain(payload?.DOMAIN);
  const memberId = str(payload?.member_id);
  const accessToken = str(payload?.AUTH_ID);

  if (!domain || !memberId || !accessToken) {
    throw new FrontendAuthError('Missing required parameters: DOMAIN, member_id, AUTH_ID', 400);
  }

  const appInfo = await confirmAccessToken(accessToken, { ...options, domain, memberId });

  return { domain, memberId, appInfo };
}

async function fetchAppInfo(accessToken, { fetchImpl, timeoutMs, servers }) {
  let unreachable = null;
  for (const server of servers) {
    let response;
    try {
      const url = `${server}rest/app.info/?${new URLSearchParams({ auth: accessToken })}`;
      response = await fetchImpl(url, { redirect: 'error', signal: AbortSignal.timeout(timeoutMs) });
    } catch (error) {
      unreachable = error;
      continue;
    }
    try {
      const result = (await response.json())?.result;
      if (result && typeof result === 'object') {
        return result;
      }
    } catch {
      // not JSON: this server does not confirm the token
    }
  }
  if (unreachable) {
    throw new FrontendAuthError('Bitrix24 OAuth server is unavailable', 503, unreachable);
  }
  throw new FrontendAuthError('Invalid Bitrix24 credentials', 401);
}
