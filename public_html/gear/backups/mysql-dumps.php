<?php
if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_CLI_MODE')) define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
$cueAutoload = function_exists('cue_autoload') ? 'cue_autoload' : null;
if (is_string($cueAutoload)) {
    call_user_func($cueAutoload, 'database');
    call_user_func($cueAutoload, 'security');
}

function mh_mysql_dumps_policy_path(): string {
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/config/mysql-backups.json';
}

function mh_mysql_dumps_state_path(): string {
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/logs/mysql-backups-state.json';
}

function mh_mysql_dumps_backup_root(): string {
    $root = is_dir('/backup') ? '/backup/backups' : '/backups';
    return rtrim($root, '/') . '/mysql-dumps';
}

function mh_mysql_dumps_load_json(string $path, array $default): array {
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : $default;
}

function mh_mysql_dumps_save_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
}

function mh_mysql_dumps_interval_seconds(string $frequency): int {
    if ($frequency === 'hourly') return 3600;
    if ($frequency === 'daily') return 86400;
    if ($frequency === 'weekly') return 7 * 86400;
    if ($frequency === 'monthly') return 31 * 86400;
    return 86400;
}

function mh_mysql_dumps_is_due(int $lastRunEpoch, string $frequency, int $now): bool {
    $interval = mh_mysql_dumps_interval_seconds($frequency);
    if ($lastRunEpoch <= 0) return true;
    return ($now - $lastRunEpoch) >= $interval;
}

function mh_mysql_dumps_safe_name(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    if ($s === '') $s = 'db';
    if (strlen($s) > 80) $s = substr($s, 0, 80);
    return $s;
}

function mh_mysql_dumps_write_tmp_defaults(array $cfg): string {
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (string)($cfg['port'] ?? '3307');
    $user = (string)($cfg['username'] ?? '');
    $pass = (string)($cfg['password'] ?? '');
    $tmp = '/tmp/mh_mysql_' . bin2hex(random_bytes(8)) . '.cnf';
    $body = "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n";
    @file_put_contents($tmp, $body, LOCK_EX);
    @chmod($tmp, 0600);
    return $tmp;
}

function mh_mysql_dumps_run_mysqldump(string $defaultsFile, string $database, string $outGzPath): array {
    $cmd = [
        'mysqldump',
        '--defaults-extra-file=' . $defaultsFile,
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
        return ['ok' => false, 'error' => 'proc_open_failed'];
    }
    @fclose($pipes[0]);
    $gz = @gzopen($outGzPath, 'wb9');
    if (!$gz) {
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);
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
    $code = is_int($code) ? $code : 2;
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'mysqldump_exit_' . $code, 'stderr' => is_string($stderr) ? trim($stderr) : ''];
    }
    return ['ok' => true, 'bytes' => $bytes];
}

function mh_mysql_dumps_retention_cleanup(string $dir, int $keep): array {
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

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$now = time();
$policy = mh_mysql_dumps_load_json(mh_mysql_dumps_policy_path(), ['version' => 2, 'connection_config_id' => '', 'databases' => []]);
$state = mh_mysql_dumps_load_json(mh_mysql_dumps_state_path(), ['version' => 1, 'last_run' => []]);
$lastRun = isset($state['last_run']) && is_array($state['last_run']) ? $state['last_run'] : [];

$results = [];
$version = isset($policy['version']) ? (int)$policy['version'] : 1;
$getCfgFn = function_exists('database_getConfiguration') ? 'database_getConfiguration' : null;
$decCfgFn = function_exists('database_decryptConfiguration') ? 'database_decryptConfiguration' : null;
if (!is_string($getCfgFn) || !is_string($decCfgFn)) {
    $results[] = ['ok' => false, 'error' => 'database_module_unavailable'];
    $version = 0;
}
if ($version >= 2 && isset($policy['connection_config_id']) && isset($policy['databases']) && is_array($policy['databases'])) {
    $configId = trim((string)$policy['connection_config_id']);
    $dbMap = $policy['databases'];
    if ($configId === '') {
        $results[] = ['ok' => false, 'error' => 'connection_config_id_missing'];
    } else {
        $cfg = call_user_func($getCfgFn, (string)$configId);
        if (!is_array($cfg)) {
            $results[] = ['config_id' => $configId, 'ok' => false, 'error' => 'config_missing'];
        } else {
            $cfg = call_user_func($decCfgFn, $cfg);
            foreach ($dbMap as $dbName => $row) {
                $dbName = is_string($dbName) ? trim($dbName) : '';
                if ($dbName === '') continue;
                if (in_array($dbName, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) continue;
                $row = is_array($row) ? $row : [];
                $enabled = !empty($row['enabled']);
                if (!$enabled) continue;
                $freq = isset($row['frequency']) && is_string($row['frequency']) ? trim((string)$row['frequency']) : 'daily';
                if (!in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) $freq = 'daily';
                $ret = isset($row['retention']) ? (int)$row['retention'] : 14;
                $ret = max(1, min(2000, $ret));
                $runKey = $configId . '|' . $dbName . '|' . $freq;
                $prev = isset($lastRun[$runKey]) ? (int)$lastRun[$runKey] : 0;
                if (!mh_mysql_dumps_is_due($prev, $freq, $now)) {
                    $results[] = ['config_id' => $configId, 'database' => $dbName, 'ok' => true, 'skipped' => true];
                    continue;
                }
                try {
                    $root = mh_mysql_dumps_backup_root();
                    if (!is_dir($root)) @mkdir($root, 0770, true);
                    $dbDir = rtrim($root, '/') . '/' . mh_mysql_dumps_safe_name($dbName) . '/' . $freq;
                    if (!is_dir($dbDir)) @mkdir($dbDir, 0770, true);
                    $stamp = gmdate('Y-m-d_His');
                    $outFile = $dbDir . '/' . $stamp . '.sql.gz';

                    $defaultsFile = mh_mysql_dumps_write_tmp_defaults($cfg);
                    $run = mh_mysql_dumps_run_mysqldump($defaultsFile, $dbName, $outFile);
                    @unlink($defaultsFile);
                    if (empty($run['ok'])) {
                        @unlink($outFile);
                        $results[] = ['config_id' => $configId, 'database' => $dbName, 'ok' => false, 'error' => $run['error'] ?? 'dump_failed', 'stderr' => $run['stderr'] ?? null];
                        continue;
                    }
                    $cleanup = mh_mysql_dumps_retention_cleanup($dbDir, $ret);
                    $lastRun[$runKey] = $now;
                    $results[] = ['config_id' => $configId, 'database' => $dbName, 'ok' => true, 'file' => $outFile, 'cleanup' => $cleanup];
                } catch (Throwable $e) {
                    $results[] = ['config_id' => $configId, 'database' => $dbName, 'ok' => false, 'error' => $e->getMessage()];
                }
            }
        }
    }
} else {
    $items = isset($policy['items']) && is_array($policy['items']) ? $policy['items'] : [];
    foreach ($items as $configId => $row) {
        if (!is_array($row)) continue;
        $enabled = !empty($row['enabled']);
        if (!$enabled) continue;
        $freq = isset($row['frequency']) && is_string($row['frequency']) ? trim((string)$row['frequency']) : 'daily';
        if (!in_array($freq, ['hourly', 'daily', 'weekly', 'monthly'], true)) $freq = 'daily';
        $ret = isset($row['retention']) ? (int)$row['retention'] : 14;
        $ret = max(1, min(2000, $ret));
        $prev = isset($lastRun[$configId]) ? (int)$lastRun[$configId] : 0;
        if (!mh_mysql_dumps_is_due($prev, $freq, $now)) {
            $results[] = ['config_id' => $configId, 'ok' => true, 'skipped' => true];
            continue;
        }
        try {
            $cfg = call_user_func($getCfgFn, (string)$configId);
            if (!is_array($cfg)) {
                $results[] = ['config_id' => $configId, 'ok' => false, 'error' => 'config_missing'];
                continue;
            }
            $cfg = call_user_func($decCfgFn, $cfg);
            $dbName = isset($cfg['database']) ? trim((string)$cfg['database']) : '';
            if ($dbName === '') {
                $results[] = ['config_id' => $configId, 'ok' => false, 'error' => 'db_name_missing'];
                continue;
            }

            $root = mh_mysql_dumps_backup_root();
            if (!is_dir($root)) @mkdir($root, 0770, true);
            $dbDir = rtrim($root, '/') . '/' . mh_mysql_dumps_safe_name($dbName) . '/' . $freq;
            if (!is_dir($dbDir)) @mkdir($dbDir, 0770, true);
            $stamp = gmdate('Y-m-d_His');
            $outFile = $dbDir . '/' . $stamp . '.sql.gz';

            $defaultsFile = mh_mysql_dumps_write_tmp_defaults($cfg);
            $run = mh_mysql_dumps_run_mysqldump($defaultsFile, $dbName, $outFile);
            @unlink($defaultsFile);
            if (empty($run['ok'])) {
                @unlink($outFile);
                $results[] = ['config_id' => $configId, 'ok' => false, 'error' => $run['error'] ?? 'dump_failed', 'stderr' => $run['stderr'] ?? null];
                continue;
            }
            $cleanup = mh_mysql_dumps_retention_cleanup($dbDir, $ret);
            $lastRun[$configId] = $now;
            $results[] = ['config_id' => $configId, 'ok' => true, 'database' => $dbName, 'file' => $outFile, 'cleanup' => $cleanup];
        } catch (Throwable $e) {
            $results[] = ['config_id' => $configId, 'ok' => false, 'error' => $e->getMessage()];
        }
    }
}

$state = [
    'version' => 1,
    'updated_at' => date('c', $now),
    'last_run' => $lastRun,
];
mh_mysql_dumps_save_json(mh_mysql_dumps_state_path(), $state);

$ok = true;
foreach ($results as $r) {
    if (is_array($r) && (($r['ok'] ?? null) !== true) && empty($r['skipped'])) {
        $ok = false;
        break;
    }
}

echo json_encode(['ok' => $ok, 'ts' => $now, 'results' => $results], JSON_UNESCAPED_SLASHES) . "\n";
exit($ok ? 0 : 2);
