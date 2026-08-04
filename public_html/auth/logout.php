<?php
require_once __DIR__ . '/../.cue/cue.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION = [];
$params = ['domain' => '', 'path' => '/'];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool)($params['secure'] ?? true),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}
setcookie('__Host-device', '', [
    'expires' => time() - 42000,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
setcookie('__Secure-mh_remember', '', [
    'expires' => time() - 42000,
    'path' => '/',
    'domain' => '.metahumans.one',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
setcookie('mh_sso_logged_out', '1', [
    'expires' => time() + 300,
    'path' => '/',
    'domain' => '.metahumans.one',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_destroy();
header('Location: /auth/login.php?logged_out=1');
exit;
