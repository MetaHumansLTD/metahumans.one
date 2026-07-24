<?php
/**
 * Theme & Layout Manager
 * @requires CUE Framework
 */
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

// Helper for hex to rgba
if (!function_exists('hex2rgba')) {
    function hex2rgba($color, $opacity = false) {
        $default = 'rgb(0,0,0)';
        if(empty($color)) return $default; 
        if ($color[0] == '#' ) {
            $color = substr( $color, 1 );
        }
        if (strlen($color) == 6) {
                $hex = array( $color[0] . $color[1], $color[2] . $color[3], $color[4] . $color[5] );
        } elseif ( strlen( $color ) == 3 ) {
                $hex = array( $color[0] . $color[0], $color[1] . $color[1], $color[2] . $color[2] );
        } else {
                return $default;
        }
        $rgb =  array_map('hexdec', $hex);
        if($opacity){
            if(abs($opacity) > 1)
                $opacity = 1.0;
            $output = 'rgba('.implode(",",$rgb).','.$opacity.')';
        } else {
            $output = 'rgb('.implode(",",$rgb).')';
        }
        return $output;
    }
}

$configPath = getDataPath() . '/theme/config.json';
$config = [];
$noticeScript = null;

// Load existing config and map to form fields
if(file_exists($configPath)) {
    $savedConfig = json_decode(file_get_contents($configPath), true) ?: [];
    
    // Check for nested CUE configuration structure
    if (isset($savedConfig['K::ThemeUI::Configuration'])) {
        // Get the first config block (latest)
        $configBlock = reset($savedConfig['K::ThemeUI::Configuration']);
        if ($configBlock) {
            // Map thm_ keys to simple keys for the form
            $config = [
                'primary_color' => $configBlock['thm_primary_color'] ?? '#00ffff',
                'secondary_color' => $configBlock['thm_secondary_color'] ?? '#0080ff',
                'background_color' => $configBlock['thm_background_color'] ?? '#0a0a1a',
                'surface_color' => $configBlock['thm_surface_color'] ?? '#1a1a2e',
                'text_color' => $configBlock['thm_text_color'] ?? '#ffffff',
                'text_secondary' => $configBlock['thm_text_secondary'] ?? 'rgba(255, 255, 255, 0.7)',
                'heading_color' => $configBlock['thm_heading_color'] ?? '#ffffff',
                'accent_color' => $configBlock['thm_accent_color'] ?? '#ff6600',
                'layout_mode' => $configBlock['thm_layout_mode'] ?? 'fluid',
                'glassmorphism' => $configBlock['thm_glassmorphism_enabled'] ?? true,
                'dark_mode' => $configBlock['thm_dark_mode_enabled'] ?? true,
                'animations_enabled' => $configBlock['thm_animations_enabled'] ?? true,
                'rounded_corners' => $configBlock['thm_rounded_corners_enabled'] ?? true,
                'shadows_enabled' => $configBlock['thm_shadows_enabled'] ?? true,
                'gradient_backgrounds' => $configBlock['thm_gradient_backgrounds_enabled'] ?? true,
                'sidebar_width' => $configBlock['thm_sidebar_width_pixels'] ?? '280',
                'content_max_width' => $configBlock['thm_content_max_width_pixels'] ?? '1200',
                'font_family' => $configBlock['thm_font_family'] ?? 'system',
                'font_size' => $configBlock['thm_font_size_pixels'] ?? '14',
                'line_height' => $configBlock['thm_line_height_ratio'] ?? '1.6',
                // Typography extended
                'font_family_primary' => $configBlock['thm_font_family_primary'] ?? 'system',
                'font_family_heading' => $configBlock['thm_font_family_heading'] ?? 'inherit',
                'font_family_mono' => $configBlock['thm_font_family_mono'] ?? 'system-mono',
                'font_size_base' => $configBlock['thm_font_size_base'] ?? '14px',
            ];
        }
    } else {
        // Fallback for flat config if it exists
        $config = $savedConfig;
    }
}

// Handle theme reset
if(isset($_POST['action']) && $_POST['action'] === 'reset_theme') {
    // Delete the config file to reset to defaults
    if(file_exists($configPath)) {
        if(unlink($configPath)) {
            $config = []; // Clear current config to force defaults
            $noticeScript = "(window.globalPopupNotice || window.popupNotice).success('✅ Theme has been reset to default settings!');";
        } else {
            $noticeScript = "(window.globalPopupNotice || window.popupNotice).error('❌ Error resetting theme. Please check file permissions.');";
        }
    } else {
        $noticeScript = "(window.globalPopupNotice || window.popupNotice).info('ℹ️ Theme is already at default settings.');";
    }
}

// Handle form submission
if(isset($_POST['action']) && $_POST['action'] === 'save_theme') {
    $configId = 'K::ThemeUI::Content::' . strtoupper(uniqid());
    
    // Prepare values for saving
    $saveValues = [
        'thm_primary_color' => $_POST['primary_color'] ?? '#00ffff',
        'thm_secondary_color' => $_POST['secondary_color'] ?? '#0080ff',
        'thm_background_color' => $_POST['background_color'] ?? '#0a0a1a',
        'thm_surface_color' => $_POST['surface_color'] ?? '#1a1a2e',
        'thm_text_color' => $_POST['text_color'] ?? '#ffffff',
        'thm_text_secondary' => $_POST['text_secondary'] ?? 'rgba(255, 255, 255, 0.7)',
        'thm_heading_color' => $_POST['heading_color'] ?? '#ffffff',
        'thm_accent_color' => $_POST['accent_color'] ?? '#ff6600',
        'thm_layout_mode' => $_POST['layout_mode'] ?? 'fluid',
        'thm_glassmorphism_enabled' => isset($_POST['glassmorphism']),
        'thm_dark_mode_enabled' => isset($_POST['dark_mode']),
        'thm_animations_enabled' => isset($_POST['animations_enabled']),
        'thm_rounded_corners_enabled' => isset($_POST['rounded_corners']),
        'thm_shadows_enabled' => isset($_POST['shadows_enabled']),
        'thm_gradient_backgrounds_enabled' => isset($_POST['gradient_backgrounds']),
        'thm_sidebar_width_pixels' => (int)($_POST['sidebar_width'] ?? '280'),
        'thm_content_max_width_pixels' => (int)($_POST['content_max_width'] ?? '1200'),
        'thm_font_family' => $_POST['font_family'] ?? 'system',
        'thm_font_size_pixels' => (int)($_POST['font_size'] ?? '14'),
        'thm_line_height_ratio' => (float)($_POST['line_height'] ?? '1.6'),
        // Typography extended
        'thm_font_family_primary' => $_POST['font_family_primary'] ?? 'system',
        'thm_font_family_heading' => $_POST['font_family_heading'] ?? 'inherit',
        'thm_font_family_mono' => $_POST['font_family_mono'] ?? 'system-mono',
        'thm_font_size_base' => $_POST['font_size_base'] ?? '14px',
        'thm_last_updated' => date('Y-m-d H:i:s')
    ];

    $newConfig = [
        'K::ThemeUI::Configuration' => [
            $configId => $saveValues
        ]
    ];
    
    // Ensure directory exists
    $dir = dirname($configPath);
    if(!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    if(file_put_contents($configPath, json_encode($newConfig, JSON_PRETTY_PRINT))) {
        // Map back to flat config for UI immediately
        $config = [
            'primary_color' => $saveValues['thm_primary_color'],
            'secondary_color' => $saveValues['thm_secondary_color'],
            'background_color' => $saveValues['thm_background_color'],
            'surface_color' => $saveValues['thm_surface_color'],
            'text_color' => $saveValues['thm_text_color'],
            'text_secondary' => $saveValues['thm_text_secondary'],
            'heading_color' => $saveValues['thm_heading_color'],
            'accent_color' => $saveValues['thm_accent_color'],
            'layout_mode' => $saveValues['thm_layout_mode'],
            'glassmorphism' => $saveValues['thm_glassmorphism_enabled'],
            'dark_mode' => $saveValues['thm_dark_mode_enabled'],
            'animations_enabled' => $saveValues['thm_animations_enabled'],
            'rounded_corners' => $saveValues['thm_rounded_corners_enabled'],
            'shadows_enabled' => $saveValues['thm_shadows_enabled'],
            'gradient_backgrounds' => $saveValues['thm_gradient_backgrounds_enabled'],
            'sidebar_width' => $saveValues['thm_sidebar_width_pixels'],
            'content_max_width' => $saveValues['thm_content_max_width_pixels'],
            'font_family' => $saveValues['thm_font_family'],
            'font_size' => $saveValues['thm_font_size_pixels'],
            'line_height' => $saveValues['thm_line_height_ratio'],
            'font_family_primary' => $saveValues['thm_font_family_primary'],
            'font_family_heading' => $saveValues['thm_font_family_heading'],
            'font_family_mono' => $saveValues['thm_font_family_mono'],
            'font_size_base' => $saveValues['thm_font_size_base']
        ];
        $noticeScript = "(window.globalPopupNotice || window.popupNotice).success('✅ Theme and layout settings saved successfully!');";
    } else {
        $noticeScript = "(window.globalPopupNotice || window.popupNotice).error('❌ Error saving theme settings. Please check file permissions.');";
    }
}

// Set defaults if config is empty
if(empty($config)) {
    $config = [
        'primary_color' => '#00ffff',
        'secondary_color' => '#0080ff',
        'background_color' => '#0a0a1a',
        'surface_color' => '#1a1a2e',
        'text_color' => '#ffffff',
        'accent_color' => '#ff6600',
        'layout_mode' => 'fluid',
        'glassmorphism' => true,
        'dark_mode' => true,
        'animations_enabled' => true,
        'rounded_corners' => true,
        'shadows_enabled' => true,
        'gradient_backgrounds' => true,
        'sidebar_width' => '280',
        'content_max_width' => '1200',
        'font_family' => 'system',
        'font_size' => '14',
        'line_height' => '1.6'
    ];
}

// Check if this file is being accessed directly (not included)
$isStandalone = !isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED']);
if ($isStandalone) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Theme Manager - CUE Framework</title>';
    includeNoticesWidget();

    // Apply CUE Framework styling from theme.php
    cue_autoload('theme');
    
    // Create compatible config structure for theme module
    $themeConfig = [
        'colors' => [
            'primary' => $config['primary_color'] ?? '#00ffff',
            'secondary' => $config['secondary_color'] ?? '#0080ff',
            'accent' => $config['accent_color'] ?? '#ff6600',
            'background' => $config['background_color'] ?? '#0a0a1a',
            'surface' => $config['surface_color'] ?? '#1a1a2e',
            'text' => $config['text_color'] ?? '#ffffff',
            'text_secondary' => $config['text_secondary'] ?? 'rgba(255, 255, 255, 0.7)',
            'heading' => $config['heading_color'] ?? '#ffffff'
        ],
        'fonts' => [
            'primary' => $config['font_family_primary'] ?? 'system',
            'heading' => $config['font_family_heading'] ?? 'inherit',
            'mono' => $config['font_family_mono'] ?? 'system-mono'
        ],
        'layout' => [
            'sidebar_width' => ($config['sidebar_width'] ?? '280') . 'px',
            'max_width' => ($config['content_max_width'] ?? '1200') . 'px'
        ],
        'glassmorphism' => $config['glassmorphism'] ?? true,
        'dark_mode' => $config['dark_mode'] ?? true
    ];
    
    theme_applyCueFrameworkStyling(null, $themeConfig);
    
    echo '<style>';
    // Dynamic Body Styling
    $bodyFont = ($config['font_family'] === 'system' || empty($config['font_family'])) 
        ? '"Segoe UI", Tahoma, Geneva, Verdana, sans-serif' 
        : $config['font_family'];
        
    $bgColor = $config['background_color'] ?? '#0a0a1a';
    $surfaceColor = $config['surface_color'] ?? '#1a1a2e';
    $textColor = $config['text_color'] ?? '#ffffff';
    $primaryColor = $config['primary_color'] ?? '#00ffff';
    
    echo "body { font-family: {$bodyFont}; background: linear-gradient(135deg, {$bgColor} 0%, {$surfaceColor} 100%); color: {$textColor}; margin: 0; padding: 20px; min-height: 100vh; }";
    
    // Dynamic Component Styling
    echo "h1 { color: {$primaryColor} !important; text-shadow: 0 0 20px " . hex2rgba($primaryColor, 0.5) . " !important; }";
    echo ".theme-manager-wrapper .form-label { color: {$primaryColor} !important; }";
    echo ".theme-manager-wrapper .form-input:focus, .theme-manager-wrapper .form-select:focus { border-color: {$primaryColor} !important; box-shadow: 0 0 15px " . hex2rgba($primaryColor, 0.4) . " !important; }";

    echo '</style></head><body>';

    // Force a realm context for the theme manager if none exists (required for menus to load)
    if ((!isset($_SESSION['current_realm']) || empty($_SESSION['current_realm'])) && function_exists('cue_autoload')) {
        try {
            require_once __DIR__ . '/../menus/navigation-database-manager.php';
            if (class_exists('NavigationDatabaseManager')) {
                $navMgr = new NavigationDatabaseManager();
                $realms = $navMgr->getRealms();
                
                // Try to find Kripz Masters or Home first
                $foundRealm = null;
                if ($realms) {
                    foreach ($realms as $rid => $r) {
                        if (($r->name ?? '') === 'Kripz Masters' || ($r->name ?? '') === 'Home') {
                            $foundRealm = $rid;
                            break;
                        }
                    }
                    // Fallback to first active
                    if (!$foundRealm) {
                        foreach ($realms as $rid => $r) {
                            if (($r->status ?? 'active') === 'active') {
                                $foundRealm = $rid;
                                break;
                            }
                        }
                    }
                }
                
                if ($foundRealm) {
                    $_SESSION['current_realm'] = $foundRealm;
                }
            }
        } catch (Exception $e) {
            // Ignore errors in realm detection
        }
    }

    // Include the global header and hamburger
    $headerInclude = __DIR__ . '/includes/header.php';
    if (file_exists($headerInclude)) {
        include_once $headerInclude;
    }
    
    $hamburgerInclude = __DIR__ . '/includes/hamburger.php';
    if (file_exists($hamburgerInclude) && empty($GLOBALS['_GLOBAL_HAMBURGER_INCLUDED'])) {
        include_once $hamburgerInclude;
    }

    echo '<h1 style="text-align: center; margin-bottom: 30px;">🎨 Theme & Layout Manager</h1>';
}

// Add reset theme JavaScript function - Always available
echo '<script>';
echo 'function resetTheme() {';
echo '  if (confirm("Are you sure you want to reset the theme to default settings? This action cannot be undone.")) {';
echo '    const form = document.createElement("form");';
echo '    form.method = "POST";';
echo '    form.style.display = "none";';
echo '    const actionInput = document.createElement("input");';
echo '    actionInput.type = "hidden";';
echo '    actionInput.name = "action";';
echo '    actionInput.value = "reset_theme";';
echo '    form.appendChild(actionInput);';
echo '    document.body.appendChild(form);';
echo '    form.submit();';
echo '  }';
echo '}';
echo '</script>';

// Component styles - Always output these so they work when included
echo '<style>';
$pColor = $config['primary_color'] ?? '#00ffff';
$sColor = $config['secondary_color'] ?? '#0080ff';
$tColor = $config['text_color'] ?? '#ffffff';
$eColor = $config['error_color'] ?? '#ff4444';

echo ".theme-manager-wrapper { font-family: \"Segoe UI\", Tahoma, Geneva, Verdana, sans-serif; color: {$tColor}; }";
echo ".theme-manager-wrapper .form-container { max-width: 1200px; margin: 0 auto; background: rgba(255,255,255,0.05); padding: 30px; border-radius: 15px; backdrop-filter: blur(10px); border: 1px solid " . hex2rgba($pColor, 0.2) . "; }";
echo '.theme-manager-wrapper .form-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }';
echo '.theme-manager-wrapper .form-group { flex: 1; min-width: 280px; }';
echo '.theme-manager-wrapper .form-group.full-width { flex: 100%; }';
echo ".theme-manager-wrapper .form-label { display: block; margin-bottom: 8px; font-weight: 600; color: {$pColor}; font-size: 14px; }";
echo ".theme-manager-wrapper .form-input, .theme-manager-wrapper .form-select { width: 100%; padding: 12px 15px; border: 1px solid " . hex2rgba($pColor, 0.3) . "; border-radius: 8px; background: rgba(255,255,255,0.1); color: {$tColor}; font-size: 14px; transition: all 0.3s ease; box-sizing: border-box; }";
echo ".theme-manager-wrapper .form-input:focus, .theme-manager-wrapper .form-select:focus { outline: none; border-color: {$pColor}; box-shadow: 0 0 15px " . hex2rgba($pColor, 0.4) . "; }";
echo '.theme-manager-wrapper .form-checkbox { margin-right: 8px; }';
echo '.theme-manager-wrapper .btn, .theme-manager-wrapper .save-button, .theme-manager-wrapper .reset-button { padding: 15px 30px; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 16px; transition: all 0.3s ease; text-shadow: none; }';
echo ".theme-manager-wrapper .save-button { background: linear-gradient(135deg, {$pColor} 0%, {$sColor} 100%); color: #000; }";
echo ".theme-manager-wrapper .save-button:hover { transform: translateY(-2px); box-shadow: 0 10px 25px " . hex2rgba($pColor, 0.4) . "; }";
echo ".theme-manager-wrapper .reset-button { background: linear-gradient(135deg, {$eColor}, #cc0000); color: white; }";
echo ".theme-manager-wrapper .reset-button:hover { transform: translateY(-2px); box-shadow: 0 10px 25px " . hex2rgba($eColor, 0.4) . "; }";
echo ".theme-manager-wrapper .section-header { background: " . hex2rgba($pColor, 0.1) . "; padding: 15px; border-radius: 10px; margin: 30px 0 20px; }";
echo ".theme-manager-wrapper .section-header h3 { color: {$pColor}; margin: 0; }";
echo '.theme-manager-wrapper .config-display { margin-top: 30px; background: rgba(255,255,255,0.05); padding: 20px; border-radius: 10px; }';
echo ".theme-manager-wrapper .dark-theme-btn { background: linear-gradient(135deg, #1a1a2e, #16213e); color: #00d4ff; padding: 15px 30px; border: 2px solid #00d4ff; border-radius: 10px; font-weight: bold; margin-right: 15px; }";
echo ".theme-manager-wrapper .dark-theme-btn:hover { transform: translateY(-2px); box-shadow: 0 0 15px rgba(0, 212, 255, 0.4); }";
echo ".theme-manager-wrapper .description-text { margin: 10px 0; color: " . hex2rgba($tColor, 0.7) . "; font-size: 14px; }";
echo ".theme-manager-wrapper .action-buttons-container { display: flex; gap: 15px; justify-content: center; margin-top: 30px; }";
echo '</style>';

// Ensure Notice Widget is properly positioned
echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        var notice = window.globalPopupNotice || window.popupNotice;
        if(notice && notice.options) {
            // Force position to top-center to ensure visibility within the frame
            notice.options.position = 'top-center';
            if(typeof notice.positionContainer === 'function') {
                notice.positionContainer();
            }
        }
    }, 200);
});
</script>";

if ($noticeScript) {
    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            if(window.globalPopupNotice || window.popupNotice) {
                $noticeScript
            } else {
                console.warn('PopupNotice widget not loaded');
                // Fallback to alert if widget failed to load
                var msg = \"$noticeScript\".match(/['\"](.*?)['\"]/)[1];
                if(msg) alert(msg);
            }
        }, 100);
    });
    </script>";
}
?>

<div class="theme-manager-wrapper">
<form method="post" class="form-container">
    <input type="hidden" name="action" value="save_theme">
    
    <!-- Color Scheme Presets -->
    <div class="section-header">
        <h3>🎨 Color Scheme Presets</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group full-width">
            <label class="form-label">🎭 Quick Color Schemes:</label>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 10px 0;">
                
                <!-- Dark Cyber Theme (from dark-style.json) -->
                <div class="color-scheme-preset" onclick="applyColorScheme('dark-cyber')" 
                     style="background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold; text-shadow: 0 2px 4px rgba(0,0,0,0.5);">Dark Cyber</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Cyan & Purple Tech</div>
                </div>
                
                <!-- Ocean Blue -->
                <div class="color-scheme-preset" onclick="applyColorScheme('ocean-blue')" 
                     style="background: linear-gradient(135deg, #006994 0%, #004d6b 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Ocean Blue</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Professional Blue</div>
                </div>
                
                <!-- Emerald Green -->
                <div class="color-scheme-preset" onclick="applyColorScheme('emerald-green')" 
                     style="background: linear-gradient(135deg, #059669 0%, #064e3b 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Emerald Green</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Nature & Growth</div>
                </div>
                
                <!-- Sunset Orange -->
                <div class="color-scheme-preset" onclick="applyColorScheme('sunset-orange')" 
                     style="background: linear-gradient(135deg, #ea580c 0%, #9a3412 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Sunset Orange</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Warm & Energetic</div>
                </div>
                
                <!-- Royal Purple -->
                <div class="color-scheme-preset" onclick="applyColorScheme('royal-purple')" 
                     style="background: linear-gradient(135deg, #7c3aed 0%, #581c87 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Royal Purple</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Luxury & Elegance</div>
                </div>
                
                <!-- Rose Gold -->
                <div class="color-scheme-preset" onclick="applyColorScheme('rose-gold')" 
                     style="background: linear-gradient(135deg, #e11d48 0%, #be185d 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Rose Gold</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Elegant & Modern</div>
                </div>
                
                <!-- Slate Gray -->
                <div class="color-scheme-preset" onclick="applyColorScheme('slate-gray')" 
                     style="background: linear-gradient(135deg, #475569 0%, #334155 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Slate Gray</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Minimal & Clean</div>
                </div>
                
                <!-- Neon Lime -->
                <div class="color-scheme-preset" onclick="applyColorScheme('neon-lime')" 
                     style="background: linear-gradient(135deg, #65a30d 0%, #365314 100%); padding: 15px; border-radius: 10px; cursor: pointer; transition: all 0.3s ease; border: 2px solid transparent;">
                    <div style="color: white; font-weight: bold;">Neon Lime</div>
                    <div style="color: rgba(255,255,255,0.8); font-size: 12px;">Electric & Fresh</div>
                </div>
                
            </div>
        </div>
    </div>

    <!-- Custom Color Scheme -->
    <div class="section-header">
        <h3>🎨 Custom Color Scheme</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🔵 Primary Color:</label>
            <input type="color" name="primary_color" value="<?= htmlspecialchars($config['primary_color'] ?? '#00ffff') ?>" 
                   class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label">🟡 Secondary Color:</label>
            <input type="color" name="secondary_color" value="<?= htmlspecialchars($config['secondary_color'] ?? '#0080ff') ?>" 
                   class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label">🟠 Accent Color:</label>
            <input type="color" name="accent_color" value="<?= htmlspecialchars($config['accent_color'] ?? '#ff6600') ?>" 
                   class="form-input">
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">⚫ Background Color:</label>
            <input type="color" name="background_color" value="<?= htmlspecialchars($config['background_color'] ?? '#0a0a1a') ?>" 
                   class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label">🔘 Surface Color:</label>
            <input type="color" name="surface_color" value="<?= htmlspecialchars($config['surface_color'] ?? '#1a1a2e') ?>" 
                   class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label">⚪ Text Color:</label>
            <input type="color" name="text_color" value="<?= htmlspecialchars($config['text_color'] ?? '#ffffff') ?>" 
                   class="form-input">
        </div>
        <div class="form-group">
            <label class="form-label">🌑 Secondary Text:</label>
            <div style="display: flex; gap: 10px;">
                <input type="color" name="text_secondary" value="<?= htmlspecialchars($config['text_secondary'] ?? '#cccccc') ?>" 
                       class="form-input" style="flex: 1;">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">🔡 Heading Color:</label>
            <input type="color" name="heading_color" value="<?= htmlspecialchars($config['heading_color'] ?? '#ffffff') ?>" 
                   class="form-input">
        </div>
    </div>
    
    <!-- Layout Settings -->
    <div class="section-header">
        <h3>📐 Layout Settings</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🏗️ Layout Mode:</label>
            <select name="layout_mode" class="form-select">
                <option value="fluid" <?= ($config['layout_mode'] ?? '') === 'fluid' ? 'selected' : '' ?>>Fluid Width</option>
                <option value="fixed" <?= ($config['layout_mode'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Width</option>
                <option value="boxed" <?= ($config['layout_mode'] ?? '') === 'boxed' ? 'selected' : '' ?>>Boxed Layout</option>
                <option value="full" <?= ($config['layout_mode'] ?? '') === 'full' ? 'selected' : '' ?>>Full Width</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">📏 Sidebar Width (px):</label>
            <input type="number" name="sidebar_width" value="<?= htmlspecialchars($config['sidebar_width'] ?? '280') ?>" 
                   class="form-input" min="200" max="400">
        </div>
        <div class="form-group">
            <label class="form-label">📐 Max Content Width (px):</label>
            <input type="number" name="content_max_width" value="<?= htmlspecialchars($config['content_max_width'] ?? '1200') ?>" 
                   class="form-input" min="800" max="2000">
        </div>
    </div>
    
    <!-- Typography -->
    <div class="section-header">
        <h3>🔤 Typography & Fonts</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">🖋️ Primary Font Family:</label>
            <select name="font_family_primary" class="form-select" onchange="previewFont(this.value, 'primary')">
                <option value="system" <?= ($config['font_family_primary'] ?? '') === 'system' ? 'selected' : '' ?>>System Default</option>
                
                <!-- Futuristic/Tech Fonts -->
                <optgroup label="🚀 Futuristic & Tech">
                    <option value="rajdhani" <?= ($config['font_family_primary'] ?? '') === 'rajdhani' ? 'selected' : '' ?>>Rajdhani - Clean Tech</option>
                    <option value="orbitron" <?= ($config['font_family_primary'] ?? '') === 'orbitron' ? 'selected' : '' ?>>Orbitron - Sci-Fi Display</option>
                    <option value="ibm-plex-mono" <?= ($config['font_family_primary'] ?? '') === 'ibm-plex-mono' ? 'selected' : '' ?>>IBM Plex Mono - Code Style</option>
                </optgroup>
                
                <!-- Professional Fonts -->
                <optgroup label="💼 Professional">
                    <option value="inter" <?= ($config['font_family_primary'] ?? '') === 'inter' ? 'selected' : '' ?>>Inter - Modern Sans</option>
                    <option value="open-sans" <?= ($config['font_family_primary'] ?? '') === 'open-sans' ? 'selected' : '' ?>>Open Sans - Clean & Friendly</option>
                    <option value="roboto" <?= ($config['font_family_primary'] ?? '') === 'roboto' ? 'selected' : '' ?>>Roboto - Google Material</option>
                    <option value="lato" <?= ($config['font_family_primary'] ?? '') === 'lato' ? 'selected' : '' ?>>Lato - Humanist Sans</option>
                    <option value="source-sans-3" <?= ($config['font_family_primary'] ?? '') === 'source-sans-3' ? 'selected' : '' ?>>Source Sans 3 - Adobe Sans</option>
                </optgroup>
                
                <!-- Elegant Fonts -->
                <optgroup label="✨ Elegant & Stylish">
                    <option value="poppins" <?= ($config['font_family_primary'] ?? '') === 'poppins' ? 'selected' : '' ?>>Poppins - Geometric</option>
                    <option value="montserrat" <?= ($config['font_family_primary'] ?? '') === 'montserrat' ? 'selected' : '' ?>>Montserrat - Urban</option>
                    <option value="raleway" <?= ($config['font_family_primary'] ?? '') === 'raleway' ? 'selected' : '' ?>>Raleway - Elegant Display</option>
                    <option value="nunito" <?= ($config['font_family_primary'] ?? '') === 'nunito' ? 'selected' : '' ?>>Nunito - Rounded Sans</option>
                    <option value="manrope" <?= ($config['font_family_primary'] ?? '') === 'manrope' ? 'selected' : '' ?>>Manrope - Modern Variable</option>
                </optgroup>
                
                <!-- Classic & Readable -->
                <optgroup label="📚 Classic & Readable">
                    <option value="pt-sans" <?= ($config['font_family_primary'] ?? '') === 'pt-sans' ? 'selected' : '' ?>>PT Sans - Humanist</option>
                    <option value="ubuntu" <?= ($config['font_family_primary'] ?? '') === 'ubuntu' ? 'selected' : '' ?>>Ubuntu - Friendly Tech</option>
                    <option value="noto-sans" <?= ($config['font_family_primary'] ?? '') === 'noto-sans' ? 'selected' : '' ?>>Noto Sans - Universal</option>
                    <option value="work-sans" <?= ($config['font_family_primary'] ?? '') === 'work-sans' ? 'selected' : '' ?>>Work Sans - Optimized</option>
                    <option value="mulish" <?= ($config['font_family_primary'] ?? '') === 'mulish' ? 'selected' : '' ?>>Mulish - Minimalist</option>
                </optgroup>
            </select>
            <div id="primary-font-preview" style="margin-top: 10px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 8px; font-size: 16px;">
                The quick brown fox jumps over the lazy dog. 123456789
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">🎭 Heading Font Family:</label>
            <select name="font_family_heading" class="form-select" onchange="previewFont(this.value, 'heading')">
                <option value="inherit" <?= ($config['font_family_heading'] ?? '') === 'inherit' ? 'selected' : '' ?>>Same as Primary</option>
                
                <!-- Display & Heading Fonts -->
                <optgroup label="🎯 Display & Headers">
                    <option value="orbitron" <?= ($config['font_family_heading'] ?? '') === 'orbitron' ? 'selected' : '' ?>>Orbitron - Futuristic</option>
                    <option value="rajdhani" <?= ($config['font_family_heading'] ?? '') === 'rajdhani' ? 'selected' : '' ?>>Rajdhani - Tech Display</option>
                    <option value="playfair-display" <?= ($config['font_family_heading'] ?? '') === 'playfair-display' ? 'selected' : '' ?>>Playfair Display - Elegant</option>
                    <option value="merriweather" <?= ($config['font_family_heading'] ?? '') === 'merriweather' ? 'selected' : '' ?>>Merriweather - Classic Serif</option>
                    <option value="montserrat" <?= ($config['font_family_heading'] ?? '') === 'montserrat' ? 'selected' : '' ?>>Montserrat - Modern Sans</option>
                    <option value="poppins" <?= ($config['font_family_heading'] ?? '') === 'poppins' ? 'selected' : '' ?>>Poppins - Geometric</option>
                </optgroup>
                
                <!-- Serif Options -->
                <optgroup label="📖 Serif Options">
                    <option value="source-serif-4" <?= ($config['font_family_heading'] ?? '') === 'source-serif-4' ? 'selected' : '' ?>>Source Serif 4 - Adobe</option>
                    <option value="pt-serif" <?= ($config['font_family_heading'] ?? '') === 'pt-serif' ? 'selected' : '' ?>>PT Serif - Transitional</option>
                    <option value="noto-serif" <?= ($config['font_family_heading'] ?? '') === 'noto-serif' ? 'selected' : '' ?>>Noto Serif - Universal</option>
                </optgroup>
            </select>
            <div id="heading-font-preview" style="margin-top: 10px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 8px; font-size: 24px; font-weight: bold;">
                Heading Preview Text
            </div>
        </div>
        
        <div class="form-group">
            <label class="form-label">💻 Monospace Font (Code):</label>
            <select name="font_family_mono" class="form-select" onchange="previewFont(this.value, 'mono')">
                <option value="system-mono" <?= ($config['font_family_mono'] ?? '') === 'system-mono' ? 'selected' : '' ?>>System Monospace</option>
                <option value="ibm-plex-mono" <?= ($config['font_family_mono'] ?? '') === 'ibm-plex-mono' ? 'selected' : '' ?>>IBM Plex Mono - Professional</option>
                <option value="source-code-pro" <?= ($config['font_family_mono'] ?? '') === 'source-code-pro' ? 'selected' : '' ?>>Source Code Pro (if available)</option>
                <option value="ubuntu-mono" <?= ($config['font_family_mono'] ?? '') === 'ubuntu-mono' ? 'selected' : '' ?>>Ubuntu Mono (if available)</option>
            </select>
            <div id="mono-font-preview" style="margin-top: 10px; padding: 15px; background: rgba(0,0,0,0.3); border-radius: 8px; font-family: monospace; font-size: 14px;">
                function example() { return "Hello World!"; }
            </div>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">📏 Base Font Size:</label>
            <select name="font_size_base" class="form-select">
                <option value="14px" <?= ($config['font_size_base'] ?? '') === '14px' ? 'selected' : '' ?>>14px - Small</option>
                <option value="16px" <?= ($config['font_size_base'] ?? '') === '16px' ? 'selected' : '' ?>>16px - Standard</option>
                <option value="18px" <?= ($config['font_size_base'] ?? '') === '18px' ? 'selected' : '' ?>>18px - Large</option>
                <option value="20px" <?= ($config['font_size_base'] ?? '') === '20px' ? 'selected' : '' ?>>20px - Extra Large</option>
            </select>
        </div>
        
        <div class="form-group">
            <label class="form-label">📐 Line Height:</label>
            <select name="line_height_preset" class="form-select" onchange="document.getElementsByName('line_height')[0].value = this.value">
                <option value="">Select Preset...</option>
                <option value="1.3" <?= ($config['line_height'] ?? '') === '1.3' ? 'selected' : '' ?>>1.3 - Tight</option>
                <option value="1.5" <?= ($config['line_height'] ?? '') === '1.5' ? 'selected' : '' ?>>1.5 - Standard</option>
                <option value="1.6" <?= ($config['line_height'] ?? '') === '1.6' ? 'selected' : '' ?>>1.6 - Comfortable</option>
                <option value="1.8" <?= ($config['line_height'] ?? '') === '1.8' ? 'selected' : '' ?>>1.8 - Spacious</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">📊 Font Size (px):</label>
            <input type="number" name="font_size" value="<?= htmlspecialchars($config['font_size'] ?? '14') ?>" 
                   class="form-input" min="10" max="20">
        </div>
        <div class="form-group">
            <label class="form-label">📏 Line Height:</label>
            <input type="number" name="line_height" value="<?= htmlspecialchars($config['line_height'] ?? '1.6') ?>" 
                   class="form-input" min="1.0" max="2.0" step="0.1">
        </div>
    </div>
    
    <!-- Visual Effects -->
    <div class="section-header">
        <h3>✨ Visual Effects</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group full-width">
            <div class="effects-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="dark_mode" id="dark_mode" class="form-checkbox" 
                           <?= ($config['dark_mode'] ?? false) ? 'checked' : '' ?>>
                    <label for="dark_mode" class="checkbox-label">🌙 Dark Mode</label>
                </div>
                
                <div class="form-checkbox-group">
                    <input type="checkbox" name="glassmorphism" id="glassmorphism" class="form-checkbox"
                           <?= ($config['glassmorphism'] ?? false) ? 'checked' : '' ?>>
                    <label for="glassmorphism" class="checkbox-label">🔮 Glassmorphism Effects</label>
                </div>
                
                <div class="form-checkbox-group">
                    <input type="checkbox" name="animations_enabled" id="animations_enabled" class="form-checkbox"
                           <?= ($config['animations_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="animations_enabled" class="checkbox-label">🎭 UI Animations</label>
                </div>
                
                <div class="form-checkbox-group">
                    <input type="checkbox" name="rounded_corners" id="rounded_corners" class="form-checkbox"
                           <?= ($config['rounded_corners'] ?? false) ? 'checked' : '' ?>>
                    <label for="rounded_corners" class="checkbox-label">🔘 Rounded Corners</label>
                </div>
                
                <div class="form-checkbox-group">
                    <input type="checkbox" name="shadows_enabled" id="shadows_enabled" class="form-checkbox"
                           <?= ($config['shadows_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="shadows_enabled" class="checkbox-label">🌑 Drop Shadows</label>
                </div>
                
                <div class="form-checkbox-group">
                    <input type="checkbox" name="gradient_backgrounds" id="gradient_backgrounds" class="form-checkbox"
                           <?= ($config['gradient_backgrounds'] ?? false) ? 'checked' : '' ?>>
                    <label for="gradient_backgrounds" class="checkbox-label">🌈 Gradient Backgrounds</label>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Dark Style Integration -->
    <div class="section-header">
        <h3>🌑 Advanced Theme Presets</h3>
    </div>
    
    <div class="form-row">
        <div class="form-group full-width">
            <button type="button" onclick="loadDarkStyleTheme()" class="dark-theme-btn">
                🌙 Load Complete Dark Style Theme
            </button>
            <p class="description-text">
                Loads the complete dark-style.json configuration with optimized colors, gradients, and typography.
            </p>
        </div>
    </div>
    
    <div class="action-buttons-container">
        <button type="submit" class="save-button">💾 Save Theme & Layout Settings</button>
        <button type="button" class="reset-button" onclick="resetTheme()">🔄 Reset Theme to Defaults</button>
    </div>
</form>

<script>
(function() {
    // Font mapping to CSS font-family values
    const fontMap = {
        'system': 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        'rajdhani': '"Rajdhani", system-ui, sans-serif',
        'orbitron': '"Orbitron", system-ui, sans-serif',
        'ibm-plex-mono': '"IBM Plex Mono", "Consolas", monospace',
        'inter': '"Inter", system-ui, sans-serif',
        'open-sans': '"Open Sans", system-ui, sans-serif',
        'roboto': '"Roboto", system-ui, sans-serif',
        'lato': '"Lato", system-ui, sans-serif',
        'source-sans-3': '"Source Sans 3", system-ui, sans-serif',
        'poppins': '"Poppins", system-ui, sans-serif',
        'montserrat': '"Montserrat", system-ui, sans-serif',
        'raleway': '"Raleway", system-ui, sans-serif',
        'nunito': '"Nunito", system-ui, sans-serif',
        'manrope': '"Manrope", system-ui, sans-serif',
        'pt-sans': '"PT Sans", system-ui, sans-serif',
        'ubuntu': '"Ubuntu", system-ui, sans-serif',
        'noto-sans': '"Noto Sans", system-ui, sans-serif',
        'work-sans': '"Work Sans", system-ui, sans-serif',
        'mulish': '"Mulish", system-ui, sans-serif',
        'playfair-display': '"Playfair Display", serif',
        'merriweather': '"Merriweather", serif',
        'source-serif-4': '"Source Serif 4", serif',
        'pt-serif': '"PT Serif", serif',
        'noto-serif': '"Noto Serif", serif',
        'source-code-pro': '"Source Code Pro", monospace',
        'ubuntu-mono': '"Ubuntu Mono", monospace',
        'system-mono': 'Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace'
    };

    // Font preview function
    window.previewFont = function(fontValue, type) {
        const previewElement = document.getElementById(type + '-font-preview');
        
        // Resolve 'inherit' to the primary font family without loading a CSS file
        if (fontValue === 'inherit') {
            const primaryValue = (document.querySelector('[name="font_family_primary"]') || {}).value || 'system';
            const resolvedFamily = fontMap[primaryValue] || fontMap['system'];
            if (previewElement) previewElement.style.fontFamily = resolvedFamily;
            return;
        }

        const fontFamily = fontMap[fontValue] || fontMap['system'];
        if (previewElement) {
            previewElement.style.fontFamily = fontFamily;
            
            // Load font if it's a web font
            if (fontValue !== 'system' && fontValue !== 'system-mono' && fontValue !== 'inherit') {
                loadWebFont(fontValue);
            }
        }
    };

    // Load web fonts dynamically
    function loadWebFont(fontName) {
        if (fontName === 'inherit' || fontName === 'system' || fontName === 'system-mono') { return; }
        var css = '';
        if (fontName === 'orbitron') {
            css = "@font-face{font-family:'Orbitron';src:url('/templates/assets/fonts/Orbitron-Regular.woff2') format('woff2');font-weight:normal;font-style:normal;font-display:swap}";
        } else if (fontName === 'rajdhani') {
            css = "@font-face{font-family:'Rajdhani';src:url('/templates/assets/fonts/Rajdhani-Regular.woff2') format('woff2'),url('/templates/assets/fonts/Rajdhani-Regular-proper.ttf') format('truetype');font-weight:normal;font-style:normal;font-display:swap}";
        } else if (fontName === 'ibm-plex-mono') {
            return;
        } else {
            const fontUrl = `/templates/assets/fonts/${fontName}/${fontName}.css`;
            const existingLink = document.querySelector(`link[href="${fontUrl}"]`);
            if (existingLink) return;
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = fontUrl;
            link.onerror = function() { console.log(`Font ${fontName} not available locally, falling back to system font`); };
            document.head.appendChild(link);
            return;
        }
        if (css) {
            var id = 'fontface-' + fontName;
            if (!document.getElementById(id)) {
                var style = document.createElement('style');
                style.id = id;
                style.textContent = css;
                document.head.appendChild(style);
            }
        }
    }

    // Load dark style theme function
    window.loadDarkStyleTheme = function() {
        // Dark style configuration based on the JSON file
        const darkStyleConfig = {
            // Colors from dark-style.json
            primary_color: '#00d4ff',
            secondary_color: '#0080ff', 
            accent_color: '#ff6600',
            background_color: '#0a0a1a',
            surface_color: '#1a1a2e',
            text_color: '#ffffff',
            text_secondary: '#b3b3b3', // Hex for rgba(255,255,255,0.7)
            success_color: '#00ff88',
            warning_color: '#ffaa00',
            error_color: '#ff4444',
            
            // Typography from dark-style.json
            font_family_primary: 'rajdhani',
            font_family_heading: 'orbitron', 
            font_family_mono: 'ibm-plex-mono',
            font_size_base: '16px',
            line_height: '1.6',
            
            // Visual effects
            dark_mode: true,
            glassmorphism: true,
            animations_enabled: true,
            rounded_corners: true,
            shadows_enabled: true,
            gradient_backgrounds: true
        };

        // Apply the configuration to form fields
        Object.keys(darkStyleConfig).forEach(key => {
            const element = document.querySelector(`[name="${key}"]`);
            if (element) {
                if (element.type === 'checkbox') {
                    element.checked = darkStyleConfig[key];
                } else {
                    element.value = darkStyleConfig[key];
                }
                
                // Trigger change event for font previews
                if (key.includes('font_family')) {
                    element.dispatchEvent(new Event('change'));
                }
            }
        });
        
        const notice = window.globalPopupNotice || window.popupNotice;
        if (notice) {
            notice.info('🌙 Dark Style theme configuration loaded! Click "Save Theme Settings" to apply.');
        } else {
            alert('🌙 Dark Style theme configuration loaded! Click "Save Theme Settings" to apply.');
        }
    };

    // Color scheme application function
    window.applyColorScheme = function(scheme) {
        const schemes = {
            'dark-cyber': {
                primary_color: '#00ffff',
                secondary_color: '#0080ff',
                accent_color: '#ff00ff',
                background_color: '#0a0a0a',
                surface_color: '#1a1a1a'
            },
            'ocean-blue': {
                primary_color: '#4fc3f7',
                secondary_color: '#29b6f6', 
                accent_color: '#ff7043',
                background_color: '#0d47a1',
                surface_color: '#1565c0'
            },
            'emerald-green': {
                primary_color: '#4caf50',
                secondary_color: '#66bb6a',
                accent_color: '#ff9800',
                background_color: '#1b5e20',
                surface_color: '#2e7d32'
            },
            'sunset-orange': {
                primary_color: '#ff9800',
                secondary_color: '#ffb74d',
                accent_color: '#e91e63',
                background_color: '#bf360c',
                surface_color: '#d84315'
            },
            'royal-purple': {
                primary_color: '#9c27b0',
                secondary_color: '#ba68c8',
                accent_color: '#ffc107',
                background_color: '#4a148c',
                surface_color: '#6a1b9a'
            },
            'rose-gold': {
                primary_color: '#e91e63',
                secondary_color: '#f06292',
                accent_color: '#ff6f00',
                background_color: '#880e4f',
                surface_color: '#ad1457'
            },
            'slate-gray': {
                primary_color: '#607d8b',
                secondary_color: '#78909c',
                accent_color: '#ff5722',
                background_color: '#263238',
                surface_color: '#37474f'
            },
            'neon-lime': {
                primary_color: '#8bc34a',
                secondary_color: '#9ccc65',
                accent_color: '#e040fb',
                background_color: '#33691e',
                surface_color: '#558b2f'
            }
        };
        
        const config = schemes[scheme];
        if (config) {
            Object.keys(config).forEach(key => {
                const element = document.querySelector(`[name="${key}"]`);
                if (element) {
                    element.value = config[key];
                }
            });
            
            const notice = window.globalPopupNotice || window.popupNotice;
            if (notice) {
                notice.success(`🎨 ${scheme.replace('-', ' ').toUpperCase()} color scheme applied! Click "Save Theme Settings" to apply.`);
            } else {
                alert(`🎨 ${scheme.replace('-', ' ').toUpperCase()} color scheme applied! Click "Save Theme Settings" to apply.`);
            }
        }
    };

    // Reset theme function - removed to avoid conflict with the server-side reset handler
    // The actual reset is handled by the submit form function injected via PHP


    // Initialize font previews on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Set initial font previews
        ['font_family_primary', 'font_family_heading', 'font_family_mono'].forEach(fieldName => {
            const select = document.querySelector(`[name="${fieldName}"]`);
            if (select && select.value) {
                const type = fieldName.replace('font_family_', '');
                previewFont(select.value, type);
            }
        });
    });
})();
</script>

<div class="config-display">
    <h4>⚙️ Current Configuration</h4>
    <pre><?= json_encode($config, JSON_PRETTY_PRINT) ?></pre>
</div>
</div><!-- .theme-manager-wrapper -->

<?php
if ($isStandalone) {
    echo '</body></html>';
}
?>
