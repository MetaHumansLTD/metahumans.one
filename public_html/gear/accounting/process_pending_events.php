<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) {
    define('CUE_DISABLE_AUTO_UI', true);
}
if (!defined('CUE_LAYOUT_MANUAL')) {
    define('CUE_LAYOUT_MANUAL', true);
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../grid/grid_db.php';
require_once __DIR__ . '/finance_gateway.php';

function mh_finance_cli_print(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$opts = getopt('', ['tenant::', 'limit::']);
$tenantId = isset($opts['tenant']) ? trim((string)$opts['tenant']) : '';
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 100;
$limit = max(1, min(500, $limit));

try {
    $result = mh_finance_gateway_process_pending_events([
        'tenantId' => $tenantId,
        'limit' => $limit,
        'principalId' => 'system:finance_cli',
        'username' => 'finance-cli',
        'role' => 'system',
        'source' => 'finance_cli',
    ]);
    mh_finance_cli_print([
        'ok' => true,
        'tenantId' => $tenantId !== '' ? $tenantId : null,
        'limit' => $limit,
        'result' => $result,
    ]);
} catch (Throwable $e) {
    mh_finance_cli_print([
        'ok' => false,
        'tenantId' => $tenantId !== '' ? $tenantId : null,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
