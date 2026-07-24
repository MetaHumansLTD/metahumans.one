<?php
/**
 * Real-time Sync API - Bridge to Clean Sync
 * Forwards layout-manager requests to the clean-sync.php endpoint
 */

// Prevent any output before headers
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

// Only POST/GET allowed
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Clear any existing output
ob_clean();

try {
    // Forward all parameters to clean-sync.php
    $action = $_POST['action'] ?? $_GET['action'] ?? 'ping';
    $componentType = $_POST['component_type'] ?? $_GET['component_type'] ?? '';
    $settings = $_POST['settings'] ?? $_GET['settings'] ?? '';

    // Include the clean sync functionality
    include_once 'clean-sync.php';
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Bridge error: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}

// End output buffering
ob_end_flush();
exit;
?>