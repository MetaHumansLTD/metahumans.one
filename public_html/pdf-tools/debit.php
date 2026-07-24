<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);
require_once dirname(__DIR__) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/auth/auth_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$isAllowedOrigin = true;

if ($origin !== '') {
    $isAllowedOrigin = str_contains($origin, '://' . $host);
}
if ($isAllowedOrigin && $referer !== '') {
    $isAllowedOrigin = str_contains($referer, '://' . $host . '/pdf-tools');
}
if (!$isAllowedOrigin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_json']);
    exit;
}

$tool = '';
if (isset($input['service']) && is_string($input['service'])) {
    $tool = trim($input['service']);
} elseif (isset($input['tool']) && is_string($input['tool'])) {
    $tool = trim($input['tool']);
}

$docCount = isset($input['documents']) ? (int)$input['documents'] : 1;

if ($tool === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_tool']);
    exit;
}

if ($docCount < 1) {
    $docCount = 1;
}
if ($docCount > 25) {
    $docCount = 25;
}

$username = (string)$_SESSION['mh_auth_user'];
mh_auth_load_user_context($username);
$pricing = mh_charge_service_tokens($username, 'pdf_tools:' . $tool, max(1, $docCount), ['documents' => $docCount], 1);
if (!$pricing['success']) {
    http_response_code(402);
    echo json_encode(['success' => false, 'error' => 'insufficient_tokens', 'tokens' => (int)($pricing['tokens'] ?? 0)]);
    exit;
}
header('X-MH-Tokens-Remaining: ' . (int)($pricing['tokens'] ?? 0));
echo json_encode([
    'success' => true,
    'debited' => (int)($pricing['debited'] ?? 0),
    'tokens' => (int)($pricing['tokens'] ?? 0),
    'tokens_per_unit' => (int)($pricing['tokens_per_unit'] ?? 1),
    'units' => (int)($pricing['units'] ?? 1),
]);
