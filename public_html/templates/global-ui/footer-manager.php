<?php
/**
 * Footer Settings Manager
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

// Safe config path retrieval with error handling
try {
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    if (trim($base) === '') {
        $base = '/data';
    }
    $configPath = rtrim($base, '/') . '/global-ui/footer/footer-config.json';
} catch (Exception $e) {
    error_log('Footer Manager - getDataPath error: ' . $e->getMessage());
    $configPath = '/data/global-ui/footer/footer-config.json';
}
$config = [];

// Load existing config
if(file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true) ?: [];
    error_log('Footer Manager - Loaded config keys: ' . implode(', ', array_keys($config)));
}

// Handle file browsing requests for footer - copy from header-manager
if(isset($_GET['browse_files'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    $requestedPath = $_GET['path'] ?? '/';
    $requestedPath = str_replace(['../', '..\\'], '', $requestedPath);
    $requestedPath = ltrim($requestedPath, '/');
    
    $basePath = dirname(dirname(__DIR__));
    $fullPath = $basePath . '/' . $requestedPath;
    
    $response = [];
    
    if(is_dir($fullPath)) {
        $items = scandir($fullPath);
        foreach($items as $item) {
            if($item === '.' || $item === '..') continue;
            
            $itemPath = $fullPath . '/' . $item;
            if(is_dir($itemPath)) {
                if(!in_array($item, ['.cue', '.data', 'gear', 'temp', 'tmp'])) {
                    $response[] = [
                        'type' => 'folder',
                        'name' => $item
                    ];
                }
            } elseif(is_file($itemPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if(in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'])) {
                    $response[] = [
                        'type' => 'file',
                        'name' => $item
                    ];
                }
            }
        }
        
        usort($response, function($a, $b) {
            if($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Directory not found or not accessible']);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// Handle logo file upload
if(isset($_FILES['ftr_logo_image']) && $_FILES['ftr_logo_image']['error'] === UPLOAD_ERR_OK) {
    $uploadError = '';
    $uploadedFile = $_FILES['ftr_logo_image'];
    
    // Validate file size (max 2MB)
    if($uploadedFile['size'] > 2 * 1024 * 1024) {
        $uploadError = 'File size too large. Maximum 2MB allowed.';
    } else {
        // Validate file type
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];
        $finfo = class_exists('finfo') ? new finfo(FILEINFO_MIME_TYPE) : null;
        $mimeType = $finfo ? $finfo->file($uploadedFile['tmp_name']) : mime_content_type($uploadedFile['tmp_name']);
        unset($finfo);
        
        if(in_array($mimeType, $allowedTypes)) {
            // Generate unique filename
            $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $filename = time() . '_footer_logo_' . preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$uploadedFile['name']);
            $uploadDir = (function_exists('getPublicPath') ? getPublicPath() : dirname(dirname(__DIR__))) . '/uploads/logos/';
            
            // Create directory if it doesn't exist
            if(!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $uploadPath = $uploadDir . $filename;
            
            if(move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
                // Set the logo path in POST data for processing
                $_POST['ftr_logo_image_path'] = '/uploads/logos/' . $filename;
                $uploadMessage = '✅ Logo uploaded successfully!';
            } else {
                $uploadError = 'Failed to save uploaded file.';
            }
        } else {
            $uploadError = 'Invalid file type. Only PNG, JPG, GIF, SVG, and WebP are allowed.';
        }
    }
}

// Handle form submission
if(isset($_POST['action']) && $_POST['action'] === 'save_footer') {
    error_log('Footer Manager - Form submission detected, action: ' . $_POST['action']);
    error_log('Footer Manager - POST data keys: ' . implode(', ', array_keys($_POST)));
    
    // Preserve existing configuration ID to maintain consistency
    $existingConfigId = null;
    if (isset($config['K::FooterUI::Configuration'])) {
        $configKeys = array_keys($config['K::FooterUI::Configuration']);
        if (!empty($configKeys)) {
            $existingConfigId = $configKeys[0];
        }
    }
    
    // Use existing ID or create new one only if none exists
    $configId = $existingConfigId ?: 'K::FooterUI::Content::' . strtoupper(uniqid());
    
    $zIndexRaw = trim((string)($_POST['ftr_z_index'] ?? 'auto'));
    $zIndex = 'auto';
    if ($zIndexRaw !== '' && strcasecmp($zIndexRaw, 'auto') !== 0) {
        if (preg_match('/^-?\d+$/', $zIndexRaw)) {
            $zIndex = (int)$zIndexRaw;
        }
    }

    $newConfig = [
        'K::FooterUI::Configuration' => [
            $configId => [
                // Site Identity Configuration - K::FooterUI::SiteTitle::*
                'ftr_site_name_enabled' => isset($_POST['ftr_site_name_enabled']),
                'ftr_site_name_text' => $_POST['ftr_site_name_text'] ?? '',
                'ftr_site_name_font' => $_POST['ftr_site_name_font'] ?? 'Merriweather-Regular',
                'ftr_site_name_font_size' => (int)($_POST['ftr_site_name_font_size'] ?? '18'),
                'ftr_site_name_color' => $_POST['ftr_site_name_color'] ?? '#00ffff',
                'ftr_site_name_opacity' => (int)($_POST['ftr_site_name_opacity'] ?? '100'),
                'ftr_site_name_position' => $_POST['ftr_site_name_position'] ?? 'left',
                
                // Slogan Configuration - K::FooterUI::Slogan::*
                'ftr_slogan_enabled' => isset($_POST['ftr_slogan_enabled']),
                'ftr_slogan_text' => $_POST['ftr_slogan_text'] ?? '',
                'ftr_slogan_font' => $_POST['ftr_slogan_font'] ?? 'Merriweather-Regular',
                'ftr_slogan_size' => (int)($_POST['ftr_slogan_size'] ?? '14'),
                'ftr_slogan_color' => $_POST['ftr_slogan_color'] ?? '#cccccc',
                'ftr_slogan_opacity' => (int)($_POST['ftr_slogan_opacity'] ?? '90'),
                'ftr_slogan_position' => $_POST['ftr_slogan_position'] ?? 'under_site_name',
                'ftr_title_slogan_spacing' => (int)($_POST['ftr_title_slogan_spacing'] ?? '5'),
                
                // Logo Configuration - K::FooterUI::Logo::*
                'ftr_logo_enabled' => isset($_POST['ftr_logo_enabled']),
                'ftr_logo_image_path' => $_POST['ftr_logo_image_path'] ?? '',
                'ftr_logo_width' => (int)($_POST['ftr_logo_width'] ?? '80'),
                'ftr_logo_height' => (int)($_POST['ftr_logo_height'] ?? '80'),
                'ftr_logo_aspect_locked' => isset($_POST['ftr_logo_aspect_locked']),
                'ftr_logo_position' => $_POST['ftr_logo_position'] ?? 'left',
                'ftr_logo_margin_x' => (int)($_POST['ftr_logo_margin_x'] ?? '15'),
                'ftr_logo_margin_y' => (int)($_POST['ftr_logo_margin_y'] ?? '10'),
                'ftr_logo_animation_enabled' => isset($_POST['ftr_logo_animation_enabled']),
                'ftr_logo_animation_type' => $_POST['ftr_logo_animation_type'] ?? 'none',
                'ftr_logo_animation_duration' => (float)($_POST['ftr_logo_animation_duration'] ?? '1.0'),
                'ftr_logo_glow_enabled' => isset($_POST['ftr_logo_glow_enabled']),
                'ftr_logo_glow_color' => $_POST['ftr_logo_glow_color'] ?? '#00d4ff',
                'ftr_logo_glow_intensity' => (int)($_POST['ftr_logo_glow_intensity'] ?? '5'),
                
                // Visual Effects Configuration - K::FooterUI::Effects::*
                'ftr_shadow_enabled' => isset($_POST['ftr_shadow_enabled']),
                'ftr_shadow_color' => $_POST['ftr_shadow_color'] ?? '#000000',
                'ftr_shadow_blur' => (int)($_POST['ftr_shadow_blur'] ?? '4'),
                'ftr_shadow_x' => (int)($_POST['ftr_shadow_x'] ?? '0'),
                'ftr_shadow_y' => (int)($_POST['ftr_shadow_y'] ?? '-2'),
                'ftr_shadow_spread' => (int)($_POST['ftr_shadow_spread'] ?? '0'),
                'ftr_border_enabled' => isset($_POST['ftr_border_enabled']),
                'ftr_border_color' => $_POST['ftr_border_color'] ?? '#00ffff',
                'ftr_border_width' => (int)($_POST['ftr_border_width'] ?? '1'),
                'ftr_border_style' => $_POST['ftr_border_style'] ?? 'solid',
                'ftr_border_radius' => (int)($_POST['ftr_border_radius'] ?? '0'),
                'ftr_glow_enabled' => isset($_POST['ftr_glow_enabled']),
                'ftr_glow_color' => $_POST['ftr_glow_color'] ?? '#00ffff',
                'ftr_glow_intensity' => (int)($_POST['ftr_glow_intensity'] ?? '10'),
                'ftr_glow_size' => (int)($_POST['ftr_glow_size'] ?? '5'),
                
                // Layout & Positioning Configuration - K::FooterUI::Layout::*
                'ftr_footer_height' => (int)($_POST['ftr_footer_height'] ?? '80'),
                'ftr_footer_content_spacing' => (int)($_POST['ftr_footer_content_spacing'] ?? '20'),
                'ftr_padding' => (int)($_POST['ftr_padding'] ?? '15'),
                'ftr_content_alignment' => $_POST['ftr_content_alignment'] ?? 'center',
                'ftr_vertical_alignment' => $_POST['ftr_vertical_alignment'] ?? 'middle',
                'ftr_layout' => $_POST['ftr_layout'] ?? 'horizontal',
                
                // Background & Styling Configuration - K::FooterUI::Background::*
                'ftr_background_type' => $_POST['ftr_background_type'] ?? 'solid',
                'ftr_footer_background_color' => $_POST['ftr_footer_background_color'] ?? '#0a0a1a',
                'ftr_background_opacity' => (int)($_POST['ftr_background_opacity'] ?? '100'),
                
                // Gradient Configuration
                'ftr_gradient_color1' => $_POST['ftr_gradient_color1'] ?? '#1a1a2e',
                'ftr_gradient_color2' => $_POST['ftr_gradient_color2'] ?? '#003344',
                'ftr_gradient_color3' => $_POST['ftr_gradient_color3'] ?? '#0066aa',
                'ftr_gradient_angle' => (int)($_POST['ftr_gradient_angle'] ?? '135'),
                'ftr_gradient_multi_enabled' => isset($_POST['ftr_gradient_multi_enabled']),
                'ftr_gradient_opacity' => (int)($_POST['ftr_gradient_opacity'] ?? '100'),
                
                // Image Background Configuration
                'ftr_background_image_path' => $_POST['ftr_background_image_path'] ?? '',
                'ftr_background_position' => $_POST['ftr_background_position'] ?? 'center',
                'ftr_background_size' => $_POST['ftr_background_size'] ?? 'cover',
                'ftr_background_repeat' => $_POST['ftr_background_repeat'] ?? 'no-repeat',
                
                // Animated Background Configuration
                'ftr_animation_type' => $_POST['ftr_animation_type'] ?? 'none',
                'ftr_animation_color' => $_POST['ftr_animation_color'] ?? '#0066aa',
                'ftr_animation_speed' => (float)($_POST['ftr_animation_speed'] ?? '2.0'),
                'ftr_animation_scale' => (float)($_POST['ftr_animation_scale'] ?? '1.0'),
                'ftr_animation_opacity' => (int)($_POST['ftr_animation_opacity'] ?? '100'),
                'ftr_animation_background_color' => $_POST['ftr_animation_background_color'] ?? '#000000',
                
                // Text and Typography Configuration
                'ftr_text_color' => $_POST['ftr_text_color'] ?? '#00ffff',
                
                // Content Management Configuration - K::FooterUI::Content::*
                'ftr_copyright_text' => $_POST['ftr_copyright_text'] ?? '',
                'ftr_copyright_size' => (int)($_POST['ftr_copyright_size'] ?? '12'),
                'ftr_copyright_size' => (int)($_POST['ftr_copyright_size'] ?? '12'),
                'ftr_copyright_color' => $_POST['ftr_copyright_color'] ?? '#888888',
                'ftr_copyright_position' => $_POST['ftr_copyright_position'] ?? 'bottom-center',
                
                // Links Configuration
                'ftr_quick_links' => $_POST['ftr_quick_links'] ?? '',
                'ftr_link_color' => $_POST['ftr_link_color'] ?? '#00ffff',
                'ftr_link_hover_color' => $_POST['ftr_link_hover_color'] ?? '#ffffff',
                'ftr_links_title_size' => (int)($_POST['ftr_links_title_size'] ?? '16'),
                'ftr_links_title_color' => $_POST['ftr_links_title_color'] ?? '#ffffff',
                

                

                
                // Company Information Configuration
                'ftr_company_name' => $_POST['ftr_company_name'] ?? '',
                'ftr_company_name_size' => (int)($_POST['ftr_company_name_size'] ?? '18'),
                'ftr_company_name_color' => $_POST['ftr_company_name_color'] ?? '#00ffff',
                
                // Advanced Settings Configuration
                'ftr_position' => $_POST['ftr_position'] ?? 'bottom',
                'ftr_z_index' => $zIndex,
                'ftr_auto_offset' => isset($_POST['ftr_auto_offset']),
                'ftr_extra_content_spacing_enabled' => isset($_POST['ftr_extra_content_spacing_enabled']),
                'ftr_extra_content_spacing' => (int)($_POST['ftr_extra_content_spacing'] ?? '0'),
                'ftr_css_classes' => $_POST['ftr_css_classes'] ?? '',
                'ftr_custom_css' => $_POST['ftr_custom_css'] ?? '',
                'ftr_enabled' => isset($_POST['ftr_enabled']),
                'ftr_last_updated' => date('Y-m-d H:i:s')
            ]
        ]
    ];
    

    
    // Manual saves always proceed regardless of content
    
    // Ensure directory exists
    $dir = dirname($configPath);
    if(!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Add debug logging
    error_log('Footer Manager - Saving config with ID: ' . $configId);
    error_log('Footer Manager - Config data: ' . print_r($newConfig, true));
    
    $saveResult = file_put_contents($configPath, json_encode($newConfig, JSON_PRETTY_PRINT));
    error_log('Footer Manager - Save result: ' . ($saveResult !== false ? 'SUCCESS' : 'FAILED'));
    
    if($saveResult !== false) {
        $config = $newConfig;
        
        // Handle manual save success (show message to user)
        $saveMessage = '✅ Footer configuration saved successfully!';
        
    } else {
        $errorMessage = '❌ Error saving footer settings. Please check file permissions.';
    }
}

// Define comprehensive default configuration matching header-manager structure
$defaultConfig = [
    // Site Identity Configuration - K::FooterUI::SiteTitle::*
    'ftr_site_name_enabled' => false,
    'ftr_site_name_text' => '',
    'ftr_site_name_font' => 'Merriweather-Regular',
    'ftr_site_name_font_size' => 18,
    'ftr_site_name_color' => '#00ffff',
    'ftr_site_name_opacity' => 100,
    'ftr_site_name_position' => 'left',
    
    // Slogan Configuration - K::FooterUI::Slogan::*
    'ftr_slogan_enabled' => true,
    'ftr_slogan_text' => 'Building the future, one innovation at a time.',
    'ftr_slogan_font' => 'Merriweather-Regular',
    'ftr_slogan_size' => 14,
    'ftr_slogan_color' => '#cccccc',
    'ftr_slogan_opacity' => 90,
    'ftr_slogan_position' => 'center',
    'ftr_title_slogan_spacing' => 5,
    
    // Logo Configuration - K::FooterUI::Logo::*
    'ftr_logo_enabled' => false,
    'ftr_logo_image_path' => '',
    'ftr_logo_width' => 80,
    'ftr_logo_height' => 80,
    'ftr_logo_aspect_locked' => false,
    'ftr_logo_position' => 'left',
    'ftr_logo_margin_x' => 15,
    'ftr_logo_margin_y' => 10,
    'ftr_logo_animation_enabled' => false,
    'ftr_logo_animation_type' => 'none',
    'ftr_logo_animation_duration' => 1.0,
    'ftr_logo_glow_enabled' => false,
    'ftr_logo_glow_color' => '#00d4ff',
    'ftr_logo_glow_intensity' => 5,
    
    // Visual Effects Configuration - K::FooterUI::Effects::*
    'ftr_shadow_enabled' => false,
    'ftr_shadow_color' => '#000000',
    'ftr_shadow_blur' => 4,
    'ftr_shadow_x' => 0,
    'ftr_shadow_y' => -2,
    'ftr_shadow_spread' => 0,
    'ftr_border_enabled' => false,
    'ftr_border_color' => '#00ffff',
    'ftr_border_width' => 1,
    'ftr_border_style' => 'solid',
    'ftr_border_radius' => 0,
    'ftr_glow_enabled' => false,
    'ftr_glow_color' => '#00ffff',
    'ftr_glow_intensity' => 10,
    'ftr_glow_size' => 5,
    
    // Layout & Positioning Configuration - K::FooterUI::Layout::*
    'ftr_footer_height' => 80,
    'ftr_footer_content_spacing' => 20,
    'ftr_padding' => 15,
    'ftr_content_alignment' => 'center',
    'ftr_vertical_alignment' => 'middle',
    'ftr_layout' => 'horizontal',
    
    // Background & Styling Configuration - K::FooterUI::Background::*
    'ftr_background_type' => 'solid',
    'ftr_footer_background_color' => '#0a0a1a',
    'ftr_background_opacity' => 100,
    
    // Gradient Configuration
    'ftr_gradient_color1' => '#1a1a2e',
    'ftr_gradient_color2' => '#003344',
    'ftr_gradient_color3' => '#0066aa',
    'ftr_gradient_angle' => 135,
    'ftr_gradient_multi_enabled' => false,
    'ftr_gradient_opacity' => 100,
    
    // Image Background Configuration
    'ftr_background_image_path' => '',
    'ftr_background_position' => 'center',
    'ftr_background_size' => 'cover',
    'ftr_background_repeat' => 'no-repeat',
    
    // Animated Background Configuration
    'ftr_animation_type' => 'none',
    'ftr_animation_color' => '#0066aa',
    'ftr_animation_speed' => 2.0,
    'ftr_animation_scale' => 1.0,
    'ftr_animation_opacity' => 100,
    'ftr_animation_background_color' => '#000000',
    
    // Text and Typography Configuration
    'ftr_text_color' => '#00ffff',
    
    // Content Management Configuration - K::FooterUI::Content::*
    'ftr_copyright_text' => '© 2025 CUE Framework. All rights reserved.',
    'ftr_copyright_size' => 12,
    'ftr_copyright_color' => '#cccccc',
    'ftr_copyright_color' => '#888888',
    'ftr_copyright_position' => 'bottom-center',
    
    // Links Configuration
    'ftr_quick_links' => '',
    'ftr_link_color' => '#00ffff',
    'ftr_link_hover_color' => '#ffffff',
    'ftr_links_title_size' => 16,
    'ftr_links_title_color' => '#ffffff',
    
    // Social Media Configuration

    

    
    // Company Information Configuration
    'ftr_company_name' => '',
    'ftr_company_name_size' => 18,
    'ftr_company_name_color' => '#00ffff',
    
    // Advanced Settings Configuration
    'ftr_position' => 'bottom',
    'ftr_z_index' => 'auto',
    'ftr_auto_offset' => true,
    'ftr_extra_content_spacing_enabled' => false,
    'ftr_extra_content_spacing' => 0,
    'ftr_css_classes' => '',
    'ftr_custom_css' => '',
    'ftr_enabled' => true,
    'ftr_last_updated' => date('Y-m-d H:i:s')
];

// Set defaults if config is empty or extract from existing structure
if(empty($config)) {
    $defaultId = 'K::FooterUI::Content::' . strtoupper(uniqid());
    $config = [
        'K::FooterUI::Configuration' => [
            $defaultId => $defaultConfig
        ]
    ];
}

// Extract current config values for form display
$currentConfig = $defaultConfig; // Start with defaults
if (isset($config['K::FooterUI::Configuration'])) {
    $configKeys = array_keys($config['K::FooterUI::Configuration']);
    if (!empty($configKeys)) {
        // Merge saved config with defaults to ensure all keys exist
        $savedConfig = $config['K::FooterUI::Configuration'][$configKeys[0]];
        $currentConfig = array_merge($defaultConfig, $savedConfig);
    }
} else {
    // Legacy format support - merge with defaults
    $currentConfig = array_merge($defaultConfig, $config);
}

// Check if this file is being accessed directly (not included)
$isStandalone = !isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED']);
if ($isStandalone) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Footer Manager - CUE Framework</title>';
    
    // Include complete global UI head components
    include_once getTemplatesPath() . '/global-ui/includes/complete-head.php';
    includeNoticesWidget();
    echo '<style>';
    // Add comprehensive CSS matching header-manager structure
    echo 'body { background: linear-gradient(135deg, #001122, #003344); color: #ffffff; font-family: Arial, sans-serif; margin: 0; padding: 20px; min-height: 100vh; }';
    echo '.page-title { text-align: center; color: #00ffff; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5); font-size: 2.5em; margin: 20px 0; }';
    echo '.form-container { max-width: 1000px; margin: 0 auto; background: rgba(0, 20, 40, 0.8); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; padding: 30px; backdrop-filter: blur(10px); }';
    echo '.form-row { display: flex; gap: 20px; margin-bottom: 25px; align-items: flex-start; } .form-row.single { justify-content: center; }';
    echo '.form-group { flex: 1; min-width: 0; } .form-group.full-width { flex: 100%; } .form-group.half-width { flex: 50%; } .form-group.third-width { flex: 33.333%; }';
    echo '.form-label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff; }';
    echo '.form-input, .form-select, .form-textarea, .number-input { width: 100%; padding: 12px 15px; background: rgba(10, 10, 26, 0.8); color: #00ffff; border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 8px; font-size: 14px; transition: all 0.3s ease; box-sizing: border-box; }';
    echo '.form-input:focus, .form-select:focus, .form-textarea:focus, .number-input:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 15px rgba(0, 255, 255, 0.3); background: rgba(10, 10, 26, 1); }';
    echo '.submit-button { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; padding: 15px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; transition: all 0.3s ease; }';
    echo '.submit-button:hover { background: linear-gradient(135deg, #00d4aa, #0066cc); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 255, 255, 0.3); }';
    echo '.submit-button:disabled { background: #666; cursor: not-allowed; transform: none; }';
    echo '.modern-file-button { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }';
    echo '.browse-server-btn { background: linear-gradient(135deg, #ffa500, #ff8c00); color: #000; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; }';
    echo '.form-checkbox { appearance: none; width: 20px; height: 20px; background: rgba(0, 255, 255, 0.1); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 4px; position: relative; cursor: pointer; }';
    echo '.form-checkbox:checked { background: linear-gradient(135deg, #00ffff, #0080ff); border-color: #00ffff; }';
    echo '.form-checkbox:checked::after { content: "✓"; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #000; font-weight: bold; font-size: 14px; }';
    echo '.form-checkbox-group { display: flex; align-items: center; gap: 10px; padding: 15px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 8px; margin-bottom: 15px; }';
    echo '.checkbox-label { cursor: pointer; user-select: none; display: flex; align-items: center; font-weight: 500; }';
    echo '.color-block-selector { display: flex; align-items: center; gap: 10px; padding: 8px; background: rgba(0, 255, 255, 0.05); border: 1px solid rgba(0, 255, 255, 0.2); border-radius: 6px; }';
    echo '.color-block-info { display: flex; flex-direction: column; } .color-block-label { font-size: 0.8em; color: #ccc; } .color-block-value { font-size: 0.9em; color: #00ffff; font-weight: bold; }';
    echo '.input-group { display: flex; align-items: center; gap: 8px; } .unit-label { color: #00ffff; font-size: 0.9em; font-weight: 500; }';
    echo '.opacity-slider, .range-slider { flex: 1; } .opacity-value, .range-value { color: #00ffff; font-weight: bold; min-width: 40px; text-align: center; }';
    echo '.aspect-lock-btn { background: rgba(0, 255, 255, 0.2); border: 1px solid rgba(0, 255, 255, 0.3); color: #00ffff; padding: 8px 12px; border-radius: 6px; cursor: pointer; transition: all 0.3s ease; }';
    
    // File Explorer Modal Styling
    echo '/* File Explorer Modal Styling */';
    echo '.file-explorer-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); z-index: 999999; }';
    echo '.file-explorer-dialog { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #001122, #003344); border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; width: 80%; max-width: 800px; max-height: 80%; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 255, 255, 0.2); }';
    echo '.file-explorer-header { background: rgba(0, 255, 255, 0.1); padding: 20px; border-bottom: 1px solid rgba(0, 255, 255, 0.2); position: relative; }';
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
    echo '.explorer-btn-primary:disabled { background: #666; cursor: not-allowed; }';
    echo '.explorer-btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.3); }';
    echo '.error-message { color: #ff6666; text-align: center; padding: 20px; }';
    echo '.site-title-section, .tagline-section, .logo-section, .visual-effects-section, .section { background: rgba(0, 255, 255, 0.03); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 12px; padding: 25px; margin-bottom: 20px; }';
    echo '.site-title-section h3, .tagline-section h3, .logo-section h3, .visual-effects-section h3, .section h3 { color: #00ffff; margin: 0 0 20px 0; font-size: 1.3em; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3); border-bottom: 2px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px; }';
    echo '.effect-subsection { margin: 20px 0; padding: 15px; background: rgba(0, 255, 255, 0.02); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 8px; }';
    echo '.effect-subsection h4 { color: #00ffff; margin: 0 0 15px 0; font-size: 1.1em; }';
    echo '.site-name-controls, .slogan-controls, .logo-controls, .shadow-controls, .border-controls, .glow-controls { margin-top: 15px; }';
    echo '.slogan-controls { transition: all 0.3s ease; }';
    echo '.slogan-controls.active, .slogan-controls[style*="display: block"] { display: block !important; }';
    echo '.field-group { margin-bottom: 20px; display: block; }';
    echo '.field-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff; }';
    echo '.field-group .form-help, .field-group small { display: block; margin-top: 5px; font-size: 0.85em; color: rgba(0, 255, 255, 0.7); }';
    echo '.field-group .form-input, .field-group .form-select { background: rgba(0, 0, 0, 0.4); border: 2px solid rgba(0, 255, 255, 0.3); color: #ffffff; }';
    echo '.field-group .form-input:focus, .field-group .form-select:focus { border-color: #00ffff; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3); }';
    echo '.glow-effects-container, .effect-subsection { margin: 15px 0; padding: 15px; background: rgba(0, 255, 255, 0.02); border-radius: 8px; }';
    echo '.tagline-section { display: block !important; }';
    echo '.bg-type-content { margin-top: 15px; } .bg-type-content.hidden { display: none !important; }';
    echo '.modern-color-picker { width: 60px; height: 40px; border-radius: 6px; border: 2px solid rgba(0, 255, 255, 0.3); cursor: pointer; }';
    echo '.file-upload-container { margin: 10px 0; } .file-upload-section { display: flex; flex-direction: column; gap: 10px; }';
    echo '.modern-file-input { display: flex; gap: 10px; align-items: center; } .file-info { font-size: 0.8em; color: #888; }';
    echo '.animation-preview { font-size: 0.8em; color: #888; margin-left: 10px; }';
    echo '.alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid rgba(0, 255, 0, 0.3); color: #00ff00; padding: 15px; border-radius: 8px; margin: 10px 0; }';
    echo '.alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid rgba(255, 0, 0, 0.3); color: #ff6666; padding: 15px; border-radius: 8px; margin: 10px 0; }';
    
    // File Explorer Modal Styles
    echo '.file-explorer-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); }';
    echo '.file-explorer-content { background: linear-gradient(135deg, #0a1a2a, #1a2a3a); margin: 5% auto; padding: 20px; border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 15px; width: 80%; max-width: 800px; max-height: 80%; overflow-y: auto; }';
    echo '.file-explorer-title { color: #00ffff; font-size: 1.2em; font-weight: bold; margin: 0; }';
    echo '.file-explorer-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px; }';
    echo '.file-explorer-path { background: rgba(0, 255, 255, 0.1); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-family: monospace; color: #00ffff; }';
    echo '.file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; margin: 20px 0; }';
    echo '.file-item { background: rgba(0, 255, 255, 0.05); border: 2px solid rgba(0, 255, 255, 0.2); border-radius: 8px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s ease; }';
    echo '.file-item:hover { background: rgba(0, 255, 255, 0.1); border-color: rgba(0, 255, 255, 0.4); transform: translateY(-2px); }';
    echo '.file-item.selected { background: rgba(0, 255, 255, 0.2); border-color: #00ffff; }';
    echo '.file-icon { font-size: 2em; margin-bottom: 5px; }';
    echo '.file-name { font-size: 0.8em; word-break: break-all; color: #00ffff; }';
    echo '.file-controls { display: flex; gap: 10px; margin-top: 20px; justify-content: center; }';
    echo '.explorer-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; transition: all 0.3s ease; }';
    echo '.btn-primary { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; } .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.2); }';
    echo '.explorer-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); }';
    echo '.explorer-btn:disabled { background: #666; cursor: not-allowed; transform: none; }';
    echo '.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #00ffff; box-shadow: 0 0 15px rgba(0, 255, 255, 0.3); background: rgba(10, 10, 26, 1); }';
    echo '.form-checkbox-group { display: flex; align-items: center; gap: 10px; margin: 10px 0; padding: 12px; background: rgba(0, 255, 255, 0.05); border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.1); }';
    echo '.form-checkbox { width: 18px; height: 18px; accent-color: #00ffff; } .checkbox-label { color: #00ffff; font-weight: 500; cursor: pointer; }';
    echo '.section { background: rgba(0, 255, 255, 0.03); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 12px; padding: 25px; margin-bottom: 20px; }';
    echo '.section h3 { color: #00ffff; margin: 0 0 20px 0; font-size: 1.3em; text-shadow: 0 0 10px rgba(0, 255, 255, 0.3); border-bottom: 2px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px; }';
    echo '.field-group { margin-bottom: 20px; } .field-group label { color: #00ffff; display: block; margin-bottom: 8px; font-weight: 500; } .field-group small { color: #aaa; font-size: 0.85em; display: block; margin-top: 5px; }';
    echo '.background-option { background: rgba(0, 255, 255, 0.02); border: 1px solid rgba(0, 255, 255, 0.08); border-radius: 8px; padding: 15px; margin-top: 15px; }';
    echo '.form-actions { display: flex; gap: 15px; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.save-button, .preview-btn { padding: 15px 30px; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 16px; transition: all 0.3s ease; }';
    echo '.save-button { background: linear-gradient(135deg, #00ffff, #0080ff); color: #000; box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); }';
    echo '.save-button:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(0, 255, 255, 0.4); }';
    echo '.preview-btn { background: rgba(0, 255, 255, 0.1); color: #00ffff; border: 1px solid rgba(0, 255, 255, 0.3); }';
    echo '.preview-btn:hover { background: rgba(0, 255, 255, 0.2); }';
    echo '.browse-btn { background: rgba(0, 255, 255, 0.1); color: #00ffff; border: 1px solid rgba(0, 255, 255, 0.3); padding: 8px 15px; border-radius: 5px; margin-left: 10px; cursor: pointer; font-size: 0.9em; }';
    echo '.browse-btn:hover { background: rgba(0, 255, 255, 0.2); }';
    echo '.current-file { color: #aaa; font-size: 0.85em; } .logo-preview { margin-top: 10px; padding: 10px; background: rgba(0, 0, 0, 0.3); border-radius: 5px; }';
    echo '.config-display { background: rgba(10, 10, 26, 0.9); padding: 20px; border-radius: 10px; margin-top: 20px; border: 1px solid rgba(0, 255, 255, 0.2); }';
    echo '.config-display h4 { color: #00ffff; margin-bottom: 15px; font-size: 1.1em; }';
    echo '.config-display pre { color: #00ffff; background: #0a0a1a; padding: 15px; border-radius: 8px; overflow: auto; font-family: "Courier New", monospace; font-size: 13px; line-height: 1.4; border: 1px solid rgba(0, 255, 255, 0.1); }';
    echo '.alert-success { background: rgba(0, 255, 0, 0.1); border: 1px solid rgba(0, 255, 0, 0.3); color: #00ff00; padding: 15px; margin: 10px 0; border-radius: 8px; }';
    echo '.alert-error { background: rgba(255, 0, 0, 0.1); border: 1px solid rgba(255, 0, 0, 0.3); color: #ff6666; padding: 15px; margin: 10px 0; border-radius: 8px; }';
    echo '</style>';
}

// Add CSS overrides for when loaded within Global UI Manager
if (isset($GLOBALS['_GLOBAL_UI_MANAGER_LOADED'])) {
    echo '<style>';
    // Override global-ui-manager form container styles with higher specificity
    echo '.content .form-container { max-width: 1200px !important; background: rgba(0, 0, 0, 0.3) !important; border: none !important; box-shadow: 0 10px 30px rgba(0, 255, 255, 0.2) !important; }';
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
    // Color block selector styling
    echo '.content .color-block-selector { display: flex; align-items: center; gap: 12px; padding: 8px; background: rgba(0, 0, 0, 0.2); border: 2px solid rgba(0, 255, 255, 0.2); border-radius: 8px; transition: all 0.3s ease; }';
    echo '.content .color-block-selector:hover { border-color: rgba(0, 255, 255, 0.4); background: rgba(0, 0, 0, 0.3); }';
    echo '.content .color-block-info { display: flex; flex-direction: column; flex: 1; }';
    echo '.content .color-block-label { color: rgba(255, 255, 255, 0.9); font-size: 0.85em; margin-bottom: 2px; }';
    echo '.content .color-block-value { color: #00ffff; font-family: monospace; font-size: 0.9em; font-weight: 600; }';
    echo '.content input[type="color"] { width: 50px; height: 40px; border: none; border-radius: 6px; cursor: pointer; background: none; }';
    // Save button styling
    echo '.content .save-btn { background: linear-gradient(135deg, #00ff88, #00cc66) !important; color: #000 !important; padding: 15px 30px; border: none; border-radius: 10px; font-size: 16px; font-weight: 700; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; gap: 10px; margin: 30px auto 0; }';
    echo '.content .save-btn:hover { transform: translateY(-3px); box-shadow: 0 8px 30px rgba(0, 255, 136, 0.4); }';
    echo '.content .save-btn:active { transform: translateY(-1px); }';
    echo '.content .save-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }';
    // Section styling to match header manager
    echo '.content .section { background: rgba(0, 255, 255, 0.02); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 12px; padding: 25px; margin-bottom: 25px; }';
    echo '.content .section h3 { color: #00ffff; margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid rgba(0, 255, 255, 0.2); font-size: 1.2em; }';
    // Opacity slider styling
    echo '.content .opacity-slider { flex: 1; margin-right: 10px; }';
    echo '.content .opacity-value { color: #00ffff; font-weight: 600; min-width: 50px; text-align: right; }';
    // Enhanced field-group styling to match form-group
    echo '.content .field-group { margin-bottom: 20px; }';
    echo '.content .field-group label { display: block; margin-bottom: 8px; font-weight: bold; color: #00ffff !important; }';
    echo '.content .field-group .form-input, .content .field-group .form-select { background: rgba(0, 0, 0, 0.4) !important; border: 2px solid rgba(0, 255, 255, 0.3) !important; color: #ffffff !important; }';
    echo '.content .field-group .form-input:focus, .content .field-group .form-select:focus { border-color: #00ffff !important; box-shadow: 0 0 10px rgba(0, 255, 255, 0.3) !important; }';
    echo '.content .field-group small, .content .field-group .form-help { color: rgba(0, 255, 255, 0.7) !important; }';
    // Background option styling
    echo '.content .background-option { margin-top: 15px; }';
    echo '.content .background-option .form-group { background: rgba(0, 255, 255, 0.02); padding: 15px; border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.1); }';
    echo '</style>';
}

// Output dynamic CSS for footer configuration
if ($isStandalone) {
    // Dynamic Footer Configuration CSS
    echo '<style id="footer-dynamic-css">';
    
    // Apply title-slogan spacing
    $titleSloganSpacing = $currentConfig['ftr_title_slogan_spacing'] ?? 5;
    echo '.footer-content .site-name { margin-right: ' . $titleSloganSpacing . 'px; }';
    echo '.footer-content .slogan { margin-left: ' . $titleSloganSpacing . 'px; }';
    
    // Apply footer-content spacing (affects body padding from bottom)
    $footerContentSpacing = $currentConfig['ftr_footer_content_spacing'] ?? 20;
    echo 'body { margin-bottom: ' . $footerContentSpacing . 'px; }';
    echo '.main-content { margin-bottom: ' . $footerContentSpacing . 'px; overflow-x: hidden; width: 100%; box-sizing: border-box; }';
    echo '.footer-container { max-width: 100vw; overflow-x: hidden; box-sizing: border-box; }';
    echo '.footer-content { max-width: 100%; box-sizing: border-box; }';
    
    // Apply slogan position styles
    $sloganPosition = $currentConfig['ftr_slogan_position'] ?? 'center';
    switch($sloganPosition) {
        case 'top':
            echo '.footer-content .slogan { order: -1; margin-bottom: 10px; }';
            break;
        case 'bottom':
            echo '.footer-content .slogan { order: 999; margin-top: 10px; }';
            break;
        case 'under_footer':
            echo '.footer-content .slogan { position: absolute; top: 100%; left: 0; right: 0; margin-top: 10px; }';
            break;
        case 'left':
            echo '.footer-content .slogan { text-align: left; }';
            break;
        case 'right':
            echo '.footer-content .slogan { text-align: right; }';
            break;
        case 'center':
        default:
            echo '.footer-content .slogan { text-align: center; }';
            break;
    }
    
    echo '</style></head><body>';
    
    // Include global UI body start components (header, hamburger)
    include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php';
    
    echo '<div class="main-content" style="padding: 20px; margin-top: 80px;">';
    echo '<h1 style="color: #00ffff; text-align: center; margin-bottom: 30px; text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);">👇 Footer Settings Manager</h1>';
}
?>

<?php
// Display save/error messages
if(isset($saveMessage)) {
    echo '<div class="alert-success">' . $saveMessage . '</div>';
}
if(isset($errorMessage)) {
    echo '<div class="alert-error">' . $errorMessage . '</div>';
}
?>

<form method="post" class="form-container" enctype="multipart/form-data" data-form-container data-component="footer">
    <input type="hidden" name="action" value="save_footer">

    <!-- Site Title Section - Matching Header Manager Structure -->
    <div class="site-title-section">
        <h3>🏢 Site Title Configuration</h3>
        
        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="ftr_site_name_enabled" id="ftr_site_name_enabled" class="form-checkbox" 
                           <?= ($currentConfig['ftr_site_name_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="ftr_site_name_enabled" class="checkbox-label">🏢 Enable Site Name</label>
                </div>
            </div>
        </div>
        
        <div id="ftr-site-name-controls" class="site-name-controls" <?= ($currentConfig['ftr_site_name_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
            <div class="form-row">
                <div class="form-group full-width">
                    <label class="form-label">Site Name Text:</label>
                    <input type="text" name="ftr_site_name_text" value="<?= htmlspecialchars($currentConfig['ftr_site_name_text'] ?? '') ?>" 
                           class="form-input" placeholder="Enter your site name">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🔤 Site Name Font:</label>
                    <select name="ftr_site_name_font" class="form-select">
                        <option value="Merriweather-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Merriweather-Regular' ? 'selected' : '' ?>>Merriweather Regular</option>
                        <option value="Merriweather-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Merriweather-Bold' ? 'selected' : '' ?>>Merriweather Bold</option>
                        <option value="Orbitron-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Orbitron-Regular' ? 'selected' : '' ?>>Orbitron Regular</option>
                        <option value="Orbitron-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Orbitron-Bold' ? 'selected' : '' ?>>Orbitron Bold</option>
                        <option value="Rajdhani-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Regular' ? 'selected' : '' ?>>Rajdhani Regular</option>
                        <option value="Rajdhani-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Bold' ? 'selected' : '' ?>>Rajdhani Bold</option>
                        <option value="Inter-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Inter-Regular' ? 'selected' : '' ?>>Inter Regular</option>
                        <option value="Inter-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Inter-Bold' ? 'selected' : '' ?>>Inter Bold</option>
                        <option value="Lato-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Lato-Regular' ? 'selected' : '' ?>>Lato Regular</option>
                        <option value="Lato-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Lato-Bold' ? 'selected' : '' ?>>Lato Bold</option>
                        <option value="Montserrat-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Montserrat-Regular' ? 'selected' : '' ?>>Montserrat Regular</option>
                        <option value="Montserrat-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Montserrat-Bold' ? 'selected' : '' ?>>Montserrat Bold</option>
                        <option value="Poppins-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Poppins-Regular' ? 'selected' : '' ?>>Poppins Regular</option>
                        <option value="Poppins-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Poppins-Bold' ? 'selected' : '' ?>>Poppins Bold</option>
                        <option value="Roboto-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Roboto-Regular' ? 'selected' : '' ?>>Roboto Regular</option>
                        <option value="Roboto-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Roboto-Bold' ? 'selected' : '' ?>>Roboto Bold</option>
                        <option value="Open-Sans-Regular" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Regular' ? 'selected' : '' ?>>Open Sans Regular</option>
                        <option value="Open-Sans-Bold" <?= ($currentConfig['ftr_site_name_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Bold' ? 'selected' : '' ?>>Open Sans Bold</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">📏 Font Size:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_site_name_font_size" value="<?= htmlspecialchars($currentConfig['ftr_site_name_font_size'] ?? '18') ?>" 
                               min="8" max="72" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📍 Position:</label>
                    <select name="ftr_site_name_position" class="form-select">
                        <option value="left" <?= ($currentConfig['ftr_site_name_position'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= ($currentConfig['ftr_site_name_position'] ?? 'left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= ($currentConfig['ftr_site_name_position'] ?? 'left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">🎨 Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="ftr_site_name_color" value="<?= htmlspecialchars($currentConfig['ftr_site_name_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Site Name Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_site_name_color'] ?? '#00ffff') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">👁️ Opacity:</label>
                    <div class="input-group">
                        <input type="range" name="ftr_site_name_opacity" value="<?= htmlspecialchars($currentConfig['ftr_site_name_opacity'] ?? '100') ?>" 
                               min="0" max="100" step="5" class="opacity-slider" oninput="updateOpacityValue(this)">
                        <span class="opacity-value"><?= htmlspecialchars($currentConfig['ftr_site_name_opacity'] ?? '100') ?>%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Slogan Section -->
    <div class="section slogan-section">
        <h3>💬 Slogan Configuration</h3>
        
        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="ftr_slogan_enabled" id="ftr_slogan_enabled" class="form-checkbox" 
                           <?= ($currentConfig['ftr_slogan_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="ftr_slogan_enabled" class="checkbox-label">💬 Enable Slogan</label>
                </div>
            </div>
        </div>
        
        <!-- Debug: ftr_slogan_enabled = <?= var_export($currentConfig['ftr_slogan_enabled'] ?? false, true) ?> -->
        <div id="ftr-slogan-controls" class="slogan-controls" <?= ($currentConfig['ftr_slogan_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
            <div class="form-row">
                <div class="form-group full-width">
                    <label class="form-label">Slogan Text:</label>
                    <input type="text" name="ftr_slogan_text" value="<?= htmlspecialchars($currentConfig['ftr_slogan_text'] ?? '') ?>" 
                           class="form-input" placeholder="Enter your slogan">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🔤 Slogan Font:</label>
                    <select name="ftr_slogan_font" class="form-select">
                        <option value="Merriweather-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Merriweather-Regular' ? 'selected' : '' ?>>Merriweather Regular</option>
                        <option value="Merriweather-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Merriweather-Bold' ? 'selected' : '' ?>>Merriweather Bold</option>
                        <option value="Orbitron-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Orbitron-Regular' ? 'selected' : '' ?>>Orbitron Regular</option>
                        <option value="Orbitron-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Orbitron-Bold' ? 'selected' : '' ?>>Orbitron Bold</option>
                        <option value="Rajdhani-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Regular' ? 'selected' : '' ?>>Rajdhani Regular</option>
                        <option value="Rajdhani-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Rajdhani-Bold' ? 'selected' : '' ?>>Rajdhani Bold</option>
                        <option value="Inter-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Inter-Regular' ? 'selected' : '' ?>>Inter Regular</option>
                        <option value="Inter-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Inter-Bold' ? 'selected' : '' ?>>Inter Bold</option>
                        <option value="Lato-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Lato-Regular' ? 'selected' : '' ?>>Lato Regular</option>
                        <option value="Lato-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Lato-Bold' ? 'selected' : '' ?>>Lato Bold</option>
                        <option value="Montserrat-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Montserrat-Regular' ? 'selected' : '' ?>>Montserrat Regular</option>
                        <option value="Montserrat-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Montserrat-Bold' ? 'selected' : '' ?>>Montserrat Bold</option>
                        <option value="Poppins-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Poppins-Regular' ? 'selected' : '' ?>>Poppins Regular</option>
                        <option value="Poppins-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Poppins-Bold' ? 'selected' : '' ?>>Poppins Bold</option>
                        <option value="Roboto-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Roboto-Regular' ? 'selected' : '' ?>>Roboto Regular</option>
                        <option value="Roboto-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Roboto-Bold' ? 'selected' : '' ?>>Roboto Bold</option>
                        <option value="Open-Sans-Regular" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Regular' ? 'selected' : '' ?>>Open Sans Regular</option>
                        <option value="Open-Sans-Bold" <?= ($currentConfig['ftr_slogan_font'] ?? 'Merriweather-Regular') === 'Open-Sans-Bold' ? 'selected' : '' ?>>Open Sans Bold</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">📏 Size:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_slogan_size" value="<?= htmlspecialchars($currentConfig['ftr_slogan_size'] ?? '14') ?>" 
                               min="8" max="48" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📍 Position:</label>
                    <select name="ftr_slogan_position" class="form-select">
                        <option value="left" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'right' ? 'selected' : '' ?>>Right</option>
                        <option value="under_site_name" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'under_site_name' ? 'selected' : '' ?>>Under Site Name</option>
                        <option value="top" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'top' ? 'selected' : '' ?>>Top</option>
                        <option value="bottom" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'bottom' ? 'selected' : '' ?>>Bottom</option>
                        <option value="under_footer" <?= ($currentConfig['ftr_slogan_position'] ?? 'under_site_name') === 'under_footer' ? 'selected' : '' ?>>Under Footer</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">↔️ Title-Slogan Spacing:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_title_slogan_spacing" value="<?= htmlspecialchars($currentConfig['ftr_title_slogan_spacing'] ?? '5') ?>" 
                               min="0" max="100" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎨 Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="ftr_slogan_color" value="<?= htmlspecialchars($currentConfig['ftr_slogan_color'] ?? '#cccccc') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Slogan Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_slogan_color'] ?? '#cccccc') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logo Customization Section - Matching Header Manager Structure -->
    <div class="logo-section">
        <h3>🖼️ Logo Customization</h3>
        
        <div class="form-row">
            <div class="form-group full-width">
                <div class="form-checkbox-group">
                    <input type="checkbox" name="ftr_logo_enabled" id="ftr_logo_enabled" class="form-checkbox" 
                           <?= ($currentConfig['ftr_logo_enabled'] ?? false) ? 'checked' : '' ?>>
                    <label for="ftr_logo_enabled" class="checkbox-label">✅ Enable Logo</label>
                </div>
            </div>
        </div>
        
        <div id="ftr-logo-controls" class="logo-controls" <?= ($currentConfig['ftr_logo_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
            <!-- Logo Upload & URL Section -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📁 Upload Logo File:</label>
                    <div class="file-upload-container">
                        <div class="file-upload-section">
                            <div class="modern-file-input">
                                <input type="file" name="ftr_logo_file" id="ftrLogoFile" accept="image/png,image/jpeg,image/jpg,image/svg+xml" style="display: none;">
                                <label for="ftrLogoFile" class="modern-file-button" id="ftrFileLabel">
                                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                                    </svg>
                                    <span id="ftrFileLabelText">Choose File</span>
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
                            <div id="ftrUploadPreview"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🌐 Or Logo URL:</label>
                    <input type="text" name="ftr_logo_image_path" id="ftrLogoUrl" value="<?= htmlspecialchars($currentConfig['ftr_logo_image_path'] ?? '') ?>"
                           class="form-input" placeholder="example.com/logo.png or /path/to/logo.png">
                    <div id="ftrLogoUrlError" style="color: #ff6b6b; font-size: 0.8em; margin-top: 5px; display: none;"></div>
                    <div style="color: #888; font-size: 0.75em; margin-top: 3px;">
                      💡 Accepts: example.com/logo.png, /path/image.jpg, https://site.com/logo.svg
                    </div>
                    <div id="ftrUrlPreview"></div>
                </div>
            </div>
            
            <!-- Logo Size Controls -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📏 Logo Width:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_width" id="ftrLogoWidth" 
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_width'] ?? '80') ?>" 
                               min="0" max="500" step="5" class="number-input">
                        <span class="unit-label">px</span>
                        <button type="button" class="aspect-lock-btn" id="ftrAspectLockBtn" title="Lock Aspect Ratio">🔒</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">📐 Logo Height:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_height" id="ftrLogoHeight"
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_height'] ?? '80') ?>" 
                               min="0" max="500" step="5" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            <input type="hidden" name="ftr_logo_aspect_locked" id="ftrLogoAspectLocked" 
                   value="<?= ($currentConfig['ftr_logo_aspect_locked'] ?? false) ? '1' : '0' ?>">
            
            <!-- Logo Position & Margins -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">📍 Logo Position:</label>
                    <select name="ftr_logo_position" class="form-select">
                        <option value="left" <?= ($currentConfig['ftr_logo_position'] ?? 'left') === 'left' ? 'selected' : '' ?>>Left</option>
                        <option value="center" <?= ($currentConfig['ftr_logo_position'] ?? 'left') === 'center' ? 'selected' : '' ?>>Center</option>
                        <option value="right" <?= ($currentConfig['ftr_logo_position'] ?? 'left') === 'right' ? 'selected' : '' ?>>Right</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">↔️ Horizontal Margin:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_margin_x" id="ftrLogoMarginX"
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_margin_x'] ?? '15') ?>" 
                               min="0" max="100" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">↕️ Vertical Margin:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_margin_y" id="ftrLogoMarginY"
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_margin_y'] ?? '10') ?>" 
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
                        <input type="checkbox" name="ftr_logo_animation_enabled" id="ftrLogoAnimationEnabled" class="form-checkbox"
                               <?= ($currentConfig['ftr_logo_animation_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="ftrLogoAnimationEnabled" class="checkbox-label">✨ Enable Logo Animation</label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🎭 Animation Type:</label>
                    <select name="ftr_logo_animation_type" id="ftrLogoAnimationType" class="form-select">
                        <option value="none" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="pulse" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'pulse' ? 'selected' : '' ?>>Pulse</option>
                        <option value="bounce" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'bounce' ? 'selected' : '' ?>>Bounce</option>
                        <option value="rotate" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'rotate' ? 'selected' : '' ?>>Rotate</option>
                        <option value="wobble" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'wobble' ? 'selected' : '' ?>>Wobble</option>
                        <option value="fade" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'fade' ? 'selected' : '' ?>>Fade</option>
                        <option value="scale" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'scale' ? 'selected' : '' ?>>Scale</option>
                        <option value="glow" <?= ($currentConfig['ftr_logo_animation_type'] ?? 'none') === 'glow' ? 'selected' : '' ?>>Glow</option>
                    </select>
                    <span class="animation-preview" id="ftrAnimationPreview">Preview: <?= htmlspecialchars($currentConfig['ftr_logo_animation_type'] ?? 'none') ?></span>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">⏱️ Animation Duration:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_animation_duration" id="ftrLogoAnimationDuration"
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_animation_duration'] ?? '1.0') ?>" 
                               min="0.1" max="5.0" step="0.1" class="number-input">
                        <span class="unit-label">s</span>
                    </div>
                </div>
                <div class="form-group">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="ftr_logo_glow_enabled" id="ftrLogoGlowEnabled" class="form-checkbox"
                               <?= ($currentConfig['ftr_logo_glow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="ftrLogoGlowEnabled" class="checkbox-label">✨ Enable Logo Glow</label>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🌟 Glow Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="ftr_logo_glow_color" value="<?= htmlspecialchars($currentConfig['ftr_logo_glow_color'] ?? '#00d4ff') ?>" 
                               class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Logo Glow Color</span>
                            <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_logo_glow_color'] ?? '#00d4ff') ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">💫 Glow Intensity:</label>
                    <div class="input-group">
                        <input type="number" name="ftr_logo_glow_intensity" id="ftrLogoGlowIntensity"
                               value="<?= htmlspecialchars($currentConfig['ftr_logo_glow_intensity'] ?? '5') ?>" 
                               min="1" max="20" step="1" class="number-input">
                        <span class="unit-label">px</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Visual Effects Section - Matching Header Manager Structure -->
    <div class="visual-effects-section">
        <h3>✨ Visual Effects</h3>
        
        <!-- Shadow Effects -->
        <div class="effect-subsection">
            <h4>🌑 Shadow Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="ftr_shadow_enabled" id="ftr_shadow_enabled" class="form-checkbox" 
                               <?= ($currentConfig['ftr_shadow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="ftr_shadow_enabled" class="checkbox-label">🌑 Enable Shadow Effects</label>
                    </div>
                </div>
            </div>
            <div id="ftr-shadow-controls" class="shadow-controls" <?= ($currentConfig['ftr_shadow_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">🎨 Shadow Color:</label>
                        <div class="color-block-selector">
                            <input type="color" name="ftr_shadow_color" value="<?= htmlspecialchars($currentConfig['ftr_shadow_color'] ?? '#000000') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                            <div class="color-block-info">
                                <span class="color-block-label">Shadow Color</span>
                                <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_shadow_color'] ?? '#000000') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🌫️ Blur Radius:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_shadow_blur" value="<?= htmlspecialchars($currentConfig['ftr_shadow_blur'] ?? '4') ?>" 
                                   min="0" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">↔️ Horizontal Offset:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_shadow_x" value="<?= htmlspecialchars($currentConfig['ftr_shadow_x'] ?? '0') ?>" 
                                   min="-50" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">↕️ Vertical Offset:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_shadow_y" value="<?= htmlspecialchars($currentConfig['ftr_shadow_y'] ?? '-2') ?>" 
                                   min="-50" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">📏 Spread Radius:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_shadow_spread" value="<?= htmlspecialchars($currentConfig['ftr_shadow_spread'] ?? '0') ?>" 
                                   min="0" max="20" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Border Effects -->
        <div class="effect-subsection">
            <h4>🔲 Border Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="ftr_border_enabled" id="ftr_border_enabled" class="form-checkbox" 
                               <?= ($currentConfig['ftr_border_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="ftr_border_enabled" class="checkbox-label">🔲 Enable Border Effects</label>
                    </div>
                </div>
            </div>
            <div id="ftr-border-controls" class="border-controls" <?= ($currentConfig['ftr_border_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">📏 Border Width:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_border_width" value="<?= htmlspecialchars($currentConfig['ftr_border_width'] ?? '1') ?>" 
                                   min="0" max="20" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🎨 Border Style:</label>
                        <select name="ftr_border_style" class="form-select">
                            <option value="solid" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'solid' ? 'selected' : '' ?>>Solid</option>
                            <option value="dashed" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'dashed' ? 'selected' : '' ?>>Dashed</option>
                            <option value="dotted" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'dotted' ? 'selected' : '' ?>>Dotted</option>
                            <option value="double" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'double' ? 'selected' : '' ?>>Double</option>
                            <option value="groove" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'groove' ? 'selected' : '' ?>>Groove</option>
                            <option value="ridge" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'ridge' ? 'selected' : '' ?>>Ridge</option>
                            <option value="inset" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'inset' ? 'selected' : '' ?>>Inset</option>
                            <option value="outset" <?= ($currentConfig['ftr_border_style'] ?? 'solid') === 'outset' ? 'selected' : '' ?>>Outset</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">🔄 Border Radius:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_border_radius" value="<?= htmlspecialchars($currentConfig['ftr_border_radius'] ?? '0') ?>" 
                                   min="0" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🎨 Border Color:</label>
                        <div class="color-block-selector">
                            <input type="color" name="ftr_border_color" value="<?= htmlspecialchars($currentConfig['ftr_border_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                            <div class="color-block-info">
                                <span class="color-block-label">Border Color</span>
                                <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_border_color'] ?? '#00ffff') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Glow Effects -->
        <div class="effect-subsection glow-effects-container">
            <h4>✨ Advanced Glow Effects</h4>
            <div class="form-row">
                <div class="form-group full-width">
                    <div class="form-checkbox-group">
                        <input type="checkbox" name="ftr_glow_enabled" id="ftr_glow_enabled" class="form-checkbox" 
                               <?= ($currentConfig['ftr_glow_enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="ftr_glow_enabled" class="checkbox-label">✨ Enable Glow Effects</label>
                    </div>
                </div>
            </div>
            <div id="ftr-glow-controls" class="glow-controls" <?= ($currentConfig['ftr_glow_enabled'] ?? false) ? 'style="display: block;"' : 'style="display: none;"' ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">💫 Glow Size:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_glow_size" value="<?= htmlspecialchars($currentConfig['ftr_glow_size'] ?? '5') ?>" 
                                   min="0" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">🔥 Glow Intensity:</label>
                        <div class="input-group">
                            <input type="number" name="ftr_glow_intensity" value="<?= htmlspecialchars($currentConfig['ftr_glow_intensity'] ?? '10') ?>" 
                                   min="1" max="50" step="1" class="number-input">
                            <span class="unit-label">px</span>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">🎨 Glow Color:</label>
                        <div class="color-block-selector">
                            <input type="color" name="ftr_glow_color" value="<?= htmlspecialchars($currentConfig['ftr_glow_color'] ?? '#00ffff') ?>" class="form-input" onchange="updateColorBlockValue(this)">
                            <div class="color-block-info">
                                <span class="color-block-label">Glow Color</span>
                                <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_glow_color'] ?? '#00ffff') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Layout & Positioning Section -->
    <div class="section">
        <h3>📐 Layout & Positioning</h3>
        
        <div class="form-group">
            <label class="form-label">📏 Footer Height:</label>
            <div class="input-group">
                <input type="number" name="ftr_footer_height" value="<?php echo $currentConfig['ftr_footer_height']; ?>" min="30" max="300" class="number-input">
                <span class="unit-label">px</span>
            </div>
            <small class="form-help">Overall footer container height</small>
        </div>

        <div class="field-group">
            <label class="form-label">📐 Footer-Content Spacing:</label>
            <div class="input-group">
                <input type="number" name="ftr_footer_content_spacing" value="<?= htmlspecialchars($currentConfig['ftr_footer_content_spacing'] ?? '20') ?>" 
                       min="0" max="300" step="5" class="number-input">
                <span class="unit-label">px</span>
            </div>
            <small class="form-help">Space between footer content and footer edges</small>
        </div>

        <div class="form-group">
            <label class="form-label">📐 Padding:</label>
            <div class="input-group">
                <input type="number" name="ftr_padding" value="<?php echo $currentConfig['ftr_padding']; ?>" min="0" max="50" class="number-input">
                <span class="unit-label">px</span>
            </div>
            <small class="form-help">Internal padding for footer elements</small>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label class="form-label">📍 Content Alignment:</label>
                <select name="ftr_content_alignment" class="form-select">
                    <option value="left" <?php echo $currentConfig['ftr_content_alignment'] === 'left' ? 'selected' : ''; ?>>Left</option>
                    <option value="center" <?php echo $currentConfig['ftr_content_alignment'] === 'center' ? 'selected' : ''; ?>>Center</option>
                    <option value="right" <?php echo $currentConfig['ftr_content_alignment'] === 'right' ? 'selected' : ''; ?>>Right</option>
                    <option value="space-between" <?php echo $currentConfig['ftr_content_alignment'] === 'space-between' ? 'selected' : ''; ?>>Space Between</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">⬆️ Vertical Alignment:</label>
                <select name="ftr_vertical_alignment" class="form-select">
                    <option value="top" <?php echo $currentConfig['ftr_vertical_alignment'] === 'top' ? 'selected' : ''; ?>>Top</option>
                    <option value="middle" <?php echo $currentConfig['ftr_vertical_alignment'] === 'middle' ? 'selected' : ''; ?>>Middle</option>
                    <option value="bottom" <?php echo $currentConfig['ftr_vertical_alignment'] === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Background & Styling Section -->
    <div class="section">
        <h3>🎨 Background & Styling</h3>
        
        <div class="form-group">
            <label class="form-label">🎨 Background Type:</label>
            <select name="ftr_background_type" onchange="toggleFooterBackgroundOptions()" class="form-select">
                <option value="solid" <?php echo $currentConfig['ftr_background_type'] === 'solid' ? 'selected' : ''; ?>>Solid Color</option>
                <option value="gradient" <?php echo $currentConfig['ftr_background_type'] === 'gradient' ? 'selected' : ''; ?>>Gradient</option>
                <option value="image" <?php echo $currentConfig['ftr_background_type'] === 'image' ? 'selected' : ''; ?>>Background Image</option>
                <option value="animation" <?php echo $currentConfig['ftr_background_type'] === 'animation' ? 'selected' : ''; ?>>Animated Background</option>
            </select>
        </div>

        <!-- Solid Color Options -->
        <div id="solid_footer_options" class="background-option">
            <div class="form-group">
                <label class="form-label">🎨 Footer Background Color:</label>
                <div class="color-block-selector">
                    <input type="color" name="ftr_footer_background_color" value="<?php echo (isset($currentConfig['ftr_footer_background_color']) && $currentConfig['ftr_footer_background_color'] !== '' ? htmlspecialchars($currentConfig['ftr_footer_background_color']) : '#1a1a2e'); ?>" class="form-input" onchange="updateColorBlockValue(this)">
                    <div class="color-block-info">
                        <span class="color-block-label">Background Color</span>
                        <span class="color-block-value"><?php echo (isset($currentConfig['ftr_footer_background_color']) && $currentConfig['ftr_footer_background_color'] !== '' ? htmlspecialchars($currentConfig['ftr_footer_background_color']) : '#1a1a2e'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gradient Options -->
        <div id="gradient_footer_options" class="background-option" style="display: none;">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">🌈 Gradient Start Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="ftr_gradient_start_color" value="<?php echo (isset($currentConfig['ftr_gradient_start_color']) && $currentConfig['ftr_gradient_start_color'] !== '' ? htmlspecialchars($currentConfig['ftr_gradient_start_color']) : '#0a0a1a'); ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">Start Color</span>
                            <span class="color-block-value"><?php echo (isset($currentConfig['ftr_gradient_start_color']) && $currentConfig['ftr_gradient_start_color'] !== '' ? htmlspecialchars($currentConfig['ftr_gradient_start_color']) : '#0a0a1a'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">🌈 Gradient End Color:</label>
                    <div class="color-block-selector">
                        <input type="color" name="ftr_gradient_end_color" value="<?php echo (isset($currentConfig['ftr_gradient_end_color']) && $currentConfig['ftr_gradient_end_color'] !== '' ? htmlspecialchars($currentConfig['ftr_gradient_end_color']) : '#1a1a2e'); ?>" class="form-input" onchange="updateColorBlockValue(this)">
                        <div class="color-block-info">
                            <span class="color-block-label">End Color</span>
                            <span class="color-block-value"><?php echo (isset($currentConfig['ftr_gradient_end_color']) && $currentConfig['ftr_gradient_end_color'] !== '' ? htmlspecialchars($currentConfig['ftr_gradient_end_color']) : '#1a1a2e'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="field-group">
                <label>Gradient Direction:</label>
                <select name="ftr_gradient_direction" class="form-select">
                    <option value="to right" <?php echo $currentConfig['ftr_gradient_direction'] === 'to right' ? 'selected' : ''; ?>>Left to Right</option>
                    <option value="to left" <?php echo $currentConfig['ftr_gradient_direction'] === 'to left' ? 'selected' : ''; ?>>Right to Left</option>
                    <option value="to bottom" <?php echo $currentConfig['ftr_gradient_direction'] === 'to bottom' ? 'selected' : ''; ?>>Top to Bottom</option>
                    <option value="to top" <?php echo $currentConfig['ftr_gradient_direction'] === 'to top' ? 'selected' : ''; ?>>Bottom to Top</option>
                    <option value="45deg" <?php echo $currentConfig['ftr_gradient_direction'] === '45deg' ? 'selected' : ''; ?>>Diagonal (↗)</option>
                    <option value="135deg" <?php echo $currentConfig['ftr_gradient_direction'] === '135deg' ? 'selected' : ''; ?>>Diagonal (↘)</option>
                </select>
            </div>
        </div>

        <!-- Background Image Options -->
        <div id="image_footer_options" class="background-option" style="display: none;">
            <div class="field-group">
                <label>Background Image:</label>
                <input type="file" name="ftr_background_image" accept="image/*" onchange="previewFooterBackgroundImage(this)" class="form-input">
                <button type="button" onclick="browseFooterBackgroundImage()" class="browse-btn">Browse Files</button>
                <?php if($currentConfig['ftr_background_image_path']): ?>
                    <br><small class="current-file">Current: <?php echo htmlspecialchars(basename($currentConfig['ftr_background_image_path'])); ?></small>
                <?php endif; ?>
            </div>
            <div class="field-group">
                <label>Image Position:</label>
                <select name="ftr_background_position" class="form-select">
                    <option value="center" <?php echo $currentConfig['ftr_background_position'] === 'center' ? 'selected' : ''; ?>>Center</option>
                    <option value="top" <?php echo $currentConfig['ftr_background_position'] === 'top' ? 'selected' : ''; ?>>Top</option>
                    <option value="bottom" <?php echo $currentConfig['ftr_background_position'] === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                    <option value="left" <?php echo $currentConfig['ftr_background_position'] === 'left' ? 'selected' : ''; ?>>Left</option>
                    <option value="right" <?php echo $currentConfig['ftr_background_position'] === 'right' ? 'selected' : ''; ?>>Right</option>
                </select>
            </div>
            <div class="field-group">
                <label>Image Size:</label>
                <select name="ftr_background_size" class="form-select">
                    <option value="cover" <?php echo $currentConfig['ftr_background_size'] === 'cover' ? 'selected' : ''; ?>>Cover</option>
                    <option value="contain" <?php echo $currentConfig['ftr_background_size'] === 'contain' ? 'selected' : ''; ?>>Contain</option>
                    <option value="auto" <?php echo $currentConfig['ftr_background_size'] === 'auto' ? 'selected' : ''; ?>>Original Size</option>
                    <option value="100% 100%" <?php echo $currentConfig['ftr_background_size'] === '100% 100%' ? 'selected' : ''; ?>>Stretch</option>
                </select>
            </div>
            <div class="field-group">
                <label>Image Repeat:</label>
                <select name="ftr_background_repeat" class="form-select">
                    <option value="no-repeat" <?php echo $currentConfig['ftr_background_repeat'] === 'no-repeat' ? 'selected' : ''; ?>>No Repeat</option>
                    <option value="repeat" <?php echo $currentConfig['ftr_background_repeat'] === 'repeat' ? 'selected' : ''; ?>>Repeat</option>
                    <option value="repeat-x" <?php echo $currentConfig['ftr_background_repeat'] === 'repeat-x' ? 'selected' : ''; ?>>Repeat Horizontally</option>
                    <option value="repeat-y" <?php echo $currentConfig['ftr_background_repeat'] === 'repeat-y' ? 'selected' : ''; ?>>Repeat Vertically</option>
                </select>
            </div>
        </div>

        <!-- Animation Options -->
        <div id="animation_footer_options" class="background-option" style="display: none;">
            <div class="field-group">
                <label>Animation Type:</label>
                <select name="ftr_animation_type" class="form-select">
                    <option value="waves" <?php echo $currentConfig['ftr_animation_type'] === 'waves' ? 'selected' : ''; ?>>Waves</option>
                    <option value="birds" <?php echo $currentConfig['ftr_animation_type'] === 'birds' ? 'selected' : ''; ?>>Birds</option>
                    <option value="halo" <?php echo $currentConfig['ftr_animation_type'] === 'halo' ? 'selected' : ''; ?>>Halo</option>
                    <option value="net" <?php echo $currentConfig['ftr_animation_type'] === 'net' ? 'selected' : ''; ?>>Network</option>
                    <option value="dots" <?php echo $currentConfig['ftr_animation_type'] === 'dots' ? 'selected' : ''; ?>>Dots</option>
                    <option value="fog" <?php echo $currentConfig['ftr_animation_type'] === 'fog' ? 'selected' : ''; ?>>Fog</option>
                    <option value="cells" <?php echo $currentConfig['ftr_animation_type'] === 'cells' ? 'selected' : ''; ?>>Cells</option>
                    <option value="clouds" <?php echo $currentConfig['ftr_animation_type'] === 'clouds' ? 'selected' : ''; ?>>Clouds</option>
                    <option value="rings" <?php echo $currentConfig['ftr_animation_type'] === 'rings' ? 'selected' : ''; ?>>Rings</option>
                    <option value="ripple" <?php echo $currentConfig['ftr_animation_type'] === 'ripple' ? 'selected' : ''; ?>>Ripple</option>
                    <option value="topology" <?php echo $currentConfig['ftr_animation_type'] === 'topology' ? 'selected' : ''; ?>>Topology</option>
                </select>
            </div>
            <div class="field-group">
                <label>Animation Color:</label>
                <input type="color" name="ftr_animation_color" value="<?php echo (isset($currentConfig['ftr_animation_color']) && $currentConfig['ftr_animation_color'] !== '' ? htmlspecialchars($currentConfig['ftr_animation_color']) : '#00ffff'); ?>" class="form-input">
            </div>
            <div class="field-group">
                <label>Animation Background Color:</label>
                <input type="color" name="ftr_animation_background_color" value="<?php echo (isset($currentConfig['ftr_animation_background_color']) && $currentConfig['ftr_animation_background_color'] !== '' ? htmlspecialchars($currentConfig['ftr_animation_background_color']) : '#0a0a1a'); ?>" class="form-input">
            </div>
            <div class="field-group">
                <label>Animation Speed:</label>
                <select name="ftr_animation_speed" class="form-select">
                    <option value="0.5" <?php echo $currentConfig['ftr_animation_speed'] === '0.5' ? 'selected' : ''; ?>>Very Slow</option>
                    <option value="1.0" <?php echo $currentConfig['ftr_animation_speed'] === '1.0' ? 'selected' : ''; ?>>Slow</option>
                    <option value="1.5" <?php echo $currentConfig['ftr_animation_speed'] === '1.5' ? 'selected' : ''; ?>>Normal</option>
                    <option value="2.0" <?php echo $currentConfig['ftr_animation_speed'] === '2.0' ? 'selected' : ''; ?>>Fast</option>
                    <option value="3.0" <?php echo $currentConfig['ftr_animation_speed'] === '3.0' ? 'selected' : ''; ?>>Very Fast</option>
                </select>
            </div>
        </div>

        <!-- Effects & Visual Enhancements -->
        <div class="field-group">
            <label>Text Color:</label>
            <input type="color" name="ftr_text_color" value="<?php echo (isset($currentConfig['ftr_text_color']) && $currentConfig['ftr_text_color'] !== '' ? htmlspecialchars($currentConfig['ftr_text_color']) : '#ffffff'); ?>" class="form-input">
        </div>

        <div class="field-group">
            <label>Shadow Effect:</label>
            <select name="ftr_shadow_effect" class="form-select">
                <option value="none" <?php echo $currentConfig['ftr_shadow_effect'] === 'none' ? 'selected' : ''; ?>>No Shadow</option>
                <option value="light" <?php echo $currentConfig['ftr_shadow_effect'] === 'light' ? 'selected' : ''; ?>>Light Shadow</option>
                <option value="medium" <?php echo $currentConfig['ftr_shadow_effect'] === 'medium' ? 'selected' : ''; ?>>Medium Shadow</option>
                <option value="heavy" <?php echo $currentConfig['ftr_shadow_effect'] === 'heavy' ? 'selected' : ''; ?>>Heavy Shadow</option>
            </select>
        </div>

        <div class="field-group">
            <label>Border Style:</label>
            <select name="ftr_border_style" class="form-select">
                <option value="none" <?php echo $currentConfig['ftr_border_style'] === 'none' ? 'selected' : ''; ?>>No Border</option>
                <option value="solid" <?php echo $currentConfig['ftr_border_style'] === 'solid' ? 'selected' : ''; ?>>Solid</option>
                <option value="dashed" <?php echo $currentConfig['ftr_border_style'] === 'dashed' ? 'selected' : ''; ?>>Dashed</option>
                <option value="dotted" <?php echo $currentConfig['ftr_border_style'] === 'dotted' ? 'selected' : ''; ?>>Dotted</option>
            </select>
        </div>

        <div class="field-group">
            <label>Border Color:</label>
            <input type="color" name="ftr_border_color" value="<?php echo (isset($currentConfig['ftr_border_color']) && $currentConfig['ftr_border_color'] !== '' ? htmlspecialchars($currentConfig['ftr_border_color']) : '#00ffff'); ?>" class="form-input">
        </div>

        <div class="field-group">
            <label>Border Width (px):</label>
            <input type="number" name="ftr_border_width" value="<?php echo $currentConfig['ftr_border_width']; ?>" min="0" max="10" class="form-input">
        </div>
    </div>

    <!-- Content Management Section -->
    <div class="section">
        <h3>📝 Content Management</h3>
        
        <div class="field-group">
            <label>©️ Copyright Text:</label>
            <input type="text" name="ftr_copyright_text" value="<?php echo htmlspecialchars($currentConfig['ftr_copyright_text']); ?>" 
                   class="form-input" placeholder="© 2025 Your Company. All rights reserved.">
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">📏 Copyright Size:</label>
                <div class="input-group">
                    <input type="number" name="ftr_copyright_size" value="<?php echo htmlspecialchars($currentConfig['ftr_copyright_size'] ?? '12'); ?>" 
                           min="8" max="72" step="1" class="number-input">
                    <span class="unit-label">px</span>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">🎨 Copyright Color:</label>
                <div class="color-block-selector">
                    <input type="color" name="ftr_copyright_color" value="<?= htmlspecialchars($currentConfig['ftr_copyright_color'] ?? '#cccccc') ?>" 
                           class="form-input" onchange="updateColorBlockValue(this)">
                    <div class="color-block-info">
                        <span class="color-block-label">Copyright Color</span>
                        <span class="color-block-value"><?= htmlspecialchars($currentConfig['ftr_copyright_color'] ?? '#cccccc') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="field-group">
            <label>📍 Copyright Position:</label>
            <select name="ftr_copyright_position" class="form-select">
                <option value="bottom-left" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'bottom-left' ? 'selected' : ''; ?>>Bottom Left</option>
                <option value="bottom-center" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'bottom-center' ? 'selected' : ''; ?>>Bottom Center</option>
                <option value="bottom-right" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'bottom-right' ? 'selected' : ''; ?>>Bottom Right</option>
                <option value="top-left" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'top-left' ? 'selected' : ''; ?>>Top Left</option>
                <option value="top-center" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'top-center' ? 'selected' : ''; ?>>Top Center</option>
                <option value="top-right" <?php echo ($currentConfig['ftr_copyright_position'] ?? 'bottom-center') === 'top-right' ? 'selected' : ''; ?>>Top Right</option>
            </select>
            <small>Choose where to position the copyright text within the footer</small>
        </div>






    </div>

    <!-- Advanced Settings Section -->
    <div class="section">
        <h3>⚙️ Advanced Settings</h3>
        
        <div class="field-group">
            <label>Footer Position:</label>
            <select name="ftr_position" class="form-select">
                <option value="bottom" <?php echo $currentConfig['ftr_position'] === 'bottom' ? 'selected' : ''; ?>>Bottom</option>
                <option value="relative" <?php echo $currentConfig['ftr_position'] === 'relative' ? 'selected' : ''; ?>>Relative</option>
                <option value="fixed" <?php echo $currentConfig['ftr_position'] === 'fixed' ? 'selected' : ''; ?>>Fixed Bottom</option>
                <option value="sticky" <?php echo $currentConfig['ftr_position'] === 'sticky' ? 'selected' : ''; ?>>Sticky Bottom</option>
                <option value="absolute" <?php echo $currentConfig['ftr_position'] === 'absolute' ? 'selected' : ''; ?>>Absolute Bottom</option>
            </select>
        </div>

        <div class="field-group">
            <label>Z-Index:</label>
            <input type="text" name="ftr_z_index" value="<?php echo htmlspecialchars((string)($currentConfig['ftr_z_index'] ?? 'auto')); ?>" class="form-input" placeholder="auto">
            <small>Use auto, a positive number, or a negative number</small>
        </div>

        <div class="field-group">
            <label class="checkbox-label">
                <input type="checkbox" name="ftr_auto_offset" value="1" <?php echo (($currentConfig['ftr_auto_offset'] ?? true) ? 'checked' : ''); ?>>
                <span class="checkmark"></span>
                Auto Content Offset
            </label>
            <small>Automatically adds spacing so content never sits under the footer when positioned</small>
        </div>

        <div class="field-group">
            <label class="checkbox-label">
                <input type="checkbox" name="ftr_extra_content_spacing_enabled" value="1" <?php echo (($currentConfig['ftr_extra_content_spacing_enabled'] ?? false) ? 'checked' : ''); ?>>
                <span class="checkmark"></span>
                Enable Extra Content Spacing
            </label>
            <small>Adds additional bottom spacing to prevent footer overlap on dense pages</small>
        </div>

        <div class="field-group">
            <label>Extra Content Spacing (px):</label>
            <div class="input-group">
                <input type="number" name="ftr_extra_content_spacing" value="<?php echo htmlspecialchars((string)($currentConfig['ftr_extra_content_spacing'] ?? 0)); ?>" min="0" max="500" step="5" class="number-input">
                <span class="unit-label">px</span>
            </div>
        </div>





        <div class="field-group">
            <label class="checkbox-label">
                <input type="checkbox" name="ftr_enabled" value="1" <?php echo $currentConfig['ftr_enabled'] ? 'checked' : ''; ?>>
    </div>

    <div class="form-actions">
        <button type="submit" class="save-button" id="saveFooterBtn">
            <span class="btn-text">💾 Save Footer Configuration</span>
            <span class="btn-spinner" style="display: none;">⏳ Saving...</span>
        </button>
        
        <button type="button" class="preview-btn" onclick="refreshFooterPreview()" style="background: linear-gradient(135deg, #00aa55, #00cc66); color: #000; padding: 15px 30px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 16px; transition: all 0.3s ease; margin-left: 15px;">
            🔄 Refresh Preview
        </button>
        

    </div>
</form>

<!-- File Explorer Modal -->
<div id="fileExplorerModal" class="file-explorer-modal" style="display: none !important; position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(0, 0, 0, 0.8) !important; z-index: 9999999 !important; margin: 0 !important; padding: 0 !important;">
    <div class="file-explorer-content" style="position: fixed !important; top: 50% !important; left: 50% !important; transform: translate(-50%, -50%) !important; background: linear-gradient(135deg, #0a1a2a, #1a2a3a) !important; padding: 20px !important; border: 2px solid rgba(0, 255, 255, 0.3) !important; border-radius: 15px !important; width: 80vw !important; max-width: 800px !important; max-height: 80vh !important; overflow-y: auto !important; box-sizing: border-box !important; margin: 0 !important;">
        <div class="file-explorer-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid rgba(0, 255, 255, 0.2); padding-bottom: 10px;">
            <h3 class="file-explorer-title" style="color: #00ffff; font-size: 1.2em; font-weight: bold; margin: 0;">📁 Server File Browser</h3>
            <button onclick="closeFileExplorer()" style="background: rgba(255, 0, 0, 0.7); color: white; border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center;">&times;</button>
        </div>
        <div class="file-explorer-path" id="currentPath" style="background: rgba(0, 255, 255, 0.1); padding: 10px; border-radius: 6px; margin-bottom: 15px; font-family: monospace; color: #00ffff;">📍 /</div>
        <div class="file-grid" id="fileGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 15px; margin: 20px 0; min-height: 200px;">
            <!-- Files will be loaded here -->
        </div>
        <div class="explorer-buttons" style="text-align: right; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(0, 255, 255, 0.2);">
            <button onclick="closeFileExplorer()" style="background: rgba(255, 255, 255, 0.1); color: #00ffff; padding: 10px 20px; border: 1px solid rgba(0, 255, 255, 0.3); border-radius: 5px; cursor: pointer; margin-right: 10px;">Cancel</button>
            <button onclick="selectFile()" id="selectBtn" disabled style="background: #666; color: #ccc; padding: 10px 20px; border: none; border-radius: 5px; cursor: not-allowed;">Select File</button>
        </div>
    </div>
</div>

<script type="text/javascript">
// Isolated Footer File Explorer - No PHP interference
(function() {
    'use strict';

    var footerExplorer = {
        selectedFile: null,
        
        init: function() {
            window.openFileExplorer = this.openFileExplorer.bind(this);
            window.closeFileExplorer = this.closeFileExplorer.bind(this);
            window.loadDirectory = this.loadDirectory.bind(this);
            window.selectFileItem = this.selectFileItem.bind(this);
            window.selectFile = this.selectFile.bind(this);
            console.log('Footer file explorer ready');
        },
        
        openFileExplorer: function() {
            console.log('Footer file explorer opened');
            var modal = document.getElementById("fileExplorerModal");
            if (modal) {
                // Ensure modal is attached to body and not affected by parent containers
                if (modal.parentNode !== document.body) {
                    document.body.appendChild(modal);
                }
                
                // Force positioning styles to ensure it appears in viewport
                modal.style.cssText = 'display: block !important; position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; background: rgba(0, 0, 0, 0.8) !important; z-index: 9999999 !important; margin: 0 !important; padding: 0 !important;';
                
                // Ensure content is properly centered
                var content = modal.querySelector('.file-explorer-content');
                if (content) {
                    content.style.cssText = 'position: fixed !important; top: 50% !important; left: 50% !important; transform: translate(-50%, -50%) !important; background: linear-gradient(135deg, #0a1a2a, #1a2a3a) !important; padding: 20px !important; border: 2px solid rgba(0, 255, 255, 0.3) !important; border-radius: 15px !important; width: 80vw !important; max-width: 800px !important; max-height: 80vh !important; overflow-y: auto !important; box-sizing: border-box !important; margin: 0 !important;';
                }
                
                this.loadDirectory("/");
            }
        }

        ,
        
        closeFileExplorer: function() {
            var modal = document.getElementById("fileExplorerModal");
            if (modal) {
                modal.style.display = "none";
            }
            this.selectedFile = null;
        },
        
        loadDirectory: function(path) {
            var pathElement = document.getElementById("currentPath");
            if (pathElement) {
                pathElement.textContent = "📍 " + path;
            }
            
            var grid = document.getElementById("fileGrid");
            if (!grid) return;
            
            var isGlobalUI = window.location.href.indexOf('global-ui-manager.php') !== -1;
            var url = isGlobalUI 
                ? '/templates/global-ui/footer-manager.php?browse_files=1&path=' + encodeURIComponent(path)
                : '?browse_files=1&path=' + encodeURIComponent(path);
            
            var self = this;
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        self.renderGrid(data, path, grid);
                    } catch (e) {
                        grid.innerHTML = '<div style="color:red;padding:20px;text-align:center;">Parse error</div>';
                    }
                }
            };
            xhr.send();
        },
        
        renderGrid: function(data, path, grid) {
            var self = this;
            grid.innerHTML = "";
            
            if (path !== "/") {
                var back = document.createElement("div");
                back.className = "file-item back-item";
                back.style.cssText = "background: rgba(0,255,255,0.1); border: 2px solid rgba(0,255,255,0.3); border-radius: 8px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s ease; margin: 5px;";
                back.innerHTML = '<div style="font-size: 2em; margin-bottom: 5px;">⬆️</div><div style="color: #00ffff; font-size: 0.9em; font-weight: bold;">.. Back</div>';
                back.onmouseover = function() { 
                    this.style.background = 'rgba(0,255,255,0.2)'; 
                    this.style.borderColor = 'rgba(0,255,255,0.5)'; 
                };
                back.onmouseout = function() { 
                    this.style.background = 'rgba(0,255,255,0.1)'; 
                    this.style.borderColor = 'rgba(0,255,255,0.3)'; 
                };
                back.onclick = function() { 
                    var parentPath = path.substring(0, path.lastIndexOf("/")) || "/";
                    self.loadDirectory(parentPath); 
                };
                grid.appendChild(back);
            }
            
            if (Array.isArray(data)) {
                data.forEach(function(item) {
                    var elem = document.createElement("div");
                    elem.className = "file-item";
                    elem.style.cssText = "background: rgba(0,255,255,0.05); border: 2px solid rgba(0,255,255,0.2); border-radius: 8px; padding: 15px; text-align: center; cursor: pointer; transition: all 0.3s ease; margin: 5px; word-wrap: break-word;";
                    
                    if (item.type === "folder") {
                        elem.innerHTML = '<div style="font-size: 2em; margin-bottom: 5px;">📁</div><div style="color: #00ffff; font-size: 0.9em;">' + item.name + '</div>';
                        elem.onmouseover = function() { 
                            this.style.background = 'rgba(0,255,255,0.1)'; 
                            this.style.borderColor = 'rgba(0,255,255,0.4)'; 
                        };
                        elem.onmouseout = function() { 
                            this.style.background = 'rgba(0,255,255,0.05)'; 
                            this.style.borderColor = 'rgba(0,255,255,0.2)'; 
                        };
                        elem.onclick = function() { 
                            var newPath = path + (path.endsWith("/") ? "" : "/") + item.name;
                            self.loadDirectory(newPath); 
                        };
                    } else {
                        elem.innerHTML = '<div style="font-size: 2em; margin-bottom: 5px;">🖼️</div><div style="color: #00ffff; font-size: 0.9em;">' + item.name + '</div>';
                        elem.onmouseover = function() { 
                            if (!this.classList.contains('selected')) {
                                this.style.background = 'rgba(0,255,255,0.1)'; 
                                this.style.borderColor = 'rgba(0,255,255,0.4)'; 
                            }
                        };
                        elem.onmouseout = function() { 
                            if (!this.classList.contains('selected')) {
                                this.style.background = 'rgba(0,255,255,0.05)'; 
                                this.style.borderColor = 'rgba(0,255,255,0.2)'; 
                            }
                        };
                        elem.onclick = function() { 
                            var fullPath = path + (path.endsWith("/") ? "" : "/") + item.name;
                            self.selectFileItem(elem, fullPath); 
                        };
                    }
                    grid.appendChild(elem);
                });
            }
        },
        
        selectFileItem: function(element, filepath) {
            // Remove selected class and styling from all items
            var items = document.querySelectorAll('.file-item');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.remove('selected');
                if (!items[i].classList.contains('back-item')) {
                    items[i].style.background = 'rgba(0,255,255,0.05)';
                    items[i].style.border = '2px solid rgba(0,255,255,0.2)';
                }
            }
            
            // Add selected styling to clicked element
            element.classList.add('selected');
            element.style.background = 'rgba(0,255,255,0.3)';
            element.style.border = '2px solid #00ffff';
            this.selectedFile = filepath;
            
            // Enable and style the select button
            var btn = document.getElementById("selectBtn");
            if (btn) {
                btn.disabled = false;
                btn.style.background = '#00ffff';
                btn.style.color = '#000';
                btn.style.cursor = 'pointer';
            }
        },
        
        selectFile: function() {
            if (this.selectedFile) {
                var input = document.getElementById("ftrLogoUrl");
                if (input) {
                    input.value = this.selectedFile;
                    var event = document.createEvent('Event');
                    event.initEvent('change', true, true);
                    input.dispatchEvent(event);
                }
                this.closeFileExplorer();
            }
        }
    };
    
    // Initialize the file explorer
    footerExplorer.init();
    
})();
</script>





<div class="config-display" style="margin-top: 30px; padding: 20px; background: rgba(0, 255, 255, 0.03); border: 1px solid rgba(0, 255, 255, 0.1); border-radius: 12px;">
    <h4 style="color: #00ffff; margin: 0 0 15px 0;">⚙️ Current Configuration</h4>
    <details style="margin-top: 10px;">
        <summary style="color: #00ffff; cursor: pointer; padding: 5px 0;">📋 Show/Hide Configuration Data</summary>
        <pre style="background: rgba(0, 0, 0, 0.3); padding: 15px; border-radius: 6px; color: #ccc; font-size: 12px; overflow-x: auto; margin-top: 10px;"><?= json_encode($config, JSON_PRETTY_PRINT) ?></pre>
    </details>
</div>

<?php
if ($isStandalone) {
    echo '</div>'; // Close main-content div
    
    // Include global UI body end components (footer, scripts)
    include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php';
    echo '</body></html>';
}
?>
