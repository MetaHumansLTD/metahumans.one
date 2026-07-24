<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    $redirect = '/hub/workbench/workspace.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$target = getenv('META_HUMANS_WORKSPACE_PORTAL_URL');
$target = is_string($target) ? trim($target) : '';
if ($target === '') {
    $target = 'https://meta.superhumans.one/ide/';
}

header('Location: ' . $target);
exit;
