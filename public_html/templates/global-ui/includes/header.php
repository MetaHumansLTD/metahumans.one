<?php
/**
 * Global Header Include
 * Include this file in any page to add the global header
 * 
 * Usage: include_once getTemplatesPath() . '/global-ui/includes/header.php';
 */

require_once dirname(__DIR__) . '/functions.php';

// Force reload of header configuration to ensure fresh data
$headerConfigFile = getGlobalUIConfigPath('header', 'config');
clearstatcache(true, $headerConfigFile);

// Check for debug parameter and pass it to renderGlobalHeader
$debug = isset($_GET['debug_header']) ? true : false;

// Debug logging for header rendering context
if (function_exists('cue_debug_log')) {
    cue_debug_log('Header Include: Rendering header from ' . (__FILE__) . ' with config: ' . $headerConfigFile);
}
if (file_exists($headerConfigFile)) {
    $testConfig = json_decode(file_get_contents($headerConfigFile), true);
    if (isset($testConfig['K::HeaderUI::Configuration'])) {
        $keys = array_keys($testConfig['K::HeaderUI::Configuration']);
        $logoPath = $testConfig['K::HeaderUI::Configuration'][$keys[0]]['hdr_logo_image_path'] ?? 'NOT SET';
        if (function_exists('cue_debug_log')) {
            cue_debug_log('Header Include: Logo path in config: ' . $logoPath);
        }
    }
}

renderGlobalHeader($debug);
renderGlobalHamburgerMenu();
?>
