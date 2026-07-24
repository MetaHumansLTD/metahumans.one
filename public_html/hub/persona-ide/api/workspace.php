<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../../.cue/cue.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$action = (string)($input['action'] ?? 'get');

$tenantId = (string)($_SESSION['mh_tenant_id'] ?? '');
$personaTenantId = (string)($_SESSION['mh_persona_tenant_id'] ?? '');

if ($tenantId === '') {
    $tenantId = 'user:' . (string)$_SESSION['mh_auth_user'];
}
if ($personaTenantId === '') {
    $personaTenantId = 'persona:' . ((string)($_SESSION['mh_auth_persona'] ?? ('MH-' . (string)$_SESSION['mh_auth_user'])));
}

$sanitize = function(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    return $s ?: 'unknown';
};

$tenantSafe = $sanitize($tenantId);
$personaSafe = $sanitize($personaTenantId);

$paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
$dataPath = is_object($paths) && method_exists($paths, 'getDataPath') ? $paths->getDataPath() : '/data';
$dataPath = rtrim((string)$dataPath, DIRECTORY_SEPARATOR);

$tenantRoot = $dataPath . '/tenants/' . $tenantSafe;
$base = $tenantRoot . '/workspaces';
$workspace = $base . '/' . $personaSafe . '/default';

$ensure = function(string $dir) use ($base): bool {
    $baseReal = realpath($base);
    if (!$baseReal) {
        @mkdir($base, 0700, true);
        $baseReal = realpath($base);
    }
    if (!$baseReal) return false;
    @mkdir($dir, 0700, true);
    $dirReal = realpath($dir);
    if (!$dirReal) return false;
    return strncmp($dirReal, $baseReal, strlen($baseReal)) === 0;
};

if (!$ensure($workspace)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'workspace_create_failed']);
    exit;
}

if ($action === 'get') {
    echo json_encode([
        'success' => true,
        'workspace' => $workspace,
        'tenant_id' => $tenantId,
        'persona_tenant_id' => $personaTenantId
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'invalid_action']);
