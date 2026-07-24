<?php
/**
 * Global Login Enforcement Middleware
 * 
 * Ensures that only authenticated users can access the application.
 * Exceptions are made for login, registration, and public assets.
 */

// Define public paths that don't require authentication
$publicPaths = [
    '/auth/login.php',
    '/auth/register.php',
    '/auth/logout.php',
    '/index.php', // Launch page is public
    '/robots.txt',
    '/favicon.ico',
];

// Allow asset directories
$publicDirs = [
    '/templates/assets/',
    '/assets/',
];

$currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
$isPublic = false;

// Check exact paths
if (in_array($currentPath, $publicPaths)) {
    $isPublic = true;
}

// Check directories
if (!$isPublic) {
    foreach ($publicDirs as $dir) {
        if (strpos($currentPath, $dir) === 0) {
            $isPublic = true;
            break;
        }
    }
}

// Check if user is logged in
$isLoggedIn = isset($_SESSION['mh_auth_user']) && !empty($_SESSION['mh_auth_user']);

// Enforce Login
if (!$isPublic && !$isLoggedIn) {
    // Check if it's an API request
    if (stripos($currentPath, '/api/') !== false || 
        (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'unauthorized', 'message' => 'Authentication required']);
        exit;
    }
    
    // Redirect to login
    header("Location: /auth/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
