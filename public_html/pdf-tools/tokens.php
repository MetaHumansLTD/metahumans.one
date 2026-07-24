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

$username = (string)$_SESSION['mh_auth_user'];
mh_auth_load_user_context($username);
$tokens = (int)($_SESSION['tokens'] ?? 0);

echo json_encode([
    'success' => true,
    'tokens' => $tokens,
]);
