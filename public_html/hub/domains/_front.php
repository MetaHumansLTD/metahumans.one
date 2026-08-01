<?php

declare(strict_types=1);

use App\Presentation\Hub\HubController;

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/domains';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/domains';
    }

    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

try {
    if (function_exists('mh_auth_load_user_context')) {
        mh_auth_load_user_context((string) ($_SESSION['mh_auth_user'] ?? ''));
    }
} catch (Throwable) {
}

$cueBootstrapPath = realpath(__DIR__ . '/../../.cue/cue.php') ?: __DIR__ . '/../../.cue/cue.php';
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_ENV['SHARED_DB_CONFIG_ID'] = $_ENV['SHARED_DB_CONFIG_ID'] ?? 'db_domain_registrars_shared';
$_SERVER['SHARED_DB_CONFIG_ID'] = $_SERVER['SHARED_DB_CONFIG_ID'] ?? 'db_domain_registrars_shared';

/** @var \App\Application $app */
$app = require __DIR__ . '/app/bootstrap/app.php';

$controller = new HubController($app);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/hub/domains', PHP_URL_PATH) ?: '/hub/domains';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: text/html; charset=utf-8');
echo $controller->handle($path, $method, $_GET, $_POST);
