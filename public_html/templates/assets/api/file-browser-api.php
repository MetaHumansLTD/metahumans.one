<?php
/**
 * File Browser API - Browse server images
 * CUE Framework compliant file browser for selecting server images
 */

// Prevent widget auto-initialization for API responses
define('CUE_ANIMATIONS_INITIALIZED', true);
define('CUE_SKIP_WIDGET_INIT', true);

// Load CUE Framework
require_once '../../../.cue/cue.php';

// Set proper headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Missing action']);
    exit;
}

try {
    switch ($action) {
        case 'browse_directory':
            $requestPath = $input['path'] ?? '';
            
            // Get the public_html root path using CUE Framework
            $publicRoot = getPublicPath();
            
            // Normalize path separators for cross-platform compatibility
            $publicRoot = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $publicRoot);
            
            // Sanitize the requested path to prevent directory traversal
            $requestPath = ltrim($requestPath, '/\\');
            $requestPath = str_replace(['../', '..\\'], '', $requestPath);
            $requestPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $requestPath);
            
            // Build the full path
            if (empty($requestPath)) {
                $fullPath = $publicRoot;
                $relativePath = '';
            } else {
                $fullPath = $publicRoot . DIRECTORY_SEPARATOR . $requestPath;
                $relativePath = $requestPath;
            }
            
            // Debug logging
            error_log('File Browser Debug: publicRoot=' . $publicRoot);
            error_log('File Browser Debug: fullPath=' . $fullPath);
            error_log('File Browser Debug: requestPath=' . $requestPath);
            
            // Verify the path exists and is within public_html
            if (!file_exists($fullPath) || !is_dir($fullPath)) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Directory not found'
                ]);
                exit;
            }
            
            // Security check: ensure path is within public_html
            $realFullPath = realpath($fullPath);
            $realPublicRoot = realpath($publicRoot);
            
            if (strpos($realFullPath, $realPublicRoot) !== 0) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Access denied - path outside allowed directory'
                ]);
                exit;
            }
            
            $items = [];
            $directories = [];
            $files = [];
            
            // Scan directory
            $scanResults = scandir($fullPath);
            if ($scanResults === false) {
                error_log('File Browser Error: Cannot scan directory: ' . $fullPath);
                echo json_encode([
                    'success' => false, 
                    'message' => 'Cannot read directory: ' . $fullPath,
                    'debug' => [
                        'fullPath' => $fullPath,
                        'exists' => file_exists($fullPath),
                        'isDir' => is_dir($fullPath),
                        'readable' => is_readable($fullPath)
                    ]
                ]);
                exit;
            }
            
            foreach ($scanResults as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $fullPath . DIRECTORY_SEPARATOR . $item;
                $relativeItemPath = empty($relativePath) ? $item : $relativePath . '/' . $item;
                $relativeItemPath = str_replace('\\', '/', $relativeItemPath);
                
                if (is_dir($itemPath)) {
                    $directories[] = [
                        'name' => $item,
                        'type' => 'directory',
                        'path' => $relativeItemPath,
                        'size' => null,
                        'modified' => filemtime($itemPath)
                    ];
                } else {
                    // Check if it's an image file
                    $extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp'];
                    
                    if (in_array($extension, $imageExtensions)) {
                        $fileSize = filesize($itemPath);
                        // Generate proper web path with forward slashes
                        $webPath = '/' . str_replace('\\', '/', $relativeItemPath);
                        
                        $dimensions = getImageDimensions($itemPath);
                        $dimensionsText = ($dimensions['width'] > 0 && $dimensions['height'] > 0) 
                            ? $dimensions['width'] . 'x' . $dimensions['height'] 
                            : 'Unknown';
                        
                        $files[] = [
                            'name' => $item,
                            'type' => 'image',
                            'path' => $relativeItemPath,
                            'url' => $webPath,  // This is what JavaScript expects
                            'extension' => $extension,
                            'size' => $fileSize,
                            'size_formatted' => formatFileSize($fileSize),
                            'modified' => filemtime($itemPath),
                            'dimensions' => $dimensionsText
                        ];
                    }
                }
            }
            
            // Sort directories and files alphabetically
            usort($directories, function($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            
            usort($files, function($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
            
            // Combine directories first, then files
            $items = array_merge($directories, $files);
            
            // Add parent directory navigation if not at root
            $parentPath = '';
            $breadcrumbs = [];
            
            if (!empty($relativePath)) {
                $pathParts = explode('/', $relativePath);
                array_pop($pathParts);
                $parentPath = implode('/', $pathParts);
                
                // Build breadcrumbs
                $breadcrumbs[] = ['name' => 'public_html', 'path' => ''];
                $currentPath = '';
                foreach ($pathParts as $part) {
                    $currentPath .= ($currentPath ? '/' : '') . $part;
                    $breadcrumbs[] = ['name' => $part, 'path' => $currentPath];
                }
                $breadcrumbs[] = ['name' => basename($relativePath), 'path' => $relativePath];
            } else {
                $breadcrumbs[] = ['name' => 'public_html', 'path' => ''];
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'current_path' => $relativePath,
                    'parent_path' => $parentPath,
                    'breadcrumbs' => $breadcrumbs,
                    'items' => $items,
                    'stats' => [
                        'directories' => count($directories),
                        'images' => count($files),
                        'other_files' => 0,
                        'total' => count($items)
                    ]
                ]
            ]);
            break;
            
        case 'get_image_info':
            $imagePath = $input['path'] ?? '';
            
            if (empty($imagePath)) {
                echo json_encode(['success' => false, 'message' => 'Missing image path']);
                exit;
            }
            
            // Sanitize path
            $imagePath = ltrim($imagePath, '/\\\\');
            $imagePath = str_replace(['../', '..\\\\'], '', $imagePath);
            
            $publicRoot = getPublicPath();
            $fullPath = $publicRoot . DIRECTORY_SEPARATOR . $imagePath;
            
            // Security and existence checks
            if (!file_exists($fullPath) || !is_file($fullPath)) {
                echo json_encode(['success' => false, 'message' => 'Image not found']);
                exit;
            }
            
            $realFullPath = realpath($fullPath);
            $realPublicRoot = realpath($publicRoot);
            
            if (strpos($realFullPath, $realPublicRoot) !== 0) {
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            // Check if it's an image
            $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp'];
            
            if (!in_array($extension, $imageExtensions)) {
                echo json_encode(['success' => false, 'message' => 'Not an image file']);
                exit;
            }
            
            $webPath = '/' . str_replace('\\\\', '/', $imagePath);
            $fileSize = filesize($fullPath);
            $dimensions = getImageDimensions($fullPath);
            
            echo json_encode([
                'success' => true,
                'info' => [
                    'name' => basename($imagePath),
                    'path' => $imagePath,
                    'webPath' => $webPath,
                    'extension' => $extension,
                    'size' => $fileSize,
                    'sizeFormatted' => formatFileSize($fileSize),
                    'dimensions' => $dimensions,
                    'modified' => filemtime($fullPath),
                    'isValid' => $fileSize <= 2 * 1024 * 1024 && $dimensions['width'] >= 50 && $dimensions['height'] >= 50
                ]
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            break;
    }
    
} catch (Exception $e) {
    error_log('File Browser API Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Get image dimensions safely
 */
function getImageDimensions($filePath) {
    try {
        $imageInfo = getimagesize($filePath);
        if ($imageInfo !== false) {
            return [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'ratio' => $imageInfo[1] > 0 ? round($imageInfo[0] / $imageInfo[1], 2) : 1
            ];
        }
    } catch (Exception $e) {
        // SVG or other formats might not work with getimagesize
    }
    
    return ['width' => 0, 'height' => 0, 'ratio' => 1];
}

/**
 * Format file size in human readable format
 */
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return $bytes . ' B';
    }
}
?>