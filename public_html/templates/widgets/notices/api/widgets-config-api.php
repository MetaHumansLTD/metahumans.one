<?php
/**
 * Notices Widget Configuration API
 * CUE Framework 100.0.99 - Modular Widget System
 * Provides JSON configuration access for PopupNotice widget
 */

// Load the CUE framework
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/.cue/cue.php';

// Set proper headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'get_config';
    
    // Get the notices widget configuration file path
    $widgetsDataPath = getDataPath() . '/widgets/notices/widgets-config.json';
    
    // Configuration file path determined by CUE framework
    
    switch ($action) {
        case 'get_config':
            if (file_exists($widgetsDataPath)) {
                $configData = json_decode(file_get_contents($widgetsDataPath), true);
                
                if ($configData) {
                    // The file structure we found has the config directly, not under popup_notice
                    $config = [
                        'position' => $configData['position'] ?? 'center',
                        'theme' => $configData['theme'] ?? 'dark',
                        'duration' => $configData['duration'] ?? 5000,
                        'stackNotifications' => $configData['stackNotifications'] ?? true,
                        'maxStack' => $configData['maxStack'] ?? 5
                    ];
                    
                    echo json_encode([
                        'success' => true,
                        'config' => $config,
                        'source' => 'json_file'
                    ]);
                } else {
                    // Return default configuration if no valid JSON found
                    echo json_encode([
                        'success' => true,
                        'config' => [
                            'position' => 'center',
                            'theme' => 'dark',
                            'duration' => 5000,
                            'stackNotifications' => true,
                            'maxStack' => 5
                        ],
                        'source' => 'default_fallback_invalid_json'
                    ]);
                }
            } else {
                // Return default configuration if file doesn't exist
                echo json_encode([
                    'success' => true,
                    'config' => [
                        'position' => 'center',
                        'theme' => 'dark',
                        'duration' => 5000,
                        'stackNotifications' => true,
                        'maxStack' => 5
                    ],
                    'source' => 'default_no_file'
                ]);
            }
            break;
            
        case 'save_config':
            $config = $input['config'] ?? [];
            
            // Load existing configuration
            $configData = [];
            if (file_exists($widgetsDataPath)) {
                $configData = json_decode(file_get_contents($widgetsDataPath), true) ?: [];
            }
            
            // Update popup_notice configuration
            $configData['popup_notice'] = $config;
            
            // Save the updated configuration
            $saveResult = file_put_contents($widgetsDataPath, json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            
            if ($saveResult !== false) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Configuration saved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to save configuration'
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action: ' . $action
            ]);
            break;
    }
    
} catch (Exception $e) {
    // Error handling
    echo json_encode([
        'success' => false,
        'message' => 'API Error: ' . $e->getMessage(),
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}
?>