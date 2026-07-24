<?php
require_once __DIR__ . '/lib.php';

mhb_require_kripzmaster();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$dbMod = function_exists('cue_autoload') ? cue_autoload('database') : null;
$csrfToken = (is_object($security) && method_exists($security, 'generateCSRFToken')) ? (string)$security->generateCSRFToken('backups') : '';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/gear/backups/';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/gear/backups/';
}
mhb_cleanup_public_archive_downloads();

function mhb_backups_redirect_target(string $requestUri, array $dropKeys = []): string
{
    $parts = @parse_url($requestUri);
    $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/gear/backups/';
    $query = [];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $query);
        if (!is_array($query)) {
            $query = [];
        }
    }
    foreach ($dropKeys as $key) {
        unset($query[$key]);
    }
    $qs = $query !== [] ? http_build_query($query) : '';
    return $qs !== '' ? ($path . '?' . $qs) : $path;
}

function mhb_format_bytes(int|float|null $bytes): string
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

function mhb_post_textarea_lines(string $key): array
{
    return mhb_b2_csv_lines((string)($_POST[$key] ?? ''));
}

function mhb_post_bool(string $key): bool
{
    return !empty($_POST[$key]);
}

function mhb_backup_volume_stats(): array
{
    $path = mhb_backup_root();
    if (!is_dir($path)) {
        $path = dirname($path);
    }
    if (!is_dir($path)) {
        $path = '/backup';
    }
    $totalRaw = @disk_total_space($path);
    $freeRaw = @disk_free_space($path);
    $total = is_numeric($totalRaw) ? (float)$totalRaw : null;
    $free = is_numeric($freeRaw) ? (float)$freeRaw : null;
    $used = ($total !== null && $free !== null) ? max(0.0, $total - $free) : null;
    $usePercent = ($total !== null && $total > 0 && $used !== null) ? round(($used / $total) * 100, 1) : null;
    return [
        'path' => $path,
        'total' => $total,
        'free' => $free,
        'used' => $used,
        'use_percent' => $usePercent,
        'total_text' => mhb_format_bytes($total),
        'free_text' => mhb_format_bytes($free),
        'used_text' => mhb_format_bytes($used),
        'is_full' => ($free !== null && $free <= 0) || ($usePercent !== null && $usePercent >= 99.9),
    ];
}

function mhb_snapshot_monitor_payload(string $setId, string $snapshotId): array
{
    $status = mhb_snapshot_archive_status($setId, $snapshotId);
    $volume = mhb_backup_volume_stats();
    $tempBytes = 0.0;
    $tempFiles = [];
    if (isset($status['temp_files']) && is_array($status['temp_files'])) {
        foreach ($status['temp_files'] as $tempPath) {
            if (!is_string($tempPath) || $tempPath === '') {
                continue;
            }
            clearstatcache(true, $tempPath);
            $sizeRaw = @filesize($tempPath);
            $size = is_numeric($sizeRaw) ? (float)$sizeRaw : null;
            if ($size !== null) {
                $tempBytes += $size;
            }
            $tempFiles[] = [
                'path' => $tempPath,
                'name' => basename($tempPath),
                'size' => $size,
                'size_text' => mhb_format_bytes($size),
            ];
        }
    }
    $archiveSize = isset($status['size']) && is_numeric($status['size']) ? (float)$status['size'] : null;
    $message = isset($status['message']) && is_string($status['message']) ? trim($status['message']) : '';
    if (($status['state'] ?? '') === 'failed' && $volume['is_full']) {
        $message .= ($message !== '' ? ' ' : '') . 'Backup volume is full at ' . $volume['path'] . '.';
    }
    return [
        'success' => true,
        'set_id' => $setId,
        'snapshot_id' => $snapshotId,
        'state' => (string)($status['state'] ?? 'missing'),
        'message' => $message,
        'updated_at' => isset($status['updated_at']) ? (string)$status['updated_at'] : null,
        'archive' => (string)($status['archive'] ?? mhb_snapshot_archive_path($setId, $snapshotId)),
        'archive_size' => $archiveSize,
        'archive_size_text' => mhb_format_bytes($archiveSize),
        'temp_size' => $tempBytes > 0 ? $tempBytes : null,
        'temp_size_text' => $tempBytes > 0 ? mhb_format_bytes($tempBytes) : null,
        'temp_files' => $tempFiles,
        'download_url' => '?download=1&set=' . rawurlencode($setId) . '&snap=' . rawurlencode($snapshotId),
        'action_label' => (($status['state'] ?? '') === 'ready') ? 'Download' : 'Prepare Download',
        'backup_volume' => $volume,
    ];
}

$status = null;
$error = null;
$previewLines = null;
$serviceStatus = [];
$serviceResults = [];

if (isset($_SESSION['mh_backups_flash']) && is_array($_SESSION['mh_backups_flash'])) {
    $flash = $_SESSION['mh_backups_flash'];
    if (array_key_exists('status', $flash)) {
        $status = is_string($flash['status']) && $flash['status'] !== '' ? $flash['status'] : null;
    }
    if (array_key_exists('error', $flash)) {
        $error = is_string($flash['error']) && $flash['error'] !== '' ? $flash['error'] : null;
    }
    if (isset($flash['previewLines']) && is_array($flash['previewLines'])) {
        $previewLines = $flash['previewLines'];
    }
    unset($_SESSION['mh_backups_flash']);
}

function mhb_backups_flash_redirect(string $requestUri, ?string $status, ?string $error, ?array $previewLines): never
{
    $_SESSION['mh_backups_flash'] = [
        'status' => is_string($status) && $status !== '' ? $status : null,
        'error' => is_string($error) && $error !== '' ? $error : null,
        'previewLines' => is_array($previewLines) ? array_values($previewLines) : null,
    ];
    header('Location: ' . $requestUri, true, 303);
    exit;
}

function mhb_mysql_backups_policy_path(): string
{
    return '/data/config/mysql-backups.json';
}

function mhb_mysql_backups_load_policy(): array
{
    $path = mhb_mysql_backups_policy_path();
    if (!is_file($path)) {
        return ['version' => 2, 'updated_at' => null, 'connection_config_id' => '', 'databases' => []];
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['version' => 2, 'updated_at' => null, 'connection_config_id' => '', 'databases' => []];
    }
    $v = isset($decoded['version']) ? (int)$decoded['version'] : 1;
    if ($v >= 2) {
        if (!isset($decoded['connection_config_id']) || !is_string($decoded['connection_config_id'])) $decoded['connection_config_id'] = '';
        if (!isset($decoded['databases']) || !is_array($decoded['databases'])) $decoded['databases'] = [];
        return $decoded;
    }
    if (!isset($decoded['items']) || !is_array($decoded['items'])) $decoded['items'] = [];
    return $decoded;
}

function mhb_mysql_backups_save_policy(array $policy): bool
{
    $path = mhb_mysql_backups_policy_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function mhb_mysql_backups_safe_name(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    if ($s === '') $s = 'db';
    if (strlen($s) > 80) $s = substr($s, 0, 80);
    return $s;
}

function mhb_mysql_backups_backup_root(): string
{
    $root = is_dir('/backup') ? '/backup/backups' : '/backups';
    return rtrim($root, '/') . '/mysql-dumps';
}

function mhb_mysql_backups_decrypt_cfg(string $configId): ?array
{
    $getFn = function_exists('database_getConfiguration') ? 'database_getConfiguration' : null;
    $decFn = function_exists('database_decryptConfiguration') ? 'database_decryptConfiguration' : null;
    if (!is_string($getFn) || !is_string($decFn)) return null;
    $cfg = call_user_func($getFn, $configId);
    if (!is_array($cfg)) return null;
    try {
        $dec = call_user_func($decFn, $cfg);
        return is_array($dec) ? $dec : null;
    } catch (Throwable $e) {
        $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
        $security = function_exists('cue_autoload') ? cue_autoload('security') : null;
        if (!is_object($paths) || !is_object($security) || !method_exists($security, 'decryptValue')) {
            throw $e;
        }
        $keyPath = method_exists($paths, 'getConfigPath') ? (rtrim((string)$paths->getConfigPath(), '/') . '/db_key.key') : '/data/config/db_key.key';
        if (!is_file($keyPath) && method_exists($paths, 'getEncryptionKeyPath')) {
            $keyPath = (string)$paths->getEncryptionKeyPath();
        }
        if (!is_file($keyPath)) {
            throw $e;
        }
        $keyRaw = @file_get_contents($keyPath);
        $key = is_string($keyRaw) ? trim($keyRaw) : '';
        if ($key === '') {
            throw $e;
        }
        $out = $cfg;
        foreach (['host', 'database', 'username', 'password'] as $k) {
            $v = isset($cfg[$k]) ? (string)$cfg[$k] : '';
            $isCipher = strlen($v) > 30 && preg_match('/^[A-Za-z0-9\\/\\+]+=*$/', $v) === 1;
            if (!$isCipher) {
                $out[$k] = $v;
                continue;
            }
            try {
                $plain = (string)$security->decryptValue($v, $key);
                if ($plain !== '') {
                    $out[$k] = $plain;
                }
            } catch (Throwable) {
            }
        }
        $host = isset($out['host']) ? (string)$out['host'] : '';
        if (strlen($host) > 30 && preg_match('/^[A-Za-z0-9\\/\\+]+=*$/', $host) === 1) {
            throw $e;
        }
        return $out;
    }
}

function mhb_mysql_backups_list_databases(string $configId): array
{
    $cfg = mhb_mysql_backups_decrypt_cfg($configId);
    if (!is_array($cfg)) return ['success' => false, 'message' => 'Config not found'];
    $host = isset($cfg['host']) ? trim((string)$cfg['host']) : '127.0.0.1';
    $port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
    $user = isset($cfg['username']) ? (string)$cfg['username'] : '';
    $pass = isset($cfg['password']) ? (string)$cfg['password'] : '';
    $defaultDb = isset($cfg['database']) ? trim((string)$cfg['database']) : '';
    if ($host === '' || $user === '') return ['success' => false, 'message' => 'Invalid config'];
    try {
        if (!function_exists('database_getConnectionById')) {
            return ['success' => false, 'message' => 'Database module unavailable'];
        }
        $pdo = database_getConnectionById($configId);
        try {
            $rows = $pdo->query('SHOW DATABASES')->fetchAll();
            $dbs = [];
            foreach ($rows as $r) {
                $name = isset($r['Database']) ? (string)$r['Database'] : (isset($r[0]) ? (string)$r[0] : '');
                $name = trim($name);
                if ($name === '') continue;
                if (in_array($name, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) continue;
                $dbs[] = $name;
            }
            sort($dbs, SORT_STRING);
            return ['success' => true, 'databases' => $dbs];
        } catch (Throwable $e) {
            if ($defaultDb !== '' && !in_array($defaultDb, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) {
                return ['success' => true, 'databases' => [$defaultDb], 'note' => 'limited_privileges'];
            }
            return ['success' => false, 'message' => 'List databases failed: ' . $e->getMessage()];
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'List databases failed: ' . $e->getMessage()];
    }
}

function mhb_mysql_backups_run_mysqldump(array $cfg, string $database, string $outGzPath): array
{
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (string)($cfg['port'] ?? '3307');
    $user = (string)($cfg['username'] ?? '');
    $pass = (string)($cfg['password'] ?? '');
    $tmp = '/tmp/mh_mysql_ui_' . bin2hex(random_bytes(8)) . '.cnf';
    $body = "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n";
    @file_put_contents($tmp, $body, LOCK_EX);
    @chmod($tmp, 0600);
    $cmd = [
        'mysqldump',
        '--defaults-extra-file=' . $tmp,
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--add-drop-table',
        '--complete-insert',
        '--default-character-set=utf8mb4',
        $database,
    ];
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'proc_open_failed'];
    }
    @fclose($pipes[0]);
    $gz = @gzopen($outGzPath, 'wb9');
    if (!$gz) {
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);
        @unlink($tmp);
        return ['ok' => false, 'error' => 'gzopen_failed'];
    }
    $bytes = 0;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 1024 * 256);
        if ($chunk === false) break;
        if ($chunk === '') continue;
        $bytes += strlen($chunk);
        gzwrite($gz, $chunk);
    }
    gzclose($gz);
    $stderr = stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $code = @proc_close($proc);
    @unlink($tmp);
    $code = is_int($code) ? $code : 2;
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'mysqldump_exit_' . $code, 'stderr' => is_string($stderr) ? trim($stderr) : ''];
    }
    return ['ok' => true, 'bytes' => $bytes];
}

function mhb_mysql_backups_retention_cleanup(string $dir, int $keep): array
{
    $keep = max(1, min(2000, $keep));
    if (!is_dir($dir)) return ['deleted' => 0];
    $files = glob(rtrim($dir, '/') . '/*.sql.gz') ?: [];
    rsort($files, SORT_STRING);
    if (count($files) <= $keep) return ['deleted' => 0];
    $toDel = array_slice($files, $keep);
    $deleted = 0;
    foreach ($toDel as $f) {
        if (is_file($f) && @unlink($f)) $deleted++;
    }
    return ['deleted' => $deleted];
}

function mhb_mysql_backups_dump_now(string $connectionConfigId, string $database, string $frequency, int $retention): array
{
    $connectionConfigId = trim($connectionConfigId);
    $database = trim($database);
    $frequency = trim($frequency);
    if ($connectionConfigId === '' || $database === '') return ['success' => false, 'message' => 'Missing inputs'];
    if (!in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) $frequency = 'daily';
    $retention = max(1, min(2000, $retention));
    $cfg = mhb_mysql_backups_decrypt_cfg($connectionConfigId);
    if (!is_array($cfg)) return ['success' => false, 'message' => 'Config not found'];
    $root = mhb_mysql_backups_backup_root();
    @mkdir($root, 0770, true);
    $dbDir = rtrim($root, '/') . '/' . mhb_mysql_backups_safe_name($database) . '/' . $frequency;
    @mkdir($dbDir, 0770, true);
    $stamp = gmdate('Y-m-d_His');
    $outFile = $dbDir . '/' . $stamp . '.sql.gz';
    $run = mhb_mysql_backups_run_mysqldump($cfg, $database, $outFile);
    if (empty($run['ok'])) {
        @unlink($outFile);
        return ['success' => false, 'message' => (string)($run['error'] ?? 'dump_failed'), 'stderr' => $run['stderr'] ?? null];
    }
    $cleanup = mhb_mysql_backups_retention_cleanup($dbDir, $retention);
    return ['success' => true, 'database' => $database, 'file' => $outFile, 'bytes' => $run['bytes'] ?? 0, 'cleanup' => $cleanup];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $isMysqlAjax = in_array($action, ['mysql_dbs_list', 'mysql_policy_save', 'mysql_dump_now'], true);
    $token = (string)($_POST['csrf_token'] ?? '');
    $ok = (is_object($security) && method_exists($security, 'validateCSRFToken')) ? (bool)$security->validateCSRFToken($token, 'backups') : true;
    if (!$ok) {
        if ($isMysqlAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $error = 'Invalid CSRF token';
        mhb_backups_flash_redirect($requestUri, $status, $error, $previewLines);
    } else {
        $setId = (string)($_POST['set_id'] ?? '');
        try {
            if ($action === 'mysql_dbs_list') {
                $cfgId = isset($_POST['connection_config_id']) ? trim((string)$_POST['connection_config_id']) : '';
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(mhb_mysql_backups_list_databases($cfgId), JSON_UNESCAPED_SLASHES);
                exit;
            } elseif ($action === 'b2_save_settings') {
                $cfg = mhb_b2_load_config(false);
                $b2 = isset($cfg['b2']) && is_array($cfg['b2']) ? $cfg['b2'] : [];
                $b2['enabled'] = mhb_post_bool('b2_enabled');
                $b2['bucket'] = trim((string)($_POST['b2_bucket'] ?? ''));
                $b2['bucket_path'] = trim((string)($_POST['b2_bucket_path'] ?? ''));
                $b2['remote_name'] = trim((string)($_POST['b2_remote_name'] ?? ''));
                $b2['crypt_remote_name'] = trim((string)($_POST['b2_crypt_remote_name'] ?? ''));
                $b2['hard_delete'] = mhb_post_bool('b2_hard_delete');
                $b2['bandwidth_limit'] = trim((string)($_POST['b2_bandwidth_limit'] ?? ''));
                $b2['transfers'] = max(1, (int)($_POST['b2_transfers'] ?? 4));
                $b2['checkers'] = max(1, (int)($_POST['b2_checkers'] ?? 8));
                $b2['restore_root'] = trim((string)($_POST['b2_restore_root'] ?? ''));
                $b2['object_lock_enabled'] = mhb_post_bool('b2_object_lock_enabled');
                $b2['object_lock_mode'] = trim((string)($_POST['b2_object_lock_mode'] ?? 'governance'));
                if (!in_array($b2['object_lock_mode'], ['governance', 'compliance'], true)) {
                    $b2['object_lock_mode'] = 'governance';
                }
                $b2['object_lock_days'] = max(1, (int)($_POST['b2_object_lock_days'] ?? 30));
                $b2['lifecycle_hide_days'] = max(1, (int)($_POST['b2_lifecycle_hide_days'] ?? 30));
                $b2['lifecycle_delete_days'] = max(1, (int)($_POST['b2_lifecycle_delete_days'] ?? 180));
                $b2['lifecycle_cancel_incomplete_days'] = max(0, (int)($_POST['b2_lifecycle_cancel_incomplete_days'] ?? 3));
                $accountId = trim((string)($_POST['b2_account_id'] ?? ''));
                $applicationKey = trim((string)($_POST['b2_application_key'] ?? ''));
                $cryptPassword = trim((string)($_POST['b2_crypt_password'] ?? ''));
                $cryptPassword2 = trim((string)($_POST['b2_crypt_password2'] ?? ''));
                if ($accountId !== '') {
                    $b2['account_id'] = mhb_b2_encrypt_secret($accountId);
                }
                if ($applicationKey !== '') {
                    $b2['application_key'] = mhb_b2_encrypt_secret($applicationKey);
                }
                if ($cryptPassword !== '') {
                    $b2['crypt_password'] = mhb_b2_encrypt_secret($cryptPassword);
                }
                if ($cryptPassword2 !== '') {
                    $b2['crypt_password2'] = mhb_b2_encrypt_secret($cryptPassword2);
                }
                $cfg['b2'] = $b2;
                $cfg['updated_by'] = $_SESSION['mh_auth_user'] ?? null;
                if (!mhb_b2_save_config($cfg)) {
                    throw new RuntimeException('Failed to save backup-b2.json');
                }
                $status = 'Saved Backblaze B2 replication settings';
            } elseif ($action === 'b2_refresh_bucket_policy') {
                $state = mhb_b2_refresh_bucket_policy_state();
                $bucketName = isset($state['bucket']) && is_array($state['bucket']) ? (string)($state['bucket']['name'] ?? '') : '';
                $drift = isset($state['drift']) && is_array($state['drift']) ? $state['drift'] : [];
                $status = 'Refreshed Backblaze bucket policy state';
                if ($bucketName !== '') {
                    $status .= ' for ' . $bucketName;
                }
                if (!empty($drift['default_retention']) || !empty($drift['lifecycle_rules'])) {
                    $status .= ' (drift detected)';
                } else {
                    $status .= ' (bucket matches desired retention and lifecycle rules)';
                }
            } elseif ($action === 'b2_apply_bucket_policy') {
                $state = mhb_b2_apply_bucket_policy();
                $bucketName = isset($state['bucket']) && is_array($state['bucket']) ? (string)($state['bucket']['name'] ?? '') : '';
                $status = (string)($state['message'] ?? 'Applied Backblaze bucket policy');
                if ($bucketName !== '') {
                    $status .= ' [' . $bucketName . ']';
                }
            } elseif ($action === 'b2_save_server') {
                $cfg = mhb_b2_load_config(false);
                $serverId = trim((string)($_POST['server_id'] ?? ''));
                $servers = isset($cfg['servers']) && is_array($cfg['servers']) ? $cfg['servers'] : [];
                $server = isset($servers[$serverId]) && is_array($servers[$serverId]) ? $servers[$serverId] : [];
                if ($serverId === '') {
                    throw new RuntimeException('Missing server profile');
                }
                $server['host'] = trim((string)($_POST['server_host'] ?? $serverId));
                $server['label'] = trim((string)($_POST['server_label'] ?? $serverId));
                $server['os'] = trim((string)($_POST['server_os'] ?? ''));
                $server['enabled'] = mhb_post_bool('server_enabled');
                $server['ssh_user'] = trim((string)($_POST['server_ssh_user'] ?? ''));
                $server['ssh_port'] = max(1, (int)($_POST['server_ssh_port'] ?? 22));
                $server['ssh_key_path'] = trim((string)($_POST['server_ssh_key_path'] ?? ''));
                $server['remote_spool_dir'] = trim((string)($_POST['server_remote_spool_dir'] ?? '/var/backups/metahumans'));
                $server['filesystem_paths'] = mhb_post_textarea_lines('server_filesystem_paths');
                $server['block_storage_paths'] = mhb_post_textarea_lines('server_block_storage_paths');
                $server['image_fetch_paths'] = mhb_post_textarea_lines('server_image_fetch_paths');
                $server['image_command'] = trim((string)($_POST['server_image_command'] ?? ''));
                $server['notes'] = trim((string)($_POST['server_notes'] ?? ''));
                $servers[$serverId] = $server;
                $cfg['servers'] = $servers;
                $cfg['updated_by'] = $_SESSION['mh_auth_user'] ?? null;
                if (!mhb_b2_save_config($cfg)) {
                    throw new RuntimeException('Failed to save remote server profile');
                }
                $status = 'Saved remote server profile: ' . $serverId;
            } elseif ($action === 'b2_push_snapshot') {
                $snapshotId = trim((string)($_POST['snapshot_id'] ?? ''));
                $job = mhb_b2_queue_job([
                    'action' => 'push_snapshot',
                    'set_id' => $setId,
                    'snapshot_id' => $snapshotId,
                ]);
                $status = 'Queued Backblaze B2 replication for ' . $setId . ' / ' . ($snapshotId !== '' ? $snapshotId : 'latest');
                if (!empty($job['pid'])) {
                    $status .= ' (pid ' . (string)$job['pid'] . ')';
                }
            } elseif ($action === 'b2_push_server') {
                $serverId = trim((string)($_POST['server_id'] ?? ''));
                if ($serverId === '') {
                    throw new RuntimeException('Missing remote server');
                }
                $job = mhb_b2_queue_job([
                    'action' => 'push_server',
                    'server_id' => $serverId,
                ]);
                $status = 'Queued remote rsync + B2 replication for ' . $serverId;
                if (!empty($job['pid'])) {
                    $status .= ' (pid ' . (string)$job['pid'] . ')';
                }
            } elseif ($action === 'b2_restore_prefix') {
                $remotePath = trim((string)($_POST['b2_remote_path'] ?? ''));
                $restoreRoot = trim((string)($_POST['b2_restore_root_override'] ?? ''));
                if ($remotePath === '') {
                    throw new RuntimeException('Remote B2 path is required');
                }
                $job = mhb_b2_queue_job([
                    'action' => 'restore_prefix',
                    'remote_path' => $remotePath,
                    'restore_root' => $restoreRoot,
                ]);
                $status = 'Queued Backblaze B2 restore for ' . $remotePath;
                if (!empty($job['pid'])) {
                    $status .= ' (pid ' . (string)$job['pid'] . ')';
                }
            } elseif ($action === 'mysql_policy_save') {
                $cfgId = isset($_POST['connection_config_id']) ? trim((string)$_POST['connection_config_id']) : '';
                $raw = isset($_POST['databases']) ? (string)$_POST['databases'] : '{}';
                $dbs = json_decode($raw, true);
                if (!is_array($dbs)) $dbs = [];
                $out = [];
                foreach ($dbs as $dbName => $row) {
                    $dbName = is_string($dbName) ? trim($dbName) : '';
                    if ($dbName === '') continue;
                    if (in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) continue;
                    $row = is_array($row) ? $row : [];
                    $enabled = !empty($row['enabled']);
                    $freq = isset($row['frequency']) ? trim((string)$row['frequency']) : 'daily';
                    if (!in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) $freq = 'daily';
                    $ret = isset($row['retention']) ? (int)$row['retention'] : 14;
                    $ret = max(1, min(2000, $ret));
                    $out[$dbName] = ['enabled' => $enabled, 'frequency' => $freq, 'retention' => $ret];
                }
                $policy = ['version' => 2, 'updated_at' => date('c'), 'connection_config_id' => $cfgId, 'databases' => $out];
                if (!mhb_mysql_backups_save_policy($policy)) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Failed to save mysql-backups.json'], JSON_UNESCAPED_SLASHES);
                    exit;
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'policy' => $policy], JSON_UNESCAPED_SLASHES);
                exit;
            } elseif ($action === 'mysql_dump_now') {
                $cfgId = isset($_POST['connection_config_id']) ? trim((string)$_POST['connection_config_id']) : '';
                $db = isset($_POST['database']) ? trim((string)$_POST['database']) : '';
                $freq = isset($_POST['frequency']) ? trim((string)$_POST['frequency']) : 'daily';
                $ret = isset($_POST['retention']) ? (int)$_POST['retention'] : 14;
                $res = mhb_mysql_backups_dump_now($cfgId, $db, $freq, $ret);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($res, JSON_UNESCAPED_SLASHES);
                exit;
            } elseif ($action === 'backup_now') {
                $res = mhb_rsync_snapshot($setId);
                $status = 'Backup created: ' . $setId . ' / ' . $res['snapshot'];
            } elseif ($action === 'update_policy') {
                $freq = isset($_POST['frequency']) ? trim((string)$_POST['frequency']) : '';
                $ret = isset($_POST['retention']) ? (int)$_POST['retention'] : 0;
                if (!mhb_update_backup_set_policy($setId, $freq, $ret)) {
                    throw new RuntimeException('Failed to save policy');
                }
                try {
                    mhb_sync_ensure_backup_jobs(mhb_backup_sets());
                } catch (Throwable $e) {
                }
                $status = 'Saved policy: ' . $setId . ' (' . $freq . ', keep ' . (int)$ret . ')';
            } elseif ($action === 'restore_preview') {
                $snapshotId = (string)($_POST['snapshot_id'] ?? '');
                $restoreCfg = ($setId === 'mysql') ? true : !empty($_POST['restore_db_configs']);
                $res = mhb_rsync_restore($setId, $snapshotId, true, $restoreCfg);
                $previewLines = $res['lines'];
                $status = 'Restore preview: changed=' . (int)$res['changed'] . ' deleted=' . (int)$res['deleted'];
            } elseif ($action === 'restore_apply') {
                $snapshotId = (string)($_POST['snapshot_id'] ?? '');
                if (empty($_POST['confirm_restore'])) {
                    throw new RuntimeException('Confirmation required');
                }
                if ($setId === 'mysql') {
                    $svcPath = rtrim(mhb_state_dir(), '/') . '/services_status.json';
                    if (is_file($svcPath)) {
                        $decoded = json_decode((string)file_get_contents($svcPath), true);
                        $svc = is_array($decoded) && isset($decoded['mariadb']) && is_array($decoded['mariadb']) ? $decoded['mariadb'] : null;
                        $active = is_array($svc) && isset($svc['active']) ? (string)$svc['active'] : '';
                        if ($active === 'active') {
                            throw new RuntimeException('Stop MariaDB before restoring /mysql to prevent partial restores and duplicate records');
                        }
                    }
                }
                $restoreCfg = ($setId === 'mysql') ? true : !empty($_POST['restore_db_configs']);
                $res = mhb_rsync_restore($setId, $snapshotId, false, $restoreCfg);
                $status = 'Restore applied: changed=' . (int)$res['changed'] . ' deleted=' . (int)$res['deleted'];
            } elseif ($action === 'archive_generate') {
                $snapshotId = (string)($_POST['snapshot_id'] ?? '');
                if (mhb_snapshot_uses_stream_download($setId)) {
                    mhb_snapshot_stream_download_reset($setId, $snapshotId);
                    $status = 'Data snapshots stream directly as TAR. No archive prebuild is used.';
                } else {
                    $archiveState = mhb_queue_archive_generation($setId, $snapshotId);
                    if (($archiveState['state'] ?? '') === 'ready') {
                        $archive = (string)($archiveState['archive'] ?? mhb_snapshot_archive_path($setId, $snapshotId));
                        $status = 'Archive ready: ' . basename($archive);
                    } elseif (($archiveState['state'] ?? '') === 'failed') {
                        $error = 'Archive worker failed to start for ' . $setId . ' / ' . $snapshotId . ': ' . (string)($archiveState['message'] ?? 'unknown error');
                    } else {
                        $status = 'Archive queued in background for ' . $setId . ' / ' . $snapshotId . '. Refresh and download once ready.';
                    }
                }
            } elseif ($action === 'upload_archive') {
                $res = mhb_handle_upload($_FILES['archive'] ?? [], $setId);
                $status = 'Imported snapshot: ' . $setId . ' / ' . $res['snapshot'];
            } elseif ($action === 'init_backups_root') {
                mhb_ensure_dir(mhb_backup_root());
                $status = 'Initialized: ' . mhb_backup_root();
            } elseif ($action === 'service_action') {
                $svc = (string)($_POST['service'] ?? '');
                $svcAction = (string)($_POST['service_cmd'] ?? '');
                $allowed = ['mariadb', 'redis'];
                $allowedCmd = ['start', 'stop', 'restart'];
                if (!in_array($svc, $allowed, true) || !in_array($svcAction, $allowedCmd, true)) {
                    throw new RuntimeException('Forbidden');
                }
                $stateDir = mhb_state_dir();
                if (!is_dir($stateDir)) {
                    @mkdir($stateDir, 0770, true);
                }
                $queueDir = rtrim($stateDir, '/') . '/service-actions';
                if (!is_dir($queueDir)) {
                    @mkdir($queueDir, 0770, true);
                }
                $id = date('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
                $job = [
                    'id' => $id,
                    'service' => $svc,
                    'action' => $svcAction,
                    'requested_at' => date('c'),
                    'requested_by' => $_SESSION['mh_auth_user'] ?? null,
                ];
                file_put_contents($queueDir . '/' . $id . '.json', json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
                $status = 'Queued: ' . $svc . ' ' . $svcAction;
            } else {
                $error = 'Unknown action';
            }
        } catch (Throwable $e) {
            if ($isMysqlAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
                exit;
            }
            $error = $e->getMessage();
        }
        if (!$isMysqlAjax) {
            mhb_backups_flash_redirect($requestUri, $status, $error, $previewLines);
        }
    }
}

if (isset($_GET['archive_status']) && isset($_GET['set']) && isset($_GET['snap'])) {
    $setId = (string)$_GET['set'];
    $snapshotId = (string)$_GET['snap'];
    $sets = mhb_backup_sets();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (!isset($sets[$setId])) {
        echo json_encode(['success' => false, 'message' => 'Unknown backup set'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        echo json_encode(mhb_snapshot_monitor_payload($setId, $snapshotId), JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['b2_jobs'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        echo json_encode(['success' => true, 'jobs' => mhb_b2_recent_jobs(20)], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['b2_policy'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        echo json_encode(['success' => true, 'state' => mhb_b2_bucket_policy_state()], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
}

if (isset($_GET['download']) && isset($_GET['set']) && isset($_GET['snap'])) {
    $setId = (string)$_GET['set'];
    $snapshotId = (string)$_GET['snap'];
    $sets = mhb_backup_sets();
    $downloadReturnUri = mhb_backups_redirect_target($requestUri, ['download', 'set', 'snap']);
    if (isset($sets[$setId])) {
        try {
            $snapshotDir = mhb_set_dir($setId) . '/' . $snapshotId;
            if (!is_dir($snapshotDir)) {
                throw new RuntimeException('Snapshot missing');
            }
            if (mhb_snapshot_uses_stream_download($setId)) {
                mhb_snapshot_stream_download_reset($setId, $snapshotId);
                @set_time_limit(0);
                @ignore_user_abort(true);
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                @ini_set('zlib.output_compression', '0');
                if (function_exists('apache_setenv')) {
                    @apache_setenv('no-gzip', '1');
                }
                $downloadName = mhb_snapshot_download_filename($setId, $snapshotId);
                $contentType = mhb_snapshot_download_content_type($downloadName);
                header('Content-Type: ' . $contentType);
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                header('Cache-Control: no-store, no-transform');
                header('X-Content-Type-Options: nosniff');
                header('Content-Encoding: identity');
                header('X-Accel-Buffering: no');
                $ionice = mhb_shell_binary('/usr/bin/ionice', 'ionice');
                $nice = mhb_shell_binary('/usr/bin/nice', 'nice');
                $tarBin = mhb_shell_binary('/usr/bin/tar', 'tar');
                $cmd = [
                    $ionice, '-c3',
                    $nice, '-n', '19',
                    $tarBin,
                    '--create',
                    '--file', '-',
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
                    throw new RuntimeException('Failed to start data stream');
                }
                @fclose($pipes[0]);
                while (!feof($pipes[1])) {
                    $chunk = fread($pipes[1], 1024 * 1024);
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    echo $chunk;
                    flush();
                }
                $stderr = stream_get_contents($pipes[2]);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                $code = @proc_close($proc);
                if ((int)$code !== 0) {
                    error_log('Data backup stream failed for ' . $setId . '/' . $snapshotId . ': ' . trim((string)$stderr));
                }
                exit;
            }
            $archiveState = mhb_snapshot_archive_status($setId, $snapshotId);
            $archive = (string)($archiveState['archive'] ?? mhb_snapshot_archive_path($setId, $snapshotId));
            if (($archiveState['state'] ?? '') !== 'ready' || !is_file($archive)) {
                $archiveState = mhb_queue_archive_generation($setId, $snapshotId);
                $archive = (string)($archiveState['archive'] ?? $archive);
                $state = (string)($archiveState['state'] ?? 'queued');
                if ($state === 'failed') {
                    $message = 'Archive worker failed to start for ' . $setId . ' / ' . $snapshotId . '.';
                    $detail = (string)($archiveState['message'] ?? 'unknown error');
                    mhb_backups_flash_redirect($downloadReturnUri, null, $message . ' ' . $detail, null);
                }
                $message = $state === 'running'
                    ? 'Archive is already being prepared in the background for ' . $setId . ' / ' . $snapshotId . '.'
                    : 'Archive queued in background for ' . $setId . ' / ' . $snapshotId . '. Refresh and download once ready.';
                mhb_backups_flash_redirect($downloadReturnUri, $message, null, null);
            }
            @set_time_limit(0);
            @ignore_user_abort(true);
            clearstatcache(true, $archive);
            $downloadName = basename($archive);
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            $publicDownload = mhb_issue_public_archive_download($archive, $downloadName);
            header('Location: ' . (string)$publicDownload['url'], true, 302);
            exit;
        } catch (Throwable $e) {
            error_log('Backup download failed for ' . $setId . ' / ' . $snapshotId . ': ' . $e->getMessage());
            http_response_code(500);
            echo 'Download failed';
            exit;
        }
    }
}

$sets = mhb_backup_sets();
$root = mhb_backup_root();
$rootExists = is_dir($root);
$rootWritable = $rootExists ? is_writable($root) : false;
$backupVolume = mhb_backup_volume_stats();

$lastRuns = [];
try {
    $lastRuns = mhb_get_last_runs();
} catch (Throwable $e) {
    $lastRuns = [];
}

$snapshotsBySet = [];
foreach (array_keys($sets) as $sid) {
    $snapshotsBySet[$sid] = mhb_list_snapshots($sid);
}

try {
    $svcPath = rtrim(mhb_state_dir(), '/') . '/services_status.json';
    if (is_file($svcPath)) {
        $decoded = json_decode((string)file_get_contents($svcPath), true);
        if (is_array($decoded)) {
            $serviceStatus = $decoded;
        }
    }
} catch (Throwable $e) {
    $serviceStatus = [];
}

try {
    $resultsDir = rtrim(mhb_state_dir(), '/') . '/service-actions/results';
    if (is_dir($resultsDir)) {
        $files = glob($resultsDir . '/*.json');
        if (is_array($files)) {
            rsort($files, SORT_STRING);
            $serviceResults = [];
            foreach (array_slice($files, 0, 8) as $f) {
                $decoded = json_decode((string)file_get_contents($f), true);
                if (is_array($decoded)) {
                    $serviceResults[] = $decoded;
                }
            }
        }
    }
} catch (Throwable $e) {
    $serviceResults = [];
}

$mysqlPolicy = mhb_mysql_backups_load_policy();
$b2Config = mhb_b2_load_config(false);
$b2RecentJobs = mhb_b2_recent_jobs(20);
$b2PolicyState = mhb_b2_bucket_policy_state();
$b2Masked = [
    'account_id' => mhb_b2_masked_value((string)($b2Config['b2']['account_id'] ?? '')),
    'application_key' => mhb_b2_masked_value((string)($b2Config['b2']['application_key'] ?? '')),
    'crypt_password' => mhb_b2_masked_value((string)($b2Config['b2']['crypt_password'] ?? '')),
    'crypt_password2' => mhb_b2_masked_value((string)($b2Config['b2']['crypt_password2'] ?? '')),
];
$mysqlCandidates = [];
$dbCfgPath = '/data/config/db_configs.json';
$dbCfg = is_file($dbCfgPath) ? json_decode((string)@file_get_contents($dbCfgPath), true) : null;
if (is_array($dbCfg)) {
    foreach ($dbCfg as $id => $c) {
        if (!is_string($id) || !is_array($c)) continue;
        $type = is_string($c['type'] ?? null) ? (string)$c['type'] : '';
        if (!in_array($type, ['mariadb', 'mysql'], true)) continue;
        $mysqlCandidates[] = [
            'id' => $id,
            'name' => is_string($c['name'] ?? null) ? (string)$c['name'] : $id,
            'port' => is_string($c['port'] ?? null) ? (string)$c['port'] : '',
            'active' => !empty($c['is_active']),
        ];
    }
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
function mhb_next_due_epoch(string $setId): int
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTime('now', $tz);
    $sets = mhb_backup_sets();
    $freq = isset($sets[$setId]) && is_array($sets[$setId]) && isset($sets[$setId]['frequency']) ? (string)$sets[$setId]['frequency'] : 'hourly';
    $expr = mhb_cron_expr_for_backup_set($setId, $freq);
    $parts = preg_split('/\\s+/', trim($expr));
    if (!is_array($parts) || count($parts) !== 5) {
        return $now->getTimestamp();
    }
    $minF = (string)$parts[0];
    $hourF = (string)$parts[1];
    $dowF = (string)$parts[4];
    $minute = ctype_digit($minF) ? (int)$minF : 0;
    $hour = ctype_digit($hourF) ? (int)$hourF : (int)$now->format('H');

    $cand = clone $now;
    $cand->setTime($hour, $minute, 0);
    if ($freq === 'hourly') {
        if ($cand->getTimestamp() <= $now->getTimestamp()) {
            $cand->modify('+1 hour');
            $cand->setTime((int)$cand->format('H'), $minute, 0);
        }
        return $cand->getTimestamp();
    }
    if ($freq === 'daily') {
        if ($cand->getTimestamp() <= $now->getTimestamp()) {
            $cand->modify('+1 day');
            $cand->setTime($hour, $minute, 0);
        }
        return $cand->getTimestamp();
    }
    if ($freq === 'weekly') {
        $targetDow = ctype_digit($dowF) ? (int)$dowF : 0;
        $curDow = (int)$now->format('w');
        $delta = ($targetDow - $curDow + 7) % 7;
        if ($delta === 0 && $cand->getTimestamp() <= $now->getTimestamp()) {
            $delta = 7;
        }
        if ($delta > 0) {
            $cand->modify('+' . $delta . ' day');
            $cand->setTime($hour, $minute, 0);
        }
        return $cand->getTimestamp();
    }
    if ($freq === 'monthly') {
        $cand->setDate((int)$now->format('Y'), (int)$now->format('m'), 1);
        $cand->setTime($hour, $minute, 0);
        if ($cand->getTimestamp() <= $now->getTimestamp()) {
            $cand->modify('first day of next month');
            $cand->setTime($hour, $minute, 0);
        }
        return $cand->getTimestamp();
    }

    return $cand->getTimestamp();
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Backups</title>
    <?php
        $headInclude = dirname(__DIR__, 3) . '/templates/global-ui/includes/complete-head.php';
        if (is_file($headInclude)) {
            include_once $headInclude;
        }
    ?>
    <style>
        :root { --primary:#00d4ff; --bg:#0a0a0a; --panel:rgba(255,255,255,0.05); --border:rgba(0,212,255,0.2); --text:#e0e0e0; --muted:#9aa; --danger:#ff5555; --ok:#10b981; }
        :root { color-scheme: dark; }
        html { color-scheme: dark; }
        html, body { background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 0 18px 60px; }
        h1 { margin: 0 0 10px; color: var(--primary); letter-spacing: 1px; }
        .sub { color: var(--muted); margin-bottom: 18px; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 14px; padding: 16px; margin: 14px 0; box-sizing: border-box; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .btn { background: rgba(43,43,43,0.35); border: 1px solid rgba(0,212,255,0.35); color: var(--primary); padding: 10px 14px; border-radius: 10px; cursor: pointer; font-weight: 700; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 10px 30px rgba(0,0,0,0.35); }
        .btn:hover { background: rgba(43,43,43,0.55); border-color: rgba(0,212,255,0.60); }
        .btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-danger { background: rgba(255,85,85,0.12); border-color: rgba(255,85,85,0.65); color: #ff9a9a; }
        .btn-danger:hover { background: rgba(255,85,85,0.18); border-color: rgba(255,85,85,0.85); }
        .btn-ok { background: rgba(43,43,43,0.35); border-color: rgba(0,212,255,0.35); color: var(--primary); }
        .btn-ok:hover { background: rgba(43,43,43,0.55); border-color: rgba(0,212,255,0.60); }
        .btn-sm { padding: 8px 10px; border-radius: 9px; font-size: 0.92rem; }
        .wrap select, .wrap input[type="text"], .wrap input[type="number"], .wrap input[type="file"], .wrap textarea { background: #2b2b2b !important; border: 1px solid var(--border); color: var(--primary) !important; padding: 10px 12px; border-radius: 10px; }
        .wrap select { -webkit-appearance: none; appearance: none; color-scheme: dark; }
        .wrap textarea { min-height: 88px; width: 100%; box-sizing: border-box; resize: vertical; }
        .wrap select option, .wrap select optgroup { background: #2b2b2b !important; color: var(--primary) !important; }
        .wrap input[type="file"]::-webkit-file-upload-button { background: rgba(43,43,43,0.35); border: 1px solid rgba(0,212,255,0.35); color: var(--primary); border-radius: 10px; padding: 8px 10px; margin-right: 10px; cursor: pointer; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
        .wrap input[type="file"]::file-selector-button { background: rgba(43,43,43,0.35); border: 1px solid rgba(0,212,255,0.35); color: var(--primary); border-radius: 10px; padding: 8px 10px; margin-right: 10px; cursor: pointer; backdrop-filter: blur(10px); }
        .msg { padding: 12px 14px; border-radius: 12px; margin: 12px 0; border: 1px solid var(--border); }
        .msg.ok { border-color: rgba(16,185,129,0.35); color: #b8f7dc; background: rgba(16,185,129,0.12); }
        .msg.err { border-color: rgba(255,85,85,0.35); color: #ffd1d1; background: rgba(255,85,85,0.10); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0,212,255,0.14); vertical-align: top; }
        th { color: var(--primary); font-size: 0.9rem; }
        .muted { color: var(--muted); font-size: 0.9rem; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 0.9rem; }
        .pill { display:inline-block; padding: 3px 10px; border-radius: 999px; border: 1px solid rgba(0,212,255,0.25); color: var(--primary); font-size: 0.82rem; }
        .pill.ok { border-color: rgba(16,185,129,0.45); color: #b8f7dc; }
        .pill.warn { border-color: rgba(255,85,85,0.45); color: #ffd1d1; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(460px, 1fr)); gap: 14px; align-items: start; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
        pre { background: rgba(0,0,0,0.35); border: 1px solid rgba(0,212,255,0.15); border-radius: 12px; padding: 12px; overflow: auto; }
        .danger-note { color: #ffb4b4; }
        .svc-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 10px; }
        .svc-card { border: 1px solid rgba(0,212,255,0.18); border-radius: 14px; padding: 12px; background: rgba(0,0,0,0.20); }
        .svc-title { font-weight: 700; color: var(--primary); }
        .svc-meta { margin-top: 6px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    </style>
</head>
<body>
<?php
    $bodyStart = dirname(__DIR__, 3) . '/templates/global-ui/includes/complete-body-start.php';
    if (is_file($bodyStart)) {
        include_once $bodyStart;
    } elseif (function_exists('renderGlobalHeader')) {
        renderGlobalHeader();
    }
?>
<main class="main-content">
<div class="wrap">
    <h1>Backups</h1>
    <div class="sub">Block storage snapshots to <span class="mono"><?php echo h($root); ?></span> with retention and restore.</div>

    <?php if ($status): ?><div class="msg ok"><?php echo h($status); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="row">
            <div><span class="pill">Status</span></div>
            <div class="muted">Backups root exists: <strong><?php echo $rootExists ? 'Yes' : 'No'; ?></strong></div>
            <div class="muted">Writable: <strong><?php echo $rootWritable ? 'Yes' : 'No'; ?></strong></div>
            <div class="muted">Backup volume used: <strong><?php echo h($backupVolume['used_text']); ?></strong></div>
            <div class="muted">Free: <strong class="<?php echo $backupVolume['is_full'] ? 'danger-note' : ''; ?>"><?php echo h($backupVolume['free_text']); ?></strong></div>
            <?php if ($backupVolume['use_percent'] !== null): ?>
                <div class="muted">Use: <strong><?php echo h(number_format((float)$backupVolume['use_percent'], 1)); ?>%</strong></div>
            <?php endif; ?>
            <?php if (!$rootExists): ?>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                    <input type="hidden" name="action" value="init_backups_root" />
                    <button class="btn btn-ok" type="submit">Initialize backups root</button>
                </form>
                <div class="muted danger-note">If /backup is mounted block storage, ensure it is available before running backups.</div>
            <?php endif; ?>
        </div>
        <?php if ($backupVolume['is_full']): ?>
            <div class="danger-note" style="margin-top:10px">
                Archive generation cannot complete because <span class="mono"><?php echo h($backupVolume['path']); ?></span> is full. Free space on the backup volume, then click <strong>Generate Archive</strong> again.
            </div>
        <?php endif; ?>
    </div>

    <?php
        $b2Docs = isset($b2['docs_reference']) && is_array($b2['docs_reference']) ? $b2['docs_reference'] : [];
        $b2PolicyBucket = isset($b2PolicyState['bucket']) && is_array($b2PolicyState['bucket']) ? $b2PolicyState['bucket'] : [];
        $b2PolicyCurrent = isset($b2PolicyState['current']) && is_array($b2PolicyState['current']) ? $b2PolicyState['current'] : [];
        $b2PolicyDesired = isset($b2PolicyState['desired']) && is_array($b2PolicyState['desired']) ? $b2PolicyState['desired'] : [];
        $b2PolicyDrift = isset($b2PolicyState['drift']) && is_array($b2PolicyState['drift']) ? $b2PolicyState['drift'] : [];
        $b2MissingCapabilities = isset($b2PolicyState['missing_capabilities']) && is_array($b2PolicyState['missing_capabilities']) ? $b2PolicyState['missing_capabilities'] : [];
        $rcloneBinary = mhb_rclone_binary();
        $rsyncBinary = mhb_rsync_binary();
    ?>
    <?php $b2 = isset($b2Config['b2']) && is_array($b2Config['b2']) ? $b2Config['b2'] : []; ?>
    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-weight:700;color:var(--primary)">Backblaze B2 Replication + Restore</div>
            </div>
            <div class="muted mono" style="margin-top:2px">Config: <?php echo h(mhb_b2_config_path()); ?></div>
        </div>

        <div class="grid" style="margin-top:12px">
            <div class="card" style="margin:0">
                <div style="font-weight:700;color:var(--primary);margin-bottom:8px">B2 Settings</div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                    <input type="hidden" name="action" value="b2_save_settings" />
                    <div class="row" style="align-items:flex-start">
                        <label style="min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Enable replication</div>
                            <input type="checkbox" name="b2_enabled" value="1" <?php echo !empty($b2['enabled']) ? 'checked' : ''; ?> />
                        </label>
                        <label style="min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Object Lock enabled</div>
                            <input type="checkbox" name="b2_object_lock_enabled" value="1" <?php echo !empty($b2['object_lock_enabled']) ? 'checked' : ''; ?> />
                        </label>
                        <label style="min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Hard delete after sync</div>
                            <input type="checkbox" name="b2_hard_delete" value="1" <?php echo !empty($b2['hard_delete']) ? 'checked' : ''; ?> />
                        </label>
                    </div>
                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:260px">
                            <div class="muted" style="margin-bottom:6px">Application Key ID</div>
                            <input type="text" name="b2_account_id" value="" placeholder="<?php echo h($b2Masked['account_id'] !== '' ? $b2Masked['account_id'] : 'Paste Backblaze Application Key ID'); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:260px">
                            <div class="muted" style="margin-bottom:6px">Application Key</div>
                            <input type="text" name="b2_application_key" value="" placeholder="<?php echo h($b2Masked['application_key'] !== '' ? $b2Masked['application_key'] : 'Paste Backblaze Application Key'); ?>" style="width:100%" />
                        </label>
                    </div>
                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Bucket</div>
                            <input type="text" name="b2_bucket" value="<?php echo h((string)($b2['bucket'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Bucket path prefix</div>
                            <input type="text" name="b2_bucket_path" value="<?php echo h((string)($b2['bucket_path'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Restore root</div>
                            <input type="text" name="b2_restore_root" value="<?php echo h((string)($b2['restore_root'] ?? '')); ?>" style="width:100%" />
                        </label>
                    </div>
                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">rclone remote name</div>
                            <input type="text" name="b2_remote_name" value="<?php echo h((string)($b2['remote_name'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">crypt remote name</div>
                            <input type="text" name="b2_crypt_remote_name" value="<?php echo h((string)($b2['crypt_remote_name'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Bandwidth limit</div>
                            <input type="text" name="b2_bandwidth_limit" value="<?php echo h((string)($b2['bandwidth_limit'] ?? '')); ?>" placeholder="e.g. 40M" style="width:100%" />
                        </label>
                    </div>
                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:260px">
                            <div class="muted" style="margin-bottom:6px">crypt password</div>
                            <input type="text" name="b2_crypt_password" value="" placeholder="<?php echo h($b2Masked['crypt_password'] !== '' ? $b2Masked['crypt_password'] : 'Enter rclone crypt password'); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:260px">
                            <div class="muted" style="margin-bottom:6px">crypt salt password</div>
                            <input type="text" name="b2_crypt_password2" value="" placeholder="<?php echo h($b2Masked['crypt_password2'] !== '' ? $b2Masked['crypt_password2'] : 'Optional password2 / salt'); ?>" style="width:100%" />
                        </label>
                    </div>
                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="min-width:150px">
                            <div class="muted" style="margin-bottom:6px">Transfers</div>
                            <input type="number" name="b2_transfers" min="1" value="<?php echo h((string)($b2['transfers'] ?? 4)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:150px">
                            <div class="muted" style="margin-bottom:6px">Checkers</div>
                            <input type="number" name="b2_checkers" min="1" value="<?php echo h((string)($b2['checkers'] ?? 8)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:180px">
                            <div class="muted" style="margin-bottom:6px">Object Lock mode</div>
                            <select name="b2_object_lock_mode" style="width:100%">
                                <option value="governance" <?php echo ((string)($b2['object_lock_mode'] ?? 'governance') === 'governance') ? 'selected' : ''; ?>>governance</option>
                                <option value="compliance" <?php echo ((string)($b2['object_lock_mode'] ?? '') === 'compliance') ? 'selected' : ''; ?>>compliance</option>
                            </select>
                        </label>
                        <label style="min-width:150px">
                            <div class="muted" style="margin-bottom:6px">Object Lock days</div>
                            <input type="number" name="b2_object_lock_days" min="1" value="<?php echo h((string)($b2['object_lock_days'] ?? 30)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:150px">
                            <div class="muted" style="margin-bottom:6px">Hide old versions after</div>
                            <input type="number" name="b2_lifecycle_hide_days" min="1" value="<?php echo h((string)($b2['lifecycle_hide_days'] ?? 30)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:150px">
                            <div class="muted" style="margin-bottom:6px">Delete hidden versions after</div>
                            <input type="number" name="b2_lifecycle_delete_days" min="1" value="<?php echo h((string)($b2['lifecycle_delete_days'] ?? 180)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:170px">
                            <div class="muted" style="margin-bottom:6px">Cancel unfinished large files after</div>
                            <input type="number" name="b2_lifecycle_cancel_incomplete_days" min="0" value="<?php echo h((string)($b2['lifecycle_cancel_incomplete_days'] ?? 3)); ?>" style="width:100%" />
                        </label>
                    </div>
                    <div class="muted" style="margin-top:10px">Required key capabilities: <?php echo h(implode(', ', isset($b2['required_capabilities']) && is_array($b2['required_capabilities']) ? $b2['required_capabilities'] : [])); ?></div>
                    <div class="muted" style="margin-top:6px">Docs: <a href="<?php echo h((string)($b2Docs['application_keys'] ?? '#')); ?>" target="_blank" rel="noopener">application keys</a>, <a href="<?php echo h((string)($b2Docs['application_key_capabilities'] ?? '#')); ?>" target="_blank" rel="noopener">capabilities</a>, <a href="<?php echo h((string)($b2Docs['authorize_account'] ?? '#')); ?>" target="_blank" rel="noopener">authorize account</a>, <a href="<?php echo h((string)($b2Docs['update_bucket'] ?? '#')); ?>" target="_blank" rel="noopener">update bucket</a>, <a href="<?php echo h((string)($b2Docs['object_lock'] ?? '#')); ?>" target="_blank" rel="noopener">Object Lock</a>, <a href="<?php echo h((string)($b2Docs['lifecycle_rules'] ?? '#')); ?>" target="_blank" rel="noopener">lifecycle rules</a>, <a href="<?php echo h((string)($b2Docs['rclone_b2'] ?? '#')); ?>" target="_blank" rel="noopener">rclone B2</a></div>
                    <div class="row" style="margin-top:12px">
                        <button class="btn btn-ok" type="submit">Save B2 Settings</button>
                    </div>
                </form>
            </div>

            <div class="card" style="margin:0">
                <div style="font-weight:700;color:var(--primary);margin-bottom:8px">Trigger Jobs</div>
                <div class="muted" style="margin-bottom:10px">Local sets push the latest archive or the selected snapshot archive to B2. Restore jobs pull a relative path or prefix back into a local restore root.</div>
                <table>
                    <thead>
                        <tr>
                            <th>Set</th>
                            <th>Latest snapshot</th>
                            <th>B2 action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sets as $sid => $set): ?>
                            <?php $latestSnapshot = isset($snapshotsBySet[$sid][0]['id']) ? (string)$snapshotsBySet[$sid][0]['id'] : ''; ?>
                            <tr>
                                <td class="mono"><?php echo h($sid); ?></td>
                                <td class="mono"><?php echo h($latestSnapshot !== '' ? $latestSnapshot : 'missing'); ?></td>
                                <td>
                                    <form method="post" class="row" style="margin:0">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                                        <input type="hidden" name="action" value="b2_push_snapshot" />
                                        <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                                        <select name="snapshot_id">
                                            <?php foreach ($snapshotsBySet[$sid] as $snapshotRow): ?>
                                                <?php $snapId = (string)($snapshotRow['id'] ?? ''); if ($snapId === '') continue; ?>
                                                <option value="<?php echo h($snapId); ?>" <?php echo $snapId === $latestSnapshot ? 'selected' : ''; ?>><?php echo h($snapId); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-sm" type="submit" <?php echo $latestSnapshot !== '' ? '' : 'disabled'; ?>>Queue B2 Replication</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="font-weight:700;color:var(--primary);margin:16px 0 8px">Restore From B2</div>
                <form method="post" class="row" style="align-items:flex-end">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                    <input type="hidden" name="action" value="b2_restore_prefix" />
                    <label style="flex:1;min-width:260px">
                        <div class="muted" style="margin-bottom:6px">Remote B2 relative path or prefix</div>
                        <input type="text" name="b2_remote_path" value="" placeholder="local-sets/data/2026-07-17_145003" style="width:100%" />
                    </label>
                    <label style="flex:1;min-width:260px">
                        <div class="muted" style="margin-bottom:6px">Override restore root</div>
                        <input type="text" name="b2_restore_root_override" value="<?php echo h((string)($b2['restore_root'] ?? '')); ?>" style="width:100%" />
                    </label>
                    <button class="btn btn-ok" type="submit">Queue B2 Restore</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-weight:700;color:var(--primary)">B2 Bucket Policy + Runtime</div>
                <div class="muted" style="margin-top:6px">This panel checks the bucket state against the desired Backblaze Object Lock and lifecycle settings, then applies drift through the B2 Native API `b2_update_bucket`. Object Lock can only be enabled and never disabled once the bucket is updated.</div>
            </div>
            <div class="muted mono" style="margin-top:2px">State: <?php echo h(mhb_b2_bucket_policy_state_path()); ?></div>
        </div>
        <div class="grid" style="margin-top:12px">
            <div class="card" style="margin:0">
                <div style="font-weight:700;color:var(--primary);margin-bottom:8px">Runtime</div>
                <table>
                    <tbody>
                        <tr><th>rclone binary</th><td class="mono"><?php echo h($rcloneBinary); ?></td></tr>
                        <tr><th>rsync binary</th><td class="mono"><?php echo h($rsyncBinary); ?></td></tr>
                        <tr><th>Compression</th><td class="mono">tar.gz</td></tr>
                        <tr><th>Jobs root</th><td class="mono"><?php echo h(mhb_b2_jobs_root()); ?></td></tr>
                        <tr><th>Restore root</th><td class="mono"><?php echo h((string)($b2['restore_root'] ?? '')); ?></td></tr>
                        <tr><th>Bucket prefix</th><td class="mono"><?php echo h((string)($b2PolicyBucket['prefix'] ?? mhb_b2_remote_prefix($b2))); ?></td></tr>
                    </tbody>
                </table>
                <div class="muted" style="margin-top:10px">`rsync` stages remote files and block-storage paths locally before `rclone` uploads the compressed artifacts into the encrypted B2 path.</div>
            </div>
            <div class="card" style="margin:0">
                <div style="font-weight:700;color:var(--primary);margin-bottom:8px">Bucket Policy State</div>
                <div id="mhB2PolicyPanel">
                    <div class="row">
                        <span class="pill <?php echo (!empty($b2PolicyDrift['default_retention']) || !empty($b2PolicyDrift['lifecycle_rules'])) ? 'warn' : 'ok'; ?>">
                            <?php echo (!empty($b2PolicyDrift['default_retention']) || !empty($b2PolicyDrift['lifecycle_rules'])) ? 'Drift detected' : 'In sync'; ?>
                        </span>
                        <?php if ($b2MissingCapabilities !== []): ?>
                            <span class="pill warn">Missing capabilities</span>
                        <?php else: ?>
                            <span class="pill ok">Capabilities ready</span>
                        <?php endif; ?>
                    </div>
                    <table style="margin-top:10px">
                        <tbody>
                            <tr><th>Bucket</th><td class="mono"><?php echo h((string)($b2PolicyBucket['name'] ?? (string)($b2['bucket'] ?? 'not checked'))); ?></td></tr>
                            <tr><th>Bucket ID</th><td class="mono"><?php echo h((string)($b2PolicyBucket['id'] ?? '')); ?></td></tr>
                            <tr><th>Last checked</th><td class="mono"><?php echo h((string)($b2PolicyState['checked_at'] ?? 'never')); ?></td></tr>
                            <tr><th>Last applied</th><td class="mono"><?php echo h((string)($b2PolicyState['applied_at'] ?? 'never')); ?></td></tr>
                            <tr><th>Current retention</th><td class="mono"><?php echo h(json_encode($b2PolicyCurrent['default_retention'] ?? ['mode' => null], JSON_UNESCAPED_SLASHES) ?: '{}'); ?></td></tr>
                            <tr><th>Desired retention</th><td class="mono"><?php echo h(json_encode($b2PolicyDesired['default_retention'] ?? ['mode' => null], JSON_UNESCAPED_SLASHES) ?: '{}'); ?></td></tr>
                            <tr><th>Current lifecycle</th><td class="mono"><?php echo h(json_encode($b2PolicyCurrent['lifecycle_rules'] ?? [], JSON_UNESCAPED_SLASHES) ?: '[]'); ?></td></tr>
                            <tr><th>Desired lifecycle</th><td class="mono"><?php echo h(json_encode($b2PolicyDesired['lifecycle_rules'] ?? [], JSON_UNESCAPED_SLASHES) ?: '[]'); ?></td></tr>
                            <tr><th>Message</th><td><?php echo h((string)($b2PolicyState['message'] ?? 'Refresh to inspect the live bucket state.')); ?></td></tr>
                        </tbody>
                    </table>
                    <?php if ($b2MissingCapabilities !== []): ?>
                        <div class="danger-note" style="margin-top:10px">Missing Backblaze key capabilities: <span class="mono"><?php echo h(implode(', ', $b2MissingCapabilities)); ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="row" style="margin-top:12px">
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                        <input type="hidden" name="action" value="b2_refresh_bucket_policy" />
                        <button class="btn" type="submit">Refresh Bucket State</button>
                    </form>
                    <form method="post" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                        <input type="hidden" name="action" value="b2_apply_bucket_policy" />
                        <button class="btn btn-ok" type="submit">Apply Bucket Settings</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-weight:700;color:var(--primary)">Remote Server Profiles</div>
                <div class="muted" style="margin-top:6px">Each profile stages remote data locally with `rsync`, optionally runs a declared image command over SSH, compresses the stage into `tar.gz`, and then pushes the result into the encrypted B2 path. Empty SSH/image fields are intentionally left for explicit operator input so nothing is guessed.</div>
            </div>
        </div>

        <?php foreach (($b2Config['servers'] ?? []) as $serverId => $server): ?>
            <div class="svc-card" style="margin-top:14px">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                    <input type="hidden" name="action" value="b2_save_server" />
                    <input type="hidden" name="server_id" value="<?php echo h($serverId); ?>" />
                    <div class="row" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <div class="svc-title"><?php echo h((string)($server['label'] ?? $serverId)); ?></div>
                            <div class="svc-meta">
                                <span class="pill <?php echo !empty($server['enabled']) ? 'ok' : 'warn'; ?>"><?php echo !empty($server['enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                <span class="pill"><?php echo h((string)($server['os'] ?? '')); ?></span>
                                <span class="pill"><?php echo h((string)($server['compression'] ?? 'tar.gz')); ?></span>
                                <span class="pill">Host</span><span class="mono"><?php echo h((string)($server['host'] ?? $serverId)); ?></span>
                            </div>
                        </div>
                        <div class="row">
                            <label>
                                <div class="muted" style="margin-bottom:6px">Enable</div>
                                <input type="checkbox" name="server_enabled" value="1" <?php echo !empty($server['enabled']) ? 'checked' : ''; ?> />
                            </label>
                            <button class="btn btn-sm btn-ok" type="submit">Save Profile</button>
                        </div>
                    </div>

                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Label</div>
                            <input type="text" name="server_label" value="<?php echo h((string)($server['label'] ?? $serverId)); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Host</div>
                            <input type="text" name="server_host" value="<?php echo h((string)($server['host'] ?? $serverId)); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:160px">
                            <div class="muted" style="margin-bottom:6px">OS</div>
                            <input type="text" name="server_os" value="<?php echo h((string)($server['os'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="min-width:140px">
                            <div class="muted" style="margin-bottom:6px">SSH port</div>
                            <input type="number" name="server_ssh_port" min="1" value="<?php echo h((string)($server['ssh_port'] ?? 22)); ?>" style="width:100%" />
                        </label>
                    </div>

                    <div class="row" style="margin-top:10px;align-items:flex-end">
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">SSH user</div>
                            <input type="text" name="server_ssh_user" value="<?php echo h((string)($server['ssh_user'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">SSH key path</div>
                            <input type="text" name="server_ssh_key_path" value="<?php echo h((string)($server['ssh_key_path'] ?? '')); ?>" style="width:100%" />
                        </label>
                        <label style="flex:1;min-width:220px">
                            <div class="muted" style="margin-bottom:6px">Remote spool dir</div>
                            <input type="text" name="server_remote_spool_dir" value="<?php echo h((string)($server['remote_spool_dir'] ?? '/var/backups/metahumans')); ?>" style="width:100%" />
                        </label>
                    </div>

                    <div class="grid" style="margin-top:10px">
                        <label>
                            <div class="muted" style="margin-bottom:6px">Filesystem paths (one per line)</div>
                            <textarea name="server_filesystem_paths"><?php echo h(implode("\n", isset($server['filesystem_paths']) && is_array($server['filesystem_paths']) ? $server['filesystem_paths'] : [])); ?></textarea>
                        </label>
                        <label>
                            <div class="muted" style="margin-bottom:6px">Block storage paths (one per line)</div>
                            <textarea name="server_block_storage_paths"><?php echo h(implode("\n", isset($server['block_storage_paths']) && is_array($server['block_storage_paths']) ? $server['block_storage_paths'] : [])); ?></textarea>
                        </label>
                    </div>

                    <div class="grid" style="margin-top:10px">
                        <label>
                            <div class="muted" style="margin-bottom:6px">Image fetch paths after image command (one per line)</div>
                            <textarea name="server_image_fetch_paths"><?php echo h(implode("\n", isset($server['image_fetch_paths']) && is_array($server['image_fetch_paths']) ? $server['image_fetch_paths'] : [])); ?></textarea>
                        </label>
                        <label>
                            <div class="muted" style="margin-bottom:6px">Image command (runs remotely via `ssh host 'bash -lc ...'`)</div>
                            <textarea name="server_image_command"><?php echo h((string)($server['image_command'] ?? '')); ?></textarea>
                        </label>
                    </div>

                    <label style="display:block;margin-top:10px">
                        <div class="muted" style="margin-bottom:6px">Operator notes</div>
                        <textarea name="server_notes"><?php echo h((string)($server['notes'] ?? '')); ?></textarea>
                    </label>

                    <div class="row" style="margin-top:12px">
                        <button class="btn btn-ok" type="submit">Save Profile</button>
                    </div>
                </form>
                <form method="post" class="row" style="margin-top:10px">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                    <input type="hidden" name="action" value="b2_push_server" />
                    <input type="hidden" name="server_id" value="<?php echo h($serverId); ?>" />
                    <button class="btn" type="submit">Queue rsync + B2 Replication</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-weight:700;color:var(--primary)">B2 Job Monitor</div>
                <div class="muted" style="margin-top:6px">The table polls the B2 queue and result files under <span class="mono"><?php echo h(mhb_b2_jobs_root()); ?></span> so replication and restore work can be monitored directly from this page.</div>
            </div>
        </div>
        <table id="mhB2JobsTable" style="margin-top:12px">
            <thead>
                <tr>
                    <th>Updated</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>State</th>
                    <th>Remote path</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="mhB2JobsBody">
                <?php foreach ($b2RecentJobs as $job): ?>
                    <tr>
                        <td class="mono"><?php echo h((string)($job['updated_at'] ?? $job['requested_at'] ?? '')); ?></td>
                        <td class="mono"><?php echo h((string)($job['action'] ?? '')); ?></td>
                        <td class="mono"><?php echo h((string)($job['server_id'] ?? $job['set_id'] ?? $job['remote_path'] ?? '')); ?></td>
                        <td class="mono"><?php echo h((string)($job['state'] ?? 'unknown')); ?></td>
                        <td class="mono"><?php echo h((string)($job['remote_path'] ?? '')); ?></td>
                        <td><?php echo h((string)($job['message'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($b2RecentJobs === []): ?>
                    <tr><td colspan="6" class="muted">No B2 jobs have run yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <script>
            (function () {
                const body = document.getElementById('mhB2JobsBody');
                if (!body) return;
                function esc(v) {
                    return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));
                }
                async function refreshB2Jobs() {
                    try {
                        const res = await fetch('?b2_jobs=1', { credentials: 'same-origin', cache: 'no-store' });
                        const data = await res.json();
                        if (!data || data.success !== true || !Array.isArray(data.jobs)) return;
                        if (data.jobs.length === 0) {
                            body.innerHTML = '<tr><td colspan="6" class="muted">No B2 jobs have run yet.</td></tr>';
                            return;
                        }
                        body.innerHTML = data.jobs.map(job => {
                            const target = job.server_id || job.set_id || job.remote_path || '';
                            return '<tr>'
                                + '<td class="mono">' + esc(job.updated_at || job.requested_at || '') + '</td>'
                                + '<td class="mono">' + esc(job.action || '') + '</td>'
                                + '<td class="mono">' + esc(target) + '</td>'
                                + '<td class="mono">' + esc(job.state || '') + '</td>'
                                + '<td class="mono">' + esc(job.remote_path || '') + '</td>'
                                + '<td>' + esc(job.message || '') + '</td>'
                                + '</tr>';
                        }).join('');
                    } catch (err) {
                    }
                }
                refreshB2Jobs();
                setInterval(refreshB2Jobs, 15000);
            })();
        </script>
        <script>
            (function () {
                const panel = document.getElementById('mhB2PolicyPanel');
                if (!panel) return;
                function esc(v) {
                    return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));
                }
                async function refreshB2Policy() {
                    try {
                        const res = await fetch('?b2_policy=1', { credentials: 'same-origin', cache: 'no-store' });
                        const data = await res.json();
                        if (!data || data.success !== true || !data.state || typeof data.state !== 'object') return;
                        const state = data.state;
                        const bucket = state.bucket && typeof state.bucket === 'object' ? state.bucket : {};
                        const current = state.current && typeof state.current === 'object' ? state.current : {};
                        const desired = state.desired && typeof state.desired === 'object' ? state.desired : {};
                        const drift = state.drift && typeof state.drift === 'object' ? state.drift : {};
                        const missing = Array.isArray(state.missing_capabilities) ? state.missing_capabilities : [];
                        const inSync = !drift.default_retention && !drift.lifecycle_rules;
                        panel.innerHTML = ''
                            + '<div class="row">'
                            + '<span class="pill ' + (inSync ? 'ok' : 'warn') + '">' + (inSync ? 'In sync' : 'Drift detected') + '</span>'
                            + '<span class="pill ' + (missing.length === 0 ? 'ok' : 'warn') + '">' + (missing.length === 0 ? 'Capabilities ready' : 'Missing capabilities') + '</span>'
                            + '</div>'
                            + '<table style="margin-top:10px"><tbody>'
                            + '<tr><th>Bucket</th><td class="mono">' + esc(bucket.name || '') + '</td></tr>'
                            + '<tr><th>Bucket ID</th><td class="mono">' + esc(bucket.id || '') + '</td></tr>'
                            + '<tr><th>Last checked</th><td class="mono">' + esc(state.checked_at || 'never') + '</td></tr>'
                            + '<tr><th>Last applied</th><td class="mono">' + esc(state.applied_at || 'never') + '</td></tr>'
                            + '<tr><th>Current retention</th><td class="mono">' + esc(JSON.stringify(current.default_retention || {mode:null})) + '</td></tr>'
                            + '<tr><th>Desired retention</th><td class="mono">' + esc(JSON.stringify(desired.default_retention || {mode:null})) + '</td></tr>'
                            + '<tr><th>Current lifecycle</th><td class="mono">' + esc(JSON.stringify(current.lifecycle_rules || [])) + '</td></tr>'
                            + '<tr><th>Desired lifecycle</th><td class="mono">' + esc(JSON.stringify(desired.lifecycle_rules || [])) + '</td></tr>'
                            + '<tr><th>Message</th><td>' + esc(state.message || 'Refresh to inspect the live bucket state.') + '</td></tr>'
                            + '</tbody></table>'
                            + (missing.length ? '<div class="danger-note" style="margin-top:10px">Missing Backblaze key capabilities: <span class="mono">' + esc(missing.join(', ')) + '</span></div>' : '');
                    } catch (err) {
                    }
                }
                refreshB2Policy();
                setInterval(refreshB2Policy, 30000);
            })();
        </script>
    </div>

    <div class="card">
        <div class="row" style="justify-content: space-between;">
            <div>
                <div style="font-weight:700;color:var(--primary)">Database Services</div>
                <div class="muted">Actions run via a root cron worker and report results here.</div>
            </div>
        </div>
        <div class="svc-grid">
            <?php
                $svcList = [
                    'mariadb' => ['label' => 'MariaDB', 'unit' => 'mariadb.service'],
                    'redis' => ['label' => 'Redis', 'unit' => 'redis.service'],
                ];
            ?>
            <?php foreach ($svcList as $k => $svc): ?>
                <?php
                    $st = isset($serviceStatus[$k]) && is_array($serviceStatus[$k]) ? $serviceStatus[$k] : [];
                    $active = (string)($st['active'] ?? 'unknown');
                    $enabled = (string)($st['enabled'] ?? 'unknown');
                    $pillClass = ($active === 'active') ? 'pill ok' : 'pill warn';
                ?>
                <div class="svc-card">
                    <div class="svc-title"><?php echo h($svc['label']); ?></div>
                    <div class="svc-meta">
                        <span class="<?php echo h($pillClass); ?>">Active</span><span class="mono"><?php echo h($active); ?></span>
                        <span class="pill">Enabled</span><span class="mono"><?php echo h($enabled); ?></span>
                        <span class="pill">Unit</span><span class="mono"><?php echo h($svc['unit']); ?></span>
                    </div>
                    <div class="row" style="margin-top:10px">
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                            <input type="hidden" name="action" value="service_action" />
                            <input type="hidden" name="service" value="<?php echo h($k); ?>" />
                            <input type="hidden" name="service_cmd" value="start" />
                            <button class="btn btn-sm btn-ok" type="submit">Start</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                            <input type="hidden" name="action" value="service_action" />
                            <input type="hidden" name="service" value="<?php echo h($k); ?>" />
                            <input type="hidden" name="service_cmd" value="stop" />
                            <button class="btn btn-sm" type="submit">Stop</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                            <input type="hidden" name="action" value="service_action" />
                            <input type="hidden" name="service" value="<?php echo h($k); ?>" />
                            <input type="hidden" name="service_cmd" value="restart" />
                            <button class="btn btn-sm" type="submit">Restart</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($serviceResults)): ?>
            <div style="margin-top:14px">
                <div class="muted" style="margin-bottom:6px">Recent service actions</div>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Service</th>
                            <th>Action</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($serviceResults as $r): ?>
                            <tr>
                                <td class="mono"><?php echo h($r['ran_at'] ?? ''); ?></td>
                                <td><?php echo h($r['service'] ?? ''); ?></td>
                                <td><?php echo h($r['action'] ?? ''); ?></td>
                                <td class="mono"><?php echo h(($r['ok'] ?? false) ? 'ok' : ('exit=' . (string)($r['exit_code'] ?? ''))); ?> <?php echo h($r['active'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-weight:700;color:var(--primary)">MySQL Logical Backups (Per Database)</div>
                <div class="muted" style="margin-top:6px">Select MySQL connection, load databases, and backup each database individually with its own frequency and retention.</div>
            </div>
            <div class="muted mono" style="margin-top:2px">Dest: <?php echo h(mhb_mysql_backups_backup_root()); ?></div>
        </div>
        <div id="mhMysqlDumpsUi" style="margin-top:12px"></div>
        <div class="row" style="margin-top:10px;align-items:center">
            <button class="btn" type="button" id="mhMysqlDumpsSaveBtn">Save MySQL Backup Policy</button>
            <span class="muted" id="mhMysqlDumpsMsg"></span>
        </div>
        <?php
            $candJson = json_encode($mysqlCandidates, JSON_UNESCAPED_SLASHES);
            $polJson = json_encode($mysqlPolicy, JSON_UNESCAPED_SLASHES);
        ?>
        <script type="application/json" id="mhMysqlDumpsCandidates"><?php echo is_string($candJson) ? $candJson : '[]'; ?></script>
        <script type="application/json" id="mhMysqlDumpsPolicy"><?php echo is_string($polJson) ? $polJson : '{}'; ?></script>
        <script>
            (function () {
                const wrap = document.getElementById('mhMysqlDumpsUi');
                const saveBtn = document.getElementById('mhMysqlDumpsSaveBtn');
                const msg = document.getElementById('mhMysqlDumpsMsg');
                const candEl = document.getElementById('mhMysqlDumpsCandidates');
                const polEl = document.getElementById('mhMysqlDumpsPolicy');
                if (!wrap || !saveBtn || !candEl || !polEl) return;

                const csrf = <?php echo json_encode((string)$csrfToken, JSON_UNESCAPED_SLASHES); ?>;

                function esc(s) {
                    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));
                }
                function setMsg(t) {
                    if (!msg) return;
                    msg.textContent = String(t || '');
                }
                function post(body) {
                    const params = new URLSearchParams();
                    for (const k in body) {
                        if (!Object.prototype.hasOwnProperty.call(body, k)) continue;
                        params.set(k, String(body[k]));
                    }
                    params.set('csrf_token', csrf);
                    return fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: String(params) });
                }

                let candidates = [];
                let policy = {};
                try { candidates = JSON.parse(candEl.textContent || '[]') || []; } catch (e) { candidates = []; }
                try { policy = JSON.parse(polEl.textContent || '{}') || {}; } catch (e) { policy = {}; }

                const selectId = 'mhMysqlDumpsConn';
                const loadId = 'mhMysqlDumpsLoad';
                const listId = 'mhMysqlDumpsList';
                const summaryId = 'mhMysqlDumpsPolicySummary';
                wrap.innerHTML = ''
                    + '<div id="' + summaryId + '"></div>'
                    + '<div class="row" style="align-items:flex-end">'
                    + '  <div style="min-width:320px;max-width:520px;flex:1">'
                    + '    <div class="muted" style="margin-bottom:6px">MySQL connection config</div>'
                    + '    <select id="' + selectId + '" style="width:100%"></select>'
                    + '  </div>'
                    + '  <button class="btn" type="button" id="' + loadId + '">Load Databases</button>'
                    + '</div>'
                    + '<div id="' + listId + '" style="margin-top:10px"></div>';

                const sel = document.getElementById(selectId);
                const loadBtn = document.getElementById(loadId);
                const list = document.getElementById(listId);
                const summary = document.getElementById(summaryId);
                if (!sel || !loadBtn || !list) return;

                function getDbPolicy() {
                    if (policy && typeof policy === 'object' && Number(policy.version) >= 2 && policy.databases && typeof policy.databases === 'object') {
                        return policy.databases || {};
                    }
                    return {};
                }
                let connId = (policy && typeof policy === 'object' && policy.connection_config_id) ? String(policy.connection_config_id) : '';
                if (!connId && candidates.length) connId = String((candidates[0] || {}).id || '');

                let options = '<option value="">Select…</option>';
                for (let i = 0; i < candidates.length; i++) {
                    const c = candidates[i] || {};
                    const id = String(c.id || '');
                    const port = c.port != null ? String(c.port) : '';
                    const label = (c.name ? String(c.name) : id) + (port ? (' : ' + port) : '');
                    options += '<option value="' + esc(id) + '"' + (id === connId ? ' selected' : '') + '>' + esc(label) + '</option>';
                }
                sel.innerHTML = options;

                let loadedDatabases = [];

                function renderPolicySummary() {
                    if (!summary) return;
                    const v = policy && typeof policy === 'object' ? Number(policy.version || 0) : 0;
                    if (v < 2) {
                        summary.innerHTML = '<div class="muted" style="margin-bottom:10px">No saved MySQL per-database policy.</div>';
                        return;
                    }
                    const pol = getDbPolicy();
                    const enabled = [];
                    for (const dbName in pol) {
                        if (!Object.prototype.hasOwnProperty.call(pol, dbName)) continue;
                        const row = pol[dbName] || {};
                        if (row && row.enabled) {
                            enabled.push({
                                db: String(dbName),
                                frequency: row.frequency ? String(row.frequency) : 'daily',
                                retention: row.retention != null ? Number(row.retention) : 14,
                            });
                        }
                    }
                    enabled.sort((a, b) => a.db.localeCompare(b.db));
                    const polConn = policy && policy.connection_config_id ? String(policy.connection_config_id) : '';
                    const polAt = policy && policy.updated_at ? String(policy.updated_at) : '';
                    let html = '';
                    html += '<div class="muted" style="margin-bottom:8px">';
                    html += 'Saved policy: <span class="mono">' + esc(polConn || '—') + '</span>';
                    if (polAt) html += ' <span class="mono" style="margin-left:10px">' + esc(polAt) + '</span>';
                    html += '</div>';
                    if (!enabled.length) {
                        html += '<div class="muted" style="margin-bottom:10px">No databases enabled yet.</div>';
                        summary.innerHTML = html;
                        return;
                    }
                    html += '<table style="width:100%;border-collapse:collapse;margin-bottom:10px">';
                    html += '<tr><th style="text-align:left;padding:8px">Database</th><th style="text-align:left;padding:8px">Frequency</th><th style="text-align:left;padding:8px">Retention</th><th style="text-align:left;padding:8px">Actions</th></tr>';
                    for (let i = 0; i < enabled.length; i++) {
                        const r = enabled[i];
                        html += '<tr style="border-top:1px solid rgba(0,212,255,0.10)">';
                        html += '<td style="padding:8px"><span class="mono">' + esc(r.db) + '</span></td>';
                        html += '<td style="padding:8px">' + esc(r.frequency) + '</td>';
                        html += '<td style="padding:8px">' + esc(String(isFinite(r.retention) ? r.retention : 14)) + '</td>';
                        html += '<td style="padding:8px"><button class="btn btn-sm" type="button" data-mh-pol-backup="1" data-db="' + esc(r.db) + '" data-freq="' + esc(r.frequency) + '" data-ret="' + esc(String(isFinite(r.retention) ? r.retention : 14)) + '">Backup Now</button></td>';
                        html += '</tr>';
                    }
                    html += '</table>';
                    summary.innerHTML = html;

                    const btns = summary.querySelectorAll('button[data-mh-pol-backup="1"]');
                    btns.forEach((b) => {
                        b.addEventListener('click', async function () {
                            const db = String(b.getAttribute('data-db') || '');
                            const frequency = String(b.getAttribute('data-freq') || 'daily');
                            const retention = String(b.getAttribute('data-ret') || '14');
                            const id = (policy && policy.connection_config_id) ? String(policy.connection_config_id) : String(sel.value || '').trim();
                            if (!id) { setMsg('Select a MySQL config first'); return; }
                            setMsg('Backing up ' + db + '...');
                            try {
                                const resp = await post({ action: 'mysql_dump_now', connection_config_id: id, database: db, frequency: frequency, retention: retention });
                                const data = await resp.json();
                                if (data && data.success) {
                                    setMsg('Backup created: ' + db);
                                    setTimeout(() => setMsg(''), 1500);
                                } else {
                                    setMsg(data && data.message ? data.message : 'Backup failed');
                                }
                            } catch (e) {
                                setMsg('Backup failed');
                            }
                        });
                    });
                }

                function renderDbTable(databases) {
                    const pol = getDbPolicy();
                    let html = '';
                    html += '<table style="width:100%;border-collapse:collapse;margin-top:6px">';
                    html += '<tr><th style="text-align:left;padding:8px">Enable</th><th style="text-align:left;padding:8px">Database</th><th style="text-align:left;padding:8px">Frequency</th><th style="text-align:left;padding:8px">Retention</th><th style="text-align:left;padding:8px">Actions</th></tr>';
                    for (let i = 0; i < databases.length; i++) {
                        const db = String(databases[i] || '');
                        const p = (pol && typeof pol === 'object') ? (pol[db] || {}) : {};
                        const enabled = !!(p && p.enabled);
                        const freq = (p && p.frequency) ? String(p.frequency) : 'daily';
                        const ret = (p && p.retention != null) ? Number(p.retention) : 14;
                        html += '<tr style="border-top:1px solid rgba(0,212,255,0.10)">';
                        html += '<td style="padding:8px"><input type="checkbox" data-mh-db-enable="1" data-db="' + esc(db) + '"' + (enabled ? ' checked' : '') + ' /></td>';
                        html += '<td style="padding:8px"><span class="mono">' + esc(db) + '</span></td>';
                        html += '<td style="padding:8px"><select data-mh-db-freq="1" data-db="' + esc(db) + '" style="min-width:120px">';
                        html += '<option value="hourly"' + (freq === 'hourly' ? ' selected' : '') + '>Hourly</option>';
                        html += '<option value="daily"' + (freq === 'daily' ? ' selected' : '') + '>Daily</option>';
                        html += '<option value="weekly"' + (freq === 'weekly' ? ' selected' : '') + '>Weekly</option>';
                        html += '<option value="monthly"' + (freq === 'monthly' ? ' selected' : '') + '>Monthly</option>';
                        html += '</select></td>';
                        html += '<td style="padding:8px"><input data-mh-db-ret="1" data-db="' + esc(db) + '" type="number" min="1" max="2000" value="' + esc(String(isFinite(ret) ? ret : 14)) + '" style="width:120px" /></td>';
                        html += '<td style="padding:8px"><button class="btn btn-sm" type="button" data-mh-db-backup="1" data-db="' + esc(db) + '">Backup Now</button></td>';
                        html += '</tr>';
                    }
                    html += '</table>';
                    list.innerHTML = html;

                    const buttons = list.querySelectorAll('button[data-mh-db-backup="1"]');
                    buttons.forEach((b) => {
                        b.addEventListener('click', async function () {
                            const db = String(b.getAttribute('data-db') || '');
                            if (!db) return;
                            const fq = list.querySelector('select[data-mh-db-freq="1"][data-db="' + CSS.escape(db) + '"]');
                            const rt = list.querySelector('input[data-mh-db-ret="1"][data-db="' + CSS.escape(db) + '"]');
                            const frequency = fq ? String(fq.value) : 'daily';
                            const retention = rt ? String(rt.value) : '14';
                            const id = String(sel.value || '').trim();
                            if (!id) { setMsg('Select a MySQL config first'); return; }
                            setMsg('Backing up ' + db + '...');
                            try {
                                const resp = await post({ action: 'mysql_dump_now', connection_config_id: id, database: db, frequency: frequency, retention: retention });
                                const data = await resp.json();
                                if (data && data.success) {
                                    setMsg('Backup created: ' + db);
                                    setTimeout(() => setMsg(''), 1500);
                                } else {
                                    setMsg(data && data.message ? data.message : 'Backup failed');
                                }
                            } catch (e) {
                                setMsg('Backup failed');
                            }
                        });
                    });
                }

                async function loadDatabases() {
                    const id = String(sel.value || '').trim();
                    if (!id) { list.innerHTML = '<div class="muted">Select a MySQL config first.</div>'; return; }
                    setMsg('Loading databases...');
                    list.innerHTML = '<div class="muted">Loading...</div>';
                    try {
                        const resp = await post({ action: 'mysql_dbs_list', connection_config_id: id });
                        const text = await resp.text();
                        const data = text ? JSON.parse(text) : null;
                        if (!data || !data.success) {
                            list.innerHTML = '<div class="muted">Failed: ' + esc((data && data.message) ? data.message : 'Request failed') + '</div>';
                            setMsg('');
                            return;
                        }
                        loadedDatabases = Array.isArray(data.databases) ? data.databases : [];
                        renderDbTable(loadedDatabases);
                        setMsg('');
                    } catch (e) {
                        list.innerHTML = '<div class="muted">Failed to load databases</div>';
                        setMsg('');
                    }
                }

                loadBtn.addEventListener('click', loadDatabases);
                sel.addEventListener('change', function () { loadedDatabases = []; list.innerHTML = ''; });

                saveBtn.addEventListener('click', async function () {
                    const id = String(sel.value || '').trim();
                    if (!id) { setMsg('Select a MySQL config first'); return; }
                    if (!loadedDatabases.length) { setMsg('Load databases first'); return; }
                    setMsg('Saving...');
                    const dbs = {};
                    for (let i = 0; i < loadedDatabases.length; i++) {
                        const db = String(loadedDatabases[i] || '');
                        const en = list.querySelector('input[data-mh-db-enable="1"][data-db="' + CSS.escape(db) + '"]');
                        const fq = list.querySelector('select[data-mh-db-freq="1"][data-db="' + CSS.escape(db) + '"]');
                        const rt = list.querySelector('input[data-mh-db-ret="1"][data-db="' + CSS.escape(db) + '"]');
                        dbs[db] = { enabled: !!(en && en.checked), frequency: fq ? fq.value : 'daily', retention: rt ? Number(rt.value) : 14 };
                    }
                    try {
                        const resp = await post({ action: 'mysql_policy_save', connection_config_id: id, databases: JSON.stringify(dbs) });
                        const data = await resp.json();
                        if (data && data.success && data.policy) {
                            policy = data.policy;
                            renderPolicySummary();
                            setMsg('Saved');
                            setTimeout(() => setMsg(''), 1200);
                        } else {
                            setMsg(data && data.message ? data.message : 'Save failed');
                        }
                    } catch (e) {
                        setMsg('Save failed');
                    }
                });

                renderPolicySummary();
                if (sel.value) {
                    loadDatabases();
                }
            })();
        </script>
    </div>

    <div class="grid">
        <?php foreach ($sets as $sid => $cfg): ?>
            <div class="card">
                <div class="row" style="justify-content: space-between;">
                    <div>
                        <div style="font-weight:700;color:var(--primary)"><?php echo h($cfg['label']); ?></div>
                        <div class="muted mono">Dest: <?php echo h(mhb_set_dir($sid)); ?></div>
                        <?php
                            $lr = isset($lastRuns[$sid]) && is_array($lastRuns[$sid]) ? $lastRuns[$sid] : null;
                            $lastEpoch = $lr && isset($lr['last_run_epoch']) ? (int)$lr['last_run_epoch'] : 0;
                            $lastAt = $lr && isset($lr['last_run_at']) ? (string)$lr['last_run_at'] : '';
                            $freq = (string)($cfg['frequency'] ?? '');
                            $interval = ($freq === 'hourly') ? 3600 : 86400;
                            $nextEpoch = $lastEpoch > 0 ? ($lastEpoch + $interval) : mhb_next_due_epoch($sid);
                            $grace = ($freq === 'hourly') ? 900 : 7200;
                            $overdue = ($lastEpoch > 0) ? (time() > ($nextEpoch + $grace)) : false;
                            $nextAt = date('c', $nextEpoch);
                            $statusPill = $overdue ? 'pill warn' : 'pill ok';
                        ?>
                        <div class="muted" style="margin-top:6px">
                            <span class="<?php echo h($statusPill); ?>">Last run</span>
                            <span class="mono"><?php echo $lastEpoch > 0 ? h($lastAt) : 'Never'; ?></span>
                            <span class="<?php echo h($statusPill); ?>" style="margin-left:8px">Next due</span>
                            <span class="mono"><?php echo h($nextAt); ?></span>
                            <?php if ($lr && isset($lr['status']) && $lr['status'] === 'error'): ?>
                                <div class="danger-note mono" style="margin-top:6px"><?php echo h((string)($lr['error'] ?? 'Last run failed')); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;align-items:flex-end">
                        <form method="post" class="row" style="margin:0;gap:10px;align-items:flex-end;justify-content:flex-end;flex-wrap:wrap">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                            <input type="hidden" name="action" value="update_policy" />
                            <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                            <div>
                                <div class="muted">Frequency</div>
                                <select name="frequency">
                                    <?php foreach (['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $fv => $fl): ?>
                                        <option value="<?php echo h($fv); ?>" <?php echo ((string)($cfg['frequency'] ?? '') === $fv) ? 'selected' : ''; ?>><?php echo h($fl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <div class="muted">Retention</div>
                                <input type="number" name="retention" min="1" max="2000" value="<?php echo h((string)($cfg['retention'] ?? 0)); ?>" style="width:120px" />
                            </div>
                            <button class="btn" type="submit">Save</button>
                        </form>
                        <form method="post" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                            <input type="hidden" name="action" value="backup_now" />
                            <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                            <button class="btn btn-ok" type="submit">Backup Now</button>
                        </form>
                    </div>
                </div>

                <?php $list = $snapshotsBySet[$sid] ?? []; ?>
                <?php if (!empty($list)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Snapshot</th>
                                <th>Copied</th>
                                <th>Linked</th>
                                <th>Deleted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($list, 0, 8) as $snap): ?>
                                <?php $m = $snap['manifest'] ?? null; ?>
                                <?php $isStreamDownload = mhb_snapshot_uses_stream_download($sid); ?>
                                <?php if ($isStreamDownload) { mhb_snapshot_stream_download_reset($sid, (string)$snap['id']); $archiveState = ['state' => 'stream']; } else { $archiveState = mhb_snapshot_archive_status($sid, (string)$snap['id']); } ?>
                                <?php
                                    $snapId = (string)$snap['id'];
                                    $downloadUrl = '?download=1&set=' . rawurlencode($sid) . '&snap=' . rawurlencode($snapId);
                                    $archiveMessage = isset($archiveState['message']) && is_string($archiveState['message']) ? trim($archiveState['message']) : '';
                                    $tempBytes = 0.0;
                                    if (!$isStreamDownload && isset($archiveState['temp_files']) && is_array($archiveState['temp_files'])) {
                                        foreach ($archiveState['temp_files'] as $tempPath) {
                                            if (!is_string($tempPath) || $tempPath === '') {
                                                continue;
                                            }
                                            clearstatcache(true, $tempPath);
                                            $tempSizeRaw = @filesize($tempPath);
                                            if (is_numeric($tempSizeRaw)) {
                                                $tempBytes += (float)$tempSizeRaw;
                                            }
                                        }
                                    }
                                    $detailParts = [];
                                    if ($archiveMessage !== '') {
                                        $detailParts[] = $archiveMessage;
                                    }
                                    if (!$isStreamDownload && $tempBytes > 0) {
                                        $detailParts[] = 'Current temp archive: ' . mhb_format_bytes($tempBytes) . '.';
                                    }
                                    if (!$isStreamDownload && (($archiveState['state'] ?? '') === 'failed') && $backupVolume['is_full']) {
                                        $detailParts[] = 'Backup volume is full: ' . $backupVolume['free_text'] . ' free.';
                                    }
                                    $archiveDetail = implode(' ', $detailParts);
                                ?>
                                <tr data-archive-row="1" data-set="<?php echo h($sid); ?>" data-snapshot="<?php echo h($snapId); ?>" data-state="<?php echo h((string)($archiveState['state'] ?? 'missing')); ?>">
                                    <td class="mono"><?php echo h($snap['id']); ?><div class="muted"><?php echo h($m['created_at'] ?? ''); ?></div></td>
                                    <td><?php echo h($m['changed'] ?? ''); ?></td>
                                    <td><?php echo h($m['linked'] ?? ''); ?></td>
                                    <td><?php echo h($m['deleted'] ?? ''); ?></td>
                                    <td>
                                        <div class="row">
                                            <a class="btn" data-download-link="1" data-set="<?php echo h($sid); ?>" data-snapshot="<?php echo h($snapId); ?>" href="<?php echo h($downloadUrl); ?>"><?php echo $isStreamDownload ? 'Download TAR' : ((($archiveState['state'] ?? '') === 'ready') ? 'Download' : 'Prepare Download'); ?></a>
                                            <?php if (!$isStreamDownload): ?>
                                                <form method="post" style="margin:0" data-archive-generate="1" data-set="<?php echo h($sid); ?>" data-snapshot="<?php echo h($snapId); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                                                    <input type="hidden" name="action" value="archive_generate" />
                                                    <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                                                    <input type="hidden" name="snapshot_id" value="<?php echo h($snapId); ?>" />
                                                    <button class="btn" type="submit">Generate Archive</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="muted" data-archive-monitor="1">
                                            <?php if ($isStreamDownload): ?>
                                                Direct TAR stream from snapshot. Starts immediately; duration depends on disk and network throughput.
                                            <?php else: ?>
                                                Archive: <?php echo h((string)($archiveState['state'] ?? 'missing')); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$isStreamDownload): ?>
                                            <div class="<?php echo (($archiveState['state'] ?? '') === 'failed') ? 'muted danger-note' : 'muted'; ?>" data-archive-detail="1" <?php echo $archiveDetail === '' ? 'style="display:none"' : ''; ?>>
                                                <?php echo h($archiveDetail); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted" style="margin-top:12px">No snapshots yet.</div>
                <?php endif; ?>

                <div style="margin-top:14px">
                    <form method="post" class="row" style="align-items:flex-end" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                        <input type="hidden" name="action" value="upload_archive" />
                        <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                        <div>
                            <div class="muted">Upload .zip into this set</div>
                            <input type="file" name="archive" accept=".zip" required />
                        </div>
                        <button class="btn" type="submit">Upload & Import</button>
                    </form>
                </div>

                <div class="card" style="margin-top:14px">
                    <div style="font-weight:700;color:var(--primary);margin-bottom:6px">Restore</div>
                    <?php if ($sid === 'mysql'): ?>
                        <div class="muted danger-note">For /mysql, stop database services before applying restore.</div>
                    <?php endif; ?>
                    <?php $hasSnapshots = !empty($list); ?>
                    <form method="post" class="row" style="margin-top:10px;align-items:flex-end">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>" />
                        <input type="hidden" name="set_id" value="<?php echo h($sid); ?>" />
                        <div>
                            <div class="muted">Snapshot</div>
                            <select name="snapshot_id" <?php echo $hasSnapshots ? '' : 'disabled'; ?>>
                                <?php if ($hasSnapshots): ?>
                                    <?php foreach (array_slice($list, 0, 20) as $s2): ?>
                                        <option value="<?php echo h($s2['id']); ?>"><?php echo h($s2['id']); ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" selected>No snapshots available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <?php if ($sid === 'mysql'): ?>
                            <label class="muted" style="display:flex;gap:8px;align-items:center">
                                <input type="checkbox" checked disabled />
                                Restore DB config files (forced)
                            </label>
                        <?php else: ?>
                            <label class="muted" style="display:flex;gap:8px;align-items:center">
                                <input type="checkbox" name="restore_db_configs" value="1" <?php echo $hasSnapshots ? '' : 'disabled'; ?> />
                                Restore DB config files
                            </label>
                        <?php endif; ?>
                        <button class="btn" type="submit" name="action" value="restore_preview" <?php echo $hasSnapshots ? '' : 'disabled'; ?>>Preview</button>
                        <label class="muted" style="display:flex;gap:8px;align-items:center">
                            <input type="checkbox" name="confirm_restore" value="1" <?php echo $hasSnapshots ? '' : 'disabled'; ?> />
                            Confirm
                        </label>
                        <button class="btn btn-danger" type="submit" name="action" value="restore_apply" <?php echo $hasSnapshots ? '' : 'disabled'; ?>>Apply Restore</button>
                    </form>
                    <?php if (is_array($previewLines) && !empty($previewLines)): ?>
                        <pre class="mono"><?php echo h(implode("\n", array_slice($previewLines, 0, 250))); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
    (function () {
        const rows = Array.from(document.querySelectorAll('[data-archive-row="1"]'));
        if (!rows.length) return;

        const watchPrefix = 'mhb-watch:';
        const firedPrefix = 'mhb-fired:';
        const pollIntervalMs = 5000;

        function makeKey(setId, snapshotId) {
            return setId + ':' + snapshotId;
        }

        function watchKey(setId, snapshotId) {
            return watchPrefix + makeKey(setId, snapshotId);
        }

        function firedKey(setId, snapshotId) {
            return firedPrefix + makeKey(setId, snapshotId);
        }

        function armWatch(setId, snapshotId) {
            try {
                sessionStorage.setItem(watchKey(setId, snapshotId), '1');
                sessionStorage.removeItem(firedKey(setId, snapshotId));
            } catch (e) {
            }
        }

        function isArmed(setId, snapshotId) {
            try {
                return sessionStorage.getItem(watchKey(setId, snapshotId)) === '1';
            } catch (e) {
                return false;
            }
        }

        function markFired(setId, snapshotId) {
            try {
                sessionStorage.removeItem(watchKey(setId, snapshotId));
                sessionStorage.setItem(firedKey(setId, snapshotId), '1');
            } catch (e) {
            }
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] || char;
            });
        }

        function buildDetail(payload, armed) {
            const parts = [];
            if (payload && payload.message) parts.push(String(payload.message));
            if (payload && payload.state === 'missing') {
                parts.push('Archive has not started yet.');
            }
            if (payload && payload.temp_size_text && (payload.state === 'queued' || payload.state === 'running')) {
                parts.push('Current temp archive: ' + String(payload.temp_size_text) + '.');
            }
            if (payload && payload.archive_size_text && payload.state === 'ready') {
                parts.push('Archive size: ' + String(payload.archive_size_text) + '.');
            }
            if (payload && payload.backup_volume && payload.backup_volume.free_text) {
                const pct = payload.backup_volume.use_percent != null ? ' (' + String(payload.backup_volume.use_percent) + '% used)' : '';
                parts.push('Backup volume free: ' + String(payload.backup_volume.free_text) + pct + '.');
            }
            if (armed && payload && (payload.state === 'queued' || payload.state === 'running')) {
                parts.push('Live monitor armed. Download starts automatically when ready.');
            }
            return parts.join(' ');
        }

        const entries = rows.map(function (row) {
            const setId = String(row.getAttribute('data-set') || '');
            const snapshotId = String(row.getAttribute('data-snapshot') || '');
            const link = row.querySelector('[data-download-link="1"]');
            const monitor = row.querySelector('[data-archive-monitor="1"]');
            const detail = row.querySelector('[data-archive-detail="1"]');
            return {
                row: row,
                setId: setId,
                snapshotId: snapshotId,
                link: link,
                monitor: monitor,
                detail: detail
            };
        }).filter(function (entry) {
            return entry.setId !== '' && entry.snapshotId !== '';
        });

        document.querySelectorAll('[data-download-link="1"]').forEach(function (link) {
            link.addEventListener('click', function () {
                const setId = String(link.getAttribute('data-set') || '');
                const snapshotId = String(link.getAttribute('data-snapshot') || '');
                if (!setId || !snapshotId) return;
                armWatch(setId, snapshotId);
            });
        });

        document.querySelectorAll('form[data-archive-generate="1"]').forEach(function (form) {
            form.addEventListener('submit', function () {
                const setId = String(form.getAttribute('data-set') || '');
                const snapshotId = String(form.getAttribute('data-snapshot') || '');
                if (!setId || !snapshotId) return;
                armWatch(setId, snapshotId);
            });
        });

        function applyPayload(entry, payload) {
            const armed = isArmed(entry.setId, entry.snapshotId);
            const state = payload && payload.state ? String(payload.state) : 'missing';
            entry.row.setAttribute('data-state', state);
            if (entry.monitor) {
                entry.monitor.textContent = 'Archive: ' + state;
            }
            if (entry.link && payload && payload.action_label) {
                entry.link.textContent = String(payload.action_label);
            }
            if (entry.detail) {
                const detailText = buildDetail(payload, armed);
                entry.detail.textContent = detailText;
                entry.detail.style.display = detailText ? '' : 'none';
                entry.detail.className = (state === 'failed' || (payload && payload.backup_volume && payload.backup_volume.is_full)) ? 'muted danger-note' : 'muted';
            }
            if (armed && state === 'ready' && entry.link) {
                if (entry.detail) {
                    entry.detail.textContent = 'Archive ready. Starting download...';
                    entry.detail.style.display = '';
                    entry.detail.className = 'muted';
                }
                markFired(entry.setId, entry.snapshotId);
                window.location.href = entry.link.href;
                return true;
            }
            return false;
        }

        async function pollEntry(entry) {
            const params = new URLSearchParams({
                archive_status: '1',
                set: entry.setId,
                snap: entry.snapshotId
            });
            const response = await fetch('?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            const payload = await response.json();
            if (!payload || payload.success !== true) {
                throw new Error(payload && payload.message ? payload.message : 'Status check failed');
            }
            return payload;
        }

        let pollTimer = null;
        let isPolling = false;

        async function pollAll() {
            if (isPolling) return;
            isPolling = true;
            try {
                for (const entry of entries) {
                    const currentState = String(entry.row.getAttribute('data-state') || '');
                    if (!isArmed(entry.setId, entry.snapshotId) && currentState !== 'queued' && currentState !== 'running') {
                        continue;
                    }
                    try {
                        const payload = await pollEntry(entry);
                        if (applyPayload(entry, payload)) {
                            return;
                        }
                    } catch (e) {
                        if (entry.detail) {
                            entry.detail.textContent = 'Monitor error: ' + escapeHtml(e && e.message ? e.message : 'Unable to refresh archive status.');
                            entry.detail.style.display = '';
                            entry.detail.className = 'muted danger-note';
                        }
                    }
                }
            } finally {
                isPolling = false;
            }
        }

        pollAll();
        pollTimer = window.setInterval(pollAll, pollIntervalMs);
    })();
</script>
</main>
<?php
    $bodyEnd = dirname(__DIR__, 3) . '/templates/global-ui/includes/complete-body-end.php';
    if (is_file($bodyEnd)) {
        include_once $bodyEnd;
    } elseif (function_exists('renderGlobalFooter')) {
        renderGlobalFooter();
    }
?>
</body>
</html>
