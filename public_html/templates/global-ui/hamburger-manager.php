<?php
/**
 * Advanced Hamburger Menu Settings Manager
 * @requires CUE Framework
 */
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
$isAjaxRequest = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';
mh_kripz_require('hamburger-manager', $isAjaxRequest);

// Start Session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load global UI functions
$functionsFile = __DIR__ . '/functions.php';
if (file_exists($functionsFile)) {
    require_once $functionsFile;
}

$configPath = cue_autoload('paths')->getSecureFilePath('global-ui/hamburger/hamburger-config.json', false);
$config = [];

// Validate path security (CUE compliance)
$dataBase = cue_autoload('paths')->getDataPath();
if (!validateSecurePath($configPath, $dataBase)) {
    $configPath = null;
}

// Load existing config
if($configPath && file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true) ?: [];
}

// Handle file browsing request - MUST BE FIRST, before any output
if(isset($_POST['action']) && $_POST['action'] === 'browse_files') {
    // Clear any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $requestedPath = $_POST['path'] ?? '/public_html';
        // Resolve base path via CUE and validate
        $basePath = function_exists('getPublicPath') ? rtrim(getPublicPath(), DIRECTORY_SEPARATOR) : dirname(dirname(__DIR__));
        $cleanPath = str_replace('/public_html', '', $requestedPath);
        $fullPath = $basePath . $cleanPath;
        
        // Security check - ensure path is within public_html
        if (!validateSecurePath($fullPath, $basePath)) {
            echo json_encode(['success' => false, 'error' => 'Access denied: Invalid path']);
            exit;
        }
        $realPath = realpath($fullPath);
        if (!$realPath) {
            echo json_encode(['success' => false, 'error' => 'Path not found']);
            exit;
        }
        
        if (!is_dir($realPath)) {
            echo json_encode(['success' => false, 'error' => 'Directory not found: ' . $requestedPath]);
            exit;
        }
    
        $files = [];
        $items = scandir($realPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $realPath . '/' . $item;
            $isDirectory = is_dir($itemPath);
            
            $files[] = [
                'name' => $item,
                'type' => $isDirectory ? 'directory' : 'file'
            ];
        }
        
        // Sort directories first, then files
        usort($files, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        echo json_encode([
            'success' => true,
            'files' => $files,
            'currentPath' => $requestedPath
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error reading directory: ' . $e->getMessage()]);
    }
    
    exit;
}

// Handle form submission
if(isset($_POST['action']) && $_POST['action'] === 'save_hamburger') {
    $configId = 'K::HamburgerUI::Content::' . strtoupper(uniqid());
    $newConfig = [
        'K::HamburgerUI::Configuration' => [
            $configId => [
                // Core Functionality
                'hbg_enabled' => isset($_POST['hbg_enabled']),
                'hbg_position' => $_POST['hbg_position'] ?? 'right',
                'hbg_animation_type' => $_POST['hbg_animation_type'] ?? 'slide',
                'hbg_menu_style' => $_POST['hbg_menu_style'] ?? 'modern',
                'hbg_dropdown_style' => $_POST['hbg_dropdown_style'] ?? 'glassmorphism',
                'hbg_responsive_enabled' => isset($_POST['hbg_responsive_enabled']),
                'hbg_mobile_only' => isset($_POST['hbg_mobile_only']),
                
                // Visual Customization
                'hbg_background_color' => $_POST['hbg_background_color'] ?? '#1a1a2e',
                'hbg_menu_item_color' => $_POST['hbg_menu_item_color'] ?? '#ffffff',
                'hbg_hover_color' => $_POST['hbg_hover_color'] ?? '#00ffff',
                'hbg_active_color' => $_POST['hbg_active_color'] ?? '#0080ff',
                'hbg_text_color' => $_POST['hbg_text_color'] ?? '#ffffff',
                'hbg_icon_color' => $_POST['hbg_icon_color'] ?? '#00ffff',
                
                // Size and Behavior
                'hbg_panel_width' => (int)($_POST['hbg_panel_width'] ?? 320),
                'hbg_panel_height' => (int)($_POST['hbg_panel_height'] ?? 400),
                'hbg_trigger_size' => (int)($_POST['hbg_trigger_size'] ?? 50),
                'hbg_trigger_offset' => (int)($_POST['hbg_trigger_offset'] ?? 20),
                'hbg_trigger_vertical_align' => $_POST['hbg_trigger_vertical_align'] ?? 'top',
                'hbg_bar_width' => (int)($_POST['hbg_bar_width'] ?? 25),
                'hbg_bar_height' => (int)($_POST['hbg_bar_height'] ?? 3),
                'hbg_bar_gap' => (int)($_POST['hbg_bar_gap'] ?? 4),
                'hbg_panel_offset' => (int)($_POST['hbg_panel_offset'] ?? 10),
                'hbg_panel_y_offset' => (int)($_POST['hbg_panel_y_offset'] ?? 0),
                'hbg_panel_bottom_padding' => (int)($_POST['hbg_panel_bottom_padding'] ?? 0),
                'hbg_menu_bottom_padding' => (int)($_POST['hbg_menu_bottom_padding'] ?? 20),
                'hbg_close_outside' => isset($_POST['hbg_close_outside']),
                'hbg_close_esc' => isset($_POST['hbg_close_esc']),
                'hbg_backdrop_enabled' => isset($_POST['hbg_backdrop_enabled']),
                
                // Logo Integration
                'hbg_logo_enabled' => isset($_POST['hbg_logo_enabled']),
                'hbg_logo_image_path' => $_POST['hbg_logo_image_path'] ?? '',
                'hbg_logo_width' => (int)($_POST['hbg_logo_width'] ?? 60),
                'hbg_logo_height' => (int)($_POST['hbg_logo_height'] ?? 60),
                'hbg_logo_position' => $_POST['hbg_logo_position'] ?? 'top-center',
                'hbg_logo_animation_enabled' => isset($_POST['hbg_logo_animation_enabled']),
                'hbg_logo_animation_type' => $_POST['hbg_logo_animation_type'] ?? 'none',
                'hbg_logo_glow_enabled' => isset($_POST['hbg_logo_glow_enabled']),
                'hbg_logo_glow_color' => $_POST['hbg_logo_glow_color'] ?? '#00ffff',
                'hbg_logo_glow_intensity' => (int)($_POST['hbg_logo_glow_intensity'] ?? 5),
                'hbg_logo_glow_size' => (int)($_POST['hbg_logo_glow_size'] ?? 10),
                'hbg_logo_glow_intensity' => (int)($_POST['hbg_logo_glow_intensity'] ?? 5),
                'hbg_logo_glow_size' => (int)($_POST['hbg_logo_glow_size'] ?? 10),
                
                // Typography
                'hbg_heading_enabled' => isset($_POST['hbg_heading_enabled']),
                'hbg_heading_text' => $_POST['hbg_heading_text'] ?? 'CUE Framework',
                'hbg_heading_font' => $_POST['hbg_heading_font'] ?? 'Orbitron-Bold',
                'hbg_heading_size' => (int)($_POST['hbg_heading_size'] ?? 24),
                'hbg_heading_color' => $_POST['hbg_heading_color'] ?? '#00ffff',
                'hbg_subheading_enabled' => isset($_POST['hbg_subheading_enabled']),
                'hbg_subheading_text' => $_POST['hbg_subheading_text'] ?? 'Advanced Framework',
                'hbg_subheading_font' => $_POST['hbg_subheading_font'] ?? 'Rajdhani-Regular',
                'hbg_subheading_size' => (int)($_POST['hbg_subheading_size'] ?? 16),
                'hbg_subheading_color' => $_POST['hbg_subheading_color'] ?? '#00ffff',
                
                // Dividers
                'hbg_divider_enabled' => isset($_POST['hbg_divider_enabled']),
                'hbg_divider_style' => $_POST['hbg_divider_style'] ?? 'solid',
                'hbg_divider_thickness' => (int)($_POST['hbg_divider_thickness'] ?? 1),
                'hbg_divider_color' => $_POST['hbg_divider_color'] ?? '#333333',
                'hbg_divider_variant' => $_POST['hbg_divider_variant'] ?? 'standard',
                'hbg_divider_curvature' => (int)($_POST['hbg_divider_curvature'] ?? 0),
                'hbg_realm_dividers_enabled' => isset($_POST['hbg_realm_dividers_enabled']),
                'hbg_realm_divider_show_top' => isset($_POST['hbg_realm_divider_show_top']),
                'hbg_realm_divider_show_bottom' => isset($_POST['hbg_realm_divider_show_bottom']),
                'hbg_realm_divider_style' => $_POST['hbg_realm_divider_style'] ?? 'solid',
                'hbg_realm_divider_thickness' => (int)($_POST['hbg_realm_divider_thickness'] ?? 1),
                'hbg_realm_divider_color' => $_POST['hbg_realm_divider_color'] ?? '#333333',
                'hbg_realm_divider_curvature' => (int)($_POST['hbg_realm_divider_curvature'] ?? 0),
                'hbg_realm_divider_spacing' => (int)($_POST['hbg_realm_divider_spacing'] ?? 8),
                
                // Social Media Footer
                'hbg_social_enabled' => isset($_POST['hbg_social_enabled']),
                'hbg_social_from_navigator' => isset($_POST['hbg_social_from_navigator']),
                'hbg_social_icons_size' => (int)($_POST['hbg_social_icons_size'] ?? 24),
                'hbg_social_link_padding_y' => (int)($_POST['hbg_social_link_padding_y'] ?? 6),
                'hbg_social_link_padding_x' => (int)($_POST['hbg_social_link_padding_x'] ?? 10),
                'hbg_social_link_font_size' => (int)($_POST['hbg_social_link_font_size'] ?? 12),
                
                // Menu Items (from navigator.php or custom)
                'hbg_menu_source' => $_POST['hbg_menu_source'] ?? 'navigator',
                'hbg_custom_menu_items' => $_POST['hbg_custom_menu_items'] ?? '',
                
                'hbg_last_updated' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    
    // Resolve secure write path and validate
    $configPathWrite = cue_autoload('paths')->getSecureFilePath('global-ui/hamburger/hamburger-config.json', true);
    $dataBaseWrite = cue_autoload('paths')->getDataPath();
    if (!validateSecurePath($configPathWrite, $dataBaseWrite)) {
        $errorMessage = '❌ Error saving hamburger menu settings. Secure path validation failed.';
    } elseif (file_put_contents($configPathWrite, json_encode($newConfig, JSON_PRETTY_PRINT))) {
        // Clear file cache and force reload
        clearstatcache();
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPathWrite, true);
        }
        
        // Cache clearing removed - no caching used
        
        $config = $newConfig;
        $saveMessage = '✅ Advanced hamburger menu settings saved successfully! Cache cleared.';
    } else {
        $errorMessage = '❌ Error saving hamburger menu settings. Please check file permissions.';
    }
}

// Extract current configuration from CUE structure
$currentConfig = [];
if (!empty($config) && isset($config['K::HamburgerUI::Configuration'])) {
    $configKeys = array_keys($config['K::HamburgerUI::Configuration']);
    if (!empty($configKeys)) {
        $currentConfig = $config['K::HamburgerUI::Configuration'][$configKeys[0]];
    }
}

// Set defaults if config is empty
if(empty($currentConfig)) {
    $currentConfig = [
        'hbg_enabled' => true,
        'hbg_position' => 'right',
        'hbg_animation_type' => 'slide',
        'hbg_menu_style' => 'modern',
        'hbg_dropdown_style' => 'glassmorphism',
        'hbg_responsive_enabled' => true,
        'hbg_mobile_only' => false,
        'hbg_background_color' => '#1a1a2e',
        'hbg_menu_item_color' => '#ffffff',
        'hbg_hover_color' => '#00ffff',
        'hbg_active_color' => '#0080ff',
        'hbg_text_color' => '#ffffff',
        'hbg_icon_color' => '#00ffff',
        'hbg_panel_width' => 320,
        'hbg_panel_height' => 400,
        'hbg_trigger_size' => 50,
        'hbg_trigger_offset' => 20,
        'hbg_trigger_vertical_align' => 'top',
        'hbg_bar_width' => 25,
        'hbg_bar_height' => 3,
        'hbg_bar_gap' => 4,
        'hbg_panel_offset' => 10,
        'hbg_panel_y_offset' => 0,
        'hbg_panel_bottom_padding' => 0,
        'hbg_menu_bottom_padding' => 20,
        'hbg_close_outside' => true,
        'hbg_close_esc' => true,
        'hbg_backdrop_enabled' => true,
        'hbg_logo_enabled' => false,
        'hbg_heading_enabled' => true,
        'hbg_heading_text' => 'CUE Framework',
        'hbg_heading_font' => 'Orbitron-Bold',
        'hbg_heading_size' => 24,
        'hbg_heading_color' => '#00ffff',
        'hbg_subheading_enabled' => false,
        'hbg_divider_enabled' => true,
        'hbg_divider_style' => 'solid',
        'hbg_divider_thickness' => 1,
        'hbg_divider_color' => '#333333',
        'hbg_realm_dividers_enabled' => true,
        'hbg_realm_divider_show_top' => true,
        'hbg_realm_divider_show_bottom' => true,
        'hbg_realm_divider_style' => 'solid',
        'hbg_realm_divider_thickness' => 1,
        'hbg_realm_divider_color' => '#333333',
        'hbg_realm_divider_curvature' => 0,
        'hbg_realm_divider_spacing' => 8,
        'hbg_social_enabled' => false,
        'hbg_social_icons_size' => 16,
        'hbg_social_link_padding_y' => 6,
        'hbg_social_link_padding_x' => 10,
        'hbg_social_link_font_size' => 12,
        'hbg_menu_source' => 'navigator'
    ];
}

// Available font families
$availableFonts = [
    'Merriweather-Regular' => 'Merriweather Regular',
    'Merriweather-Bold' => 'Merriweather Bold',
    'Orbitron-Regular' => 'Orbitron Regular',
    'Orbitron-Bold' => 'Orbitron Bold',
    'Rajdhani-Regular' => 'Rajdhani Regular',
    'Rajdhani-Bold' => 'Rajdhani Bold',
    'Inter-Regular' => 'Inter Regular',
    'Inter-Bold' => 'Inter Bold',
    'Lato-Regular' => 'Lato Regular',
    'Lato-Bold' => 'Lato Bold',
    'Montserrat-Regular' => 'Montserrat Regular',
    'Montserrat-Bold' => 'Montserrat Bold',
    'Poppins-Regular' => 'Poppins Regular',
    'Poppins-Bold' => 'Poppins Bold',
    'Roboto-Regular' => 'Roboto Regular',
    'Roboto-Bold' => 'Roboto Bold',
    'Open-Sans-Regular' => 'Open Sans Regular',
    'Open-Sans-Bold' => 'Open Sans Bold'
];

// Check if this file is being accessed directly (not included)
$isStandalone = !isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED']);
if ($isStandalone) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Advanced Hamburger Menu Manager - CUE Framework</title>';
    include_once __DIR__ . '/includes/complete-head.php';
    echo '<link rel="stylesheet" href="/templates/assets/icons/iconoir/css/iconoir.css">';
    echo '<link rel="stylesheet" href="/templates/assets/icons/phosphor/Fonts/regular/style.css">';
    
    // Include basic head components without animations
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&family=Orbitron:wght@400;700;900&family=Rajdhani:wght@400;700&display=swap" rel="stylesheet">';
    echo '<meta name="robots" content="noindex, nofollow">';
    includeNoticesWidget();
    echo '<style>';
    echo 'body { background: var(--theme-background, #1a1a1a); color: var(--theme-text, #00ffff); font-family: Arial, sans-serif; margin: 0; padding: 0; min-height: 100vh; }';
    echo '.page-title { text-align: center; color: #00ffff; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5); font-size: 2.5em; margin: 20px 0; }';
    echo '.form-container { max-width: 1200px; margin: 0 auto; background: rgba(0, 20, 40, 0.8); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; padding: 30px; backdrop-filter: blur(10px); }';
    echo '.form-row { display: flex; gap: 20px; margin-bottom: 25px; align-items: flex-start; } .form-row.single { justify-content: center; }';
    echo '.form-group { flex: 1; min-width: 0; } .form-group.full-width { flex: 100%; } .form-group.half-width { flex: 50%; } .form-group.third-width { flex: 33.333%; }';
    echo '.form-label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff; }';
    echo '.form-input, .form-select, .form-textarea { width: 100%; padding: 12px 15px; background: rgba(10, 10, 26, 0.8); color: #00ffff; border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 8px; font-size: 14px; transition: all 0.3s ease; box-sizing: border-box; }';
    echo '.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 15px rgba(0, 255, 255, 0.3); background: rgba(10, 10, 26, 1); }';
    echo '.form-checkbox-group { display: flex; align-items: center; gap: 10px; padding: 15px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; margin-bottom: 15px; }';
    echo '.form-checkbox { appearance: none; width: 20px; height: 20px; background: rgba(0, 255, 255, 0.1); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 4px; position: relative; cursor: pointer; }';
    echo '.form-checkbox:checked { background: linear-gradient(135deg, #00ffff, #0080ff); border-color: #00ffff; }';
    echo '.form-checkbox:checked::after { content: "✓"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #000; font-weight: bold; font-size: 14px; }';
    echo '.checkbox-label { cursor: pointer; user-select: none; display: flex; align-items: center; font-weight: 500; color: #00ffff; }';
    echo '.input-group { display: flex; align-items: center; gap: 0; } .unit-label { color: #00ffff; font-size: 0.9em; font-weight: 500; margin-left: 8px; }';
    echo '.browse-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); }';
    echo '.section { background: rgba(0, 255, 255, 0.03); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 12px; padding: 25px; margin-bottom: 20px; }';
    echo '.section h3 { color: #00ffff; margin: 0 0 20px 0; font-size: 1.3em; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3); border-bottom: 2px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px; }';
    echo '.color-block-selector { display: flex; align-items: center; gap: 10px; padding: 8px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 6px; }';
    echo '.color-block-info { display: flex; flex-direction: column; } .color-block-label { font-size: 0.8em; color: #ccc; } .color-block-value { font-size: 0.9em; color: #00ffff; font-weight: bold; }';
    echo '.save-btn { background: linear-gradient(135deg, #00ff88, #00cc66); color: #000; padding: 15px 30px; border: none; border-radius: 10px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 10px; margin: 30px auto 0; }';
    echo '.save-btn:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0, 255, 136, 0.4); }';

    echo '.alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid rgba(0, 255, 0, 0.3); color: #00ff00; padding: 15px; margin: 10px 0; border-radius: 8px; }';
    echo '.alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid rgba(255, 0, 0, 0.3); color: #ff6666; padding: 15px; margin: 10px 0; border-radius: 8px; }';
    
    // Ensure global hamburger menu appears above demo
    echo '.cue-hamburger-menu { z-index: 10500 !important; }';
    echo '.hamburger-trigger { z-index: 10500 !important; }';
    echo '.hamburger-panel { z-index: 10500 !important; }';
    echo '.hamburger-backdrop { z-index: 10499 !important; }';
    
    echo '</style></head><body>';
    
    // Include global UI body start components (header, hamburger) via CUE Framework
    $bodyStartPath = __DIR__ . '/includes/complete-body-start.php';
    if (file_exists($bodyStartPath)) {
        include_once $bodyStartPath;
    } else {
        // Fallback if complete-body-start doesn't exist
        if (function_exists('renderGlobalHeader')) {
            renderGlobalHeader();
        }
        if (function_exists('renderGlobalHamburgerMenu')) {
            renderGlobalHamburgerMenu();
        }
        if (function_exists('renderGlobalWidgets')) {
            renderGlobalWidgets();
        }
    }
    
    echo '<div class="main-content" style="padding: 20px;">';
    echo '<h1 class="page-title">🍔 Advanced Hamburger Menu Manager</h1>';
}

// Display messages
if (isset($saveMessage)) {
    echo '<div class="alert-success">' . $saveMessage . '</div>';
    // Add JavaScript to refresh hamburger menu UI
    // Settings saved successfully - no additional scripts needed
}
if (isset($errorMessage)) {
    echo '<div class="alert-error">' . $errorMessage . '</div>';
}

if (true):  // Always show form when loaded
?>

<div class="form-container">
    <form method="post">
        <input type="hidden" name="action" value="save_hamburger">
        
        <!-- Core Functionality Section -->
        <div class="section">
            <h3>🛠️ Core Functionality</h3>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_enabled" name="hbg_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_enabled" class="checkbox-label">Enable Hamburger Menu</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_responsive_enabled" name="hbg_responsive_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_responsive_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_responsive_enabled" class="checkbox-label">Responsive Design</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_mobile_only" name="hbg_mobile_only" class="form-checkbox" <?php echo $currentConfig['hbg_mobile_only'] ? 'checked' : ''; ?>>
                        <label for="hbg_mobile_only" class="checkbox-label">Mobile Only</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Position</label>
                    <select name="hbg_position" class="form-select">
                        <option value="left" <?php echo ($currentConfig['hbg_position'] ?? '') === 'left' ? 'selected' : ''; ?>>Left</option>
                        <option value="right" <?php echo ($currentConfig['hbg_position'] ?? '') === 'right' ? 'selected' : ''; ?>>Right</option>
                        <option value="center" <?php echo ($currentConfig['hbg_position'] ?? '') === 'center' ? 'selected' : ''; ?>>Center</option>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Menu Style</label>
                    <select name="hbg_menu_style" class="form-select">
                        <option value="modern" <?php echo ($currentConfig['hbg_menu_style'] ?? '') === 'modern' ? 'selected' : ''; ?>>Modern</option>
                        <option value="glassmorphism" <?php echo ($currentConfig['hbg_menu_style'] ?? '') === 'glassmorphism' ? 'selected' : ''; ?>>Glassmorphism</option>
                        <option value="dark" <?php echo ($currentConfig['hbg_menu_style'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Dropdown Style</label>
                    <select name="hbg_dropdown_style" class="form-select">
                        <option value="glassmorphism" <?php echo ($currentConfig['hbg_dropdown_style'] ?? '') === 'glassmorphism' ? 'selected' : ''; ?>>Glassmorphism</option>
                        <option value="modern" <?php echo ($currentConfig['hbg_dropdown_style'] ?? '') === 'modern' ? 'selected' : ''; ?>>Modern</option>
                        <option value="dark" <?php echo ($currentConfig['hbg_dropdown_style'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">Animation Type</label>
                    <select name="hbg_animation_type" class="form-select">
                        <option value="slide" <?php echo ($currentConfig['hbg_animation_type'] ?? '') === 'slide' ? 'selected' : ''; ?>>Slide</option>
                        <option value="fade" <?php echo ($currentConfig['hbg_animation_type'] ?? '') === 'fade' ? 'selected' : ''; ?>>Fade</option>
                        <option value="zoom" <?php echo ($currentConfig['hbg_animation_type'] ?? '') === 'zoom' ? 'selected' : ''; ?>>Zoom</option>
                        <option value="rotate" <?php echo ($currentConfig['hbg_animation_type'] ?? '') === 'rotate' ? 'selected' : ''; ?>>Rotate</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Visual Customization Section -->
        <div class="section">
            <h3>Visual Customization</h3>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Background Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_background_color" class="form-input" value="<?php echo $currentConfig['hbg_background_color'] ?? '#1a1a2e'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Background</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_background_color'] ?? '#1a1a2e'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Menu Item Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_menu_item_color" class="form-input" value="<?php echo $currentConfig['hbg_menu_item_color'] ?? '#ffffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Menu Item</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_menu_item_color'] ?? '#ffffff'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Hover Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_hover_color" class="form-input" value="<?php echo $currentConfig['hbg_hover_color'] ?? '#00ffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Hover</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_hover_color'] ?? '#00ffff'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Active Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_active_color" class="form-input" value="<?php echo $currentConfig['hbg_active_color'] ?? '#0080ff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Active</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_active_color'] ?? '#0080ff'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Text Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_text_color" class="form-input" value="<?php echo $currentConfig['hbg_text_color'] ?? '#ffffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Text</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_text_color'] ?? '#ffffff'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Icon Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_icon_color" class="form-input" value="<?php echo $currentConfig['hbg_icon_color'] ?? '#00ffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Icon</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_icon_color'] ?? '#00ffff'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Size and Behavior Section -->
        <div class="section">
            <h3>📊 Size & Behavior</h3>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Panel Width</label>
                    <div class="input-group">
                        <input type="number" name="hbg_panel_width" class="form-input" min="200" max="500" value="<?php echo $currentConfig['hbg_panel_width'] ?? 320; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Panel Height</label>
                    <div class="input-group">
                        <input type="number" name="hbg_panel_height" class="form-input" min="300" max="700" value="<?php echo $currentConfig['hbg_panel_height'] ?? 400; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Trigger Size</label>
                    <div class="input-group">
                        <input type="number" name="hbg_trigger_size" class="form-input" min="30" max="120" value="<?php echo $currentConfig['hbg_trigger_size'] ?? 50; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Trigger Align</label>
                    <select name="hbg_trigger_vertical_align" class="form-select">
                        <option value="top" <?php echo ($currentConfig['hbg_trigger_vertical_align'] ?? 'top') === 'top' ? 'selected' : ''; ?>>Top</option>
                        <option value="center" <?php echo ($currentConfig['hbg_trigger_vertical_align'] ?? '') === 'center' ? 'selected' : ''; ?>>Center</option>
                        <option value="bottom" <?php echo ($currentConfig['hbg_trigger_vertical_align'] ?? '') === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                    </select>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Trigger Offset</label>
                    <div class="input-group">
                        <input type="number" name="hbg_trigger_offset" class="form-input" min="0" max="200" value="<?php echo $currentConfig['hbg_trigger_offset'] ?? 20; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Bar Width</label>
                    <div class="input-group">
                        <input type="number" name="hbg_bar_width" class="form-input" min="10" max="80" value="<?php echo $currentConfig['hbg_bar_width'] ?? 25; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Bar Height</label>
                    <div class="input-group">
                        <input type="number" name="hbg_bar_height" class="form-input" min="1" max="12" value="<?php echo $currentConfig['hbg_bar_height'] ?? 3; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Bar Gap</label>
                    <div class="input-group">
                        <input type="number" name="hbg_bar_gap" class="form-input" min="0" max="20" value="<?php echo $currentConfig['hbg_bar_gap'] ?? 4; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Panel Offset</label>
                    <div class="input-group">
                        <input type="number" name="hbg_panel_offset" class="form-input" min="0" max="200" value="<?php echo $currentConfig['hbg_panel_offset'] ?? 10; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Panel Y Offset</label>
                    <div class="input-group">
                        <input type="number" name="hbg_panel_y_offset" class="form-input" min="-200" max="400" value="<?php echo $currentConfig['hbg_panel_y_offset'] ?? 0; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Panel Bottom Padding</label>
                    <div class="input-group">
                        <input type="number" name="hbg_panel_bottom_padding" class="form-input" min="0" max="300" value="<?php echo $currentConfig['hbg_panel_bottom_padding'] ?? 0; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Menu Bottom Padding</label>
                    <div class="input-group">
                        <input type="number" name="hbg_menu_bottom_padding" class="form-input" min="0" max="400" value="<?php echo $currentConfig['hbg_menu_bottom_padding'] ?? 20; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_close_outside" name="hbg_close_outside" class="form-checkbox" <?php echo $currentConfig['hbg_close_outside'] ? 'checked' : ''; ?>>
                        <label for="hbg_close_outside" class="checkbox-label">Close on Outside Click</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_close_esc" name="hbg_close_esc" class="form-checkbox" <?php echo $currentConfig['hbg_close_esc'] ? 'checked' : ''; ?>>
                        <label for="hbg_close_esc" class="checkbox-label">Close on Escape Key</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_backdrop_enabled" name="hbg_backdrop_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_backdrop_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_backdrop_enabled" class="checkbox-label">Backdrop Overlay</label>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Logo Integration Section -->
        <div class="section">
            <h3>🇺 Logo Integration</h3>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_logo_enabled" name="hbg_logo_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_logo_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_logo_enabled" class="checkbox-label">Enable Logo</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">Logo Image Path</label>
                    <div class="input-group">
                        <input type="text" id="hbg_logo_image_path" name="hbg_logo_image_path" class="form-input" placeholder="/images/logo.png" value="<?php echo htmlspecialchars($currentConfig['hbg_logo_image_path'] ?? ''); ?>">
                        <button type="button" class="browse-btn" onclick="openFileBrowser()" style="background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; border: none; padding: 12px 16px; border-radius: 0 8px 8px 0; cursor: pointer; font-weight: bold; transition: all 0.3s ease;">Browse</button>
                    </div>
                </div>
                
                <div class="form-group half-width">
                    <label class="form-label">Logo Position</label>
                    <select name="hbg_logo_position" class="form-select">
                        <option value="top-left" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'top-left' ? 'selected' : ''; ?>>Top Left</option>
                        <option value="top-center" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'top-center' ? 'selected' : ''; ?>>Top Center</option>
                        <option value="top-right" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'top-right' ? 'selected' : ''; ?>>Top Right</option>
                        <option value="center-left" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'center-left' ? 'selected' : ''; ?>>Center Left</option>
                        <option value="center" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'center' ? 'selected' : ''; ?>>Center</option>
                        <option value="center-right" <?php echo ($currentConfig['hbg_logo_position'] ?? '') === 'center-right' ? 'selected' : ''; ?>>Center Right</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Logo Width</label>
                    <div class="input-group">
                        <input type="number" name="hbg_logo_width" class="form-input" min="20" max="200" value="<?php echo $currentConfig['hbg_logo_width'] ?? 60; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Logo Height</label>
                    <div class="input-group">
                        <input type="number" name="hbg_logo_height" class="form-input" min="20" max="200" value="<?php echo $currentConfig['hbg_logo_height'] ?? 60; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_logo_animation_enabled" name="hbg_logo_animation_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_logo_animation_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_logo_animation_enabled" class="checkbox-label">Enable Animation</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Animation Type</label>
                    <select name="hbg_logo_animation_type" class="form-select">
                        <option value="none" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                        <option value="pulse" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'pulse' ? 'selected' : ''; ?>>Pulse</option>
                        <option value="bounce" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'bounce' ? 'selected' : ''; ?>>Bounce</option>
                        <option value="rotate" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'rotate' ? 'selected' : ''; ?>>Rotate</option>
                        <option value="wobble" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'wobble' ? 'selected' : ''; ?>>Wobble</option>
                        <option value="fade" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'fade' ? 'selected' : ''; ?>>Fade</option>
                        <option value="scale" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'scale' ? 'selected' : ''; ?>>Scale</option>
                        <option value="glow" <?php echo ($currentConfig['hbg_logo_animation_type'] ?? '') === 'glow' ? 'selected' : ''; ?>>Glow</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_logo_glow_enabled" name="hbg_logo_glow_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_logo_glow_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_logo_glow_enabled" class="checkbox-label">Enable Glow Effect</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Glow Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_logo_glow_color" class="form-input" value="<?php echo $currentConfig['hbg_logo_glow_color'] ?? '#00ffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Glow</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_logo_glow_color'] ?? '#00ffff'; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Glow Intensity (1-20)</label>
                    <input type="number" name="hbg_logo_glow_intensity" class="form-input" min="1" max="20" value="<?php echo (int)($currentConfig['hbg_logo_glow_intensity'] ?? 5); ?>">
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Glow Size (px)</label>
                    <input type="number" name="hbg_logo_glow_size" class="form-input" min="1" max="50" value="<?php echo (int)($currentConfig['hbg_logo_glow_size'] ?? 10); ?>">
                </div>
            </div>
        </div>
        
        <!-- Typography Section -->
        <div class="section">
            <h3>🅰️ Typography Controls</h3>
            
            <!-- Heading -->
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_heading_enabled" name="hbg_heading_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_heading_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_heading_enabled" class="checkbox-label">Enable Heading</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Heading Text</label>
                    <input type="text" name="hbg_heading_text" class="form-input" placeholder="CUE Framework" value="<?php echo htmlspecialchars($currentConfig['hbg_heading_text'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Heading Font</label>
                    <select name="hbg_heading_font" class="form-select">
                        <?php foreach ($availableFonts as $fontFile => $fontName): ?>
                            <option value="<?php echo $fontFile; ?>" <?php echo ($currentConfig['hbg_heading_font'] ?? '') === $fontFile ? 'selected' : ''; ?>><?php echo $fontName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Heading Size</label>
                    <div class="input-group">
                        <input type="number" name="hbg_heading_size" class="form-input" min="12" max="48" value="<?php echo $currentConfig['hbg_heading_size'] ?? 24; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Heading Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_heading_color" class="form-input" value="<?php echo $currentConfig['hbg_heading_color'] ?? '#00ffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Heading</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_heading_color'] ?? '#00ffff'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Subheading -->
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_subheading_enabled" name="hbg_subheading_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_subheading_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_subheading_enabled" class="checkbox-label">Enable Subheading</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Subheading Text</label>
                    <input type="text" name="hbg_subheading_text" class="form-input" placeholder="Advanced Framework" value="<?php echo htmlspecialchars($currentConfig['hbg_subheading_text'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Subheading Font</label>
                    <select name="hbg_subheading_font" class="form-select">
                        <?php foreach ($availableFonts as $fontFile => $fontName): ?>
                            <option value="<?php echo $fontFile; ?>" <?php echo ($currentConfig['hbg_subheading_font'] ?? '') === $fontFile ? 'selected' : ''; ?>><?php echo $fontName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Subheading Size</label>
                    <div class="input-group">
                        <input type="number" name="hbg_subheading_size" class="form-input" min="10" max="32" value="<?php echo $currentConfig['hbg_subheading_size'] ?? 16; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Subheading Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_subheading_color" class="form-input" value="<?php echo $currentConfig['hbg_subheading_color'] ?? '#00ffff'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Subheading</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_subheading_color'] ?? '#00ffff'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dividers Section -->
        <div class="section">
            <h3>➖ Enhanced Dividers</h3>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_divider_enabled" name="hbg_divider_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_divider_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_divider_enabled" class="checkbox-label">Enable Dividers</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Divider Style</label>
                    <select name="hbg_divider_style" class="form-select">
                        <option value="solid" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'solid' ? 'selected' : ''; ?>>Solid</option>
                        <option value="dashed" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'dashed' ? 'selected' : ''; ?>>Dashed</option>
                        <option value="dotted" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'dotted' ? 'selected' : ''; ?>>Dotted</option>
                        <option value="double" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'double' ? 'selected' : ''; ?>>Double</option>
                        <option value="groove" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'groove' ? 'selected' : ''; ?>>Groove</option>
                        <option value="ridge" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'ridge' ? 'selected' : ''; ?>>Ridge</option>
                        <option value="inset" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'inset' ? 'selected' : ''; ?>>Inset</option>
                        <option value="outset" <?php echo ($currentConfig['hbg_divider_style'] ?? '') === 'outset' ? 'selected' : ''; ?>>Outset</option>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Thickness</label>
                    <div class="input-group">
                        <input type="number" name="hbg_divider_thickness" class="form-input" min="1" max="10" value="<?php echo $currentConfig['hbg_divider_thickness'] ?? 1; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Divider Color</label>
                    <div class="color-block-selector">
                        <input type="color" name="hbg_divider_color" class="form-input" value="<?php echo $currentConfig['hbg_divider_color'] ?? '#333333'; ?>"
                               style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                        <div class="color-block-info">
                            <span class="color-block-label">Divider</span>
                            <span class="color-block-value"><?php echo $currentConfig['hbg_divider_color'] ?? '#333333'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Divider Variant</label>
                    <select name="hbg_divider_variant" class="form-select">
                        <option value="standard" <?php echo ($currentConfig['hbg_divider_variant'] ?? '') === 'standard' ? 'selected' : ''; ?>>Standard</option>
                        <option value="curved" <?php echo ($currentConfig['hbg_divider_variant'] ?? '') === 'curved' ? 'selected' : ''; ?>>Curved</option>
                        <option value="wavy" <?php echo ($currentConfig['hbg_divider_variant'] ?? '') === 'wavy' ? 'selected' : ''; ?>>Wavy</option>
                        <option value="zigzag" <?php echo ($currentConfig['hbg_divider_variant'] ?? '') === 'zigzag' ? 'selected' : ''; ?>>ZigZag</option>
                    </select>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Curvature</label>
                    <div class="input-group">
                        <input type="number" name="hbg_divider_curvature" class="form-input" min="0" max="50" value="<?php echo $currentConfig['hbg_divider_curvature'] ?? 0; ?>">
                        <span class="unit-label">%</span>
                    </div>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group full-width">
                    <label class="form-label">Realm Dividers</label>
                    <div class="form-row">
                        <div class="form-group third-width">
                            <div class="form-checkbox-group">
                                <input type="checkbox" id="hbg_realm_dividers_enabled" name="hbg_realm_dividers_enabled" class="form-checkbox" <?php echo !empty($currentConfig['hbg_realm_dividers_enabled']) ? 'checked' : ''; ?>>
                                <label for="hbg_realm_dividers_enabled" class="checkbox-label">Enable Realm Dividers</label>
                            </div>
                        </div>
                        <div class="form-group third-width">
                            <div class="form-checkbox-group">
                                <input type="checkbox" id="hbg_realm_divider_show_top" name="hbg_realm_divider_show_top" class="form-checkbox" <?php echo !empty($currentConfig['hbg_realm_divider_show_top']) ? 'checked' : ''; ?>>
                                <label for="hbg_realm_divider_show_top" class="checkbox-label">Show Top Divider</label>
                            </div>
                        </div>
                        <div class="form-group third-width">
                            <div class="form-checkbox-group">
                                <input type="checkbox" id="hbg_realm_divider_show_bottom" name="hbg_realm_divider_show_bottom" class="form-checkbox" <?php echo !empty($currentConfig['hbg_realm_divider_show_bottom']) ? 'checked' : ''; ?>>
                                <label for="hbg_realm_divider_show_bottom" class="checkbox-label">Show Bottom Divider</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group third-width">
                            <label class="form-label">Realm Divider Style</label>
                            <select name="hbg_realm_divider_style" class="form-select">
                                <option value="solid" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'solid' ? 'selected' : ''; ?>>Solid</option>
                                <option value="dashed" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'dashed' ? 'selected' : ''; ?>>Dashed</option>
                                <option value="dotted" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'dotted' ? 'selected' : ''; ?>>Dotted</option>
                                <option value="double" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'double' ? 'selected' : ''; ?>>Double</option>
                                <option value="groove" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'groove' ? 'selected' : ''; ?>>Groove</option>
                                <option value="ridge" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'ridge' ? 'selected' : ''; ?>>Ridge</option>
                                <option value="inset" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'inset' ? 'selected' : ''; ?>>Inset</option>
                                <option value="outset" <?php echo ($currentConfig['hbg_realm_divider_style'] ?? '') === 'outset' ? 'selected' : ''; ?>>Outset</option>
                            </select>
                        </div>
                        <div class="form-group third-width">
                            <label class="form-label">Thickness</label>
                            <div class="input-group">
                                <input type="number" name="hbg_realm_divider_thickness" class="form-input" min="1" max="10" value="<?php echo $currentConfig['hbg_realm_divider_thickness'] ?? 1; ?>">
                                <span class="unit-label">px</span>
                            </div>
                        </div>
                        <div class="form-group third-width">
                            <label class="form-label">Realm Divider Color</label>
                            <div class="color-block-selector">
                                <input type="color" name="hbg_realm_divider_color" class="form-input" value="<?php echo $currentConfig['hbg_realm_divider_color'] ?? '#333333'; ?>" style="width: 50px; height: 40px; border-radius: 5px; cursor: pointer;">
                                <div class="color-block-info">
                                    <span class="color-block-label">Realm Divider</span>
                                    <span class="color-block-value"><?php echo $currentConfig['hbg_realm_divider_color'] ?? '#333333'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group third-width">
                            <label class="form-label">Curvature</label>
                            <div class="input-group">
                                <input type="number" name="hbg_realm_divider_curvature" class="form-input" min="0" max="50" value="<?php echo $currentConfig['hbg_realm_divider_curvature'] ?? 0; ?>">
                                <span class="unit-label">%</span>
                            </div>
                        </div>
                        <div class="form-group third-width">
                            <label class="form-label">Spacing</label>
                            <div class="input-group">
                                <input type="number" name="hbg_realm_divider_spacing" class="form-input" min="0" max="50" value="<?php echo $currentConfig['hbg_realm_divider_spacing'] ?? 8; ?>">
                                <span class="unit-label">px</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Social Media Footer Section -->
        <div class="section">
            <h3>📱 Social Media Integration</h3>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_social_enabled" name="hbg_social_enabled" class="form-checkbox" <?php echo $currentConfig['hbg_social_enabled'] ? 'checked' : ''; ?>>
                        <label for="hbg_social_enabled" class="checkbox-label">Enable Social Footer</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" id="hbg_social_from_navigator" name="hbg_social_from_navigator" class="form-checkbox" <?php echo $currentConfig['hbg_social_from_navigator'] ? 'checked' : ''; ?>>
                        <label for="hbg_social_from_navigator" class="checkbox-label">Use Navigator Config</label>
                    </div>
                </div>
                
                <div class="form-group third-width">
                    <label class="form-label">Icon Size</label>
                    <div class="input-group">
                        <input type="number" name="hbg_social_icons_size" class="form-input" min="16" max="48" value="<?php echo $currentConfig['hbg_social_icons_size'] ?? 24; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">Link Font Size</label>
                    <div class="input-group">
                        <input type="number" name="hbg_social_link_font_size" class="form-input" min="10" max="20" value="<?php echo $currentConfig['hbg_social_link_font_size'] ?? 12; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Link Padding Y</label>
                    <div class="input-group">
                        <input type="number" name="hbg_social_link_padding_y" class="form-input" min="0" max="30" value="<?php echo $currentConfig['hbg_social_link_padding_y'] ?? 6; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>

                <div class="form-group third-width">
                    <label class="form-label">Link Padding X</label>
                    <div class="input-group">
                        <input type="number" name="hbg_social_link_padding_x" class="form-input" min="0" max="60" value="<?php echo $currentConfig['hbg_social_link_padding_x'] ?? 10; ?>">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Menu Content Section -->
        <div class="section">
            <h3>📋 Menu Content</h3>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">Menu Source</label>
                    <select name="hbg_menu_source" class="form-select">
                        <option value="navigator" <?php echo ($currentConfig['hbg_menu_source'] ?? '') === 'navigator' ? 'selected' : ''; ?>>From Navigator Config</option>
                        <option value="custom" <?php echo ($currentConfig['hbg_menu_source'] ?? '') === 'custom' ? 'selected' : ''; ?>>Custom Menu Items</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group full-width">
                    <label class="form-label">Custom Menu Items (pipe-separated)</label>
                    <textarea name="hbg_custom_menu_items" class="form-textarea" rows="3" placeholder="Home|About|Services|Contact|Blog"><?php echo htmlspecialchars($currentConfig['hbg_custom_menu_items'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <button type="submit" class="save-btn">
            💾 Save Advanced Hamburger Menu Settings
        </button>
    </form>
</div>

<?php endif; ?>

<!-- File browser and hamburger menu scripts - Always load -->
<input type="file" id="fileBrowser" accept="image/*" style="display: none;" onchange="handleFileSelect(event)">

<?php if ($isStandalone): ?>
<script>
// Prevent any Vanta.js initialization to avoid THREE.js errors
window.DISABLE_VANTA = true;
window.SKIP_VANTA_ANIMATIONS = true;

// Block Vanta initialization functions
if (typeof initVantaAnimation !== 'undefined') {
    window.initVantaAnimation = function() { 
        console.log('Vanta animations blocked in hamburger manager to prevent THREE.js errors'); 
        return null;
    };
}

// Override any existing VANTA calls to prevent errors
if (typeof VANTA !== 'undefined') {
    Object.keys(VANTA).forEach(key => {
        if (typeof VANTA[key] === 'function' && key !== 'current') {
            VANTA[key] = function() {
                console.log('VANTA.' + key + ' call blocked to prevent THREE.js errors');
                return { destroy: function() {} };
            };
        }
    });
}

// File browser functionality - Always available
function openFileBrowser() {
    const fileBrowser = document.getElementById('serverFileBrowser');
    if (fileBrowser) {
        fileBrowser.style.display = 'flex';
        loadServerFiles('/public_html');
    } else {
        console.error('File browser modal not found');
        alert('File browser is not available in this context');
    }
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (file) {
        // Create a relative path from /public_html/
        const relativePath = '/images/' + file.name;
        const inputElement = document.getElementById('hbg_logo_image_path');
        if (inputElement) {
            inputElement.value = relativePath;
            // Show preview if possible
            showImagePreview(file, relativePath);
        } else {
            console.error('Logo image path input not found');
        }
    }
}

function showImagePreview(file, path) {
    const reader = new FileReader();
    reader.onload = function(e) {
        // Remove existing preview
        const existingPreview = document.querySelector('.logo-preview');
        if (existingPreview) existingPreview.remove();
        
        // Create new preview
        const preview = document.createElement('div');
        preview.className = 'logo-preview';
        preview.style.cssText = 'margin-top: 10px; padding: 10px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; text-align: center;';
        preview.innerHTML = `
            <img src="${e.target.result}" style="max-width: 100px; max-height: 60px; border-radius: 4px; margin-bottom: 5px;" alt="Logo Preview">
            <div style="color: #00ffff; font-size: 12px;">${path}</div>
            <div style="color: #ccc; font-size: 11px;">Note: Upload this file to ${path} on your server</div>
        `;
        const inputElement = document.getElementById('hbg_logo_image_path');
        if (inputElement && inputElement.parentNode && inputElement.parentNode.parentNode) {
            inputElement.parentNode.parentNode.appendChild(preview);
        } else {
            console.error('Cannot append preview - parent elements not found');
        }
    };
    reader.readAsDataURL(file);
}

function loadServerFiles(path = '/public_html') {
    fetch('hamburger-manager.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=browse_files&path=' + encodeURIComponent(path)
    })
    .then(response => response.json())
    .then(data => {
        const filesList = document.getElementById('serverFilesList');
        if (!filesList) {
            console.error('Server files list element not found');
            return;
        }
        filesList.innerHTML = '';
        
        if (data.success) {
            // Add parent directory option if not at root
            if (path !== '/public_html') {
                const parentPath = path.substring(0, path.lastIndexOf('/')) || '/public_html';
                filesList.innerHTML += `<div class="file-item folder" onclick="loadServerFiles('${parentPath}')">../</div>`;
            }
            
            data.files.forEach(file => {
                if (file.type === 'directory') {
                    filesList.innerHTML += `<div class="file-item folder" onclick="loadServerFiles('${path}/${file.name}')">${file.name}/</div>`;
                } else {
                    // Only show image files
                    if (file.name.match(/\.(jpg|jpeg|png|gif|svg|webp)$/i)) {
                        const fullPath = path.replace('/public_html', '') + '/' + file.name;
                        filesList.innerHTML += `<div class="file-item file" onclick="selectServerFile('${fullPath}')">${file.name}</div>`;
                    }
                }
            });
            
            const currentPathElement = document.getElementById('currentPath');
            if (currentPathElement) {
                currentPathElement.textContent = path;
            }
        } else {
            filesList.innerHTML = '<div class="file-item error">Error loading files</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        const filesList = document.getElementById('serverFilesList');
        if (filesList) {
            filesList.innerHTML = '<div class="file-item error">Network error</div>';
        }
    });
}

function selectServerFile(filePath) {
    const inputElement = document.getElementById('hbg_logo_image_path');
    if (!inputElement) {
        console.error('Logo image path input not found');
        return;
    }
    inputElement.value = filePath;
    
    // Show preview
    const preview = document.createElement('div');
    preview.className = 'logo-preview';
    preview.style.cssText = 'margin-top: 10px; padding: 10px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; text-align: center;';
    preview.innerHTML = `
        <img src="${filePath}" style="max-width: 100px; max-height: 60px; border-radius: 4px; margin-bottom: 5px;" alt="Logo Preview" onerror="this.style.display='none'">
        <div style="color: #00ffff; font-size: 12px;">${filePath}</div>
    `;
    
    // Remove existing preview first
    const existingPreview = document.querySelector('.logo-preview');
    if (existingPreview) existingPreview.remove();
    
    if (inputElement && inputElement.parentNode && inputElement.parentNode.parentNode) {
        inputElement.parentNode.parentNode.appendChild(preview);
    } else {
        console.error('Cannot append preview - parent elements not found');
    }
    closeFileBrowser();
}

function closeFileBrowser() {
    const fileBrowser = document.getElementById('serverFileBrowser');
    if (fileBrowser) {
        fileBrowser.style.display = 'none';
    }
}
</script>

<!-- Server File Browser Modal - Always Available -->
<div id="serverFileBrowser" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #1a1a2e; border: 2px solid #00ffff; border-radius: 12px; width: 90%; max-width: 600px; max-height: 80%; display: flex; flex-direction: column;">
        <div style="padding: 20px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #00ffff;">Browse Server Files</h3>
            <button onclick="closeFileBrowser()" style="background: #ff4757; color: white; border: none; border-radius: 6px; padding: 8px 12px; cursor: pointer; margin-left: auto;">✖ Close</button>
        </div>
        <div style="padding: 10px 20px; color: #ccc; font-size: 14px;">
            Current: <span id="currentPath">/public_html</span>
        </div>
        <div id="serverFilesList" style="flex: 1; overflow-y: auto; padding: 0 20px 20px;">
            Loading files...
        </div>
    </div>
</div>

<style>
.file-item {
    padding: 10px;
    margin: 2px 0;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    color: #ccc;
}
.file-item:hover {
    background: rgba(0, 255, 255, 0.1);
    color: #00ffff;
}
.file-item.folder {
    border-left: 3px solid #ffa502;
}
.file-item.file {
    border-left: 3px solid #7bed9f;
}
</style>

<?php endif; ?>

<div id="hamburgerPreview" style="margin: 20px auto; max-width: 800px; background: rgba(0,255,255,0.06); border: 1px solid rgba(0,255,255,0.2); border-radius: 12px; padding: 16px;">
    <div style="color:#00ffff; font-weight:600; margin-bottom:10px;">Hamburger Menu Preview</div>
    <ul id="hamburgerPreviewList" style="list-style:none; padding:0; margin:0;"></ul>
    <div id="hamburgerPreviewStatus" style="color:#a1a1aa; font-size:12px; margin-top:8px;"></div>
    <style>
        #hamburgerPreview .nav-link { display:flex; align-items:center; gap:8px; padding:8px 10px; color:#fff; text-decoration:none; }
        #hamburgerPreview .submenu { list-style:none; padding-left:18px; margin:6px 0; }
        #hamburgerPreview .submenu-link { display:flex; align-items:center; gap:6px; color:#cbd5e1; text-decoration:none; padding:6px 8px; }
        #hamburgerPreview i { display:inline-block; }
    </style>
</div>
<script>
(function(){
    var dataUrl = "/templates/global-ui/includes/hamburger.php?format=json&include=all";
    function esc(s){
        return String(s === undefined || s === null ? "" : s)
            .replace(/&/g,"&amp;")
            .replace(/</g,"&lt;")
            .replace(/>/g,"&gt;")
            .replace(/"/g,"&quot;")
            .replace(/'/g,"&#039;");
    }
    function renderStructured(data){
        if (!Array.isArray(data) || data.length === 0) {
            return '<li><div style="padding:10px;color:#a1a1aa;">No menu data</div></li>';
        }
        var html = '';
        data.forEach(function(realm){
            var realmTitle = realm && (realm.title || realm.id) ? (realm.title || realm.id) : 'Realm';
            html += '<li style="padding:8px 10px;margin:8px 0;border:1px solid rgba(0,255,255,0.2);border-radius:10px;background:rgba(0,255,255,0.06);color:#00ffff;font-weight:700;">' + esc(realmTitle) + '</li>';
            if (realm && Array.isArray(realm.menus)) {
                realm.menus.forEach(function(menu){
                    var title = menu && (menu.title || menu.name) ? (menu.title || menu.name) : 'Menu';
                    var url = menu && menu.url ? String(menu.url) : '';
                    html += '<li style="margin-left:12px;">';
                    if (url) {
                        html += '<a class="nav-link" href="' + esc(url) + '">' + esc(title) + '</a>';
                    } else {
                        html += '<span class="nav-link" style="opacity:0.85;">' + esc(title) + '</span>';
                    }
                    if (menu && Array.isArray(menu.submenus) && menu.submenus.length > 0) {
                        html += '<ul class="submenu">';
                        menu.submenus.forEach(function(sm){
                            var st = sm && (sm.title || sm.name) ? (sm.title || sm.name) : 'Submenu';
                            var su = sm && sm.url ? String(sm.url) : '';
                            html += '<li>';
                            if (su) {
                                html += '<a class="nav-link submenu-link" href="' + esc(su) + '">' + esc(st) + '</a>';
                            } else {
                                html += '<span class="nav-link submenu-link" style="opacity:0.75;">' + esc(st) + '</span>';
                            }
                            html += '</li>';
                        });
                        html += '</ul>';
                    }
                    html += '</li>';
                });
            }
        });
        return html;
    }
    function loadHamburgerPreview(){
        var status = document.getElementById('hamburgerPreviewStatus');
        if (status) status.textContent = 'Loading preview...';
        fetch(dataUrl + '&_t=' + Date.now(), { cache: "no-store", credentials: "same-origin" })
        .then(function(r){ return r.json(); }).then(function(j){
            var list = document.getElementById('hamburgerPreviewList');
            if (!list) return;
            list.innerHTML = renderStructured(j);
            if (status) status.textContent = 'Preview loaded';
        }).catch(function(){
            var list = document.getElementById('hamburgerPreviewList');
            if (list) list.innerHTML = '<li><div style="padding:10px;color:#ef4444;">Network error</div></li>';
            if (status) status.textContent = 'Network error';
        });
    }
    document.addEventListener('DOMContentLoaded', loadHamburgerPreview);
})();
</script>

<?php if ($isStandalone): ?>
    <script>
    // Standalone-specific initialization only
    // Block any potential Vanta initialization in standalone mode to prevent THREE.js errors
    window.SKIP_VANTA_ANIMATIONS = true;
    if (typeof initVantaAnimation !== 'undefined') {
        window.initVantaAnimation = function() { 
            console.log('Vanta animations blocked in hamburger manager to prevent THREE.js errors'); 
            return null;
        };
    }
    
    // Override any existing VANTA calls
    if (typeof VANTA !== 'undefined') {
        Object.keys(VANTA).forEach(key => {
            if (typeof VANTA[key] === 'function' && key !== 'current') {
                VANTA[key] = function() {
                    console.log('VANTA.' + key + ' call blocked to prevent THREE.js errors');
                    return { destroy: function() {} };
                };
            }
        });
    }
    </script>

</body>
</html>
<?php endif; ?>
