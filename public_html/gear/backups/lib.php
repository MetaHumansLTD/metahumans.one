<?php
if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';

function mhb_start_session(): void
{
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function mhb_require_kripzmaster(): void
{
    mhb_start_session();
    if (!isset($_SESSION['mh_auth_user'])) {
        header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/gear/backups/index.php'));
        exit;
    }
    if (!isset($_SESSION['mh_auth_role']) || trim((string)$_SESSION['mh_auth_role']) === '') {
        if (function_exists('mh_auth_load_user_context')) {
            mh_auth_load_user_context((string)$_SESSION['mh_auth_user']);
        }
    }
    $role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
    if (stripos($role, 'kripzmaster') === false) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function mhb_backup_root(): string
{
    if (is_dir('/backup')) {
        return '/backup/backups';
    }
    return '/backups';
}

function mhb_backup_sets_cfg_path(): string
{
    return '/data/config/backup-sets.json';
}

function mhb_backup_sets_load_overrides(): array
{
    $path = mhb_backup_sets_cfg_path();
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $setId => $row) {
        if (!is_string($setId) || $setId === '' || !is_array($row)) continue;
        $freq = isset($row['frequency']) && is_string($row['frequency']) ? trim((string)$row['frequency']) : '';
        $ret = isset($row['retention']) ? (int)$row['retention'] : 0;
        if ($freq !== '' && in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            $out[$setId]['frequency'] = $freq;
        }
        if ($ret > 0 && $ret <= 2000) {
            $out[$setId]['retention'] = $ret;
        }
    }
    return $out;
}

function mhb_backup_sets_save_overrides(array $overrides): bool
{
    $path = mhb_backup_sets_cfg_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function mhb_update_backup_set_policy(string $setId, string $frequency, int $retention): bool
{
    $setId = trim($setId);
    $frequency = trim($frequency);
    if ($setId === '') return false;
    if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) return false;
    $retention = max(1, min(2000, $retention));
    $all = mhb_backup_sets_load_overrides();
    if (!isset($all[$setId]) || !is_array($all[$setId])) $all[$setId] = [];
    $all[$setId]['frequency'] = $frequency;
    $all[$setId]['retention'] = $retention;
    return mhb_backup_sets_save_overrides($all);
}

function mhb_sync_cron_cfg_path(): string
{
    return '/data/config/gear-crons.json';
}

function mhb_cron_expr_for_backup_set(string $setId, string $frequency): string
{
    $setId = trim($setId);
    if ($frequency === 'hourly') {
        $minute = 0;
        if ($setId === 'vector') $minute = 5;
        if ($setId === 'graph') $minute = 10;
        if ($setId === 'data') $minute = 0;
        return (string)$minute . ' * * * *';
    }
    if ($frequency === 'daily') {
        $hm = ['data' => [2, 0], 'mysql' => [2, 10], 'vector' => [2, 20], 'graph' => [2, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 2;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' * * *';
    }
    if ($frequency === 'weekly') {
        $hm = ['data' => [3, 0], 'mysql' => [3, 10], 'vector' => [3, 20], 'graph' => [3, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 3;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' * * 0';
    }
    if ($frequency === 'monthly') {
        $hm = ['data' => [4, 0], 'mysql' => [4, 10], 'vector' => [4, 20], 'graph' => [4, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 4;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' 1 * *';
    }
    return '0 * * * *';
}

function mhb_sync_load_cron_cfg(): array
{
    $path = mhb_sync_cron_cfg_path();
    if (!is_file($path)) {
        return ['version' => 1, 'jobs' => []];
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return ['version' => 1, 'jobs' => []];
    if (!isset($decoded['jobs']) || !is_array($decoded['jobs'])) $decoded['jobs'] = [];
    if (!isset($decoded['version'])) $decoded['version'] = 1;
    return $decoded;
}

function mhb_sync_save_cron_cfg(array $cfg): bool
{
    $path = mhb_sync_cron_cfg_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function mhb_sync_ensure_backup_jobs(array $sets): array
{
    $cfg = mhb_sync_load_cron_cfg();
    $jobs = isset($cfg['jobs']) && is_array($cfg['jobs']) ? $cfg['jobs'] : [];
    $updated = false;
    $phpBin = '/opt/cpanel/ea-php83/root/usr/bin/php';
    $script = '/home/onemeta/public_html/gear/backups/run.php';

    foreach ($sets as $setId => $set) {
        if (!is_string($setId) || $setId === '' || !is_array($set)) continue;
        $jobId = 'backup_' . $setId;
        $freq = isset($set['frequency']) && is_string($set['frequency']) ? (string)$set['frequency'] : 'hourly';
        $expr = mhb_cron_expr_for_backup_set($setId, $freq);
        $label = 'Backup ' . $setId;
        $cmd = $phpBin . ' ' . $script . ' ' . $setId;
        if (!isset($jobs[$jobId]) || !is_array($jobs[$jobId])) {
            $jobs[$jobId] = [
                'label' => $label,
                'type' => 'php',
                'enabled' => true,
                'cron' => $expr,
                'cmd' => $cmd,
                'max' => 1,
                'max_seconds' => 300,
            ];
            $updated = true;
        } else {
            $prevExpr = isset($jobs[$jobId]['cron']) ? (string)$jobs[$jobId]['cron'] : '';
            $prevCmd = isset($jobs[$jobId]['cmd']) ? (string)$jobs[$jobId]['cmd'] : '';
            $prevType = isset($jobs[$jobId]['type']) ? (string)$jobs[$jobId]['type'] : '';
            if ($prevType !== 'php') { $jobs[$jobId]['type'] = 'php'; $updated = true; }
            if ($prevCmd !== $cmd) { $jobs[$jobId]['cmd'] = $cmd; $updated = true; }
            if ($prevExpr !== $expr) { $jobs[$jobId]['cron'] = $expr; $updated = true; }
            if (!isset($jobs[$jobId]['max_seconds'])) { $jobs[$jobId]['max_seconds'] = 300; $updated = true; }
            if (!isset($jobs[$jobId]['max'])) { $jobs[$jobId]['max'] = 1; $updated = true; }
            if (!isset($jobs[$jobId]['enabled'])) { $jobs[$jobId]['enabled'] = true; $updated = true; }
        }
    }

    $cfg['jobs'] = $jobs;
    if ($updated) {
        $ok = mhb_sync_save_cron_cfg($cfg);
        return ['ok' => $ok, 'updated' => true];
    }
    return ['ok' => true, 'updated' => false];
}

function mhb_backup_sets(): array
{
    $sets = [
        'data' => [
            'source' => '/data',
            'frequency' => 'daily',
            'retention' => 7,
        ],
        'mysql' => [
            'source' => '/mysql',
            'frequency' => 'hourly',
            'retention' => 96,
        ],
        'vector' => [
            'source' => '/vector',
            'frequency' => 'hourly',
            'retention' => 96,
        ],
        'graph' => [
            'source' => '/graph',
            'frequency' => 'hourly',
            'retention' => 96,
        ],
    ];

    $ov = mhb_backup_sets_load_overrides();
    foreach ($sets as $id => $row) {
        if (isset($ov[$id]) && is_array($ov[$id])) {
            if (isset($ov[$id]['frequency']) && is_string($ov[$id]['frequency'])) {
                $sets[$id]['frequency'] = (string)$ov[$id]['frequency'];
            }
            if (isset($ov[$id]['retention'])) {
                $sets[$id]['retention'] = (int)$ov[$id]['retention'];
            }
        }
        $src = (string)($sets[$id]['source'] ?? '');
        $freq = (string)($sets[$id]['frequency'] ?? '');
        $ret = (int)($sets[$id]['retention'] ?? 0);
        $sets[$id]['label'] = $src . ' (' . $freq . ', keep ' . $ret . ')';
    }

    return $sets;
}

function mhb_state_dir(): string
{
    return rtrim(mhb_backup_root(), '/') . '/.state';
}

function mhb_last_run_state_path(): string
{
    return mhb_state_dir() . '/last_run.json';
}

function mhb_get_last_runs(): array
{
    $path = mhb_last_run_state_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mhb_set_last_run(string $setId, array $record): void
{
    $dir = mhb_state_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $path = mhb_last_run_state_path();
    $state = [];
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }
    $record['updated_at'] = date('c');
    $state[$setId] = $record;
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function mhb_set_dir(string $setId): string
{
    $sets = mhb_backup_sets();
    if (!isset($sets[$setId])) {
        throw new InvalidArgumentException('Unknown backup set');
    }
    $freq = (string)$sets[$setId]['frequency'];
    return rtrim(mhb_backup_root(), '/') . '/' . $setId . '/' . $freq;
}

function mhb_snapshot_uses_stream_download(string $setId): bool
{
    return false;
}

function mhb_snapshot_download_filename(string $setId, string $snapshotId): string
{
    return $setId . '_' . $snapshotId . (mhb_snapshot_uses_stream_download($setId) ? '.tar' : ((substr(mhb_snapshot_archive_path($setId, $snapshotId), -4) === '.tar') ? '.tar' : '.zip'));
}

function mhb_snapshot_download_content_type(string $filename): string
{
    if (substr($filename, -4) === '.zip') {
        return 'application/zip';
    }
    if (substr($filename, -4) === '.tar') {
        return 'application/x-tar';
    }
    return 'application/octet-stream';
}

function mhb_public_archive_download_dir(): string
{
    return __DIR__ . '/archive-downloads';
}

function mhb_public_archive_download_url_root(): string
{
    return '/gear/backups/archive-downloads';
}

function mhb_make_dir_http_traversable(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $perms = @fileperms($path);
    $mode = is_int($perms) ? ($perms & 0777) : 0770;
    $targetMode = $mode | 0001;
    if (str_starts_with($path, mhb_public_archive_download_dir())) {
        $targetMode |= 0004;
    }
    if ($targetMode !== $mode) {
        @chmod($path, $targetMode);
    }
}

function mhb_make_file_http_readable(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $perms = @fileperms($path);
    $mode = is_int($perms) ? ($perms & 0777) : 0660;
    $targetMode = $mode | 0004;
    if ($targetMode !== $mode) {
        @chmod($path, $targetMode);
    }
}

function mhb_prepare_archive_for_http_download(string $archivePath): void
{
    if (!is_file($archivePath)) {
        return;
    }
    $archiveDir = dirname($archivePath);
    $backupRoot = dirname($archiveDir);
    mhb_make_dir_http_traversable($backupRoot);
    mhb_make_dir_http_traversable($archiveDir);
    mhb_make_file_http_readable($archivePath);
}

function mhb_remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = @scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        mhb_remove_tree($path . '/' . $item);
    }
    @rmdir($path);
}

function mhb_cleanup_public_archive_downloads(int $ttl = 86400): void
{
    $root = mhb_public_archive_download_dir();
    if (!is_dir($root)) {
        return;
    }
    $now = time();
    $items = @scandir($root);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $root . '/' . $item;
        if (!is_dir($path)) {
            continue;
        }
        $metaPath = $path . '/meta.json';
        $expiresAt = null;
        if (is_file($metaPath)) {
            $raw = @file_get_contents($metaPath);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['expires_at'])) {
                $expiresAt = strtotime((string)$decoded['expires_at']);
            }
        }
        if (!is_int($expiresAt)) {
            $mtime = @filemtime($path);
            $expiresAt = is_int($mtime) ? ($mtime + $ttl) : ($now - 1);
        }
        if ($expiresAt <= $now) {
            mhb_remove_tree($path);
        }
    }
}

function mhb_issue_public_archive_download(string $archivePath, ?string $downloadName = null, int $ttl = 86400): array
{
    if (!is_file($archivePath)) {
        throw new RuntimeException('Archive missing');
    }
    mhb_prepare_archive_for_http_download($archivePath);
    mhb_cleanup_public_archive_downloads($ttl);
    $root = mhb_public_archive_download_dir();
    mhb_ensure_dir($root);
    @chmod($root, 0755);
    $downloadName = trim((string)$downloadName);
    if ($downloadName === '') {
        $downloadName = basename($archivePath);
    }
    $token = bin2hex(random_bytes(16));
    $tokenDir = $root . '/' . $token;
    mhb_ensure_dir($tokenDir);
    @chmod($tokenDir, 0755);
    $linkPath = $tokenDir . '/' . $downloadName;
    if (!@symlink($archivePath, $linkPath)) {
        throw new RuntimeException('Failed to create public download link');
    }
    $meta = [
        'archive_path' => $archivePath,
        'download_name' => $downloadName,
        'created_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + $ttl),
    ];
    @file_put_contents($tokenDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    mhb_make_file_http_readable($tokenDir . '/meta.json');
    return [
        'url' => mhb_public_archive_download_url_root() . '/' . rawurlencode($token) . '/' . rawurlencode($downloadName),
        'path' => $linkPath,
        'expires_at' => $meta['expires_at'],
    ];
}

function mhb_meta_dir(string $snapshotDir): string
{
    return rtrim($snapshotDir, '/') . '/.mh_meta';
}

function mhb_ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (@mkdir($path, 0770, true) === false && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
}

function mhb_safe_realpath(string $path): string
{
    $rp = realpath($path);
    if ($rp === false) {
        return $path;
    }
    return $rp;
}

function mhb_iterate_tree(string $root, callable $cb, array $options = []): void
{
    $root = rtrim($root, '/');
    $skipHiddenMeta = !empty($options['skip_meta']);
    $dir = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
        try {
            if ($current instanceof SplFileInfo && $current->isDir()) {
                return $current->isReadable();
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
    });
    $it = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $path = $f->getPathname();
        if (!is_string($path) || $path === '') {
            continue;
        }
        $rel = ltrim(substr($path, strlen($root)), '/');
        if ($skipHiddenMeta && ($rel === '.mh_meta' || str_starts_with($rel, '.mh_meta/'))) {
            continue;
        }
        $cb($path, $rel, $f);
    }
}

function mhb_list_snapshots(string $setId): array
{
    $base = mhb_set_dir($setId);
    if (!is_dir($base)) {
        return [];
    }
    $entries = scandir($base);
    if (!is_array($entries)) {
        return [];
    }
    $snapshots = [];
    foreach ($entries as $e) {
        if (!is_string($e) || $e === '.' || $e === '..') {
            continue;
        }
        if ($e[0] === '.') {
            continue;
        }
        $p = $base . '/' . $e;
        if (!is_dir($p)) {
            continue;
        }
        $manifestPath = mhb_meta_dir($p) . '/manifest.json';
        $manifest = null;
        if (is_file($manifestPath)) {
            $raw = file_get_contents($manifestPath);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }
        $snapshots[] = [
            'id' => $e,
            'path' => $p,
            'manifest' => $manifest,
            'mtime' => @filemtime($p) ?: 0,
        ];
    }
    usort($snapshots, function ($a, $b) {
        return ($b['mtime'] <=> $a['mtime']);
    });
    return $snapshots;
}

function mhb_parse_rsync_itemized(string $stdout): array
{
    $changed = 0;
    $deleted = 0;
    $lines = preg_split("/\\r?\\n/", $stdout);
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (stripos($line, '*deleting') === 0) {
            $deleted++;
            continue;
        }
        $code = substr($line, 0, 1);
        if ($code !== '.' && $code !== '') {
            $changed++;
        }
    }
    return ['changed' => $changed, 'deleted' => $deleted, 'lines' => $lines];
}

function mhb_write_manifest(string $snapshotDir, array $manifest, array $changesLines): void
{
    $metaDir = mhb_meta_dir($snapshotDir);
    mhb_ensure_dir($metaDir);
    $manifestPath = $metaDir . '/manifest.json';
    $changesPath = $metaDir . '/changes.log';
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents($changesPath, implode("\n", $changesLines) . "\n", LOCK_EX);

    $cfgDir = $metaDir . '/config';
    mhb_ensure_dir($cfgDir);
    $cfgPaths = [
        '/data/config/db_configs.json' => $cfgDir . '/db_configs.json',
        '/data/config/mysql_block_root.ini' => $cfgDir . '/mysql_block_root.ini',
        '/data/config/db_key.key' => $cfgDir . '/db_key.key',
        '/data/config/encryption.key' => $cfgDir . '/encryption.key',
        '/data/config/vector-config.json' => $cfgDir . '/vector-config.json',
        '/data/config/embeddings.json' => $cfgDir . '/embeddings.json',
        '/data/config/backup-sets.json' => $cfgDir . '/backup-sets.json',
        '/data/config/mysql-backups.json' => $cfgDir . '/mysql-backups.json',
    ];
    foreach ($cfgPaths as $src => $dst) {
        if (is_file($src)) {
            @copy($src, $dst);
        }
    }
}

function mhb_latest_snapshot_dir(string $setId): ?string
{
    $list = mhb_list_snapshots($setId);
    if (empty($list)) {
        return null;
    }
    return (string)$list[0]['path'];
}

function mhb_retention_cleanup(string $setId): array
{
    $sets = mhb_backup_sets();
    $keep = (int)$sets[$setId]['retention'];
    $list = mhb_list_snapshots($setId);
    $deleted = 0;
    if (count($list) <= $keep) {
        return ['deleted' => 0];
    }
    $toDelete = array_slice($list, $keep);
    foreach ($toDelete as $snap) {
        $p = (string)($snap['path'] ?? '');
        if ($p === '' || !is_dir($p)) {
            continue;
        }
        mhb_recursive_delete($p);
        $deleted++;
    }
    return ['deleted' => $deleted];
}

function mhb_recursive_delete(string $path): void
{
    $rp = mhb_safe_realpath($path);
    if ($rp === '/' || $rp === '' || strlen($rp) < 5) {
        throw new RuntimeException('Refusing to delete path');
    }
    if (!file_exists($rp)) {
        return;
    }
    if (is_file($rp) || is_link($rp)) {
        @unlink($rp);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $fp = $f->getPathname();
        if ($f->isDir()) {
            @rmdir($fp);
        } else {
            @unlink($fp);
        }
    }
    @rmdir($rp);
}

function mhb_snapshot_mirror(string $source, string $snapshotDir, ?string $prevSnapshotDir): array
{
    if (!is_dir($source)) {
        throw new RuntimeException('Source directory missing: ' . $source);
    }
    $source = rtrim($source, '/');
    $snapshotDir = rtrim($snapshotDir, '/');
    mhb_ensure_dir($snapshotDir);

    $seen = [];
    $copied = 0;
    $linked = 0;
    $dirs = 0;
    $bytesCopied = 0;
    $errors = 0;
    $changeLines = [];

    mhb_iterate_tree($source, function ($path, $rel, $f) use ($source, $snapshotDir, $prevSnapshotDir, &$seen, &$copied, &$linked, &$dirs, &$bytesCopied, &$errors, &$changeLines) {
        $seen[$rel] = true;
        $dst = $snapshotDir . '/' . $rel;
        if ($f->isDir()) {
            if (!is_dir($dst)) {
                @mkdir($dst, 0770, true);
            }
            $dirs++;
            return;
        }
        if (is_link($path)) {
            $t = readlink($path);
            if ($t !== false) {
                $dstDir = dirname($dst);
                if (!is_dir($dstDir)) @mkdir($dstDir, 0770, true);
                @symlink($t, $dst);
                $copied++;
                $changeLines[] = 'L ' . $rel;
            }
            return;
        }
        if ($f->isFile()) {
            $dstDir = dirname($dst);
            if (!is_dir($dstDir)) @mkdir($dstDir, 0770, true);
            $didLink = false;
            if ($prevSnapshotDir) {
                $prevFile = rtrim($prevSnapshotDir, '/') . '/' . $rel;
                if (is_file($prevFile) && !is_link($prevFile)) {
                    $same = (filesize($prevFile) === filesize($path)) && (@filemtime($prevFile) === @filemtime($path));
                    if ($same) {
                        if (@link($prevFile, $dst)) {
                            $linked++;
                            $didLink = true;
                        }
                    }
                }
            }
            if (!$didLink) {
                if (@copy($path, $dst)) {
                    @touch($dst, @filemtime($path) ?: time());
                    $copied++;
                    $sz = @filesize($path);
                    if (is_int($sz) && $sz > 0) {
                        $bytesCopied += $sz;
                    }
                    $changeLines[] = 'C ' . $rel;
                } else {
                    $errors++;
                    $changeLines[] = 'E ' . $rel;
                }
            } else {
                $changeLines[] = 'H ' . $rel;
            }
        }
    }, ['skip_meta' => true]);

    $deleted = 0;
    if ($prevSnapshotDir && is_dir($prevSnapshotDir)) {
        mhb_iterate_tree($prevSnapshotDir, function ($path, $rel, $f) use (&$seen, &$deleted, &$changeLines) {
            if ($rel === '.mh_meta' || str_starts_with($rel, '.mh_meta/')) {
                return;
            }
            if (!isset($seen[$rel])) {
                $deleted++;
                $changeLines[] = 'D ' . $rel;
            }
        }, ['skip_meta' => true]);
    }

    return [
        'copied' => $copied,
        'linked' => $linked,
        'dirs' => $dirs,
        'deleted' => $deleted,
        'bytes_copied' => $bytesCopied,
        'errors' => $errors,
        'lines' => $changeLines,
    ];
}

function mhb_rsync_snapshot(string $setId): array
{
    $sets = mhb_backup_sets();
    if (!isset($sets[$setId])) {
        throw new InvalidArgumentException('Unknown backup set');
    }
    $startedAt = microtime(true);
    $source = (string)$sets[$setId]['source'];

    $root = mhb_backup_root();
    mhb_ensure_dir($root);
    mhb_ensure_dir(rtrim($root, '/') . '/archives');
    mhb_ensure_dir(rtrim($root, '/') . '/uploads');
    mhb_ensure_dir(rtrim($root, '/') . '/imports');

    $base = mhb_set_dir($setId);
    mhb_ensure_dir($base);

    $timestamp = date('Y-m-d_His');
    $snapshotDir = $base . '/' . $timestamp;
    $prev = mhb_latest_snapshot_dir($setId);
    $prevArg = (is_string($prev) && $prev !== '' && is_dir($prev)) ? $prev : null;

    $stats = mhb_snapshot_mirror($source, $snapshotDir, $prevArg);
    $manifest = [
        'set_id' => $setId,
        'source' => $source,
        'snapshot' => $timestamp,
        'created_at' => date('c'),
        'previous' => $prevArg ? basename($prevArg) : null,
        'changed' => (int)$stats['copied'],
        'linked' => (int)$stats['linked'],
        'deleted' => (int)$stats['deleted'],
        'bytes_copied' => (int)$stats['bytes_copied'],
        'errors' => (int)$stats['errors'],
    ];
    mhb_write_manifest($snapshotDir, $manifest, $stats['lines']);

    $cleanup = mhb_retention_cleanup($setId);
    try {
        mhb_set_last_run($setId, [
            'status' => 'ok',
            'last_run_epoch' => time(),
            'last_run_at' => date('c'),
            'snapshot' => $timestamp,
            'copied' => (int)$manifest['changed'],
            'linked' => (int)$manifest['linked'],
            'deleted' => (int)$manifest['deleted'],
            'bytes_copied' => (int)$manifest['bytes_copied'],
            'errors' => (int)$manifest['errors'],
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);
    } catch (Throwable $e) {
    }
    return [
        'snapshot' => $timestamp,
        'snapshot_dir' => $snapshotDir,
        'manifest' => $manifest,
        'cleanup' => $cleanup,
    ];
}

function mhb_rsync_restore(string $setId, string $snapshotId, bool $dryRun, bool $restoreDbConfigs): array
{
    $sets = mhb_backup_sets();
    if (!isset($sets[$setId])) {
        throw new InvalidArgumentException('Unknown backup set');
    }
    $target = (string)$sets[$setId]['source'];
    $base = mhb_set_dir($setId);
    $snapshotDir = rtrim($base, '/') . '/' . $snapshotId;
    if (!is_dir($snapshotDir)) {
        throw new RuntimeException('Snapshot missing');
    }
    if (!is_dir($target)) {
        throw new RuntimeException('Destination missing: ' . $target);
    }

    $target = rtrim($target, '/');
    $snapshotDir = rtrim($snapshotDir, '/');

    $seen = [];
    $changed = 0;
    $deleted = 0;
    $lines = [];

    mhb_iterate_tree($snapshotDir, function ($path, $rel, $f) use ($snapshotDir, $target, $dryRun, &$seen, &$changed, &$lines) {
        if ($rel === '.mh_meta' || str_starts_with($rel, '.mh_meta/')) {
            return;
        }
        $seen[$rel] = true;
        $dst = $target . '/' . $rel;
        if ($f->isDir()) {
            if (!$dryRun) {
                @mkdir($dst, 0770, true);
            }
            return;
        }
        if (is_link($path)) {
            $t = readlink($path);
            if ($t !== false) {
                $needs = true;
                if (is_link($dst)) {
                    $cur = readlink($dst);
                    $needs = ($cur !== $t);
                } elseif (file_exists($dst)) {
                    $needs = true;
                }
                if ($needs) {
                    $changed++;
                    $lines[] = 'L ' . $rel;
                    if (!$dryRun) {
                        @mkdir(dirname($dst), 0770, true);
                        @unlink($dst);
                        @symlink($t, $dst);
                    }
                }
            }
            return;
        }
        if ($f->isFile()) {
            $needs = true;
            if (is_file($dst) && !is_link($dst)) {
                $needs = (filesize($dst) !== filesize($path)) || (@filemtime($dst) !== @filemtime($path));
            }
            if ($needs) {
                $changed++;
                $lines[] = 'U ' . $rel;
                if (!$dryRun) {
                    @mkdir(dirname($dst), 0770, true);
                    @copy($path, $dst);
                    @touch($dst, @filemtime($path) ?: time());
                }
            }
        }
    }, ['skip_meta' => false]);

    mhb_iterate_tree($target, function ($path, $rel, $f) use ($target, $dryRun, &$seen, &$deleted, &$lines) {
        if ($rel === '' || $rel === '.mh_meta' || str_starts_with($rel, '.mh_meta/')) {
            return;
        }
        if (isset($seen[$rel])) {
            return;
        }
        $deleted++;
        $lines[] = 'D ' . $rel;
        if ($dryRun) {
            return;
        }
        $full = $target . '/' . $rel;
        if (is_dir($full) && !is_link($full)) {
            mhb_recursive_delete($full);
        } else {
            @unlink($full);
        }
    }, ['skip_meta' => false]);

    $cfgRestored = [];
    if (!$dryRun && $restoreDbConfigs) {
        $cfgDir = mhb_meta_dir($snapshotDir) . '/config';
        if (is_dir($cfgDir)) {
            $pairs = [
                $cfgDir . '/db_configs.json' => '/data/config/db_configs.json',
                $cfgDir . '/mysql_block_root.ini' => '/data/config/mysql_block_root.ini',
                $cfgDir . '/db_key.key' => '/data/config/db_key.key',
                $cfgDir . '/encryption.key' => '/data/config/encryption.key',
                $cfgDir . '/vector-config.json' => '/data/config/vector-config.json',
                $cfgDir . '/embeddings.json' => '/data/config/embeddings.json',
                $cfgDir . '/backup-sets.json' => '/data/config/backup-sets.json',
                $cfgDir . '/mysql-backups.json' => '/data/config/mysql-backups.json',
            ];
            foreach ($pairs as $from => $to) {
                if (is_file($from)) {
                    mhb_ensure_dir(dirname($to));
                    if (@copy($from, $to)) {
                        $cfgRestored[] = basename($to);
                    }
                }
            }
        }
    }

    return [
        'dry_run' => $dryRun,
        'changed' => $changed,
        'deleted' => $deleted,
        'cfg_restored' => $cfgRestored,
        'lines' => $lines,
    ];
}

function mhb_snapshot_archive_path(string $setId, string $snapshotId): string
{
    $root = rtrim(mhb_backup_root(), '/');
    $ext = ($setId === 'data') ? '.tar' : '.zip';
    return $root . '/archives/' . $setId . '_' . $snapshotId . $ext;
}

function mhb_snapshot_archive_status_path(string $setId, string $snapshotId): string
{
    $root = rtrim(mhb_backup_root(), '/');
    return $root . '/archives/' . $setId . '_' . $snapshotId . '.status.json';
}

function mhb_snapshot_archive_legacy_paths(string $setId, string $snapshotId): array
{
    $root = rtrim(mhb_backup_root(), '/');
    $base = $root . '/archives/' . $setId . '_' . $snapshotId;
    $current = mhb_snapshot_archive_path($setId, $snapshotId);
    $paths = [];
    foreach ([$base . '.zip', $base . '.tar'] as $candidate) {
        if ($candidate !== $current) {
            $paths[] = $candidate;
        }
    }
    return $paths;
}

function mhb_snapshot_archive_temp_paths(string $setId, string $snapshotId): array
{
    $archives = array_merge([mhb_snapshot_archive_path($setId, $snapshotId)], mhb_snapshot_archive_legacy_paths($setId, $snapshotId));
    $out = [];
    foreach ($archives as $archive) {
        $matches = glob($archive . '.tmp*');
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $match) {
            if (is_string($match)) {
                $out[$match] = $match;
            }
        }
    }
    return array_values($out);
}

function mhb_process_exists($pid): bool
{
    $pid = (int)$pid;
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        try {
            return @posix_kill($pid, 0);
        } catch (Throwable) {
        }
    }
    return is_dir('/proc/' . $pid);
}

function mhb_terminate_process_tree(int $pid): void
{
    $pid = (int)$pid;
    if ($pid <= 0) {
        return;
    }
    $pkill = mhb_shell_binary('/usr/bin/pkill', 'pkill');
    @exec(escapeshellarg($pkill) . ' -TERM -P ' . $pid . ' >/dev/null 2>&1');
    if (mhb_process_exists($pid) && function_exists('posix_kill')) {
        @posix_kill($pid, 15);
    }
    usleep(200000);
    @exec(escapeshellarg($pkill) . ' -KILL -P ' . $pid . ' >/dev/null 2>&1');
    if (mhb_process_exists($pid) && function_exists('posix_kill')) {
        @posix_kill($pid, 9);
    }
}

function mhb_snapshot_archive_write_status(string $setId, string $snapshotId, array $status): void
{
    $path = mhb_snapshot_archive_status_path($setId, $snapshotId);
    mhb_ensure_dir(dirname($path));
    $status['archive'] = mhb_snapshot_archive_path($setId, $snapshotId);
    $status['status_path'] = $path;
    $status['set_id'] = $setId;
    $status['snapshot_id'] = $snapshotId;
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (is_string($json) && $json !== '') {
        @file_put_contents($path, $json . "\n", LOCK_EX);
    }
}

function mhb_snapshot_archive_status(string $setId, string $snapshotId): array
{
    $archive = mhb_snapshot_archive_path($setId, $snapshotId);
    $statusPath = mhb_snapshot_archive_status_path($setId, $snapshotId);
    $snapshotDir = mhb_set_dir($setId) . '/' . $snapshotId;
    $status = [
        'state' => 'missing',
        'archive' => $archive,
        'status_path' => $statusPath,
        'size' => null,
        'updated_at' => null,
    ];
    if (is_file($statusPath)) {
        $raw = @file_get_contents($statusPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $status = array_merge($status, $decoded);
        }
    }
    $status['archive'] = $archive;
    $status['status_path'] = $statusPath;
    clearstatcache(true, $archive);
    if (is_file($archive)) {
        $size = @filesize($archive);
        $mt = @filemtime($archive);
        $status['state'] = 'ready';
        $status['size'] = is_int($size) ? $size : null;
        $status['updated_at'] = is_int($mt) ? gmdate('c', $mt) : ($status['updated_at'] ?? null);
        return $status;
    }
    $temps = mhb_snapshot_archive_temp_paths($setId, $snapshotId);
    $currentTemps = array_values(array_filter($temps, static fn($path) => is_string($path) && str_starts_with($path, $archive . '.tmp')));
    $legacyTemps = array_values(array_filter($temps, static fn($path) => !in_array($path, $currentTemps, true)));
    if ($setId === 'data' && substr($archive, -4) === '.tar' && $currentTemps === [] && $legacyTemps !== []) {
        $status['state'] = 'failed';
        $status['message'] = 'Legacy ZIP archive job detected; requeue required';
        $status['legacy_temp_files'] = $legacyTemps;
        return $status;
    }
    if ($temps !== [] && ($status['state'] === 'missing' || $status['state'] === 'ready')) {
        $status['state'] = 'running';
        $status['temp_files'] = $temps;
    }
    $updatedAt = isset($status['updated_at']) ? strtotime((string)$status['updated_at']) : false;
    $age = is_int($updatedAt) ? (time() - $updatedAt) : null;
    $pid = isset($status['pid']) ? (int)$status['pid'] : 0;
    if (in_array((string)($status['state'] ?? ''), ['queued', 'running'], true)
        && $temps === []
        && is_int($age) && $age > 30
        && ($pid <= 0 || !mhb_process_exists($pid))) {
        $status['state'] = 'failed';
        $status['message'] = 'Archive worker is not running';
    }
    if (!is_dir($snapshotDir) && (($status['state'] ?? '') !== 'ready')) {
        $status['state'] = 'failed';
        $status['message'] = 'Snapshot directory is no longer available. Refresh the page to load the current snapshot list.';
    }
    return $status;
}

function mhb_snapshot_archive_cleanup_temps(string $setId, string $snapshotId): void
{
    foreach (mhb_snapshot_archive_temp_paths($setId, $snapshotId) as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    foreach (array_merge([mhb_snapshot_archive_path($setId, $snapshotId)], mhb_snapshot_archive_legacy_paths($setId, $snapshotId)) as $archive) {
        $tmp = $archive . '.tmp';
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

function mhb_snapshot_stream_download_reset(string $setId, string $snapshotId): void
{
    if (!mhb_snapshot_uses_stream_download($setId)) {
        return;
    }
    $statusPath = mhb_snapshot_archive_status_path($setId, $snapshotId);
    $pid = 0;
    if (is_file($statusPath)) {
        $raw = @file_get_contents($statusPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded) && isset($decoded['pid'])) {
            $pid = (int)$decoded['pid'];
        }
    }
    if ($pid > 0) {
        mhb_terminate_process_tree($pid);
    }
    mhb_snapshot_archive_cleanup_temps($setId, $snapshotId);
    foreach (array_merge([mhb_snapshot_archive_path($setId, $snapshotId)], mhb_snapshot_archive_legacy_paths($setId, $snapshotId)) as $archive) {
        if (is_file($archive)) {
            @unlink($archive);
        }
    }
    if (is_file($statusPath)) {
        @unlink($statusPath);
    }
}

function mhb_php_binary(): string
{
    $candidates = [
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/usr/bin/php',
        defined('PHP_BINARY') ? (string)PHP_BINARY : '',
        'php',
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }
        if ($candidate === 'php') {
            return $candidate;
        }
        $base = strtolower(basename($candidate));
        if (PHP_SAPI !== 'cli' && in_array($base, ['lsphp', 'php-cgi', 'php-fpm', 'cgi-fcgi'], true)) {
            continue;
        }
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }
    return 'php';
}

function mhb_shell_binary(string $path, string $fallback): string
{
    $path = trim($path);
    if ($path !== '' && is_file($path) && is_executable($path)) {
        return $path;
    }
    return $fallback;
}

function mhb_human_bytes(int|float|null $bytes): string
{
    if (!is_int($bytes) && !is_float($bytes)) {
        return 'unknown';
    }
    if ($bytes < 0) {
        return 'unknown';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    $precision = ($value >= 100 || $unit === 0) ? 0 : (($value >= 10) ? 1 : 2);
    return number_format($value, $precision) . ' ' . $units[$unit];
}

function mhb_estimate_directory_apparent_bytes(string $path): ?float
{
    if (!is_dir($path)) {
        return null;
    }
    $du = mhb_shell_binary('/usr/bin/du', 'du');
    $output = [];
    $exitCode = 1;
    @exec(escapeshellarg($du) . ' -sb ' . escapeshellarg($path) . ' 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0 || !isset($output[0]) || !is_string($output[0])) {
        return null;
    }
    if (preg_match('/^\s*(\d+)/', $output[0], $matches) !== 1) {
        return null;
    }
    return (float)$matches[1];
}

function mhb_queue_archive_generation(string $setId, string $snapshotId): array
{
    $base = mhb_set_dir($setId);
    $snapshotDir = $base . '/' . $snapshotId;
    if (!is_dir($snapshotDir)) {
        $available = array_map(static fn($row) => (string)($row['id'] ?? ''), mhb_list_snapshots($setId));
        $available = array_values(array_filter($available, static fn($id) => $id !== ''));
        $hint = $available !== [] ? (' Current snapshot: ' . $available[0]) : ' No snapshots are currently available.';
        throw new RuntimeException('Snapshot missing. Refresh the page to reload the current snapshot list.' . $hint);
    }
    if (mhb_snapshot_uses_stream_download($setId)) {
        mhb_snapshot_stream_download_reset($setId, $snapshotId);
        return [
            'state' => 'stream',
            'archive' => null,
            'status_path' => mhb_snapshot_archive_status_path($setId, $snapshotId),
            'updated_at' => gmdate('c'),
            'message' => 'Snapshot streams directly as TAR; archive prebuild is disabled',
        ];
    }
    $status = mhb_snapshot_archive_status($setId, $snapshotId);
    if (($status['state'] ?? '') === 'ready') {
        return $status;
    }
    $updatedAt = isset($status['updated_at']) ? strtotime((string)$status['updated_at']) : false;
    if (in_array((string)($status['state'] ?? ''), ['queued', 'running'], true) && is_int($updatedAt) && (time() - $updatedAt) < 6 * 3600) {
        return $status;
    }
    $backupVolumePath = mhb_backup_root();
    if (!is_dir($backupVolumePath)) {
        $backupVolumePath = dirname($backupVolumePath);
    }
    $freeBytesRaw = @disk_free_space($backupVolumePath);
    if ($setId === 'data' && is_numeric($freeBytesRaw)) {
        $freeBytes = (float)$freeBytesRaw;
        $requiredBytes = mhb_estimate_directory_apparent_bytes($snapshotDir);
        if ($requiredBytes !== null) {
            $requiredBytes *= 1.02; // small buffer for tar metadata and rounding
            if ($freeBytes < $requiredBytes) {
                $status = [
                    'state' => 'failed',
                    'updated_at' => gmdate('c'),
                    'message' => 'Insufficient space on backup volume. Need about '
                        . mhb_human_bytes($requiredBytes)
                        . ' free, only '
                        . mhb_human_bytes($freeBytes)
                        . ' available at '
                        . $backupVolumePath,
                ];
                mhb_snapshot_archive_write_status($setId, $snapshotId, $status);
                return $status;
            }
        } elseif ($freeBytes <= 0) {
            $status = [
                'state' => 'failed',
                'updated_at' => gmdate('c'),
                'message' => 'Backup volume is full at ' . $backupVolumePath,
            ];
            mhb_snapshot_archive_write_status($setId, $snapshotId, $status);
            return $status;
        }
    }
    mhb_snapshot_archive_cleanup_temps($setId, $snapshotId);
    mhb_snapshot_archive_write_status($setId, $snapshotId, [
        'state' => 'queued',
        'updated_at' => gmdate('c'),
        'message' => 'Archive queued for background generation',
    ]);
    $php = mhb_php_binary();
    $script = __DIR__ . '/archive.php';
    $ionice = mhb_shell_binary('/usr/bin/ionice', 'ionice');
    $nice = mhb_shell_binary('/usr/bin/nice', 'nice');
    $prio = $setId === 'data' ? 19 : 15;
    $logDir = rtrim(mhb_backup_root(), '/') . '/archives/logs';
    mhb_ensure_dir($logDir);
    $logPath = $logDir . '/' . $setId . '_' . $snapshotId . '.log';
    $prefix = '';
    if ($setId === 'data') {
        $prefix = escapeshellarg($ionice) . ' -c3 ' . escapeshellarg($nice) . ' -n ' . (string)$prio . ' ';
    } else {
        $prefix = escapeshellarg($nice) . ' -n ' . (string)$prio . ' ';
    }
    $cmd = 'nohup '
        . $prefix
        . escapeshellarg($php) . ' '
        . escapeshellarg($script) . ' --set=' . escapeshellarg($setId) . ' --snap=' . escapeshellarg($snapshotId)
        . ' >> ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';
    $handle = @popen($cmd, 'r');
    $pid = '';
    if (is_resource($handle)) {
        $pid = trim((string)stream_get_contents($handle));
        @pclose($handle);
    }
    usleep(250000);
    clearstatcache();
    $status = mhb_snapshot_archive_status($setId, $snapshotId);
    if (($status['state'] ?? '') === 'queued') {
        $status['state'] = 'failed';
        $status['message'] = 'Archive worker failed to start';
    }
    $status['updated_at'] = gmdate('c');
    $status['launcher_php'] = $php;
    $status['launcher_log'] = $logPath;
    if ($pid !== '') {
        $status['pid'] = $pid;
    }
    mhb_snapshot_archive_write_status($setId, $snapshotId, $status);
    return $status;
}

function mhb_generate_archive(string $setId, string $snapshotId): string
{
    $base = mhb_set_dir($setId);
    $snapshotDir = $base . '/' . $snapshotId;
    if (!is_dir($snapshotDir)) {
        throw new RuntimeException('Snapshot missing');
    }
    $root = mhb_backup_root();
    mhb_ensure_dir($root);
    mhb_ensure_dir(rtrim($root, '/') . '/archives');
    mhb_snapshot_archive_cleanup_temps($setId, $snapshotId);

    $archive = mhb_snapshot_archive_path($setId, $snapshotId);
    $tmp = $archive . '.tmp';
    @unlink($tmp);
    if (substr($archive, -4) === '.tar') {
        $tarBin = is_executable('/usr/bin/tar') ? '/usr/bin/tar' : 'tar';
        $cmd = [
            $tarBin,
            '--create',
            '--file', $tmp,
            '--sparse',
            '-C', $snapshotDir,
            '.',
        ];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptor, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to start tar archive process');
        }
        @fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $code = @proc_close($proc);
        if ((int)$code !== 0) {
            $msg = trim((string)$stderr);
            if ($msg === '') {
                $msg = trim((string)$stdout);
            }
            throw new RuntimeException($msg !== '' ? ('tar failed: ' . $msg) : 'tar failed');
        }
    } else {
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create archive');
        }
        mhb_iterate_tree($snapshotDir, function ($path, $rel, $f) use ($zip, $snapshotDir) {
            if ($f->isDir()) {
                $zip->addEmptyDir($rel);
                return;
            }
            if (is_link($path)) {
                $t = readlink($path);
                if ($t !== false) {
                    $zip->addFromString($rel . '.symlink', (string)$t);
                }
                return;
            }
            if ($f->isFile()) {
                $zip->addFile($path, $rel);
            }
        }, ['skip_meta' => false]);
        $zip->close();
    }
    if (!is_file($tmp)) {
        throw new RuntimeException('Archive staging file missing');
    }
    if (!@rename($tmp, $archive)) {
        throw new RuntimeException('Failed to finalize archive');
    }
    mhb_prepare_archive_for_http_download($archive);
    return $archive;
}

function mhb_handle_upload(array $file, string $setId): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload failed');
    }
    $name = isset($file['name']) ? (string)$file['name'] : 'upload.tar.gz';
    $extOk = (substr($name, -4) === '.zip');
    if (!$extOk) {
        throw new RuntimeException('Only .zip supported');
    }
    $root = mhb_backup_root();
    mhb_ensure_dir($root);
    $uploadDir = rtrim($root, '/') . '/uploads';
    mhb_ensure_dir($uploadDir);
    $id = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
    $dst = $uploadDir . '/' . $id . '_' . basename($name);
    if (!move_uploaded_file($file['tmp_name'], $dst)) {
        throw new RuntimeException('Failed to store upload');
    }

    $base = mhb_set_dir($setId);
    mhb_ensure_dir($base);
    $snapshotId = 'import_' . date('Y-m-d_His');
    $snapshotDir = $base . '/' . $snapshotId;
    mhb_ensure_dir($snapshotDir);
    $zip = new ZipArchive();
    if ($zip->open($dst) !== true) {
        mhb_recursive_delete($snapshotDir);
        throw new RuntimeException('Extract failed');
    }
    if (!$zip->extractTo($snapshotDir)) {
        $zip->close();
        mhb_recursive_delete($snapshotDir);
        throw new RuntimeException('Extract failed');
    }
    $zip->close();

    mhb_iterate_tree($snapshotDir, function ($path, $rel, $f) use ($snapshotDir) {
        if (!$f->isFile()) {
            return;
        }
        if (substr($rel, -8) !== '.symlink') {
            return;
        }
        $targetRel = substr($rel, 0, -8);
        $dst = rtrim($snapshotDir, '/') . '/' . $targetRel;
        $t = @file_get_contents($path);
        if (!is_string($t) || trim($t) === '') {
            return;
        }
        @unlink($dst);
        @mkdir(dirname($dst), 0770, true);
        @symlink(trim($t), $dst);
        @unlink($path);
    }, ['skip_meta' => false]);
    $meta = [
        'set_id' => $setId,
        'source' => null,
        'snapshot' => $snapshotId,
        'created_at' => date('c'),
        'previous' => null,
        'rsync_exit' => null,
        'changed' => null,
        'deleted' => null,
        'imported_from' => basename($dst),
    ];
    mhb_write_manifest($snapshotDir, $meta, []);
    mhb_retention_cleanup($setId);

    return ['snapshot' => $snapshotId, 'stored' => $dst];
}

function mhb_b2_config_path(): string
{
    return '/data/config/backup-b2.json';
}

function mhb_b2_default_servers(): array
{
    $notes = 'Populate SSH user, SSH key, block-storage paths, and image command explicitly before first run.';
    return [
        'meta.superhumans.one' => [
            'host' => 'meta.superhumans.one',
            'label' => 'meta.superhumans.one',
            'os' => 'almalinux-whm',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [
                '/home/onemeta/public_html/gear/backups',
                '/home/onemeta/ops',
                '/backup/backups',
            ],
            'block_storage_paths' => [
                '/data',
                '/mysql',
                '/vector',
                '/graph',
            ],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => $notes,
        ],
        'api.superhumans.one' => [
            'host' => 'api.superhumans.one',
            'label' => 'api.superhumans.one',
            'os' => 'ubuntu-24',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [],
            'block_storage_paths' => [],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => $notes,
        ],
        'ingress.superhumans.one' => [
            'host' => 'ingress.superhumans.one',
            'label' => 'ingress.superhumans.one',
            'os' => 'ubuntu-24',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [],
            'block_storage_paths' => [],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => $notes,
        ],
        'rke-cp-1.superhumans.one' => [
            'host' => 'rke-cp-1.superhumans.one',
            'label' => 'rke-cp-1.superhumans.one',
            'os' => 'ubuntu-24',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [],
            'block_storage_paths' => [],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => $notes,
        ],
        'rke-cp-2.superhumans.one' => [
            'host' => 'rke-cp-2.superhumans.one',
            'label' => 'rke-cp-2.superhumans.one',
            'os' => 'ubuntu-24',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [],
            'block_storage_paths' => [],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => $notes,
        ],
    ];
}

function mhb_b2_default_config(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'updated_by' => null,
        'b2' => [
            'enabled' => false,
            'account_id' => '',
            'application_key' => '',
            'bucket' => '',
            'bucket_path' => 'metahumans-one',
            'remote_name' => 'metahumans_b2',
            'crypt_remote_name' => 'metahumans_b2_crypt',
            'crypt_password' => '',
            'crypt_password2' => '',
            'hard_delete' => false,
            'bandwidth_limit' => '',
            'transfers' => 4,
            'checkers' => 8,
            'restore_root' => '/backup/restores/b2',
            'object_lock_enabled' => true,
            'object_lock_mode' => 'governance',
            'object_lock_days' => 30,
            'lifecycle_hide_days' => 30,
            'lifecycle_delete_days' => 180,
            'lifecycle_cancel_incomplete_days' => 3,
            'required_capabilities' => [
                'listAllBucketNames',
                'listBuckets',
                'readBuckets',
                'writeBuckets',
                'listFiles',
                'readFiles',
                'writeFiles',
                'deleteFiles',
                'readBucketEncryption',
                'writeBucketEncryption',
                'readBucketRetentions',
                'writeBucketRetentions',
                'readFileRetentions',
                'writeFileRetentions',
                'readFileLegalHolds',
                'writeFileLegalHolds',
                'bypassGovernance',
            ],
            'docs_reference' => [
                'application_keys' => 'https://www.backblaze.com/docs/cloud-storage-application-keys',
                'application_key_capabilities' => 'https://www.backblaze.com/docs/cloud-storage-application-key-capabilities',
                'authorize_account' => 'https://www.backblaze.com/apidocs/b2-authorize-account',
                'update_bucket' => 'https://www.backblaze.com/apidocs/b2-update-bucket',
                'object_lock' => 'https://www.backblaze.com/b2/docs/file_lock.html',
                'lifecycle_rules' => 'https://www.backblaze.com/b2/docs/lifecycle_rules.html',
                'rclone_b2' => 'https://rclone.org/b2/',
            ],
        ],
        'servers' => mhb_b2_default_servers(),
    ];
}

function mhb_b2_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : $fallback;
}

function mhb_b2_write_json(string $path, array $payload): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function mhb_b2_security_key(): string
{
    if (function_exists('cue_autoload')) {
        cue_autoload('security');
        cue_autoload('paths');
    }
    $candidates = [];
    if (function_exists('paths_getEncryptionKeyPath')) {
        $candidates[] = (string)paths_getEncryptionKeyPath();
    }
    $candidates[] = '/data/security/app.key';
    $candidates[] = '/data/config/db_key.key';
    foreach ($candidates as $path) {
        $path = trim((string)$path);
        if ($path === '' || !is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        $key = is_string($raw) ? trim($raw) : '';
        if ($key !== '' && function_exists('security_validateEncryptionKey') && security_validateEncryptionKey($key)) {
            return $key;
        }
    }
    return '';
}

function mhb_b2_encrypt_secret(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    if (!function_exists('security_encryptValue')) {
        if (function_exists('cue_autoload')) {
            cue_autoload('security');
        }
    }
    $key = mhb_b2_security_key();
    if ($key === '' || !function_exists('security_encryptValue')) {
        throw new RuntimeException('Backup secret encryption key is unavailable');
    }
    return 'enc:' . security_encryptValue($plain, $key);
}

function mhb_b2_decrypt_secret(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') {
        return '';
    }
    if (!str_starts_with($stored, 'enc:')) {
        return $stored;
    }
    if (!function_exists('security_decryptValue')) {
        if (function_exists('cue_autoload')) {
            cue_autoload('security');
        }
    }
    $key = mhb_b2_security_key();
    if ($key === '' || !function_exists('security_decryptValue')) {
        return '';
    }
    return (string)security_decryptValue(substr($stored, 4), $key);
}

function mhb_b2_csv_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $out[] = $line;
    }
    return array_values(array_unique($out));
}

function mhb_b2_merge_server_config(array $defaults, array $stored): array
{
    $merged = $defaults;
    foreach ($stored as $serverId => $row) {
        if (!is_string($serverId) || !is_array($row)) {
            continue;
        }
        $base = isset($merged[$serverId]) && is_array($merged[$serverId]) ? $merged[$serverId] : [
            'host' => $serverId,
            'label' => $serverId,
            'os' => '',
            'enabled' => true,
            'ssh_user' => '',
            'ssh_port' => 22,
            'ssh_key_path' => '',
            'remote_spool_dir' => '/var/backups/metahumans',
            'filesystem_paths' => [],
            'block_storage_paths' => [],
            'image_fetch_paths' => [],
            'image_command' => '',
            'required_backup_classes' => ['server-image', 'block-storage', 'filesystem'],
            'compression' => 'tar.gz',
            'notes' => '',
        ];
        foreach ($row as $key => $value) {
            $base[$key] = $value;
        }
        foreach (['filesystem_paths', 'block_storage_paths', 'image_fetch_paths', 'required_backup_classes'] as $listKey) {
            $items = $base[$listKey] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $clean = [];
            foreach ($items as $item) {
                $item = trim((string)$item);
                if ($item !== '') {
                    $clean[] = $item;
                }
            }
            $base[$listKey] = array_values(array_unique($clean));
        }
        $base['ssh_port'] = max(1, (int)($base['ssh_port'] ?? 22));
        $base['enabled'] = !empty($base['enabled']);
        $merged[$serverId] = $base;
    }
    ksort($merged, SORT_STRING);
    return $merged;
}

function mhb_b2_load_config(bool $decryptSecrets = false): array
{
    $defaults = mhb_b2_default_config();
    $stored = mhb_b2_read_json(mhb_b2_config_path(), []);
    $config = $defaults;
    foreach ($stored as $key => $value) {
        if ($key === 'servers' || $key === 'b2') {
            continue;
        }
        $config[$key] = $value;
    }
    $storedB2 = isset($stored['b2']) && is_array($stored['b2']) ? $stored['b2'] : [];
    foreach ($storedB2 as $key => $value) {
        $config['b2'][$key] = $value;
    }
    $config['servers'] = mhb_b2_merge_server_config(mhb_b2_default_servers(), isset($stored['servers']) && is_array($stored['servers']) ? $stored['servers'] : []);
    if ($decryptSecrets) {
        foreach (['account_id', 'application_key', 'crypt_password', 'crypt_password2'] as $secretKey) {
            $config['b2'][$secretKey] = mhb_b2_decrypt_secret((string)($config['b2'][$secretKey] ?? ''));
        }
    }
    return $config;
}

function mhb_b2_save_config(array $config): bool
{
    $defaults = mhb_b2_default_config();
    $payload = $defaults;
    foreach ($config as $key => $value) {
        if ($key === 'servers' || $key === 'b2') {
            continue;
        }
        $payload[$key] = $value;
    }
    $incomingB2 = isset($config['b2']) && is_array($config['b2']) ? $config['b2'] : [];
    foreach ($incomingB2 as $key => $value) {
        $payload['b2'][$key] = $value;
    }
    $payload['servers'] = mhb_b2_merge_server_config(mhb_b2_default_servers(), isset($config['servers']) && is_array($config['servers']) ? $config['servers'] : []);
    $payload['updated_at'] = date('c');
    return mhb_b2_write_json(mhb_b2_config_path(), $payload);
}

function mhb_b2_bucket_policy_state_path(): string
{
    return rtrim(mhb_state_dir(), '/') . '/b2-bucket-policy.json';
}

function mhb_b2_bucket_policy_state(): array
{
    return mhb_b2_read_json(mhb_b2_bucket_policy_state_path(), []);
}

function mhb_b2_write_bucket_policy_state(array $state): void
{
    $state['updated_at'] = $state['updated_at'] ?? gmdate('c');
    mhb_b2_write_json(mhb_b2_bucket_policy_state_path(), $state);
}

function mhb_b2_remote_prefix(array $b2): string
{
    $prefix = trim((string)($b2['bucket_path'] ?? ''), '/');
    return $prefix === '' ? '' : ($prefix . '/');
}

function mhb_b2_desired_default_retention(array $b2): array
{
    if (empty($b2['object_lock_enabled'])) {
        return ['mode' => null];
    }
    $mode = trim((string)($b2['object_lock_mode'] ?? 'governance'));
    if (!in_array($mode, ['governance', 'compliance'], true)) {
        $mode = 'governance';
    }
    return [
        'mode' => $mode,
        'period' => [
            'duration' => max(1, (int)($b2['object_lock_days'] ?? 30)),
            'unit' => 'days',
        ],
    ];
}

function mhb_b2_desired_lifecycle_rules(array $b2): array
{
    $rule = [
        'fileNamePrefix' => mhb_b2_remote_prefix($b2),
    ];
    $hideDays = max(0, (int)($b2['lifecycle_hide_days'] ?? 0));
    $deleteDays = max(0, (int)($b2['lifecycle_delete_days'] ?? 0));
    $cancelDays = max(0, (int)($b2['lifecycle_cancel_incomplete_days'] ?? 0));
    if ($hideDays > 0) {
        $rule['daysFromUploadingToHiding'] = $hideDays;
    }
    if ($deleteDays > 0) {
        $rule['daysFromHidingToDeleting'] = $deleteDays;
    }
    if ($cancelDays > 0) {
        $rule['daysFromStartingToCancelingUnfinishedLargeFiles'] = $cancelDays;
    }
    if (count($rule) === 1) {
        return [];
    }
    return [$rule];
}

function mhb_b2_normalize_for_compare(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $isAssoc = array_keys($value) !== range(0, count($value) - 1);
    if ($isAssoc) {
        ksort($value);
    }
    foreach ($value as $key => $item) {
        $value[$key] = mhb_b2_normalize_for_compare($item);
    }
    return $value;
}

function mhb_b2_values_differ(mixed $left, mixed $right): bool
{
    return json_encode(mhb_b2_normalize_for_compare($left), JSON_UNESCAPED_SLASHES) !== json_encode(mhb_b2_normalize_for_compare($right), JSON_UNESCAPED_SLASHES);
}

function mhb_b2_http_json(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $method = strtoupper(trim($method));
    $headerLines = [];
    foreach ($headers as $name => $value) {
        if (is_int($name)) {
            $headerLines[] = (string)$value;
        } else {
            $headerLines[] = $name . ': ' . $value;
        }
    }
    if ($body !== null) {
        $headerLines[] = 'Content-Type: application/json';
    }
    $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null;
    if ($body !== null && !is_string($payload)) {
        throw new RuntimeException('Failed to encode Backblaze request payload');
    }

    $statusCode = 0;
    $responseHeaders = [];
    $rawBody = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Backblaze request failed: ' . $err);
        }
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $rawHeader = substr($response, 0, $headerSize);
        $rawBody = substr($response, $headerSize);
        $responseHeaders = preg_split("/\r\n|\n|\r/", trim((string)$rawHeader)) ?: [];
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $payload ?? '',
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $rawBody = is_string($raw) ? $raw : '';
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) {
                $statusCode = (int)$m[1];
                break;
            }
        }
    }

    $decoded = null;
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
    }
    return [
        'status_code' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $decoded,
        'raw_body' => $rawBody,
    ];
}

function mhb_b2_authorize_storage(array $b2): array
{
    $keyId = trim((string)($b2['account_id'] ?? ''));
    $applicationKey = trim((string)($b2['application_key'] ?? ''));
    if ($keyId === '' || $applicationKey === '') {
        throw new RuntimeException('Backblaze Application Key ID and Application Key are required');
    }
    $auth = mhb_b2_http_json('GET', 'https://api.backblazeb2.com/b2api/v4/b2_authorize_account', [
        'Authorization' => 'Basic ' . base64_encode($keyId . ':' . $applicationKey),
        'Accept' => 'application/json',
    ]);
    if (($auth['status_code'] ?? 0) < 200 || ($auth['status_code'] ?? 0) >= 300 || !is_array($auth['body'])) {
        $message = is_array($auth['body']) ? (string)($auth['body']['message'] ?? $auth['body']['code'] ?? '') : '';
        throw new RuntimeException('Backblaze authorize failed' . ($message !== '' ? ': ' . $message : ''));
    }
    $body = $auth['body'];
    $storageApi = isset($body['apiInfo']['storageApi']) && is_array($body['apiInfo']['storageApi']) ? $body['apiInfo']['storageApi'] : [];
    $token = trim((string)($body['authorizationToken'] ?? ''));
    $apiUrl = trim((string)($storageApi['apiUrl'] ?? ''));
    if ($token === '' || $apiUrl === '') {
        throw new RuntimeException('Backblaze authorize response is incomplete');
    }
    return [
        'accountId' => (string)($body['accountId'] ?? ''),
        'authorizationToken' => $token,
        'apiUrl' => $apiUrl,
        'downloadUrl' => (string)($storageApi['downloadUrl'] ?? ''),
        'allowed' => isset($storageApi['allowed']) && is_array($storageApi['allowed']) ? $storageApi['allowed'] : [],
    ];
}

function mhb_b2_bucket_policy_snapshot(array $b2): array
{
    $auth = mhb_b2_authorize_storage($b2);
    $bucketName = trim((string)($b2['bucket'] ?? ''));
    if ($bucketName === '') {
        throw new RuntimeException('Backblaze bucket name is required');
    }
    $list = mhb_b2_http_json('POST', rtrim((string)$auth['apiUrl'], '/') . '/b2api/v4/b2_list_buckets', [
        'Authorization' => $auth['authorizationToken'],
        'Accept' => 'application/json',
    ], [
        'accountId' => (string)$auth['accountId'],
        'bucketName' => $bucketName,
    ]);
    if (($list['status_code'] ?? 0) < 200 || ($list['status_code'] ?? 0) >= 300 || !is_array($list['body'])) {
        $message = is_array($list['body']) ? (string)($list['body']['message'] ?? $list['body']['code'] ?? '') : '';
        throw new RuntimeException('Backblaze bucket lookup failed' . ($message !== '' ? ': ' . $message : ''));
    }
    $bucketRows = isset($list['body']['buckets']) && is_array($list['body']['buckets']) ? $list['body']['buckets'] : [];
    $bucket = isset($bucketRows[0]) && is_array($bucketRows[0]) ? $bucketRows[0] : null;
    if (!is_array($bucket)) {
        throw new RuntimeException('Backblaze bucket not found: ' . $bucketName);
    }
    $currentLock = isset($bucket['fileLockConfiguration']['value']) && is_array($bucket['fileLockConfiguration']['value'])
        ? $bucket['fileLockConfiguration']['value']
        : ['defaultRetention' => ['mode' => null, 'period' => null], 'isFileLockEnabled' => false];
    $currentLifecycle = isset($bucket['lifecycleRules']) && is_array($bucket['lifecycleRules']) ? $bucket['lifecycleRules'] : [];
    $desiredRetention = mhb_b2_desired_default_retention($b2);
    $desiredLifecycle = mhb_b2_desired_lifecycle_rules($b2);
    $allowed = isset($auth['allowed']['capabilities']) && is_array($auth['allowed']['capabilities']) ? array_values($auth['allowed']['capabilities']) : [];
    $required = isset($b2['required_capabilities']) && is_array($b2['required_capabilities']) ? array_values($b2['required_capabilities']) : [];
    $missing = array_values(array_diff($required, $allowed));
    return [
        'checked_at' => gmdate('c'),
        'bucket' => [
            'id' => (string)($bucket['bucketId'] ?? ''),
            'name' => (string)($bucket['bucketName'] ?? $bucketName),
            'type' => (string)($bucket['bucketType'] ?? ''),
            'revision' => isset($bucket['revision']) ? (int)$bucket['revision'] : null,
            'prefix' => mhb_b2_remote_prefix($b2),
        ],
        'allowed' => [
            'capabilities' => $allowed,
            'buckets' => isset($auth['allowed']['buckets']) && is_array($auth['allowed']['buckets']) ? $auth['allowed']['buckets'] : [],
            'namePrefix' => $auth['allowed']['namePrefix'] ?? null,
        ],
        'required_capabilities' => $required,
        'missing_capabilities' => $missing,
        'current' => [
            'file_lock_enabled' => !empty($currentLock['isFileLockEnabled']),
            'default_retention' => $currentLock['defaultRetention'] ?? ['mode' => null, 'period' => null],
            'lifecycle_rules' => $currentLifecycle,
        ],
        'desired' => [
            'default_retention' => $desiredRetention,
            'lifecycle_rules' => $desiredLifecycle,
        ],
        'drift' => [
            'default_retention' => mhb_b2_values_differ($currentLock['defaultRetention'] ?? ['mode' => null, 'period' => null], $desiredRetention),
            'lifecycle_rules' => mhb_b2_values_differ($currentLifecycle, $desiredLifecycle),
        ],
        'docs_reference' => isset($b2['docs_reference']) && is_array($b2['docs_reference']) ? $b2['docs_reference'] : [],
    ];
}

function mhb_b2_refresh_bucket_policy_state(): array
{
    $config = mhb_b2_load_config(true);
    $state = mhb_b2_bucket_policy_snapshot(isset($config['b2']) && is_array($config['b2']) ? $config['b2'] : []);
    mhb_b2_write_bucket_policy_state($state);
    return $state;
}

function mhb_b2_apply_bucket_policy(): array
{
    $config = mhb_b2_load_config(true);
    $b2 = isset($config['b2']) && is_array($config['b2']) ? $config['b2'] : [];
    $auth = mhb_b2_authorize_storage($b2);
    $snapshot = mhb_b2_bucket_policy_snapshot($b2);
    $bucket = isset($snapshot['bucket']) && is_array($snapshot['bucket']) ? $snapshot['bucket'] : [];
    $bucketId = trim((string)($bucket['id'] ?? ''));
    if ($bucketId === '') {
        throw new RuntimeException('Backblaze bucket ID is missing');
    }
    $bucketType = trim((string)($bucket['type'] ?? 'allPrivate'));
    $revision = isset($bucket['revision']) ? (int)$bucket['revision'] : null;
    $drift = isset($snapshot['drift']) && is_array($snapshot['drift']) ? $snapshot['drift'] : [];
    $current = isset($snapshot['current']) && is_array($snapshot['current']) ? $snapshot['current'] : [];
    $desired = isset($snapshot['desired']) && is_array($snapshot['desired']) ? $snapshot['desired'] : [];

    $updateBody = [
        'accountId' => (string)$auth['accountId'],
        'bucketId' => $bucketId,
        'bucketType' => $bucketType,
    ];
    if ($revision !== null) {
        $updateBody['ifRevisionIs'] = $revision;
    }
    if (!empty($drift['default_retention'])) {
        $updateBody['defaultRetention'] = $desired['default_retention'] ?? ['mode' => null];
    }
    if (!empty($drift['lifecycle_rules'])) {
        $updateBody['lifecycleRules'] = $desired['lifecycle_rules'] ?? [];
    }
    if (!empty($b2['object_lock_enabled']) && empty($current['file_lock_enabled'])) {
        $updateBody['fileLockEnabled'] = true;
    }
    if (count($updateBody) === 3 || (count($updateBody) === 4 && array_key_exists('ifRevisionIs', $updateBody))) {
        $snapshot['applied_at'] = gmdate('c');
        $snapshot['message'] = 'Bucket lifecycle and retention already match the desired state';
        mhb_b2_write_bucket_policy_state($snapshot);
        return $snapshot;
    }

    $update = mhb_b2_http_json('POST', rtrim((string)$auth['apiUrl'], '/') . '/b2api/v4/b2_update_bucket', [
        'Authorization' => $auth['authorizationToken'],
        'Accept' => 'application/json',
    ], $updateBody);
    if (($update['status_code'] ?? 0) < 200 || ($update['status_code'] ?? 0) >= 300) {
        $message = is_array($update['body']) ? (string)($update['body']['message'] ?? $update['body']['code'] ?? '') : '';
        throw new RuntimeException('Backblaze bucket update failed' . ($message !== '' ? ': ' . $message : ''));
    }

    $fresh = mhb_b2_bucket_policy_snapshot($b2);
    $fresh['applied_at'] = gmdate('c');
    $fresh['message'] = 'Applied Backblaze bucket lifecycle and default retention settings';
    mhb_b2_write_bucket_policy_state($fresh);
    return $fresh;
}

function mhb_b2_masked_value(string $value): string
{
    $plain = mhb_b2_decrypt_secret($value);
    if ($plain === '') {
        return '';
    }
    $len = strlen($plain);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($plain, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($plain, -4);
}

function mhb_b2_jobs_root(): string
{
    return rtrim(mhb_state_dir(), '/') . '/b2-jobs';
}

function mhb_b2_jobs_queue_dir(): string
{
    return mhb_b2_jobs_root() . '/queue';
}

function mhb_b2_jobs_results_dir(): string
{
    return mhb_b2_jobs_root() . '/results';
}

function mhb_b2_jobs_logs_dir(): string
{
    return mhb_b2_jobs_root() . '/logs';
}

function mhb_b2_job_status_path(string $jobId): string
{
    return mhb_b2_jobs_results_dir() . '/' . $jobId . '.json';
}

function mhb_b2_job_write_status(string $jobId, array $payload): void
{
    mhb_ensure_dir(mhb_b2_jobs_results_dir());
    $current = mhb_b2_job_status($jobId);
    $merged = array_merge($current, $payload);
    if (!isset($merged['id'])) {
        $merged['id'] = $jobId;
    }
    if (!isset($merged['updated_at'])) {
        $merged['updated_at'] = gmdate('c');
    }
    mhb_b2_write_json(mhb_b2_job_status_path($jobId), $merged);
}

function mhb_b2_job_status(string $jobId): array
{
    $path = mhb_b2_job_status_path($jobId);
    if (is_file($path)) {
        $decoded = mhb_b2_read_json($path, []);
        if ($decoded !== []) {
            return $decoded;
        }
    }
    $queuePath = mhb_b2_jobs_queue_dir() . '/' . $jobId . '.json';
    if (is_file($queuePath)) {
        $decoded = mhb_b2_read_json($queuePath, []);
        if ($decoded !== []) {
            $decoded['state'] = $decoded['state'] ?? 'queued';
            return $decoded;
        }
    }
    return ['id' => $jobId, 'state' => 'missing'];
}

function mhb_b2_recent_jobs(int $limit = 12): array
{
    $items = [];
    $resultsDir = mhb_b2_jobs_results_dir();
    if (is_dir($resultsDir)) {
        $files = glob($resultsDir . '/*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                $decoded = mhb_b2_read_json($file, []);
                if ($decoded !== []) {
                    $items[] = $decoded;
                }
            }
        }
    }
    $queueDir = mhb_b2_jobs_queue_dir();
    if (is_dir($queueDir)) {
        $files = glob($queueDir . '/*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                $decoded = mhb_b2_read_json($file, []);
                if ($decoded !== []) {
                    $jobId = (string)($decoded['id'] ?? basename($file, '.json'));
                    $items[$jobId] = array_merge(['state' => 'queued'], $decoded);
                }
            }
        }
    }
    usort($items, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['updated_at'] ?? $a['requested_at'] ?? ''));
        $bTime = strtotime((string)($b['updated_at'] ?? $b['requested_at'] ?? ''));
        $aTime = is_int($aTime) ? $aTime : 0;
        $bTime = is_int($bTime) ? $bTime : 0;
        return $bTime <=> $aTime;
    });
    return array_slice($items, 0, max(1, $limit));
}

function mhb_rclone_binary(): string
{
    return mhb_shell_binary('/usr/bin/rclone', 'rclone');
}

function mhb_rsync_binary(): string
{
    return mhb_shell_binary('/usr/bin/rsync', 'rsync');
}

function mhb_b2_queue_job(array $job): array
{
    mhb_ensure_dir(mhb_b2_jobs_queue_dir());
    mhb_ensure_dir(mhb_b2_jobs_logs_dir());
    $jobId = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
    $job['id'] = $jobId;
    $job['requested_at'] = date('c');
    $job['requested_by'] = $_SESSION['mh_auth_user'] ?? null;
    $job['state'] = 'queued';
    $queuePath = mhb_b2_jobs_queue_dir() . '/' . $jobId . '.json';
    mhb_b2_write_json($queuePath, $job);
    mhb_b2_job_write_status($jobId, $job);

    $php = mhb_php_binary();
    $script = __DIR__ . '/b2-replication.php';
    $nice = mhb_shell_binary('/usr/bin/nice', 'nice');
    $logPath = mhb_b2_jobs_logs_dir() . '/' . $jobId . '.log';
    $cmd = 'nohup '
        . escapeshellarg($nice) . ' -n 15 '
        . escapeshellarg($php) . ' '
        . escapeshellarg($script) . ' --job=' . escapeshellarg($jobId)
        . ' >> ' . escapeshellarg($logPath) . ' 2>&1 & echo $!';
    $handle = @popen($cmd, 'r');
    $pid = '';
    if (is_resource($handle)) {
        $pid = trim((string)stream_get_contents($handle));
        @pclose($handle);
    }
    $status = mhb_b2_job_status($jobId);
    $status['log_path'] = $logPath;
    if ($pid !== '') {
        $status['pid'] = $pid;
    }
    $status['updated_at'] = gmdate('c');
    mhb_b2_job_write_status($jobId, $status);
    return $status;
}
