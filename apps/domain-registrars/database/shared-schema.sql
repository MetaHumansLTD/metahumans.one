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
    CONSTRAINT fk_shared_tld_prices_tld FOREIGN KEY (tld_id) REFERENCES tlds(id),
    CONSTRAINT fk_shared_tld_prices_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE TABLE worker_tasks (
    id CHAR(36) PRIMARY KEY,
    task_type VARCHAR(100) NOT NULL,
    queue_name VARCHAR(50) NOT NULL DEFAULT 'default',
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    unique_key VARCHAR(255) NULL UNIQUE,
    priority INT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    tenant_id VARCHAR(120) NULL,
    tenant_db_config_id VARCHAR(120) NULL,
    owner_type VARCHAR(30) NULL,
    owner_id VARCHAR(120) NULL,
    acting_user_id VARCHAR(120) NULL,
    acting_persona_id VARCHAR(120) NULL,
    billing_mode VARCHAR(20) NULL,
    billing_tenant_id VARCHAR(120) NULL,
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
CREATE INDEX idx_worker_tasks_tenant ON worker_tasks(tenant_id, status);

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
    tenant_id VARCHAR(120) NULL,
    tenant_db_config_id VARCHAR(120) NULL,
    requested_by_user_id VARCHAR(120) NULL,
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
CREATE INDEX idx_sync_job_runs_tenant ON sync_job_runs(tenant_id);

CREATE TABLE domain_sync_events (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NULL,
    tenant_domain_id CHAR(36) NULL,
    domain_name VARCHAR(255) NOT NULL,
    provider_account_id CHAR(36) NOT NULL,
    provider_code VARCHAR(50) NOT NULL,
    job_run_id CHAR(36) NULL,
    sync_type VARCHAR(50) NOT NULL,
    sync_status VARCHAR(30) NOT NULL,
    source_checksum VARCHAR(255) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_domain_sync_events_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id),
    CONSTRAINT fk_domain_sync_events_job FOREIGN KEY (job_run_id) REFERENCES sync_job_runs(id)
);

CREATE INDEX idx_domain_sync_events_domain_name ON domain_sync_events(domain_name);
CREATE INDEX idx_domain_sync_events_provider_id ON domain_sync_events(provider_account_id);
CREATE INDEX idx_domain_sync_events_tenant ON domain_sync_events(tenant_id);

CREATE TABLE import_conflicts (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NULL,
    provider_account_id CHAR(36) NOT NULL,
    domain_name VARCHAR(255) NOT NULL,
    conflict_type VARCHAR(50) NOT NULL,
    local_snapshot_json JSON NULL,
    remote_snapshot_json JSON NULL,
    resolution_status VARCHAR(30) NOT NULL DEFAULT 'open',
    resolved_by_user_id VARCHAR(120) NULL,
    resolved_at TIMESTAMP NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_import_conflicts_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE INDEX idx_import_conflicts_status ON import_conflicts(resolution_status);
CREATE INDEX idx_import_conflicts_tenant ON import_conflicts(tenant_id);

CREATE TABLE provider_command_logs (
    id CHAR(36) PRIMARY KEY,
    tenant_id VARCHAR(120) NULL,
    tenant_domain_id CHAR(36) NULL,
    provider_account_id CHAR(36) NOT NULL,
    provider_code VARCHAR(50) NOT NULL,
    domain_name VARCHAR(255) NULL,
    correlation_id VARCHAR(100) NOT NULL,
    command_name VARCHAR(100) NOT NULL,
    request_payload_json JSON NULL,
    response_payload_json JSON NULL,
    was_successful BOOLEAN NOT NULL DEFAULT FALSE,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_provider_command_logs_provider FOREIGN KEY (provider_account_id) REFERENCES provider_accounts(id)
);

CREATE INDEX idx_provider_command_logs_provider ON provider_command_logs(provider_account_id);
CREATE INDEX idx_provider_command_logs_tenant ON provider_command_logs(tenant_id);
