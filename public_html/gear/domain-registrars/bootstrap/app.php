<?php

declare(strict_types=1);

use App\Application;
use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
$autoloadPath = $rootPath . '/vendor/autoload.php';

if (! is_file($autoloadPath)) {
    throw new RuntimeException('Domain registrars dependencies are not installed in this app runtime yet.');
}

require $autoloadPath;

if (is_file($rootPath . '/.env')) {
    Dotenv::createImmutable($rootPath)->safeLoad();
}

$cueBootstrapPath = $_ENV['CUE_BOOTSTRAP_PATH'] ?? $_SERVER['CUE_BOOTSTRAP_PATH'] ?? getenv('CUE_BOOTSTRAP_PATH');
if (is_string($cueBootstrapPath) && $cueBootstrapPath !== '' && is_file($cueBootstrapPath)) {
    require_once $cueBootstrapPath;
}

return new Application($rootPath);
