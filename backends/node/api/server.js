import { Pool } from 'pg';
import mysql from 'mysql2/promise';
import dotenv from 'dotenv';
import { createApp } from './app.js';
import { createAccountsRepository, createDb } from './db/accounts.js';

dotenv.config({ quiet: true });

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

const app = createApp({
  accounts: createAccountsRepository(createDb(pool, dbType)),
  clientId: process.env.CLIENT_ID,
  jwtSecret: process.env.JWT_SECRET,
  appUrl: process.env.NUXT_PUBLIC_API_URL,
});

const PORT = process.env.PORT || 8000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
