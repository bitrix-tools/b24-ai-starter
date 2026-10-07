-- ==========================================================
--  PostgreSQL schema for Bitrix24 integration (Doctrine ORM)
-- ==========================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-------------------------------------------------------------
-- Table: bitrix24account
-------------------------------------------------------------
CREATE TABLE bitrix24account (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    b24_user_id INTEGER NOT NULL,
    is_b24_user_admin BOOLEAN NOT NULL,
    member_id VARCHAR NOT NULL,
    is_master_account BOOLEAN,
    domain_url VARCHAR NOT NULL,
    status VARCHAR NOT NULL,
    application_token VARCHAR,
    created_at_utc TIMESTAMP(3) NOT NULL,
    updated_at_utc TIMESTAMP(3) NOT NULL,
    application_version INTEGER NOT NULL,
    comment TEXT,

    -- Embedded AuthToken fields
    auth_token_access_token VARCHAR,
    auth_token_refresh_token VARCHAR,
    auth_token_expires BIGINT,
    auth_token_expires_in BIGINT,
    access_token VARCHAR,
    refresh_token VARCHAR,
    expires INTEGER,
    expires_in INTEGER,

    -- Embedded Scope field
    application_scope_current_scope JSON,
    current_scope JSON
);

-------------------------------------------------------------
-- Table: application_installation
-------------------------------------------------------------
CREATE TABLE application_installation (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    status VARCHAR NOT NULL,
    created_at_utc TIMESTAMP(3) NOT NULL,
    update_at_utc TIMESTAMP(3) NOT NULL,
    bitrix_24_account_id UUID NOT NULL UNIQUE,
    contact_person_id UUID,
    bitrix_24_partner_contact_person_id UUID,
    bitrix_24_partner_id UUID,
    external_id VARCHAR,
    portal_license_family VARCHAR NOT NULL,
    portal_users_count INTEGER,
    application_token VARCHAR,
    comment TEXT,

    -- Embedded ApplicationStatus field
    status_code JSON,
    application_status_status_code JSON,

    CONSTRAINT fk_application_installation_account
        FOREIGN KEY (bitrix_24_account_id)
        REFERENCES bitrix24account (id)
        ON DELETE CASCADE
);

-------------------------------------------------------------
-- Indexes (optional, but good for performance)
-------------------------------------------------------------
CREATE INDEX idx_bitrix24account_member_id ON bitrix24account (member_id);
CREATE INDEX idx_bitrix24account_domain_url ON bitrix24account (domain_url);

-- Uniqueness of (b24_user_id, domain_url) ONLY among non-deleted accounts.
-- On re-install the SDK soft-deletes the previous account (status = 'deleted')
-- and inserts a fresh one; a plain UNIQUE constraint raised SQLSTATE[23505]
-- (duplicate key). This partial index preserves the single-active-account
-- guarantee while letting the soft-deleted row coexist. See issue #8.
CREATE UNIQUE INDEX unique_b24_user_domain
    ON bitrix24account (b24_user_id, domain_url)
    WHERE status <> 'deleted';

CREATE INDEX idx_application_installation_status ON application_installation (status);
CREATE INDEX idx_application_installation_portal_license_family ON application_installation (portal_license_family);
