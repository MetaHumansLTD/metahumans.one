<?php
/**
 * Loader Widget Configuration API
 * 
 * Returns the current loader configuration as JSON
 */

// Security: Prevent direct access if CUE is not loaded
if (!defined('CUE_CORE_LOADED')) {
    $cuePath = dirname(__DIR__, 3) . '/.cue/cue.php';
    if (file_exists($cuePath)) {
        require_once $cuePath;
    } else {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        exit(json_encode(['error' => 'Framework not available']));
    }
}

// Set JSON content type
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $configPath = $paths ? $paths->getSecureFilePath('widgets/loader/loader-config.json') : null;
    if ($configPath && $paths && function_exists('getDataPath')) {
        if (!$paths->validateSecurePath($configPath, getDataPath())) {
            $configPath = null;
        }
    }
    
    $defaultConfig = [
        'enabled' => true,
        'animation_type' => 'rings',
        'size' => 'medium',
        'position' => 'center',
        'colors' => [
            'primary' => '#3b82f6',
            'secondary' => '#7c3aed',
            'tertiary' => '#f59e0b'
        ],
        'background_opacity' => 95,
        'animation_speed' => 1.0,
        'blur_backdrop' => 10,
        'duration' => 0,
        'show_text' => true,
        'auto_hide' => false
    ];
    
    if ($configPath && file_exists($configPath)) {
        $configContent = file_get_contents($configPath);
        $config = json_decode($configContent, true);
        
        if ($config && is_array($config)) {
            // Merge with defaults to ensure all properties exist
            $config = array_merge($defaultConfig, $config);
        } else {
            $config = $defaultConfig;
        }
    } else {
        $config = $defaultConfig;
    }
    
    echo json_encode($config);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration unavailable']);
}
?>
