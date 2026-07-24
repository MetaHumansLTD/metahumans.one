<?php
/**
 * File Upload Handler for Theme Builder
 * Handles uploading of images and other assets to the /templates/assets/images/ directory
 */

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define upload directory
$uploadDir = dirname(__DIR__, 2) . '/assets/images/';
$webPath = '/templates/assets/images/';

// Ensure upload directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No file uploaded or upload error occurred'
    ]);
    exit;
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmpName = $file['tmp_name'];
$fileSize = $file['size'];
$fileType = $file['type'];

// Validate file type (images only)
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
if (!in_array($fileType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid file type. Only images are allowed (JPEG, PNG, GIF, WebP, SVG)'
    ]);
    exit;
}

// Validate file size (max 5MB)
$maxSize = 5 * 1024 * 1024; // 5MB
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'File size too large. Maximum size is 5MB'
    ]);
    exit;
}

// Generate unique filename to prevent conflicts
$fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
$baseName = pathinfo($fileName, PATHINFO_FILENAME);
$uniqueFileName = $baseName . '_' . time() . '.' . $fileExtension;

// Full path for the uploaded file
$targetPath = $uploadDir . $uniqueFileName;

// Move uploaded file to target directory
if (move_uploaded_file($fileTmpName, $targetPath)) {
    // Return success response with file information
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'file' => [
            'name' => $uniqueFileName,
            'original_name' => $fileName,
            'path' => $webPath . $uniqueFileName,
            'full_path' => $targetPath,
            'size' => $fileSize,
            'type' => $fileType
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to move uploaded file'
    ]);
}
?>