<?php
declare(strict_types=1);

require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/internal_accounts.php';

function mh_grid_bootstrap_existing_account_id(PDO $db, string $tenantId): string
{
    $stmt = $db->prepare("
        SELECT sr_internal_account_id
        FROM mh_settlement_accounts
        WHERE tenant_id = ? AND account_type = 'EMBEDDED_WALLET'
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? trim((string)($row['sr_internal_account_id'] ?? '')) : '';
}

function mh_grid_bootstrap_tenant(string $tenantId): array
{
    $tenantId = function_exists('mh_grid_normalize_tenant_id')
        ? mh_grid_normalize_tenant_id($tenantId)
        : trim($tenantId);
    if ($tenantId === '') {
        return ['ok' => false, 'error' => 'missing_tenant_id'];
    }

    $cfg = mh_grid_read_cfg();
    if (
        trim((string)($cfg['base_url'] ?? '')) === ''
        || trim((string)($cfg['token_id'] ?? '')) === ''
        || trim((string)($cfg['client_secret'] ?? '')) === ''
    ) {
        return ['ok' => false, 'error' => 'grid_not_configured', 'tenantId' => $tenantId];
    }

    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        return ['ok' => false, 'error' => 'db_unavailable', 'tenantId' => $tenantId];
    }
    mh_grid_ensure_tables($db);

    $existingCustomer = mh_grid_customer_get_by_tenant($db, $tenantId);
    $existingAccountId = mh_grid_bootstrap_existing_account_id($db, $tenantId);

    if (
        is_array($existingCustomer)
        && trim((string)($existingCustomer['sr_customer_id'] ?? '')) !== ''
        && $existingAccountId !== ''
    ) {
        return [
            'ok' => true,
            'tenantId' => $tenantId,
            'customer' => $existingCustomer,
            'accountId' => $existingAccountId,
            'source' => 'db',
        ];
    }

    $customer = is_array($existingCustomer) && trim((string)($existingCustomer['sr_customer_id'] ?? '')) !== ''
        ? ['ok' => true, 'customer' => $existingCustomer, 'source' => 'db']
        : mh_grid_customer_link_or_create($tenantId);
    if (($customer['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'customer_bootstrap_failed',
            'tenantId' => $tenantId,
            'detail' => $customer,
        ];
    }

    $accountBootstrap = $existingAccountId !== ''
        ? ['ok' => true, 'saved' => [$existingAccountId], 'count' => 1, 'source' => 'db']
        : mh_grid_discover_embedded_wallet_accounts_for_tenant($tenantId);
    if (($accountBootstrap['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'account_bootstrap_failed',
            'tenantId' => $tenantId,
            'detail' => $accountBootstrap,
        ];
    }

    $accountId = mh_grid_bootstrap_existing_account_id($db, $tenantId);

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'customer' => $customer['customer'] ?? mh_grid_customer_get_by_tenant($db, $tenantId),
        'accountId' => $accountId,
        'source' => 'bootstrap',
    ];
}

function mh_grid_bootstrap_user_tenant(string $username): array
{
    $username = trim($username);
    if ($username === '') {
        return ['ok' => false, 'error' => 'missing_username'];
    }

    return mh_grid_bootstrap_tenant('user:' . $username);
}

function mh_grid_bootstrap_user_tenant_best_effort(string $username): array
{
    try {
        return mh_grid_bootstrap_user_tenant($username);
    } catch (Throwable $e) {
        error_log('[GRID BOOTSTRAP] ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => 'grid_bootstrap_exception',
            'message' => $e->getMessage(),
            'username' => trim($username),
        ];
    }
}
