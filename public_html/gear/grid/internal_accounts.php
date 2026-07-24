<?php
declare(strict_types=1);

require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';
require_once __DIR__ . '/customers.php';

function mh_grid_customer_id_for_tenant(PDO $db, string $tenantId): string
{
    if (function_exists('mh_grid_normalize_tenant_id')) {
        $tenantId = mh_grid_normalize_tenant_id($tenantId);
    }
    $stmt = $db->prepare("SELECT sr_customer_id FROM mh_settlement_customers WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return '';
    $sr = trim((string)($row['sr_customer_id'] ?? ''));
    return $sr;
}

function mh_grid_internal_accounts_list_for_customer(array $cfg, string $customerId, string $type = 'EMBEDDED_WALLET'): array
{
    return mh_grid_http_request($cfg, 'GET', '/customers/internal-accounts', [
        'query' => [
            'customerId' => $customerId,
            'type' => $type,
            'limit' => 100,
        ],
    ]);
}

function mh_grid_account_upsert(PDO $db, array $row): void
{
    $tenantId = trim((string)($row['tenant_id'] ?? ''));
    $srInternalId = trim((string)($row['sr_internal_account_id'] ?? ''));
    if ($tenantId === '' || $srInternalId === '') {
        throw new RuntimeException('missing_account_key');
    }

    $accountType = trim((string)($row['account_type'] ?? ''));
    if ($accountType === '') $accountType = 'unknown';
    $currency = isset($row['currency']) ? trim((string)$row['currency']) : null;
    $label = isset($row['label']) ? trim((string)$row['label']) : null;
    $status = trim((string)($row['status'] ?? 'unknown'));
    if ($status === '') $status = 'unknown';

    $rawSnapshot = $row['raw_snapshot_json'] ?? null;
    if ($rawSnapshot !== null && !is_string($rawSnapshot)) {
        $rawSnapshot = json_encode($rawSnapshot, JSON_UNESCAPED_SLASHES);
    }

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_accounts
            (tenant_id, sr_internal_account_id, account_type, currency, label, status, raw_snapshot_json, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            account_type = VALUES(account_type),
            currency = VALUES(currency),
            label = VALUES(label),
            status = VALUES(status),
            raw_snapshot_json = VALUES(raw_snapshot_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        $tenantId,
        $srInternalId,
        $accountType,
        ($currency !== '' ? $currency : null),
        ($label !== '' ? $label : null),
        $status,
        $rawSnapshot,
    ]);
}

function mh_grid_discover_embedded_wallet_account(string $tenantId, string $srCustomerId): array
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
    $srCustomerId = trim($srCustomerId);
    if ($tenantId === '' || $srCustomerId === '') {
        return ['ok' => false, 'error' => 'missing_customer'];
    }

    $resp = mh_grid_internal_accounts_list_for_customer($cfg, $srCustomerId, 'EMBEDDED_WALLET');
    if (($resp['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'sr_list_failed', 'detail' => $resp];
    }

    $data = is_array($resp['json'] ?? null) ? ($resp['json']['data'] ?? null) : null;
    if (!is_array($data) || $data === []) {
        return ['ok' => false, 'error' => 'no_accounts', 'detail' => $resp];
    }

    $saved = [];
    foreach ($data as $acct) {
        if (!is_array($acct)) continue;
        $id = trim((string)($acct['id'] ?? ''));
        if ($id === '') continue;
        $type = trim((string)($acct['type'] ?? ''));
        $currency = '';
        if (isset($acct['currency']) && is_array($acct['currency'])) {
            $currency = trim((string)($acct['currency']['code'] ?? ''));
        } elseif (isset($acct['currency']) && is_string($acct['currency'])) {
            $currency = trim((string)$acct['currency']);
        }
        mh_grid_account_upsert($db, [
            'tenant_id' => $tenantId,
            'sr_internal_account_id' => $id,
            'account_type' => ($type !== '' ? $type : 'EMBEDDED_WALLET'),
            'currency' => $currency,
            'label' => 'Treasury',
            'status' => 'discovered',
            'raw_snapshot_json' => $acct,
        ]);
        $saved[] = $id;
    }

    return ['ok' => true, 'saved' => $saved, 'count' => count($saved)];
}

function mh_grid_discover_embedded_wallet_accounts_for_tenant(string $tenantId): array
{
    $tenantId = function_exists('mh_grid_normalize_tenant_id')
        ? mh_grid_normalize_tenant_id($tenantId)
        : trim($tenantId);
    if ($tenantId === '') {
        return ['ok' => false, 'error' => 'missing_tenant_id'];
    }

    $db = mh_grid_get_db();
    if (!$db) {
        return ['ok' => false, 'error' => 'db_unavailable'];
    }
    mh_grid_ensure_tables($db);

    $srCustomerId = mh_grid_customer_id_for_tenant($db, $tenantId);
    if ($srCustomerId === '') {
        $linked = mh_grid_customer_link_or_create($tenantId);
        if (($linked['ok'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'missing_customer', 'detail' => ['tenant_id' => $tenantId, 'customer_link' => $linked]];
        }

        $srCustomerId = mh_grid_customer_id_for_tenant($db, $tenantId);
        if ($srCustomerId === '') {
            return ['ok' => false, 'error' => 'missing_customer', 'detail' => ['tenant_id' => $tenantId, 'customer_link' => $linked]];
        }
    }

    return mh_grid_discover_embedded_wallet_account($tenantId, $srCustomerId);
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $args = $argv ?? [];
    array_shift($args);

    $tenantId = '';
    $all = false;
    foreach ($args as $a) {
        if (!is_string($a)) continue;
        $s = trim($a);
        if ($s === '') continue;
        if ($s === '--all') {
            $all = true;
            continue;
        }
        if (str_starts_with($s, '--tenant=')) {
            $tenantId = trim(substr($s, strlen('--tenant=')));
            continue;
        }
        if ($tenantId === '') {
            $tenantId = $s;
        }
    }

    $db = mh_grid_get_db();
    if (!$db) {
        fwrite(STDERR, "db_unavailable\n");
        exit(2);
    }
    mh_grid_ensure_tables($db);

    $targets = [];
    if ($all) {
        $stmt = $db->query("SELECT DISTINCT tenant_id FROM mh_settlement_customers ORDER BY tenant_id ASC");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $t = trim((string)($r['tenant_id'] ?? ''));
                if ($t !== '') $targets[] = $t;
            }
        }
    } else {
        if ($tenantId === '') {
            fwrite(STDERR, "usage: php internal_accounts.php <tenant_id> | --all\n");
            exit(2);
        }
        $targets = [$tenantId];
    }

    $results = [];
    foreach ($targets as $t) {
        $results[] = [
            'tenant_id' => $t,
            'result' => mh_grid_discover_embedded_wallet_accounts_for_tenant($t),
        ];
    }

    echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
