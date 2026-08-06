<?php

declare(strict_types=1);

if (function_exists('error_reporting')) {
    error_reporting(0);
}
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

use App\Presentation\Control\ControlController;

if (! function_exists('cue_autoload')) {
    $cueBootstrapPath = $_ENV['CUE_BOOTSTRAP_PATH'] ?? $_SERVER['CUE_BOOTSTRAP_PATH'] ?? getenv('CUE_BOOTSTRAP_PATH');
    if (! is_string($cueBootstrapPath) || $cueBootstrapPath === '' || ! is_file($cueBootstrapPath)) {
        throw new RuntimeException('CUE bootstrap path is not available for registrar control integration.');
    }

    require_once $cueBootstrapPath;
}

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/control/domain-registrars');
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/domain-registrars';
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

$_SESSION['current_realm'] = 'control';

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

$_ENV['APP_ROLE'] = 'control';
$_SERVER['APP_ROLE'] = 'control';
$_ENV['SHARED_DB_CONFIG_NAME'] = $_ENV['SHARED_DB_CONFIG_NAME'] ?? 'db_domain_registrars_shared';
$_SERVER['SHARED_DB_CONFIG_NAME'] = $_SERVER['SHARED_DB_CONFIG_NAME'] ?? 'db_domain_registrars_shared';
$_ENV['SHARED_DB_DATABASE_NAME'] = $_ENV['SHARED_DB_DATABASE_NAME'] ?? 'domainname_controller';
$_SERVER['SHARED_DB_DATABASE_NAME'] = $_SERVER['SHARED_DB_DATABASE_NAME'] ?? 'domainname_controller';

$path = parse_url($requestUri, PHP_URL_PATH) ?: '/control/domain-registrars';
$prefix = '/control/domain-registrars';
if (str_starts_with($path, $prefix)) {
    $path = substr($path, strlen($prefix));
    $path = $path === '' ? '/' : $path;
}

$appRoot = dirname(__DIR__, 2);
$bootstrapPath = $appRoot . '/bootstrap/app.php';
if (! is_file($bootstrapPath)) {
    throw new RuntimeException('Domain registrar bootstrap file is missing.');
}

try {
    /** @var \App\Application $app */
    $app = require $bootstrapPath;

    try {
        $app->enableRegistrarPoolMode();
    } catch (Throwable $poolException) {
        error_log('[control/domain-registrars][pool] ' . $poolException->getMessage());
        try {
            $app->sharedSchemaLoader()->load();
        } catch (Throwable) {
        }
        try {
            $app->tenantSchemaLoader()->load();
        } catch (Throwable) {
        }
    }

    $controller = new ControlController($app);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (! headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    try {
        $response = $controller->handle($path, $method, $_GET, $_POST);
    } catch (Throwable $inner) {
        error_log('[control/domain-registrars][controller] ' . $inner->getMessage());
        $response = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registrar Control Error</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><h1>Registrar Control Error</h1><p>The request could not be handled right now.</p><p style="color:#94a3b8;">' . htmlspecialchars($inner->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><p><a style="color:#60a5fa;" href="' . htmlspecialchars((string) ($_SERVER['REQUEST_URI'] ?? '/control/domain-registrars/'), ENT_QUOTES, 'UTF-8') . '">Try again</a></p></body></html>';
    }
    if (! is_string($response) || $response === '') {
        $response = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registrar Control</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><h1>No content</h1><p>The server returned an empty response for this control page.</p><p><a style="color:#60a5fa;" href="/control/domain-registrars/">Back to control home</a></p></body></html>';
    }
    echo $response;
} catch (Throwable $exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('[control/domain-registrars] ' . $exception->getMessage());
    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Registrar Control Error</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><h1>Registrar Control Error</h1><p>The control workspace could not be loaded right now.</p><p style="color:#94a3b8;">' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></body></html>';
}
