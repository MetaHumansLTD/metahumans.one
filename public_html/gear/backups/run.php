<?php
require_once __DIR__ . '/lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$setId = isset($argv[1]) ? (string)$argv[1] : '';
$sets = mhb_backup_sets();
if ($setId === '' || !isset($sets[$setId])) {
    fwrite(STDERR, "Usage: php run.php <" . implode('|', array_keys($sets)) . ">\n");
    exit(2);
}

try {
    $res = mhb_rsync_snapshot($setId);
    fwrite(STDOUT, "OK " . $setId . " " . $res['snapshot'] . "\n");
    exit(0);
} catch (Throwable $e) {
    try {
        mhb_set_last_run($setId, [
            'status' => 'error',
            'last_run_epoch' => time(),
            'last_run_at' => date('c'),
            'error' => $e->getMessage(),
        ]);
    } catch (Throwable $e2) {
    }
    fwrite(STDERR, "ERR " . $setId . " " . $e->getMessage() . "\n");
    exit(1);
}
