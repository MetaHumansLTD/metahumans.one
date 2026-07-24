<?php
/**
 * Widgets Settings Manager
 * @requires CUE Framework
 */
require_once dirname(__DIR__, 3) . '/.cue/cue.php';
$isAjaxRequest = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
require_once dirname(__DIR__, 3) . '/auth/kripz_gate.php';
mh_kripz_require('remote-widgets-manager', $isAjaxRequest);

if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } else {
        session_start();
    }
}

$paths = cue_autoload('paths');
$secureConfigPath = $paths->getSecureFilePath('widgets/config.json');
$config = [];
$latestConfig = [];

// Prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Clear file status cache to ensure we get the latest file modification time and size
clearstatcache();

if ($secureConfigPath && $paths->validateSecurePath($secureConfigPath, getDataPath()) && file_exists($secureConfigPath)) {
    // Robust file read with locking
    $fp = fopen($secureConfigPath, 'r');
    $jsonContent = '';
    if ($fp && flock($fp, LOCK_SH)) {
        $jsonContent = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    } elseif ($fp) {
        // Fallback if lock fails
        fclose($fp);
        $jsonContent = file_get_contents($secureConfigPath);
    }
    
    $config = json_decode($jsonContent, true) ?: [];
    
    if (isset($config['K::WidgetUI::Configuration']) && is_array($config['K::WidgetUI::Configuration'])) {
        // Sort configs by date descending to ensure we get the absolute latest
        $configs = $config['K::WidgetUI::Configuration'];
        uasort($configs, function($a, $b) {
            return strtotime($b['wgt_last_updated'] ?? '0') - strtotime($a['wgt_last_updated'] ?? '0');
        });
        $latestConfig = reset($configs) ?: [];
    }
}

// Handle form submission
if(isset($_POST['action']) && $_POST['action'] === 'save_widgets') {
    $configId = 'K::WidgetUI::Content::' . strtoupper(uniqid());
    $enabledKeys = ['loader','notices','dragdrop','icons','metahuman_overlay','animations','statusbar','backtotop'];
    $activeList = [];
    foreach ($enabledKeys as $k) { if (isset($_POST[$k . '_enabled'])) { $activeList[] = $k; } }
    $newConfig = [
        'K::WidgetUI::Configuration' => [
            $configId => [
                'wgt_active_widgets_list' => $activeList,
                'wgt_positions_mode' => $_POST['positions'] ?? 'auto',
                'wgt_loader_enabled' => isset($_POST['loader_enabled']),
                'wgt_notices_enabled' => isset($_POST['notices_enabled']),
                'wgt_dragdrop_enabled' => isset($_POST['dragdrop_enabled']),
                'wgt_icons_enabled' => isset($_POST['icons_enabled']),
                'wgt_sidebar_enabled' => false,
                'wgt_metahuman_overlay_enabled' => isset($_POST['metahuman_overlay_enabled']),
                'wgt_animations_enabled' => isset($_POST['animations_enabled']),
                'wgt_statusbar_enabled' => isset($_POST['statusbar_enabled']),
                'wgt_backtotop_enabled' => isset($_POST['backtotop_enabled']),
                'wgt_metahuman_overlay_version' => $_POST['metahuman_overlay_version'] ?? ($latestConfig['wgt_metahuman_overlay_version'] ?? 'latest'),
                'wgt_metahuman_overlay_autostart' => isset($_POST['metahuman_overlay_autostart']),
                'wgt_metahuman_overlay_default_mode' => $_POST['metahuman_overlay_default_mode'] ?? ($latestConfig['wgt_metahuman_overlay_default_mode'] ?? 'auto'),
                
                // Status Bar Settings
                'wgt_statusbar_position' => $_POST['statusbar_position'] ?? ($latestConfig['wgt_statusbar_position'] ?? 'bottom'),
                'wgt_statusbar_placement' => $_POST['statusbar_placement'] ?? ($latestConfig['wgt_statusbar_placement'] ?? 'center'),
                'wgt_statusbar_background_color' => $_POST['statusbar_background_color'] ?? ($latestConfig['wgt_statusbar_background_color'] ?? '#1a1a2e'),
                'wgt_statusbar_text_color' => $_POST['statusbar_text_color'] ?? ($latestConfig['wgt_statusbar_text_color'] ?? '#00ffff'),
                'wgt_statusbar_border_color' => $_POST['statusbar_border_color'] ?? ($latestConfig['wgt_statusbar_border_color'] ?? '#00ffff'),
                'wgt_statusbar_height' => (int)($_POST['statusbar_height'] ?? ($latestConfig['wgt_statusbar_height'] ?? 40)),
                'wgt_statusbar_width' => $_POST['statusbar_width'] ?? ($latestConfig['wgt_statusbar_width'] ?? '100%'),
                'wgt_statusbar_font_size' => (int)($_POST['statusbar_font_size'] ?? ($latestConfig['wgt_statusbar_font_size'] ?? 14)),
                'wgt_statusbar_font_family' => $_POST['statusbar_font_family'] ?? ($latestConfig['wgt_statusbar_font_family'] ?? 'Arial, sans-serif'),
                'wgt_statusbar_shape' => $_POST['statusbar_shape'] ?? ($latestConfig['wgt_statusbar_shape'] ?? 'rounded'),
                'wgt_statusbar_border_radius' => (int)($_POST['statusbar_border_radius'] ?? ($latestConfig['wgt_statusbar_border_radius'] ?? 5)),
                'wgt_statusbar_padding' => $_POST['statusbar_padding'] ?? ($latestConfig['wgt_statusbar_padding'] ?? '10px'),
                'wgt_statusbar_margin' => $_POST['statusbar_margin'] ?? ($latestConfig['wgt_statusbar_margin'] ?? '0'),
                'wgt_statusbar_opacity' => (int)($_POST['statusbar_opacity'] ?? ($latestConfig['wgt_statusbar_opacity'] ?? 90)),
                'wgt_statusbar_glow_enabled' => isset($_POST['statusbar_glow_enabled']),
                'wgt_statusbar_glow_color' => $_POST['statusbar_glow_color'] ?? ($latestConfig['wgt_statusbar_glow_color'] ?? '#00ffff'),
                'wgt_statusbar_glow_intensity' => (int)($_POST['statusbar_glow_intensity'] ?? ($latestConfig['wgt_statusbar_glow_intensity'] ?? 5)),

                // Back to Top Settings
                'wgt_backtotop_placement' => $_POST['backtotop_placement'] ?? ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right'),
                'wgt_backtotop_size' => (int)($_POST['backtotop_size'] ?? ($latestConfig['wgt_backtotop_size'] ?? 40)),
                'wgt_backtotop_shape' => $_POST['backtotop_shape'] ?? ($latestConfig['wgt_backtotop_shape'] ?? 'circle'),
                'wgt_backtotop_arrow_type' => $_POST['backtotop_arrow_type'] ?? ($latestConfig['wgt_backtotop_arrow_type'] ?? 'chevron'),
                'wgt_backtotop_bg_color' => $_POST['backtotop_bg_color'] ?? ($latestConfig['wgt_backtotop_bg_color'] ?? '#00ffff'),
                'wgt_backtotop_arrow_color' => $_POST['backtotop_arrow_color'] ?? ($latestConfig['wgt_backtotop_arrow_color'] ?? '#000000'),
                'wgt_backtotop_animation' => $_POST['backtotop_animation'] ?? ($latestConfig['wgt_backtotop_animation'] ?? 'fade'),
                'wgt_backtotop_scroll_threshold' => (int)($_POST['backtotop_scroll_threshold'] ?? ($latestConfig['wgt_backtotop_scroll_threshold'] ?? 300)),
                'wgt_backtotop_transition_duration' => (float)($_POST['backtotop_transition_duration'] ?? ($latestConfig['wgt_backtotop_transition_duration'] ?? 0.3)),
                
                'wgt_notice_duration_seconds' => (int)($_POST['notice_duration'] ?? ($latestConfig['wgt_notice_duration_seconds'] ?? 5)),
                'wgt_animation_theme' => $_POST['animation_theme'] ?? ($latestConfig['wgt_animation_theme'] ?? 'cyber'),
                'wgt_icon_size' => (int)($_POST['wgt_icon_size'] ?? ($latestConfig['wgt_icon_size'] ?? 18)),
                'wgt_icon_color' => $_POST['wgt_icon_color'] ?? ($latestConfig['wgt_icon_color'] ?? '#00ffff'),
                'wgt_icon_hover_color' => $_POST['wgt_icon_hover_color'] ?? ($latestConfig['wgt_icon_hover_color'] ?? '#ffffff'),
                'wgt_icon_default_set' => $_POST['icon_default_set'] ?? ($latestConfig['wgt_icon_default_set'] ?? 'fontawesome'),
                'wgt_icon_sets' => (function(){
                    $arr = isset($_POST['icon_sets']) && is_array($_POST['icon_sets']) ? $_POST['icon_sets'] : (isset($latestConfig['wgt_icon_sets']) ? explode(',', (string)$latestConfig['wgt_icon_sets']) : ['fontawesome','iconoir','phosphor','feather']);
                    $arr = array_values(array_unique(array_map('strval', $arr)));
                    return implode(',', $arr);
                })(),
                'wgt_icon_grid_columns' => (int)($_POST['wgt_icon_grid_columns'] ?? ($latestConfig['wgt_icon_grid_columns'] ?? 8)),
                'wgt_icon_height' => $_POST['wgt_icon_height'] ?? ($latestConfig['wgt_icon_height'] ?? '300px'),
                'wgt_icon_size_multiplier_realms' => (float)($_POST['wgt_icon_size_multiplier_realms'] ?? ($latestConfig['wgt_icon_size_multiplier_realms'] ?? 1.0)),
                'wgt_icon_size_multiplier_menus' => (float)($_POST['wgt_icon_size_multiplier_menus'] ?? ($latestConfig['wgt_icon_size_multiplier_menus'] ?? 1.0)),
                'wgt_icon_size_multiplier_submenus' => (float)($_POST['wgt_icon_size_multiplier_submenus'] ?? ($latestConfig['wgt_icon_size_multiplier_submenus'] ?? 0.85)),
                'wgt_last_updated' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    if (!$secureConfigPath || !$paths->validateSecurePath($secureConfigPath, getDataPath())) {
        echo '<div class="notice error">❌ Invalid configuration path</div>';
    } else {
        if(file_put_contents($secureConfigPath, json_encode($newConfig, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX)) {
            $config = $newConfig;
            // Ensure we get the config we just saved
            $latestConfig = reset($config['K::WidgetUI::Configuration']);
            
            // Force clear cache
            clearstatcache(true, $secureConfigPath);
            if (function_exists('opcache_invalidate')) opcache_invalidate($secureConfigPath, true);
            
            echo '<div class="notice success">✅ Widget settings saved successfully!</div>';
            echo '<div class="notice info">Saved to: ' . htmlspecialchars($secureConfigPath) . ' at ' . date('H:i:s') . '</div>';
        } else {
            echo '<div class="notice error">❌ Error saving widget settings. Please check file permissions.</div>';
        }
    }
}

if(empty($latestConfig)) {
    $latestConfig = [
        'wgt_positions_mode' => 'auto',
        'wgt_loader_enabled' => true,
        'wgt_notices_enabled' => true,
        'wgt_dragdrop_enabled' => false,
        'wgt_icons_enabled' => false,
        'wgt_sidebar_enabled' => false,
        'wgt_metahuman_overlay_enabled' => false,
        'wgt_metahuman_overlay_version' => 'latest',
        'wgt_metahuman_overlay_autostart' => true,
        'wgt_metahuman_overlay_default_mode' => 'auto',
        'wgt_animations_enabled' => true,
        'wgt_notice_duration_seconds' => 5,
        'wgt_animation_theme' => 'cyber',
        'wgt_icon_size' => 18,
        'wgt_icon_color' => '#00ffff',
        'wgt_icon_hover_color' => '#ffffff',
        'wgt_icon_size_multiplier_realms' => 1.0,
        'wgt_icon_size_multiplier_menus' => 1.0,
        'wgt_icon_size_multiplier_submenus' => 0.85
    ];
}

// Available widgets with descriptions
$availableWidgets = [
    'loader' => ['name' => 'Loading Animation', 'description' => 'Shows loading spinners and progress indicators'],
    'notices' => ['name' => 'Popup Notices', 'description' => 'Display success, error, and info notifications'],

    'dragdrop' => ['name' => 'Drag & Drop', 'description' => 'File upload with drag and drop functionality'],
    'icons' => ['name' => 'Icon Library', 'description' => 'Icon selection and management system'],
    'metahuman_overlay' => ['name' => 'Meta Human Overlay', 'description' => 'Full-screen persona overlay widget (dock + voice/video/text)'],
    'statusbar' => ['name' => 'Status Bar', 'description' => 'Display realm info, login status, and permissions'],
    'backtotop' => ['name' => 'Back to Top', 'description' => 'Button to scroll back to top of page'],
    'animations' => ['name' => 'UI Animations', 'description' => 'Background animations and visual effects']
];

// Check if this file is being accessed directly (not included)
$isStandalone = !isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED']);
if ($isStandalone) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Widgets Manager - CUE Framework</title>';
    echo '<link rel="stylesheet" href="' . getTemplateURL('assets/fonts/fontawesome.css') . '">';
    // Load all available fonts for preview
    $fonts = ['rajdhani', 'inter', 'roboto', 'merriweather'];
    foreach($fonts as $font) {
        $fontUrl = getTemplateURL('assets/fonts/' . $font . '.css');
        echo '<link rel="stylesheet" href="' . $fontUrl . '">';
    }
    includeNoticesWidget();
    echo '<style>';
    echo 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #0a0a1a 0%, #1a1a2e 100%); color: #ffffff; margin: 0; padding: 20px; min-height: 100vh; }';
    echo '.form-container { max-width: 1200px; margin: 0 auto; background: rgba(255,255,255,0.05); padding: 30px; border-radius: 15px; backdrop-filter: blur(10px); border: 1px solid rgba(0,255,255,0.2); }';
    echo '.form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }';
    echo '.form-group { flex: 1; min-width: 280px; }';
    echo '.form-group.full-width { flex: 100%; }';
    echo '.form-label { display: block; margin-bottom: 8px; font-weight: 600; color: #00ffff; font-size: 14px; }';
    echo '.form-input, .form-select { width: 100%; padding: 12px 15px; border: 1px solid rgba(0,255,255,0.3); border-radius: 8px; background: rgba(255,255,255,0.1); color: #ffffff; font-size: 14px; transition: all 0.3s ease; }';
    echo '.form-input:focus, .form-select:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 15px rgba(0,255,255,0.4); }';
    echo '.form-checkbox { margin-right: 8px; }';
    echo '.btn { padding: 15px 30px; background: linear-gradient(135deg, #00ffff 0%, #0080ff 100%); color: #000; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 16px; transition: all 0.3s ease; text-shadow: none; }';
    echo '.btn:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,255,255,0.4); }';
    echo '.section-header { background: rgba(0, 255, 255, 0.1); padding: 15px; border-radius: 10px; margin: 30px 0 20px; }';
    echo '.section-header h3 { color: #00ffff; margin: 0; }';
    echo '.config-display { margin-top: 30px; background: rgba(255,255,255,0.05); padding: 20px; border-radius: 10px; }';
    echo '.config-display h4 { color: #00ffff; margin-top: 0; }';
    echo '.config-display pre { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }';
    echo '.preview-header { color: #00ffff; }';
    echo '#icon_default_set.form-select { background: #0a1f44; color: #00ffff; }';
    echo '.form-select { background-color: #0a1f44 !important; color: #00ffff !important; }';
    echo '</style></head><body>';
    
    // Render Status Bar for preview
    require_once __DIR__ . '/functions.php';
    renderGlobalStatusBar();
    
    echo '<h1 style="color: #00ffff; text-align: center; margin-bottom: 30px; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);">🧩 Widgets Manager</h1>';
}
?>

<form method="post" class="form-container">
    <input type="hidden" name="action" value="save_widgets">
    
    <div class="section-header"><h3>PHASE X — META HUMAN WIDGET (CURRENT → TARGET)</h3></div>
    <div class="config-display" style="margin-top: 0;">
        <h4 style="margin-bottom: 10px;">Current state</h4>
        <pre><?php echo htmlspecialchars(implode("\n", [
            "Widget config used by UI + runtime:",
            "- UI writes: getDataPath()/widgets/config.json (K::WidgetUI::Configuration)",
            "- Runtime reads: templates/global-ui/functions.php renderGlobalWidgets() → getDataPath()/widgets/config.json",
            "",
            "Widget config used by API:",
            "- templates/assets/api/global-ui-api.php maps widgets → getDataPath()/widgets/config.json",
            "- Backward-compat read: falls back to getDataPath()/widgets/widgets-config.json only if config.json is missing",
            "",
            "Sidebar implementations:",
            "- templates/widgets/sidebar/opera-sidebar.php is a standalone demo page",
            "- templates/widgets/sidebar/sidebar.php is an include-style widget (CUE bootstraps if needed)",
        ]), ENT_QUOTES); ?></pre>

        <h4 style="margin-top: 18px; margin-bottom: 10px;">Target state</h4>
        <pre><?php echo htmlspecialchars(implode("\n", [
            "Single source of truth:",
            "- One canonical widgets registry file for enable/disable + settings (no drift between API/UI/runtime)",
            "",
            "Web widget (primary):",
            "- Same-origin bundle: /global-ui/widgets/metahuman-sidebar/latest/widget.js",
            "- Loader mounts into global widgets container; no host framework assumptions",
            "",
            "PWA (primary install path):",
            "- Same UI packaged as PWA with Service Worker cache + IndexedDB local cache",
            "- Explicit offline/low-connectivity degraded modes (Realtime → Voice+Text → Text-only → Offline)",
            "",
            "Auth contract:",
            "- LemonLDAP session cookies; fetch with credentials: include; no long-lived tokens in localStorage",
            "",
            "UX contract:",
            "- Persistent dock button + slide-in panel + fullscreen modal",
        ]), ENT_QUOTES); ?></pre>
    </div>

<div class="form-row">
        <div class="form-group full-width">
            <label class="form-label">🧩 Available Widgets</label>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px;">
                <?php foreach($availableWidgets as $key => $widget): ?>
                    <div class="widget-card" style="background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 10px; padding: 15px;">
                        <div class="form-checkbox-group" style="margin-bottom: 10px;">
                            <input type="checkbox" name="<?= $key ?>_enabled" id="<?= $key ?>_enabled" class="form-checkbox"
                                   <?= ($latestConfig['wgt_' . $key . '_enabled'] ?? false) ? 'checked' : '' ?>>
                            <label for="<?= $key ?>_enabled" class="checkbox-label" style="font-weight: 600;"><?= $widget['name'] ?></label>
                        </div>
                        <p style="color: #aaffff; font-size: 0.9em; margin: 5px 0; line-height: 1.4;"><?= $widget['description'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="section-header"><h3>STATUS BAR CONFIGURATION</h3></div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📍 Position:</label>
            <select name="statusbar_position" class="form-select">
                <option value="top" <?= ($latestConfig['wgt_statusbar_position'] ?? 'bottom') === 'top' ? 'selected' : '' ?>>Top</option>
                <option value="middle" <?= ($latestConfig['wgt_statusbar_position'] ?? 'bottom') === 'middle' ? 'selected' : '' ?>>Middle</option>
                <option value="bottom" <?= ($latestConfig['wgt_statusbar_position'] ?? 'bottom') === 'bottom' ? 'selected' : '' ?>>Bottom</option>
