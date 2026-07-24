<?php
/**
 * Meta Humans Enterprise Software - Advanced Hamburger Menu Navigator
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getContextAwareDatabase() for database connections
 * ✓ Uses getSecureFilePath() for all file operations
 * ✓ Follows enterprise security standards
 * ✓ Supports multi-database context awareness
 * ✓ Uses framework encryption functions
 * 
 * @package    Meta Humans Menu Navigator
 * @author     Meta Humans LTD (Pieter Rubeus - owner)
 * @copyright  Copyright (c) Meta Humans LTD® 2025
 * @license    Licensed
 * @link       https://metahumans.one
 */

// Check for AJAX requests BEFORE loading CUE framework to prevent auto-injection
$isAjaxRequest = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));

// MANDATORY: Load CUE framework - provides all database and security functions
$start_time = microtime(true);
if ($isAjaxRequest || !defined('CUE_DISABLE_AUTO_LAYOUT')) { define('CUE_DISABLE_AUTO_LAYOUT', true); }
if ($isAjaxRequest) { define('CUE_DISABLE_OUTPUT_BUFFERING', true); }
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('navigator', $isAjaxRequest);

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$authFunctionsPath = dirname(__DIR__, 2) . '/auth/auth_functions.php';
if (is_file($authFunctionsPath)) {
    require_once $authFunctionsPath;
}
if (isset($_SESSION['mh_auth_user']) && (!isset($_SESSION['mh_auth_role']) || trim((string)$_SESSION['mh_auth_role']) === '') && function_exists('mh_load_biometrics_user')) {
    mh_load_biometrics_user((string)$_SESSION['mh_auth_user']);
}

$cue_load_time = (microtime(true) - $start_time) * 1000;

$footerSafeOffsetPx = 0;
try {
    $footerConfigPath = function_exists('getDataPath')
        ? (getDataPath() . '/global-ui/footer/footer-config.json')
        : (dirname(dirname(__DIR__)) . '/.data/global-ui/footer/footer-config.json');
    if (is_file($footerConfigPath)) {
        $cfgJson = json_decode((string)file_get_contents($footerConfigPath), true);
        if (is_array($cfgJson) && isset($cfgJson['K::FooterUI::Configuration']) && is_array($cfgJson['K::FooterUI::Configuration'])) {
            $keys = array_keys($cfgJson['K::FooterUI::Configuration']);
            if (!empty($keys) && is_array($cfgJson['K::FooterUI::Configuration'][$keys[0]] ?? null)) {
                $f = $cfgJson['K::FooterUI::Configuration'][$keys[0]];
                $pos = (string)($f['ftr_position'] ?? 'bottom');
                if ($pos === 'fixed' || $pos === 'absolute') {
                    $h = (int)($f['ftr_footer_height'] ?? 0);
                    $gap = (int)($f['ftr_footer_content_spacing'] ?? 0);
                    $extra = (!empty($f['ftr_extra_content_spacing_enabled'])) ? (int)($f['ftr_extra_content_spacing'] ?? 0) : 0;
                    $footerSafeOffsetPx = max(0, $h + $gap + $extra);
                }
            }
        }
    }
} catch (Throwable $e) {
    $footerSafeOffsetPx = 0;
}

function mh_nav_default_icon_url(): string
{
    return '/templates/assets/images/branding/triangle/logo-triangle-1000.png';
}

function mh_nav_thesvg_primary_url(string $slug, string $variant): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9\\-]/', '', $slug) ?: '';
    $variant = strtolower(trim($variant));
    $variant = preg_replace('/[^a-z0-9]/', '', $variant) ?: 'default';
    return '/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=' . rawurlencode($slug) . '&variant=' . rawurlencode($variant);
}

function mh_nav_thesvg_fallback_url(string $slug, string $variant): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9\\-]/', '', $slug) ?: '';
    $variant = strtolower(trim($variant));
    $variant = preg_replace('/[^a-z0-9]/', '', $variant) ?: 'default';
    return '';
}

// #region debug-point A:php-report
function mh_nav_dbg_send(string $hypothesisId, string $location, string $msg, array $data = []): void
{
    static $cfg = null;
    static $count = 0;
    $count++;
    if ($count > 300) return;

    if (!is_array($cfg)) {
        $cfg = [
            'url' => '',
            'sessionId' => 'navigator-icons-broken',
            'runId' => 'pre-fix',
        ];
        $env = '/home/onemeta/public_html/.dbg/navigator-icons-broken.env';
        if (is_file($env)) {
            $c = (string)file_get_contents($env);
            if (preg_match('/^DEBUG_SERVER_URL=(.+)$/m', $c, $m)) { $cfg['url'] = trim((string)$m[1]); }
            if (preg_match('/^DEBUG_SESSION_ID=(.+)$/m', $c, $m2)) { $cfg['sessionId'] = trim((string)$m2[1]); }
        }
    }
    if (!is_string($cfg['url'] ?? null) || $cfg['url'] === '') return;

    $payload = [
        'sessionId' => (string)$cfg['sessionId'],
        'runId' => (string)($cfg['runId'] ?? 'pre-fix'),
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'msg' => $msg,
        'data' => $data,
        'ts' => (int)floor(microtime(true) * 1000),
    ];
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'timeout' => 0.4,
        ],
    ]);
    @file_get_contents((string)$cfg['url'], false, $ctx);
}
// #endregion

function mh_nav_phosphor_name_from_any(string $raw): string
{
    $s = strtolower(trim($raw));
    if ($s === '') return '';

    if (preg_match('/\\bfa-([a-z0-9\\-]+)\\b/', $s, $m)) {
        $name = (string)($m[1] ?? '');
        if ($name !== '' && !in_array($name, ['solid', 'regular', 'brands'], true)) {
            $map = [
                'chevron-right' => 'caret-right',
                'chevron-left' => 'caret-left',
                'chevron-up' => 'caret-up',
                'chevron-down' => 'caret-down',
                'angle-right' => 'caret-right',
                'angle-left' => 'caret-left',
                'angle-up' => 'caret-up',
                'angle-down' => 'caret-down',
            ];
            return $map[$name] ?? $name;
        }
    }

    if (preg_match('/\\biconoir-([a-z0-9\\-]+)\\b/', $s, $m)) {
        return (string)($m[1] ?? '');
    }

    if (preg_match('/\\bph-([a-z0-9\\-]+)\\b/', $s, $m)) {
        $name = (string)($m[1] ?? '');
        $map = [
            'chevron-right' => 'caret-right',
            'chevron-left' => 'caret-left',
            'chevron-up' => 'caret-up',
            'chevron-down' => 'caret-down',
        ];
        return $map[$name] ?? $name;
    }

    if (preg_match('/^[a-z0-9\\-]+$/', $s)) {
        $map = [
            'chevron-right' => 'caret-right',
            'chevron-left' => 'caret-left',
            'chevron-up' => 'caret-up',
            'chevron-down' => 'caret-down',
        ];
        return $map[$s] ?? $s;
    }

    return '';
}

function mh_nav_icon_html(mixed $icon, int $sizePx, string $color): string
{
    $sizePx = max(10, $sizePx);
    $color = trim((string)$color);
    if ($color === '') $color = '#00ffff';

    $raw = is_string($icon) ? trim($icon) : '';
    if ($raw === '') {
        // #region debug-point E:php-icon-default
        mh_nav_dbg_send('E', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon default (empty)', ['size' => $sizePx]);
        // #endregion
        $u = mh_nav_default_icon_url();
        return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;"><img src="' . htmlspecialchars($u, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:block"></span>';
    }

    if (stripos($raw, '<svg') !== false) {
        // #region debug-point E:php-icon-inline-svg
        mh_nav_dbg_send('E', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon inline svg', ['size' => $sizePx, 'head' => substr($raw, 0, 40)]);
        // #endregion
        $svg = $raw;
        $svg = preg_replace('/(<svg[^>]*)\\swidth="[^"]+"/i', '$1', $svg);
        $svg = preg_replace('/(<svg[^>]*)\\sheight="[^"]+"/i', '$1', $svg);
        $svg = preg_replace('/(<svg[^>]*style=")([^\\"]*?)\\bwidth\\s*:\\s*[^;]+;?/i', '$1$2', $svg);
        $svg = preg_replace('/(<svg[^>]*style=")([^\\"]*?)\\bheight\\s*:\\s*[^;]+;?/i', '$1$2', $svg);
        $svg = preg_replace('/<svg([^>]*)>/', '<svg$1 preserveAspectRatio="xMidYMid meet" style="color:' . htmlspecialchars($color, ENT_QUOTES) . ';fill:' . htmlspecialchars($color, ENT_QUOTES) . ';stroke:' . htmlspecialchars($color, ENT_QUOTES) . '">', $svg, 1);
        return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;color:' . htmlspecialchars($color, ENT_QUOTES) . ';">' . $svg . '</span>';
    }

    if (stripos($raw, 'thesvg:') === 0) {
        $rest = trim(substr($raw, 7));
        $slug = $rest;
        $variant = 'default';
        if (strpos($rest, ':') !== false) {
            [$slug, $variant] = explode(':', $rest, 2);
        } elseif (strpos($rest, '/') !== false) {
            [$slug, $variant] = explode('/', $rest, 2);
        }
        $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)$slug) ?: '';
        $variant = preg_replace('/[^a-z0-9]/i', '', (string)$variant) ?: 'default';
        if ($slug === '') {
            // #region debug-point E:php-icon-thesvg-empty
            mh_nav_dbg_send('E', 'navigator.php:mh_nav_icon_html', '[DEBUG] thesvg empty slug', ['size' => $sizePx, 'raw' => substr($raw, 0, 60)]);
            // #endregion
            $u = mh_nav_default_icon_url();
            return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;"><img src="' . htmlspecialchars($u, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:block"></span>';
        }
        $primary = mh_nav_thesvg_primary_url($slug, $variant);
        $fallback = mh_nav_thesvg_fallback_url($slug, $variant);
        // #region debug-point B:php-icon-thesvg
        mh_nav_dbg_send('B', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon thesvg', ['size' => $sizePx, 'slug' => $slug, 'variant' => $variant]);
        // #endregion
        $invert = ($variant === 'mono' || $variant === 'dark') ? 'filter:invert(1);' : '';
        return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;"><img src="' . htmlspecialchars($primary, ENT_QUOTES) . '" data-thesvg-primary="' . htmlspecialchars($primary, ENT_QUOTES) . '" data-thesvg-fallback="' . htmlspecialchars($fallback, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:block;' . $invert . '"></span>';
    }

    $phName = mh_nav_phosphor_name_from_any($raw);
    if ($phName !== '') {
        // #region debug-point A:php-icon-ph
        mh_nav_dbg_send('A', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon phosphor', ['size' => $sizePx, 'name' => $phName, 'raw' => substr($raw, 0, 60)]);
        // #endregion
        return '<span class="icon" aria-hidden="true" data-ph-name="' . htmlspecialchars($phName, ENT_QUOTES) . '" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;color:' . htmlspecialchars($color, ENT_QUOTES) . ';"><i class="ph ph-' . htmlspecialchars($phName, ENT_QUOTES) . '"></i></span>';
    }

    $slug = preg_replace('/[^a-z0-9\\-]/i', '', $raw) ?: '';
    if ($slug !== '') {
        $primary = mh_nav_thesvg_primary_url($slug, 'default');
        $fallback = mh_nav_thesvg_fallback_url($slug, 'default');
        // #region debug-point B:php-icon-slug-thesvg
        mh_nav_dbg_send('B', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon slug->thesvg', ['size' => $sizePx, 'slug' => $slug, 'raw' => substr($raw, 0, 60)]);
        // #endregion
        return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;"><img src="' . htmlspecialchars($primary, ENT_QUOTES) . '" data-thesvg-primary="' . htmlspecialchars($primary, ENT_QUOTES) . '" data-thesvg-fallback="' . htmlspecialchars($fallback, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:block"></span>';
    }

    // #region debug-point E:php-icon-default-fallback
    mh_nav_dbg_send('E', 'navigator.php:mh_nav_icon_html', '[DEBUG] icon default (fallback)', ['size' => $sizePx, 'raw' => substr($raw, 0, 80)]);
    // #endregion
    $u = mh_nav_default_icon_url();
    return '<span class="icon" aria-hidden="true" style="font-size:' . (int)$sizePx . 'px;margin-right:6px;"><img src="' . htmlspecialchars($u, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:block"></span>';
}

// Include the Navigation Database Manager
if (file_exists(__DIR__ . "/navigation-database-manager.php")) {
    require_once __DIR__ . "/navigation-database-manager.php";
}

// Handle AJAX requests FIRST before any HTML output
if ($isAjaxRequest) {
    // AGGRESSIVE output buffer cleaning - catch ANY output that might have leaked
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Capture and discard any existing output
    if (ob_get_contents() !== false) {
        ob_clean();
    }
    
    // Suppress ALL PHP error output that could break JSON
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('html_errors', 0);
    ini_set('log_errors', 1);
    
    // Start completely fresh output buffer
    ob_start();
    
    // IMMEDIATELY set JSON headers before any other code runs
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    
    // Enhanced remote server compatibility headers
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Cache-Control');
    header('Access-Control-Max-Age: 3600');
    
    // Force browser cache clearing for remote servers
    header('Vary: Accept-Encoding, User-Agent');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    
    // Enhanced error handler for remote server compatibility
    set_error_handler(function($severity, $message, $file, $line) {
        error_log("Navigator Error: $message in $file on line $line");
        // Suppress all output for AJAX requests
        return true;
    });
    
    // Aggressive output buffer management for remote server compatibility
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Enforce page-level permission before processing any action
    try {
        // Permission Manager removed as per user request
        // $permManagerPath = dirname(__DIR__, 2) . '/gear/settings/classes/PagePermissionManager.php';
        // if (file_exists($permManagerPath)) { ... }
    } catch (Exception $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    
    // Clean any accumulated output before processing
    $preOutput = ob_get_clean();
    if (!empty($preOutput)) {
        error_log("Navigator AJAX: Cleaned pre-output: " . substr($preOutput, 0, 200));
    }
    ob_start();
    
    // Include database manager for AJAX requests - capture any output it might generate
    ob_start();
    // require_once __DIR__ . "/navigation-database-manager.php"; // Removed to use inline definition
    $includeOutput = ob_get_clean();
    
    // Log any unexpected output from includes
    if (!empty($includeOutput)) {
        error_log("Navigator AJAX: Unexpected output from includes: " . substr($includeOutput, 0, 200));
    }
    
    // Process AJAX request directly with comprehensive error handling
    try {
        processAjaxRequest();
    } catch (Throwable $e) {
        // Clear any existing output that might contain HTML
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Log the full error details
        error_log('Navigator AJAX Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        error_log('Navigator AJAX Stack trace: ' . $e->getTraceAsString());
        
        // Ensure JSON header is set again
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        
        // Return clean JSON error response
        echo json_encode([
            'success' => false,
            'error' => 'Server processing error',
            'detail' => $e->getMessage(),
            'debug' => $_POST['action'] ?? 'unknown_action'
        ], JSON_UNESCAPED_UNICODE);
    }
    
    // Ensure we exit cleanly
    exit;
}
// Set page metadata for header
$pageTitle = 'Menu Navigator - System Management';
$pageDescription = 'Advanced menu navigation system for managing realms and menus';
$pageKeywords = 'menu, navigation, realms, system management';
// Include database manager once (will be used for both AJAX and page rendering)
$db_manager_start = microtime(true);
// Class definition moved to top of file
$db_manager_load_time = (microtime(true) - $db_manager_start) * 1000;

// Enforce page-level permission on normal page load (GET)
$permission_start = microtime(true);
try {
    // Permission Manager removed as per user request
    // $permManagerPath = dirname(__DIR__, 2) . '/gear/settings/classes/PagePermissionManager.php';
    // if (file_exists($permManagerPath)) { ... }
} catch (Exception $e) {
    // Render minimal 403 response and stop further processing
    http_response_code(403);
    echo "{" . "\"success\":false,\"error\":\"" . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\"}";
    exit;
}
$permission_check_time = (microtime(true) - $permission_start) * 1000;
// Initialize the Menu Navigator class
// Function to handle AJAX requests separately
function processAjaxRequest() {
    // Lazy-init navigator to allow early return on cache hits
    $navigator = null;
    $initSuccess = null;
    $initError = null;

    $action = $_POST['action'] ?? '';
    // Handle debug requests without initializing navigator
    if ($action === 'debug_status') {
        echo json_encode([
            'success' => true,
            'data' => [
                'php_version' => PHP_VERSION,
                'current_time' => date('Y-m-d H:i:s'),
                'init_success' => true,
                'init_error' => null
            ]
        ]);
        return;
    }
    
    // Handle cache clearing without initializing navigator
    if ($action === 'clear_cache') {
        if (session_status() !== PHP_SESSION_ACTIVE) { 
            @session_start(); 
        }
        
        // Clear all navigator-related session cache
        $clearedKeys = [];
        foreach ($_SESSION as $key => $value) {
            if (strpos($key, 'realms_') === 0 || strpos($key, 'menus_') === 0 || strpos($key, 'social_') === 0) {
                unset($_SESSION[$key]);
                $clearedKeys[] = $key;
            }
        }
        
        @session_write_close();
        
        // Also clear hamburger menu file caches
        $paths = cue_autoload('paths');
        $hamburgerCacheFiles = array_filter([
            $paths->getSecureFilePath('cache/hamburger-menu-cache.json'),
            $paths->getSecureFilePath('cache/hamburger-structured-menu-cache.json'),
            $paths->getSecureFilePath('cache/hamburger-social-cache.json')
        ]);
        
        foreach ($hamburgerCacheFiles as $cacheFile) {
            if ($paths->validateSecurePath($cacheFile, getDataPath()) && file_exists($cacheFile)) {
                unlink($cacheFile);
                $clearedKeys[] = 'hamburger_file_cache: ' . basename($cacheFile);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Cache cleared successfully',
            'cleared_keys' => $clearedKeys
        ]);
        return;
    }

    // Shared initialization: ensure navigator exists for actions that need DB work
    // Keep 'get_realms' excluded to preserve its early-return cache path
    $actionsRequiringNavigator = [
        'switch_realm',
        'get_realm_info',
        'generate_realm_id',
        'validate_realm_id',
        'create_realm',
        'update_realm',
        'delete_realm',
        'get_menus',
        'get_menu_html',
        'create_menu',
        'update_menu',
        'delete_menu',
        'add_submenu',
        'update_submenu',
        'delete_submenu',
        'get_social_links',
        // Social link/connect actions (support both naming variants)
        'create_social_link',
        'update_social_link',
        'delete_social_link',
        'get_social_connects',
        'create_social_connect',
        'update_social_connect',
        'delete_social_connect',
        'reorder_realm',
        'reorder_menus',
        'reorder_submenu'
    ];
    if (in_array($action, $actionsRequiringNavigator, true) && $navigator === null) {
        try {
            $navigator = new NavigationDatabaseManager();
            $initSuccess = true;
            $initError = null;
        } catch (Exception $e) {
            $initSuccess = false;
            $initError = $e->getMessage();
            error_log('MenuNavigator initialization failed: ' . $e->getMessage());
        }
        if (!$initSuccess) {
            echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $initError]);
            return;
        }
    }

    $widgetsConfig = [];
    try {
        $paths = cue_autoload('paths');
        $cfgPath = $paths->getSecureFilePath('widgets/config.json');
        if ($cfgPath && $paths->validateSecurePath($cfgPath, getDataPath()) && file_exists($cfgPath)) {
            $raw = json_decode(file_get_contents($cfgPath), true) ?: [];
            if (isset($raw['K::WidgetUI::Configuration']) && is_array($raw['K::WidgetUI::Configuration'])) {
                $first = reset($raw['K::WidgetUI::Configuration']);
                $widgetsConfig = [
                    'icon_size' => (int)($first['wgt_icon_size'] ?? 18),
                    'icon_color' => (string)($first['wgt_icon_color'] ?? '#00ffff'),
                    'icon_hover_color' => (string)($first['wgt_icon_hover_color'] ?? '#ffffff'),
                    'default_set' => (string)($first['wgt_icon_default_set'] ?? 'fontawesome'),
                    'mult_realms' => (float)($first['wgt_icon_size_multiplier_realms'] ?? 1.0),
                    'mult_menus' => (float)($first['wgt_icon_size_multiplier_menus'] ?? 1.0),
                    'mult_submenus' => (float)($first['wgt_icon_size_multiplier_submenus'] ?? 1.0)
                ];
            }
        }
    } catch (Exception $e) {}
    try {
        switch ($action) {
            case 'dbg_event':
                $evtRaw = isset($_POST['event']) ? (string)$_POST['event'] : '';
                $evt = [];
                if ($evtRaw !== '') {
                    $decoded = json_decode($evtRaw, true);
                    if (is_array($decoded)) $evt = $decoded;
                }
                mh_nav_dbg_send(
                    (string)($evt['hypothesisId'] ?? 'C'),
                    (string)($evt['location'] ?? 'navigator.php:dbg_event'),
                    (string)($evt['msg'] ?? '[DEBUG] dbg_event'),
                    is_array($evt['data'] ?? null) ? (array)$evt['data'] : ['raw' => substr($evtRaw, 0, 200)]
                );
                echo json_encode(['success' => true]);
                return;
            case 'get_realms':
                // Session cache with 2 minute TTL; return before any DB work
                if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                $cacheKey = 'realms_data_v2';
                $cacheTimeKey = 'realms_data_time_v2';
                $ttl = 120; // seconds
                $cacheStart = microtime(true);
                $forceRefresh = (isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1') || isset($_POST['cache_bust']);
                if (!$forceRefresh && isset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]) && (time() - $_SESSION[$cacheTimeKey]) < $ttl) {
                    $cached = $_SESSION[$cacheKey];
                    @session_write_close();
                    $cacheTimeMs = (microtime(true) - $cacheStart) * 1000;
                    error_log("PROFILE get_realms: served from session cache in {$cacheTimeMs}ms");
                    echo $cached;
                    return;
                }

                // Cache miss: initialize navigator and fetch
                $initStart = microtime(true);
                try {
                    $navigator = new NavigationDatabaseManager();
                    $initSuccess = true;
                    $initError = null;
                } catch (Exception $e) {
                    $initSuccess = false;
                    $initError = $e->getMessage();
                    error_log('MenuNavigator initialization failed: ' . $e->getMessage());
                }
                if (!$initSuccess) {
                    echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $initError]);
                    return;
                }
                $initTimeMs = (microtime(true) - $initStart) * 1000;
                error_log("PROFILE get_realms: navigator init took {$initTimeMs}ms");

                $fetchStart = microtime(true);
                try {
                    $realms = $navigator->getRealms();
                    error_log("DEBUG get_realms: getRealms() returned type: " . gettype($realms));
                    error_log("DEBUG get_realms: getRealms() data: " . (is_object($realms) || is_array($realms) ? json_encode($realms) : $realms));
                } catch (Exception $e) {
                    error_log("ERROR get_realms: getRealms() failed: " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Failed to fetch realms: ' . $e->getMessage()]);
                    return;
                }
                $fetchTimeMs = (microtime(true) - $fetchStart) * 1000;
                
                // Ensure data is always an object (not array) for JavaScript compatibility
                if (empty($realms)) { 
                    error_log("DEBUG get_realms: realms is empty, creating empty object");
                    $realms = new stdClass(); 
                }
                
                $responseData = ['success' => true, 'data' => $realms, 'count' => is_object($realms) ? count((array)$realms) : count($realms)];
                $response = json_encode($responseData);
                
                if ($response === false) {
                    error_log("ERROR get_realms: JSON encoding failed: " . json_last_error_msg());
                    echo json_encode(['success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg()]);
                    return;
                }
                
                // Cache the response
                $_SESSION[$cacheKey] = $response;
                $_SESSION[$cacheTimeKey] = time();
                @session_write_close();
                error_log("PROFILE get_realms: fetch took {$fetchTimeMs}ms; response size: " . strlen($response) . "; realm count: " . $responseData['count']);
                echo $response;
                return;
            case 'switch_realm':
                // Initialize navigator for actions requiring DB
                try {
                    $navigator = new NavigationDatabaseManager();
                    $initSuccess = true;
                    $initError = null;
                } catch (Exception $e) {
                    $initSuccess = false;
                    $initError = $e->getMessage();
                    error_log('MenuNavigator initialization failed: ' . $e->getMessage());
                }
                if (!$initSuccess) {
                    echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $initError]);
                    return;
                }
                $startTime = microtime(true);
                
                // Sanitize and validate input
                $targetRealm = isset($_POST['realm_id']) ? trim($_POST['realm_id']) : '';
                if ($targetRealm === '') {
                    echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                    break;
                }
                
                // Validate that the realm exists using returned structure (objects with 'id' property)
                try {
                    $realmLoadStart = microtime(true);
                    $realms = $navigator->getRealms();
                    $realmLoadTime = (microtime(true) - $realmLoadStart) * 1000;
                    
                    if ($realmLoadTime > 1000) {
                        error_log("switch_realm: getRealms() took {$realmLoadTime}ms");
                    }
                } catch (Exception $e) {
                    $totalTime = (microtime(true) - $startTime) * 1000;
                    error_log("switch_realm: getRealms() failed after {$totalTime}ms - " . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                    break;
                }
                $realmExists = false;
                if (!$realmExists) {
                    foreach ($realms as $realm) {
                        $rid = is_object($realm) ? ($realm->id ?? null) : (is_array($realm) ? ($realm['id'] ?? null) : null);
                        if ($rid !== null && $rid === $targetRealm) {
                            $realmExists = true;
                            break;
                        }
                    }
                }
                if (!$realmExists) {
                    echo json_encode(['success' => false, 'error' => 'Invalid realm specified']);
                    break;
                }
                $totalTime = (microtime(true) - $startTime) * 1000;
                if ($totalTime > 5000) {
                    error_log("switch_realm: Total operation took {$totalTime}ms for realm {$targetRealm}");
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => ['realm_id' => $targetRealm],
                    'message' => 'Realm switched successfully',
                    'debug' => ['execution_time_ms' => round($totalTime, 2)]
                ]);
                break;
            case 'get_realm_info':
                $realmId = $_POST['realm_id'] ?? null;
                if (!$realmId) {
                    echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                    break;
                }
                try {
                    $realms = $navigator->getRealms();
                    $realm = null;
                    foreach ($realms as $r) {
                        $rid = is_object($r) ? ($r->id ?? null) : (is_array($r) ? ($r['id'] ?? null) : null);
                        if ($rid !== null && $rid === $realmId) { $realm = $r; break; }
                    }
                    if (!$realm) {
                        echo json_encode(['success' => false, 'error' => 'Realm not found']);
                        break;
                    }
                    echo json_encode(['success' => true, 'realm' => $realm]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to get realm info: ' . $e->getMessage()]);
                }
                break;
            case 'generate_realm_id':
                try {
                    $name = $_POST['name'] ?? '';
                    if (empty($name)) {
                        echo json_encode(['success' => false, 'error' => 'Name is required']);
                        break;
                    }
                    $generatedId = $navigator->generateRealmId($name);
                    if ($generatedId === false) {
                        echo json_encode(['success' => false, 'error' => 'Failed to generate realm ID']);
                        break;
                    }
                    echo json_encode(['success' => true, 'data' => $generatedId]);
                } catch (Exception $e) {
                    error_log('GENERATE REALM ID ERROR: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Failed to generate realm ID']);
                }
                break;
            case 'validate_realm_id':
                $id = $_POST['id'] ?? '';
                if (empty($id)) {
                    echo json_encode(['success' => false, 'error' => 'ID is required']);
                    break;
                }
                // Validate format
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $id)) {
                    echo json_encode(['success' => false, 'error' => 'ID can only contain letters, numbers, and underscores']);
                    break;
                }
                // Check if ID already exists
                $realms = $navigator->getRealms();
                // getRealms returns an object keyed by realm IDs; use property access
                $exists = isset($realms->$id);
                echo json_encode(['success' => true, 'exists' => $exists]);
                break;
            case 'create_realm':
                try {
                    // Validate realm ID format
                    if (empty($_POST['id'])) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                        break;
                    }
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $_POST['id'])) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID can only contain letters, numbers, and underscores']);
                        break;
                    }
                    // Check if realm ID already exists
                    $existingRealms = $navigator->getRealms();
                    // getRealms returns stdClass keyed by IDs; use property access
                    if (isset($existingRealms->{$_POST['id']})) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID already exists. Please choose another.']);
                        break;
                    }
                    error_log('CREATE REALM - POST data: ' . print_r($_POST, true));
                    // Handle pages parameter if it's JSON string
                    if (isset($_POST['pages']) && is_string($_POST['pages'])) {
                        $_POST['pages'] = json_decode($_POST['pages'], true) ?? [];
                    }
                    $result = $navigator->createRealm($_POST);
                    // Invalidate realms cache so UI reflects the new realm immediately
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    unset($_SESSION['realms_data'], $_SESSION['realms_data_time']);
                    @session_write_close();
                    echo json_encode(['success' => true, 'data' => $result]);
                } catch (Exception $e) {
                    error_log('CREATE REALM ERROR: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'update_realm':
                try {
                    if (empty($_POST['realm_id'])) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                        break;
                    }
                    // Handle pages parameter if it's JSON string
                    if (isset($_POST['pages']) && is_string($_POST['pages'])) {
                        $_POST['pages'] = json_decode($_POST['pages'], true) ?? [];
                    }
                    $result = $navigator->updateRealm($_POST['realm_id'], $_POST);
                    // Invalidate realms cache so UI reflects updates immediately
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    unset($_SESSION['realms_data'], $_SESSION['realms_data_time']);
                    @session_write_close();
                    echo json_encode(['success' => true, 'data' => $result]);
                } catch (Exception $e) {
                    error_log('UPDATE REALM ERROR: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'delete_realm':
                try {
                    if (empty($_POST['realm_id'])) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                        break;
                    }
                    error_log('DELETE REALM - Attempting to delete realm: ' . $_POST['realm_id']);
                    $result = $navigator->deleteRealm($_POST['realm_id']);
                    error_log('DELETE REALM - Delete operation completed for realm: ' . $_POST['realm_id']);
                    // Invalidate realms cache so UI reflects deletion immediately
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    unset($_SESSION['realms_data'], $_SESSION['realms_data_time']);
                    @session_write_close();
                    echo json_encode(['success' => true, 'message' => 'Realm deleted successfully']);
                } catch (Exception $e) {
                    error_log('DELETE REALM ERROR: ' . $e->getMessage());
                    error_log('DELETE REALM ERROR STACK: ' . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'get_menus':
                // Initialize navigator for menu operations
                try {
                    $navigator = new NavigationDatabaseManager();
                    $initSuccess = true;
                    $initError = null;
                } catch (Exception $e) {
                    $initSuccess = false;
                    $initError = $e->getMessage();
                    error_log('MenuNavigator initialization failed: ' . $e->getMessage());
                }
                if (!$initSuccess) {
                    echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $initError]);
                    return;
                }
                // Add short-lived caching to reduce repeated DB work and mitigate timeouts
                $realmId = $_POST['realm_id'] ?? 'guest';
                
                // Debug output
                error_log("GET_MENUS: Requested realm_id: " . $realmId);
                
                // Use session cache (30s TTL) to avoid redundant menu generation under load
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                
                // RBAC-aware cache key
                $userRole = isset($_SESSION['mh_auth_role']) ? strtolower(trim($_SESSION['mh_auth_role'])) : 'guest';
                // Force cache bust if user is KripzMaster to ensure they see everything
                if ($userRole === 'kripzmasters') {
                    $cacheKey = "menus_json_{$realmId}_v3_{$userRole}_" . time(); // Always unique
                } else {
                    $cacheKey = "menus_json_{$realmId}_v3_{$userRole}";
                }
                $cacheTimeKey = "menus_json_time_{$realmId}_v3_{$userRole}";
                
                $skipCache = false;
                if (isset($_POST['_t']) || (isset($_POST['nocache']) && (string)$_POST['nocache'] === '1')) {
                    $skipCache = true;
                }
                if (isset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]) &&
                    (time() - $_SESSION[$cacheTimeKey]) < 30 && !$skipCache) {
                    echo $_SESSION[$cacheKey];
                    // Release the session lock ASAP
                    @session_write_close();
                    break;
                }
                $start = microtime(true);
                error_log('PROFILE get_menus: starting cold path');
                $menus = $navigator->getMenus($realmId);
                
                // FORCE JSON ARRAY FOR MENUS - Ensure menus is a numerically indexed array
                if (is_object($menus)) {
                    $menus = (array)$menus;
                }
                if (!is_array($menus)) {
                    $menus = [];
                }
                $menus = array_values($menus); // Reset keys to 0,1,2...
                
                $afterFetch = microtime(true);
                $encodeStart = microtime(true);
                $response = json_encode([
                    'success' => true,
                    'data' => $menus,
                    'execution_time' => round((microtime(true) - $start) * 1000, 2),
                    'cache_status' => 'fresh'
                ]);
                $encodeTimeMs = (microtime(true) - $encodeStart) * 1000;
                $fetchTimeMs = ($afterFetch - $start) * 1000;
                error_log("PROFILE get_menus: fetch={$fetchTimeMs}ms, json_encode={$encodeTimeMs}ms, item_count=" . count($menus));
                $_SESSION[$cacheKey] = $response;
                $_SESSION[$cacheTimeKey] = time();
                echo $response;
                @session_write_close();
                break;
            case 'create_menu':
                try {
                    if (empty($_POST['realm_id'])) {
                        echo json_encode(['success' => false, 'error' => 'Realm ID is required']);
                        break;
                    }
                    if (empty($_POST['name'])) {
                        echo json_encode(['success' => false, 'error' => 'Menu name is required']);
                        break;
                    }
                    // Debug: Log the incoming POST data
                    error_log('CREATE MENU - POST data: ' . print_r($_POST, true));
                    // Map form fields to expected format
                    $menuData = $_POST;
                    $menuData['title'] = $_POST['name'] ?? '';
                    $result = $navigator->createMenu($menuData);
                    error_log('CREATE MENU - Menu created successfully with ID: ' . ($result['id'] ?? 'unknown'));
                    // Invalidate menus cache for realm so UI reflects new menu
                    $realmId = $_POST['realm_id'] ?? null;
                    if ($realmId) {
                        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                        $cacheKey = "menus_json_{$realmId}";
                        $cacheTimeKey = "menus_json_time_{$realmId}";
                        unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                        @session_write_close();
                    }
                    echo json_encode(['success' => true, 'data' => $result, 'message' => 'Menu created successfully']);
                } catch (Exception $e) {
                    error_log('CREATE MENU ERROR: ' . $e->getMessage());
                    error_log('CREATE MENU ERROR STACK: ' . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'update_menu':
                try {
                    if (empty($_POST['menu_id'])) {
                        echo json_encode(['success' => false, 'error' => 'Menu ID is required']);
                        break;
                    }
                    if (empty($_POST['name'])) {
                        echo json_encode(['success' => false, 'error' => 'Menu name is required']);
                        break;
                    }
                    // Map form fields to expected format
                    $menuData = $_POST;
                    $menuData['title'] = $_POST['name'] ?? '';
                    error_log('UPDATE MENU - POST data: ' . print_r($_POST, true));
                    $result = $navigator->updateMenu($_POST['menu_id'], $menuData);
                    error_log('UPDATE MENU - Menu updated successfully: ' . $_POST['menu_id']);
                    // Invalidate menus cache for realm so UI reflects menu update
                    $realmId = $_POST['realm_id'] ?? null;
                    if ($realmId) {
                        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                        $cacheKey = "menus_json_{$realmId}";
                        $cacheTimeKey = "menus_json_time_{$realmId}";
                        unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                        @session_write_close();
                    }
                    echo json_encode(['success' => true, 'data' => $result, 'message' => 'Menu updated successfully']);
                } catch (Exception $e) {
                    error_log('UPDATE MENU ERROR: ' . $e->getMessage());
                    error_log('UPDATE MENU ERROR STACK: ' . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'delete_menu':
                try {
                    if (empty($_POST['menu_id'])) {
                        echo json_encode(['success' => false, 'error' => 'Menu ID is required']);
                        break;
                    }
                    error_log('DELETE MENU - Attempting to delete menu: ' . $_POST['menu_id']);
                    $navigator->deleteMenu($_POST['menu_id']);
                    error_log('DELETE MENU - Menu deleted successfully: ' . $_POST['menu_id']);
                    // Attempt to invalidate menus cache for current realm
                    $realmId = $_POST['realm_id'] ?? null;
                    if ($realmId) {
                        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                        $cacheKey = "menus_json_{$realmId}";
                        $cacheTimeKey = "menus_json_time_{$realmId}";
                        unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                        @session_write_close();
                    }
                    echo json_encode(['success' => true, 'message' => 'Menu deleted successfully']);
                } catch (Exception $e) {
                    error_log('DELETE MENU ERROR: ' . $e->getMessage());
                    error_log('DELETE MENU ERROR STACK: ' . $e->getTraceAsString());
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'add_submenu':
                // Debug: log the received data
                error_log('ADD SUBMENU - POST data: ' . print_r($_POST, true));
                // Check if the menu ID exists in the database
                if (!empty($_POST['menu_id'])) {
                    $menuExists = $navigator->menuExists($_POST['menu_id']);
                    error_log("Menu ID {$_POST['menu_id']} exists: " . ($menuExists ? 'YES' : 'NO'));
                    if (!$menuExists) {
                        echo json_encode(['success' => false, 'error' => 'Parent menu not found. Please refresh the page and try again.']);
                        break;
                    }
                }
                // Map form fields to expected format
                $submenuData = $_POST;
                $submenuData['title'] = $_POST['name'] ?? '';
                $submenuData['parent_id'] = $_POST['menu_id']; // Set parent menu ID
                $result = $navigator->addSubmenu($submenuData);
                // Invalidate menus cache for realm so UI reflects new submenu
                $realmId = $_POST['realm_id'] ?? null;
                if ($realmId) {
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    $cacheKey = "menus_json_{$realmId}";
                    $cacheTimeKey = "menus_json_time_{$realmId}";
                    unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                    @session_write_close();
                }
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'update_submenu':
                // Map form fields to expected format
                $submenuData = $_POST;
                $submenuData['title'] = $_POST['name'] ?? '';
                $submenuData['parent_id'] = $_POST['menu_id']; // Set parent menu ID
                error_log('UPDATE SUBMENU - POST data: ' . print_r($_POST, true));
                $result = $navigator->updateSubmenu($_POST['submenu_id'], $submenuData);
                // Invalidate menus cache for realm so UI reflects submenu update
                $realmId = $_POST['realm_id'] ?? null;
                if ($realmId) {
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    $cacheKey = "menus_json_{$realmId}";
                    $cacheTimeKey = "menus_json_time_{$realmId}";
                    unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                    @session_write_close();
                }
                echo json_encode(['success' => true, 'data' => $result]);
                break;
        error_log("DEBUG: delete_submenu called with POST: " . print_r($_POST, true));
            case 'delete_submenu':
                $navigator->deleteSubmenu($_POST['submenu_id']);
                // Invalidate menus cache for realm so UI reflects deletion
                $realmId = $_POST['realm_id'] ?? null;
                if ($realmId) {
                    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                    $cacheKey = "menus_json_{$realmId}";
                    $cacheTimeKey = "menus_json_time_{$realmId}";
                    unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                    @session_write_close();
                }
                echo json_encode(['success' => true]);
                break;
            case 'get_pages':
                // Ensure navigator is initialized before use
                if (!$navigator) {
                    try {
                        $navigator = new NavigationDatabaseManager();
                        $initSuccess = true;
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'error' => 'System initialization failed: ' . $e->getMessage()]);
                        break;
                    }
                }
                try {
                    $pages = $navigator->getAvailablePages();
                    echo json_encode(['success' => true, 'data' => $pages]);
                } catch (Exception $e) {
                    error_log('GET PAGES ERROR: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Failed to load pages: ' . $e->getMessage()]);
                }
                break;
            case 'get_menu_html':
                try {
                    // Get realm_id from POST data, default to guest
                    $realmId = $_POST['realm_id'] ?? 'guest';
                    // Get menus directly from NavigationDatabaseManager
                    $menus = $navigator->getMenus($realmId);
                    $iconColor = isset($widgetsConfig['icon_color']) ? $widgetsConfig['icon_color'] : '#00ffff';
                    $iconBase = isset($widgetsConfig['icon_size']) ? (int)$widgetsConfig['icon_size'] : 18;
                    $mulMenus = isset($widgetsConfig['mult_menus']) ? (float)$widgetsConfig['mult_menus'] : 1.0;
                    $mulSubs = isset($widgetsConfig['mult_submenus']) ? (float)$widgetsConfig['mult_submenus'] : 0.85;
                    $iconSizeMenu = max(12, (int)round($iconBase * $mulMenus));
                    $iconSizeSub = max(10, (int)round($iconBase * $mulSubs));
                    // Generate hamburger menu HTML matching sidebar structure
                    $html = '';
                    if (!empty($menus)) {
                        foreach ($menus as $menu) {
                            $html .= '<li class="menu-item-container">';
                            $html .= '<div class="menu-item-wrapper">';
                            $menuUrl = is_string($menu->url) ? trim($menu->url) : '';
                            if (!empty($menu->submenu)) { $menuUrl = ''; }
                            $html .= '<a href="' . htmlspecialchars($menuUrl) . '" class="nav-link"' . (empty($menuUrl) ? ' onclick="return false;"' : '') . '>';
                            $html .= mh_nav_icon_html($menu->icon ?? '', $iconSizeMenu, (string)$iconColor);
                            
                            $html .= "<span>" . htmlspecialchars($menu->title) . "</span>";
                            $html .= "</a>";
                            // Add dropdown arrow if submenus exist - outside the anchor
                            if (!empty($menu->submenu)) {
                                $html .= '<button class="submenu-toggle" type="button" aria-label="Toggle submenu">';
                                $html .= '<i class="ph ph-caret-right" aria-hidden="true"></i>';
                                $html .= '</button>';
                            }
                            $html .= "</div>";
                            // Add submenus if they exist
                            if (!empty($menu->submenu)) {
                                $html .= '<ul class="submenu">';
                                foreach ($menu->submenu as $submenu) {
                                    if (!empty($submenu->title) && trim($submenu->title) !== "") {
                                        $html .= '<li>';
                                        $subUrl = is_string($submenu->url) ? trim($submenu->url) : '';
                                        $html .= '<a href="' . htmlspecialchars($subUrl) . '" class="nav-link submenu-link"' . ($subUrl === '' ? ' onclick="return false;"' : '') . '>';
                                        
                                        $html .= mh_nav_icon_html($submenu->icon ?? '', $iconSizeSub, (string)$iconColor);
                                        
                                        $html .= '<span>' . htmlspecialchars($submenu->title) . '</span>';
                                        $html .= '</a>';
                                        $html .= '</li>';
                                    }
                                }
                                $html .= '</ul>';
                            }
                            $html .= '</li>';
                        }
                    } else {
                        // Show a message for no menus
                        $html = '<li><div class="no-menus" style="padding: 15px; text-align: center; color: #00d4ff; font-style: italic;">No menus available for this realm</div></li>';
                    }
                    echo json_encode([
                        'success' => true,
                        'html' => $html,
                        'realm_id' => $realmId,
                        'menus_count' => count($menus)
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'realm_id' => $_POST['realm_id'] ?? 'unknown'
                    ]);
                }
                break;
            case 'reorder_menus':
                $realm_id = $_POST['realm_id'] ?? '';
                $menu_id = $_POST['menu_id'] ?? '';
                $new_position = intval($_POST['new_position'] ?? 0);
                if (empty($realm_id) || empty($menu_id) || $new_position <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid parameters for menu reordering']);
                    return;
                }
                try {
                    // Get all menus for the realm to build proper order array
                    $allMenus = $navigator->getMenus($realm_id);
                    $menuOrders = [];
                    // Build new order array
                    $targetInserted = false;
                    $currentPosition = 1;
                    foreach ($allMenus as $menu) {
                        if ($menu->id === $menu_id) {
                            // Skip the menu being moved
                            continue;
                        }
                        // Insert the moved menu at the new position
                        if ($currentPosition === $new_position && !$targetInserted) {
                            $menuOrders[$menu_id] = $currentPosition;
                            $currentPosition++;
                            $targetInserted = true;
                        }
                        $menuOrders[$menu->id] = $currentPosition;
                        $currentPosition++;
                    }
                    // If we havent inserted yet, add at the end
                    if (!$targetInserted) {
                        $menuOrders[$menu_id] = $currentPosition;
                    }
                    $result = $navigator->reorderMenus($menuOrders);
                    if ($realm_id) {
                        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                        $cacheKey = "menus_json_{$realm_id}";
                        $cacheTimeKey = "menus_json_time_{$realm_id}";
                        unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                        @session_write_close();
                    }
                    echo json_encode(['success' => (bool)$result, 'realm_id' => $realm_id, 'updated' => array_keys($menuOrders)]);
                    return;
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to reorder menus: ' . $e->getMessage()]);
                    return;
                }
                
            case 'reorder_submenu':
                $realm_id = $_POST['realm_id'] ?? 'guest';
                $menu_id = $_POST['menu_id'] ?? '';
                $submenu_id = $_POST['submenu_id'] ?? '';
                $new_position = intval($_POST['new_position'] ?? 0);
                if (empty($menu_id) || empty($submenu_id) || $new_position <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid parameters for submenu reordering']);
                    return;
                }
                try {
                    $allMenus = $navigator->getMenus($realm_id);
                    $targetMenu = null;
                    $menuIdStr = (string)$menu_id;
                    foreach ($allMenus as $m) { if ((string)$m->id == $menuIdStr) { $targetMenu = $m; break; } }
                    if (!$targetMenu || empty($targetMenu->submenu)) {
                        foreach ($allMenus as $m) {
                            $mt = isset($m->title) ? (string)$m->title : (isset($m->name) ? (string)$m->name : '');
                            if ($mt !== '' && ($mt === $menuIdStr || strtolower($mt) === strtolower($menuIdStr))) { $targetMenu = $m; break; }
                        }
                    }
                    if (!$targetMenu || empty($targetMenu->submenu)) {
                        $allMenus = $navigator->getMenus(null);
                        foreach ($allMenus as $m) { if ((string)$m->id == $menuIdStr) { $targetMenu = $m; break; } }
                        if (!$targetMenu || empty($targetMenu->submenu)) {
                            foreach ($allMenus as $m) {
                                $mt = isset($m->title) ? (string)$m->title : (isset($m->name) ? (string)$m->name : '');
                                if ($mt !== '' && ($mt === $menuIdStr || strtolower($mt) === strtolower($menuIdStr))) { $targetMenu = $m; break; }
                            }
                        }
                    }
                    if (!$targetMenu || empty($targetMenu->submenu)) {
                        echo json_encode(['success' => false, 'error' => 'Target menu or submenus not found']);
                        return;
                    }
                    $ids = [];
                    foreach ($targetMenu->submenu as $sm) { if (!empty($sm->id)) { $ids[] = (string)$sm->id; } }
                    $subIdStr = (string)$submenu_id;
                    $ids = array_values(array_filter($ids, function($id) use ($subIdStr) { return $id !== $subIdStr; }));
                    $maxPos = count($ids) + 1;
                    if ($new_position > $maxPos) { $new_position = $maxPos; }
                    if ($new_position < 1) { $new_position = 1; }
                    array_splice($ids, $new_position - 1, 0, $subIdStr);
                    $submenuOrders = [];
                    $pos = 1;
                    foreach ($ids as $id) { $submenuOrders[$id] = $pos++; }
                    if (empty($submenuOrders[$subIdStr])) {
                        echo json_encode(['success' => false, 'error' => 'Failed to compute new order']);
                        return;
                    }
                    $result = $navigator->reorderSubmenus($submenuOrders);
                    if ($realm_id) {
                        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
                        $cacheKey = "menus_json_{$realm_id}";
                        $cacheTimeKey = "menus_json_time_{$realm_id}";
                        unset($_SESSION[$cacheKey], $_SESSION[$cacheTimeKey]);
                        @session_write_close();
                    }
                    echo json_encode(['success' => (bool)$result, 'realm_id' => $realm_id, 'menu_id' => $menu_id, 'updated' => array_keys($submenuOrders)]);
                    return;
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to reorder submenus: ' . $e->getMessage()]);
                    return;
                }
                
            case 'reorder_realm':
                $realm_id = $_POST['realm_id'] ?? '';
                $new_position = intval($_POST['new_position'] ?? 0);
                if (empty($realm_id) || $new_position <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Invalid parameters for realm reordering']);
                    return;
                }
                try {
                    // Get all realms to build proper order array
                    $allRealms = $navigator->getRealms();
                    $realmOrders = [];
                    // Build new order array
                    $targetInserted = false;
                    $currentPosition = 1;
                    foreach ($allRealms as $realm) {
                        $rid = is_object($realm) ? ($realm->id ?? null) : (is_array($realm) ? ($realm['id'] ?? null) : null);
                        if ($rid !== null && $rid === $realm_id) { continue; }
                        if ($currentPosition === $new_position && !$targetInserted) {
                            $realmOrders[$realm_id] = $currentPosition;
                            $currentPosition++;
                            $targetInserted = true;
                        }
                        if ($rid !== null) { $realmOrders[$rid] = $currentPosition; }
                        $currentPosition++;
                    }
                    // If we haven't inserted yet, add at the end
                    if (!$targetInserted) {
                        $realmOrders[$realm_id] = $currentPosition;
                    }
                    $result = $navigator->reorderRealms($realmOrders);
                    echo json_encode(['success' => (bool)$result, 'updated' => array_keys($realmOrders)]);
                    return;
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => 'Failed to reorder realms: ' . $e->getMessage()]);
                    return;
                }
                
            case 'get_submenu_data':
                try {
                    // Accept both camelCase and snake_case keys from the frontend
                    $menuId = $_POST['menu_id'] ?? ($_POST['menuId'] ?? null);
                    $submenuId = $_POST['submenu_id'] ?? ($_POST['submenuId'] ?? null);
                    $realmId = $_POST['realm_id'] ?? ($_POST['realmId'] ?? 'guest');
                    if (!$menuId) {
                        throw new Exception('Menu ID is required');
                    }
                    if (!$submenuId) {
                        throw new Exception('Submenu ID is required');
                    }
                    // Fetch menus and locate specific submenu
                    $menus = $navigator->getMenus($realmId);
                    $targetMenu = null;
                    foreach ($menus as $menu) {
                        $mid = is_object($menu) ? ($menu->id ?? null) : (is_array($menu) ? ($menu['id'] ?? null) : null);
                        if ($mid !== null && $mid === $menuId) { $targetMenu = $menu; break; }
                    }
                    $submenuList = is_object($targetMenu) ? ($targetMenu->submenu ?? []) : (is_array($targetMenu) ? ($targetMenu['submenu'] ?? []) : []);
                    if (!$targetMenu || empty($submenuList)) {
                        throw new Exception('Menu or submenu list not found');
                    }
                    $found = null;
                    foreach ($submenuList as $submenu) {
                        $sid = is_object($submenu) ? ($submenu->id ?? null) : (is_array($submenu) ? ($submenu['id'] ?? null) : null);
                        if (!empty($sid) && $sid == $submenuId) { $found = $submenu; break; }
                    }
                    if (!$found) {
                        throw new Exception('Submenu not found');
                    }
                    // Normalize to canonical fields expected by the UI
                    $canonical = [
                        'id' => $found->id ?? $submenuId,
                        'title' => $found->title ?? ($found->name ?? ''),
                        'path' => $found->path ?? ($found->url ?? ''),
                        'order' => $found->order ?? ($found->order_index ?? 0),
                        'target' => $found->target ?? '_self'
                    ];
                    echo json_encode(['success' => true, 'submenu' => $canonical, 'menu_id' => $menuId, 'realm_id' => $realmId]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                }
                break;
            case 'get_menu_data':
                try {
                    // Get realm_id from POST data, default to guest
                    $realmId = $_POST['realm_id'] ?? 'guest';
                    // Check cache first - session already started via startSecureSession()
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        if (function_exists('startSecureSession')) {
                            startSecureSession();
                        } else {
                            session_start();
                        }
                    }
                    $cacheKey = "menu_data_{$realmId}";
                    $cacheTimeKey = "menu_data_time_{$realmId}";
                    if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheTimeKey]) &&
                        (time() - $_SESSION[$cacheTimeKey]) < 0) {
                        echo $_SESSION[$cacheKey];
                        break;
                    }
                    // Get menus and social connects from database
                    $menus = $navigator->getMenus($realmId);
                    $socialConnects = $navigator->getSocialConnects($realmId);
                    $iconColor = isset($widgetsConfig['icon_color']) ? (string)$widgetsConfig['icon_color'] : '#00ffff';
                    $iconBase = isset($widgetsConfig['icon_size']) ? (int)$widgetsConfig['icon_size'] : 18;
                    $mulMenus = isset($widgetsConfig['mult_menus']) ? (float)$widgetsConfig['mult_menus'] : 1.0;
                    $iconSizeMenu = max(12, (int)round($iconBase * $mulMenus));
                    // Generate hamburger menu HTML
                    $html = '';
                    if (!empty($menus)) {
                        foreach ($menus as $menu) {
                            $html .= '<li class="menu-item-container">';
                            $html .= '<div class="menu-item-wrapper">';
                            $html .= '<a href="' . htmlspecialchars($menu->url) . '" class="nav-link">';
                            $html .= mh_nav_icon_html($menu->icon ?? '', $iconSizeMenu, (string)$iconColor);
                            
                            $html .= "<span>" . htmlspecialchars($menu->title) . "</span>";
                            $html .= "</a>";
                            // Add dropdown arrow if submenus exist - outside the anchor
                            if (!empty($menu->submenu)) {
                                $html .= '<button class="submenu-toggle" type="button" aria-label="Toggle submenu">';
                                $html .= '<i class="ph ph-caret-right" aria-hidden="true"></i>';
                                $html .= '</button>';
                            }
                            $html .= "</div>";
                            // Add lazy loading placeholder for submenus
                            if (!empty($menu->submenu)) {
                                $html .= '<ul class="submenu lazy-submenu" data-menu-id="' . htmlspecialchars($menu->id) . '">';
                                $html .= '<li class="submenu-loader" style="padding: 10px; text-align: center; color: #00d4ff; font-size: 0.8rem;">Click to load submenus...</li>';
                                $html .= '</ul>';
                            }
                            $html .= '</li>';
                        }
                    } else {
                        $html = '<li><div class="no-menus" style="padding: 15px; text-align: center; color: #00d4ff; font-style: italic;">No menus available for this realm</div></li>';
                    }
                    $response = json_encode([
                        'success' => true,
                        'html' => $html,
                        'social_connects' => $socialConnects,
                        'realm_id' => $realmId,
                        'menus_count' => count($menus),
                        'cached' => false,
                        'timestamp' => time()
                    ]);
                    // Cache the response
                    $_SESSION[$cacheKey] = $response;
                    $_SESSION[$cacheTimeKey] = time();
                    echo $response;
                } catch (Exception $e) {
                    echo json_encode([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'realm_id' => $_POST['realm_id'] ?? 'unknown'
                    ]);
                }
                break;
            case 'get_social_connects':
                $realmId = $_POST['realm_id'] ?? null;
                $socialConnects = $navigator->getSocialConnects($realmId);
                echo json_encode(['success' => true, 'data' => $socialConnects]);
                break;
            case 'get_social_connect':
                $id = $_POST['id'] ?? null;
                $realmId = $_POST['realm_id'] ?? null;
                if (!$id) {
                    echo json_encode(['success' => false, 'error' => 'Missing id']);
                    break;
                }
                $social = $navigator->getSocialConnectById($id, $realmId);
                if ($social) {
                    echo json_encode(['success' => true, 'data' => $social]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Social link not found']);
                }
                break;
            case 'create_social_connect':
                $result = $navigator->createSocialConnect($_POST);
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'update_social_connect':
                $result = $navigator->updateSocialConnect($_POST['id'], $_POST);
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'delete_social_connect':
                $navigator->deleteSocialConnect($_POST['id']);
                echo json_encode(['success' => true]);
                break;
            case 'get_available_pages':
                try {
                    $pages = $navigator->getAvailablePages();
                    echo json_encode(['success' => true, 'data' => $pages]);
                } catch (Exception $e) {
                    error_log('GET AVAILABLE PAGES ERROR: ' . $e->getMessage());
                    echo json_encode(['success' => false, 'error' => 'Failed to load available pages: ' . $e->getMessage()]);
                }
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $_POST['action']]);
        }
    } catch (Exception $e) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    
    // Enhanced output cleaning for remote server compatibility
    $output = '';
    while (ob_get_level()) {
        $buffer = ob_get_clean();
        $output .= $buffer;
    }
    
    // Aggressive cleaning for remote servers
    $output = trim($output);
    
    // Remove any BOM (Byte Order Mark) that might be added by remote servers
    $output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
    
    // Remove any HTML tags or script content that might be injected
    $output = preg_replace('/<[^>]*>/', '', $output);
    
    // Remove any non-printable characters except newlines and tabs
    $output = preg_replace('/[^\x20-\x7E\x0A\x0D\x09]/', '', $output);
    
    // Find JSON content more aggressively
    $patterns = [
        '/(\{.*\})$/s',     // Match last JSON object
        '/(\[.*\])$/s',     // Match last JSON array
        '/.*(\{.*\})/s',    // Match any JSON object
        '/.*(\[.*\])/s'     // Match any JSON array
    ];
    
    $json_found = false;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $output, $matches)) {
            $json_candidate = trim($matches[1]);
            $test_decode = json_decode($json_candidate);
            if ($test_decode !== null || json_last_error() === JSON_ERROR_NONE) {
                $output = $json_candidate;
                $json_found = true;
                break;
            }
        }
    }
    
    // If no valid JSON found, create error response
    if (!$json_found || empty($output)) {
        error_log("Navigator AJAX: No valid JSON found in output, creating error response");
        $output = json_encode(['success' => false, 'error' => 'Invalid server response']);
    }
    
    // Final validation
    $final_test = json_decode($output);
    if ($final_test === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("Navigator AJAX: Final JSON validation failed: " . json_last_error_msg());
        $output = json_encode(['success' => false, 'error' => 'JSON validation failed']);
    }
    
    // Ensure proper headers for remote servers
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    
    // Restore error handler and output clean JSON
    restore_error_handler();
    echo $output;
}
// Initialize the navigator once for the page (database manager already included above)
$navigator_start = microtime(true);
try {
    error_log('NavigationDatabaseManager: Starting constructor...');
    $constructor_start = microtime(true);
    $navigator = new NavigationDatabaseManager();
    $constructor_time = (microtime(true) - $constructor_start) * 1000;
    error_log("NavigationDatabaseManager: Constructor took {$constructor_time}ms");
    
    $initSuccess = true;
    $initError = null;
} catch (Exception $e) {
    $initSuccess = false;
    $initError = $e->getMessage();
    error_log('NavigationDatabaseManager initialization failed: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
}
$navigator_init_time = (microtime(true) - $navigator_start) * 1000;

// Log performance metrics if any operation takes more than 100ms
$total_time = (microtime(true) - $start_time) * 1000;
if ($total_time > 100 || $cue_load_time > 50 || $db_manager_load_time > 50 || $permission_check_time > 50 || $navigator_init_time > 50) {
    error_log("Navigator Performance: Total={$total_time}ms, CUE={$cue_load_time}ms, DBManager={$db_manager_load_time}ms, Permissions={$permission_check_time}ms, Navigator={$navigator_init_time}ms");
}

// Don't load realms during page load - let JavaScript load them asynchronously
// This significantly improves initial page load time
$realms = [];
?>
<?php
// CUE framework is already loaded at the top of this file, no need to reload

// Security helper used by inline script tags
if (!function_exists('cspNonce')) {
    function cspNonce(): string {
        static $nonce = null;
        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
        }
        return $nonce;
    }
}

// Assets base URL (assets are in /templates/assets/)
$assetsUrl = rtrim(getBaseUrl(), '/') . '/templates/assets/';

// Minimal CSS variables without theme includes
$cssVariables = implode('\n', [
    "--primary-color: #00d4ff;",
    "--dark-bg: #0a0a0a;",
    "--darker-bg: #000000;",
    "--light-text: #ffffff;",
    "--gray-text: #a1a1aa;",
    "--border-color: #1f1f1f;",
    "--gradient-primary: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%);",
    "--shadow-card: 0 8px 24px rgba(0, 212, 255, 0.15);",
    "--shadow-primary: 0 8px 32px rgba(0, 212, 255, 0.2);",
    "--font-family-primary: 'Rajdhani', sans-serif;",
]);

// Define CSS version early for preloading
$cssVersion = '75.0.2'; // Use framework version for CSS caching
$jsVersion = '2.1.' . time(); // Force JavaScript reload
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- Icon CSS (phosphor only) -->
    <?php
    require_once dirname(__DIR__, 2) . "/.cue/cue.php";
    echo '<link rel="stylesheet" href="/templates/assets/icons/phosphor/Fonts/regular/style.css">';
    $favUrl = mh_nav_default_icon_url();
    echo '<link rel="icon" type="image/png" href="' . htmlspecialchars($favUrl, ENT_QUOTES) . '">';
    $widgetsConfig = [];
    try {
        $paths = cue_autoload('paths');
        $cfgPath = $paths->getSecureFilePath('widgets/config.json');
        if ($cfgPath && $paths->validateSecurePath($cfgPath, getDataPath()) && file_exists($cfgPath)) {
            $raw = json_decode(file_get_contents($cfgPath), true) ?: [];
            if (isset($raw['K::WidgetUI::Configuration']) && is_array($raw['K::WidgetUI::Configuration'])) {
                $first = reset($raw['K::WidgetUI::Configuration']);
                $widgetsConfig = [
                    'icon_size' => (int)($first['wgt_icon_size'] ?? 18),
                    'icon_color' => (string)($first['wgt_icon_color'] ?? '#00ffff'),
                    'icon_hover_color' => (string)($first['wgt_icon_hover_color'] ?? '#ffffff'),
                    'default_set' => (string)($first['wgt_icon_default_set'] ?? 'fontawesome'),
                    'mult_realms' => (float)($first['wgt_icon_size_multiplier_realms'] ?? 1.0),
                    'mult_menus' => (float)($first['wgt_icon_size_multiplier_menus'] ?? 1.0),
                    'mult_submenus' => (float)($first['wgt_icon_size_multiplier_submenus'] ?? 0.85)
                ];
            }
        }
    } catch (Exception $e) {}
    echo '<script id="widgets-config" type="application/json">' . json_encode($widgetsConfig) . '</script>';
    ?>
    <script>
    (function(){
        try{
            var el = document.getElementById('widgets-config');
            window.WidgetsConfig = el ? JSON.parse(el.textContent||'{}') : {};
        }catch(e){ window.WidgetsConfig = {}; }
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($pageKeywords); ?>">

    

    <!-- Global UI Theme Integration - Dark Theme with Internal CSS Only -->
    <style>
        :root {
            /* Global UI Theme Variables - Dark Theme */
            --primary-color: #00d4ff;
            --secondary-color: #0080ff;
            --accent-color: #ff6600;
            --background-color: #0a0a1a;
            --surface-color: #1a1a2e;
            --text-color: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --success-color: #00ff88;
            --warning-color: #ffaa00;
            --error-color: #ff4444;
        }

        /* Global UI Base Styles */
        * { box-sizing: border-box; }
        
        body {
            font-family: "Rajdhani", "Arial", sans-serif;
            background: linear-gradient(135deg, var(--background-color) 0%, #0d0d24 100%);
            color: var(--text-color);
            margin: 0; padding: 0; min-height: 100vh;
        }

        /* Dropdown Selectors - Dark Blue Background with Cyan Text */

        /* Form Elements with Global UI Theme */
        .form-input, .form-select, .form-textarea, input[type="text"], input[type="email"], input[type="url"], textarea {
            background: rgba(26, 26, 46, 0.8) !important;
            border: 1px solid rgba(0, 212, 255, 0.3) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            padding: 12px 16px !important;
            font-family: "Rajdhani", sans-serif !important;
            transition: all 0.3s ease !important;
            backdrop-filter: blur(10px) !important;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus, input:focus, textarea:focus {
            outline: none !important;
            border-color: #00d4ff !important;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.4) !important;
        }

        /* Modal Enhancements */
        .modal {
            background: rgba(10, 10, 26, 0.9) !important;
            backdrop-filter: blur(15px) !important;
        }

        .modal-content {
            background: rgba(26, 26, 46, 0.95) !important;
            backdrop-filter: blur(25px) !important;
            border: 1px solid rgba(0, 212, 255, 0.3) !important;
            border-radius: 15px !important;
            box-shadow: 0 20px 50px rgba(0, 212, 255, 0.3) !important;
            color: #ffffff !important;
        }

        /* Tab System Enhancement */
        .tab-btn {
            background: rgba(26, 26, 46, 0.8) !important;
            color: rgba(255, 255, 255, 0.7) !important;
            border: 1px solid rgba(0, 212, 255, 0.2) !important;
            border-radius: 12px 12px 0 0 !important;
            padding: 12px 20px !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, #00d4ff 0%, #0080ff 100%) !important;
            color: #0a0a1a !important;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.4) !important;
        }

        /* Notification System Enhancement */
        .notification {
            background: rgba(26, 26, 46, 0.95) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid rgba(0, 212, 255, 0.3) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.3) !important;
        }

        .notification.success {
            border-color: #00ff88 !important;
            background: rgba(0, 255, 136, 0.15) !important;
        }

        .notification.error {
            border-color: #ff4444 !important;
            background: rgba(255, 68, 68, 0.15) !important;
        }

        .notification.warning {
            border-color: #ffaa00 !important;
            background: rgba(255, 170, 0, 0.15) !important;
        }
        select, .dropdown-selector {
            background: linear-gradient(135deg, #1a1a3a 0%, #0d0d24 100%) !important;
            color: #00d4ff !important;
            border: 1px solid rgba(0, 212, 255, 0.3);
        }
    </style>
</head>
<body>
<?php include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<?php
    // Include widgets using specific CUE Framework functions
    try {
        includeDragDropWidget();
        includeNoticesWidget();
        includeLoaderWidget();
    } catch (Throwable $e) {
        error_log("Failed to include navigator widgets: " . $e->getMessage());
    }

?>
<!-- Navigator content starts here - standalone -->
<!-- Version: 2025-11-05 00:45:49 - Cache-busted version -->
<div class="main-content">
<!-- Navigator-specific styles and scripts -->
<style>
        /* Navigator Interface Styles - Global UI Integration */
        .navigator-container {
            display: grid;
            grid-template-columns: 350px 1fr 350px;
            gap: 20px;
            padding: 0 20px 20px;
            min-height: calc(100vh - 80px - <?php echo (int)$footerSafeOffsetPx; ?>px);
            padding-bottom: calc(20px + <?php echo (int)$footerSafeOffsetPx; ?>px);
            margin-top: 0px;
            background: rgba(10, 10, 26, 0.3);
            backdrop-filter: blur(5px);
        }

        /* Glassmorphism Cards */
        .card, .menu-item-enhanced, .realm-item {
            background: rgba(26, 26, 46, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(0, 212, 255, 0.2);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.2);
            transition: all 0.3s ease;
        }

        .card:hover, .menu-item-enhanced:hover, .realm-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 212, 255, 0.3);
            border-color: var(--primary-color);
        }

        /* Enhanced Button Styles */
        .btn, button {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: var(--background-color);
            border: none;
            border-radius: 12px;
            padding: 12px 24px;
            font-family: "Rajdhani", sans-serif;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
        }

        .btn:hover, button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.5);
            filter: brightness(1.1);
        }
        /* Reduce any global top spacing applied by header styles */
        .main-content { padding-top: 0 !important; margin-top: 0 !important; padding-bottom: <?php echo (int)$footerSafeOffsetPx; ?>px; }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 8px;
        }
        .panel-title {
            font-family: 'Orbitron', monospace;
            font-size: 1.3rem;
            color: var(--primary-color);
            margin: 0;
        }
        /* Tab Navigation Styles */
        .tab-navigation {
            display: flex;
            gap: 5px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 4px;
        }
        .tab-btn {
            background: none;
            border: none;
            color: var(--gray-text);
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 44px;
        }
        .tab-btn:focus-visible { outline: 2px solid var(--primary-color); outline-offset: 2px; }
        .tab-btn:hover {
            color: var(--light-text);
            background: rgba(255, 255, 255, 0.05);
        }
        .tab-btn.active {
            background: var(--gradient-primary);
            color: white;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .icon { display: inline-flex; align-items: center; line-height: 1; }
        .icon svg { width: 1em; height: 1em; vertical-align: middle; }
        .icon i { font-size: 1em !important; line-height: 1em; display: inline-block; width: 1em; height: 1em; vertical-align: middle; }
        .realm-header .realm-name .icon { margin-right: 6px; }
        .submenu-content .icon { margin-right: 6px; }
        .tab-header {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 15px;
        }
        .btn-add {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-primary);
        }
        .realm-item, .menu-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .realm-item:hover, .menu-item:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        .realm-item.active {
            background: rgba(0, 212, 255, 0.1);
            border-color: var(--primary-color);
        }
        .realm-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        /* Realm icon visuals removed */
        .realm-name {
            font-weight: 600;
            color: var(--light-text);
            flex: 1;
        }
        .realm-actions {
            display: flex;
            gap: 5px;
        }
        .btn-action {
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        .btn-action:hover {
            color: var(--primary-color);
            background: rgba(255, 255, 255, 0.1);
        }
        .btn-action.delete:hover {
            color: #ef4444;
        }
        .realm-description {
            font-size: 0.85rem;
            color: var(--gray-text);
            margin-bottom: 8px;
        }
        .realm-pages {
            font-size: 0.8rem;
            color: var(--primary-color);
        }
        .preview-menu {
            max-height: 400px;
            overflow-y: auto;
            position: relative;
            z-index: 10050;
        }
        .preview-menu::-webkit-scrollbar {
            width: 6px;
        }
        .preview-menu::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }
        .preview-menu::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 3px;
        }
        .preview-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid transparent;
            transition: all 0.3s ease;
        }
        .preview-item:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-color);
        }
        /* Preview icon visuals removed */
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: var(--light-text);
            font-weight: 500;
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--light-text);
            font-size: 14px;
            transition: all 0.3s ease;
        }
        /* Dark dropdown for select in modals */
        select.form-input, select.form-select {
            background: #2a2a2a;
            color: #fff;
            border-color: var(--border-color);
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.2);
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: var(--dark-bg);
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            position: relative;
            z-index: 10050;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-card);
            margin: auto;
            position: relative;
            transform: translateY(0);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        .modal-title {
            font-family: 'Orbitron', monospace;
            font-size: 1.2rem;
            color: var(--primary-color);
            margin: 0;
        }
        .btn-close {
            background: none;
            border: none;
            color: var(--gray-text);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-close:hover {
            color: var(--light-text);
        }
        
        /* Working Modal Overrides - Force dark blue background */
        #working-submenu-modal > div,
        #working-social-modal > div {
            background: #1a237e !important;
            color: white !important;
        }
        
        #working-submenu-modal input,
        #working-submenu-modal select,
        #working-submenu-modal textarea,
        #working-social-modal input,
        #working-social-modal select,
        #working-social-modal textarea {
            background: #2a2a2a !important;
            color: white !important;
            border: 1px solid #444 !important;
        }
        
        /* Submenu display items */
        .submenu-item, .submenu-item * {
            color: white !important;
        }
        
        .form-actions {
            display: flex;
            flex-direction: row;
            gap: 10px;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: nowrap;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        .form-actions .btn-save,
        .form-actions .btn-cancel {
            display: inline-flex;
            width: auto;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-save {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-primary);
        }
        .btn-cancel {
            background: transparent;
            color: var(--gray-text);
            border: 1px solid var(--border-color);
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-cancel:hover {
            color: var(--light-text);
            border-color: var(--gray-text);
        }
        .btn-delete {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-delete:hover:not(:disabled) {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }
        .btn-delete:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        /* Confirmation modal specific styles */
        #delete-confirmation-modal .modal-content {
            border: 2px solid rgba(231, 76, 60, 0.3);
        }
        #delete-confirmation-input {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-color);
            border: 2px solid var(--border-color);
            transition: all 0.3s ease;
        }
        #delete-confirmation-input:focus {
            outline: none;
            border-color: #e74c3c;
            box-shadow: 0 0 0 3px rgba(231, 76, 60, 0.1);
        }
        #delete-confirmation-input.valid {
            border-color: #27ae60;
            background: rgba(39, 174, 96, 0.1);
        }
        .warning-message {
            animation: warningPulse 2s ease-in-out infinite;
        }
        @keyframes warningPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        .submenu-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        .submenu-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 5px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }
        .submenu-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--primary-color);
        }
        .submenu-content {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
        }
        .submenu-name {
            font-weight: 500;
            color: var(--light-text);
        }
        /* Submenu icon visuals removed */
        .submenu-url {
            font-size: 0.8rem;
            color: var(--gray-text);
            font-family: 'Courier New', monospace;
        }
        .submenu-actions {
            display: flex;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .submenu-item:hover .submenu-actions {
            opacity: 1;
        }
        .btn-action-small {
            background: none;
            border: none;
            color: var(--gray-text);
            cursor: pointer;
            padding: 4px;
            border-radius: 3px;
            transition: all 0.3s ease;
            font-size: 0.8rem;
        }
        .btn-action-small:hover {
            color: var(--primary-color);
            background: rgba(255, 255, 255, 0.1);
        }
        .btn-action-small.delete:hover {
            color: #ef4444;
        }
        /* New CSS for enhanced components */
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--light-text);
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-color);
        }
        .btn-secondary.active {
            background: var(--primary-color);
            color: var(--dark-bg);
            border-color: var(--primary-color);
        }
        .page-browser-container {
            background: rgba(255, 255, 255, 0.02);
        }
        .page-item {
            padding: 12px 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-item:hover {
            background: rgba(0, 212, 255, 0.1);
        }
        .page-item.selected {
            background: rgba(0, 212, 255, 0.2);
            color: var(--primary-color);
        }
        .page-item .page-path {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            flex: 1;
        }
        .page-item .page-type {
            font-size: 0.8rem;
            color: var(--gray-text);
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 8px;
            border-radius: 12px;
        }
        .color-picker-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .color-preview {
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        /* Filter buttons container */
        .filter-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .filter-buttons .btn-secondary {
            font-size: 0.8rem;
            padding: 6px 12px;
        }
        @media (max-width: 1024px) {
            .navigator-container {
                grid-template-columns: 1fr;
                gap: 15px;
                padding: 15px;
            }
            .modal-content {
                margin: 10px;
                max-width: calc(100vw - 20px);
                max-height: 90vh;
                padding: 20px;
            }
            .filter-buttons {
                justify-content: center;
            }
        }
        @media (max-width: 768px) {
            .modal-content {
                margin: 5px;
                max-width: calc(100vw - 10px);
                max-height: 95vh;
                padding: 15px;
                border-radius: 10px;
            }
            .modal-header {
                margin-bottom: 20px;
                padding-bottom: 10px;
            }
            .modal-title {
                font-size: 1.1rem;
            }
        }
        /* Responsive navigation */
        @media (max-width: 1024px) {
            .navigator-container {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 768px) {
            .menu-item {
                padding: 20px 15px;
            }
        }
        /* Live Preview Styles */
        .menu-preview-wrapper {
            background: rgba(6, 18, 25, 0.4);
            border-radius: 8px;
            padding: 15px;
            border: 1px solid rgba(0, 212, 255, 0.2);
        }
        .menu-preview-content {
            margin-top: 10px;
        }
        .sidebar-menu-preview {
            background: rgba(14, 22, 33, 0.6);
            border-radius: 6px;
            padding: 10px 0;
        }
        .sidebar-menu-preview ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .sidebar-menu-preview li {
            margin: 0;
            padding: 0;
        }
        .sidebar-menu-preview .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            color: #e0e6ed;
            text-decoration: none;
            border-radius: 4px;
            margin: 2px 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        .sidebar-menu-preview .nav-link:hover {
            background: rgba(0, 212, 255, 0.1);
            color: #00d4ff;
            transform: translateX(3px);
        }
        /* Sidebar nav icon visuals removed */
        .sidebar-menu-preview .nav-content h4 {
            margin: 0;
            font-size: 14px;
            font-weight: 500;
            color: inherit;
        }
        .sidebar-menu-preview .nav-content p {
            margin: 0;
            font-size: 11px;
            color: #8892b0;
        }
        .sidebar-menu-preview .submenu-container {
            padding-left: 20px;
            border-left: 2px solid rgba(0, 212, 255, 0.2);
            margin-left: 27px;
            margin-top: 5px;
        }
        .sidebar-menu-preview .submenu-link {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            color: #a8b2d1;
            text-decoration: none;
            font-size: 12px;
            border-radius: 3px;
            margin: 1px 0;
            transition: all 0.2s ease;
        }
        .sidebar-menu-preview .submenu-link:hover {
            background: rgba(0, 212, 255, 0.05);
            color: #00d4ff;
            padding-left: 16px;
        }
        .sidebar-menu-preview .submenu-link i {
            font-size: 10px;
            width: 14px;
            color: #00d4ff;
        }
    
        /* Enhanced hierarchy styles */
        .menu-item-enhanced {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 10px 0;
            background: #f9f9f9;
        }
        
        .menu-header {
            display: flex;
            align-items: center;
            padding: 12px;
            background: #1a237e; color: white;
            border-radius: 8px 8px 0 0;
        }
        
        .menu-name {
            flex-grow: 1;
            margin-left: 10px;
            font-weight: bold;
        }
        
        .menu-actions {
            display: flex;
            gap: 5px;
        }
        
        .submenu-container {
            padding: 0 20px 10px;
            background: #0a0a0a;
        }
        
        .submenu-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin: 5px 0;
            background: #1a237e; color: white;
            border-radius: 4px;
            border-left: 3px solid #00d4ff;
        }
        
        .submenu-item span { color: white;
            margin-left: 8px;
        }
        
        .submenu-url {
            margin-left: auto;
            color: #ccc;
            font-size: 0.9em;
        }
        
        .menu-social-container {
            padding: 10px 20px;
            background: #1f1f1f; /* dark grey for social section */
            border-radius: 0 0 8px 8px;
        }
        
        .social-link-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            margin: 6px 0;
            background: #2a2a2a; /* dark grey item */
            color: #eaeaea;
            border: 1px solid var(--border-color);
            border-radius: 6px;
        }
        
        .social-url {
            margin-left: auto;
            color: #00d4ff;
            text-decoration: none;
            font-size: 0.9em;
        }

        /* Ensure social action buttons are visible on dark backgrounds */
        .social-link-item .btn-action {
            color: #ddd;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.08);
        }
        .social-link-item .btn-action:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.15);
        }
        

        /* Loader styles are provided by the loader widget */
        #realms-list.loading,
        #menus-list.loading,
        #menu-preview.loading {
            position: relative;
            min-height: 80px;
        }
        
        /* Legacy inline loading placeholder removed. Shared overlay widget handles loading UI. */

</style>
<script nonce="<?php echo cspNonce(); ?>"></script>




    <?php if (!$initSuccess): ?>
    <!-- Initialization Error Banner -->
    <div style="position: fixed; top: 80px; left: 0; right: 0; background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; padding: 12px 20px; text-align: center; z-index: 999; box-shadow: 0 4px 20px rgba(220, 38, 38, 0.3);">
        <strong>System Error</strong> - Menu Navigator failed to initialize: <?php echo htmlspecialchars($initError); ?>
    </div>
    <?php endif; ?>
    <!-- Loader Widget Integration -->
    <link rel="stylesheet" href="/templates/widgets/loader/loader.css">
    <script src="/templates/widgets/loader/loader-simple.js"></script>
    <script nonce="<?php echo cspNonce(); ?>">
        // Accent color used across preview and header; defaults to site accent
        let currentRealmColor = '#00d4ff';
        console.log('Loader check:', typeof window.showLoadingAnimation, typeof window.hideLoadingAnimation);
    </script>
    <div class="navigator-container">        
        <!-- Left Panel: Realm Management -->
        <div class="navigator-panel">
            <div class="panel-header">
                <h2 class="panel-title">Realms</h2>
                <button class="btn-add" id="btn-add-realm" >
                    Add Realm
                </button>
            </div>
            <div id="realms-list">
                <!-- Realms will be loaded here dynamically -->
            </div>
        </div>
        <!-- Center Panel: Menu Management -->
        <div class="navigator-panel">
            <div class="panel-header">
                <h2 class="panel-title">Menu Management</h2>
                <div class="tab-navigation" role="tablist" aria-label="Navigator tabs">
                    <button class="tab-btn active" role="tab" aria-selected="true" aria-controls="menus-tab" data-tab="menus">
                        Menus
                    </button>
                    <button class="tab-btn" role="tab" aria-selected="false" aria-controls="social-tab" data-tab="social">
                        Social
                    </button>
                </div>
            </div>
            <!-- Regular Menus Tab -->
            <div id="menus-tab" class="tab-content active">
                <div class="tab-header">
                    <button class="btn-add" id="btn-add-menu" disabled>
                        Add Menu
                    </button>
                    <button class="btn-add" id="btn-load-menus" disabled>
                        Load Menus
                    </button>
                </div>
                <div id="no-realm-selected" style="text-align: center; color: var(--gray-text); padding: 40px 20px;">
                    <p>Select a realm to manage its menus</p>
                </div>
                <div id="menus-list" style="display: none;">
                    <!-- Menus will be loaded here -->
                </div>
            </div>
            <!-- Social Menus Tab -->
            <div id="social-tab" class="tab-content">
                <div class="tab-header">
                    <button class="btn-add" id="btn-add-social" disabled>
                        Add Social Link
                    </button>
                </div>
                <div id="no-realm-selected-social" style="text-align: center; color: var(--gray-text); padding: 40px 20px;">
                        
                    <p>Select a realm to manage its social links</p>
                </div>
                <div id="social-list" style="display: none;">
                    <!-- Social links will be loaded here -->
                </div>
            </div>
        </div>
        <!-- Right Panel: Live Preview -->
        <div class="navigator-panel">
            <div class="panel-header">
                <h2 class="panel-title">Live Preview</h2>
                
            </div>
            <div id="no-preview" style="text-align: center; color: var(--gray-text); padding: 40px 20px;">
                
                <p>Select a realm to preview its menu</p>
            </div>
            <div class="preview-menu" id="menu-preview" style="display: none;">
                <!-- Preview will be loaded here -->
            </div>
        </div>
    </div>
    <!-- Realm Modal -->
    <div class="modal" id="realm-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="realm-modal-title">Add New Realm</h3>
                <button class="btn-close" onclick="closeRealmModal()">&times;</button>
            </div>
            <form id="realm-form">
                <input type="hidden" id="realm-edit-id" name="realm_id">
                <div class="form-group">
                    <label class="form-label">Realm Name *</label>
                    <input type="text" class="form-input" id="realm-name" name="name" required placeholder="Enter realm name">
                </div>
                <div class="form-group">
                    <label class="form-label">Realm ID *</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" class="form-input" id="realm-id" name="id" required placeholder="Enter realm ID or use auto-generation" style="flex: 1;">
                        <button type="button" id="auto-generate-btn" class="btn" style="padding: 8px 12px; white-space: nowrap; background: var(--primary-color); color: white; border: none; border-radius: 4px; cursor: pointer;" title="Auto-generate ID from name">Auto</button>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--gray-text); margin-top: 5px;">
                        ID must be unique and contain only letters, numbers, and underscores
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="realm-description" name="description" rows="3" placeholder="Describe this realm's purpose and access level"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select class="form-input" id="realm-status" name="status">
                        <option value="active">Active - Available for assignment</option>
                        <option value="inactive">Inactive - Hidden from selection</option>
                        <option value="maintenance">Maintenance - Temporarily disabled</option>
                        <option value="development">Development - Test only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Priority</label>
                    <input type="number" class="form-input" id="realm-priority" name="priority" min="1" step="1" placeholder="Enter display priority">
                </div>
                <div class="form-group">
                    <label class="form-label">Auto-Detection</label>
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <input type="checkbox" id="realm-auto-detect" name="auto_detect" style="margin: 0;">
                        <label for="realm-auto-detect" style="font-size: 0.9rem; margin: 0;">Enable automatic realm detection</label>
                    </div>
                    <textarea class="form-textarea" id="realm-detection-rules" name="detection_rules" rows="3" 
                              placeholder="Enter detection rules (one per line):&#10;url_pattern:/admin/*&#10;header:HTTP_GROUPS=admins"></textarea>
                    <div style="font-size: 0.8rem; color: var(--gray-text); margin-top: 5px;" id="detection-help">
                        Rules help header.php automatically determine user realm based on URL or headers
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Realm Color</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" class="form-input" id="realm-color" name="color" value="#6b7280" style="width: 60px; height: 50px; padding: 5px;">
                        <span id="color-preview" style="padding: 8px 15px; border-radius: 6px; background: #6b7280; color: white; font-size: 0.9rem;">Preview</span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Realm Icon</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="hidden" id="realm-icon" name="icon" value="">
                        <div id="realm-icon-preview" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; border: 2px dashed #666; border-radius: 6px; background: rgba(255,255,255,0.05); color: #666;">
                            <span style="font-size: 0.8rem;">No Icon</span>
                        </div>
                        <button type="button" class="btn-secondary" onclick="openIconPicker('realm')" style="padding: 8px 16px;">
                            Choose Icon
                        </button>
                        <button type="button" class="btn-secondary" onclick="clearIcon('realm')" style="padding: 8px 16px; background: #444;">
                            Clear
                        </button>
                    </div>
                </div>
 
                <div class="form-group">
                    <label class="form-label">Allowed Pages</label>
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <button type="button" class="btn-secondary" onclick="showPageBrowser()" style="flex: 1; padding: 8px 12px; font-size: 0.9rem;">Browse Pages</button>
                        <button type="button" class="btn-secondary" onclick="addCommonPages()" style="flex: 1; padding: 8px 12px; font-size: 0.9rem;">Add Common</button>
                    </div>
                    <textarea class="form-textarea" id="realm-pages" name="pages" rows="4" placeholder="Enter page paths, one per line:&#10;/admin/*&#10;/user/profile&#10;/dashboard"></textarea>
                    <div style="font-size: 0.8rem; color: var(--gray-text); margin-top: 5px;">
                        Use * for wildcards (e.g., /admin/* for all admin pages)
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-save">Save Realm</button>
                    <button type="button" class="btn-cancel" onclick="closeRealmModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Menu Modal -->
    <div class="modal" id="menu-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="menu-modal-title">Add New Menu</h3>
                <button class="btn-close" onclick="closeMenuModal()">&times;</button>
            </div>
            <form id="menu-form">
                <input type="hidden" id="menu-realm-id" name="realm_id">
                <input type="hidden" id="menu-edit-id" name="menu_id">
                <div class="form-group">
                    <label class="form-label">Menu Name *</label>
                    <input type="text" class="form-input" id="menu-name" name="name" required>
                </div>
                <div class="form-group">
                    <label class="form-label">URL</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" class="form-input" id="menu-url" name="url" placeholder="/dashboard" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" onclick="showPageBrowser()" style="padding: 8px 12px; white-space: nowrap;">Browse Pages</button>
                    </div>
                    <select id="page-browser" class="form-input" style="margin-top: 8px; display: none;" onchange="selectPage(this.value)">
                        <option value="">-- Select a page --</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Menu Icon</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="hidden" id="menu-icon" name="icon" value="">
                        <div id="menu-icon-preview" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; border: 2px dashed #666; border-radius: 6px; background: rgba(255,255,255,0.05); color: #666;">
                            <span style="font-size: 0.8rem;">No Icon</span>
                        </div>
                        <button type="button" class="btn-secondary" onclick="openIconPicker('menu')" style="padding: 8px 16px;">
                            Choose Icon
                        </button>
                        <button type="button" class="btn-secondary" onclick="clearIcon('menu')" style="padding: 8px 16px; background: #444;">
                            Clear
                        </button>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-actions">
                        <button type="submit" class="btn-save">Save Menu</button>
                        <button type="button" class="btn-cancel" onclick="closeMenuModal()">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <!-- Page Browser Modal -->
    <div class="modal" id="page-browser-modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3 class="modal-title">Browse Available Pages</h3>
                <button class="btn-close" onclick="closePageBrowser()">&times;</button>
            </div>
            <div style="margin-bottom: 20px;">
                <input type="text" class="form-input" id="page-search" placeholder="Search pages..." style="margin-bottom: 15px;">
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <button type="button" class="btn-secondary" onclick="filterPages('all')" data-filter="all">All Pages</button>
                    <button type="button" class="btn-secondary" onclick="filterPages('admin')" data-filter="admin">Admin</button>
                    <button type="button" class="btn-secondary" onclick="filterPages('user')" data-filter="user">User</button>
                    <button type="button" class="btn-secondary" onclick="filterPages('api')" data-filter="api">API</button>
                </div>
            </div>
            <div class="page-browser-container" style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px;">
                <div id="page-list">
                    <!-- Pages will be loaded here -->
                </div>
            </div>
            <div style="margin-top: 15px; padding: 15px; background: rgba(255, 255, 255, 0.03); border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: var(--primary-color); font-size: 0.9rem;">Selected Pages:</h4>
                <div id="selected-pages" style="font-size: 0.85rem; color: var(--gray-text);">
                    None selected
                </div>
            </div>
            <div class="form-actions" style="display:flex !important; flex-direction:row !important; justify-content:flex-end; gap:10px; align-items:center; flex-wrap:nowrap; width:100%;">
                <button type="button" class="btn-save" onclick="applySelectedPages()" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Apply Selected Pages</button>
                <button type="button" class="btn-cancel" onclick="closePageBrowser()" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Cancel</button>
            </div>
        </div>
    </div>
    <!-- Submenu Modal -->
    <div class="modal" id="submenu-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="submenu-modal-title">Add Submenu Item</h3>
                <button class="btn-close" onclick="closeSubmenuModal()">&times;</button>
            </div>
            <form id="submenu-form">
                <input type="hidden" id="submenu-realm-id" name="realm_id">
                <input type="hidden" id="submenu-menu-id" name="menu_id">
                <input type="hidden" id="submenu-edit-id" name="submenu_id">
                <div class="form-group">
                    <label class="form-label">Submenu Name *</label>
                    <input type="text" class="form-input" id="submenu-name" name="name" required>
                </div>
             <div class="form-group">
                    <label class="form-label">URL</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" class="form-input" id="submenu-url" name="url" placeholder="/subpage" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" onclick="showPageBrowser()" style="padding: 8px 12px; white-space: nowrap;">Browse</button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Submenu Icon</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="hidden" id="submenu-icon" name="icon" value="">
                        <div id="submenu-icon-preview" style="display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; border: 2px dashed #666; border-radius: 6px; background: rgba(255,255,255,0.05); color: #666;">
                            <span style="font-size: 0.8rem;">No Icon</span>
                        </div>
                        <button type="button" class="btn-secondary" onclick="openIconPicker('submenu')" style="padding: 8px 16px;">
                            Choose Icon
                        </button>
                        <button type="button" class="btn-secondary" onclick="clearIcon('submenu')" style="padding: 8px 16px; background: #444;">
                            Clear
                        </button>
                    </div>
                </div>
                <div class="form-actions" style="display:flex !important; flex-direction:row !important; justify-content:flex-end; gap:10px; align-items:center; flex-wrap:nowrap; width:100%;">
                    <button type="submit" class="btn-save" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Save Submenu</button>
                    <button type="button" class="btn-cancel" onclick="closeSubmenuModal()" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Social Modal -->
    <div class="modal" id="social-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="social-modal-title">Add Social Link</h3>
                <button class="btn-close" onclick="closeSocialModal()">&times;</button>
            </div>
            <form id="social-form">
                <input type="hidden" id="social-realm-id" name="realm_id">
                <input type="hidden" id="social-edit-id" name="id">
                <div class="form-group">
                    <label class="form-label">Platform *</label>
                    <select class="form-input" id="social-platform" name="platform" required>
                        <option value="">Select Platform</option>
                        <option value="facebook">Facebook</option>
                        <option value="twitter">Twitter</option>
                        <option value="instagram">Instagram</option>
                        <option value="linkedin">LinkedIn</option>
                        <option value="youtube">YouTube</option>
                        <option value="tiktok">TikTok</option>
                        <option value="discord">Discord</option>
                        <option value="github">GitHub</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">URL *</label>
                    <input type="url" class="form-input" id="social-url" name="url" required placeholder="https://...">
                    <input type="hidden" id="social-platform-name" name="platform_name">
                </div>
                <div class="form-actions" style="display:flex !important; flex-direction:row !important; justify-content:flex-end; gap:10px; align-items:center; flex-wrap:nowrap; width:100%;">
                    <button type="submit" class="btn-save" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Save Social Link</button>
                    <button type="button" class="btn-cancel" onclick="closeSocialModal()" style="width:auto !important; white-space:nowrap; flex:0 0 auto;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Realm Deletion Confirmation Modal -->
    <div class="modal" id="delete-confirmation-modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color: #e74c3c;">Confirm Realm Deletion</h3>
                <button class="btn-close" onclick="closeDeleteConfirmationModal()">&times;</button>
            </div>
            <div style="padding: 20px;">
                <div class="warning-message" style="background: rgba(231, 76, 60, 0.1); border: 1px solid rgba(231, 76, 60, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        
                        <div>
                            <h4 style="margin: 0 0 8px 0; color: #e74c3c; font-size: 1rem;">This action cannot be undone!</h4>
                            <p style="margin: 0; color: #ccc; line-height: 1.4;">You are about to permanently delete the following realm and all its associated menu items:</p>
                        </div>
                    </div>
                </div>
                <div class="realm-info" style="background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                        <div>
                            <div id="delete-realm-name" style="font-weight: 600; font-size: 1.1rem; color: var(--text-color);"></div>
                            <div id="delete-realm-id" style="font-size: 0.9rem; color: var(--gray-text); font-family: monospace;"></div>
                        </div>
                    </div>
                    <div id="delete-menu-count" style="color: var(--gray-text); font-size: 0.9rem;"></div>
                </div>
                <div id="system-realm-warning" class="system-warning" style="display: none; background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                        
                        <div>
                            <h4 style="margin: 0 0 8px 0; color: #ffc107; font-size: 1rem;">System Realm Warning</h4>
                            <p style="margin: 0; color: #ccc; line-height: 1.4;">This is a system realm that may be critical for application functionality. Deleting it could affect system operations.</p>
                        </div>
                    </div>
                </div>
                <div class="confirmation-text" style="text-align: center; margin: 20px 0;">
                    <p style="margin: 0; color: var(--text-color); font-weight: 500;">Type <strong>DELETE</strong> to confirm:</p>
                    <input type="text" id="delete-confirmation-input" placeholder="Type DELETE here" style="margin-top: 10px; padding: 12px; border: 2px solid #ddd; border-radius: 6px; width: 200px; text-align: center; font-weight: 600; text-transform: uppercase;" maxlength="6">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeDeleteConfirmationModal()">Cancel</button>
                <button type="button" class="btn-delete" id="confirm-delete-btn" onclick="confirmRealmDeletion()" disabled style="background: #e74c3c; color: white; opacity: 0.5; cursor: not-allowed;">Delete Realm</button>
            </div>
        </div>
    </div>
    <style>
        /* Page browser styles */
        #page-browser {
            width: 100%;
            max-height: 250px;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px;
            margin-top: 5px;
            background: rgba(255, 255, 255, 0.95);
            display: none;
            font-size: 14px;
            overflow-y: auto;
            position: relative;
            z-index: 10050;
        }
        #page-browser optgroup {
            font-weight: bold;
            color: #2c3e50;
            margin: 5px 0;
        }
        #page-browser option {
            padding: 3px 8px;
            color: #34495e;
            font-weight: normal;
        }
        #page-browser option:hover {
            background-color: #3498db;
            color: white;
        }
        .browse-button {
            background: linear-gradient(45deg, #3498db, #2980b9);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 5px;
            transition: all 0.3s ease;
        }
        .browse-button:hover {
            background: linear-gradient(45deg, #2980b9, #3498db);
            transform: translateY(-1px);
        }
    
        /* Enhanced hierarchy styles */
        .menu-item-enhanced {
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 10px 0;
            background: #f9f9f9;
        }
        
        .menu-header {
            display: flex;
            align-items: center;
            padding: 12px;
            background: #1a237e; color: white;
            border-radius: 8px 8px 0 0;
        }
        
        .menu-name {
            flex-grow: 1;
            margin-left: 10px;
            font-weight: bold;
        }
        
        .menu-actions {
            display: flex;
            gap: 5px;
        }
        
        .submenu-container {
            padding: 0 20px 10px;
            background: #0a0a0a;
        }
        
        .submenu-item {
            display: flex;
            align-items: center;
            padding: 8px;
            margin: 5px 0;
            background: #1a237e; color: white;
            border-radius: 4px;
            border-left: 3px solid #00d4ff;
        }
        
        .submenu-item span { color: white;
            margin-left: 8px;
        }
        
        .submenu-url {
            margin-left: auto;
            color: #ccc;
            font-size: 0.9em;
        }
        
        .menu-social-container {
            padding: 10px 20px;
            background: #f0f0f0;
            border-radius: 0 0 8px 8px;
        }
        
        .social-link-item {
            display: flex;
            align-items: center;
            padding: 6px;
            margin: 3px 0;
            background: #1a237e; color: white;
            border-radius: 4px;
        }
        
        .social-url {
            margin-left: auto;
            color: #007bff;
            text-decoration: none;
            font-size: 0.9em;
        }
        
        /* Icon button styles removed */
    </style>
    <script nonce='<?php echo cspNonce(); ?>'>
        // Navigator.php - Last updated: 2025-11-05 00:45:49 - Cache version: <?php echo time(); ?>
        // Initialize navigator configuration
        // Global request management to prevent 403 errors from mod_evasive
        window.requestQueue = window.requestQueue || [];
        window.processingQueue = false;
        window.lastRequestTime = 0;
        function queueRequest(requestFn, delay = 100) {
            window.requestQueue.push({ fn: requestFn, delay });
            processRequestQueue();
        }
        function processRequestQueue() {
            if (window.processingQueue || window.requestQueue.length === 0) return;
            window.processingQueue = true;
            const request = window.requestQueue.shift();
            const timeSinceLastRequest = Date.now() - window.lastRequestTime;
            const actualDelay = Math.max(request.delay, 100 - timeSinceLastRequest);
            setTimeout(() => {
                window.lastRequestTime = Date.now();
                request.fn();
                window.processingQueue = false;
                processRequestQueue(); // Process next request
            }, actualDelay);
        }
        window.navigatorConfig = {
            navigatorUrl: <?php echo json_encode(getTemplateURL("menus/navigator.php")); ?>,
            templatePath: <?php echo json_encode(getTemplateURL()); ?>
        };
        // Global variables
        let currentRealm = null;
        let availablePages = [];
        let selectedPages = new Set();
        // Social Management Functions
        function loadSocialConnects(realmId) {
            const effectiveRealm = realmId || currentRealm || 'guest';
            // Prepare UI for loading
            const socialList = document.getElementById('social-list');
            const noRealmMsg = document.getElementById('no-realm-selected-social');
            if (noRealmMsg) noRealmMsg.style.display = 'none';
            if (socialList) socialList.style.display = 'block';
            // Enable the Add Social button when we have an effective realm
            const addBtn = document.getElementById('btn-add-social');
            if (addBtn) addBtn.disabled = false;
            const formData = new URLSearchParams();
            formData.append('action', 'get_social_connects');
            formData.append('realm_id', effectiveRealm);
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySocialConnects(data.data);
                    const addBtn = document.getElementById('btn-add-social');
                    if (addBtn) addBtn.disabled = false;
                } else {
                    console.error('Error loading social connects:', data.error);
                    document.getElementById('social-list').innerHTML = '<p style="color: var(--error-color);">Error loading social connects</p>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('social-list').innerHTML = '<p style="color: var(--error-color);">Network error</p>';
            });
        }
        function displaySocialConnects(socialConnects) {
            const container = document.getElementById('social-list');
            const noSocial = document.getElementById('no-realm-selected-social');
            if (!socialConnects || socialConnects.length === 0) {
                container.style.display = 'none';
                noSocial.style.display = 'block';
                noSocial.innerHTML = `
                    
                    <p>No social links found for this realm</p>
                `;
                return;
            }
            noSocial.style.display = 'none';
            container.style.display = 'block';
            container.innerHTML = '';
            socialConnects.forEach(social => {
                const socialEl = document.createElement('div');
                socialEl.className = 'menu-item social-item';
                socialEl.innerHTML = `
                    <div class="menu-header">
                        <div class="menu-info">
                            <div class="menu-name">${social.name || social.platform}</div>
                            <div class="menu-url">${social.url}</div>
                        </div>
                        <div class="menu-actions">
                            <button class="btn-action" onclick="editSocial('${social.id}')" title="Edit">Edit</button>
                            <button class="btn-action delete" onclick="deleteSocial('${social.id}')" title="Delete">Delete</button>
                        </div>
                    </div>
                `;
                container.appendChild(socialEl);
            });
        }
        function openSocialModal(socialId = null) {
            const modal = document.getElementById('social-modal');
            const form = document.getElementById('social-form');
            const title = document.getElementById('social-modal-title');
            // Ensure realm is set for social operations
            const socialRealmInput = document.getElementById('social-realm-id');
            if (socialRealmInput) {
                socialRealmInput.value = currentRealm || 'guest';
            }
            if (socialId) {
                title.textContent = 'Edit Social Link';
                loadSocialData(socialId);
            } else {
                title.textContent = 'Add Social Link';
                form.reset();
                document.getElementById('social-edit-id').value = '';
                if (socialRealmInput && currentRealm) {
                    socialRealmInput.value = currentRealm;
                }
            }
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('role', 'dialog');
            
            // Check if modal is actually visible - if not, create a working modal
            setTimeout(() => {
                const rect = modal.getBoundingClientRect();
                console.log('Social modal dimensions:', rect.width, rect.height);
                
                if (rect.width === 0 || rect.height === 0) {
                    console.log('Social modal not visible, creating working modal fallback');
                    createWorkingSocialModal(socialId);
                }
            }, 100);
        }
        function closeSocialModal() {
            const modal = document.getElementById('social-modal');
            if (!modal) return;
            // Add a quick fade-out for visual closure
            modal.style.transition = 'opacity 150ms ease-out';
            modal.style.opacity = '0';
            setTimeout(() => {
                modal.style.display = 'none';
                modal.style.opacity = '1';
                modal.removeAttribute('aria-modal');
                modal.setAttribute('aria-hidden', 'true');
                // Cleanup form state
                const form = document.getElementById('social-form');
                if (form) form.reset();
                const editId = document.getElementById('social-edit-id');
                if (editId) editId.value = '';
            }, 160);
        }

        // Close whichever social modal is currently visible (primary or working fallback)
        function closeAnySocialModal() {
            const workingModal = document.getElementById('working-social-modal');
            if (workingModal) {
                closeWorkingSocialModal();
            }
            closeSocialModal();
        }
        
        function createWorkingSocialModal(socialId = null) {
            console.log('Creating working social modal for socialId:', socialId);
            
            // Create a working social modal
            const workingModal = document.createElement('div');
            workingModal.id = 'working-social-modal';
            workingModal.style.cssText = `
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: rgba(0, 0, 0, 0.8) !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                z-index: 999999 !important;
            `;
            
            const modalContent = `
                <div style="
                    background: #1a237e !important;
                    border-radius: 15px;
                    padding: 30px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    border: 1px solid #283593;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                    color: white !important;
                    outline: none;
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #00d4ff;" id="working-social-title">${socialId ? 'Edit Social Link' : 'Add Social Link'}</h3>
                        <button onclick="closeWorkingSocialModal()" style="background: none; border: none; color: #999; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px;">&times;</button>
                    </div>
                    
                    <form id="working-social-form" style="display: flex; flex-direction: column; gap: 15px;" role="form" aria-labelledby="working-social-title">
                        <input type="hidden" id="working-social-edit-id" value="${socialId || ''}" />
                        <input type="hidden" id="working-social-realm-id" value="${currentRealm}" />
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Platform *</label>
                            <select id="working-social-platform" name="platform" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            ">
                                <option value="">Select Platform</option>
                                <option value="facebook">Facebook</option>
                                <option value="twitter">Twitter</option>
                                <option value="instagram">Instagram</option>
                                <option value="linkedin">LinkedIn</option>
                                <option value="youtube">YouTube</option>
                                <option value="tiktok">TikTok</option>
                                <option value="discord">Discord</option>
                                <option value="github">GitHub</option>
                            </select>
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">URL *</label>
                            <input type="url" id="working-social-url" name="url" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            " placeholder="Enter social media URL" />
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button type="button" onclick="closeWorkingSocialModal()" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: 1px solid #666;
                                border-radius: 6px;
                                background: transparent;
                                color: #ccc;
                                cursor: pointer;
                                font-size: 1rem;
                            ">Cancel</button>
                            <button type="submit" id="working-social-submit" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: none;
                                border-radius: 6px;
                                background: #00d4ff;
                                color: #1a1a1a;
                                cursor: pointer;
                                font-size: 1rem;
                                font-weight: 600;
                            ">${socialId ? 'Update Social Link' : 'Create Social Link'}</button>
                        </div>
                    </form>
                </div>
            `;
            
            workingModal.innerHTML = modalContent;
            workingModal.setAttribute('role', 'dialog');
            workingModal.setAttribute('aria-modal', 'true');
            workingModal.setAttribute('aria-hidden', 'false');
            
            // Remove any existing working modal
            const existingWorkingModal = document.getElementById('working-social-modal');
            if (existingWorkingModal) existingWorkingModal.remove();
            
            document.body.appendChild(workingModal);
            console.log('Working social modal created and displayed');
            
            // Hide the original modal
            const originalModal = document.getElementById('social-modal');
            if (originalModal) originalModal.style.display = 'none';
            
            // Load social data if editing
            if (socialId) {
                loadWorkingSocialData(socialId);
            }
            
            // Set up form submission
            setupWorkingSocialForm(socialId);
        }
        
        // Helper functions for working social modal
        window.closeWorkingSocialModal = function() {
            const workingModal = document.getElementById('working-social-modal');
            if (workingModal) {
                workingModal.remove();
                console.log('Working social modal closed');
            }
        }
        
        function loadWorkingSocialData(socialId) {
            console.log('Loading working social data for socialId:', socialId);
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_social_connects&realm_id=${currentRealm}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                console.log('Working social data response:', data);
                if (data.success && data.data) {
                    const social = data.data.find(s => s.id === socialId);
                    console.log('Found social link for editing:', social);
                    if (social) {
                        // Populate the working modal fields
                        const platformField = document.getElementById('working-social-platform');
                        const urlField = document.getElementById('working-social-url');
                        
                        if (platformField) platformField.value = social.platform || '';
                        if (urlField) urlField.value = social.url || '';
                        
                        console.log('Working social fields populated:', {
                            platform: platformField?.value,
                            url: urlField?.value
                        });
                    } else {
                        console.error('Social link not found in data for ID:', socialId);
                    }
                } else {
                    console.error('Failed to load social data:', data);
                }
            })
            .catch(error => {
                console.error('Error loading working social data:', error);
            });
        }
        
        function setupWorkingSocialForm(socialId = null) {
            const form = document.getElementById('working-social-form');
            if (!form) return;
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('Working social form submitted');
                
                const submitBtn = document.getElementById('working-social-submit');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Saving...';
                showLoadingAnimation('Saving social connection...');
                
                const formData = new FormData();
                const action = socialId ? 'update_social_connect' : 'create_social_connect';
                formData.append('action', action);
                formData.append('realm_id', currentRealm);
                formData.append('platform', document.getElementById('working-social-platform').value);
                formData.append('url', document.getElementById('working-social-url').value);
                
                if (socialId) {
                    formData.append('social_id', socialId);
                }
                
                const urlEncodedData = new URLSearchParams(formData).toString();
                
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: urlEncodedData
                })
                .then(response => response.json().catch(() => ({ success: false, error: 'Invalid response' })))
                .then(data => {
                    console.log('Working social form response:', data);
                    if (data.success) {
                        closeAnySocialModal();
                        try {
                            document.dispatchEvent(new CustomEvent('socialConnectSaved', { detail: {
                                realmId: currentRealm,
                                platform: document.getElementById('working-social-platform').value,
                                url: document.getElementById('working-social-url').value
                            }}));
                        } catch (e) { /* no-op */ }
                        showNotification(data.message || 'Social link saved successfully!', 'success');
                        
                        // Refresh social links display if there's a function for it
                        if (typeof loadSocialConnects === 'function' && currentRealm) {
                            setTimeout(() => {
                                loadSocialConnects(currentRealm);
                            }, 100);
                        }
                    } else {
                        showNotification('Error: ' + (data.error || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Working social save error:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    hideLoadingAnimation();
                });
            });
        }
        function editSocial(socialId) {
            openSocialModal(socialId);
        }
        function deleteSocial(socialId) {
            if (confirm('Are you sure you want to delete this social link?')) {
                const formData = new URLSearchParams();
                formData.append('action', 'delete_social_connect');
                formData.append('id', socialId);
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Clear cache and refresh all components
                        clearMenuCache(currentRealm);
                        loadSocialConnects(currentRealm);
                        loadPreview(currentRealm);
                        showNotification('Social link deleted successfully!', 'success');
                    } else {
                        throw new Error(data.error || 'Failed to delete social link');
                    }
                })
                .catch(error => {
                    console.error('Error deleting social link:', error);
                    showNotification('Error deleting social link: ' + error.message, 'error');
                });
            }
        }
        function loadSocialData(socialId) {
            const platformField = document.getElementById('social-platform');
            const urlField = document.getElementById('social-url');
            const nameField = document.getElementById('social-platform-name');
            const editId = document.getElementById('social-edit-id');
            if (editId) editId.value = socialId || '';
            if (platformField) platformField.disabled = true;
            if (urlField) { urlField.disabled = true; urlField.placeholder = 'Loading...'; }
            if (nameField) nameField.disabled = true;

            // First try direct lookup by ID to avoid list desyncs
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_social_connect&id=${encodeURIComponent(socialId)}&realm_id=${encodeURIComponent(currentRealm || 'guest')}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const social = data.data;
                    const platformName = social.platform || '';
                    const displayName = social.name || social.platform_name || platformName;
                    if (platformField) platformField.value = platformName;
                    if (nameField) nameField.value = displayName;
                    if (urlField) urlField.value = social.url || '';
                } else {
                    // Fallback: load list and match by coerced ID
                    return fetch(window.navigatorConfig.navigatorUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=get_social_connects&realm_id=${currentRealm || 'guest'}&_t=${Date.now()}`
                    })
                    .then(resp => resp.json())
                    .then(listData => {
                        if (listData.success && Array.isArray(listData.data)) {
                            const social = listData.data.find(s => String(s.id) === String(socialId));
                            if (social) {
                                const platformName = social.platform || '';
                                const displayName = social.name || social.platform_name || platformName;
                                if (platformField) platformField.value = platformName;
                                if (nameField) nameField.value = displayName;
                                if (urlField) urlField.value = social.url || '';
                            } else {
                                showNotification('Social link not found for editing.', 'error');
                            }
                        } else {
                            showNotification('Failed to load social link data.', 'error');
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error loading social data:', error);
                showNotification('Error loading social data: ' + error.message, 'error');
            })
            .finally(() => {
                if (platformField) platformField.disabled = false;
                if (urlField) { urlField.disabled = false; urlField.placeholder = ''; }
                if (nameField) nameField.disabled = false;
            });
        }
        // Load realm data for editing
        function loadRealmData(realmId) {
            // Get realms data from cache or fetch fresh
            const realms = realmsCache;
            if (!realms) {
                showNotification('Error: No realms data available', 'error');
                return;
            }
            
            const realm = realms[realmId];
            if (!realm) {
                showNotification('Error: Realm not found', 'error');
                return;
            }
            
            // Populate form fields
            document.getElementById('realm-edit-id').value = realm.id;
            document.getElementById('realm-name').value = realm.name || '';
            document.getElementById('realm-id').value = realm.id || '';
            document.getElementById('realm-description').value = realm.description || '';
            document.getElementById('realm-status').value = realm.status || 'active';
            var rp = document.getElementById('realm-priority');
            if (rp) { rp.value = (typeof realm.priority !== 'undefined' ? realm.priority : ''); }
            document.getElementById('realm-auto-detect').checked = realm.auto_detect == 1;
            document.getElementById('realm-detection-rules').value = realm.detection_rules || '';
            document.getElementById('realm-color').value = realm.color || '#6b7280';
            
            // Handle pages data
            let pagesText = '';
            if (Array.isArray(realm.pages)) {
                pagesText = realm.pages.map(page => {
                    if (typeof page === 'object' && page.path) {
                        return page.path;
                    } else if (typeof page === 'string') {
                        return page;
                    }
                    return '';
                }).filter(p => p).join('\n');
            } else if (typeof realm.pages === 'string') {
                pagesText = realm.pages;
            }
            document.getElementById('realm-pages').value = pagesText;
            
            // Update color preview
            const colorPreview = document.getElementById('color-preview');
            if (colorPreview) {
                colorPreview.style.background = realm.color || '#6b7280';
                const rgb = hexToRgb(realm.color || '#6b7280');
                if (rgb) {
                    const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
                    colorPreview.style.color = brightness > 128 ? '#000000' : '#ffffff';
                }
            }
        }
        
        // Load menu data for editing
        function loadMenuData(menuId) {
            if (!currentRealm || !menuCache[currentRealm]) {
                showNotification('Error: No menu data available', 'error');
                return;
            }
            
            const menu = menuCache[currentRealm].find(m => m.id === menuId);
            if (!menu) {
                showNotification('Error: Menu not found', 'error');
                return;
            }
            
            // Populate form fields
            document.getElementById('menu-edit-id').value = menu.id;
            document.getElementById('menu-name').value = menu.title || menu.name || '';
            document.getElementById('menu-url').value = menu.url || '';
        }

        // Submenu modal functions
        function openSubmenuModal(menuId, submenuId = null) {
            const modal = document.getElementById('submenu-modal');
            const title = document.getElementById('submenu-modal-title');
            const form = document.getElementById('submenu-form');
            
            if (!modal || !title || !form) {
                showNotification('Error: Submenu modal not found', 'error');
                return;
            }
            
            // Set realm and menu IDs
            document.getElementById('submenu-realm-id').value = currentRealm;
            document.getElementById('submenu-menu-id').value = menuId;
            
            if (submenuId) {
                title.textContent = 'Edit Submenu';
                document.getElementById('submenu-edit-id').value = submenuId;
                // Load submenu data
                loadSubmenuData(menuId, submenuId);
            } else {
                title.textContent = 'Add Submenu';
                form.reset();
                document.getElementById('submenu-edit-id').value = '';
                document.getElementById('submenu-realm-id').value = currentRealm;
                document.getElementById('submenu-menu-id').value = menuId;
            }
            
            modal.classList.add('active');
            modal.style.display = 'flex';
        }

        function closeSubmenuModal() {
            const modal = document.getElementById('submenu-modal');
            if (modal) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        }

        function loadSubmenuData(menuId, submenuId) {
            if (!currentRealm || !menuCache[currentRealm]) {
                showNotification('Error: No menu data available', 'error');
                return;
            }
            
            const menu = menuCache[currentRealm].find(m => m.id === menuId);
            if (!menu || !menu.submenu) {
                showNotification('Error: Menu or submenu not found', 'error');
                return;
            }
            
            const submenu = menu.submenu.find(s => s.id === submenuId);
            if (!submenu) {
                showNotification('Error: Submenu not found', 'error');
                return;
            }
            
            // Populate form fields
            document.getElementById('submenu-name').value = submenu.title || submenu.name || '';
            document.getElementById('submenu-url').value = submenu.url || '';
            
            // Load submenu icon
            const submenuIconInput = document.getElementById('submenu-icon');
            const submenuIconPreview = document.getElementById('submenu-icon-preview');
            if (submenuIconInput) submenuIconInput.value = submenu.icon || '';
            if (submenuIconPreview && submenu.icon) {
                submenuIconPreview.innerHTML = renderIconDirect(submenu.icon, 20);
                submenuIconPreview.style.color = '#00d4ff';
                submenuIconPreview.style.background = 'rgba(0, 212, 255, 0.1)';
                try { applyIconFixups(submenuIconPreview); } catch (e) {}
            } else if (submenuIconPreview) {
                submenuIconPreview.innerHTML = '<span style="font-size: 0.8rem;">No Icon</span>';
                submenuIconPreview.style.color = '#666';
                submenuIconPreview.style.background = 'rgba(255,255,255,0.05)';
            }
        }

        // Menu CRUD functions
        function editMenu(menuId) {
            openMenuModal(menuId);
        }

        function deleteMenu(menuId) {
            if (confirm('Are you sure you want to delete this menu and all its submenus?')) {
                const formData = new URLSearchParams();
                formData.append('action', 'delete_menu');
                formData.append('menu_id', menuId);
                formData.append('realm_id', currentRealm);
                
                showLoadingAnimation('Deleting menu...');
                
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        clearMenuCache(currentRealm);
                        loadMenus(currentRealm, true);
                        loadPreview(currentRealm);
                        showNotification('Menu deleted successfully!', 'success');
                    } else {
                        showNotification('Error: ' + (data.error || 'Failed to delete menu'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error deleting menu:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    hideLoadingAnimation();
                });
            }
        }

        function deleteSubmenu(submenuId) {
            const formData = new URLSearchParams();
            formData.append('action', 'delete_submenu');
            formData.append('submenu_id', submenuId);
            formData.append('realm_id', currentRealm);
            
            showLoadingAnimation('Deleting submenu...');
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    clearMenuCache(currentRealm);
                    loadMenus(currentRealm, true);
                    loadPreview(currentRealm);
                    showNotification('Submenu deleted successfully!', 'success');
                } else {
                    showNotification('Error: ' + (data.error || 'Failed to delete submenu'), 'error');
                }
            })
            .catch(error => {
                console.error('Error deleting submenu:', error);
                showNotification('Network error: ' + error.message, 'error');
            })
            .finally(() => {
                hideLoadingAnimation();
            });
        }

        // Delete confirmation modal functions
        function showDeleteConfirmationModal(realmId) {
            realmToDelete = realmId;
            const modal = document.getElementById('delete-confirmation-modal');
            
            if (!modal) {
                showNotification('Error: Delete confirmation modal not found', 'error');
                return;
            }
            
            // Load realm data to display in confirmation
            const realms = realmsCache;
            if (realms && realms[realmId]) {
                const realm = realms[realmId];
                document.getElementById('delete-realm-name').textContent = realm.name || 'Unknown';
                document.getElementById('delete-realm-id').textContent = realm.id || '';
                
                // Count menus for this realm
                const menuCount = menuCache[realmId] ? menuCache[realmId].length : 0;
                document.getElementById('delete-menu-count').textContent = `${menuCount} menu items will be deleted`;
                
                // Show system realm warning for certain realms
                const systemRealms = ['guest', 'admin', 'system'];
                const systemWarning = document.getElementById('system-realm-warning');
                if (systemRealms.includes(realmId)) {
                    systemWarning.style.display = 'block';
                } else {
                    systemWarning.style.display = 'none';
                }
            }
            
            // Reset confirmation input
            document.getElementById('delete-confirmation-input').value = '';
            document.getElementById('confirm-delete-btn').disabled = true;
            
            modal.style.display = 'flex';
        }

        function closeDeleteConfirmationModal() {
            const modal = document.getElementById('delete-confirmation-modal');
            if (modal) {
                modal.style.display = 'none';
            }
            realmToDelete = null;
        }

        function confirmRealmDeletion() {
            if (!realmToDelete) {
                showNotification('Error: No realm selected for deletion', 'error');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'delete_realm');
            formData.append('realm_id', realmToDelete);
            
            showLoadingAnimation('Deleting realm...');
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeDeleteConfirmationModal();
                    clearRealmsCache();
                    clearMenuCache(realmToDelete);
                    window.loadRealms(true);
                    
                    // If we deleted the current realm, reset selection
                    if (currentRealm === realmToDelete) {
                        currentRealm = null;
                        document.getElementById('menus-list').style.display = 'none';
                        document.getElementById('no-realm-selected').style.display = 'block';
                        document.getElementById('menu-preview').style.display = 'none';
                        document.getElementById('no-preview').style.display = 'block';
                    }
                    
                    showNotification('Realm deleted successfully!', 'success');
                } else {
                    showNotification('Error: ' + (data.error || 'Failed to delete realm'), 'error');
                }
            })
            .catch(error => {
                console.error('Error deleting realm:', error);
                showNotification('Network error: ' + error.message, 'error');
            })
            .finally(() => {
                hideLoadingAnimation();
            });
        }

        // Show notification function
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                opacity: 0;
                transform: translateX(100px);
                transition: all 0.3s ease;
                max-width: 400px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            `;
            
            // Set background color based on type
            switch(type) {
                case 'success':
                    notification.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                    break;
                case 'error':
                    notification.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
                    break;
                case 'warning':
                    notification.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
                    break;
                default:
                    notification.style.background = 'linear-gradient(135deg, #3b82f6, #2563eb)';
            }
            
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.style.opacity = '1';
                notification.style.transform = 'translateX(0)';
            }, 100);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateX(100px)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
        }

        // Initialize delete confirmation modal
        function initializeDeleteConfirmationModal() {
            const confirmInput = document.getElementById('delete-confirmation-input');
            const confirmBtn = document.getElementById('confirm-delete-btn');
            
            if (confirmInput && confirmBtn) {
                confirmInput.addEventListener('input', function() {
                    const value = this.value.trim().toUpperCase();
                    if (value === 'DELETE') {
                        confirmBtn.disabled = false;
                        confirmBtn.style.opacity = '1';
                        confirmBtn.style.cursor = 'pointer';
                        this.classList.add('valid');
                    } else {
                        confirmBtn.disabled = true;
                        confirmBtn.style.opacity = '0.5';
                        confirmBtn.style.cursor = 'not-allowed';
                        this.classList.remove('valid');
                    }
                });
            }
        }

        // Initialize the navigator with aggressive performance optimization
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Navigator DOMContentLoaded - Starting initialization...');
            
            // Setup critical UI elements first
            try {
                console.log('📋 Setting up color selector...');
                setupColorSelector();
                console.log('✅ Color selector setup complete');
            } catch (e) {
                console.error('❌ Error setting up color selector:', e);
            }
            
            // Delay realm loading until the page is fully rendered and interactive
            setTimeout(() => {
                console.log('⏰ Timeout reached - Loading realms now...');
                console.log('📊 Current state - realmsCache:', !!window.realmsCache);
                console.log('📊 Current state - navigatorConfig:', !!window.navigatorConfig);
                console.log('📊 DOM ready state:', document.readyState);
                
                // Clear any existing cache to ensure fresh data
                console.log('🧹 Clearing realm cache to ensure fresh data...');
                window.realmsCache = null;
                window.realmsCacheTime = 0;
                
                try {
                    const result = window.loadRealms(true); // Force refresh to bypass cache
                    console.log('✅ window.loadRealms() called successfully with forceRefresh=true, returned:', result);
                    if (result && typeof result.then === 'function') {
                        result.then(data => {
                            console.log('✅ window.loadRealms() promise resolved with:', data);
                        }).catch(error => {
                            console.error('❌ window.loadRealms() promise rejected:', error);
                        });
                    }
                } catch (e) {
                    console.error('❌ Error calling window.loadRealms():', e);
                }
            }, 100); // Small delay to ensure smooth initial render
            // Continue initializing after DOM is ready
            try {
                console.log('👤 Setting up realm name handler...');
                setupRealmNameHandler();
                console.log('✅ Realm name handler setup complete');
            } catch (e) {
                console.error('❌ Error setting up realm name handler:', e);
            }
            

            
            try {
                console.log('🗑️ Initializing delete confirmation modal...');
                initializeDeleteConfirmationModal();
                console.log('✅ Delete confirmation modal initialized');
            } catch (e) {
                console.error('❌ Error initializing delete confirmation modal:', e);
            }
            // Attach event listeners to buttons
            const addRealmBtn = document.getElementById('btn-add-realm');
            console.log('DEBUG: Add Realm button found:', addRealmBtn);
            if (addRealmBtn) {
                addRealmBtn.addEventListener('click', function(e) {
                    console.log('DEBUG: Add Realm button clicked!');
                    e.preventDefault();
                    openRealmModal();
                });
                console.log('DEBUG: Add Realm button event listener attached');
            } else {
                console.error('DEBUG: Add Realm button NOT found!');
            }
            const addMenuBtn = document.getElementById('btn-add-menu');
            console.log('DEBUG: Add Menu button found:', addMenuBtn);
            if (addMenuBtn) {
                addMenuBtn.addEventListener('click', function(e) {
                    console.log('DEBUG: Add Menu button clicked!');
                    e.preventDefault();
                    
                    // Check if a realm is selected
                    if (!currentRealm) {
                        console.log('DEBUG: No realm selected, showing warning');
                        showNotification('Please select a realm first before adding menus.', 'warning');
                        return;
                    }
                    
                    console.log('DEBUG: Opening menu modal for realm:', currentRealm);
                    openMenuModal();
                });
                console.log('DEBUG: Add Menu button event listener attached');
            } else {
                console.error('DEBUG: Add Menu button NOT found!');
            }
            // Load Menus Button Event Listener
            const loadMenusBtn = document.getElementById('btn-load-menus');
            console.log('DEBUG: Load Menus button found:', loadMenusBtn);
            if (loadMenusBtn) {
                loadMenusBtn.addEventListener('click', function(e) {
                    console.log('DEBUG: Load Menus button clicked!');
                    e.preventDefault();
                    if (!currentRealm) {
                        showNotification('Please select a realm first.', 'warning');
                        return;
                    }
                    console.log('DEBUG: About to call loadMenus for realm:', currentRealm);
                    loadMenus(currentRealm);
                });
                console.log('DEBUG: Load Menus button event listener attached');
            } else {
                console.error('DEBUG: Load Menus button NOT found!');
            }
            // Add Social Button Event Listener (moved inside DOMContentLoaded for reliability)
            const addSocialBtn = document.getElementById('btn-add-social');
            console.log('DEBUG: Add Social button found:', addSocialBtn);
            if (addSocialBtn) {
                addSocialBtn.addEventListener('click', function(e) {
                    console.log('DEBUG: Add Social button clicked!');
                    e.preventDefault();
                    openSocialModal();
                });
                console.log('DEBUG: Add Social button event listener attached');
            } else {
                console.error('DEBUG: Add Social button NOT found!');
            }

            // Setup event delegation for dynamically created buttons
            document.addEventListener('click', function(e) {
                // Handle submenu edit buttons
                if (e.target.classList.contains('btn-edit-submenu')) {
                    e.preventDefault();
                    const menuId = e.target.getAttribute('data-menu-id');
                    const submenuId = e.target.getAttribute('data-submenu-id');
                    openSubmenuModal(menuId, submenuId);
                }
                
                // Handle submenu delete buttons
                if (e.target.classList.contains('btn-delete-submenu')) {
                    e.preventDefault();
                    const submenuId = e.target.getAttribute('data-submenu-id');
                    if (confirm('Are you sure you want to delete this submenu?')) {
                        deleteSubmenu(submenuId);
                    }
                }
            });
        });
        // Setup auto-ID generation from realm name
        function setupRealmNameHandler() {
            const nameInput = document.getElementById('realm-name');
            const idInput = document.getElementById('realm-id');
            const autoBtn = document.getElementById('auto-generate-btn');
            let manuallyEdited = false;
            // Auto-generate ID when name changes (only if not manually edited)
            nameInput.addEventListener('input', function() {
                const name = this.value;
                if (name && !manuallyEdited && !document.getElementById('realm-edit-id').value) {
                    generateRealmId(name);
                }
            });
            // Track manual editing of ID field
            idInput.addEventListener('input', function() {
                manuallyEdited = true;
                validateRealmId(this.value);
            });
            // Reset manual editing flag when the form is reset (opening new realm)
            const formEl = document.getElementById('realm-form');
            if (formEl) {
                formEl.addEventListener('reset', function() {
                    manuallyEdited = false;
                });
            }
            // Auto-generate button click handler
            autoBtn.addEventListener('click', function() {
                const name = nameInput.value;
                if (name) {
                    generateRealmId(name);
                    manuallyEdited = false;
                } else {
                    alert('Please enter a realm name first');
                    nameInput.focus();
                }
            });
        }
        // Generate realm ID from name
        function generateRealmId(name) {
            const idInput = document.getElementById('realm-id');
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=generate_realm_id&name=${encodeURIComponent(name)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    idInput.value = data.data;
                    idInput.style.border = '';
                    clearValidationMessage();
                }
            })
            .catch(error => console.error('Error generating realm ID:', error));
        }
        // Validate realm ID format and uniqueness
        function validateRealmId(id) {
            const idInput = document.getElementById('realm-id');
            if (!id) {
                showValidationMessage('ID is required', 'error');
                idInput.style.border = '2px solid #dc3545';
                return false;
            }
            // Check format
            if (!/^[a-zA-Z0-9_]+$/.test(id)) {
                showValidationMessage('ID can only contain letters, numbers, and underscores', 'error');
                idInput.style.border = '2px solid #dc3545';
                return false;
            }
            // Check uniqueness (skip for existing realm being edited)
            const isEditing = document.getElementById('realm-edit-id').value;
            if (!isEditing || document.getElementById('realm-edit-id').value !== id) {
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=validate_realm_id&id=${encodeURIComponent(id)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.exists) {
                            showValidationMessage('This ID already exists. Please choose another.', 'error');
                            idInput.style.border = '2px solid #dc3545';
                        } else {
                            showValidationMessage('ID is available', 'success');
                            idInput.style.border = '2px solid #28a745';
                        }
                    }
                })
                .catch(error => console.error('Error validating realm ID:', error));
            } else {
                idInput.style.border = '';
                clearValidationMessage();
            }
            return true;
        }
        // Show validation message
        function showValidationMessage(message, type) {
            clearValidationMessage();
            const idField = document.getElementById('realm-id');
            const messageEl = document.createElement('div');
            messageEl.id = 'realm-id-validation'; 
            messageEl.style.cssText = `
                font-size: 0.8rem; 
                margin-top: 5px; 
                color: ${type === 'success' ? '#28a745' : '#dc3545'};
            `;
            messageEl.textContent = message;
            idField.parentNode.appendChild(messageEl);
        }
        // Clear validation message
        function clearValidationMessage() {
            const existing = document.getElementById('realm-id-validation');
            if (existing) {
                existing.remove();
            }
        }
        // Setup color selector with live preview
        function setupColorSelector() {
            const colorInput = document.getElementById('realm-color');
            const colorPreview = document.getElementById('color-preview');
            if (colorInput && colorPreview) {
                colorInput.addEventListener('input', function() {
                    const color = this.value;
                    colorPreview.style.background = color;
                    // Determine text color based on background brightness
                    const rgb = hexToRgb(color);
                    const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
                    colorPreview.style.color = brightness > 128 ? '#000000' : '#ffffff';

                    // Live-propagate to preview UI without requiring save
                    currentRealmColor = color;
                    try {
                        // Update preview items border accent
                        document.querySelectorAll('.preview-menu-item').forEach(el => {
                            el.style.borderLeftColor = currentRealmColor;
                        });
                        // Update preview header accent
                        const previewHeader = document.querySelector('.menu-preview-wrapper h4');
                        if (previewHeader) {
                            previewHeader.style.color = currentRealmColor;
                        }
                        // Live update the realm list block for the realm being edited
                        const editingIdEl = document.getElementById('realm-edit-id');
                        const editingId = editingIdEl ? editingIdEl.value : '';
                        if (editingId) {
                            const item = document.querySelector(`.realm-item[data-realm-id="${editingId}"]`);
                            if (item) {
                                item.style.borderLeft = `6px solid ${color}`;
                                const nameEl = item.querySelector('.realm-name');
                                if (nameEl) nameEl.style.color = color;
                            }
                        }
                    } catch (e) {
                        console.debug('Live color preview update skipped:', e);
                    }
                });
                // Some browsers fire change rather than input for color pickers
                colorInput.addEventListener('change', function() {
                    const color = this.value;
                    colorPreview.style.background = color;
                    const rgb = hexToRgb(color);
                    const brightness = (rgb.r * 299 + rgb.g * 587 + rgb.b * 114) / 1000;
                    colorPreview.style.color = brightness > 128 ? '#000000' : '#ffffff';
                    currentRealmColor = color;
                    try {
                        document.querySelectorAll('.preview-menu-item').forEach(el => {
                            el.style.borderLeftColor = currentRealmColor;
                        });
                        const previewHeader = document.querySelector('.menu-preview-wrapper h4');
                        if (previewHeader) {
                            previewHeader.style.color = currentRealmColor;
                        }
                        const editingIdEl = document.getElementById('realm-edit-id');
                        const editingId = editingIdEl ? editingIdEl.value : '';
                        if (editingId) {
                            const item = document.querySelector(`.realm-item[data-realm-id="${editingId}"]`);
                            if (item) {
                                item.style.borderLeft = `6px solid ${color}`;
                                const nameEl = item.querySelector('.realm-name');
                                if (nameEl) nameEl.style.color = color;
                            }
                        }
                    } catch (e) {
                        console.debug('Live color preview update skipped:', e);
                    }
                });
            }
        }
        // Helper function to convert hex to RGB
        function hexToRgb(hex) {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : null;
        }
        // Load available pages
        function loadAvailablePages() {
            console.log('Loading available pages...');
            const currentUrl = window.navigatorConfig.navigatorUrl;
            console.log('Posting to URL:', currentUrl);
            fetch(currentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_pages'
            })
            .then(response => {
                console.log('Pages response status:', response.status);
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Pages response is not JSON. Content-Type: ' + contentType);
                }
                return response.json();
            })
            .then(data => {
                console.log('Pages data received:', data);
                if (data.success) {
                    availablePages = data.data;
                } else {
                    console.error('Error from server (pages):', data.error);
                    alert('Error loading pages: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error loading pages:', error);
                alert('Network error loading pages: ' + error.message);
            });
        }
        // Page browser functionality
        function showPageBrowser() {
            const browser = document.getElementById('page-browser');
            if (browser.style.display === 'none') {
                browser.style.display = 'block';
                if (browser.children.length <= 1) { // Only has the default option
                    loadPagesList();
                }
            } else {
                browser.style.display = 'none';
            }
        }
        function selectPage(url) {
            if (url) {
                // Check if submenu modal is open, and populate the correct field
                const submenuModal = document.getElementById('submenu-modal');
                const isSubmenuModalOpen = submenuModal && submenuModal.classList.contains('active');
                if (isSubmenuModalOpen) {
                    document.getElementById('submenu-url').value = url;
                } else {
                    document.getElementById('menu-url').value = url;
                }
                document.getElementById('page-browser').style.display = 'none';
            }
        }
        function loadPagesList() {
            if (!availablePages || availablePages.length === 0) {
                // Load pages if not already loaded
                loadAvailablePages();
                // Wait a moment for pages to load, then populate
                setTimeout(() => {
                    populatePageBrowser();
                }, 1000);
            } else {
                populatePageBrowser();
            }
        }
        function populatePageBrowser() {
            const browser = document.getElementById('page-browser');
            // Clear existing options except the first one
            while (browser.children.length > 1) {
                browser.removeChild(browser.lastChild);
            }
            if (availablePages && availablePages.length > 0) {
                // Group pages by category
                const pageGroups = {
                    'Root Pages': [],
                    'Control Pages': [],
                    'Admin Pages': [],
                    'Template Pages': [],
                    'Other Pages': []
                };
                availablePages.forEach(page => {
                    if (
                        page.includes('/control') ||
                        page.includes('/settings') ||
                        page.includes('/api/')
                    ) {
                        pageGroups['Control Pages'].push(page);
                    } else if (page.startsWith('/admin/')) {
                        pageGroups['Admin Pages'].push(page);
                    } else if (page.startsWith(window.navigatorConfig.templatePath)) {
                        pageGroups['Template Pages'].push(page);
                    } else if (
                        page === '/' ||
                        page.startsWith('/about') ||
                        page.startsWith('/contact')
                    ) {
                        pageGroups['Root Pages'].push(page);
                    } else {
                        pageGroups['Other Pages'].push(page);
                    }
                });
                // Add grouped options
                Object.keys(pageGroups).forEach(group => {
                    if (pageGroups[group].length > 0) {
                        const optgroup = document.createElement('optgroup');
                        optgroup.label = group;
                        pageGroups[group].sort().forEach(page => {
                            const option = document.createElement('option');
                            option.value = page;
                            option.textContent = page;
                            optgroup.appendChild(option);
                        });
                        browser.appendChild(optgroup);
                    }
                });
            } else {
                // No pages found - database connection failed
                console.error('No databases found - unable to load pages');
            }
        }
        // Icon search functionality
        // Page Browser functionality
        function showPageBrowser() {
            selectedPages = new Set();
            const currentPages = document.getElementById('realm-pages').value.split('\n').filter(p => p.trim());
            currentPages.forEach(page => selectedPages.add(page.trim()));
            displayPages(availablePages);
            updateSelectedPagesDisplay();
            document.getElementById('page-browser-modal').style.display = 'flex';
            const searchInput = document.getElementById('page-search');
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                const filtered = availablePages.filter(page => {
                    const path = getPagePath(page).toLowerCase();
                    return path.includes(query);
                });
                displayPages(filtered);
            });
        }
        function closePageBrowser() {
            document.getElementById('page-browser-modal').style.display = 'none';
        }
        function getPagePath(item) {
            if (typeof item === 'string') return item;
            if (item && typeof item === 'object') {
                if (item.path) return String(item.path);
                if (item.url) return String(item.url);
                if (item.href) return String(item.href);
            }
            return String(item || '');
        }
        function displayPages(pages) {
            const container = document.getElementById('page-list');
            container.innerHTML = '';
            pages.forEach(page => {
                const path = getPagePath(page);
                const pageEl = document.createElement('div');
                pageEl.className = 'page-item';
                if (selectedPages.has(path)) {
                    pageEl.classList.add('selected');
                }
                const pageType = getPageType(path);
                pageEl.innerHTML = `
                    <span class="page-path">${path}</span>
                    <span class="page-type">${pageType}</span>
                `;
                pageEl.onclick = () => togglePage(path, pageEl);
                container.appendChild(pageEl);
            });
        }
        function getPageType(page) {
            const p = typeof page === 'string' ? page : getPagePath(page);
            if (p.includes('/admin/')) return 'Admin';
            if (p.includes('/user/')) return 'User';
            if (p.includes('/api/')) return 'API';
            if (p.includes('*')) return 'Wildcard';
            return 'Page';
        }
        function togglePage(page, element) {
            if (selectedPages.has(page)) {
                selectedPages.delete(page);
                element.classList.remove('selected');
            } else {
                selectedPages.add(page);
                element.classList.add('selected');
            }
            updateSelectedPagesDisplay();
        }
        function updateSelectedPagesDisplay() {
            const container = document.getElementById('selected-pages');
            if (selectedPages.size === 0) {
                container.textContent = 'None selected';
            } else {
                container.innerHTML = Array.from(selectedPages).map(page => 
                    `<span style="display: inline-block; background: rgba(0,212,255,0.2); color: var(--primary-color); padding: 2px 8px; border-radius: 12px; margin: 2px; font-size: 0.8rem;">${page}</span>`
                ).join('');
            }
        }
        function filterPages(type) {
            document.querySelectorAll('[data-filter]').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-filter="${type}"]`).classList.add('active');
            let filtered = availablePages;
            if (type !== 'all') {
                filtered = availablePages.filter(page => {
                    const lowerPage = getPagePath(page).toLowerCase();
                    switch(type) {
                        case 'admin': return lowerPage.includes('/admin/');
                        case 'user': return lowerPage.includes('/user/');
                        case 'api': return lowerPage.includes('/api/');
                        default: return true;
                    }
                });
            }
            displayPages(filtered);
        }
        function addCommonPages() {
            const commonPages = [
                '/admin/*',
                '/user/profile',
                '/api/*'
            ];
            commonPages.forEach(page => selectedPages.add(page));
            // Refresh display if page browser is open
            if (document.getElementById('page-browser-modal').style.display === 'flex') {
                displayPages(availablePages);
                updateSelectedPagesDisplay();
            } else {
                // Add to textarea directly
                const currentPages = document.getElementById('realm-pages').value.split('\n').filter(p => p.trim());
                const allPages = [...new Set([...currentPages, ...commonPages])];
                document.getElementById('realm-pages').value = allPages.join('\n');
            }
        }
        function applySelectedPages() {
            const pagesArray = Array.from(selectedPages);
            document.getElementById('realm-pages').value = pagesArray.join('\n');
            closePageBrowser();
        }
        // Load realms
        function setLoading(containerId, isLoading, message) {
            // Use the loader widget functions for consistent loading animations
            if (isLoading) {
                if (typeof showLoadingAnimation === 'function') {
                    showLoadingAnimation(message || 'Loading...');
                }
            } else {
                if (typeof hideLoadingAnimation === 'function') {
                    hideLoadingAnimation();
                }
            }
        }

        // Full-screen loader overlay helpers are provided by loader.php.
        // Avoid duplicate definitions here; use global showLoadingAnimation/hideLoadingAnimation from the widget.

        // Client-side cache for realms (2 minute TTL)
        let realmsCache = null;
        let realmsCacheTime = 0;
        const REALMS_CACHE_TTL = 120000; // 2 minutes in milliseconds
        
        // Client-side cache for menus (5 minute TTL)
        let menuCache = {};
        let menuCacheTime = {};
        const MENU_CACHE_TTL = 300000; // 5 minutes in milliseconds
        // Prevent duplicate requests per realm and memoize last render
        let menuFetchPromises = {};
        let lastPreviewHashByRealm = {};
        
        // Debouncing for function calls
        let debounceTimers = {};
        
        function debounce(func, delay, key) {
            return function(...args) {
                clearTimeout(debounceTimers[key]);
                debounceTimers[key] = setTimeout(() => func.apply(this, args), delay);
            };
        }
        
        // Performance monitoring
        function logPerformance(operation, startTime, additionalInfo = '') {
            const duration = performance.now() - startTime;
            const level = duration > 1000 ? 'warn' : 'log';
            console[level](`Performance: ${operation} took ${duration.toFixed(2)}ms ${additionalInfo}`);
            return duration;
        }
        
        // Cache invalidation functions
        function clearMenuCache(realmId = null) {
            if (realmId) {
                delete menuCache[realmId];
                delete menuCacheTime[realmId];
                console.log(`Cleared menu cache for realm: ${realmId}`);
            } else {
                menuCache = {};
                menuCacheTime = {};
                console.log('Cleared all menu cache');
            }
        }
        
        function clearRealmsCache() {
            realmsCache = null;
            realmsCacheTime = 0;
            console.log('Cleared realms cache');
        }
        
        window.loadRealms = function(forceRefresh = false) {
        console.log("✅ window.loadRealms function defined successfully at", new Date());
            console.log('🔄 window.loadRealms() called with forceRefresh:', forceRefresh);
            
            // Check client-side cache first
            const now = Date.now();
            if (!forceRefresh && realmsCache && (now - realmsCacheTime) < REALMS_CACHE_TTL) {
                console.log('📦 Using cached realms data');
                displayRealms(realmsCache);
                return Promise.resolve(realmsCache);
            }
            
            // Use shared overlay loader for all realm loads
            const container = document.getElementById('realms-list');
            console.log('📋 Realms container found:', !!container);
            
            if (!container) {
                console.error('❌ Realms container not found! Cannot display realms.');
                return Promise.reject(new Error('Realms container not found'));
            }
            
            // Unified global loader message
            try {
                console.log('🔄 Showing loading animation...');
                showLoadingAnimation();
            } catch (e) {
                console.error('❌ Error showing loading animation:', e);
            }
            
            // Get the current page URL to ensure we're posting to the right place
            const currentUrl = window.navigatorConfig ? window.navigatorConfig.navigatorUrl : window.location.href;
            console.log('🌐 Posting to URL:', currentUrl);
            // Add cache-busting parameter
            const cacheBuster = 'cache_bust=' + Date.now();
            // No special refresh message; use unified loader
            
            console.log('🌐 Making fetch request with body:', 'action=get_realms&' + cacheBuster);
            console.log('🌐 Full URL being called:', currentUrl);
            console.log('🌐 Request details - Method: POST, Content-Type: application/x-www-form-urlencoded');
            
            return fetch(currentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_realms' + (forceRefresh ? '&force_refresh=1' : '') + '&' + cacheBuster
            })
            .then(response => {
                console.log('📡 Response received - status:', response.status, 'statusText:', response.statusText);
                console.log('📡 Response ok:', response.ok);
                console.log('📡 Response URL:', response.url);
                // Check if response is actually JSON
                const contentType = response.headers.get('content-type');
                console.log('📄 Response Content-Type:', contentType);
                
                if (!contentType || !contentType.includes('application/json')) {
                    console.error('❌ Response is not JSON. Content-Type:', contentType);
                    // Log first 500 chars of response for debugging
                    return response.text().then(text => {
                        console.error('📄 Response text preview:', text.substring(0, 500));
                        throw new Error('Response is not JSON. Content-Type: ' + contentType + '. Preview: ' + text.substring(0, 100));
                    });
                }
                return response.text();
            })
            .then(text => {
                console.log('📄 Raw response text length:', text.length);
                console.log('📄 Raw response preview:', text.substring(0, 300));
                console.log('📄 Raw response end:', text.substring(text.length - 100));
                
                // Check for multiple JSON objects
                const jsonMatches = text.match(/\{[^}]*"success"[^}]*\}/g);
                if (jsonMatches && jsonMatches.length > 1) {
                    console.warn('⚠️ Multiple JSON objects detected:', jsonMatches.length);
                }
                
                const data = JSON.parse(text);
                console.log('📡 Parsed data type:', Array.isArray(data) ? 'array' : typeof data);
                console.log('📡 Parsed data:', data);
                
                return data;
            })
            .then(data => {
                console.log('📡 Realms data received:', data);
                console.log('📡 Data type:', typeof data);
                console.log('📡 Data success:', data.success);
                console.log('📡 Data.data type:', typeof data.data);
                console.log('📡 Data.data keys:', data.data ? Object.keys(data.data) : 'null/undefined');
                console.log('📡 Data.data length:', data.data ? Object.keys(data.data).length : 0);
                
                // Handle unexpected response formats
                if (Array.isArray(data)) {
                    console.error('❌ Received array instead of object:', data);
                    console.error('❌ This suggests wrong API endpoint or data corruption');
                    throw new Error('Invalid response format: received array instead of response object');
                }
                
                if (!data || typeof data !== 'object') {
                    console.error('❌ Invalid data type received:', typeof data, data);
                    throw new Error('Invalid response: expected object, got ' + typeof data);
                }
                
                if (data.success && data.data) {
                    console.log('✅ Success response - caching and displaying realms');
                    
                    // Cache the data
                    realmsCache = data.data;
                    realmsCacheTime = now;
                    
                    console.log('📞 Calling displayRealms() with:', data.data);
                    displayRealms(data.data);
                    
                    console.log('🎭 Hiding loading animation');
                    hideLoadingAnimation();
                    
                    return data.data; // Return the realms data for chaining
                } else {
                    console.error('❌ Response indicates failure or no data:', data);
                    console.error('Error from server:', data.error);
                    hideLoadingAnimation();
                    // Show error in container instead of loading placeholder
                    if (container) {
                        container.innerHTML = `
                            <div style="text-align: center; color: #dc3545; padding: 40px 20px;">
                                
                                <p>Error loading realms</p>
                                <p style="font-size: 0.9rem;">${data.error || 'Unknown error'}</p>
                                <button onclick="window.loadRealms(true)" style="margin-top: 10px; padding: 8px 16px; background: var(--primary-color); color: white; border: none; border-radius: 4px; cursor: pointer;">Retry</button>
                            </div>
                        `;
                    }
                    throw new Error('Error loading realms: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error loading realms:', error);
                hideLoadingAnimation();
                
                // Show error in container
                const container = document.getElementById('realms-list');
                if (container) {
                    container.innerHTML = `
                        <div style="text-align: center; color: #dc3545; padding: 40px 20px;">
                            
                            <p>Connection error</p>
                            <p style="font-size: 0.9rem;">${error.message || 'Network error'}</p>
                            <button onclick="window.loadRealms(true)" style="margin-top: 10px; padding: 8px 16px; background: var(--primary-color); color: white; border: none; border-radius: 4px; cursor: pointer;">Retry</button>
                        </div>
                    `;
                }
                throw error; // Re-throw for proper error handling in chains
            });
        }
        
        function sanitizeSVGMarkup(svg) {
            try {
                if (!svg || typeof svg !== 'string') return svg;
                svg = svg.replace(/(<svg[^>]*)(\swidth="[^"]+")/i, '$1');
                svg = svg.replace(/(<svg[^>]*)(\sheight="[^"]+")/i, '$1');
                svg = svg.replace(/(<svg[^>]*style="[^"]*)\bwidth\s*:\s*[^;]+;?/i, '$1');
                svg = svg.replace(/(<svg[^>]*style="[^"]*)\bheight\s*:\s*[^;]+;?/i, '$1');
                if (!/preserveAspectRatio=/i.test(svg)) {
                    svg = svg.replace(/<svg([^>]*)>/i, '<svg$1 preserveAspectRatio="xMidYMid meet">');
                }
                return svg;
            } catch (e) { return svg; }
        }
        function mhDefaultIconUrl() { return "<?php echo htmlspecialchars(mh_nav_default_icon_url(), ENT_QUOTES); ?>"; }
        function mhTheSvgPrimary(slug, variant) { return "/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=" + encodeURIComponent(String(slug || "")) + "&variant=" + encodeURIComponent(String(variant || "default")); }
        function mhTheSvgFallback(slug, variant) { return ""; }
        function escAttr(s) {
            return String(s == null ? "" : s)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }
        // #region debug-point A:dbg-init
        (function(){
            var dbg = { url: "", sessionId: "navigator-icons-broken", runId: "pre-fix" };
            try {
                dbg.url = "<?php
                    $dbgEnv = '/home/onemeta/public_html/.dbg/navigator-icons-broken.env';
                    $dbgUrl = '';
                    if (is_file($dbgEnv)) {
                        $c = (string)file_get_contents($dbgEnv);
                        if (preg_match('/^DEBUG_SERVER_URL=(.+)$/m', $c, $m)) { $dbgUrl = trim((string)$m[1]); }
                    }
                    echo htmlspecialchars($dbgUrl, ENT_QUOTES);
                ?>";
            } catch (e) {}
            window.__MH_NAV_DBG = dbg;
            window.__mhNavDbgSend = function(hypothesisId, loc, msg, data){
                try {
                    var d = window.__MH_NAV_DBG || {};
                    var payload = { sessionId: d.sessionId, runId: d.runId, hypothesisId: hypothesisId, location: loc, msg: msg, data: data || {}, ts: Date.now() };
                    if (d.url && d.url.indexOf("127.0.0.1") === -1) {
                        fetch(d.url, { method: "POST", body: JSON.stringify(payload) }).catch(function(){});
                        return;
                    }
                    fetch(String(window.location.pathname || ""), {
                        method: "POST",
                        credentials: "include",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "action=dbg_event&event=" + encodeURIComponent(JSON.stringify(payload))
                    }).catch(function(){});
                } catch (e) {}
            };
            window.__mhNavDbgSend("A", "navigator.php:init", "[DEBUG] navigator js init", { href: String(location && location.href || ""), ua: String(navigator && navigator.userAgent || "") });
        })();
        // #endregion
        function normalizePhosphorName(n) {
            n = (n || "").toLowerCase().trim();
            if (!n) return "";
            var map = {
                "chevron-right": "caret-right",
                "chevron-left": "caret-left",
                "chevron-up": "caret-up",
                "chevron-down": "caret-down",
                "angle-right": "caret-right",
                "angle-left": "caret-left",
                "angle-up": "caret-up",
                "angle-down": "caret-down",
            };
            return map[n] || n;
        }
        function phosphorFromAny(raw) {
            var s = (raw || "").trim();
            if (!s) return "";
            var lc = s.toLowerCase();
            var m = lc.match(/\bph-([a-z0-9\-]+)\b/i);
            if (m && m[1]) return normalizePhosphorName(m[1]);
            m = lc.match(/\bfa-([a-z0-9\-]+)\b/i);
            if (m && m[1] && ["solid","regular","brands"].indexOf(m[1]) === -1) return normalizePhosphorName(m[1]);
            m = lc.match(/\biconoir-([a-z0-9\-]+)\b/i);
            if (m && m[1]) return normalizePhosphorName(m[1]);
            if (/^[a-z0-9\-]+$/i.test(lc)) return normalizePhosphorName(lc);
            return "";
        }
        function iconHtmlDefault(z) {
            return '<span class="icon" aria-hidden="true" style="font-size:' + z + 'px;margin-right:6px;"><img src="' + mhDefaultIconUrl() + '" alt="" style="width:1em;height:1em;display:block"></span>';
        }
        function applyIconFixups(root) {
            try {
                var scope = root || document;
                // #region debug-point B:fixups-scan
                try {
                    var imgs = scope.querySelectorAll('img[data-thesvg-primary]').length;
                    var phs = scope.querySelectorAll('i.ph').length;
                    window.__mhNavDbgSend && window.__mhNavDbgSend("B", "navigator.php:applyIconFixups", "[DEBUG] fixups scan", { thesvg_imgs: imgs, phosphor_i: phs });
                } catch (e) {}
                // #endregion
                scope.querySelectorAll('img[data-thesvg-primary]').forEach(function(img) {
                    if (img.__mhIconBound) return;
                    img.__mhIconBound = true;
                    img.addEventListener('error', function() {
                        try {
                            var p = img.getAttribute('data-thesvg-primary') || '';
                            var f = img.getAttribute('data-thesvg-fallback') || '';
                            // #region debug-point B:thesvg-img-error
                            try { window.__mhNavDbgSend && window.__mhNavDbgSend("B", "navigator.php:img.onerror", "[DEBUG] thesvg img error", { src: String(img.src || ""), primary: p, fallback: f }); } catch (e) {}
                            // #endregion
                            var cur = img.getAttribute('src') || '';
                            if (p && cur === p && f) { img.setAttribute('src', f); return; }
                            var span = img.closest('.icon');
                            if (span) span.innerHTML = '<img src="' + mhDefaultIconUrl() + '" alt="" style="width:1em;height:1em;display:block">';
                        } catch (e) {}
                    });
                });
                scope.querySelectorAll('i.ph').forEach(function(el) {
                    try {
                        var c = window.getComputedStyle(el, ':before').content;
                        if (!c || c === 'none' || c === 'normal' || c === '""') {
                            // #region debug-point A:ph-missing
                            try {
                                var span0 = el.closest('.icon');
                                window.__mhNavDbgSend && window.__mhNavDbgSend("A", "navigator.php:ph-missing", "[DEBUG] phosphor glyph missing", { class: String(el.className || ""), data_ph_name: span0 ? String(span0.getAttribute('data-ph-name') || "") : "" });
                            } catch (e) {}
                            // #endregion
                            var span = el.closest('.icon');
                            if (span) {
                                var slug = (span.getAttribute('data-ph-name') || '').toLowerCase().replace(/[^a-z0-9\-]/g, '');
                                if (slug) {
                                    var p = mhTheSvgPrimary(slug, 'default');
                                    var f = mhTheSvgFallback(slug, 'default');
                                    span.innerHTML = '<img src="' + escAttr(p) + '" data-thesvg-primary="' + escAttr(p) + '" data-thesvg-fallback="' + escAttr(f) + '" alt="" style="width:1em;height:1em;display:block">';
                                    try {
                                        var img = span.querySelector('img[data-thesvg-primary]');
                                        if (img && !img.__mhIconBound) {
                                            img.__mhIconBound = true;
                                            img.addEventListener('error', function() {
                                                try {
                                                    var p0 = img.getAttribute('data-thesvg-primary') || '';
                                                    var f0 = img.getAttribute('data-thesvg-fallback') || '';
                                                    // #region debug-point B:ph-fallback-img-error
                                                    try { window.__mhNavDbgSend && window.__mhNavDbgSend("B", "navigator.php:ph-fallback-img.onerror", "[DEBUG] fallback img error", { src: String(img.src || ""), primary: p0, fallback: f0 }); } catch (e) {}
                                                    // #endregion
                                                    var cur0 = img.getAttribute('src') || '';
                                                    if (p0 && cur0 === p0 && f0) { img.setAttribute('src', f0); return; }
                                                    var sp = img.closest('.icon');
                                                    if (sp) sp.innerHTML = '<img src="' + mhDefaultIconUrl() + '" alt="" style="width:1em;height:1em;display:block">';
                                                } catch (e) {}
                                            });
                                        }
                                    } catch (e) {}
                                } else {
                                    span.innerHTML = '<img src="' + mhDefaultIconUrl() + '" alt="" style="width:1em;height:1em;display:block">';
                                }
                            }
                        }
                    } catch (e) {}
                });
            } catch (e) {}
        }
        function renderIconDirect(i, s) {
            try {
                var cfg = window.WidgetsConfig || {};
                var z = parseInt(s || cfg.icon_size || 16, 10) || 16;
                if (!i || typeof i !== 'string') {
                    // #region debug-point E:render-default
                    try { window.__mhNavDbgSend && window.__mhNavDbgSend("E", "navigator.php:renderIconDirect", "[DEBUG] icon default (empty/non-string)", { size: z }); } catch (e) {}
                    // #endregion
                    return iconHtmlDefault(z);
                }
                if (i.indexOf('<svg') !== -1) {
                    var col = cfg.icon_color || '';
                    var clean = sanitizeSVGMarkup(i);
                    // #region debug-point E:render-inline-svg
                    try { window.__mhNavDbgSend && window.__mhNavDbgSend("E", "navigator.php:renderIconDirect", "[DEBUG] icon inline svg", { size: z, head: String(i).slice(0, 40) }); } catch (e) {}
                    // #endregion
                    return '<span class="icon" aria-hidden="true" style="font-size:'+z+'px;margin-right:6px;'+(col?('color:'+col+';fill:'+col+';stroke:'+col+';'):'')+'">'+clean+'</span>';
                }
                var c = i.trim();
                var lc = c.toLowerCase();
                if (lc.indexOf('thesvg:') === 0) {
                    var rest = c.substring(7).trim();
                    var slug = rest;
                    var variant = 'default';
                    if (rest.indexOf(':') !== -1) {
                        var parts = rest.split(':');
                        slug = parts[0] || '';
                        variant = parts.slice(1).join(':') || 'default';
                    } else if (rest.indexOf('/') !== -1) {
                        var parts2 = rest.split('/');
                        slug = parts2[0] || '';
                        variant = parts2.slice(1).join('/') || 'default';
                    }
                    slug = (slug || '').toLowerCase().replace(/[^a-z0-9\-]/g, '');
                    variant = (variant || '').toLowerCase().replace(/[^a-z0-9]/g, '') || 'default';
                    if (!slug) return iconHtmlDefault(z);
                    var p = mhTheSvgPrimary(slug, variant);
                    var f = mhTheSvgFallback(slug, variant);
                    var extra = (variant === 'mono' || variant === 'dark') ? 'filter:invert(1);' : '';
                    // #region debug-point B:render-thesvg
                    try { window.__mhNavDbgSend && window.__mhNavDbgSend("B", "navigator.php:renderIconDirect", "[DEBUG] icon thesvg", { size: z, slug: slug, variant: variant, primary: p, fallback: f }); } catch (e) {}
                    // #endregion
                    return '<span class="icon" aria-hidden="true" style="font-size:'+z+'px;margin-right:6px;"><img src="'+escAttr(p)+'" data-thesvg-primary="'+escAttr(p)+'" data-thesvg-fallback="'+escAttr(f)+'" alt="" style="width:1em;height:1em;display:block;'+extra+'"></span>';
                }
                var name = phosphorFromAny(c);
                if (!name) {
                    var slug2 = lc.replace(/[^a-z0-9\-]/g, '');
                    if (slug2) {
                        var p2 = mhTheSvgPrimary(slug2, 'default');
                        var f2 = mhTheSvgFallback(slug2, 'default');
                        // #region debug-point B:render-thesvg-slug
                        try { window.__mhNavDbgSend && window.__mhNavDbgSend("B", "navigator.php:renderIconDirect", "[DEBUG] icon slug->thesvg", { size: z, slug: slug2, primary: p2, fallback: f2 }); } catch (e) {}
                        // #endregion
                        return '<span class="icon" aria-hidden="true" style="font-size:'+z+'px;margin-right:6px;"><img src="'+escAttr(p2)+'" data-thesvg-primary="'+escAttr(p2)+'" data-thesvg-fallback="'+escAttr(f2)+'" alt="" style="width:1em;height:1em;display:block"></span>';
                    }
                    // #region debug-point E:render-default-unknown
                    try { window.__mhNavDbgSend && window.__mhNavDbgSend("E", "navigator.php:renderIconDirect", "[DEBUG] icon default (unknown)", { size: z, raw: String(c).slice(0, 40) }); } catch (e) {}
                    // #endregion
                    return iconHtmlDefault(z);
                }
                var col2 = cfg.icon_color || '';
                // #region debug-point A:render-ph
                try { window.__mhNavDbgSend && window.__mhNavDbgSend("A", "navigator.php:renderIconDirect", "[DEBUG] icon phosphor", { size: z, name: name, raw: String(c).slice(0, 40) }); } catch (e) {}
                // #endregion
                return '<span class="icon" aria-hidden="true" data-ph-name="'+name+'" style="font-size:'+z+'px;margin-right:6px;'+(col2?('color:'+col2+';'):'')+'"><i class="ph ph-'+name+'"></i></span>';
            } catch (e) { return ''; }
        }
        function normalizeIconSizes(root) { try { var scope = root || document; scope.querySelectorAll('.icon i').forEach(function(el){ el.style.setProperty('font-size','1em','important'); el.style.lineHeight = '1em'; el.style.width = '1em'; el.style.height = '1em'; el.style.verticalAlign = 'middle'; }); scope.querySelectorAll('.icon svg').forEach(function(el){ el.removeAttribute('width'); el.removeAttribute('height'); try { el.style.removeProperty('width'); el.style.removeProperty('height'); } catch(e) {} el.style.setProperty('width','1em','important'); el.style.setProperty('height','1em','important'); el.style.verticalAlign = 'middle'; }); } catch (e) {} }
        
        // Display realms
        function displayRealms(realms) {
            console.log('🎨 displayRealms() called with:', realms);
            console.log('🎨 Realms type:', typeof realms);
            console.log('🎨 Realms keys:', realms ? Object.keys(realms) : 'null/undefined');
            
            const container = document.getElementById('realms-list');
            if (!container) {
                console.error('❌ Realms container not found!');
                return;
            }
            
            console.log('✅ Container found, clearing content...');
            container.innerHTML = '';
            
            if (!realms || Object.keys(realms).length === 0) {
                console.log('⚠️ No realms to display - showing empty message');
                container.innerHTML = `
                    <div style="text-align: center; color: var(--gray-text); padding: 40px 20px;">
                        
                        <p>No realms found</p>
                        <p style="font-size: 0.9rem;">Click "Add Realm" to create your first realm</p>
                    </div>
                `;
                return;
            }
            
            console.log('🏰 Processing realms - count:', Object.keys(realms).length);
            Object.values(realms).forEach((realm, index) => {
                console.log(`🏰 Processing realm ${index + 1}:`, realm.name, realm.id);
                const realmEl = document.createElement('div');
                realmEl.className = 'realm-item';
                realmEl.dataset.realmId = realm.id;
                realmEl.onclick = () => selectRealm(realm.id);
                const accent = (realm && realm.color) ? String(realm.color) : '#6b7280';
                realmEl.style.borderLeft = `6px solid ${accent}`;
                
                // Handle pages display properly
                let pagesDisplay = 'No pages';
                if (Array.isArray(realm.pages) && realm.pages.length > 0) {
                    pagesDisplay = realm.pages.map(page => {
                        if (typeof page === 'object' && page.title) {
                            return page.title;
                        } else if (typeof page === 'string') {
                            return page;
                        } else {
                            return 'Unknown';
                        }
                    }).join(', ');
                } else if (typeof realm.pages === 'string' && realm.pages.trim()) {
                    pagesDisplay = realm.pages;
                }
                
                const baseIcon = (window.WidgetsConfig && window.WidgetsConfig.icon_size) ? parseInt(window.WidgetsConfig.icon_size,10) : 18;
                const realmMul = (window.WidgetsConfig && window.WidgetsConfig.mult_realms) ? parseFloat(window.WidgetsConfig.mult_realms) : 1;
                const realmIconSize = Math.max(12, Math.round(baseIcon * realmMul));
                realmEl.innerHTML = `
                        <div class="realm-header">
                        <span class="realm-name">${renderIconDirect(realm.icon, realmIconSize)}${realm.name || 'Unnamed Realm'}</span>
                        <div class="realm-actions">
                            <button class="btn-action edit-realm-btn" data-realm-id="${realm.id}" title="Edit" aria-label="Edit Realm">Edit</button>
                            <button class="btn-action delete delete-realm-btn" data-realm-id="${realm.id}" title="Delete${['guest'].includes(realm.id) ? ' (System Realm)' : ''}" aria-label="Delete Realm">Delete</button>
                        </div>
                    </div>
                    <div class="realm-description">${realm.description || 'No description available'}</div>
                    <div class="realm-pages">Pages: ${pagesDisplay}</div>
                `;
                const nameEl = realmEl.querySelector('.realm-name');
                if (nameEl) { nameEl.style.color = accent; }
                
                // Add event listeners to the buttons
                const editBtn = realmEl.querySelector('.edit-realm-btn');
                const deleteBtn = realmEl.querySelector('.delete-realm-btn');
                
                if (editBtn) {
                    editBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        console.log('Edit realm button clicked for:', realm.id);
                        editRealm(realm.id);
                    });
                }
                
                if (deleteBtn) {
                    deleteBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        console.log('Delete realm button clicked for:', realm.id);
                        deleteRealm(realm.id);
                    });
                }
                
                container.appendChild(realmEl);
            });
            applyIconFixups(container);
            normalizeIconSizes(container);
            // Do not auto-select any realm; wait for user selection
            // Keep initial UI state: menus and preview prompt until a realm is chosen
        }
        // Switch realm with UI refresh
        function switchRealm(realmId) {
            console.log('switchRealm called with:', realmId);
            // Prevent multiple rapid calls
            if (window.switchingRealm === realmId) {
                console.log('Already switching to realm:', realmId);
                return;
            }
            // Add throttling for realm switches
            const now = Date.now();
            const lastRealmSwitch = window.lastRealmSwitch || 0;
            const timeDiff = now - lastRealmSwitch;
            if (timeDiff < 500) {
                console.log(`Throttling realm switch to: ${realmId}, waiting ${500 - timeDiff}ms`);
                setTimeout(() => {
                    window.switchingRealm = null;
                    switchRealm(realmId);
                }, 500 - timeDiff);
                return;
            }
            window.lastRealmSwitch = now;
            window.switchingRealm = realmId;
            // Use shared overlay loader; remove inline spinner
            const menusList = document.getElementById('menus-list');
            // Show full-screen switching overlay
            // Unified global loader message
            showLoadingAnimation();
            // Failsafe: auto-hide overlay after 35s in case of hung network
            const overlayFailsafeId = setTimeout(() => {
                console.warn('Overlay failsafe triggered for switchRealm; hiding loader');
                hideLoadingAnimation();
            }, 35000);
            // Call switch_realm AJAX endpoint
            const currentUrl = window.navigatorConfig.navigatorUrl;
            // Add timeout via AbortController to avoid indefinite hang
            const controller = new AbortController();
            const switchTimeoutId = setTimeout(() => {
                console.error('switchRealm request timed out after 30s');
                controller.abort();
            }, 30000);
            fetch(currentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=switch_realm&realm_id=${encodeURIComponent(realmId)}&_t=${Date.now()}`,
                signal: controller.signal
            })
            .then(response => {
                // Clear timeout when we have a response
                clearTimeout(switchTimeoutId);
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.warn('switch_realm returned non-JSON response');
                        console.warn('Response preview:', (text || '').substring(0, 200));
                        throw new Error('Non-JSON response from switch_realm');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    console.log('Realm switched successfully to:', realmId);
                    // Update current realm
                    currentRealm = realmId;
                    // Update UI elements
                    document.querySelectorAll('.realm-item').forEach(item => {
                        item.classList.remove('active');
                        if (item.dataset.realmId === realmId) {
                            item.classList.add('active');
                        }
                    });
                    // Enable add menu button
                    const addMenuBtn = document.getElementById('btn-add-menu');
                    if (addMenuBtn) {
                        addMenuBtn.disabled = false;
                    }
                    // Enable explicit Load Menus button
                    const loadMenusBtn = document.getElementById('btn-load-menus');
                    if (loadMenusBtn) {
                        loadMenusBtn.disabled = false;
                    }
                    // Hide no realm selected message
                    const noRealmMsg = document.getElementById('no-realm-selected');
                    if (noRealmMsg) {
                        noRealmMsg.style.display = 'block';
                        const msgP = noRealmMsg.querySelector('p');
                        if (msgP) {
                            msgP.textContent = "Click 'Load Menus' to fetch menus for this realm";
                        }
                    }
                    if (menusList) menusList.style.display = 'none';
                    // Update header realm indicator
                    updateHeaderRealm(realmId);
                    // Add small delay to ensure realm is fully updated before refreshing menus
                    setTimeout(() => {
                        console.log('Refreshing all menu interfaces for realm:', realmId);
                        // Refresh sidebar/hamburger menu with new realm context
                        refreshSidebarMenu(realmId);
                        
                        // Automatically load menus for the selected realm
                        console.log('Auto-loading menus for realm:', realmId);
                        loadMenus(realmId, true);
                        
                        console.log('All menu interfaces refreshed for realm:', realmId);
                    }, 150); // 150ms delay to ensure realm update is processed
                    
                    console.log('Realm switched successfully. Auto-loading menus...');
                    // Reset switching state
                    window.switchingRealm = null;
                    // Hide switching overlay once realm context is updated
                    hideLoadingAnimation();
                } else {
                    console.error('Failed to switch realm:', data.error);
                    showNotification('Failed to switch realm: ' + (data.error || 'Unknown error'), 'error');
                    // Reset switching state on error
                    window.switchingRealm = null;
                    // Restore previous menu display
                    if (currentRealm) {
                        // Keep menus panel idle; user can manually load menus
                    } else {
                        if (menusList) {
                            menusList.innerHTML = '<div style="text-align: center; color: var(--gray-text); padding: 40px 20px;"><p>Please select a realm to view menus</p></div>';
                        }
                    }
                    hideLoadingAnimation();
                }
            })
            .catch(error => {
                // Clear timeout on error
                clearTimeout(switchTimeoutId);
                clearTimeout(overlayFailsafeId);
                console.error('Error switching realm:', error);
                if (error && error.name === 'AbortError') {
                    showNotification('Switching realm timed out (30s limit). This may be due to a slow database query. Please try again or check server logs.', 'error');
                } else {
                    showNotification('Error switching realm: ' + (error.message || String(error)), 'error');
                }
                // Reset switching state on error
                window.switchingRealm = null;
                // Restore previous menu display
                if (currentRealm) {
                    // Keep menus panel idle; user can manually load menus
                } else {
                    if (menusList) {
                        menusList.innerHTML = '<div style="text-align: center; color: var(--gray-text); padding: 40px 20px;"><p>Please select a realm to view menus</p></div>';
                    }
                }
                hideLoadingAnimation();
            });
            // Clear overlay failsafe when the promise settles
            // (Race-safe: clearing an already-fired timeout is harmless)
            Promise.resolve().then(() => {
                // Attach a microtask to clear once the chain completes
            }).finally(() => clearTimeout(overlayFailsafeId));
        }
        // Select realm (now uses switchRealm for proper realm management)
        function selectRealm(realmId) {
            console.log('selectRealm called with:', realmId, '- delegating to switchRealm');
            // Delegate to switchRealm for proper realm management and UI updates
            switchRealm(realmId);
        }
        // Debounced menu reload scheduler to consolidate repeated calls
        function scheduleMenuReload(realmId, forceRefresh = false, delay = 200) {
            if (!realmId) return;
            if (window.menuReloadScheduled) {
                clearTimeout(window.menuReloadScheduled);
                window.menuReloadScheduled = null;
            }
            window.menuReloadScheduled = setTimeout(() => {
                window.menuReloadScheduled = null;
                loadMenus(realmId, forceRefresh);
            }, delay);
        }
        // Load menus for realm
        function loadMenus(realmId, forceRefresh = false) {
            console.log('DEBUG: loadMenus called with realm:', realmId);
            
            // Check client-side cache first
            const now = Date.now();
            if (!forceRefresh && menuCache[realmId] && (now - (menuCacheTime[realmId] || 0)) < MENU_CACHE_TTL) {
                console.log(`Using cached menu data for realm: ${realmId}`);
                displayMenus(menuCache[realmId]);
                return Promise.resolve(menuCache[realmId]);
            }
            
            // Prevent concurrent requests and add throttling
            if (window.loadingRealm === realmId) {
                console.log(`Already loading menus for realm: ${realmId}, skipping duplicate request`);
                return Promise.resolve(); // Return resolved promise to prevent hanging
            }
            
            if (!realmId) {
                console.log('No realm selected; skipping menu load');
                const noRealmMsg = document.getElementById('no-realm-selected');
                const menusList = document.getElementById('menus-list');
                if (noRealmMsg) noRealmMsg.style.display = 'block';
                if (menusList) menusList.style.display = 'none';
                return Promise.resolve();
            }

            // Clear any pending timeout to prevent multiple requests
            if (window.loadMenusTimeout) {
                clearTimeout(window.loadMenusTimeout);
                window.loadMenusTimeout = null;
            }

            // Throttle requests - wait at least 300ms between requests (unless forced)
            const currentTime = Date.now();
            const lastRequest = window.lastMenuRequest || 0;
            const timeDiff = currentTime - lastRequest;
            if (!forceRefresh && timeDiff < 300) {
                console.log(`Throttling menu request for realm: ${realmId}, waiting ${300 - timeDiff}ms`);
                return new Promise((resolve) => {
                    window.loadMenusTimeout = setTimeout(() => {
                        window.loadMenusTimeout = null;
                        loadMenus(realmId, forceRefresh).then(resolve);
                    }, 300 - timeDiff);
                });
            }
            
            window.lastMenuRequest = currentTime;
            window.loadingRealm = realmId;
            
            if (forceRefresh) {
                console.log(`Force refreshing menus for realm: ${realmId} (bypassing throttle)`);
            } else {
                console.log(`Loading menus for realm: ${realmId}`);
            }
            
            // Update button state
            const loadMenusBtn = document.getElementById('btn-load-menus');
            if (loadMenusBtn) {
                loadMenusBtn.disabled = true;
                loadMenusBtn.innerHTML = 'Loading...';
                showLoadingAnimation('Loading menus...');
            }
            
            // Show loader on menus panel
            const noRealmMsg = document.getElementById('no-realm-selected');
            const menusList = document.getElementById('menus-list');
            if (noRealmMsg) noRealmMsg.style.display = 'none';
            if (menusList) {
                menusList.style.display = 'block';
            }
            // Use shared overlay widget instead of inline spinner
            // Unified global loader message
            showLoadingAnimation();

            const startTime = performance.now();
            const currentUrl = window.navigatorConfig.navigatorUrl;
            const timestamp = Date.now();
            
            // Increase timeout to 15 seconds for better reliability
            const controller = new AbortController();
            const timeoutId = setTimeout(() => {
                console.error(`Menu loading timed out after 15 seconds for realm: ${realmId}`);
                controller.abort();
            }, 15000); // 15 second timeout
            
            return fetch(currentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Cache-Control': 'no-cache, no-store, must-revalidate'
                },
                body: `action=get_menus&realm_id=${realmId}&_t=${timestamp}`,
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                const fetchTime = performance.now() - startTime;
                console.log(`Fetch completed in ${fetchTime.toFixed(2)}ms for realm: ${realmId}`);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                const totalTime = performance.now() - startTime;
                console.log(`Menu loading completed in ${totalTime.toFixed(2)}ms for realm: ${realmId}`);
                console.log(`Server execution time: ${data.execution_time || 'unknown'}ms`);
                if (data.success) {
                    // Cache the menu data
                    menuCache[realmId] = data.data || [];
                    menuCacheTime[realmId] = now;
                    
                    displayMenus(data.data || []);
                    // Load preview with delay to avoid overwhelming the server
                    setTimeout(() => loadPreview(realmId), 100);
                    showNotification('Menus loaded successfully!', 'success');
                    hideLoadingAnimation();
                } else {
                    throw new Error(data.error || 'Unknown error loading menus');
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                const totalTime = performance.now() - startTime;
                console.error(`Menu loading failed after ${totalTime.toFixed(2)}ms for realm: ${realmId}`, error);
                
                if (error.name === 'AbortError') {
                    showNotification('Menu loading timed out after 15 seconds. Please check your connection and try again.', 'error');
                    displayMenus([]);
                } else {
                    console.error('Error loading menus:', error);
                    showNotification('Error loading menus: ' + error.message, 'error');
                    displayMenus([]);
                }
                hideLoadingAnimation();
            })
            .finally(() => {
                // Reset loading state and button
                window.loadingRealm = null;
                if (loadMenusBtn) {
                    loadMenusBtn.disabled = false;
                    loadMenusBtn.textContent = 'Load Menus';
                }
            });
        }
        // Update header realm display
        function updateHeaderRealm(realmId) {
            try {
                // Get realm info first
                const currentUrl = window.navigatorConfig.navigatorUrl;
                fetch(currentUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_realm_info&realm_id=${realmId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.realm) {
                        // Track current realm accent color for UI usage
                        currentRealmColor = data.realm.color || '#00d4ff';
                        // Immediately reflect new color in preview if visible
                        try {
                            document.querySelectorAll('.preview-menu-item').forEach(el => {
                                el.style.borderLeftColor = currentRealmColor;
                            });
                            const previewHeader = document.querySelector('.menu-preview-wrapper h4');
                            if (previewHeader) {
                                previewHeader.style.color = currentRealmColor;
                            }
                        } catch (e) {
                            console.debug('Preview color sync skipped:', e);
                        }
                        // Try to update header in parent window (if in iframe)
                        try {
                            const headerRealm = parent.document.querySelector('.realm-indicator');
                            if (headerRealm) {
                                const span = headerRealm.querySelector('span');
                                if (span) span.textContent = data.realm.name || 'Guest User';
                                headerRealm.style.color = currentRealmColor;
                                headerRealm.setAttribute('aria-label', `Current realm: ${data.realm.name || 'Guest User'}`);
                            }
                        } catch (e) {
                            // If we can't access parent, try current window
                            const headerRealm = document.querySelector('.realm-indicator');
                            if (headerRealm) {
                                const span = headerRealm.querySelector('span');
                                if (span) span.textContent = data.realm.name || 'Guest User';
                                headerRealm.style.color = currentRealmColor;
                                headerRealm.setAttribute('aria-label', `Current realm: ${data.realm.name || 'Guest User'}`);
                            }
                        }
                    }
                })
                .catch(error => console.log('Could not update header realm:', error));
            } catch (e) {
                console.log('Header realm update not available in this context');
            }
        }
        // Refresh the sidebar/hamburger menu
        function refreshSidebarMenu(realmId) {
            console.log('refreshSidebarMenu called with realmId:', realmId);
            try {
                // Add cache busting parameter to ensure fresh data
                const currentUrl = window.navigatorConfig.navigatorUrl;
                const timestamp = Date.now();
                fetch(currentUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Cache-Control': 'no-cache, no-store, must-revalidate'
                    },
                    body: `action=get_menu_html&realm_id=${encodeURIComponent(realmId)}&_t=${timestamp}`
                })
                .then(response => {
                    console.log('refreshSidebarMenu response status:', response.status);
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        // Capture response text for debugging and avoid JSON parse error
                        return response.text().then(text => {
                            console.warn('get_menu_html returned non-JSON response');
                            console.warn('Response preview:', (text || '').substring(0, 200));
                            throw new Error('Non-JSON response from get_menu_html');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('refreshSidebarMenu data received:', data);
                    if (data.success) {
                        const html = typeof data.html === 'string' ? data.html : '';
                        console.log('Menu HTML fetched (length:', html.length, ') for realm:', realmId);
                        // .sidebar-nav is obsolete; skip DOM updates to avoid console notices
                    } else {
                        console.error('Failed to get menu HTML:', data.error || `Unknown error for realm ${realmId}`);
                    }
                })
                .catch(error => {
                    console.error('Error refreshing sidebar menu:', error);
                });
            } catch (e) {
                console.error('Exception in refreshSidebarMenu:', e);
            }
        }
        // Display menus
        function displayMenus(menus) {
            const container = document.getElementById('menus-list');
            if (!container) return;
            container.innerHTML = '';
            if (menus.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; color: var(--gray-text); padding: 40px 20px;">
                        
                        <p>No menus found for this realm</p>
                        <p style="font-size: 0.9rem;">Click "Add Menu" to create your first menu item</p>
                    </div>
                `;
                return;
            }
            menus.filter(menu => (menu.title || menu.name) && (menu.title || menu.name).trim() !== '"').forEach((menu, index) => {
                const menuEl = document.createElement('div');
                menuEl.className = 'menu-item';
                menuEl.setAttribute('data-menu-id', menu.id);
                menuEl.setAttribute('data-menu-title', menu.title || menu.name);
                menuEl.setAttribute('data-menu-url', menu.url);
                menuEl.setAttribute('data-source-realm', currentRealm);
                const baseIconMenu = (window.WidgetsConfig && window.WidgetsConfig.icon_size) ? parseInt(window.WidgetsConfig.icon_size,10) : 18;
                const mulMenus = (window.WidgetsConfig && window.WidgetsConfig.mult_menus) ? parseFloat(window.WidgetsConfig.mult_menus) : 1;
                const mulSubs = (window.WidgetsConfig && window.WidgetsConfig.mult_submenus) ? parseFloat(window.WidgetsConfig.mult_submenus) : 0.85;
                const menuIconSize = Math.max(12, Math.round(baseIconMenu * mulMenus));
                const subIconSize = Math.max(10, Math.round(baseIconMenu * mulSubs));
                menuEl.innerHTML = `
                    <div class="realm-header">
                        <span class="realm-name">${renderIconDirect(menu.icon || "", menuIconSize)}${menu.title || menu.name || 'Unnamed Menu'}</span>
                        <div class="realm-actions">
                            <button class="btn-action" onclick="openSubmenuModal('${menu.id}')" title="Add Submenu">Add Submenu</button>
                            <button class="btn-action" onclick="editMenu('${menu.id}')" title="Edit">Edit</button>
                            <button class="btn-action delete" onclick="deleteMenu('${menu.id}')" title="Delete">Delete</button>
                        </div>
                    </div>
                    <div class="realm-description">URL: ${menu.url || 'No URL'}</div>
                    <div class="realm-pages">${menu.submenu && menu.submenu.length > 0 ? `• ${menu.submenu.length} submenu(s)` : ''}</div>
                    ${menu.submenu && menu.submenu.length > 0 ? `
                        <div class="submenu-list">
                            ${menu.submenu.filter(sub => (sub.title || sub.name) && (sub.title || sub.name).trim() !== '"').map(sub => `
                                <div class="submenu-item" data-submenu-id="${sub.id}" data-menu-id="${menu.id}">
                                    <div class="submenu-content">
                                        ${renderIconDirect(sub.icon || "", subIconSize)}<span class="submenu-name">${sub.title || sub.name || 'Unnamed Submenu'}</span>
                                        <span class="submenu-url">${sub.url || 'No URL'}</span>
                                    </div>
                                    <div class="submenu-actions">
                                        <button class="btn-action-small btn-edit-submenu" data-menu-id="${menu.id}" data-submenu-id="${sub.id}" title="Edit Submenu">Edit</button>
                                        <button class="btn-action-small delete btn-delete-submenu" data-menu-id="${menu.id}" data-submenu-id="${sub.id}" title="Delete Submenu">Delete</button>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                `;
                container.appendChild(menuEl);
            });
            applyIconFixups(container);
            normalizeIconSizes(container);
        }
        // Load preview
        function loadPreview(realmId, forceRefresh = false) {
            console.log('loadPreview called with realmId:', realmId);
            const startTime = performance && performance.now ? performance.now() : Date.now();
            const container = document.getElementById('menu-preview');
            const noPreview = document.getElementById('no-preview');
            if (!realmId) {
                // No realm selected; keep prompt visible
                if (container) container.style.display = 'none';
                if (noPreview) {
                    noPreview.style.display = 'block';
                    noPreview.innerHTML = `
                        
                        <p>Select a realm to preview its menu</p>
                    `;
                }
                return;
            }
            // Prepare preview container and show loader
            if (noPreview) noPreview.style.display = 'none';
            if (container) {
                container.style.display = 'block';
                container.setAttribute('aria-busy', 'true');
            }
            // Show full-screen loader for preview
            // Unified global loader message
            showLoadingAnimation();
            const now = Date.now();
            // Serve from cache when valid
            const cachedMenus = menuCache[realmId];
            const cacheValid = cachedMenus && (now - (menuCacheTime[realmId] || 0)) < MENU_CACHE_TTL;
            if (cacheValid && !forceRefresh) {
                let ordered = cachedMenus;
                try {
                    if (typeof window.mhNavigatorApplyOrder === 'function') {
                        ordered = window.mhNavigatorApplyOrder(Array.isArray(cachedMenus) ? cachedMenus.slice() : []);
                    }
                } catch (e) {}
                const hash = JSON.stringify(ordered);
                if (lastPreviewHashByRealm[realmId] !== hash) {
                    lastPreviewHashByRealm[realmId] = hash;
                    displayPreviewMenus(ordered);
                }
                hideLoadingAnimation();
                if (container) container.setAttribute('aria-busy', 'false');
                try { logPerformance && logPerformance('loadPreview (cache)', startTime); } catch (e) {}
                return;
            }
            // Deduplicate concurrent fetches
            if (menuFetchPromises[realmId] && !forceRefresh) {
                console.log('Using in-flight menu fetch for realm:', realmId);
                menuFetchPromises[realmId]
                    .then((data) => {
                        const menus = (data && data.data) ? data.data : [];
                        let ordered = menus;
                        try {
                            if (typeof window.mhNavigatorApplyOrder === 'function') {
                                ordered = window.mhNavigatorApplyOrder(Array.isArray(menus) ? menus.slice() : []);
                            }
                        } catch (e) {}
                        const hash = JSON.stringify(ordered);
                        if (lastPreviewHashByRealm[realmId] !== hash) {
                            lastPreviewHashByRealm[realmId] = hash;
                            displayPreviewMenus(ordered);
                        }
                    })
                    .catch((err) => {
                        console.error('In-flight preview fetch error:', err);
                        displayPreviewError('Network error loading preview');
                    })
                    .finally(() => {
                        hideLoadingAnimation();
                        if (container) container.setAttribute('aria-busy', 'false');
                        try { logPerformance && logPerformance('loadPreview (in-flight)', startTime); } catch (e) {}
                    });
                return;
            }
            const currentUrl = window.navigatorConfig.navigatorUrl;
            const fetchPromise = fetch(currentUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_menus&realm_id=${encodeURIComponent(realmId)}&_t=${Date.now()}`
            })
            .then(response => {
                console.log('Preview response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Preview data received:', data);
                if (data.success) {
                    const menus = data.data || [];
                    console.log('Preview menus count:', menus.length);
                    menuCache[realmId] = menus;
                    menuCacheTime[realmId] = now;
                    let ordered = menus;
                    try {
                        if (typeof window.mhNavigatorApplyOrder === 'function') {
                            ordered = window.mhNavigatorApplyOrder(Array.isArray(menus) ? menus.slice() : []);
                        }
                    } catch (e) {}
                    const hash = JSON.stringify(ordered);
                    if (lastPreviewHashByRealm[realmId] !== hash) {
                        lastPreviewHashByRealm[realmId] = hash;
                        displayPreviewMenus(ordered);
                    } else {
                        console.log('Preview memoized; no DOM update needed');
                    }
                    hideLoadingAnimation();
                    try { logPerformance && logPerformance('loadPreview (network)', startTime, `menus=${menus.length}`); } catch (e) {}
                } else {
                    console.error('Error loading preview menus:', data.error);
                    displayPreviewError(data.error || 'Unknown error');
                    hideLoadingAnimation();
                    try { logPerformance && logPerformance('loadPreview (error)', startTime); } catch (e) {}
                }
            })
            .catch(error => {
                console.error('Error loading preview:', error);
                displayPreviewError('Network error loading preview');
                hideLoadingAnimation();
                try { logPerformance && logPerformance('loadPreview (network error)', startTime); } catch (e) {}
            });
            // Track in-flight fetch
            menuFetchPromises[realmId] = fetchPromise.finally(() => {
                delete menuFetchPromises[realmId];
                if (container) container.setAttribute('aria-busy', 'false');
            });
        }
        // Loader auto-hide handlers are now defined in the reusable loader widget
        // included via cue.php. Remove duplicate listeners here.
        // Display preview menus (new function for structured menu data)
        function displayPreviewMenus(menus) {
            const container = document.getElementById('menu-preview');
            const noPreview = document.getElementById('no-preview');
            if (!menus || menus.length === 0) {
                container.style.display = 'none';
                noPreview.style.display = 'block';
                noPreview.innerHTML = `
                    
                    <p>No menus to preview</p>
                `;
                return;
            }
            noPreview.style.display = 'none';
            container.style.display = 'block';
            const baseIcon = (window.WidgetsConfig && window.WidgetsConfig.icon_size) ? parseInt(window.WidgetsConfig.icon_size,10) : 18;
            const mulMenus = (window.WidgetsConfig && window.WidgetsConfig.mult_menus) ? parseFloat(window.WidgetsConfig.mult_menus) : 1;
            const mulSubs = (window.WidgetsConfig && window.WidgetsConfig.mult_submenus) ? parseFloat(window.WidgetsConfig.mult_submenus) : 0.85;
            const menuIconSize = Math.max(12, Math.round(baseIcon * mulMenus));
            const subIconSize = Math.max(10, Math.round(baseIcon * mulSubs));
            // Generate preview HTML from menu data
            let previewHTML = '';
            menus.forEach(menu => {
                previewHTML += `
                    <div class="preview-menu-item" style="margin-bottom: 15px; padding: 12px; background: rgba(255, 255, 255, 0.05); border-radius: 8px; border-left: 3px solid ${currentRealmColor};">
                        <div style="display: flex; align-items: center; margin-bottom: 8px;">
                            <span style="font-weight: 600; color: var(--light-text);">${renderIconDirect(menu.icon || "", menuIconSize)}${menu.title || 'Unnamed Menu'}</span>
                        </div>
                        <div style="font-size: 0.85rem; color: var(--gray-text); margin-left: 26px;">${menu.url || '#'}</div>`;
                // Add submenu items if they exist
                if (menu.submenu && menu.submenu.length > 0) {
                    previewHTML += `<div style="margin-top: 10px; margin-left: 20px;">`;
                    menu.submenu.forEach(sub => {
                        previewHTML += `
                            <div style="display: flex; align-items: center; margin-bottom: 6px; padding: 6px; background: rgba(255, 255, 255, 0.02); border-radius: 4px;">
                                ${renderIconDirect(sub.icon || "", subIconSize)}<span style="font-size: 0.85rem; color: var(--light-text);">${sub.title || sub.name || 'Unnamed Submenu'}</span>
                                <span style="font-size: 0.75rem; color: var(--gray-text); margin-left: auto;">${sub.url || '#'}</span>
                            </div>`;
                    });
                    previewHTML += `</div>`;
                }
                previewHTML += `</div>`;
            });
            // Create the preview wrapper
            container.innerHTML = `
                <div class="menu-preview-wrapper">
                    <h4 style="color: ${currentRealmColor}; margin-bottom: 15px; font-family: 'Orbitron', monospace;">
                        Live Preview
                    </h4>
                    <div class="menu-preview-content">
                        ${previewHTML}
                    </div>
                </div>
            `;
            applyIconFixups(container);
            normalizeIconSizes(container);
        }
        // Display preview HTML (legacy function for backward compatibility)
        function displayPreviewHTML(html) {
            const container = document.getElementById('menu-preview');
            const noPreview = document.getElementById('no-preview');
            if (!html || html.trim() === '') {
                container.style.display = 'none';
                noPreview.style.display = 'block';
                noPreview.innerHTML = `
                    
                    <p>No menus to preview</p>
                `;
                return;
            }
            noPreview.style.display = 'none';
            container.style.display = 'block';
            // Create a styled preview wrapper for the menu HTML
            container.innerHTML = `
                <div class="menu-preview-wrapper">
                    <h4 style="color: #00d4ff; margin-bottom: 15px; font-family: 'Orbitron', monospace;">
                        Live Preview
                    </h4>
                    <div class="menu-preview-content">
                        <nav class="sidebar-menu-preview">
                            <ul role="menu">${html}</ul>
                        </nav>
                    </div>
                </div>
            `;
        }
        // Display preview error
        function displayPreviewError(errorMessage) {
            const container = document.getElementById('menu-preview');
            const noPreview = document.getElementById('no-preview');
            container.style.display = 'none';
            noPreview.style.display = 'block';
            noPreview.innerHTML = `
                
                <p style="color: #ff6b6b;">Preview Error</p>
                <small style="color: #a1a1aa;">${errorMessage}</small>
            `;
        }
        // Keep the old displayPreview function for backward compatibility
        function displayPreview(menus) {
            const container = document.getElementById('menu-preview');
            const noPreview = document.getElementById('no-preview');
            if (menus.length === 0) {
                container.style.display = 'none';
                noPreview.style.display = 'block';
                noPreview.innerHTML = `
                    
                    <p>No menus to preview</p>
                `;
                return;
            }
            noPreview.style.display = 'none';
            container.style.display = 'block';
            container.innerHTML = '';
            menus.forEach(menu => {
                const previewEl = document.createElement('div');
                previewEl.className = 'preview-item';
                previewEl.innerHTML = `
                    
                    <div>
                        <div style="font-weight: 600; color: var(--light-text);">${menu.title}</div>
                        <div style="font-size: 0.85rem; color: var(--gray-text);">${menu.url}</div>
                    </div>
                `;
                container.appendChild(previewEl);
                // Add submenu items
                if (menu.submenu && menu.submenu.length > 0) {
                    menu.submenu.forEach(sub => {
                        const subEl = document.createElement('div');
                        subEl.className = 'preview-item';
                        subEl.style.marginLeft = '20px';
                        subEl.style.background = 'rgba(255, 255, 255, 0.02)';
                        subEl.innerHTML = `
                            
                            <div>
                                <div style="font-weight: 500; color: var(--light-text); font-size: 0.9rem;">${sub.title || sub.name}</div>
                                <div style="font-size: 0.8rem; color: var(--gray-text);">${sub.url}</div>
                            </div>
                        `;
                        container.appendChild(subEl);
                    });
                }
            });
        }
        // Modal functions - Make them globally accessible
        window.openRealmModal = function(realmId = null) {
            console.log('DEBUG: openRealmModal called with realmId:', realmId);
            const modal = document.getElementById('realm-modal');
            const title = document.getElementById('realm-modal-title');
            const form = document.getElementById('realm-form');
            if (!modal || !title || !form) {
                console.error('Modal elements not found!', {modal: !!modal, title: !!title, form: !!form});
                alert('Error: Modal elements not found');
                return;
            }
            console.log('DEBUG: Modal element found:', modal);
            console.log('DEBUG: Modal current classes:', modal.className);
            // Reset filtered icons
            if (realmId) {
                title.textContent = 'Edit Realm';
                // Load realm data
                loadRealmData(realmId);
            } else {
                title.textContent = 'Add New Realm';
                form.reset();
                document.getElementById('realm-edit-id').value = '';
                document.getElementById('realm-id').value = '';
                document.getElementById('realm-color').value = '#6b7280';
                // Reset color preview
                const colorPreview = document.getElementById('color-preview');
                if (colorPreview) {
                    colorPreview.style.background = '#6b7280';
                    colorPreview.style.color = 'white';
                }
                
                // Sync global accent and update live preview accents
                currentRealmColor = '#6b7280';
                try {
                    document.querySelectorAll('.preview-menu-item').forEach(el => {
                        el.style.borderLeftColor = currentRealmColor;
                    });
                    const previewHeader = document.querySelector('.menu-preview-wrapper h4');
                    if (previewHeader) {
                        previewHeader.style.color = currentRealmColor;
                    }
                } catch (e) {
                    console.debug('Preview accent reset skipped:', e);
                }
            }
            console.log('DEBUG: Adding active class to modal');
            modal.classList.add('active');
            console.log('DEBUG: Modal classes after adding active:', modal.className);
            console.log('DEBUG: Modal computed display:', window.getComputedStyle(modal).display);
            
            
        }
        window.closeRealmModal = function() {
            const modal = document.getElementById('realm-modal');
            if (modal) {
                modal.classList.remove('active');
                console.log('DEBUG: Closed realm modal');
            }
        }

        
        window.openMenuModal = function(menuId = null) {
            console.log('DEBUG: openMenuModal called with menuId:', menuId);
            console.log('Opening menu modal, currentRealm:', currentRealm);
            if (!currentRealm) {
                console.error('DEBUG: No realm selected - showing alert');
                alert('Please select a realm first');
                return;
            }
            
            const modal = document.getElementById('menu-modal');
            const title = document.getElementById('menu-modal-title');
            const form = document.getElementById('menu-form');
            console.log('DEBUG: Modal elements found:', { modal: !!modal, title: !!title, form: !!form });
            if (!modal || !title || !form) {
                console.error('Menu modal elements not found!', { modal, title, form });
                alert('Error: Menu modal elements not found');
                return;
            }
            
            // Reset filtered icons
            document.getElementById('menu-realm-id').value = currentRealm;
            if (menuId) {
                title.textContent = 'Edit Menu';
                // Load menu data
                loadMenuData(menuId);
            } else {
                title.textContent = 'Add New Menu';
                form.reset();
                document.getElementById('menu-edit-id').value = '';
                document.getElementById('menu-realm-id').value = currentRealm;
            }
            
            console.log('DEBUG: Adding active class to menu modal');
            modal.classList.add('active');
            console.log('DEBUG: Menu modal classes after adding active:', modal.className);
            
            // Check if modal is actually visible - if not, create a working modal
            setTimeout(() => {
                const rect = modal.getBoundingClientRect();
                console.log('Menu modal dimensions:', rect.width, rect.height);
                
                if (rect.width === 0 || rect.height === 0) {
                    console.log('Menu modal not visible, creating working modal fallback');
                    createWorkingMenuModal(menuId);
                }
            }, 100);
        }
        
        function createWorkingMenuModal(menuId = null) {
            console.log('Creating working menu modal for menuId:', menuId);
            
            // Create a working menu modal
            const workingModal = document.createElement('div');
            workingModal.id = 'working-menu-modal';
            workingModal.style.cssText = `
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: rgba(0, 0, 0, 0.8) !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                z-index: 999999 !important;
            `;
            
            const modalContent = `
                <div style="
                    background: var(--dark-bg, #1a1a1a);
                    border-radius: 15px;
                    padding: 30px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    border: 1px solid var(--border-color, #333);
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                    color: var(--text-color, white);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #00d4ff;">${menuId ? 'Edit Menu' : 'Add New Menu'}</h3>
                        <button onclick="closeWorkingMenuModal()" style="background: none; border: none; color: #999; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px;">&times;</button>
                    </div>
                    
                    <form id="working-menu-form" style="display: flex; flex-direction: column; gap: 15px;">
                        <input type="hidden" id="working-menu-edit-id" value="${menuId || ''}" />
                        <input type="hidden" id="working-menu-realm-id" value="${currentRealm}" />
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Menu Name *</label>
                            <input type="text" id="working-menu-name" name="name" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            " placeholder="Enter menu name" />
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">URL *</label>
                            <input type="text" id="working-menu-url" name="url" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            " placeholder="Enter URL or page path" />
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Description</label>
                            <textarea id="working-menu-description" name="description" style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                min-height: 80px;
                                resize: vertical;
                                box-sizing: border-box;
                            " placeholder="Optional description"></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button type="button" onclick="closeWorkingMenuModal()" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: 1px solid #666;
                                border-radius: 6px;
                                background: transparent;
                                color: #ccc;
                                cursor: pointer;
                                font-size: 1rem;
                            ">Cancel</button>
                            <button type="submit" id="working-menu-submit" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: none;
                                border-radius: 6px;
                                background: #00d4ff;
                                color: #1a1a1a;
                                cursor: pointer;
                                font-size: 1rem;
                                font-weight: 600;
                            ">${menuId ? 'Update Menu' : 'Create Menu'}</button>
                        </div>
                    </form>
                </div>
            `;
            
            workingModal.innerHTML = modalContent;
            
            // Remove any existing working modal
            const existingWorkingModal = document.getElementById('working-menu-modal');
            if (existingWorkingModal) existingWorkingModal.remove();
            
            document.body.appendChild(workingModal);
            console.log('Working menu modal created and displayed');
            
            // Hide the original modal
            const originalModal = document.getElementById('menu-modal');
            if (originalModal) originalModal.classList.remove('active');
            
            // Load menu data if editing
            if (menuId) {
                loadWorkingMenuData(menuId);
            }
            
            // Set up form submission
            setupWorkingMenuForm(menuId);
        }
        
        window.closeMenuModal = function() {
            const modal = document.getElementById('menu-modal');
            if (modal) {
                modal.classList.remove('active');
                console.log('DEBUG: Closed menu modal');
            }
        }
        
        // Helper functions for working menu modal
        window.closeWorkingMenuModal = function() {
            const workingModal = document.getElementById('working-menu-modal');
            if (workingModal) {
                workingModal.remove();
                console.log('Working menu modal closed');
            }
        }
        
        function setupWorkingMenuForm(menuId = null) {
            const form = document.getElementById('working-menu-form');
            if (!form) return;
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('Working menu form submitted');
                
                const submitBtn = document.getElementById('working-menu-submit');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Saving...';
                showLoadingAnimation('Saving menu...');
                
                const formData = new FormData();
                const action = menuId ? 'update_menu' : 'create_menu';
                formData.append('action', action);
                formData.append('realm_id', currentRealm);
                formData.append('name', document.getElementById('working-menu-name').value);
                formData.append('url', document.getElementById('working-menu-url').value);
                formData.append('description', document.getElementById('working-menu-description').value);
                
                // Add icon field
                const menuIcon = document.getElementById('menu-icon');
                if (menuIcon && menuIcon.value) {
                    formData.append('icon', menuIcon.value);
                }
                
                if (menuId) {
                    formData.append('menu_id', menuId);
                }
                
                const urlEncodedData = new URLSearchParams(formData).toString();
                
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: urlEncodedData
                })
                .then(response => response.json().catch(() => ({ success: false, error: 'Invalid response' })))
                .then(data => {
                    console.log('Working menu form response:', data);
                    if (data.success) {
                        closeWorkingMenuModal();
                        showNotification(data.message || 'Menu saved successfully!', 'success');
                        
                        // Clear cache and refresh menus
                        clearMenuCache(currentRealm);
                        if (currentRealm) {
                            setTimeout(() => {
                                loadMenus(currentRealm, true); // Force refresh
                                loadPreview(currentRealm);
                                refreshSidebarMenu(currentRealm);
                            }, 500);
                        }
                    } else {
                        showNotification('Error: ' + (data.error || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Working menu save error:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
        }
        
        function loadWorkingMenuData(menuId) {
            console.log('Loading working menu data for menuId:', menuId);
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_menus&realm_id=${currentRealm}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                console.log('Working menu data response:', data);
                if (data.success && data.data) {
                    const menu = data.data.find(m => m.id === menuId);
                    console.log('Found menu for editing:', menu);
                    if (menu) {
                        // Populate the working modal fields
                        const nameField = document.getElementById('working-menu-name');
                        const urlField = document.getElementById('working-menu-url');
                        const descField = document.getElementById('working-menu-description');
                        
                        if (nameField) nameField.value = menu.title || menu.name || '';
                        if (urlField) urlField.value = menu.url || '';
                        if (descField) descField.value = menu.description || '';
                        
                        console.log('Working menu fields populated:', {
                            name: nameField?.value,
                            url: urlField?.value,
                            description: descField?.value
                        });
                    } else {
                        console.error('Menu not found in data for ID:', menuId);
                    }
                } else {
                    console.error('Failed to load menu data:', data);
                }
            })
            .catch(error => {
                console.error('Error loading working menu data:', error);
            });
        }
        
        // Setup icon selectors (intentionally left empty - previous malformed block removed)
        // Form submissions
        document.getElementById('realm-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            // Validate realm ID before submission
            const realmId = formData.get('id');
            if (!realmId) {
                alert('Please enter a realm ID');
                document.getElementById('realm-id').focus();
                return;
            }
            if (!/^[a-zA-Z0-9_]+$/.test(realmId)) {
                alert('Realm ID can only contain letters, numbers, and underscores');
                document.getElementById('realm-id').focus();
                return;
            }
            // Convert pages textarea to array
            
            // Add icon field
            const realmIcon = document.getElementById('realm-icon');
            if (realmIcon && realmIcon.value) {
                formData.append('icon', realmIcon.value);
            }
            const pagesText = formData.get('pages');
            const pages = pagesText ? pagesText.split('\n').map(p => p.trim()).filter(p => p) : [];
            const action = formData.get('realm_id') ? 'update_realm' : 'create_realm';
            formData.set('action', action);
            formData.delete('pages');
            formData.append('pages', JSON.stringify(pages));
            const urlEncodedData = new URLSearchParams(formData).toString();
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: urlEncodedData
            })
            .then(response => response.json())
            .then(data => {
                console.log('DEBUG: Realm form response:', data);
                if (data.success) {
                    console.log('DEBUG: Realm saved successfully, closing modal');
                    closeRealmModal();
                    
                    // For create_realm, automatically switch to the new realm
                    if (action === 'create_realm') {
                        const newRealmId = formData.get('id');
                        console.log('DEBUG: Auto-switching to newly created realm:', newRealmId);
                        // Clear cache and reload realms first
                        clearRealmsCache();
                        window.loadRealms(true).then(() => {
                            // Then switch to the new realm
                            setTimeout(() => {
                                switchRealm(newRealmId);
                                showNotification(`Realm "${newRealmId}" created and selected successfully!`, 'success');
                            }, 500); // Small delay to ensure realms are loaded
                        });
                    } else {
                        // For update_realm, just reload the list
                        clearRealmsCache();
                        window.loadRealms(true); // Force refresh
                        showNotification('Realm updated successfully!', 'success');
                    }
                } else {
                    console.error('DEBUG: Realm save error:', data.error);
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the realm');
            });
        });
        document.getElementById('menu-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            console.log('DEBUG: Menu form submission started');
            
            // Disable submit button to prevent double submission
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Saving...';
            showLoadingAnimation('Saving submenu...');
            
            const formData = new FormData(this);
            const action = formData.get('menu_id') ? 'update_menu' : 'create_menu';
            formData.set('action', action);
            
            const urlEncodedData = new URLSearchParams(formData).toString();
            const currentUrl = window.navigatorConfig.navigatorUrl;
            
            console.log('DEBUG: Submitting menu form', { action, url: currentUrl, body: urlEncodedData });
            
            fetch(currentUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: urlEncodedData
            })
            .then(response => {
                console.log('DEBUG: Menu form response status:', response.status);
                return response.json().catch(() => ({ success: false, error: 'Non-JSON response received' }));
            })
            .then(data => {
                console.log('DEBUG: Menu form response data:', data);
                if (data.success) {
                    console.log('DEBUG: Menu operation successful, closing modal');
                    closeMenuModal();
                    showNotification(data.message || 'Menu saved successfully!', 'success');
                    
                    // Refresh the menus list if a realm is selected
                    if (currentRealm) {
                        // Add delay to ensure database is updated
                        setTimeout(() => {
                            console.log('DEBUG: Refreshing menus after save');
                            loadMenus(currentRealm);
                            loadPreview(currentRealm);
                            refreshSidebarMenu(currentRealm);
                        }, 500);
                    }
                } else {
                    console.error('DEBUG: Menu operation failed:', data.error);
                    showNotification('Error: ' + (data.error || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('ERROR: Menu save failed:', error);
                showNotification('Network error occurred while saving menu: ' + error.message, 'error');
            })
            .finally(() => {
                console.log('DEBUG: Restoring submit button');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
        // Submenu form submission
        document.getElementById('submenu-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Saving...';
            showLoadingAnimation('Saving submenu...');
            const formData = new FormData(this);
            const action = formData.get('submenu_id') ? 'update_submenu' : 'add_submenu';
            formData.set('action', action);
            const urlEncodedData = new URLSearchParams(formData).toString();
            console.log('DEBUG: Submitting submenu form', { action, body: urlEncodedData });
            queueRequest(() => {
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: urlEncodedData
                })
                .then(response => response.json().catch(() => ({ success: false, error: 'Non-JSON response' })))
                .then(data => {
                    console.log('DEBUG: Submenu form response:', data);
                    if (data.success) {
                        closeSubmenuModal();
                        if (currentRealm) {
                            scheduleMenuReload(currentRealm, false, 200);
                            loadPreview(currentRealm);
                            refreshSidebarMenu(currentRealm);
                        }
                        showNotification('Submenu item saved successfully!', 'success');
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('ERROR: Submenu save failed:', error);
                    alert('An error occurred while saving the submenu');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
        });
        // CRUD operations
        function editRealm(realmId) {
            openRealmModal(realmId);
        }
        function deleteRealm(realmId) {
            console.log('=== DELETE REALM CLICKED ===');
            console.log('Realm ID:', realmId);
            console.log('Function called successfully');
            // Show the custom confirmation modal instead of confirm() dialog
            showDeleteConfirmationModal(realmId);
        }
        // Global variable to store the realm ID being deleted
        let realmToDelete = null;
        function showDeleteConfirmationModal(realmId) {
            try {
                console.log('=== SHOW DELETE CONFIRMATION MODAL ===');
                console.log('Realm ID:', realmId);
                console.log('Setting realmToDelete...');
                realmToDelete = realmId;
                console.log('realmToDelete set successfully');
                
                // Check if modal exists
                console.log('Looking for delete-confirmation-modal element...');
                const modal = document.getElementById('delete-confirmation-modal');
                console.log('Delete confirmation modal found:', modal);
                if (!modal) {
                    console.error('ERROR: Delete confirmation modal not found in DOM');
                    alert('Error: Delete confirmation modal not found. Please check the page structure.');
                    return;
                }
                
                console.log('Modal found successfully, proceeding with fetch...');
                console.log('Fetching realm data for modal...');
                console.log('Navigator URL:', window.navigatorConfig?.navigatorUrl);
                console.log('Window navigatorConfig:', window.navigatorConfig);
                
                // Check if navigatorConfig exists
                if (!window.navigatorConfig || !window.navigatorConfig.navigatorUrl) {
                    console.error('ERROR: navigatorConfig not found or missing navigatorUrl');
                    alert('Error: Navigator configuration not found');
                    return;
                }
                
                console.log('About to start fetch...');
                
                // Get realm data to populate the modal
                fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_realms'
            })
            .then(response => {
                console.log('Realm data fetch response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Realm data received:', data);
                if (data.success && data.data[realmId]) {
                    const realm = data.data[realmId];
                    const isSystemRealm = ['guest'].includes(realmId);
                    
                    console.log('Populating modal with realm data:', realm);
                    
                    // Populate modal with realm information
                    const nameEl = document.getElementById('delete-realm-name');
                    const idEl = document.getElementById('delete-realm-id');
                    console.log('Modal elements found:', {nameEl, idEl});
                    
                    if (nameEl) nameEl.textContent = realm.name;
                    if (idEl) idEl.textContent = realmId;
                    // Set realm icon
                    // Count menus for this realm
                    fetch(window.navigatorConfig.navigatorUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=get_menus&realm_id=${realmId}`
                    })
                    .then(response => response.json())
                    .then(menuData => {
                        const menuCount = menuData.success && menuData.data[realmId] ? menuData.data[realmId].length : 0;
                        const menuCountEl = document.getElementById('delete-menu-count');
                        console.log('Menu count element found:', menuCountEl);
                        if (menuCountEl) {
                            menuCountEl.textContent = 
                                menuCount === 0 ? 'No menu items will be affected.' :
                                menuCount === 1 ? '1 menu item will be permanently deleted.' :
                                `${menuCount} menu items will be permanently deleted.`;
                        } else {
                            console.error('ERROR: delete-menu-count element not found in modal');
                        }
                    })
                    .catch(() => {
                        const menuCountEl = document.getElementById('delete-menu-count');
                        if (menuCountEl) {
                            menuCountEl.textContent = 'Menu count unavailable.';
                        } else {
                            console.error('ERROR: delete-menu-count element not found in modal (catch block)');
                        }
                    });
                    // Show/hide system realm warning
                    const systemWarning = document.getElementById('system-realm-warning');
                    console.log('System warning element found:', systemWarning);
                    if (systemWarning) {
                        if (isSystemRealm) {
                            systemWarning.style.display = 'block';
                        } else {
                            systemWarning.style.display = 'none';
                        }
                    }
                    
                    // Reset confirmation input
                    const confirmInput = document.getElementById('delete-confirmation-input');
                    const confirmBtn = document.getElementById('confirm-delete-btn');
                    console.log('Modal control elements found:', {confirmInput, confirmBtn});
                    
                    if (confirmInput) {
                        confirmInput.value = '';
                        confirmInput.classList.remove('valid');
                    }
                    if (confirmBtn) {
                        confirmBtn.disabled = true;
                    }
                    
                    // Show the modal
                    console.log('Showing delete confirmation modal...');
                    modal.classList.add('active');
                    console.log('Modal classList after adding active:', modal.classList.toString());
                    console.log('Modal display style:', window.getComputedStyle(modal).display);
                    console.log('Modal visibility:', window.getComputedStyle(modal).visibility);
                    console.log('Modal z-index:', window.getComputedStyle(modal).zIndex);
                    
                    // Check modal content
                    const modalContent = modal.querySelector('.modal-content');
                    console.log('Modal content element:', modalContent);
                    if (modalContent) {
                        console.log('Modal content display:', window.getComputedStyle(modalContent).display);
                        console.log('Modal content visibility:', window.getComputedStyle(modalContent).visibility);
                        console.log('Modal content z-index:', window.getComputedStyle(modalContent).zIndex);
                        
                        // Check positioning and size
                        const rect = modalContent.getBoundingClientRect();
                        console.log('Modal content position:', {
                            top: rect.top,
                            left: rect.left,
                            width: rect.width,
                            height: rect.height,
                            bottom: rect.bottom,
                            right: rect.right
                        });
                        console.log('Viewport size:', {
                            width: window.innerWidth,
                            height: window.innerHeight
                        });
                        
                        // Remove debug-only forced styles and content injection
                        // Keep default modal styles defined in CSS
                        
                        // Create a working delete confirmation modal
                        const workingModal = document.createElement('div');
                        workingModal.id = 'working-delete-modal';
                        workingModal.style.cssText = `
                            position: fixed !important;
                            top: 0 !important;
                            left: 0 !important;
                            width: 100% !important;
                            height: 100% !important;
                            background: rgba(0, 0, 0, 0.8) !important;
                            display: flex !important;
                            align-items: center !important;
                            justify-content: center !important;
                            z-index: 999999 !important;
                        `;
                        workingModal.innerHTML = `
                            <div style="
                                background: var(--dark-bg, #1a1a1a);
                                border-radius: 15px;
                                padding: 30px;
                                max-width: 500px;
                                width: 90%;
                                border: 1px solid var(--border-color, #333);
                                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                                color: var(--text-color, white);
                            ">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                    <h3 style="color: #e74c3c; margin: 0;">⚠️ Confirm Realm Deletion</h3>
                                    <button onclick="closeDeleteConfirmationModal()" style="background: none; border: none; color: #999; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px;">&times;</button>
                                </div>
                                
                                <div style="background: rgba(231, 76, 60, 0.1); border: 1px solid rgba(231, 76, 60, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                                    <div style="display: flex; align-items: flex-start; gap: 12px;">
                                        
                                        <div>
                                            <h4 style="margin: 0 0 8px 0; color: #e74c3c; font-size: 1rem;">This action cannot be undone!</h4>
                                            <p style="margin: 0; color: #ccc; line-height: 1.4;">You are about to permanently delete the following realm and all its associated menu items:</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="background: rgba(255, 255, 255, 0.05); border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                        <div style="width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1rem; background: ${realm.color || '#6b7280'}; color: white;">
                                            ${realm.name.charAt(0).toUpperCase()}
                                        </div>
                                        <div>
                                            <div style="font-weight: 600; font-size: 1.1rem; color: var(--text-color, white);">${realm.name}</div>
                                            <div style="font-size: 0.9rem; color: #999; font-family: monospace;">${realmId}</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 12px; margin-top: 30px;">
                                    <button onclick="closeDeleteConfirmationModal()" style="
                                        flex: 1;
                                        padding: 12px 24px;
                                        border: 1px solid #666;
                                        border-radius: 6px;
                                        background: transparent;
                                        color: #ccc;
                                        cursor: pointer;
                                        font-size: 1rem;
                                    ">Cancel</button>
                                    <button onclick="confirmRealmDeletion()" style="
                                        flex: 1;
                                        padding: 12px 24px;
                                        border: none;
                                        border-radius: 6px;
                                        background: #e74c3c;
                                        color: white;
                                        cursor: pointer;
                                        font-size: 1rem;
                                        font-weight: 600;
                                    ">Delete Realm</button>
                                </div>
                            </div>
                        `;
                        
                        // Remove any existing working modal and add the new one
                        const existingWorkingModal = document.getElementById('working-delete-modal');
                        if (existingWorkingModal) existingWorkingModal.remove();
                        
                        document.body.appendChild(workingModal);
                        console.log('Working delete modal created and displayed');
                        
                        // Hide the original modal
                        modal.classList.remove('active');
                        
                        // Check dimensions one more time
                        setTimeout(() => {
                            const finalRect = modalContent.getBoundingClientRect();
                            console.log('Final modal content position:', {
                                top: finalRect.top,
                                left: finalRect.left,
                                width: finalRect.width,
                                height: finalRect.height,
                                bottom: finalRect.bottom,
                                right: finalRect.right
                            });
                        }, 100);
                    }
                } else {
                    console.error('ERROR: Realm data not found for realm:', realmId);
                    console.error('Available realms:', Object.keys(data.data || {}));
                    alert('Error: Realm information not found');
                }
            })
            .catch(error => {
                console.error('Error loading realm data:', error);
                alert('Error loading realm information');
            });
            } catch (error) {
                console.error('Error in showDeleteConfirmationModal:', error);
                alert('Error showing delete confirmation modal: ' + error.message);
            }
        }
        function closeDeleteConfirmationModal() {
            document.getElementById('delete-confirmation-modal').classList.remove('active');
            
            // Remove the working modal if it exists
            const workingModal = document.getElementById('working-delete-modal');
            if (workingModal) {
                workingModal.remove();
                console.log('Working delete modal removed');
            }
            
            // Also remove the old test modal if it exists (backwards compatibility)
            const testModal = document.getElementById('test-modal-fallback');
            if (testModal) {
                testModal.remove();
                console.log('Test modal removed');
            }
            
            realmToDelete = null;
        }
        function confirmRealmDeletion() {
            console.log('=== REALM DELETION STARTED ===');
            console.log('Timestamp:', new Date().toISOString());
            console.log('Realm to delete:', realmToDelete);
            
            if (!realmToDelete) {
                console.error('ERROR: No realm to delete set');
                showNotification('Error: No realm selected for deletion', 'error');
                return;
            }
            
            console.log('Starting deletion process for realm:', realmToDelete);
            console.log('Navigator config:', window.navigatorConfig);
            
            // Try to find the delete button - could be in original modal or working modal
            let deleteBtn = document.getElementById('confirm-delete-btn');
            if (!deleteBtn) {
                // Check if we're using the working modal or test modal
                const workingModal = document.getElementById('working-delete-modal');
                const testModal = document.getElementById('test-modal-fallback');
                if (workingModal || testModal) {
                    console.log('Using working/test modal, no button to disable');
                    // For working/test modal, we don't need to disable a button
                } else {
                    console.error('ERROR: Delete button not found');
                    showNotification('Error: Delete button not found', 'error');
                    return;
                }
            }
            
            let originalText = '';
            if (deleteBtn) {
                originalText = deleteBtn.textContent;
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = 'Deleting...';
                showLoadingAnimation('Deleting realm...');
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'delete_realm');
            formData.append('realm_id', realmToDelete);
            
            console.log('Sending DELETE request:', {
                action: 'delete_realm',
                realm_id: realmToDelete,
                url: window.navigatorConfig.navigatorUrl
            });
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => {
                console.log('Response received - Status:', response.status);
                console.log('Response headers:', [...response.headers.entries()]);
                
                // Check content type
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    console.error('ERROR: Non-JSON response received');
                    console.error('Content-Type:', contentType);
                    return response.text().then(text => {
                        console.error('Response text:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON response. Check browser Network tab for details.');
                    });
                }
                
                return response.text().then(text => {
                    console.log('Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('ERROR: Failed to parse JSON response:', e);
                        console.error('Response text was:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                console.log('Parsed response data:', data);
                
                if (data.success) {
                    console.log('SUCCESS: Realm deletion completed');
                    showNotification(data.message || `Realm "${realmToDelete}" deleted successfully!`, 'success');
                    
                    // Close the modal first
                    closeDeleteConfirmationModal();
                    
                    // Clear cache and reload realms list
                    clearRealmsCache();
                    clearMenuCache(); // Clear all menu caches since realm is deleted
                    window.loadRealms(true).then(() => {
                        console.log('Realms list reloaded after deletion');
                    }).catch(error => {
                        console.error('Error reloading realms:', error);
                        showNotification('Realm deleted but failed to reload list. Please refresh the page.', 'warning');
                    });
                    
                    // If we deleted the current realm, reset the interface
                    if (currentRealm === realmToDelete) {
                        console.log('Deleted current realm, resetting interface');
                        currentRealm = null;
                        document.getElementById('no-realm-selected').style.display = 'block';
                        document.getElementById('menus-list').style.display = 'none';
                        document.getElementById('btn-add-menu').disabled = true;
                        document.getElementById('btn-load-menus').disabled = true;
                        
                        // Reset preview
                        const container = document.getElementById('menu-preview');
                        const noPreview = document.getElementById('no-preview');
                        if (container) container.style.display = 'none';
                        if (noPreview) {
                            noPreview.style.display = 'block';
                            noPreview.innerHTML = `
                                <p>Select a realm to preview its menu</p>
                            `;
                        }
                    }
                } else {
                    console.error('ERROR: Server returned error:', data.error);
                    showNotification('Failed to delete realm: ' + (data.error || 'Unknown server error'), 'error');
                }
            })
            .catch(error => {
                console.error('NETWORK ERROR:', error);
                console.error('Error details:', error.message);
                console.error('Error stack:', error.stack);
                showNotification('Network error while deleting realm: ' + error.message, 'error');
            })
            .finally(() => {
                // Restore button state
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalText;
                }
                console.log('=== REALM DELETION PROCESS COMPLETED ===');
            });
        }
        function editMenu(menuId) {
            openMenuModal(menuId);
        }
        function deleteMenu(menuId) {
            if (!confirm('Are you sure you want to delete this menu?')) {
                return;
            }
            
            console.log('DEBUG: Deleting menu:', menuId);
            
            const formData = new URLSearchParams();
            formData.append('action', 'delete_menu');
            formData.append('realm_id', currentRealm);
            formData.append('menu_id', menuId);
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString()
            })
            .then(response => {
                console.log('DEBUG: Delete menu response status:', response.status);
                return response.json().catch(() => ({ success: false, error: 'Non-JSON response received' }));
            })
            .then(data => {
                console.log('DEBUG: Delete menu response data:', data);
                if (data.success) {
                    console.log('DEBUG: Menu deleted successfully');
                    showNotification(data.message || 'Menu deleted successfully!', 'success');
                    
                    // Clear cache and refresh the interface
                    clearMenuCache(currentRealm);
                    if (currentRealm) {
                        setTimeout(() => {
                            loadMenus(currentRealm, true); // Force refresh
                            loadPreview(currentRealm);
                            refreshSidebarMenu(currentRealm);
                        }, 500);
                    }
                } else {
                    console.error('DEBUG: Menu deletion failed:', data.error);
                    showNotification('Error: ' + (data.error || 'Failed to delete menu'), 'error');
                }
            })
            .catch(error => {
                console.error('ERROR: Delete menu failed:', error);
                showNotification('Network error occurred while deleting menu: ' + error.message, 'error');
            });
        }
        // Submenu Management Functions
        function openSubmenuModal(menuId, submenuId = null) {
            if (!currentRealm) {
                alert('Please select a realm first');
                return;
            }
            const modal = document.getElementById('submenu-modal');
            const title = document.getElementById('submenu-modal-title');
            const form = document.getElementById('submenu-form');
            document.getElementById('submenu-realm-id').value = currentRealm;
            document.getElementById('submenu-menu-id').value = menuId;
            if (submenuId) {
                title.textContent = 'Edit Submenu Item';
                // Load submenu data
                loadSubmenuData(menuId, submenuId);
            } else {
                title.textContent = 'Add Submenu Item';
                form.reset();
                document.getElementById('submenu-edit-id').value = '';
                document.getElementById('submenu-realm-id').value = currentRealm;
                document.getElementById('submenu-menu-id').value = menuId;
            }
            modal.classList.add('active');
            
            // Check if modal is actually visible - if not, create a working modal
            setTimeout(() => {
                const rect = modal.getBoundingClientRect();
                console.log('Submenu modal dimensions:', rect.width, rect.height);
                
                if (rect.width === 0 || rect.height === 0) {
                    console.log('Submenu modal not visible, creating working modal fallback');
                    createWorkingSubmenuModal(menuId, submenuId);
                }
            }, 100);
        }
        function closeSubmenuModal() {
            const modal = document.getElementById('submenu-modal');
            if (modal) {
                modal.classList.remove('active');
                console.log('DEBUG: Closed submenu modal');
            }
        }
        
        function createWorkingSubmenuModal(menuId, submenuId = null) {
            console.log('Creating working submenu modal for menuId:', menuId, 'submenuId:', submenuId);
            
            // Create a working submenu modal
            const workingModal = document.createElement('div');
            workingModal.id = 'working-submenu-modal';
            workingModal.style.cssText = `
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: rgba(0, 0, 0, 0.8) !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                z-index: 999999 !important;
            `;
            
            const modalContent = `
                <div style="
                    background: #1a237e !important;
                    border-radius: 15px;
                    padding: 30px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    border: 1px solid #283593;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
                    color: white !important;
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #00d4ff;">${submenuId ? 'Edit Submenu Item' : 'Add Submenu Item'}</h3>
                        <button onclick="closeWorkingSubmenuModal()" style="background: none; border: none; color: #999; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px;">&times;</button>
                    </div>
                    
                    <form id="working-submenu-form" style="display: flex; flex-direction: column; gap: 15px;">
                        <input type="hidden" id="working-submenu-edit-id" value="${submenuId || ''}" />
                        <input type="hidden" id="working-submenu-realm-id" value="${currentRealm}" />
                        <input type="hidden" id="working-submenu-menu-id" value="${menuId}" />
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">Submenu Name *</label>
                            <input type="text" id="working-submenu-name" name="name" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            " placeholder="Enter submenu name" />
                        </div>
                        
                        <div>
                            <label style="display: block; margin-bottom: 5px; color: #ccc;">URL *</label>
                            <input type="text" id="working-submenu-url" name="url" required style="
                                width: 100%;
                                padding: 12px;
                                border: 1px solid #444;
                                border-radius: 6px;
                                background: #2a2a2a;
                                color: white;
                                font-size: 1rem;
                                box-sizing: border-box;
                            " placeholder="Enter URL or page path" />
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button type="button" onclick="closeWorkingSubmenuModal()" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: 1px solid #666;
                                border-radius: 6px;
                                background: transparent;
                                color: #ccc;
                                cursor: pointer;
                                font-size: 1rem;
                            ">Cancel</button>
                            <button type="submit" id="working-submenu-submit" style="
                                flex: 1;
                                padding: 12px 24px;
                                border: none;
                                border-radius: 6px;
                                background: #00d4ff;
                                color: #1a1a1a;
                                cursor: pointer;
                                font-size: 1rem;
                                font-weight: 600;
                            ">${submenuId ? 'Update Submenu' : 'Create Submenu'}</button>
                        </div>
                    </form>
                </div>
            `;
            
            workingModal.innerHTML = modalContent;
            
            // Remove any existing working modal
            const existingWorkingModal = document.getElementById('working-submenu-modal');
            if (existingWorkingModal) existingWorkingModal.remove();
            
            document.body.appendChild(workingModal);
            console.log('Working submenu modal created and displayed');
            
            // Hide the original modal
            const originalModal = document.getElementById('submenu-modal');
            if (originalModal) originalModal.classList.remove('active');
            
            // Load submenu data if editing
            if (submenuId) {
                loadWorkingSubmenuData(menuId, submenuId);
            }
            
            // Set up form submission
            setupWorkingSubmenuForm(menuId, submenuId);
        }
        
        // Helper functions for working submenu modal
        window.closeWorkingSubmenuModal = function() {
            const workingModal = document.getElementById('working-submenu-modal');
            if (workingModal) {
                workingModal.remove();
                console.log('Working submenu modal closed');
            }
        }
        
        function loadWorkingSubmenuData(menuId, submenuId) {
            console.log('Loading working submenu data for menuId:', menuId, 'submenuId:', submenuId);
            
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_menus&realm_id=${currentRealm}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                console.log('Working submenu data response:', data);
                if (data.success && data.data) {
                    const menu = data.data.find(m => m.id === menuId);
                    if (menu && menu.submenu) {
                        const submenu = menu.submenu.find(s => s.id === submenuId);
                        console.log('Found submenu for editing:', submenu);
                        if (submenu) {
                            // Populate the working modal fields
                            const nameField = document.getElementById('working-submenu-name');
                            const urlField = document.getElementById('working-submenu-url');
                            
                            if (nameField) nameField.value = submenu.title || submenu.name || '';
                            if (urlField) urlField.value = submenu.url || '';
                            
                            console.log('Working submenu fields populated:', {
                                name: nameField?.value,
                                url: urlField?.value
                            });
                        } else {
                            console.error('Submenu not found in data for ID:', submenuId);
                        }
                    } else {
                        console.error('Menu or submenu not found in data for ID:', menuId);
                    }
                } else {
                    console.error('Failed to load submenu data:', data);
                }
            })
            .catch(error => {
                console.error('Error loading working submenu data:', error);
            });
        }
        
        function setupWorkingSubmenuForm(menuId, submenuId = null) {
            const form = document.getElementById('working-submenu-form');
            if (!form) return;
            
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('Working submenu form submitted');
                
                const submitBtn = document.getElementById('working-submenu-submit');
                const originalText = submitBtn.textContent;
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Saving...';
                showLoadingAnimation('Saving submenu...');
                
                const formData = new FormData();
                const action = submenuId ? 'update_submenu' : 'add_submenu';
                formData.append('action', action);
                formData.append('realm_id', currentRealm);
                formData.append('menu_id', menuId);
                formData.append('name', document.getElementById('working-submenu-name').value);
                formData.append('url', document.getElementById('working-submenu-url').value);
                
                // Add icon field AFTER FormData is created
                const submenuIcon = document.getElementById('submenu-icon');
                if (submenuIcon && submenuIcon.value) {
                    formData.append('icon', submenuIcon.value);
                }
                
                if (submenuId) {
                    formData.append('submenu_id', submenuId);
                }
                
                const urlEncodedData = new URLSearchParams(formData).toString();
                
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: urlEncodedData
                })
                .then(response => response.json().catch(() => ({ success: false, error: 'Invalid response' })))
                .then(data => {
                    console.log('Working submenu form response:', data);
                    if (data.success) {
                        closeWorkingSubmenuModal();
                        showNotification(data.message || 'Submenu saved successfully!', 'success');
                        
                        // Clear cache and refresh menus immediately
                        clearMenuCache(currentRealm);
                        console.log('Submenu saved, refreshing menus for realm:', currentRealm);
                        
                        if (currentRealm) {
                            // Immediate refresh with shorter delay
                            scheduleMenuReload(currentRealm, true, 150);
                            loadPreview(currentRealm);
                            refreshSidebarMenu(currentRealm);
                        }
                    } else {
                        showNotification('Error: ' + (data.error || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Working submenu save error:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
        }
        function editSubmenu(menuId, submenuId) {
            openSubmenuModal(menuId, submenuId);
        }
        function deleteSubmenu(menuId, submenuId) {
            if (confirm('Are you sure you want to delete this submenu item?')) {
                const formData = new URLSearchParams();
                formData.append('action', 'delete_submenu');
                formData.append('realm_id', currentRealm);
                formData.append('menu_id', menuId);
                formData.append('submenu_id', submenuId);
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        scheduleMenuReload(currentRealm, false, 200);
                        loadPreview(currentRealm);
                        refreshSidebarMenu(currentRealm);
                        showNotification('Submenu item deleted successfully!', 'success');
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the submenu');
                });
        }
        }
        function loadRealmData(realmId) {
            console.log('Loading realm data for ID:', realmId);
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_realms'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Realm data response:', data);
                if (data.success && data.data) {
                    // Find the realm in the data structure
                    let realm = null;
                    
                    // Handle object structure (new format with realm IDs as keys)
                    if (typeof data.data === 'object' && !Array.isArray(data.data)) {
                        realm = data.data[realmId];
                    }
                    // Fallback to array structure (old format)
                    else if (Array.isArray(data.data)) {
                        realm = data.data.find(r => r.id === realmId);
                    }
                    
                    console.log('Found realm:', realm);
                    if (realm) {
                        document.getElementById('realm-edit-id').value = realmId;
                        document.getElementById('realm-id').value = realm.id;
                        document.getElementById('realm-name').value = realm.name || '';
                        document.getElementById('realm-description').value = realm.description || '';
                        document.getElementById('realm-color').value = realm.color || '#6b7280';
                        
                        // Handle pages - convert object array to text
                        let pagesText = '';
                        if (Array.isArray(realm.pages)) {
                            pagesText = realm.pages.map(page => {
                                if (typeof page === 'object' && page.title) {
                                    return page.title;
                                } else if (typeof page === 'string') {
                                    return page;
                                }
                                return '';
                            }).filter(p => p).join('\n');
                        } else if (typeof realm.pages === 'string') {
                            pagesText = realm.pages;
                        }
                        document.getElementById('realm-pages').value = pagesText;
                        
                        const autoDetectEl = document.getElementById('realm-auto-detect');
                        if (autoDetectEl) {
                            autoDetectEl.checked = !!realm.auto_detect;
                        }
                        const rulesEl = document.getElementById('realm-detection-rules');
                        if (rulesEl) {
                            rulesEl.value = realm.detection_rules || '';
                        }
                        
                        // Update color preview
                        const colorPreview = document.getElementById('color-preview');
                        if (colorPreview) {
                            const color = realm.color || '#6b7280';
                            colorPreview.style.background = color;
                            colorPreview.style.color = getContrastColor(color);
                            // Sync global accent and immediate preview accents
                            currentRealmColor = color;
                            try {
                                document.querySelectorAll('.preview-menu-item').forEach(el => {
                                    el.style.borderLeftColor = currentRealmColor;
                                });
                                const previewHeader = document.querySelector('.menu-preview-wrapper h4');
                                if (previewHeader) {
                                    previewHeader.style.color = currentRealmColor;
                                }
                            } catch (e) {
                                console.debug('Preview accent apply skipped:', e);
                            }
                        }
                        
                        console.log('Realm data loaded successfully');
                    } else {
                        console.error('Realm not found in response:', realmId);
                        alert('Realm not found');
                    }
                } else {
                    console.error('Invalid response:', data);
                    alert('Failed to load realm data');
                }
            })
            .catch(error => {
                console.error('Error loading realm data:', error);
                alert('Error loading realm data: ' + error.message);
            });
        }
        function loadMenuData(menuId) {
            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_menus&realm_id=${currentRealm}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const menu = data.data.find(m => m.id === menuId);
                    if (menu) {
                        document.getElementById('menu-edit-id').value = menuId;
                        document.getElementById('menu-name').value = menu.title;
                        document.getElementById('menu-url').value = menu.url || '';
                    }
                }
            })
            .catch(error => console.error('Error loading menu data:', error));
        }
        function loadSubmenuData(menuId, submenuId) {
            const nameField = document.getElementById('submenu-name');
            const urlField = document.getElementById('submenu-url');
            const editIdField = document.getElementById('submenu-edit-id');
            if (editIdField) editIdField.value = submenuId || '';
            if (nameField) { nameField.disabled = true; nameField.placeholder = 'Loading...'; }
            if (urlField) { urlField.disabled = true; urlField.placeholder = 'Loading...'; }

            fetch(window.navigatorConfig.navigatorUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_menus&realm_id=${currentRealm}&_t=${Date.now()}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data && data.data.length > 0) {
                    const menu = data.data.find(m => m.id === menuId);
                    if (menu && Array.isArray(menu.submenu)) {
                        const submenu = menu.submenu.find(s => s.id === submenuId);
                        if (submenu) {
                            const resolvedName = submenu.title || submenu.name || submenu.label || '';
                            if (nameField) nameField.value = resolvedName;
                            if (urlField) urlField.value = submenu.url || '';
                        } else {
                            showNotification('Submenu not found for editing.', 'error');
                        }
                    } else {
                        showNotification('No submenu data available for this menu.', 'error');
                    }
                } else {
                    showNotification('Failed to load submenu data.', 'error');
                }
            })
            .catch(error => {
                console.error('Error loading submenu data:', error);
                showNotification('Error loading submenu data: ' + error.message, 'error');
            })
            .finally(() => {
                if (nameField) { nameField.disabled = false; nameField.placeholder = ''; }
                if (urlField) { urlField.disabled = false; urlField.placeholder = ''; }
            });
        }
        function initializeDeleteConfirmationModal() {
            // Handle confirmation input validation
            const confirmInput = document.getElementById('delete-confirmation-input');
            const confirmBtn = document.getElementById('confirm-delete-btn');
            if (confirmInput && confirmBtn) {
                confirmInput.addEventListener('input', function() {
                    const isValid = this.value.trim().toLowerCase() === 'delete';
                    this.classList.toggle('valid', isValid);
                    confirmBtn.disabled = !isValid;
                });
                // Handle Enter key in confirmation input
                confirmInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !confirmBtn.disabled) {
                        confirmRealmDeletion();
                    }
                });
            }
            // Handle modal close events
            const modal = document.getElementById('delete-confirmation-modal');
            const cancelBtn = document.getElementById('cancel-delete-btn');
            if (modal) {
                // Close modal when clicking outside
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeDeleteConfirmationModal();
                    }
                });
                // Handle Escape key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeDeleteConfirmationModal();
                    }
                });
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', closeDeleteConfirmationModal);
            }
            if (confirmBtn) {
                confirmBtn.addEventListener('click', confirmRealmDeletion);
            }
        }
        
        // Utility function to get contrasting text color for a background color
        function getContrastColor(hexColor) {
            // Convert hex to RGB
            const hex = hexColor.replace('#', '');
            const r = parseInt(hex.substr(0, 2), 16);
            const g = parseInt(hex.substr(2, 2), 16);
            const b = parseInt(hex.substr(4, 2), 16);
            
            // Calculate luminance using the formula for relative luminance
            const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            
            // Return white for dark backgrounds, black for light backgrounds
            return luminance > 0.5 ? '#000000' : '#ffffff';
        }
        
        function showNotification(message, type = 'info') {
            try {
                const instance = window.popupNotice || window.globalPopupNotice || (typeof window.PopupNotice !== 'undefined' ? new window.PopupNotice() : null);
                if (instance && typeof instance.show === 'function') {
                    if (!window.popupNotice) {
                        window.popupNotice = instance;
                    }
                    const msg = (function () {
                        if (message === null || typeof message === 'undefined') return '';
                        const s = String(message);
                        if (s.indexOf('<') === -1) return s;
                        const tmp = document.createElement('div');
                        tmp.innerHTML = s;
                        return (tmp.textContent || tmp.innerText || '').trim();
                    })();
                    instance.show(msg, type || 'info');
                    return;
                }
            } catch (_) {}

            const notification = document.createElement('div');
            let backgroundColor;
            switch (type) {
                case 'success':
                    backgroundColor = 'var(--gradient-primary)';
                    break;
                case 'error':
                    backgroundColor = 'linear-gradient(135deg, #ef4444, #dc2626)';
                    break;
                case 'warning':
                    backgroundColor = 'linear-gradient(135deg, #f59e0b, #d97706)';
                    break;
                default:
                    backgroundColor = 'var(--gradient-secondary)';
            }
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${backgroundColor};
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                z-index: 10002;
                box-shadow: var(--shadow-card);
                transform: translateX(400px);
                transition: transform 0.3s ease;
                max-width: 350px;
                word-wrap: break-word;
            `;
            notification.textContent = (message === null || typeof message === 'undefined') ? '' : String(message);
            document.body.appendChild(notification);
            setTimeout(function() {
                notification.style.transform = 'translateX(0)';
            }, 100);
            const duration = (type === 'error') ? 5000 : 4000;
            setTimeout(function() {
                notification.style.transform = 'translateX(400px)';
                setTimeout(function() {
                    if (notification.parentNode) {
                        document.body.removeChild(notification);
                    }
                }, 300);
            }, duration);
        }
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;
                    // Remove active class from all tabs and contents
                    tabButtons.forEach(btn => { btn.classList.remove('active'); btn.setAttribute('aria-selected','false'); });
                    tabContents.forEach(content => content.classList.remove('active'));
                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    this.setAttribute('aria-selected','true');
                    document.getElementById(targetTab + '-tab').classList.add('active');
                    // Load data based on active tab
                    if (targetTab === 'menus') {
                        const noRealmMsg = document.getElementById('no-realm-selected');
                        const menusList = document.getElementById('menus-list');
                        const loadMenusBtn = document.getElementById('btn-load-menus');
                        
                        if (currentRealm) {
                            // Enable the Load Menus button
                            if (loadMenusBtn) {
                                loadMenusBtn.disabled = false;
                            }
                            
                            // Check if we have cached menus to display
                            const now = Date.now();
                            if (menuCache[currentRealm] && (now - (menuCacheTime[currentRealm] || 0)) < MENU_CACHE_TTL) {
                                // Show cached menus
                                if (noRealmMsg) noRealmMsg.style.display = 'none';
                                if (menusList) menusList.style.display = 'block';
                                displayMenus(menuCache[currentRealm]);
                            } else {
                                // Show instruction message and enable button
                                if (noRealmMsg) {
                                    noRealmMsg.style.display = 'block';
                                    const msgP = noRealmMsg.querySelector('p');
                                    if (msgP) msgP.textContent = "Click 'Load Menus' to fetch menus for this realm";
                                }
                                if (menusList) menusList.style.display = 'none';
                            }
                        } else {
                            // No realm selected
                            if (loadMenusBtn) loadMenusBtn.disabled = true;
                            if (noRealmMsg) noRealmMsg.style.display = 'block';
                            if (menusList) menusList.style.display = 'none';
                        }
                    } else if (targetTab === 'social') {
                        // Always load social connects using current realm, defaulting to 'guest'
                        loadSocialConnects(currentRealm || 'guest');
                    }
                });
            });
            // Social form submission
            document.getElementById('social-form').addEventListener('submit', function(e) {
                e.preventDefault();
                // Validate required fields
                const platformField = document.getElementById('social-platform');
                const urlField = document.getElementById('social-url');
                if (!platformField || !platformField.value.trim()) {
                    alert('Platform name is required');
                    if (platformField) platformField.focus();
                    return;
                }
                if (!urlField || !urlField.value.trim()) {
                    alert('URL is required');
                    if (urlField) urlField.focus();
                    return;
                }
                // Basic URL validation to prevent invalid submissions
                const urlValue = urlField.value.trim();
                try {
                    const parsed = new URL(urlValue.startsWith('http') ? urlValue : `https://${urlValue}`);
                    if (!parsed.hostname) throw new Error('Invalid URL');
                } catch (_) {
                    showNotification('Please enter a valid URL (e.g., https://example.com)', 'error');
                    urlField.focus();
                    return;
                }
                // Sync platform field to name field for backward compatibility
                const nameField = document.getElementById('social-platform-name');
                if (platformField && nameField) {
                    // Keep platform_name synced with selected platform for display
                    nameField.value = platformField.value;
                }
                const socialId = document.getElementById('social-edit-id').value;
                const action = socialId ? 'update_social_connect' : 'create_social_connect';
                // Send as URL-encoded for consistency
                const payload = new URLSearchParams();
                payload.append('action', action);
                payload.append('realm_id', currentRealm || 'guest');
                payload.append('platform', platformField.value.trim());
                payload.append('platform_name', nameField ? nameField.value.trim() : platformField.value.trim());
                payload.append('url', urlValue);
                
                // Icon selection removed
                
                if (socialId) {
                    // Append both for maximum compatibility with server handlers
                    payload.append('id', socialId);
                    payload.append('social_id', socialId);
                }
                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: payload.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeAnySocialModal();
                        try {
                            document.dispatchEvent(new CustomEvent('socialConnectSaved', { detail: {
                                realmId: currentRealm || 'guest',
                                platform: platformField.value.trim(),
                                url: urlField.value.trim()
                            }}));
                        } catch (e) { /* no-op */ }
                        
                        // Clear cache and refresh all components
                        clearMenuCache(currentRealm);
                        loadSocialConnects(currentRealm);
                        loadPreview(currentRealm);
                        
                        showNotification(socialId ? 'Social link updated successfully!' : 'Social link created successfully!', 'success');
                    } else {
                        throw new Error(data.error || 'Failed to save social link');
                    }
                })
                .catch(error => {
                    console.error('Error saving social link:', error);
                    showNotification('Error saving social link: ' + error.message, 'error');
                });
            });
            // Delegated submenu edit/delete handling for dynamic items
            const menusListDelegated = document.getElementById('menus-list');
            if (menusListDelegated) {
                menusListDelegated.addEventListener('click', function(e) {
                    const editBtn = e.target.closest('.btn-edit-submenu');
                    const deleteBtn = e.target.closest('.btn-delete-submenu');
                    if (editBtn) {
                        e.preventDefault();
                        e.stopPropagation();
                        const menuId = editBtn.getAttribute('data-menu-id');
                        const submenuId = editBtn.getAttribute('data-submenu-id');
                        if (menuId && submenuId && typeof editSubmenu === 'function') {
                            editSubmenu(menuId, submenuId);
                        }
                        return;
                    }
                    if (deleteBtn) {
                        e.preventDefault();
                        e.stopPropagation();
                        const menuId = deleteBtn.getAttribute('data-menu-id');
                        const submenuId = deleteBtn.getAttribute('data-submenu-id');
                        if (menuId && submenuId && typeof deleteSubmenu === 'function') {
                            const confirmed = confirm('Are you sure you want to delete this submenu?');
                            if (confirmed) {
                                deleteSubmenu(menuId, submenuId);
                            }
                        }
                    }
                });
            }
            // Initialize with menus tab active
            document.querySelector('[data-tab="menus"]').classList.add('active');
            document.getElementById('menus-tab').classList.add('active');
        });
    
        
        /**
         * Enhanced menu rendering with hierarchy and social links
         */
        function renderMenuWithHierarchy(menu) {
            let html = `
                <div class="menu-item-enhanced" data-menu-id="${menu.id}">
                    <div class="menu-header">
                        <span class="menu-name">${menu.name}</span>
                        <div class="menu-actions">
                            <button onclick="editMenu('${menu.id}')" title="Edit Menu">Edit</button>
                            <button onclick="addSubmenuToMenu('${menu.id}')" title="Add Submenu">Add Submenu</button>
                            <button class="btn-add" onclick="addSocialToMenu('${menu.id}')" title="Add Social Link">Add Social Link</button>
                        </div>
                    </div>
                    <div class="menu-url" style="padding: 0 12px 8px; color: #ccc; font-size: 0.9em;">${menu.url}</div>
            `;

            // Render submenus
            if (menu.submenu && menu.submenu.length > 0) {
                html += '<div class="submenu-container">';
                html += '<h5 style="margin: 10px 0 5px; color: #333;">Submenus:</h5>';
                menu.submenu.forEach(submenu => {
                    html += `
                        <div class="submenu-item" data-submenu-id="${submenu.id}">
                            <span>${renderIconDirect(submenu.icon || "", 16)}${submenu.name}</span>
                            <span class="submenu-url">${submenu.url}</span>
                            <button class="btn-edit-submenu" data-menu-id="${menu.id}" data-submenu-id="${submenu.id}" onclick="editSubmenu('${menu.id}','${submenu.id}')" aria-label="Edit submenu ${submenu.name}" title="Edit Submenu" style="margin-left: 10px;">Edit</button>
                            <button class="btn-delete-submenu delete" data-menu-id="${menu.id}" data-submenu-id="${submenu.id}" onclick="deleteSubmenu('${menu.id}','${submenu.id}')" aria-label="Delete submenu ${submenu.name}" title="Delete Submenu" style="margin-left: 6px;">Delete</button>
                        </div>
                    `;
                });
                html += '</div>';
            }

            // Render social links
            if (menu.social_links && menu.social_links.length > 0) {
                html += '<div class="menu-social-container">';
                html += '<h5 style="margin: 0 0 8px; color: #eaeaea;">Social Links:</h5>';
                menu.social_links.forEach(social => {
                    html += `
                        <div class="social-link-item" data-social-id="${social.id}">
                            <span>${social.name}</span>
                            <a href="${social.url}" target="_blank" class="social-url">${social.url}</a>
                            <button class="btn-action btn-action-small" onclick="editSocial('${social.id}')" title="Edit Social Link">Edit</button>
                            <button class="btn-action btn-action-small delete" onclick="deleteSocial('${social.id}')" title="Delete Social Link">Delete</button>
                        </div>
                `;
                });
                html += '</div>';
            }

            html += '</div>';
            return html;
        }

        /**
         * Add submenu to a specific menu
         */
        function addSubmenuToMenu(menuId) {
            const name = prompt('Enter submenu name:');
            const url = prompt('Enter submenu URL:');
            
            if (name && url) {
                const formData = new URLSearchParams();
                formData.append('action', 'add_submenu');
                formData.append('menu_id', menuId);
                formData.append('name', name);
                formData.append('url', url);
                formData.append('realm_id', currentRealm);

                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Submenu added successfully', 'success');
                        if (currentRealm) {
                            clearMenuCache(currentRealm);
                            loadMenus(currentRealm, true);
                            loadPreview(currentRealm);
                        }
                    } else {
                        showNotification('Error adding submenu: ' + data.error, 'error');
                    }
                });
            }
        }

        /**
         * Add social link to a specific menu
         */
        function addSocialToMenu(menuId) {
            const platform = prompt('Enter platform (facebook, twitter, etc.):');
            const name = prompt('Enter display name:');
            const url = prompt('Enter social URL:');
            
            if (platform && name && url) {
                const formData = new URLSearchParams();
                formData.append('action', 'add_social_link');
                formData.append('menu_id', menuId);
                formData.append('platform', platform);
                formData.append('platform_name', name);
                formData.append('url', url);
                formData.append('realm_id', currentRealm);

                fetch(window.navigatorConfig.navigatorUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Social link added successfully', 'success');
                        if (currentRealm) {
                            clearMenuCache(currentRealm);
                            loadMenus(currentRealm, true);
                            loadPreview(currentRealm);
                        }
                    } else {
                        showNotification('Error adding social link: ' + data.error, 'error');
                    }
                });
            }
        }

        // Removed duplicate placeholder edit handlers; using earlier implementations

        // Ensure functions are globally accessible for inline onclick handlers
        window.deleteRealm = deleteRealm;
        window.editRealm = editRealm;
        window.selectRealm = selectRealm;
        window.editMenu = editMenu;
        window.deleteMenu = deleteMenu;
        window.editSocial = editSocial;
        window.editSubmenu = editSubmenu;
        
        console.log('Global functions registered:', {
            deleteRealm: typeof window.deleteRealm,
            editRealm: typeof window.editRealm,
            selectRealm: typeof window.selectRealm
        });

        // Drag-and-drop order management for menu blocks (menus tab)
        (function() {
            const ORDER_STORAGE_PREFIX = 'menuOrder:';

            function injectStyles() {
                try {
                    var style = document.getElementById('drag-drop-styles');
                    if (style) return;
                    style = document.createElement('style');
                    style.id = 'drag-drop-styles';
                    style.textContent =
                        '.draggable-block{cursor:grab; transition: transform 150ms ease, box-shadow 150ms ease;}'+
                        '.draggable-block:active{cursor:grabbing;}'+
                        '.dragging{opacity:0.85; box-shadow:0 8px 20px rgba(0,0,0,0.3); z-index:1000;}'+
                        '.drop-indicator{height:0; border-top:2px dashed #4A90E2; margin:6px 0;}'+
                        '#menus-list{position:relative;}';
                    document.head.appendChild(style);
                } catch (e) { console.warn('Failed to inject drag-drop styles', e); }
            }

            function getRealmKey() { return (window.currentRealm || 'guest') + ''; }

            function loadMenuOrder() {
                try {
                    const raw = localStorage.getItem(ORDER_STORAGE_PREFIX + getRealmKey());
                    return raw ? JSON.parse(raw) : [];
                } catch (e) {
                    console.warn('Failed to read menu order from storage', e);
                    return [];
                }
            }

            function saveMenuOrder(ids) {
                try {
                    localStorage.setItem(ORDER_STORAGE_PREFIX + getRealmKey(), JSON.stringify(ids));
                } catch (e) {
                    console.error('Failed to persist menu order', e);
                }
            }

            function applyOrderToMenus(menus) {
                const order = loadMenuOrder();
                if (!order || order.length === 0 || !Array.isArray(menus)) return menus;
                const idToMenu = new Map(menus.map(m => [m.id, m]));
                const sorted = [];
                order.forEach(id => { const item = idToMenu.get(id); if (item) sorted.push(item); });
                menus.forEach(m => { if (!order.includes(m.id)) sorted.push(m); });
                return sorted;
            }

            function getElementAfterY(container, y) {
                const els = Array.from(container.querySelectorAll('.menu-item-enhanced:not(.dragging)'));
                let candidate = null;
                for (let el of els) {
                    const rect = el.getBoundingClientRect();
                    const midpoint = rect.top + rect.height / 2;
                    if (y < midpoint) { candidate = el; break; }
                }
                return candidate; // null indicates append to end
            }

            function updateOrderFromDOM(container) {
                const ids = Array.from(container.querySelectorAll('.menu-item-enhanced'))
                    .map(el => el.dataset.menuId)
                    .filter(Boolean);
                if (ids.length) saveMenuOrder(ids);
            }

            function applyStoredOrderToDOM(container) {
                const order = loadMenuOrder();
                if (!order.length) return;
                const byId = {};
                container.querySelectorAll('.menu-item-enhanced').forEach(el => { byId[el.dataset.menuId] = el; });
                order.forEach(id => { const el = byId[id]; if (el) container.appendChild(el); });
            }

            function initDragAndDropForMenus() {
                try {
                    injectStyles();
                    const container = document.getElementById('menus-list');
                    if (!container) return;
                    const blocks = container.querySelectorAll('.menu-item-enhanced');
                    if (!blocks.length) return;

                    // Make elements draggable and focusable for keyboard interaction
                    blocks.forEach(b => {
                        b.setAttribute('draggable', 'true');
                        b.setAttribute('role', 'option');
                        if (!b.hasAttribute('tabindex')) b.setAttribute('tabindex', '0');
                        b.classList.add('draggable-block');
                    });

                    let draggingEl = null;
                    const dropIndicator = document.createElement('div');
                    dropIndicator.className = 'drop-indicator';

                    // DnD events
                    container.addEventListener('dragstart', (e) => {
                        const el = e.target.closest('.menu-item-enhanced');
                        if (!el) return;
                        draggingEl = el;
                        el.classList.add('dragging');
                        if (e.dataTransfer) {
                            e.dataTransfer.effectAllowed = 'move';
                            e.dataTransfer.setData('text/plain', el.dataset.menuId || '');
                        }
                    });

                    container.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        if (!draggingEl) return;
                        const after = getElementAfterY(container, e.clientY);
                        if (after == null) {
                            container.appendChild(dropIndicator);
                        } else {
                            container.insertBefore(dropIndicator, after);
                        }
                    });

                    container.addEventListener('drop', (e) => {
                        e.preventDefault();
                        if (!draggingEl) return;
                        if (dropIndicator.parentNode === container) {
                            container.insertBefore(draggingEl, dropIndicator);
                            dropIndicator.remove();
                            updateOrderFromDOM(container);
                        }
                    });

                    container.addEventListener('dragend', () => {
                        if (draggingEl) {
                            draggingEl.classList.remove('dragging');
                            draggingEl = null;
                        }
                        if (dropIndicator.parentNode) dropIndicator.remove();
                    });

                    // Keyboard accessibility
                    blocks.forEach(b => {
                        b.addEventListener('keydown', (e) => {
                            const key = e.key;
                            if (key === ' ' || key === 'Spacebar') {
                                e.preventDefault();
                                draggingEl = b;
                                b.classList.add('dragging');
                            } else if (key === 'Escape') {
                                if (draggingEl) {
                                    draggingEl.classList.remove('dragging');
                                    draggingEl = null;
                                }
                            } else if (key === 'ArrowUp' && draggingEl === b) {
                                e.preventDefault();
                                const prev = b.previousElementSibling;
                                if (prev) {
                                    b.parentNode.insertBefore(b, prev);
                                    updateOrderFromDOM(container);
                                    b.focus();
                                }
                            } else if (key === 'ArrowDown' && draggingEl === b) {
                                e.preventDefault();
                                const next = b.nextElementSibling;
                                if (next) {
                                    b.parentNode.insertBefore(next, b);
                                    updateOrderFromDOM(container);
                                    b.focus();
                                }
                            } else if (key === 'Enter' && draggingEl === b) {
                                e.preventDefault();
                                b.classList.remove('dragging');
                                draggingEl = null;
                            }
                        });
                    });

                    // Touch support via Pointer Events
                    blocks.forEach(b => {
                        b.addEventListener('pointerdown', (e) => {
                            if (e.pointerType === 'touch') {
                                draggingEl = b;
                                b.classList.add('dragging');
                                if (b.setPointerCapture) b.setPointerCapture(e.pointerId);
                            }
                        });
                        b.addEventListener('pointermove', (e) => {
                            if (!draggingEl || e.pointerType !== 'touch') return;
                            const after = getElementAfterY(container, e.clientY);
                            if (after == null) {
                                container.appendChild(dropIndicator);
                            } else {
                                container.insertBefore(dropIndicator, after);
                            }
                        });
                        b.addEventListener('pointerup', (e) => {
                            if (draggingEl && e.pointerType === 'touch') {
                                if (dropIndicator.parentNode === container) {
                                    container.insertBefore(draggingEl, dropIndicator);
                                    dropIndicator.remove();
                                    updateOrderFromDOM(container);
                                }
                                draggingEl.classList.remove('dragging');
                                draggingEl = null;
                            }
                        });
                    });

                    // Apply stored order to DOM on init
                    applyStoredOrderToDOM(container);
                } catch (e) {
                    console.error('Failed to initialize drag-and-drop for menus', e);
                }
            }

            // Hook displayMenus to apply saved order and then set up DnD
            if (typeof window.displayMenus === 'function' && !window._displayMenusOriginal) {
                window._displayMenusOriginal = window.displayMenus;
                window.displayMenus = function(menus) {
                    try {
                        const ordered = applyOrderToMenus(Array.isArray(menus) ? menus.slice() : []);
                        window._displayMenusOriginal(ordered);
                        initDragAndDropForMenus();
                    } catch (e) {
                        console.error('displayMenus wrapper error', e);
                        window._displayMenusOriginal(menus);
                        initDragAndDropForMenus();
                    }
                };
            } else {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(initDragAndDropForMenus, 500);
                });
            }

        })();

        // Generic drag-and-drop for realms, submenus, and social links
        (function() {
            const STORE_PREFIX = 'order:';
            function currentRealmKey() { return (window.currentRealm || 'guest') + ''; }

            function injectSharedStyles() {
                if (document.getElementById('drag-drop-shared-styles')) return;
                const style = document.createElement('style');
                style.id = 'drag-drop-shared-styles';
                style.textContent =
                    '.drag-item{cursor:grab; transition: transform 150ms ease, box-shadow 150ms ease;}'+
                    '.drag-item:active{cursor:grabbing;}'+
                    '.dragging{opacity:0.92; box-shadow:0 8px 20px rgba(0,0,0,0.28); z-index:999;}'+
                    '.drop-indicator{height:0; border-top:2px dashed #4A90E2; margin:6px 0;}';
                document.head.appendChild(style);
            }

            function storageKey(kind, menuId) {
                return `${STORE_PREFIX}${kind}:${currentRealmKey()}${menuId ? ':'+menuId : ''}`;
            }

            function readOrder(key) {
                try {
                    const raw = localStorage.getItem(key);
                    return raw ? JSON.parse(raw) : [];
                } catch { return []; }
            }
            function writeOrder(key, ids) {
                try { localStorage.setItem(key, JSON.stringify(ids || [])); } catch {}
                try {
                    if (typeof loadPreview === 'function') {
                        loadPreview(currentRealmKey(), false);
                    }
                } catch (e) {}
            }

            function applyOrderToMenus(menus) {
                const topOrder = readOrder(storageKey('menus'));
                const byId = {};
                const orderedTop = [];
                const remaining = [];
                (menus || []).forEach(m => { byId[(m && m.id) ? (m.id + '') : ''] = m; });
                if (Array.isArray(topOrder) && topOrder.length) {
                    topOrder.forEach(id => { const k = id + ''; if (byId[k]) { orderedTop.push(byId[k]); delete byId[k]; } });
                    Object.keys(byId).forEach(k => { if (byId[k]) remaining.push(byId[k]); });
                } else {
                    return menus || [];
                }
                const result = orderedTop.concat(remaining);
                const out = [];
                result.forEach(menu => {
                    if (!menu) return;
                    const clone = Object.assign({}, menu);
                    if (Array.isArray(menu.submenu)) {
                        const subOrder = readOrder(storageKey('submenus', (menu.id + '')));
                        if (Array.isArray(subOrder) && subOrder.length) {
                            const subMap = {};
                            menu.submenu.forEach(s => { subMap[(s && s.id) ? (s.id + '') : ''] = s; });
                            const orderedSubs = [];
                            const leftoverSubs = [];
                            subOrder.forEach(id => { const k = id + ''; if (subMap[k]) { orderedSubs.push(subMap[k]); delete subMap[k]; } });
                            Object.keys(subMap).forEach(k => { if (subMap[k]) leftoverSubs.push(subMap[k]); });
                            clone.submenu = orderedSubs.concat(leftoverSubs);
                        } else {
                            clone.submenu = menu.submenu.slice();
                        }
                    }
                    out.push(clone);
                });
                return out;
            }
            window.mhNavigatorApplyOrder = applyOrderToMenus;

            function extractIds(container, itemSelector, idAttr) {
                return Array.from(container.querySelectorAll(itemSelector))
                    .map(el => el.getAttribute(idAttr))
                    .filter(Boolean);
            }

            function applyStoredOrder(container, itemSelector, idAttr, key) {
                const order = readOrder(key);
                if (!order.length) return;
                const byId = {};
                container.querySelectorAll(itemSelector).forEach(el => { byId[el.getAttribute(idAttr)] = el; });
                order.forEach(id => { const el = byId[id]; if (el) container.appendChild(el); });
            }

            function getElementAfterY(container, itemSelector, y) {
                const els = Array.from(container.querySelectorAll(`${itemSelector}:not(.dragging)`));
                let candidate = null;
                for (let el of els) {
                    const rect = el.getBoundingClientRect();
                    const midpoint = rect.top + rect.height / 2;
                    if (y < midpoint) { candidate = el; break; }
                }
                return candidate; // null indicates append to end
            }

            function initDnD(container, options) {
                const { itemSelector, idAttr, keyBuilder } = options;
                injectSharedStyles();
                const items = container.querySelectorAll(itemSelector);
                if (!items.length) return;

                // Determine storage key context (supports per-menu containers)
                    const menuEl = container.closest('.menu-item-enhanced, .menu-item');
                    const menuId = menuEl ? menuEl.getAttribute('data-menu-id') : undefined;
                    const key = keyBuilder(menuId);

                // Make items draggable and focusable
                items.forEach(el => {
                    el.setAttribute('draggable', 'true');
                    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
                    el.classList.add('drag-item');
                    el.setAttribute('role', 'option');
                });

                // Apply stored order
                applyStoredOrder(container, itemSelector, idAttr, key);

                let draggingEl = null;
                const dropIndicator = document.createElement('div');
                dropIndicator.className = 'drop-indicator';

                container.addEventListener('dragstart', (e) => {
                    const el = e.target.closest(itemSelector);
                    if (!el) return;
                    draggingEl = el;
                    el.classList.add('dragging');
                    if (e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', el.getAttribute(idAttr) || '');
                    }
                });

                container.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    if (!draggingEl) return;
                    const after = getElementAfterY(container, itemSelector, e.clientY);
                    if (after == null) {
                        container.appendChild(dropIndicator);
                    } else {
                        container.insertBefore(dropIndicator, after);
                    }
                });

                container.addEventListener('drop', (e) => {
                    e.preventDefault();
                    if (!draggingEl) return;
                    if (dropIndicator.parentNode === container) {
                        container.insertBefore(draggingEl, dropIndicator);
                        dropIndicator.remove();
                        const newOrder = extractIds(container, itemSelector, idAttr);
                        writeOrder(key, newOrder);
                        
                        // Send to server for realms
                        if (key === storageKey('realms')) {
                            const realmId = draggingEl.getAttribute(idAttr);
                            const newPosition = Array.from(container.children).indexOf(draggingEl) + 1;
                            if (realmId && newPosition > 0) {
                                fetch(window.location.href, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        action: 'reorder_realm',
                                        realm_id: realmId,
                                        new_position: newPosition
                                    })
                                }).then(response => response.json()).then(data => {
                                    if (!data.success) {
                                        console.warn('Realm reorder failed:', data.error);
                                    } else {
                                        try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'realms_reordered' }); } catch(e) {}
                                        if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                    }
                                }).catch(error => {
                                    console.error('Realm reorder error:', error);
                                });
                            }
                        }
                        if (key === storageKey('menus')) {
                            const menuId = draggingEl.getAttribute(idAttr);
                            const realmId = draggingEl.getAttribute('data-source-realm') || (window.currentRealm || 'guest');
                            const newPosition = Array.from(container.children).indexOf(draggingEl) + 1;
                            if (menuId && newPosition > 0) {
                                fetch(window.location.href, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        action: 'reorder_menus',
                                        realm_id: realmId,
                                        menu_id: menuId,
                                        new_position: newPosition
                                    })
                                }).then(response => response.json()).then(data => {
                                    if (!data.success) {
                                        console.warn('Menu reorder failed:', data.error);
                                    } else {
                                        try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'menus_reordered', realmId: realmId }); } catch(e) {}
                                        if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                    }
                                }).catch(error => {
                                    console.error('Menu reorder error:', error);
                                });
                            }
                        }
                    const menuElCtx = container.closest('.menu-item-enhanced, .menu-item');
                    const menuCtxId = menuElCtx ? menuElCtx.getAttribute('data-menu-id') : undefined;
                        if (key === storageKey('submenus', menuCtxId)) {
                            const submenuId = draggingEl.getAttribute(idAttr);
                            const realmId = (window.currentRealm || 'guest');
                            const newPosition = Array.from(container.children).indexOf(draggingEl) + 1;
                            if (menuCtxId && submenuId && newPosition > 0) {
                                fetch(window.location.href, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        action: 'reorder_submenu',
                                        realm_id: realmId,
                                        menu_id: menuCtxId,
                                        submenu_id: submenuId,
                                        new_position: newPosition
                                    })
                                }).then(response => response.json()).then(data => {
                                    if (!data.success) {
                                        console.warn('Submenu reorder failed:', data.error);
                                    } else {
                                        try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'submenus_reordered', menuId: menuCtxId }); } catch(e) {}
                                        if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                    }
                                }).catch(error => {
                                    console.error('Submenu reorder error:', error);
                                });
                            }
                        }
                    }
                });

                container.addEventListener('dragend', () => {
                    if (draggingEl) {
                        draggingEl.classList.remove('dragging');
                        draggingEl = null;
                    }
                    if (dropIndicator.parentNode) dropIndicator.remove();
                });

                // Keyboard support
                items.forEach(el => {
                    el.addEventListener('keydown', (e) => {
                        const keyName = e.key;
                        if (keyName === ' ' || keyName === 'Spacebar') {
                            e.preventDefault(); draggingEl = el; el.classList.add('dragging');
                        } else if (keyName === 'Escape') {
                            if (draggingEl) { draggingEl.classList.remove('dragging'); draggingEl = null; }
                        } else if (keyName === 'ArrowUp' && draggingEl === el) {
                            e.preventDefault(); const prev = el.previousElementSibling; if (prev) { el.parentNode.insertBefore(el, prev); const newOrder = extractIds(container, itemSelector, idAttr); writeOrder(key, newOrder); el.focus(); 
                                // Send to server for realms
                                if (key === storageKey('realms')) {
                                    const realmId = el.getAttribute(idAttr);
                                    const newPosition = Array.from(container.children).indexOf(el) + 1;
                                    if (realmId && newPosition > 0) {
                                        fetch(window.location.href, {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                            body: new URLSearchParams({
                                                action: 'reorder_realm',
                                                realm_id: realmId,
                                                new_position: newPosition
                                            })
                                        }).then(response => response.json()).then(data => {
                                            if (!data.success) {
                                                console.warn('Realm reorder failed:', data.error);
                                            } else {
                                                try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'realms_reordered' }); } catch(e) {}
                                                if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                            }
                                        }).catch(error => {
                                            console.error('Realm reorder error:', error);
                                        });
                                    }
                                }
                            }
                        } else if (keyName === 'ArrowDown' && draggingEl === el) {
                            e.preventDefault(); const next = el.nextElementSibling; if (next) { el.parentNode.insertBefore(next, el); const newOrder = extractIds(container, itemSelector, idAttr); writeOrder(key, newOrder); el.focus(); 
                                // Send to server for realms
                                if (key === storageKey('realms')) {
                                    const realmId = el.getAttribute(idAttr);
                                    const newPosition = Array.from(container.children).indexOf(el) + 1;
                                    if (realmId && newPosition > 0) {
                                        fetch(window.location.href, {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                            body: new URLSearchParams({
                                                action: 'reorder_realm',
                                                realm_id: realmId,
                                                new_position: newPosition
                                            })
                                        }).then(response => response.json()).then(data => {
                                            if (!data.success) {
                                                console.warn('Realm reorder failed:', data.error);
                                            } else {
                                                try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'realms_reordered' }); } catch(e) {}
                                                if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                            }
                                        }).catch(error => {
                                            console.error('Realm reorder error:', error);
                                        });
                                    }
                                }
                            }
                        } else if (keyName === 'Enter' && draggingEl === el) {
                            e.preventDefault(); el.classList.remove('dragging'); draggingEl = null;
                        }
                    });
                });

                // Touch via Pointer Events
                items.forEach(el => {
                    el.addEventListener('pointerdown', (e) => { if (e.pointerType === 'touch') { draggingEl = el; el.classList.add('dragging'); el.setPointerCapture && el.setPointerCapture(e.pointerId); } });
                    el.addEventListener('pointermove', (e) => { if (!draggingEl || e.pointerType !== 'touch') return; const after = getElementAfterY(container, itemSelector, e.clientY); if (after == null) { container.appendChild(dropIndicator); } else { container.insertBefore(dropIndicator, after); } });
                    el.addEventListener('pointerup', (e) => { if (draggingEl && e.pointerType === 'touch') { if (dropIndicator.parentNode === container) { container.insertBefore(draggingEl, dropIndicator); dropIndicator.remove(); const newOrder = extractIds(container, itemSelector, idAttr); writeOrder(key, newOrder); 
                        // Send to server for realms
                        if (key === storageKey('realms')) {
                            const realmId = draggingEl.getAttribute(idAttr);
                            const newPosition = Array.from(container.children).indexOf(draggingEl) + 1;
                            if (realmId && newPosition > 0) {
                                fetch(window.location.href, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: new URLSearchParams({
                                        action: 'reorder_realm',
                                        realm_id: realmId,
                                        new_position: newPosition
                                    })
                                }).then(response => response.json()).then(data => {
                                    if (!data.success) {
                                        console.warn('Realm reorder failed:', data.error);
                                    } else {
                                        try { var bc = new BroadcastChannel('navigator-order'); bc.postMessage({ type: 'realms_reordered' }); } catch(e) {}
                                        if (typeof window.refreshHamburgerOrder === 'function') { window.refreshHamburgerOrder(); if (typeof window.refreshHamburgerOrderByTitle === 'function') window.refreshHamburgerOrderByTitle(); }
                                    }
                                }).catch(error => {
                                    console.error('Realm reorder error:', error);
                                });
                            }
                        }
                    } draggingEl.classList.remove('dragging'); draggingEl = null; } });
                });
            }

            function initAllDnD() {
                // Realms list
                const realmsList = document.getElementById('realms-list');
                if (realmsList) {
                    initDnD(realmsList, {
                        itemSelector: '.realm-item',
                        idAttr: 'data-realm-id',
                        keyBuilder: () => storageKey('realms')
                    });
                }

                // Menus list (top-level menus)
                const menusList = document.getElementById('menus-list');
                if (menusList) {
                    initDnD(menusList, {
                        itemSelector: '.menu-item-enhanced',
                        idAttr: 'data-menu-id',
                        keyBuilder: () => storageKey('menus')
                    });
                    // Legacy menu items
                    initDnD(menusList, {
                        itemSelector: '.menu-item',
                        idAttr: 'data-menu-id',
                        keyBuilder: () => storageKey('menus')
                    });

                    // Submenus per menu
                    menusList.querySelectorAll('.submenu-container').forEach(container => {
                        initDnD(container, {
                            itemSelector: '.submenu-item',
                            idAttr: 'data-submenu-id',
                            keyBuilder: (menuId) => storageKey('submenus', menuId)
                        });
                    });
                    // Legacy submenu container
                    menusList.querySelectorAll('.submenu-list').forEach(container => {
                        initDnD(container, {
                            itemSelector: '.submenu-item',
                            idAttr: 'data-submenu-id',
                            keyBuilder: (menuId) => storageKey('submenus', menuId)
                        });
                    });

                    // Social links per menu
                    menusList.querySelectorAll('.menu-social-container').forEach(container => {
                        // If the reusable DragDropWidget is available, mount it; otherwise fall back to initDnD
                        try {
                            if (window.DragDropWidget) {
                                const menuBlock = container.closest('.menu-block');
                                const menuId = menuBlock ? (menuBlock.getAttribute('data-menu-id') || 'unknown') : 'unknown';
                                const items = Array.from(container.querySelectorAll('.social-link-item')).map(el => ({
                                    id: el.getAttribute('data-social-id') || '',
                                    name: (el.querySelector('.social-label')?.textContent || el.querySelector('span')?.textContent || '').trim(),
                                    url: (el.querySelector('a[href]')?.getAttribute('href') || '').trim()
                                }));
                                const widget = window.DragDropWidget.mount(container, items, {
                                    instanceId: `social:${menuId}`,
                                    itemSelector: '.social-link-item',
                                    idAttr: 'data-social-id',
                                    persist: true
                                });
                                if (widget) {
                                    widget.on('orderChange', ({ order }) => {
                                        try { localStorage.setItem(storageKey('social', menuId), JSON.stringify(order)); } catch (_) {}
                                    });
                                }
                            } else {
                                initDnD(container, {
                                    itemSelector: '.social-link-item',
                                    idAttr: 'data-social-id',
                                    keyBuilder: (menuId) => storageKey('social', menuId)
                                });
                            }
                        } catch (e) { console.warn('Social links dragdrop init warning:', e); }
                    });
                }
            }

            // Wrap displayRealms to init/reorder after render
            if (typeof window.displayRealms === 'function' && !window._displayRealmsOriginal) {
                window._displayRealmsOriginal = window.displayRealms;
                window.displayRealms = function(realms) {
                    window._displayRealmsOriginal(realms);
                    try { initAllDnD(); } catch (e) { console.error('initAllDnD realms failed', e); }
                };
            }

            // Extend displayMenus wrapper to init nested containers after render
            if (typeof window.displayMenus === 'function') {
                const original = window._displayMenusOriginal || window.displayMenus;
                window._displayMenusOriginal = original;
                window.displayMenus = function(menus) {
                    let ordered = Array.isArray(menus) ? menus.slice() : [];
                    try { ordered = applyOrderToMenus(ordered); } catch (e) { console.warn('applyOrderToMenus failed', e); }
                    original(ordered);
                    try { initAllDnD(); } catch (e) { console.error('initAllDnD menus failed', e); }
                };
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Initialize DnD once DOM is ready; will re-run after renders
                setTimeout(initAllDnD, 400);
            });
        })();

        // Bridge functions for backward compatibility with the standardized loader widget
        function showLoadingAnimation(message, targetElement) {
            if (typeof window.CUELoader !== 'undefined' && window.CUELoader.show) {
                // Use the new standardized loader widget
                const text = typeof message === 'string' ? message : 'Loading...';
                window.CUELoader.show(text);
            } else {
                // Fallback to console log if widget not loaded
                console.warn('CUELoader widget not available, loading animation not shown:', message || 'Loading...');
            }
        }

        function hideLoadingAnimation() {
            if (typeof window.CUELoader !== 'undefined' && window.CUELoader.hide) {
                // Use the new standardized loader widget
                window.CUELoader.hide();
            } else {
                // Fallback to console log if widget not loaded
                console.warn('CUELoader widget not available, loading animation not hidden');
            }
        }

        // Make functions globally available for backward compatibility
        window.showLoadingAnimation = showLoadingAnimation;
        window.hideLoadingAnimation = hideLoadingAnimation;

    </script>
<!-- End main-content -->

<!-- Enhanced Remote Server Compatibility -->
<script>
(function() {
    'use strict';
    
    console.log('Initializing remote server compatibility enhancements...');
    
    // Disable any potential animation libraries that might cause issues on remote servers
    function disableAnimations() {
        try {
            // Remove Vanta.waves if present
            if (typeof VANTA !== 'undefined') {
                console.log('Removing VANTA animations for remote compatibility');
                if (VANTA.current) VANTA.current.destroy();
                delete window.VANTA;
            }
            
            // Disable Three.js if present and causing issues on remote server
            if (typeof THREE !== 'undefined' && (window.location.hostname === 'irmabot.one' || window.location.hostname.includes('remote'))) {
                console.log('Disabling THREE.js for remote server compatibility');
                delete window.THREE;
            }
            
            // Remove any wave or particle animations
            const animationElements = document.querySelectorAll('[id*="vanta"], [class*="vanta"], [id*="wave"], [class*="wave"]');
            animationElements.forEach(el => {
                try {
                    el.remove();
                    console.log('Removed animation element:', el.id || el.className);
                } catch(e) {}
            });
            
            // Clean up animation intervals and timeouts
            for (let i = 1; i < 10000; i++) {
                try {
                    clearTimeout(i);
                    clearInterval(i);
                } catch(e) {}
            }
            
            console.log('Animation cleanup completed');
        } catch (error) {
            console.log('Animation cleanup completed with warnings:', error.message);
        }
    }
    
    // Enhanced fetch wrapper for better JSON handling on remote servers (v2.2-cache-bust)
    function enhanceFetch() {
        if (!window.originalFetch) {
            window.originalFetch = window.fetch;
            
            window.fetch = function(...args) {
                return window.originalFetch.apply(this, args)
                    .then(response => {
                        const contentType = response.headers.get('content-type') || '';
                        
                        // Enhanced JSON cleaning for remote servers
                        if (contentType.includes('application/json') || args[0].includes('navigator.php')) {
                            return response.text().then(text => {
                                try {
                                    // Aggressive text cleaning for remote server environments
                                    let cleanText = text.trim();
                                    
                                    // Remove BOM and control characters
                                    cleanText = cleanText.replace(/^\uFEFF/, '');
                                    cleanText = cleanText.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
                                    
                                    // Remove any HTML that might be injected by remote server
                                    cleanText = cleanText.replace(/<[^>]*>/g, '');
                                    
                                    // Extract JSON from potentially contaminated response
                                    // Try to find the complete JSON response, starting from the outermost object
                                    const jsonPatterns = [
                                        /(\{"success".*?\}$)/s,                // Success response object (complete)
                                        /(\{.*?"success".*?"count".*?\})/s,    // Response with success and count
                                        /(\{.*?"success".*?"data".*?\})/s,     // Response with success and data  
                                        /(\{.*?"success".*?\})/s,              // Any success response
                                        /(\{.*\})/s                            // Any JSON object
                                    ];
                                    
                                    for (const pattern of jsonPatterns) {
                                        const match = cleanText.match(pattern);
                                        if (match) {
                                            try {
                                                const testJson = JSON.parse(match[1]);
                                                cleanText = match[1];
                                                break;
                                            } catch (e) {
                                                continue;
                                            }
                                        }
                                    }
                                    
                                    // Validate final JSON
                                    const parsedData = JSON.parse(cleanText);
                                    
                                    return new Response(JSON.stringify(parsedData), {
                                        status: response.status,
                                        statusText: response.statusText,
                                        headers: new Headers({
                                            'Content-Type': 'application/json'
                                        })
                                    });
                                } catch (parseError) {
                                    console.error('Remote server JSON parse error:', parseError);
                                    console.log('Raw response (first 200 chars):', text.substring(0, 200));
                                    
                                    // Return fallback error response
                                    return new Response(JSON.stringify({
                                        success: false,
                                        error: 'Remote server response error',
                                        debug_info: text.substring(0, 100)
                                    }), {
                                        status: 200,
                                        headers: new Headers({
                                            'Content-Type': 'application/json'
                                        })
                                    });
                                }
                            });
                        }
                        
                        return response;
                    })
                    .catch(error => {
                        console.error('Enhanced fetch error for remote server:', error);
                        return Promise.reject(error);
                    });
            };
            
            console.log('Enhanced fetch wrapper installed for remote server compatibility');
        }
    }
    
    // Prevent pages loading errors
    function preventPagesError() {
        // Intercept any attempts to load pages that might cause errors
        window.addEventListener('error', function(e) {
            if (e.message && e.message.includes('Pages response is not JSON')) {
                console.warn('🛡️ Intercepted pages loading error - preventing display');
                e.preventDefault();
                return false;
            }
        });
        
        // Override any loadPages functions that might exist
        if (window.loadPages) {
            console.log('🛡️ Overriding existing loadPages function to prevent errors');
            window.loadPages = function() {
                console.log('🛡️ loadPages called but intercepted to prevent errors');
                return Promise.resolve([]);
            };
        }
    }

    // Initialize navigator functionality
    function initNavigator() {
        // Set up navigator configuration
        window.navigatorConfig = {
            navigatorUrl: window.location.href.split('?')[0] // Remove query params
        };
        
        // Initialize realms loading
        console.log('🚀 Initializing Navigator - loading realms...');
        setTimeout(() => {
            if (typeof window.loadRealms === 'function') {
                window.loadRealms(true).catch(error => {
                    console.error('❌ Failed to load realms:', error);
                });
            } else {
                console.error('❌ loadRealms function not found');
            }
        }, 500);
    }

    // Initialize compatibility enhancements
    function initCompatibility() {
        disableAnimations();
        enhanceFetch();
        preventPagesError();
        initNavigator();
        
        // Remove any error-causing script tags
        const problematicScripts = document.querySelectorAll('script[src*="vanta"], script[src*="three"]');
        problematicScripts.forEach(script => {
            console.log('Removing problematic script:', script.src);
            script.remove();
        });
        
        console.log('Remote server compatibility initialization complete');
    }
    
    // Run immediately and on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCompatibility);
    } else {
        initCompatibility();
    }
    
    // Also run after a delay to catch late-loading animations
    setTimeout(disableAnimations, 2000);
})();

// Icon picker functionality
    
let currentIconContext = '';

function openIconPicker(context) {
    currentIconContext = context;

    // Create modal if it doesn't exist
    let modal = document.getElementById('icon-picker-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'icon-picker-modal';
        modal.innerHTML = `
            <div class="modal-overlay" onclick="closeIconPicker()"></div>
            <div class="modal-content" style="width: 80%; max-width: 800px; height: 70%; max-height: 600px;">
                <div class="modal-header">
                    <h3 class="modal-title">Choose Icon</h3>
                    <button class="btn-close" onclick="closeIconPicker()">&times;</button>
                </div>
                <div class="modal-body" style="padding: 0; height: calc(100% - 60px);">
                    <iframe id="icon-picker-frame" src="/templates/widgets/icons/icon-widget.php?mode=picker" 
                        style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
            </div>
        `;
        modal.className = 'modal';
        document.body.appendChild(modal);
    }
    
    // Reset iframe source to ensure fresh load
    const iframe = modal.querySelector('#icon-picker-frame');
    iframe.src = '/templates/widgets/icons/icon-widget.php?mode=picker&t=' + Date.now();
    
    modal.classList.add('active');
}

function closeIconPicker() {
    const modal = document.getElementById('icon-picker-modal');
    if (modal) {
        modal.classList.remove('active');
    }
    currentIconContext = '';
}

function clearIcon(context) {
    const iconInput = document.getElementById(context + '-icon');
    const iconPreview = document.getElementById(context + '-icon-preview');
    
    if (iconInput) iconInput.value = '';
    if (iconPreview) {
        iconPreview.innerHTML = '<span style="font-size: 0.8rem;">No Icon</span>';
        iconPreview.style.color = '#666';
        iconPreview.style.background = 'rgba(255,255,255,0.05)';
    }
}

// Listen for icon selection from picker
window.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'iconSelected' && currentIconContext) {
        const iconData = event.data.icon;
        const iconInput = document.getElementById(currentIconContext + '-icon');
        const iconPreview = document.getElementById(currentIconContext + '-icon-preview');
        
        if (iconInput && iconPreview && iconData) {
            iconInput.value = iconData.name || iconData.class || iconData;
            
            // Update preview
            if (iconData.type === 'svg' && iconData.svg) {
                iconPreview.innerHTML = renderIconDirect(iconData.svg, 20);
            } else if (iconData.type === 'fontawesome' && iconData.class) {
                iconPreview.innerHTML = renderIconDirect(iconData.class, 20);
            } else if (iconData && iconData.name) {
                iconPreview.innerHTML = renderIconDirect(iconData.name, 20);
            } else {
                iconPreview.innerHTML = renderIconDirect(String(iconData || ""), 20);
            }
            iconPreview.style.color = '#00d4ff';
            iconPreview.style.background = 'rgba(0, 212, 255, 0.1)';
            try { applyIconFixups(iconPreview); } catch (e) {}
            
            closeIconPicker();
            // Force refresh of the current realm display after icon selection
            setTimeout(function() {
                if (window.currentRealm && typeof loadRealm === "function") {
                    console.log("Refreshing realm display after icon selection");
                    loadRealm(window.currentRealm);
                }
            }, 500);
        }
    }
});

// Update realm data loading to include icon
function updateRealmDataLoading() {
    const originalLoadRealmData = window.loadRealmData;
    if (originalLoadRealmData) {
        window.loadRealmData = function(realmId) {
            originalLoadRealmData(realmId);
            
            // Load icon data if available
            const realm = window.realmsCache && window.realmsCache[realmId] ? window.realmsCache[realmId] : null;
            if (realm && realm.icon) {
                const iconInput = document.getElementById('realm-icon');
                const iconPreview = document.getElementById('realm-icon-preview');
                
                if (iconInput) iconInput.value = realm.icon;
                if (iconPreview && realm.icon) {
                    iconPreview.innerHTML = renderIconDirect(realm.icon, 20);
                    iconPreview.style.color = '#00d4ff';
                    iconPreview.style.background = 'rgba(0, 212, 255, 0.1)';
                    try { applyIconFixups(iconPreview); } catch (e) {}
                }
            }
        };
    }
}

// Update menu data loading to include icon
function updateMenuDataLoading() {
    const originalLoadMenuData = window.loadMenuData;
    if (originalLoadMenuData) {
        window.loadMenuData = function(menuId) {
            originalLoadMenuData(menuId);
            
            // Load icon data if available
            if (currentRealm && menuCache[currentRealm]) {
                const menu = menuCache[currentRealm].find(m => m.id === menuId);
                if (menu && menu.icon) {
                    const iconInput = document.getElementById('menu-icon');
                    const iconPreview = document.getElementById('menu-icon-preview');
                    
                    if (iconInput) iconInput.value = menu.icon;
                    if (iconPreview && menu.icon) {
                        iconPreview.innerHTML = renderIconDirect(menu.icon, 20);
                        iconPreview.style.color = '#00d4ff';
                        iconPreview.style.background = 'rgba(0, 212, 255, 0.1)';
                        try { applyIconFixups(iconPreview); } catch (e) {}
                    }
                }
            }
        };
    }
}

// Initialize icon functionality when document is ready
document.addEventListener('DOMContentLoaded', function() {
    updateRealmDataLoading();
        updateMenuDataLoading();
        try { applyIconFixups(document); } catch (e) {}
    });
</script>

<?php
// Footer removed per request
echo "\n</body>\n</html>";
?>
