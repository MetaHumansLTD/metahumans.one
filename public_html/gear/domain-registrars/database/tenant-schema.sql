CREATE TABLE customers (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    owner_type VARCHAR(30) NOT NULL,
    owner_id VARCHAR(120) NOT NULL,
    platform_user_id VARCHAR(120) NULL,
    platform_company_id VARCHAR(120) NULL,
    platform_persona_id VARCHAR(120) NULL,
    external_ref VARCHAR(100) NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_customers_tenant_email ON customers(tenant_id, email);
CREATE INDEX idx_customers_owner ON customers(owner_type, owner_id);

CREATE TABLE contacts (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    owner_type VARCHAR(30) NOT NULL,
    owner_id VARCHAR(120) NOT NULL,
    customer_id CHAR(36) NULL,
    upstream_contact_id VARCHAR(100) NULL,
    provider_code VARCHAR(50) NULL,
    role_hint VARCHAR(20) NULL,
    company_name VARCHAR(255) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NULL,
    address_line_1 VARCHAR(255) NULL,
    address_line_2 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    state_region VARCHAR(100) NULL,
    postal_code VARCHAR(30) NULL,
    country_code CHAR(2) NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dr_tenant_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE INDEX idx_contacts_tenant ON contacts(tenant_id, role_hint);

CREATE TABLE domains (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NOT NULL,
    owner_type VARCHAR(30) NOT NULL,
    owner_id VARCHAR(120) NOT NULL,
    acting_user_id VARCHAR(120) NULL,
    acting_persona_id VARCHAR(120) NULL,
    billing_mode VARCHAR(20) NOT NULL DEFAULT 'user',
    billing_tenant_id VARCHAR(120) NOT NULL,
    finance_event_ref VARCHAR(120) NULL,
    receipt_bundle_path VARCHAR(255) NULL,
    receipt_bundle_hash VARCHAR(255) NULL,
    external_action_ref VARCHAR(120) NULL,
    customer_id CHAR(36) NULL,
    provider_account_id CHAR(36) NOT NULL,
    provider_code VARCHAR(50) NOT NULL,
    domain_name VARCHAR(255) NOT NULL,
    tld VARCHAR(50) NOT NULL,
    upstream_domain_id VARCHAR(100) NULL,
    upstream_order_id VARCHAR(100) NULL,
    registrar_status VARCHAR(50) NOT NULL DEFAULT 'active',
    transfer_status VARCHAR(50) NULL,
    auth_code_state VARCHAR(30) NULL,
    auto_renew_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    registered_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    renewal_due_at TIMESTAMP NULL,
    grace_period_ends_at TIMESTAMP NULL,
    redemption_period_ends_at TIMESTAMP NULL,
    last_synced_at TIMESTAMP NULL,
    last_sync_source VARCHAR(30) NULL,
    last_sync_error TEXT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_dr_tenant_domains_provider_name UNIQUE (provider_account_id, domain_name),
    CONSTRAINT fk_dr_tenant_domains_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE INDEX idx_domains_tenant ON domains(tenant_id, domain_name);
CREATE INDEX idx_domains_owner ON domains(owner_type, owner_id);
CREATE INDEX idx_domains_expires_at ON domains(expires_at);
CREATE INDEX idx_domains_renewal_due_at ON domains(renewal_due_at);
CREATE INDEX idx_domains_transfer_status ON domains(transfer_status);

CREATE TABLE domain_statuses (
    id CHAR(36) PRIMARY KEY,
    domain_id CHAR(36) NOT NULL,
    status_code VARCHAR(100) NOT NULL,
    status_label VARCHAR(255) NULL,
    source VARCHAR(30) NOT NULL,
    observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dr_tenant_domain_statuses_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_domain_statuses_domain_id ON domain_statuses(domain_id);

CREATE TABLE domain_contact_links (
    id CHAR(36) PRIMARY KEY,
    domain_id CHAR(36) NOT NULL,
    contact_id CHAR(36) NOT NULL,
    contact_role VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_dr_tenant_domain_contact_role UNIQUE (domain_id, contact_role),
    CONSTRAINT fk_dr_tenant_domain_contact_links_domain FOREIGN KEY (domain_id) REFERENCES domains(id),
    CONSTRAINT fk_dr_tenant_domain_contact_links_contact FOREIGN KEY (contact_id) REFERENCES contacts(id)
);

CREATE TABLE domain_nameservers (
    id CHAR(36) PRIMARY KEY,
    domain_id CHAR(36) NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    ipv4_address VARCHAR(45) NULL,
    ipv6_address VARCHAR(45) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dr_tenant_domain_nameservers_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_domain_nameservers_domain_id ON domain_nameservers(domain_id);

CREATE TABLE customer_orders (
    id CHAR(36) PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    tenant_id VARCHAR(120) NOT NULL,
    owner_type VARCHAR(30) NOT NULL,
    owner_id VARCHAR(120) NOT NULL,
    acting_user_id VARCHAR(120) NULL,
    acting_persona_id VARCHAR(120) NULL,
    billing_mode VARCHAR(20) NOT NULL DEFAULT 'user',
    billing_tenant_id VARCHAR(120) NOT NULL,
    finance_event_ref VARCHAR(120) NULL,
    receipt_bundle_path VARCHAR(255) NULL,
    receipt_bundle_hash VARCHAR(255) NULL,
    reference_id VARCHAR(120) NULL UNIQUE,
    customer_id CHAR(36) NOT NULL,
    provider_account_id CHAR(36) NOT NULL,
    provider_code VARCHAR(50) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    action_type VARCHAR(30) NOT NULL,
    submission_mode VARCHAR(20) NOT NULL DEFAULT 'draft',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    period_years INT NOT NULL DEFAULT 1,
    currency_code CHAR(3) NOT NULL DEFAULT 'ZAR',
    total_amount DECIMAL(12, 2) NULL,
    customer_email VARCHAR(255) NOT NULL,
    payload_json JSON NOT NULL,
    provider_response_json JSON NULL,
    last_error TEXT NULL,
    submitted_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dr_tenant_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_dr_tenant_customer_orders_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_customer_orders_tenant_status ON customer_orders(tenant_id, status);
CREATE INDEX idx_customer_orders_domain_id ON customer_orders(domain_id);
CREATE INDEX idx_customer_orders_customer_id ON customer_orders(customer_id);
