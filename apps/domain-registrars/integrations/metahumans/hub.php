<?php

declare(strict_types=1);

use App\Presentation\Hub\HubController;

if (! function_exists('cue_autoload')) {
    $cueBootstrapPath = $_ENV['CUE_BOOTSTRAP_PATH'] ?? $_SERVER['CUE_BOOTSTRAP_PATH'] ?? getenv('CUE_BOOTSTRAP_PATH');
    if (! is_string($cueBootstrapPath) || $cueBootstrapPath === '' || ! is_file($cueBootstrapPath)) {
        throw new RuntimeException('CUE bootstrap path is not available for hub/domains integration.');
    }

    require_once $cueBootstrapPath;
}

if (! function_exists('mh_auth_load_user_context') || ! function_exists('mh_apply_tenant_context')) {
    $publicRoot = $_ENV['MH_PUBLIC_ROOT'] ?? $_SERVER['MH_PUBLIC_ROOT'] ?? getenv('MH_PUBLIC_ROOT');
    if (is_string($publicRoot) && $publicRoot !== '') {
        $authFunctionsPath = rtrim($publicRoot, '/\\') . '/auth/auth_functions.php';
        if (is_file($authFunctionsPath)) {
            require_once $authFunctionsPath;
        }
    }
}

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/hub/domains');
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/hub/domains';
}

if (! isset($_SESSION['mh_auth_user']) || ! is_string($_SESSION['mh_auth_user']) || trim((string) $_SESSION['mh_auth_user']) === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode($requestUri), true, 302);
    exit;
}

$username = trim((string) $_SESSION['mh_auth_user']);
try {
    if (function_exists('mh_auth_load_user_context')) {
        mh_auth_load_user_context($username);
    }
} catch (Throwable) {
}

$tenantId = trim((string) ($_SESSION['mh_tenant_id'] ?? ''));
if ($tenantId === '') {
    $tenantId = 'user:' . $username;
}

try {
    if (function_exists('mh_apply_tenant_context')) {
        mh_apply_tenant_context($tenantId);
    }
} catch (Throwable) {
}

$_SESSION['current_realm'] = 'hub';

$publicRoot = $_ENV['MH_PUBLIC_ROOT'] ?? $_SERVER['MH_PUBLIC_ROOT'] ?? getenv('MH_PUBLIC_ROOT');
if ((! is_string($publicRoot) || $publicRoot === '') && isset($cueBootstrapPath) && is_string($cueBootstrapPath) && $cueBootstrapPath !== '') {
    $publicRoot = dirname(dirname($cueBootstrapPath));
}

if (is_string($publicRoot) && $publicRoot !== '') {
    $_ENV['MH_PUBLIC_ROOT'] = rtrim($publicRoot, '/\\');
    $_SERVER['MH_PUBLIC_ROOT'] = rtrim($publicRoot, '/\\');
}

$cueBootstrapPath = $_ENV['CUE_BOOTSTRAP_PATH'] ?? $_SERVER['CUE_BOOTSTRAP_PATH'] ?? getenv('CUE_BOOTSTRAP_PATH');
if (is_string($cueBootstrapPath) && $cueBootstrapPath !== '') {
    $_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
    $_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
}

$_ENV['SHARED_DB_CONFIG_NAME'] = $_ENV['SHARED_DB_CONFIG_NAME'] ?? 'db_domain_registrars_shared';
$_SERVER['SHARED_DB_CONFIG_NAME'] = $_SERVER['SHARED_DB_CONFIG_NAME'] ?? 'db_domain_registrars_shared';
$_ENV['SHARED_DB_DATABASE_NAME'] = $_ENV['SHARED_DB_DATABASE_NAME'] ?? 'domainname_controller';
$_SERVER['SHARED_DB_DATABASE_NAME'] = $_SERVER['SHARED_DB_DATABASE_NAME'] ?? 'domainname_controller';

$appRoot = dirname(__DIR__, 2);
$bootstrapPath = $appRoot . '/bootstrap/app.php';
if (! is_file($bootstrapPath)) {
    throw new RuntimeException('Domain registrar bootstrap file is missing.');
}

try {
    /** @var \App\Application $app */
    $app = require $bootstrapPath;

    $controller = new HubController($app);
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/hub/domains';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    header('Content-Type: text/html; charset=UTF-8');
    echo $controller->handle($path, $method, $_GET, $_POST);
} catch (Throwable $exception) {
    error_log('[hub/domains] ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hub Domains Error</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><h1>Hub Domains Error</h1><p>The domain workspace could not be loaded right now.</p><p style="color:#94a3b8;">' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}
