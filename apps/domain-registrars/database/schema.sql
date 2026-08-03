-- Initial scaffold schema for the standalone domain registrar service.
-- Uses portable SQL types where possible so it can be adapted to MariaDB or PostgreSQL.

CREATE TABLE provider_accounts (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    driver_class VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    environment VARCHAR(20) NOT NULL DEFAULT 'production',
    credentials_secret_ref VARCHAR(255) NULL,
    config_json JSON NULL,
    last_health_check_at TIMESTAMP NULL,
    last_health_status VARCHAR(20) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE customers (
    id CHAR(36) PRIMARY KEY,
    external_ref VARCHAR(100) NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE contacts (
    id CHAR(36) PRIMARY KEY,
    provider_account_id CHAR(36) NULL,
    customer_id CHAR(36) NULL,
    upstream_contact_id VARCHAR(100) NULL,
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
    CONSTRAINT fk_contacts_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id),
    CONSTRAINT fk_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE tlds (
    id CHAR(36) PRIMARY KEY,
    tld VARCHAR(50) NOT NULL UNIQUE,
    provider_code VARCHAR(50) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'standard',
    currency_code CHAR(3) NOT NULL DEFAULT 'ZAR',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tld_price_snapshots (
    id CHAR(36) PRIMARY KEY,
    tld_id CHAR(36) NOT NULL,
    provider_account_id CHAR(36) NOT NULL,
    source VARCHAR(30) NOT NULL,
    registration_price DECIMAL(12, 2) NULL,
    renewal_price DECIMAL(12, 2) NULL,
    transfer_price DECIMAL(12, 2) NULL,
    restore_price DECIMAL(12, 2) NULL,
    public_registration_price DECIMAL(12, 2) NULL,
    public_renewal_price DECIMAL(12, 2) NULL,
    public_transfer_price DECIMAL(12, 2) NULL,
    currency_code CHAR(3) NOT NULL DEFAULT 'ZAR',
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_tld_prices_tld FOREIGN KEY (tld_id) REFERENCES tlds(id),
    CONSTRAINT fk_tld_prices_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE TABLE domains (
    id CHAR(36) PRIMARY KEY,
    customer_id CHAR(36) NULL,
    provider_account_id CHAR(36) NOT NULL,
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
    CONSTRAINT uq_domains_provider_name UNIQUE (provider_account_id, domain_name),
    CONSTRAINT fk_domains_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_domains_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE INDEX idx_domains_customer_id ON domains(customer_id);
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
    CONSTRAINT fk_domain_statuses_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_domain_statuses_domain_id ON domain_statuses(domain_id);

CREATE TABLE domain_contact_links (
    id CHAR(36) PRIMARY KEY,
    domain_id CHAR(36) NOT NULL,
    contact_id CHAR(36) NOT NULL,
    contact_role VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_domain_contact_role UNIQUE (domain_id, contact_role),
    CONSTRAINT fk_domain_contact_links_domain FOREIGN KEY (domain_id) REFERENCES domains(id),
    CONSTRAINT fk_domain_contact_links_contact FOREIGN KEY (contact_id) REFERENCES contacts(id)
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
    CONSTRAINT fk_domain_nameservers_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_domain_nameservers_domain_id ON domain_nameservers(domain_id);

CREATE TABLE customer_orders (
    id CHAR(36) PRIMARY KEY,
    order_number VARCHAR(50) NOT NULL UNIQUE,
    customer_id CHAR(36) NOT NULL,
    provider_account_id CHAR(36) NOT NULL,
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
    CONSTRAINT fk_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_customer_orders_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id),
    CONSTRAINT fk_customer_orders_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_customer_orders_status ON customer_orders(status);
CREATE INDEX idx_customer_orders_domain_id ON customer_orders(domain_id);
CREATE INDEX idx_customer_orders_customer_id ON customer_orders(customer_id);

CREATE TABLE worker_tasks (
    id CHAR(36) PRIMARY KEY,
    task_type VARCHAR(100) NOT NULL,
    queue_name VARCHAR(50) NOT NULL DEFAULT 'default',
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    unique_key VARCHAR(255) NULL UNIQUE,
    priority INT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    payload_json JSON NOT NULL,
    result_json JSON NULL,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_worker_tasks_status_available ON worker_tasks(status, available_at);
CREATE INDEX idx_worker_tasks_queue_status ON worker_tasks(queue_name, status);

CREATE TABLE sync_job_definitions (
    id CHAR(36) PRIMARY KEY,
    job_code VARCHAR(100) NOT NULL UNIQUE,
    handler_class VARCHAR(255) NOT NULL,
    queue_name VARCHAR(50) NOT NULL,
    schedule_expression VARCHAR(100) NOT NULL,
    provider_scope VARCHAR(50) NOT NULL DEFAULT 'all',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    payload_template_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sync_job_runs (
    id CHAR(36) PRIMARY KEY,
    job_definition_id CHAR(36) NOT NULL,
    provider_account_id CHAR(36) NULL,
    requested_by_user_id CHAR(36) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    queue_name VARCHAR(50) NOT NULL,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    records_seen INT NOT NULL DEFAULT 0,
    records_created INT NOT NULL DEFAULT 0,
    records_updated INT NOT NULL DEFAULT 0,
    records_failed INT NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    result_json JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sync_job_runs_definition FOREIGN KEY (job_definition_id) REFERENCES sync_job_definitions(id),
    CONSTRAINT fk_sync_job_runs_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE INDEX idx_sync_job_runs_status ON sync_job_runs(status);
CREATE INDEX idx_sync_job_runs_provider ON sync_job_runs(provider_account_id);

CREATE TABLE domain_sync_events (
    id CHAR(36) PRIMARY KEY,
    domain_id CHAR(36) NOT NULL,
    provider_account_id CHAR(36) NOT NULL,
    job_run_id CHAR(36) NULL,
    sync_type VARCHAR(50) NOT NULL,
    sync_status VARCHAR(30) NOT NULL,
    source_checksum VARCHAR(255) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_domain_sync_events_domain FOREIGN KEY (domain_id) REFERENCES domains(id),
    CONSTRAINT fk_domain_sync_events_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id),
    CONSTRAINT fk_domain_sync_events_job FOREIGN KEY (job_run_id) REFERENCES sync_job_runs(id)
);

CREATE INDEX idx_domain_sync_events_domain_id ON domain_sync_events(domain_id);
CREATE INDEX idx_domain_sync_events_provider_id ON domain_sync_events(provider_account_id);

CREATE TABLE import_conflicts (
    id CHAR(36) PRIMARY KEY,
    provider_account_id CHAR(36) NOT NULL,
    domain_name VARCHAR(255) NOT NULL,
    conflict_type VARCHAR(50) NOT NULL,
    local_snapshot_json JSON NULL,
    remote_snapshot_json JSON NULL,
    resolution_status VARCHAR(30) NOT NULL DEFAULT 'open',
    resolved_by_user_id CHAR(36) NULL,
    resolved_at TIMESTAMP NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_conflicts_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE INDEX idx_import_conflicts_status ON import_conflicts(resolution_status);

CREATE TABLE provider_command_logs (
    id CHAR(36) PRIMARY KEY,
    provider_account_id CHAR(36) NOT NULL,
    domain_id CHAR(36) NULL,
    correlation_id VARCHAR(100) NOT NULL,
    command_name VARCHAR(100) NOT NULL,
    request_payload_json JSON NULL,
    response_payload_json JSON NULL,
    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_provider_command_logs_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id),
    CONSTRAINT fk_provider_command_logs_domain FOREIGN KEY (domain_id) REFERENCES domains(id)
);

CREATE INDEX idx_provider_command_logs_provider ON provider_command_logs(provider_account_id);
CREATE INDEX idx_provider_command_logs_domain ON provider_command_logs(domain_id);
