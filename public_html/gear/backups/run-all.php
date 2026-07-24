<?php
require_once __DIR__ . '/lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$sets = mhb_backup_sets();
$out = [];
foreach (array_keys($sets) as $setId) {
    try {
        $res = mhb_rsync_snapshot($setId);
        $out[] = ['set' => $setId, 'ok' => true, 'snapshot' => $res['snapshot'] ?? null];
    } catch (Throwable $e) {
        $out[] = ['set' => $setId, 'ok' => false, 'error' => $e->getMessage()];
    }
}

fwrite(STDOUT, json_encode(['ok' => true, 'results' => $out], JSON_UNESCAPED_SLASHES) . "\n");

