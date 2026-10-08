-- #8 / PR #23: allow re-installing the app on a database created before the fix.
-- MySQL has no partial indexes: a generated column that is NULL for soft-deleted
-- rows joins the unique key (NULLs never collide). Same as init-mysql.sql.
-- Idempotent: does nothing if active_account_guard already exists.
SET @has_guard := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'bitrix24account'
       AND COLUMN_NAME = 'active_account_guard'
);

SET @upgrade := IF(@has_guard = 0,
    'ALTER TABLE bitrix24account
         ADD COLUMN active_account_guard TINYINT GENERATED ALWAYS AS (IF(status = ''deleted'', NULL, 1)) VIRTUAL,
         DROP INDEX unique_b24_user_domain,
         ADD UNIQUE KEY unique_b24_user_domain (b24_user_id, domain_url, active_account_guard)',
    'DO 0');

PREPARE upgrade_stmt FROM @upgrade;
EXECUTE upgrade_stmt;
DEALLOCATE PREPARE upgrade_stmt;
