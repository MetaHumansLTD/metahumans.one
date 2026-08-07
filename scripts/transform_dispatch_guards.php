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

$reFormat = '#^<\?php\s*\R\s*\Rdeclare\(strict_types=1\);\s*\R\s*\R\$publicRoot = dirname\(__DIR__, (\d+)\);\s*\R\$cueBootstrapPath = \$publicRoot \. \'/(.+?)/cue\.php\';\s*\R\s*\R\$_ENV\[\'MH_PUBLIC_ROOT\'\] = \$publicRoot;\s*\R\$_SERVER\[\'MH_PUBLIC_ROOT\'\] = \$publicRoot;\s*\R\$_ENV\[\'CUE_BOOTSTRAP_PATH\'\] = \$cueBootstrapPath;\s*\R\$_SERVER\[\'CUE_BOOTSTRAP_PATH\'\] = \$cueBootstrapPath;\s*\R\s*\Rrequire_once \$cueBootstrapPath;\s*\R\s*\R\$integrationCandidates = \[\s*\R\s*\$publicRoot \. \'([^\']+)\',\s*\R\s*(?:defined\(\'ROOT_PATH\'\) \? \(string\)ROOT_PATH : \'\'|ROOT_PATH) \. \'([^\']+)\',\s*\R\];\s*\R\s*\Rforeach \(\$integrationCandidates as \$integrationPath\) \{\s*\R\s*if \(is_string\(\$integrationPath\) && \$integrationPath !== \'\' && is_file\(\$integrationPath\)\) \{\s*\R\s*require \$integrationPath;\s*\R\s*(?:return;|break;)\s*\R\s*\}\s*\R\}\s*\R\s*\Rthrow new RuntimeException\(\'([^\']+)\'\);\s*$#s';

$totalTried = 0;
$totalRewrote = 0;
$totalSkipped = 0;
$failures = [];

foreach ($files as $f) {
    $rel = substr($f, strlen($repoRoot) + 1);
    $orig = @file_get_contents($f);
    if ($orig === false) { $failures[] = "$rel: cannot read"; continue; }
    $totalTried++;

    if (strpos($orig, 'MH_') !== false && (strpos($orig, '_OB_CLEANUP') !== false || strpos($orig, 'DISPATCH_OB_') !== false)) {
        $totalSkipped++;
        echo "SKIP (already has guard): $rel\n";
        continue;
    }

    if (!preg_match($reFormat, $orig, $m)) {
        $failures[] = "$rel: regex mismatch";
        echo "FAIL (pattern mismatch, len=".strlen($orig)."): $rel\n";
        echo "  head: " . substr(str_replace(["\r","\n"],['','\n'],$orig), 0, 80) . "\n";
        continue;
    }
    [, $dirDepth, $cueFolder, $candidateA, $candidateB, $throwMsg] = $m;
    $dirDepth = (int)$dirDepth;

    $isControl = (stripos($throwMsg, 'control') !== false);
    $stemName = str_replace(['public_html/','/index.php','control/','hub/','companies/','domain-registrars','domains'],
        ['','','ctl-','hub-','','',''], $rel);
    $stemName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $stemName);
    $stemName = strtoupper(trim($stemName, '_'));
    if ($stemName === '') $stemName = $isControl ? 'CONTROL_DISPATCH' : 'HUB_DISPATCH';
    if (strlen($stemName) > 40) $stemName = substr($stemName,0,40);
    $obConst = 'MH_' . $stemName . '_OB_CLEANUP';

    $cueMissingMsg = ($isControl ? 'CUE bootstrap path is not available for registrar control dispatch.' : 'CUE bootstrap path is not available for hub domains dispatch.') . "\n";
    $intMissingMsg = $throwMsg . "\n";

    $new = "<?php\n\ndeclare(strict_types=1);\n\n"
         . "if (function_exists('error_reporting')) {\n    error_reporting(0);\n}\n"
         . "@ini_set('display_errors', '0');\n"
         . "@ini_set('html_errors', '0');\n"
         . "@ini_set('log_errors', '1');\n\n"
         . "while (ob_get_level() > 0) {\n    if (!@ob_end_clean()) {\n        break;\n    }\n}\n"
         . "if (ob_get_level() === 0) {\n"
         . "    @ob_start(function (string \$buffer, int \$phase): string {\n        return '';\n    }, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);\n"
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

    if (rtrim($new, "\n") === rtrim($orig, "\n")) {
        $totalSkipped++;
        echo "SAME (no diff): $rel\n";
        continue;
    }
    $ok = @file_put_contents($f, $new, LOCK_EX);
    if ($ok === false) {
        $failures[] = "$rel: cannot write";
        echo "FAIL (write): $rel\n";
        continue;
    }
    $totalRewrote++;
    echo "OK   rewrote: $rel (depth=$dirDepth, cue=/$cueFolder/, const=$obConst)\n";
}

echo "\n=== Summary ===\n";
echo "Files enumerated: " . count($files) . "\n";
echo "Tried: $totalTried\nRewrote: $totalRewrote\nSkipped (already have guard / identical): $totalSkipped\nFailures: " . count($failures) . "\n";
if ($failures) {
    foreach ($failures as $x) echo "  - $x\n";
    exit(1);
}
exit(0);
