<?php
/**
 * Browse Files API - Returns directory contents for Browse Pages functionality
 * CUE Framework Compliant
 * 
 * @package CUE Framework
 * @version 100.0.99
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$requestedPath = $input['path'] ?? '/';

// Security: Normalize and validate path
$requestedPath = str_replace(['..', '\\'], ['', '/'], $requestedPath);
$requestedPath = '/' . trim($requestedPath, '/');
if ($requestedPath === '/') $requestedPath = '';

// Base directory is public_html
$baseDir = $_SERVER['DOCUMENT_ROOT'];
$fullPath = $baseDir . $requestedPath;

// Ensure path exists and is within public_html
if (!is_dir($fullPath) || !str_starts_with(realpath($fullPath), realpath($baseDir))) {
    http_response_code(404);
    echo json_encode(['error' => 'Directory not found or access denied']);
    exit;
}

try {
    $folders = [];
    $files = [];
    
    // Scan directory
    $items = scandir($fullPath);
    
    foreach ($items as $item) {
        // Skip hidden files and current directory
        if ($item === '.' || str_starts_with($item, '.')) {
            continue;
        }
        
        $itemPath = $fullPath . '/' . $item;
        
        if (is_dir($itemPath)) {
            $folders[] = $item;
        } else {
            $files[] = $item;
        }
    }
    
    // Sort alphabetically
    sort($folders);
    sort($files);
    
    // Return response
    echo json_encode([
        'success' => true,
        'path' => $requestedPath,
        'folders' => $folders,
        'files' => $files,
        'total' => count($folders) + count($files)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to read directory: ' . $e->getMessage()
    ]);
}
?>