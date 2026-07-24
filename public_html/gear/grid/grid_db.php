<?php
declare(strict_types=1);

if (!function_exists('getContextAwareDatabase')) {
    require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
    $settingsPath = dirname(__DIR__) . '/settings/functions/database-context.php';
    if (file_exists($settingsPath)) {
        require_once $settingsPath;
    }
}

$mhGridRootPath = dirname(dirname(__DIR__));
if (!function_exists('database_getContextAwareConnection')) {
    $databasePath = $mhGridRootPath . '/.cue/database.php';
    if (is_file($databasePath)) {
        require_once $databasePath;
    }
}
if (!function_exists('mh_apply_tenant_context')) {
    $tenantProvisioningPath = $mhGridRootPath . '/auth/tenant_provisioning.php';
    if (is_file($tenantProvisioningPath)) {
        require_once $tenantProvisioningPath;
    }
}

function mh_grid_normalize_tenant_id(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return '';
    }

    if (stripos($tenantId, 'user:') === 0) {
        return 'user:' . strtolower(trim(substr($tenantId, 5)));
    }

    return $tenantId;
}

function mh_grid_current_tenant_id(): string
{
    $tenantId = $_SESSION['mh_tenant_id'] ?? '';
    if (is_string($tenantId) && trim($tenantId) !== '') {
        return mh_grid_normalize_tenant_id($tenantId);
    }

    $user = $_SESSION['mh_auth_user'] ?? '';
    if (is_string($user) && trim($user) !== '') {
        return mh_grid_normalize_tenant_id('user:' . $user);
    }

    return '';
}

function mh_grid_get_db(): ?PDO
{
    if (function_exists('database_getContextAwareConnection')) {
        try {
            $pdo = database_getContextAwareConnection();
            return $pdo instanceof PDO ? $pdo : null;
        } catch (Throwable $e) {
            $tenantId = $_SESSION['mh_tenant_id'] ?? '';
            $tenantId = is_string($tenantId) ? trim($tenantId) : '';
            if ($tenantId !== '') {
                if (!function_exists('mh_apply_tenant_context')) {
                    $tenantProvisioningPath = dirname(dirname(__DIR__)) . '/auth/tenant_provisioning.php';
                    if (is_file($tenantProvisioningPath)) {
                        require_once $tenantProvisioningPath;
                    }
                }
                if (function_exists('mh_apply_tenant_context') && mh_apply_tenant_context($tenantId)) {
                    $pdo = database_getContextAwareConnection();
                    return $pdo instanceof PDO ? $pdo : null;
                }
            }
            throw $e;
        }
    }
    if (function_exists('getContextAwareDatabase')) {
        $fn = 'getContextAwareDatabase';
        $pdo = $fn();
        return $pdo instanceof PDO ? $pdo : null;
    }
    return null;
}

function mh_grid_ensure_tables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            platform_customer_id VARCHAR(191) NOT NULL,
            sr_customer_id VARCHAR(191) NULL,
            customer_type VARCHAR(32) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'unknown',
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_settlement_customers_tenant (tenant_id),
            UNIQUE KEY uniq_mh_settlement_customers_platform (platform_customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            sr_internal_account_id VARCHAR(191) NOT NULL,
            account_type VARCHAR(64) NOT NULL,
            currency VARCHAR(16) NULL,
            label VARCHAR(255) NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'unknown',
            raw_snapshot_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_settlement_accounts (tenant_id, sr_internal_account_id),
            INDEX idx_mh_settlement_accounts_tenant (tenant_id),
            INDEX idx_mh_settlement_accounts_type (account_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_webhooks (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            webhook_id VARCHAR(191) NOT NULL,
            webhook_type VARCHAR(128) NOT NULL,
            signature_valid TINYINT(1) NOT NULL DEFAULT 0,
            raw_path VARCHAR(1024) NOT NULL,
            raw_sha256 CHAR(64) NOT NULL,
            received_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_settlement_webhooks (tenant_id, webhook_id),
            INDEX idx_mh_settlement_webhooks_tenant_time (tenant_id, received_at_utc),
            INDEX idx_mh_settlement_webhooks_type_time (webhook_type, received_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_auth_credentials (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            sr_internal_account_id VARCHAR(191) NOT NULL,
            sr_auth_credential_id VARCHAR(191) NOT NULL,
            credential_type VARCHAR(64) NOT NULL,
            nickname VARCHAR(255) NULL,
            platform_credential_id VARCHAR(512) NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'active',
            raw_snapshot_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_settlement_auth_credentials (tenant_id, sr_auth_credential_id),
            INDEX idx_mh_settlement_auth_credentials_account (tenant_id, sr_internal_account_id),
            INDEX idx_mh_settlement_auth_credentials_type (credential_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_auth_sessions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            sr_internal_account_id VARCHAR(191) NOT NULL,
            sr_auth_credential_id VARCHAR(191) NOT NULL,
            sr_auth_session_id VARCHAR(191) NULL,
            session_status VARCHAR(64) NOT NULL DEFAULT 'active',
            expires_at_utc DATETIME NULL,
            raw_snapshot_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_settlement_auth_sessions (tenant_id, sr_auth_credential_id, sr_auth_session_id),
            INDEX idx_mh_settlement_auth_sessions_tenant (tenant_id, expires_at_utc),
            INDEX idx_mh_settlement_auth_sessions_account (tenant_id, sr_internal_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_settlement_quotes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            sr_internal_account_id VARCHAR(191) NULL,
            sr_quote_id VARCHAR(191) NOT NULL,
            quote_status VARCHAR(64) NOT NULL DEFAULT 'unknown',
            source_type VARCHAR(64) NULL,
            destination_type VARCHAR(64) NULL,
            requires_grid_wallet_signature TINYINT(1) NOT NULL DEFAULT 0,
            payload_to_sign LONGTEXT NULL,
            transaction_id VARCHAR(191) NULL,
            expires_at_utc DATETIME NULL,
            raw_request_json LONGTEXT NULL,
            raw_snapshot_json LONGTEXT NULL,
            raw_execute_response_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NULL,
            executed_at_utc DATETIME NULL,
            UNIQUE KEY uniq_mh_settlement_quotes (tenant_id, sr_quote_id),
            INDEX idx_mh_settlement_quotes_tenant (tenant_id, updated_at_utc),
            INDEX idx_mh_settlement_quotes_account (tenant_id, sr_internal_account_id),
            INDEX idx_mh_settlement_quotes_status (tenant_id, quote_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
