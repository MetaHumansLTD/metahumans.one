<?php

declare(strict_types=1);

use App\Presentation\Control\ControlController;
use App\Presentation\Hub\HubController;

/** @var \App\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$role = $app->config()->string('APP_ROLE', 'hub');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($role === 'hub') {
    $controller = new HubController($app);
    header('Content-Type: text/html; charset=utf-8');
    echo $controller->handle($path, $method, $_GET, $_POST);
    return;
}

if ($role === 'control') {
    $controller = new ControlController($app);
    header('Content-Type: text/html; charset=utf-8');
    echo $controller->handle($path, $method, $_GET, $_POST);
    return;
}

$tenantDatabaseOk = true;
$tenantDatabaseError = null;
$sharedDatabaseOk = true;
$sharedDatabaseError = null;

try {
    $app->tenantDatabase()->fetchOne('SELECT 1 AS ok');
} catch (Throwable $exception) {
    $tenantDatabaseOk = false;
    $tenantDatabaseError = $exception->getMessage();
}

try {
    $app->sharedDatabase()->fetchOne('SELECT 1 AS ok');
} catch (Throwable $exception) {
    $sharedDatabaseOk = false;
    $sharedDatabaseError = $exception->getMessage();
}

header('Content-Type: application/json');

echo json_encode(
    [
        'app' => 'domain-registrar-service',
        'role' => $role,
        'environment' => $app->config()->string('APP_ENV', 'local'),
        'databases' => [
            'tenant' => [
                'ok' => $tenantDatabaseOk,
                'error' => $tenantDatabaseError,
            ],
            'shared' => [
                'ok' => $sharedDatabaseOk,
                'error' => $sharedDatabaseError,
            ],
        ],
        'routes' => [
            'health' => '/',
            'cli' => 'php bin/console',
        ],
    ],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
);
