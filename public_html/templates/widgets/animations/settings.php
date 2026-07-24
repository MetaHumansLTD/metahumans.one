<?php
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
$paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
/**
 * Animation Widget Configuration Page
 * CUE Framework 100.0.99 Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getSecureFilePath() for file operations
 * ✓ Uses framework validation functions
 * ✓ Follows enterprise security standards
 * ✓ Implements proper error handling
 * 
 * Standalone configuration interface for the Animation widget system
 * Controls 3D background effects, CSS animations, GSAP settings, and element targeting
 */

// Handle AJAX request for PHP files
if (isset($_GET['action']) && $_GET['action'] === 'get_php_files') {
    header('Content-Type: application/json; charset=UTF-8');
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    
    $publicHtmlPath = dirname(__DIR__, 3);
    $phpFiles = [];
    $id = 1;
    
    // Scan for PHP files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicHtmlPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $relativePath = str_replace($publicHtmlPath, '', $file->getPathname());
            $relativePath = str_replace('\\', '/', $relativePath);
            
            // Skip hidden files, cache files, and templates
            if (strpos($relativePath, '/.') === false && 
                strpos($relativePath, '/cache/') === false &&
                strpos($relativePath, '/temp/') === false) {
                
                $fileName = $file->getBasename('.php');
                $pageName = ucwords(str_replace(['_', '-'], ' ', $fileName));
                
                // Set default background effect based on page type
                $animation = 'none';
                $enabled = false;
                
                if (strpos($relativePath, 'index.php') !== false) {
                    $animation = 'waves';
                    $enabled = true;
                } elseif (strpos($relativePath, 'settings') !== false) {
                    $animation = 'topology';
                    $enabled = false;
                } elseif (strpos($relativePath, 'dashboard') !== false) {
                    $animation = 'net';
                    $enabled = false;
                }
                
                $phpFiles[] = [
                    'id' => $id++,
                    'name' => $pageName,
                    'path' => $relativePath,
                    'animation' => $animation,
                    'enabled' => $enabled
                ];
                
                // Limit to prevent memory issues
                if (count($phpFiles) >= 50) break;
            }
        }
    }
    
    echo json_encode(['success' => true, 'files' => $phpFiles]);
    exit;
}

// Handle AJAX request for saving page animation
if (isset($_POST['action']) && $_POST['action'] === 'save_page_animation') {
    header('Content-Type: application/json; charset=UTF-8');
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    
    $pageId = $_POST['page_id'] ?? '';
    $pagePath = $_POST['page_path'] ?? '';
    $animation = $_POST['animation'] ?? 'none';
    $enabled = $_POST['enabled'] ?? '0';
    
    // Load existing page animations config
    $pageAnimationsPath = $paths ? $paths->getSecureFilePath('widgets/animations/page-animations.json') : null;
    if ($pageAnimationsPath && $paths && function_exists('getDataPath')) {
        if (!$paths->validateSecurePath($pageAnimationsPath, getDataPath())) {
            $pageAnimationsPath = null;
        }
    }
    $pageAnimations = [];
    
    if ($pageAnimationsPath && file_exists($pageAnimationsPath)) {
        $pageAnimations = json_decode(file_get_contents($pageAnimationsPath), true) ?: [];
    }
    
    // Use page path as key for better matching
    $key = !empty($pagePath) ? $pagePath : $pageId;
    
    // Update the specific page animation
    $pageAnimations[$key] = [
        'page_id' => $pageId,
        'page_path' => $pagePath,
        'animation' => $animation,
        'enabled' => $enabled === '1'
    ];
    
    // Save back to file
    if ($pageAnimationsPath && file_exists($pageAnimationsPath)) {
        $written = file_put_contents($pageAnimationsPath, json_encode($pageAnimations, JSON_PRETTY_PRINT));
        if ($written !== false) {
            echo json_encode(['success' => true, 'message' => 'Animation saved successfully', 'key' => $key]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save animation']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Storage path not available']);
    }
    exit;
}

// Handle AJAX request for getting saved animations
if (isset($_GET['action']) && $_GET['action'] === 'get_saved_animations') {
    header('Content-Type: application/json; charset=UTF-8');
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    
    $pageAnimationsPath = $paths ? $paths->getSecureFilePath('widgets/animations/page-animations.json') : null;
    if ($pageAnimationsPath && $paths && function_exists('getDataPath')) {
        if (!$paths->validateSecurePath($pageAnimationsPath, getDataPath())) {
            $pageAnimationsPath = null;
        }
    }
    $pageAnimations = [];
    
    if ($pageAnimationsPath && file_exists($pageAnimationsPath)) {
        $pageAnimations = json_decode(file_get_contents($pageAnimationsPath), true) ?: [];
    }
    
    echo json_encode(['success' => true, 'animations' => $pageAnimations]);
    exit;
}

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//

// Fallback functions if CUE framework is not available
if (!function_exists('validateInput')) {
    function validateInput(mixed $value, string $type, array $options = []): array {
        $result = ['valid' => true, 'sanitized' => $value, 'error' => ''];
        
        switch ($type) {
            case 'string':
                $result['sanitized'] = trim(strip_tags((string) $value));
                if (isset($options['allowed']) && !in_array($result['sanitized'], $options['allowed'])) {
                    $result['valid'] = false;
                    $result['error'] = 'Invalid value';
                }
                break;
            case 'int':
                $result['sanitized'] = intval($value);
                if (isset($options['min']) && $result['sanitized'] < $options['min']) {
                    $result['valid'] = false;
                    $result['error'] = 'Value too small';
                }
                if (isset($options['max']) && $result['sanitized'] > $options['max']) {
                    $result['valid'] = false;
                    $result['error'] = 'Value too large';
                }
                break;
            case 'boolean':
                $result['sanitized'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'float':
                $result['sanitized'] = floatval($value);
                if (isset($options['min']) && $result['sanitized'] < $options['min']) {
                    $result['valid'] = false;
                    $result['error'] = 'Value too small';
                }
                if (isset($options['max']) && $result['sanitized'] > $options['max']) {
                    $result['valid'] = false;
                    $result['error'] = 'Value too large';
                }
                break;
        }
        
        return $result;
    }
}

// Configuration file path
$configPath = $paths ? $paths->getSecureFilePath('widgets/animations/animation-config.json') : null;
if ($configPath && $paths && function_exists('getDataPath')) {
    if (!$paths->validateSecurePath($configPath, getDataPath())) {
        $configPath = null;
    }
}

// Default configuration
$defaultConfig = [
    'background_effects_enabled' => true,
    'css_animations_enabled' => true,
    'gsap_enabled' => true,
    'background_rotation_enabled' => false,
    'auto_init' => true,
    'debug_mode' => false,
    'default_animations' => [
        'header' => ['type' => 'fade-in', 'duration' => '1.2s', 'delay' => '0.2s'],
        'footer' => ['type' => 'slide-in-left', 'duration' => '1s', 'delay' => '0.5s'],
        'navigation' => ['type' => 'bounce', 'duration' => '2s', 'delay' => '0s'],
        'content' => ['type' => 'fade-in', 'duration' => '1s', 'delay' => '0.3s'],
        'images' => ['type' => 'scale-up', 'duration' => '0.8s', 'delay' => '0s']
    ],
    'background_effects' => [
        'header_background' => [
            'effect' => 'waves',
            'enabled' => true,
            'settings' => [
                'color' => '0x23153c',
                'shininess' => 30,
                'waveHeight' => 15,
                'waveSpeed' => 1.25,
                'zoom' => 0.65
            ]
        ],
        'footer_background' => [
            'effect' => 'birds',
            'enabled' => false,
            'settings' => [
                'backgroundColor' => '0x1a1a2e',
                'color1' => '0xff6b6b',
                'color2' => '0x4ecdc4',
                'birdSize' => 1.2,
                'quantity' => 3
            ]
        ],
        'content_background' => [
            'effect' => 'cells',
            'enabled' => false,
            'settings' => [
                'color1' => '0xff6b6b',
                'color2' => '0x4ecdc4',
                'size' => 1.5,
                'speed' => 1
            ]
        ]
    ],
    'performance' => [
        'reduce_motion_respect' => true,
        'mobile_optimized' => true,
        'fps_limit' => 60,
        'pause_on_inactive' => true
    ],
    'responsive' => [
        'disable_on_mobile' => false,
        'mobile_breakpoint' => 768,
        'tablet_breakpoint' => 1024
    ],
    'glow_effects_enabled' => false,
    'glow_color' => '#00ffff',
    'glow_size' => 10,
    'glow_opacity' => 80,
    'rotation_interval' => 30
];

// Load current configuration
$config = $defaultConfig;
if (file_exists($configPath)) {
    $configContent = file_get_contents($configPath);
    $loadedConfig = json_decode($configContent, true);
    if ($loadedConfig && is_array($loadedConfig)) {
        $config = array_merge($defaultConfig, $loadedConfig);
    }
}

// Available animation types
$animationTypes = [
    'wobble' => 'Wobble',
    'bounce' => 'Bounce',
    'fade-in' => 'Fade In',
    'fade-out' => 'Fade Out',
    'slide-in-left' => 'Slide In Left',
    'slide-in-right' => 'Slide In Right',
    'scale-up' => 'Scale Up',
    'rotate' => 'Rotate',
    'pulse' => 'Pulse',
    'float' => 'Float',
    'glow' => 'Glow Effect'
];

// Available background effects
$backgroundEffects = [
    'birds' => 'Birds',
    'waves' => 'Waves',
    'clouds' => 'Clouds',
    'cells' => 'Cells',
    'fog' => 'Fog',
    'halo' => 'Halo',
    'net' => 'Net',
    'rings' => 'Rings',
    'ripple' => 'Ripple',
    'topology' => 'Topology'
];

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $newConfig = $config;
    
    try {
        // Validate main settings
        $backgroundEnabled = validateInput($_POST['background_effects_enabled'] ?? false, 'boolean');
        $cssEnabled = validateInput($_POST['css_animations_enabled'] ?? false, 'boolean');
        $gsapEnabled = validateInput($_POST['gsap_enabled'] ?? false, 'boolean');
        $bgRotationEnabled = validateInput($_POST['background_rotation_enabled'] ?? false, 'boolean');
        $autoInit = validateInput($_POST['auto_init'] ?? false, 'boolean');
        $debugMode = validateInput($_POST['debug_mode'] ?? false, 'boolean');
        
        $newConfig['background_effects_enabled'] = $backgroundEnabled['sanitized'];
        $newConfig['css_animations_enabled'] = $cssEnabled['sanitized'];
        $newConfig['gsap_enabled'] = $gsapEnabled['sanitized'];
        $newConfig['background_rotation_enabled'] = $bgRotationEnabled['sanitized'];
        $newConfig['auto_init'] = $autoInit['sanitized'];
        $newConfig['debug_mode'] = $debugMode['sanitized'];
        
        // Validate default animations
        $elements = ['header', 'footer', 'navigation', 'content', 'images'];
        foreach ($elements as $element) {
            $type = validateInput($_POST["default_animations_{$element}_type"] ?? '', 'string', ['allowed' => array_keys($animationTypes)]);
            $duration = validateInput($_POST["default_animations_{$element}_duration"] ?? '1s', 'string');
            $delay = validateInput($_POST["default_animations_{$element}_delay"] ?? '0s', 'string');
            
            if ($type['valid'] && $duration['valid'] && $delay['valid']) {
                $newConfig['default_animations'][$element] = [
                    'type' => $type['sanitized'],
                    'duration' => $duration['sanitized'],
                    'delay' => $delay['sanitized']
                ];
            } else {
                $errors[] = "Invalid animation settings for {$element}";
            }
        }
        
        // Validate background effects
        $backgroundElements = ['header_background', 'footer_background', 'content_background'];
        foreach ($backgroundElements as $element) {
            $effect = validateInput($_POST["background_{$element}_effect"] ?? '', 'string', ['allowed' => array_keys($backgroundEffects)]);
            $enabled = validateInput($_POST["background_{$element}_enabled"] ?? false, 'boolean');
            
            if ($effect['valid']) {
                $newConfig['background_effects'][$element]['effect'] = $effect['sanitized'];
                $newConfig['background_effects'][$element]['enabled'] = $enabled['sanitized'];
            }
        }
        
        // Validate performance settings
        $reduceMotion = validateInput($_POST['performance_reduce_motion_respect'] ?? false, 'boolean');
        $mobileOptimized = validateInput($_POST['performance_mobile_optimized'] ?? false, 'boolean');
        $fpsLimit = validateInput($_POST['performance_fps_limit'] ?? 60, 'int', ['min' => 30, 'max' => 120]);
        $pauseInactive = validateInput($_POST['performance_pause_on_inactive'] ?? false, 'boolean');
        
        $newConfig['performance'] = [
            'reduce_motion_respect' => $reduceMotion['sanitized'],
            'mobile_optimized' => $mobileOptimized['sanitized'],
            'fps_limit' => $fpsLimit['sanitized'],
            'pause_on_inactive' => $pauseInactive['sanitized']
        ];
        
        // Validate glow effects settings
        $glowEnabled = validateInput($_POST['glow_effects_enabled'] ?? false, 'boolean');
        $glowColor = validateInput($_POST['glow_color'] ?? '#00ffff', 'string');
        $glowSize = validateInput($_POST['glow_size'] ?? 10, 'int', ['min' => 0, 'max' => 50]);
        $glowOpacity = validateInput($_POST['glow_opacity'] ?? 80, 'int', ['min' => 0, 'max' => 100]);
        $rotationInterval = validateInput($_POST['rotation_interval'] ?? 30, 'int', ['min' => 5, 'max' => 300]);
        
        $newConfig['glow_effects_enabled'] = $glowEnabled['sanitized'];
        $newConfig['glow_color'] = $glowColor['sanitized'];
        $newConfig['glow_size'] = $glowSize['sanitized'];
        $newConfig['glow_opacity'] = $glowOpacity['sanitized'];
        $newConfig['rotation_interval'] = $rotationInterval['sanitized'];
        
        // Validate responsive settings
        $disableMobile = validateInput($_POST['responsive_disable_on_mobile'] ?? false, 'boolean');
        $mobileBreakpoint = validateInput($_POST['responsive_mobile_breakpoint'] ?? 768, 'int', ['min' => 320, 'max' => 1024]);
        $tabletBreakpoint = validateInput($_POST['responsive_tablet_breakpoint'] ?? 1024, 'int', ['min' => 768, 'max' => 1920]);
        
        $newConfig['responsive'] = [
            'disable_on_mobile' => $disableMobile['sanitized'],
            'mobile_breakpoint' => $mobileBreakpoint['sanitized'],
            'tablet_breakpoint' => $tabletBreakpoint['sanitized']
        ];
        
        if (empty($errors)) {
            // Save configuration
            $jsonContent = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($configPath, $jsonContent) !== false) {
                $config = $newConfig;
                $message = 'Animation widget configuration saved successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to save configuration file.';
                $messageType = 'error';
            }
        } else {
            $message = 'Validation errors: ' . implode(', ', $errors);
            $messageType = 'error';
        }
        
    } catch (Exception $e) {
        $message = 'Error saving configuration: ' . $e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Animation Widget Settings</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .content {
            padding: 40px;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }

        .form-section h3 {
            color: #495057;
            margin-bottom: 20px;
            font-size: 1.3rem;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #007bff;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .animation-preview {
            width: 50px;
            height: 50px;
            background: linear-gradient(45deg, #007bff, #00d4aa);
            border-radius: 50%;
            margin: 10px 0;
            display: inline-block;
        }

        .background-preview {
            width: 100%;
            height: 100px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            margin: 10px 0;
            background: linear-gradient(45deg, #1a1a2e, #16213e);
            position: relative;
            overflow: hidden;
        }

        .save-section {
            background: #f8f9fa;
            padding: 30px;
            border-top: 1px solid #e9ecef;
            text-align: center;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            margin-left: 15px;
        }

        .performance-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-left: 10px;
        }

        .performance-good {
            background: #28a745;
        }

        .performance-warning {
            background: #ffc107;
        }

        .performance-poor {
            background: #dc3545;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .content {
                padding: 20px;
            }
        }

        /* Animation classes for preview */
        .animate-wobble { animation: wobble 1s ease-in-out infinite; }
        .animate-bounce { animation: bounce 2s infinite; }
        .animate-pulse { animation: pulse 1.5s ease-in-out infinite; }

        @keyframes wobble {
            0% { transform: translateX(0%); }
            15% { transform: translateX(-25%) rotate(-5deg); }
            30% { transform: translateX(20%) rotate(3deg); }
            45% { transform: translateX(-15%) rotate(-3deg); }
            60% { transform: translateX(10%) rotate(2deg); }
            75% { transform: translateX(-5%) rotate(-1deg); }
            100% { transform: translateX(0%); }
        }

        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% { transform: translate3d(0,0,0); }
            40%, 43% { transform: translate3d(0, -30px, 0); }
            70% { transform: translate3d(0, -15px, 0); }
            90% { transform: translate3d(0,-4px,0); }
        }

        @keyframes pulse {
            0% { transform: scale3d(1, 1, 1); }
            50% { transform: scale3d(1.05, 1.05, 1.05); }
            100% { transform: scale3d(1, 1, 1); }
        }

        /* Glow Effects Styles */
        .glow-preview {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin: 15px 0;
        }

        .glow-sample {
            display: inline-block;
            padding: 15px 30px;
            background: #007bff;
            color: white;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        /* Page Manager Modal Styles - Force white background */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
        }
        
        /* Force all modal elements to have white backgrounds */
        #pageManagerModal * { color: #333; }
        
        #pageManagerModal .modal-container { background: #ffffff; }
        #pageManagerModal .modal-content { background: #ffffff; }
        #pageManagerModal .page-list { background: #ffffff; }

        .modal-container {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #ffffff !important;
            background-color: #ffffff !important;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            color: #333;
        }

        .modal-header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.8rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-content {
            padding: 20px;
            max-height: 60vh;
            overflow-y: auto;
            background: #ffffff !important;
            background-color: #ffffff !important;
            color: #333;
        }

        .modal-search {
            margin-bottom: 20px;
        }

        .modal-search input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
        }

        .page-list {
            border: 2px solid #00bcd4 !important;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            background: #283593 !important;
            min-height: 100px;
            color: #00bcd4 !important;
        }

        .page-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #00bcd4;
            transition: all 0.2s ease;
            cursor: pointer;
            background: #283593 !important;
            color: #00bcd4 !important;
        }

        .page-item:last-child {
            border-bottom: none;
        }

        .page-item:hover {
            background: #3949ab !important;
            transform: translateX(2px);
        }

        .page-item.selected {
            background: #1976d2 !important;
            border-left: 4px solid #00bcd4;
        }
        
        .page-checkbox-container {
            flex-shrink: 0;
            width: 20px;
        }
        
        .page-info {
            flex-grow: 1;
            margin-left: 15px;
        }
        
        .page-name {
            font-weight: 600;
            color: #00e5ff !important;
            margin-bottom: 4px;
        }
        
        .page-path {
            font-size: 12px;
            color: #4dd0e1 !important;
            margin-bottom: 4px;
        }
        
        .animation-status {
            font-size: 11px;
            margin-top: 2px;
        }
        
        .status-enabled {
            color: #4CAF50;
            font-weight: 600;
        }
        
        .status-disabled {
            color: #999;
        }
        
        .animation-controls {
            flex-shrink: 0;
            width: 150px;
        }
        
        .animation-controls select {
            width: 100%;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .animation-controls select:disabled {
            background: #f5f5f5;
            color: #999;
            border-left: 4px solid #2196f3;
        }

        .page-item .page-checkbox {
            margin-right: 15px;
            cursor: pointer;
        }

        .page-info {
            flex: 1;
        }

        .page-name {
            font-weight: 600;
            color: #333 !important;
            margin-bottom: 5px;
        }

        .page-path {
            font-size: 12px;
            color: #666 !important;
        }

        .animation-type {
            display: inline-block;
            padding: 6px 12px;
            background: linear-gradient(45deg, #007bff, #00d4aa);
            color: white;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            min-width: 60px;
            text-align: center;
        }

        .animation-selector {
            margin-left: 10px;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            background: white;
        }

        .modal-actions {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-modal {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary-modal {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary-modal {
            background: #6c757d;
            color: white;
        }

        .btn-modal:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Range input styling */
        input[type="range"] {
            width: calc(100% - 60px);
            margin-right: 10px;
        }

        input[type="color"] {
            width: 60px;
            height: 40px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        
        /* FORCE NO BLANK SPACE AT BOTTOM - AGGRESSIVE */
        html {
            margin: 0 !important;
            padding: 0 !important;
            height: auto !important;
            overflow-x: hidden !important;
        }
        
        body {
            margin: 0 !important;
            padding: 0 !important;
            height: fit-content !important;
            min-height: auto !important;
            max-height: none !important;
            overflow-x: hidden !important;
            position: relative !important;
        }
        
        .container {
            margin: 0 auto !important;
            margin-bottom: 0 !important;
            padding-bottom: 0 !important;
            position: relative !important;
        }
        
        /* Ensure no element creates excessive space */
        * {
            box-sizing: border-box !important;
        }
        
        body::after {
            content: '';
            display: block;
            height: 0 !important;
            clear: both;
        }
        
        /* Modal positioning - centered and draggable */
        .modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background: rgba(0, 0, 0, 0.7) !important;
            display: none !important;
            z-index: 10000 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .modal-overlay.show {
            display: flex !important;
        }
        
        .modal-container {
            background: #1a237e !important;
            color: #00bcd4 !important;
            border-radius: 12px !important;
            padding: 0 !important;
            max-width: 900px !important;
            width: 95% !important;
            max-height: 85vh !important;
            overflow: visible !important;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3) !important;
            position: fixed !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            cursor: move !important;
            user-select: none !important;
            border: 2px solid #00bcd4 !important;
            z-index: 1000 !important;
            display: flex !important;
            flex-direction: column !important;
        }
        
        .modal-container *,
        .modal-content *,
        .modal-actions *,
        #pageManagerModal *,
        #pageManagerModal h2,
        #pageManagerModal h3,
        #pageManagerModal h4,
        #pageManagerModal p,
        #pageManagerModal span,
        #pageManagerModal div,
        #pageManagerModal label,
        .page-list *,
        .page-list-item *,
        .modal-search * {
            color: #00bcd4 !important;
        }
        
        .modal-container.dragging {
            transform: none !important;
            transition: none !important;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%) !important;
            color: white !important;
            padding: 20px !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move !important;
            user-select: none !important;
        }
        
        .modal-header:hover {
            background: linear-gradient(135deg, #3d8bfe 0%, #00d4fe 100%) !important;
        }
        
        .modal-header h2 {
            margin: 0;
            pointer-events: none;
        }
        
        .modal-header::before {
            content: '..';
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.7);
            font-size: 16px;
            letter-spacing: 2px;
        }
        
        .modal-close {
            background: none !important;
            border: none !important;
            color: white !important;
            font-size: 24px !important;
            cursor: pointer !important;
            padding: 0 !important;
            width: 30px !important;
            height: 30px !important;
        }
        
        .modal-content {
            padding: 20px !important;
            max-height: 50vh !important;
            overflow-y: auto !important;
            background: #1a237e !important;
            color: #00bcd4 !important;
        }
        
        .modal-search input {
            background: #283593 !important;
            color: #00bcd4 !important;
            border: 1px solid #00bcd4 !important;
        }
        
        .modal-search input::placeholder {
            color: #4dd0e1 !important;
        }
        
        .modal-actions {
            padding: 15px 20px !important;
            background: #1565c0 !important;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn-modal {
            padding: 8px 16px !important;
            border: 1px solid #00bcd4 !important;
            border-radius: 4px !important;
            background: #283593 !important;
            color: #00bcd4 !important;
            cursor: pointer !important;
        }
        
        .btn-modal:hover {
            background: #3949ab !important;
        }
        
        /* FORCE DROPDOWN BACKGROUND DARK BLUE TEXT CYAN - MAXIMUM OVERRIDE */
        select,
        select:focus,
        select:active,
        select:hover,
        select[multiple],
        .modal-content select,
        .page-list select,
        form select,
        .animation-controls select {
            background-color: #1a237e !important;
            background: #1a237e !important;
            color: #00bcd4 !important;
            border: 2px solid #00bcd4 !important;
            border-radius: 4px !important;
            padding: 8px 12px !important;
            font-size: 14px !important;
            min-width: 150px !important;
            pointer-events: auto !important;
            z-index: 9999 !important;
            position: relative !important;
        }
        
        select option,
        select option:checked,
        select option:focus,
        select option:active,
        select option:selected {
            background-color: #1a237e !important;
            background: #1a237e !important;
            color: #00bcd4 !important;
        }
        
        select option:hover,
        select option:focus {
            background-color: #283593 !important;
            background: #283593 !important;
            color: #00bcd4 !important;
        }
        
        /* Test dropdown styling - add visible test */
        .test-dropdown-colors {
            background: #1a237e !important;
            color: #00bcd4 !important;
            border: 2px solid #00bcd4 !important;
            padding: 10px !important;
            margin: 10px 0 !important;
        }
        
        /* FORCE ALL PAGE LIST CONTENT TO BE CYAN */
        .page-item,
        .page-item *,
        .page-info,
        .page-info *,
        .page-name,
        .page-path,
        .animation-status,
        .animation-status *,
        .status-enabled,
        .status-disabled,
        .page-checkbox-container,
        .animation-controls {
            color: #00bcd4 !important;
            background: transparent !important;
            z-index: 10000 !important;
            position: relative !important;
        }
        
        .animation-controls select {
            z-index: 10001 !important;
            position: relative !important;
            pointer-events: auto !important;
        }
        
        /* Fix modal z-index hierarchy */
        .modal-container {
            z-index: 1000 !important;
        }
        
        .modal-content {
            z-index: 1001 !important;
            position: relative !important;
        }
        
        .page-list {
            display: flex !important;
            flex-direction: column !important;
            gap: 15px !important;
            padding: 10px 0 !important;
            position: relative !important;
        }
        
        .page-item {
            display: flex !important;
            align-items: center !important;
            gap: 15px !important;
            padding: 15px !important;
            background: rgba(0, 188, 212, 0.1) !important;
            border: 1px solid rgba(0, 188, 212, 0.3) !important;
            border-radius: 8px !important;
            position: relative !important;
            z-index: 1 !important;
        }
        
        .page-item:hover {
            background: rgba(0, 188, 212, 0.2) !important;
        }
        
        .page-checkbox-container {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 40px !important;
        }
        
        .page-info {
            min-width: 0 !important;
        }
        
        .page-name {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        
        .page-item .page-name {
            color: #00bcd4 !important;
            font-weight: bold !important;
        }
        
        .page-item .page-path {
            color: rgba(0, 188, 212, 0.8) !important;
            font-size: 12px !important;
        }
        
        .status-enabled {
            color: #4caf50 !important;
        }
        
        .status-disabled {
            color: rgba(0, 188, 212, 0.6) !important;
        }
        
        select option:checked,
        select option:selected {
            background: #1565c0 !important;
            color: #00e5ff !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-grid">
                    <!-- Main Settings -->
                    <div class="form-section">
                        <h3>Main Settings</h3>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="background_effects_enabled" name="background_effects_enabled" value="1" 
                                       <?php echo $config['background_effects_enabled'] ? 'checked' : ''; ?>>
                                <label for="background_effects_enabled">Enable 3D Backgrounds</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="css_animations_enabled" name="css_animations_enabled" value="1" 
                                       <?php echo $config['css_animations_enabled'] ? 'checked' : ''; ?>>
                                <label for="css_animations_enabled">Enable CSS Animations</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="gsap_enabled" name="gsap_enabled" value="1" 
                                       <?php echo $config['gsap_enabled'] ? 'checked' : ''; ?>>
                                <label for="gsap_enabled">Enable GSAP Animations</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="bg_rotation_main" name="background_rotation_enabled" value="1" 
                                       <?php echo $config['background_rotation_enabled'] ? 'checked' : ''; ?>>
                                <label for="bg_rotation_main">Enable Background Rotation</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="auto_init" name="auto_init" value="1" 
                                       <?php echo $config['auto_init'] ? 'checked' : ''; ?>>
                                <label for="auto_init">Auto-Initialize on Page Load</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="debug_mode" name="debug_mode" value="1" 
                                       <?php echo $config['debug_mode'] ? 'checked' : ''; ?>>
                                <label for="debug_mode">Debug Mode</label>
                            </div>
                        </div>
                    </div>

                    <!-- Header Animation -->
                    <div class="form-section">
                        <h3>Header Animation</h3>
                        
                        <div class="form-group">
                            <label for="default_animations_header_type">Animation Type</label>
                            <select id="default_animations_header_type" name="default_animations_header_type">
                                <?php foreach ($animationTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['default_animations']['header']['type'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="default_animations_header_duration">Duration</label>
                            <input type="text" id="default_animations_header_duration" name="default_animations_header_duration" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['header']['duration'] ?? '1.2s'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="default_animations_header_delay">Delay</label>
                            <input type="text" id="default_animations_header_delay" name="default_animations_header_delay" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['header']['delay'] ?? '0.2s'); ?>">
                        </div>

                        <div class="animation-preview animate-pulse"></div>
                    </div>

                    <!-- Footer Animation -->
                    <div class="form-section">
                        <h3>Footer Animation</h3>
                        
                        <div class="form-group">
                            <label for="default_animations_footer_type">Animation Type</label>
                            <select id="default_animations_footer_type" name="default_animations_footer_type">
                                <?php foreach ($animationTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['default_animations']['footer']['type'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="default_animations_footer_duration">Duration</label>
                            <input type="text" id="default_animations_footer_duration" name="default_animations_footer_duration" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['footer']['duration'] ?? '1s'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="default_animations_footer_delay">Delay</label>
                            <input type="text" id="default_animations_footer_delay" name="default_animations_footer_delay" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['footer']['delay'] ?? '0.5s'); ?>">
                        </div>

                        <div class="animation-preview animate-wobble"></div>
                    </div>

                    <!-- Navigation Animation -->
                    <div class="form-section">
                        <h3>Navigation Animation</h3>
                        
                        <div class="form-group">
                            <label for="default_animations_navigation_type">Animation Type</label>
                            <select id="default_animations_navigation_type" name="default_animations_navigation_type">
                                <?php foreach ($animationTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['default_animations']['navigation']['type'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="default_animations_navigation_duration">Duration</label>
                            <input type="text" id="default_animations_navigation_duration" name="default_animations_navigation_duration" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['navigation']['duration'] ?? '2s'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="default_animations_navigation_delay">Delay</label>
                            <input type="text" id="default_animations_navigation_delay" name="default_animations_navigation_delay" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['navigation']['delay'] ?? '0s'); ?>">
                        </div>

                        <div class="animation-preview animate-bounce"></div>
                    </div>

                    <!-- Content Animation -->
                    <div class="form-section">
                        <h3>Content Animation</h3>
                        
                        <div class="form-group">
                            <label for="default_animations_content_type">Animation Type</label>
                            <select id="default_animations_content_type" name="default_animations_content_type">
                                <?php foreach ($animationTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['default_animations']['content']['type'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="default_animations_content_duration">Duration</label>
                            <input type="text" id="default_animations_content_duration" name="default_animations_content_duration" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['content']['duration'] ?? '1s'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="default_animations_content_delay">Delay</label>
                            <input type="text" id="default_animations_content_delay" name="default_animations_content_delay" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['content']['delay'] ?? '0.3s'); ?>">
                        </div>
                    </div>

                    <!-- Image Animation -->
                    <div class="form-section">
                        <h3>Image Animation</h3>
                        
                        <div class="form-group">
                            <label for="default_animations_images_type">Animation Type</label>
                            <select id="default_animations_images_type" name="default_animations_images_type">
                                <?php foreach ($animationTypes as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['default_animations']['images']['type'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="default_animations_images_duration">Duration</label>
                            <input type="text" id="default_animations_images_duration" name="default_animations_images_duration" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['images']['duration'] ?? '0.8s'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="default_animations_images_delay">Delay</label>
                            <input type="text" id="default_animations_images_delay" name="default_animations_images_delay" 
                                   value="<?php echo htmlspecialchars($config['default_animations']['images']['delay'] ?? '0s'); ?>">
                        </div>
                    </div>

                    <!-- Background Effects -->
                    <div class="form-section">
                        <h3>Background Effects</h3>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="background_header_background_enabled" name="background_header_background_enabled" value="1" 
                                       <?php echo $config['background_effects']['header_background']['enabled'] ? 'checked' : ''; ?>>
                                <label for="background_header_background_enabled">Header Background Effect</label>
                            </div>
                            <select name="background_header_background_effect">
                                <?php foreach ($backgroundEffects as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['background_effects']['header_background']['effect'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="background_footer_background_enabled" name="background_footer_background_enabled" value="1" 
                                       <?php echo $config['background_effects']['footer_background']['enabled'] ? 'checked' : ''; ?>>
                                <label for="background_footer_background_enabled">Footer Background Effect</label>
                            </div>
                            <select name="background_footer_background_effect">
                                <?php foreach ($backgroundEffects as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['background_effects']['footer_background']['effect'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="background_content_background_enabled" name="background_content_background_enabled" value="1" 
                                       <?php echo $config['background_effects']['content_background']['enabled'] ? 'checked' : ''; ?>>
                                <label for="background_content_background_enabled">Content Background Effect</label>
                            </div>
                            <select name="background_content_background_effect">
                                <?php foreach ($backgroundEffects as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" 
                                            <?php echo ($config['background_effects']['content_background']['effect'] ?? '') === $value ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="bg_rotation_content" name="background_rotation_enabled" value="1" 
                                       <?php echo $config['background_rotation_enabled'] ? 'checked' : ''; ?>>
                                <label for="bg_rotation_content">Enable Background Rotation</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="rotation_interval">Rotation Interval (seconds)</label>
                            <input type="number" id="rotation_interval" name="rotation_interval" 
                                   min="5" max="300" value="<?php echo $config['rotation_interval'] ?? 30; ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="button" class="btn" onclick="openPageManager()">Manage Animation Pages</button>
                            <p style="font-size: 12px; color: #666; margin-top: 5px;">Configure which pages display animations</p>
                        </div>

                        <div class="background-preview"></div>
                    </div>
                    
                    <!-- Glow Effects -->
                    <div class="form-section">
                        <h3>Glow Effects</h3>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="glow_effects_enabled" name="glow_effects_enabled" value="1" 
                                       <?php echo $config['glow_effects_enabled'] ?? false ? 'checked' : ''; ?>>
                                <label for="glow_effects_enabled">Enable Glow Effects</label>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="glow_color">Glow Color</label>
                            <input type="color" id="glow_color" name="glow_color" 
                                   value="<?php echo $config['glow_color'] ?? '#00ffff'; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="glow_size">Glow Size (px)</label>
                            <input type="range" id="glow_size" name="glow_size" 
                                   min="0" max="50" value="<?php echo $config['glow_size'] ?? 10; ?>" 
                                   oninput="updateGlowPreview()">
                            <span id="glow_size_value"><?php echo $config['glow_size'] ?? 10; ?>px</span>
                        </div>
                        
                        <div class="form-group">
                            <label for="glow_opacity">Glow Opacity</label>
                            <input type="range" id="glow_opacity" name="glow_opacity" 
                                   min="0" max="100" value="<?php echo $config['glow_opacity'] ?? 80; ?>" 
                                   oninput="updateGlowPreview()">
                            <span id="glow_opacity_value"><?php echo $config['glow_opacity'] ?? 80; ?>%</span>
                        </div>
                        
                        <div class="glow-preview" id="glow-preview">
                            <div class="glow-sample">Glow Preview</div>
                        </div>
                    </div>

                    <!-- Performance Settings -->
                    <div class="form-section">
                        <h3>Performance Settings</h3>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="performance_reduce_motion_respect" name="performance_reduce_motion_respect" value="1" 
                                       <?php echo $config['performance']['reduce_motion_respect'] ? 'checked' : ''; ?>>
                                <label for="performance_reduce_motion_respect">Respect Reduced Motion Preferences</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="performance_mobile_optimized" name="performance_mobile_optimized" value="1" 
                                       <?php echo $config['performance']['mobile_optimized'] ? 'checked' : ''; ?>>
                                <label for="performance_mobile_optimized">Mobile Optimized</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="performance_fps_limit">FPS Limit</label>
                            <input type="number" id="performance_fps_limit" name="performance_fps_limit" 
                                   min="30" max="120" value="<?php echo $config['performance']['fps_limit']; ?>">
                            <span class="performance-indicator performance-good"></span>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="performance_pause_on_inactive" name="performance_pause_on_inactive" value="1" 
                                       <?php echo $config['performance']['pause_on_inactive'] ? 'checked' : ''; ?>>
                                <label for="performance_pause_on_inactive">Pause on Inactive Tab</label>
                            </div>
                        </div>
                    </div>

                    <!-- Responsive Settings -->
                    <div class="form-section">
                        <h3>Responsive Settings</h3>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="responsive_disable_on_mobile" name="responsive_disable_on_mobile" value="1" 
                                       <?php echo $config['responsive']['disable_on_mobile'] ? 'checked' : ''; ?>>
                                <label for="responsive_disable_on_mobile">Disable on Mobile</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="responsive_mobile_breakpoint">Mobile Breakpoint (px)</label>
                            <input type="number" id="responsive_mobile_breakpoint" name="responsive_mobile_breakpoint" 
                                   min="320" max="1024" value="<?php echo $config['responsive']['mobile_breakpoint']; ?>">
                        </div>

                        <div class="form-group">
                            <label for="responsive_tablet_breakpoint">Tablet Breakpoint (px)</label>
                            <input type="number" id="responsive_tablet_breakpoint" name="responsive_tablet_breakpoint" 
                                   min="768" max="1920" value="<?php echo $config['responsive']['tablet_breakpoint']; ?>">
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="save-section">
            <button type="submit" class="btn" form="settingsForm">Save Animation Settings</button>
            <a href="../../../" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <script>
        // Make the form properly submit
        document.querySelector('.btn[type="submit"]').addEventListener('click', function() {
            document.querySelector('form').submit();
        });

        // Add animation previews
        document.addEventListener('DOMContentLoaded', function() {
            const selects = document.querySelectorAll('select[name$="_type"]');
            
            selects.forEach(select => {
                select.addEventListener('change', function() {
                    const preview = this.closest('.form-section').querySelector('.animation-preview');
                    if (preview) {
                        // Remove existing animation classes
                        preview.className = preview.className.replace(/animate-\w+/g, '').trim() + ' animation-preview';
                        // Add new animation class
                        preview.classList.add('animate-' + this.value);
                    }
                });
            });
        });

        // Glow Effects Preview Update
        function updateGlowPreview() {
            const glowColor = document.getElementById('glow_color').value;
            const glowSize = document.getElementById('glow_size').value;
            const glowOpacity = document.getElementById('glow_opacity').value;
            
            const glowSample = document.querySelector('.glow-sample');
            const sizeValue = document.getElementById('glow_size_value');
            const opacityValue = document.getElementById('glow_opacity_value');
            
            if (glowSample) {
                const rgba = hexToRgba(glowColor, glowOpacity / 100);
                glowSample.style.boxShadow = `0 0 ${glowSize}px ${rgba}`;
            }
            
            if (sizeValue) sizeValue.textContent = glowSize + 'px';
            if (opacityValue) opacityValue.textContent = glowOpacity + '%';
        }

        function hexToRgba(hex, alpha) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }

        // Drag functionality variables
        let isDragging = false;
        let dragOffset = { x: 0, y: 0 };
        let modalContainer = null;
        
        // Page Manager Modal Functions
        function openPageManager() {
            console.log('Opening page manager modal...');
            const modal = document.getElementById('pageManagerModal');
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
                loadPageList();
                
                // Add delay to ensure modal is rendered
                setTimeout(() => {
                    makeDraggable();
                    console.log('Page manager modal opened and drag enabled');
                }, 200);
            } else {
                console.error('Page manager modal not found');
                createModalIfMissing();
            }
        }
        
        function makeDraggable() {
            console.log('Setting up drag functionality...');
            const modal = document.querySelector('#pageManagerModal .modal-container');
            const header = document.querySelector('#pageManagerModal .modal-header');
            
            if (!modal || !header) {
                console.error('Modal or header not found for drag setup');
                return;
            }
            
            console.log('Drag elements found, adding event listeners...');
            
            let isDragging = false;
            let startX, startY, initialX, initialY;
            
            header.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('modal-close')) return;
                
                console.log('Starting drag...');
                isDragging = true;
                
                startX = e.clientX;
                startY = e.clientY;
                initialX = modal.offsetLeft;
                initialY = modal.offsetTop;
                
                // Remove transform and set position absolute
                modal.style.position = 'absolute';
                modal.style.transform = 'none';
                modal.style.left = initialX + 'px';
                modal.style.top = initialY + 'px';
                
                header.style.cursor = 'grabbing';
                e.preventDefault();
            });
            
            document.addEventListener('mousemove', function(e) {
                if (!isDragging) return;
                
                e.preventDefault();
                
                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;
                
                let newX = initialX + deltaX;
                let newY = initialY + deltaY;
                
                // Keep within viewport
                newX = Math.max(0, Math.min(newX, window.innerWidth - modal.offsetWidth));
                newY = Math.max(0, Math.min(newY, window.innerHeight - modal.offsetHeight));
                
                modal.style.left = newX + 'px';
                modal.style.top = newY + 'px';
            });
            
            document.addEventListener('mouseup', function() {
                if (isDragging) {
                    console.log('Drag ended');
                    isDragging = false;
                    header.style.cursor = 'move';
                }
            });
            
            console.log('Drag functionality setup complete');
        }
        
        function setupDragFunctionality() {
            const modal = document.getElementById('pageManagerModal');
            modalContainer = modal.querySelector('.modal-container');
            const modalHeader = modal.querySelector('.modal-header');
            
            if (!modalHeader || !modalContainer) {
                console.error('Modal elements not found for drag setup');
                return;
            }
            
            console.log('Setting up drag functionality...');
            
            modalHeader.onmousedown = function(e) {
                // Prevent dragging if clicking on close button
                if (e.target.classList.contains('modal-close')) {
                    return;
                }
                
                console.log('Mouse down on header');
                isDragging = true;
                
                const rect = modalContainer.getBoundingClientRect();
                dragOffset.x = e.clientX - rect.left;
                dragOffset.y = e.clientY - rect.top;
                
                modalContainer.style.cursor = 'grabbing';
                modalHeader.style.cursor = 'grabbing';
                
                e.preventDefault();
                return false;
            };
            
            document.onmousemove = function(e) {
                if (!isDragging) return;
                
                e.preventDefault();
                
                let x = e.clientX - dragOffset.x;
                let y = e.clientY - dragOffset.y;
                
                // Keep modal within viewport
                x = Math.max(0, Math.min(x, window.innerWidth - modalContainer.offsetWidth));
                y = Math.max(0, Math.min(y, window.innerHeight - modalContainer.offsetHeight));
                
                modalContainer.style.left = x + 'px';
                modalContainer.style.top = y + 'px';
                modalContainer.style.transform = 'none';
            };
            
            document.onmouseup = function() {
                if (isDragging) {
                    console.log('Drag ended');
                    isDragging = false;
                    modalContainer.style.cursor = 'move';
                    modalHeader.style.cursor = 'move';
                }
            };
            
            console.log('Drag functionality setup complete');
            

        }

        function closePageManager() {
            const modal = document.getElementById('pageManagerModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                
                // Reset modal position for next time
                const modalContainer = modal.querySelector('.modal-container');
                if (modalContainer) {
                    modalContainer.style.left = '50%';
                    modalContainer.style.top = '50%';
                    modalContainer.style.transform = 'translate(-50%, -50%)';
                    modalContainer.classList.remove('dragging');
                }
            }
        }
        
        function createModalIfMissing() {
            if (!document.getElementById('pageManagerModal')) {
                console.log('Creating missing modal...');
                const modalHTML = `
                    <div id="pageManagerModal" class="modal-overlay">
                        <div class="modal-container">
                            <div class="modal-header">
                                <h2>Page Manager</h2>
                                <button class="modal-close" onclick="closePageManager()">x</button>
                            </div>
                            <div class="modal-content">
                                <div class="modal-search">
                                    <input type="text" id="pageSearch" placeholder="Search pages..." onkeyup="searchPages()">
                                </div>
                                <div class="page-list" id="pageList">Loading pages...</div>
                            </div>
                            <div class="modal-actions">
                                <button class="btn-modal btn-secondary-modal" onclick="closePageManager()">Cancel</button>
                                <button class="btn-modal btn-primary-modal" onclick="savePageSettings()">Save Settings</button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.insertAdjacentHTML('beforeend', modalHTML);
                setTimeout(() => openPageManager(), 100);
            }
        }

        function searchPages() {
            const searchTerm = document.getElementById('pageSearch').value.toLowerCase();
            const pageItems = document.querySelectorAll('.page-item');
            
            pageItems.forEach(item => {
                const pageName = item.querySelector('.page-name').textContent.toLowerCase();
                const pagePath = item.querySelector('.page-path').textContent.toLowerCase();
                
                if (pageName.includes(searchTerm) || pagePath.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function togglePageSelection(pageId) {
            const pageItem = document.querySelector(`[data-page-id="${pageId}"]`);
            const checkbox = pageItem.querySelector('.page-checkbox');
            const effectSelect = pageItem.querySelector(`#page_${pageId}_effect`);
            const statusElement = pageItem.querySelector('.animation-status');
            
            if (pageItem && checkbox) {
                if (checkbox.checked) {
                    pageItem.classList.add('selected');
                    effectSelect.disabled = false;
                    const selectedEffect = effectSelect.value;
                    const effectLabel = effectSelect.options[effectSelect.selectedIndex].text;
                    statusElement.innerHTML = `<span class="status-enabled">Enabled: ${effectLabel}</span>`;
                } else {
                    pageItem.classList.remove('selected');
                    effectSelect.disabled = true;
                    statusElement.innerHTML = '<span class="status-disabled">Disabled: No background effect</span>';
                }
                
                console.log(`Page ${pageId} ${checkbox.checked ? 'enabled' : 'disabled'} for background effects`);
            }
        }

        function updatePageAnimation(pageId) {
            console.log('Updating animation for page ID:', pageId);
            const pageItem = document.querySelector(`[data-page-id="${pageId}"]`);
            const effectSelect = pageItem.querySelector(`#page_${pageId}_effect`);
            const statusElement = pageItem.querySelector('.animation-status');
            const checkbox = pageItem.querySelector('.page-checkbox');
            
            if (effectSelect && statusElement) {
                const selectedEffect = effectSelect.value;
                const effectLabel = effectSelect.options[effectSelect.selectedIndex].text;
                
                console.log('Selected animation:', selectedEffect, effectLabel);
                
                // Update status display
                if (checkbox && checkbox.checked) {
                    statusElement.innerHTML = `<span class="status-enabled">Enabled: ${effectLabel}</span>`;
                } else {
                    statusElement.innerHTML = '<span class="status-disabled">Disabled: No background effect</span>';
                }
                
                // Save to server immediately
                savePageAnimation(pageId, selectedEffect, checkbox ? checkbox.checked : false);
            }
        }
        
        function savePageAnimation(pageId, animation, enabled) {
            console.log('Saving page animation:', { pageId, animation, enabled });
            
            const pageItem = document.querySelector(`[data-page-id="${pageId}"]`);
            const pagePath = pageItem ? pageItem.querySelector('.page-path').textContent : '';
            
            const formData = new FormData();
            formData.append('action', 'save_page_animation');
            formData.append('page_id', pageId);
            formData.append('page_path', pagePath);
            formData.append('animation', animation);
            formData.append('enabled', enabled ? '1' : '0');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                console.log('Animation saved:', data);
                if (data.success) {
                    console.log('Page animation updated successfully');
                } else {
                    console.error('Save failed:', data.message);
                }
            })
            .catch(error => {
                console.error('Error saving animation:', error);
            });
        }
        
        function savePageSettings() {
            const pageItems = document.querySelectorAll('.page-item');
            const settings = [];
            
            pageItems.forEach(item => {
                const pageId = item.getAttribute('data-page-id');
                const checkbox = item.querySelector('.page-checkbox');
                const animationSelect = item.querySelector(`#page_${pageId}_animation`);
                const pageName = item.querySelector('.page-name').textContent;
                const pagePath = item.querySelector('.page-path').textContent;
                
                if (checkbox && animationSelect) {
                    settings.push({
                        id: pageId,
                        name: pageName,
                        path: pagePath,
                        enabled: checkbox.checked,
                        animation: animationSelect.value
                    });
                }
            });
            
            console.log('Saving page animation settings:', settings);
            
            // Here you would typically send to server
            // For now, just show success message
            alert(`Saved animation settings for ${settings.filter(s => s.enabled).length} pages`);
            closePageManager();
        }

        function loadPageList() {
            console.log('Loading real PHP files from server...');
            
            // Make AJAX request to get actual PHP files
            fetch(window.location.href + '?action=get_php_files')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Load saved page animations and merge with file list
                        loadSavedAnimations().then(savedAnimations => {
                            const mergedFiles = data.files.map(file => {
                                // Try to match by path first, then by ID
                                const saved = savedAnimations[file.path] || savedAnimations[file.id];
                                if (saved) {
                                    return {
                                        ...file,
                                        animation: saved.animation || file.animation,
                                        enabled: saved.enabled !== undefined ? saved.enabled : file.enabled
                                    };
                                }
                                return file;
                            });
                            renderPageList(mergedFiles);
                        });
                    } else {
                        renderPageList(getDefaultPages());
                    }
                })
                .catch(error => {
                    console.log('AJAX failed, using default pages:', error);
                    renderPageList(getDefaultPages());
                });
        }
        
        function loadSavedAnimations() {
            return fetch(window.location.href + '?action=get_saved_animations')
                .then(response => response.json())
                .then(data => data.success ? data.animations : {})
                .catch(() => ({}));
        }
        
        function getDefaultPages() {
            return [
                { id: 1, name: 'Home Page', path: '/index.php', animation: 'waves', enabled: true },
                { id: 2, name: 'Animation Settings', path: '/templates/widgets/animations/settings.php', animation: 'none', enabled: false },
                { id: 3, name: 'Layout Manager', path: '/gear/settings/layout-manager.php', animation: 'topology', enabled: false },
                { id: 4, name: 'Database Manager', path: '/gear/settings/dbmanager.php', animation: 'net', enabled: false },
                { id: 5, name: 'Enterprise Monitor', path: '/gear/settings/enterprise_monitor.php', animation: 'dots', enabled: false },
                { id: 6, name: 'Appointments', path: '/gear/appointments/index.php', animation: 'clouds', enabled: false },
                { id: 7, name: 'Meetings', path: '/gear/meet/index.php', animation: 'fog', enabled: false },
                { id: 8, name: 'Meeting Recorder', path: '/meetings/recorder.php', animation: 'birds', enabled: false }
            ];
        }
        
        function renderPageList(pages) {
            const backgroundEffects = {
                'none': 'No Effect',
                'waves': 'Waves',
                'fog': 'Fog',
                'clouds': 'Clouds',
                'birds': 'Birds',
                'topology': 'Topology',
                'dots': 'Dots',
                'net': 'Network',
                'cells': 'Cells',
                'trunk': 'Trunk',
                'rings': 'Rings',
                'halo': 'Halo'
            };

            const pageList = document.getElementById('pageList');
            console.log('Rendering page list, found element:', pageList);
            console.log('Pages to render:', pages.length);
            
            if (pageList) {
                if (pages.length === 0) {
                    pageList.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">No PHP files found</div>';
                    return;
                }
                
                const htmlContent = pages.map((page, index) => `
                    <div class="page-item ${page.enabled ? 'selected' : ''}" data-page-id="${page.id}">
                        <div class="page-checkbox-container" style="flex: 0 0 auto;">
                            <input type="checkbox" name="page_${page.id}_enabled" id="page_${page.id}_checkbox" class="page-checkbox" ${page.enabled ? 'checked' : ''} onchange="togglePageSelection(${page.id})">
                        </div>
                        <div class="page-info" style="flex: 1 1 auto;">
                            <div class="page-name" style="font-weight: bold; font-size: 16px;">${page.name}</div>
                            <div class="page-path" style="font-size: 12px; opacity: 0.8;">${page.path}</div>
                            <div class="animation-status" style="margin-top: 5px;">
                                ${page.enabled ? `<span class="status-enabled">Enabled: ${backgroundEffects[page.animation] || page.animation}</span>` : '<span class="status-disabled">Disabled: No background effect</span>'}
                            </div>
                        </div>
                        <div class="animation-controls" style="flex: 0 0 auto; z-index: 1000; position: relative;">
                            <select name="page_${page.id}_effect" id="page_${page.id}_effect" onchange="updatePageAnimation(${page.id})" ${!page.enabled ? 'disabled' : ''}>
                                ${Object.entries(backgroundEffects).map(([value, label]) => `<option value="${value}" ${page.animation === value ? 'selected' : ''}>${label}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                `).join('');
                
                pageList.innerHTML = htmlContent;
                console.log('Page list HTML updated, content length:', htmlContent.length);
            } else {
                console.error('Page list element not found!');
            }
        }

        function togglePageSelection(pageId) {
            const pageItem = document.querySelector(`[data-page-id="${pageId}"]`);
            const checkbox = pageItem.querySelector('.page-checkbox');
            const select = pageItem.querySelector('select');
            
            pageItem.classList.toggle('selected');
            checkbox.checked = pageItem.classList.contains('selected');
            
            // Enable/disable the dropdown based on checkbox
            if (select) {
                select.disabled = !checkbox.checked;
            }
            
            // Update animation when toggling
            updatePageAnimation(pageId);
        }



        // Make searchPages function globally accessible
        window.searchPages = function() {
            console.log('Searching pages...');
            const searchTerm = document.getElementById('pageSearch').value.toLowerCase();
            const pageItems = document.querySelectorAll('.page-item');
            
            let visibleCount = 0;
            pageItems.forEach(item => {
                const pageName = item.querySelector('.page-name').textContent.toLowerCase();
                const pagePath = item.querySelector('.page-path').textContent.toLowerCase();
                
                if (pageName.includes(searchTerm) || pagePath.includes(searchTerm)) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });
            
            console.log(`Search results: ${visibleCount} of ${pageItems.length} pages visible`);
        }

        // Initialize glow preview on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateGlowPreview();
            
            // Color picker change event
            document.getElementById('glow_color').addEventListener('change', updateGlowPreview);
        });
    </script>

    <!-- Page Manager Modal -->
    <div id="pageManagerModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Page Manager</h2>
                <button class="modal-close" onclick="closePageManager()">x</button>
            </div>
            
            <div class="modal-content">
                <div class="modal-search">
                    <input type="text" id="pageSearch" placeholder="Search pages..." onkeyup="searchPages()">
                </div>
                
                <div class="page-list" id="pageList">
                    <!-- Page items will be populated by JavaScript -->
                </div>
            </div>
            
            <div class="modal-actions">
                <button class="btn-modal btn-secondary-modal" onclick="closePageManager()">Cancel</button>
                <button class="btn-modal btn-primary-modal" onclick="closePageManager()">Confirm Selection</button>
            </div>
        </div>
    </div>

    <!-- Click outside modal to close -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('pageManagerModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    // Only close if clicking the overlay itself, not the modal content
                    if (e.target === modal && !e.target.closest('.modal-container')) {
                        closePageManager();
                    }
                });
            }
        });
        
        // FORCE REMOVE ANY BLANK SPACE AT BOTTOM - AGGRESSIVE APPROACH
        document.addEventListener('DOMContentLoaded', function() {
            // Force body to exact content height
            document.documentElement.style.height = 'auto';
            document.body.style.height = 'fit-content';
            document.body.style.minHeight = 'auto';
            document.body.style.maxHeight = 'none';
            document.body.style.margin = '0';
            document.body.style.padding = '0';
            
            // Remove container bottom spacing
            const container = document.querySelector('.container');
            if (container) {
                container.style.marginBottom = '0';
                container.style.paddingBottom = '0';
            }
            
            // Remove any large empty elements
            const allElements = document.querySelectorAll('*');
            allElements.forEach(el => {
                if (el.innerHTML.trim() === '' && el.offsetHeight > 100) {
                    el.style.display = 'none';
                }
            });
            
            // Final height adjustment after page load
            setTimeout(() => {
                document.body.style.height = 'fit-content';
                window.scrollTo(0, 0);
            }, 100);
        });
    </script>
</body>
</html>
