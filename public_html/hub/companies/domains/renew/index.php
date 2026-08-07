<?php

declare(strict_types=1);

if (function_exists('error_reporting')) {
    error_reporting(0);
}
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

if (!defined('MH_DISPATCH_FATAL_DIAG_INSTALLED')) {
    define('MH_DISPATCH_FATAL_DIAG_INSTALLED', true);
    $GLOBALS['MH_FATAL_LAST'] = null;
    set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        $mask = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
        if (($errno & $mask) !== 0) {
            $GLOBALS['MH_FATAL_LAST'] = ['type' => $errno, 'message' => $errstr, 'file' => $errfile, 'line' => $errline];
        }
        return true;
    }, E_ALL);
    register_shutdown_function(function (): void {
        $last = error_get_last();
        if ($last === null) { $last = $GLOBALS['MH_FATAL_LAST'] ?? null; }
        if (!is_array($last)) { return; }
        $fatalTypes = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array((int)($last['type'] ?? 0), $fatalTypes, true)) { return; }
        while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
        foreach (['MH_CONTROL_DISPATCH_OB_CLEANUP','MH_CTL_OB_CLEANUP','MH_CTL_ORDERS_OB_CLEANUP','MH_CTL_PROVIDERS_OB_CLEANUP','MH_CTL_PROVIDERS_COZA_OB_CLEANUP','MH_CTL_PROVIDERS_NETEARTHONE_OB_CLEANUP','MH_CTL_TASKS_OB_CLEANUP','MH_CTL_TASKS_ENQUEUE_OB_CLEANUP','MH_HUB_COMPANIES_DOMAINS_OB_CLEANUP','MH_HUB_EDIT_OB_CLEANUP','MH_HUB_RENEW_OB_CLEANUP','MH_HUB_REGISTER_OB_CLEANUP','MH_HUB_MANAGE_OB_CLEANUP','MH_HUB_CANCEL_OB_CLEANUP','MH_HUB_ORDERS_CANCEL_OB_CLEANUP','MH_HUB_DOMAINS_OB_CLEANUP','MH_CONTROL_OB_CLEANUP','MH_HUB_OB_CLEANUP'] as $c) { if (defined($c)) { @ob_end_clean(); } }
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8', true); }
        echo "Dispatch fatal error:\n";
        echo '  type=' . ($last['type'] ?? 0) . "\n";
        echo '  message=' . ($last['message'] ?? '(no message)') . "\n";
        echo '  file=' . ($last['file'] ?? '(unknown file)') . "\n";
        echo '  line=' . ($last['line'] ?? 0) . "\n";
    });
}

while (ob_get_level() > 0) {
    if (!@ob_end_clean()) {
        break;
    }
}
if (ob_get_level() === 0) {
    @ob_start(function (string $buffer, int $phase): string { return ''; },
        0, PHP_OUTPUT_HANDLER_STDFLAGS);
    define('MH_HUB_RENEW_OB_CLEANUP', true);
}

$publicRoot = dirname(__DIR__, 4);
$cueBootstrapPath = $publicRoot . '/.cue/cue.php';

$_ENV['MH_PUBLIC_ROOT'] = $publicRoot;
$_SERVER['MH_PUBLIC_ROOT'] = $publicRoot;
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;

if (!is_file($cueBootstrapPath)) {
    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
    if (defined('MH_HUB_RENEW_OB_CLEANUP')) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8', true);
    }
    echo "CUE bootstrap path is not available for hub domains dispatch.
";
    exit;
}

require_once $cueBootstrapPath;

while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }
if (defined('MH_HUB_RENEW_OB_CLEANUP')) { @ob_end_clean(); }

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
    if (defined('MH_HUB_RENEW_OB_CLEANUP')) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8', true);
    }
    echo "Domain registrars hub integration file is missing.
";
    exit;
}
