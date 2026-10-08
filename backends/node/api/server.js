import express from 'express';
import cors from 'cors';
import { Pool } from 'pg';
import mysql from 'mysql2/promise';
import jwt from 'jsonwebtoken';
import verifyToken from './utils/verifyToken.js';
import { verifyFrontendAuth, FrontendAuthError } from './utils/verifyFrontendAuth.js';

const app = express();
app.use(cors());
app.use(express.json({ limit: '100kb' }));
// Bitrix24 sends install/event callbacks as application/x-www-form-urlencoded,
// so this parser is required for req.body to be populated on those requests.
app.use(express.urlencoded({ extended: true, limit: '100kb' }));

// Keys that carry Bitrix24 OAuth tokens — never write these to logs.
// Same list as App\Service\LogRedactor in the PHP backend; matched case-insensitively.
const SENSITIVE_KEYS = ['auth_id', 'refresh_id', 'refresh_token', 'access_token', 'application_token'];

/**
 * Return a shallow copy of a request body with token fields masked,
 * so install/event payloads can be logged without leaking credentials.
 */
function redactSensitive(value) {
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

const dbType = (process.env.DB_TYPE || 'postgresql').toLowerCase();
const defaultDbPort = dbType === 'mysql' ? 3306 : 5432;

const pool = dbType === 'mysql'
  ? mysql.createPool({
    host: process.env.DB_HOST || 'database',
    port: Number(process.env.DB_PORT || defaultDbPort),
    database: process.env.DB_NAME || 'appdb',
    user: process.env.DB_USER || 'appuser',
    password: process.env.DB_PASSWORD || 'apppass',
    waitForConnections: true,
    connectionLimit: 10
  })
  : new Pool({
    host: process.env.DB_HOST || 'database',
    port: Number(process.env.DB_PORT || defaultDbPort),
    database: process.env.DB_NAME || 'appdb',
    user: process.env.DB_USER || 'appuser',
    password: process.env.DB_PASSWORD || 'apppass'
  });

// Without an 'error' listener an idle-client failure (e.g. a dropped DB
// connection) is emitted as an unhandled error and terminates the process.
pool.on('error', (err) => {
  console.error('Database pool error:', err);
});

app.get('/', (req, res) => {
  res.json([
    '!default route for index page, please use /api/* routes'
  ]);
});

app.get('/api/health', verifyToken, (req, res) => {
  res.json({
    status: 'healthy',
    backend: 'node',
    timestamp: Math.floor(Date.now() / 1000)
  });
});

app.get('/api/enum', verifyToken, async (req, res) => {
  res.json([
    'option 1',
    'option 2',
    'option 3'
  ]);
});

app.get('/api/list', verifyToken, async (req, res) => {
  res.json([
    'element 1',
    'element 2',
    'element 3'
  ]);
});

app.post('/api/install', async (req, res) => {
  console.log('/api/install', redactSensitive(req.body));
  res.json({
    message: 'All success'
  });
});

app.post('/api/getToken', async (req, res) => {
  try {
    // Issue a JWT only after the Bitrix24 OAuth server confirms the caller's token belongs to this app.
    const { domain, memberId } = await verifyFrontendAuth(req.body, { clientId: process.env.CLIENT_ID });
    const token = jwt.sign({ domain, member_id: memberId }, process.env.JWT_SECRET, { expiresIn: '1h' });

    console.log('/api/getToken issued', { domain, member_id: memberId });
    res.json({ token });
  } catch (error) {
    const status = error instanceof FrontendAuthError ? error.status : 500;
    console.warn('/api/getToken rejected', { status, reason: error.message, cause: error.cause?.message });
    res.status(status).json({ error: status === 500 ? 'Internal error' : error.message });
  }
});

const PORT = process.env.PORT || 8000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
