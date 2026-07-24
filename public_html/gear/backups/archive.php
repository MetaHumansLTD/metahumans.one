<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);
if (!defined('CUE_CLI_MODE')) define('CUE_CLI_MODE', true);

require_once __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    echo "Method Not Allowed\n";
    exit(1);
}

$setId = '';
$snapshotId = '';
foreach (($argv ?? []) as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--set=')) {
        $setId = substr($arg, 6);
        continue;
    }
    if (str_starts_with($arg, '--snap=')) {
        $snapshotId = substr($arg, 7);
        continue;
    }
}

if ($setId === '' || $snapshotId === '') {
    fwrite(STDERR, "Missing --set or --snap\n");
    exit(1);
}

mhb_snapshot_archive_write_status($setId, $snapshotId, [
    'state' => 'running',
    'updated_at' => gmdate('c'),
    'message' => 'Archive generation started',
]);

try {
    $archive = mhb_generate_archive($setId, $snapshotId);
    clearstatcache(true, $archive);
    $size = @filesize($archive);
    mhb_snapshot_archive_write_status($setId, $snapshotId, [
        'state' => 'ready',
        'updated_at' => gmdate('c'),
        'archive' => $archive,
        'size' => is_int($size) ? $size : null,
        'message' => 'Archive ready',
    ]);
    echo $archive . "\n";
    exit(0);
} catch (Throwable $e) {
    mhb_snapshot_archive_cleanup_temps($setId, $snapshotId);
    mhb_snapshot_archive_write_status($setId, $snapshotId, [
        'state' => 'failed',
        'updated_at' => gmdate('c'),
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
