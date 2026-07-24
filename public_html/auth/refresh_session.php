<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/.cue/cue.php';
require_once __DIR__ . '/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($user !== '' && function_exists('mh_auth_load_user_context')) {
    mh_auth_load_user_context($user, $_SESSION['mh_auth_groups'] ?? null, null);
}

$redirect = isset($_GET['redirect']) ? (string)$_GET['redirect'] : '/hub/';
if ($redirect === '' || $redirect[0] !== '/') {
    $redirect = '/hub/';
}
header('Location: ' . $redirect, true, 302);
exit;
