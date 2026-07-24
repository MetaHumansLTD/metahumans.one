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

function mhb_b2_worker_exec(array $cmd, ?string $cwd = null): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptor, $pipes, $cwd);
    if (!is_resource($proc)) {
        return ['ok' => false, 'exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open_failed', 'command' => $cmd];
    }
    @fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = @proc_close($proc);
    $exitCode = is_int($exitCode) ? $exitCode : 2;
    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
        'command' => $cmd,
    ];
}

function mhb_b2_worker_require_b2(array $config): array
{
    $b2 = isset($config['b2']) && is_array($config['b2']) ? $config['b2'] : [];
    if (empty($b2['enabled'])) {
        throw new RuntimeException('Backblaze B2 replication is disabled');
    }
    foreach (['account_id', 'application_key', 'bucket', 'remote_name', 'crypt_remote_name'] as $required) {
        if (trim((string)($b2[$required] ?? '')) === '') {
            throw new RuntimeException('Missing B2 setting: ' . $required);
        }
    }
    if (trim((string)($b2['crypt_password'] ?? '')) === '') {
        throw new RuntimeException('Missing B2 crypt password');
    }
    return $b2;
}

function mhb_b2_worker_obscure(string $rcloneBinary, string $value): string
{
    if ($value === '') {
        return '';
    }
    $res = mhb_b2_worker_exec([$rcloneBinary, 'obscure', $value]);
    if (empty($res['ok'])) {
        throw new RuntimeException('rclone obscure failed: ' . ($res['stderr'] !== '' ? $res['stderr'] : 'unknown error'));
    }
    return trim((string)$res['stdout']);
}

function mhb_b2_worker_temp_rclone_config(array $b2): string
{
    $rclone = mhb_rclone_binary();
    $remoteName = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)($b2['remote_name'] ?? 'metahumans_b2'));
    $cryptName = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)($b2['crypt_remote_name'] ?? 'metahumans_b2_crypt'));
    $bucket = trim((string)($b2['bucket'] ?? ''), '/');
    $prefix = trim((string)($b2['bucket_path'] ?? ''), '/');
    $hardDelete = !empty($b2['hard_delete']) ? 'true' : 'false';
    $cryptPassword = mhb_b2_worker_obscure($rclone, (string)($b2['crypt_password'] ?? ''));
    $cryptPassword2 = mhb_b2_worker_obscure($rclone, (string)($b2['crypt_password2'] ?? ''));

    $lines = [
        '[' . $remoteName . ']',
        'type = b2',
        'account = ' . trim((string)($b2['account_id'] ?? '')),
        'key = ' . trim((string)($b2['application_key'] ?? '')),
        'hard_delete = ' . $hardDelete,
        '',
        '[' . $cryptName . ']',
        'type = crypt',
        'remote = ' . $remoteName . ':' . $bucket . ($prefix !== '' ? '/' . $prefix : ''),
        'filename_encryption = standard',
        'directory_name_encryption = true',
        'password = ' . $cryptPassword,
    ];
    if ($cryptPassword2 !== '') {
        $lines[] = 'password2 = ' . $cryptPassword2;
    }
    $path = '/tmp/mhb_rclone_' . bin2hex(random_bytes(8)) . '.conf';
    @file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    @chmod($path, 0600);
    return $path;
}

function mhb_b2_worker_rclone_flags(array $b2): array
{
    $flags = [];
    $transfers = max(1, (int)($b2['transfers'] ?? 4));
    $checkers = max(1, (int)($b2['checkers'] ?? 8));
    $flags[] = '--transfers=' . $transfers;
    $flags[] = '--checkers=' . $checkers;
    $bwlimit = trim((string)($b2['bandwidth_limit'] ?? ''));
    if ($bwlimit !== '') {
        $flags[] = '--bwlimit=' . $bwlimit;
    }
    return $flags;
}

function mhb_b2_worker_rclone_copyto(array $b2, string $localPath, string $remoteRelativePath): array
{
    $configPath = mhb_b2_worker_temp_rclone_config($b2);
    try {
        $dest = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)($b2['crypt_remote_name'] ?? 'metahumans_b2_crypt'))
            . ':' . ltrim($remoteRelativePath, '/');
        $cmd = array_merge(
            [mhb_rclone_binary(), '--config', $configPath, 'copyto', $localPath, $dest],
            mhb_b2_worker_rclone_flags($b2)
        );
        return mhb_b2_worker_exec($cmd);
    } finally {
        @unlink($configPath);
    }
}

function mhb_b2_worker_rclone_copy(array $b2, string $remoteRelativePath, string $localPath): array
{
    $configPath = mhb_b2_worker_temp_rclone_config($b2);
    try {
        $source = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)($b2['crypt_remote_name'] ?? 'metahumans_b2_crypt'))
            . ':' . ltrim($remoteRelativePath, '/');
        $cmd = array_merge(
            [mhb_rclone_binary(), '--config', $configPath, 'copy', $source, $localPath],
            mhb_b2_worker_rclone_flags($b2)
        );
        return mhb_b2_worker_exec($cmd);
    } finally {
        @unlink($configPath);
    }
}

function mhb_b2_worker_tar_gz(string $sourceDir, string $archivePath): array
{
    $tar = mhb_shell_binary('/usr/bin/tar', 'tar');
    $dir = dirname($archivePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return mhb_b2_worker_exec([$tar, '-czf', $archivePath, '-C', $sourceDir, '.']);
}

function mhb_b2_worker_ssh_command(array $server, string $command): array
{
    $ssh = mhb_shell_binary('/usr/bin/ssh', 'ssh');
    $sshUser = trim((string)($server['ssh_user'] ?? ''));
    $sshHost = trim((string)($server['host'] ?? ''));
    $sshPort = max(1, (int)($server['ssh_port'] ?? 22));
    if ($sshUser === '' || $sshHost === '') {
        throw new RuntimeException('Remote server SSH user/host is incomplete for ' . ($server['label'] ?? $sshHost));
    }
    $target = $sshUser . '@' . $sshHost;
    $cmd = [$ssh, '-p', (string)$sshPort, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new'];
    $keyPath = trim((string)($server['ssh_key_path'] ?? ''));
    if ($keyPath !== '') {
        $cmd[] = '-i';
        $cmd[] = $keyPath;
    }
    $cmd[] = $target;
    $cmd[] = 'bash -lc ' . escapeshellarg($command);
    return mhb_b2_worker_exec($cmd);
}

function mhb_b2_worker_rsync_remote(array $server, string $remotePath, string $localBase): array
{
    $rsync = mhb_rsync_binary();
    $sshUser = trim((string)($server['ssh_user'] ?? ''));
    $sshHost = trim((string)($server['host'] ?? ''));
    $sshPort = max(1, (int)($server['ssh_port'] ?? 22));
    if ($sshUser === '' || $sshHost === '') {
        throw new RuntimeException('Remote server SSH user/host is incomplete for ' . ($server['label'] ?? $sshHost));
    }
    if (!is_dir($localBase)) {
        @mkdir($localBase, 0770, true);
    }
    $sshParts = ['ssh', '-p', (string)$sshPort, '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new'];
    $keyPath = trim((string)($server['ssh_key_path'] ?? ''));
    if ($keyPath !== '') {
        $sshParts[] = '-i';
        $sshParts[] = $keyPath;
    }
    $cmd = [
        $rsync,
        '-aHAXz',
        '--numeric-ids',
        '--relative',
        '--partial',
        '-e',
        implode(' ', $sshParts),
        $sshUser . '@' . $sshHost . ':' . $remotePath,
        $localBase,
    ];
    return mhb_b2_worker_exec($cmd);
}

function mhb_b2_worker_push_snapshot(array $job, array $config): array
{
    $b2 = mhb_b2_worker_require_b2($config);
    $setId = trim((string)($job['set_id'] ?? ''));
    if ($setId === '') {
        throw new RuntimeException('Missing set_id');
    }
    $snapshotId = trim((string)($job['snapshot_id'] ?? ''));
    if ($snapshotId === '') {
        $list = mhb_list_snapshots($setId);
        $snapshotId = isset($list[0]['id']) ? (string)$list[0]['id'] : '';
    }
    if ($snapshotId === '') {
        throw new RuntimeException('No snapshot is available for ' . $setId);
    }
    $snapshotDir = mhb_set_dir($setId) . '/' . $snapshotId;
    if (!is_dir($snapshotDir)) {
        throw new RuntimeException('Snapshot missing: ' . $setId . ' / ' . $snapshotId);
    }
    mhb_b2_job_write_status((string)$job['id'], [
        'state' => 'running',
        'message' => 'Preparing local archive for B2 replication',
        'set_id' => $setId,
        'snapshot_id' => $snapshotId,
        'updated_at' => gmdate('c'),
    ]);
    $archiveState = mhb_snapshot_archive_status($setId, $snapshotId);
    $archivePath = (string)($archiveState['archive'] ?? mhb_snapshot_archive_path($setId, $snapshotId));
    if (($archiveState['state'] ?? '') !== 'ready' || !is_file($archivePath)) {
        $archivePath = mhb_generate_archive($setId, $snapshotId);
        clearstatcache(true, $archivePath);
        $size = @filesize($archivePath);
        mhb_snapshot_archive_write_status($setId, $snapshotId, [
            'state' => 'ready',
            'updated_at' => gmdate('c'),
            'archive' => $archivePath,
            'size' => is_int($size) ? $size : null,
            'message' => 'Archive ready',
        ]);
    }
    $remoteBase = 'local-sets/' . $setId . '/' . $snapshotId;
    $push = mhb_b2_worker_rclone_copyto($b2, $archivePath, $remoteBase . '/' . basename($archivePath));
    if (empty($push['ok'])) {
        throw new RuntimeException('B2 archive upload failed: ' . ($push['stderr'] !== '' ? $push['stderr'] : 'unknown error'));
    }
    $manifestPath = mhb_meta_dir($snapshotDir) . '/manifest.json';
    if (is_file($manifestPath)) {
        $manifestPush = mhb_b2_worker_rclone_copyto($b2, $manifestPath, $remoteBase . '/manifest.json');
        if (empty($manifestPush['ok'])) {
            throw new RuntimeException('B2 manifest upload failed: ' . ($manifestPush['stderr'] !== '' ? $manifestPush['stderr'] : 'unknown error'));
        }
    }
    return [
        'message' => 'Replicated local snapshot archive to Backblaze B2',
        'remote_path' => $remoteBase . '/' . basename($archivePath),
        'archive_path' => $archivePath,
    ];
}

function mhb_b2_worker_push_server(array $job, array $config): array
{
    $b2 = mhb_b2_worker_require_b2($config);
    $serverId = trim((string)($job['server_id'] ?? ''));
    if ($serverId === '') {
        throw new RuntimeException('Missing server_id');
    }
    $servers = isset($config['servers']) && is_array($config['servers']) ? $config['servers'] : [];
    $server = isset($servers[$serverId]) && is_array($servers[$serverId]) ? $servers[$serverId] : null;
    if (!is_array($server)) {
        throw new RuntimeException('Unknown remote server: ' . $serverId);
    }
    if (empty($server['enabled'])) {
        throw new RuntimeException('Remote server is disabled: ' . $serverId);
    }
    $timestamp = gmdate('Ymd_His');
    $stageRoot = rtrim(mhb_backup_root(), '/') . '/remote-replication/' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $serverId) . '/' . $timestamp;
    $filesystemDir = $stageRoot . '/filesystem';
    $imagesDir = $stageRoot . '/images';
    $metaDir = $stageRoot . '/meta';
    foreach ([$filesystemDir, $imagesDir, $metaDir] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }

    $paths = [];
    foreach (['filesystem_paths', 'block_storage_paths'] as $key) {
        $items = isset($server[$key]) && is_array($server[$key]) ? $server[$key] : [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $paths[] = $item;
            }
        }
    }
    $paths = array_values(array_unique($paths));
    if ($paths === [] && trim((string)($server['image_command'] ?? '')) === '') {
        throw new RuntimeException('No filesystem paths or image command configured for ' . $serverId);
    }

    mhb_b2_job_write_status((string)$job['id'], [
        'state' => 'running',
        'message' => 'Collecting remote server data with rsync',
        'server_id' => $serverId,
        'updated_at' => gmdate('c'),
    ]);

    $rsyncRuns = [];
    foreach ($paths as $path) {
        $res = mhb_b2_worker_rsync_remote($server, $path, $filesystemDir);
        $rsyncRuns[] = ['path' => $path, 'ok' => !empty($res['ok']), 'stderr' => $res['stderr'] ?? ''];
        if (empty($res['ok'])) {
            throw new RuntimeException('rsync failed for ' . $serverId . ' ' . $path . ': ' . ($res['stderr'] !== '' ? $res['stderr'] : 'unknown error'));
        }
    }

    $imageCommand = trim((string)($server['image_command'] ?? ''));
    $imageFetchPaths = isset($server['image_fetch_paths']) && is_array($server['image_fetch_paths']) ? $server['image_fetch_paths'] : [];
    if ($imageCommand !== '') {
        $spool = trim((string)($server['remote_spool_dir'] ?? '/var/backups/metahumans'));
        $remoteScript = 'export MHB_REMOTE_SPOOL_DIR=' . escapeshellarg($spool) . '; mkdir -p ' . escapeshellarg($spool) . '; ' . $imageCommand;
        $ssh = mhb_b2_worker_ssh_command($server, $remoteScript);
        if (empty($ssh['ok'])) {
            throw new RuntimeException('Remote image command failed for ' . $serverId . ': ' . ($ssh['stderr'] !== '' ? $ssh['stderr'] : 'unknown error'));
        }
        foreach ($imageFetchPaths as $fetchPath) {
            $fetchPath = trim((string)$fetchPath);
            if ($fetchPath === '') {
                continue;
            }
            $res = mhb_b2_worker_rsync_remote($server, $fetchPath, $imagesDir);
            if (empty($res['ok'])) {
                throw new RuntimeException('Failed to fetch image artifact ' . $fetchPath . ': ' . ($res['stderr'] !== '' ? $res['stderr'] : 'unknown error'));
            }
        }
    }

    $manifest = [
        'server_id' => $serverId,
        'host' => (string)($server['host'] ?? $serverId),
        'os' => (string)($server['os'] ?? ''),
        'collected_at' => gmdate('c'),
        'filesystem_paths' => isset($server['filesystem_paths']) && is_array($server['filesystem_paths']) ? array_values($server['filesystem_paths']) : [],
        'block_storage_paths' => isset($server['block_storage_paths']) && is_array($server['block_storage_paths']) ? array_values($server['block_storage_paths']) : [],
        'image_fetch_paths' => array_values($imageFetchPaths),
        'image_command' => $imageCommand !== '' ? $imageCommand : null,
        'required_backup_classes' => isset($server['required_backup_classes']) && is_array($server['required_backup_classes']) ? array_values($server['required_backup_classes']) : [],
        'compression' => (string)($server['compression'] ?? 'tar.gz'),
        'rsync_runs' => $rsyncRuns,
    ];
    @file_put_contents($metaDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    mhb_b2_job_write_status((string)$job['id'], [
        'state' => 'running',
        'message' => 'Compressing staged remote backup before B2 upload',
        'local_stage' => $stageRoot,
        'updated_at' => gmdate('c'),
    ]);
    $archiveRoot = rtrim(mhb_backup_root(), '/') . '/archives/remote';
    if (!is_dir($archiveRoot)) {
        @mkdir($archiveRoot, 0770, true);
    }
    $archivePath = $archiveRoot . '/' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $serverId) . '_' . $timestamp . '.tar.gz';
    $tar = mhb_b2_worker_tar_gz($stageRoot, $archivePath);
    if (empty($tar['ok'])) {
        throw new RuntimeException('Failed to compress staged backup: ' . ($tar['stderr'] !== '' ? $tar['stderr'] : 'unknown error'));
    }
    $remoteBase = 'servers/' . $serverId . '/' . $timestamp;
    $push = mhb_b2_worker_rclone_copyto($b2, $archivePath, $remoteBase . '/' . basename($archivePath));
    if (empty($push['ok'])) {
        throw new RuntimeException('B2 upload failed for ' . $serverId . ': ' . ($push['stderr'] !== '' ? $push['stderr'] : 'unknown error'));
    }
    $manifestPush = mhb_b2_worker_rclone_copyto($b2, $metaDir . '/manifest.json', $remoteBase . '/manifest.json');
    if (empty($manifestPush['ok'])) {
        throw new RuntimeException('B2 manifest upload failed for ' . $serverId . ': ' . ($manifestPush['stderr'] !== '' ? $manifestPush['stderr'] : 'unknown error'));
    }
    return [
        'message' => 'Collected, compressed, and replicated remote server backup to Backblaze B2',
        'remote_path' => $remoteBase . '/' . basename($archivePath),
        'archive_path' => $archivePath,
        'local_stage' => $stageRoot,
    ];
}

function mhb_b2_worker_restore(array $job, array $config): array
{
    $b2 = mhb_b2_worker_require_b2($config);
    $sourcePrefix = trim((string)($job['remote_path'] ?? ''));
    if ($sourcePrefix === '') {
        throw new RuntimeException('Missing remote_path to restore');
    }
    $restoreRoot = trim((string)($job['restore_root'] ?? $b2['restore_root'] ?? ''));
    if ($restoreRoot === '') {
        throw new RuntimeException('Missing restore_root');
    }
    if (!is_dir($restoreRoot)) {
        @mkdir($restoreRoot, 0770, true);
    }
    $restoreDir = rtrim($restoreRoot, '/') . '/' . gmdate('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($sourcePrefix));
    if (!is_dir($restoreDir)) {
        @mkdir($restoreDir, 0770, true);
    }
    $copy = mhb_b2_worker_rclone_copy($b2, $sourcePrefix, $restoreDir);
    if (empty($copy['ok'])) {
        throw new RuntimeException('B2 restore failed: ' . ($copy['stderr'] !== '' ? $copy['stderr'] : 'unknown error'));
    }
    return [
        'message' => 'Restored Backblaze B2 data to local restore path',
        'remote_path' => $sourcePrefix,
        'restore_path' => $restoreDir,
    ];
}

$jobId = '';
foreach (($argv ?? []) as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--job=')) {
        $jobId = substr($arg, 6);
        break;
    }
}

if ($jobId === '') {
    fwrite(STDERR, "Missing --job\n");
    exit(1);
}

$queuePath = mhb_b2_jobs_queue_dir() . '/' . $jobId . '.json';
if (!is_file($queuePath)) {
    fwrite(STDERR, "Queue entry missing\n");
    exit(2);
}

$job = mhb_b2_read_json($queuePath, []);
if ($job === []) {
    @unlink($queuePath);
    fwrite(STDERR, "Invalid queue entry\n");
    exit(2);
}

mhb_b2_job_write_status($jobId, array_merge($job, [
    'state' => 'running',
    'updated_at' => gmdate('c'),
    'message' => 'B2 replication job started',
]));

try {
    $config = mhb_b2_load_config(true);
    $action = trim((string)($job['action'] ?? ''));
    if ($action === 'push_snapshot') {
        $result = mhb_b2_worker_push_snapshot($job, $config);
    } elseif ($action === 'push_server') {
        $result = mhb_b2_worker_push_server($job, $config);
    } elseif ($action === 'restore_prefix') {
        $result = mhb_b2_worker_restore($job, $config);
    } else {
        throw new RuntimeException('Unknown B2 action: ' . $action);
    }
    mhb_b2_job_write_status($jobId, array_merge($job, $result, [
        'state' => 'ready',
        'updated_at' => gmdate('c'),
        'completed_at' => gmdate('c'),
    ]));
    @unlink($queuePath);
    echo "ok\n";
    exit(0);
} catch (Throwable $e) {
    mhb_b2_job_write_status($jobId, array_merge($job, [
        'state' => 'failed',
        'updated_at' => gmdate('c'),
        'completed_at' => gmdate('c'),
        'message' => $e->getMessage(),
    ]));
    @unlink($queuePath);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
