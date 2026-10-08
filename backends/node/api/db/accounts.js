import { randomUUID } from 'node:crypto';

/**
 * Bitrix24 accounts (one row per portal user who installed the app) in the
 * `bitrix24account` table from infrastructure/database/init*.sql — the same
 * table the PHP backend uses, for PostgreSQL and MySQL.
 *
 * Lifecycle (same as PHP):
 *   /api/install   -> row with status 'new' + OAuth tokens
 *   ONAPPINSTALL   -> application_token stored, status 'active'
 *   ONAPPUNINSTALL -> status 'deleted' (only with the stored application_token)
 *
 * Soft-deleted rows stay; uniqueness of (b24_user_id, domain_url) only covers
 * non-deleted rows (see #8), so a re-install inserts a fresh row.
 */

const INSTALLED = ['new', 'active'];

/**
 * @param {{ query: (sql: string, params: unknown[]) => Promise<Record<string, any>[]> }} db
 */
export function createAccountsRepository(db) {
  const now = () => new Date();

  return {
    /** Accounts of a portal that are installed (status new/active). */
    async findInstalled(memberId, domain) {
      return db.query(
        `SELECT * FROM bitrix24account
          WHERE member_id = ? AND domain_url = ? AND status IN (?, ?)`,
        [memberId, domain, ...INSTALLED],
      );
    },

    /** Insert or refresh the installing user's account (status 'new'). */
    async saveInstall({ memberId, domain, b24UserId, isAdmin, accessToken, refreshToken, expiresIn, appVersion }) {
      const expires = Math.floor(Date.now() / 1000) + Number(expiresIn || 3600);
      const updated = await db.query(
        `UPDATE bitrix24account
            SET is_b24_user_admin = ?, member_id = ?, auth_token_access_token = ?,
                auth_token_refresh_token = ?, auth_token_expires = ?, auth_token_expires_in = ?,
                application_version = ?, updated_at_utc = ?
          WHERE b24_user_id = ? AND domain_url = ? AND status IN (?, ?)`,
        [isAdmin, memberId, accessToken, refreshToken, expires, Number(expiresIn || 3600),
          appVersion, now(), b24UserId, domain, ...INSTALLED],
      );
      if (affected(updated) > 0) {
        return;
      }
      await db.query(
        `INSERT INTO bitrix24account
           (id, b24_user_id, is_b24_user_admin, member_id, is_master_account, domain_url, status,
            created_at_utc, updated_at_utc, application_version,
            auth_token_access_token, auth_token_refresh_token, auth_token_expires, auth_token_expires_in)
         VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?)`,
        [randomUUID(), b24UserId, isAdmin, memberId, isAdmin, domain, now(), now(), appVersion,
          accessToken, refreshToken, expires, Number(expiresIn || 3600)],
      );
    },

    /** ONAPPINSTALL: remember the application token and activate the portal's accounts. */
    async activate(memberId, domain, applicationToken) {
      const result = await db.query(
        `UPDATE bitrix24account
            SET application_token = ?, status = 'active', updated_at_utc = ?
          WHERE member_id = ? AND domain_url = ? AND status IN (?, ?)`,
        [applicationToken, now(), memberId, domain, ...INSTALLED],
      );
      return affected(result);
    },

    /** ONAPPUNINSTALL: soft-delete, but only if the application token matches. */
    async markDeleted(memberId, applicationToken) {
      const result = await db.query(
        `UPDATE bitrix24account
            SET status = 'deleted', updated_at_utc = ?
          WHERE member_id = ? AND application_token = ? AND status IN (?, ?)`,
        [now(), memberId, applicationToken, ...INSTALLED],
      );
      return affected(result);
    },
  };
}

function affected(result) {
  return Number(result?.affectedRows ?? result?.rowCount ?? 0);
}

/**
 * Adapt a pg Pool / mysql2 pool to `query(sql, params)` with `?` placeholders.
 * SELECT returns rows; UPDATE/INSERT return `{ affectedRows }`.
 */
export function createDb(pool, dbType) {
  if (dbType === 'mysql') {
    return {
      async query(sql, params) {
        const [result] = await pool.execute(sql, params);
        return Array.isArray(result) ? result : { affectedRows: result.affectedRows };
      },
    };
  }
  return {
    async query(sql, params) {
      let i = 0;
      const result = await pool.query(sql.replace(/\?/g, () => `$${++i}`), params);
      return result.command === 'SELECT' ? result.rows : { affectedRows: result.rowCount };
    },
  };
}
