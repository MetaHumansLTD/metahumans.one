<?php
require_once __DIR__ . '/lib.php';

if (php_sapi_name() !== 'cli') {
    mhb_require_kripzmaster();
    header('Location: /gear/backups/index.php');
    exit;
}

$sets = mhb_backup_sets();
$setId = isset($argv[1]) ? (string)$argv[1] : '';
$snapshotId = isset($argv[2]) ? (string)$argv[2] : '';

if ($setId === '' || !isset($sets[$setId]) || $snapshotId === '') {
    fwrite(STDERR, "Usage: php restore.php <" . implode('|', array_keys($sets)) . "> <snapshot_id> [--dry-run] [--restore-db-configs]\n");
    exit(2);
}

$dryRun = in_array('--dry-run', $argv, true);
$restoreCfg = in_array('--restore-db-configs', $argv, true);

try {
    $res = mhb_rsync_restore($setId, $snapshotId, $dryRun, $restoreCfg);
    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $res], JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
