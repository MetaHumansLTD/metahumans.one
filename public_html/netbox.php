<?php
require_once __DIR__ . '/.cue/cue.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/netbox/';
$target = '/gear/settings/infra.php';
if ($requestUri !== '' && $requestUri !== '/netbox' && $requestUri !== '/netbox/') {
    $target .= '?legacy=netbox&from=' . rawurlencode($requestUri);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target, true, 302);
exit;
