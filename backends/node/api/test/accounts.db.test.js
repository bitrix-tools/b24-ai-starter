/**
 * db/accounts.js against a real database created from infrastructure/database/init*.sql.
 * Skipped unless a URL is given, e.g.:
 *   TEST_PG_URL=postgresql://appuser:apppass@localhost:5432/appdb \
 *   TEST_MYSQL_URL=mysql://appuser:apppass@localhost:3306/appdb pnpm test
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Pool } from 'pg';
import mysql from 'mysql2/promise';
import { createAccountsRepository, createDb } from '../db/accounts.js';

const targets = [
  ['postgresql', process.env.TEST_PG_URL, (url) => new Pool({ connectionString: url })],
  ['mysql', process.env.TEST_MYSQL_URL, (url) => mysql.createPool(url)],
];

for (const [dbType, url, connect] of targets) {
  test(`accounts lifecycle on ${dbType}`, { skip: !url && `set TEST_${dbType === 'mysql' ? 'MYSQL' : 'PG'}_URL` }, async () => {
    const pool = connect(url);
    const db = createDb(pool, dbType);
    const accounts = createAccountsRepository(db);
    const memberId = `m-${Date.now()}-${dbType}`;
    const domain = `${memberId}.bitrix24.ru`;
    const install = { memberId, domain, b24UserId: 7, isAdmin: true, accessToken: 'a1', refreshToken: 'r1', expiresIn: 3600, appVersion: 1 };

    try {
      await accounts.saveInstall(install);
      await accounts.saveInstall({ ...install, accessToken: 'a2' }); // same user again: update, not a 2nd row
      let rows = await accounts.findInstalled(memberId, domain);
      assert.equal(rows.length, 1);
      assert.equal(rows[0].status, 'new');
      assert.equal(rows[0].auth_token_access_token, 'a2');

      assert.equal(await accounts.activate(memberId, domain, 'app-token'), 1);
      rows = await accounts.findInstalled(memberId, domain);
      assert.equal(rows[0].status, 'active');

      assert.equal(await accounts.markDeleted(memberId, 'wrong-token'), 0);
      assert.equal(await accounts.markDeleted(memberId, 'app-token'), 1);
      assert.equal((await accounts.findInstalled(memberId, domain)).length, 0);

      // Re-install after uninstall (#8): the soft-deleted row must not block a fresh one.
      await accounts.saveInstall(install);
      rows = await accounts.findInstalled(memberId, domain);
      assert.equal(rows.length, 1);
      assert.equal(rows[0].status, 'new');
    } finally {
      await db.query('DELETE FROM bitrix24account WHERE member_id = ?', [memberId]);
      await pool.end();
    }
  });
}
