<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
require_once __DIR__ . '/../../lib/superhumans_connector.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['mh_auth_user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$role = strtolower(trim((string)($_SESSION['mh_auth_role'] ?? '')));
if ($role !== 'kripzmasters' && $role !== 'kripzmaster') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$method = isset($input['method']) ? strtoupper(trim((string)$input['method'])) : 'GET';
$path = isset($input['path']) ? trim((string)$input['path']) : '';
$body = isset($input['body']) && is_array($input['body']) ? $input['body'] : null;

if ($path === '' || strpos($path, '..') !== false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid path']);
    exit;
}

$allowedPrefixes = ['/hub/', '/v1/', '/api/'];
$okPrefix = false;
foreach ($allowedPrefixes as $p) {
    if (strpos($path, $p) === 0) {
        $okPrefix = true;
        break;
    }
}
if (!$okPrefix) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Path not allowed']);
    exit;
}

$res = mh_superhumans_request($method, $path, $body, [
    'X-MH-Proxy-User' => (string)$_SESSION['mh_auth_user'],
    'X-MH-Proxy-Source' => 'metahumans.one',
]);

http_response_code($res['ok'] ? 200 : ($res['status'] > 0 ? $res['status'] : 502));
echo json_encode([
    'success' => $res['ok'],
    'status' => $res['status'],
    'url' => $res['url'] ?? null,
    'body' => $res['body'],
    'body_raw' => $res['body'] === null ? ($res['body_raw'] ?? null) : null,
    'error' => $res['error'],
]);
