<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) {
    define('CUE_DISABLE_AUTO_UI', true);
}
if (!defined('CUE_LAYOUT_MANUAL')) {
    define('CUE_LAYOUT_MANUAL', true);
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../accounting/finance_gateway.php';
require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';

function mh_grid_tx_is_terminal(string $status): bool
{
    return in_array(strtoupper(trim($status)), ['COMPLETED', 'REJECTED', 'FAILED', 'REFUNDED', 'EXPIRED'], true);
}

function mh_grid_tx_parse_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function mh_grid_tx_print(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$opts = getopt('', ['limit::', 'min-age-minutes::', 'tenant::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 50;
$minAgeMinutes = isset($opts['min-age-minutes']) ? (int)$opts['min-age-minutes'] : 5;
$tenantFilter = isset($opts['tenant']) ? trim((string)$opts['tenant']) : '';

if ($limit <= 0 || $limit > 500) {
    $limit = 50;
}
if ($minAgeMinutes < 0 || $minAgeMinutes > 1440) {
    $minAgeMinutes = 5;
}

try {
    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('db_unavailable');
    }
    mh_grid_ensure_tables($db);
} catch (Throwable $e) {
    mh_grid_tx_print(['ok' => false, 'error' => 'db_unavailable', 'message' => $e->getMessage()]);
    exit(1);
}

$terminalStatuses = ['COMPLETED', 'REJECTED', 'FAILED', 'REFUNDED', 'EXPIRED'];
$placeholders = implode(',', array_fill(0, count($terminalStatuses), '?'));

$sql = "
    SELECT tenant_id, sr_quote_id, transaction_id, quote_status, updated_at_utc, created_at_utc
    FROM mh_settlement_quotes
    WHERE transaction_id IS NOT NULL
      AND transaction_id <> ''
      AND (quote_status IS NULL OR UPPER(quote_status) NOT IN ($placeholders))
      AND (
        updated_at_utc IS NULL
        OR updated_at_utc < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)
      )
";
$params = array_merge($terminalStatuses, [$minAgeMinutes]);

if ($tenantFilter !== '') {
    $sql .= " AND tenant_id = ? ";
    $params[] = $tenantFilter;
}

$sql .= " ORDER BY COALESCE(updated_at_utc, created_at_utc) ASC, id ASC LIMIT " . (int)$limit;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows) || count($rows) === 0) {
    mh_grid_tx_print(['ok' => true, 'scanned' => 0, 'updated' => 0]);
    exit(0);
}

$cfg = mh_grid_read_cfg();
$updated = 0;
$scanned = 0;
$failures = 0;
$financeEvents = 0;

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $tenantId = trim((string)($row['tenant_id'] ?? ''));
    $quoteId = trim((string)($row['sr_quote_id'] ?? ''));
    $transactionId = trim((string)($row['transaction_id'] ?? ''));
    if ($tenantId === '' || $quoteId === '' || $transactionId === '') {
        continue;
    }
    $scanned += 1;

    $resp = mh_grid_http_request($cfg, 'GET', '/transactions/' . rawurlencode($transactionId), []);
    if (($resp['ok'] ?? false) !== true) {
        $failures += 1;
        continue;
    }

    $tx = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $status = strtoupper(trim((string)($tx['status'] ?? '')));
    if ($status === '') {
        continue;
    }

    $executedAt = null;
    if (mh_grid_tx_is_terminal($status)) {
        $executedAt = mh_grid_tx_parse_datetime((string)($tx['settledAt'] ?? '')) ?: mh_grid_tx_parse_datetime((string)($tx['updatedAt'] ?? '')) ?: gmdate('Y-m-d H:i:s');
    }

    $upd = $db->prepare("
        UPDATE mh_settlement_quotes
        SET quote_status = ?,
            updated_at_utc = UTC_TIMESTAMP(),
            executed_at_utc = CASE
                WHEN executed_at_utc IS NULL AND ? IS NOT NULL THEN ?
                ELSE executed_at_utc
            END
        WHERE tenant_id = ? AND sr_quote_id = ?
    ");
    $upd->execute([
        $status,
        $executedAt,
        $executedAt,
        $tenantId,
        $quoteId,
    ]);
    $updated += 1;

    if (mh_grid_tx_is_terminal($status) && function_exists('mh_finance_gateway_capture_grid_terminal_event')) {
        try {
            $posted = mh_finance_gateway_capture_grid_terminal_event([
                'tenantId' => $tenantId,
                'quoteId' => $quoteId,
                'transactionId' => $transactionId,
                'status' => $status,
                'occurredAtUtc' => (string)($tx['settledAt'] ?? $tx['updatedAt'] ?? ''),
                'transaction' => $tx,
                'sourceChannel' => 'grid_reconcile_poll',
                'principalId' => 'system:grid_reconcile',
                'username' => 'grid-reconcile',
                'role' => 'system',
                'source' => 'grid_reconcile_poll',
            ]);
            if (is_array($posted) && trim((string)($posted['eventKey'] ?? '')) !== '') {
                $financeEvents += 1;
            }
        } catch (Throwable $e) {
            if (function_exists('mh_finance_record_exception')) {
                mh_finance_record_exception(
                    $db,
                    $tenantId,
                    'finance:grid:' . hash('sha256', implode('|', [$tenantId, $quoteId, $transactionId, $status])),
                    'finance_event_post_failed',
                    $e->getMessage(),
                    [
                        'source' => 'grid_reconcile_poll',
                        'transactionId' => $transactionId,
                        'quoteId' => $quoteId,
                    ]
                );
            }
        }
    }
}

mh_grid_tx_print([
    'ok' => true,
    'scanned' => $scanned,
    'updated' => $updated,
    'failures' => $failures,
    'financeEvents' => $financeEvents,
]);
