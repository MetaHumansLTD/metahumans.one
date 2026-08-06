<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__, 2);
$cueBootstrapPath = $publicRoot . '/.cue/cue.php';

$_ENV['MH_PUBLIC_ROOT'] = $publicRoot;
$_SERVER['MH_PUBLIC_ROOT'] = $publicRoot;
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;

if (! is_file($cueBootstrapPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Registrar workspace is not bootstrapped on this host yet.';
    exit;
}

require_once $cueBootstrapPath;

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/gear/domain-registrars/');
$authUser = isset($_SESSION['mh_auth_user']) ? trim((string) $_SESSION['mh_auth_user']) : '';
$authRole = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string) $_SESSION['mh_auth_role'])) : '';

if ($authUser === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode($requestUri), true, 302);
    exit;
}

$isKripzMaster = $authRole !== '' && stripos($authRole, 'kripzmaster') !== false;
if (! $isKripzMaster) {
    try {
        if (function_exists('mh_auth_load_user_context')) {
            mh_auth_load_user_context($authUser);
        }
    } catch (Throwable) {
    }
}

header('Location: /control/domain-registrars/', true, 302);
exit;
