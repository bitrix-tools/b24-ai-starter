/**
 * Minimal Bitrix24 REST call for the few server-side calls the starter makes
 * during installation (user.admin, event.bind). `clientEndpoint` comes from the
 * OAuth server's app.info answer (`install.client_endpoint`, e.g.
 * https://example.bitrix24.ru/rest/), never from the request.
 *
 * For anything beyond this, add @bitrix24/b24jssdk (B24OAuth) — see
 * instructions/node/knowledge.md.
 */
export class Bitrix24RestError extends Error {
  constructor(method, code, description) {
    super(`${method}: ${code}${description ? ` — ${description}` : ''}`);
    this.code = code;
  }
}

export async function callRest(clientEndpoint, method, params, accessToken, { fetchImpl = fetch, timeoutMs = 10000 } = {}) {
  const url = `${clientEndpoint.replace(/\/+$/, '')}/${method}.json`;
  const response = await fetchImpl(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...params, auth: accessToken }),
    redirect: 'error',
    signal: AbortSignal.timeout(timeoutMs),
  });
  const body = await response.json();
  if (body?.error) {
    throw new Bitrix24RestError(method, body.error, body.error_description);
  }
  return body?.result;
}
