<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../gear/grid/sr_client.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/grid/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/grid/';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$cfg = mh_grid_read_cfg();
$baseUrlSet = isset($cfg['base_url']) && is_string($cfg['base_url']) && trim($cfg['base_url']) !== '';
$allowlistCount = isset($cfg['allowlist']) && is_array($cfg['allowlist']) ? count($cfg['allowlist']) : 0;
$credsSet = isset($cfg['token_id'], $cfg['client_secret']) && is_string($cfg['token_id']) && is_string($cfg['client_secret']) && trim($cfg['token_id']) !== '' && trim($cfg['client_secret']) !== '';
$webhookKeySet = isset($cfg['webhook_public_key_pem']) && is_string($cfg['webhook_public_key_pem']) && trim($cfg['webhook_public_key_pem']) !== '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grid | Meta Humans Hub</title>
    <?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }
        .grid-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 18px 18px; }
        .grid-title { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); margin: 0 0 10px 0; letter-spacing: 1px; text-transform: uppercase; }
        .grid-sub { color: rgba(255,255,255,0.78); margin: 0 0 18px 0; }
        .grid-status { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid-item { background: rgba(0,0,0,0.18); border: 1px solid rgba(0, 212, 255, 0.12); border-radius: 12px; padding: 12px 12px; }
        .grid-k { color: rgba(255,255,255,0.72); font-size: 0.92rem; margin: 0 0 6px 0; }
        .grid-v { font-weight: 700; margin: 0; color: rgba(255,255,255,0.92); }
        .grid-v.ok { color: #8ff0a4; }
        .grid-v.no { color: #ffb4a2; }
        .grid-links { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
        .grid-btn { display: inline-block; padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.28); color: rgba(0, 212, 255, 0.95); text-decoration: none; font-weight: 700; letter-spacing: 0.6px; }
        .grid-btn:hover { background: rgba(0, 212, 255, 0.10); }
        @media (max-width: 800px) { .grid-status { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<main class="main-content">
    <div class="grid-card">
        <h1 class="grid-title">Grid (Global Accounts)</h1>
        <p class="grid-sub">Settlement rail integration status for your account.</p>
        <div class="grid-status">
            <div class="grid-item">
                <p class="grid-k">Base URL</p>
                <p class="grid-v <?php echo $baseUrlSet ? 'ok' : 'no'; ?>"><?php echo $baseUrlSet ? 'Configured' : 'Not configured'; ?></p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Allowlist</p>
                <p class="grid-v <?php echo $allowlistCount > 0 ? 'ok' : 'no'; ?>"><?php echo $allowlistCount > 0 ? ($allowlistCount . ' endpoints') : 'Empty'; ?></p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Platform Credentials</p>
                <p class="grid-v <?php echo $credsSet ? 'ok' : 'no'; ?>"><?php echo $credsSet ? 'Configured' : 'Not configured'; ?></p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Webhook Verification Key</p>
                <p class="grid-v <?php echo $webhookKeySet ? 'ok' : 'no'; ?>"><?php echo $webhookKeySet ? 'Configured' : 'Not configured'; ?></p>
            </div>
        </div>
        <div class="grid-links">
            <a class="grid-btn" href="/hub/grid/dashboard.php">Open Banking Dashboard</a>
            <a class="grid-btn" href="/hub/grid/transactions.php">Open Transactions</a>
            <a class="grid-btn" href="/hub/grid/passkey.php">Register Grid Passkey</a>
            <a class="grid-btn" href="/hub/grid/payments.php">Send / Receive (Quotes)</a>
            <a class="grid-btn" href="/hub/wallet.php">Open Wallet</a>
            <a class="grid-btn" href="/hub/">Back to Hub</a>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/footer.php'; ?>
</body>
</html>
