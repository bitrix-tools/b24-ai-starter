-- ==========================================================
--  MySQL schema for Bitrix24 integration (Doctrine ORM)
-- ==========================================================

CREATE TABLE IF NOT EXISTS bitrix24account (
    id CHAR(36) NOT NULL,
    b24_user_id INT NOT NULL,
    is_b24_user_admin BOOLEAN NOT NULL,
    member_id VARCHAR(255) NOT NULL,
    is_master_account BOOLEAN NULL,
    domain_url VARCHAR(255) NOT NULL,
    status VARCHAR(255) NOT NULL,
    application_token VARCHAR(255) NULL,
    created_at_utc DATETIME(3) NOT NULL,
    updated_at_utc DATETIME(3) NOT NULL,
    application_version INT NOT NULL,
    comment TEXT NULL,
    auth_token_access_token VARCHAR(255) NULL,
    auth_token_refresh_token VARCHAR(255) NULL,
    auth_token_expires BIGINT NULL,
    auth_token_expires_in BIGINT NULL,
    access_token VARCHAR(255) NULL,
    refresh_token VARCHAR(255) NULL,
    expires INT NULL,
    expires_in INT NULL,
    application_scope_current_scope JSON NULL,
    current_scope JSON NULL,
    -- NULL for soft-deleted accounts (status='deleted'); MySQL has no partial
    -- indexes but treats NULLs as distinct, so on re-install the previous
    -- (deleted) row and the fresh one no longer collide on the unique key,
    -- while two active accounts for the same (user, domain) are still rejected.
    -- See issue #8.
    active_account_guard TINYINT GENERATED ALWAYS AS (IF(status = 'deleted', NULL, 1)) VIRTUAL,
    PRIMARY KEY (id),
    UNIQUE KEY unique_b24_user_domain (b24_user_id, domain_url, active_account_guard),
    KEY idx_bitrix24account_member_id (member_id),
    KEY idx_bitrix24account_domain_url (domain_url)
);

CREATE TABLE IF NOT EXISTS application_installation (
    id CHAR(36) NOT NULL,
    status VARCHAR(255) NOT NULL,
    created_at_utc DATETIME(3) NOT NULL,
    update_at_utc DATETIME(3) NOT NULL,
    bitrix_24_account_id CHAR(36) NOT NULL,
    contact_person_id CHAR(36) NULL,
    bitrix_24_partner_contact_person_id CHAR(36) NULL,
    bitrix_24_partner_id CHAR(36) NULL,
    external_id VARCHAR(255) NULL,
    portal_license_family VARCHAR(255) NOT NULL,
    portal_users_count INT NULL,
    application_token VARCHAR(255) NULL,
    comment TEXT NULL,
    status_code JSON NULL,
    application_status_status_code JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_application_installation_account (bitrix_24_account_id),
    KEY idx_application_installation_status (status),
    KEY idx_application_installation_portal_license_family (portal_license_family),
    CONSTRAINT fk_application_installation_account
      FOREIGN KEY (bitrix_24_account_id) REFERENCES bitrix24account (id)
      ON DELETE CASCADE
);
