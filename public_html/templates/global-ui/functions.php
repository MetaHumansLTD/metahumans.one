<?php
/**
 * Global UI Functions - Component Rendering Functions Only
 * This file contains only the rendering functions without the full manager interface
 */

// Prevent multiple inclusions
if (defined('GLOBAL_UI_FUNCTIONS_LOADED')) {
    return;
}
define('GLOBAL_UI_FUNCTIONS_LOADED', true);

// Only require cue.php if not already loaded (prevents circular dependency)
if (!function_exists('cue_autoload')) {
    require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * Get current realm information using existing navigator system
 * @return array|null Realm data or null if not found
 */
if (!function_exists('getCurrentRealm')) {
function getCurrentRealm() {
    static $currentRealm = null;

    if ($currentRealm !== null) {
        return $currentRealm;
    }

    $currentRealmId = $_SESSION['current_realm'] ?? null;

    if (!$currentRealmId) {
        return null;
    }

    $emergencyDisabled = (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED);
    if ($emergencyDisabled) {
        return null;
    }
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
    } catch (Throwable $e) {
        return null;
    }
    if (function_exists('database_hasActiveConfigurations') && !database_hasActiveConfigurations()) {
        return null;
    }
    
    // Use existing navigator system to get realm data
    try {
        // Cache navigator instance to avoid multiple slow database connections
        static $cachedNavigator = null;
        if ($cachedNavigator === null) {
            $cachedNavigator = new NavigationDatabaseManager();
        }
        $navigator = $cachedNavigator;
        $realms = $navigator->getRealms();

        // Find the current realm in the realms object
        if (isset($realms->$currentRealmId)) {
            $realm = $realms->$currentRealmId;
            $currentRealm = [
                'id' => $realm->id,
                'name' => $realm->name,
                'folder_name' => $realm->slug ?? $realm->id, // Use slug as folder name
                'color' => $realm->color ?? '#00ffff',
                'description' => $realm->description ?? ''
            ];
        }
    } catch (Exception $e) {
        error_log("Failed to get current realm: " . $e->getMessage());
    }

    return $currentRealm;
}
}

// Include realm management functions
require_once __DIR__ . '/../menus/navigation-database-manager.php';

/**
 * Caching completely removed to improve performance
 */
function clearHamburgerMenuCache() {
    // No-op - caching removed
    error_log('Caching system disabled for performance');
}



/**
 * Get navigation items for current realm using existing navigator system
 */
// if (!function_exists('getNavigationItems')) {
if (!function_exists('getNavigationItems')) {
function getNavigationItems() {
    $emergencyDisabled = (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED);
    if ($emergencyDisabled) {
        return [];
    }
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
    } catch (Throwable $e) {
        return [];
    }
    if (function_exists('database_hasActiveConfigurations') && !database_hasActiveConfigurations()) {
        return [];
    }
    
    try {
        // Cache navigator instance to avoid multiple slow database connections
        static $cachedNavigator = null;
        if ($cachedNavigator === null) {
            $cachedNavigator = new NavigationDatabaseManager();
        }
        $navigator = $cachedNavigator;
        $currentRealm = getCurrentRealm();
        $realmId = $currentRealm ? $currentRealm['id'] : 'guest';

        $menus = $navigator->getMenus($realmId);
        $navigationItems = [];

        foreach ($menus as $menu) {
            $submenus = [];
            $submenuList = is_object($menu) ? ($menu->submenu ?? []) : [];
            if (is_array($submenuList)) {
                foreach ($submenuList as $sm) {
                    if (!is_object($sm)) {
                        continue;
                    }
                    $t = isset($sm->title) ? trim((string)$sm->title) : '';
                    if ($t === '' && isset($sm->name)) {
                        $t = trim((string)$sm->name);
                    }
                    $u = isset($sm->url) ? trim((string)$sm->url) : '';
                    if ($t === '') {
                        continue;
                    }
                    $submenus[] = [
                        'title' => $t,
                        'url' => $u !== '' ? $u : '#',
                        'icon' => $sm->icon ?? '',
                    ];
                }
            }
            $navigationItems[] = [
                'title' => $menu->title ?? $menu->name ?? 'Untitled',
                'url' => $menu->url ?? '#',
                'icon' => $menu->icon ?? '',
                'submenus' => $submenus
            ];
        }

        return $navigationItems;
    } catch (Exception $e) {
        error_log("Failed to load navigation menus: " . $e->getMessage());
        return [];
    }
}
}

function globalUi_normalizeIconClass(string $iconVal): string {
    $iconVal = trim($iconVal);
    if ($iconVal === '') {
        return '';
    }
    if (stripos($iconVal, 'thesvg:') === 0) {
        return $iconVal;
    }
    if (str_starts_with($iconVal, 'fa-')) {
        return 'fa ' . $iconVal;
    }
    if (stripos($iconVal, 'iconoir') !== false) {
        return $iconVal;
    }
    if (stripos($iconVal, 'ph ') !== false || str_starts_with($iconVal, 'ph-')) {
        return $iconVal;
    }
    if (preg_match('/\b(fa|fas|far|fab|fal|fad|fa-solid|fa-regular|fa-brands|fa-light|fa-thin|fa-duotone|fa-sharp|fa-sharp-solid|fa-sharp-regular|fa-sharp-light|fa-sharp-thin)\b/i', $iconVal)) {
        return $iconVal;
    }
    if (strpos($iconVal, ' ') === false) {
        $name = preg_replace('/[^a-z0-9\-]/i', '', $iconVal);
        if (is_string($name) && $name !== '') {
            static $iconoirCss = null;
            if ($iconoirCss === null) {
                $iconoirPath = dirname(__DIR__) . '/assets/icons/iconoir/css/iconoir.css';
                $iconoirCss = is_file($iconoirPath) ? (string)file_get_contents($iconoirPath) : '';
            }
            if ($iconoirCss !== '' && strpos($iconoirCss, '.iconoir-' . $name . '::before') !== false) {
                return 'iconoir-' . $name;
            }
        }
    }
    return 'fa fa-' . $iconVal;
}

function globalUi_normalizeUrl(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if ($url === '#' || stripos($url, 'javascript:') === 0) {
        return '';
    }
    if (stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        return $url;
    }
    if (str_starts_with($url, '?')) {
        return $url;
    }
    return '/' . ltrim($url, '/');
}
// }

/**
 * Render Global Header Component
 * @param array|bool $config Optional configuration override or debug flag
 * @return void
 */
function renderGlobalHeader($config = []) {
    // Handle debug parameter passed as boolean
    if (is_bool($config)) {
        $debug = $config;
        $config = [];
        if ($debug) {
            $_GET['debug_header'] = '1';
        }
    }
    
    // Realm Auto-Detection Logic - Folder-Based
    $currentRealm = $_SESSION['current_realm'] ?? null;
    if (!$currentRealm && function_exists('cue_autoload')) {
        try {
            // Check database status properly
            $emergencyDisabled = (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED);
            $hasActiveDB = function_exists('database_hasActiveConfigurations') ? database_hasActiveConfigurations() : false;
            
            if ($emergencyDisabled || !$hasActiveDB) {
                $realms = (object)[]; // Empty object if database operations disabled or no active databases
            } else {
                // Use existing NavigationDatabaseManager for realm detection
                require_once dirname(__DIR__) . '/menus/navigation-database-manager.php';
                // Cache navigator instance to avoid multiple slow database connections
                static $cachedNavigator = null;
                if ($cachedNavigator === null) {
                    $cachedNavigator = new NavigationDatabaseManager();
                }
                $navigator = $cachedNavigator;
                $realms = $navigator->getRealms();
            }

            if (!empty($realms) && is_object($realms)) {
                // Extract folder name from request URI
                $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                $pathParts = explode('/', trim($requestUri, '/'));
                $folderName = $pathParts[0] ?? '';

                // Skip empty folder names and common non-realm paths
                $skipFolders = ['', 'templates', 'gear', 'favicon.ico', 'index.php'];
                if (!empty($folderName) && !in_array($folderName, $skipFolders)) {
                    // Look for realm by slug (folder name)
                    foreach ($realms as $realmId => $realm) {
                        $realmSlug = $realm->slug ?? $realm->id;
                        if ($realmSlug === $folderName && ($realm->status ?? 'active') === 'active') {
                            $currentRealm = $realmId;
                            $_SESSION['current_realm'] = $currentRealm;
                            error_log("Realm Auto-Detection: Detected realm '{$realm->name}' for folder '{$folderName}'");
                            break;
                        }
                    }

                    if (!$currentRealm) {
                        error_log("Realm Auto-Detection: No realm found for folder '{$folderName}'");
                    }
                }

                // Fallback: Try to find a default realm if no folder match
                if (!$currentRealm) {
                    // Look for a realm with name 'Kripz Masters' or use first active realm
                    foreach ($realms as $realmId => $realm) {
                        if (($realm->name ?? '') === 'Kripz Masters' && ($realm->status ?? 'active') === 'active') {
                            $currentRealm = $realmId;
                            $_SESSION['current_realm'] = $currentRealm;
                            error_log("Realm Auto-Detection: Using default realm 'Kripz Masters'");
                            break;
                        }
                    }

                    // If no 'Kripz Masters' found, use first active realm
                    if (!$currentRealm) {
                        foreach ($realms as $realmId => $realm) {
                            if (($realm->status ?? 'active') === 'active') {
                                $currentRealm = $realmId;
                                $_SESSION['current_realm'] = $currentRealm;
                                error_log("Realm Auto-Detection: Using first available realm '{$realm->name}'");
                                break;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Realm Auto-Detection failed: " . $e->getMessage());
        }
    }

    // Default Header Configuration - Minimal defaults, primary config loaded from JSON
    $headerConfig = [
        'enabled' => true,
        'show_navigation' => true,  // Enable navigation for realm-specific menus
        'navigation_items' => getNavigationItems(), // Load realm-specific menus
    ];
    
    // Load from JSON config if exists - Always reload for fresh data
    $configFile = getGlobalUIConfigPath('header', 'config');
    
    if (file_exists($configFile)) {
        // Clear any file cache and force fresh read
        clearstatcache(true, $configFile);
        $savedConfig = json_decode(file_get_contents($configFile), true);
        
        if ($savedConfig && is_array($savedConfig)) {
            // Parse complex nested JSON structure
            if (isset($savedConfig['K::HeaderUI::Configuration'])) {
                $nestedConfig = $savedConfig['K::HeaderUI::Configuration'];
                
                // Find the first configuration block
                foreach ($nestedConfig as $configBlock) {
                    if (is_array($configBlock)) {
                        // Map the complex keys to simple keys
                        
                        // Site name configuration
                        if (isset($configBlock['hdr_site_name_text'])) {
                            $headerConfig['site_name'] = $configBlock['hdr_site_name_text'];
                        }
                        if (isset($configBlock['hdr_site_name_enabled'])) {
                            $headerConfig['site_name_enabled'] = (bool)$configBlock['hdr_site_name_enabled'];
                        }
                        
                        // Slogan/Subtitle Configuration
                        if (isset($configBlock['hdr_slogan_enabled'])) {
                            $headerConfig['slogan_enabled'] = (bool)$configBlock['hdr_slogan_enabled'];
                        }
                        if (isset($configBlock['hdr_slogan_text'])) {
                            $headerConfig['slogan_text'] = $configBlock['hdr_slogan_text'];
                        }
                        if (isset($configBlock['hdr_slogan_font'])) {
                            $headerConfig['slogan_font'] = $configBlock['hdr_slogan_font'];
                        }
                        if (isset($configBlock['hdr_slogan_size'])) {
                            $headerConfig['slogan_size'] = (int)$configBlock['hdr_slogan_size'];
                        }
                        if (isset($configBlock['hdr_slogan_position'])) {
                            $headerConfig['slogan_position'] = $configBlock['hdr_slogan_position'];
                        }
                        if (isset($configBlock['hdr_slogan_color'])) {
                            $headerConfig['slogan_color'] = $configBlock['hdr_slogan_color'];
                        }
                        if (isset($configBlock['hdr_slogan_opacity'])) {
                            $headerConfig['slogan_opacity'] = (int)$configBlock['hdr_slogan_opacity'];
                        }
                        
                        // Title Configuration
                        if (isset($configBlock['hdr_title_font'])) {
                            $headerConfig['title_font'] = $configBlock['hdr_title_font'];
                        }
                        if (isset($configBlock['hdr_title_size'])) {
                            $headerConfig['title_size'] = (int)$configBlock['hdr_title_size'];
                        }
                        if (isset($configBlock['hdr_title_color'])) {
                            $headerConfig['title_color'] = $configBlock['hdr_title_color'];
                        }
                        if (isset($configBlock['hdr_title_position'])) {
                            $headerConfig['title_position'] = $configBlock['hdr_title_position'];
                        }
                        if (isset($configBlock['hdr_title_opacity'])) {
                            $headerConfig['title_opacity'] = (int)$configBlock['hdr_title_opacity'];
                        }
                        if (isset($configBlock['hdr_title_slogan_spacing'])) {
                            $headerConfig['title_slogan_spacing'] = (int)$configBlock['hdr_title_slogan_spacing'];
                        }
                        if (isset($configBlock['hdr_content_spacing'])) {
                            $headerConfig['header_content_spacing'] = (int)$configBlock['hdr_content_spacing'];
                        }
                        
                        // Logo configuration
                        if (isset($configBlock['hdr_logo_image_path'])) {
                            $headerConfig['logo_url'] = $configBlock['hdr_logo_image_path'];
                        }
                        if (isset($configBlock['hdr_logo_enabled'])) {
                            $headerConfig['logo_enabled'] = (bool)$configBlock['hdr_logo_enabled'];
                        }
                        if (isset($configBlock['hdr_logo_width'])) {
                            $headerConfig['logo_width'] = (int)$configBlock['hdr_logo_width'];
                        }
                        if (isset($configBlock['hdr_logo_height'])) {
                            $headerConfig['logo_height'] = (int)$configBlock['hdr_logo_height'];
                        }
                        if (isset($configBlock['hdr_logo_aspect_locked'])) {
                            $headerConfig['logo_aspect_locked'] = (bool)$configBlock['hdr_logo_aspect_locked'];
                        }
                        if (isset($configBlock['hdr_logo_position'])) {
                            $headerConfig['logo_position'] = $configBlock['hdr_logo_position'];
                        }
                        if (isset($configBlock['hdr_logo_margin_x'])) {
                            $headerConfig['logo_margin_x'] = (int)$configBlock['hdr_logo_margin_x'];
                        }
                        if (isset($configBlock['hdr_logo_margin_y'])) {
                            $headerConfig['logo_margin_y'] = (int)$configBlock['hdr_logo_margin_y'];
                        }
                        if (isset($configBlock['hdr_logo_animation_enabled'])) {
                            $headerConfig['logo_animation_enabled'] = (bool)$configBlock['hdr_logo_animation_enabled'];
                        }
                        if (isset($configBlock['hdr_logo_animation_type'])) {
                            $headerConfig['logo_animation_type'] = $configBlock['hdr_logo_animation_type'];
                        }
                        if (isset($configBlock['hdr_logo_animation_duration'])) {
                            $headerConfig['logo_animation_duration'] = (float)$configBlock['hdr_logo_animation_duration'];
                        }
                        if (isset($configBlock['hdr_logo_glow_enabled'])) {
                            $headerConfig['logo_glow_enabled'] = (bool)$configBlock['hdr_logo_glow_enabled'];
                        }
                        if (isset($configBlock['hdr_logo_glow_color'])) {
                            $headerConfig['logo_glow_color'] = $configBlock['hdr_logo_glow_color'];
                        }
                        if (isset($configBlock['hdr_logo_glow_intensity'])) {
                            $headerConfig['logo_glow_intensity'] = (int)$configBlock['hdr_logo_glow_intensity'];
                        }
                        
                        // Header styling configuration
                        if (isset($configBlock['hdr_height'])) {
                            $headerConfig['header_height'] = (int)$configBlock['hdr_height'];
                        }
                        if (isset($configBlock['hdr_position'])) {
                            $headerConfig['position'] = $configBlock['hdr_position'];
                        }
                        if (isset($configBlock['hdr_vertical_alignment'])) {
                            $headerConfig['vertical_alignment'] = $configBlock['hdr_vertical_alignment'];
                        }
                        // Enhanced Background Configuration
                        if (isset($configBlock['hdr_background_type'])) {
                            $headerConfig['background_type'] = $configBlock['hdr_background_type'];
                        }
                        if (isset($configBlock['hdr_background_color'])) {
                            $headerConfig['background_color'] = $configBlock['hdr_background_color'];
                        }
                        if (isset($configBlock['hdr_background_opacity'])) {
                            $headerConfig['background_opacity'] = (int)$configBlock['hdr_background_opacity'];
                        }
                        
                        // Gradient Configuration
                        if (isset($configBlock['hdr_gradient_color1'])) {
                            $headerConfig['gradient_color1'] = $configBlock['hdr_gradient_color1'];
                        }
                        if (isset($configBlock['hdr_gradient_color2'])) {
                            $headerConfig['gradient_color2'] = $configBlock['hdr_gradient_color2'];
                        }
                        if (isset($configBlock['hdr_gradient_color3'])) {
                            $headerConfig['gradient_color3'] = $configBlock['hdr_gradient_color3'];
                        }
                        if (isset($configBlock['hdr_gradient_angle'])) {
                            $headerConfig['gradient_angle'] = (int)$configBlock['hdr_gradient_angle'];
                        }
                        if (isset($configBlock['hdr_gradient_multi_enabled'])) {
                            $headerConfig['gradient_multi_enabled'] = (bool)$configBlock['hdr_gradient_multi_enabled'];
                        }
                        if (isset($configBlock['hdr_gradient_opacity'])) {
                            $headerConfig['gradient_opacity'] = (int)$configBlock['hdr_gradient_opacity'];
                        }
                        
                        // Animated Background Configuration
                        if (isset($configBlock['hdr_animation_type'])) {
                            $headerConfig['animation_type'] = $configBlock['hdr_animation_type'];
                        }
                        if (isset($configBlock['hdr_animation_color'])) {
                            $headerConfig['animation_color'] = $configBlock['hdr_animation_color'];
                        }
                        if (isset($configBlock['hdr_animation_speed'])) {
                            $headerConfig['animation_speed'] = (float)$configBlock['hdr_animation_speed'];
                        }
                        if (isset($configBlock['hdr_animation_scale'])) {
                            $headerConfig['animation_scale'] = (float)$configBlock['hdr_animation_scale'];
                        }
                        if (isset($configBlock['hdr_animation_opacity'])) {
                            $headerConfig['animation_opacity'] = (int)$configBlock['hdr_animation_opacity'];
                        }
                        if (isset($configBlock['hdr_text_color'])) {
                            $headerConfig['text_color'] = $configBlock['hdr_text_color'];
                        }
                        
                        // Visual Effects Configuration
                        if (isset($configBlock['hdr_shadow_enabled'])) {
                            $headerConfig['shadow_enabled'] = (bool)$configBlock['hdr_shadow_enabled'];
                        }
                        if (isset($configBlock['hdr_shadow_color'])) {
                            $headerConfig['shadow_color'] = $configBlock['hdr_shadow_color'];
                        }
                        if (isset($configBlock['hdr_shadow_blur'])) {
                            $headerConfig['shadow_blur'] = (int)$configBlock['hdr_shadow_blur'];
                        }
                        if (isset($configBlock['hdr_shadow_x'])) {
                            $headerConfig['shadow_x'] = (int)$configBlock['hdr_shadow_x'];
                        }
                        if (isset($configBlock['hdr_shadow_y'])) {
                            $headerConfig['shadow_y'] = (int)$configBlock['hdr_shadow_y'];
                        }
                        if (isset($configBlock['hdr_shadow_spread'])) {
                            $headerConfig['shadow_spread'] = (int)$configBlock['hdr_shadow_spread'];
                        }
                        if (isset($configBlock['hdr_border_enabled'])) {
                            $headerConfig['border_enabled'] = (bool)$configBlock['hdr_border_enabled'];
                        }
                        if (isset($configBlock['hdr_border_color'])) {
                            $headerConfig['border_color'] = $configBlock['hdr_border_color'];
                        }
                        if (isset($configBlock['hdr_border_width'])) {
                            $headerConfig['border_width'] = (int)$configBlock['hdr_border_width'];
                        }
                        if (isset($configBlock['hdr_border_style'])) {
                            $headerConfig['border_style'] = $configBlock['hdr_border_style'];
                        }
                        if (isset($configBlock['hdr_border_radius'])) {
                            $headerConfig['border_radius'] = (int)$configBlock['hdr_border_radius'];
                        }
                        if (isset($configBlock['hdr_glow_enabled'])) {
                            $headerConfig['glow_enabled'] = (bool)$configBlock['hdr_glow_enabled'];
                        }
                        if (isset($configBlock['hdr_glow_color'])) {
                            $headerConfig['glow_color'] = $configBlock['hdr_glow_color'];
                        }
                        if (isset($configBlock['hdr_glow_intensity'])) {
                            $headerConfig['glow_intensity'] = (int)$configBlock['hdr_glow_intensity'];
                        }
                        if (isset($configBlock['hdr_glow_size'])) {
                            $headerConfig['glow_size'] = (int)$configBlock['hdr_glow_size'];
                        }
                        
                        // Navigation configuration
                        // Check both complex and simple navigation keys
                        if (isset($configBlock['hdr_show_navigation'])) {
                            $headerConfig['show_navigation'] = (bool)$configBlock['hdr_show_navigation'];
                        } elseif (isset($configBlock['show_navigation'])) {
                            $headerConfig['show_navigation'] = (bool)$configBlock['show_navigation'];
                        }
                        
                        break; // Use first config block found
                    }
                }
            }
            
            // Also check for direct configuration (fallback)
            if (isset($savedConfig['show_navigation'])) {
                $headerConfig['show_navigation'] = (bool)$savedConfig['show_navigation'];
            }
            if (isset($savedConfig['site_title'])) {
                $headerConfig['site_title'] = $savedConfig['site_title'];
            }
        }
    }
    
    // Override with any passed config
    if (!empty($config)) {
        $headerConfig = array_merge($headerConfig, $config);
    }
    

    
    // Don't render header if disabled
    if (!($headerConfig['enabled'] ?? true)) {
        return;
    }
    
    $headerGap = (int)($headerConfig['hdr_content_spacing'] ?? $headerConfig['header_content_spacing'] ?? 15);
    $headerAutoOffset = array_key_exists('hdr_auto_offset', $headerConfig) ? (bool)$headerConfig['hdr_auto_offset'] : true;
    echo '<header class="cue-global-header" data-component="header" data-position="' . htmlspecialchars($headerConfig['position'] ?? 'fixed') . '" data-content-gap="' . $headerGap . '" data-auto-offset="' . ($headerAutoOffset ? '1' : '0') . '">';
    
    // Add animated background if enabled
    $backgroundType = $headerConfig['background_type'] ?? 'solid';
    if ($backgroundType === 'animated') {
        $animationType = $headerConfig['animation_type'] ?? 'none';
        if ($animationType !== 'none') {
            echo '<div class="header-animation-background" id="headerAnimationBg" data-animation="' . htmlspecialchars($animationType) . '" data-color="' . htmlspecialchars($headerConfig['animation_color'] ?? '#0066aa') . '" data-speed="' . htmlspecialchars($headerConfig['animation_speed'] ?? '1.0') . '" data-scale="' . htmlspecialchars($headerConfig['animation_scale'] ?? '1.0') . '"></div>';
        }
    }
    
    // Vertical alignment setting
    $verticalAlignment = $headerConfig['vertical_alignment'] ?? 'middle';
    $alignItems = $verticalAlignment === 'top' ? 'flex-start' : 
                  ($verticalAlignment === 'bottom' ? 'flex-end' : 'center');
    
    echo '<div class="header-container" style="display: flex; align-items: ' . $alignItems . '; width: 100%; padding: 10px 20px;">';
    
    // Logo section with proper positioning based on settings
    $logoPosition = $headerConfig['logo_position'] ?? 'left';
    $logoOrder = $logoPosition === 'right' ? '3' : ($logoPosition === 'center' ? '2' : '1');
    
    echo '<div class="header-logo-section" style="flex: 0 0 auto; display: flex; align-items: ' . $alignItems . '; order: ' . $logoOrder . ';">';
    
    // Enhanced logo rendering with full customization - show if enabled
    $logoEnabled = $headerConfig['logo_enabled'] ?? false;
    $logoUrl = $headerConfig['logo_url'] ?? '';
    
    if ($logoEnabled) {
        $logoStyles = [];
        
        // Size customization
        if (isset($headerConfig['logo_width'])) {
            $logoStyles[] = 'width: ' . (int)$headerConfig['logo_width'] . 'px';
        }
        if (isset($headerConfig['logo_height'])) {
            $logoStyles[] = 'height: ' . (int)$headerConfig['logo_height'] . 'px';
        }
        
        // Margin customization
        if (isset($headerConfig['logo_margin_x']) && isset($headerConfig['logo_margin_y'])) {
            $logoStyles[] = 'margin: ' . (int)$headerConfig['logo_margin_y'] . 'px ' . (int)$headerConfig['logo_margin_x'] . 'px';
        }
        
        // Object fit
        $logoStyles[] = 'object-fit: contain';
        
        // Glow effect
        if ($headerConfig['logo_glow_enabled'] ?? false) {
            $glowColor = $headerConfig['logo_glow_color'] ?? '#00d4ff';
            $glowIntensity = $headerConfig['logo_glow_intensity'] ?? '5';
            $logoStyles[] = 'filter: drop-shadow(0 0 ' . (int)$glowIntensity . 'px ' . htmlspecialchars($glowColor) . ')';
        }
        
        // Animation
        $animationClass = '';
        if ($headerConfig['logo_animation_enabled'] ?? false) {
            $animationType = $headerConfig['logo_animation_type'] ?? 'none';
            $animationDuration = $headerConfig['logo_animation_duration'] ?? '1.0';
            
            switch($animationType) {
                case 'pulse':
                    $animationClass = 'animate-pulse';
                    break;
                case 'bounce':
                    $animationClass = 'animate-bounce';
                    break;
                case 'rotate':
                    $animationClass = 'animate-rotate';
                    break;
                case 'wobble':
                    $animationClass = 'animate-wobble';
                    break;
                case 'fade':
                    $animationClass = 'animate-fade-in';
                    break;
                case 'scale':
                    $animationClass = 'animate-scale-up';
                    break;
                case 'glow':
                    $animationClass = 'animate-glow';
                    break;
            }
            
            if ($animationClass) {
                $logoStyles[] = 'animation-duration: ' . (float)$animationDuration . 's';
            }
        }
        
        $styleString = !empty($logoStyles) ? ' style="' . implode('; ', $logoStyles) . '"' : '';
        $classString = !empty($animationClass) ? ' class="header-logo ' . $animationClass . '"' : ' class="header-logo"';
        
        if (!empty($logoUrl)) {
            // Check if file exists locally
            $logoPath = getPublicPath() . $logoUrl;
            if (file_exists($logoPath) || filter_var($logoUrl, FILTER_VALIDATE_URL)) {
                echo '<img src="' . htmlspecialchars($logoUrl) . '" alt="Logo"' . $classString . $styleString . '>';
            } else {
                // Show placeholder when logo enabled but file doesn't exist
                echo '<div' . $classString . $styleString . ' style="' . implode('; ', array_merge($logoStyles, ['border: 2px dashed #00ffff', 'display: flex', 'align-items: center', 'justify-content: center', 'color: #00ffff', 'font-size: 12px', 'min-width: 60px', 'min-height: 60px'])) . '">Logo</div>';
            }
        } else {
            // Show placeholder when logo enabled but no image selected
            echo '<div' . $classString . $styleString . ' style="' . implode('; ', array_merge($logoStyles, ['border: 2px dashed #00ffff', 'display: flex', 'align-items: center', 'justify-content: center', 'color: #00ffff', 'font-size: 12px', 'min-width: 60px', 'min-height: 60px'])) . '">Select Logo</div>';
        }
    }
    echo '</div>';
    
    // Title section with proper positioning and ordering
    $titlePosition = $headerConfig['title_position'] ?? 'left';
    $titleOrder = ($logoPosition === 'left' && $titlePosition === 'right') ? '3' : 
                 (($logoPosition === 'right' && $titlePosition === 'left') ? '1' : '2');
    
    if ($titlePosition === 'right') {
        echo '<div class="header-title-section" style="flex: 1; display: flex; flex-direction: column; align-items: flex-end; text-align: right; order: ' . $titleOrder . '; min-width: 0; overflow: hidden;">';
    } elseif ($titlePosition === 'center') {
        echo '<div class="header-title-section" style="flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; order: ' . $titleOrder . '; min-width: 0; overflow: hidden;">';
    } else {
        echo '<div class="header-title-section" style="flex: 1; display: flex; flex-direction: column; align-items: flex-start; text-align: left; order: ' . $titleOrder . '; min-width: 0; overflow: hidden;">';
    }
    
    // Render title with styling (using !important for specificity)
    $titleStyles = [];
    if (isset($headerConfig['title_color'])) {
        $titleStyles[] = 'color: ' . $headerConfig['title_color'] . ' !important';
    }
    if (isset($headerConfig['title_size'])) {
        $titleStyles[] = 'font-size: ' . (int)$headerConfig['title_size'] . 'px !important';
    }
    if (isset($headerConfig['title_opacity'])) {
        $titleStyles[] = 'opacity: ' . ((int)$headerConfig['title_opacity'] / 100) . ' !important';
    }
    // Position is now handled by container flexbox structure
    if (isset($headerConfig['title_font'])) {
        // Convert font name to font-family CSS
        $fontFamily = str_replace(['-Regular', '-Bold', '-Light'], '', $headerConfig['title_font']);
        $fontFamily = str_replace('-', ' ', $fontFamily);
        $titleStyles[] = 'font-family: "' . $fontFamily . '", serif !important';
        
        // Apply font weight based on font variant
        if (strpos($headerConfig['title_font'], 'Bold') !== false) {
            $titleStyles[] = 'font-weight: bold !important';
        } elseif (strpos($headerConfig['title_font'], 'Light') !== false) {
            $titleStyles[] = 'font-weight: 300 !important';
        } else {
            $titleStyles[] = 'font-weight: normal !important';
        }
    }
    
    // Add margin reset and positioning to title styles to prevent conflicts
    $titleStyles[] = 'margin: 0 !important';
    $titleStyles[] = 'line-height: 1.2 !important';
    $titleStyles[] = 'word-wrap: break-word !important';
    $titleStyles[] = 'overflow-wrap: break-word !important';
    $titleStyles[] = 'max-width: 100% !important';
    
    // Add title-slogan spacing - apply if spacing is set (allows 0 for no spacing)
    if (isset($headerConfig['title_slogan_spacing'])) {
        $titleStyles[] = 'margin-bottom: ' . (int)$headerConfig['title_slogan_spacing'] . 'px !important';
    }
    
    // Only render title if enabled
    if ($headerConfig['site_name_enabled'] ?? true) {
        $titleStyleStr = !empty($titleStyles) ? ' style="' . implode('; ', $titleStyles) . '"' : '';
        $titleClass = 'header-title';
        if (isset($headerConfig['title_position'])) {
            $titleClass .= ' title-' . $headerConfig['title_position'];
        }
        
        echo '<h1 class="' . $titleClass . '"' . $titleStyleStr . '>' . htmlspecialchars($headerConfig['site_name'] ?? 'Your Site') . '</h1>';
    }
    
    // Render slogan if enabled and text exists (but not if positioned under_header)
    $sloganPosition = $headerConfig['slogan_position'] ?? 'center';
    if (($headerConfig['slogan_enabled'] ?? false) && !empty($headerConfig['slogan_text']) && $sloganPosition !== 'under_header') {
        $sloganStyles = [];
        if (isset($headerConfig['slogan_color'])) {
            $sloganStyles[] = 'color: ' . $headerConfig['slogan_color'] . ' !important';
        }
        if (isset($headerConfig['slogan_size'])) {
            $sloganStyles[] = 'font-size: ' . (int)$headerConfig['slogan_size'] . 'px !important';
        }
        if (isset($headerConfig['slogan_opacity'])) {
            $sloganStyles[] = 'opacity: ' . ((int)$headerConfig['slogan_opacity'] / 100) . ' !important';
        }
        if (isset($headerConfig['slogan_position'])) {
            $sloganStyles[] = 'text-align: ' . $headerConfig['slogan_position'] . ' !important';
        }
        if (isset($headerConfig['slogan_font'])) {
            // Convert font name to font-family CSS
            $fontFamily = str_replace(['-Regular', '-Bold', '-Light'], '', $headerConfig['slogan_font']);
            $fontFamily = str_replace('-', ' ', $fontFamily);
            $sloganStyles[] = 'font-family: "' . $fontFamily . '", serif !important';
            
            // Apply font weight based on font variant
            if (strpos($headerConfig['slogan_font'], 'Bold') !== false) {
                $sloganStyles[] = 'font-weight: bold !important';
            } elseif (strpos($headerConfig['slogan_font'], 'Light') !== false) {
                $sloganStyles[] = 'font-weight: 300 !important';
            } else {
                $sloganStyles[] = 'font-weight: normal !important';
            }
        }
        
        // Reset margins and line height
        $sloganStyles[] = 'margin: 0 !important';
        $sloganStyles[] = 'line-height: 1.1 !important';
        $sloganStyles[] = 'word-wrap: break-word !important';
        $sloganStyles[] = 'overflow-wrap: break-word !important';
        $sloganStyles[] = 'max-width: 100% !important';
        
        $sloganStyleStr = !empty($sloganStyles) ? ' style="' . implode('; ', $sloganStyles) . '"' : '';
        $sloganClass = 'header-slogan';
        if (isset($headerConfig['slogan_position'])) {
            $sloganClass .= ' slogan-' . $headerConfig['slogan_position'];
        }
        
        echo '<p class="' . $sloganClass . '"' . $sloganStyleStr . '>' . htmlspecialchars($headerConfig['slogan_text']) . '</p>';
    }
    
    echo '</div>'; // Close title section
    
    // Navigation section - positioned based on layout
    $navOrder = ($logoPosition === 'right') ? '1' : '3';
    echo '<div class="header-nav-section" style="flex: 0 0 auto; display: flex; align-items: ' . $alignItems . '; gap: 15px; order: ' . $navOrder . ';">';
    
    // Navigation section
    if ($headerConfig['show_navigation'] && !empty($headerConfig['navigation_items'])) {
        echo '<nav class="header-navigation" style="display: flex; gap: 15px;">';
        foreach ($headerConfig['navigation_items'] as $item) {
            echo '<a href="' . htmlspecialchars($item['url']) . '" class="nav-item">';
            
            // Process icon - if it's just a class string, wrap it in <i>, otherwise output as is
            $iconHtml = '';
            if (!empty($item['icon'])) {
                $iconVal = trim($item['icon']);
                // Check if it already looks like HTML
                if (strpos($iconVal, '<') !== false && strpos($iconVal, '>') !== false) {
                    $iconHtml = $iconVal;
                } else {
                    // It's likely a class name
                    $rcls = globalUi_normalizeIconClass($iconVal);
                    $iconHtml = '<i class="' . htmlspecialchars($rcls) . '"></i>';
                }
            }
            
            echo '<span class="nav-icon">' . $iconHtml . '</span>';
            echo '<span class="nav-title">' . htmlspecialchars($item['title']) . '</span>';
            echo '</a>';
        }
        echo '</nav>';
    }
    
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    echo '<button type="button" class="mh-header-notices-btn" id="mhHeaderNoticesBtn" data-user="' . htmlspecialchars($u, ENT_QUOTES) . '" data-feed="/hub/notices.php?ajax=feed" aria-label="Notices">';
    echo '<span class="mh-header-notices-dot" aria-hidden="true"></span>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
    echo '</button>';

    echo '<div class="hamburger-trigger-spacer" aria-hidden="true"></div>';
    
    echo '</div>'; // Close header-nav-section
    
    // Status bar section
    echo '<div class="header-status-section" style="flex: 0 0 auto; margin-left: auto; order: 4; display: none !important;">';
    // renderGlobalStatusBar(); // Status bar removed per user request
    echo '</div>';
    
    echo '</div>'; // Close header-container
    
    echo '</header>';
    $gap = (int)($headerConfig['hdr_content_spacing'] ?? $headerConfig['header_content_spacing'] ?? 15);
    $autoOffset = array_key_exists('hdr_auto_offset', $headerConfig) ? (bool)$headerConfig['hdr_auto_offset'] : true;
    echo '<div class="cue-global-header-spacer" data-component="header-spacer" style="height:0px"></div>';
    echo '<script>(function(){var gap=' . json_encode($gap) . ';var auto=' . json_encode($autoOffset) . ';function apply(){var h=document.querySelector(".cue-global-header");var s=document.querySelector(".cue-global-header-spacer");if(!h||!s)return;var p=(getComputedStyle(h).position||"").toLowerCase();var d=(auto&&(p==="fixed"||p==="sticky"))?(h.offsetHeight+gap):0;s.style.height=d+"px";}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply);}else{apply();}window.addEventListener("resize",apply);})();</script>';

    // Status Bar Mobile Visibility
    echo '<style>@media (max-width: 768px) { .header-status-section { display: none !important; } }</style>';
    
    // Render under_header slogan if positioned outside header
    if (($headerConfig['slogan_enabled'] ?? false) && !empty($headerConfig['slogan_text']) && ($headerConfig['slogan_position'] ?? 'center') === 'under_header') {
        $sloganStyles = [];
        if (isset($headerConfig['slogan_color'])) {
            $sloganStyles[] = 'color: ' . $headerConfig['slogan_color'] . ' !important';
        }
        if (isset($headerConfig['slogan_size'])) {
            $sloganStyles[] = 'font-size: ' . (int)$headerConfig['slogan_size'] . 'px !important';
        }
        if (isset($headerConfig['slogan_opacity'])) {
            $sloganStyles[] = 'opacity: ' . ((int)$headerConfig['slogan_opacity'] / 100) . ' !important';
        }
        if (isset($headerConfig['slogan_font'])) {
            // Convert font name to font-family CSS
            $fontFamily = str_replace(['-Regular', '-Bold', '-Light'], '', $headerConfig['slogan_font']);
            $fontFamily = str_replace('-', ' ', $fontFamily);
            $sloganStyles[] = 'font-family: "' . $fontFamily . '", serif !important';
            
            // Apply font weight based on font variant
            if (strpos($headerConfig['slogan_font'], 'Bold') !== false) {
                $sloganStyles[] = 'font-weight: bold !important';
            } elseif (strpos($headerConfig['slogan_font'], 'Light') !== false) {
                $sloganStyles[] = 'font-weight: 300 !important';
            } else {
                $sloganStyles[] = 'font-weight: normal !important';
            }
        }
        
        // Position and styling for under_header
        $sloganStyles[] = 'text-align: center !important';
        $sloganStyles[] = 'margin: 5px 0 !important';
        $sloganStyles[] = 'padding: 0 10px !important';
        $sloganStyles[] = 'position: relative !important';
        $sloganStyles[] = 'z-index: 999 !important';
        $sloganStyles[] = 'background: rgba(0, 0, 0, 0.8) !important';
        $sloganStyles[] = 'backdrop-filter: blur(10px) !important';
        
        $sloganStyleStr = !empty($sloganStyles) ? ' style="' . implode('; ', $sloganStyles) . '"' : '';
        
        echo '<div class="header-under-slogan"' . $sloganStyleStr . '>';
        echo '<p style="margin: 0; padding: 5px 0;">' . htmlspecialchars($headerConfig['slogan_text']) . '</p>';
        echo '</div>';
    }
    includeGlobalUIStyles($headerConfig);
    includeGlobalUIScripts();
    if (function_exists('renderGlobalHamburgerMenu')) {
        renderGlobalHamburgerMenu();
    }
    return;
}

/**
 * Render Global Widgets
 * Renders all active widgets (Status Bar, Back to Top, Loader, Notices, Sidebar, etc.)
 * @param array $config Optional configuration override
 */
function renderGlobalWidgets($config = []) {
    // 1. Status Bar - REMOVED per user request
    // renderGlobalStatusBar();
    
    // 2. Back to Top
    $backToTopPath = dirname(dirname(__DIR__)) . '/templates/widgets/back-to-top/back-to-top.php';
    if (file_exists($backToTopPath)) {
        include $backToTopPath;
    }

    // 3. Load other widgets configuration (Loader, Notices, Sidebar)
    $widgetsConfigPath = function_exists('getDataPath') ? getDataPath() : dirname(dirname(dirname(__DIR__))) . '/secure';
    $widgetConfigFile = $widgetsConfigPath . '/widgets/config.json';
    $widgetConfig = [];
    
    if (file_exists($widgetConfigFile)) {
        $savedConfig = json_decode(file_get_contents($widgetConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::WidgetUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::WidgetUI::Configuration']);
            if (!empty($configKeys)) {
                $widgetConfig = $savedConfig['K::WidgetUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Merge any override config
    $widgetConfig = array_merge($widgetConfig, $config);
    $overlayEnabled = ($widgetConfig['wgt_metahuman_overlay_enabled'] ?? false) ? true : false;
    $sidebarEnabled = (($widgetConfig['wgt_sidebar_enabled'] ?? false) ? true : false) && !$overlayEnabled;

    $reqUri = $_SERVER['REQUEST_URI'] ?? '';
    $isHubMeet = is_string($reqUri) && strpos($reqUri, '/hub/meet/') === 0;
    if ($isHubMeet) {
        $overlayEnabled = false;
        $sidebarEnabled = false;
    }
    
    // Check if any other widgets are enabled
    if (($widgetConfig['wgt_loader_enabled'] ?? true) || 
        ($widgetConfig['wgt_notices_enabled'] ?? true) || 
        ($widgetConfig['wgt_icons_enabled'] ?? false) || 
        $sidebarEnabled ||
        $overlayEnabled) {
        
        echo '<!-- Global Widgets Container -->';
        echo '<div class="cue-global-widgets" data-component="widgets">';
        
        // Render enabled widgets
        if ($widgetConfig['wgt_loader_enabled'] ?? true) {
            echo '<div class="widget-loader" id="global-loader" style="display: none;">';
            echo '<div class="loader-spinner"></div>';
            echo '<div class="loader-text">Loading...</div>';
            echo '</div>';
        }
        
        if ($widgetConfig['wgt_notices_enabled'] ?? true) {
            echo '<div class="widget-notices" id="global-notices"></div>';
        }
        
        if ($sidebarEnabled) {
            echo '<div class="widget-sidebar" id="global-sidebar">';
            echo '<div class="sidebar-content">';
            echo '<h3>Sidebar</h3>';
            echo '<p>Sidebar content goes here...</p>';
            echo '</div>';
            echo '</div>';
        }

        if ($overlayEnabled) {
            $v = isset($widgetConfig['wgt_metahuman_overlay_version']) ? trim((string)$widgetConfig['wgt_metahuman_overlay_version']) : '';
            $v = $v !== '' ? $v : 'latest';
            $hubBase = isset($widgetConfig['wgt_metahuman_overlay_hub_base']) ? trim((string)$widgetConfig['wgt_metahuman_overlay_hub_base']) : '';
            $hubBase = $hubBase !== '' ? $hubBase : '/hub';
            $autostart = ($widgetConfig['wgt_metahuman_overlay_autostart'] ?? true) ? '1' : '0';
            echo '<div id="mh-overlay-mount" data-mh-widget="metahuman-overlay" data-version="' . htmlspecialchars($v, ENT_QUOTES) . '" data-hub-base="' . htmlspecialchars($hubBase, ENT_QUOTES) . '" data-autostart="' . htmlspecialchars($autostart, ENT_QUOTES) . '"></div>';
            $widgetJs = '/templates/widgets/metahuman-overlay/' . rawurlencode($v) . '/widget.js';
            $cacheBuster = (string)time();
            echo '<script src="' . $widgetJs . '?v=' . rawurlencode($cacheBuster) . '" defer></script>';
        }
        
        echo '</div>';
        
        // Include widget styles
        echo '<style>';
        echo '.cue-global-widgets { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 9999; }';
        echo '.widget-loader { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); pointer-events: all; background: rgba(0,0,0,0.8); color: #00ffff; padding: 20px; border-radius: 10px; }';
        echo '.widget-notices { position: absolute; top: 20px; right: 20px; pointer-events: all; }';
        echo '.widget-sidebar { position: absolute; left: 0; top: 0; height: 100%; width: 250px; background: rgba(26,26,46,0.95); pointer-events: all; transform: translateX(-100%); transition: transform 0.3s; }';
        echo '.widget-sidebar.active { transform: translateX(0); }';
        echo '</style>';
        
        $metaUrl = '/templates/assets/images/branding/logo/MHlogoTB64.png';
        echo '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
    }
}

/**
 * Render Global Status Bar
 * Renders the status bar based on its own configuration file
 * @param array $config Optional configuration override
 * @return void
 */
function renderGlobalStatusBar($config = []) {
    $statusBarPath = dirname(dirname(__DIR__)) . '/templates/widgets/status-bar/status-bar.php';
    if (file_exists($statusBarPath)) {
        // Pass config to the included file via a global or variable scope if needed, 
        // but status-bar.php loads its own config. 
        // We can optionally merge $config if status-bar.php supports it.
        // For now, let's just include it.
        include $statusBarPath;
    } else {
        error_log("Status Bar Widget not found at: " . $statusBarPath);
    }
}

/**
 * Generate Global UI CSS
 * Outputs CSS based on header and footer configurations
 * @return void
 */
function generateGlobalUICSS() {
    // Load header configuration
    $headerConfigFile = getDataPath() . '/global-ui/header/header-config.json';
    $headerConfig = [];
    if (file_exists($headerConfigFile)) {
        $savedConfig = json_decode(file_get_contents($headerConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::HeaderUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::HeaderUI::Configuration']);
            if (!empty($configKeys)) {
                $headerConfig = $savedConfig['K::HeaderUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Load footer configuration
    $footerConfigFile = getDataPath() . '/global-ui/footer/footer-config.json';
    $footerConfig = [];
    if (file_exists($footerConfigFile)) {
        $savedConfig = json_decode(file_get_contents($footerConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::FooterUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::FooterUI::Configuration']);
            if (!empty($configKeys)) {
                $footerConfig = $savedConfig['K::FooterUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Load hamburger configuration
    $hamburgerConfigFile = getDataPath() . '/global-ui/hamburger/hamburger-config.json';
    $hamburgerConfig = [];
    if (file_exists($hamburgerConfigFile)) {
        $savedConfig = json_decode(file_get_contents($hamburgerConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::HamburgerUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::HamburgerUI::Configuration']);
            if (!empty($configKeys)) {
                $hamburgerConfig = $savedConfig['K::HamburgerUI::Configuration'][$configKeys[0]];
            }
        } elseif ($savedConfig && isset($savedConfig['K::MenuUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::MenuUI::Configuration']);
            if (!empty($configKeys)) {
                $hamburgerConfig = $savedConfig['K::MenuUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Load widgets configuration
    $widgetConfigFile = getDataPath() . '/widgets/config.json';
    $widgetConfig = [];
    if (file_exists($widgetConfigFile)) {
        $savedConfig = json_decode(file_get_contents($widgetConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::WidgetUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::WidgetUI::Configuration']);
            if (!empty($configKeys)) {
                $widgetConfig = $savedConfig['K::WidgetUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Load theme configuration
    $themeConfigFile = getDataPath() . '/theme/config.json';
    $themeConfig = [];
    if (file_exists($themeConfigFile)) {
        $savedConfig = json_decode(file_get_contents($themeConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::ThemeUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::ThemeUI::Configuration']);
            if (!empty($configKeys)) {
                $themeConfig = $savedConfig['K::ThemeUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    echo '<style id="global-ui-css">';
    
    $headerGap = (int)($headerConfig['hdr_content_spacing'] ?? $headerConfig['header_content_spacing'] ?? 0);
    $footerGap = (int)($footerConfig['ftr_footer_content_spacing'] ?? 0);
    $footerExtra = 0;
    if (!empty($footerConfig['ftr_extra_content_spacing_enabled'])) {
        $footerExtra = (int)($footerConfig['ftr_extra_content_spacing'] ?? 0);
    }
    
    // Apply header title-slogan spacing - fix CSS selectors to match actual HTML
    $headerTitleSloganSpacing = $headerConfig['hdr_title_slogan_spacing'] ?? 10;
    echo '.cue-global-header .header-title { margin-bottom: ' . (int)$headerTitleSloganSpacing . 'px !important; }';
    
    // Apply header slogan positioning - fix CSS selectors and positioning
    $headerSloganPosition = $headerConfig['hdr_slogan_position'] ?? 'center';
    switch($headerSloganPosition) {
        case 'under_header':
            echo '.cue-global-header { position: relative; }';
            echo '.cue-global-header .header-slogan { position: static; width: 100%; padding: 6px 12px; background: rgba(0,0,0,0.8); }';
            break;
        case 'top':
            echo '.cue-global-header .header-title-section { flex-direction: column-reverse !important; }';
            echo '.cue-global-header .header-slogan { margin-bottom: ' . (int)$headerTitleSloganSpacing . 'px !important; margin-top: 0 !important; }';
            break;
        case 'bottom':
        default:
            echo '.cue-global-header .header-title-section { flex-direction: column !important; }';
            echo '.cue-global-header .header-slogan { margin-top: ' . (int)$headerTitleSloganSpacing . 'px !important; margin-bottom: 0 !important; }';
            break;
    }
    
    // Apply footer title-slogan spacing - fix CSS selectors to match actual HTML structure
    $footerTitleSloganSpacing = $footerConfig['ftr_title_slogan_spacing'] ?? 10;
    echo '.cue-global-footer .footer-branding { margin-bottom: ' . (int)$footerTitleSloganSpacing . 'px !important; }';
    
    // Apply footer slogan positioning if configured
    $footerSloganPosition = $footerConfig['ftr_slogan_position'] ?? 'bottom';
    switch($footerSloganPosition) {
        case 'under_site_name':
            // Keep logo and site name horizontal, but allow text section to be vertical
            echo '.cue-global-footer .footer-text-section { flex-direction: column; align-items: flex-start !important; }';
            echo '.cue-global-footer .footer-slogan-under-name { margin-top: ' . (int)$footerTitleSloganSpacing . 'px !important; }';
            break;
        case 'under_footer':
            echo '.cue-global-footer { position: relative; }';
            echo '.cue-global-footer .footer-slogan { position: static; width: 100%; padding: 10px 20px; background: rgba(0,0,0,0.8); }';
            break;
        case 'top':
            echo '.cue-global-footer .footer-container { flex-direction: column-reverse !important; }';
            echo '.cue-global-footer .footer-slogan { order: -1; margin-bottom: ' . (int)$footerTitleSloganSpacing . 'px !important; margin-top: 0 !important; }';
            break;
        case 'bottom':
        default:
            echo '.cue-global-footer .footer-container { flex-direction: column !important; }';
            echo '.cue-global-footer .footer-slogan { order: 1; margin-top: ' . (int)$footerTitleSloganSpacing . 'px !important; margin-bottom: 0 !important; }';
            break;
    }
    
    // Apply footer shadow effects
    if (!empty($footerConfig) && ($footerConfig['ftr_shadow_enabled'] ?? false)) {
        $shadowColor = $footerConfig['ftr_shadow_color'] ?? '#000000';
        $shadowBlur = $footerConfig['ftr_shadow_blur'] ?? 4;
        $shadowX = $footerConfig['ftr_shadow_x'] ?? 0;
        $shadowY = $footerConfig['ftr_shadow_y'] ?? -2;
        $shadowSpread = $footerConfig['ftr_shadow_spread'] ?? 0;
        echo '.cue-global-footer { box-shadow: ' . (int)$shadowX . 'px ' . (int)$shadowY . 'px ' . (int)$shadowBlur . 'px ' . (int)$shadowSpread . 'px ' . htmlspecialchars($shadowColor) . ' !important; }';
    }
    
    // Apply hamburger menu styles
    if (!empty($hamburgerConfig)) {
        $menuBgColor = $hamburgerConfig['menu_background_color'] ?? '#1a1a2e';
        $menuTextColor = $hamburgerConfig['menu_text_color'] ?? '#00ffff';
        $menuIconColor = $hamburgerConfig['menu_icon_color'] ?? '#00ffff';
        $menuPosition = $hamburgerConfig['menu_position'] ?? 'top-right';
        
        echo '.cue-global-hamburger { ';
        switch($menuPosition) {
            case 'top-left':
                echo 'position: fixed; top: 20px; left: 20px;';
                break;
            case 'top-right':
                echo 'position: fixed; top: 20px; right: 20px;';
                break;
            case 'bottom-left':
                echo 'position: fixed; bottom: 20px; left: 20px;';
                break;
            case 'bottom-right':
                echo 'position: fixed; bottom: 20px; right: 20px;';
                break;
        }
        echo 'z-index: 10000; background: ' . $menuBgColor . '; color: ' . $menuIconColor . '; }';
        
        echo '.cue-global-hamburger .hamburger-menu { background: ' . $menuBgColor . '; color: ' . $menuTextColor . '; }';
        echo '.cue-global-hamburger .hamburger-trigger { color: ' . $menuIconColor . '; }';
        $metaUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB400.png') : '';
        if (!empty($metaUrl)) {
            echo '.fa-metahumans{background-image:url(' . htmlspecialchars($metaUrl) . ');background-size:contain;background-repeat:no-repeat;background-position:center;width:1em;height:1em;display:inline-block}';
            echo '.fa-metahumans:before{content:""}';
        }
    }
    
    // Apply widget styles
    if (!empty($widgetConfig)) {
        $animationTheme = $widgetConfig['wgt_animation_theme'] ?? 'cyber';
        $noticeDuration = ($widgetConfig['wgt_notice_duration_seconds'] ?? 5) * 1000; // Convert to ms
        
        echo '.cue-global-widgets { z-index: 9999; }';
        echo '.widget-notices .notice { animation-duration: ' . $noticeDuration . 'ms; }';
        
        // Theme-specific styles
        switch($animationTheme) {
            case 'cyber':
                echo '.widget-loader { border: 1px solid #00ffff; box-shadow: 0 0 10px #00ffff; }';
                break;
            case 'matrix':
                echo '.widget-loader { border: 1px solid #00ff00; box-shadow: 0 0 10px #00ff00; }';
                break;
            case 'neon':
                echo '.widget-loader { border: 1px solid #ff00ff; box-shadow: 0 0 10px #ff00ff; }';
                break;
            case 'minimal':
                echo '.widget-loader { border: 1px solid #cccccc; }';
                break;
        }
    }
    
    // Apply theme global styles if available
    if (!empty($themeConfig)) {
        generateGlobalThemeCSS($themeConfig);
    }
    
    echo '</style>';
}

/**
 * Render Global Footer Component
 * @param array $config Optional configuration override
 * @return void
 */
function renderGlobalFooter($config = []) {
    // Default footer configuration - Minimal defaults, primary config loaded from JSON
    $footerConfig = [
        'ftr_enabled' => true,
    ];
    
    // Load from JSON config if exists - Always reload for fresh data
    $configFile = getDataPath() . '/global-ui/footer/footer-config.json';
    if (file_exists($configFile)) {
        // Clear any file cache and force fresh read
        clearstatcache(true, $configFile);
        $savedConfig = json_decode(file_get_contents($configFile), true);
        if ($savedConfig && isset($savedConfig['K::FooterUI::Configuration'])) {
            // Extract the first configuration
            $configKeys = array_keys($savedConfig['K::FooterUI::Configuration']);
            if (!empty($configKeys)) {
                $footerData = $savedConfig['K::FooterUI::Configuration'][$configKeys[0]];
                $footerConfig = array_merge($footerConfig, $footerData);
            }
        }
    }
    
    // Override with any passed config
    if (!empty($config)) {
        $footerConfig = array_merge($footerConfig, $config);
    }
    
    // Don't render if disabled
    if (!$footerConfig['ftr_enabled']) {
        return;
    }
    
    // Generate footer styles based on configuration
    $footerStyles = [];
    
    // Handle background based on type
    $backgroundType = $footerConfig['ftr_background_type'] ?? 'solid';
    switch ($backgroundType) {
        case 'gradient':
            $startColor = $footerConfig['ftr_gradient_start_color'] ?? '#1a1a2e';
            $endColor = $footerConfig['ftr_gradient_end_color'] ?? '#003344';
            $direction = $footerConfig['ftr_gradient_direction'] ?? 'to right';
            $footerStyles[] = 'background: linear-gradient(' . $direction . ', ' . $startColor . ', ' . $endColor . ')';
            break;
        case 'image':
            if (!empty($footerConfig['ftr_background_image_path'])) {
                $footerStyles[] = 'background-image: url(' . $footerConfig['ftr_background_image_path'] . ')';
                $footerStyles[] = 'background-position: ' . ($footerConfig['ftr_background_position'] ?? 'center');
                $footerStyles[] = 'background-size: ' . ($footerConfig['ftr_background_size'] ?? 'cover');
                $footerStyles[] = 'background-repeat: ' . ($footerConfig['ftr_background_repeat'] ?? 'no-repeat');
            } else {
                $footerStyles[] = 'background-color: ' . ($footerConfig['ftr_footer_background_color'] ?? '#001f3f');
            }
            break;
        case 'animation':
            $footerStyles[] = 'background-color: ' . ($footerConfig['ftr_animation_background_color'] ?? '#000000');
            break;
        default: // solid
            $footerStyles[] = 'background-color: ' . ($footerConfig['ftr_footer_background_color'] ?? '#001f3f');
            break;
    }
    
    $footerStyles[] = 'color: ' . ($footerConfig['ftr_text_color'] ?? '#00ffff');
    $footerStyles[] = 'box-sizing: border-box';
    $footerStyles[] = 'height: ' . ($footerConfig['ftr_footer_height'] ?? 80) . 'px';
    $footerStyles[] = 'padding: ' . ($footerConfig['ftr_padding'] ?? 15) . 'px';
    $footerStyles[] = 'display: flex';
    $footerStyles[] = 'align-items: ' . ((($footerConfig['ftr_vertical_alignment'] ?? 'center') === 'middle') ? 'center' : ($footerConfig['ftr_vertical_alignment'] ?? 'center'));
    $footerStyles[] = 'justify-content: ' . ($footerConfig['ftr_content_alignment'] ?? 'center');
    
    $footerGap = (int)($footerConfig['ftr_footer_content_spacing'] ?? 15);
    $footerAutoOffset = array_key_exists('ftr_auto_offset', $footerConfig) ? (bool)$footerConfig['ftr_auto_offset'] : true;
    $position = (string)($footerConfig['ftr_position'] ?? 'bottom');
    $zIndex = $footerConfig['ftr_z_index'] ?? 'auto';

    switch ($position) {
        case 'fixed':
            $footerStyles[] = 'position: fixed';
            $footerStyles[] = 'bottom: 0';
            $footerStyles[] = 'left: 0';
            $footerStyles[] = 'right: 0';
            $footerStyles[] = 'width: 100vw';
            break;
        case 'sticky':
            $footerStyles[] = 'position: sticky';
            $footerStyles[] = 'bottom: 0';
            $footerStyles[] = 'left: 0';
            $footerStyles[] = 'right: 0';
            $footerStyles[] = 'width: 100%';
            break;
        case 'absolute':
            $footerStyles[] = 'position: absolute';
            $footerStyles[] = 'bottom: 0';
            $footerStyles[] = 'left: 0';
            $footerStyles[] = 'right: 0';
            $footerStyles[] = 'width: 100%';
            break;
        case 'relative':
            $footerStyles[] = 'position: relative';
            $footerStyles[] = 'width: 100%';
            break;
        case 'bottom':
        default:
            $footerStyles[] = 'position: static';
            $footerStyles[] = 'width: 100%';
            break;
    }

    if ($position !== 'fixed' && $position !== 'absolute') {
        $footerStyles[] = 'margin-top: ' . $footerGap . 'px';
    }

    if ($position !== 'bottom' && $zIndex !== 'auto') {
        if (is_int($zIndex) || (is_string($zIndex) && preg_match('/^-?\d+$/', $zIndex))) {
            $footerStyles[] = 'z-index: ' . (int)$zIndex;
        }
    }
    
    // Handle shadow effects
    $shadowEffect = $footerConfig['ftr_shadow_effect'] ?? 'none';
    if ($shadowEffect !== 'none') {
        switch ($shadowEffect) {
            case 'light':
                $footerStyles[] = 'box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.1)';
                break;
            case 'medium':
                $footerStyles[] = 'box-shadow: 0 -4px 8px rgba(0, 0, 0, 0.2)';
                break;
            case 'heavy':
                $footerStyles[] = 'box-shadow: 0 -6px 16px rgba(0, 0, 0, 0.3)';
                break;
        }
    }
    
    // Handle border
    $borderStyle = $footerConfig['ftr_border_style'] ?? 'none';
    if ($borderStyle !== 'none') {
        $borderWidth = $footerConfig['ftr_border_width'] ?? 1;
        $borderColor = $footerConfig['ftr_border_color'] ?? '#00ffff';
        $footerStyles[] = 'border-top: ' . $borderWidth . 'px ' . $borderStyle . ' ' . $borderColor;
    }
    
    // Add custom CSS if provided
    if (!empty($footerConfig['ftr_custom_css'])) {
        // Note: Custom CSS will be added separately as a style tag
    }
    
    $styleAttr = 'style="' . implode('; ', $footerStyles) . ';"';
    
    echo '<footer class="cue-global-footer" data-component="footer" data-position="' . htmlspecialchars($position) . '" data-content-gap="' . $footerGap . '" data-auto-offset="' . ($footerAutoOffset ? '1' : '0') . '" ' . $styleAttr . '>';
    echo '<div class="footer-container footer-content" style="width: 100%; position: relative; display: flex; align-items: inherit; justify-content: inherit; flex-wrap: wrap; gap: 20px;">';
    
    // Site name and logo section
    if ($footerConfig['ftr_site_name_enabled'] || $footerConfig['ftr_logo_enabled']) {
        echo '<div class="footer-branding" style="display: flex; align-items: center; gap: 10px;">';
        
        if ($footerConfig['ftr_logo_enabled'] && !empty($footerConfig['ftr_logo_image_path'])) {
            $logoWidth = $footerConfig['ftr_logo_width'] ?? 40;
            $logoHeight = $footerConfig['ftr_logo_height'] ?? 40;
            echo '<img src="' . htmlspecialchars($footerConfig['ftr_logo_image_path']) . '" alt="Logo" style="width: ' . $logoWidth . 'px; height: ' . $logoHeight . 'px;">';
        }
        
        if ($footerConfig['ftr_site_name_enabled'] && !empty($footerConfig['ftr_site_name_text'])) {
            $siteName = htmlspecialchars($footerConfig['ftr_site_name_text']);
            $fontSize = $footerConfig['ftr_site_name_font_size'] ?? '16px';
            if (is_numeric($fontSize)) $fontSize .= 'px';
            $textColor = $footerConfig['ftr_site_name_color'] ?? $footerConfig['ftr_text_color'] ?? '#00ffff';
            
            // Check if slogan should be under site name
            $sloganPosition = $footerConfig['ftr_slogan_position'] ?? 'center';
            $hasUnderSlogan = $sloganPosition === 'under_site_name' && ($footerConfig['ftr_slogan_enabled'] ?? false) && !empty($footerConfig['ftr_slogan_text']);
            
            if ($hasUnderSlogan) {
                // Create a text section container for site name + slogan
                echo '<div class="footer-text-section" style="display: flex; flex-direction: column; align-items: flex-start;">';
                echo '<span class="footer-site-name" style="font-size: ' . $fontSize . '; color: ' . $textColor . '; font-weight: bold;">' . $siteName . '</span>';
                
                // Add slogan under site name
                $sloganText = htmlspecialchars($footerConfig['ftr_slogan_text']);
                $sloganSize = $footerConfig['ftr_slogan_size'] ?? 14;
                if (is_numeric($sloganSize)) $sloganSize .= 'px';
                $sloganColor = $footerConfig['ftr_slogan_color'] ?? '#cccccc';
                $sloganFont = $footerConfig['ftr_slogan_font'] ?? 'Merriweather-Regular';
                $sloganOpacity = ($footerConfig['ftr_slogan_opacity'] ?? 90) / 100;
                $titleSloganSpacing = $footerConfig['ftr_title_slogan_spacing'] ?? 5;
                
                echo '<div class="footer-slogan-under-name" style="margin-top: ' . (int)$titleSloganSpacing . 'px; font-family: \'' . $sloganFont . '\', sans-serif; font-size: ' . $sloganSize . '; color: ' . $sloganColor . '; opacity: ' . $sloganOpacity . ';">' . $sloganText . '</div>';
                echo '</div>';
            } else {
                // Regular site name without under-slogan
                echo '<span class="footer-site-name" style="font-size: ' . $fontSize . '; color: ' . $textColor . '; font-weight: bold;">' . $siteName . '</span>';
            }
        }
        
        echo '</div>';
    }
    
    // Slogan section (only render if not already rendered under site name)
    $sloganPosition = $footerConfig['ftr_slogan_position'] ?? 'center';
    if (($footerConfig['ftr_slogan_enabled'] ?? false) && !empty($footerConfig['ftr_slogan_text']) && $sloganPosition !== 'under_site_name') {
        $sloganText = htmlspecialchars($footerConfig['ftr_slogan_text']);
        $sloganSize = $footerConfig['ftr_slogan_size'] ?? 14;
        if (is_numeric($sloganSize)) $sloganSize .= 'px';
        $sloganColor = $footerConfig['ftr_slogan_color'] ?? '#cccccc';
        $sloganFont = $footerConfig['ftr_slogan_font'] ?? 'Merriweather-Regular';
        $sloganOpacity = ($footerConfig['ftr_slogan_opacity'] ?? 90) / 100;
        
        // Map position to CSS alignment (basic positioning, enhanced positioning handled by global CSS)
        $alignItems = 'center';
        $justifyContent = 'center';
        $additionalStyles = '';
        
        switch ($sloganPosition) {
            case 'left':
                $justifyContent = 'flex-start';
                break;
            case 'right':
                $justifyContent = 'flex-end';
                break;
            case 'under_site_name':
                // Position under site name within branding section
                $additionalStyles = ' data-position="under_site_name"';
                break;
            case 'top':
            case 'bottom':
            case 'under_footer':
                // Enhanced positioning handled by global CSS
                $additionalStyles = ' data-position="' . $sloganPosition . '"';
                break;
        }
        
        echo '<div class="footer-slogan" style="display: flex; align-items: ' . $alignItems . '; justify-content: ' . $justifyContent . '; width: 100%; margin: 10px 0;"' . $additionalStyles . '>';
        echo '<span style="font-family: \'' . $sloganFont . '\', sans-serif; font-size: ' . $sloganSize . '; color: ' . $sloganColor . '; opacity: ' . $sloganOpacity . ';">' . $sloganText . '</span>';
        echo '</div>';
    }
    
    // Copyright
    if (!empty($footerConfig['ftr_copyright_text'])) {
        $copyrightPosition = $footerConfig['ftr_copyright_position'] ?? 'bottom-center';
        $copyrightSize = $footerConfig['ftr_copyright_size'] ?? 12;
        if (is_numeric($copyrightSize)) $copyrightSize .= 'px';
        $copyrightColor = $footerConfig['ftr_copyright_color'] ?? '#888888';
        
        // Map position to CSS properties
        $positionStyles = [];
        switch ($copyrightPosition) {
            case 'top-left':
                $positionStyles = ['position: absolute', 'top: 10px', 'left: 10px'];
                break;
            case 'top-center':
                $positionStyles = ['position: absolute', 'top: 10px', 'left: 50%', 'transform: translateX(-50%)'];
                break;
            case 'top-right':
                $positionStyles = ['position: absolute', 'top: 10px', 'right: 10px'];
                break;
            case 'bottom-left':
                $positionStyles = ['position: absolute', 'bottom: 10px', 'left: 10px'];
                break;
            case 'bottom-right':
                $positionStyles = ['position: absolute', 'bottom: 10px', 'right: 10px'];
                break;
            case 'bottom-center':
            default:
                $positionStyles = ['position: absolute', 'bottom: 10px', 'left: 50%', 'transform: translateX(-50%)'];
                break;
        }
        
        $copyrightStylesStr = implode('; ', $positionStyles);
        echo '<div class="footer-copyright" style="' . $copyrightStylesStr . '; font-size: ' . $copyrightSize . '; color: ' . $copyrightColor . '; opacity: 0.8;">';
        echo '<p style="margin: 0;">' . htmlspecialchars($footerConfig['ftr_copyright_text']) . '</p>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</footer>';

    $footerExtra = (!empty($footerConfig['ftr_extra_content_spacing_enabled'])) ? (int)($footerConfig['ftr_extra_content_spacing'] ?? 0) : 0;
    echo '<script>(function(){try{var gap=' . json_encode((int)$footerGap) . ';var extra=' . json_encode((int)$footerExtra) . ';function apply(){var f=document.querySelector(".cue-global-footer");if(!f||!document.body)return;var pos=(getComputedStyle(f).position||"").toLowerCase();var should=(pos==="fixed"||pos==="sticky");var h=Math.max(f.offsetHeight||0,f.scrollHeight||0,0);var b=(should?(h+gap+extra):(gap+extra));document.body.style.paddingBottom=b+"px";}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",apply);}else{apply();}window.addEventListener("resize",apply);}catch(e){}})();</script>';

    echo '<script>(function(){try{var k=' . json_encode('mh_auth_event') . ';var bc=null;try{bc=new BroadcastChannel(' . json_encode('mh-auth') . ');}catch(e){};function reload(){try{window.location.reload();}catch(e){}};if(bc){bc.onmessage=function(ev){var d=ev&&ev.data?ev.data:null;var t=d&&(d.type||d.event);if(t==="login"||t==="logout"){reload();}};}window.addEventListener("storage",function(ev){if(ev&&ev.key===k&&ev.newValue){reload();}});window.mhBroadcastAuthEvent=function(type){try{var payload=JSON.stringify({type:type,ts:Date.now()});try{localStorage.setItem(k,payload);}catch(e){};try{if(bc)bc.postMessage({type:type,ts:Date.now()});}catch(e){}}catch(e){}};}catch(e){}})();</script>';
    
    // Add responsive CSS and custom styles
    echo '<style>
    html, body {
        min-height: 100%;
        margin: 0;
    }
    body {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }
    .main-content {
        flex: 1 0 auto;
    }
    .cue-global-footer {
        box-sizing: border-box;
        width: 100%;
        flex-shrink: 0; /* Prevent footer from shrinking */
        margin-top: auto; /* Push to bottom if content is short */
    }
    .footer-container {
        box-sizing: border-box;
        max-width: 1200px;
        margin: 0 auto;
    }
    @media (max-width: 768px) {
        .footer-container {
            flex-direction: column !important;
            text-align: center !important;
            gap: 10px !important;
            padding-bottom: 5px !important;
        }
        /* Mobile Optimization: Show only Logo and Copyright */
        .footer-site-name,
        .footer-text-section,
        .footer-slogan,
        .footer-slogan-under-name {
            display: none !important;
        }
        .footer-branding {
            justify-content: center !important;
            width: 100% !important;
        }
        .footer-copyright {
            position: static !important;
            transform: none !important;
            margin-top: 5px !important;
            width: 100% !important;
            text-align: center !important;
        }

        /* Header Mobile Optimization */
        .cue-global-header .header-container {
            padding: 5px 10px !important;
        }
        .cue-global-header .header-title {
            font-size: 20px !important;
        }
        .cue-global-header .header-slogan {
            display: none !important;
        }
        .cue-global-header .header-logo img {
            max-width: 40px !important;
            height: auto !important;
        }
    }';
    
    // Add animation styles if background type is animation OR if footer animations are enabled
    $hasFooterAnimation = ($footerConfig['ftr_background_type'] ?? 'solid') === 'animation' || 
                         ($footerConfig['ftr_logo_animation_enabled'] ?? false) ||
                         !empty($footerConfig['ftr_animation_type']) && $footerConfig['ftr_animation_type'] !== 'none';
    
    if ($hasFooterAnimation) {
        $animationType = $footerConfig['ftr_animation_type'] ?? 'pulse';
        $animationSpeed = $footerConfig['ftr_animation_speed'] ?? 'normal';
        $animationColor = $footerConfig['ftr_animation_color'] ?? '#00ffff';
        
        // Determine animation duration
        $duration = '2s'; // default
        switch ($animationSpeed) {
            case 'slow': $duration = '4s'; break;
            case 'fast': $duration = '1s'; break;
            default: $duration = '2s'; break;
        }
        
        echo '
        .cue-global-footer[data-animation="true"] {
            animation: footer-' . $animationType . ' ' . $duration . ' infinite;
        }
        @keyframes footer-pulse {
            0%, 100% { background-color: ' . ($footerConfig['ftr_animation_background_color'] ?? '#000000') . '; }
            50% { background-color: ' . $animationColor . '; opacity: 0.8; }
        }
        @keyframes footer-glow {
            0%, 100% { box-shadow: 0 0 5px ' . $animationColor . '; }
            50% { box-shadow: 0 0 20px ' . $animationColor . ', 0 0 30px ' . $animationColor . '; }
        }
        @keyframes footer-gradient {
            0% { background: linear-gradient(90deg, ' . ($footerConfig['ftr_animation_background_color'] ?? '#000000') . ', ' . $animationColor . '); }
            50% { background: linear-gradient(90deg, ' . $animationColor . ', ' . ($footerConfig['ftr_animation_background_color'] ?? '#000000') . '); }
            100% { background: linear-gradient(90deg, ' . ($footerConfig['ftr_animation_background_color'] ?? '#000000') . ', ' . $animationColor . '); }
        }
        
        /* Logo Animation Styles */
        .footer-logo-animated.wobble { animation: footer-logo-wobble infinite; }
        .footer-logo-animated.pulse { animation: footer-logo-pulse infinite; }
        .footer-logo-animated.bounce { animation: footer-logo-bounce infinite; }
        .footer-logo-animated.rotate { animation: footer-logo-rotate infinite linear; }
        .footer-logo-animated.glow { animation: footer-logo-glow infinite; }
        
        @keyframes footer-logo-wobble {
            0%, 100% { transform: rotate(0deg); }
            15% { transform: rotate(-5deg); }
            30% { transform: rotate(3deg); }
            45% { transform: rotate(-3deg); }
            60% { transform: rotate(2deg); }
            75% { transform: rotate(-1deg); }
        }
        @keyframes footer-logo-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        @keyframes footer-logo-bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        @keyframes footer-logo-rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes footer-logo-glow {
            0%, 100% { filter: drop-shadow(0 0 5px ' . ($footerConfig['ftr_logo_glow_color'] ?? '#00ffff') . '); }
            50% { filter: drop-shadow(0 0 15px ' . ($footerConfig['ftr_logo_glow_color'] ?? '#00ffff') . '); }
        }';
    }
    
    // Add custom CSS if provided
    if (!empty($footerConfig['ftr_custom_css'])) {
        $customCss = (string)$footerConfig['ftr_custom_css'];
        $customCss = str_ireplace('</style', '</st yle', $customCss);
        echo "\n" . $customCss;
    }
    
    echo '
    </style>';
    
    // Add animation data attribute if needed
    if ($hasFooterAnimation) {
        echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const footer = document.querySelector(".cue-global-footer");
            if (footer) {
                footer.setAttribute("data-animation", "true");
                // Add logo animation if enabled
                if (' . json_encode($footerConfig['ftr_logo_animation_enabled'] ?? false) . ') {
                    const logoImg = footer.querySelector("img");
                    if (logoImg) {
                        logoImg.classList.add("footer-logo-animated");
                        logoImg.classList.add(' . json_encode((string)($footerConfig['ftr_logo_animation_type'] ?? 'pulse')) . ');
                        logoImg.style.animationDuration = ' . json_encode(((float)($footerConfig['ftr_logo_animation_duration'] ?? 1.0)) . 's') . ';
                    }
                }
            }
        });
        </script>';
    }
}

/**
 * Render Global Hamburger Menu Component
 * @param array $config Optional configuration override
 * @return void
 */
function renderGlobalHamburgerMenu($config = []) {
    if (!empty($GLOBALS['_GLOBAL_HAMBURGER_INCLUDED'])) { return; }
    $GLOBALS['_GLOBAL_HAMBURGER_INCLUDED'] = true;
    // MANDATORY: Load CUE framework
    if (!defined('CUE_CORE_LOADED')) {
        require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
    }
    $authFunctionsPath = dirname(dirname(__DIR__)) . '/auth/auth_functions.php';
    if (is_file($authFunctionsPath)) {
        require_once $authFunctionsPath;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['mh_auth_user']) && (!isset($_SESSION['mh_auth_role']) || trim((string)$_SESSION['mh_auth_role']) === '') && function_exists('mh_load_biometrics_user')) {
        mh_load_biometrics_user((string)$_SESSION['mh_auth_user']);
    }

    // Default Hamburger Configuration - Minimal defaults, primary config loaded from JSON
    $hamburgerConfig = [
        'hbg_enabled' => true,
        'hbg_position' => 'right',
    ];

    // Load configuration using CUE paths module
    $configPath = cue_autoload('paths')->getDataPath() . '/global-ui/hamburger/hamburger-config.json';
    if (file_exists($configPath)) {
        $jsonContent = file_get_contents($configPath);
        $configData = json_decode($jsonContent, true);
        if ($configData && isset($configData['K::HamburgerUI::Configuration'])) {
            $keys = array_keys($configData['K::HamburgerUI::Configuration']);
            if (!empty($keys)) {
                $savedConfig = $configData['K::HamburgerUI::Configuration'][$keys[0]];
                $hamburgerConfig = array_merge($hamburgerConfig, $savedConfig);
            }
        }
    }

    // Merge with passed config
    $hamburgerConfig = array_merge($hamburgerConfig, $config);

    // Early return if disabled
    if (!$hamburgerConfig['hbg_enabled']) {
        return;
    }

    // Get ALL realms, menus and submenus from navigator database (caching removed for performance)
    $menuItems = [];
    try {
            // Load the NavigationDatabaseManager from navigator system
            $navigatorManagerPath = dirname(__DIR__) . '/menus/navigation-database-manager.php';
            if (file_exists($navigatorManagerPath)) {
                require_once $navigatorManagerPath;
                
                // Use the navigator system to get all realms and their menus
                $navigator = new NavigationDatabaseManager();
                
                // Get all realms
                $realms = $navigator->getRealms();
            
            if ($realms && is_object($realms)) {
                // Process each realm and its menus
                foreach ($realms as $realmId => $realm) {
                    // Add realm as top-level menu item if it has a name
                    if (isset($realm->name) && !empty($realm->name)) {
                    }
                    
                    // Get menus for this realm
                    try {
                        $menus = $navigator->getMenus($realmId);
                        
                        if ($menus && is_array($menus)) {
                            foreach ($menus as $menu) {
                                // Add main menu item
                                if (isset($menu->title) && isset($menu->url)) {
                                    $menuItems[] = [
                                        'title' => $menu->title,
                                        'url' => $menu->url,
                                        'icon' => $menu->icon ?? null
                                    ];
                                }
                                
                                // Add submenus if they exist
                                if (isset($menu->submenu) && is_array($menu->submenu)) {
                                    foreach ($menu->submenu as $submenu) {
                                        if (isset($submenu->title) && isset($submenu->url)) {
                                            $menuItems[] = [
                                                'title' => '└ ' . $submenu->title,
                                                'url' => $submenu->url,
                                                'icon' => $submenu->icon ?? null
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Exception $menuException) {
                        // Log individual menu loading errors but continue
                        cue_autoload('error')->logError('Hamburger menu error for realm ' . $realmId . ': ' . $menuException->getMessage());
                    }
                }
            }
            
            // Caching removed for improved performance
        }
    } catch (Exception $e) {
        cue_autoload('error')->logError('Hamburger navigator integration error: ' . $e->getMessage());
    }

    // Fallback removed - show empty menu when no database connection
    if (empty($menuItems)) {
        // No fallback - hamburger menu will be empty if no database data
    }

    // Generate CSS using CUE theme module
    echo '<style>';
    echo '.cue-hamburger-menu { position: fixed; z-index: 9999; }';
    $triggerSize = (int)($hamburgerConfig['hbg_trigger_size'] ?? 50);
    $triggerOffset = (int)($hamburgerConfig['hbg_trigger_offset'] ?? 20);
    $triggerAlign = strtolower(trim((string)($hamburgerConfig['hbg_trigger_vertical_align'] ?? 'top')));
    if (!in_array($triggerAlign, ['top', 'center', 'bottom'], true)) {
        $triggerAlign = 'top';
    }
    $panelYOffset = (int)($hamburgerConfig['hbg_panel_y_offset'] ?? 0);
    $panelBottomPadding = (int)($hamburgerConfig['hbg_panel_bottom_padding'] ?? 0);
    $barWidth = (int)($hamburgerConfig['hbg_bar_width'] ?? 25);
    $barHeight = (int)($hamburgerConfig['hbg_bar_height'] ?? 3);
    $barGap = (int)($hamburgerConfig['hbg_bar_gap'] ?? 4);
    $panelOffset = (int)($hamburgerConfig['hbg_panel_offset'] ?? 10);
    $menuBottomPadding = (int)($hamburgerConfig['hbg_menu_bottom_padding'] ?? 20);
    $socialLinkPaddingY = (int)($hamburgerConfig['hbg_social_link_padding_y'] ?? 6);
    $socialLinkPaddingX = (int)($hamburgerConfig['hbg_social_link_padding_x'] ?? 10);
    $socialLinkFontSize = (int)($hamburgerConfig['hbg_social_link_font_size'] ?? 12);
    $socialIconsSize = (int)($hamburgerConfig['hbg_social_icons_size'] ?? 16);

    echo '.cue-hamburger-menu .hamburger-trigger { ';
    echo 'position: fixed; ';
    if ($triggerAlign === 'bottom') {
        echo 'bottom: ' . $triggerOffset . 'px; top: auto; transform: none; ';
    } elseif ($triggerAlign === 'center') {
        echo 'top: 50%; bottom: auto; transform: translateY(-50%); ';
    } else {
        echo 'top: ' . $triggerOffset . 'px; bottom: auto; transform: none; ';
    }
    echo ($hamburgerConfig['hbg_position'] === 'left') ? 'left: 20px; ' : 'right: 20px; ';
    echo 'width: ' . $triggerSize . 'px; height: ' . $triggerSize . 'px; ';
    echo 'background: ' . $hamburgerConfig['hbg_background_color'] . '; ';
    echo 'border: 2px solid ' . $hamburgerConfig['hbg_icon_color'] . '; ';
    echo 'border-radius: 10px; cursor: pointer; ';
    echo 'display: flex; flex-direction: column; justify-content: center; align-items: center; ';
    echo 'gap: ' . $barGap . 'px; ';
    echo 'transition: all 0.3s ease; }';
    
    echo '.cue-hamburger-menu .hamburger-line { ';
    echo 'width: ' . $barWidth . 'px; height: ' . $barHeight . 'px; margin: 0; ';
    echo 'background: ' . $hamburgerConfig['hbg_icon_color'] . '; ';
    echo 'transition: 0.3s; }';
    
    echo '.cue-hamburger-menu .hamburger-backdrop { ';
    echo 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; ';
    echo 'background: rgba(0, 0, 0, 0.5); opacity: 0; visibility: hidden; ';
    echo 'transition: all 0.3s ease; z-index: 9998; pointer-events: none; }';
    echo '.cue-hamburger-menu .hamburger-backdrop.active { opacity: 1; visibility: visible; pointer-events: auto; }';
    
    echo '.cue-hamburger-menu .hamburger-panel { ';
    echo 'position: fixed; ';
    if ($triggerAlign === 'bottom') {
        echo 'bottom: ' . ($triggerOffset + $triggerSize + $panelOffset + $panelYOffset) . 'px; top: auto; ';
    } elseif ($triggerAlign === 'center') {
        echo 'top: calc(50% - ' . ((int)$hamburgerConfig['hbg_panel_height'] / 2) . 'px + ' . $panelYOffset . 'px); bottom: auto; ';
    } else {
        echo 'top: ' . ($triggerOffset + $triggerSize + $panelOffset + $panelYOffset) . 'px; bottom: auto; ';
    }
    echo 'width: ' . $hamburgerConfig['hbg_panel_width'] . 'px; ';
    echo 'height: ' . $hamburgerConfig['hbg_panel_height'] . 'px; ';
    echo 'max-height: ' . $hamburgerConfig['hbg_panel_height'] . 'px; ';
    echo 'background: ' . $hamburgerConfig['hbg_background_color'] . '; ';
    echo 'border: 2px solid ' . $hamburgerConfig['hbg_icon_color'] . '; ';
    echo 'border-radius: 12px; padding: 0; ';
    echo 'opacity: 0; visibility: hidden; ';
    echo 'transition: all 0.3s ease; z-index: 9999; pointer-events: none; ';
    echo 'display: flex; flex-direction: column; overflow: hidden; ';
    
    if ($hamburgerConfig['hbg_position'] === 'left') {
        echo 'left: -' . ($hamburgerConfig['hbg_panel_width'] + 50) . 'px; ';
    } else {
        echo 'right: -' . ($hamburgerConfig['hbg_panel_width'] + 50) . 'px; ';
    }
    echo '}';
    
    echo '.cue-hamburger-menu .hamburger-panel.active { ';
    echo 'opacity: 1; visibility: visible; ';
    echo ($hamburgerConfig['hbg_position'] === 'left') ? 'left: 20px; ' : 'right: 20px; ';
    echo 'pointer-events: auto; ';
    echo '}';
    
    echo '.cue-hamburger-menu .hamburger-content-wrapper { flex: 1; overflow-y: auto; padding: 20px; padding-bottom: ' . $menuBottomPadding . 'px; }';
    echo '.cue-hamburger-menu .hamburger-content-wrapper { scrollbar-width: thin; scrollbar-color: ' . $hamburgerConfig['hbg_icon_color'] . ' ' . hexToRgba($hamburgerConfig['hbg_background_color'], 0.3) . '; }';
    echo '.cue-hamburger-menu .hamburger-content-wrapper::-webkit-scrollbar { width: 10px; }';
    echo '.cue-hamburger-menu .hamburger-content-wrapper::-webkit-scrollbar-track { background: ' . hexToRgba($hamburgerConfig['hbg_background_color'], 0.3) . '; border-radius: 12px; }';
    echo '.cue-hamburger-menu .hamburger-content-wrapper::-webkit-scrollbar-thumb { background: linear-gradient(180deg, ' . $hamburgerConfig['hbg_icon_color'] . ', ' . hexToRgba($hamburgerConfig['hbg_hover_color'], 0.8) . '); border-radius: 12px; border: 2px solid ' . $hamburgerConfig['hbg_background_color'] . '; }';
    echo '.cue-hamburger-menu .hamburger-content-wrapper::-webkit-scrollbar-thumb:hover { background: ' . $hamburgerConfig['hbg_hover_color'] . '; }';
    echo '.cue-hamburger-menu .hamburger-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }';
    echo '.cue-hamburger-menu .hamburger-header-content { display: flex; align-items: center; gap: 10px; }';
    echo '.cue-hamburger-menu .hamburger-text { flex: 1; }';
    
    // Add realm header styling
    echo '.cue-hamburger-menu .hamburger-realm-header { margin: 15px 0 5px 0; }';
    echo '.cue-hamburger-menu .hamburger-realm { ';
    echo 'background: ' . $hamburgerConfig['hbg_hover_color'] . '15 !important; ';
    echo 'border-left: 4px solid ' . $hamburgerConfig['hbg_hover_color'] . ' !important; ';
    echo 'font-weight: bold; }';
    echo '.cue-hamburger-menu .hamburger-realm:hover { ';
    echo 'background: ' . $hamburgerConfig['hbg_hover_color'] . '25 !important; }';
    
    if ($hamburgerConfig['hbg_heading_enabled']) {
        echo '.cue-hamburger-menu .hamburger-title { ';
        echo 'font-size: ' . $hamburgerConfig['hbg_heading_size'] . 'px; ';
        echo 'color: ' . $hamburgerConfig['hbg_heading_color'] . '; ';
        echo 'margin: 0 0 8px 0; font-weight: bold; }';
    }
    
    if ($hamburgerConfig['hbg_subheading_enabled']) {
        echo '.cue-hamburger-menu .hamburger-subtitle { ';
        echo 'font-size: ' . $hamburgerConfig['hbg_subheading_size'] . 'px; ';
        echo 'color: ' . $hamburgerConfig['hbg_subheading_color'] . '; ';
        echo 'margin: 0; opacity: 0.9; }';
    }
    
    if ($hamburgerConfig['hbg_logo_enabled']) {
        // Position logo based on configuration
        if (strpos($hamburgerConfig['hbg_logo_position'], 'left') !== false) {
            echo '.cue-hamburger-menu .hamburger-logo { display: inline-block; margin-right: 15px; vertical-align: top; }';
        } elseif (strpos($hamburgerConfig['hbg_logo_position'], 'right') !== false) {
            echo '.cue-hamburger-menu .hamburger-logo { display: inline-block; margin-left: 15px; vertical-align: top; }';
        } else {
            echo '.cue-hamburger-menu .hamburger-logo { text-align: center; margin: 15px 0; }';
        }
        
        echo '.cue-hamburger-menu .hamburger-logo img { ';
        echo 'width: ' . $hamburgerConfig['hbg_logo_width'] . 'px; ';
        echo 'height: ' . $hamburgerConfig['hbg_logo_height'] . 'px; ';
        echo 'display: block; ';
        
        // Apply glow effect
        if ($hamburgerConfig['hbg_logo_glow_enabled']) {
            echo 'filter: drop-shadow(0 0 ' . $hamburgerConfig['hbg_logo_glow_size'] . 'px ' . $hamburgerConfig['hbg_logo_glow_color'] . '); ';
        }
        
        // Apply animation
        if ($hamburgerConfig['hbg_logo_animation_enabled']) {
            if ($hamburgerConfig['hbg_logo_animation_type'] === 'glow') {
                echo 'animation: logoGlow 2s ease-in-out infinite alternate; ';
            } elseif ($hamburgerConfig['hbg_logo_animation_type'] === 'pulse') {
                echo 'animation: logoPulse 1.5s ease-in-out infinite; ';
            } elseif ($hamburgerConfig['hbg_logo_animation_type'] === 'spin') {
                echo 'animation: logoSpin 3s linear infinite; ';
            }
        }
        
        echo 'transition: all 0.3s ease; }';
        
        // Add CSS animations
        echo '@keyframes logoGlow { 0% { filter: drop-shadow(0 0 ' . $hamburgerConfig['hbg_logo_glow_size'] . 'px ' . $hamburgerConfig['hbg_logo_glow_color'] . '); } 100% { filter: drop-shadow(0 0 ' . ($hamburgerConfig['hbg_logo_glow_size'] * 2) . 'px ' . $hamburgerConfig['hbg_logo_glow_color'] . '); } }';
        echo '@keyframes logoPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }';
        echo '@keyframes logoSpin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
    }
    
    if ($hamburgerConfig['hbg_divider_enabled']) {
        echo '.cue-hamburger-menu .hamburger-divider { ';
        echo 'border: 0; margin: 15px 0; ';
        
        // Apply divider style
        if ($hamburgerConfig['hbg_divider_style'] === 'dashed') {
            echo 'border-top: ' . $hamburgerConfig['hbg_divider_thickness'] . 'px dashed ' . $hamburgerConfig['hbg_divider_color'] . '; ';
        } elseif ($hamburgerConfig['hbg_divider_style'] === 'dotted') {
            echo 'border-top: ' . $hamburgerConfig['hbg_divider_thickness'] . 'px dotted ' . $hamburgerConfig['hbg_divider_color'] . '; ';
        } elseif ($hamburgerConfig['hbg_divider_style'] === 'double') {
            echo 'border-top: ' . ($hamburgerConfig['hbg_divider_thickness'] * 3) . 'px double ' . $hamburgerConfig['hbg_divider_color'] . '; ';
        } else {
            echo 'border-top: ' . $hamburgerConfig['hbg_divider_thickness'] . 'px solid ' . $hamburgerConfig['hbg_divider_color'] . '; ';
        }
        
        // Apply curvature if specified
        if (isset($hamburgerConfig['hbg_divider_curvature']) && $hamburgerConfig['hbg_divider_curvature'] > 0) {
            echo 'border-radius: ' . $hamburgerConfig['hbg_divider_curvature'] . 'px; ';
        }
        
        echo '}';
    }
    if (!empty($hamburgerConfig['hbg_realm_dividers_enabled'])) {
        echo '.cue-hamburger-menu .hamburger-realm-divider { ';
        echo 'border: 0; margin: ' . ($hamburgerConfig['hbg_realm_divider_spacing'] ?? 8) . 'px 0; ';
        echo 'border-top: ' . ($hamburgerConfig['hbg_realm_divider_thickness'] ?? 1) . 'px ' . ($hamburgerConfig['hbg_realm_divider_style'] ?? 'solid') . ' ' . ($hamburgerConfig['hbg_realm_divider_color'] ?? '#333333') . '; ';
        if (!empty($hamburgerConfig['hbg_realm_divider_curvature'])) {
            echo 'border-radius: ' . $hamburgerConfig['hbg_realm_divider_curvature'] . 'px; ';
        }
        echo 'width: 100%; display: block; ';
        echo '}';
    }
    
    echo '.cue-hamburger-menu .hamburger-close { background: none; border: none; color: ' . $hamburgerConfig['hbg_icon_color'] . '; font-size: 24px; cursor: pointer; width: 30px; height: 30px; }';
    
    echo '.cue-hamburger-menu .hamburger-item { ';
    echo 'display: block; padding: 12px; ';
    echo 'color: ' . $hamburgerConfig['hbg_text_color'] . '; ';
    echo 'text-decoration: none; border-radius: 8px; margin: 5px 0; ';
    echo 'transition: all 0.3s ease; border-left: 3px solid transparent; }';
    echo '.cue-hamburger-menu .hamburger-item-label { ';
    echo 'display: block; padding: 12px; ';
    echo 'color: ' . $hamburgerConfig['hbg_text_color'] . '; ';
    echo 'text-decoration: none; border-radius: 8px; margin: 5px 0; ';
    echo 'transition: all 0.3s ease; border-left: 3px solid transparent; }';
    echo '.cue-hamburger-menu .hamburger-item:hover, .cue-hamburger-menu .hamburger-item-label:hover { ';
    echo 'background: ' . $hamburgerConfig['hbg_hover_color'] . '20; ';
    echo 'color: ' . $hamburgerConfig['hbg_hover_color'] . '; ';
    echo 'border-left-color: ' . $hamburgerConfig['hbg_hover_color'] . '; }';
    
    // Add realm section styling
    echo '.cue-hamburger-menu .hamburger-realm-section { margin-bottom: 15px; }';
    echo '.cue-hamburger-menu .hamburger-realm-toggle { display: flex; align-items: center; gap: 10px; padding: 6px 0; transition: all 0.3s ease; }';
    echo '.cue-hamburger-menu .hamburger-menu-header { display: flex; align-items: center; gap: 10px; }';
    echo '.cue-hamburger-menu .hamburger-realm-toggle-btn, .cue-hamburger-menu .hamburger-submenu-toggle-btn { display: inline-flex; align-items: center; justify-content: center; border: 0; background: transparent; border-radius: 10px; padding: 10px; cursor: pointer; }';
    echo '.cue-hamburger-menu .hamburger-realm-toggle-btn:hover, .cue-hamburger-menu .hamburger-submenu-toggle-btn:hover { background: ' . $hamburgerConfig['hbg_hover_color'] . '15; }';
    
    echo '.cue-hamburger-menu .hamburger-realm-icon, .cue-hamburger-menu .hamburger-submenu-icon { ';
    echo 'width: 20px; font-size: 14px; ';
    echo 'color: ' . $hamburgerConfig['hbg_icon_color'] . '; ';
    echo 'transition: transform 0.3s ease; }';
    echo '.cue-hamburger-menu .hamburger-realm-icon.expanded, .cue-hamburger-menu .hamburger-submenu-icon.expanded { ';
    echo 'transform: rotate(90deg); }';
    
    echo '.cue-hamburger-menu .hamburger-realm-content { ';
    echo 'overflow: visible; transition: max-height 0.3s ease, opacity 0.3s ease; ';
    echo 'padding-left: 20px; }';
    echo '.cue-hamburger-menu .hamburger-realm-content.collapsed { ';
    echo 'max-height: 0; opacity: 0; display: none; }';
    echo '.cue-hamburger-menu .hamburger-realm-content.expanded { ';
    echo 'max-height: none; opacity: 1; display: block; }';
    
    echo '.cue-hamburger-menu .hamburger-menu-section { margin-bottom: 5px; }';
    
    // Add submenu styling with expand/collapse
    echo '.cue-hamburger-menu .hamburger-submenu { ';
    echo 'overflow: visible; transition: max-height 0.3s ease, opacity 0.3s ease; ';
    echo 'padding-left: 20px; margin-left: 15px; ';
    echo 'border-left: 2px solid ' . $hamburgerConfig['hbg_divider_color'] . '; }';
    echo '.cue-hamburger-menu .hamburger-submenu.collapsed { ';
    echo 'max-height: 0; opacity: 0; display: none; }';
    echo '.cue-hamburger-menu .hamburger-submenu.expanded { ';
    echo 'max-height: none; opacity: 1; display: block; }';
    
    echo '.cue-hamburger-menu .hamburger-submenu-item { ';
    echo 'font-size: 14px; padding: 8px 12px; ';
    echo 'opacity: 0.9; border-left: 2px solid transparent; }';
    echo '.cue-hamburger-menu .hamburger-submenu-item:hover { ';
    echo 'opacity: 1; transform: translateX(5px); }';
    
    // Add social footer styling - positioned at bottom
    echo '.cue-hamburger-menu .hamburger-social-footer { ';
    echo 'flex-shrink: 0; border-top: 1px solid ' . $hamburgerConfig['hbg_divider_color'] . '; ';
    echo 'background: ' . $hamburgerConfig['hbg_background_color'] . '; ';
    echo 'padding: 15px 20px; }';
    
    echo '.cue-hamburger-menu .hamburger-social-divider { ';
    echo 'border: 0; margin: 0 0 15px 0; ';
    echo 'border-top: 1px solid ' . $hamburgerConfig['hbg_divider_color'] . '; }';
    
    echo '.cue-hamburger-menu .hamburger-social-title { ';
    echo 'font-size: 14px; font-weight: bold; margin-bottom: 10px; ';
    echo 'color: ' . $hamburgerConfig['hbg_heading_color'] . '; ';
    echo 'text-align: center; }';
    
    echo '.cue-hamburger-menu .hamburger-social-links { ';
    echo 'display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }';
    
    echo '.cue-hamburger-menu .hamburger-social-link { ';
    echo 'display: inline-flex; align-items: center; gap: 5px; ';
    echo 'padding: ' . $socialLinkPaddingY . 'px ' . $socialLinkPaddingX . 'px; border-radius: 6px; ';
    echo 'background: ' . $hamburgerConfig['hbg_background_color'] . '; ';
    echo 'color: ' . $hamburgerConfig['hbg_text_color'] . '; ';
    echo 'text-decoration: none; font-size: ' . $socialLinkFontSize . 'px; ';
    echo 'border: 1px solid ' . $hamburgerConfig['hbg_divider_color'] . '; ';
    echo 'transition: all 0.3s ease; }';
    echo '.cue-hamburger-menu .hamburger-social-link i { font-size: ' . $socialIconsSize . 'px; }';
    
    echo '.cue-hamburger-menu .hamburger-social-link:hover { ';
    echo 'background: ' . $hamburgerConfig['hbg_hover_color'] . '20; ';
    echo 'color: ' . $hamburgerConfig['hbg_hover_color'] . '; ';
    echo 'border-color: ' . $hamburgerConfig['hbg_hover_color'] . '; ';
    echo 'transform: translateY(-2px); }';
    
    echo '.cue-hamburger-menu .hamburger-social-empty { ';
    echo 'text-align: center; font-size: 12px; ';
    echo 'color: ' . $hamburgerConfig['hbg_text_color'] . '; opacity: 0.7; }';
    
    // Update panel styling to accommodate footer
    echo '.cue-hamburger-menu .hamburger-panel { padding-bottom: ' . $panelBottomPadding . 'px; }';

    echo '@media (max-width: 768px) {';
    echo '.cue-global-header .header-logo { display: none !important; }';
    echo '.cue-global-header .header-title { display: none !important; }';
    echo '.cue-global-header .header-slogan { display: none !important; }';
    echo '.cue-global-header .header-navigation { display: none !important; }';
    if (($hamburgerConfig['hbg_position'] ?? 'right') === 'left') {
        echo '.cue-global-header .header-container { padding-left: 90px !important; padding-right: 10px !important; }';
    } else {
        echo '.cue-global-header .header-container { padding-right: 90px !important; padding-left: 10px !important; }';
    }
    echo '}';
    
    echo '</style>';

    // Generate HTML structure
    echo '<div class="cue-hamburger-menu" id="globalHamburgerMenu">';
    
    // Trigger button
    echo '<div class="hamburger-trigger" onclick="toggleHamburgerMenu()">';
    echo '<div class="hamburger-line"></div>';
    echo '<div class="hamburger-line"></div>';
    echo '<div class="hamburger-line"></div>';
    echo '</div>';
    
    // Backdrop
    echo '<div class="hamburger-backdrop" onclick="closeHamburgerMenu()"></div>';
    
    // Panel
    echo '<div class="hamburger-panel">';
    
    // Content wrapper for scrollable content
    echo '<div class="hamburger-content-wrapper">';
    
    // Header with logo, heading and subheading inline
    echo '<div class="hamburger-header">';
    echo '<div class="hamburger-header-content">';
    
    // Logo inline with text (if configured for left/right position)
    if ($hamburgerConfig['hbg_logo_enabled'] && !empty($hamburgerConfig['hbg_logo_image_path']) && strpos($hamburgerConfig['hbg_logo_position'], 'left') !== false) {
        echo '<div class="hamburger-logo">';
        echo '<img src="' . htmlspecialchars($hamburgerConfig['hbg_logo_image_path']) . '" alt="Logo">';
        echo '</div>';
    }
    
    echo '<div class="hamburger-text">';
    if ($hamburgerConfig['hbg_heading_enabled'] && !empty($hamburgerConfig['hbg_heading_text'])) {
        echo '<h3 class="hamburger-title">' . htmlspecialchars($hamburgerConfig['hbg_heading_text']) . '</h3>';
    }
    
    if ($hamburgerConfig['hbg_subheading_enabled'] && !empty($hamburgerConfig['hbg_subheading_text'])) {
        echo '<p class="hamburger-subtitle">' . htmlspecialchars($hamburgerConfig['hbg_subheading_text']) . '</p>';
    }
    echo '</div>';
    
    // Logo inline with text (if configured for right position)
    if ($hamburgerConfig['hbg_logo_enabled'] && !empty($hamburgerConfig['hbg_logo_image_path']) && strpos($hamburgerConfig['hbg_logo_position'], 'right') !== false) {
        echo '<div class="hamburger-logo">';
        echo '<img src="' . htmlspecialchars($hamburgerConfig['hbg_logo_image_path']) . '" alt="Logo">';
        echo '</div>';
    }
    
    echo '</div>';
    echo '<button type="button" class="hamburger-close" onclick="closeHamburgerMenu(this)">✕</button>';
    echo '</div>';
    
    // Login/Logout Status
    $currentUser = isset($_SESSION['mh_auth_user']) ? $_SESSION['mh_auth_user'] : null;
    echo '<div class="hamburger-auth-status" style="text-align: center; margin-bottom: 15px; padding: 0 15px;">';
    if ($currentUser) {
        // Logged in
        echo '<div style="font-size: 13px; margin-bottom: 8px; color: ' . $hamburgerConfig['hbg_text_color'] . '; opacity: 0.8;">';
        echo 'Logged in as <strong>' . htmlspecialchars($currentUser) . '</strong>';
        echo '</div>';
        echo '<a href="/auth/logout.php" class="hamburger-item" style="text-align: center; background: rgba(255, 50, 50, 0.1); color: #ff5555; border: 1px solid rgba(255, 50, 50, 0.3); padding: 8px;">';
        echo '<i class="fa fa-sign-out-alt" style="margin-right: 8px;"></i>Logout';
        echo '</a>';
    } else {
        // Logged out
        echo '<a href="/auth/login.php" class="hamburger-item" style="text-align: center; background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.1); color: var(--theme-primary, #00ffff); border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.3); padding: 8px;">';
        echo '<i class="fa fa-sign-in-alt" style="margin-right: 8px;"></i>Login';
        echo '</a>';
    }
    echo '</div>';
    
    // Note: Center logo removed to avoid duplicate logo display
    
    // Menu items with proper hierarchy and expand/collapse functionality
    echo '<div class="hamburger-menu-items">';
    
    $structuredMenu = getHamburgerStructuredMenu();
    
    // Render structured menu with expand/collapse
    if (!empty($structuredMenu)) {
        foreach ($structuredMenu as $realmIndex => $realm) {
            $realmId = 'realm-' . $realm['id'];
            $isExpanded = ($realmIndex === 0) ? 'expanded' : 'collapsed';
            
            echo '<div class="hamburger-realm-section" data-realm-id="' . htmlspecialchars($realm['id']) . '">';
            
            // Realm header with expand/collapse
            echo '<div class="hamburger-realm-header" style="position:relative;z-index:10">';
            if (!empty($hamburgerConfig['hbg_realm_dividers_enabled']) && !empty($hamburgerConfig['hbg_realm_divider_show_top'])) { echo '<hr class="hamburger-realm-divider">'; }
            echo '<div class="hamburger-realm-toggle">'; 
            echo '<button type="button" class="hamburger-realm-toggle-btn" onclick="toggleRealmMenu(\'' . $realmId . '\', event)" aria-label="Toggle realm">';
            echo '<span class="hamburger-realm-icon">' . ($isExpanded === 'expanded' ? '▼' : '▶') . '</span>';
            echo '</button>';
            echo '<span class="hamburger-item-label hamburger-realm">';
            $realmIconVal = isset($realm['icon']) ? trim((string)$realm['icon']) : '';
            if ($realmIconVal !== '') {
                if (stripos($realmIconVal, 'thesvg:') === 0) {
                    $rest = trim(substr($realmIconVal, 7));
                    $slug = $rest;
                    $variant = 'default';
                    if (strpos($rest, ':') !== false) { [$slug, $variant] = explode(':', $rest, 2); }
                    elseif (strpos($rest, '/') !== false) { [$slug, $variant] = explode('/', $rest, 2); }
                    $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)$slug) ?: '';
                    $variant = preg_replace('/[^a-z0-9]/i', '', (string)$variant) ?: 'default';
                    $inv = ($variant === 'mono' || $variant === 'dark') ? 'filter:invert(1);' : '';
                    $src = '/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=' . rawurlencode($slug) . '&variant=' . rawurlencode($variant);
                    echo '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle;' . $inv . '">';
                } else {
                    $rcls = globalUi_normalizeIconClass($realmIconVal);
                    if (stripos($rcls, 'thesvg:') === 0) {
                        echo '<img src="/templates/assets/images/branding/triangle/logo-triangle-1000.png" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle">';
                    } else {
                        echo '<i class="' . htmlspecialchars($rcls) . '" style="margin-right:8px;color:' . htmlspecialchars($hamburgerConfig['hbg_icon_color']) . ';"></i>';
                    }
                }
            } else {
                echo '<img src="/templates/assets/images/branding/triangle/logo-triangle-1000.png" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle">';
            }
            echo '<strong>' . htmlspecialchars($realm['title']) . '</strong>';
            echo '</span>';
            echo '</div>';
            if (!empty($hamburgerConfig['hbg_realm_dividers_enabled']) && !empty($hamburgerConfig['hbg_realm_divider_show_bottom'])) { echo '<hr class="hamburger-realm-divider">'; }
            echo '</div>';
            
            // Realm menus (initially expanded for first realm, collapsed for others)
            echo '<div class="hamburger-realm-content ' . $isExpanded . '" id="' . $realmId . '"' . ($isExpanded === 'collapsed' ? ' style="display:none"' : '') . '>'; 
            
            if (!empty($realm['menus'])) {
                foreach ($realm['menus'] as $menuIndex => $menu) {
                    $menuId = $realmId . '-menu-' . $menuIndex;
                    
                    // Main menu item
                    if (!empty($menu['submenus'])) {
                        // Menu with submenus - expandable
                        echo '<div class="hamburger-menu-section" data-menu-id="' . htmlspecialchars($menu['id'] ?? '') . '">';
                        echo '<div class="hamburger-menu-header">';
                        echo '<button type="button" class="hamburger-submenu-toggle-btn" onclick="toggleSubmenu(\'' . $menuId . '\', event)" aria-expanded="false" aria-controls="' . htmlspecialchars($menuId) . '" aria-label="Toggle submenu">';
                        echo '<span class="hamburger-submenu-icon">▶</span>';
                        echo '</button>';
                        $iconVal = isset($menu['icon']) ? trim((string)$menu['icon']) : '';
                        $menuIconHtml = '';
                        if ($iconVal !== '') {
                            if (stripos($iconVal, 'thesvg:') === 0) {
                                $rest = trim(substr($iconVal, 7));
                                $slug = $rest;
                                $variant = 'default';
                                if (strpos($rest, ':') !== false) { [$slug, $variant] = explode(':', $rest, 2); }
                                elseif (strpos($rest, '/') !== false) { [$slug, $variant] = explode('/', $rest, 2); }
                                $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)$slug) ?: '';
                                $variant = preg_replace('/[^a-z0-9]/i', '', (string)$variant) ?: 'default';
                                $inv = ($variant === 'mono' || $variant === 'dark') ? 'filter:invert(1);' : '';
                                $src = '/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=' . rawurlencode($slug) . '&variant=' . rawurlencode($variant);
                                $menuIconHtml = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle;' . $inv . '">';
                            } else {
                                $cls = globalUi_normalizeIconClass($iconVal);
                                $menuIconHtml = '<i class="' . htmlspecialchars($cls) . '" style="margin-right:8px;color:' . htmlspecialchars($hamburgerConfig['hbg_icon_color']) . ';"></i>';
                            }
                        } else {
                            $menuIconHtml = '<img src="/templates/assets/images/branding/triangle/logo-triangle-1000.png" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle">';
                        }
                        echo '<span class="hamburger-item-label hamburger-menu-link">' . $menuIconHtml . htmlspecialchars($menu['title']) . '</span>';
                        echo '</div>';
                        
                        // Submenus (initially collapsed)
                        echo '<div class="hamburger-submenu collapsed" id="' . $menuId . '" style="display:none">';
                        foreach ($menu['submenus'] as $submenu) {
                            $su = globalUi_normalizeUrl($submenu['url'] ?? '');
                            $subIconVal = isset($submenu['icon']) ? trim((string)$submenu['icon']) : '';
                            $subIconHtml = '';
                            if ($subIconVal !== '') {
                                if (stripos($subIconVal, 'thesvg:') === 0) {
                                    $rest = trim(substr($subIconVal, 7));
                                    $slug = $rest;
                                    $variant = 'default';
                                    if (strpos($rest, ':') !== false) { [$slug, $variant] = explode(':', $rest, 2); }
                                    elseif (strpos($rest, '/') !== false) { [$slug, $variant] = explode('/', $rest, 2); }
                                    $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)$slug) ?: '';
                                    $variant = preg_replace('/[^a-z0-9]/i', '', (string)$variant) ?: 'default';
                                    $inv = ($variant === 'mono' || $variant === 'dark') ? 'filter:invert(1);' : '';
                                    $src = '/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=' . rawurlencode($slug) . '&variant=' . rawurlencode($variant);
                                    $subIconHtml = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle;' . $inv . '">';
                                } else {
                                    $scls = globalUi_normalizeIconClass($subIconVal);
                                    $subIconHtml = '<i class="' . htmlspecialchars($scls) . '" style="margin-right:8px;color:' . htmlspecialchars($hamburgerConfig['hbg_icon_color']) . ';"></i>';
                                }
                            } else {
                                $subIconHtml = '<img src="/templates/assets/images/branding/triangle/logo-triangle-1000.png" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle">';
                            }
                            if ($su !== '') {
                                echo '<a href="' . htmlspecialchars($su) . '" class="hamburger-item hamburger-submenu-item" data-submenu-id="' . htmlspecialchars($submenu['id'] ?? '') . '">';
                                echo $subIconHtml . htmlspecialchars($submenu['title']);
                                echo '</a>';
                            } else {
                                echo '<span class="hamburger-item-label hamburger-submenu-item" data-submenu-id="' . htmlspecialchars($submenu['id'] ?? '') . '">';
                                echo $subIconHtml . htmlspecialchars($submenu['title']);
                                echo '</span>';
                            }
                        }
                        echo '</div>';
                        echo '</div>';
                    } else {
                        // Simple menu item without submenus (wrap to enable reordering by ID)
                        echo '<div class="hamburger-menu-section" data-menu-id="' . htmlspecialchars($menu['id'] ?? '') . '">';
                        $mu = globalUi_normalizeUrl($menu['url'] ?? '');
                        $mt = isset($menu['title']) ? (string)$menu['title'] : '';
                        if (strcasecmp($mt, 'Global UI') === 0) { $mu = ''; }
                        $menuIconVal = isset($menu['icon']) ? trim((string)$menu['icon']) : '';
                        $menuIconHtml = '';
                        if ($menuIconVal !== '') {
                            if (stripos($menuIconVal, 'thesvg:') === 0) {
                                $rest = trim(substr($menuIconVal, 7));
                                $slug = $rest;
                                $variant = 'default';
                                if (strpos($rest, ':') !== false) { [$slug, $variant] = explode(':', $rest, 2); }
                                elseif (strpos($rest, '/') !== false) { [$slug, $variant] = explode('/', $rest, 2); }
                                $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)$slug) ?: '';
                                $variant = preg_replace('/[^a-z0-9]/i', '', (string)$variant) ?: 'default';
                                $inv = ($variant === 'mono' || $variant === 'dark') ? 'filter:invert(1);' : '';
                                $src = '/templates/widgets/icons/icon-widget.php?thesvg_svg=1&slug=' . rawurlencode($slug) . '&variant=' . rawurlencode($variant);
                                $menuIconHtml = '<img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle;' . $inv . '">';
                            } else {
                                $mcls = globalUi_normalizeIconClass($menuIconVal);
                                $menuIconHtml = '<i class="' . htmlspecialchars($mcls) . '" style="margin-right:8px;color:' . htmlspecialchars($hamburgerConfig['hbg_icon_color']) . ';"></i>';
                            }
                        } else {
                            $menuIconHtml = '<img src="/templates/assets/images/branding/triangle/logo-triangle-1000.png" alt="" style="width:1em;height:1em;display:inline-block;margin-right:8px;vertical-align:middle">';
                        }
                        if ($mu !== '') {
                            echo '<a href="' . htmlspecialchars($mu) . '" class="hamburger-item">' . $menuIconHtml . htmlspecialchars($menu['title']) . '</a>';
                        } else {
                            echo '<span class="hamburger-item-label">' . $menuIconHtml . htmlspecialchars($menu['title']) . '</span>';
                        }
                        echo '</div>';
                    }
                }
            }
            
            echo '</div>'; // Close realm content
            echo '</div>'; // Close realm section
        }
    } else {
        // No fallback menu - show empty state when no navigator data
        echo '<div class="hamburger-empty-state">';
        echo '<p style="text-align: center; color: #888; padding: 20px; font-style: italic;">No navigation data available</p>';
        echo '</div>';
    }
    
    echo '</div>'; // Close menu items
    
    echo '</div>'; // Close content wrapper
    
    // Divider between menu items and social footer
    if ($hamburgerConfig['hbg_divider_enabled']) {
        echo '<hr class="hamburger-divider">';
    }
    
    // Add social media footer at bottom of menu
    if ($hamburgerConfig['hbg_social_enabled'] && $hamburgerConfig['hbg_social_from_navigator']) {
        try {
            $allSocialLinks = [];
            static $staticSocialNavigator = null;
            if ($staticSocialNavigator === null) {
                $navigatorManagerPath = dirname(__DIR__) . '/menus/navigation-database-manager.php';
                if (file_exists($navigatorManagerPath)) {
                    require_once $navigatorManagerPath;
                    $staticSocialNavigator = new NavigationDatabaseManager();
                }
            }
            $navigator = $staticSocialNavigator;
            if ($navigator) {
                $currentRealm = getCurrentRealm();
                if ($currentRealm) {
                    $realmId = $currentRealm['id'];
                    try {
                        $socialConnects = $navigator->getSocialConnects($realmId);
                        if ($socialConnects && is_array($socialConnects)) {
                            $allSocialLinks = $socialConnects;
                        }
                    } catch (Exception $e) { error_log('getHamburgerStructuredMenu menu load error: ' . $e->getMessage()); }
                } else {
                    $realms = $navigator->getRealms();
                    if ($realms && is_object($realms)) {
                        foreach ($realms as $realmId => $realm) {
                            try {
                                $socialConnects = $navigator->getSocialConnects($realmId);
                                if ($socialConnects && is_array($socialConnects)) {
                                    $allSocialLinks = array_merge($allSocialLinks, $socialConnects);
                                }
                            } catch (Exception $e) { error_log('getHamburgerStructuredMenu all-realms menu error: ' . $e->getMessage()); }
                        }
                    }
                }
            }
            
            // Always show social footer section if enabled, even if no links
                echo '<div class="hamburger-social-footer">';
                
                echo '<div class="hamburger-social-title">Social Connect</div>';
                
                if (!empty($allSocialLinks)) {
                    echo '<div class="hamburger-social-links">';
                    foreach ($allSocialLinks as $social) {
                        if (!isset($social->platform) || !isset($social->url)) {
                            continue;
                        }
                        $platformRaw = trim((string)$social->platform);
                        $platform = strtolower($platformRaw);
                        $url = trim((string)$social->url);
                        if ($platform === '' || $url === '') {
                            continue;
                        }
                        echo '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener" class="hamburger-social-link">';
                        echo '<i class="fab fa-' . htmlspecialchars($platform) . '"></i>';
                        echo '<span>' . htmlspecialchars($platformRaw) . '</span>';
                        echo '</a>';
                    }
                    
                    echo '</div>';
                } else {
                    echo '<div class="hamburger-social-empty">No social links configured</div>';
                }
                
                echo '</div>'; // Close social footer
        } catch (Exception $e) {
            cue_autoload('error')->logError('Hamburger social integration error: ' . $e->getMessage());
            
            // Show social footer even on error
            echo '<div class="hamburger-social-footer">';
            echo '<div class="hamburger-social-title">Social Connect</div>';
            echo '<div class="hamburger-social-empty">Social links unavailable</div>';
            echo '</div>';
        }
    }
    
    echo '</div>';
    echo '</div>';

    // JavaScript functionality with expand/collapse
    echo '<script>';
    echo 'function toggleHamburgerMenu() {';
    echo '  const container = document.getElementById("globalHamburgerMenu");';
    echo '  const panel = container ? container.querySelector(".hamburger-panel") : null;';
    echo '  const backdrop = container ? container.querySelector(".hamburger-backdrop") : null;';
    echo '  if (container) container.classList.toggle("active");';
    echo '  if (panel) panel.classList.toggle("active");';
    echo '  if (backdrop) backdrop.classList.toggle("active");';
    echo '  document.body.style.overflow = (panel && panel.classList.contains("active")) ? "hidden" : "";';
    echo '}';
    echo 'function closeHamburgerMenu(el) {';
    echo '  let container = null;';
    echo '  if (el && typeof el.closest === "function") {';
    echo '    container = el.closest(".cue-hamburger-menu");';
    echo '  }';
    echo '  if (!container) {';
    echo '    container = document.getElementById("globalHamburgerMenu");';
    echo '  }';
    echo '  const panel = container ? container.querySelector(".hamburger-panel") : null;';
    echo '  const backdrop = container ? container.querySelector(".hamburger-backdrop") : null;';
    echo '  if (container) container.classList.remove("active");';
    echo '  if (panel) panel.classList.remove("active");';
    echo '  if (backdrop) backdrop.classList.remove("active");';
    echo '  document.body.style.overflow = "";';
    echo '}';
    
    // Realm expand/collapse functionality
    echo 'function toggleRealmMenu(realmId, evt) {';
    echo '  if (evt) { evt.preventDefault(); evt.stopPropagation(); }';
    echo '  const realmContent = document.getElementById(realmId);';
    echo '  if (!realmContent) return;';
    echo '  const section = realmContent.closest ? realmContent.closest(".hamburger-realm-section") : null;';
    echo '  const realmToggle = section ? section.querySelector(".hamburger-realm-icon") : null;';
    echo '  const isExpanded = realmContent.classList.contains("expanded");';
    echo '  realmContent.classList.toggle("expanded", !isExpanded);';
    echo '  realmContent.classList.toggle("collapsed", isExpanded);';
    echo '  realmContent.style.display = isExpanded ? "none" : "block";';
    echo '  realmContent.setAttribute("aria-hidden", isExpanded ? "true" : "false");';
    echo '  if (realmToggle) {';
    echo '    realmToggle.innerHTML = isExpanded ? "▶" : "▼";';
    echo '    realmToggle.classList.toggle("expanded", !isExpanded);';
    echo '  }';
    echo '}';
    
    // Submenu expand/collapse functionality
    echo 'function toggleSubmenu(menuId, evt) {';
    echo '  if (evt) { evt.preventDefault(); evt.stopPropagation(); }';
    echo '  let submenu = document.getElementById(menuId);';
    echo '  if (!submenu) return;';
    echo '  const header = submenu.previousElementSibling;';
    echo '  const submenuToggle = header && header.querySelector ? header.querySelector(".hamburger-submenu-icon") : null;';
    echo '  const isExpanded = submenu.classList.contains("expanded");';
    echo '  submenu.classList.toggle("expanded", !isExpanded);';
    echo '  submenu.classList.toggle("collapsed", isExpanded);';
    echo '  submenu.setAttribute("aria-hidden", isExpanded ? "true" : "false");';
    echo '  submenu.style.display = isExpanded ? "none" : "block";';
    echo '  submenu.style.maxHeight = isExpanded ? "0" : "none";';
    echo '  if (submenuToggle) {';
    echo '    submenuToggle.innerHTML = isExpanded ? "▶" : "▼";';
    echo '    submenuToggle.classList.toggle("expanded", !isExpanded);';
    echo '  }';
    echo '  const toggleBtn = header && header.querySelector ? header.querySelector(".hamburger-submenu-toggle-btn") : null;';
    echo '  if (toggleBtn) toggleBtn.setAttribute("aria-expanded", (!isExpanded) ? "true" : "false");';
    echo '}';
    echo 'document.addEventListener("keydown", function(e) {';
    echo '  if (e.key === "Escape") closeHamburgerMenu();';
    echo '});';
    echo '(function(){';
    echo '  const panel = document.querySelector(".hamburger-panel");';
    echo '  if (panel) {';
    echo '    panel.addEventListener("pointerdown", function(e){ }, false);';
    echo '    panel.addEventListener("touchstart", function(e){ }, { passive: true });';
    echo '  }';
    echo '})();';
    echo '</script>';
    echo '<script>';
    echo 'document.addEventListener("click", function(e){';
    echo '  var panel = document.querySelector(".hamburger-panel");';
    echo '  if (panel && panel.classList.contains("active") && !panel.contains(e.target)) {';
    echo '    closeHamburgerMenu();';
    echo '  }';
    echo '}, true);';
    echo '</script>';
}

function globalUi_getMenuPermissionSets(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($username !== '') {
        $authFunctionsPath = dirname(dirname(__DIR__)) . '/auth/auth_functions.php';
        if (!function_exists('mh_get_token_balance') && !function_exists('mh_refresh_session_token_balance') && is_file($authFunctionsPath)) {
            require_once $authFunctionsPath;
        }
        if (function_exists('mh_refresh_session_token_balance')) {
            mh_refresh_session_token_balance($username, 30);
        } elseif (function_exists('mh_get_token_balance')) {
            $bal = mh_get_token_balance($username);
            if (is_int($bal)) $_SESSION['tokens'] = $bal;
        }
    }
    $role = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
    $isKripzMaster = ($role !== '' && stripos($role, 'kripzmaster') !== false);
    if ($isKripzMaster) {
        return [
            'is_kripzmaster' => true,
            'realms' => [],
            'menus' => [],
            'submenus' => [],
            'explicit_realms' => false,
            'explicit_menus' => false,
            'explicit_submenus' => false,
        ];
    }

    $default = [
        'is_kripzmaster' => false,
        'realms' => ['hub'],
        'menus' => [],
        'submenus' => [],
        'explicit_realms' => false,
        'explicit_menus' => false,
        'explicit_submenus' => false,
    ];
    if ($username === '') {
        return $default;
    }
    $rawPerms = $_SESSION['mh_auth_permissions'] ?? null;
    if (is_string($rawPerms)) {
        $decoded = json_decode($rawPerms, true);
    } elseif (is_array($rawPerms)) {
        $decoded = $rawPerms;
    } else {
        $decoded = null;
    }
    if (!is_array($decoded)) {
        return $default;
    }

    $realms = (isset($decoded['realms']) && is_array($decoded['realms'])) ? array_values(array_unique(array_map('strval', $decoded['realms']))) : [];
    $menus = (isset($decoded['menus']) && is_array($decoded['menus'])) ? array_values(array_unique(array_map('strval', $decoded['menus']))) : [];
    $submenus = (isset($decoded['submenus']) && is_array($decoded['submenus'])) ? array_values(array_unique(array_map('strval', $decoded['submenus']))) : [];
    if (!in_array('hub', $realms, true)) {
        array_unshift($realms, 'hub');
    }

    return [
        'is_kripzmaster' => false,
        'realms' => $realms,
        'menus' => $menus,
        'submenus' => $submenus,
        'explicit_realms' => array_key_exists('realms', $decoded),
        'explicit_menus' => array_key_exists('menus', $decoded),
        'explicit_submenus' => array_key_exists('submenus', $decoded),
    ];
}

function getHamburgerStructuredMenu($config = []) {
    if (!defined('CUE_CORE_LOADED')) {
        require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
    }
    $structuredMenu = [];
    try {
        $navigatorManagerPath = dirname(__DIR__) . '/menus/navigation-database-manager.php';
        if (file_exists($navigatorManagerPath)) {
            require_once $navigatorManagerPath;
            $navigator = new NavigationDatabaseManager();
            $currentRealm = getCurrentRealm();
            $perm = globalUi_getMenuPermissionSets();
            $allowedRealms = (isset($config['allowed_realms']) && is_array($config['allowed_realms'])) ? array_values(array_unique(array_map('strval', $config['allowed_realms']))) : (isset($perm['realms']) ? array_values(array_unique(array_map('strval', (array)$perm['realms']))) : []);
            $allowedMenus = (isset($config['allowed_menus']) && is_array($config['allowed_menus'])) ? array_values(array_unique(array_map('strval', $config['allowed_menus']))) : (isset($perm['menus']) ? array_values(array_unique(array_map('strval', (array)$perm['menus']))) : []);
            $allowedSubmenus = (isset($config['allowed_submenus']) && is_array($config['allowed_submenus'])) ? array_values(array_unique(array_map('strval', $config['allowed_submenus']))) : (isset($perm['submenus']) ? array_values(array_unique(array_map('strval', (array)$perm['submenus']))) : []);
            $explicitRealms = array_key_exists('allowed_realms', (array)$config) ? true : (bool)($perm['explicit_realms'] ?? false);
            $explicitMenus = array_key_exists('allowed_menus', (array)$config) ? true : (bool)($perm['explicit_menus'] ?? false);
            $explicitSubmenus = array_key_exists('allowed_submenus', (array)$config) ? true : (bool)($perm['explicit_submenus'] ?? false);

            $forceAll = !empty($config['include_all_realms']) || !empty($perm['is_kripzmaster']);
            $sessionRole = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
            if ($sessionRole !== '' && stripos($sessionRole, 'kripzmaster') !== false) {
                $forceAll = true;
            }

            $realms = $navigator->getRealms();
            if (!$forceAll) {
                $realmIds = [];
                if (!empty($allowedRealms)) {
                    $realmIds = [];
                    if ($realms && (is_object($realms) || is_array($realms))) {
                        $realmsList = is_object($realms) ? get_object_vars($realms) : $realms;
                        foreach ($realmsList as $rid => $_r) {
                            if (in_array((string)$rid, $allowedRealms, true)) {
                                $realmIds[] = (string)$rid;
                            }
                        }
                    }
                    if (empty($realmIds)) {
                        $realmIds = $allowedRealms;
                    }
                } elseif ($currentRealm && isset($currentRealm['id'])) {
                    $realmIds = [(string)$currentRealm['id']];
                } else {
                    $realmIds = ['hub'];
                }
                foreach ($realmIds as $realmId) {
                    $realm = null;
                    if ($realms && is_object($realms) && isset($realms->$realmId)) {
                        $realm = $realms->$realmId;
                    } elseif ($realms && is_array($realms) && isset($realms[$realmId])) {
                        $realm = $realms[$realmId];
                    }
                    if (!$realm) {
                        continue;
                    }
                    $realmData = [ 'id' => $realmId, 'title' => ($realm->name ?? (string)$realmId), 'url' => '', 'icon' => ($realm->icon ?? null), 'menus' => [] ];
                    try {
                        $menus = $navigator->getMenus($realmId);
                        if ($menus && is_array($menus)) {
                            foreach ($menus as $menu) {
                                if (!isset($menu->title)) {
                                    continue;
                                }
                                $menuId = isset($menu->id) ? (string)$menu->id : '';
                                if ($explicitMenus) {
                                    if ($menuId === '' || !in_array($menuId, $allowedMenus, true)) {
                                        continue;
                                    }
                                }
                                $murl = isset($menu->url) ? trim((string)$menu->url) : '';
                                if ($murl === '#' || stripos($murl, 'javascript:') === 0) { $murl = ''; }
                                if (strcasecmp((string)$menu->title, 'Global UI') === 0) { $murl = ''; }
                                $menuData = [ 'id' => ($menu->id ?? null), 'title' => $menu->title, 'url' => $murl, 'icon' => ($menu->icon ?? null), 'submenus' => [] ];
                                if (!empty($menu->submenu) && (is_array($menu->submenu) || is_object($menu->submenu))) {
                                    $items = is_array($menu->submenu) ? $menu->submenu : (array)$menu->submenu;
                                    foreach ($items as $submenu) {
                                        $subTitle = is_object($submenu) ? ($submenu->title ?? ($submenu->name ?? ($submenu->label ?? null))) : ($submenu['title'] ?? ($submenu['name'] ?? ($submenu['label'] ?? null)));
                                        $subUrl = is_object($submenu) ? ($submenu->url ?? ($submenu->href ?? ($submenu->link ?? null))) : ($submenu['url'] ?? ($submenu['href'] ?? ($submenu['link'] ?? null)));
                                        $subId = is_object($submenu) ? ($submenu->id ?? null) : ($submenu['id'] ?? null);
                                        $subIcon = is_object($submenu) ? ($submenu->icon ?? null) : ($submenu['icon'] ?? null);
                                        $subIdStr = $subId !== null ? (string)$subId : '';
                                        if ($explicitSubmenus) {
                                            if ($subIdStr === '' || !in_array($subIdStr, $allowedSubmenus, true)) {
                                                continue;
                                            }
                                        }
                                        if ($subTitle) {
                                            $u = isset($subUrl) ? trim((string)$subUrl) : '';
                                            if ($u === '#' || stripos($u, 'javascript:') === 0) { $u = ''; }
                                            $menuData['submenus'][] = [ 'id' => $subId, 'title' => $subTitle, 'url' => $u, 'icon' => $subIcon ];
                                        }
                                    }
                                }
                                $realmData['menus'][] = $menuData;
                            }
                        }
                    } catch (Exception $e) {}
                    if (!$explicitRealms || !empty($realmData['menus']) || $realmId === 'hub') {
                        $structuredMenu[] = $realmData;
                    }
                }
            } else {
                if ($realms && (is_object($realms) || is_array($realms))) {
                    $realmsList = is_object($realms) ? get_object_vars($realms) : $realms;
                    $hasPriority = false;
                    $hasOrderIndex = false;
                    foreach ($realmsList as $r) { if (isset($r->priority)) { $hasPriority = true; } if (isset($r->order_index)) { $hasOrderIndex = true; } }
                    $realmItems = [];
                    foreach ($realmsList as $rid => $realm) { $realmItems[] = ['rid' => $rid, 'realm' => $realm]; }
                    foreach ($realmItems as $item) { $rid = $item['rid']; $realm = $item['realm'];
                        if (isset($realm->name) && !empty($realm->name)) {
                            $realmData = [ 'id' => $rid, 'title' => $realm->name, 'url' => '', 'icon' => ($realm->icon ?? null), 'menus' => [] ];
                            try {
                                $menus = $navigator->getMenus($rid);
                                if ($menus && is_array($menus)) {
                                    foreach ($menus as $menu) {
                                        if (isset($menu->title)) {
                                            if ($explicitMenus) {
                                                $mid = isset($menu->id) ? (string)$menu->id : '';
                                                if ($mid === '' || !in_array($mid, $allowedMenus, true)) {
                                                    continue;
                                                }
                                            }
                                            $murl = isset($menu->url) ? trim((string)$menu->url) : '';
                                            if ($murl === '#' || stripos($murl, 'javascript:') === 0) { $murl = ''; }
                                            if (strcasecmp((string)$menu->title, 'Global UI') === 0) { $murl = ''; }
                                            $menuData = [ 'id' => ($menu->id ?? null), 'title' => $menu->title, 'url' => $murl, 'icon' => ($menu->icon ?? null), 'submenus' => [] ];
                                            if (!empty($menu->submenu) && (is_array($menu->submenu) || is_object($menu->submenu))) {
                                                $items = is_array($menu->submenu) ? $menu->submenu : (array)$menu->submenu;
                                                foreach ($items as $submenu) {
                                                    $subTitle = is_object($submenu) ? ($submenu->title ?? ($submenu->name ?? ($submenu->label ?? null))) : ($submenu['title'] ?? ($submenu['name'] ?? ($submenu['label'] ?? null)));
                                                    $subUrl = is_object($submenu) ? ($submenu->url ?? ($submenu->href ?? ($submenu->link ?? null))) : ($submenu['url'] ?? ($submenu['href'] ?? ($submenu['link'] ?? null)));
                                                    $subId = is_object($submenu) ? ($submenu->id ?? null) : ($submenu['id'] ?? null);
                                                    $subIcon = is_object($submenu) ? ($submenu->icon ?? null) : ($submenu['icon'] ?? null);
                                                    $subIdStr = $subId !== null ? (string)$subId : '';
                                                    if ($explicitSubmenus) {
                                                        if ($subIdStr === '' || !in_array($subIdStr, $allowedSubmenus, true)) {
                                                            continue;
                                                        }
                                                    }
                                                    if ($subTitle) {
                                                        $u = isset($subUrl) ? trim((string)$subUrl) : '';
                                                        if ($u === '#' || stripos($u, 'javascript:') === 0) { $u = ''; }
                                                        $menuData['submenus'][] = [ 'id' => $subId, 'title' => $subTitle, 'url' => $u, 'icon' => $subIcon ];
                                                    }
                                                }
                                            }
                                            $realmData['menus'][] = $menuData;
                                        }
                                    }
                                }
                            } catch (Exception $e) {}
                            if (!$explicitRealms || in_array((string)$rid, $allowedRealms, true) || $rid === 'hub') {
                                if (!$explicitRealms || !empty($realmData['menus']) || $rid === 'hub') {
                                    $structuredMenu[] = $realmData;
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) { error_log('getHamburgerStructuredMenu fatal error: ' . $e->getMessage()); }
    try {
        $hasHub = false;
        foreach ($structuredMenu as &$realm) {
            if (!is_array($realm)) continue;
            if ((string)($realm['id'] ?? '') !== 'hub') continue;
            $hasHub = true;
            if (!isset($realm['menus']) || !is_array($realm['menus'])) $realm['menus'] = [];
            $hasAgents = false;
            foreach ($realm['menus'] as $m) {
                if (!is_array($m)) continue;
                if (strcasecmp((string)($m['title'] ?? ''), 'Agents') === 0) { $hasAgents = true; break; }
            }
            if (!$hasAgents) {
                $realm['menus'][] = [
                    'id' => 'agents',
                    'title' => 'Agents',
                    'url' => '',
                    'icon' => null,
                    'submenus' => [
                        ['id' => 'agents_multica', 'title' => 'Multica', 'url' => '/hub/agents/multi.php', 'icon' => null],
                    ],
                ];
            }
            break;
        }
        unset($realm);
        if (!$hasHub) {
            $structuredMenu[] = [
                'id' => 'hub',
                'title' => 'Hub',
                'url' => '/hub/',
                'icon' => null,
                'menus' => [
                    [
                        'id' => 'agents',
                        'title' => 'Agents',
                        'url' => '',
                        'icon' => null,
                        'submenus' => [
                            ['id' => 'agents_multica', 'title' => 'Multica', 'url' => '/hub/agents/multi.php', 'icon' => null],
                        ],
                    ],
                ],
            ];
        }
    } catch (Throwable) {}
    return $structuredMenu;
}

/**
 * Get menu items from navigator database
 * @return array Menu items array or empty array if failed
 */
function getNavigatorMenuItems() {
    try {
        // Check if NavigationDatabaseManager exists
        $navManagerPath = __DIR__ . '/../menus/navigation-database-manager.php';
        if (!file_exists($navManagerPath)) {
            return [];
        }
        
        require_once $navManagerPath;
        if (!class_exists('NavigationDatabaseManager')) {
            return [];
        }
        
        $navigator = new NavigationDatabaseManager();
        $menus = $navigator->getMenus(); // Use correct method name
        
        $menuItems = [];
        
        // Handle different return types from getMenus()
        if (!empty($menus)) {
            // Convert object to array if needed
            if (is_object($menus)) {
                $menus = (array) $menus;
            }
            
            // If it's an array, process the menus
            if (is_array($menus)) {
                foreach ($menus as $menu) {
                    // Convert menu object to array if needed
                    if (is_object($menu)) {
                        $menu = (array) $menu;
                    }
                    
                    // Check if this menu has items/submenus
                    if (is_array($menu)) {
                        // Look for different possible keys for menu items
                        $items = $menu['items'] ?? $menu['submenus'] ?? $menu['submenu_items'] ?? [];
                        
                        if (is_object($items)) {
                            $items = (array) $items;
                        }
                        
                        if (is_array($items) && !empty($items)) {
                            foreach ($items as $item) {
                                // Convert item object to array if needed
                                if (is_object($item)) {
                                    $item = (array) $item;
                                }
                                
                                if (is_array($item)) {
                                    $menuItems[] = [
                                        'title' => $item['title'] ?? $item['name'] ?? $item['label'] ?? 'Untitled',
                                        'url' => $item['url'] ?? $item['href'] ?? $item['link'] ?? '#'
                                    ];
                                }
                            }
                        } else {
                            // If no items, add the menu itself as an item
            $menuItems[] = [
                                'title' => $menu['title'] ?? $menu['name'] ?? $menu['label'] ?? 'Menu',
                                'url' => $menu['url'] ?? $menu['href'] ?? $menu['link'] ?? '#'
                            ];
                        }
                    }
                }
            }
        }
        
        return $menuItems;
    } catch (Exception $e) {
        error_log('Failed to load navigator menu items: ' . $e->getMessage());
        return [];
    }
}

/**
 * Convert hex color to RGBA with opacity
 */
function hexToRgba(string $hex, float $opacity = 1.0): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    return "rgba($r, $g, $b, $opacity)";
}

/**
 * Generate Header Background CSS based on type
 */
function generateHeaderBackground(array $headerConfig): string {
    $backgroundType = $headerConfig['background_type'] ?? 'solid';
    
    switch ($backgroundType) {
        case 'gradient':
            $color1 = $headerConfig['gradient_color1'] ?? '#1a1a2e';
            $color2 = $headerConfig['gradient_color2'] ?? '#003344';
            $angle = $headerConfig['gradient_angle'] ?? 135;
            $opacity = ($headerConfig['gradient_opacity'] ?? 100) / 100;
            
            $gradientColors = [
                hexToRgba($color1, $opacity),
                hexToRgba($color2, $opacity)
            ];
            
            // Add third color if multi-color is enabled
            if ($headerConfig['gradient_multi_enabled'] ?? false) {
                $color3 = $headerConfig['gradient_color3'] ?? '#0066aa';
                $gradientColors[] = hexToRgba($color3, $opacity);
            }
            
            return 'background: linear-gradient(' . $angle . 'deg, ' . implode(', ', $gradientColors) . ')';
            
        case 'animated':
            $animationType = $headerConfig['animation_type'] ?? 'none';
            $baseColor = $headerConfig['animation_color'] ?? '#0066aa';
            $opacity = ($headerConfig['animation_opacity'] ?? 100) / 100;
            $backgroundRgba = hexToRgba($baseColor, $opacity);
            
            // For animated backgrounds, set a base color and let the animation overlay handle the rest
            return 'background: ' . $backgroundRgba;
            
        case 'solid':
        default:
            $backgroundColor = $headerConfig['background_color'] ?? '#1a1a2e';
            $backgroundOpacity = ($headerConfig['background_opacity'] ?? 100) / 100;
            $backgroundRgba = hexToRgba($backgroundColor, $backgroundOpacity);
            
            return 'background: ' . $backgroundRgba;
    }
}

/**
 * Include Global UI Components CSS
 */
function includeGlobalUIStyles($headerConfig = []) {
    if (!empty($GLOBALS['_GLOBAL_UI_STYLES_INCLUDED'])) {
        return;
    }
    $GLOBALS['_GLOBAL_UI_STYLES_INCLUDED'] = true;
    if (empty($headerConfig)) {
        $headerConfigFile = getDataPath() . '/global-ui/header/header-config.json';
        if (file_exists($headerConfigFile)) {
            $savedConfig = json_decode(file_get_contents($headerConfigFile), true);
            if ($savedConfig && isset($savedConfig['K::HeaderUI::Configuration'])) {
                $configKeys = array_keys($savedConfig['K::HeaderUI::Configuration']);
                if (!empty($configKeys)) {
                    $headerConfig = $savedConfig['K::HeaderUI::Configuration'][$configKeys[0]];
                }
            }
        }
    }
    if (empty($GLOBALS['_FA_LOADED'])) {
        $faUrl = '/templates/assets/icons/fontawesome/css/all.min.css';
        $faPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/icons/fontawesome/css/all.min.css');
        if (file_exists($faPath)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
            $GLOBALS['_FA_LOADED'] = true;
        } else {
            $faAltUrl = '/templates/assets/fonts/all.min.css';
            $faAltPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/fonts/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/fonts/all.min.css');
            if (file_exists($faAltPath)) {
                echo '<link rel="stylesheet" href="' . htmlspecialchars($faAltUrl, ENT_QUOTES) . '">';
                $GLOBALS['_FA_LOADED'] = true;
            }
        }
    }
    if (empty($GLOBALS['_ICONOIR_LOADED'])) {
        $iconoirUrl = '/templates/assets/icons/iconoir/css/iconoir.css';
        $iconoirPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/iconoir/css/iconoir.css') : (dirname(__DIR__, 2) . '/templates/assets/icons/iconoir/css/iconoir.css');
        if (file_exists($iconoirPath)) {
            $v = @filemtime($iconoirPath);
            $href = $iconoirUrl . ($v ? ('?v=' . (int)$v) : '');
            echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
            $GLOBALS['_ICONOIR_LOADED'] = true;
        }
    }
    if (empty($GLOBALS['_PHOSPHOR_LOADED'])) {
        $phUrl = '/templates/assets/icons/phosphor/Fonts/regular/style.css';
        $phPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/phosphor/Fonts/regular/style.css') : (dirname(__DIR__, 2) . '/templates/assets/icons/phosphor/Fonts/regular/style.css');
        if (file_exists($phPath)) {
            $v = @filemtime($phPath);
            $href = $phUrl . ($v ? ('?v=' . (int)$v) : '');
            echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
            $GLOBALS['_PHOSPHOR_LOADED'] = true;
        }
    }

    // Ensure MetaHumans custom icon style is loaded
    if (empty($GLOBALS['_FA_META_LOADED'])) {
        $metaUrl = '/templates/assets/images/branding/logo/MHlogoTB64.png';
        echo '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
        $GLOBALS['_FA_META_LOADED'] = true;
    }

    // Generate global UI CSS first
    generateGlobalUICSS();
    
    $textColor = $headerConfig['text_color'] ?? '#00ffff';
    $headerHeight = $headerConfig['hdr_height'] ?? $headerConfig['header_height'] ?? 200;
    $headerPosition = $headerConfig['position'] ?? 'fixed';
    $headerPosition = strtolower(trim((string)$headerPosition));
    $allowedHeaderPositions = ['fixed', 'sticky', 'relative', 'static'];
    if (!in_array($headerPosition, $allowedHeaderPositions, true)) {
        $headerPosition = 'fixed';
    }
    
    // Generate background based on type
    $backgroundType = $headerConfig['background_type'] ?? 'solid';
    $bgFn = 'generateHeaderBackground';
    $backgroundStyle = function_exists($bgFn) ? $bgFn($headerConfig) : 'background: #001f3f';
    
    // Build dynamic visual effects styles with enhanced positioning
    $headerStyles = [
        $backgroundStyle,
        'backdrop-filter: blur(10px)',
        'border-bottom: 1px solid ' . htmlspecialchars($textColor) . '55',
        'position: ' . htmlspecialchars($headerPosition) . ' !important',
        'top: 0 !important',
        'left: 0 !important', 
        'right: 0 !important',
        'width: 100% !important',
        'z-index: 1000 !important',
        'height: ' . (int)$headerHeight . 'px !important',
        'color: ' . htmlspecialchars($textColor),
        'box-sizing: border-box !important'
    ];
    
    // Build combined shadow effects (shadow + glow combined to avoid conflicts)
    $shadowParts = [];
    
    // Add shadow effect if enabled
    if (($headerConfig['shadow_enabled'] ?? false) && isset($headerConfig['shadow_color'])) {
        $shadowX = $headerConfig['shadow_x'] ?? 2;
        $shadowY = $headerConfig['shadow_y'] ?? 2;
        $shadowBlur = $headerConfig['shadow_blur'] ?? 4;
        $shadowSpread = $headerConfig['shadow_spread'] ?? 0;
        $shadowColor = $headerConfig['shadow_color'];
        $shadowParts[] = (int)$shadowX . 'px ' . (int)$shadowY . 'px ' . (int)$shadowBlur . 'px ' . (int)$shadowSpread . 'px ' . htmlspecialchars($shadowColor);
    }
    
    // Add glow effect if enabled
    if (($headerConfig['glow_enabled'] ?? false) && isset($headerConfig['glow_color'])) {
        $glowColor = $headerConfig['glow_color'];
        $glowIntensity = $headerConfig['glow_intensity'] ?? 5;
        $glowSize = $headerConfig['glow_size'] ?? 10;
        $shadowParts[] = '0 0 ' . (int)$glowSize . 'px ' . htmlspecialchars($glowColor);
        $shadowParts[] = '0 0 ' . (int)$glowIntensity . 'px ' . htmlspecialchars($glowColor);
    }
    
    // Apply combined shadows if any exist
    if (!empty($shadowParts)) {
        $headerStyles[] = 'box-shadow: ' . implode(', ', $shadowParts);
    }

    echo '<style>';
    echo '.mh-header-notices-btn{appearance:none;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.05);color:var(--theme-primary,#00d4ff);width:40px;height:40px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;position:relative;pointer-events:auto}';
    echo '.mh-header-notices-btn:hover{background:rgba(0,212,255,.10);border-color:rgba(0,212,255,.35)}';
    echo '.mh-header-notices-btn svg{width:20px;height:20px;display:block}';
    echo '.mh-header-notices-dot{position:absolute;top:7px;right:7px;width:9px;height:9px;border-radius:999px;background:rgba(239,68,68,.95);box-shadow:0 0 0 2px rgba(0,0,0,.35);display:none}';
    echo '.mh-header-notices-btn.mh-has-new .mh-header-notices-dot{display:block}';
    echo '.mh-header-notices-btn.mh-flicker{animation:mhHeaderNoticesFlicker 1.1s infinite}';
    echo '@keyframes mhHeaderNoticesFlicker{0%{filter:brightness(1)}30%{filter:brightness(1.35)}60%{filter:brightness(1)}100%{filter:brightness(1)}}';
    echo '.mh-notices-global-root{position:fixed;inset:0;pointer-events:none;z-index:2147483646}';
    echo '.mh-notices-backdrop{position:fixed;inset:0;pointer-events:none;background:rgba(0,0,0,.55);opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s ease}';
    echo '.mh-notices-backdrop.mh-open{pointer-events:auto;opacity:1;visibility:visible}';
    echo '.mh-notices-panel{position:fixed;top:20px;bottom:20px;right:20px;width:min(560px,calc(100vw - 40px));display:flex;flex-direction:column;pointer-events:none;background:linear-gradient(135deg,rgba(18,24,44,.92),rgba(10,14,26,.94));border:1px solid rgba(0,212,255,.18);border-radius:18px;backdrop-filter:blur(14px);color:#e8eefc;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;overflow:hidden;opacity:0;visibility:hidden;transform:translateY(10px) scale(.985);transition:opacity .18s ease,transform .18s ease,visibility .18s ease;box-shadow:0 24px 90px rgba(0,0,0,.62),0 0 0 1px rgba(255,255,255,.08) inset}';
    echo '.mh-notices-panel.mh-open{pointer-events:auto;opacity:1;visibility:visible;transform:none}';
    echo '.mh-notices-h{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.10);cursor:move;touch-action:none;user-select:none;-webkit-user-select:none}';
    echo '.mh-notices-title{font-weight:950;font-size:12px;letter-spacing:.03em;text-transform:uppercase;opacity:.9}';
    echo '.mh-notices-actions{margin-left:auto;display:flex;gap:10px;flex-wrap:wrap}';
    echo '.mh-notices-btn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:#e8eefc;border-radius:10px;padding:6px 10px;font-weight:950;cursor:pointer}';
    echo '.mh-notices-btn:hover{background:rgba(0,212,255,.10);border-color:rgba(0,212,255,.28)}';
    echo '.mh-notices-b{padding:14px;display:flex;flex-direction:column;gap:12px;overflow:auto}';
    echo '.mh-notice{border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;background:rgba(255,255,255,.04)}';
    echo '.mh-notice-top{display:flex;justify-content:space-between;gap:10px;align-items:baseline}';
    echo '.mh-notice-title2{font-weight:900;font-size:13px}';
    echo '.mh-notice-meta{font-size:11px;opacity:.75;white-space:nowrap}';
    echo '.mh-notice-badge{display:inline-flex;align-items:center;justify-content:center;font-weight:950;font-size:10px;letter-spacing:.03em;text-transform:uppercase;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);margin-top:8px}';
    echo '.mh-notice-badge.success{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.12)}';
    echo '.mh-notice-badge.warning{border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.12)}';
    echo '.mh-notice-badge.error{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)}';
    echo '.mh-notice-badge.info{border-color:rgba(59,130,246,.35);background:rgba(59,130,246,.12)}';
    echo '.mh-notice-body{margin-top:8px;font-size:12px;line-height:1.55;opacity:.92;white-space:pre-wrap;overflow:hidden}';
    echo '.mh-notice-body.mh-collapsed{max-height:9.2em;position:relative}';
    echo '.mh-notice-body.mh-collapsed:after{content:"";position:absolute;left:0;right:0;bottom:0;height:2.8em;background:linear-gradient(to bottom, rgba(12,18,34,0), rgba(12,18,34,.92))}';
    echo '.mh-notice-actions{margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}';
    echo '.mh-notice-link{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;font-weight:900;font-size:12px;border:1px solid rgba(0,212,255,.35);background:rgba(0,212,255,.14);color:#e8eefc;text-decoration:none}';
    echo '.mh-notice-link2{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;font-weight:900;font-size:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#e8eefc;text-decoration:none}';
    echo '.mh-notice-select{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:#e8eefc;border-radius:10px;padding:8px 10px;font-weight:900;font-size:12px}';
    echo '.mh-notices-rz{position:absolute;pointer-events:auto;background:transparent}';
    echo '.mh-notices-rz[data-dir="n"],.mh-notices-rz[data-dir="s"]{left:8px;right:8px;height:10px}';
    echo '.mh-notices-rz[data-dir="n"]{top:-5px;cursor:n-resize}';
    echo '.mh-notices-rz[data-dir="s"]{bottom:-5px;cursor:s-resize}';
    echo '.mh-notices-rz[data-dir="e"],.mh-notices-rz[data-dir="w"]{top:8px;bottom:8px;width:10px}';
    echo '.mh-notices-rz[data-dir="e"]{right:-5px;cursor:e-resize}';
    echo '.mh-notices-rz[data-dir="w"]{left:-5px;cursor:w-resize}';
    echo '.mh-notices-rz[data-dir="ne"],.mh-notices-rz[data-dir="nw"],.mh-notices-rz[data-dir="se"],.mh-notices-rz[data-dir="sw"]{width:18px;height:18px}';
    echo '.mh-notices-rz[data-dir="ne"]{top:-6px;right:-6px;cursor:ne-resize}';
    echo '.mh-notices-rz[data-dir="nw"]{top:-6px;left:-6px;cursor:nw-resize}';
    echo '.mh-notices-rz[data-dir="se"]{bottom:-6px;right:-6px;cursor:se-resize}';
    echo '.mh-notices-rz[data-dir="sw"]{bottom:-6px;left:-6px;cursor:sw-resize}';
    echo '</style>';
    
    // Add border effect if enabled
    if (($headerConfig['border_enabled'] ?? false) && isset($headerConfig['border_color'])) {
        $borderWidth = $headerConfig['border_width'] ?? 1;
        $borderStyle = $headerConfig['border_style'] ?? 'solid';
        $borderColor = $headerConfig['border_color'];
        $borderRadius = $headerConfig['border_radius'] ?? 0;
        $headerStyles[] = 'border: ' . (int)$borderWidth . 'px ' . htmlspecialchars($borderStyle) . ' ' . htmlspecialchars($borderColor);
        if ($borderRadius > 0) {
            $headerStyles[] = 'border-radius: ' . (int)$borderRadius . 'px';
        }
    }
    
    echo '<style>
    /* CUE Global UI Styles */
    /* Set CSS variables for dynamic updates */
    :root {
        --header-height: ' . (int)$headerHeight . 'px;
        --header-position: ' . htmlspecialchars($headerPosition) . ';
    }
    
    /* Global Header Styles */
    .cue-global-header {
        ' . implode(';' . "\n        ", $headerStyles) . ';
        overflow: hidden;
    }
    
    /* Override any conflicting position rules */
    .cue-global-header[data-position="fixed"] {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        z-index: 1000 !important;
    }
    
    .cue-global-header[data-position="sticky"] {
        position: sticky !important;
        top: 0 !important;
        z-index: 1000 !important;
    }
    
    .cue-global-header[data-position="relative"] {
        position: relative !important;
    }
    
    .cue-global-header[data-position="static"] {
        position: static !important;
    }

    main.main-content,
    .main-content {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    
    /* Header Animation Background */
    .header-animation-background {
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        z-index: -1 !important;
        pointer-events: none !important;
        opacity: 1 !important;
        visibility: visible !important;
        display: block !important;
    }
    
    .header-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 10px;
        height: 100%;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .header-brand {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .header-logo {
        height: 40px;
        width: auto;
    }
    
    .header-title {
        /* Dynamic styles applied inline - no hardcoded values */
        margin: 0;
        line-height: 1.2;
        word-wrap: break-word;
        overflow-wrap: break-word;
        max-width: 100%;
    }
    
    .header-navigation {
        display: flex;
        gap: 20px;
    }
    
    .nav-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        color: var(--theme-primary, #00ffff);
        text-decoration: none;
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.1);
        border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2);
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .nav-item:hover {
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2);
        box-shadow: 0 0 15px rgba(var(--theme-primary-rgb, 0, 255, 255), 0.3);
    }
    
    /* Global Footer Styles */
    .cue-global-footer {
        background: rgba(var(--theme-background-rgb, 0, 31, 63), 0.9) !important;
        backdrop-filter: blur(10px);
        border-top: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.3);
        color: var(--theme-primary, #00ffff);
        padding: 8px 0;
        margin-top: 12px;
    }
    
    .footer-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 10px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 16px;
        align-items: center;
    }

    .cue-global-header { background: rgba(var(--theme-background-rgb, 0, 31, 63), 0.9) !important; color: var(--theme-primary, #00ffff) !important; }
    .cue-global-header * { color: var(--theme-primary, #00ffff) !important; }
    .cue-global-footer { color: var(--theme-primary, #00ffff) !important; }
    .cue-global-footer * { color: var(--theme-primary, #00ffff) !important; }
    
    .footer-links {
        display: flex;
        gap: 20px;
    }
    
    .footer-link {
        display: flex;
        align-items: center;
        gap: 8px;
        color: var(--theme-primary, #00ffff);
        text-decoration: none;
        padding: 8px 12px;
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.1);
        border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2);
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    
    .footer-link:hover {
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2);
        box-shadow: 0 0 10px rgba(var(--theme-primary-rgb, 0, 255, 255), 0.3);
    }
    
    .footer-social {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
    }
    
    .social-link {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--theme-primary, #00ffff);
        text-decoration: none;
        padding: 8px;
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.1);
        border-radius: 50px;
        transition: all 0.3s ease;
    }
    
    .social-link:hover {
        background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2);
        transform: translateY(-2px);
    }
    
    .footer-copyright {
        text-align: center;
        color: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.7);
        font-size: 0.9em;
    }
    
    /* Enhanced Responsive Design */
    @media (max-width: 1024px) {
        .header-container {
            flex-wrap: wrap;
            justify-content: center;
            gap: 15px;
        }
        
        .header-title {
            font-size: calc(1em + 0.5vw) !important;
            max-width: 70%;
        }
        
        .header-slogan {
            font-size: calc(0.8em + 0.3vw) !important;
            max-width: 80%;
        }
    }
    
    @media (max-width: 768px) {
        .header-navigation {
            display: none;
        }
        
        .header-container {
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 15px;
            padding: 10px 15px;
        }
        
        .header-logo-section,
        .header-title-section {
            flex: none;
            width: 100%;
            order: unset !important;
        }
        
        .header-title-section {
            align-items: center !important;
            text-align: center !important;
        }
        
        .header-title {
            font-size: calc(1.2em + 1vw) !important;
            text-align: center !important;
        }
        
        .header-slogan {
            font-size: calc(0.9em + 0.5vw) !important;
            text-align: center !important;
        }
        
        .status-bar-widget {
            font-size: 12px !important;
            padding: 8px !important;
            flex-direction: column;
            align-items: flex-start;
        }
        
        .footer-container {
            grid-template-columns: 1fr;
            text-align: center;
            gap: 20px;
        }
        
        .footer-links {
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .footer-social {
            justify-content: center;
        }
        
        .hamburger-panel {
            width: 100vw;
        }
    }
    
    /* Header Slogan Styling */
    .header-slogan {
        margin: 5px 0;
        font-weight: 300;
        line-height: 1.4;
        display: block;
    }
    
    .header-slogan.slogan-left {
        text-align: left;
    }
    
    .header-slogan.slogan-center {
        text-align: center;
    }
    
    .header-slogan.slogan-right {
        text-align: right;
    }
    
    /* Font Face Declarations */
    @font-face { font-family: \"Merriweather\"; src: url(\"/templates/assets/fonts/Merriweather-Regular.ttf\") format(\"truetype\"); font-weight: normal; }
    @font-face { font-family: \"Merriweather\"; src: url(\"/templates/assets/fonts/Merriweather-Bold.ttf\") format(\"truetype\"); font-weight: bold; }
    @font-face { font-family: \"Orbitron\"; src: url(\"/templates/assets/fonts/Orbitron-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Orbitron\"; src: url(\"/templates/assets/fonts/Orbitron-Bold.woff2\") format(\"woff2\"); font-weight: bold; }
    @font-face { font-family: \"Rajdhani\"; src: url(\"/templates/assets/fonts/rajdhani/LDIxapCSOBg7S-QT7p4JM-aUWA.woff2\") format(\"woff2\"), url(\"/templates/assets/fonts/Rajdhani-Regular-proper.ttf\") format(\"truetype\"); font-weight: normal; font-display: swap; }
    @font-face { font-family: \"Rajdhani\"; src: url(\"/templates/assets/fonts/rajdhani/LDI2apCSOBg7S-QT7pb0EPOqeef2kg.woff2\") format(\"woff2\"), url(\"/templates/assets/fonts/Rajdhani-Regular-proper.ttf\") format(\"truetype\"); font-weight: bold; font-display: swap; }
    @font-face { font-family: \"Inter\"; src: url(\"/templates/assets/fonts/inter/Inter-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Inter\"; src: url(\"/templates/assets/fonts/inter/Inter-Bold.woff2\") format(\"woff2\"); font-weight: bold; }
    @font-face { font-family: \"Lato\"; src: url(\"/templates/assets/fonts/lato/Lato-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Lato\"; src: url(\"/templates/assets/fonts/lato/Lato-Bold.woff2\") format(\"woff2\"); font-weight: bold; }
    @font-face { font-family: \"Montserrat\"; src: url(\"/templates/assets/fonts/montserrat/Montserrat-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Montserrat\"; src: url(\"/templates/assets/fonts/montserrat/Montserrat-Bold.woff2\") format(\"woff2\"); font-weight: bold; }
    @font-face { font-family: \"Poppins\"; src: url(\"/templates/assets/fonts/poppins/Poppins-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Poppins\"; src: url(\"/templates/assets/fonts/poppins/Poppins-Bold.woff2\") format(\"woff2\"); font-weight: bold; }
    @font-face { font-family: \"Roboto\"; src: url(\"/templates/assets/fonts/roboto/KFO7CnqEu92Fr1ME7kSn66aGLdTylUAMaxKUBGEe.woff2\") format(\"woff2\"); font-weight: 400; font-style: normal; font-display: swap; }
    @font-face { font-family: \"Roboto\"; src: url(\"/templates/assets/fonts/roboto/KFO7CnqEu92Fr1ME7kSn66aGLdTylUAMa3KUBGEe.woff2\") format(\"woff2\"); font-weight: 700; font-style: normal; font-display: swap; }
    @font-face { font-family: \"Open Sans\"; src: url(\"/templates/assets/fonts/open-sans/OpenSans-Regular.woff2\") format(\"woff2\"); font-weight: normal; }
    @font-face { font-family: \"Open Sans\"; src: url(\"/templates/assets/fonts/open-sans/OpenSans-Bold.woff2\") format(\"woff2\"); font-weight: bold; }

    /* Header Title Styling */
    .header-title {
        margin: 0;
        font-weight: bold;
        line-height: 1.2;
    }

    .header-title.title-left {
        text-align: left;
    }

    .header-title.title-center {
        text-align: center;
    }

    .header-title.title-right {
        text-align: right;
    }

    .mh-header-spacer, .mh-footer-spacer {
        display: block;
        width: 100%;
        height: 0;
    }
	
    .mh-auth-body {
        margin: 0;
        min-height: 100vh;
        display: block;
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background:
            radial-gradient(circle at top left, rgba(124, 58, 237, 0.22), transparent 55%),
            radial-gradient(circle at bottom right, rgba(0, 212, 255, 0.22), transparent 55%),
            radial-gradient(circle at center, rgba(15, 23, 42, 0.9), #020617);
        color: #f9fafb;
    }

    .mh-auth-main {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 24px 12px;
        box-sizing: border-box;
    }

    .mh-auth-shell {
        width: 100%;
        max-width: 980px;
        margin-bottom: 20px;
    }

    .mh-auth-shell-secondary {
        width: 100%;
        max-width: 720px;
    }

    .mh-auth-grid {
        display: grid;
        grid-template-columns: minmax(0, 3fr) minmax(0, 2.3fr);
        gap: 22px;
    }

    @media (max-width: 900px) {
        .mh-auth-grid {
            grid-template-columns: minmax(0, 1fr);
        }
    }

    .mh-auth-panel {
        position: relative;
        padding: 22px 22px 24px;
        border-radius: 18px;
        background: linear-gradient(145deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.95));
        border: 1px solid rgba(148, 163, 184, 0.18);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(18px);
    }

    .mh-auth-panel-inner {
        position: relative;
        z-index: 1;
    }

    .mh-auth-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 4px 12px;
        border-radius: 999px;
        background: radial-gradient(circle at top left, rgba(0, 212, 255, 0.22), rgba(15, 23, 42, 0.8));
        border: 1px solid rgba(56, 189, 248, 0.4);
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #00d4ff;
    }

    .mh-auth-pill-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: radial-gradient(circle at 30% 30%, #f9fafb, #22c55e);
        box-shadow: 0 0 12px rgba(34, 197, 94, 0.85);
    }

    .mh-auth-title {
        margin: 14px 0 6px;
        font-size: 24px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
    }

    .mh-auth-subtitle {
        margin: 0 0 18px;
        font-size: 14px;
        color: #9ca3af;
    }

    .mh-auth-mode-toggle {
        display: inline-flex;
        padding: 2px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.92);
        border: 1px solid rgba(148, 163, 184, 0.35);
        margin-bottom: 20px;
    }

    .mh-auth-mode-toggle button {
        border: 0;
        margin: 0;
        padding: 7px 14px;
        border-radius: 999px;
        font-size: 12px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        cursor: pointer;
        background: transparent;
        color: #9ca3af;
        transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
    }

    .mh-auth-mode-toggle button.active {
        background: radial-gradient(circle at top left, rgba(0, 212, 255, 0.3), rgba(15, 23, 42, 0.9));
        color: #f9fafb;
        box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.8), 0 0 24px rgba(56, 189, 248, 0.5);
    }

    .mh-auth-field-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-bottom: 14px;
    }

    .mh-auth-field-group label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.16em;
        color: #9ca3af;
    }

    .mh-auth-input-shell {
        position: relative;
    }

    .mh-auth-input-shell input {
        width: 100%;
        padding: 9px 11px;
        border-radius: 10px;
        border: 1px solid rgba(148, 163, 184, 0.2);
        background: rgba(15, 23, 42, 0.9);
        color: #f9fafb;
        font-size: 14px;
        outline: none;
        transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
    }

    .mh-auth-input-shell input:focus {
        border-color: rgba(56, 189, 248, 0.9);
        box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.6), 0 0 22px rgba(56, 189, 248, 0.28);
        background: rgba(15, 23, 42, 0.98);
    }

    .mh-auth-button-main {
        width: 100%;
        margin-top: 4px;
        padding: 10px 14px;
        border-radius: 12px;
        border: 0;
        background: radial-gradient(circle at top left, #00d4ff, #7c3aed);
        color: #020617;
        font-size: 14px;
        font-weight: 600;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        cursor: pointer;
        transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
    }

    .mh-auth-button-main:hover {
        transform: translateY(-1px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.45);
        filter: brightness(1.05);
    }

    .mh-auth-register-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-top: 8px;
        padding: 9px 14px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: radial-gradient(circle at top left, rgba(148, 163, 184, 0.22), rgba(15, 23, 42, 0.9));
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        color: #e5e7eb;
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        text-decoration: none;
        cursor: pointer;
        transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease, border-color 0.12s ease;
    }

    .mh-auth-register-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.8);
        background: radial-gradient(circle at top left, rgba(191, 219, 254, 0.32), rgba(15, 23, 42, 0.96));
        border-color: rgba(96, 165, 250, 0.8);
    }

    .mh-auth-status {
        margin-top: 10px;
        min-height: 18px;
        font-size: 13px;
    }

    .mh-auth-status span {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .mh-auth-status span.ok {
        color: #22c55e;
    }

    .mh-auth-status span.err {
        color: #ef4444;
    }

    .mh-auth-status-icon {
        width: 10px;
        height: 10px;
        border-radius: 999px;
    }

    .mh-auth-status-icon.ok {
        background: #22c55e;
        box-shadow: 0 0 10px rgba(34, 197, 94, 0.8);
    }

    .mh-auth-status-icon.err {
        background: #ef4444;
        box-shadow: 0 0 10px rgba(239, 68, 68, 0.8);
    }

    .mh-auth-session-pill {
        margin-top: 18px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 6px 12px;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.9);
        border: 1px solid rgba(34, 197, 94, 0.6);
        font-size: 13px;
    }

    .mh-auth-logout-link {
        color: #60a5fa;
        text-decoration: none;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        font-size: 11px;
    }

    .mh-auth-logout-link:hover {
        text-decoration: underline;
    }

    .mh-auth-side-title {
        font-size: 14px;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #a5b4fc;
        margin-bottom: 10px;
    }

    .mh-auth-side-section {
        margin-top: 12px;
    }

    .mh-auth-side-heading {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .mh-auth-side-text {
        font-size: 13px;
        color: #9ca3af;
        margin-bottom: 8px;
    }

    .mh-auth-badge-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .mh-auth-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.4);
        font-size: 11px;
        color: #e5e7eb;
        background: rgba(15, 23, 42, 0.9);
    }

    .mh-auth-badge.accent {
        border-color: rgba(56, 189, 248, 0.7);
        color: #7dd3fc;
    }

    .mh-auth-form {
        margin-top: 10px;
    }

    .mh-auth-secondary-links {
        margin-top: 10px;
        font-size: 13px;
    }

    .mh-auth-secondary-links a {
        color: #60a5fa;
        text-decoration: none;
    }

    .mh-auth-secondary-links a:hover {
        text-decoration: underline;
    }
    </style>';
}

/**
 * Include Global UI Components JavaScript
 */
function includeGlobalUIScripts() {
    if (!empty($GLOBALS['_GLOBAL_UI_SCRIPTS_INCLUDED'])) {
        return;
    }
    $GLOBALS['_GLOBAL_UI_SCRIPTS_INCLUDED'] = true;
    echo <<<'JS'
<script>
    // Hamburger Menu Control
    function toggleHamburgerMenu() {
        const container = document.querySelector(".cue-hamburger-menu");
        const panel = document.querySelector(".hamburger-panel");
        const backdrop = document.querySelector(".hamburger-backdrop");
        if (container) container.classList.toggle("active");
        if (panel) panel.classList.toggle("active");
        if (backdrop) backdrop.classList.toggle("active");
        document.body.style.overflow = (panel && panel.classList.contains("active")) ? "hidden" : "";
    }
    
    function closeHamburgerMenu(el) {
        let container = null;
        if (el && typeof el.closest === "function") {
            container = el.closest(".cue-hamburger-menu");
        }
        if (!container) {
            container = document.querySelector(".cue-hamburger-menu");
        }
        const panel = container ? container.querySelector(".hamburger-panel") : null;
        const backdrop = container ? container.querySelector(".hamburger-backdrop") : null;
        if (container) container.classList.remove("active");
        if (panel) panel.classList.remove("active");
        if (backdrop) backdrop.classList.remove("active");
        document.body.style.overflow = "";
    }
    
    // Close menu with Escape key
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") {
            closeHamburgerMenu();
        }
    });

    window.refreshHamburgerOrder = async function() {
        try {
            const res = await fetch("/templates/global-ui/includes/hamburger.php?format=json&include=all", { cache: "no-store" });
            const data = await res.json();
            const container = document.querySelector(".hamburger-menu-items");
            if (!container || !Array.isArray(data)) return false;
            data.forEach(realm => {
                const realmNode = container.querySelector(`.hamburger-realm-section[data-realm-id="${realm.id}"]`);
                if (realmNode) container.appendChild(realmNode);
                const realmContent = document.getElementById(`realm-${realm.id}`);
                if (realmContent && Array.isArray(realm.menus)) {
                    realm.menus.forEach(menu => {
                        if (!menu.id) return;
                        const menuNode = realmContent.querySelector(`.hamburger-menu-section[data-menu-id="${menu.id}"]`);
                        if (menuNode) realmContent.appendChild(menuNode);
                        const submenuContainer = menuNode ? menuNode.querySelector(".hamburger-submenu") : null;
                        if (submenuContainer && Array.isArray(menu.submenus)) {
                            menu.submenus.forEach(sm => {
                                if (!sm.id) return;
                                const smNode = submenuContainer.querySelector(`.hamburger-submenu-item[data-submenu-id="${sm.id}"]`);
                                if (smNode) submenuContainer.appendChild(smNode);
                            });
                        }
                    });
                }
            });
            return true;
        } catch (e) {}
    };

    try {
        const bc = new BroadcastChannel("navigator-order");
        bc.onmessage = function(){ window.refreshHamburgerOrder(); };
    } catch (e) {}

    (function(){
        const panel = document.querySelector(".hamburger-panel");
        if (panel) {
            panel.addEventListener("pointerdown", function(e){ }, false);
            panel.addEventListener("touchstart", function(e){ }, { passive: true });
            panel.addEventListener("transitionend", function(){
                if (panel.classList.contains("active")) {
                    if (!window.hamburgerRefreshInterval) {
                        window.hamburgerRefreshInterval = setInterval(window.refreshHamburgerOrder, 2000);
                    }
                } else {
                    if (window.hamburgerRefreshInterval) {
                        clearInterval(window.hamburgerRefreshInterval);
                        window.hamburgerRefreshInterval = null;
                    }
                }
            });
        }
    })();
    
    // Header Animation Background Management
    function initHeaderAnimations() {
        // Simple initialization without complex coordination
        if (window.animationInitializing) {
            console.log("Animation initialization in progress, skipping");
            return;
        }
        
        const animationBg = document.getElementById("headerAnimationBg");
        if (!animationBg) {
            console.log("No animation background element found");
            return;
        }
        
        window.animationInitializing = true;
        
        const animationType = animationBg.dataset.animation;
        const color = animationBg.dataset.color || "#0066aa";
        const speed = parseFloat(animationBg.dataset.speed) || 1.0;
        const scale = parseFloat(animationBg.dataset.scale) || 1.0;
        
        if (animationType === "none") return;
        
        console.log(`Starting header animation: ${animationType}`);
        
        // Mark element to prevent interference
        animationBg.setAttribute("data-vanta-ready", "true");
        
        // Load animation scripts dynamically with retry logic
        loadAnimationScript(animationType).then(() => {
            // Add small delay to ensure script is fully initialized
            setTimeout(() => {
                initVantaAnimation(animationType, animationBg, color, speed, scale);
                window.animationInitializing = false;
            }, 100);
        }).catch(err => {
            console.error("Failed to load animation:", animationType, err);
            window.animationInitializing = false;
            // Try fallback to waves animation
            if (animationType !== "waves") {
                console.log("Falling back to waves animation");
                loadAnimationScript("waves").then(() => {
                    setTimeout(() => {
                        window.animationInitializing = true;
                        initVantaAnimation("waves", animationBg, color, speed, scale);
                        window.animationInitializing = false;
                    }, 100);
                });
            }
        });
    }
    
    function loadAnimationScript(animationType) {
        return new Promise((resolve, reject) => {
            // Load Three.js first if needed
            if (!window.THREE) {
                const threeScript = document.createElement("script");
                threeScript.src = "/templates/assets/animations/three.r134.min.js";
                threeScript.onload = () => {
                    loadVantaScript(animationType).then(resolve).catch(reject);
                };
                threeScript.onerror = reject;
                document.head.appendChild(threeScript);
            } else {
                loadVantaScript(animationType).then(resolve).catch(reject);
            }
        });
    }
    
    function loadVantaScript(animationType) {
        return new Promise((resolve, reject) => {
            // Check if script is already loaded
            const existingScript = document.querySelector(`script[src*="vanta.${animationType}.min.js"]`);
            if (existingScript) {
                console.log(`Animation script ${animationType} already loaded`);
                resolve();
                return;
            }
            
            console.log(`Loading animation script: vanta.${animationType}.min.js`);
            const script = document.createElement("script");
            script.src = `/templates/assets/animations/vanta.${animationType}.min.js`;
            script.onload = () => {
                console.log(`Animation script ${animationType} loaded successfully`);
                // Small delay to ensure VANTA object is fully initialized
                setTimeout(resolve, 50);
            };
            script.onerror = (error) => {
                console.error(`Failed to load animation script: vanta.${animationType}.min.js`, error);
                reject(error);
            };
            document.head.appendChild(script);
        });
    }
    
    function initVantaAnimation(type, element, color, speed, scale) {
        if (!window.VANTA) {
            console.error("VANTA library not loaded");
            return;
        }
        
        if (!window.THREE) {
            console.error("THREE.js library not loaded - required for animations");
            return;
        }
        
        console.log(`Initializing ${type} animation with color: ${color}, speed: ${speed}, scale: ${scale}`);
        
        // Destroy existing animation if it exists
        if (window.currentVantaEffect) {
            try {
                console.log("Destroying previous VANTA animation");
                window.currentVantaEffect.destroy();
                window.currentVantaEffect = null;
            } catch (e) {
                console.warn("Error destroying previous VANTA animation:", e);
            }
        }
        
        // Clear any existing intervals (simplified)
        if (window.vantaProtectionInterval) {
            clearInterval(window.vantaProtectionInterval);
            window.vantaProtectionInterval = null;
        }
        
        // Ensure element exists and is visible
        if (!element || !element.offsetParent) {
            console.warn("Animation element not visible, retrying...");
            setTimeout(() => {
                const retryElement = document.getElementById("headerAnimationBg");
                if (retryElement) {
                    initVantaAnimation(type, retryElement, color, speed, scale);
                }
            }, 500);
            return;
        }
        
        // Store animation metadata for debugging
        window.vantaAnimationMeta = {
            type: type,
            startTime: Date.now(),
            element: element,
            color: color,
            speed: speed,
            scale: scale
        };
        
        // Simplified - no complex protection overrides
        
        // Convert color to proper format for VANTA (hex string to number)
        const vantaColor = typeof color === "string" ? 
            parseInt(color.replace("#", ""), 16) : color;
        
        const baseConfig = {
            el: element,
            mouseControls: true,
            touchControls: true,
            gyroControls: false,
            minHeight: 200.00,
            minWidth: 200.00,
            scale: scale,
            scaleMobile: 1.00
            // Note: color properties are set per animation type as they differ
        };
        
        switch (type) {
            case "waves":
                if (window.VANTA && window.VANTA.WAVES) {
                    console.log("Initializing WAVES animation");
                    try {
                        window.currentVantaEffect = window.VANTA.WAVES({
                            ...baseConfig,
                            color: vantaColor,
                            waveHeight: 20 * scale,
                            waveSpeed: speed,
                            zoom: 0.75
                        });
                    } catch (e) {
                        console.error("WAVES animation failed:", e);
                    }
                } else {
                    console.error("VANTA.WAVES not available");
                }
                break;
            case "net":
                if (window.VANTA && window.VANTA.NET) {
                    console.log("Initializing NET animation");
                    try {
                        window.currentVantaEffect = window.VANTA.NET({
                            ...baseConfig,
                            color: vantaColor,
                            backgroundColor: 0x0,
                            points: Math.max(3, Math.floor(10 * scale)),
                            maxDistance: 20 * scale,
                            spacing: 15 * scale
                        });
                    } catch (e) {
                        console.error("NET animation failed:", e);
                    }
                } else {
                    console.error("VANTA.NET not available");
                }
                break;
            case "dots":
                if (window.VANTA && window.VANTA.DOTS) {
                    console.log("Initializing DOTS animation");
                    try {
                        window.currentVantaEffect = window.VANTA.DOTS({
                            ...baseConfig,
                            color: vantaColor,
                            backgroundColor: 0x0,
                            size: 4 * scale,
                            spacing: 30 * scale
                        });
                    } catch (e) {
                        console.error("DOTS animation failed:", e);
                    }
                } else {
                    console.error("VANTA.DOTS not available");
                }
                break;
            case "particles":
                if (window.VANTA.DOTS) {
                    window.currentVantaEffect = window.VANTA.DOTS({
                        ...baseConfig,
                        size: 4 * scale,
                        spacing: 30 * scale
                    });
                }
                break;
            case "fog":
                if (window.VANTA && window.VANTA.FOG) {
                    console.log("Initializing FOG animation");
                    try {
                        window.currentVantaEffect = window.VANTA.FOG({
                            ...baseConfig,
                            highlightColor: vantaColor,
                            midtoneColor: vantaColor,
                            lowlightColor: vantaColor,
                            baseColor: vantaColor,
                            blurFactor: 0.6,
                            speed: speed,
                            zoom: 1 * scale
                        });
                    } catch (e) {
                        console.error("FOG animation failed:", e);
                    }
                } else {
                    console.error("VANTA.FOG not available");
                }
                break;
            case "birds":
                if (window.VANTA && window.VANTA.BIRDS) {
                    console.log("Initializing BIRDS animation");
                    try {
                        window.currentVantaEffect = window.VANTA.BIRDS({
                            ...baseConfig,
                            color1: vantaColor,
                            color2: vantaColor,
                            colorMode: "variance",
                            quantity: Math.max(1, Math.floor(3 * scale)),
                            birdSize: 1.2 * scale,
                            wingSpan: 25 * scale,
                            speedLimit: 4 * speed,
                            separation: 20,
                            alignment: 20,
                            cohesion: 20
                        });
                    } catch (e) {
                        console.error("BIRDS animation failed:", e);
                    }
                } else {
                    console.error("VANTA.BIRDS not available");
                }
                break;
            case "cells":
                if (window.VANTA && window.VANTA.CELLS) {
                    console.log("Initializing CELLS animation");
                    try {
                        window.currentVantaEffect = window.VANTA.CELLS({
                            ...baseConfig,
                            size: 1.5 * scale,
                            speed: speed,
                            scale: 1.0 * scale
                        });
                    } catch (e) {
                        console.error("CELLS animation failed:", e);
                    }
                } else {
                    console.error("VANTA.CELLS not available");
                }
                break;
            case "clouds":
                if (window.VANTA && window.VANTA.CLOUDS) {
                    console.log("Initializing CLOUDS animation");
                    try {
                        window.currentVantaEffect = window.VANTA.CLOUDS({
                            ...baseConfig,
                            skyColor: color,
                            cloudColor: color,
                            cloudShadowColor: color,
                            sunColor: color,
                            sunGlareColor: color,
                            sunlightColor: color,
                            speed: speed * 0.5
                        });
                    } catch (e) {
                        console.error("CLOUDS animation failed:", e);
                    }
                } else {
                    console.error("VANTA.CLOUDS not available");
                }
                break;
            case "halo":
                if (window.VANTA && window.VANTA.HALO) {
                    console.log("Initializing HALO animation");
                    try {
                        window.currentVantaEffect = window.VANTA.HALO({
                            ...baseConfig,
                            baseColor: vantaColor,
                            backgroundColor: 0x0,
                            amplitudeFactor: 1.0 * scale,
                            xOffset: 0.1,
                            yOffset: 0.1,
                            size: 1.0 * scale
                        });
                    } catch (e) {
                        console.error("HALO animation failed:", e);
                    }
                } else {
                    console.error("VANTA.HALO not available");
                }
                break;
            case "rings":
                if (window.VANTA && window.VANTA.RINGS) {
                    console.log("Initializing RINGS animation");
                    try {
                        window.currentVantaEffect = window.VANTA.RINGS({
                            ...baseConfig,
                            backgroundColor: color,
                            color: color,
                            color2: color
                        });
                    } catch (e) {
                        console.error("RINGS animation failed:", e);
                    }
                } else {
                    console.error("VANTA.RINGS not available");
                }
                break;
            case "ripple":
                if (window.VANTA && window.VANTA.RIPPLE) {
                    console.log("Initializing RIPPLE animation");
                    try {
                        window.currentVantaEffect = window.VANTA.RIPPLE({
                            ...baseConfig,
                            backgroundColor: color,
                            color: color,
                            amplitude: 1.0 * scale,
                            speed: speed
                        });
                    } catch (e) {
                        console.error("RIPPLE animation failed:", e);
                    }
                } else {
                    console.error("VANTA.RIPPLE not available");
                }
                break;
            case "topology":
                if (window.VANTA && window.VANTA.TOPOLOGY) {
                    console.log("Initializing TOPOLOGY animation");
                    try {
                        window.currentVantaEffect = window.VANTA.TOPOLOGY({
                            ...baseConfig,
                            backgroundColor: color,
                            color: color
                        });
                    } catch (e) {
                        console.error("TOPOLOGY animation failed:", e);
                    }
                } else {
                    console.error("VANTA.TOPOLOGY not available");
                }
                break;
        default:
            console.warn("Unsupported animation type:", type);
        }
        
        // DISABLED aggressive protection to prevent loops
        if (window.currentVantaEffect) {
            console.log(`Animation created successfully: ${type}. NO auto-protection to prevent loops.`);
        }
    }    // Debug helper function
    window.debugAnimations = function() {
        console.log("=== Animation Debug Info ===");
        console.log("THREE.js loaded:", !!window.THREE);
        console.log("VANTA loaded:", !!window.VANTA);
        console.log("Current VANTA effect:", !!window.currentVantaEffect);
        console.log("Pre-injection effect:", !!window.preInjectionVantaEffect);
        console.log("Page hidden:", document.hidden);
        
        if (window.vantaAnimationMeta) {
            const uptime = Date.now() - window.vantaAnimationMeta.startTime;
            const uptimeMinutes = Math.floor(uptime / 60000);
            const uptimeSeconds = Math.floor((uptime % 60000) / 1000);
            console.log("Animation metadata:", {
                type: window.vantaAnimationMeta.type,
                uptime: `${uptimeMinutes}m ${uptimeSeconds}s`,
                uptimeMs: uptime,
                element: !!window.vantaAnimationMeta.element,
                hiddenTime: window.vantaAnimationMeta.hiddenTime
            });
        }
        
        if (window.VANTA) {
            const availableAnimations = [];
            ["WAVES", "NET", "DOTS", "FOG", "BIRDS", "CELLS", "CLOUDS", "HALO", "RINGS", "RIPPLE", "TOPOLOGY"].forEach(anim => {
                if (window.VANTA[anim]) availableAnimations.push(anim);
            });
            console.log("Available VANTA animations:", availableAnimations);
        }
        const animationBg = document.getElementById("headerAnimationBg");
        if (animationBg) {
            console.log("Animation container found:", {
                animation: animationBg.dataset.animation,
                color: animationBg.dataset.color,
                speed: animationBg.dataset.speed,
                scale: animationBg.dataset.scale,
                ready: animationBg.getAttribute("data-vanta-ready")
            });
        } else {
            console.log("❌ No animation container found");
        }
    };
    
    // Make initHeaderAnimations available globally
    window.initHeaderAnimations = initHeaderAnimations;
    
    // Page visibility handling to prevent animation timeout issues
    window.handleVisibilityChange = function() {
        if (document.hidden) {
            console.log("🔍 Page hidden, preserving animation state...");
            // Store current animation state but don\'t destroy
            if (window.currentVantaEffect && window.vantaAnimationMeta) {
                window.vantaAnimationMeta.hiddenTime = Date.now();
            }
        } else {
            console.log("🔍 Page visible, checking animation state...");
            // Page became visible again
            if (window.vantaAnimationMeta && window.vantaAnimationMeta.hiddenTime) {
                const hiddenDuration = Date.now() - window.vantaAnimationMeta.hiddenTime;
                console.log(`🔍 Page was hidden for ${Math.floor(hiddenDuration / 1000)}s`);
                delete window.vantaAnimationMeta.hiddenTime;
                
                // If page was hidden for more than 30 seconds, reinitialize animation
                if (hiddenDuration > 30000) {
                    console.log("🔄 Reinitializing animation after long hidden period...");
                    setTimeout(() => {
                        if (!window.currentVantaEffect) {
                            initHeaderAnimations();
                        }
                    }, 1000);
                }
            }
        }
    };
    
    // Animation persistence observer
    window.setupAnimationObserver = function() {
        if (typeof MutationObserver !== "undefined") {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    // Check for removed nodes
                    if (mutation.type === "childList" && mutation.removedNodes.length > 0) {
                        for (let node of mutation.removedNodes) {
                            if (node.nodeType === 1 && (
                                node.classList?.contains("cue-global-header") || 
                                node.querySelector?.(".cue-global-header") ||
                                node.id === "headerAnimationBg"
                            )) {
                                console.log("🔍 Animation container removed, scheduling reinit...");
                                setTimeout(() => {
                                    const newBg = document.getElementById("headerAnimationBg");
                                    if (newBg && !window.currentVantaEffect) {
                                        console.log("🔄 Reinitializing animations after DOM change");
                                        initHeaderAnimations();
                                    }
                                }, 100);
                                break;
                            }
                        }
                    }
                    
                    // Check for added nodes
                    if (mutation.type === "childList" && mutation.addedNodes.length > 0) {
                        for (let node of mutation.addedNodes) {
                            if (node.nodeType === 1 && (
                                node.classList?.contains("cue-global-header") || 
                                node.querySelector?.(".cue-global-header") ||
                                node.id === "headerAnimationBg"
                            )) {
                                console.log("🔍 New header container added");
                                setTimeout(() => {
                                    const newBg = document.getElementById("headerAnimationBg");
                                    if (newBg && newBg.dataset.animation && newBg.dataset.animation !== "none" && !window.currentVantaEffect) {
                                        console.log("🔄 Initializing animations for new container");
                                        initHeaderAnimations();
                                    }
                                }, 200);
                                break;
                            }
                        }
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
            
            console.log("🔍 Animation observer active");
        }
    };
    
    // Initialize global UI components when DOM is ready
    document.addEventListener("DOMContentLoaded", function() {
        console.log("CUE Global UI Components initialized");
        
        // DISABLED animation observer to prevent loops
        // window.setupAnimationObserver();
        
        // DISABLED visibility change monitoring to prevent loops
        // document.addEventListener("visibilitychange", window.handleVisibilityChange);
        
        // Add debug info
        setTimeout(window.debugAnimations, 1000);
        
        // Initialize header animations with delay to avoid conflicts
        setTimeout(() => {
            console.log("Starting header animations after DOM stabilization");
            initHeaderAnimations();
        }, 500);
        
        // Auto-close hamburger menu only when clicking actual anchors
        document.querySelectorAll("a.hamburger-item").forEach(function(link) {
            link.addEventListener("click", function() {
                setTimeout(closeHamburgerMenu, 100);
            });
        });
        
        // SIMPLIFIED animation system - NO automatic monitoring to prevent loops
        console.log("Animation system initialized - manual control only");
        
        // Simple manual check function
        window.manualAnimationCheck = function() {
            const animationBg = document.getElementById("headerAnimationBg");
            if (animationBg && animationBg.dataset.animation && animationBg.dataset.animation !== "none") {
                if (!window.currentVantaEffect) {
                    console.log("Manual check: No animation, need to restart");
                    return false;
                } else {
                    console.log("Manual check: Animation is running");
                    return true;
                }
            }
            return null;
        };

        (function () {
            const btn = document.getElementById("mhHeaderNoticesBtn");
            if (!btn) return;
            const feedUrl = (btn.getAttribute("data-feed") || "/hub/notices.php?ajax=feed").trim();
            const username = (btn.getAttribute("data-user") || "").trim();
            const userKeyStore = "mh_notices_user_key_v1";
            let storageUser = "";
            try { storageUser = (sessionStorage.getItem(userKeyStore) || "").trim(); } catch (e) { storageUser = ""; }
            if (!storageUser) storageUser = username || "guest";
            function k(name) {
                return "mh_notices_" + String(name) + ":" + storageUser;
            }
            function setStorageUser(u) {
                storageUser = (String(u || "").trim() || "guest");
                try { sessionStorage.setItem(userKeyStore, storageUser); } catch (e) {}
            }
            function migrateKeys(fromUser, toUser) {
                const from = (String(fromUser || "").trim() || "guest");
                const to = (String(toUser || "").trim() || "guest");
                if (from === to) return;
                const fromSeen = "mh_notices_seen_at:" + from;
                const toSeen = "mh_notices_seen_at:" + to;
                const fromDismissed = "mh_notices_dismissed:" + from;
                const toDismissed = "mh_notices_dismissed:" + to;
                const fromSnooze = "mh_notices_snooze:" + from;
                const toSnooze = "mh_notices_snooze:" + to;
                const fromPanel = "mh_notices_panel_v1:" + from;
                const toPanel = "mh_notices_panel_v1:" + to;
                try {
                    const fs = getNumLs(fromSeen, 0);
                    const ts = getNumLs(toSeen, 0);
                    if (fs > ts) setNumLs(toSeen, fs);
                } catch (e) {}
                try {
                    const f = getObjLs(fromDismissed);
                    const t = getObjLs(toDismissed);
                    const merged = Object.assign({}, f || {}, t || {});
                    setObjLs(toDismissed, merged);
                } catch (e) {}
                try {
                    const f = getObjLs(fromSnooze);
                    const t = getObjLs(toSnooze);
                    const merged = Object.assign({}, f || {}, t || {});
                    setObjLs(toSnooze, merged);
                } catch (e) {}
                try {
                    if (!localStorage.getItem(toPanel) && localStorage.getItem(fromPanel)) {
                        localStorage.setItem(toPanel, localStorage.getItem(fromPanel) || "");
                    }
                } catch (e) {}
            }

            function nowSec() {
                return Math.floor(Date.now() / 1000);
            }

            function clamp(n, a, b) {
                n = Number(n);
                if (!isFinite(n)) return a;
                if (n < a) return a;
                if (n > b) return b;
                return n;
            }

            function getViewport() {
                try {
                    const vv = window.visualViewport;
                    if (vv && vv.width && vv.height) return { w: vv.width, h: vv.height };
                } catch (e) {}
                return { w: window.innerWidth || 0, h: window.innerHeight || 0 };
            }

            function getNumLs(key, fallback) {
                try {
                    const v = Number(localStorage.getItem(key) || "");
                    return isFinite(v) ? v : fallback;
                } catch (e) {
                    return fallback;
                }
            }

            function setNumLs(key, val) {
                try { localStorage.setItem(key, String(val)); } catch (e) {}
            }

            function getObjLs(key) {
                try {
                    const raw = localStorage.getItem(key);
                    if (!raw) return {};
                    const j = JSON.parse(raw);
                    return j && typeof j === "object" ? j : {};
                } catch (e) {
                    return {};
                }
            }

            function setObjLs(key, obj) {
                try { localStorage.setItem(key, JSON.stringify(obj || {})); } catch (e) {}
            }

            function ensureUi() {
                let root = document.getElementById("mhNoticesGlobalRoot");
                if (root) return root;
                root = document.createElement("div");
                root.id = "mhNoticesGlobalRoot";
                root.className = "mh-notices-global-root";

                const backdrop = document.createElement("div");
                backdrop.id = "mhNoticesBackdrop";
                backdrop.className = "mh-notices-backdrop";

                const panel = document.createElement("div");
                panel.id = "mhNoticesPanel";
                panel.className = "mh-notices-panel";
                panel.setAttribute("role", "dialog");
                panel.setAttribute("aria-modal", "true");

                const h = document.createElement("div");
                h.id = "mhNoticesHeader";
                h.className = "mh-notices-h";
                h.innerHTML =
                    '<div class="mh-notices-title">Notices</div>' +
                    '<div class="mh-notices-actions">' +
                    '<button type="button" class="mh-notices-btn" id="mhNoticesRefreshBtn">Refresh</button>' +
                    '<button type="button" class="mh-notices-btn" id="mhNoticesMarkReadBtn">Mark Read</button>' +
                    '<button type="button" class="mh-notices-btn" id="mhNoticesCloseBtn">Close</button>' +
                    "</div>";

                const b = document.createElement("div");
                b.id = "mhNoticesBody";
                b.className = "mh-notices-b";

                const mkRz = function (dir) {
                    const el = document.createElement("div");
                    el.className = "mh-notices-rz";
                    el.setAttribute("data-dir", dir);
                    el.setAttribute("aria-hidden", "true");
                    return el;
                };

                panel.appendChild(h);
                panel.appendChild(b);
                panel.appendChild(mkRz("n"));
                panel.appendChild(mkRz("s"));
                panel.appendChild(mkRz("e"));
                panel.appendChild(mkRz("w"));
                panel.appendChild(mkRz("ne"));
                panel.appendChild(mkRz("nw"));
                panel.appendChild(mkRz("se"));
                panel.appendChild(mkRz("sw"));
                root.appendChild(backdrop);
                root.appendChild(panel);
                document.body.appendChild(root);
                return root;
            }

            function loadPanelLayout() {
                try {
                    const raw = localStorage.getItem(k("panel_v1"));
                    if (!raw) return null;
                    const j = JSON.parse(raw);
                    if (!j || typeof j !== "object") return null;
                    const x = Number(j.x), y = Number(j.y), w = Number(j.w), h = Number(j.h);
                    if (!isFinite(x) || !isFinite(y) || !isFinite(w) || !isFinite(h)) return null;
                    return { x, y, w, h };
                } catch (e) {
                    return null;
                }
            }

            function savePanelLayout(panel) {
                try {
                    const r = panel.getBoundingClientRect();
                    localStorage.setItem(k("panel_v1"), JSON.stringify({ x: r.left, y: r.top, w: r.width, h: r.height, t: Date.now() }));
                } catch (e) {}
            }

            function applyPanelLayout(panel, layout) {
                const vp = getViewport();
                const margin = 10;
                const minW = 340;
                const minH = 380;
                const maxW = Math.max(minW, (vp.w || 0) - margin * 2);
                const maxH = Math.max(minH, (vp.h || 0) - margin * 2);
                const w = clamp(layout && layout.w, minW, maxW);
                const h = clamp(layout && layout.h, minH, maxH);
                const maxX = Math.max(margin, (vp.w || 0) - w - margin);
                const maxY = Math.max(margin, (vp.h || 0) - h - margin);
                const x = clamp(layout && layout.x, margin, maxX);
                const y = clamp(layout && layout.y, margin, maxY);
                panel.style.left = x + "px";
                panel.style.top = y + "px";
                panel.style.right = "auto";
                panel.style.bottom = "auto";
                panel.style.width = w + "px";
                panel.style.height = h + "px";
            }

            function setDefaultPanel(panel, layoutSide) {
                const vp = getViewport();
                const margin = 20;
                const w = Math.min(560, Math.max(340, (vp.w || 0) - 40));
                const h = Math.max(380, (vp.h || 0) - 40);
                const x = layoutSide === "left" ? margin : Math.max(margin, (vp.w || 0) - w - margin);
                const y = margin;
                applyPanelLayout(panel, { x, y, w, h });
            }

            function fmtTs(ts) {
                ts = Number(ts) || 0;
                if (!ts) return "";
                try { return new Date(ts * 1000).toLocaleString(); } catch (e) { return ""; }
            }

            function isDismissed(id) {
                const d = getObjLs(k("dismissed"));
                return !!(d && d[id]);
            }

            function getSnoozeUntil(id) {
                const s = getObjLs(k("snooze"));
                const v = s ? Number(s[id] || 0) : 0;
                return isFinite(v) ? v : 0;
            }

            function isSnoozed(id) {
                const until = getSnoozeUntil(id);
                return until > nowSec();
            }

            function dismiss(id) {
                const d = getObjLs(k("dismissed"));
                d[id] = nowSec();
                setObjLs(k("dismissed"), d);
                try {
                    fetch("/hub/notices.php?ajax=dismiss", {
                        method: "POST",
                        credentials: "include",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                            "Accept": "application/json",
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: "id=" + encodeURIComponent(String(id || ""))
                    }).catch(function () {});
                } catch (e) {}
            }

            function postNoticeEvent(type, id) {
                try {
                    const body = "type=" + encodeURIComponent(String(type || "")) + "&id=" + encodeURIComponent(String(id || ""));
                    if (navigator && typeof navigator.sendBeacon === "function") {
                        const blob = new Blob([body], { type: "application/x-www-form-urlencoded" });
                        navigator.sendBeacon("/hub/notices.php?ajax=event", blob);
                        return;
                    }
                    fetch("/hub/notices.php?ajax=event", {
                        method: "POST",
                        credentials: "include",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                            "Accept": "application/json",
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: body,
                        keepalive: true
                    }).catch(function () {});
                } catch (e) {}
            }

            function logOnce(type, id) {
                try {
                    const storeKey = k("events_logged_v1");
                    const raw = localStorage.getItem(storeKey);
                    const map = raw ? JSON.parse(raw) : {};
                    if (!map || typeof map !== "object") return;
                    const kk = String(type || "") + "|" + String(id || "");
                    if (!kk || map[kk]) return;
                    map[kk] = 1;
                    localStorage.setItem(storeKey, JSON.stringify(map));
                } catch (e) { return; }
                postNoticeEvent(type, id);
            }

            function snooze(id, days) {
                const s = getObjLs(k("snooze"));
                s[id] = nowSec() + (Number(days) || 0) * 86400;
                setObjLs(k("snooze"), s);
            }

            function clearSnooze(id) {
                const s = getObjLs(k("snooze"));
                if (s && Object.prototype.hasOwnProperty.call(s, id)) {
                    delete s[id];
                    setObjLs(k("snooze"), s);
                }
            }

            function shouldCountAsNew(n, seenAt) {
                const ts = Number(n.ts || 0) || 0;
                if (ts <= 0) return false;
                if (ts <= seenAt) return false;
                const id = String(n.id || "");
                if (!id) return false;
                if (isDismissed(id)) return false;
                if (isSnoozed(id)) return false;
                return true;
            }

            function safeText(s) {
                return s == null ? "" : String(s);
            }

            let state = { feed: null, lastFetchAt: 0, open: false };

            function fetchFeed() {
                return fetch(feedUrl, { credentials: "include", cache: "no-store" })
                    .then(function (r) { return r.text(); })
                    .then(function (t) {
                        try { return JSON.parse(t); } catch (e) { return { ok: false, error: "invalid_json", raw: t }; }
                    })
                    .catch(function () { return { ok: false, error: "request_failed" }; });
            }

            function updateIndicator(feed) {
                const seenAt = getNumLs(k("seen_at"), 0);
                const list = feed && Array.isArray(feed.notices) ? feed.notices : [];
                let newCount = 0;
                for (let i = 0; i < list.length; i++) {
                    if (shouldCountAsNew(list[i] || {}, seenAt)) newCount++;
                }
                if (newCount > 0) btn.classList.add("mh-has-new");
                else btn.classList.remove("mh-has-new");
                if (newCount > 0 && feed && feed.panel && feed.panel.icon_flicker_on_new) btn.classList.add("mh-flicker");
                else btn.classList.remove("mh-flicker");
                return newCount;
            }

            function render(feed) {
                ensureUi();
                const body = document.getElementById("mhNoticesBody");
                const list = feed && Array.isArray(feed.notices) ? feed.notices : [];
                const seenAt = getNumLs(k("seen_at"), 0);
                body.innerHTML = "";

                const visible = [];
                for (let i = 0; i < list.length; i++) {
                    const n = list[i] || {};
                    const id = String(n.id || "");
                    if (!id) continue;
                    if (isDismissed(id)) continue;
                    if (isSnoozed(id)) continue;
                    visible.push(n);
                }
                for (let i = 0; i < visible.length; i++) {
                    const n = visible[i] || {};
                    const id = String(n.id || "");
                    if (!id) continue;
                    logOnce("view", id);
                }

                if (visible.length === 0) {
                    const empty = document.createElement("div");
                    empty.className = "mh-notice";
                    empty.innerHTML = '<div class="mh-notice-title2">No notices</div><div class="mh-notice-body">You are up to date.</div>';
                    body.appendChild(empty);
                    return;
                }

                for (let i = 0; i < visible.length; i++) {
                    const n = visible[i] || {};
                    const id = String(n.id || "");
                    const type = String(n.type || "info");
                    const title = safeText(n.title || "Notice");
                    const txt = safeText(n.body || "");
                    const url = (n.url ? String(n.url) : "").trim();
                    const ts = Number(n.ts || 0) || 0;
                    const pinned = !!n.pinned;
                    const attachments = Array.isArray(n.attachments) ? n.attachments : [];

                    const card = document.createElement("div");
                    card.className = "mh-notice";

                    const top = document.createElement("div");
                    top.className = "mh-notice-top";
                    const ttl = document.createElement("div");
                    ttl.className = "mh-notice-title2";
                    ttl.textContent = title;
                    const meta = document.createElement("div");
                    meta.className = "mh-notice-meta";
                    meta.textContent = fmtTs(ts);
                    top.appendChild(ttl);
                    top.appendChild(meta);

                    const badge = document.createElement("div");
                    badge.className = "mh-notice-badge " + (type === "success" || type === "warning" || type === "error" ? type : "info");
                    badge.textContent = (type || "info").toUpperCase() + (pinned ? " · PINNED" : "");

                    const bodyEl = document.createElement("div");
                    bodyEl.className = "mh-notice-body";
                    bodyEl.textContent = txt;

                    const lines = txt.split("\n").length;
                    const needsMore = txt.length > 360 || lines > 9;
                    if (needsMore) bodyEl.classList.add("mh-collapsed");

                    const actions = document.createElement("div");
                    actions.className = "mh-notice-actions";

                    if (url) {
                        const a = document.createElement("a");
                        a.className = "mh-notice-link";
                        a.href = url;
                        a.textContent = "Open";
                        a.addEventListener("click", function () {
                            logOnce("read", id);
                        });
                        actions.appendChild(a);
                    }

                    if (attachments.length) {
                        for (let k = 0; k < attachments.length; k++) {
                            const att = attachments[k] || {};
                            const pu = (att.preview_url ? String(att.preview_url) : "").trim();
                            const nm = (att.name ? String(att.name) : "Attachment").trim();
                            if (!pu) continue;
                            const a2 = document.createElement("a");
                            a2.className = "mh-notice-link2";
                            try {
                                const sep = pu.indexOf("?") === -1 ? "?" : "&";
                                a2.href = pu + sep + "r=" + encodeURIComponent(window.location.href);
                            } catch (e) {
                                a2.href = pu;
                            }
                            a2.target = "_blank";
                            a2.rel = "noopener";
                            a2.textContent = "Read: " + nm;
                            a2.addEventListener("click", function () {
                                logOnce("read", id);
                            });
                            actions.appendChild(a2);
                        }
                    }

                    if (needsMore) {
                        const more = document.createElement("button");
                        more.type = "button";
                        more.className = "mh-notices-btn";
                        more.textContent = "Read more";
                        more.addEventListener("click", function () {
                            if (bodyEl.classList.contains("mh-collapsed")) {
                                bodyEl.classList.remove("mh-collapsed");
                                more.textContent = "Show less";
                                logOnce("read", id);
                            } else {
                                bodyEl.classList.add("mh-collapsed");
                                more.textContent = "Read more";
                            }
                        });
                        actions.appendChild(more);
                    }

                    const dismissBtn = document.createElement("button");
                    dismissBtn.type = "button";
                    dismissBtn.className = "mh-notices-btn";
                    dismissBtn.textContent = "Dismiss";
                    dismissBtn.addEventListener("click", function () {
                        dismiss(id);
                        render(state.feed);
                        updateIndicator(state.feed);
                    });
                    actions.appendChild(dismissBtn);

                    const remindSel = document.createElement("select");
                    remindSel.className = "mh-notice-select";
                    remindSel.innerHTML =
                        '<option value="">Remind…</option>' +
                        '<option value="1">1 day</option>' +
                        '<option value="2">2 days</option>' +
                        '<option value="4">4 days</option>' +
                        '<option value="7">7 days</option>' +
                        '<option value="15">15 days</option>' +
                        '<option value="30">30 days</option>' +
                        '<option value="0">Clear remind</option>';
                    remindSel.addEventListener("change", function () {
                        const v = String(remindSel.value || "");
                        if (v === "") return;
                        if (v === "0") {
                            clearSnooze(id);
                        } else {
                            snooze(id, Number(v));
                        }
                        remindSel.value = "";
                        render(state.feed);
                        updateIndicator(state.feed);
                    });
                    actions.appendChild(remindSel);

                    if (shouldCountAsNew(n, seenAt)) {
                        const nBadge = document.createElement("span");
                        nBadge.className = "mh-notice-badge warning";
                        nBadge.textContent = "NEW";
                        actions.appendChild(nBadge);
                    }

                    card.appendChild(top);
                    card.appendChild(badge);
                    card.appendChild(bodyEl);
                    card.appendChild(actions);
                    body.appendChild(card);
                }
            }

            function openPanel() {
                ensureUi();
                const backdrop = document.getElementById("mhNoticesBackdrop");
                const panel = document.getElementById("mhNoticesPanel");
                backdrop.classList.add("mh-open");
                panel.classList.add("mh-open");
                state.open = true;
                const lay = loadPanelLayout();
                if (lay) applyPanelLayout(panel, lay);
                else {
                    const side = state.feed && state.feed.panel && state.feed.panel.layout ? String(state.feed.panel.layout) : "right";
                    setDefaultPanel(panel, side);
                }
            }

            function closePanel() {
                const backdrop = document.getElementById("mhNoticesBackdrop");
                const panel = document.getElementById("mhNoticesPanel");
                if (backdrop) backdrop.classList.remove("mh-open");
                if (panel) panel.classList.remove("mh-open");
                if (panel) savePanelLayout(panel);
                state.open = false;
            }

            function markRead(feed) {
                const ts = feed && isFinite(Number(feed.max_ts)) ? Number(feed.max_ts) : nowSec();
                setNumLs(k("seen_at"), Math.max(getNumLs(k("seen_at"), 0), Math.floor(ts)));
            }

            function wireDragResize() {
                ensureUi();
                const header = document.getElementById("mhNoticesHeader");
                const panel = document.getElementById("mhNoticesPanel");
                const handles = panel ? panel.querySelectorAll(".mh-notices-rz[data-dir]") : null;
                if (!header || !panel || !handles || !handles.length) return;

                const drag = { active: false, pid: null, sx: 0, sy: 0, bx: 0, by: 0 };
                header.addEventListener("pointerdown", function (e) {
                    try {
                        if (e.target && typeof e.target.closest === "function") {
                            if (e.target.closest("button,a,select,input,textarea")) return;
                        }
                    } catch (e0) {}
                    drag.active = true;
                    drag.pid = e.pointerId;
                    drag.sx = e.clientX;
                    drag.sy = e.clientY;
                    const r = panel.getBoundingClientRect();
                    drag.bx = r.left;
                    drag.by = r.top;
                    try { header.setPointerCapture(e.pointerId); } catch (e2) {}
                });
                header.addEventListener("pointermove", function (e) {
                    if (!drag.active || drag.pid !== e.pointerId) return;
                    applyPanelLayout(panel, { x: drag.bx + (e.clientX - drag.sx), y: drag.by + (e.clientY - drag.sy), w: panel.getBoundingClientRect().width, h: panel.getBoundingClientRect().height });
                });
                header.addEventListener("pointerup", function (e) {
                    if (!drag.active || drag.pid !== e.pointerId) return;
                    drag.active = false;
                    drag.pid = null;
                    savePanelLayout(panel);
                });

                const rz = { active: false, pid: null, sx: 0, sy: 0, bx: 0, by: 0, bw: 0, bh: 0, dir: "" };
                function onRzDown(e) {
                    const dir = (e.currentTarget && e.currentTarget.getAttribute) ? String(e.currentTarget.getAttribute("data-dir") || "") : "";
                    if (!dir) return;
                    rz.active = true;
                    rz.pid = e.pointerId;
                    rz.dir = dir;
                    rz.sx = e.clientX;
                    rz.sy = e.clientY;
                    const r = panel.getBoundingClientRect();
                    rz.bx = r.left;
                    rz.by = r.top;
                    rz.bw = r.width;
                    rz.bh = r.height;
                    try { e.currentTarget.setPointerCapture(e.pointerId); } catch (e2) {}
                }
                function onRzMove(e) {
                    if (!rz.active || rz.pid !== e.pointerId) return;
                    const dx = e.clientX - rz.sx;
                    const dy = e.clientY - rz.sy;
                    let x = rz.bx;
                    let y = rz.by;
                    let w = rz.bw;
                    let h = rz.bh;
                    if (rz.dir.indexOf("e") !== -1) w = rz.bw + dx;
                    if (rz.dir.indexOf("s") !== -1) h = rz.bh + dy;
                    if (rz.dir.indexOf("w") !== -1) { x = rz.bx + dx; w = rz.bw - dx; }
                    if (rz.dir.indexOf("n") !== -1) { y = rz.by + dy; h = rz.bh - dy; }
                    applyPanelLayout(panel, { x: x, y: y, w: w, h: h });
                }
                function onRzUp(e) {
                    if (!rz.active || rz.pid !== e.pointerId) return;
                    rz.active = false;
                    rz.pid = null;
                    rz.dir = "";
                    savePanelLayout(panel);
                }
                handles.forEach(function (el) {
                    el.addEventListener("pointerdown", onRzDown);
                    el.addEventListener("pointermove", onRzMove);
                    el.addEventListener("pointerup", onRzUp);
                });
            }

            function wireControls() {
                ensureUi();
                const backdrop = document.getElementById("mhNoticesBackdrop");
                const closeBtn = document.getElementById("mhNoticesCloseBtn");
                const markBtn = document.getElementById("mhNoticesMarkReadBtn");
                const refreshBtn = document.getElementById("mhNoticesRefreshBtn");
                if (backdrop) backdrop.addEventListener("click", closePanel);
                if (closeBtn) closeBtn.addEventListener("click", closePanel);
                if (markBtn) markBtn.addEventListener("click", function () {
                    if (state.feed) markRead(state.feed);
                    updateIndicator(state.feed);
                    closePanel();
                });
                if (refreshBtn) refreshBtn.addEventListener("click", function () {
                    init(true);
                });
                document.addEventListener("keydown", function (e) {
                    if (e.key === "Escape") closePanel();
                });
            }

            function init(force) {
                const refresh = force || (Date.now() - state.lastFetchAt) > 30000;
                if (!refresh && state.feed) return Promise.resolve(state.feed);
                return fetchFeed().then(function (data) {
                    state.lastFetchAt = Date.now();
                    if (!data || data.ok !== true) {
                        state.feed = {
                            ok: false,
                            notices: [
                                {
                                    id: "notices_feed_error",
                                    ts: nowSec(),
                                    type: "error",
                                    title: "Notices unavailable",
                                    body: "Unable to load notices feed.",
                                    url: "/hub/notices.php?ajax=feed",
                                    pinned: true,
                                },
                            ],
                            panel: { enabled: true, auto_open_seconds: 0, layout: "right", icon_flicker_on_new: false }
                        };
                        updateIndicator(state.feed);
                        if (state.open) render(state.feed);
                        return state.feed;
                    }
                    try {
                        const feedUser = data && data.user && data.user.username ? String(data.user.username).trim() : "";
                        if (feedUser && feedUser !== storageUser) {
                            migrateKeys(storageUser, feedUser);
                            setStorageUser(feedUser);
                        }
                    } catch (e) {}
                    state.feed = data;
                    const newCount = updateIndicator(state.feed);
                    if (state.open) render(state.feed);
                    if (newCount > 0 && data.panel && Number(data.panel.auto_open_seconds || 0) > 0) {
                        setTimeout(function () {
                            if (state.open) return;
                            init(false).then(function (d2) {
                                const nc = updateIndicator(d2);
                                if (nc > 0) {
                                    openPanel();
                                    render(d2);
                                }
                            });
                        }, Number(data.panel.auto_open_seconds) * 1000);
                    }
                    return state.feed;
                });
            }

            btn.addEventListener("click", function () {
                init(false).then(function (feed) {
                    openPanel();
                    render(feed);
                    markRead(feed);
                    updateIndicator(feed);
                });
            });

            wireDragResize();
            wireControls();
            init(true);
            setInterval(function () { init(false); }, 60000);
        })();
        
        // DISABLED all automatic monitoring to prevent loops
    });
</script>
JS;
}



/**
 * Render Global Theme
 * @param array $config Optional configuration override
 * @return void
 */
function renderGlobalTheme($config = []) {
    // Load theme configuration
    $themeConfigFile = getDataPath() . '/theme/config.json';
    $themeConfig = [];
    
    if (file_exists($themeConfigFile)) {
        $savedConfig = json_decode(file_get_contents($themeConfigFile), true);
        if ($savedConfig && isset($savedConfig['K::ThemeUI::Configuration'])) {
            $configKeys = array_keys($savedConfig['K::ThemeUI::Configuration']);
            if (!empty($configKeys)) {
                $themeConfig = $savedConfig['K::ThemeUI::Configuration'][$configKeys[0]];
            }
        }
    }
    
    // Merge any override config
    $themeConfig = array_merge($themeConfig, $config);
    
    // Generate and include theme CSS
    generateGlobalThemeCSS($themeConfig);
}

/**
 * Generate Global Theme CSS
 * @param array $themeConfig Theme configuration array
 * @return void
 */
function generateGlobalThemeCSS($themeConfig = []) {
    echo '<style id="global-theme-css">';
    
    $primary = (string)($themeConfig['thm_primary_color'] ?? '#00ffff');
    $secondary = (string)($themeConfig['thm_secondary_color'] ?? '#0080ff');
    $background = (string)($themeConfig['thm_background_color'] ?? '#1a1a1a');
    $surface = (string)($themeConfig['thm_surface_color'] ?? '#222222');
    $text = (string)($themeConfig['thm_text_color'] ?? '#00ffff');
    $heading = (string)($themeConfig['thm_heading_color'] ?? $text);
    $accent = (string)($themeConfig['thm_accent_color'] ?? '#ff6600');
    $textSecondary = (string)($themeConfig['thm_text_secondary'] ?? 'rgba(0, 255, 255, 0.7)');

    $hexToRgb = function(string $hex): array {
        $hex = trim($hex);
        if ($hex === '') {
            return [0, 0, 0];
        }
        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    };

    $pRgb = $hexToRgb($primary);
    $sRgb = $hexToRgb($secondary);
    $aRgb = $hexToRgb($accent);
    $bgRgb = $hexToRgb($background);
    $sfRgb = $hexToRgb($surface);
    $tRgb = $hexToRgb($text);

    echo ':root{';
    echo '--theme-primary:' . $primary . ';';
    echo '--theme-secondary:' . $secondary . ';';
    echo '--theme-accent:' . $accent . ';';
    echo '--theme-background:' . $background . ';';
    echo '--theme-surface:' . $surface . ';';
    echo '--theme-text:' . $text . ';';
    echo '--theme-text_secondary:' . $textSecondary . ';';
    echo '--theme-heading:' . $heading . ';';
    echo '--theme-dark-bg:' . $background . ';';
    echo '--theme-darker-bg:' . $surface . ';';
    echo '--theme-light-text:' . $text . ';';
    echo '--theme-gray-text:' . $textSecondary . ';';
    echo '--theme-primary-rgb:' . (int)$pRgb[0] . ',' . (int)$pRgb[1] . ',' . (int)$pRgb[2] . ';';
    echo '--theme-secondary-rgb:' . (int)$sRgb[0] . ',' . (int)$sRgb[1] . ',' . (int)$sRgb[2] . ';';
    echo '--theme-accent-rgb:' . (int)$aRgb[0] . ',' . (int)$aRgb[1] . ',' . (int)$aRgb[2] . ';';
    echo '--theme-background-rgb:' . (int)$bgRgb[0] . ',' . (int)$bgRgb[1] . ',' . (int)$bgRgb[2] . ';';
    echo '--theme-surface-rgb:' . (int)$sfRgb[0] . ',' . (int)$sfRgb[1] . ',' . (int)$sfRgb[2] . ';';
    echo '--theme-text-rgb:' . (int)$tRgb[0] . ',' . (int)$tRgb[1] . ',' . (int)$tRgb[2] . ';';
    echo '--primary-color:var(--theme-primary);';
    echo '--secondary-color:var(--theme-secondary);';
    echo '--background-color:var(--theme-background);';
    echo '--surface-color:var(--theme-surface);';
    echo '--text-color:var(--theme-text);';
    echo '--accent-color:var(--theme-accent);';
    echo '}';
    
    // Apply theme colors to body and common elements
    echo 'body { ';
    echo 'background-color: var(--background-color) !important; ';
    echo 'color: var(--text-color) !important; ';
    if (isset($themeConfig['thm_font_family'])) {
        $fontMap = [
            'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            'segoe' => '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif',
            'roboto' => '"Roboto", sans-serif',
            'inter' => '"Inter", sans-serif',
            'monospace' => '"Fira Code", Consolas, Monaco, monospace'
        ];
        $fontFamily = $fontMap[$themeConfig['thm_font_family']] ?? $fontMap['system'];
        echo 'font-family: ' . $fontFamily . ' !important; ';
    }
    if (isset($themeConfig['thm_font_size_pixels'])) {
        echo 'font-size: ' . (int)$themeConfig['thm_font_size_pixels'] . 'px !important; ';
    }
    if (isset($themeConfig['thm_line_height_ratio'])) {
        echo 'line-height: ' . (float)$themeConfig['thm_line_height_ratio'] . ' !important; ';
    }
    echo '}';
    
    // Layout mode styles
    if (isset($themeConfig['thm_layout_mode'])) {
        switch($themeConfig['thm_layout_mode']) {
            case 'fixed':
                echo '.main-content { max-width: ' . ($themeConfig['thm_content_max_width_pixels'] ?? 1200) . 'px; margin: 0 auto; }';
                break;
            case 'boxed':
                echo '.main-content { max-width: ' . ($themeConfig['thm_content_max_width_pixels'] ?? 1200) . 'px; margin: 0 auto; padding: 0 20px; }';
                break;
            case 'full':
                echo '.main-content { width: 100vw; margin: 0; padding: 0; }';
                break;
            case 'fluid':
            default:
                echo '.main-content { width: 100%; max-width: none; }';
                break;
        }
    }
    
    // Visual effects
    if ($themeConfig['thm_glassmorphism_enabled'] ?? false) {
        echo '.cue-global-header, .cue-global-footer { backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }';
    }
    
    if ($themeConfig['thm_rounded_corners_enabled'] ?? false) {
        echo '.form-input, .form-select, .form-button, .card, .panel { border-radius: 8px; }';
    }
    
    if ($themeConfig['thm_shadows_enabled'] ?? false) {
        echo '.cue-global-header, .cue-global-footer, .card, .panel { box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }';
    }
    
    if ($themeConfig['thm_gradient_backgrounds_enabled'] ?? false) {
        echo 'body { background: linear-gradient(135deg, var(--background-color) 0%, var(--surface-color) 100%) !important; }';
    }

    echo '.dropdown,.form-select,select{background-color:var(--theme-surface) !important;color:var(--theme-primary) !important;border:1px solid rgba(var(--theme-primary-rgb),0.3);border-radius:8px;padding:12px 15px;transition:all 0.3s ease}';
    echo '.dropdown:hover,.form-select:hover,select:hover,.dropdown:focus,.form-select:focus,select:focus{background-color:rgba(var(--theme-surface-rgb),0.92) !important;border-color:var(--theme-primary) !important;box-shadow:0 0 15px rgba(var(--theme-primary-rgb),0.4);outline:none}';
    echo '.dropdown option,.form-select option,select option{background-color:var(--theme-surface) !important;color:var(--theme-primary) !important}';

    echo '.glassmorphism{background:rgba(255,255,255,0.05);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.2);border-radius:15px}';
    echo '.glassmorphism-primary{background:rgba(var(--theme-primary-rgb),0.05);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(var(--theme-primary-rgb),0.2);border-radius:15px}';
    echo '.glassmorphism-dark{background:rgba(0,0,0,0.3);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.1);border-radius:15px}';
    
    // Dark mode handling
    if ($themeConfig['thm_dark_mode_enabled'] ?? true) {
        echo 'body.dark-mode { filter: invert(0); }';
    } else {
        echo 'body { filter: invert(1) hue-rotate(180deg); }';
        echo 'img, video, iframe { filter: invert(1) hue-rotate(180deg); }';
    }
    
    // Status bar widget styles
    echo '.status-bar-widget { backdrop-filter: blur(10px); background: rgba(var(--theme-surface-rgb), 0.8) !important; border: 1px solid rgba(var(--theme-primary-rgb), 0.3); border-radius: 5px; box-shadow: 0 0 10px rgba(var(--theme-primary-rgb), 0.2); margin: 5px 0; font-family: \'Rajdhani-Regular\', sans-serif; }';
    echo '.status-bar-widget span { margin-right: 15px; }';
    echo '.status-bar-widget a:hover { text-decoration: underline; }';
    echo '@media (max-width: 768px) { .status-bar-widget { flex-direction: column; align-items: flex-start; font-size: 11px !important; padding: 6px 8px !important; } .status-bar-widget span { margin-right: 0; margin-bottom: 5px; display: block; width: 100%; } }';
    
    echo '</style>';
}

?>
