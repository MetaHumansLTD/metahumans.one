<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Validation script for Status Bar Configuration
 */

// Load CUE framework
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

// Define getDataPath if not available (mimic core behavior)
if (!function_exists('getDataPath')) {
    function getDataPath() {
        return '/data';
    }
}

echo "<h1>Status Bar Config Validation</h1>";

// 1. Check Path Resolution
echo "<h2>1. Path Resolution</h2>";
$dataPath = getDataPath();
echo "Data Path: " . htmlspecialchars($dataPath) . "<br>";

$configPath = $dataPath . '/widgets/config.json';
echo "Config Path: " . htmlspecialchars($configPath) . "<br>";

// 2. Check Config File
echo "<h2>2. Config File Check</h2>";
if (file_exists($configPath)) {
    echo "Config file exists.<br>";
    $content = file_get_contents($configPath);
    $config = json_decode($content, true);
    
    if ($config) {
        echo "Config JSON is valid.<br>";
        if (isset($config['K::WidgetUI::Configuration'])) {
            $configs = $config['K::WidgetUI::Configuration'];
            uasort($configs, function($a, $b) {
                return strtotime($b['wgt_last_updated'] ?? '0') - strtotime($a['wgt_last_updated'] ?? '0');
            });
            $latest = reset($configs);
            echo "Latest Config Timestamp: " . ($latest['wgt_last_updated'] ?? 'N/A') . "<br>";
            echo "Status Bar Enabled: " . ($latest['wgt_statusbar_enabled'] ? 'Yes' : 'No') . "<br>";
            echo "Background Color: " . ($latest['wgt_statusbar_background_color'] ?? 'N/A') . "<br>";
        } else {
            echo "K::WidgetUI::Configuration key missing.<br>";
        }
    } else {
        echo "Config JSON is invalid.<br>";
    }
} else {
    echo "Config file NOT found.<br>";
}

// 3. Check Function Availability
echo "<h2>3. Function Availability</h2>";
require_once __DIR__ . '/functions.php';

if (function_exists('includeGlobalUIStyles')) {
    echo "includeGlobalUIStyles is available.<br>";
} else {
    echo "includeGlobalUIStyles is NOT available.<br>";
}

if (function_exists('renderGlobalStatusBar')) {
    echo "renderGlobalStatusBar is available.<br>";
} else {
    echo "renderGlobalStatusBar is NOT available.<br>";
}
?>
