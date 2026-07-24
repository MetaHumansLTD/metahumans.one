<?php
require_once __DIR__ . '/../.cue/cue.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pt_json(array $data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo json_encode($data);
}

$user = $_SESSION['mh_auth_user'] ?? '';
$role = $_SESSION['mh_auth_role'] ?? '';
$isKripz = (stripos((string)$role, 'KripzMasters') !== false || stripos((string)$role, 'KripzMaster') !== false);
if ($user === '' || !$isKripz) {
    pt_json(['success' => false, 'error' => 'access_denied'], 403);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$tenantId = trim((string)($input['tenant_id'] ?? ''));
if ($tenantId === '') {
    pt_json(['success' => false, 'error' => 'missing_tenant_id'], 400);
    exit;
}

function pt_mysql_name(string $tenantId): string {
    $s = preg_replace('/[^a-zA-Z0-9_]+/', '_', $tenantId);
    $s = trim($s, '_');
    if ($s === '') $s = substr(hash('sha256', $tenantId), 0, 16);
    $name = 'tenant_' . $s;
    if (strlen($name) > 58) $name = substr($name, 0, 42) . '_' . substr(hash('sha256', $tenantId), 0, 15);
    return $name;
}
function pt_cfg_id(string $tenantId): string {
    return 'tenant_' . substr(hash('sha256', $tenantId), 0, 24);
}
function pt_paths(string $tenantId): array {
    $suffix = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
    if ($suffix === '') $suffix = substr(hash('sha256', $tenantId), 0, 16);
    return [
        'vector' => '/vector/tenant_' . $suffix,
        'graph' => '/graph/tenant_' . $suffix,
    ];
}
function pt_ctx_path(): string {
    $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
    return rtrim($base, '/') . '/config/tenant-contexts.json';
}
function pt_dbconfigs_path(): string {
    $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
    return rtrim($base, '/') . '/config/db_configs.json';
}

try {
    cue_autoload('database');
    cue_autoload('security');
    $bio = function_exists('database_getConfiguration') ? database_getConfiguration('biometrics') : null;
    if (!$bio || !is_array($bio)) {
        pt_json(['success' => false, 'error' => 'biometrics_config_missing'], 500);
        exit;
    }
    $dbName = pt_mysql_name($tenantId);
    $cfgId = pt_cfg_id($tenantId);
    $paths = pt_paths($tenantId);

    $vectorOk = true; $graphOk = true; $vectorErr = null; $graphErr = null;
    foreach (['vector' => $paths['vector'], 'graph' => $paths['graph']] as $k => $p) {
        if (!is_dir($p)) {
            $ok = @mkdir($p, 0775, true);
            if (!$ok && !is_dir($p)) {
                if ($k === 'vector') { $vectorOk = false; $vectorErr = 'mkdir_failed'; }
                else { $graphOk = false; $graphErr = 'mkdir_failed'; }
            }
        }
    }

    $ctxPath = pt_ctx_path();
    if (!is_dir(dirname($ctxPath))) mkdir(dirname($ctxPath), 0755, true);
    $map = [];
    if (file_exists($ctxPath)) {
        $j = json_decode((string)file_get_contents($ctxPath), true);
        if (is_array($j)) $map = $j;
    }
    $map[$tenantId] = [
        'vector_path' => $paths['vector'],
        'graph_path' => $paths['graph'],
        'updated_at' => date('Y-m-d H:i:s'),
    ] + ($map[$tenantId] ?? ['created_at' => date('Y-m-d H:i:s')]);
    file_put_contents($ctxPath, json_encode($map, JSON_PRETTY_PRINT), LOCK_EX);

    pt_json([
        'success' => true,
        'tenant_id' => $tenantId,
        'vector' => ['path' => $paths['vector'], 'ok' => $vectorOk, 'error' => $vectorErr],
        'graph' => ['path' => $paths['graph'], 'ok' => $graphOk, 'error' => $graphErr],
    ]);
    exit;
} catch (Throwable $e) {
    pt_json(['success' => false, 'error' => 'server_error'], 500);
    exit;
}
