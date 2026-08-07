<?php

declare(strict_types=1);

if (function_exists('error_reporting')) {
    error_reporting(0);
}
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

while (ob_get_level() > 0) {
    if (!@ob_end_clean()) {
        break;
    }
}
if (ob_get_level() === 0) {
    @ob_start(function (string $buffer, int $phase): string {
        return '';
    }, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);
    define('MH_HUB_ORDERS_CANCEL_OB_CLEANUP', true);
}

$publicRoot = dirname(__DIR__, 5);
$cueBootstrapPath = $publicRoot . '/.cue/cue.php';

$_ENV['MH_PUBLIC_ROOT'] = $publicRoot;
$_SERVER['MH_PUBLIC_ROOT'] = $publicRoot;
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;

if (!is_file($cueBootstrapPath)) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
    if (defined('MH_HUB_ORDERS_CANCEL_OB_CLEANUP')) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8', true);
    }
    echo "CUE bootstrap path is not available for hub domains dispatch.
";
    exit;
}

require_once $cueBootstrapPath;

$integrationCandidates = [
    $publicRoot . '/gear/domain-registrars/integrations/metahumans/hub.php',
    (defined('ROOT_PATH') ? (string)ROOT_PATH : '') . '/apps/domain-registrars/integrations/metahumans/hub.php',
];

$foundIntegration = false;
foreach ($integrationCandidates as $integrationPath) {
    if (is_string($integrationPath) && $integrationPath !== '' && is_file($integrationPath)) {
        require $integrationPath;
        $foundIntegration = true;
        break;
    }
}

if (!$foundIntegration) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
    if (defined('MH_HUB_ORDERS_CANCEL_OB_CLEANUP')) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8', true);
    }
    echo "Domain registrars hub integration file is missing.
";
    exit;
}
