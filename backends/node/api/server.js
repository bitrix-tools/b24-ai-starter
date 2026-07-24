import express from 'express';
import cors from 'cors';
import { Pool } from 'pg';
import mysql from 'mysql2/promise';
import jwt from 'jsonwebtoken';
import verifyToken from './utils/verifyToken.js';

const app = express();
app.use(cors());
app.use(express.json());
// Bitrix24 sends install/event callbacks as application/x-www-form-urlencoded,
// so this parser is required for req.body to be populated on those requests.
app.use(express.urlencoded({ extended: true }));

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
  console.log('/api/install', req.body);
  res.json({
    message: 'All success'
  });
});

app.post('/api/getToken', async (req, res) => {
  console.log('/api/getToken', req.body);
  const appInfo = {
    id: 1
  };

  const token = jwt.sign(appInfo, process.env.JWT_SECRET, { expiresIn: '1h' });

  res.json({
    token: token
  });
});

const PORT = process.env.PORT || 8000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
