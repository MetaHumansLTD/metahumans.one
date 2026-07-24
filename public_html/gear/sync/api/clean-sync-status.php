<?php
/**
 * Clean Sync Status API - No Framework Dependencies
 * Returns current synchronization status for all components
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

// Only GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/**
 * Get configuration file path
 */
function getConfigPath($componentType) {
    $basePath = dirname(__DIR__, 4) . '/.data/global-ui';
    return $basePath . '/' . $componentType . '/' . $componentType . '-config.json';
}

/**
 * Get sync status file path
 */
function getSyncStatusFilePath() {
    $basePath = dirname(__DIR__, 4) . '/.data/sync';
    return $basePath . '/status.json';
}

/**
 * Get file modification time
 */
function getFileModTime($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    return date('c', filemtime($filePath));
}

/**
 * Get component status
 */
function getComponentStatus($componentType) {
    $configFile = getConfigPath($componentType);
    
    $status = [
        'json_last_modified' => getFileModTime($configFile),
        'database_last_modified' => null, // No database in clean version
        'status' => file_exists($configFile) ? 'json_available' : 'no_config',
        'conflicts' => []
    ];
    
    return $status;
}

// Clear any existing output
ob_clean();

try {
    // Get status for all components
    $components = ['header', 'footer', 'navigation', 'theme'];
    $componentStatus = [];
    
    foreach ($components as $component) {
        $componentStatus[$component] = getComponentStatus($component);
    }
    
    // Build complete status response
    $syncStatus = [
        'sync_enabled' => true,
        'last_sync' => null,
        'sync_direction' => 'json_to_database',
        'components' => $componentStatus,
        'auto_sync' => [
            'enabled' => false,
            'interval' => 300,
            'conflict_resolution' => 'manual'
        ],
        'version' => '1.0.0',
        'database_available' => false // Clean version doesn't use database
    ];
    
    echo json_encode([
        'success' => true,
        'status' => $syncStatus,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'status' => null
    ]);
}

// End output buffering and exit
ob_end_flush();
exit;
?>