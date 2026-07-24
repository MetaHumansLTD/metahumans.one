<?php
/**
 * Clean Real-time Sync API - No Framework Dependencies
 * Handles immediate JSON file updates for UI synchronization
 */

// Prevent any output
ob_start();

// Disable error output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

// Set JSON headers immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only POST/GET
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get parameters
$action = $_POST['action'] ?? $_GET['action'] ?? 'ping';
$componentType = $_POST['component_type'] ?? $_GET['component_type'] ?? '';

/**
 * Get configuration file path
 */
function getSyncConfigPath($componentType) {
    $basePath = dirname(__DIR__, 4) . '/.data/global-ui';
    
    // Special case for navigation - header.php expects 'menu-config.json'
    if ($componentType === 'navigation') {
        return $basePath . '/' . $componentType . '/menu-config.json';
    }
    
    return $basePath . '/' . $componentType . '/' . $componentType . '-config.json';
}

/**
 * Sync configuration to JSON file and database - AGGRESSIVE CACHE CLEARING
 */
function syncToFile($componentType, $settings) {
    $configFile = getSyncConfigPath($componentType);
    $configDir = dirname($configFile);
    
    // Create directory if needed
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    
    // AGGRESSIVE cache clearing before read
    clearstatcache(true);
    clearstatcache(true, $configFile);
    
    // Read existing config
    $existingConfig = [];
    if (file_exists($configFile)) {
        $content = file_get_contents($configFile);
        $existingConfig = json_decode($content, true) ?? [];
    }
    
    // Merge settings - FORCE overwrite of critical fields
    $mergedConfig = array_merge($existingConfig, $settings);
    $mergedConfig['last_updated'] = date('Y-m-d\TH:i:s\Z');
    $mergedConfig['version'] = '1.0.0';
    $mergedConfig['force_cache_bust'] = time(); // Force cache invalidation
    
    // FORCE write with exclusive lock
    $result = file_put_contents($configFile, json_encode($mergedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    
    // AGGRESSIVE cache clearing after write
    clearstatcache(true);
    clearstatcache(true, $configFile);
    
    // Verify write succeeded
    $verifyContent = file_get_contents($configFile);
    $verifyData = json_decode($verifyContent, true);
    
    error_log("SYNC FORCED WRITE - Component: $componentType, File: $configFile, Bytes: $result, Verified Position: " . ($verifyData['hamburger_position'] ?? 'NOT SET'));
    
    return [
        'success' => $result !== false && isset($verifyData['hamburger_position']),
        'file' => $configFile,
        'bytes_written' => $result ?: 0,
        'verified_position' => $verifyData['hamburger_position'] ?? 'FAILED',
        'storage' => 'JSON_ONLY'
    ];
}

/**
 * Sync configuration to database
 */
function syncToDatabase($componentType, $settings) {
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        $pdo = database_getContextAwareConnection();
        
        $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES);
        $timestamp = date('Y-m-d H:i:s');
        
        // First try to update existing record
        $updateStmt = $pdo->prepare("UPDATE ui_layout_configurations 
                                   SET settings = ?, updated_at = ?, is_active = 1 
                                   WHERE component_type = ?");
        $updateStmt->execute([$settingsJson, $timestamp, $componentType]);
        $rowsAffected = $updateStmt->rowCount();
        
        // If no rows affected, insert new record
        if ($rowsAffected === 0) {
            $insertStmt = $pdo->prepare("INSERT INTO ui_layout_configurations 
                                       (component_type, settings, is_active, created_at, updated_at) 
                                       VALUES (?, ?, 1, ?, ?)");
            $insertStmt->execute([$componentType, $settingsJson, $timestamp, $timestamp]);
            return [
                'success' => true,
                'action' => 'inserted',
                'affected_rows' => $insertStmt->rowCount()
            ];
        }
        
        return [
            'success' => true,
            'action' => 'updated',
            'affected_rows' => $rowsAffected
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get current configuration
 */
function getConfig($componentType) {
    $configFile = getSyncConfigPath($componentType);
    
    if (!file_exists($configFile)) {
        return null;
    }
    
    $content = file_get_contents($configFile);
    return json_decode($content, true);
}

// Clear any existing output
ob_clean();

try {
    switch ($action) {
        case 'sync_config':
            if (!$componentType) {
                throw new Exception('Missing component_type parameter');
            }
            
            $settings = $_POST['settings'] ?? '';
            if (!$settings) {
                throw new Exception('Missing settings parameter');
            }
            
            // Parse settings
            $settingsArray = json_decode($settings, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON settings: ' . json_last_error_msg());
            }
            
            // Sync to file
            $result = syncToFile($componentType, $settingsArray);
            
            echo json_encode([
                'success' => $result['success'],
                'message' => ucfirst($componentType) . ' configuration synchronized',
                'component' => $componentType,
                'file_result' => $result,
                'settings' => $settingsArray,
                'timestamp' => time()
            ]);
            break;
            
        case 'get_config':
            if (!$componentType) {
                throw new Exception('Missing component_type parameter');
            }
            
            $config = getConfig($componentType);
            
            echo json_encode([
                'success' => true,
                'component' => $componentType,
                'configuration' => $config,
                'timestamp' => time()
            ]);
            break;
            
        case 'ping':
            echo json_encode([
                'success' => true,
                'message' => 'Clean sync API is active',
                'timestamp' => time(),
                'version' => '1.0.0'
            ]);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

// End output buffering and exit
ob_end_flush();
exit;
?>
