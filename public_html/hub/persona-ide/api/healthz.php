<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../../.cue/cue.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo json_encode([
    'ok' => true,
    'service' => 'persona-headless-ide',
    'user' => $_SESSION['mh_auth_user'] ?? null,
    'tenant_id' => $_SESSION['mh_tenant_id'] ?? null,
    'persona_tenant_id' => $_SESSION['mh_persona_tenant_id'] ?? null
]);
