<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$repoRoot = dirname(__DIR__);
$roots = [
    $repoRoot . '/public_html/control/domain-registrars',
    $repoRoot . '/public_html/hub/companies/domains',
    $repoRoot . '/public_html/hub/domains',
];

$files = [];
foreach ($roots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        if (!$entry->isFile()) continue;
        if ($entry->getFilename() !== 'index.php') continue;
        $files[] = str_replace('\\', '/', $entry->getRealPath());
    }
}
$files = array_values(array_unique($files));

$totalTried = 0;
$totalRewrote = 0;
$totalSkipped = 0;
$failures = [];

foreach ($files as $f) {
    $rel = substr($f, strlen($repoRoot) + 1);
    $orig = @file_get_contents($f);
    if ($orig === false) { $failures[] = "$rel: cannot read"; continue; }
    $totalTried++;

    if (preg_match('/dirname\(__DIR__, (\d+)\);/', $orig, $m)) {
        $dirDepth = (int)$m[1];
    } else {
        $failures[] = "$rel: cannot find dirname depth";
        echo "FAIL (no dirname): $rel\n";
        continue;
    }
    if (preg_match('%/\Q.cue\E/cue\.php%', $orig, $_)) {
        $cueFolder = '.cue';
    } elseif (preg_match('%/\Q.mh\E/cue\.php%', $orig, $_)) {
        $cueFolder = '.mh';
    } else {
        $failures[] = "$rel: cannot find cue folder (.cue/.mh)";
        echo "FAIL (no cue folder): $rel\n";
        continue;
    }
    if (preg_match('%\s*\$publicRoot \. \'([^\']+integrations[^\']+)\'%', $orig, $m)) {
        $candidateA = $m[1];
    } else {
        $failures[] = "$rel: cannot find candidateA";
        echo "FAIL (candidateA): $rel\n";
        continue;
    }
    $patternB = "%\s*(?:defined\('ROOT_PATH'\) \? \(string\)ROOT_PATH : ''\)|ROOT_PATH)\s*\.\s*'([^']+integrations[^']+)'%";
    if (preg_match($patternB, $orig, $m)) {
        $candidateB = $m[1];
    } else {
        $failures[] = "$rel: cannot find candidateB";
        echo "FAIL (candidateB): $rel\n";
        continue;
    }

    $isControl = (stripos($candidateA, '/control.php') !== false);
    $stemName = str_replace(
        ['public_html/','/index.php','control/','hub/','companies/','domain-registrars','domains'],
        ['','','ctl-','hub-','','',''],
        $rel
    );
    $stemName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $stemName);
    $stemName = strtoupper(trim($stemName, '_'));
    if ($stemName === '') $stemName = $isControl ? 'CONTROL_DISPATCH' : 'HUB_DISPATCH';
    if (strlen($stemName) > 40) $stemName = substr($stemName, 0, 40);
    $obConst = 'MH_' . $stemName . '_OB_CLEANUP';

    $cueMissingMsg = $isControl
        ? "CUE bootstrap path is not available for registrar control dispatch.\n"
        : "CUE bootstrap path is not available for hub domains dispatch.\n";
    $intMissingMsg = $isControl
        ? "Domain registrars control integration file is missing.\n"
        : "Domain registrars hub integration file is missing.\n";

    $diagConst = 'MH_DISPATCH_FATAL_DIAG_INSTALLED';
    $new = '<?php' . "\n\n"
         . 'declare(strict_types=1);' . "\n\n"
         . 'if (function_exists(\'error_reporting\')) {' . "\n    error_reporting(0);\n}\n"
         . "@ini_set('display_errors', '0');\n"
         . "@ini_set('html_errors', '0');\n"
         . "@ini_set('log_errors', '1');\n\n"
         . "if (!defined('{$diagConst}')) {\n"
         . "    define('{$diagConst}', true);\n"
         . '    $GLOBALS[\'MH_FATAL_LAST\'] = null;' . "\n"
         . '    set_error_handler(function (int $errno, string $errstr, string $errfile = \'\', int $errline = 0): bool {' . "\n"
         . '        $mask = E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;' . "\n"
         . '        if (($errno & $mask) !== 0) {' . "\n"
         . '            $GLOBALS[\'MH_FATAL_LAST\'] = [\'type\' => $errno, \'message\' => $errstr, \'file\' => $errfile, \'line\' => $errline];' . "\n"
         . "        }\n        return true;\n"
         . '    }, E_ALL);' . "\n"
         . "    register_shutdown_function(function (): void {\n"
         . '        $last = error_get_last();' . "\n"
         . '        if ($last === null) { $last = $GLOBALS[\'MH_FATAL_LAST\'] ?? null; }' . "\n"
         . '        if (!is_array($last)) { return; }' . "\n"
         . '        $fatalTypes = [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];' . "\n"
         . "        if (!in_array((int)(\$last['type'] ?? 0), \$fatalTypes, true)) { return; }\n"
         . "        while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }\n"
         . "        foreach (['MH_CONTROL_DISPATCH_OB_CLEANUP','MH_CTL_OB_CLEANUP','MH_CTL_ORDERS_OB_CLEANUP','MH_CTL_PROVIDERS_OB_CLEANUP','MH_CTL_PROVIDERS_COZA_OB_CLEANUP','MH_CTL_PROVIDERS_NETEARTHONE_OB_CLEANUP','MH_CTL_TASKS_OB_CLEANUP','MH_CTL_TASKS_ENQUEUE_OB_CLEANUP','MH_HUB_COMPANIES_DOMAINS_OB_CLEANUP','MH_HUB_EDIT_OB_CLEANUP','MH_HUB_RENEW_OB_CLEANUP','MH_HUB_REGISTER_OB_CLEANUP','MH_HUB_MANAGE_OB_CLEANUP','MH_HUB_CANCEL_OB_CLEANUP','MH_HUB_ORDERS_CANCEL_OB_CLEANUP','MH_HUB_DOMAINS_OB_CLEANUP','MH_CONTROL_OB_CLEANUP','MH_HUB_OB_CLEANUP'] as \$c) { if (defined(\$c)) { @ob_end_clean(); } }\n"
         . "        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=UTF-8', true); }\n"
         . "        echo \"Dispatch fatal error:\\n\";\n"
         . "        echo '  type=' . (\$last['type'] ?? 0) . \"\\n\";\n"
         . "        echo '  message=' . (\$last['message'] ?? '(no message)') . \"\\n\";\n"
         . "        echo '  file=' . (\$last['file'] ?? '(unknown file)') . \"\\n\";\n"
         . "        echo '  line=' . (\$last['line'] ?? 0) . \"\\n\";\n"
         . '    });' . "\n"
         . "}\n\n"
         . "while (ob_get_level() > 0) {\n    if (!@ob_end_clean()) {\n        break;\n    }\n}\n"
         . "if (ob_get_level() === 0) {\n"
         . "    @ob_start(function (string \$buffer, int \$phase): string { return ''; },\n"
         . "        0, PHP_OUTPUT_HANDLER_STDFLAGS);\n"
         . "    define('{$obConst}', true);\n}\n\n"
         . "\$publicRoot = dirname(__DIR__, {$dirDepth});\n"
         . "\$cueBootstrapPath = \$publicRoot . '/{$cueFolder}/cue.php';\n\n"
         . "\$_ENV['MH_PUBLIC_ROOT'] = \$publicRoot;\n"
         . "\$_SERVER['MH_PUBLIC_ROOT'] = \$publicRoot;\n"
         . "\$_ENV['CUE_BOOTSTRAP_PATH'] = \$cueBootstrapPath;\n"
         . "\$_SERVER['CUE_BOOTSTRAP_PATH'] = \$cueBootstrapPath;\n\n"
         . "if (!is_file(\$cueBootstrapPath)) {\n"
         . "    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }\n"
         . "    if (defined('{$obConst}')) { @ob_end_clean(); }\n"
         . "    if (!headers_sent()) {\n        http_response_code(500);\n        header('Content-Type: text/plain; charset=UTF-8', true);\n    }\n"
         . "    echo \"{$cueMissingMsg}\";\n    exit;\n}\n\n"
         . "require_once \$cueBootstrapPath;\n\n"
         . "while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }\n"
         . "if (defined('{$obConst}')) { @ob_end_clean(); }\n\n"
         . "\$integrationCandidates = [\n"
         . "    \$publicRoot . '{$candidateA}',\n"
         . "    (defined('ROOT_PATH') ? (string)ROOT_PATH : '') . '{$candidateB}',\n];\n\n"
         . "\$foundIntegration = false;\n"
         . "foreach (\$integrationCandidates as \$integrationPath) {\n"
         . "    if (is_string(\$integrationPath) && \$integrationPath !== '' && is_file(\$integrationPath)) {\n"
         . "        require \$integrationPath;\n        \$foundIntegration = true;\n        break;\n    }\n}\n\n"
         . "if (!\$foundIntegration) {\n"
         . "    while (ob_get_level() > 0) { if (!@ob_end_clean()) break; }\n"
         . "    if (defined('{$obConst}')) { @ob_end_clean(); }\n"
         . "    if (!headers_sent()) {\n        http_response_code(500);\n        header('Content-Type: text/plain; charset=UTF-8', true);\n    }\n"
         . "    echo \"{$intMissingMsg}\";\n    exit;\n}\n";

    $normNew = rtrim(str_replace(["\r\n", "\r"], "\n", $new), "\n");
    $normOrig = rtrim(str_replace(["\r\n", "\r"], "\n", $orig), "\n");
    if ($normNew === $normOrig) {
        $totalSkipped++;
        echo "SAME (V3): $rel\n";
        continue;
    }
    $ok = @file_put_contents($f, $new, LOCK_EX);
    if ($ok === false) {
        $failures[] = "$rel: cannot write";
        echo "FAIL (write): $rel\n";
        continue;
    }
    $totalRewrote++;
    echo "OK   rewrote V3: $rel (depth=$dirDepth, cue=/$cueFolder/, const=$obConst)\n";
}

echo "\n=== Summary (V3 transformer) ===\n";
echo "Files enumerated: " . count($files) . "\n";
echo "Tried: $totalTried\nRewrote: $totalRewrote\nSkipped (identical V3): $totalSkipped\nFailures: " . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $x) echo "  - $x\n";
    exit(1);
}
exit(0);
