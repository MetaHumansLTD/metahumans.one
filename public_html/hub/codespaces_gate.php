<?php
/**
 * Codespaces Gatekeeper
 * - Checks if user has tokens available.
 * - Redirects to Codespaces environment if eligible.
 * - Redirects to Tokenization page if insufficient tokens.
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';

// Start Session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth Check
if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

// Token Check
// Ensure we have the latest token balance
if (function_exists('mh_refresh_session_token_balance')) {
    mh_refresh_session_token_balance((string)$_SESSION['mh_auth_user'], 10);
} elseif (!isset($_SESSION['tokens']) && function_exists('mh_auth_load_user_context')) {
    mh_auth_load_user_context((string)$_SESSION['mh_auth_user']);
}

$tokens = $_SESSION['tokens'] ?? 0;
$minTokens = 1;
try {
    if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_get_service_pricing')) {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdoTok);
        $row = mh_tokenomics_get_service_pricing($pdoTok, 'hub_ide:access_min_tokens', 1);
        $minTokens = max(0, (int)($row['tokens_per_unit'] ?? 1));
    }
} catch (Throwable $e) {
    $minTokens = 1;
}

if ($tokens >= $minTokens) {
    // User has tokens, grant access
    // Redirect to the actual Codespaces environment
    header('Location: /hub/ide/');
    exit;
} else {
    // Insufficient tokens
    // Redirect to token purchase page with a message
    header('Location: /hub/genesis/tokenization.php?error=insufficient_tokens');
    exit;
}
