import express from 'express';
import cors from 'cors';
import jwt from 'jsonwebtoken';
import verifyToken from './utils/verifyToken.js';
import { confirmAccessToken, normalizeDomain, verifyFrontendAuth, FrontendAuthError } from './utils/verifyFrontendAuth.js';
import { callRest } from './utils/bitrix24Rest.js';

// Keys that carry Bitrix24 OAuth tokens — never write these to logs.
// Same list as App\Service\LogRedactor in the PHP backend; matched case-insensitively.
const SENSITIVE_KEYS = ['auth_id', 'refresh_id', 'refresh_token', 'access_token', 'application_token'];

/**
 * Return a copy of a request body with token fields masked,
 * so install/event payloads can be logged without leaking credentials.
 */
export function redactSensitive(value) {
  if (!value || typeof value !== 'object') {
    return value;
  }
  const clone = Array.isArray(value) ? [...value] : { ...value };
  for (const key of Object.keys(clone)) {
    if (SENSITIVE_KEYS.includes(key.toLowerCase())) {
      clone[key] = '***';
    } else if (clone[key] && typeof clone[key] === 'object') {
      clone[key] = redactSensitive(clone[key]);
    }
  }
  return clone;
}

const LIFECYCLE_EVENTS = ['ONAPPINSTALL', 'ONAPPUNINSTALL'];

/**
 * @param {{
 *   accounts: ReturnType<import('./db/accounts.js').createAccountsRepository>,
 *   clientId: string,
 *   jwtSecret: string,
 *   appUrl?: string,           // public URL Bitrix24 calls back (NUXT_PUBLIC_API_URL)
 *   fetchImpl?: typeof fetch,
 *   oauthServers?: string[],
 *   logger?: Pick<Console, 'log' | 'warn' | 'error'>,
 * }} deps
 */
export function createApp({ accounts, clientId, jwtSecret, appUrl, fetchImpl = fetch, oauthServers, logger = console }) {
  const oauth = { clientId, fetchImpl, ...(oauthServers ? { servers: oauthServers } : {}) };
  const app = express();

  app.use(cors());
  app.use(express.json({ limit: '100kb' }));
  // Bitrix24 sends event callbacks as application/x-www-form-urlencoded.
  app.use(express.urlencoded({ extended: true, limit: '100kb' }));

  app.get('/', (req, res) => {
    res.json(['!default route for index page, please use /api/* routes']);
  });

  // Public liveness probe (same in every backend): no auth, no sensitive data.
  app.get('/api/health', (req, res) => {
    res.json({ status: 'healthy', backend: 'node', timestamp: Math.floor(Date.now() / 1000) });
  });

  app.get('/api/enum', verifyToken, (req, res) => {
    res.json(['option 1', 'option 2', 'option 3']);
  });

  app.get('/api/list', verifyToken, (req, res) => {
    res.json(['element 1', 'element 2', 'element 3']);
  });

  // Installation from the frontend install page (before installFinish()).
  app.post('/api/install', async (req, res) => {
    logger.log('/api/install', redactSensitive(req.body));
    try {
      const { domain, memberId, appInfo } = await verifyFrontendAuth(req.body, oauth);
      const accessToken = String(req.body.AUTH_ID);
      const endpoint = clientEndpointFor(appInfo, domain);

      const isAdmin = Boolean(await callRest(endpoint, 'user.admin', {}, accessToken, { fetchImpl }));
      await accounts.saveInstall({
        memberId,
        domain,
        b24UserId: Number(appInfo.user_id),
        isAdmin,
        accessToken,
        refreshToken: String(req.body.REFRESH_ID ?? req.body.REFRESH_TOKEN ?? ''),
        expiresIn: Number(req.body.AUTH_EXPIRES ?? 3600),
        appVersion: Number(appInfo.install?.version ?? req.body.appVersion ?? 1),
      });

      if (appUrl) {
        await bindLifecycleEvents(endpoint, accessToken, `${appUrl.replace(/\/+$/, '')}/api/app-events/`);
      } else {
        logger.warn('/api/install: NUXT_PUBLIC_API_URL is not set — lifecycle events are not bound');
      }

      res.json({ message: 'Installation successful' });
    } catch (error) {
      sendError(res, error, '/api/install');
    }
  });

  // Application lifecycle events from Bitrix24 (bound during /api/install).
  app.post('/api/app-events/', async (req, res) => {
    const event = String(req.body?.event ?? '').toUpperCase();
    const auth = req.body?.auth ?? {};
    logger.log('/api/app-events/', { event, domain: auth.domain, member_id: auth.member_id });
    try {
      const memberId = String(auth.member_id ?? '');
      const applicationToken = String(auth.application_token ?? '');
      if (!memberId || !applicationToken) {
        throw new FrontendAuthError('Missing auth data', 400);
      }

      if (event === 'ONAPPINSTALL') {
        // No stored application token yet: prove the event via its access token.
        const domain = normalizeDomain(auth.domain);
        await confirmAccessToken(String(auth.access_token ?? ''), { ...oauth, domain, memberId });
        if (await accounts.activate(memberId, domain, applicationToken) === 0) {
          throw new FrontendAuthError('Application is not installed on this portal', 404);
        }
      } else if (event === 'ONAPPUNINSTALL') {
        // Uninstall carries no access token: the stored application token is the proof.
        if (await accounts.markDeleted(memberId, applicationToken) === 0) {
          throw new FrontendAuthError('Unknown application token', 401);
        }
      } else {
        throw new FrontendAuthError(`Unsupported event: ${event || 'none'}`, 400);
      }

      res.json({ message: 'ok' });
    } catch (error) {
      sendError(res, error, '/api/app-events/');
    }
  });

  app.post('/api/getToken', async (req, res) => {
    try {
      const domain = normalizeDomain(req.body?.DOMAIN);
      const memberId = typeof req.body?.member_id === 'string' ? req.body.member_id : '';
      if (domain && memberId && (await accounts.findInstalled(memberId, domain)).length === 0) {
        // Cheap local check first — no network call for portals without an installation.
        throw new FrontendAuthError('Application is not installed on this portal', 401);
      }
      // Issue a JWT only after the Bitrix24 OAuth server confirms the caller's token belongs to this app.
      const verified = await verifyFrontendAuth(req.body, oauth);
      const token = jwt.sign({ domain: verified.domain, member_id: verified.memberId }, jwtSecret, { expiresIn: '1h' });

      logger.log('/api/getToken issued', { domain: verified.domain, member_id: verified.memberId });
      res.json({ token });
    } catch (error) {
      sendError(res, error, '/api/getToken');
    }
  });

  async function bindLifecycleEvents(endpoint, accessToken, handler) {
    for (const event of LIFECYCLE_EVENTS) {
      // Re-install: drop an older binding first, so the handler URL is always current.
      await callRest(endpoint, 'event.unbind', { event, handler }, accessToken, { fetchImpl }).catch(() => {});
      await callRest(endpoint, 'event.bind', { event, handler }, accessToken, { fetchImpl });
    }
  }

  function sendError(res, error, route) {
    const status = error instanceof FrontendAuthError ? error.status : 500;
    const log = status >= 500 ? logger.error : logger.warn;
    log(`${route} rejected`, { status, reason: error.message, cause: error.cause?.message });
    res.status(status).json({ error: status === 500 ? 'Internal error' : error.message });
  }

  return app;
}

/** REST endpoint of the portal, as reported by the OAuth server — must be the same portal. */
function clientEndpointFor(appInfo, domain) {
  const endpoint = String(appInfo.install?.client_endpoint ?? `https://${domain}/rest/`);
  const url = new URL(endpoint);
  if (url.protocol !== 'https:' || normalizeDomain(url.host) !== domain) {
    throw new FrontendAuthError('Unexpected portal endpoint', 401);
  }
  return endpoint;
}
