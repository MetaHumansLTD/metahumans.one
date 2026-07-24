<?php
/**
 * Clear Sync Log API
 * Clears all sync log entries
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Clear any existing output
ob_clean();

try {
    // Define log file path directly to avoid framework contamination
    $logFile = dirname(__DIR__, 4) . '/.data/sync-status-log.json';
    
    if (!file_exists($logFile)) {
        // If log file doesn't exist, consider it already cleared
        echo json_encode([
            'success' => true,
            'message' => 'Sync log was already empty',
            'timestamp' => time()
        ]);
        exit;
    }
    
    // Create empty log structure
    $emptyLog = [
        'entries' => [],
        'cleared_at' => date('Y-m-d H:i:s'),
        'cleared_by' => 'user_request'
    ];
    
    // Write empty log file
    $result = file_put_contents($logFile, json_encode($emptyLog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    if ($result !== false) {
        echo json_encode([
            'success' => true,
            'message' => 'Sync log cleared successfully',
            'entries_cleared' => 'all',
            'timestamp' => time()
        ]);
    } else {
        throw new Exception('Failed to write to log file');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

// End output buffering
ob_end_flush();
exit;
?>