<?php
declare(strict_types=1);

require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/internal_accounts.php';

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not a browsable page.\n";
    exit;
}

function mh_grid_admin_reprovision_normalize_tenant_id(string $tenantId): string
{
    $tenantId = function_exists('mh_grid_normalize_tenant_id')
        ? mh_grid_normalize_tenant_id($tenantId)
        : trim($tenantId);

    return trim($tenantId);
}

function mh_grid_admin_reprovision_platform_customer_id(string $tenantId): string
{
    $suffix = gmdate('YmdHis');
    try {
        $suffix .= '-' . bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix .= '-' . substr(hash('sha256', uniqid('', true)), 0, 8);
    }

    return $tenantId . '--reprovision-' . $suffix;
}

function mh_grid_admin_reprovision_internal_email_otp_address(string $tenantId, array $options = []): string
{
    $email = trim((string)($options['email'] ?? ''));
    if ($email !== '') {
        return $email;
    }

    return mh_grid_internal_email_otp_address_for_tenant($tenantId);
}

function mh_grid_admin_reprovision_list_local_accounts(PDO $db, string $tenantId): array
{
    $stmt = $db->prepare("
        SELECT sr_internal_account_id, account_type, currency, label, status
        FROM mh_settlement_accounts
        WHERE tenant_id = ?
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
    ");
    $stmt->execute([$tenantId]);

    $accounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $accounts[] = [
            'accountId' => trim((string)($row['sr_internal_account_id'] ?? '')),
            'accountType' => trim((string)($row['account_type'] ?? '')),
            'currency' => trim((string)($row['currency'] ?? '')),
            'label' => trim((string)($row['label'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
        ];
    }

    return $accounts;
}

function mh_grid_admin_reprovision_local_snapshot(PDO $db, string $tenantId): array
{
    $customer = mh_grid_customer_get_by_tenant($db, $tenantId);
    $accounts = mh_grid_admin_reprovision_list_local_accounts($db, $tenantId);

    $stmt = $db->prepare("SELECT COUNT(*) FROM mh_settlement_auth_credentials WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    $credentialCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM mh_settlement_auth_sessions WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
    $sessionCount = (int)$stmt->fetchColumn();

    return [
        'customer' => is_array($customer) ? [
            'platformCustomerId' => trim((string)($customer['platform_customer_id'] ?? '')),
            'srCustomerId' => trim((string)($customer['sr_customer_id'] ?? '')),
            'customerType' => trim((string)($customer['customer_type'] ?? '')),
            'status' => trim((string)($customer['status'] ?? '')),
        ] : null,
        'accounts' => $accounts,
        'credentialCount' => $credentialCount,
        'sessionCount' => $sessionCount,
    ];
}

function mh_grid_admin_reprovision_fetch_embedded_wallet_accounts(string $srCustomerId): array
{
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_internal_accounts_list_for_customer($cfg, $srCustomerId, 'EMBEDDED_WALLET');
    if (($resp['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'embedded_wallet_lookup_failed',
            'message' => 'Grid did not return the new embedded wallet account list.',
            'detail' => $resp,
        ];
    }

    $data = is_array($resp['json'] ?? null) ? ($resp['json']['data'] ?? null) : null;
    if (!is_array($data)) {
        $data = [];
    }

    $accounts = [];
    foreach ($data as $acct) {
        if (!is_array($acct)) {
            continue;
        }
        $accountId = trim((string)($acct['id'] ?? ''));
        if ($accountId === '') {
            continue;
        }
        $accounts[] = $acct;
    }

    if ($accounts === []) {
        return [
            'ok' => false,
            'error' => 'embedded_wallet_missing',
            'message' => 'Grid created the customer but no embedded wallet account is discoverable yet.',
            'detail' => $resp,
        ];
    }

    return [
        'ok' => true,
        'accounts' => $accounts,
        'detail' => $resp,
    ];
}

function mh_grid_admin_reprovision_clear_local_wallet_state(PDO $db, string $tenantId): void
{
    $stmt = $db->prepare("DELETE FROM mh_settlement_auth_sessions WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);

    $stmt = $db->prepare("DELETE FROM mh_settlement_auth_credentials WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);

    $stmt = $db->prepare("DELETE FROM mh_settlement_accounts WHERE tenant_id = ?");
    $stmt->execute([$tenantId]);
}

function mh_grid_admin_reprovision_store_accounts(PDO $db, string $tenantId, array $accounts): array
{
    $saved = [];
    foreach ($accounts as $acct) {
        if (!is_array($acct)) {
            continue;
        }
        $accountId = trim((string)($acct['id'] ?? ''));
        if ($accountId === '') {
            continue;
        }

        $type = trim((string)($acct['type'] ?? ''));
        $currency = '';
        if (isset($acct['currency']) && is_array($acct['currency'])) {
            $currency = trim((string)($acct['currency']['code'] ?? ''));
        } elseif (isset($acct['currency']) && is_string($acct['currency'])) {
            $currency = trim((string)$acct['currency']);
        }

        mh_grid_account_upsert($db, [
            'tenant_id' => $tenantId,
            'sr_internal_account_id' => $accountId,
            'account_type' => ($type !== '' ? $type : 'EMBEDDED_WALLET'),
            'currency' => $currency,
            'label' => 'Treasury',
            'status' => 'reprovisioned',
            'raw_snapshot_json' => $acct,
        ]);
        $saved[] = $accountId;
    }

    return $saved;
}

function mh_grid_admin_reprovision_bootstrap_credential(string $tenantId, array $options = []): array
{
    $tenantId = mh_grid_admin_reprovision_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return [
            'ok' => false,
            'error' => 'missing_tenant_id',
            'message' => 'Tenant ID is required.',
        ];
    }

    $cfg = mh_grid_read_cfg();
    if (
        trim((string)($cfg['base_url'] ?? '')) === ''
        || trim((string)($cfg['token_id'] ?? '')) === ''
        || trim((string)($cfg['client_secret'] ?? '')) === ''
    ) {
        return [
            'ok' => false,
            'error' => 'grid_not_configured',
            'message' => 'Grid platform credentials are not configured.',
        ];
    }

    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        return [
            'ok' => false,
            'error' => 'db_unavailable',
            'message' => 'Grid database is unavailable.',
        ];
    }
    mh_grid_ensure_tables($db);

    $snapshot = mh_grid_admin_reprovision_local_snapshot($db, $tenantId);
    $customerType = trim((string)($snapshot['customer']['customerType'] ?? mh_grid_customer_type_for_tenant($tenantId)));
    if ($customerType === '') {
        $customerType = mh_grid_customer_type_for_tenant($tenantId);
    }
    if (strcasecmp($customerType, 'INDIVIDUAL') !== 0) {
        return [
            'ok' => false,
            'error' => 'business_reprovision_not_supported',
            'message' => 'This reprovision helper currently supports INDIVIDUAL tenants only.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
        ];
    }

    $email = mh_grid_admin_reprovision_internal_email_otp_address($tenantId, $options);

    $platformCustomerId = trim((string)($options['platformCustomerId'] ?? ''));
    if ($platformCustomerId === '') {
        $platformCustomerId = mh_grid_admin_reprovision_platform_customer_id($tenantId);
    }

    $fullName = trim((string)($options['fullName'] ?? ''));
    if ($fullName === '') {
        $fullName = 'MetaHumans ' . substr(hash('sha256', $platformCustomerId), 0, 8);
    }

    $createPayload = [
        'customerType' => $customerType,
        'platformCustomerId' => $platformCustomerId,
        'email' => $email,
        'full_name' => $fullName,
        'fullName' => $fullName,
    ];

    $mailboxProvision = mh_grid_whm_ensure_internal_mailbox_for_email($email, $tenantId);
    if (($mailboxProvision['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'internal_mailbox_provision_failed',
            'message' => 'The internal Grid EMAIL_OTP mailbox could not be provisioned before creating the reprovisioned customer.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
            'detail' => $mailboxProvision,
            'createPayload' => $createPayload,
        ];
    }

    $create = mh_grid_customer_create_in_sr($cfg, $createPayload);
    if (($create['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'customer_reprovision_create_failed',
            'message' => 'Grid rejected the new customer reprovision request for the platform-routed EMAIL_OTP address.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
            'detail' => $create,
            'createPayload' => $createPayload,
        ];
    }

    $createdCustomer = is_array($create['json'] ?? null) ? $create['json'] : [];
    $srCustomerId = trim((string)($createdCustomer['id'] ?? ''));
    if ($srCustomerId === '') {
        return [
            'ok' => false,
            'error' => 'customer_reprovision_missing_id',
            'message' => 'Grid created the customer but did not return a customer ID.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
            'detail' => $create,
        ];
    }

    $accountsResult = mh_grid_admin_reprovision_fetch_embedded_wallet_accounts($srCustomerId);
    if (($accountsResult['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string)($accountsResult['error'] ?? 'embedded_wallet_lookup_failed'),
            'message' => (string)($accountsResult['message'] ?? 'Grid did not expose a fresh embedded wallet account for the new customer.'),
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
            'createdCustomer' => [
                'platformCustomerId' => $platformCustomerId,
                'srCustomerId' => $srCustomerId,
                'email' => $email,
            ],
            'detail' => $accountsResult['detail'] ?? null,
        ];
    }

    $savedAccountIds = [];
    $committed = false;
    try {
        $db->beginTransaction();
        mh_grid_admin_reprovision_clear_local_wallet_state($db, $tenantId);
        mh_grid_customer_upsert($db, [
            'tenant_id' => $tenantId,
            'platform_customer_id' => $platformCustomerId,
            'sr_customer_id' => $srCustomerId,
            'customer_type' => $customerType,
            'status' => 'reprovisioned',
        ]);
        $savedAccountIds = mh_grid_admin_reprovision_store_accounts($db, $tenantId, $accountsResult['accounts'] ?? []);
        $db->commit();
        $committed = true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return [
            'ok' => false,
            'error' => 'local_rebind_failed',
            'message' => 'Grid created a fresh customer/account, but the local tenant rebind failed.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
            'createdCustomer' => [
                'platformCustomerId' => $platformCustomerId,
                'srCustomerId' => $srCustomerId,
                'email' => $email,
            ],
            'exceptionMessage' => $e->getMessage(),
        ];
    }

    if (!$committed) {
        return [
            'ok' => false,
            'error' => 'local_rebind_failed',
            'message' => 'Grid created a fresh customer/account, but the local tenant rebind did not commit.',
            'tenantId' => $tenantId,
            'snapshot' => $snapshot,
        ];
    }

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'message' => 'Tenant now points at a freshly provisioned Grid customer/account using the platform-routed EMAIL_OTP address. The old Grid wallet was not repaired in place.',
        'previous' => $snapshot,
        'current' => [
            'platformCustomerId' => $platformCustomerId,
            'srCustomerId' => $srCustomerId,
            'customerType' => $customerType,
            'email' => $email,
            'accountIds' => $savedAccountIds,
        ],
    ];
}
