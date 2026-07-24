<?php
/**
 * Widgets Settings Manager
 * @requires CUE Framework
 */
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
$isAjaxRequest = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';
mh_kripz_require('widgets-manager', $isAjaxRequest);

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
                'wgt_metahuman_overlay_default_persona' => $_POST['metahuman_overlay_default_persona'] ?? ($latestConfig['wgt_metahuman_overlay_default_persona'] ?? ''),
                'wgt_metahuman_overlay_hub_base' => $_POST['metahuman_overlay_hub_base'] ?? ($latestConfig['wgt_metahuman_overlay_hub_base'] ?? '/hub'),
                
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
        'wgt_metahuman_overlay_default_persona' => '',
        'wgt_metahuman_overlay_hub_base' => '/hub',
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
    include_once __DIR__ . '/includes/complete-head.php';
    echo '<link rel="stylesheet" href="/templates/assets/fonts/fontawesome.css">';
    echo '<link rel="stylesheet" href="/templates/assets/fonts/merriweather.css">';
    includeNoticesWidget();
    echo '<style>';
    echo 'body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: var(--theme-background, #1a1a1a); color: var(--theme-text, #00ffff); margin: 0; padding: 0; min-height: 100vh; }';
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
    include_once __DIR__ . '/includes/complete-body-start.php';
    echo '<div class="main-content" style="padding:20px;">';
    
    // Render Status Bar for preview
    require_once __DIR__ . '/functions.php';
    renderGlobalStatusBar();
    
    echo '<h1 style="color: #00ffff; text-align: center; margin-bottom: 30px; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);">🧩 Widgets Manager</h1>';
}
?>

<form method="post" class="form-container">
    <input type="hidden" name="action" value="save_widgets">
    
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
    
    <div class="section-header"><h3>META HUMAN OVERLAY</h3></div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📦 Loader Version</label>
            <input type="text" name="metahuman_overlay_version" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_metahuman_overlay_version'] ?? 'latest') ?>" placeholder="latest">
        </div>
        <div class="form-group">
            <label class="form-label">🚀 Autostart</label>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                <input type="checkbox" name="metahuman_overlay_autostart" id="metahuman_overlay_autostart" <?= ($latestConfig['wgt_metahuman_overlay_autostart'] ?? true) ? 'checked' : '' ?>>
                <label for="metahuman_overlay_autostart">Open overlay on page load</label>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🎛️ Default Mode</label>
            <select name="metahuman_overlay_default_mode" class="form-select">
                <option value="auto" <?= ($latestConfig['wgt_metahuman_overlay_default_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>auto</option>
                <option value="realtime" <?= ($latestConfig['wgt_metahuman_overlay_default_mode'] ?? 'auto') === 'realtime' ? 'selected' : '' ?>>realtime</option>
                <option value="voice_text" <?= ($latestConfig['wgt_metahuman_overlay_default_mode'] ?? 'auto') === 'voice_text' ? 'selected' : '' ?>>voice_text</option>
                <option value="text_only" <?= ($latestConfig['wgt_metahuman_overlay_default_mode'] ?? 'auto') === 'text_only' ? 'selected' : '' ?>>text_only</option>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🔗 Hub Base</label>
            <input type="text" name="metahuman_overlay_hub_base" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_metahuman_overlay_hub_base'] ?? '/hub') ?>" placeholder="/hub">
        </div>
        <div class="form-group">
            <label class="form-label">🧠 Default Persona</label>
            <input type="text" name="metahuman_overlay_default_persona" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_metahuman_overlay_default_persona'] ?? '') ?>" placeholder="(empty = server decides)">
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
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">↔️ Content Alignment:</label>
            <select name="statusbar_placement" class="form-select">
                <option value="left" <?= ($latestConfig['wgt_statusbar_placement'] ?? 'center') === 'left' ? 'selected' : '' ?>>Left</option>
                <option value="center" <?= ($latestConfig['wgt_statusbar_placement'] ?? 'center') === 'center' ? 'selected' : '' ?>>Center</option>
                <option value="right" <?= ($latestConfig['wgt_statusbar_placement'] ?? 'center') === 'right' ? 'selected' : '' ?>>Right</option>
                <option value="space-between" <?= ($latestConfig['wgt_statusbar_placement'] ?? 'center') === 'space-between' ? 'selected' : '' ?>>Space Between</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Background Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="statusbar_bg_picker" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_background_color'] ?? '#1a1a2e') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="statusbar_background_color" id="statusbar_background_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_background_color'] ?? '#1a1a2e') ?>" placeholder="#1a1a2e">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Text Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="statusbar_text_picker" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_text_color'] ?? '#00ffff') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="statusbar_text_color" id="statusbar_text_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_text_color'] ?? '#00ffff') ?>" placeholder="#00ffff">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Border Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="statusbar_border_picker" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_border_color'] ?? ($latestConfig['wgt_statusbar_text_color'] ?? '#00ffff')) ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="statusbar_border_color" id="statusbar_border_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_border_color'] ?? ($latestConfig['wgt_statusbar_text_color'] ?? '#00ffff')) ?>" placeholder="#00ffff">
            </div>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📏 Height (px)</label>
            <input type="number" name="statusbar_height" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_height'] ?? '40') ?>" min="20" max="100">
        </div>
        <div class="form-group">
            <label class="form-label">📐 Width</label>
            <input type="text" name="statusbar_width" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_width'] ?? '100%') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">📦 Padding</label>
            <input type="text" name="statusbar_padding" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_padding'] ?? '10px') ?>">
        </div>
        <div class="form-group">
            <label class="form-label">⏹️ Block Shape</label>
            <select name="statusbar_shape" class="form-select">
                <option value="square" <?= ($latestConfig['wgt_statusbar_shape'] ?? 'rounded') === 'square' ? 'selected' : '' ?>>Square</option>
                <option value="rounded" <?= ($latestConfig['wgt_statusbar_shape'] ?? 'rounded') === 'rounded' ? 'selected' : '' ?>>Rounded (5px)</option>
                <option value="round" <?= ($latestConfig['wgt_statusbar_shape'] ?? 'rounded') === 'round' ? 'selected' : '' ?>>Round (15px)</option>
                <option value="pill" <?= ($latestConfig['wgt_statusbar_shape'] ?? 'rounded') === 'pill' ? 'selected' : '' ?>>Pill (Circle)</option>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🔤 Font Family</label>
            <select name="statusbar_font_family" class="form-select">
                <option value="Arial, sans-serif" <?= ($latestConfig['wgt_statusbar_font_family'] ?? '') === 'Arial, sans-serif' ? 'selected' : '' ?>>Arial</option>
                <option value="Rajdhani" <?= ($latestConfig['wgt_statusbar_font_family'] ?? '') === 'Rajdhani' ? 'selected' : '' ?>>Rajdhani</option>
                <option value="Inter" <?= ($latestConfig['wgt_statusbar_font_family'] ?? '') === 'Inter' ? 'selected' : '' ?>>Inter</option>
                <option value="Roboto" <?= ($latestConfig['wgt_statusbar_font_family'] ?? '') === 'Roboto' ? 'selected' : '' ?>>Roboto</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">🎯 Font Size (px)</label>
            <input type="number" name="statusbar_font_size" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_font_size'] ?? '14') ?>" min="8" max="24">
        </div>
        <div class="form-group">
            <label class="form-label">🌟 Opacity (%)</label>
            <input type="number" name="statusbar_opacity" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_opacity'] ?? '90') ?>" min="0" max="100">
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
             <label class="form-label">✨ Glow Effect</label>
             <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                 <input type="checkbox" name="statusbar_glow_enabled" id="statusbar_glow_enabled" <?= ($latestConfig['wgt_statusbar_glow_enabled'] ?? false) ? 'checked' : '' ?>>
                 <label for="statusbar_glow_enabled">Enable Glow</label>
             </div>
        </div>
        <div class="form-group">
            <label class="form-label">🌈 Glow Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="statusbar_glow_picker" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_glow_color'] ?? '#00ffff') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="statusbar_glow_color" id="statusbar_glow_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_glow_color'] ?? '#00ffff') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">💡 Glow Intensity (px)</label>
            <input type="number" name="statusbar_glow_intensity" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_statusbar_glow_intensity'] ?? '5') ?>" min="1" max="20">
        </div>
    </div>

    <div class="form-row">

        <div class="form-group">
            <label class="form-label">⏰ Notice Duration (seconds):</label>
            <input type="number" name="notice_duration" value="<?= htmlspecialchars($latestConfig['wgt_notice_duration_seconds'] ?? 5) ?>" 
                   class="form-input" min="1" max="30">
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Animation Theme:</label>
            <select name="animation_theme" class="form-select">
                <option value="cyber" <?= ($latestConfig['wgt_animation_theme'] ?? '') === 'cyber' ? 'selected' : '' ?>>Cyber (Default)</option>
                <option value="matrix" <?= ($latestConfig['wgt_animation_theme'] ?? '') === 'matrix' ? 'selected' : '' ?>>Matrix</option>
                <option value="neon" <?= ($latestConfig['wgt_animation_theme'] ?? '') === 'neon' ? 'selected' : '' ?>>Neon</option>
                <option value="minimal" <?= ($latestConfig['wgt_animation_theme'] ?? '') === 'minimal' ? 'selected' : '' ?>>Minimal</option>
            </select>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group full-width">
            <label class="form-label">📍 Widget Positions:</label>
            <select name="positions" class="form-select">
                <option value="auto" <?= ($latestConfig['wgt_positions_mode'] ?? '') === 'auto' ? 'selected' : '' ?>>Auto-Detect Best Positions</option>
                <option value="manual" <?= ($latestConfig['wgt_positions_mode'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual Configuration</option>
                <option value="floating" <?= ($latestConfig['wgt_positions_mode'] ?? '') === 'floating' ? 'selected' : '' ?>>Floating Elements</option>
                <option value="integrated" <?= ($latestConfig['wgt_positions_mode'] ?? '') === 'integrated' ? 'selected' : '' ?>>Integrated in Layout</option>
            </select>
        </div>
    </div>
    
    <button type="submit" class="btn">💾 Save Widget Settings</button>

<div class="form-container" style="margin-top:20px;">
    <div class="section-header"><h3>BACK TO TOP CONFIGURATION</h3></div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📍 Placement</label>
            <select name="backtotop_placement" class="form-select">
                <option value="top-left" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'top-left' ? 'selected' : '' ?>>Top Left</option>
                <option value="top-center" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'top-center' ? 'selected' : '' ?>>Top Center</option>
                <option value="top-right" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'top-right' ? 'selected' : '' ?>>Top Right</option>
                <option value="bottom-left" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'bottom-left' ? 'selected' : '' ?>>Bottom Left</option>
                <option value="bottom-center" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'bottom-center' ? 'selected' : '' ?>>Bottom Center</option>
                <option value="bottom-right" <?= ($latestConfig['wgt_backtotop_placement'] ?? 'bottom-right') === 'bottom-right' ? 'selected' : '' ?>>Bottom Right</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">📏 Size (px)</label>
            <input type="number" name="backtotop_size" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_size'] ?? '40') ?>" min="20" max="100">
        </div>
        <div class="form-group">
            <label class="form-label">⏹️ Shape</label>
            <select name="backtotop_shape" class="form-select">
                <option value="circle" <?= ($latestConfig['wgt_backtotop_shape'] ?? 'circle') === 'circle' ? 'selected' : '' ?>>Circle</option>
                <option value="square" <?= ($latestConfig['wgt_backtotop_shape'] ?? 'circle') === 'square' ? 'selected' : '' ?>>Square</option>
                <option value="rounded" <?= ($latestConfig['wgt_backtotop_shape'] ?? 'circle') === 'rounded' ? 'selected' : '' ?>>Rounded</option>
            </select>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">⬆️ Arrow Type</label>
            <select name="backtotop_arrow_type" class="form-select">
                <option value="chevron" <?= ($latestConfig['wgt_backtotop_arrow_type'] ?? 'chevron') === 'chevron' ? 'selected' : '' ?>>Chevron (Wrong)</option>
                <option value="simple" <?= ($latestConfig['wgt_backtotop_arrow_type'] ?? 'chevron') === 'simple' ? 'selected' : '' ?>>Simple Arrow</option>
                <option value="double" <?= ($latestConfig['wgt_backtotop_arrow_type'] ?? 'chevron') === 'double' ? 'selected' : '' ?>>Double Chevron</option>
                <option value="arrow-up" <?= ($latestConfig['wgt_backtotop_arrow_type'] ?? 'chevron') === 'arrow-up' ? 'selected' : '' ?>>Full Arrow</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Background Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="backtotop_bg_picker" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_bg_color'] ?? '#00ffff') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="backtotop_bg_color" id="backtotop_bg_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_bg_color'] ?? '#00ffff') ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Arrow Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="backtotop_arrow_picker" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_arrow_color'] ?? '#000000') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="backtotop_arrow_color" id="backtotop_arrow_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_arrow_color'] ?? '#000000') ?>">
            </div>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">✨ Animation</label>
            <select name="backtotop_animation" class="form-select">
                <option value="fade" <?= ($latestConfig['wgt_backtotop_animation'] ?? 'fade') === 'fade' ? 'selected' : '' ?>>Fade</option>
                <option value="slide" <?= ($latestConfig['wgt_backtotop_animation'] ?? 'fade') === 'slide' ? 'selected' : '' ?>>Slide</option>
                <option value="zoom" <?= ($latestConfig['wgt_backtotop_animation'] ?? 'fade') === 'zoom' ? 'selected' : '' ?>>Zoom</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">⏱️ Scroll Threshold (px)</label>
            <input type="number" name="backtotop_scroll_threshold" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_scroll_threshold'] ?? '300') ?>" min="0">
        </div>
        <div class="form-group">
            <label class="form-label">⏳ Transition Duration (s)</label>
            <input type="number" step="0.1" name="backtotop_transition_duration" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_backtotop_transition_duration'] ?? '0.3') ?>" min="0.1" max="2.0">
            <button type="submit" class="btn">💾 Save Back to Top Settings</button>
        </div>
    </div>

    <div class="section-header"><h3>ICON WIDGET</h3></div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🔧 Base Icon Size (px)</label>
            <input type="number" name="wgt_icon_size" class="form-input" min="10" max="64" value="<?= htmlspecialchars($latestConfig['wgt_icon_size'] ?? 18) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Icon Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="wgt_icon_color_picker" value="<?= htmlspecialchars($latestConfig['wgt_icon_color'] ?? '#00ffff') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="wgt_icon_color" id="wgt_icon_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_icon_color'] ?? '#00ffff') ?>" placeholder="#00ffff">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🎨 Icon Hover Color</label>
            <div style="display:flex;gap:10px;align-items:center;">
                <input type="color" id="wgt_icon_hover_color_picker" value="<?= htmlspecialchars($latestConfig['wgt_icon_hover_color'] ?? '#ffffff') ?>" style="width:40px;height:40px;border:none;background:transparent;">
                <input type="text" name="wgt_icon_hover_color" id="wgt_icon_hover_color" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_icon_hover_color'] ?? '#ffffff') ?>" placeholder="#ffffff">
            </div>
        </div>
    </div>
    <script>
    (function(){
        function syncColor(colorInputId, textInputId){
            var c = document.getElementById(colorInputId);
            var t = document.getElementById(textInputId);
            if(!c || !t) return;
            c.addEventListener('input', function(){ t.value = c.value; });
            t.addEventListener('input', function(){
                var v = t.value.trim();
                if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) { c.value = v; }
            });
        }
        syncColor('wgt_icon_color_picker','wgt_icon_color');
        syncColor('wgt_icon_hover_color_picker','wgt_icon_hover_color');
        syncColor('statusbar_bg_picker','statusbar_background_color');
        syncColor('statusbar_text_picker','statusbar_text_color');
        syncColor('statusbar_glow_picker','statusbar_glow_color');
        syncColor('backtotop_bg_picker','backtotop_bg_color');
        syncColor('backtotop_arrow_picker','backtotop_arrow_color');
    })();
    </script>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📚 Icon Library</label>
            <?php $defaultSet = $latestConfig['wgt_icon_default_set'] ?? 'fontawesome'; ?>
            <select name="icon_default_set" id="icon_default_set" class="form-select">
                <option value="fontawesome" <?= $defaultSet === 'fontawesome' ? 'selected' : '' ?>>Font Awesome</option>
                <option value="iconoir" <?= $defaultSet === 'iconoir' ? 'selected' : '' ?>>Iconoir</option>
                <option value="phosphor" <?= $defaultSet === 'phosphor' ? 'selected' : '' ?>>Phosphor</option>
                <option value="feather" <?= $defaultSet === 'feather' ? 'selected' : '' ?>>Feather</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">✅ Enabled Icon Sets</label>
            <?php $sets = isset($latestConfig['wgt_icon_sets']) ? explode(',', (string)$latestConfig['wgt_icon_sets']) : ['fontawesome','iconoir','phosphor','feather']; ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <?php foreach(['fontawesome','iconoir','phosphor','feather'] as $set): ?>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="icon_sets[]" value="<?= $set ?>" <?= in_array($set, $sets, true) ? 'checked' : '' ?>>
                        <span style="min-width:90px;"><?= ucfirst($set) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🧩 Icon Grid Columns</label>
            <input type="number" name="wgt_icon_grid_columns" class="form-input" min="4" max="16" value="<?= htmlspecialchars($latestConfig['wgt_icon_grid_columns'] ?? 8) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">📏 Icon Grid Height</label>
            <input type="text" name="wgt_icon_height" class="form-input" value="<?= htmlspecialchars($latestConfig['wgt_icon_height'] ?? '300px') ?>">
        </div>
    </div>
    <div class="form-row">
        <?php $current = $latestConfig['wgt_icon_default_set'] ?? 'fontawesome'; ?>
        <div class="form-group full-width">
            <div id="icon_set_info_fontawesome" style="display: <?= $current==='fontawesome' ? 'block':'none' ?>;">
                <label class="form-label">Font Awesome</label>
                <div class="form-input" style="padding:12px;">Uses local CSS classes from `assets/icons/all.min.css`</div>
            </div>
            <div id="icon_set_info_iconoir" style="display: <?= $current==='iconoir' ? 'block':'none' ?>;">
                <label class="form-label">Iconoir</label>
                <div class="form-input" style="padding:12px;">Uses local SVGs from `assets/icons/iconoir/icons/regular/`</div>
            </div>
            <div id="icon_set_info_phosphor" style="display: <?= $current==='phosphor' ? 'block':'none' ?>;">
                <label class="form-label">Phosphor</label>
                <div class="form-input" style="padding:12px;">Uses local SVGs from `assets/icons/phosphor/SVGs/regular/`</div>
            </div>
            <div id="icon_set_info_feather" style="display: <?= $current==='feather' ? 'block':'none' ?>;">
                <label class="form-label">Feather</label>
                <div class="form-input" style="padding:12px;">Uses local SVGs from `assets/icons/feather/`</div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var sel = document.getElementById('icon_default_set');
        function update(){
            var v = sel.value||'fontawesome';
            ['fontawesome','iconoir','phosphor','feather'].forEach(function(k){
                var el = document.getElementById('icon_set_info_'+k);
                if(el){ el.style.display = (k===v)?'block':'none'; }
            });
        }
        if(sel){ sel.addEventListener('change', update); update(); }
    })();
    </script>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📐 Realms Size Multiplier</label>
            <input type="number" step="0.05" name="wgt_icon_size_multiplier_realms" class="form-input" min="0.5" max="3" value="<?= htmlspecialchars($latestConfig['wgt_icon_size_multiplier_realms'] ?? 1.0) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">📐 Menus Size Multiplier</label>
            <input type="number" step="0.05" name="wgt_icon_size_multiplier_menus" class="form-input" min="0.5" max="3" value="<?= htmlspecialchars($latestConfig['wgt_icon_size_multiplier_menus'] ?? 1.0) ?>">
        </div>
        <div class="form-group">
            <label class="form-label">📐 Submenus Size Multiplier</label>
            <input type="number" step="0.05" name="wgt_icon_size_multiplier_submenus" class="form-input" min="0.5" max="3" value="<?= htmlspecialchars($latestConfig['wgt_icon_size_multiplier_submenus'] ?? 0.85) ?>">
        </div>
    </div>
    <div class="form-row" style="margin-top: 20px;">
        <div class="form-group full-width" style="text-align:right;">
            <button type="submit" class="btn">💾 Save Icon Settings</button>
        </div>
    </div>
</div>
</form>

<div class="preview-section">
    <h4 class="preview-header">🔍 Active Widgets Preview</h4>
    <div class="preview-container">
        <?php
        if (!function_exists('renderWidgetsPreview')) {
        function renderWidgetsPreview($config) {
            $html = "<div style='padding: 20px; background: #0a0a1a; border-radius: 10px;'>";
            
            $enabledWidgets = [];
            foreach(['loader', 'notices', 'dragdrop', 'icons', 'metahuman_overlay', 'animations'] as $widget) {
                if($config['wgt_' . $widget . '_enabled'] ?? false) {
                    $enabledWidgets[] = $widget;
                }
            }
            
            if(empty($enabledWidgets)) {
                return '<div style="padding: 20px; text-align: center; color: #666;">No widgets enabled</div>';
            }
            
            $html .= "<h5 style='color: #00ffff; margin-bottom: 15px;'>Enabled Widgets (" . count($enabledWidgets) . "):</h5>";
            
            foreach($enabledWidgets as $widget) {
                $widgetInfo = [
                    'loader' => ['icon' => '⏳', 'status' => 'Loading animations active'],
                    'notices' => ['icon' => '📢', 'status' => 'Notifications enabled'],

                    'dragdrop' => ['icon' => '📁', 'status' => 'Drag & drop file uploads'],
                    'icons' => ['icon' => '🎨', 'status' => 'Icon library available'],
                    'metahuman_overlay' => ['icon' => '🧠', 'status' => 'Meta Human overlay widget active'],
                    'animations' => ['icon' => '✨', 'status' => ucfirst($config['wgt_animation_theme'] ?? 'cyber') . ' theme animations']
                ];
                
                $info = $widgetInfo[$widget] ?? ['icon' => '🔧', 'status' => 'Widget active'];
                
                $html .= "<div style='display: flex; align-items: center; padding: 10px; margin: 5px 0; background: rgba(0, 255, 255, 0.1); border-radius: 8px; border-left: 4px solid #00ffff;'>";
                $html .= "<span style='font-size: 1.2em; margin-right: 10px;'>" . $info['icon'] . "</span>";
                $html .= "<div>";
                $html .= "<strong style='color: #00ffff;'>" . ucfirst($widget) . " Widget</strong><br>";
                $html .= "<small style='color: #aaffff;'>" . $info['status'] . "</small>";
                $html .= "</div>";
                $html .= "</div>";
            }
            
            $html .= "</div>";
            return $html;
        }
        
        echo renderWidgetsPreview($latestConfig);
        }
        ?>
    </div>
</div>

<div class="config-display">
    <h4>⚙️ Current Configuration</h4>
    <pre><?= json_encode($latestConfig, JSON_PRETTY_PRINT) ?></pre>
</div>

<?php
if ($isStandalone) {
    echo '</div>';
    include_once __DIR__ . '/includes/complete-body-end.php';
    echo '</body></html>';
}
?>
