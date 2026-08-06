<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__, 3);
$cueBootstrapPath = $publicRoot . '/.cue/cue.php';

$_ENV['MH_PUBLIC_ROOT'] = $publicRoot;
$_SERVER['MH_PUBLIC_ROOT'] = $publicRoot;
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;

require_once $cueBootstrapPath;

$integrationCandidates = [
    $publicRoot . '/gear/domain-registrars/integrations/metahumans/control.php',
    ROOT_PATH . '/apps/domain-registrars/integrations/metahumans/control.php',
];

foreach ($integrationCandidates as $integrationPath) {
    if (is_string($integrationPath) && $integrationPath !== '' && is_file($integrationPath)) {
        require $integrationPath;
        return;
    }
}

throw new RuntimeException('Domain registrars control integration file is missing.');
