-- #8 / PR #23: allow re-installing the app on a database created before the fix.
-- The old schema had a full UNIQUE (b24_user_id, domain_url), so the SDK's
-- soft-deleted row (status = 'deleted') blocked the fresh one on re-install.
-- Idempotent: safe to run on new and already upgraded databases.
ALTER TABLE bitrix24account DROP CONSTRAINT IF EXISTS unique_b24_user_domain;

CREATE UNIQUE INDEX IF NOT EXISTS unique_b24_user_domain
    ON bitrix24account (b24_user_id, domain_url)
    WHERE status <> 'deleted';
