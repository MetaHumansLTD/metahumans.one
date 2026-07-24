<?php
declare(strict_types=1);

require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';
require_once __DIR__ . '/whm_mail.php';

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not a browsable page.\n";
    exit;
}

function mh_grid_customer_type_for_tenant(string $tenantId): string
{
    $t = strtolower(trim($tenantId));
    if (str_starts_with($t, 'company:')) return 'BUSINESS';
    return 'INDIVIDUAL';
}

function mh_grid_internal_email_domain(): string
{
    $cfg = function_exists('mh_grid_read_cfg') ? mh_grid_read_cfg() : [];
    $domain = isset($cfg['internal_email_domain']) && is_string($cfg['internal_email_domain'])
        ? trim((string)$cfg['internal_email_domain'])
        : '';
    if ($domain === '') {
        $domain = trim((string)(getenv('MH_GRID_INTERNAL_EMAIL_DOMAIN') ?: ''));
    }
    if ($domain === '') {
        $domain = trim((string)mh_grid_whm_default_email_domain_hint());
    }
    if ($domain === '') {
        $domain = 'onemeta.ai';
    }

    return ltrim($domain, '@');
}

function mh_grid_internal_email_otp_address_for_tenant(string $tenantId): string
{
    $t = function_exists('mh_grid_normalize_tenant_id')
        ? mh_grid_normalize_tenant_id($tenantId)
        : trim($tenantId);
    if ($t === '') {
        throw new RuntimeException('missing_tenant_id');
    }
    $h = hash('sha256', $t);
    return 'grid-' . substr($h, 0, 20) . '@' . mh_grid_internal_email_domain();
}

function mh_grid_synthetic_email_for_tenant(string $tenantId): string
{
    return mh_grid_internal_email_otp_address_for_tenant($tenantId);
}

function mh_grid_customer_get_by_tenant(PDO $db, string $tenantId): ?array
{
    if (function_exists('mh_grid_normalize_tenant_id')) {
        $tenantId = mh_grid_normalize_tenant_id($tenantId);
    }
    $stmt = $db->prepare("SELECT * FROM mh_settlement_customers WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_grid_customer_upsert(PDO $db, array $row): void
{
    $tenantId = trim((string)($row['tenant_id'] ?? ''));
    if (function_exists('mh_grid_normalize_tenant_id')) {
        $tenantId = mh_grid_normalize_tenant_id($tenantId);
    }
    if ($tenantId === '') {
        throw new RuntimeException('missing_tenant_id');
    }

    $platformCustomerId = trim((string)($row['platform_customer_id'] ?? $tenantId));
    $srCustomerId = trim((string)($row['sr_customer_id'] ?? ''));
    $customerType = trim((string)($row['customer_type'] ?? ''));
    if ($customerType === '') $customerType = mh_grid_customer_type_for_tenant($tenantId);
    $status = trim((string)($row['status'] ?? 'unknown'));
    if ($status === '') $status = 'unknown';

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_customers
            (tenant_id, platform_customer_id, sr_customer_id, customer_type, status, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            platform_customer_id = VALUES(platform_customer_id),
            sr_customer_id = VALUES(sr_customer_id),
            customer_type = VALUES(customer_type),
            status = VALUES(status),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([$tenantId, $platformCustomerId, ($srCustomerId !== '' ? $srCustomerId : null), $customerType, $status]);
}

function mh_grid_customer_find_in_sr(array $cfg, string $platformCustomerId): array
{
    return mh_grid_http_request($cfg, 'GET', '/customers', [
        'query' => [
            'platformCustomerId' => $platformCustomerId,
            'limit' => 1,
        ],
    ]);
}

function mh_grid_customer_create_in_sr(array $cfg, array $payload): array
{
    return mh_grid_http_request($cfg, 'POST', '/customers', [
        'json' => $payload,
        'idempotency_key' => isset($payload['platformCustomerId']) && is_string($payload['platformCustomerId'])
            ? mh_grid_idempotency_key((string)$payload['platformCustomerId'], 'create_customer', '')
            : '',
    ]);
}

function mh_grid_customer_link_or_create(string $tenantId, array $createFields = []): array
{
    $cfg = mh_grid_read_cfg();

    $db = mh_grid_get_db();
    if (!$db) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }
    mh_grid_ensure_tables($db);

    $tenantId = function_exists('mh_grid_normalize_tenant_id')
        ? mh_grid_normalize_tenant_id($tenantId)
        : trim($tenantId);
    if ($tenantId === '') {
        return ['ok' => false, 'error' => 'missing_tenant_id'];
    }

    $existing = mh_grid_customer_get_by_tenant($db, $tenantId);
    if (is_array($existing)) {
        $srId = trim((string)($existing['sr_customer_id'] ?? ''));
        if ($srId !== '') {
            return ['ok' => true, 'customer' => $existing, 'source' => 'db'];
        }
    }

    $platformCustomerId = $tenantId;
    $customerType = mh_grid_customer_type_for_tenant($tenantId);

    $lookup = mh_grid_customer_find_in_sr($cfg, $platformCustomerId);
    if (($lookup['ok'] ?? false) === true) {
        $data = is_array($lookup['json'] ?? null) ? ($lookup['json']['data'] ?? null) : null;
        $first = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        $srCustomerId = is_array($first) ? trim((string)($first['id'] ?? '')) : '';
        if ($srCustomerId !== '') {
            mh_grid_customer_upsert($db, [
                'tenant_id' => $tenantId,
                'platform_customer_id' => $platformCustomerId,
                'sr_customer_id' => $srCustomerId,
                'customer_type' => $customerType,
                'status' => 'linked',
            ]);
            $row = mh_grid_customer_get_by_tenant($db, $tenantId);
            return ['ok' => true, 'customer' => $row, 'source' => 'sr_lookup'];
        }
    } elseif (isset($lookup['error']) && is_string($lookup['error'])) {
        $e = (string)$lookup['error'];
        if ($e === 'grid_base_url_missing' || $e === 'grid_credentials_missing' || $e === 'endpoint_not_allowlisted') {
            return ['ok' => false, 'error' => $e, 'detail' => $lookup];
        }
    }

    $payload = [
        'customerType' => $customerType,
        'platformCustomerId' => $platformCustomerId,
    ];

    foreach ($createFields as $k => $v) {
        if (!is_string($k) || $k === '') continue;
        $payload[$k] = $v;
    }

    $email = $payload['email'] ?? null;
    if (!is_string($email) || trim($email) === '') {
        // Grid still requires an EMAIL_OTP address, but it must stay on the
        // platform's internal mail rail instead of a user inbox.
        $payload['email'] = mh_grid_internal_email_otp_address_for_tenant($tenantId);
    }

    $mailboxProvision = mh_grid_whm_ensure_internal_mailbox_for_email((string)$payload['email'], $tenantId);
    if (($mailboxProvision['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'internal_mailbox_provision_failed',
            'detail' => $mailboxProvision,
        ];
    }

    $fullNameSnake = $payload['full_name'] ?? null;
    $fullNameCamel = $payload['fullName'] ?? null;
    if (
        (!is_string($fullNameSnake) || trim($fullNameSnake) === '')
        && (!is_string($fullNameCamel) || trim($fullNameCamel) === '')
    ) {
        $name = 'MetaHumans ' . substr(hash('sha256', $tenantId), 0, 8);
        $payload['full_name'] = $name;
        $payload['fullName'] = $name;
    }

    if ($customerType === 'BUSINESS') {
        $bi = $payload['businessInfo'] ?? null;
        if (!is_array($bi)) {
            return ['ok' => false, 'error' => 'business_info_required'];
        }
    }

    $create = mh_grid_customer_create_in_sr($cfg, $payload);
    if (($create['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'sr_create_failed', 'detail' => $create];
    }

    $srCustomerId = '';
    if (is_array($create['json'] ?? null)) {
        $srCustomerId = trim((string)($create['json']['id'] ?? ''));
    }
    if ($srCustomerId === '') {
        return ['ok' => false, 'error' => 'sr_create_missing_id', 'detail' => $create];
    }

    mh_grid_customer_upsert($db, [
        'tenant_id' => $tenantId,
        'platform_customer_id' => $platformCustomerId,
        'sr_customer_id' => $srCustomerId,
        'customer_type' => $customerType,
        'status' => 'created',
    ]);

    $row = mh_grid_customer_get_by_tenant($db, $tenantId);
    return ['ok' => true, 'customer' => $row, 'source' => 'sr_create'];
}
