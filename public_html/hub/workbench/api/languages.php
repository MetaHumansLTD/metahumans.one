<?php

require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$langs = [];
$gearLanguages = __DIR__ . '/../../../gear/languages/languages.php';
if (is_file($gearLanguages)) {
    require_once $gearLanguages;
    if (function_exists('gear_languages_get_nmt_languages')) {
        $langs = gear_languages_get_nmt_languages();
    }
}

mhw_json([
    'success' => true,
    'kind' => 'nmt',
    'count' => is_array($langs) ? count($langs) : 0,
    'languages' => $langs,
]);
