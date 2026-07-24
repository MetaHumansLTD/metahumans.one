<?php
/**
 * Global Hamburger Menu Include
 * Include this file in any page to add the global hamburger menu
 * 
 * Usage: include_once getTemplatesPath() . '/global-ui/includes/hamburger.php';
 */

// JSON mode: safe output for clients fetching hamburger data
if ((isset($_GET['format']) && $_GET['format'] === 'json') || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_POST['action'] === 'get_hamburger' || $_POST['action'] === 'get_menu'))) {
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();
    require_once dirname(__DIR__) . '/functions.php';
    ob_end_clean();

    // Suppress error display in JSON responses
    ini_set('display_errors', 0);
    ini_set('html_errors', 0);
    ini_set('log_errors', 1);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        http_response_code(200);
    }

    try {
        $include = isset($_GET['include']) ? $_GET['include'] : '';
        $cfg = [];
        if ($include === 'all') { $cfg['include_all_realms'] = true; }
        $data = getHamburgerStructuredMenu($cfg);
        while (ob_get_level()) { ob_end_clean(); }
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        if (!headers_sent()) { http_response_code(200); }
        error_log('Hamburger JSON error: ' . $e->getMessage());
        while (ob_get_level()) { ob_end_clean(); }
        echo json_encode([]);
    }
    exit;
}

require_once dirname(__DIR__) . '/functions.php';
$isDirect = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__);
if ($isDirect) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Hamburger Preview</title>';
    include_once __DIR__ . '/complete-head.php';
    echo '</head><body>';
    include_once __DIR__ . '/complete-body-start.php';
    echo '<main class="main-content" style="padding:20px;min-height:50vh"></main>';
    include_once __DIR__ . '/complete-body-end.php';
    echo '</body></html>';
    return;
}
if (empty($GLOBALS['_FA_LOADED'])) {
    $faUrl = '/templates/assets/icons/fontawesome/css/all.min.css';
    $faPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__, 2) . '/assets/icons/fontawesome/css/all.min.css');
    if (file_exists($faPath)) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
        $GLOBALS['_FA_LOADED'] = true;
    }
}
// Load Phosphor Icons if not already loaded
if (empty($GLOBALS['_PHOSPHOR_LOADED'])) {
    $phUrl = '/templates/assets/icons/phosphor/Fonts/regular/style.css';
    $phPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/phosphor/Fonts/regular/style.css') : (dirname(__DIR__, 2) . '/assets/icons/phosphor/Fonts/regular/style.css');
    if (file_exists($phPath)) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($phUrl, ENT_QUOTES) . '">';
        $GLOBALS['_PHOSPHOR_LOADED'] = true;
    }
}
// Ensure MetaHumans FA glyph is available globally when hamburger is included
if (empty($GLOBALS['_FA_META_LOADED'])) {
    $metaUrl = '/templates/assets/images/branding/logo/MHlogoTB64.png';
    echo '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
    $GLOBALS['_FA_META_LOADED'] = true;
}
renderGlobalHamburgerMenu();
?>
