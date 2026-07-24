<?php
require_once __DIR__ . '/../.cue/cue.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = $_SESSION['mh_auth_user'] ?? ($_SERVER['HTTP_AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? null));

if (!$currentUser) {
    $loginUrl = "login.php";
    if (isset($_GET['redirect'])) {
        $loginUrl .= "?redirect=" . urlencode($_GET['redirect']);
    }
    header("Location: " . $loginUrl);
    exit;
}

// Check for redirect param if already logged in
if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    $redirect = $_GET['redirect'];
    if (mh_is_allowed_redirect($redirect)) {
        header("Location: " . $redirect);
        exit;
    }
}

function mh_is_allowed_redirect(string $redirect): bool {
    $redirect = trim($redirect);
    if ($redirect === '') {
        return false;
    }
    if (strpos($redirect, '/') === 0) {
        return true;
    }
    $parts = parse_url($redirect);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'https' && $scheme !== 'http') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }
    if ($host === 'metahumans.one') {
        return true;
    }
    return substr($host, -strlen('.metahumans.one')) === '.metahumans.one';
}

$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans Authentication</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-auth-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .mh-auth-popup {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 16px;
            padding: 2.5rem;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            color: #fff;
            font-family: system-ui, -apple-system, sans-serif;
            animation: mh-popup-fade-in 0.3s ease-out;
        }
        @keyframes mh-popup-fade-in {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .mh-auth-popup h2 {
            margin-top: 0;
            margin-bottom: 1rem;
            font-size: 1.75rem;
            font-weight: 600;
            color: #fff;
        }
        .mh-auth-popup p {
            margin-bottom: 2rem;
            color: #a0a0a0;
            line-height: 1.6;
            font-size: 1.05rem;
        }
        .mh-auth-popup strong {
            color: #fff;
        }
        .mh-auth-popup-actions {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .mh-auth-btn {
            display: block;
            width: 100%;
            padding: 0.875rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
            text-align: center;
            box-sizing: border-box;
        }
        .mh-auth-btn-primary {
            background: #3b82f6;
            color: white;
            border: 1px solid #3b82f6;
        }
        .mh-auth-btn-primary:hover {
            background: #2563eb;
            border-color: #2563eb;
            transform: translateY(-1px);
        }
        .mh-auth-btn-secondary {
            background: transparent;
            border: 1px solid #444;
            color: #a0a0a0;
        }
        .mh-auth-btn-secondary:hover {
            border-color: #666;
            color: #fff;
            background: rgba(255,255,255,0.05);
        }
    </style>
</head>
<body class="mh-auth-body">
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
    
    <div class="mh-auth-popup-overlay">
        <div class="mh-auth-popup">
            <h2>Welcome Back</h2>
            <p>You are currently signed in as <strong><?php echo htmlspecialchars($currentUser); ?></strong>.<br>Would you like to proceed to the Hub?</p>
            <div class="mh-auth-popup-actions">
                <a href="/hub/" class="mh-auth-btn mh-auth-btn-primary">Go to Hub</a>
                <a href="logout.php" class="mh-auth-btn mh-auth-btn-secondary">Logout</a>
            </div>
        </div>
    </div>

    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
