<?php
declare(strict_types=1);

$isAjax = true;
if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);
if (!defined('CUE_DISABLE_PERFORMANCE_LOG')) define('CUE_DISABLE_PERFORMANCE_LOG', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('dbmanager_monitor', true);
cue_autoload('database');

function dbmmon_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function dbmmon_csrf(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $k = 'mh_dbmmon_csrf';
    $t = isset($_SESSION[$k]) ? (string)$_SESSION[$k] : '';
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        $_SESSION[$k] = $t;
    }
    return $t;
}

function dbmmon_require_csrf(): void {
    $t = dbmmon_csrf();
    $p = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($p === '' || !hash_equals($t, $p)) {
        dbmmon_json(['success' => false, 'error' => 'invalid_csrf'], 400);
    }
}

function dbmmon_tail(string $path, int $maxLines, int $maxBytes = 1048576): array {
    $maxLines = max(1, $maxLines);
    $maxBytes = max(8192, $maxBytes);
    if (!is_file($path) || !is_readable($path)) return [];
    $fh = @fopen($path, 'rb');
    if (!is_resource($fh)) return [];
    $size = @filesize($path);
    $size = is_int($size) ? $size : 0;
    $pos = $size;
    $buf = '';
    while ($pos > 0 && strlen($buf) < $maxBytes) {
        $read = min(8192, $pos);
        $pos -= $read;
        if (@fseek($fh, $pos) !== 0) break;
        $data = @fread($fh, $read);
        if (!is_string($data) || $data === '') break;
        $buf = $data . $buf;
        if (substr_count($buf, "\n") > ($maxLines + 5)) break;
    }
    @fclose($fh);
    $arr = preg_split("/\\r\\n|\\n|\\r/", $buf);
    if (!is_array($arr)) return [];
    $arr = array_values(array_filter(array_map('trim', $arr), fn($v) => $v !== ''));
    if (count($arr) > $maxLines) {
        $arr = array_slice($arr, -$maxLines);
    }
    return $arr;
}

function dbmmon_audit_summary(string $path): array {
    $lines = dbmmon_tail($path, 250);
    $events = [];
    $errors = 0;
    foreach ($lines as $l) {
        $j = json_decode($l, true);
        if (!is_array($j)) continue;
        $events[] = $j;
        $code = isset($j['status']) ? (int)$j['status'] : 0;
        if ($code >= 400 || isset($j['fatal'])) $errors++;
    }
    return [
        'path' => $path,
        'count' => count($events),
        'errors' => $errors,
        'recent' => array_slice($events, -15),
    ];
}

function dbmmon_status(): array {
    $paths = cue_autoload('paths');
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';

    $dbConfigsPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : ($base . '/config/db_configs.json');
    $tenantContextsPath = $paths ? $paths->getSecureFilePath('config/tenant-contexts.json', true) : ($base . '/config/tenant-contexts.json');
    $ctxPrimary = $base . '/config/database-context.json';
    $ctxFallback = $base . '/config/database-contexts.json';
    $ctxPath = file_exists($ctxPrimary) ? $ctxPrimary : $ctxFallback;

    $dbConfigs = null;
    $dbConfigsErr = null;
    if (is_string($dbConfigsPath) && $dbConfigsPath !== '' && file_exists($dbConfigsPath)) {
        $tmp = json_decode((string)file_get_contents($dbConfigsPath), true);
        if (is_array($tmp)) {
            $dbConfigs = $tmp;
        } else {
            $dbConfigsErr = 'invalid_db_configs_json';
        }
    } else {
        $dbConfigsErr = 'missing_db_configs_json';
    }
    $active = 0;
    if (is_array($dbConfigs)) {
        foreach ($dbConfigs as $cfg) {
            if (is_array($cfg) && (($cfg['is_active'] ?? null) === true)) $active++;
        }
    }

    $tenantContexts = null;
    $tenantErr = null;
    if (is_string($tenantContextsPath) && $tenantContextsPath !== '' && file_exists($tenantContextsPath)) {
        $tmp = json_decode((string)file_get_contents($tenantContextsPath), true);
        if (is_array($tmp)) {
            $tenantContexts = $tmp;
        } else {
            $tenantErr = 'invalid_tenant_contexts_json';
        }
    } else {
        $tenantErr = 'missing_tenant_contexts_json';
    }
    $tenantCount = is_array($tenantContexts) ? count($tenantContexts) : 0;
    $tenantWithDb = 0;
    if (is_array($tenantContexts)) {
        foreach ($tenantContexts as $t => $v) {
            if (is_array($v) && isset($v['db_config_id']) && is_string($v['db_config_id']) && trim($v['db_config_id']) !== '') {
                $tenantWithDb++;
            }
        }
    }

    $contextsOk = null;
    $contextsError = null;
    if (is_string($ctxPath) && $ctxPath !== '' && file_exists($ctxPath)) {
        try {
            $data = json_decode((string)file_get_contents($ctxPath), true);
            if (!is_array($data)) {
                $contextsOk = false;
                $contextsError = 'invalid_database_context_json';
            } else {
                $activeIds = array_keys(database_loadConfigurations());
                $ok = database_validateDatabaseContexts($data, $activeIds, $ctxPath);
                $contextsOk = (bool)$ok;
            }
        } catch (Throwable $e) {
            $contextsOk = false;
            $contextsError = $e->getMessage();
        }
    } else {
        $contextsOk = false;
        $contextsError = 'missing_database_context_file';
    }

    $errorLog = $base . '/logs/error.log';
    $errorLines = dbmmon_tail($errorLog, 120);
    $errorFiltered = [];
    foreach ($errorLines as $l) {
        if (stripos($l, 'dbmanager') !== false || stripos($l, 'db_configs') !== false || stripos($l, 'database-context') !== false) {
            $errorFiltered[] = $l;
        }
    }

    $auditPath = $base . '/logs/dbmanager_audit.jsonl';
    $audit = dbmmon_audit_summary($auditPath);

    $readJson = function (string $path): array {
        if (!is_file($path)) return ['ok' => false, 'path' => $path, 'error' => 'missing'];
        $raw = @file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) return ['ok' => false, 'path' => $path, 'error' => 'invalid_json'];
        return ['ok' => true, 'path' => $path, 'data' => $decoded];
    };

    $mon = $readJson($base . '/logs/monitoring_status.json');
    $bkp = $readJson($base . '/logs/backup_status.json');
    $now = time();
    $monTs = (isset($mon['data']) && is_array($mon['data']) && isset($mon['data']['ts'])) ? (int)$mon['data']['ts'] : 0;
    $bkpTs = (isset($bkp['data']) && is_array($bkp['data']) && isset($bkp['data']['ts'])) ? (int)$bkp['data']['ts'] : 0;

    return [
        'now' => time(),
        'csrf' => dbmmon_csrf(),
        'db_configs' => [
            'path' => $dbConfigsPath,
            'ok' => is_array($dbConfigs),
            'error' => $dbConfigsErr,
            'active' => $active,
        ],
        'tenant_contexts' => [
            'path' => $tenantContextsPath,
            'ok' => is_array($tenantContexts),
            'error' => $tenantErr,
            'count' => $tenantCount,
            'with_db_mapping' => $tenantWithDb,
        ],
        'db_contexts' => [
            'path' => $ctxPath,
            'ok' => $contextsOk,
            'error' => $contextsError,
        ],
        'logs' => [
            'error_log' => [
                'path' => $errorLog,
                'lines' => array_slice($errorFiltered, -80),
            ],
            'audit' => $audit,
        ],
        'ops' => [
            'monitoring' => [
                'path' => (string)($mon['path'] ?? ''),
                'present' => (bool)($mon['ok'] ?? false),
                'ts' => $monTs > 0 ? $monTs : null,
                'age_s' => $monTs > 0 ? max(0, $now - $monTs) : null,
                'ok' => (isset($mon['data']) && is_array($mon['data'])) ? (bool)($mon['data']['ok'] ?? false) : null,
                'stale' => $monTs > 0 ? (($now - $monTs) > 15 * 60) : null,
            ],
            'backup' => [
                'path' => (string)($bkp['path'] ?? ''),
                'present' => (bool)($bkp['ok'] ?? false),
                'ts' => $bkpTs > 0 ? $bkpTs : null,
                'age_s' => $bkpTs > 0 ? max(0, $now - $bkpTs) : null,
                'ok' => (isset($bkp['data']) && is_array($bkp['data'])) ? (bool)($bkp['data']['ok'] ?? false) : null,
                'stale' => $bkpTs > 0 ? (($now - $bkpTs) > 15 * 60) : null,
            ],
        ],
    ];
}

function dbmmon_find_new_pdo(string $rootDir): array {
    $allowed = [
        realpath($rootDir . '/.cue/database.php') ?: '',
        realpath($rootDir . '/pdf/editor/migrate_wopi_sqlite.php') ?: '',
    ];
    $allowed = array_filter($allowed);
    $violations = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (!is_string($path) || $path === '') continue;
        $p = str_replace('\\', '/', $path);
        if (strpos($p, '/.git/') !== false) continue;
        if (strpos($p, '/.data/') !== false) continue;
        if (strpos($p, '/node_modules/') !== false) continue;
        if (strpos($p, '/vendor/') !== false) continue;
        if (substr($p, -4) !== '.php') continue;
        $real = realpath($path) ?: $path;
        if (in_array($real, $allowed, true)) continue;
        $code = @file_get_contents($real);
        if (!is_string($code) || $code === '') continue;
        $tokens = token_get_all($code);
        $hits = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_NEW) continue;
            $line = (int)($t[2] ?? 0);
            $j = $i + 1;
            while ($j < $n) {
                $tt = $tokens[$j];
                if (is_array($tt) && ($tt[0] === T_WHITESPACE || $tt[0] === T_COMMENT || $tt[0] === T_DOC_COMMENT)) {
                    $j++;
                    continue;
                }
                break;
            }
            if ($j >= $n) continue;
            $name = '';
            $k = $j;
            while ($k < $n) {
                $tt = $tokens[$k];
                if (is_array($tt) && ($tt[0] === T_STRING || $tt[0] === T_NS_SEPARATOR || (defined('T_NAME_QUALIFIED') && $tt[0] === T_NAME_QUALIFIED))) {
                    $name .= $tt[1];
                    $k++;
                    continue;
                }
                break;
            }
            $name = ltrim($name, '\\');
            if ($name !== 'PDO') continue;
            while ($k < $n) {
                $tt = $tokens[$k];
                if (is_array($tt) && ($tt[0] === T_WHITESPACE || $tt[0] === T_COMMENT || $tt[0] === T_DOC_COMMENT)) {
                    $k++;
                    continue;
                }
                break;
            }
            if ($k < $n && $tokens[$k] === '(') {
                $hits[] = ['line' => $line];
            }
        }
        if ($hits !== []) $violations[] = ['file' => $real, 'hits' => $hits];
    }
    return $violations;
}

function dbmmon_find_pool_usage(string $rootDir): array {
    $allowed = [];
    $allowed = array_filter($allowed);
    $violations = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if (!is_string($path) || $path === '') continue;
        $p = str_replace('\\', '/', $path);
        if (strpos($p, '/.git/') !== false) continue;
        if (strpos($p, '/.data/') !== false) continue;
        if (strpos($p, '/node_modules/') !== false) continue;
        if (strpos($p, '/vendor/') !== false) continue;
        if (strpos($p, '/studio/api/lib/') !== false) continue;
        if (substr($p, -4) !== '.php') continue;
        $real = realpath($path) ?: $path;
        if (in_array($real, $allowed, true)) continue;
        $code = @file_get_contents($real);
        if (!is_string($code) || $code === '') continue;
        $tokens = token_get_all($code);
        $hits = [];
        $targetClass = 'Database' . 'ConnectionPool';
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== $targetClass) continue;
            $hits[] = ['line' => (int)($t[2] ?? 0)];
        }
        if ($hits !== []) $violations[] = ['file' => $real, 'hits' => $hits];
    }
    return $violations;
}

$action = isset($_POST['action']) && is_string($_POST['action']) ? trim((string)$_POST['action']) : 'status';
if ($action === 'guard_scan') {
    dbmmon_require_csrf();
    $root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    $pdo = dbmmon_find_new_pdo($root);
    $pool = dbmmon_find_pool_usage($root);
    dbmmon_json(['success' => true, 'action' => 'guard_scan', 'pdo' => $pdo, 'pool' => $pool]);
}

dbmmon_json(['success' => true, 'action' => 'status', 'status' => dbmmon_status()]);
