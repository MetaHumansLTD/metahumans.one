<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
require_once dirname(dirname(dirname(__DIR__))) . '/auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

header('Location: /hub/tools/vimax.php', true, 302);
exit;
?>
