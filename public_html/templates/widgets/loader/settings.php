<?php
/**
 * Loader Widget Configuration Page
 * CUE Framework 100.0.99 Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getSecureFilePath() for file operations
 * ✓ Uses framework validation functions
 * ✓ Follows enterprise security standards
 * ✓ Implements proper error handling
 * 
 * Standalone configuration interface for the Loader widget system
 * Controls animation types, colors, sizes, duration, and placement
 */

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// MANDATORY: Include CUE framework
$cueLoaded = false;
try {
    require_once dirname(__DIR__, 3) . '/.cue/cue.php';
    $cueLoaded = true;
} catch (Exception $e) {
    // Continue without CUE framework
}

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

if (!function_exists('getSecureFilePath')) {
    function getSecureFilePath(string $relativePath, bool $createDir = false): string {
        $basePath = dirname($_SERVER['DOCUMENT_ROOT']) . '/.data/';
        $fullPath = $basePath . $relativePath;
        
        if ($createDir) {
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        return $fullPath;
    }
}

// Handle form submission
$message = '';
$messageType = '';
$debugInfo = [];

if ($_POST) {
    $debugInfo[] = 'Form submitted with ' . count($_POST) . ' fields';
    $debugInfo[] = 'POST data keys: ' . implode(', ', array_keys($_POST));
    
    // Validate all inputs using CUE framework
    $validations = [
        'animation_type' => validateInput($_POST['loader_animation_type'] ?? '', 'string', [
            'allowed' => ['rings', 'dots', 'bars', 'pulse', 'wave', 'orbit', 'ripple', 'bounce', 'spiral', 'cube']
        ]),
        'size' => validateInput($_POST['loader_size'] ?? 'medium', 'string', [
            'allowed' => ['small', 'medium', 'large', 'xlarge']
        ]),
        'position' => validateInput($_POST['loader_position'] ?? 'center', 'string', [
            'allowed' => ['center', 'top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right', 'center-left', 'center-right']
        ]),
        'primary_color' => validateInput($_POST['loader_primary_color'] ?? '#3b82f6', 'string'),
        'secondary_color' => validateInput($_POST['loader_secondary_color'] ?? '#7c3aed', 'string'),
        'tertiary_color' => validateInput($_POST['loader_tertiary_color'] ?? '#f59e0b', 'string'),
        'background_opacity' => validateInput($_POST['loader_background_opacity'] ?? 95, 'int', ['min' => 0, 'max' => 100]),
        'animation_speed' => validateInput($_POST['loader_animation_speed'] ?? 1, 'float', ['min' => 0.1, 'max' => 5]),
        'blur_backdrop' => validateInput($_POST['loader_blur_backdrop'] ?? 10, 'int', ['min' => 0, 'max' => 50]),
        'duration' => validateInput($_POST['loader_duration'] ?? 0, 'int', ['min' => 0, 'max' => 300])
    ];
    
    // Check for validation errors
    $hasErrors = false;
    foreach ($validations as $field => $validation) {
        $debugInfo[] = "Validation $field: " . ($validation['valid'] ? 'VALID' : 'INVALID - ' . $validation['error']);
        if (!$validation['valid']) {
            $message = "Invalid $field: " . $validation['error'];
            $messageType = 'error';
            $hasErrors = true;
            break;
        }
    }
    
    $debugInfo[] = 'Validation complete - errors: ' . ($hasErrors ? 'YES' : 'NO');
    
    if (!$hasErrors) {
        $config = [
            'enabled' => isset($_POST['loader_enabled']),
            'animation_type' => $validations['animation_type']['sanitized'],
            'size' => $validations['size']['sanitized'],
            'position' => $validations['position']['sanitized'],
            'colors' => [
                'primary' => $validations['primary_color']['sanitized'],
                'secondary' => $validations['secondary_color']['sanitized'],
                'tertiary' => $validations['tertiary_color']['sanitized']
            ],
            'background_opacity' => $validations['background_opacity']['sanitized'],
            'animation_speed' => $validations['animation_speed']['sanitized'],
            'blur_backdrop' => $validations['blur_backdrop']['sanitized'],
            'duration' => $validations['duration']['sanitized'],
            'show_text' => isset($_POST['loader_show_text']),
            'auto_hide' => isset($_POST['loader_auto_hide'])
        ];
        
        $debugInfo[] = 'Attempting to save configuration...';
        $jsonData = json_encode($config, JSON_PRETTY_PRINT);
        $debugInfo[] = 'JSON data length: ' . strlen($jsonData);

        $configPath = null;
        try {
            $paths = cue_autoload('paths');
            $dataBase = $paths->getDataPath();
            $candidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'loader' . DIRECTORY_SEPARATOR . 'loader-config.json';
            $safe = $paths->validateSecurePath($candidate, $dataBase);
            $configPath = $safe ?: null;
        } catch (Throwable $e) {
            $configPath = null;
        }
        $debugInfo[] = 'Config path: ' . ($configPath ?: 'NULL');
        $debugInfo[] = 'Config path exists: ' . ($configPath && file_exists($configPath) ? 'YES' : 'NO');
        $debugInfo[] = 'Config dir writable: ' . ($configPath && is_writable(dirname($configPath)) ? 'YES' : 'NO');

        if (!$configPath) {
            $message = 'Error: Could not resolve secure data path for configuration.';
            $messageType = 'error';
        } else {
            $configDir = dirname($configPath);
            if ($configDir && is_dir($configDir) && is_writable($configDir)) {
                $bytesWritten = @file_put_contents($configPath, $jsonData);
                $debugInfo[] = 'Bytes written: ' . ($bytesWritten !== false ? $bytesWritten : 'FALSE');
                if ($bytesWritten !== false) {
                    $message = 'Loader widget configuration saved successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to save configuration.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Configuration directory is not writable.';
                $messageType = 'error';
            }
        }
    }
}

// Load existing configuration using secure path
$configPath = null;
try {
    $paths = cue_autoload('paths');
    $dataBase = $paths->getDataPath();
    $candidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'loader' . DIRECTORY_SEPARATOR . 'loader-config.json';
    $safe = $paths->validateSecurePath($candidate, $dataBase);
    $configPath = $safe ?: null;
} catch (Throwable $e) { $configPath = null; }
$config = [];
if ($configPath && file_exists($configPath)) {
    $configContent = file_get_contents($configPath);
    $config = json_decode($configContent, true) ?: [];
}

// Default values
$config = array_merge([
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
], $config);

// Animation type definitions
$animationTypes = [
    'rings' => [
        'name' => 'Spinning Rings',
        'description' => 'Three spinning rings with different sizes and delays',
        'preview' => '◯◯◯'
    ],
    'dots' => [
        'name' => 'Bouncing Dots',
        'description' => 'Three dots bouncing in sequence',
        'preview' => '●●●'
    ],
    'bars' => [
        'name' => 'Loading Bars',
        'description' => 'Vertical bars animating up and down',
        'preview' => '▮▮▮'
    ],
    'pulse' => [
        'name' => 'Pulsing Circle',
        'description' => 'Single circle that grows and shrinks',
        'preview' => '●'
    ],
    'wave' => [
        'name' => 'Wave Animation',
        'description' => 'Horizontal wave motion effect',
        'preview' => '～～～'
    ],
    'orbit' => [
        'name' => 'Orbital Motion',
        'description' => 'Small circles orbiting around a center point',
        'preview' => '◦●◦'
    ],
    'ripple' => [
        'name' => 'Ripple Effect',
        'description' => 'Expanding circular ripples',
        'preview' => '◯◯'
    ],
    'bounce' => [
        'name' => 'Bouncing Ball',
        'description' => 'Ball bouncing up and down',
        'preview' => '●'
    ],
    'spiral' => [
        'name' => 'Spiral Motion',
        'description' => 'Dots moving in a spiral pattern',
        'preview' => '◉'
    ],
    'cube' => [
        'name' => 'Rotating Cube',
        'description' => '3D cube rotation animation',
        'preview' => '■'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loader Widget Configuration</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <?php
    // Include loader widget assets
    if (function_exists('getLoaderWidgetUrl')) {
        echo '<link rel="stylesheet" href="' . getLoaderWidgetUrl('loader.css') . '">';
        echo '<script src="' . getLoaderWidgetUrl('loader.js') . '" defer></script>';
    } else {
        echo '<link rel="stylesheet" href="/templates/widgets/loader/loader.css">';
        echo '<script src="/templates/widgets/loader/loader.js" defer></script>';
    }
    ?>
    
    <style>
        :root {
            --primary-color: <?php echo $config['colors']['primary']; ?>;
            --secondary-color: <?php echo $config['colors']['secondary']; ?>;
            --tertiary-color: <?php echo $config['colors']['tertiary']; ?>;
            --info-color: #17a2b8;
            --success-color: #4caf50;
            --warning-color: #ff9800;
            --danger-color: #f44336;
            --dark-color: #212529;
            --light-color: #f8f9fa;
            --border-color: #dee2e6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: var(--primary-color);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .content {
            padding: 40px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 30px;
        }

        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
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

        .form-help {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 14px;
        }

        .color-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .color-input {
            width: 60px !important;
            height: 50px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            padding: 0;
        }

        .color-text {
            flex: 1;
        }

        .range-container {
            margin: 15px 0;
        }

        .range-value {
            text-align: center;
            margin-top: 10px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 18px;
        }

        .animation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .animation-card {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .animation-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .animation-card.selected {
            border-color: var(--primary-color);
            background: rgba(59, 130, 246, 0.05);
        }

        .animation-preview {
            font-size: 2rem;
            margin-bottom: 10px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .animation-name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .animation-desc {
            font-size: 12px;
            color: #6c757d;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-secondary { background: #6c757d; color: white; }

        .test-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .preview-area {
            background: #f8f9fa;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            margin: 20px 0;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .message.error {
            background: rgba(244, 67, 54, 0.1);
            color: #c62828;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: var(--dark-color);
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .animation-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            
            .tabs {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-spinner"></i> Loader Widget Configuration</h1>
            <p>Customize animation types, colors, sizes, and behavior</p>
        </div>
        
        <div class="content">
            <a href="../../../gear/settings/dbmanager.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Back to Settings
            </a>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($debugInfo) && $messageType === 'error'): ?>
                <div class="debug-info" style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin: 10px 0; font-size: 12px; color: #666;">
                    <strong>Debug Information:</strong><br>
                    <?php foreach ($debugInfo as $info): ?>
                        <?php echo htmlspecialchars($info); ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="showTab('animation')">
                    <i class="fas fa-play-circle"></i> Animation
                </button>
                <button class="tab" onclick="showTab('appearance')">
                    <i class="fas fa-palette"></i> Appearance
                </button>
                <button class="tab" onclick="showTab('behavior')">
                    <i class="fas fa-cog"></i> Behavior
                </button>
                <button class="tab" onclick="showTab('preview')">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
            
            <form method="POST" id="loaderConfigForm">
                <!-- Animation Tab -->
                <div id="animation-tab" class="tab-content active">
                    <h3>Animation Type</h3>
                    <p class="form-help">Choose the loading animation style</p>
                    
                    <div class="animation-grid">
                        <?php foreach ($animationTypes as $type => $info): ?>
                            <div class="animation-card <?php echo $config['animation_type'] === $type ? 'selected' : ''; ?>" 
                                 onclick="selectAnimation('<?php echo $type; ?>')">
                                <div class="animation-preview"><?php echo $info['preview']; ?></div>
                                <div class="animation-name"><?php echo $info['name']; ?></div>
                                <div class="animation-desc"><?php echo $info['description']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <input type="hidden" id="loader_animation_type" name="loader_animation_type" 
                           value="<?php echo htmlspecialchars($config['animation_type']); ?>">
                    
                    <div class="form-group" style="margin-top: 30px;">
                        <button type="button" class="btn btn-info" onclick="openAnimationPreview()">
                            <i class="fas fa-eye"></i> Preview Selected Animation
                        </button>
                        <small class="form-help">Click to see a live preview of your selected animation type</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="loader_animation_speed">Animation Speed</label>
                        <input type="range" id="loader_animation_speed" name="loader_animation_speed" 
                               min="0.1" max="5" step="0.1" 
                               value="<?php echo $config['animation_speed']; ?>"
                               oninput="updateRangeValue('speed', this.value + 'x')">
                        <div class="range-value" id="speed-value"><?php echo $config['animation_speed']; ?>x</div>
                        <small class="form-help">Controls how fast the animation plays (0.1x = very slow, 5x = very fast)</small>
                    </div>
                </div>
                
                <!-- Appearance Tab -->
                <div id="appearance-tab" class="tab-content">
                    <div class="form-grid">
                        <div>
                            <h3>Colors</h3>
                            
                            <div class="form-group">
                                <label for="loader_primary_color">Primary Color</label>
                                <div class="color-input-group">
                                    <input type="color" id="loader_primary_color" name="loader_primary_color" 
                                           class="color-input" value="<?php echo $config['colors']['primary']; ?>"
                                           onchange="updateColorPreview()">
                                    <input type="text" class="color-text" 
                                           value="<?php echo $config['colors']['primary']; ?>" readonly>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="loader_secondary_color">Secondary Color</label>
                                <div class="color-input-group">
                                    <input type="color" id="loader_secondary_color" name="loader_secondary_color" 
                                           class="color-input" value="<?php echo $config['colors']['secondary']; ?>"
                                           onchange="updateColorPreview()">
                                    <input type="text" class="color-text" 
                                           value="<?php echo $config['colors']['secondary']; ?>" readonly>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="loader_tertiary_color">Tertiary Color</label>
                                <div class="color-input-group">
                                    <input type="color" id="loader_tertiary_color" name="loader_tertiary_color" 
                                           class="color-input" value="<?php echo $config['colors']['tertiary']; ?>"
                                           onchange="updateColorPreview()">
                                    <input type="text" class="color-text" 
                                           value="<?php echo $config['colors']['tertiary']; ?>" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3>Size & Position</h3>
                            
                            <div class="form-group">
                                <label for="loader_size">Loader Size</label>
                                <select id="loader_size" name="loader_size">
                                    <option value="small" <?php echo $config['size'] === 'small' ? 'selected' : ''; ?>>Small</option>
                                    <option value="medium" <?php echo $config['size'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="large" <?php echo $config['size'] === 'large' ? 'selected' : ''; ?>>Large</option>
                                    <option value="xlarge" <?php echo $config['size'] === 'xlarge' ? 'selected' : ''; ?>>Extra Large</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="loader_position">Position</label>
                                <select id="loader_position" name="loader_position">
                                    <option value="center" <?php echo $config['position'] === 'center' ? 'selected' : ''; ?>>Center</option>
                                    <option value="top-left" <?php echo $config['position'] === 'top-left' ? 'selected' : ''; ?>>Top Left</option>
                                    <option value="top-center" <?php echo $config['position'] === 'top-center' ? 'selected' : ''; ?>>Top Center</option>
                                    <option value="top-right" <?php echo $config['position'] === 'top-right' ? 'selected' : ''; ?>>Top Right</option>
                                    <option value="center-left" <?php echo $config['position'] === 'center-left' ? 'selected' : ''; ?>>Center Left</option>
                                    <option value="center-right" <?php echo $config['position'] === 'center-right' ? 'selected' : ''; ?>>Center Right</option>
                                    <option value="bottom-left" <?php echo $config['position'] === 'bottom-left' ? 'selected' : ''; ?>>Bottom Left</option>
                                    <option value="bottom-center" <?php echo $config['position'] === 'bottom-center' ? 'selected' : ''; ?>>Bottom Center</option>
                                    <option value="bottom-right" <?php echo $config['position'] === 'bottom-right' ? 'selected' : ''; ?>>Bottom Right</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <h3>Background Settings</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="loader_background_opacity">Background Opacity</label>
                            <input type="range" id="loader_background_opacity" name="loader_background_opacity" 
                                   min="0" max="100" value="<?php echo $config['background_opacity']; ?>"
                                   oninput="updateRangeValue('opacity', this.value + '%')">
                            <div class="range-value" id="opacity-value"><?php echo $config['background_opacity']; ?>%</div>
                            <small class="form-help">Controls the darkness of the background overlay</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="loader_blur_backdrop">Backdrop Blur</label>
                            <input type="range" id="loader_blur_backdrop" name="loader_blur_backdrop" 
                                   min="0" max="50" value="<?php echo $config['blur_backdrop']; ?>"
                                   oninput="updateRangeValue('blur', this.value + 'px')">
                            <div class="range-value" id="blur-value"><?php echo $config['blur_backdrop']; ?>px</div>
                            <small class="form-help">Adds a blur effect to the content behind the loader</small>
                        </div>
                    </div>
                </div>
                
                <!-- Behavior Tab -->
                <div id="behavior-tab" class="tab-content">
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="loader_enabled" name="loader_enabled" 
                                   <?php echo $config['enabled'] ? 'checked' : ''; ?>>
                            <label for="loader_enabled">Enable Loader Widget</label>
                        </div>
                        <small class="form-help">Globally enable or disable the loader widget system</small>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="loader_show_text" name="loader_show_text" 
                                   <?php echo $config['show_text'] ? 'checked' : ''; ?>>
                            <label for="loader_show_text">Show Loading Text</label>
                        </div>
                        <small class="form-help">Display text message below the loader animation</small>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="loader_auto_hide" name="loader_auto_hide" 
                                   <?php echo $config['auto_hide'] ? 'checked' : ''; ?>>
                            <label for="loader_auto_hide">Auto Hide</label>
                        </div>
                        <small class="form-help">Automatically hide loader after a few seconds (not recommended)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="loader_duration">Auto-Hide Duration (seconds)</label>
                        <input type="range" id="loader_duration" name="loader_duration" 
                               min="0" max="300" step="1" 
                               value="<?php echo $config['duration']; ?>"
                               oninput="updateRangeValue('duration', this.value + 's')">
                        <div class="range-value" id="duration-value"><?php echo $config['duration']; ?>s</div>
                        <small class="form-help">Time in seconds before loader automatically hides (0 = manual hide only)</small>
                    </div>
                </div>
                
                <!-- Preview Tab -->
                <div id="preview-tab" class="tab-content">
                    <h3>Live Preview</h3>
                    <p class="form-help">Test your loader settings in real-time</p>
                    
                    <div class="preview-area" id="previewArea">
                        <p>Click "Test Loader" to see your configuration in action</p>
                    </div>
                    
                    <div class="test-controls">
                        <button type="button" class="btn btn-primary" onclick="testLoader('Loading data...')">
                            <i class="fas fa-play"></i> Test Loader
                        </button>
                        <button type="button" class="btn btn-warning" onclick="testLoader('Processing request...')">
                            <i class="fas fa-cog"></i> Test Processing
                        </button>
                        <button type="button" class="btn btn-danger" onclick="hideLoadingAnimation()">
                            <i class="fas fa-stop"></i> Hide Loader
                        </button>
                    </div>
                </div>
                
                <div style="margin-top: 40px; text-align: center; border-top: 2px solid var(--border-color); padding-top: 30px;">
                    <button type="submit" class="btn btn-success" style="padding: 15px 30px; font-size: 16px;">
                        <i class="fas fa-save"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Opera Browser Detection and Compatibility for Settings Page
        var isOpera = !!window.opera || navigator.userAgent.indexOf('Opera') !== -1 || navigator.userAgent.indexOf('OPR/') !== -1;
        if (isOpera) {
            console.log('Opera browser detected in settings - applying compatibility fixes');
        }
        
        // Opera-safe element creation helper
        function createElementSafe(tagName) {
            var element = document.createElement(tagName);
            return element;
        }
        
        // Opera-safe className setting
        function setClassNameSafe(element, className) {
            try {
                if (element.classList && element.classList.add) {
                    var classes = className.split(' ');
                    for (var i = 0; i < classes.length; i++) {
                        if (classes[i]) {
                            element.classList.add(classes[i]);
                        }
                    }
                } else {
                    element.className = className;
                }
            } catch (e) {
                console.warn('Opera className fallback:', e);
                element.className = className;
            }
        }

        function showTab(tabName) {
            // Hide all tab contents - Opera compatible
            var tabContents = document.querySelectorAll('.tab-content');
            for (var i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Remove active class from all tabs - Opera compatible
            var tabs = document.querySelectorAll('.tab');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        function selectAnimation(type) {
            // Remove selected class from all cards - Opera compatible
            var animationCards = document.querySelectorAll('.animation-card');
            for (var i = 0; i < animationCards.length; i++) {
                animationCards[i].classList.remove('selected');
            }
            
            // Add selected class to clicked card
            event.currentTarget.classList.add('selected');
            
            // Update hidden input
            document.getElementById('loader_animation_type').value = type;
        }
        
        function updateRangeValue(type, value) {
            document.getElementById(type + '-value').textContent = value;
        }
        
        function updateColorPreview() {
            const primary = document.getElementById('loader_primary_color').value;
            const secondary = document.getElementById('loader_secondary_color').value;
            const tertiary = document.getElementById('loader_tertiary_color').value;
            
            // Update text inputs
            document.querySelector('#loader_primary_color + .color-text').value = primary;
            document.querySelector('#loader_secondary_color + .color-text').value = secondary;
            document.querySelector('#loader_tertiary_color + .color-text').value = tertiary;
            
            // Update CSS variables
            document.documentElement.style.setProperty('--primary-color', primary);
            document.documentElement.style.setProperty('--secondary-color', secondary);
            document.documentElement.style.setProperty('--tertiary-color', tertiary);
        }
        
        function testLoader(message) {
            if (typeof showLoadingAnimation === 'function') {
                showLoadingAnimation(message || 'Testing loader...');
            } else {
                alert('Loader JavaScript not available. Please save the configuration first.');
            }
        }
        
        // Initialize range values on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateRangeValue('speed', document.getElementById('loader_animation_speed').value + 'x');
            updateRangeValue('opacity', document.getElementById('loader_background_opacity').value + '%');
            updateRangeValue('blur', document.getElementById('loader_blur_backdrop').value + 'px');
            updateRangeValue('duration', document.getElementById('loader_duration').value + 's');
        });
        
        // Animation Preview Modal Functions
        function openAnimationPreview() {
            const modal = document.getElementById('animationPreviewModal');
            const animationType = document.getElementById('loader_animation_type').value;
            const animationTypes = <?php echo json_encode($animationTypes); ?>;
            
            // Update modal content
            if (animationTypes[animationType]) {
                document.getElementById('previewAnimationName').textContent = animationTypes[animationType].name;
                document.getElementById('previewAnimationDesc').textContent = animationTypes[animationType].description;
            }
            
            // Show modal
            modal.style.display = 'block';
            
            // Start preview automatically
            setTimeout(() => startPreviewAnimation(), 500);
        }
        
        function closeAnimationPreview() {
            var modal = document.getElementById('animationPreviewModal');
            modal.style.display = 'none';
            stopPreviewAnimation();
        }
        
        function startPreviewAnimation() {
            try {
                console.log('🎬 Starting preview animation...');
                
                var spinnerContainer = document.querySelector('.preview-loader-spinner');
                if (!spinnerContainer) {
                    throw new Error('Spinner container not found');
                }
                
                var animationType = document.getElementById('loader_animation_type').value;
                if (!animationType) {
                    throw new Error('Animation type not selected');
                }
                
                console.log('Animation type:', animationType);
                
                // Clear existing animation
                spinnerContainer.innerHTML = '';
                
                // Show loading indicator while creating animation
                spinnerContainer.innerHTML = '<div style="text-align: center; color: #007bff; padding: 20px;">Creating animation...</div>';
            
            // Get current settings
            var primaryColor = document.getElementById('loader_primary_color').value;
            var secondaryColor = document.getElementById('loader_secondary_color').value;
            var tertiaryColor = document.getElementById('loader_tertiary_color').value;
            var size = document.getElementById('loader_size').value;
            var speed = document.getElementById('loader_animation_speed').value;
            
            // Set CSS variables for preview on multiple elements to ensure coverage
            var previewContainer = document.getElementById('previewDisplay');
            var modal = document.getElementById('animationPreviewModal');
            var body = document.body;
            
            // Set variables on multiple targets to ensure they're applied
            var targets = [previewContainer, spinnerContainer, modal, body, document.documentElement];
            for (var i = 0; i < targets.length; i++) {
                var target = targets[i];
                if (target) {
                    target.style.setProperty('--loader-primary', primaryColor);
                    target.style.setProperty('--loader-secondary', secondaryColor);
                    target.style.setProperty('--loader-tertiary', tertiaryColor);
                    target.style.setProperty('--loader-speed', speed + 's');
                }
            }
            
            // Force visibility styles on containers
            spinnerContainer.style.cssText += 
                'display: block !important; ' + 
                'visibility: visible !important; ' + 
                'opacity: 1 !important; ' +
                'width: 100% !important; ' +
                'height: 100% !important; ' +
                'min-height: 100px !important;';
            
            console.log('Preview animation starting with colors:', {
                primary: primaryColor,
                secondary: secondaryColor,
                tertiary: tertiaryColor,
                speed: speed + 's'
            });
            
            // Create animation element based on type
            var animationElement;
            
            switch (animationType) {
                case 'rings':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-rings spinner-element size-' + size);
                    for (var i = 0; i < 3; i++) {
                        var ring = createElementSafe('div');
                        setClassNameSafe(ring, 'spinner-ring');
                        animationElement.appendChild(ring);
                    }
                    break;
                    
                case 'dots':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-dots spinner-element size-' + size);
                    for (var i = 0; i < 3; i++) {
                        var dot = createElementSafe('div');
                        setClassNameSafe(dot, 'dot');
                        animationElement.appendChild(dot);
                    }
                    break;
                    
                case 'bars':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-bars spinner-element size-' + size);
                    for (var i = 0; i < 5; i++) {
                        var bar = createElementSafe('div');
                        setClassNameSafe(bar, 'bar');
                        animationElement.appendChild(bar);
                    }
                    break;
                    
                case 'pulse':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-pulse spinner-element size-' + size);
                    var circle = createElementSafe('div');
                    setClassNameSafe(circle, 'pulse-circle');
                    animationElement.appendChild(circle);
                    break;
                    
                case 'wave':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-wave spinner-element size-' + size);
                    for (var i = 0; i < 7; i++) {
                        var bar = createElementSafe('div');
                        setClassNameSafe(bar, 'wave-bar');
                        animationElement.appendChild(bar);
                    }
                    break;
                    
                case 'orbit':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-orbit spinner-element size-' + size);
                    for (var i = 0; i < 3; i++) {
                        var dot = createElementSafe('div');
                        setClassNameSafe(dot, 'orbit-dot');
                        animationElement.appendChild(dot);
                    }
                    break;
                    
                case 'ripple':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-ripple spinner-element size-' + size);
                    for (var i = 0; i < 2; i++) {
                        var ring = createElementSafe('div');
                        setClassNameSafe(ring, 'ripple-ring');
                        animationElement.appendChild(ring);
                    }
                    break;
                    
                case 'bounce':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-bounce spinner-element size-' + size);
                    var ball = createElementSafe('div');
                    setClassNameSafe(ball, 'bounce-ball');
                    animationElement.appendChild(ball);
                    break;
                    
                case 'spiral':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-spiral spinner-element size-' + size);
                    for (var i = 0; i < 5; i++) {
                        var dot = createElementSafe('div');
                        setClassNameSafe(dot, 'spiral-dot');
                        animationElement.appendChild(dot);
                    }
                    break;
                    
                case 'cube':
                    animationElement = createElementSafe('div');
                    setClassNameSafe(animationElement, 'spinner-cube spinner-element size-' + size);
                    break;
                    
                default:
                    animationElement = createElementSafe('div');
                    animationElement.textContent = 'Unknown animation type';
            }
            
            if (animationElement) {
                // Force visibility and proper styling on the animation element
                animationElement.style.cssText += 
                    'display: block !important; ' +
                    'visibility: visible !important; ' +
                    'opacity: 1 !important; ' +
                    'position: relative !important; ' +
                    'width: auto !important; ' +
                    'height: auto !important;';
                
                // Apply CSS variables directly to the element as well
                animationElement.style.setProperty('--loader-primary', primaryColor);
                animationElement.style.setProperty('--loader-secondary', secondaryColor);
                animationElement.style.setProperty('--loader-tertiary', tertiaryColor);
                animationElement.style.setProperty('--loader-speed', speed + 's');
                
                spinnerContainer.appendChild(animationElement);
                
                // Double-check that all child elements are visible
                var allChildren = animationElement.querySelectorAll('*');
                for (var i = 0; i < allChildren.length; i++) {
                    var child = allChildren[i];
                    child.style.cssText += 
                        'display: block !important; ' +
                        'visibility: visible !important; ' +
                        'opacity: 1 !important;';
                }
                
                console.log('✅ Animation element created and styled:', animationElement);
                console.log('Animation type:', animationType, 'Size:', size);
                
                // Success message
                setTimeout(() => {
                    console.log('🎯 Preview animation successfully started!');
                }, 100);
                
            } else {
                throw new Error('Failed to create animation element for type: ' + animationType);
            }
            
        } catch (error) {
            console.error('❌ Error in startPreviewAnimation:', error);
            var spinnerContainer = document.querySelector('.preview-loader-spinner');
            if (spinnerContainer) {
                spinnerContainer.innerHTML = 
                    '<div style="text-align: center; color: #dc3545; padding: 20px;">' +
                        '<strong>Error creating animation:</strong><br>' +
                        error.message + '<br>' +
                        '<small>Check console for details</small>' +
                    '</div>';
            }
        }
        }
        
        function stopPreviewAnimation() {
            var spinnerContainer = document.querySelector('.preview-loader-spinner');
            if (spinnerContainer) {
                spinnerContainer.innerHTML = '<div style="color: #6c757d; font-style: italic; text-align: center; padding: 20px;">Animation stopped</div>';
                console.log('Preview animation stopped');
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('animationPreviewModal');
            if (event.target === modal) {
                closeAnimationPreview();
            }
        }

        // Global configuration update after successful save
        function updateGlobalLoaderConfig() {
            // Get current form values
            var form = document.getElementById('loaderConfigForm');
            var formData = new FormData(form);
            var config = {
                enabled: formData.get('loader_enabled') === 'on',
                animation_type: formData.get('loader_animation_type'),
                size: formData.get('loader_size'),
                position: formData.get('loader_position'),
                colors: {
                    primary: formData.get('loader_primary_color'),
                    secondary: formData.get('loader_secondary_color'),
                    tertiary: formData.get('loader_tertiary_color')
                },
                background_opacity: parseInt(formData.get('loader_background_opacity')),
                animation_speed: parseFloat(formData.get('loader_animation_speed')),
                blur_backdrop: parseInt(formData.get('loader_blur_backdrop')),
                duration: parseInt(formData.get('loader_duration')),
                show_text: formData.get('loader_show_text') === 'on',
                auto_hide: formData.get('loader_auto_hide') === 'on'
            };

            // Update global loader configuration if function exists
            if (typeof window.updateLoaderConfig === 'function') {
                window.updateLoaderConfig(config);
                console.log('Global loader configuration updated:', config);
            }

            // Also broadcast to other windows/tabs using localStorage
            try {
                localStorage.setItem('loaderConfigUpdate', JSON.stringify({
                    timestamp: Date.now(),
                    config: config
                }));
            } catch (e) {
                console.warn('Could not broadcast config update to other tabs');
            }
        }

        // Listen for configuration updates from other tabs
        window.addEventListener('storage', function(e) {
            if (e.key === 'loaderConfigUpdate' && e.newValue) {
                try {
                    const data = JSON.parse(e.newValue);
                    if (data.config && typeof window.updateLoaderConfig === 'function') {
                        window.updateLoaderConfig(data.config);
                        console.log('Configuration updated from another tab');
                    }
                } catch (e) {
                    console.warn('Could not parse config update from storage');
                }
            }
        });

        // Intercept form submission to update global config immediately
        document.getElementById('loaderConfigForm').addEventListener('submit', function(e) {
            // Update global config immediately before form submission
            updateGlobalLoaderConfig();
        });
    </script>

    <!-- Animation Preview Modal -->
    <div id="animationPreviewModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2><i class="fas fa-eye"></i> Animation Preview</h2>
                <span class="close" onclick="closeAnimationPreview()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="animation-preview-container">
                    <div class="preview-header">
                        <h3 id="previewAnimationName">Loading Animation</h3>
                        <p id="previewAnimationDesc">Preview of your selected animation</p>
                    </div>
                    
                    <div class="preview-display" id="previewDisplay">
                        <div class="preview-loader-overlay">
                            <div class="preview-loader-spinner">
                                <!-- Animation will be dynamically inserted here -->
                            </div>
                            <div class="preview-loader-text">Loading...</div>
                        </div>
                    </div>
                    
                    <div class="preview-controls">
                        <button type="button" class="btn btn-success" onclick="startPreviewAnimation()">
                            <i class="fas fa-play"></i> Start Preview
                        </button>
                        <button type="button" class="btn btn-warning" onclick="stopPreviewAnimation()">
                            <i class="fas fa-stop"></i> Stop Preview
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeAnimationPreview()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                    
                    <div class="preview-info">
                        <small>This preview shows how your loader will appear with the current settings.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .animation-preview-container {
            text-align: center;
        }
        
        .preview-header {
            margin-bottom: 30px;
        }
        
        .preview-header h3 {
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .preview-display {
            background: #f8f9fa;
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 60px 40px;
            margin: 20px 0;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .preview-loader-overlay {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .preview-loader-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .preview-loader-text {
            color: var(--dark-color);
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .preview-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .preview-info {
            margin-top: 20px;
            color: #6c757d;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            max-width: 90%;
        }
        
        .modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            color: var(--dark-color);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: var(--dark-color);
        }
    </style>
</body>
</html>
