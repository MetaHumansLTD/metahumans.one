<?php
/**
 * Status Bar Widget
 * Displays current realm, login status, and permissions in the header
 */

if (defined('STATUS_BAR_RENDERED')) return;
define('STATUS_BAR_RENDERED', true);

if (session_status() === PHP_SESSION_NONE) {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } else {
        session_start();
    }
}

echo "<!-- STATUS BAR WIDGET START -->";

// Load CUE framework if not already loaded
if (!function_exists('cue_autoload')) {
    $cuePath = dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
    if (file_exists($cuePath)) {
        require_once $cuePath;
    }
}

// Get current realm from session (set by realm auto-detection)
$currentRealmId = null;
if (isset($_SESSION) && is_array($_SESSION)) {
    $currentRealmId = $_SESSION['current_realm'] ?? null;
}
$realmData = null;

if ($currentRealmId && function_exists('cue_autoload')) {
    try {
        $navManagerPath = dirname(__DIR__, 2) . '/menus/navigation-database-manager.php';
        if (is_file($navManagerPath)) {
            require_once $navManagerPath;
        }

        if (class_exists('NavigationDatabaseManager')) {
            $navigator = new NavigationDatabaseManager();
            $realms = $navigator->getRealms();
            if (is_object($realms) && isset($realms->$currentRealmId)) {
                $realm = $realms->$currentRealmId;
                if (is_object($realm)) {
                    $realmData = [
                        'name' => isset($realm->name) ? (string)$realm->name : '',
                        'color' => isset($realm->color) ? (string)$realm->color : '',
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Status Bar: Failed to fetch realm data: " . $e->getMessage());
    }
}

$userLoggedIn = false;
$userName = 'User';
$userPersona = '';
$userRole = '';

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION) && is_array($_SESSION)) {
    if (isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] !== '') {
        $userLoggedIn = true;
        $userName = $_SESSION['mh_auth_user'];
        $userPersona = $_SESSION['mh_auth_persona'] ?? '';
        $userRole = $_SESSION['mh_auth_role'] ?? '';
    } else {
        $userLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['username']);
        $userName = $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'User';
    }
}

// Get permissions for current realm
$permissions = [];
if ($currentRealmId && $userLoggedIn && function_exists('cue_autoload')) {
    try {
        if (cue_autoload('database')) {
            // ... (rest of DB logic)
        }
    } catch (Exception $e) {
        error_log("Status Bar: Failed to fetch permissions: " . $e->getMessage());
    }
}

// Render status bar content
$realmName = $realmData ? htmlspecialchars($realmData['name']) : 'Default';
$realmColor = $realmData ? htmlspecialchars($realmData['color']) : '#00ffff';

$loginStatus = "Not logged in";
if ($userLoggedIn) {
    $loginStatus = "User: " . htmlspecialchars($userName);
    if ($userPersona) {
        $loginStatus .= " | Persona: " . htmlspecialchars($userPersona);
    }
    if ($userRole) {
        $loginStatus .= " | Role: " . htmlspecialchars($userRole);
    }
}
$permissionsText = !empty($permissions) ? implode(', ', array_map('htmlspecialchars', $permissions)) : 'No permissions';

// Load configuration from widgets/config.json
$statusBarConfig = [];
if (function_exists('cue_autoload')) {
    $paths = cue_autoload('paths');
    $configPath = $paths->getSecureFilePath('widgets/config.json');
    echo "<!-- DEBUG: Status Bar Config Path: " . htmlspecialchars($configPath) . " -->";
    echo "<!-- DEBUG: CUE Autoload Exists: " . (function_exists('cue_autoload') ? 'YES' : 'NO') . " -->";
    
    if ($configPath && file_exists($configPath)) {
        echo "<!-- DEBUG: Config File Exists. Size: " . filesize($configPath) . " Modified: " . date('Y-m-d H:i:s', filemtime($configPath)) . " -->";
        // Clear cache to ensure we get the latest config
        clearstatcache(true, $configPath);
        
        // Robust file read with locking
        $jsonContent = '';
        $fp = fopen($configPath, 'r');
        if ($fp && flock($fp, LOCK_SH)) {
            $jsonContent = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        } elseif ($fp) {
            fclose($fp);
            $jsonContent = file_get_contents($configPath);
        } else {
            $jsonContent = file_get_contents($configPath);
        }
        
        if ($jsonContent === false) echo "<!-- DEBUG: file_get_contents failed -->";
        
        $configData = json_decode($jsonContent, true);
        if ($configData === null) echo "<!-- DEBUG: json_decode failed: " . json_last_error_msg() . " -->";
        
        if ($configData && isset($configData['K::WidgetUI::Configuration'])) {
            // Sort configs by date descending to ensure we get the absolute latest
            $configs = $configData['K::WidgetUI::Configuration'];
            echo "<!-- DEBUG: Found " . count($configs) . " config entries -->";
            
            uasort($configs, function($a, $b) {
                return strtotime($b['wgt_last_updated'] ?? '0') - strtotime($a['wgt_last_updated'] ?? '0');
            });
            $latestConfig = reset($configs);
            if ($latestConfig) {
                echo "<!-- DEBUG: Loaded Config Updated At: " . ($latestConfig['wgt_last_updated'] ?? 'N/A') . " -->";
                echo "<!-- DEBUG: Loaded BG Color: " . ($latestConfig['wgt_statusbar_background_color'] ?? 'N/A') . " -->";
                $statusBarConfig = $latestConfig;
            }
        } else {
             echo "<!-- DEBUG: Invalid Config Structure -->";
        }
    } else {
        echo "<!-- DEBUG: Config File NOT FOUND at $configPath -->";
    }
}

// Apply configuration with defaults
$enabled = $statusBarConfig['wgt_statusbar_enabled'] ?? true;
$position = $statusBarConfig['wgt_statusbar_position'] ?? 'bottom'; // top, middle, bottom
$contentAlignment = $statusBarConfig['wgt_statusbar_placement'] ?? 'center'; // left, center, right, space-between
$height = $statusBarConfig['wgt_statusbar_height'] ?? 40;
$width = $statusBarConfig['wgt_statusbar_width'] ?? '100%';
$backgroundColor = $statusBarConfig['wgt_statusbar_background_color'] ?? '#1a1a2e';
$textColor = $statusBarConfig['wgt_statusbar_text_color'] ?? '#00ffff';
$borderColor = $statusBarConfig['wgt_statusbar_border_color'] ?? '#00ffff';
$fontSize = $statusBarConfig['wgt_statusbar_font_size'] ?? 14;
$fontFamily = $statusBarConfig['wgt_statusbar_font_family'] ?? 'Arial, sans-serif';
$shape = $statusBarConfig['wgt_statusbar_shape'] ?? 'rounded';
$borderRadius = $statusBarConfig['wgt_statusbar_border_radius'] ?? 5;
$padding = $statusBarConfig['wgt_statusbar_padding'] ?? '10px';
$margin = $statusBarConfig['wgt_statusbar_margin'] ?? '0';
$opacity = $statusBarConfig['wgt_statusbar_opacity'] ?? 90;
$glowEnabled = $statusBarConfig['wgt_statusbar_glow_enabled'] ?? false;
$glowColor = $statusBarConfig['wgt_statusbar_glow_color'] ?? '#00ffff';
$glowIntensity = $statusBarConfig['wgt_statusbar_glow_intensity'] ?? 5;

// Apply Shape Logic
switch($shape) {
    case 'square': $borderRadius = 0; break;
    case 'round': $borderRadius = 15; break;
    case 'pill': $borderRadius = 999; break;
    case 'rounded': default: $borderRadius = 5; break;
}

// Generate Unique ID for this widget instance
$widgetId = 'status-bar-' . uniqid();

// Positioning Logic (CSS)
$positionCSS = "position: fixed; z-index: 9999;";

if ($position === 'middle') {
    // Middle Position: Smart placement based on alignment
    $positionCSS .= " top: 50%; bottom: auto;";
    
    // Override width if it's default 100% to prevent full-screen blocking in middle mode
    if ($width === '100%') $width = 'auto';
    
    if ($contentAlignment === 'right') {
        $positionCSS .= " right: 0; left: auto; transform: translateY(-50%);";
    } elseif ($contentAlignment === 'left') {
        $positionCSS .= " left: 0; right: auto; transform: translateY(-50%);";
    } elseif ($contentAlignment === 'center') {
        $positionCSS .= " left: 50%; right: auto; transform: translate(-50%, -50%);";
    } else {
        // Space-between or others -> likely full width
        $positionCSS .= " left: 0; right: 0; transform: translateY(-50%);";
    }
} else {
    // Top/Bottom: Default to full width
    $positionCSS .= " left: 0; right: 0;";
    
    if ($position === 'top') {
        $positionCSS .= " top: 0; bottom: auto;";
    } else {
        $positionCSS .= " bottom: 0; top: auto;";
    }
}

// Content Alignment Logic (Flexbox)
$justifyContent = 'space-between';
switch($contentAlignment) {
    case 'left': $justifyContent = 'flex-start'; break;
    case 'center': $justifyContent = 'center'; break;
    case 'right': $justifyContent = 'flex-end'; break;
    case 'space-between': default: $justifyContent = 'space-between'; break;
}

// Only render if enabled
if ($enabled) {
    // Font Loading Logic
    if ($fontFamily !== 'Arial, sans-serif') {
        $fontDirName = strtolower($fontFamily);
        // Adjust font name for specific cases if needed
        if ($fontFamily === 'Rajdhani') $fontDirName = 'rajdhani';
        if ($fontFamily === 'Inter') $fontDirName = 'inter';
        if ($fontFamily === 'Roboto') $fontDirName = 'roboto';

        $baseFontPath = dirname(dirname(dirname(__DIR__))) . '/templates/assets/fonts/' . $fontDirName;
        
        // Check for CSS file first (e.g. merriweather.css in fonts root)
        $cssFile = dirname($baseFontPath) . '/' . strtolower($fontFamily) . '.css';
        
        // Clear stat cache to ensure file_exists is accurate
        clearstatcache(true, $cssFile);
        
        echo "<style>";
        if (file_exists($cssFile)) {
             $cssUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/fonts/' . strtolower($fontFamily) . '.css') : '/templates/assets/fonts/' . strtolower($fontFamily) . '.css';
             echo "@import url('$cssUrl');";
        } elseif (is_dir($baseFontPath)) {
            // Fallback: Load all woff2 files in the directory
            $fontFiles = glob($baseFontPath . '/*.woff2');
            if ($fontFiles) {
                foreach($fontFiles as $file) {
                    $fileName = basename($file);
                    $fontUrl = function_exists('getTemplateURL') ? getTemplateURL("assets/fonts/{$fontDirName}/{$fileName}") : "/templates/assets/fonts/{$fontDirName}/{$fileName}";
                    echo "@font-face { font-family: '{$fontFamily}'; src: url('$fontUrl') format('woff2'); font-weight: normal; font-style: normal; } ";
                }
            }
        }
        echo "</style>";
    }

    // Dynamic Styles with !important to ensure precedence
    // We use a unique ID to avoid conflicts and increase specificity
    echo "<style>
        #{$widgetId} {
            background-color: {$backgroundColor} !important;
            color: {$textColor} !important;
            font-size: {$fontSize}px !important;
            font-family: {$fontFamily} !important;
            border-radius: {$borderRadius}px !important;
            padding: {$padding} !important;
            margin: {$margin} !important;
            opacity: " . ($opacity / 100) . " !important;
            height: {$height}px !important;
            width: {$width} !important;
            display: flex !important;
            justify-content: {$justifyContent} !important;
            align-items: center !important;
            border: 1px solid {$borderColor} !important;
            box-sizing: border-box !important;
            min-width: 200px !important;
            backdrop-filter: blur(10px);
            " . ($glowEnabled ? "box-shadow: 0 0 {$glowIntensity}px {$glowColor} !important;" : "box-shadow: 0 0 10px rgba(0, 255, 255, 0.2);") . "
            {$positionCSS}
        }
        #{$widgetId} span {
            margin: 0 10px;
        }
        #{$widgetId} a {
            color: {$textColor};
            text-decoration: none;
            margin-left: 10px;
        }
        #{$widgetId} a:hover {
            text-decoration: underline;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            #{$widgetId} {
                flex-direction: column !important;
                align-items: flex-start !important;
                height: auto !important;
                width: 100% !important;
                top: auto !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                transform: none !important;
                border-radius: 0 !important;
                padding: 10px !important;
            }
            #{$widgetId} span {
                margin: 2px 0;
            }
            #{$widgetId} a {
                margin-left: 0;
                margin-top: 5px;
            }
        }
    </style>";
    
    echo "<div id='{$widgetId}' class='status-bar-widget'>";
    echo "<span><strong>Realm:</strong> {$realmName}</span>";
    echo "<span><strong>Status:</strong> {$loginStatus}</span>";
    if ($userLoggedIn) {
        if (!empty($personaName)) {
             echo "<span style='margin-right: 15px;'><strong>Persona:</strong> " . htmlspecialchars($personaName) . "</span>";
        }
        echo "<span><strong>Permissions:</strong> {$permissionsText}</span>";
        echo "<a href='/auth/logout.php'>Logout</a>";
    }
    echo "</div>";
}

// Debug: Add visible marker
echo "<!-- STATUS BAR WIDGET END -->";
?>
