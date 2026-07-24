<?php
/**
 * Header Settings Manager
 * @requires CUE Framework
 */

// Handle file browsing requests FIRST - allow when accessed directly or via global-ui manager
if(isset($_GET['browse_files'])) {
    // Completely clear any buffered output and start fresh
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Don't load any CUE framework - use direct path calculation
    $requestedPath = $_GET['path'] ?? '/';
    // Security: Ensure path is within public_html and normalize it
    $requestedPath = str_replace(['../', '..\\'], '', $requestedPath);
    $requestedPath = ltrim($requestedPath, '/');
    
    // Direct path to public_html without using CUE framework
    $basePath = dirname(dirname(__DIR__)); // Should be public_html
    $fullPath = $basePath . '/' . $requestedPath;
    
    $response = [];
    
    if(is_dir($fullPath)) {
        $items = scandir($fullPath);
        foreach($items as $item) {
            if($item === '.' || $item === '..') continue;
            
            $itemPath = $fullPath . '/' . $item;
            if(is_dir($itemPath)) {
                // Only show directories that might contain images
                if(!in_array($item, ['.cue', '.data', 'gear', 'temp', 'tmp'])) {
                    $response[] = [
                        'type' => 'folder',
                        'name' => $item
                    ];
                }
            } elseif(is_file($itemPath)) {
                // Only show image files
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])) {
                    $response[] = [
                        'type' => 'file',
                        'name' => $item
                    ];
                }
            }
        }
        
        // Sort by type (folders first) then by name
        usort($response, function($a, $b) {
            if($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
    } else {
        // Directory doesn't exist or is not accessible
        http_response_code(404);
        echo json_encode(['error' => 'Directory not found or not accessible']);
        exit;
    }
    
    // Output clean JSON and exit immediately
    echo json_encode($response);
    exit;
}

// Start output buffering to prevent any unwanted output
ob_start();

require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

// Load global UI functions
$functionsFile = __DIR__ . '/functions.php';
if (file_exists($functionsFile)) {
    require_once $functionsFile;
}

$configPath = getDataPath() . '/global-ui/header/header-config.json';
$config = [];

// Continue with normal page processing

// Load existing config
if(file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true) ?: [];
}

// Handle logo file upload
if(isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
    $uploadError = '';
    $uploadedFile = $_FILES['logo_file'];
    
    // Validate file size (max 2MB)
    if($uploadedFile['size'] > 2 * 1024 * 1024) {
        $uploadError = 'File size exceeds 2MB limit';
    }
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/svg+xml'];
    $fileType = mime_content_type($uploadedFile['tmp_name']);
    if(!in_array($fileType, $allowedTypes)) {
        $uploadError = 'Invalid file type. Only PNG, JPG, and SVG files are allowed';
    }
    
    // Validate image dimensions (min 50x50px)
    if(!$uploadError) {
        if($fileType !== 'image/svg+xml') {
            list($width, $height) = getimagesize($uploadedFile['tmp_name']);
            if($width < 50 || $height < 50) {
                $uploadError = 'Image dimensions must be at least 50x50 pixels';
            }
        }
    }
    
    // Move file if validation passes
    if(!$uploadError) {
        $uploadsDir = getPublicPath() . '/uploads/logos';
        if(!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $uploadedFile['name']);
        $targetPath = $uploadsDir . '/' . $fileName;
        
        if(move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            $_POST['logo'] = '/uploads/logos/' . $fileName;
        } else {
            $uploadError = 'Failed to save uploaded file';
        }
    }
    
    if($uploadError) {
    }
}

// Handle form submission
if(isset($_POST['action']) && $_POST['action'] === 'save_header') {
    // Debug: Log form submission
    error_log('Header Manager - Form submitted with action: ' . $_POST['action']);
    error_log('Header Manager - POST data keys: ' . implode(', ', array_keys($_POST)));
    error_log('Header Manager - All POST data: ' . print_r($_POST, true));
    
    // Validate key fields are present
    $missingFields = [];
    if ($_POST['action'] === 'save_header') {
        $requiredFields = ['action', 'title'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field])) {
                $missingFields[] = $field;
            }
        }
    } else {

        $requiredFields = ['action'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field])) {
                $missingFields[] = $field;
            }
        }
    }
    
    if (!empty($missingFields)) {
        error_log('Header Manager - Missing required fields: ' . implode(', ', $missingFields));
    }
    
    // Clear any file cache to ensure fresh read
    clearstatcache(true, $configPath);
    
    // Reload config to get latest data
    if(file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }
    
    // Reuse existing config ID or create new one if none exists
    $configId = null;
    if (isset($config['K::HeaderUI::Configuration'])) {
        $configKeys = array_keys($config['K::HeaderUI::Configuration']);
        if (!empty($configKeys)) {
            $configId = $configKeys[0]; // Use existing ID
        }
    }
    if (!$configId) {
        $configId = 'K::HeaderUI::Content::' . strtoupper(uniqid()); // Create new only if none exists
    }
    
    $newConfig = [
        'K::HeaderUI::Configuration' => [
            $configId => [
                // Site Title Configuration - K::HeaderUI::Title::*
                'hdr_site_name_enabled' => isset($_POST['site_name_enabled']),
                'hdr_site_name_text' => $_POST['title'] ?? '',
                'hdr_title_font' => $_POST['title_font'] ?? 'Merriweather-Regular',
                'hdr_title_position' => $_POST['title_position'] ?? 'left',
                'hdr_title_size' => (int)($_POST['title_size'] ?? '24'),
                'hdr_title_color' => $_POST['title_color'] ?? '#00ffff',
                'hdr_title_opacity' => (int)($_POST['title_opacity'] ?? '100'),
                'hdr_title_slogan_spacing' => (int)($_POST['title_slogan_spacing'] ?? '10'),
                'hdr_content_spacing' => (int)($_POST['header_content_spacing'] ?? '15'),
                'hdr_auto_offset' => isset($_POST['hdr_auto_offset']),
                
                // Slogan/Subtitle Configuration - K::HeaderUI::Slogan::*
                'hdr_slogan_enabled' => isset($_POST['slogan_enabled']),
                'hdr_slogan_text' => $_POST['slogan_text'] ?? '',
                'hdr_slogan_font' => $_POST['slogan_font'] ?? 'Merriweather-Regular',
                'hdr_slogan_size' => (int)($_POST['slogan_size'] ?? '16'),
                'hdr_slogan_position' => $_POST['slogan_position'] ?? 'center',
                'hdr_slogan_color' => $_POST['slogan_color'] ?? '#00ffff',
                'hdr_slogan_opacity' => (int)($_POST['slogan_opacity'] ?? '100'),
                
                // Visual Effects Configuration - K::HeaderUI::Effects::*
                'hdr_shadow_enabled' => isset($_POST['shadow_enabled']),
                'hdr_shadow_color' => $_POST['shadow_color'] ?? '#000000',
                'hdr_shadow_blur' => (int)($_POST['shadow_blur'] ?? '4'),
                'hdr_shadow_x' => (int)($_POST['shadow_x'] ?? '2'),
                'hdr_shadow_y' => (int)($_POST['shadow_y'] ?? '2'),
                'hdr_shadow_spread' => (int)($_POST['shadow_spread'] ?? '0'),
                'hdr_border_enabled' => isset($_POST['border_enabled']),
                'hdr_border_color' => $_POST['border_color'] ?? '#00ffff',
                'hdr_border_width' => (int)($_POST['border_width'] ?? '0'),
                'hdr_border_style' => $_POST['border_style'] ?? 'solid',
                'hdr_border_radius' => (int)($_POST['border_radius'] ?? '0'),
                'hdr_glow_enabled' => isset($_POST['glow_enabled']),
                'hdr_glow_color' => $_POST['glow_color'] ?? '#00ffff',
                'hdr_glow_intensity' => (int)($_POST['glow_intensity'] ?? '10'),
                'hdr_glow_size' => (int)($_POST['glow_size'] ?? '5'),
                
                // Logo Configuration - K::HeaderUI::Logo::*
                'hdr_logo_enabled' => isset($_POST['logo_enabled']),
                'hdr_logo_image_path' => $_POST['logo'] ?? '',
                'hdr_logo_width' => (int)($_POST['logo_width'] ?? '80'),
                'hdr_logo_height' => (int)($_POST['logo_height'] ?? '80'),
                'hdr_logo_aspect_locked' => isset($_POST['logo_aspect_locked']),
                'hdr_logo_position' => $_POST['logo_position'] ?? 'left',
                'hdr_logo_margin_x' => (int)($_POST['logo_margin_x'] ?? '20'),
                'hdr_logo_margin_y' => (int)($_POST['logo_margin_y'] ?? '10'),
                'hdr_logo_animation_enabled' => isset($_POST['logo_animation_enabled']),
                'hdr_logo_animation_type' => $_POST['logo_animation_type'] ?? 'none',
                'hdr_logo_animation_duration' => (float)($_POST['logo_animation_duration'] ?? '1.0'),
                'hdr_logo_glow_enabled' => isset($_POST['logo_glow_enabled']),
                'hdr_logo_glow_color' => $_POST['logo_glow_color'] ?? '#00d4ff',
                'hdr_logo_glow_intensity' => (int)($_POST['logo_glow_intensity'] ?? '5'),
                
                // General Header Configuration - K::HeaderUI::General::*
                'hdr_position' => $_POST['position'] ?? 'fixed',
                
                // Background Configuration - K::HeaderUI::Background::*
                'hdr_background_type' => $_POST['background_type'] ?? 'solid',
                'hdr_background_color' => $_POST['background_color'] ?? '#1a1a2e',
                'hdr_background_opacity' => (int)($_POST['background_opacity'] ?? '100'),
                
                // Gradient Configuration
                'hdr_gradient_color1' => $_POST['gradient_color1'] ?? '#1a1a2e',
                'hdr_gradient_color2' => $_POST['gradient_color2'] ?? '#003344',
                'hdr_gradient_color3' => $_POST['gradient_color3'] ?? '#0066aa',
                'hdr_gradient_angle' => (int)($_POST['gradient_angle'] ?? 135),
                'hdr_gradient_multi_enabled' => isset($_POST['gradient_multi_enabled']),
                'hdr_gradient_opacity' => (int)($_POST['gradient_opacity'] ?? 100),
                
                // Animated Background Configuration
                'hdr_animation_type' => $_POST['animation_type'] ?? 'none',
                'hdr_animation_color' => $_POST['animation_color'] ?? '#0066aa',
                'hdr_animation_speed' => (float)($_POST['animation_speed'] ?? 1.0),
                'hdr_animation_scale' => (float)($_POST['animation_scale'] ?? 1.0),
                'hdr_animation_opacity' => (int)($_POST['animation_opacity'] ?? 100),
                'hdr_text_color' => $_POST['text_color'] ?? '#00ffff',
                'hdr_height' => (int)($_POST['height'] ?? '300'),
                'hdr_vertical_alignment' => $_POST['vertical_alignment'] ?? 'middle',
                'hdr_show_navigation' => isset($_POST['show_navigation']),
                'show_navigation' => isset($_POST['show_navigation']), // Simple compatibility key
                'hdr_sticky_enabled' => isset($_POST['sticky']),
                'hdr_border_bottom_enabled' => isset($_POST['border_bottom']),
                'hdr_glassmorphism_enabled' => isset($_POST['glassmorphism']),
                'hdr_last_updated' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    
    // Debug spacing values specifically
    error_log('Header Manager - Title-Slogan Spacing: ' . ($_POST['title_slogan_spacing'] ?? 'NOT SET'));
    error_log('Header Manager - Header-Content Spacing: ' . ($_POST['header_content_spacing'] ?? 'NOT SET'));
    error_log('Header Manager - Final config title_slogan_spacing: ' . $newConfig['K::HeaderUI::Configuration'][$configId]['hdr_title_slogan_spacing']);
    error_log('Header Manager - Final config content_spacing: ' . $newConfig['K::HeaderUI::Configuration'][$configId]['hdr_content_spacing']);
    
    // Ensure directory exists
    $dir = dirname($configPath);
    if(!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    

    
    // Add debug logging
    error_log('Header Manager - Saving config with ID: ' . $configId);
    error_log('Header Manager - Config data: ' . print_r($newConfig, true));
    
    // Status Bar Configuration saving removed - managed in widgets-manager.php


    $saveResult = file_put_contents($configPath, json_encode($newConfig, JSON_PRETTY_PRINT));
    error_log('Header Manager - Save result: ' . ($saveResult !== false ? 'SUCCESS' : 'FAILED'));
    
    if($saveResult !== false) {
        $config = $newConfig;
        
        // Handle manual save success (show message to user)
        $saveMessage = '✅ Header configuration saved successfully!';
    } else {
        error_log('Header Manager - Failed to write to config file: ' . $configPath);
        $errorMessage = '❌ Error saving header settings. Please check file permissions.';
    }
}

// Set defaults if config is empty or extract from existing structure
if(empty($config)) {
    // Only create defaults if no config file exists at all
    if (!file_exists($configPath)) {
        $defaultId = 'K::HeaderUI::Content::' . strtoupper(uniqid());
        $config = [
            'K::HeaderUI::Configuration' => [
                $defaultId => [
                    // Site Title Configuration - K::HeaderUI::Title::*
                    'hdr_site_name_text' => 'CUE Framework Site',
                    'hdr_site_name_enabled' => true,
                    'hdr_title_font' => 'Merriweather-Regular',
                    'hdr_title_position' => 'left',
                    'hdr_title_size' => 24,
                    'hdr_title_color' => '#00ffff',
                    'hdr_title_opacity' => 100,
                    'hdr_title_slogan_spacing' => 10,
                    
                    // Slogan/Subtitle Configuration - K::HeaderUI::Slogan::*
                    'hdr_slogan_enabled' => false,
                    'hdr_slogan_text' => '',
                    'hdr_slogan_font' => 'Merriweather-Regular',
                    'hdr_slogan_size' => 16,
                    'hdr_slogan_position' => 'center',
                    'hdr_slogan_color' => '#00ffff',
                    'hdr_slogan_opacity' => 100,
                    
                    // Visual Effects Configuration - K::HeaderUI::Effects::*
                    'hdr_shadow_enabled' => false,
                    'hdr_shadow_color' => '#000000',
                    'hdr_shadow_blur' => 4,
                    'hdr_shadow_x' => 2,
                    'hdr_shadow_y' => 2,
                    'hdr_shadow_spread' => 0,
                    'hdr_border_enabled' => false,
                    'hdr_border_color' => '#00ffff',
                    'hdr_border_width' => 0,
                    'hdr_border_style' => 'solid',
                    'hdr_border_radius' => 0,
                    'hdr_glow_enabled' => false,
                    'hdr_glow_color' => '#00ffff',
                    'hdr_glow_intensity' => 10,
                    'hdr_glow_size' => 5,
                    
                    // Logo Configuration - K::HeaderUI::Logo::*
                    'hdr_logo_image_path' => '',
                    'hdr_logo_enabled' => false,
                    'hdr_logo_width' => 80,
                    'hdr_logo_height' => 80,
                    'hdr_logo_aspect_locked' => true,
                    'hdr_logo_position' => 'left',
                    'hdr_logo_margin_x' => 20,
                    'hdr_logo_margin_y' => 10,
                    'hdr_logo_animation_enabled' => false,
                    'hdr_logo_animation_type' => 'none',
                    'hdr_logo_animation_duration' => 1.0,
                    'hdr_logo_glow_enabled' => false,
                    'hdr_logo_glow_color' => '#00d4ff',
                    'hdr_logo_glow_intensity' => 5,
                    
                    // General Header Configuration - K::HeaderUI::General::*
                    'hdr_position' => 'fixed',
                    
                    // Background Configuration - K::HeaderUI::Background::*
                    'hdr_background_type' => 'solid',
                    'hdr_background_color' => '#1a1a2e',
                    'hdr_background_opacity' => 100,
                    
                    // Gradient Configuration
                    'hdr_gradient_color1' => '#1a1a2e',
                    'hdr_gradient_color2' => '#003344',
                    'hdr_gradient_color3' => '#0066aa',
                    'hdr_gradient_angle' => 135,
                    'hdr_gradient_multi_enabled' => false,
                    'hdr_gradient_opacity' => 100,
                    
                    // Animated Background Configuration
                    'hdr_animation_type' => 'none',
                    'hdr_animation_color' => '#0066aa',
                    'hdr_animation_speed' => 1.0,
                    'hdr_animation_scale' => 1.0,
                    'hdr_animation_opacity' => 100,
                    'hdr_text_color' => '#00ffff',
                    'hdr_height' => 300,
                    'hdr_vertical_alignment' => 'middle',
                    'hdr_show_navigation' => true,
                    'hdr_sticky_enabled' => false,
                    'hdr_border_bottom_enabled' => true,
                    'hdr_glassmorphism_enabled' => true,
                    'hdr_last_updated' => date('Y-m-d H:i:s')
                ]
            ]
        ];
        
        // Save default configuration to file
        $dir = dirname($configPath);
        if(!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
        error_log('Created new default header configuration');
    } else {
        // Config file exists but returned empty - this shouldn't happen
        error_log('Warning: Config file exists but is empty or invalid: ' . $configPath);
    }
}

// Extract current config values for form display
// Ensure we have the latest config data by clearing cache first
clearstatcache(true, $configPath);
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true) ?: [];
}

$currentConfig = [];
if (isset($config['K::HeaderUI::Configuration'])) {
    $configKeys = array_keys($config['K::HeaderUI::Configuration']);
    if (!empty($configKeys)) {
        $currentConfig = $config['K::HeaderUI::Configuration'][$configKeys[0]];
    }
} else {
    // Legacy format support
    $currentConfig = $config;
}

// Status Bar Config loading removed - managed in widgets-manager.php

// Check if this file is being accessed directly (not included)
$isStandalone = !isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED']);

// Essential JavaScript functions that need to be available in both standalone and embedded contexts
echo '<script>';
echo 'if (typeof openFileExplorer === "undefined") {';
echo '  function openFileExplorer() {';
echo '    const modal = document.getElementById("fileExplorerModal");';
echo '    if (modal) {';
echo '      modal.style.display = "block";';
echo '      loadDirectory("/");';
echo '    } else {';
echo '      console.error("File explorer modal not found");';
echo '      alert("File explorer is not available in this context");';
echo '    }';
echo '  }';
echo '}';
echo '';
echo 'if (typeof closeFileExplorer === "undefined") {';
echo '  function closeFileExplorer() {';
echo '    const modal = document.getElementById("fileExplorerModal");';
echo '    if (modal) {';
echo '      modal.style.display = "none";';
echo '    }';
echo '    selectedFile = null;';
echo '    document.getElementById("selectBtn").disabled = true;';
echo '  }';
echo '}';
echo '</script>';

if ($isStandalone) {
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Header Manager - CUE Framework</title>';
    include_once __DIR__ . '/includes/complete-head.php';
    includeNoticesWidget();
    echo '<style>';
    echo 'body { background: var(--theme-background, #1a1a1a); color: var(--theme-text, #00ffff); font-family: Arial, sans-serif; margin: 0; padding: 0; min-height: 100vh; }';
    echo '.form-container { max-width: 1200px; margin: -20px auto 0 auto; background: rgba(0, 0, 0, 0.3); padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0, 255, 255, 0.2); display: block; width: 100%; box-sizing: border-box; }; }';
    echo "/* Enhanced form block stacking */";

    echo '.form-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }';
    echo '.form-group { flex: 1; min-width: 250px; }';
    echo '.form-group.full-width { flex: 100%; }';
    echo '.form-label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff; }';
    echo '.form-input, .form-select { width: 100%; padding: 12px; background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 8px; color: #ffffff; box-sizing: border-box; }';
    echo '.form-input:focus, .form-select:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    echo '.form-checkbox { margin-right: 8px; }';
    echo '.checkbox-label { cursor: pointer; }';
    echo '.form-checkbox-group { display: flex; align-items: center; }';
    echo '.submit-button { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; padding: 15px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; transition: all 0.3s ease; }';
    echo '.submit-button:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 255, 255, 0.4); }';
    echo '.logo-controls { display: none; margin-top: 15px; padding: 20px; background: rgba(0, 255, 255, 0.05); border-radius: 10px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.aspect-lock-btn { background: rgba(0, 255, 255, 0.1); border: 2px solid rgba(0, 255, 255, 0.3); color: #00ffff; padding: 8px 12px; border-radius: 6px; cursor: pointer; margin-left: 10px; }';
    echo '.file-upload-container { margin-top: 10px; }';
    echo '.modern-file-input { display: flex; gap: 10px; align-items: center; }';
    echo '.modern-file-button { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }';
    echo '.browse-server-btn { background: linear-gradient(135deg, #ffa500, #ff8c00); color: #000; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }';    
    echo '.browse-server-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(255, 165, 0, 0.4); }';    
    echo '/* Modern Checkbox Styling */';    
    echo '.form-checkbox { appearance: none; -webkit-appearance: none; width: 20px; height: 20px; border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 6px; background: rgba(0, 0, 0, 0.4); position: relative; cursor: pointer; transition: all 0.3s ease; margin-right: 10px; }';    
    echo '.form-checkbox:checked { background: linear-gradient(135deg, #00ffff, #0080ff); border-color: #00ffff; }';    
    echo '.form-checkbox:checked::after { content: "✓"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #000; font-weight: bold; font-size: 14px; }';    
    echo '.form-checkbox:hover { border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';    
    echo '.checkbox-label { cursor: pointer; user-select: none; display: flex; align-items: center; font-weight: 500; }';    
    echo '.form-checkbox-group { display: flex; align-items: center; }';
    echo '/* File Explorer Modal Styling */';
    echo '.file-explorer-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 1000; }';
    echo '.file-explorer-dialog { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #001122, #003344); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; width: 80%; max-width: 800px; max-height: 80%; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 255, 255, 0.2); }';
    echo '.file-explorer-header { background: rgba(0, 255, 255, 0.1); padding: 20px; border-bottom: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.file-explorer-title { color: #00ffff; font-size: 1.2em; font-weight: bold; margin: 0; }';
    echo '.file-explorer-body { padding: 20px; max-height: 500px; overflow-y: auto; }';
    echo '.file-explorer-path { background: rgba(0, 255, 255, 0.1); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-family: monospace; color: #00ffff; }';
    echo '.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; }';
    echo '.file-item { background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.3s ease; }';
    echo '.file-item:hover { background: rgba(0, 255, 255, 0.15); border-color: #00ffff; transform: translateY(-2px); }';
    echo '.file-item.selected { background: rgba(0, 255, 255, 0.3); border-color: #00ffff; }';
    echo '.file-icon { font-size: 2em; margin-bottom: 5px; }';
    echo '.file-name { font-size: 0.8em; word-break: break-all; color: #00ffff; }';
    echo '.folder-item { background: rgba(255, 165, 0, 0.1); border-color: rgba(255, 165, 0, 0.3); }';
    echo '.folder-item:hover { background: rgba(255, 165, 0, 0.2); border-color: #ffa500; }';
    echo '.explorer-buttons { display: flex; gap: 10px; margin-top: 15px; justify-content: flex-end; }';
    echo '.explorer-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.3s ease; }';
    echo '.explorer-btn-primary { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; }';
    echo '.explorer-btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); }';
    
    // Enhanced Background Options CSS
    echo '/* Header Background Section Styling */';
    echo '.header-background-section { background: rgba(0, 255, 255, 0.05); padding: 25px; margin: 20px 0; border-radius: 15px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.section-title { color: #00ffff; font-size: 1.3em; margin-bottom: 20px; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3); border-bottom: 2px solid rgba(0, 255, 255, 0.3); padding-bottom: 10px; }';
    echo '.background-type-selector { display: flex; gap: 15px; margin-bottom: 20px; }';
    echo '.bg-type-option { flex: 1; }';
    echo '.bg-type-option input[type="radio"] { display: none; }';
    echo '.bg-type-option label { display: block; padding: 15px 20px; background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(0, 255, 255, 0.2); border-radius: 10px; text-align: center; cursor: pointer; transition: all 0.3s ease; font-weight: 600; }';
    echo '.bg-type-option input[type="radio"]:checked + label { background: rgba(0, 255, 255, 0.2); border-color: #00ffff; color: #00ffff; box-shadow: 0 0 15px rgba(0, 255, 255, 0.3); }';
    echo '.bg-type-option label:hover { background: rgba(0, 255, 255, 0.1); border-color: rgba(0, 255, 255, 0.5); }';
    echo '.bg-type-content { margin-top: 20px; }';
    echo '.modern-color-picker { width: 60px !important; height: 60px !important; border-radius: 10px !important; cursor: pointer; }';
    echo '.modern-color-picker::-webkit-color-swatch-wrapper { padding: 0; border-radius: 8px; }';
    echo '.modern-color-picker::-webkit-color-swatch { border: none; border-radius: 8px; }';
    echo '.gradient-multi-controls { margin-top: 10px; }';
    echo '.multi-color-section { margin-top: 15px; padding: 15px; background: rgba(0, 255, 255, 0.05); border-radius: 8px; }';
    echo '.third-width { flex: 0 0 32%; }';
    echo '.input-group { display: flex; align-items: center; gap: 8px; }';
    echo '.number-input { flex: 1; padding: 8px 12px; background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 6px; color: #ffffff; font-size: 14px; }';
    echo '.number-input:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    echo '.unit-label { color: rgba(0, 255, 255, 0.8); font-size: 14px; font-weight: 500; min-width: 25px; }';

    // Use dynamic header-content spacing from configuration
    $headerContentSpacing = $currentConfig['hdr_content_spacing'] ?? 15;
    echo 'body { padding-top: ' . (int)$headerContentSpacing . 'px; }';
    echo '.cue-hamburger-menu { z-index: 10500 !important; } .hamburger-trigger { z-index: 10500 !important; } .hamburger-panel { z-index: 10500 !important; } .hamburger-backdrop { z-index: 10499 !important; }';
    echo '</style>';
    echo '</head><body>';
    
    // Include the global header
    $headerInclude = __DIR__ . '/includes/header.php';
    if (file_exists($headerInclude)) {
        include_once $headerInclude;
    }
    $hamburgerInclude = __DIR__ . '/includes/hamburger.php';
    if (file_exists($hamburgerInclude) && empty($GLOBALS['_GLOBAL_HAMBURGER_INCLUDED'])) {
        include_once $hamburgerInclude;
    }
    
    echo '<h1 style="color: #00ffff; text-align: center; margin-bottom: 30px; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);">🏠 Header Settings Manager</h1>';
    
    // Display save message if present
    if (isset($saveMessage)) {
        echo '<div style="background: rgba(0, 255, 0, 0.1); border: 1px solid #00ff00; color: #00ff00; padding: 15px; margin-bottom: 20px; border-radius: 8px; text-align: center;">' . htmlspecialchars($saveMessage) . '</div>';
    }
    

    // File Explorer functions are now defined outside conditional blocks for universal availability
    
    
    
    

}

// File Explorer Modal HTML - needed in both standalone and embedded contexts
echo '<!-- File Explorer Modal -->';
echo '<div id="fileExplorerModal" class="file-explorer-modal">';
echo '  <div class="file-explorer-dialog">';
echo '    <div class="file-explorer-header">';
echo '      <h3 class="file-explorer-title">📁 Server File Browser</h3>';
echo '      <button onclick="closeFileExplorer()" style="position: absolute; top: 15px; right: 20px; background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.3); color: #fff; border-radius: 4px; width: 30px; height: 30px; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center;">&times;</button>';
echo '    </div>';
echo '    <div class="file-explorer-body">';
echo '      <div class="file-explorer-path" id="currentPath">📍 /public_html/</div>';
echo '      <div class="file-grid" id="fileGrid">';
echo '        <!-- Files will be loaded here -->';
echo '      </div>';
echo '      <div class="explorer-buttons">';
echo '        <button class="explorer-btn explorer-btn-secondary" onclick="closeFileExplorer()">Cancel</button>';
echo '        <button class="explorer-btn explorer-btn-primary" onclick="selectFile()" id="selectBtn" disabled>Select File</button>';
echo '      </div>';
echo '    </div>';
echo '  </div>';
echo '</div>';

// Add CSS overrides for when loaded within Global UI Manager
if (isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED'])) {
    echo '<style>';
    // Override global-ui-manager form container styles with higher specificity
    echo '.content .form-container { max-width: 1200px !important; background: rgba(0, 0, 0, 0.3) !important; border: none !important; box-shadow: 0 10px 30px rgba(0, 255, 255, 0.2) !important; }';
    echo "/* Enhanced form block stacking */";

    // Form layout overrides
    echo '.content .form-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }';
    echo '.content .form-group { flex: 1; min-width: 250px; }';
    echo '.content .form-group.full-width { flex: 100%; }';
    echo '.content .form-group.half-width { flex: 50%; min-width: 300px; }';
    echo '.content .form-label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff; }';
    echo '.content .form-input, .content .form-select { width: 100%; padding: 12px; background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 8px; color: #ffffff; box-sizing: border-box; }';
    echo '.content .form-input:focus, .content .form-select:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    // Ensure our modern file input styles take precedence
    echo '.content .modern-file-input { display: flex; gap: 10px; align-items: center; }';
    echo '.content .modern-file-button { background: linear-gradient(135deg, #00ffff, #0080ff) !important; color: #000 !important; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }';
    echo '.content .browse-server-btn { background: linear-gradient(135deg, #ffa500, #ff8c00) !important; color: #000 !important; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }';
    echo '.content .browse-server-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(255, 165, 0, 0.4); }';
    // Override checkbox styles
    echo '.content .form-checkbox { appearance: none !important; -webkit-appearance: none !important; width: 20px !important; height: 20px !important; border: 2px solid rgba(0, 255, 255, 0.3) !important; border-radius: 6px !important; background: rgba(0, 0, 0, 0.4) !important; position: relative; cursor: pointer; transition: all 0.3s ease; margin-right: 10px; }';
    echo '.content .form-checkbox:checked { background: linear-gradient(135deg, #00ffff, #0080ff) !important; border-color: #00ffff !important; }';
    echo '.content .form-checkbox:checked::after { content: "✓"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #000; font-weight: bold; font-size: 14px; }';
    echo '.content .form-checkbox:hover { border-color: #00ffff !important; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3) !important; }';
    // File upload container styling
    echo '.content .file-upload-container { margin-top: 10px; }';
    echo '.content .file-upload-section { background: rgba(0, 255, 255, 0.05); padding: 15px; border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.content .file-info { margin-top: 8px; color: rgba(0, 255, 255, 0.7); font-size: 0.9em; }';
    // Input group and number input styling
    echo '.content .input-group { display: flex; align-items: center; gap: 8px; }';
    echo '.content .number-input { flex: 1; padding: 8px 12px; background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 6px; color: #ffffff; font-size: 14px; }';
    echo '.content .number-input:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    echo '.content .unit-label { color: rgba(0, 255, 255, 0.7); font-size: 14px; font-weight: 500; min-width: 25px; }';
    echo '.content .aspect-lock-btn { background: rgba(0, 255, 255, 0.1); border: 2px solid rgba(0, 255, 255, 0.3); color: #00ffff; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 16px; transition: all 0.3s ease; }';
    echo '.content .aspect-lock-btn:hover { background: rgba(0, 255, 255, 0.2); border-color: #00ffff; }';
    echo '.content .color-input-group { display: flex; flex-direction: column; gap: 10px; }';
    echo '.content .color-input-group label { color: #00ffff; font-weight: 600; }';
    echo '.content .color-input-group input[type="color"] { width: 50px; height: 35px; border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 8px; background: rgba(0, 0, 0, 0.4); cursor: pointer; -webkit-appearance: none; -moz-appearance: none; appearance: none; padding: 2px; }';  
    echo '.content .color-input-group input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; border-radius: 6px; }';  
    echo '.content .color-input-group input[type="color"]::-webkit-color-swatch { border: none; border-radius: 6px; width: 100%; height: 100%; }';  
    echo '.content .color-input-group input[type="color"]::-moz-color-swatch { border: none; border-radius: 6px; width: 100%; height: 100%; }';  
    echo '.content input[type="color"] { width: 50px; height: 35px; border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 8px; cursor: pointer; -webkit-appearance: none; -moz-appearance: none; appearance: none; padding: 2px; }';  
    echo '.content input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; border-radius: 6px; }';  
    echo '.content input[type="color"]::-webkit-color-swatch { border: none; border-radius: 6px; width: 100%; height: 100%; }';  
    echo '.content input[type="color"]::-moz-color-swatch { border: none; border-radius: 6px; width: 100%; height: 100%; }';  
    echo '.content input[type="color"]:focus { outline: 2px solid #00ffff; outline-offset: 2px; }';  
    echo '.content input[type="color"]:hover { border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';  
    echo '.content .color-block-label { font-size: 13px; font-weight: 600; letter-spacing: 0.5px; }';  
    echo '.content .color-block-selector { display: flex; align-items: center; gap: 12px; padding: 10px; background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 10px; transition: all 0.3s ease; }';  
    echo '.content .color-block-selector:hover { border-color: rgba(0, 255, 255, 0.6); background: rgba(0, 0, 0, 0.6); box-shadow: 0 0 15px rgba(0, 255, 255, 0.2); }';  
    echo '.content .color-block-info { display: flex; flex-direction: column; }';  
    echo '.content .color-block-label { color: #00ffff; font-size: 14px; font-weight: 600; margin-bottom: 2px; }';  
    echo '.content .color-block-value { color: rgba(255, 255, 255, 0.7); font-size: 12px; font-family: monospace; }';
    // Submit button styling
    echo '.content .submit-button { background: linear-gradient(135deg, #00ffff, #0080ff) !important; color: #000 !important; padding: 15px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; transition: all 0.3s ease; }';
    echo '.content .submit-button:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 255, 255, 0.4); }';
    // Enhanced section styling
    echo '.content .site-title-section, .content .slogan-section, .content .logo-section, .content .visual-effects-section, .content .header-background-section, .content .status-bar-section { background: rgba(0, 255, 255, 0.05); padding: 20px; margin: 20px 0; border-radius: 12px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.content .site-title-section h3, .content .slogan-section h3, .content .visual-effects-section h3 { color: #00ffff; margin-bottom: 20px; font-size: 1.2em; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    echo '.content .effect-subsection { background: rgba(0, 0, 0, 0.2); padding: 15px; margin: 15px 0; border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.1); }';
    echo '.content .effect-subsection h4 { color: #ccffff; margin-bottom: 15px; font-size: 1em; }';
    // Color opacity group styling
    echo '.content .color-opacity-group { display: flex; flex-direction: column; gap: 10px; }';
    echo '.content .opacity-control { display: flex; align-items: center; gap: 10px; }';
    echo '.content .opacity-control label { color: rgba(0, 255, 255, 0.8); font-size: 12px; min-width: 60px; }';
    echo '.content .opacity-slider, .content .range-slider { flex: 1; height: 6px; background: rgba(0, 255, 255, 0.2); border-radius: 3px; outline: none; }';
    echo '.content .opacity-slider::-webkit-slider-thumb, .content .range-slider::-webkit-slider-thumb { appearance: none; width: 16px; height: 16px; background: linear-gradient(135deg, #00ffff, #0080ff); border-radius: 50%; cursor: pointer; }';
    echo '.content .opacity-value, .content .range-value { color: #00ffff; font-size: 12px; font-weight: bold; min-width: 40px; text-align: right; }';
    // Enhanced preview styling
    echo '.content .image-preview { position: relative; display: inline-block; margin-top: 10px; }';
    echo '.content .clear-preview-btn { background: rgba(255, 0, 0, 0.7) !important; color: white !important; border: none; border-radius: 50%; width: 24px; height: 24px; position: absolute; top: 5px; right: 5px; cursor: pointer; font-size: 14px; }';
    echo '.content .clear-preview-btn:hover { background: rgba(255, 0, 0, 0.9) !important; }';
    // File browser enhancements
    echo '.content .file-preview img { border: 1px solid rgba(0, 255, 255, 0.3); transition: all 0.3s ease; }';
    echo '.content .file-item:hover .file-preview img { border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.4); }';
    // Local font face declarations
    echo '@font-face { font-family: "Merriweather-Regular"; src: url("/templates/assets/fonts/Merriweather-Regular.ttf") format("truetype"); font-display: swap; }';
    echo '@font-face { font-family: "Merriweather-Bold"; src: url("/templates/assets/fonts/Merriweather-Bold.ttf") format("truetype"); font-display: swap; }';
    echo '@font-face { font-family: "Orbitron-Regular"; src: url("/templates/assets/fonts/Orbitron-Medium.woff2") format("woff2"); font-display: swap; }';
    echo '@font-face { font-family: "Orbitron-Bold"; src: url("/templates/assets/fonts/Orbitron-Medium.woff2") format("woff2"); font-display: swap; }';
    echo '@font-face { font-family: "Rajdhani-Regular"; src: url("/templates/assets/fonts/rajdhani/LDIxapCSOBg7S-QT7p4JM-aUWA.woff2") format("woff2"), url("/templates/assets/fonts/Rajdhani-Regular-proper.ttf") format("truetype"); font-display: swap; }';
    echo '@font-face { font-family: "Rajdhani-Bold"; src: url("/templates/assets/fonts/rajdhani/LDI2apCSOBg7S-QT7pb0EPOqeef2kg.woff2") format("woff2"), url("/templates/assets/fonts/Rajdhani-Regular-proper.ttf") format("truetype"); font-display: swap; }';
    // Logo section styling
    echo '.content .logo-section { background: rgba(0, 255, 255, 0.05); padding: 20px; margin: 20px 0; border-radius: 12px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.content .logo-section h3 { color: #00ffff; margin-bottom: 20px; font-size: 1.2em; }';
    // File explorer modal overrides to ensure they work in global context
    echo '.file-explorer-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 999999 !important; }';
    echo '.file-explorer-dialog { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #001122, #003344); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; width: 80%; max-width: 800px; max-height: 80%; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 255, 255, 0.2); z-index: 1000000 !important; }';
    echo '.file-explorer-header { background: rgba(0, 255, 255, 0.1); padding: 20px; border-bottom: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.file-explorer-title { color: #00ffff; font-size: 1.2em; font-weight: bold; margin: 0; }';
    echo '.file-explorer-body { padding: 20px; max-height: 500px; overflow-y: auto; }';
    echo '.file-explorer-path { background: rgba(0, 255, 255, 0.1); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-family: monospace; color: #00ffff; }';
    echo '.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; }';
    echo '.file-item { background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; padding: 10px; text-align: center; cursor: pointer; transition: all 0.3s ease; }';
    echo '.file-item:hover { background: rgba(0, 255, 255, 0.15); border-color: #00ffff; transform: translateY(-2px); }';
    echo '.file-item.selected { background: rgba(0, 255, 255, 0.3); border-color: #00ffff; }';
    echo '.file-icon { font-size: 2em; margin-bottom: 5px; }';
    echo '.file-name { font-size: 0.8em; word-break: break-all; color: #00ffff; }';
    echo '.folder-item { background: rgba(255, 165, 0, 0.1); border-color: rgba(255, 165, 0, 0.3); }';
    echo '.folder-item:hover { background: rgba(255, 165, 0, 0.2); border-color: #ffa500; }';
    echo '.explorer-buttons { display: flex; gap: 10px; margin-top: 15px; justify-content: flex-end; }';
    echo '.explorer-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.3s ease; }';
    echo '.explorer-btn-primary { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; }';
    echo '.explorer-btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); }';
    
    // Enhanced Header Background Section Styling for Global UI Manager
    echo '.content .header-background-section { background: rgba(0, 255, 255, 0.05) !important; padding: 25px !important; margin: 20px 0 !important; border-radius: 15px !important; border: 1px solid rgba(0, 255, 255, 0.2) !important; }';
    echo '.content .section-title { color: #00ffff !important; font-size: 1.3em !important; margin-bottom: 20px !important; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3) !important; border-bottom: 2px solid rgba(0, 255, 255, 0.3) !important; padding-bottom: 10px !important; }';
    echo '.content .background-type-selector { display: flex !important; gap: 15px !important; margin-bottom: 20px !important; }';
    echo '.content .bg-type-option { flex: 1 !important; }';
    echo '.content .bg-type-option input[type="radio"] { display: none !important; }';
    echo '.content .bg-type-option label { display: block !important; padding: 15px 20px !important; background: rgba(0, 0, 0, 0.3) !important; border: 2px solid rgba(0, 255, 255, 0.2) !important; border-radius: 10px !important; text-align: center !important; cursor: pointer !important; transition: all 0.3s ease !important; font-weight: 600 !important; }';
    echo '.content .bg-type-option input[type="radio"]:checked + label { background: rgba(0, 255, 255, 0.2) !important; border-color: #00ffff !important; color: #00ffff !important; box-shadow: 0 0 15px rgba(0, 255, 255, 0.3) !important; }';
    echo '.content .bg-type-option label:hover { background: rgba(0, 255, 255, 0.1) !important; border-color: rgba(0, 255, 255, 0.5) !important; }';
    echo '.content .bg-type-content { margin-top: 20px !important; }';
    echo '.content .modern-color-picker { width: 60px !important; height: 60px !important; border-radius: 10px !important; cursor: pointer !important; }';
    echo '.content .modern-color-picker::-webkit-color-swatch-wrapper { padding: 0 !important; border-radius: 8px !important; }';
    echo '.content .modern-color-picker::-webkit-color-swatch { border: none !important; border-radius: 8px !important; }';
    echo '.content .gradient-multi-controls { margin-top: 10px !important; }';
    echo '.content .multi-color-section { margin-top: 15px !important; padding: 15px !important; background: rgba(0, 255, 255, 0.05) !important; border-radius: 8px !important; }';
    echo '.content .third-width { flex: 0 0 32% !important; }';
    
    echo '</style>';
}
?>

<script>
// JavaScript functions that need to be available regardless of standalone mode
let aspectRatio = null;
let isAspectLocked = false;
let currentPath = "/";
let selectedFile = null;

document.addEventListener("DOMContentLoaded", function() {
  // Load available fonts from assets directory
  loadAvailableFonts();
  
  const logoEnabled = document.getElementById("logo_enabled");
  const logoControls = document.getElementById("logo-controls");
  const aspectLockBtn = document.getElementById("aspectLockBtn");
  const widthSlider = document.getElementById("logoWidth");
  const heightSlider = document.getElementById("logoHeight");
  const logoUrl = document.getElementById("logoUrl");
  const logoFileInput = document.getElementById("logoFile");

  if (logoEnabled && logoControls) {
    logoEnabled.addEventListener("change", function() {
      if (this.checked) {
        logoControls.style.display = "block";
        logoControls.classList.add("active");
      } else {
        logoControls.style.display = "none";
        logoControls.classList.remove("active");
      }
    });
  }
  
  // Slogan checkbox functionality
  const sloganEnabled = document.getElementById("slogan_enabled");
  const sloganControls = document.getElementById("slogan-controls");
  
  if (sloganEnabled && sloganControls) {
    sloganEnabled.addEventListener("change", function() {
      if (this.checked) {
        sloganControls.style.display = "block";
        sloganControls.classList.add("active");
      } else {
        sloganControls.style.display = "none";
        sloganControls.classList.remove("active");
      }
    });
  }
  
  // Visual Effects checkbox functionality
  const shadowEnabled = document.getElementById("shadow_enabled");
  const shadowControls = document.getElementById("shadow-controls");
  
  if (shadowEnabled && shadowControls) {
    shadowEnabled.addEventListener("change", function() {
      if (this.checked) {
        shadowControls.style.display = "block";
        shadowControls.classList.add("active");
      } else {
        shadowControls.style.display = "none";
        shadowControls.classList.remove("active");
      }
    });
  }
  
  const borderEnabled = document.getElementById("border_enabled");
  const borderControls = document.getElementById("border-controls");
  
  if (borderEnabled && borderControls) {
    borderEnabled.addEventListener("change", function() {
      if (this.checked) {
        borderControls.style.display = "block";
        borderControls.classList.add("active");
      } else {
        borderControls.style.display = "none";
        borderControls.classList.remove("active");
      }
    });
  }
  
  const glowEnabled = document.getElementById("glow_enabled");
  const glowControls = document.getElementById("glow-controls");
  
  if (glowEnabled && glowControls) {
    glowEnabled.addEventListener("change", function() {
      if (this.checked) {
        glowControls.style.display = "block";
        glowControls.classList.add("active");
      } else {
        glowControls.style.display = "none";
        glowControls.classList.remove("active");
      }
    });
  }
  
  // Enhanced slider functionality with preview updates
  document.querySelectorAll('.opacity-slider, .range-slider').forEach(slider => {
    const valueSpan = slider.parentElement.querySelector('.opacity-value, .range-value');
    if (valueSpan) {
      slider.addEventListener('input', function() {
        const suffix = this.classList.contains('opacity-slider') || this.name.includes('visibility') ? '%' : '';
        valueSpan.textContent = this.value + suffix;
        
        // DISABLED: Auto-refresh header preview - preventing automatic overrides
        // clearTimeout(window.previewRefreshTimeout);
        // window.previewRefreshTimeout = setTimeout(() => {
        //   updateHeaderPreview();
        // }, 500);
      });
    }
  });
  
  // Manual save button is the only way to save changes
  
  // const formInputs = document.querySelectorAll('input, select, textarea');
  // formInputs.forEach(input => {
  //   if (!input.name || input.type === 'file' || input.type === 'submit') return;
  //   
  //   input.addEventListener('input', function() {
  //     clearTimeout(window.previewRefreshTimeout);
  //     window.previewRefreshTimeout = setTimeout(() => {
  //       updateHeaderPreview();
  //     }, 300);
  //   });
  //   
  //   input.addEventListener('change', function() {
  //     clearTimeout(window.previewRefreshTimeout);
  //     window.previewRefreshTimeout = setTimeout(() => {
  //       updateHeaderPreview();
  //     }, 200);
  //   });
  // });
  
  // File input enhancement
  const logoFileEnhanced = document.getElementById("logoFile");
  if (logoFileEnhanced) {
    logoFileEnhanced.addEventListener("change", function(e) {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          showImagePreview(e.target.result, 'uploadPreview');
        };
        reader.readAsDataURL(file);
      }
    });
  }

  if (aspectLockBtn) {
    aspectLockBtn.addEventListener("click", function() {
      isAspectLocked = !isAspectLocked;
      this.textContent = isAspectLocked ? "🔒" : "🔓";
      this.title = isAspectLocked ? "Aspect ratio locked" : "Aspect ratio unlocked";
    });
  }

  if (widthSlider) {
    widthSlider.addEventListener("input", function() {
      if (isAspectLocked && aspectRatio && heightSlider) {
        const newHeight = Math.round(this.value / aspectRatio);
        heightSlider.value = Math.min(Math.max(newHeight, 10), 500);
      }
    });
  }

  if (heightSlider) {
    heightSlider.addEventListener("input", function() {
      if (isAspectLocked && aspectRatio && widthSlider) {
        const newWidth = Math.round(this.value * aspectRatio);
        widthSlider.value = Math.min(Math.max(newWidth, 10), 500);
      }
    });
  }

  const marginXInput = document.getElementById("logoMarginX");
  const marginYInput = document.getElementById("logoMarginY");
  const animationDurationInput = document.getElementById("logoAnimationDuration");
  const glowIntensityInput = document.getElementById("logoGlowIntensity");
  const animationTypeSelect = document.getElementById("logoAnimationType");

  // DISABLED: Auto-update triggers - preventing automatic overrides
  // if (marginXInput) marginXInput.addEventListener("input", updatePreview);
  // if (marginYInput) marginYInput.addEventListener("input", updatePreview);
  // if (animationDurationInput) animationDurationInput.addEventListener("input", updatePreview);
  // if (glowIntensityInput) glowIntensityInput.addEventListener("input", updatePreview);

  if (animationTypeSelect) {
    animationTypeSelect.addEventListener("change", function() {
      const durationContainer = document.getElementById("animationDurationContainer");
      const glowContainer = document.getElementById("glowContainer");
      if (durationContainer) {
        durationContainer.style.display = this.value !== "none" ? "block" : "none";
      }
      if (glowContainer) {
        glowContainer.style.display = this.value !== "none" ? "block" : "none";
      }
      updatePreview();
    });
  }

  if (logoUrl) {
    logoUrl.addEventListener("input", function() {
      const value = this.value.trim();
      if (value) {
        // Validate URL format (allow various formats)
        if (isValidLogoUrl(value)) {
          showUrlPreview(value);
        } else {
          showUrlError('Please enter a valid URL or path (e.g., example.com/logo.png, /path/logo.png)');
        }
      } else {
        hidePreview();
      }
    });
  }

  if (logoFileInput) {
    logoFileInput.addEventListener("change", handleFileSelect);
  }
  
  // Initialize other form controls
  updatePreview();
});

function handleFileSelect(event) {
  const file = event.target.files[0];
  const fileLabel = document.getElementById("fileLabelText");
  
  if (file) {
    if (fileLabel) {
      fileLabel.textContent = file.name.length > 20 ? file.name.substring(0, 20) + "..." : file.name;
    }
    validateAndPreviewFile(file);
  } else {
    if (fileLabel) {
      fileLabel.textContent = "Choose File";
    }
  }
}

function handleDragOver(event) {
  event.preventDefault();
  event.currentTarget.classList.add("drag-over");
}

function handleFileDrop(event) {
  event.preventDefault();
  event.currentTarget.classList.remove("drag-over");
  const file = event.dataTransfer.files[0];
  if (file) validateAndPreviewFile(file);
}

function validateAndPreviewFile(file) {
  const allowedTypes = ["image/png", "image/jpeg", "image/jpg", "image/svg+xml"];
  const maxSize = 2 * 1024 * 1024; // 2MB
  
  if (!allowedTypes.includes(file.type)) {
    alert("Please select a valid image file (PNG, JPG, or SVG)");
    return;
  }
  
  if (file.size > maxSize) {
    alert("File size must be less than 2MB");
    return;
  }
  
  const reader = new FileReader();
  reader.onload = function(e) {
    const img = new Image();
    img.onload = function() {
      if (this.width < 50 || this.height < 50) {
        alert("Image dimensions must be at least 50×50 pixels");
        return;
      }
      aspectRatio = this.width / this.height;
      showFilePreview(e.target.result, file.name, this.width, this.height, file.size);
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

function showFilePreview(src, name, width, height, size) {
  const preview = document.getElementById("logoPreview");
  if (preview) {
    const sizeKB = (size / 1024).toFixed(1);
    preview.innerHTML = `
      <div class="preview-container">
        <img src="${src}" alt="Logo Preview" style="max-width: 200px; max-height: 100px; object-fit: contain;">
        <div class="preview-info">
          <strong>${name}</strong><br>
          Dimensions: ${width}×${height}<br>
          Size: ${sizeKB} KB
        </div>
      </div>
    `;
    preview.style.display = "block";
  }
}

function isValidLogoUrl(url) {
  if (!url || url.trim() === '') return false;
  
  // Allow data URLs for base64 images
  if (url.startsWith('data:image/')) return true;
  
  // Allow relative paths
  if (url.startsWith('/') || url.startsWith('./') || url.startsWith('../')) return true;
  
  // Allow URLs with or without protocol
  const urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?([\w\.-]*\.(jpg|jpeg|png|gif|webp|svg))?$/i;
  return urlPattern.test(url);
}

function showUrlError(message) {
  const errorDiv = document.getElementById('logoUrlError');
  if (errorDiv) {
    errorDiv.textContent = message;
    errorDiv.style.display = message ? 'block' : 'none';
  }
}

function showUrlPreview(url) {
  const preview = document.getElementById("logoPreview");
  if (preview) {
    preview.innerHTML = `
      <div class="preview-container">
        <img src="${url}" alt="Logo Preview" style="max-width: 200px; max-height: 100px; object-fit: contain;"
             onerror="this.parentElement.innerHTML='<div class=\\"preview-error\\">⚠️ Unable to load image from URL</div>'">
        <div class="preview-info">
          <strong>URL Preview</strong><br>
          ${url}
        </div>
      </div>
    `;
    preview.style.display = "block";
  }
}

function updatePreview() {
  // Update preview logic here
}

function openFileExplorer() {
  const modal = document.getElementById("fileExplorerModal");
  if (modal) {
    modal.style.display = "block";
    loadDirectory("/");
  } else {
    console.error("File explorer modal not found");
    alert("File explorer is not available in this context");
  }
}

function closeFileExplorer() {
  const modal = document.getElementById("fileExplorerModal");
  if (modal) {
    modal.style.display = "none";
  }
  selectedFile = null;
  document.getElementById("selectBtn").disabled = true;
}

function loadDirectory(path) {
  currentPath = path;
  document.getElementById("currentPath").textContent = "📍 /public_html" + path;
  
  // Use direct path to header-manager.php for file browsing in global-ui context
  const browseUrl = typeof window.location !== 'undefined' && window.location.href.includes('global-ui-manager.php') 
    ? '/templates/global-ui/header-manager.php?browse_files=1&path=' + encodeURIComponent(path)
    : '?browse_files=1&path=' + encodeURIComponent(path);
  
  fetch(browseUrl)
    .then(response => response.json())
    .then(data => {
      const grid = document.getElementById("fileGrid");
      grid.innerHTML = "";
      
      if (path !== "/") {
        const backItem = document.createElement("div");
        backItem.className = "file-item folder-item";
        backItem.innerHTML = "<div class=\"file-icon\">⬆️</div><div class=\"file-name\">.. (Back)</div>";
        backItem.onclick = () => {
          const parentPath = path.substring(0, path.lastIndexOf("/")) || "/";
          loadDirectory(parentPath);
        };
        grid.appendChild(backItem);
      }
      
      // Handle both response formats: array format (standalone) and object format (global-ui-manager)
      let items = [];
      if (Array.isArray(data)) {
        // Standalone header-manager.php format: [{type: 'folder', name: 'name'}]
        items = data;
      } else if (data.folders && data.files) {
        // Global-ui-manager.php format: {folders: [], files: []}
        var folderItems = data.folders.map(function(name) { return {type: 'folder', name: name}; });
        var fileItems = data.files.map(function(name) { return {type: 'file', name: name}; });
        items = folderItems.concat(fileItems);
      }
      
      items.forEach(item => {
        const element = document.createElement("div");
        element.className = `file-item ${item.type}-item`;
        
        if (item.type === "folder") {
          element.innerHTML = `<div class="file-icon">📁</div><div class="file-name">${item.name}</div>`;
          element.onclick = () => loadDirectory(path + (path.endsWith("/") ? "" : "/") + item.name);
        } else {
          const isImage = /\.(jpg|jpeg|png|gif|svg|webp)$/i.test(item.name);
          const icon = isImage ? "🖼️" : "📄";
          const fullPath = (path + (path.endsWith("/") ? "" : "/") + item.name).replace(/\/+/g, '/');
          
          if (isImage) {
            element.innerHTML = `
              <div class="file-icon">${icon}</div>
              <div class="file-name">${item.name}</div>
              <div class="file-preview">
                <img src="${fullPath}" alt="${item.name}" style="width: 60px; height: 40px; object-fit: cover; border-radius: 4px; margin-top: 5px;" 
                     onerror="this.style.display='none'">
              </div>
            `;
            element.onclick = () => selectFileItem(element, fullPath);
          } else {
            element.innerHTML = `<div class="file-icon">${icon}</div><div class="file-name">${item.name}</div>`;
          }
        }
        
        grid.appendChild(element);
      });
    })
    .catch(error => {
      console.error("Error loading directory:", error);
      document.getElementById("fileGrid").innerHTML = "<div class='error-message'>Error loading directory</div>";
    });
}

function selectFileItem(element, filePath) {
  document.querySelectorAll(".file-item").forEach(item => item.classList.remove("selected"));
  element.classList.add("selected");
  selectedFile = filePath;
  document.getElementById("selectBtn").disabled = false;
}

function selectFile() {
  if (selectedFile) {
    document.getElementById("logoUrl").value = selectedFile;
    showImagePreview(selectedFile, 'urlPreview');
    updatePreview();
    closeFileExplorer();
  }
}

// Enhanced preview functions
function showImagePreview(src, containerId) {
  const container = document.getElementById(containerId);
  if (container) {
    container.innerHTML = `
      <div class="image-preview">
        <img src="${src}" alt="Preview" style="max-width: 200px; max-height: 100px; object-fit: contain; border-radius: 8px; border: 2px solid rgba(0, 255, 255, 0.3); margin-top: 10px;">
        <button type="button" onclick="clearPreview('${containerId}')" class="clear-preview-btn" style="background: rgba(255, 0, 0, 0.7); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; position: absolute; top: 5px; right: 5px; cursor: pointer;">✕</button>
      </div>
    `;
    container.style.display = "block";
    container.style.position = "relative";
  }
}

function clearPreview(containerId) {
  const container = document.getElementById(containerId);
  if (container) {
    container.innerHTML = "";
    container.style.display = "none";
  }
}

// Load available fonts from assets directory
function loadAvailableFonts() {
  // Create font face declarations for local fonts
  const fontFaces = [
    { family: 'Merriweather-Regular', file: 'Merriweather-Regular.ttf' },
    { family: 'Merriweather-Bold', file: 'Merriweather-Bold.ttf' },
    { family: 'Orbitron-Regular', file: 'Orbitron-Medium.woff2' },
    { family: 'Orbitron-Bold', file: 'Orbitron-Medium.woff2' },
    { family: 'Rajdhani-Regular', file: 'rajdhani/LDIxapCSOBg7S-QT7p4JM-aUWA.woff2' },
    { family: 'Rajdhani-Bold', file: 'rajdhani/LDI2apCSOBg7S-QT7pb0EPOqeef2kg.woff2' }
  ];
  
  // Add font face styles to document
  const style = document.createElement('style');
  let fontCSS = '';
  
  fontFaces.forEach(font => {
    fontCSS += `
      @font-face {
        font-family: '${font.family}';
        src: url('/templates/assets/fonts/${font.file}') format('${font.file.endsWith('.woff2') ? 'woff2' : 'truetype'}');
        font-display: swap;
      }
    `;
  });
  
  style.textContent = fontCSS;
  document.head.appendChild(style);
}

// Update header configuration in real-time
function updateHeaderPreview() {
  // Collect current form values
  const formData = new FormData();
  formData.append('action', 'save_header');
  
  // Site Title settings
  const siteNameEnabled = document.querySelector('[name="site_name_enabled"]')?.checked;
  const titleText = document.querySelector('[name="title"]')?.value;
  const titleFont = document.querySelector('[name="title_font"]')?.value;
  const titlePosition = document.querySelector('[name="title_position"]')?.value;
  const titleSize = document.querySelector('[name="title_size"]')?.value;
  const titleColor = document.querySelector('[name="title_color"]')?.value;
  const titleOpacity = document.querySelector('[name="title_opacity"]')?.value;
  const titleSloganSpacing = document.querySelector('[name="title_slogan_spacing"]')?.value;
  const headerContentSpacing = document.querySelector('[name="header_content_spacing"]')?.value;
  
  if (siteNameEnabled) formData.append('site_name_enabled', '1');
  if (titleText) formData.append('title', titleText);
  if (titleFont) formData.append('title_font', titleFont);
  if (titlePosition) formData.append('title_position', titlePosition);
  if (titleSize) formData.append('title_size', titleSize);
  if (titleColor) formData.append('title_color', titleColor);
  if (titleOpacity) formData.append('title_opacity', titleOpacity);
  if (titleSloganSpacing) formData.append('title_slogan_spacing', titleSloganSpacing);
  if (headerContentSpacing) formData.append('header_content_spacing', headerContentSpacing);
  
  // Slogan settings
  const sloganEnabled = document.querySelector('[name="slogan_enabled"]')?.checked;
  const sloganText = document.querySelector('[name="slogan_text"]')?.value;
  const sloganFont = document.querySelector('[name="slogan_font"]')?.value;
  const sloganSize = document.querySelector('[name="slogan_size"]')?.value;
  const sloganPosition = document.querySelector('[name="slogan_position"]')?.value;
  const sloganColor = document.querySelector('[name="slogan_color"]')?.value;
  
  if (sloganEnabled) formData.append('slogan_enabled', '1');
  if (sloganText) formData.append('slogan_text', sloganText);
  if (sloganFont) formData.append('slogan_font', sloganFont);
  if (sloganSize) formData.append('slogan_size', sloganSize);
  if (sloganPosition) formData.append('slogan_position', sloganPosition);
  if (sloganColor) formData.append('slogan_color', sloganColor);
  
  // Visual Effects settings
  const shadowEnabled = document.querySelector('[name="shadow_enabled"]')?.checked;
  const shadowColor = document.querySelector('[name="shadow_color"]')?.value;
  const shadowBlur = document.querySelector('[name="shadow_blur"]')?.value;
  const shadowX = document.querySelector('[name="shadow_x"]')?.value;
  const shadowY = document.querySelector('[name="shadow_y"]')?.value;
  const shadowSpread = document.querySelector('[name="shadow_spread"]')?.value;
  
  const borderEnabled = document.querySelector('[name="border_enabled"]')?.checked;
  const borderColor = document.querySelector('[name="border_color"]')?.value;
  const borderWidth = document.querySelector('[name="border_width"]')?.value;
  const borderStyle = document.querySelector('[name="border_style"]')?.value;
  const borderRadius = document.querySelector('[name="border_radius"]')?.value;
  
  const glowEnabled = document.querySelector('[name="glow_enabled"]')?.checked;
  const glowColor = document.querySelector('[name="glow_color"]')?.value;
  const glowIntensity = document.querySelector('[name="glow_intensity"]')?.value;
  const glowSize = document.querySelector('[name="glow_size"]')?.value;
  
  if (shadowEnabled) formData.append('shadow_enabled', '1');
  if (shadowColor) formData.append('shadow_color', shadowColor);
  if (shadowBlur) formData.append('shadow_blur', shadowBlur);
  if (shadowX) formData.append('shadow_x', shadowX);
  if (shadowY) formData.append('shadow_y', shadowY);
  if (shadowSpread) formData.append('shadow_spread', shadowSpread);
  
  if (borderEnabled) formData.append('border_enabled', '1');
  if (borderColor) formData.append('border_color', borderColor);
  if (borderWidth) formData.append('border_width', borderWidth);
  if (borderStyle) formData.append('border_style', borderStyle);
  if (borderRadius) formData.append('border_radius', borderRadius);
  
  if (glowEnabled) formData.append('glow_enabled', '1');
  if (glowColor) formData.append('glow_color', glowColor);
  if (glowIntensity) formData.append('glow_intensity', glowIntensity);
  if (glowSize) formData.append('glow_size', glowSize);
  
  // Main header settings
  const headerHeight = document.querySelector('[name="height"]')?.value;
  const headerPosition = document.querySelector('[name="position"]')?.value;
  const backgroundColor = document.querySelector('[name="background_color"]')?.value;
  const backgroundOpacity = document.querySelector('[name="background_opacity"]')?.value;
  const verticalAlignment = document.querySelector('[name="vertical_alignment"]')?.value;
  
  if (headerHeight) formData.append('height', headerHeight);
  if (headerPosition) formData.append('position', headerPosition);
  if (backgroundColor) formData.append('background_color', backgroundColor);
  if (backgroundOpacity) formData.append('background_opacity', backgroundOpacity);
  if (verticalAlignment) formData.append('vertical_alignment', verticalAlignment);
  
  // Logo settings
  const logoEnabled = document.querySelector('[name="logo_enabled"]')?.checked;
  const logoPath = document.querySelector('[name="logo_image_path"]')?.value;
  const logoWidth = document.querySelector('[name="logo_width"]')?.value;
  const logoHeight = document.querySelector('[name="logo_height"]')?.value;
  const logoPosition = document.querySelector('[name="logo_position"]')?.value;
  const logoMarginX = document.querySelector('[name="logo_margin_x"]')?.value;
  const logoMarginY = document.querySelector('[name="logo_margin_y"]')?.value;
  const logoAnimationType = document.querySelector('[name="logo_animation_type"]')?.value;
  const logoAnimationEnabled = document.querySelector('[name="logo_animation_enabled"]')?.checked;
  const logoAnimationDuration = document.querySelector('[name="logo_animation_duration"]')?.value;
  
  if (logoEnabled) formData.append('logo_enabled', '1');
  if (logoPath) formData.append('logo_image_path', logoPath);
  if (logoWidth) formData.append('logo_width', logoWidth);
  if (logoHeight) formData.append('logo_height', logoHeight);
  if (logoPosition) formData.append('logo_position', logoPosition);
  if (logoMarginX) formData.append('logo_margin_x', logoMarginX);
  if (logoMarginY) formData.append('logo_margin_y', logoMarginY);
  if (logoAnimationType) formData.append('logo_animation_type', logoAnimationType);
  if (logoAnimationEnabled) formData.append('logo_animation_enabled', '1');
  if (logoAnimationDuration) formData.append('logo_animation_duration', logoAnimationDuration);
  
  // Status Bar settings removed - managed in widgets-manager.php
  
  // Send AJAX request to update header
  fetch('', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json().catch(() => ({ success: true })))
  .then(data => {
    if (data.success !== false) {
      // Refresh the actual global header
      if (typeof window.refreshActualHeader === 'function') {
        setTimeout(() => {
          window.refreshActualHeader();
        }, 300);
      }
      
      // Header updated - no need to reload page
      console.log('Header configuration updated successfully');
    }
  })
  .catch(error => console.error('Header update error:', error));
}

// Update title opacity value display
function updateTitleOpacityValue(slider) {
  const valueSpan = slider.parentElement.querySelector('.opacity-value');
  if (valueSpan) {
    valueSpan.textContent = slider.value + '%';
  }
}

// Update background opacity value display
function updateBackgroundOpacityValue(slider) {
  const valueSpan = slider.parentElement.querySelector('.opacity-value');
  if (valueSpan) {
    valueSpan.textContent = slider.value + '%';
  }
}

// Update color block value display
function updateColorBlockValue(colorInput) {
  const valueSpan = colorInput.parentElement.querySelector('.color-block-value');
  if (valueSpan) {
    valueSpan.textContent = colorInput.value.toUpperCase();
  }
}

// Toggle glow settings visibility
function toggleGlowSettings(checkbox) {
  const glowSettings = document.getElementById('glow-settings');
  if (glowSettings) {
    glowSettings.style.display = checkbox.checked ? 'flex' : 'none';
  }
}

// URL validation function
function isValidLogoUrl(url) {
  // Allow various URL formats:
  // - Full URLs: https://example.com/logo.png
  // - Domain URLs: example.com/logo.png  
  // - Absolute paths: /path/to/logo.png
  // - Relative paths: images/logo.png
  const urlPattern = /^(https?:\/\/)?(([\w\-]+\.)+[a-z]{2,}(:[0-9]+)?(\/.*)?|\/.+|[\w\-\/]+\.[a-zA-Z]{2,4})$/i;
  const imageExtPattern = /\.(jpg|jpeg|png|gif|svg|webp)$/i;
  
  return urlPattern.test(url) && (imageExtPattern.test(url) || url.includes('/'));
}

// Show URL error function
function showUrlError(message) {
  const preview = document.getElementById("logoPreview");
  if (preview) {
    preview.innerHTML = `
      <div class="preview-error" style="color: #ff6666; padding: 10px; background: rgba(255, 0, 0, 0.1); border: 1px solid rgba(255, 0, 0, 0.3); border-radius: 6px; margin-top: 8px;">
        ⚠️ ${message}
      </div>
    `;
    preview.style.display = "block";
  }
}

// Enhanced hide preview function
function hidePreview() {
  const preview = document.getElementById("logoPreview");
  if (preview) {
    preview.innerHTML = "";
    preview.style.display = "none";
  }
}

// openFileExplorer function moved to main script block to avoid duplicates

function updateColorPreview(inputId, previewId) {
  const colorInput = document.getElementById(inputId);
  const previewBox = document.getElementById(previewId);
  const colorValue = colorInput.value;
  
  previewBox.style.backgroundColor = colorValue;
  previewBox.nextElementSibling.textContent = colorValue;
  
  // Trigger header update via AJAX
  updateHeaderColors();
}

function updateHeaderColors() {
  const bgColor = document.getElementById("backgroundColor").value;
  const textColor = document.getElementById("textColor").value;
  
  fetch("", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: `action=update_colors&background_color=${encodeURIComponent(bgColor)}&text_color=${encodeURIComponent(textColor)}`
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      refreshHeader();
    }
  })
  .catch(error => console.error("Error updating colors:", error));
}

function refreshHeader() {
  // Header refresh - no page reload needed
  console.log('Header refresh requested');
}

// Alias function for compatibility
function refreshActualHeader() {
  refreshHeader();
}

// Background Type Management
function initBackgroundControls() {
  const backgroundTypeRadios = document.querySelectorAll('input[name="background_type"]');
  const solidOptions = document.getElementById('solid-options');
  const gradientOptions = document.getElementById('gradient-options');
  const animatedOptions = document.getElementById('animated-options');
  
  // Show/hide options based on selected background type
  function updateBackgroundOptions() {
    const selectedType = document.querySelector('input[name="background_type"]:checked')?.value || 'solid';
    
    solidOptions.style.display = selectedType === 'solid' ? 'block' : 'none';
    gradientOptions.style.display = selectedType === 'gradient' ? 'block' : 'none';
    animatedOptions.style.display = selectedType === 'animated' ? 'block' : 'none';
  }
  
  // Add event listeners to radio buttons
  backgroundTypeRadios.forEach(radio => {
    radio.addEventListener('change', updateBackgroundOptions);
  });
  
  // Initialize on load
  updateBackgroundOptions();
  
  // Multi-color gradient toggle
  const multiColorCheckbox = document.getElementById('gradient_multi');
  const multiColorControls = document.getElementById('multi-color-controls');
  
  if (multiColorCheckbox && multiColorControls) {
    multiColorCheckbox.addEventListener('change', function() {
      multiColorControls.style.display = this.checked ? 'block' : 'none';
    });
  }
}

// Color value update functions
function updateColorBlockValue(input) {
  const colorBlock = input.parentElement.querySelector('.color-block-value');
  if (colorBlock) {
    colorBlock.textContent = input.value;
  }
}

function updateBackgroundOpacityValue(slider) {
  const valueSpan = slider.parentElement.querySelector('.opacity-value');
  if (valueSpan) {
    valueSpan.textContent = slider.value + '%';
  }
}

function updateGradientOpacityValue(slider) {
  const valueSpan = slider.parentElement.querySelector('.opacity-value');
  if (valueSpan) {
    valueSpan.textContent = slider.value + '%';
  }
}

function updateAnimationOpacityValue(slider) {
  const valueSpan = slider.parentElement.querySelector('.opacity-value');
  if (valueSpan) {
    valueSpan.textContent = slider.value + '%';
  }
}

// Initialize background controls when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
  initBackgroundControls();
});
</script>

<form method="post" class="form-container" enctype="multipart/form-data" data-form-container data-component="header">
    <input type="hidden" name="action" value="save_header">
    
    <!-- Site Title Section -->
    <div class="site-title-section">
        <h3>🏷️ Site Title Configuration</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">
                    <input type="checkbox" name="site_name_enabled" value="1" 
                           <?= ($currentConfig['hdr_site_name_enabled'] ?? true) ? 'checked' : '' ?> class="form-checkbox">
                    Enable Site Title
                </label>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group full-width">
                <label class="form-label">Site Title Text:</label>
                <input type="text" name="title" value="<?= htmlspecialchars($currentConfig['hdr_site_name_text'] ?? $currentConfig['title'] ?? '') ?>" 
                       class="form-input" placeholder="Enter your site title" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">🔤 Title Font:</label>
                <select name="title_font" class="form-select" id="titleFont">
                    <option value="Merriweather-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Merriweather-Regular' ? 'selected' : '' ?>>Merriweather Regular</option>
                    <option value="Merriweather-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Merriweather-Bold' ? 'selected' : '' ?>>Merriweather Bold</option>
                    <option value="Orbitron-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Orbitron-Regular' ? 'selected' : '' ?>>Orbitron Regular</option>
                    <option value="Orbitron-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Orbitron-Bold' ? 'selected' : '' ?>>Orbitron Bold</option>
                    <option value="Rajdhani-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Regular' ? 'selected' : '' ?>>Rajdhani Regular</option>
                    <option value="Rajdhani-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Bold' ? 'selected' : '' ?>>Rajdhani Bold</option>
                    <option value="Inter-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Inter-Regular' ? 'selected' : '' ?>>Inter Regular</option>
                    <option value="Inter-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Inter-Bold' ? 'selected' : '' ?>>Inter Bold</option>
                    <option value="Lato-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Lato-Regular' ? 'selected' : '' ?>>Lato Regular</option>
                    <option value="Lato-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Lato-Bold' ? 'selected' : '' ?>>Lato Bold</option>
                    <option value="Montserrat-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Montserrat-Regular' ? 'selected' : '' ?>>Montserrat Regular</option>
                    <option value="Montserrat-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Montserrat-Bold' ? 'selected' : '' ?>>Montserrat Bold</option>
                    <option value="Poppins-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Poppins-Regular' ? 'selected' : '' ?>>Poppins Regular</option>
                    <option value="Poppins-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Poppins-Bold' ? 'selected' : '' ?>>Poppins Bold</option>
                    <option value="Roboto-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Roboto-Regular' ? 'selected' : '' ?>>Roboto Regular</option>
                    <option value="Roboto-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Roboto-Bold' ? 'selected' : '' ?>>Roboto Bold</option>
                    <option value="Open-Sans-Regular" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Regular' ? 'selected' : '' ?>>Open Sans Regular</option>
                    <option value="Open-Sans-Bold" <?= ($currentConfig['hdr_title_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Bold' ? 'selected' : '' ?>>Open Sans Bold</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">📍 Title Position:</label>
                <select name="title_position" class="form-select">
                    <option value="left" <?= ($currentConfig['hdr_title_position'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                    <option value="center" <?= ($currentConfig['hdr_title_position'] ?? 'left') === 'center' ? 'selected' : '' ?>>Center</option>
                    <option value="right" <?= ($currentConfig['hdr_title_position'] ?? 'left') === 'right' ? 'selected' : '' ?>>Right</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">📏 Title Size:</label>
                <div class="input-group">
                    <input type="number" name="title_size" value="<?= htmlspecialchars($currentConfig['hdr_title_size'] ?? '24') ?>" 
                           min="0" max="200" step="1" class="number-input">
                    <span class="unit-label">px</span>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">🎨 Title Color:</label>
                <div class="color-opacity-group">
                    <div class="color-block-selector">
                        <input type="color" name="title_color" value="<?= htmlspecialchars($currentConfig['hdr_title_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Title Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_title_color'] ?? '#00ffff') ?></span>
                        </div>
                    </div>
                    <div class="opacity-control">
                        <label>Opacity:</label>
                        <input type="range" name="title_opacity" value="<?= htmlspecialchars($currentConfig['hdr_title_opacity'] ?? '100') ?>" 
                               min="0" max="100" step="5" class="opacity-slider" oninput="updateTitleOpacityValue(this)">
                        <span class="opacity-value"><?= htmlspecialchars($currentConfig['hdr_title_opacity'] ?? '100') ?>%</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">↔️ Title-Slogan Spacing:</label>
                <div class="input-group">
                    <input type="number" name="title_slogan_spacing" value="<?= htmlspecialchars($currentConfig['hdr_title_slogan_spacing'] ?? '20') ?>" 
                           min="0" max="100" step="1" class="number-input">
                    <span class="unit-label">px</span>
                </div>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">📐 Header-Content Spacing:</label>
                <div class="input-group">
                    <input type="number" name="header_content_spacing" value="<?= htmlspecialchars($currentConfig['hdr_content_spacing'] ?? '15') ?>" 
                           min="0" max="200" step="5" class="number-input">
                    <span class="unit-label">px</span>
                </div>
                <small class="form-help">Space between header and main content</small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="hdr_auto_offset" id="hdr_auto_offset" class="form-checkbox"
                           <?= (($currentConfig['hdr_auto_offset'] ?? true) ? 'checked' : '') ?>>
                    <label for="hdr_auto_offset" class="checkbox-label">Auto Content Offset</label>
                </div>
                <small class="form-help">Automatically adds spacing so content never sits under the header</small>
            </div>
        </div>
    </div>

    <!-- Slogan/Subtitle Section -->
    <div class="slogan-section">
        <h3>💬 Slogan/Subtitle Configuration</h3>
        
        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="slogan_enabled" id="slogan_enabled" class="form-checkbox" 
                           <?= ($currentConfig['hdr_slogan_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="slogan_enabled" class="checkbox-label">💬 Enable Slogan</label>
                </div>
            </div>
        </div>
        
        <div id="slogan-controls" class="slogan-controls" <?= ($currentConfig['hdr_slogan_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
            <div class="form-row">
                <div class="form-group full-width">
                    <label class="form-label">Slogan Text:</label>
                    <input type="text" name="slogan_text" value="<?= htmlspecialchars($currentConfig['hdr_slogan_text'] ?? '') ?>" 
                           class="form-input" placeholder="Enter your slogan or subtitle">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🔤 Slogan Font:</label>
                    <select name="slogan_font" class="form-select">
                        <option value="Merriweather-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Merriweather-Regular' ? 'selected' : '' ?>>Merriweather Regular</option>
                        <option value="Merriweather-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Merriweather-Bold' ? 'selected' : '' ?>>Merriweather Bold</option>
                        <option value="Orbitron-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Orbitron-Regular' ? 'selected' : '' ?>>Orbitron Regular</option>
                        <option value="Orbitron-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Orbitron-Bold' ? 'selected' : '' ?>>Orbitron Bold</option>
                        <option value="Rajdhani-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Rajdhani-Regular' ? 'selected' : '' ?>>Rajdhani Regular</option>
                        <option value="Rajdhani-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Rajdhani-Bold' ? 'selected' : '' ?>>Rajdhani Bold</option>
                        <option value="Inter-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Inter-Regular' ? 'selected' : '' ?>>Inter Regular</option>
                        <option value="Inter-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Inter-Bold' ? 'selected' : '' ?>>Inter Bold</option>
                        <option value="Lato-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Lato-Regular' ? 'selected' : '' ?>>Lato Regular</option>
                        <option value="Lato-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Lato-Bold' ? 'selected' : '' ?>>Lato Bold</option>
                        <option value="Montserrat-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Montserrat-Regular' ? 'selected' : '' ?>>Montserrat Regular</option>
                        <option value="Montserrat-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Montserrat-Bold' ? 'selected' : '' ?>>Montserrat Bold</option>
                        <option value="Poppins-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Poppins-Regular' ? 'selected' : '' ?>>Poppins Regular</option>
                        <option value="Poppins-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Poppins-Bold' ? 'selected' : '' ?>>Poppins Bold</option>
                        <option value="Roboto-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Roboto-Regular' ? 'selected' : '' ?>>Roboto Regular</option>
                        <option value="Roboto-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Roboto-Bold' ? 'selected' : '' ?>>Roboto Bold</option>
                        <option value="Open-Sans-Regular" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Open-Sans-Regular' ? 'selected' : '' ?>>Open Sans Regular</option>
                        <option value="Open-Sans-Bold" <?= ($currentConfig['hdr_slogan_font'] ?? 'Rajdhani-Regular') === 'Open-Sans-Bold' ? 'selected' : '' ?>>Open Sans Bold</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">📏 Slogan Size:</label>
                    <div class="input-group">
                        <input type="number" name="slogan_size" value="<?= htmlspecialchars($currentConfig['hdr_slogan_size'] ?? '16') ?>" 
                               min="0" max="200" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📍 Slogan Position:</label>
                    <select name="slogan_position" class="form-select">
                        <option value="left" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'right' ? 'selected' : '' ?>>Right</option>
                        <option value="top" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'top' ? 'selected' : '' ?>>Top</option>
                        <option value="bottom" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'bottom' ? 'selected' : '' ?>>Bottom</option>
                        <option value="under_header" <?= ($currentConfig['hdr_slogan_position'] ?? 'center') === 'under_header' ? 'selected' : '' ?>>Under Header</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">🎨 Slogan Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="slogan_color" value="<?= htmlspecialchars($currentConfig['hdr_slogan_color'] ?? '#ccffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Slogan Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_slogan_color'] ?? '#ccffff') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Logo Customization Section -->
    <div class="logo-section">
        <h3>🖼️ Logo Customization</h3>
        
        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="logo_enabled" id="logo_enabled" class="form-checkbox" 
                           <?= ($currentConfig['hdr_logo_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="logo_enabled" class="checkbox-label">✅ Enable Logo</label>
                </div>
            </div>
        </div>
        
        <div id="logo-controls" class="logo-controls" <?= ($currentConfig['hdr_logo_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
            <!-- Logo Upload & URL Section -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📁 Upload Logo File:</label>
                    <div class="file-upload-container">
                        <div class="file-upload-section">
                            <div class="modern-file-input">
                                <input type="file" name="logo_file" id="logoFile" accept="image/png,image/jpeg,image/jpg,image/svg+xml" style="display: none;">
                                <label for="logoFile" class="modern-file-button" id="fileLabel">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                                    </svg>
                                    <span id="fileLabelText">Choose File</span>
                                </label>
                                <button type="button" class="browse-server-btn" onclick="openFileExplorer()">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" style="margin-right: 5px;">
                                        <path d="M10,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V8C22,6.89 21.1,6 20,6H12L10,4Z"/>
                                    </svg>
                                    Browse Server
                                </button>
                            </div>
                            <div class="file-info">
                                <small>Supported: PNG, JPG, SVG • Max: 2MB • Min: 50×50px</small>
                            </div>
                            <div id="uploadPreview"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🌐 Or Logo URL:</label>
                    <input type="text" name="logo" id="logoUrl" value="<?= htmlspecialchars($currentConfig['hdr_logo_image_path'] ?? $currentConfig['logo'] ?? '') ?>"
                           class="form-input" placeholder="example.com/logo.png or /path/to/logo.png">
                    <div id="logoUrlError" style="color: #ff6b6b; font-size: 0.8em; margin-top: 5px; display: none;"></div>
                    <div style="color: #888; font-size: 0.75em; margin-top: 3px;">
                      💡 Accepts: example.com/logo.png, /path/image.jpg, https://site.com/logo.svg
                    </div>
                    <div id="urlPreview"></div>
                </div>
            </div>
            
            <!-- Logo Size Controls -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📏 Logo Width:</label>
                    <div class="input-group">
                        <input type="number" name="logo_width" id="logoWidth" 
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_width'] ?? '80') ?>" 
                               min="0" max="500" step="5" class="number-input">
                        <span class="unit-label">px</span>
                        <button type="button" class="aspect-lock-btn" id="aspectLockBtn" title="Lock Aspect Ratio">🔒</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">📐 Logo Height:</label>
                    <div class="input-group">
                        <input type="number" name="logo_height" id="logoHeight"
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_height'] ?? '80') ?>" 
                               min="0" max="500" step="5" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            <input type="hidden" name="logo_aspect_locked" id="logoAspectLocked" 
                   value="<?= ($currentConfig['hdr_logo_aspect_locked'] ?? false) ? '1' : '0' ?>">
            
            <!-- Logo Position & Margins -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📍 Logo Position:</label>
                    <select name="logo_position" class="form-select">
                        <option value="left" <?= ($currentConfig['hdr_logo_position'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= ($currentConfig['hdr_logo_position'] ?? 'left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= ($currentConfig['hdr_logo_position'] ?? 'left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">↔️ Horizontal Margin:</label>
                    <div class="input-group">
                        <input type="number" name="logo_margin_x" id="logoMarginX"
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_margin_x'] ?? '20') ?>" 
                               min="0" max="100" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">↕️ Vertical Margin:</label>
                    <div class="input-group">
                        <input type="number" name="logo_margin_y" id="logoMarginY"
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_margin_y'] ?? '10') ?>" 
                               min="0" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group"></div>
            </div>
            
            <!-- Logo Animation Effects -->
            <div class="form-row">
                <div class="form-group">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="logo_animation_enabled" id="logoAnimationEnabled" class="form-checkbox"
                               <?= ($currentConfig['hdr_logo_animation_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="logoAnimationEnabled" class="checkbox-label">✨ Enable Logo Animation</label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎭 Animation Type:</label>
                    <select name="logo_animation_type" id="logoAnimationType" class="form-select">
                        <option value="none" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="pulse" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'pulse' ? 'selected' : '' ?>>Pulse</option>
                        <option value="bounce" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'bounce' ? 'selected' : '' ?>>Bounce</option>
                        <option value="rotate" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'rotate' ? 'selected' : '' ?>>Rotate</option>
                        <option value="wobble" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'wobble' ? 'selected' : '' ?>>Wobble</option>
                        <option value="fade" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'fade' ? 'selected' : '' ?>>Fade</option>
                        <option value="scale" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'scale' ? 'selected' : '' ?>>Scale</option>
                        <option value="glow" <?= ($currentConfig['hdr_logo_animation_type'] ?? 'none') === 'glow' ? 'selected' : '' ?>>Glow</option>
                    </select>
                    <span class="animation-preview" id="animationPreview">Preview: <?= htmlspecialchars($currentConfig['hdr_logo_animation_type'] ?? 'none') ?></span>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">⏱️ Animation Duration:</label>
                    <div class="input-group">
                        <input type="number" name="logo_animation_duration" id="logoAnimationDuration"
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_animation_duration'] ?? '1.0') ?>" 
                               min="0.1" max="5.0" step="0.1" class="number-input">
                        <span class="unit-label">s</span>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="logo_glow_enabled" id="logoGlowEnabled" class="form-checkbox"
                               <?= ($currentConfig['hdr_logo_glow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="logoGlowEnabled" class="checkbox-label">✨ Enable Logo Glow</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🌟 Glow Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="logo_glow_color" value="<?= htmlspecialchars($currentConfig['hdr_logo_glow_color'] ?? '#00d4ff') ?>" 
                               class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Logo Glow Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_logo_glow_color'] ?? '#00d4ff') ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">💫 Glow Intensity:</label>
                    <div class="input-group">
                        <input type="number" name="logo_glow_intensity" id="logoGlowIntensity"
                               value="<?= htmlspecialchars($currentConfig['hdr_logo_glow_intensity'] ?? '5') ?>" 
                               min="1" max="20" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Visual Effects Section -->
    <div class="visual-effects-section">
        <h3>✨ Visual Effects</h3>
        
        <!-- Shadow Effects -->
        <div class="effect-subsection">
            <h4>🌑 Shadow Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="shadow_enabled" id="shadow_enabled" class="form-checkbox" 
                               <?= ($currentConfig['hdr_shadow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="shadow_enabled" class="checkbox-label">🌑 Enable Shadow Effects</label>
                    </div>
                </div>
            </div>
            <div class="form-row" id="shadow-controls" <?= ($currentConfig['hdr_shadow_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-group">
                    <label class="form-label">↔️ X Offset:</label>
                    <div class="input-group">
                        <input type="number" name="shadow_x" value="<?= htmlspecialchars($currentConfig['hdr_shadow_x'] ?? '2') ?>" 
                               min="-50" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">↕️ Y Offset:</label>
                    <div class="input-group">
                        <input type="number" name="shadow_y" value="<?= htmlspecialchars($currentConfig['hdr_shadow_y'] ?? '2') ?>" 
                               min="-50" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🌫️ Blur Radius:</label>
                    <div class="input-group">
                        <input type="number" name="shadow_blur" value="<?= htmlspecialchars($currentConfig['hdr_shadow_blur'] ?? '4') ?>" 
                               min="0" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">📏 Spread:</label>
                    <div class="input-group">
                        <input type="number" name="shadow_spread" value="<?= htmlspecialchars($currentConfig['hdr_shadow_spread'] ?? '0') ?>" 
                               min="-20" max="20" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🎨 Shadow Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="shadow_color" value="<?= htmlspecialchars($currentConfig['hdr_shadow_color'] ?? '#000000') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Shadow Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_shadow_color'] ?? '#000000') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Border Effects -->
        <div class="effect-subsection">
            <h4>🖼️ Border Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="border_enabled" id="border_enabled" class="form-checkbox" 
                               <?= ($currentConfig['hdr_border_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="border_enabled" class="checkbox-label">🖼️ Enable Border Effects</label>
                    </div>
                </div>
            </div>
            <div class="form-row" id="border-controls" <?= ($currentConfig['hdr_border_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-group">
                    <label class="form-label">📏 Border Width:</label>
                    <div class="input-group">
                        <input type="number" name="border_width" value="<?= htmlspecialchars($currentConfig['hdr_border_width'] ?? '0') ?>" 
                               min="0" max="10" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🖌️ Border Style:</label>
                    <select name="border_style" class="form-select">
                        <option value="solid" <?= ($currentConfig['hdr_border_style'] ?? 'solid') === 'solid' ? 'selected' : '' ?>>Solid</option>
                        <option value="dashed" <?= ($currentConfig['hdr_border_style'] ?? 'solid') === 'dashed' ? 'selected' : '' ?>>Dashed</option>
                        <option value="dotted" <?= ($currentConfig['hdr_border_style'] ?? 'solid') === 'dotted' ? 'selected' : '' ?>>Dotted</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🔄 Border Radius:</label>
                    <div class="input-group">
                        <input type="number" name="border_radius" value="<?= htmlspecialchars($currentConfig['hdr_border_radius'] ?? '0') ?>" 
                               min="0" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎨 Border Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="border_color" value="<?= htmlspecialchars($currentConfig['hdr_border_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Border Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_border_color'] ?? '#00ffff') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Glow Effects -->
        <div class="effect-subsection">
            <h4>✨ Advanced Glow Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="glow_enabled" id="glow_enabled" class="form-checkbox" 
                               <?= ($currentConfig['hdr_glow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="glow_enabled" class="checkbox-label">✨ Enable Glow Effects</label>
                    </div>
                </div>
            </div>
            <div class="form-row" id="glow-controls" <?= ($currentConfig['hdr_glow_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-group">
                    <label class="form-label">💫 Glow Size:</label>
                    <div class="input-group">
                        <input type="number" name="glow_size" value="<?= htmlspecialchars($currentConfig['hdr_glow_size'] ?? '10') ?>" 
                               min="0" max="50" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🔥 Glow Depth:</label>
                    <div class="input-group">
                        <input type="range" name="glow_depth" value="<?= htmlspecialchars($currentConfig['hdr_glow_depth'] ?? '5') ?>" 
                               min="1" max="10" step="1" class="range-slider">
                        <span class="range-value"><?= htmlspecialchars($currentConfig['hdr_glow_depth'] ?? '5') ?></span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">👁️ Visibility Threshold:</label>
                    <div class="input-group">
                        <input type="range" name="glow_visibility" value="<?= htmlspecialchars($currentConfig['hdr_glow_visibility'] ?? '70') ?>" 
                               min="0" max="100" step="5" class="range-slider">
                        <span class="range-value"><?= htmlspecialchars($currentConfig['hdr_glow_visibility'] ?? '70') ?>%</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎨 Glow Color:</label>
                    <div class="color-opacity-group">
                        <div class="color-block-selector">
                            <input type="color" name="glow_color" value="<?= htmlspecialchars($currentConfig['hdr_glow_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                            <div class="color-block-info">
                                <span class="color-block-label">Glow Color</span>
                                <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_glow_color'] ?? '#00ffff') ?></span>
                            </div>
                        </div>
                        <div class="opacity-control">
                            <label>Opacity:</label>
                            <input type="range" name="glow_opacity" value="<?= htmlspecialchars($currentConfig['hdr_glow_opacity'] ?? '80') ?>" 
                                   min="0" max="100" step="5" class="opacity-slider">
                            <span class="opacity-value"><?= htmlspecialchars($currentConfig['hdr_glow_opacity'] ?? '80') ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group half-width">
            <label class="form-label">📍 Header Position:</label>
            <select name="position" class="form-select">
                <option value="static" <?= ($currentConfig['hdr_position'] ?? $currentConfig['position'] ?? '') === 'static' ? 'selected' : '' ?>>Static</option>
                <option value="fixed" <?= ($currentConfig['hdr_position'] ?? $currentConfig['position'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed Top</option>
                <option value="relative" <?= ($currentConfig['hdr_position'] ?? $currentConfig['position'] ?? '') === 'relative' ? 'selected' : '' ?>>Relative</option>
                <option value="sticky" <?= ($currentConfig['hdr_position'] ?? $currentConfig['position'] ?? '') === 'sticky' ? 'selected' : '' ?>>Sticky</option>
            </select>
        </div>
        <div class="form-group half-width">
            <label class="form-label">📏 Header Height (px):</label>
            <input type="number" name="height" value="<?= htmlspecialchars($currentConfig['hdr_height'] ?? $currentConfig['height'] ?? '300') ?>" 
                   class="form-input" min="40">
        </div>
    </div>
    
    <!-- HEADER BACKGROUND Section -->
    <div class="header-background-section">
        <h3 class="section-title">🎨 HEADER BACKGROUND</h3>
        
        <!-- Background Type Selection -->
        <div class="form-row">
            <div class="form-group full-width">
                <label class="form-label">📊 Background Type:</label>
                <div class="background-type-selector">
                    <div class="bg-type-option" data-type="solid">
                        <input type="radio" name="background_type" value="solid" id="bg_solid" 
                               <?= ($currentConfig['hdr_background_type'] ?? 'solid') === 'solid' ? 'checked' : '' ?>>
                        <label for="bg_solid">🎨 Solid Color</label>
                    </div>
                    <div class="bg-type-option" data-type="gradient">
                        <input type="radio" name="background_type" value="gradient" id="bg_gradient"
                               <?= ($currentConfig['hdr_background_type'] ?? 'solid') === 'gradient' ? 'checked' : '' ?>>
                        <label for="bg_gradient">🌈 Gradient</label>
                    </div>
                    <div class="bg-type-option" data-type="animated">
                        <input type="radio" name="background_type" value="animated" id="bg_animated"
                               <?= ($currentConfig['hdr_background_type'] ?? 'solid') === 'animated' ? 'checked' : '' ?>>
                        <label for="bg_animated">✨ Animated</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Solid Color Options -->
        <div id="solid-options" class="bg-type-content">
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">🎨 Background Color:</label>
                    <div class="color-opacity-group">
                        <div class="color-block-selector">
                            <input type="color" name="background_color" value="<?= htmlspecialchars($currentConfig['hdr_background_color'] ?? $currentConfig['background_color'] ?? '#1a1a2e') ?>" class="form-input modern-color-picker" onchange="updateColorBlockValue(this)">
                            <div class="color-block-info">
                                <span class="color-block-label">Background Color</span>
                                <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_background_color'] ?? $currentConfig['background_color'] ?? '#1a1a2e') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group half-width">
                    <label class="form-label">🔆 Background Opacity:</label>
                    <div class="opacity-control">
                        <input type="range" name="background_opacity" value="<?= htmlspecialchars($currentConfig['hdr_background_opacity'] ?? '100') ?>" 
                               min="0" max="100" step="5" class="opacity-slider" oninput="updateBackgroundOpacityValue(this)">
                        <span class="opacity-value"><?= htmlspecialchars($currentConfig['hdr_background_opacity'] ?? '100') ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gradient Options -->
        <div id="gradient-options" class="bg-type-content" style="display: none;">
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">🌈 First Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="gradient_color1" value="<?= htmlspecialchars($currentConfig['hdr_gradient_color1'] ?? '#1a1a2e') ?>" class="form-input modern-color-picker">
                        <div class="color-block-info">
                            <span class="color-block-label">Color 1</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_gradient_color1'] ?? '#1a1a2e') ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group third-width">
                    <label class="form-label">🌈 Second Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="gradient_color2" value="<?= htmlspecialchars($currentConfig['hdr_gradient_color2'] ?? '#003344') ?>" class="form-input modern-color-picker">
                        <div class="color-block-info">
                            <span class="color-block-label">Color 2</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_gradient_color2'] ?? '#003344') ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group third-width">
                    <label class="form-label">🔄 Gradient Angle:</label>
                    <div class="input-group">
                        <input type="number" name="gradient_angle" value="<?= htmlspecialchars($currentConfig['hdr_gradient_angle'] ?? '135') ?>" 
                               min="0" max="360" step="1" class="number-input">
                        <span class="unit-label">°</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">🌈 Third Color (Optional):</label>
                    <div class="gradient-multi-controls">
                        <div class="form-checkbox-group">
                            <input type="checkbox" name="gradient_multi_enabled" id="gradient_multi" class="form-checkbox"
                                   <?= ($currentConfig['hdr_gradient_multi_enabled'] ?? false) ? 'checked' : '' ?>>
                            <label for="gradient_multi" class="checkbox-label">Enable Multi-Color</label>
                        </div>
                        <div id="multi-color-controls" class="multi-color-section" style="display: <?= ($currentConfig['hdr_gradient_multi_enabled'] ?? false) ? 'block' : 'none' ?>;">
                            <div class="color-block-selector">
                                <input type="color" name="gradient_color3" value="<?= htmlspecialchars($currentConfig['hdr_gradient_color3'] ?? '#0066aa') ?>" class="form-input modern-color-picker">
                                <div class="color-block-info">
                                    <span class="color-block-label">Color 3</span>
                                    <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_gradient_color3'] ?? '#0066aa') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group half-width">
                    <label class="form-label">🔆 Gradient Opacity:</label>
                    <div class="opacity-control">
                        <input type="range" name="gradient_opacity" value="<?= htmlspecialchars($currentConfig['hdr_gradient_opacity'] ?? '100') ?>" 
                               min="0" max="100" step="5" class="opacity-slider" oninput="updateGradientOpacityValue(this)">
                        <span class="opacity-value"><?= htmlspecialchars($currentConfig['hdr_gradient_opacity'] ?? '100') ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Animated Background Options -->
        <div id="animated-options" class="bg-type-content" style="display: none;">
            <div class="form-row">
                <div class="form-group half-width">
                    <label class="form-label">✨ Animation Type:</label>
                    <select name="animation_type" class="form-select" id="animationTypeSelect">
                        <option value="none" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>No Animation</option>
                        <option value="waves" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'waves' ? 'selected' : '' ?>>Waves</option>
                        <option value="particles" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'particles' ? 'selected' : '' ?>>Particles</option>
                        <option value="net" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'net' ? 'selected' : '' ?>>Net</option>
                        <option value="dots" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'dots' ? 'selected' : '' ?>>Dots</option>
                        <option value="fog" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'fog' ? 'selected' : '' ?>>Fog</option>
                        <option value="birds" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'birds' ? 'selected' : '' ?>>Birds</option>
                        <option value="cells" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'cells' ? 'selected' : '' ?>>Cells</option>
                        <option value="clouds" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'clouds' ? 'selected' : '' ?>>Clouds</option>
                        <option value="halo" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'halo' ? 'selected' : '' ?>>Halo</option>
                        <option value="rings" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'rings' ? 'selected' : '' ?>>Rings</option>
                        <option value="ripple" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'ripple' ? 'selected' : '' ?>>Ripple</option>
                        <option value="topology" <?= ($currentConfig['hdr_animation_type'] ?? 'none') === 'topology' ? 'selected' : '' ?>>Topology</option>
                    </select>
                </div>
                <div class="form-group half-width">
                    <label class="form-label">🔆 Animation Opacity:</label>
                    <div class="opacity-control">
                        <input type="range" name="animation_opacity" value="<?= htmlspecialchars($currentConfig['hdr_animation_opacity'] ?? '100') ?>" 
                               min="0" max="100" step="5" class="opacity-slider" oninput="updateAnimationOpacityValue(this)">
                        <span class="opacity-value"><?= htmlspecialchars($currentConfig['hdr_animation_opacity'] ?? '100') ?>%</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group third-width">
                    <label class="form-label">🎨 Animation Base Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="animation_color" value="<?= htmlspecialchars($currentConfig['hdr_animation_color'] ?? '#0066aa') ?>" class="form-input modern-color-picker">
                        <div class="color-block-info">
                            <span class="color-block-label">Base Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['hdr_animation_color'] ?? '#0066aa') ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group third-width">
                    <label class="form-label">⚡ Animation Speed:</label>
                    <div class="input-group">
                        <input type="number" name="animation_speed" value="<?= htmlspecialchars($currentConfig['hdr_animation_speed'] ?? '1.0') ?>" 
                               min="0.1" max="5.0" step="0.1" class="number-input">
                        <span class="unit-label">x</span>
                    </div>
                </div>
                <div class="form-group third-width">
                    <label class="form-label">📊 Animation Scale:</label>
                    <div class="input-group">
                        <input type="number" name="animation_scale" value="<?= htmlspecialchars($currentConfig['hdr_animation_scale'] ?? '1.0') ?>" 
                               min="0.1" max="3.0" step="0.1" class="number-input">
                        <span class="unit-label">x</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group half-width">
            <label class="form-label">📐 Vertical Alignment:</label>
            <select name="vertical_alignment" class="form-select">
                <option value="top" <?= ($currentConfig['hdr_vertical_alignment'] ?? 'middle') === 'top' ? 'selected' : '' ?>>Top</option>
                <option value="middle" <?= ($currentConfig['hdr_vertical_alignment'] ?? 'middle') === 'middle' ? 'selected' : '' ?>>Middle</option>
                <option value="bottom" <?= ($currentConfig['hdr_vertical_alignment'] ?? 'middle') === 'bottom' ? 'selected' : '' ?>>Bottom</option>
            </select>
        </div>
    </div>
    
    <div class="form-row">
        <div class="form-group full-width">
            <div class="form-checkbox-group">
                <input type="checkbox" name="enabled" id="enabled" class="form-checkbox" 
                       <?= ($currentConfig['hdr_site_name_enabled'] ?? $currentConfig['enabled'] ?? false) ? 'checked' : '' ?>>
                <label for="enabled" class="checkbox-label">🔛 Enable Header</label>
            </div>
            
            <div class="form-checkbox-group">
                <input type="checkbox" name="show_navigation" id="show_navigation" class="form-checkbox"
                       <?= ($currentConfig['hdr_show_navigation'] ?? $currentConfig['show_navigation'] ?? false) ? 'checked' : '' ?>>
                <label for="show_navigation" class="checkbox-label">🧭 Show Navigation Menu</label>
            </div>
            
            <div class="form-checkbox-group">
                <input type="checkbox" name="sticky" id="sticky" class="form-checkbox"
                       <?= ($currentConfig['hdr_sticky_enabled'] ?? $currentConfig['sticky'] ?? false) ? 'checked' : '' ?>>
                <label for="sticky" class="checkbox-label">📌 Sticky Header</label>
            </div>
            
            <div class="form-checkbox-group">
                <input type="checkbox" name="border_bottom" id="border_bottom" class="form-checkbox"
                       <?= ($currentConfig['hdr_border_bottom_enabled'] ?? $currentConfig['border_bottom'] ?? false) ? 'checked' : '' ?>>
                <label for="border_bottom" class="checkbox-label">━ Bottom Border</label>
            </div>
            
            <div class="form-checkbox-group">
                <input type="checkbox" name="glassmorphism" id="glassmorphism" class="form-checkbox"
                       <?= ($currentConfig['hdr_glassmorphism_enabled'] ?? $currentConfig['glassmorphism'] ?? false) ? 'checked' : '' ?>>
                <label for="glassmorphism" class="checkbox-label">🔮 Glassmorphism Effect</label>
            </div>
        </div>
    </div>
    
    <!-- Status Bar settings removed - managed in widgets-manager.php -->
    
    <!-- Hidden fields for required configuration values (removed duplicates that interfere with visible form fields) -->
    <input type="hidden" name="action" value="save_header">
    
    <button type="submit" class="submit-button save-button">💾 Save Header Settings</button>
</form>

<div class="config-display">
    <h4>⚙️ Current Configuration</h4>
    <pre><?= json_encode($config, JSON_PRETTY_PRINT) ?></pre>
</div>

<?php
if ($isStandalone) {
    // Include global UI scripts for hamburger menu and other functionality
    if (function_exists('includeGlobalUIScripts')) {
        includeGlobalUIScripts();
    }
    
    // Always add fallback hamburger menu function
    echo '<script>';
    echo 'if (typeof toggleHamburgerMenu === "undefined") {';
    echo '  function toggleHamburgerMenu() {';
    echo '    const menu = document.querySelector(".cue-hamburger-menu");';
    echo '    if (menu) {';
    echo '      menu.classList.toggle("active");';
    echo '      console.log("Hamburger menu toggled");';
    echo '    } else {';
    echo '      console.log("Hamburger menu element not found");';
    echo '    }';
    echo '  }';
    echo '  console.log("Fallback toggleHamburgerMenu function loaded");';
    echo '}';
    echo '</script>';
}
?>
