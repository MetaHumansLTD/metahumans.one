<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
cue_autoload('security');
cue_autoload('database');

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
$isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
if (!$isKripz) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
}

function whmmp_load_all_configs(): array {
    $paths = cue_autoload('paths');
    $cfgRoot = $paths ? (string)$paths->getConfigPath() : '/data/config';
    $file = rtrim($cfgRoot, '/') . '/db_configs.json';
    if (!is_file($file)) return [];
    $decoded = json_decode((string)file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

function whmmp_try_decrypt(array $cfg): ?array {
    try {
        return database_decryptConfiguration($cfg);
    } catch (Throwable $e) {
        return null;
    }
}

function whmmp_list_databases(array $cfg): array {
    $driver = strtolower((string)($cfg['type'] ?? 'mysql'));
    if ($driver === 'mariadb') $driver = 'mysql';
    $connCfg = [
        'driver' => $driver,
        'type' => $driver,
        'host' => (string)($cfg['host'] ?? ''),
        'port' => (string)($cfg['port'] ?? ''),
        'database' => (string)($cfg['database'] ?? ''),
        'username' => (string)($cfg['username'] ?? ''),
        'password' => (string)($cfg['password'] ?? ''),
        'charset' => (string)($cfg['charset'] ?? 'utf8mb4'),
        'storage_profile' => (string)($cfg['storage_profile'] ?? ''),
        'name' => (string)($cfg['name'] ?? ''),
        'id' => (string)($cfg['id'] ?? ''),
        'context' => (string)($cfg['context'] ?? ''),
    ];
    $pdo = database_getConnectionFromConfig($connCfg);
    $rows = database_query('SHOW DATABASES', [], $pdo);
    $names = [];
    foreach ($rows as $r) {
        $v = is_array($r) ? array_values($r)[0] ?? null : null;
        if (is_string($v) && $v !== '') $names[] = $v;
    }
    sort($names);
    return $names;
}

$all = whmmp_load_all_configs();
$whm = [];
$block = [];

foreach ($all as $id => $cfg) {
    if (!is_array($cfg)) continue;
    $cfg['id'] = is_string($cfg['id'] ?? null) ? (string)$cfg['id'] : (string)$id;
    $plain = whmmp_try_decrypt($cfg);
    if (!is_array($plain)) continue;
    $profile = database_inferStorageProfile($plain);
    if ($profile === 'whm_mysql') {
        $whm[] = $plain;
    }
    if ($profile === 'block_mysql') {
        $block[] = $plain;
    }
}

$result = [
    'ok' => true,
    'whm' => [
        'configs' => [],
    ],
    'block' => [
        'configs' => [],
    ],
];

foreach ($whm as $cfg) {
    $entry = [
        'id' => (string)($cfg['id'] ?? ''),
        'name' => (string)($cfg['name'] ?? ''),
        'port' => (string)($cfg['port'] ?? ''),
        'storage_profile' => (string)($cfg['storage_profile'] ?? ''),
        'is_active' => ($cfg['is_active'] ?? false) === true,
    ];
    try {
        $entry['databases'] = array_slice(whmmp_list_databases($cfg), 0, 200);
    } catch (Throwable $e) {
        $entry['error'] = $e->getMessage();
    }
    $result['whm']['configs'][] = $entry;
}

foreach ($block as $cfg) {
    $entry = [
        'id' => (string)($cfg['id'] ?? ''),
        'name' => (string)($cfg['name'] ?? ''),
        'port' => (string)($cfg['port'] ?? ''),
        'storage_profile' => (string)($cfg['storage_profile'] ?? ''),
        'is_active' => ($cfg['is_active'] ?? false) === true,
    ];
    try {
        $entry['databases'] = array_slice(whmmp_list_databases($cfg), 0, 200);
    } catch (Throwable $e) {
        $entry['error'] = $e->getMessage();
    }
    $result['block']['configs'][] = $entry;
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
