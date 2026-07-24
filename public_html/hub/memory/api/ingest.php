<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/workbench/api/_context.php';
require_once dirname(__DIR__, 2) . '/workbench/api/_memory_ingest_lib.php';

$ctx = mhw_require_context();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    mhw_json(['success' => false, 'error' => 'invalid_json'], 400);
    exit;
}

if (isset($body['events']) && is_array($body['events'])) {
    $stored = 0;
    $ids = [];
    foreach ($body['events'] as $e) {
        if (!is_array($e)) continue;
        $r = mhw_memory_ingest_store_one($ctx, $e);
        if (!($r['ok'] ?? false)) continue;
        $ids[] = (string)($r['event_id'] ?? '');
        $stored++;
    }
    mhw_json(['success' => true, 'stored' => $stored, 'ids' => $ids]);
    exit;
}

$r = mhw_memory_ingest_store_one($ctx, $body);
if (!($r['ok'] ?? false)) {
    mhw_json(['success' => false, 'error' => (string)($r['error'] ?? 'store_failed')], 400);
    exit;
}
mhw_json(['success' => true, 'id' => (string)($r['event_id'] ?? ''), 'status' => 'stored']);
