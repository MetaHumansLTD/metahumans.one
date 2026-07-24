<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

mh_widget_require_auth();

$docroot = dirname(__DIR__, 2);
$gearLang = $docroot . '/gear/languages/languages.php';

$spokenCodes = [];
$nmtLangs = [];
$ok = false;

if (is_file($gearLang)) {
    try {
        require_once $gearLang;
        if (isset($allowed_spoken_langs) && is_array($allowed_spoken_langs)) {
            $spokenCodes = array_values(array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', $allowed_spoken_langs), fn($v) => $v !== ''));
        }
        if (function_exists('gear_languages_get_nmt_languages')) {
            $nmtLangs = gear_languages_get_nmt_languages();
            if (!is_array($nmtLangs)) $nmtLangs = [];
        }
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
}

if (!$ok) {
    $spokenCodes = ['auto', 'en-US'];
    $nmtLangs = [];
}

$spokenLabels = [];
foreach ($spokenCodes as $code) {
    if ($code === 'auto') {
        $spokenLabels[$code] = 'Auto Detect';
        continue;
    }
    $tag = str_replace('-', '_', $code);
    $lang = '';
    $regionLabel = '';
    if (class_exists('Locale')) {
        try {
            $lang = (string)Locale::getDisplayLanguage($tag, 'en');
            $region = (string)Locale::getRegion($tag);
            if ($region !== '') {
                $regionLabel = (string)Locale::getDisplayRegion('_' . $region, 'en');
            }
        } catch (Throwable) {
            $lang = '';
            $regionLabel = '';
        }
    }
    $lang = trim($lang);
    $regionLabel = trim($regionLabel);
    if ($lang === '') {
        $spokenLabels[$code] = $code;
    } elseif ($regionLabel !== '') {
        $spokenLabels[$code] = $lang . ' (' . $regionLabel . ')';
    } else {
        $spokenLabels[$code] = $lang;
    }
}

mh_widget_json([
    'success' => true,
    'source' => $ok ? 'gear/languages' : 'fallback',
    'spoken_langs' => $spokenCodes,
    'spoken_labels' => $spokenLabels,
    'nmt_langs' => $nmtLangs,
]);
