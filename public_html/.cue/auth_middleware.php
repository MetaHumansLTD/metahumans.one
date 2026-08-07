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
        while (ob_get_level() > 0) { if (!@ob_end_clean()) { break; } }
        $swallowConsts = ['MH_CONTROL_DISPATCH_OB_CLEANUP','MH_CTL_OB_CLEANUP','MH_CTL_ORDERS_OB_CLEANUP','MH_CTL_PROVIDERS_OB_CLEANUP','MH_CTL_PROVIDERS_COZA_OB_CLEANUP','MH_CTL_PROVIDERS_NETEARTHONE_OB_CLEANUP','MH_CTL_TASKS_OB_CLEANUP','MH_CTL_TASKS_ENQUEUE_OB_CLEANUP','MH_HUB_COMPANIES_DOMAINS_OB_CLEANUP','MH_HUB_EDIT_OB_CLEANUP','MH_HUB_RENEW_OB_CLEANUP','MH_HUB_REGISTER_OB_CLEANUP','MH_HUB_MANAGE_OB_CLEANUP','MH_HUB_CANCEL_OB_CLEANUP','MH_HUB_ORDERS_CANCEL_OB_CLEANUP','MH_HUB_DOMAINS_OB_CLEANUP','MH_CONTROL_OB_CLEANUP','MH_HUB_OB_CLEANUP'];
        foreach ($swallowConsts as $c) { if (defined($c)) { @ob_end_clean(); } }
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8', true);
        echo json_encode(['success' => false, 'error' => 'unauthorized', 'message' => 'Authentication required'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    $redirect = '/auth/login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/');
    $escaped = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
    if (!headers_sent()) {
        header('Location: ' . $redirect, true, 302);
        header('Content-Type: text/html; charset=UTF-8', true);
    }
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=' . $escaped . '"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in required</title></head><body style="font-family:system-ui,sans-serif;background:#020617;color:#e2e8f0;margin:0;padding:32px;"><p>Please <a style="color:#60a5fa;" href="' . $escaped . '">sign in</a> to continue.</p></body></html>';
    while (ob_get_level() > 0) { if (!@ob_end_clean()) { break; } }
    $swallowConsts = ['MH_CONTROL_DISPATCH_OB_CLEANUP','MH_CTL_OB_CLEANUP','MH_CTL_ORDERS_OB_CLEANUP','MH_CTL_PROVIDERS_OB_CLEANUP','MH_CTL_PROVIDERS_COZA_OB_CLEANUP','MH_CTL_PROVIDERS_NETEARTHONE_OB_CLEANUP','MH_CTL_TASKS_OB_CLEANUP','MH_CTL_TASKS_ENQUEUE_OB_CLEANUP','MH_HUB_COMPANIES_DOMAINS_OB_CLEANUP','MH_HUB_EDIT_OB_CLEANUP','MH_HUB_RENEW_OB_CLEANUP','MH_HUB_REGISTER_OB_CLEANUP','MH_HUB_MANAGE_OB_CLEANUP','MH_HUB_CANCEL_OB_CLEANUP','MH_HUB_ORDERS_CANCEL_OB_CLEANUP','MH_HUB_DOMAINS_OB_CLEANUP','MH_CONTROL_OB_CLEANUP','MH_HUB_OB_CLEANUP'];
    foreach ($swallowConsts as $c) { if (defined($c)) { @ob_end_clean(); } }
    echo $html;
    exit;
}
