<?php

declare(strict_types=1);

$publicRoot = dirname(__DIR__, 2);
$cueBootstrapPath = $publicRoot . '/.cue/cue.php';

$_ENV['MH_PUBLIC_ROOT'] = $publicRoot;
$_SERVER['MH_PUBLIC_ROOT'] = $publicRoot;
$_ENV['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;
$_SERVER['CUE_BOOTSTRAP_PATH'] = $cueBootstrapPath;

require_once $cueBootstrapPath;
require ROOT_PATH . '/apps/domain-registrars/integrations/metahumans/hub.php';
