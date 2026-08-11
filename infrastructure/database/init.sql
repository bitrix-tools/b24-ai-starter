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
    current_scope JSON,

    -- Unique constraint for combination of b24_user_id and domain_url
    CONSTRAINT unique_b24_user_domain UNIQUE (b24_user_id, domain_url)
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
-- Table: tochka_credentials
-------------------------------------------------------------
CREATE TABLE tochka_credentials (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    member_id VARCHAR NOT NULL UNIQUE,
    client_id VARCHAR NOT NULL,
    client_secret VARCHAR NOT NULL,
    access_token TEXT,
    refresh_token TEXT,
    token_expires_at TIMESTAMP(3),
    is_connected BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-------------------------------------------------------------
-- Table: tochka_payments
-------------------------------------------------------------
CREATE TABLE tochka_payments (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    member_id VARCHAR NOT NULL,
    account_id VARCHAR NOT NULL,
    transaction_id VARCHAR,
    payment_slip_number VARCHAR(255),
    amount DECIMAL(15,2) NOT NULL,
    amount_nat DECIMAL(15,2),
    currency VARCHAR(3) DEFAULT 'RUB',
    credit_debit VARCHAR(10) NOT NULL,
    counterparty_name VARCHAR,
    counterparty_inn VARCHAR(12),
    counterparty_account VARCHAR,
    counterparty_bik VARCHAR(9),
    purpose TEXT,
    invoice_number VARCHAR,
    vat_type VARCHAR(20),
    transaction_date TIMESTAMP NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    matched_deal_id INTEGER,
    matched_lead_id INTEGER,
    matched_invoice_id INTEGER,
    -- Deal info cache
    deal_title VARCHAR,
    deal_amount DECIMAL(15,2),
    deal_currency VARCHAR(3),
    deal_responsible_id INTEGER,
    deal_responsible_name VARCHAR,
    deal_responsible_photo VARCHAR,
    deal_paid_amount DECIMAL(15,2) DEFAULT 0,
    deal_paid_percent INTEGER DEFAULT 0,
    -- Invoice info cache (new smart invoices, entityTypeId=31)
    invoice_title VARCHAR,
    invoice_amount DECIMAL(15,2),
    invoice_currency VARCHAR(3),
    invoice_account_number VARCHAR,
    invoice_responsible_id INTEGER,
    invoice_responsible_name VARCHAR,
    invoice_responsible_photo VARCHAR,
    invoice_paid_amount DECIMAL(15,2) DEFAULT 0,
    invoice_paid_percent INTEGER DEFAULT 0,
    ledger_exclude BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_member_transaction UNIQUE (member_id, transaction_id)
);

-------------------------------------------------------------
-- Table: tochka_acquiring_operations (Django-managed mirror for fresh DB)
-------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tochka_acquiring_operations (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    member_id VARCHAR NOT NULL,
    deal_id INTEGER,
    lead_id INTEGER,
    amount DECIMAL(15,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'RUB',
    customer_code VARCHAR(255) NOT NULL,
    merchant_id VARCHAR(255),
    payment_link_id VARCHAR(255),
    external_operation_id VARCHAR(255),
    payment_url TEXT,
    payment_modes_requested JSONB DEFAULT '[]',
    payment_mode_effective VARCHAR(32),
    payment_kind VARCHAR(32),
    purpose TEXT,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    webhook_raw TEXT,
    duplicate_bank_payment_id UUID,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_tochka_acquiring_member ON tochka_acquiring_operations (member_id);
CREATE INDEX IF NOT EXISTS idx_tochka_acquiring_external_op ON tochka_acquiring_operations (external_operation_id);

-------------------------------------------------------------
-- Indexes (optional, but good for performance)
-------------------------------------------------------------
CREATE INDEX idx_bitrix24account_member_id ON bitrix24account (member_id);
CREATE INDEX idx_bitrix24account_domain_url ON bitrix24account (domain_url);

CREATE INDEX idx_application_installation_status ON application_installation (status);
CREATE INDEX idx_application_installation_portal_license_family ON application_installation (portal_license_family);

CREATE INDEX idx_tochka_credentials_member_id ON tochka_credentials (member_id);

CREATE INDEX idx_tochka_payments_member_id ON tochka_payments (member_id);
CREATE INDEX idx_tochka_payments_status ON tochka_payments (status);
CREATE INDEX idx_tochka_payments_date ON tochka_payments (transaction_date DESC);
