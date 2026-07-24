<?php
if (isset($_GET['action']) && is_string($_GET['action']) && trim($_GET['action']) !== '') {
    if (!defined('CUE_DISABLE_AUTO_UI')) {
        define('CUE_DISABLE_AUTO_UI', true);
    }
    if (!defined('CUE_LAYOUT_MANUAL')) {
        define('CUE_LAYOUT_MANUAL', true);
    }
}

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/persona_registry.php';
require_once __DIR__ . '/../gear/grid/bootstrap.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function mh_auth_debug_emit(string $hypothesisId, string $location, string $msg, array $data = []): void {
    // #region debug-point B:auth-debug-emit
    $url = 'http://127.0.0.1:7777/event';
    $sessionId = 'oidc-sso-loop';
    $envPath = dirname(__DIR__) . '/../.dbg/oidc-sso-loop.env';
    if (is_file($envPath)) {
        $envRaw = (string)@file_get_contents($envPath);
        if ($envRaw !== '') {
            foreach (preg_split('/\r?\n/', $envRaw) ?: [] as $line) {
                $line = trim((string)$line);
                if ($line === '' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                if ($k === 'DEBUG_SERVER_URL' && trim($v) !== '') $url = trim($v);
                if ($k === 'DEBUG_SESSION_ID' && trim($v) !== '') $sessionId = trim($v);
            }
        }
    }
    $payload = json_encode([
        'sessionId' => $sessionId,
        'runId' => 'pre-fix',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'msg' => '[DEBUG] ' . $msg,
        'data' => $data,
        'ts' => (int)round(microtime(true) * 1000),
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($payload) && $payload !== '') {
        @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 1,
                'ignore_errors' => true,
            ],
        ]));
    }
    // #endregion
}

$redirect = isset($_GET['redirect']) ? (string)$_GET['redirect'] : '';
$redirect = trim($redirect);
// Preserve same-origin absolute return URLs so OIDC flows can resume after login.
// Final safety checks are applied later by mh_is_allowed_redirect()/mh_post_login_destination().
// #region debug-point B:login-entry
$debugSessionName = session_name();
$debugSessionId = session_id();
$debugCookieValue = (is_string($debugSessionName) && $debugSessionName !== '' && isset($_COOKIE[$debugSessionName]) && is_string($_COOKIE[$debugSessionName])) ? $_COOKIE[$debugSessionName] : null;
mh_auth_debug_emit('B', 'auth/login.php:entry', 'login entry session snapshot', [
    'redirect' => $redirect,
    'mh_auth_user' => $_SESSION['mh_auth_user'] ?? null,
    'session_name' => $debugSessionName,
    'session_id' => $debugSessionId !== '' ? $debugSessionId : null,
    'cookie_present' => $debugCookieValue !== null && $debugCookieValue !== '',
    'cookie_matches_session' => ($debugCookieValue !== null && $debugSessionId !== '' ? hash_equals($debugSessionId, $debugCookieValue) : false),
]);
// #endregion

function mh_contains_at_sign(string $value): bool {
    $value = trim($value);
    if ($value === '') return false;
    return strpos($value, '@') !== false;
}

function mh_validate_username_strict(string $username): void {
    $username = trim($username);
    if ($username === '') {
        throw new Exception("Username is required.");
    }
    if (mh_contains_at_sign($username)) {
        throw new Exception('Username cannot contain "@".');
    }
    if (preg_match('/\s/', $username)) {
        throw new Exception("Username cannot contain spaces.");
    }
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
        throw new Exception("Username can contain letters and numbers only.");
    }
    if (strlen($username) < 5) {
        throw new Exception("Username must be at least 5 characters.");
    }
    if (preg_match('/^(metahuman|anon|device)/i', $username)) {
        throw new Exception("Username is invalid. Please choose your own unique username.");
    }
}

function mh_validate_real_first_and_surname_strict(string $first, string $surname): void {
    $first = trim($first);
    $surname = trim($surname);
    if ($first === '') throw new Exception("Real name is required.");
    if ($surname === '') throw new Exception("Real surname is required.");
    if (mh_contains_at_sign($first) || mh_contains_at_sign($surname)) throw new Exception('Real name/surname cannot contain "@".');
    $firstClean = preg_replace("/[^a-zA-Z\\-']/u", '', $first);
    $surnameClean = preg_replace("/[^a-zA-Z\\-']/u", '', $surname);
    if (!is_string($firstClean) || strlen($firstClean) < 2) throw new Exception("Real name must be at least 2 characters.");
    if (!is_string($surnameClean) || strlen($surnameClean) < 2) throw new Exception("Real surname must be at least 2 characters.");
    if (strcasecmp($firstClean, $surnameClean) === 0) throw new Exception("Real name and surname cannot be the same.");
}

function mh_validate_real_name_strict(string $name): void {
    $name = trim($name);
    if ($name === '') throw new Exception("Real name is required.");
    if (mh_contains_at_sign($name)) throw new Exception('Real name cannot contain "@".');
    $parts = preg_split('/\s+/', $name);
    $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
    if (count($parts) < 2) throw new Exception("Real name must include a surname.");
    $first = (string)$parts[0];
    $surname = (string)$parts[count($parts) - 1];
    mh_validate_real_first_and_surname_strict($first, $surname);
}

function mh_ensure_registration_policy_schema(PDO $pdoBio): void {
    try { $pdoBio->query("SELECT ip_address FROM users LIMIT 1"); } catch (Exception) { $pdoBio->exec("ALTER TABLE users ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL AFTER device_id"); try { $pdoBio->exec("ALTER TABLE users ADD INDEX idx_ip_address (ip_address)"); } catch (Throwable) {} }
    try { $pdoBio->query("SELECT device_fingerprint FROM users LIMIT 1"); } catch (Exception) { $pdoBio->exec("ALTER TABLE users ADD COLUMN device_fingerprint VARCHAR(64) DEFAULT NULL AFTER ip_address"); try { $pdoBio->exec("ALTER TABLE users ADD INDEX idx_device_fingerprint (device_fingerprint)"); } catch (Throwable) {} }
    try { $pdoBio->query("SELECT device_id FROM users LIMIT 1"); } catch (Exception) { $pdoBio->exec("ALTER TABLE users ADD COLUMN device_id VARCHAR(255) DEFAULT NULL"); }
    try { $pdoBio->exec("ALTER TABLE users ADD INDEX idx_device_id (device_id)"); } catch (Throwable) {}
}

function mh_enforce_registration_policy(PDO $pdoBio, string $ipAddress, string $deviceFingerprint, string $deviceId = ''): void {
    if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
        return;
    }
    $deviceFingerprint = trim($deviceFingerprint);
    mh_ensure_registration_policy_schema($pdoBio);

    $deviceId = trim((string)$deviceId);
    if ($deviceId !== '') {
        $stmt = $pdoBio->prepare("SELECT 1 FROM users WHERE device_id = ? LIMIT 1");
        $stmt->execute([$deviceId]);
        if ((bool)$stmt->fetchColumn()) {
            throw new Exception("device_already_registered", 1001);
        }
    }

    $stmt = $pdoBio->prepare("SELECT COUNT(*) FROM users WHERE ip_address = ?");
    $stmt->execute([$ipAddress]);
    $cnt = (int)$stmt->fetchColumn();
    if ($cnt >= 5) {
        throw new Exception("registration_limit_reached", 1002);
    }
}

// LemonLDAP::NG SSO Integration
require_once __DIR__ . '/lemonldap-handler.php';
$ssoData = lemonldap_process_headers();

$authClassesPath = __DIR__ . '/auth_classes.php';
require_once $authClassesPath;
if ($ssoData) {
    // Sync user to biometrics DB
    lemonldap_sync_user($ssoData);

    $ssoUser = $ssoData['username'];
    
    // Only process if not already logged in or if logged in user doesn't match
    if (!isset($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] !== $ssoUser) {
        mh_auth_load_user_context($ssoUser, $ssoData['groups'] ?? null, $ssoData['email'] ?? null);
        mh_login_try_grid_bootstrap_for_user($ssoUser);
        
        $_SESSION['mh_auth_user'] = $ssoUser;
        $_SESSION['mh_auth_method'] = 'sso_lemonldap'; // Track method

        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
            $pdoBio = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
            $pepper = function_exists('mh_remember_me_get_pepper') ? mh_remember_me_get_pepper() : null;
            $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
            if ($pdoBio instanceof PDO && is_string($pepper) && trim($pepper) !== '' && $uid > 0 && function_exists('mh_remember_me_issue_or_rotate_cookie')) {
                mh_remember_me_ensure_schema_once($pdoBio);
                $ctx = function_exists('mh_remember_me_get_context') ? mh_remember_me_get_context() : [];
                $device = function_exists('mh_remember_me_resolve_device') ? mh_remember_me_resolve_device($pdoBio, $pepper, $uid, $ctx) : ['recognized' => false, 'device_token_id' => null, 'row' => null];
                mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $uid, $device, $ctx, 'sso_login');
            }
        } catch (Throwable $e) {}
        
        if (!empty($ssoData['groups'])) $_SESSION['mh_auth_groups'] = $ssoData['groups'];
        
        // Log the SSO login for audit
        error_log("SSO Login successful for user: " . $ssoUser);
    }
}

$loggedIn = (isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) && trim((string)$_SESSION['mh_auth_user']) !== '');
if ($loggedIn && $redirect && mh_is_allowed_redirect($redirect) && strpos($redirect, '/auth/login.php') !== 0 && strpos($redirect, '/auth/index.php') !== 0) {
    $dest = mh_post_login_destination($redirect);
    // #region debug-point D:login-redirect-out
    mh_auth_debug_emit('D', 'auth/login.php:redirect', 'login page sees authenticated session and is redirecting out', [
        'mh_auth_user' => $_SESSION['mh_auth_user'] ?? null,
        'redirect' => $redirect,
        'dest' => $dest,
        'session_name' => $debugSessionName,
        'session_id' => $debugSessionId !== '' ? $debugSessionId : null,
    ]);
    // #endregion
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header("Location: " . $dest);
    exit;
}


function auth_json_response(array $data, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo json_encode($data);
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

function mh_post_login_destination(string $redirect): string {
    $redirect = trim($redirect);
    $candidate = '';
    if ($redirect !== '' && mh_is_allowed_redirect($redirect) && strpos($redirect, '/auth/') !== 0) {
        $candidate = $redirect;
        if (stripos($candidate, 'http://') === 0 || stripos($candidate, 'https://') === 0) {
            $p = parse_url($candidate);
            $path = is_array($p) ? (string)($p['path'] ?? '') : '';
            $query = is_array($p) ? (string)($p['query'] ?? '') : '';
            if ($path !== '' && $path[0] === '/') {
                $candidate = $path . ($query !== '' ? ('?' . $query) : '');
            }
        }
    }
    if ($candidate !== '' && strpos($candidate, '/hub/widget/') !== 0 && strpos($candidate, '/hub/widget/') === false) {
        return $candidate;
    }

    $last = isset($_SESSION['mh_last_page']) ? trim((string)$_SESSION['mh_last_page']) : '';
    if ($last !== '' && $last[0] === '/' && strpos($last, '/auth/') !== 0 && strpos($last, '/hub/widget/') !== 0) {
        return $last;
    }
    return '/hub/index.php';
}

function mh_login_try_grid_bootstrap_for_user(string $username): void
{
    $username = trim($username);
    if ($username === '' || !function_exists('mh_grid_bootstrap_user_tenant_best_effort')) {
        return;
    }

    $result = mh_grid_bootstrap_user_tenant_best_effort($username);
    if (($result['ok'] ?? false) !== true) {
        error_log('[GRID BOOTSTRAP] login bootstrap failed for ' . $username . ': ' . json_encode($result, JSON_UNESCAPED_SLASHES));
    }
}

function mh_resolve_biometrics_username_from_login_id(string $loginId): string {
    if (function_exists('mh_auth_resolve_username_from_login_id')) {
        return mh_auth_resolve_username_from_login_id($loginId);
    }
    return trim($loginId);
}

function mh_handle_diag_identity(array $input): void {
    $role = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string)$_SESSION['mh_auth_role'])) : '';
    $isKripz = (stripos($role, 'kripzmaster') !== false);
    if (!$isKripz) {
        auth_json_response(['success' => false, 'error' => 'forbidden'], 403);
        exit;
    }
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    $pdoBio = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
    if (!($pdoBio instanceof PDO)) {
        auth_json_response(['success' => false, 'error' => 'biometrics_unavailable'], 500);
        exit;
    }

    $username = isset($input['username']) ? trim((string)$input['username']) : '';
    $deviceId = isset($input['deviceId']) ? trim((string)$input['deviceId']) : '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

    mh_ensure_registration_policy_schema($pdoBio);

    $userExists = false;
    if ($username !== '') {
        $st = $pdoBio->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $st->execute([$username]);
        $userExists = (bool)$st->fetchColumn();
    }

    $deviceIdUsers = [];
    if ($deviceId !== '') {
        $st = $pdoBio->prepare("SELECT username FROM users WHERE device_id = ? LIMIT 25");
        $st->execute([$deviceId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u !== '') $deviceIdUsers[] = $u;
        }
    }

    $fingerprintMatchCount = 0;
    try {
        $st = $pdoBio->prepare("SELECT COUNT(*) FROM users WHERE device_fingerprint = ?");
        $st->execute([$deviceFingerprint]);
        $fingerprintMatchCount = (int)$st->fetchColumn();
    } catch (Throwable) {
        $fingerprintMatchCount = -1;
    }

    auth_json_response([
        'success' => true,
        'data' => [
            'username' => $username,
            'user_exists' => $userExists,
            'device_id' => $deviceId,
            'device_id_usernames' => $deviceIdUsers,
            'fingerprint_user_count' => $fingerprintMatchCount,
            'ip' => $ipAddress,
        ]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
    if ($action === 'diag_identity') {
        mh_handle_diag_identity($_GET);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = [];
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = $decoded;
            }
        }
    }
    if (empty($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? $_GET['action'] ?? '';

    if (in_array($action, ['start_anonymous_entry', 'finish_anonymous_entry', 'claim_profile', 'get_anonymous_challenge', 'finish_anonymous_login'], true)) {
        auth_json_response([
            'success' => false,
            'error' => 'temporary_registration_disabled',
            'message' => 'Temporary/anonymous registration is disabled. Please register via /auth/register.php.',
            'redirect' => '/auth/register.php',
        ], 400);
        exit;
    }
    $hp = isset($input['website']) ? (string)$input['website'] : '';
    if (trim($hp) !== '' && in_array($action, ['start_passkey_registration', 'finish_passkey_registration', 'pin_register', 'verify_pin_and_start_add_device'], true)) {
        auth_json_response(['success' => false, 'error' => 'blocked'], 400);
        exit;
    }
    if ($action === 'seed_reg_ts') {
        $_SESSION['mh_reg_form_ts'] = time();
        auth_json_response(['success' => true]);
        exit;
    }
    $ts = isset($_SESSION['mh_reg_form_ts']) ? (int)$_SESSION['mh_reg_form_ts'] : 0;
    if ($ts > 0 && (time() - $ts) < 3 && in_array($action, ['start_passkey_registration', 'finish_passkey_registration', 'pin_register'], true)) {
        auth_json_response(['success' => false, 'error' => 'too_fast', 'message' => 'Please wait a moment and try again.'], 400);
        exit;
    }
    try {
    if ($action === 'diag_identity') {
            mh_handle_diag_identity(is_array($input) ? $input : []);
        }
    if ($action === 'start_passkey_login') {
            $userId = isset($input['userId']) ? (string)$input['userId'] : null;
            $auth = new MetaPasskeyAuth();
            $challenge = $auth->generateAuthenticationChallenge($userId);
            auth_json_response([
                'success' => true,
                'challengeId' => $challenge['challengeId'],
                'publicKey' => $challenge['options']
            ]);
            exit;
        }
        if ($action === 'finish_passkey_login') {
            $challengeId = isset($input['challengeId']) ? (string)$input['challengeId'] : '';
            $credential = $input['credential'] ?? [];
            if ($challengeId === '' || !is_array($credential)) {
                auth_json_response(['success' => false, 'error' => 'invalid_payload'], 400);
                exit;
            }
            $assertion = [
                'id' => $credential['id'] ?? '',
                'response' => [
                    'authenticatorData' => $credential['response']['authenticatorData'] ?? '',
                    'clientDataJSON' => $credential['response']['clientDataJSON'] ?? '',
                    'signature' => $credential['response']['signature'] ?? ''
                ]
            ];
            $auth = new MetaPasskeyAuth();
            $rawUserId = (string)$auth->verifyAuthentication($challengeId, $assertion);
            $username = mh_auth_resolve_username_from_login_id($rawUserId);
            if ($username !== '' && $rawUserId !== '' && $username !== $rawUserId && method_exists($auth, 'migrateUserCredentials')) {
                try { $auth->migrateUserCredentials($rawUserId, $username); } catch (Throwable $e) {}
            }
            $_SESSION['mh_auth_user'] = $username !== '' ? $username : $rawUserId;
            $_SESSION['mh_auth_method'] = 'passkey';
            mh_auth_load_user_context($_SESSION['mh_auth_user']);
            mh_login_try_grid_bootstrap_for_user((string)$_SESSION['mh_auth_user']);
            try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
            $pdoBio = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
            $pepper = function_exists('mh_remember_me_get_pepper') ? mh_remember_me_get_pepper() : null;
            $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
            $u = (string)($_SESSION['mh_auth_user'] ?? '');
            if ($pdoBio instanceof PDO && is_string($pepper) && trim($pepper) !== '' && $uid > 0 && $u !== '' && function_exists('mh_remember_me_issue_or_rotate_cookie')) {
                mh_remember_me_ensure_schema_once($pdoBio);
                $ctx = function_exists('mh_remember_me_get_context') ? mh_remember_me_get_context() : [];
                $device = function_exists('mh_remember_me_resolve_device') ? mh_remember_me_resolve_device($pdoBio, $pepper, $uid, $ctx) : ['recognized' => false, 'device_token_id' => null, 'row' => null];
                $reason = $_SESSION['mh_auth_method'] === 'passkey' ? 'passkey_login' : 'pin_login';
                mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $uid, $device, $ctx, $reason);
            }
        } catch (Throwable $e) {}
            $dest = mh_post_login_destination(isset($_GET['redirect']) ? (string)$_GET['redirect'] : '');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            auth_json_response(['success' => true, 'userId' => $_SESSION['mh_auth_user'], 'redirect' => $dest]);
            exit;
        }
        if ($action === 'pin_login') {
            $userId = isset($input['userId']) ? trim((string)$input['userId']) : '';
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : '';
            if ($userId === '' || $pin === '') {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }
            if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                exit;
            }
            try {
                $pinBackup = new MetaPinBackup();
                $pinBackup->verifyPin($userId, $pin);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'no pin set for user') !== false) {
                    auth_json_response([
                        'success' => false,
                        'error' => 'no_pin_set',
                        'message' => 'No PIN is set for this user. Login with passkey and set a PIN in /hub/settings.php.',
                    ], 400);
                    exit;
                }
                auth_json_response(['success' => false, 'error' => 'invalid_pin', 'message' => $msg], 400);
                exit;
            }
            $username = mh_auth_resolve_username_from_login_id($userId);
            $_SESSION['mh_auth_user'] = $username !== '' ? $username : $userId;
            $_SESSION['mh_auth_method'] = 'pin';
            mh_auth_load_user_context($_SESSION['mh_auth_user']);
            mh_login_try_grid_bootstrap_for_user((string)$_SESSION['mh_auth_user']);
            try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
            $pdoBio = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
            $pepper = function_exists('mh_remember_me_get_pepper') ? mh_remember_me_get_pepper() : null;
            $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
            $u = (string)($_SESSION['mh_auth_user'] ?? '');
            if ($pdoBio instanceof PDO && is_string($pepper) && trim($pepper) !== '' && $uid > 0 && $u !== '' && function_exists('mh_remember_me_issue_or_rotate_cookie')) {
                mh_remember_me_ensure_schema_once($pdoBio);
                $ctx = function_exists('mh_remember_me_get_context') ? mh_remember_me_get_context() : [];
                $device = function_exists('mh_remember_me_resolve_device') ? mh_remember_me_resolve_device($pdoBio, $pepper, $uid, $ctx) : ['recognized' => false, 'device_token_id' => null, 'row' => null];
                $reason = $_SESSION['mh_auth_method'] === 'passkey' ? 'passkey_login' : 'pin_login';
                mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $uid, $device, $ctx, $reason);
            }
        } catch (Throwable $e) {}
            $dest = mh_post_login_destination(isset($_GET['redirect']) ? (string)$_GET['redirect'] : '');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            auth_json_response(['success' => true, 'userId' => $_SESSION['mh_auth_user'], 'redirect' => $dest]);
            exit;
        }
        if ($action === 'check_user_status') {
            $username = isset($input['username']) ? trim((string)$input['username']) : '';
            if ($username === '') {
                auth_json_response(['success' => false, 'error' => 'missing_username'], 400);
                exit;
            }
            try {
                mh_validate_username_strict($username);
            } catch (Throwable $e) {
                auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => $e->getMessage()], 400);
                exit;
            }
            $exists = false;
            $hasPin = false;
            $hasPasskeys = false;
            try {
                $bioConfig = null;
                if (function_exists('database_getConfiguration')) {
                    $bioConfig = database_getConfiguration('biometrics');
                } elseif (function_exists('cue_autoload')) {
                    cue_autoload('database');
                    if (function_exists('database_getConfiguration')) {
                        $bioConfig = database_getConfiguration('biometrics');
                    }
                }
                if ($bioConfig) {
                    $bioConfig = (array)$bioConfig;
                    $pdoBio = database_getConnectionById('biometrics');
                    $stmt = $pdoBio->prepare("SELECT id, pin_hash FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user) {
                        $exists = true;
                        $hasPin = !empty($user['pin_hash']);
                    }
                }
            } catch (Exception $e) {
                error_log("Check User Error: " . $e->getMessage());
            }
            try {
                $authCheck = new MetaPasskeyAuth();
                if (method_exists($authCheck, 'hasUserPasskeys')) {
                    $hasPasskeys = (bool)$authCheck->hasUserPasskeys($username);
                }
            } catch (Throwable) {
                $hasPasskeys = false;
            }
            auth_json_response(['success' => true, 'exists' => $exists, 'has_pin' => $hasPin, 'has_passkeys' => $hasPasskeys]);
            exit;
        }

        if ($action === 'verify_pin_and_start_add_device') {
            $username = isset($input['username']) ? trim((string)$input['username']) : '';
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : '';
            if ($username === '' || $pin === '') {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }
            try {
                $pinBackup = new MetaPinBackup();
                $pinBackup->verifyPin($username, $pin);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'no pin set for user') !== false) {
                    auth_json_response([
                        'success' => false,
                        'error' => 'no_pin_set',
                        'message' => 'No PIN is set for this user. Login with passkey and set a PIN in /hub/settings.php, then retry adding this device.',
                    ], 400);
                    exit;
                }
                auth_json_response(['success' => false, 'error' => 'invalid_pin', 'message' => $msg], 400);
                exit;
            }
            $userId = $username;
            $displayName = $username; 
            $auth = new MetaPasskeyAuth();
            $challenge = $auth->generateRegistrationChallenge($userId, $username, $displayName);
            $_SESSION['mh_reg_username'] = $username;
            $_SESSION['mh_reg_display'] = $displayName;
            $_SESSION['mh_reg_mode'] = 'add_device';
            $_SESSION['mh_reg_pin'] = $pin;
            auth_json_response([
                'success' => true,
                'challengeId' => $challenge['challengeId'],
                'publicKey' => $challenge['options']
            ]);
            exit;
        }

        if ($action === 'start_passkey_registration') {
            $username = isset($input['username']) ? trim((string)$input['username']) : '';
            $firstName = isset($input['firstName']) ? trim((string)$input['firstName']) : '';
            $surname = isset($input['surname']) ? trim((string)$input['surname']) : '';
            $displayName = isset($input['displayName']) ? trim((string)$input['displayName']) : '';
            $personaName = isset($input['persona_id']) ? trim((string)$input['persona_id']) : '';
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : '';
            $deviceId = isset($input['deviceId']) ? trim((string)$input['deviceId']) : '';

            if ($displayName !== '' && ($firstName === '' || $surname === '')) {
                $parts = preg_split('/\s+/', $displayName);
                $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                if (count($parts) >= 2) {
                    if ($firstName === '') $firstName = (string)$parts[0];
                    if ($surname === '') $surname = (string)$parts[count($parts) - 1];
                }
            }
            if ($displayName === '' && $firstName !== '' && $surname !== '') {
                $displayName = trim($firstName . ' ' . $surname);
            }

            if ($username === '' || $displayName === '' || $personaName === '' || $pin === '') {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }
            try {
                mh_validate_username_strict($username);
                if (function_exists('mh_registration_validate_real_first_last_for_registration')) {
                    mh_registration_validate_real_first_last_for_registration($firstName, $surname);
                } else {
                    mh_validate_real_first_and_surname_strict($firstName, $surname);
                }
            } catch (Throwable $e) {
                auth_json_response(['success' => false, 'error' => 'invalid_fields', 'message' => $e->getMessage()], 400);
                exit;
            }
            
            if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                exit;
            }
            // Prevent duplicate registrations: username exists or passkey already present
            try {
                $bioConfig = function_exists('database_getConfiguration') ? database_getConfiguration('biometrics') : null;
                if ($bioConfig) {
                    $pdoBio = database_getConnectionById('biometrics');
                    try {
                        if (function_exists('mh_registration_seed_default_policy_rules')) {
                            mh_registration_seed_default_policy_rules($pdoBio);
                        }
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
                        if (function_exists('mh_registration_rate_limit_check')) {
                            $ipCheck = mh_registration_rate_limit_check($pdoBio, 'ip', $ipAddress, 10, 600, 900);
                            if (is_array($ipCheck) && (($ipCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $fpCheck = mh_registration_rate_limit_check($pdoBio, 'fingerprint', $deviceFingerprint, 5, 600, 3600);
                            if (is_array($fpCheck) && (($fpCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $uCheck = mh_registration_rate_limit_check($pdoBio, 'username', $username, 10, 3600, 3600);
                            if (is_array($uCheck) && (($uCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                        }
                        if (function_exists('mh_registration_policy_evaluate')) {
                            $checks = [
                                ['real_first_name', $firstName, 'invalid_name', 'Real name is invalid.'],
                                ['real_last_name', $surname, 'invalid_name', 'Real surname is invalid.'],
                                ['username', $username, 'invalid_username', 'Username is invalid.'],
                                ['persona_name', $personaName, 'persona_invalid', 'Persona name is invalid.'],
                            ];
                            foreach ($checks as $c) {
                                $scope = (string)$c[0];
                                $val = (string)$c[1];
                                $policy = mh_registration_policy_evaluate($pdoBio, $scope, $val);
                                if (is_array($policy) && (($policy['action'] ?? 'reject') === 'review')) {
                                    if (function_exists('mh_registration_review_enqueue')) {
                                        mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, $scope, (string)($policy['reason'] ?? 'policy'), $val);
                                    }
                                    auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                    exit;
                                }
                                if (is_array($policy)) {
                                    if (function_exists('mh_registration_review_enqueue')) {
                                        mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, $scope, (string)($policy['reason'] ?? 'policy'), $val);
                                    }
                                    auth_json_response(['success' => false, 'error' => (string)$c[2], 'message' => (string)$c[3]], 400);
                                    exit;
                                }
                            }
                        }
                    } catch (Throwable) {}
                    try {
                        $stDel = $pdoBio->prepare("SELECT 1 FROM mh_deleted_users WHERE username = ? LIMIT 1");
                        $stDel->execute([$username]);
                        if ((bool)$stDel->fetchColumn()) {
                            auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username is not available.'], 400);
                            exit;
                        }
                    } catch (Throwable) {}
                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username already exists'], 400);
                        exit;
                    }

                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE persona_name = ?");
                    $stmt->execute([$personaName]);
                    if ($stmt->fetch()) {
                        auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                        exit;
                    }
                    try {
                        $regPath = __DIR__ . '/persona_registry.php';
                        if (is_file($regPath)) {
                            require_once $regPath;
                            $pdoReg = mh_persona_registry_pdo();
                            $stmt = $pdoReg->prepare("SELECT 1 FROM mh_personas WHERE persona_name = ? LIMIT 1");
                            $stmt->execute([$personaName]);
                            if ($stmt->fetch()) {
                                auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                                exit;
                            }
                        }
                    } catch (Throwable) {}
                }
                $authCheck = new MetaPasskeyAuth();
                if (method_exists($authCheck, 'hasUserPasskeys') && $authCheck->hasUserPasskeys($username)) {
                    auth_json_response(['success' => false, 'error' => 'passkey_exists', 'message' => 'A passkey is already registered for this user'], 400);
                    exit;
                }
            } catch (Throwable $e) {}
            $userId = $username;
            $auth = new MetaPasskeyAuth();
            $challenge = $auth->generateRegistrationChallenge($userId, $username, $displayName);
            $_SESSION['mh_reg_username'] = $username;
            $_SESSION['mh_reg_display'] = $displayName;
            $_SESSION['mh_reg_first'] = $firstName;
            $_SESSION['mh_reg_last'] = $surname;
            $_SESSION['mh_reg_persona'] = $personaName;
            $_SESSION['mh_reg_pin'] = $pin;
            $_SESSION['mh_reg_device_id'] = $deviceId;
            
            auth_json_response([
                'success' => true,
                'challengeId' => $challenge['challengeId'],
                'publicKey' => $challenge['options']
            ]);
            exit;
        }
        if ($action === 'finish_passkey_registration') {
            $challengeId = isset($input['challengeId']) ? (string)$input['challengeId'] : '';
            $credential = $input['credential'] ?? [];
            $username = isset($input['username']) ? trim((string)$input['username']) : ($_SESSION['mh_reg_username'] ?? '');
            $displayName = isset($input['displayName']) ? trim((string)$input['displayName']) : ($_SESSION['mh_reg_display'] ?? '');
            $personaName = isset($input['persona_id']) ? trim((string)$input['persona_id']) : ($_SESSION['mh_reg_persona'] ?? '');
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : ($_SESSION['mh_reg_pin'] ?? '');
            $mode = $_SESSION['mh_reg_mode'] ?? 'new_user';

            if ($challengeId === '' || !is_array($credential) || $username === '') {
                auth_json_response(['success' => false, 'error' => 'invalid_payload'], 400);
                exit;
            }

            // For new users, validate all fields
            if ($mode === 'new_user') {
                if ($displayName === '' || $personaName === '' || $pin === '') {
                     auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                     exit;
                }
                try {
                    mh_validate_username_strict($username);
                    mh_validate_real_name_strict($displayName);
                    $fn = isset($_SESSION['mh_reg_first']) ? trim((string)$_SESSION['mh_reg_first']) : '';
                    $ln = isset($_SESSION['mh_reg_last']) ? trim((string)$_SESSION['mh_reg_last']) : '';
                    if (($fn === '' || $ln === '') && $displayName !== '') {
                        $parts = preg_split('/\s+/', $displayName);
                        $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                        if (count($parts) >= 2) {
                            if ($fn === '') $fn = (string)$parts[0];
                            if ($ln === '') $ln = (string)$parts[count($parts) - 1];
                        }
                    }
                    if ($fn !== '' && $ln !== '') {
                        if (function_exists('mh_registration_validate_real_first_last_for_registration')) {
                            mh_registration_validate_real_first_last_for_registration($fn, $ln);
                        } else {
                            mh_validate_real_first_and_surname_strict($fn, $ln);
                        }
                    }
                } catch (Throwable $e) {
                    auth_json_response(['success' => false, 'error' => 'invalid_fields', 'message' => $e->getMessage()], 400);
                    exit;
                }
                if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                    auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                    exit;
                }
            }

            $auth = new MetaPasskeyAuth();
            $auth->verifyRegistration($challengeId, $credential);

            // Save user to Biometrics Database
            try {
                $bioConfig = null;
                if (function_exists('database_getConfiguration')) {
                    $bioConfig = database_getConfiguration('biometrics');
                } elseif (function_exists('cue_autoload')) {
                    cue_autoload('database');
                    if (function_exists('database_getConfiguration')) {
                        $bioConfig = database_getConfiguration('biometrics');
                    }
                }

                if ($bioConfig) {
                    $bioConfig = (array)$bioConfig; 
                    $pdoBio = database_getConnectionById('biometrics');
                    
                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $existingUser = $stmt->fetch();

                    if (!$existingUser) {
                        try {
                            $regPath = __DIR__ . '/persona_registry.php';
                            if (is_file($regPath)) {
                                require_once $regPath;
                                $pdoReg = mh_persona_registry_pdo();
                                $stmt = $pdoReg->prepare("SELECT owner_username FROM mh_personas WHERE persona_name = ? LIMIT 1");
                                $stmt->execute([$personaName]);
                                $personaRow = $stmt->fetch(PDO::FETCH_ASSOC);
                                if (is_array($personaRow) && isset($personaRow['owner_username']) && (string)$personaRow['owner_username'] !== $username) {
                                    auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                                    exit;
                                }
                            }
                        } catch (Throwable) {}
                        $stmt = $pdoBio->prepare("SELECT username FROM users WHERE persona_name = ? LIMIT 1");
                        $stmt->execute([$personaName]);
                        $personaUser = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (is_array($personaUser) && isset($personaUser['username']) && (string)$personaUser['username'] !== $username) {
                            auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                            exit;
                        }

                        // Create New User
                        $tenantId = 'user:' . $username;
                        $deviceId = isset($input['deviceId']) ? trim((string)$input['deviceId']) : ($_SESSION['mh_reg_device_id'] ?? '');
                        $firstName = isset($_SESSION['mh_reg_first']) ? trim((string)$_SESSION['mh_reg_first']) : '';
                        $surname = isset($_SESSION['mh_reg_last']) ? trim((string)$_SESSION['mh_reg_last']) : '';
                        if ($firstName === '' || $surname === '') {
                            $parts = preg_split('/\s+/', (string)$displayName);
                            $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                            if (count($parts) >= 2) {
                                if ($firstName === '') $firstName = (string)$parts[0];
                                if ($surname === '') $surname = (string)$parts[count($parts) - 1];
                            }
                        }

                        // Capture IP and Device Fingerprint for Policy Enforcement
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

                        // Ensure columns exist
                        try { $pdoBio->query("SELECT tenant_id FROM users LIMIT 1"); } catch (Exception $e) { $pdoBio->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL AFTER persona_name"); }
                        try { $pdoBio->query("SELECT device_id FROM users LIMIT 1"); } catch (Exception $e) { $pdoBio->exec("ALTER TABLE users ADD COLUMN device_id VARCHAR(255) DEFAULT NULL AFTER tenant_id"); }

                        try {
                            mh_enforce_registration_policy($pdoBio, $ipAddress, $deviceFingerprint, $deviceId);
                        } catch (Throwable $e) {
                            if ((int)$e->getCode() === 1001) {
                                auth_json_response([
                                    'success' => false,
                                    'error' => 'device_already_registered',
                                    'message' => 'This device already has an account—continue to login.',
                                    'switch_to_login' => true,
                                ], 200);
                                exit;
                            }
                            if ((int)$e->getCode() === 1002) {
                                auth_json_response(['success' => false, 'error' => 'registration_limit', 'message' => 'Registration limit reached for this network. Please log in with an existing account.'], 400);
                                exit;
                            }
                            auth_json_response(['success' => false, 'error' => 'registration_limit', 'message' => 'Registration is not available for this device/network.'], 400);
                            exit;
                        }

                        if (function_exists('mh_ensure_user_real_name_schema')) {
                            mh_ensure_user_real_name_schema($pdoBio);
                        }
                        $stmt = $pdoBio->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, device_id, ip_address, device_fingerprint, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'client', NOW())");
                        $stmt->execute([$username, $displayName, $firstName, $surname, $personaName, $tenantId, $deviceId, $ipAddress, $deviceFingerprint]);
                        try {
                            $regPath = __DIR__ . '/persona_registry.php';
                            if (is_file($regPath)) {
                                require_once $regPath;
                                $pdoReg = mh_persona_registry_pdo();
                                mh_persona_registry_claim($pdoReg, $username, $personaName);
                            }
                        } catch (Throwable) {}
                    }

                    if ($pin !== '') {
                        $pinBackup = new MetaPinBackup();
                        $pinBackup->setPinForUser($username, $pin);
                    }
                }
            } catch (Exception $e) {
                error_log("Biometrics Insert Error (Login.php): " . $e->getMessage());
            }

            unset($_SESSION['mh_reg_username'], $_SESSION['mh_reg_display'], $_SESSION['mh_reg_persona'], $_SESSION['mh_reg_pin'], $_SESSION['mh_reg_mode'], $_SESSION['mh_reg_first'], $_SESSION['mh_reg_last']);
            
            // Auto-login
            $_SESSION['mh_auth_user'] = $username;
            $_SESSION['mh_auth_method'] = 'passkey';
            mh_auth_load_user_context($username);
            mh_apply_tenant_context('user:' . $username);

            if (!isset($_SESSION['mh_auth_display']) || $_SESSION['mh_auth_display'] !== $displayName) {
                $_SESSION['mh_auth_display'] = $displayName;
            }
            if ($personaName !== '' && (!isset($_SESSION['mh_auth_persona']) || $_SESSION['mh_auth_persona'] !== $personaName)) {
                $_SESSION['mh_auth_persona'] = $personaName;
            }

            auth_json_response(['success' => true, 'userId' => $username]);
            exit;
        }
        if ($action === 'pin_register') {
            $username = isset($input['username']) ? trim((string)$input['username']) : '';
            $tenantId = 'user:' . $username;
            $firstName = isset($input['firstName']) ? trim((string)$input['firstName']) : '';
            $surname = isset($input['surname']) ? trim((string)$input['surname']) : '';
            $displayName = isset($input['displayName']) ? trim((string)$input['displayName']) : '';
            $personaName = isset($input['persona_id']) ? trim((string)$input['persona_id']) : '';
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : '';
            $deviceId = isset($input['deviceId']) ? trim((string)$input['deviceId']) : '';
            if ($username === '' || $personaName === '' || $pin === '' || (($firstName === '' || $surname === '') && $displayName === '')) {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }
            try {
                mh_validate_username_strict($username);
                if ($displayName !== '' && ($firstName === '' || $surname === '')) {
                    $parts = preg_split('/\s+/', $displayName);
                    $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                    if (count($parts) >= 2) {
                        if ($firstName === '') $firstName = (string)$parts[0];
                        if ($surname === '') $surname = (string)$parts[count($parts) - 1];
                    }
                }
                if ($displayName === '' && $firstName !== '' && $surname !== '') {
                    $displayName = trim($firstName . ' ' . $surname);
                }
                if (function_exists('mh_registration_validate_real_first_last_for_registration')) {
                    mh_registration_validate_real_first_last_for_registration($firstName, $surname);
                } else {
                    mh_validate_real_first_and_surname_strict($firstName, $surname);
                }
            } catch (Throwable $e) {
                auth_json_response(['success' => false, 'error' => 'invalid_fields', 'message' => $e->getMessage()], 400);
                exit;
            }
            if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                exit;
            }
            try {
                $bioConfig = null;
                if (function_exists('database_getConfiguration')) {
                    $bioConfig = database_getConfiguration('biometrics');
                } elseif (function_exists('cue_autoload')) {
                    cue_autoload('database');
                    if (function_exists('database_getConfiguration')) {
                        $bioConfig = database_getConfiguration('biometrics');
                    }
                }
                if ($bioConfig) {
                    $bioConfig = (array)$bioConfig;
                    $pdoBio = database_getConnectionById('biometrics');
                    try {
                        if (function_exists('mh_registration_seed_default_policy_rules')) {
                            mh_registration_seed_default_policy_rules($pdoBio);
                        }
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
                        if (function_exists('mh_registration_rate_limit_check')) {
                            $ipCheck = mh_registration_rate_limit_check($pdoBio, 'ip', $ipAddress, 10, 600, 900);
                            if (is_array($ipCheck) && (($ipCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $fpCheck = mh_registration_rate_limit_check($pdoBio, 'fingerprint', $deviceFingerprint, 5, 600, 3600);
                            if (is_array($fpCheck) && (($fpCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $uCheck = mh_registration_rate_limit_check($pdoBio, 'username', $username, 10, 3600, 3600);
                            if (is_array($uCheck) && (($uCheck['ok'] ?? true) === false)) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                        }
                        if (function_exists('mh_registration_policy_evaluate')) {
                            $checks = [
                                ['real_first_name', $firstName, 'invalid_name', 'Real name is invalid.'],
                                ['real_last_name', $surname, 'invalid_name', 'Real surname is invalid.'],
                                ['username', $username, 'invalid_username', 'Username is invalid.'],
                                ['persona_name', $personaName, 'persona_invalid', 'Persona name is invalid.'],
                            ];
                            foreach ($checks as $c) {
                                $scope = (string)$c[0];
                                $val = (string)$c[1];
                                $policy = mh_registration_policy_evaluate($pdoBio, $scope, $val);
                                if (is_array($policy) && (($policy['action'] ?? 'reject') === 'review')) {
                                    if (function_exists('mh_registration_review_enqueue')) {
                                        mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, $scope, (string)($policy['reason'] ?? 'policy'), $val);
                                    }
                                    auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                    exit;
                                }
                                if (is_array($policy)) {
                                    if (function_exists('mh_registration_review_enqueue')) {
                                        mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, $scope, (string)($policy['reason'] ?? 'policy'), $val);
                                    }
                                    auth_json_response(['success' => false, 'error' => (string)$c[2], 'message' => (string)$c[3]], 400);
                                    exit;
                                }
                            }
                        }
                    } catch (Throwable) {}
                    try {
                        $stDel = $pdoBio->prepare("SELECT 1 FROM mh_deleted_users WHERE username = ? LIMIT 1");
                        $stDel->execute([$username]);
                        if ((bool)$stDel->fetchColumn()) {
                            auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username is not available.'], 400);
                            exit;
                        }
                    } catch (Throwable) {}
                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username already exists'], 400);
                        exit;
                    }

                    try {
                        $regPath = __DIR__ . '/persona_registry.php';
                        if (is_file($regPath)) {
                            require_once $regPath;
                            $pdoReg = mh_persona_registry_pdo();
                            $stmt = $pdoReg->prepare("SELECT owner_username FROM mh_personas WHERE persona_name = ? LIMIT 1");
                            $stmt->execute([$personaName]);
                            $personaRow = $stmt->fetch(PDO::FETCH_ASSOC);
                            if (is_array($personaRow) && isset($personaRow['owner_username'])) {
                                auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                                exit;
                            }
                        }
                    } catch (Throwable) {}
                    $stmt = $pdoBio->prepare("SELECT username FROM users WHERE persona_name = ? LIMIT 1");
                    $stmt->execute([$personaName]);
                    $personaUser = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($personaUser) && isset($personaUser['username'])) {
                        auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Meta Human Persona name already exists'], 400);
                        exit;
                    }
                    $tenantId = 'user:' . $username;
                    $deviceId = isset($input['deviceId']) ? trim((string)$input['deviceId']) : '';

                    // Capture IP and Device Fingerprint for Policy Enforcement
                    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

                    try { $pdoBio->query("SELECT tenant_id FROM users LIMIT 1"); } catch (Exception $e) { $pdoBio->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL AFTER persona_name"); }
                    try { $pdoBio->query("SELECT device_id FROM users LIMIT 1"); } catch (Exception $e) { $pdoBio->exec("ALTER TABLE users ADD COLUMN device_id VARCHAR(255) DEFAULT NULL AFTER tenant_id"); }

                    try {
                        mh_enforce_registration_policy($pdoBio, $ipAddress, $deviceFingerprint, $deviceId);
                    } catch (Throwable $e) {
                        if ((int)$e->getCode() === 1001) {
                            auth_json_response([
                                'success' => false,
                                'error' => 'device_already_registered',
                                'message' => 'This device already has an account—continue to login.',
                                'switch_to_login' => true,
                            ], 200);
                            exit;
                        }
                        if ((int)$e->getCode() === 1002) {
                            auth_json_response(['success' => false, 'error' => 'registration_limit', 'message' => 'Registration limit reached for this network. Please log in with an existing account.'], 400);
                            exit;
                        }
                        auth_json_response(['success' => false, 'error' => 'registration_limit', 'message' => 'Registration is not available for this device/network.'], 400);
                        exit;
                    }

                    if (function_exists('mh_ensure_user_real_name_schema')) {
                        mh_ensure_user_real_name_schema($pdoBio);
                    }
                    $stmt = $pdoBio->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, device_id, ip_address, device_fingerprint, role, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'client', NOW())");
                    $stmt->execute([$username, $displayName, $firstName, $surname, $personaName, $tenantId, $deviceId, $ipAddress, $deviceFingerprint]);
                    try {
                        $regPath = __DIR__ . '/persona_registry.php';
                        if (is_file($regPath)) {
                            require_once $regPath;
                            $pdoReg = mh_persona_registry_pdo();
                            mh_persona_registry_claim($pdoReg, $username, $personaName);
                        }
                    } catch (Throwable) {}
                }
                $pinBackup = new MetaPinBackup();
                $pinBackup->setPinForUser($username, $pin);
                $_SESSION['mh_auth_user'] = $username;
                $_SESSION['mh_auth_method'] = 'pin';
                $_SESSION['mh_auth_display'] = $displayName;
                $_SESSION['mh_auth_persona'] = $personaName;
                mh_auth_load_user_context($username);
                mh_apply_tenant_context($tenantId);
                auth_json_response(['success' => true, 'userId' => $username]);
                exit;
            } catch (Throwable $e) {
                auth_json_response(['success' => false, 'error' => 'auth_error', 'message' => $e->getMessage()], 400);
                exit;
            }
        }
        if ($action === 'start_anonymous_registration') {
            auth_json_response([
                'success' => false,
                'error' => 'anonymous_registration_disabled',
                'message' => 'Anonymous registration is disabled. Please use the full registration form.',
                'redirect' => '/auth/register.php'
            ], 400);
            exit;
        }

        auth_json_response(['success' => false, 'error' => 'invalid_action'], 400);

        exit;
    } catch (Throwable $e) {
        auth_json_response([
            'success' => false,
            'error' => 'auth_error',
            'message' => $e->getMessage()
        ], 400);
        exit;
    }
}

$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';
$currentUser = $_SESSION['mh_auth_user'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans Authentication</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        /* Biometric Coverage Popup Styles */
        .mh-bio-coverage-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(12px);
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
            opacity: 0;
            animation: mh-fade-in 0.5s forwards;
        }
        .mh-bio-coverage-card {
            background: rgba(20, 20, 25, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2.5rem;
            width: min(520px, calc(100vw - 32px));
            max-height: calc(100vh - 32px);
            overflow: auto;
            color: #fff;
            box-shadow: 0 0 40px rgba(0, 212, 255, 0.15);
            position: relative;
            font-family: 'Rajdhani', system-ui, sans-serif;
        }
        .mh-bio-coverage-header {
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #00d4ff;
            margin-bottom: 2rem;
            border-bottom: 1px solid rgba(0, 212, 255, 0.3);
            padding-bottom: 0.5rem;
        }
        .mh-bio-section {
            margin-bottom: 2rem;
        }
        .mh-bio-section h3 {
            font-size: 1.1rem;
            color: #fff;
            margin: 0 0 0.5rem 0;
        }
        .mh-bio-section p {
            font-size: 0.95rem;
            color: #a0a0a0;
            line-height: 1.5;
            margin: 0 0 1rem 0;
        }
        .mh-bio-badges {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .mh-bio-badge {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            color: #ccc;
        }
        .mh-bio-badge.active {
            border-color: #00d4ff;
            color: #00d4ff;
            background: rgba(0, 212, 255, 0.1);
        }
        .mh-bio-close {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .mh-bio-close:hover {
            background: rgba(0, 212, 255, 0.2);
            transform: rotate(90deg);
        }
        .mh-bio-entry-btn {
            display: block;
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #00d4ff 0%, #0056b3 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 1.1rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 1rem;
        }
        .mh-bio-entry-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 212, 255, 0.3);
        }
        @keyframes mh-fade-in {
            to { opacity: 1; }
        }

        .mh-auth-popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
            overflow-y: auto;
        }
        .mh-auth-popup {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 16px;
            padding: 2.5rem;
            width: min(420px, calc(100vw - 32px));
            box-sizing: border-box;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            color: #fff;
            font-family: system-ui, -apple-system, sans-serif;
            animation: mh-popup-fade-in 0.3s ease-out;
            max-height: calc(100vh - 32px) !important;
            overflow: auto !important;
        }
        .mh-auth-register-popup {
            max-width: 520px;
            width: min(520px, calc(100vw - 32px));
            text-align: left;
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
        .mh-auth-btn-primary.mh-auth-btn-ready {
            background: #34c759;
            border-color: #34c759;
        }
        .mh-auth-btn-primary.mh-auth-btn-ready:hover {
            background: #28a745;
            border-color: #28a745;
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
        .mh-auth-input-invalid {
            outline: none;
            border: 1px solid rgba(255, 59, 48, 0.8) !important;
            box-shadow: 0 0 0 3px rgba(255, 59, 48, 0.18) !important;
        }

        /* Mobile Responsive Adjustments */
        @media (max-width: 768px) {
            .mh-auth-popup-overlay {
                align-items: flex-start;
                padding-top: 24px;
            }
            .mh-bio-coverage-overlay {
                align-items: flex-start;
                padding-top: 24px;
            }
            .mh-bio-coverage-card, .mh-auth-panel {
                width: 95%;
                padding: 1.5rem;
                max-width: none;
                margin: 1rem auto;
            }
            .mh-auth-popup {
                width: 95%;
                padding: 1.25rem;
                border-radius: 14px;
            }
            .mh-auth-register-popup {
                width: 100% !important;
                max-width: none !important;
            }
            .mh-auth-popup h2 { font-size: 1.35rem; }
            .mh-auth-popup p { font-size: 0.95rem; margin-bottom: 1.25rem; }
            /* Hide global UI widgets on mobile */
            .mh-server-status-widget, .mh-status-indicator, .mh-widget-status, header .status-bar {
                display: none !important;
            }
            .mh-auth-title {
                font-size: 1.75rem;
            }
        }
        
        /* Tooltip Styles */
        .mh-tooltip-container {
            position: relative;
            display: inline-block;
            margin-left: 8px;
            vertical-align: middle;
        }
        .mh-tooltip-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            font-size: 12px;
            color: #00d4ff;
            cursor: help;
        }
        .mh-tooltip-content {
            visibility: hidden;
            width: 240px;
            background-color: #1a1a1a;
            color: #e0e0e0;
            text-align: left;
            border-radius: 8px;
            padding: 12px;
            position: absolute;
            z-index: 1000;
            bottom: 130%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
            border: 1px solid #333;
            font-size: 0.85rem;
            line-height: 1.4;
            pointer-events: none;
        }
        .mh-tooltip-content::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #333 transparent transparent transparent;
        }
        .mh-tooltip-container:hover .mh-tooltip-content {
            visibility: visible;
            opacity: 1;
        }
        /* Login Panel Width Fix */
        .mh-auth-panel {
            max-width: 450px !important;
            margin: 0 auto;
            width: 100%;
        }
        .mh-auth-grid {
            max-width: 900px;
            margin: 0 auto;
            width: 100%;
            justify-content: center;
        }
    </style>
</head>
<body class="mh-auth-body">
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>

<?php
    $action = $_GET['action'] ?? '';
    $rawAuthUser = $_SESSION['mh_auth_user'] ?? '';
    $rawAuthUser = is_string($rawAuthUser) ? trim($rawAuthUser) : '';
    $isTempAuthUser = ($rawAuthUser !== '' && preg_match('/^(MetaHuman_[0-9a-f]{16}|anon_[0-9a-f]+)$/i', $rawAuthUser));
    if ($isTempAuthUser) {
        try {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        } catch (Throwable $e) {}
        header('Location: /auth/register.php');
        exit;
    }
    if (
        isset($_SESSION['mh_auth_user']) &&
        is_string($_SESSION['mh_auth_user']) &&
        trim((string)$_SESSION['mh_auth_user']) !== '' &&
        $action !== 'logout'
    ) {
        // If genesis status < 3, they might need to go to hub to finish setup
        // But if they are just visiting login.php, show the "You are logged in" overlay
        $currentUser = $_SESSION['mh_auth_user'];
        $currentDisplay = $_SESSION['mh_auth_display'] ?? ucfirst($currentUser);
        
        // Check if we are in a redirect loop or invalid state
        if (isset($_GET['redirect_loop'])) {
            header('Location: /auth/logout.php');
            exit;
        }
        try {
            if (function_exists('mh_auth_load_user_context')) {
                mh_auth_load_user_context((string)$currentUser);
            }
        } catch (Throwable) {
        }
        $dest = mh_post_login_destination(isset($_GET['redirect']) ? (string)$_GET['redirect'] : '');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Location: ' . $dest);
        exit;
    }

?>

    <?php if (!$currentUser && !isset($_GET['skip_intro'])): ?>
    <?php
        $showBioPopup = true;
        if (isset($_GET['reauth'])) {
            $showBioPopup = false;
        }
        
        if ($showBioPopup):
    ?>
    <div class="mh-bio-coverage-overlay" id="bioCoveragePopup">
        <div class="mh-bio-coverage-card">
            <button class="mh-bio-close" onclick="closeBioPopup()">&times;</button>
            <div class="mh-bio-coverage-header">Biometric Coverage</div>
            <button class="mh-bio-entry-btn" id="seamlessEntryBtn" style="margin-bottom: 16px;">PASSKEY LOGIN</button>
            <button class="mh-bio-entry-btn" id="bioPinBtn" style="margin-bottom: 12px; background: rgba(0, 212, 255, 0.08); border: 1px solid rgba(0, 212, 255, 0.55); color: #00d4ff;">Username + Pin Login</button>
            <button class="mh-bio-entry-btn" id="bioOnboardBtn" style="margin-bottom: 16px; background: rgba(0, 212, 255, 0.08); border: 1px solid rgba(0, 212, 255, 0.55); color: #00d4ff;">Onboard now</button>
            
            <div class="mh-bio-section">
                <h3>Native device authenticators</h3>
                <p>The Mobile Login flow uses the device’s secure authenticator: Windows Hello, Face ID, Touch ID and Android or iOS biometrics.</p>
                <div class="mh-bio-badges">
                    <span class="mh-bio-badge active">Windows Hello</span>
                    <span class="mh-bio-badge active">Face ID</span>
                    <span class="mh-bio-badge active">Touch ID</span>
                    <span class="mh-bio-badge">Android Biometrics</span>
                </div>
            </div>

            <div class="mh-bio-section">
                <h3>PIN fallback</h3>
                <p>If biometrics are not available, a PIN provides a limited fallback path. PINs are stored as Argon2ID hashes inside an encrypted vault on the server.</p>
                <div class="mh-bio-badges">
                    <span class="mh-bio-badge">Argon2ID</span>
                    <span class="mh-bio-badge">Encrypted vault</span>
                    <span class="mh-bio-badge">Rate limiting</span>
                </div>
            </div>

            <div class="mh-bio-section">
                <h3>Privacy</h3>
                <p>Biometric templates never leave the device. The server only stores public keys and encrypted metadata required to verify signed challenges.</p>
            </div>

            <div class="mh-bio-section">
                <h3>Device capability</h3>
                <p id="mhDeviceCapabilityText" style="margin:0; color:#ccc;">Checking...</p>
            </div>
            
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$currentUser): ?>
    <main class="mh-auth-main">
    <section class="mh-auth-shell">
        <div class="mh-auth-grid">
            <div class="mh-auth-panel">
                <div class="mh-auth-panel-inner mh-auth-panel-inner--cta">
                    <div class="mh-auth-cta-stack">
                        <button type="button" class="mh-auth-btn-glass" id="openRegisterButton">
                            <span class="mh-auth-btn-kicker">START HERE IF YOUR NEW</span>
                            <span class="mh-auth-btn-title">ONBOARD NOW</span>
                        </button>

                        <div class="mh-auth-cta-row">
                            <button class="mh-auth-btn-glass" id="seamlessEntryBtnHero">PASSKEY LOGIN</button>
                            <div class="mh-auth-status" id="heroStatus"></div>
                        </div>

                        <button type="button" id="togglePinLogin" class="mh-auth-btn-glass">LOGIN WITH USERNAME AND PIN</button>
                    </div>

                    <div id="pinMode" style="display:none; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.1);">
                        <div class="mh-auth-field-group">
                            <label>Username</label>
                            <div class="mh-auth-input-shell">
                                <input type="text" id="pinUserId" autocomplete="username" placeholder="Username">
                            </div>
                        </div>
                        <div class="mh-auth-field-group">
                            <label>PIN</label>
                            <div class="mh-auth-input-shell">
                                <input type="password" id="pinCode" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="off" placeholder="At least 5 digits">
                            </div>
                        </div>
                        <button class="mh-auth-button-main" id="pinLoginButton">Sign in with PIN</button>
                        <div class="mh-auth-status" id="pinStatus"></div>
                    </div>
                    
                    <!-- Hidden Elements for compatibility with existing JS -->
                    <div id="passkeyMode" style="display:none;"><input type="hidden" id="passkeyUserId"></div>
                    <div id="webauthnMode" style="display:none;"><input type="hidden" id="webauthnUserId"></div>
                    <div id="modeToggle" style="display:none;"><button data-mode="passkey"></button></div>

                    <?php if ($currentUser): ?>
                        <div class="mh-auth-session-pill">
                            <span class="mh-auth-status-icon ok"></span>
                            <span>Signed in as <strong><?php echo htmlspecialchars((string)$currentUser, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                            <span><a class="mh-auth-logout-link" href="<?php echo htmlspecialchars($baseUrl . '/auth/logout.php', ENT_QUOTES, 'UTF-8'); ?>">Logout</a></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mh-auth-panel">
                <div class="mh-auth-panel-inner" id="bioCoveragePopup">
                    <div style="display:flex; justify-content:center; margin-bottom: 12px;">
                        <div class="mh-auth-pill" style="margin:0;">
                            <span class="mh-auth-pill-dot"></span>
                            <span>Meta Humans Identity Edge</span>
                        </div>
                    </div>
                    <div class="mh-auth-side-title">Biometric Coverage</div>
                    
                    <?php if ($currentUser): ?>
                    <div class="mh-auth-side-section" style="background: rgba(0, 212, 255, 0.1); border: 1px solid #00d4ff; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <div class="mh-auth-side-heading" style="color: #00d4ff;">You are logged in</div>
                        <div class="mh-auth-side-text" style="color: #fff; margin-bottom: 10px;">
                            Identity: <strong><?php echo htmlspecialchars((string)$currentUser, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <a href="/hub/" class="mh-auth-btn mh-auth-btn-primary" style="width: 100%; text-align: center; display: block; box-sizing: border-box; text-decoration: none;">Go to Hub</a>
                    </div>
                    <?php else: ?>
                    <!-- Duplicate Button Removed -->
                    <?php endif; ?>

                    <div class="mh-auth-side-section">
                        <div class="mh-auth-side-heading">Native device authenticators</div>
                        <div class="mh-auth-side-text">
                            The Mobile Login flow uses the device’s secure authenticator:
                            Windows Hello, Face ID, Touch ID and Android or iOS biometrics.
                        </div>
                        <div class="mh-auth-badge-row">
                            <span class="mh-auth-badge accent">Windows Hello</span>
                            <span class="mh-auth-badge accent">Face ID</span>
                            <span class="mh-auth-badge accent">Touch ID</span>
                            <span class="mh-auth-badge">Android Biometrics</span>
                        </div>
                    </div>
                    <div class="mh-auth-side-section">
                        <div class="mh-auth-side-heading">PIN fallback</div>
                        <div class="mh-auth-side-text">
                            If biometrics are not available, a PIN provides a limited fallback path. PINs are stored as Argon2ID hashes inside an encrypted vault on the server.
                        </div>
                        <div class="mh-auth-badge-row">
                            <span class="mh-auth-badge">Argon2ID</span>
                            <span class="mh-auth-badge">Encrypted vault</span>
                            <span class="mh-auth-badge">Rate limiting</span>
                        </div>
                    </div>
                    <div class="mh-auth-side-section">
                        <div class="mh-auth-side-heading">Privacy</div>
                        <div class="mh-auth-side-text">
                            Biometric templates never leave the device. The server only stores public keys and encrypted metadata required to verify signed challenges.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    </main>
    <?php else: ?>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        try {
          const popup = document.getElementById('authPopup');
          if (popup) popup.style.display = 'flex';
        } catch (e) {}
      });
    </script>
    <?php endif; ?>
<?php if (!$currentUser): ?>
<style>
    .mh-auth-btn-glass {
        background: rgba(0, 212, 255, 0.08);
        border: 1px solid rgba(0, 212, 255, 0.55);
        padding: 1rem 1.2rem;
        width: 100%;
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        box-shadow: 0 12px 40px rgba(0, 212, 255, 0.12), inset 0 1px 0 rgba(255,255,255,0.10);
        color: #00d4ff;
        cursor: pointer;
        font-family: inherit;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
        border-radius: 14px;
        font-size: 1rem;
        line-height: 1.2;
    }
    .mh-auth-btn-glass:hover {
        background: rgba(0, 212, 255, 0.14);
        border-color: rgba(0, 212, 255, 0.75);
    }
    .mh-auth-btn-glass:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .mh-auth-cta-stack {
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 22px;
    }
    .mh-auth-cta-stack > button,
    .mh-auth-cta-row {
        width: 100%;
        max-width: 420px;
    }
    .mh-auth-cta-row {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0;
    }
    .mh-auth-panel-inner--cta {
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        min-height: clamp(460px, 62vh, 680px);
    }
    .mh-auth-btn-kicker,
    .mh-auth-btn-title {
        display: block;
        text-align: center;
        color: #00d4ff;
        letter-spacing: 1px;
    }
    .mh-auth-btn-kicker {
        font-size: 1rem;
        font-weight: 700;
        opacity: 0.95;
        margin-bottom: 6px;
    }
    .mh-auth-btn-title {
        font-size: 1rem;
        font-weight: 700;
    }
    #heroStatus:empty {
        display: none;
    }
    .mh-auth-switch {
        position: relative;
        display: inline-block;
        width: 62px;
        height: 32px;
    }
    .mh-auth-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .mh-auth-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 59, 48, 0.16);
        border: 1px solid rgba(255, 59, 48, 0.55);
        transition: 0.2s;
        border-radius: 999px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.10);
    }
    .mh-auth-slider:before {
        position: absolute;
        content: "";
        height: 24px;
        width: 24px;
        left: 4px;
        top: 3px;
        background: #ff3b30;
        transition: 0.2s;
        border-radius: 999px;
        box-shadow: 0 6px 18px rgba(255, 59, 48, 0.25);
    }
    .mh-auth-switch input:checked + .mh-auth-slider {
        background: rgba(52, 199, 89, 0.18);
        border-color: rgba(52, 199, 89, 0.65);
    }
    .mh-auth-switch input:checked + .mh-auth-slider:before {
        background: #34c759;
        box-shadow: 0 6px 18px rgba(52, 199, 89, 0.25);
        transform: translateX(30px);
    }

    /* Modern Scrollbar for Webkit */
    .mh-auth-popup::-webkit-scrollbar {
        width: 8px;
    }
    .mh-auth-popup::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 4px;
    }
    .mh-auth-popup::-webkit-scrollbar-thumb {
        background: rgba(0, 212, 255, 0.3);
        border-radius: 4px;
    }
    .mh-auth-popup::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 212, 255, 0.5);
    }
</style>
<div class="mh-auth-popup-overlay" id="registerOverlay" style="display:none;">
    <div class="mh-auth-popup mh-auth-register-popup">
        <button type="button" id="regOverlayCloseX" aria-label="Close" style="position:absolute; top: 14px; right: 14px; background: transparent; border: 1px solid rgba(0, 212, 255, 0.35); color: #00d4ff; width: 34px; height: 34px; border-radius: 10px; cursor: pointer; line-height: 0; font-size: 18px;">×</button>
        <h2 style="text-align:center;" id="regModalTitle">Let's onboard you</h2>

        <!-- EXISTING: Form Container (Hidden by default) -->
        <div id="regFormContainer">
            <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                <label>Type a username to see if it’s free
                    <div class="mh-tooltip-container">
                        <div class="mh-tooltip-icon">?</div>
                        <div class="mh-tooltip-content">Add a username (minimum 5 characters), do not use spaces. It can contain letters or numbers only. The username must be unique to you and you must remember it to gain access to this account.</div>
                    </div>
                </label>
                <div class="mh-auth-input-shell">
                    <input type="text" id="regUsername" autocomplete="username" placeholder="Type a username to see if it’s free">
                </div>
            </div>
            <input type="text" id="regWebsite" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">

            <div class="mh-auth-field-group" style="margin-bottom:1rem; border: 1px solid rgba(0, 212, 255, 0.3); border-radius: 8px; padding: 12px; background: rgba(0, 212, 255, 0.05);">
                <div style="font-weight: bold; color: #00d4ff; margin-bottom: 10px; font-size: 0.9rem; text-align:center;">Onboarding Terms and Conditions</div>
                <div style="display:flex; justify-content:center; margin-bottom: 10px;">
                    <label class="mh-auth-switch" for="regTermsCheck" aria-label="Accept onboarding terms">
                        <input type="checkbox" id="regTermsCheck">
                        <span class="mh-auth-slider"></span>
                    </label>
                </div>
                <label for="regTermsCheck" style="font-size: 0.85rem; line-height: 1.4; color: #ccc; cursor: pointer; display:block;">
                    <strong>I confirm that I am 18 years or older.</strong> By creating an account and a Meta Human, I accept full responsibility for all actions and processes undertaken with my Meta Human. I acknowledge that no information is shared with others, and I alone have access to my account, which I must protect.<br><br>
                    I understand that information shared or created using the system—such as songs, videos, code, and personas—is managed by multiple shared large language models (LLMs) and contributes to my Meta Human’s knowledge base.<br><br>
                    I confirm that all data I share, build, or create is stored in encrypted form and accessible only by me.<br><br>
                    I acknowledge that system use requires tokens, which do not expire once purchased, and that I cannot use the system without tokens.<br><br>
                    Complete terms and conditions are available by asking your Meta Human.<br><br>
                    <strong>I confirm that I have read, understood, and agree to the terms and conditions.</strong>
                </label>
            </div>
            
            <div id="regNewUserFields" style="display:none;">
                <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                    <label>Real Name
                        <div class="mh-tooltip-container">
                            <div class="mh-tooltip-icon">?</div>
                            <div class="mh-tooltip-content">Exactly as on your ID/Passport/Drivers License as this will become important for payout requests.</div>
                        </div>
                    </label>
                    <div class="mh-auth-input-shell">
                        <input type="text" id="regRealName" autocomplete="given-name">
                    </div>
                </div>
                <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                    <label>Real Surname</label>
                    <div class="mh-auth-input-shell">
                        <input type="text" id="regRealSurname" autocomplete="family-name">
                    </div>
                    <div style="opacity: 0.8; font-size: 12px; margin-top: 6px;">Exactly as on your ID/Passport/Drivers License as this will become important for payout requests.</div>
                </div>
                <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                    <label>Give a name to your Meta Human Persona
                        <div class="mh-tooltip-container">
                            <div class="mh-tooltip-icon">?</div>
                            <div class="mh-tooltip-content">Your public Meta Human Persona name. Must be unique.</div>
                        </div>
                    </label>
                    <div class="mh-auth-input-shell">
                        <input type="text" id="regPersonaId" autocomplete="off" placeholder="Give a name to your Meta Human Persona">
                    </div>
                </div>
                <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                    <label>Device ID (Auto-generated)
                        <div class="mh-tooltip-container">
                            <div class="mh-tooltip-icon">?</div>
                            <div class="mh-tooltip-content">A unique ID for this specific device.</div>
                        </div>
                    </label>
                    <div class="mh-auth-input-shell">
                        <input type="text" id="regDeviceId" disabled style="opacity: 0.7; cursor: not-allowed;">
                    </div>
                </div>
            </div>

            <div id="regPinField" style="display:none;">
                <div class="mh-auth-field-group" style="margin-bottom:1rem;">
                    <label id="regPinLabel">PIN (minimum 5 digits)
                        <div class="mh-tooltip-container">
                            <div class="mh-tooltip-icon">?</div>
                            <div class="mh-tooltip-content">A backup access code hashed with Argon2ID for security. Used if biometrics fail.</div>
                        </div>
                    </label>
                    <div class="mh-auth-input-shell">
                        <input type="password" id="regPin" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="off">
                    </div>
                </div>
                <div class="mh-auth-field-group" style="margin-bottom:1rem;" id="regPinConfirmField">
                    <label>Confirm PIN</label>
                    <div class="mh-auth-input-shell">
                        <input type="password" id="regPinConfirm" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="mh-auth-popup-actions">
                <button type="button" class="mh-auth-btn mh-auth-btn-primary" id="regContinueButton">Continue</button>
                <button type="button" class="mh-auth-btn mh-auth-btn-primary" id="registerButton" style="display:none;">Create passkey</button>
                <button type="button" class="mh-auth-btn mh-auth-btn-secondary" id="closeRegisterButton">Cancel</button>
            </div>
            <div class="mh-auth-status" id="registerStatus" style="margin-top:1rem;"></div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
    // Check if anonymous "Seamless Entry" is allowed in production
    // Set to true to enable, false to disable
    const ALLOW_ANONYMOUS_ENTRY = false; 

    function mh_emit_auth_event(type) {
        try {
            if (typeof window.mhBroadcastAuthEvent === 'function') {
                window.mhBroadcastAuthEvent(type);
                return;
            }
            const payload = JSON.stringify({ type: type, ts: Date.now() });
            try { localStorage.setItem('mh_auth_event', payload); } catch (e) {}
            try {
                const bc = new BroadcastChannel('mh-auth');
                bc.postMessage({ type: type, ts: Date.now() });
                bc.close();
            } catch (e) {}
        } catch (e) {}
    }

    // Biometric Popup Logic
    const bioPopup = document.getElementById('bioCoveragePopup');
    const seamlessBtn = document.getElementById('seamlessEntryBtn');
    const bioPinBtn = document.getElementById('bioPinBtn');
    const bioOnboardBtn = document.getElementById('bioOnboardBtn');
    const deviceCapText = document.getElementById('mhDeviceCapabilityText');
    const sideEnterBtn = document.getElementById('sideEnterMetaHumans');

    function closeBioPopup() {
        if (bioPopup) {
             bioPopup.style.opacity = '0';
             setTimeout(() => bioPopup.remove(), 300);
        }
    }

    if (bioPinBtn) {
        bioPinBtn.addEventListener('click', () => {
            closeBioPopup();
            const toggle = document.getElementById('togglePinLogin');
            if (toggle) toggle.click();
            const pm = document.getElementById('pinMode');
            if (pm) pm.scrollIntoView({ behavior: 'smooth' });
        });
    }
    if (bioOnboardBtn) {
        bioOnboardBtn.addEventListener('click', () => {
            closeBioPopup();
            const regBtn = document.getElementById('openRegisterButton');
            if (regBtn) regBtn.click();
        });
    }

    (async function(){
        if (!deviceCapText) return;
        try {
            const hasWebAuthn = typeof window.PublicKeyCredential !== 'undefined';
            if (!hasWebAuthn) {
                deviceCapText.textContent = 'Passkeys are not supported on this browser/device.';
                return;
            }
            let platform = null;
            try {
                platform = await Promise.race([
                    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(),
                    new Promise((resolve) => setTimeout(() => resolve(null), 900)),
                ]);
            } catch (e) {}
            let conditional = null;
            try {
                if (PublicKeyCredential.isConditionalMediationAvailable) {
                    conditional = await Promise.race([
                        PublicKeyCredential.isConditionalMediationAvailable(),
                        new Promise((resolve) => setTimeout(() => resolve(null), 700)),
                    ]);
                }
            } catch (e) {}
            const parts = [];
            parts.push('WebAuthn: supported');
            if (platform === true) parts.push('Platform authenticator: available');
            if (platform === false) parts.push('Platform authenticator: not available');
            if (conditional === true) parts.push('Conditional UI: available');
            if (conditional === false) parts.push('Conditional UI: not available');
            if (platform === null) parts.push('Platform authenticator: unknown');
            if (conditional === null && PublicKeyCredential.isConditionalMediationAvailable) parts.push('Conditional UI: unknown');
            deviceCapText.textContent = parts.join(' · ');
        } catch (e) {
            deviceCapText.textContent = 'Capability check failed.';
        }
    })();
    
    // Add logic for Enter Meta Humans button to trigger registration
    if (sideEnterBtn) {
        sideEnterBtn.addEventListener('click', (e) => {
            e.preventDefault();
            // Trigger the registration overlay
            const regBtn = document.getElementById('openRegisterButton');
            if (regBtn) regBtn.click();
            else {
                 // Fallback if button hidden
                 const regOverlay = document.getElementById('registerOverlay');
                 if(regOverlay) {
                     regOverlay.style.display = 'flex';
                     regOverlay.style.opacity = '1';
                 }
            }
        });
    }

    if (bioPopup) {
        // Disable auto-close timeout to allow full user review
        
        // Close on outside click
        bioPopup.addEventListener('click', (e) => {
            if (e.target === bioPopup) closeBioPopup();
        });

        // Handle Seamless Entry (Sign in with mobile)
        if (seamlessBtn) {
            seamlessBtn.addEventListener('click', async (e) => {
                // Prevent default form submission if any
                e.preventDefault();
                
                // Show loading state
                seamlessBtn.textContent = "Verifying Device...";
                seamlessBtn.disabled = true;

                try {
                    // Attempt Silent Authentication (Get Assertion)
                    const authResp = await fetch("login.php?action=start_passkey_login", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ userId: "" }) // Empty ID triggers discovery
                    });
                    const authData = await authResp.json();
                    
                    if (authData.success) {
                        // User exists -> Login Flow
                        const options = authData.publicKey;
                        options.challenge = base64UrlToArrayBuffer(options.challenge);
                        if (options.allowCredentials) {
                            options.allowCredentials = options.allowCredentials.map(c => {
                                c.id = base64UrlToArrayBuffer(c.id);
                                return c;
                            });
                        }

                        const credential = await navigator.credentials.get({ publicKey: options });

                        const credentialData = {
                            id: credential.id,
                            response: {
                                authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                                clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                                signature: bufferToBase64Url(credential.response.signature),
                                userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                            }
                        };

                        const verifyResp = await fetch("login.php?action=finish_passkey_login", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({
                                challengeId: authData.challengeId,
                                credential: credentialData
                            })
                        });
                        
                        const verifyData = await verifyResp.json();
                        if (verifyData.success) {
                            seamlessBtn.textContent = "Success!";
                            mh_emit_auth_event('login');
                            const dest = (verifyData && verifyData.redirect) ? String(verifyData.redirect) : "/hub/index.php";
                            setTimeout(() => { window.location.replace(dest); }, 200);
                        } else {
                            throw new Error(verifyData.message || verifyData.error);
                        }

                    } else {
                         // User not found -> Registration Flow
                         // Trigger registration overlay
                         closeBioPopup();
                         const regBtn = document.getElementById('openRegisterButton');
                         if (regBtn) regBtn.click();
                    }
                } catch (e) {
                    // console.error("Seamless entry error:", e);
                    
                    // If user cancelled (NotAllowedError) or other error, fallback to Login view (PIN)
                    closeBioPopup();

                    const toggle = document.getElementById('togglePinLogin');
                    if (toggle) toggle.click();
                    const pm = document.getElementById('pinMode');
                    if (pm) pm.scrollIntoView({ behavior: 'smooth' });
                    
                    // Optional: You could show a toast here
                    // alert("Biometric login cancelled. Please use PIN.");
                } finally {
                    seamlessBtn.textContent = "PASSKEY LOGIN";
                    seamlessBtn.disabled = false;
                }
            });
        }
    }

    // --- New Hero Login Logic ---
    const seamlessEntryBtnHero = document.getElementById('seamlessEntryBtnHero');
    const heroStatus = document.getElementById('heroStatus');
    const togglePinLogin = document.getElementById('togglePinLogin');
    // const pinMode = document.getElementById('pinMode'); // Already defined

    if (togglePinLogin && document.getElementById('pinMode')) {
        togglePinLogin.addEventListener('click', () => {
             const pm = document.getElementById('pinMode');
             if (pm.style.display === 'none') {
                 pm.style.display = 'block';
                 // Scroll to pin section
                 pm.scrollIntoView({ behavior: 'smooth' });
             } else {
                 pm.style.display = 'none';
             }
        });
    }

    if (seamlessEntryBtnHero) {
        seamlessEntryBtnHero.addEventListener('click', async () => {
             renderStatus(heroStatus, "ok", "Verifying Device...");
             seamlessEntryBtnHero.disabled = true;
             
             try {
                // Reuse existing seamless logic (start_passkey_login with empty user)
                const authResp = await fetch("login.php?action=start_passkey_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ userId: "" }) // Empty ID triggers discovery
                });
                const authData = await authResp.json();
                
                if (authData.success) {
                    await performWebAuthnLogin(authData);
                } else {
                     // User not found -> Registration Flow
                     const regBtn = document.getElementById('openRegisterButton');
                     if (regBtn) regBtn.click();
                     renderStatus(heroStatus, "", "");
                }
             } catch (e) {
                 renderStatus(heroStatus, "", ""); // Clear status
                // Reveal PIN login on failure/cancel
                 const pm = document.getElementById('pinMode');
                 if (pm) {
                     pm.style.display = 'block';
                     renderStatus(document.getElementById('pinStatus'), "err", "Device login cancelled. Use PIN.");
                 }
             } finally {
                 seamlessEntryBtnHero.disabled = false;
                 renderStatus(heroStatus, "", "");
             }
        });
    }

    async function performWebAuthnLogin(data) {
        try {
            const options = data.publicKey;
            options.challenge = base64UrlToArrayBuffer(options.challenge);
            if (options.allowCredentials) {
                options.allowCredentials = options.allowCredentials.map(c => {
                    c.id = base64UrlToArrayBuffer(c.id);
                    return c;
                });
            }

            // Fallback handling for cancellation/QR code avoidance
            let credential;
            try {
                // Try to prefer platform (local) authenticator first to avoid QR if possible
                // Note: If no platform auth exists, this might throw NotAllowedError immediately on some browsers,
                // or just show the QR code if cross-platform is allowed. 
                // We'll try with default options first as per server request.
                credential = await navigator.credentials.get({ publicKey: options });
            } catch (err) {
                if (err.name === 'NotAllowedError' || err.name === 'AbortError') {
                    // User cancelled the dialog (e.g. closed QR code)
                    console.log("WebAuthn cancelled by user.");
                    throw new Error("User cancelled authentication");
                }
                throw err;
            }
            
            const credentialData = {
                id: credential.id,
                response: {
                    authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                    signature: bufferToBase64Url(credential.response.signature),
                    userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                }
            };

            const verifyResp = await fetch("login.php?action=finish_passkey_login", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    challengeId: data.challengeId,
                    credential: credentialData
                })
            });

            const verifyData = await verifyResp.json();
            if (verifyData.success) {
                mh_emit_auth_event('login');
                const dest = (verifyData && verifyData.redirect) ? String(verifyData.redirect) : "/hub/index.php";
                window.location.replace(dest);
            } else {
                throw new Error(verifyData.message);
            }
        } catch (e) {
            console.warn("Login failed or cancelled:", e);
            // If explicit cancellation, just stop and let user choose another method
            if (e.message === "User cancelled authentication") {
                return; 
            }
            
            // If unknown error, maybe fallback to registration or just alert
            // await performSeamlessRegistration(); // Only do this if we are SURE it was a "user not found" scenario, which we aren't here.
        }
    }

    async function performSeamlessRegistration() {
        window.location.href = '/auth/register.php';
    }

    const modeToggle = document.getElementById("modeToggle");
    const passkeyMode = document.getElementById("passkeyMode");
    const pinMode = document.getElementById("pinMode");
    const webauthnMode = document.getElementById("webauthnMode");
    const passkeyStatus = document.getElementById("passkeyStatus");
    const pinStatus = document.getElementById("pinStatus");
    const webauthnStatus = document.getElementById("webauthnStatus");
    const passkeyLoginButton = document.getElementById("passkeyLoginButton");
    const pinLoginButton = document.getElementById("pinLoginButton");
    const webauthnLoginButton = document.getElementById("webauthnLoginButton");
    const deviceLabelInput = document.getElementById("deviceLabel");
    const passkeyUserIdInput = document.getElementById("passkeyUserId");
    const openRegisterButton = document.getElementById("openRegisterButton");
    const registerOverlay = document.getElementById("registerOverlay");
    const closeRegisterButton = document.getElementById("closeRegisterButton");
    const registerButton = document.getElementById("registerButton");
    const regContinueButton = document.getElementById("regContinueButton");
    const registerStatus = document.getElementById("registerStatus");

    // New Registration Elements
    const regUsername = document.getElementById("regUsername");
    const regDeviceId = document.getElementById("regDeviceId");
    const regNewUserFields = document.getElementById("regNewUserFields");
    const regPinField = document.getElementById("regPinField");
    const regPinConfirmField = document.getElementById("regPinConfirmField");
    const regModalTitle = document.getElementById("regModalTitle");
    const regPinLabel = document.getElementById("regPinLabel");

    let regMode = 'check'; // check, new, add_device

    function generateDeviceId() {
        const array = new Uint8Array(8);
        window.crypto.getRandomValues(array);
        let hex = '';
        for (let i = 0; i < array.length; i++) {
            hex += array[i].toString(16).padStart(2, '0');
        }
        return 'device_' + hex;
    }

    function setMode(mode) {
        const buttons = modeToggle.querySelectorAll("button");
        buttons.forEach(function (btn) {
            if (btn.dataset.mode === mode) {
                btn.classList.add("active");
            } else {
                btn.classList.remove("active");
            }
        });
        if (mode === "passkey") {
            passkeyMode.style.display = "";
            webauthnMode.style.display = "none";
            pinMode.style.display = "none";
        } else if (mode === "webauthn") {
            passkeyMode.style.display = "none";
            webauthnMode.style.display = "";
            pinMode.style.display = "none";
        } else {
            passkeyMode.style.display = "none";
            webauthnMode.style.display = "none";
            pinMode.style.display = "";
        }
    }

    if (modeToggle) {
        modeToggle.addEventListener("click", function (e) {
            const target = e.target;
            if (!target || !target.dataset) return;
            const mode = target.dataset.mode;
            if (!mode) return;
            setMode(mode);
        });
    }

    function renderStatus(container, type, message) {
        if (!container) return;
        container.innerHTML = "";
        if (!message) return;
        const span = document.createElement("span");
        const icon = document.createElement("span");
        icon.className = "mh-auth-status-icon " + (type === "ok" ? "ok" : "err");
        const text = document.createElement("span");
        text.textContent = message;
        span.className = type === "ok" ? "ok" : "err";
        span.appendChild(icon);
        span.appendChild(text);
        container.appendChild(span);
    }

    function mh_getNoticeInstance() {
        if (window.globalPopupNotice || window.popupNotice) {
            return window.globalPopupNotice || window.popupNotice;
        }
        try {
            if (typeof window.PopupNotice !== 'undefined') {
                window.popupNotice = new window.PopupNotice();
                return window.popupNotice;
            }
        } catch (e) {}
        return null;
    }

    function mh_notice(type, msg) {
        const n = mh_getNoticeInstance();
        if (!n || !msg) return;
        try {
            if (type === 'error' && typeof n.error === 'function') return n.error(msg);
            if (type === 'warning' && typeof n.warning === 'function') return n.warning(msg);
            if (type === 'success' && typeof n.success === 'function') return n.success(msg);
            if (type === 'info' && typeof n.info === 'function') return n.info(msg);
            if (typeof n.show === 'function') return n.show(msg, type);
        } catch (e) {}
    }

    function mh_setFieldInvalid(el, isInvalid) {
        if (!el) return;
        try { el.classList.toggle('mh-auth-input-invalid', !!isInvalid); } catch (e) {}
    }

    function mh_validateRegisterForm() {
        const errors = [];
        const usernameEl = regUsername;
        const firstNameEl = document.getElementById('regRealName');
        const surnameEl = document.getElementById('regRealSurname');
        const personaEl = document.getElementById('regPersonaId');
        const pinEl = document.getElementById('regPin');
        const pinConfirmEl = document.getElementById('regPinConfirm');
        const termsEl = document.getElementById('regTermsCheck');

        const username = usernameEl ? usernameEl.value.trim() : '';
        const firstName = firstNameEl ? firstNameEl.value.trim() : '';
        const surname = surnameEl ? surnameEl.value.trim() : '';
        const displayName = (firstName && surname) ? (firstName + ' ' + surname) : '';
        const personaId = personaEl ? personaEl.value.trim() : '';
        const pin = pinEl ? pinEl.value.trim() : '';
        const pinConfirm = pinConfirmEl ? pinConfirmEl.value.trim() : '';
        const termsOk = !!(termsEl && termsEl.checked);

        if (!termsOk) errors.push({ field: 'terms', message: 'Terms: please accept the onboarding terms.' });
        if (!username) errors.push({ field: 'username', message: 'Username: required.' });
        if (username && username.length < 5) errors.push({ field: 'username', message: 'Username: must be at least 5 characters.' });
        if (window._mhRegUsernameEmailLike) errors.push({ field: 'username', message: 'Username: cannot contain "@".' });

        const needsProfileFields = (regMode === 'new_user');
        const needsPinConfirm = (regMode === 'new_user');

        if (needsProfileFields) {
            if (!firstName) errors.push({ field: 'firstName', message: 'Real Name: required.' });
            if (!surname) errors.push({ field: 'surname', message: 'Real Surname: required.' });
            if (firstName && surname) {
                try {
                    const rnErr = mh_validateRealFirstSurname(firstName, surname);
                    if (rnErr) errors.push({ field: 'firstName', message: rnErr });
                } catch (e) {}
            }
            if (!personaId) errors.push({ field: 'personaId', message: 'Meta Human Persona name: required.' });
            if (personaId && personaId.length > 0 && personaId.length < 3) errors.push({ field: 'personaId', message: 'Meta Human Persona name: must be at least 3 characters.' });
        }

        if (!pin) errors.push({ field: 'pin', message: 'PIN: required.' });
        if (pin && !/^[0-9]{5,}$/.test(pin)) errors.push({ field: 'pin', message: 'PIN: must be at least 5 digits.' });
        if (needsPinConfirm) {
            if (!pinConfirm) errors.push({ field: 'pinConfirm', message: 'Confirm PIN: required.' });
            if (pin && pinConfirm && pin !== pinConfirm) errors.push({ field: 'pinConfirm', message: 'Confirm PIN: does not match.' });
        }

        mh_setFieldInvalid(usernameEl, errors.some(e => e.field === 'username'));
        mh_setFieldInvalid(firstNameEl, errors.some(e => e.field === 'firstName'));
        mh_setFieldInvalid(surnameEl, errors.some(e => e.field === 'surname'));
        mh_setFieldInvalid(personaEl, errors.some(e => e.field === 'personaId'));
        mh_setFieldInvalid(pinEl, errors.some(e => e.field === 'pin'));
        mh_setFieldInvalid(pinConfirmEl, errors.some(e => e.field === 'pinConfirm'));

        return { ok: errors.length === 0, errors };
    }

    function mh_updateCreatePasskeyButtonState() {
        if (!registerButton) return;
        if (registerButton.style.display === 'none') return;
        const isCreatePasskey = (regMode === 'new_user' && String(registerButton.textContent || '').toLowerCase().includes('create passkey'));
        if (!isCreatePasskey) {
            registerButton.classList.remove('mh-auth-btn-ready');
            registerButton.disabled = false;
            return;
        }
        const v = mh_validateRegisterForm();
        registerButton.disabled = !v.ok;
        registerButton.classList.toggle('mh-auth-btn-ready', v.ok);
    }

    function getDeviceEnvironmentLabel() {
        let label = "Unknown Device";
        if (window.navigator.userAgent.indexOf("Windows") !== -1) label = "Windows Device";
        else if (window.navigator.userAgent.indexOf("Mac") !== -1) label = "Mac Device";
        else if (window.navigator.userAgent.indexOf("Android") !== -1) label = "Android Device";
        else if (window.navigator.userAgent.indexOf("iPhone") !== -1 || window.navigator.userAgent.indexOf("iPad") !== -1) label = "iOS Device";
        else if (window.navigator.userAgent.indexOf("Linux") !== -1) label = "Linux Device";
        
        // Check for biometric capability hint
        if (window.PublicKeyCredential) {
            PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(avail => {
                if (deviceLabelInput) {
                    if (avail) {
                        deviceLabelInput.value = label + " (Biometrics Available)";
                    } else {
                        deviceLabelInput.value = label + " (No Biometrics)";
                    }
                }
            });
        } else {
            if (deviceLabelInput) deviceLabelInput.value = label;
        }
        return label;
    }
    
    getDeviceEnvironmentLabel();

    function bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = "";
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        const base64 = btoa(binary);
        return base64.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function base64UrlToArrayBuffer(base64url) {
        const padded = base64url.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((base64url.length + 3) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function isWebAuthnAvailable() {
        return typeof window.PublicKeyCredential !== "undefined" && typeof navigator.credentials !== "undefined";
    }

    // --- Login Logic ---

    // 1. Passkey Login
    if (passkeyLoginButton) {
        passkeyLoginButton.addEventListener("click", async function () {
            renderStatus(passkeyStatus, "ok", "Contacting authenticator...");
            const userId = passkeyUserIdInput.value.trim();
            
            try {
                // Get challenge
                const resp = await fetch("login.php?action=start_passkey_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ userId: userId })
                });
                const data = await resp.json();
                if (!data.success) throw new Error(data.message || data.error);

                // Decode options
                const options = data.publicKey;
                options.challenge = base64UrlToArrayBuffer(options.challenge);
                if (options.allowCredentials) {
                    options.allowCredentials = options.allowCredentials.map(c => {
                        c.id = base64UrlToArrayBuffer(c.id);
                        return c;
                    });
                }

                // WebAuthn Call
                const credential = await navigator.credentials.get({ publicKey: options });

                // Encode response
                const credentialData = {
                    id: credential.id,
                    response: {
                        authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        signature: bufferToBase64Url(credential.response.signature),
                        userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                    }
                };

                // Verify
                const verifyResp = await fetch("login.php?action=finish_passkey_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        challengeId: data.challengeId,
                        credential: credentialData
                    })
                });
                const verifyData = await verifyResp.json();
                if (verifyData.success) {
                    renderStatus(passkeyStatus, "ok", "Success! Redirecting...");
                    const dest = (verifyData && verifyData.redirect) ? String(verifyData.redirect) : "/hub/index.php";
                    setTimeout(() => { window.location.replace(dest); }, 200);
                } else {
                    throw new Error(verifyData.message || verifyData.error);
                }

            } catch (e) {
                if (e.name === 'NotAllowedError' || e.message.includes('cancelled') || e.message.includes('timed out') || e.message.includes('not allowed')) {
                     renderStatus(passkeyStatus, "err", "No passkey found/cancelled. Opening registration...");
                     setTimeout(() => {
                         const regBtn = document.getElementById('openRegisterButton');
                          if (regBtn) {
                              regBtn.click();
                              const regTitle = document.getElementById('regModalTitle');
                              if (regTitle) regTitle.textContent = "Let's onboard you";
                          }
                     }, 800);
                } else {
                    renderStatus(passkeyStatus, "err", "Login failed: " + e.message);
                }
            }
        });
    }

    // 1b. Mobile/Security Key Login (Same logic as Passkey)
    if (webauthnLoginButton) {
        webauthnLoginButton.addEventListener("click", async function () {
            renderStatus(webauthnStatus, "ok", "Contacting authenticator...");
            const userId = document.getElementById('webauthnUserId').value.trim();
            
            try {
                // Get challenge
                const resp = await fetch("login.php?action=start_passkey_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ userId: userId })
                });
                const data = await resp.json();
                if (!data.success) throw new Error(data.message || data.error);

                // Decode options
                const options = data.publicKey;
                options.challenge = base64UrlToArrayBuffer(options.challenge);
                if (options.allowCredentials) {
                    options.allowCredentials = options.allowCredentials.map(c => {
                        c.id = base64UrlToArrayBuffer(c.id);
                        return c;
                    });
                }

                // WebAuthn Call
                const credential = await navigator.credentials.get({ publicKey: options });

                // Encode response
                const credentialData = {
                    id: credential.id,
                    response: {
                        authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        signature: bufferToBase64Url(credential.response.signature),
                        userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null
                    }
                };

                // Verify
                const verifyResp = await fetch("login.php?action=finish_passkey_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        challengeId: data.challengeId,
                        credential: credentialData
                    })
                });
                const verifyData = await verifyResp.json();
                if (verifyData.success) {
                    renderStatus(webauthnStatus, "ok", "Success! Redirecting...");
                    const dest = (verifyData && verifyData.redirect) ? String(verifyData.redirect) : "/hub/index.php";
                    setTimeout(() => { window.location.replace(dest); }, 200);
                } else {
                    throw new Error(verifyData.message || verifyData.error);
                }

            } catch (e) {
                if (e.name === 'NotAllowedError' || e.message.includes('cancelled') || e.message.includes('timed out') || e.message.includes('not allowed')) {
                     renderStatus(webauthnStatus, "err", "No security key found/cancelled. Opening registration...");
                     setTimeout(() => {
                         const regBtn = document.getElementById('openRegisterButton');
                         if (regBtn) {
                             regBtn.click();
                             const regTitle = document.getElementById('regModalTitle');
                             if (regTitle) regTitle.textContent = "Let's onboard you";
                         }
                     }, 800);
                } else {
                    renderStatus(webauthnStatus, "err", "Login failed: " + e.message);
                }
            }
        });
    }

    // 2. PIN Login
    if (pinLoginButton) {
        pinLoginButton.addEventListener("click", async function() {
            const userIdInput = document.getElementById("pinUserId");
            const userId = userIdInput.value.trim();
            const pin = document.getElementById("pinCode").value.trim();
            if (!userId || !pin) {
                renderStatus(pinStatus, "err", "Please enter Username and PIN");
                return;
            }
            if (userId.length < 5) {
                renderStatus(pinStatus, "err", "Username must be at least 5 characters");
                return;
            }
            renderStatus(pinStatus, "ok", "Verifying...");
            try {
                const resp = await fetch("login.php?action=pin_login", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ userId: userId, pin: pin })
                });
                const data = await resp.json();
                if (data.success) {
                    renderStatus(pinStatus, "ok", "Success! Redirecting...");
                    const dest = (data && data.redirect) ? String(data.redirect) : "/hub/index.php";
                        setTimeout(() => { window.location.replace(dest); }, 200);
                } else {
                    throw new Error(data.message || data.error);
                }
            } catch(e) {
                if (e.message.includes('User not found')) {
                     renderStatus(pinStatus, "err", "User not found. Opening registration...");
                     setTimeout(() => {
                         const regBtn = document.getElementById('openRegisterButton');
                         if (regBtn) {
                             regBtn.click();
                             // Ensure resetRegModal is called to clear state and checkbox
                             if (typeof resetRegModal === 'function') {
                                 resetRegModal();
                             }
                             // Prefill username if valid
                             if (regUsername) regUsername.value = userId;
                         }
                     }, 800);
                } else {
                    renderStatus(pinStatus, "err", "Login failed: " + e.message);
                }
            }
        });
    }

    // --- Registration Logic ---

    if (openRegisterButton) {
        openRegisterButton.addEventListener("click", async () => {
             registerOverlay.style.display = "flex";
             resetRegModal();
             try {
                 await fetch("login.php", {
                     method: "POST",
                     headers: { "Content-Type": "application/json" },
                     body: JSON.stringify({ action: "seed_reg_ts" })
                 });
             } catch (e) {}
             if (regUsername) regUsername.focus();
        });
    }
    
    const regOverlayCloseX = document.getElementById('regOverlayCloseX');
    const regFormContainer = document.getElementById('regFormContainer');
    if (regOverlayCloseX) {
        regOverlayCloseX.addEventListener('click', () => {
            registerOverlay.style.display = 'none';
        });
    }

    if (closeRegisterButton) {
        closeRegisterButton.addEventListener("click", () => registerOverlay.style.display = "none");
    }

    function resetRegModal() {
        regUsername.value = "";
        document.getElementById("regRealName").value = "";
        document.getElementById("regRealSurname").value = "";
        document.getElementById("regPersonaId").value = "";
        document.getElementById("regPin").value = "";
        document.getElementById("regPinConfirm").value = "";
        const hp = document.getElementById("regWebsite");
        if (hp) hp.value = "";
        
        if (regDeviceId) {
            regDeviceId.value = generateDeviceId();
        }

        regUsername.disabled = false;
        regModalTitle.textContent = "Let's onboard you";
        regContinueButton.textContent = "Continue";
        if (regFormContainer) regFormContainer.style.display = 'block';

        regNewUserFields.style.display = "none";
        regPinField.style.display = "none";
        regContinueButton.style.display = "inline-block";
        registerButton.style.display = "none";
        
        // --- Fix: Reset Checkbox State ---
        const termsCheck = document.getElementById("regTermsCheck");
        if (termsCheck) {
            termsCheck.checked = false;
            // Disable continue button by default
            regContinueButton.disabled = true;
            regContinueButton.style.opacity = "0.5";
            regContinueButton.style.cursor = "not-allowed";
        }
        // ---------------------------------

        renderStatus(registerStatus, "", "");
        regMode = 'check';
    }

    // Add listener for checkbox
    const regTermsCheck = document.getElementById("regTermsCheck");
    if (regTermsCheck) {
        regTermsCheck.addEventListener('change', function() {
            if (this.checked) {
                regContinueButton.disabled = false;
                regContinueButton.style.opacity = "1";
                regContinueButton.style.cursor = "pointer";
            } else {
                regContinueButton.disabled = true;
                regContinueButton.style.opacity = "0.5";
                regContinueButton.style.cursor = "not-allowed";
            }
            mh_updateCreatePasskeyButtonState();
        });
    }

    // Input validation for username
    if (regUsername) {
        regUsername.addEventListener('input', function() {
            const raw = String(this.value || '');
            const emailLike = /@/.test(raw) || /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(raw);
            window._mhRegUsernameEmailLike = emailLike;
            const sanitized = raw.replace(/[^a-zA-Z0-9]/g, '');
            this.value = sanitized;
            
            // Visual warning for length
            const statusEl = document.getElementById('registerStatus');
            if (emailLike) {
                if (statusEl) {
                    statusEl.style.color = '#ff4444';
                    statusEl.textContent = 'Username cannot contain "@"';
                }
            } else if (this.value.length > 0 && this.value.length < 5) {
                if (statusEl) {
                    statusEl.style.color = '#ff4444';
                    statusEl.textContent = "Username too short (min 5 chars)";
                }
            } else {
                if (statusEl) statusEl.textContent = "";
            }
            mh_updateCreatePasskeyButtonState();
        });
        regUsername.addEventListener('paste', function(e) {
            try {
                const t = e && e.clipboardData ? e.clipboardData.getData('text') : '';
                if (typeof t === 'string' && /@/.test(t)) {
                    window._mhRegUsernameEmailLike = true;
                }
            } catch (e2) {}
        });
    }

    try {
        const watchIds = ['regRealName', 'regRealSurname', 'regPersonaId', 'regPin', 'regPinConfirm'];
        for (const id of watchIds) {
            const el = document.getElementById(id);
            if (!el) continue;
            el.addEventListener('input', mh_updateCreatePasskeyButtonState);
            el.addEventListener('blur', function() {
                const v = mh_validateRegisterForm();
                const first = v.errors.find(e => {
                    if (id === 'regRealName') return e.field === 'firstName';
                    if (id === 'regRealSurname') return e.field === 'surname';
                    if (id === 'regPersonaId') return e.field === 'personaId';
                    if (id === 'regPin') return e.field === 'pin';
                    if (id === 'regPinConfirm') return e.field === 'pinConfirm';
                    return false;
                });
                if (first) mh_notice('warning', first.message);
                mh_updateCreatePasskeyButtonState();
            });
        }
    } catch (e) {}

    function mh_validateRealFirstSurname(firstName, surname) {
        const fn = String(firstName || '').trim();
        const sn = String(surname || '').trim();
        if (!fn) return 'Real Name: required.';
        if (!sn) return 'Real Surname: required.';
        if (/@/.test(fn) || /@/.test(sn)) return 'Real name/surname cannot contain "@".';
        const fnc = fn.replace(/[^a-zA-Z\-']/g, '');
        const snc = sn.replace(/[^a-zA-Z\-']/g, '');
        if (fnc.length < 2) return 'Real Name: must be at least 2 characters.';
        if (snc.length < 2) return 'Real Surname: must be at least 2 characters.';
        if (fnc.toLowerCase() === snc.toLowerCase()) return 'Real Name and Real Surname cannot be the same.';
        return null;
    }

    // Input validation for Persona Name
    const regPersonaId = document.getElementById("regPersonaId");
    if (regPersonaId) {
        regPersonaId.addEventListener('input', function() {
            const v = String(this.value || '').replace(/[\r\n\t]/g, ' ');
            this.value = v.slice(0, 64);
        });
    }

    regContinueButton.addEventListener("click", async function() {
        // --- Fix: Double Check Validation ---
        const termsCheck = document.getElementById("regTermsCheck");
        if (termsCheck && !termsCheck.checked) {
            renderStatus(registerStatus, "err", "You must agree to the terms.");
            return;
        }
        // ------------------------------------

        const username = regUsername.value.trim();
        if (window._mhRegUsernameEmailLike) {
            renderStatus(registerStatus, "err", 'Username cannot contain "@"');
            return;
        }
        if (!username) {
            renderStatus(registerStatus, "err", "Please enter a username");
            return;
        }
        if (username.length < 5) {
            renderStatus(registerStatus, "err", "Username must be at least 5 characters");
            return;
        }
        
        regContinueButton.disabled = true;
        renderStatus(registerStatus, "ok", "Checking user status...");

        try {
            const resp = await fetch("login.php?action=check_user_status", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ username: username })
            });
            const data = await resp.json();
            if (!data.success) throw new Error(data.message || data.error);

            regUsername.disabled = true;
            regContinueButton.style.display = "none";

            if (data.exists) {
                if (!data.has_pin) {
                    regMode = 'add_device';
                    regModalTitle.textContent = "Account Found";
                    regPinField.style.display = "none";
                    regPinConfirmField.style.display = "none";
                    registerButton.style.display = "none";
                    renderStatus(registerStatus, "err", "This account exists but has no PIN set yet. Use Passkey login first, then set a PIN in /hub/settings.php.");
                } else {
                    // Add Device / Create First Passkey Mode (PIN-gated)
                    regMode = 'add_device';
                    regModalTitle.textContent = (data.has_passkeys ? ("Add Device to " + username) : ("Create Passkey for " + username));
                    regPinField.style.display = "block";
                    regPinConfirmField.style.display = "none"; // No confirm needed for verify
                    regPinLabel.textContent = "Enter your PIN to verify identity";
                    registerButton.textContent = (data.has_passkeys ? "Verify & Add Device" : "Verify & Create Passkey");
                    registerButton.style.display = "inline-block";
                    renderStatus(registerStatus, "ok", data.has_passkeys ? "User found. Verify PIN to add this device." : "User found. Verify PIN to create your passkey.");
                }
            } else {
                // New User Mode
                regMode = 'new_user';
                regModalTitle.textContent = "Create New Account";
                regNewUserFields.style.display = "block";
                regPinField.style.display = "block";
                regPinConfirmField.style.display = "block";
                regPinLabel.textContent = "Create a PIN (minimum 5 digits)";
                registerButton.textContent = "Create Passkey";
                
                registerButton.style.display = "inline-block";
                renderStatus(registerStatus, "", "");
            }
            mh_updateCreatePasskeyButtonState();
        } catch (e) {
            renderStatus(registerStatus, "err", e.message);
            regContinueButton.disabled = false;
            regUsername.disabled = false;
        }
    });

    if (registerButton) {
        registerButton.addEventListener("click", async function() {
            const v = mh_validateRegisterForm();
            if (!v.ok) {
                for (const err of v.errors) {
                    mh_notice('error', err.message);
                }
                renderStatus(registerStatus, "err", "Please correct the highlighted fields.");
                mh_updateCreatePasskeyButtonState();
                return;
            }
            const username = regUsername.value.trim();
            const pin = document.getElementById("regPin").value.trim();
            window._mhLastRegUsername = username;
            window._mhLastRegPin = pin;
            if (window._mhRegUsernameEmailLike) {
                renderStatus(registerStatus, "err", 'Username cannot contain "@"');
                return;
            }

            if (regMode === 'add_device') {
                // Add Device Flow
                try {
                    renderStatus(registerStatus, "ok", "Verifying PIN...");
                    const startResp = await fetch("login.php?action=verify_pin_and_start_add_device", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            username: username,
                            pin: pin,
                            website: (document.getElementById("regWebsite") || {}).value || ""
                        })
                    });
                    const startData = await startResp.json();
                    if (!startData.success) throw new Error(startData.message || startData.error);

                    await performWebAuthnRegistration(startData);

                } catch (e) {
                    renderStatus(registerStatus, "err", "Failed: " + e.message);
                }

            } else {
                // New User Flow
                const firstName = document.getElementById("regRealName").value.trim();
                const surname = document.getElementById("regRealSurname").value.trim();
                const displayName = (firstName && surname) ? (firstName + ' ' + surname) : '';
                const personaId = document.getElementById("regPersonaId").value.trim();
                const pinConfirm = document.getElementById("regPinConfirm").value.trim();

                if (!username || !firstName || !surname || !personaId || !pin) {
                    renderStatus(registerStatus, "err", "All fields are required");
                    return;
                }
                const rnErr = mh_validateRealFirstSurname(firstName, surname);
                if (rnErr) {
                    renderStatus(registerStatus, "err", rnErr);
                    return;
                }
                if (personaId.length < 3) {
                    renderStatus(registerStatus, "err", "Meta Human Persona name must be at least 3 characters");
                    return;
                }

                if (pin !== pinConfirm) {
                    renderStatus(registerStatus, "err", "PINs do not match");
                    return;
                }

                try {
                    renderStatus(registerStatus, "ok", "Creating your account...");
                    const pinRegResp = await fetch("login.php?action=pin_register", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            username: username,
                            firstName: firstName,
                            surname: surname,
                            displayName: displayName,
                            persona_id: personaId,
                            pin: pin,
                            deviceId: regDeviceId.value,
                            website: (document.getElementById("regWebsite") || {}).value || ""
                        })
                    });
                    const pinRegData = await pinRegResp.json();
                    if (pinRegData && !pinRegData.success && pinRegData.error === 'device_already_registered') {
                        const msg = pinRegData.message || 'This device already has an account—continue to login.';
                        renderStatus(registerStatus, "err", msg);
                        try {
                            const heroStatus = document.getElementById('heroStatus');
                            if (heroStatus) renderStatus(heroStatus, "err", msg);
                        } catch (e) {}
                        registerOverlay.style.display = "none";
                        try {
                            const btn = document.getElementById('seamlessEntryBtnHero');
                            if (btn) btn.scrollIntoView({ behavior: 'smooth' });
                        } catch (e) {}
                        return;
                    }
                    if (!pinRegData || !pinRegData.success) throw new Error((pinRegData && (pinRegData.message || pinRegData.error)) || 'registration_failed');
                    window._mhLastRegPinCreated = true;

                    renderStatus(registerStatus, "ok", "Account created. Creating passkey...");
                    const startResp = await fetch("login.php?action=verify_pin_and_start_add_device", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            username: username,
                            pin: pin,
                            website: (document.getElementById("regWebsite") || {}).value || ""
                        })
                    });
                    const startData = await startResp.json();
                    if (!startData || !startData.success) throw new Error((startData && (startData.message || startData.error)) || 'passkey_start_failed');

                    await performWebAuthnRegistration(startData);

                } catch (e) {
                    renderStatus(registerStatus, "err", "Registration failed: " + e.message);
                }
            }
        });
    }

    async function performWebAuthnRegistration(startData) {
        const likelyInApp = (() => {
            const ua = String(navigator.userAgent || '');
            const u = ua.toLowerCase();
            if (u.includes('fban') || u.includes('fbav') || u.includes('instagram') || u.includes('line/')) return true;
            if (u.includes('; wv)') || u.includes(' wv)') || u.includes('webview')) return true;
            return false;
        })();
        if (!window.isSecureContext || location.protocol !== 'https:') {
            throw new Error('Passkeys require a secure (HTTPS) connection.');
        }
        if (likelyInApp) {
            throw new Error('Passkeys are often blocked in in-app browsers. Open this page in Chrome/Safari/Edge.');
        }
        if (!window.PublicKeyCredential) {
            throw new Error('Passkeys are not supported in this browser. Use Chrome/Safari/Edge.');
        }
        if (PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
            try {
                const ok = await Promise.race([
                    PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(),
                    new Promise((resolve) => setTimeout(() => resolve(null), 900)),
                ]);
                if (ok === false) {
                    throw new Error('This device has no screen lock/biometrics enabled. Enable a device PIN/biometrics, then try again.');
                }
            } catch (e) {}
        }

        renderStatus(registerStatus, "ok", "Contacting authenticator... You may see Face ID/Touch ID/Windows Hello/Screen lock. If prompted to sign in to Apple/Google/Microsoft/Samsung, that is your device passkey provider.");
        
        try {
            const options = startData.publicKey;
            options.challenge = base64UrlToArrayBuffer(options.challenge);
            options.user.id = base64UrlToArrayBuffer(options.user.id);
            if (options.user && options.user.id && options.user.id.byteLength > 64 && window.crypto && window.crypto.subtle) {
                try {
                    const digest = await window.crypto.subtle.digest('SHA-256', options.user.id);
                    options.user.id = digest.slice(0, 32);
                } catch (e) {}
            }
            
            // Fix excludeCredentials if present
            if (options.excludeCredentials) {
                options.excludeCredentials = options.excludeCredentials.map(c => {
                    c.id = base64UrlToArrayBuffer(c.id);
                    return c;
                });
            }

            let credential = null;
            try {
                credential = await navigator.credentials.create({ publicKey: options });
            } catch (e) {
                const msg = String(e && e.message ? e.message : '');
                const name = String(e && e.name ? e.name : '');
                const looksLikeCredentialManager = (name === 'UnknownError' && /credential manager/i.test(msg));
                if (looksLikeCredentialManager && options && options.authenticatorSelection) {
                    try {
                        delete options.authenticatorSelection.authenticatorAttachment;
                        options.authenticatorSelection.userVerification = 'preferred';
                        options.authenticatorSelection.residentKey = 'preferred';
                        options.authenticatorSelection.requireResidentKey = false;
                        credential = await navigator.credentials.create({ publicKey: options });
                    } catch (e2) {
                        throw e2;
                    }
                } else {
                    throw e;
                }
            }

            const credentialData = {
                id: credential.id,
                response: {
                    attestationObject: bufferToBase64Url(credential.response.attestationObject),
                    clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON)
                },
                // Pass username to server to help it construct handle
                userHandleName: startData.user ? startData.user.name : null
            };

            renderStatus(registerStatus, "ok", "Finalizing...");
            
            // Common finish endpoint (session handles context)
            const finishResp = await fetch("login.php?action=finish_passkey_registration", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    challengeId: startData.challengeId,
                    credential: credentialData,
                    website: (document.getElementById("regWebsite") || {}).value || ""
                })
            });
            
            const finishData = await finishResp.json();
            if (finishData.success) {
                renderStatus(registerStatus, "ok", "Success! Logging in...");
                setTimeout(() => window.location.reload(), 1000);
            } else {
                if (finishData && finishData.error === 'device_already_registered') {
                    const msg = finishData.message || 'This device already has an account—continue to login.';
                    renderStatus(registerStatus, "err", msg);
                    try {
                        const heroStatus = document.getElementById('heroStatus');
                        if (heroStatus) renderStatus(heroStatus, "err", msg);
                    } catch (e) {}
                    registerOverlay.style.display = "none";
                    try {
                        const btn = document.getElementById('seamlessEntryBtnHero');
                        if (btn) btn.scrollIntoView({ behavior: 'smooth' });
                    } catch (e) {}
                    return;
                }
                throw new Error(finishData.message || finishData.error);
            }
        } catch (e) {
            const name = String(e && e.name ? e.name : '');
            const msg = String(e && e.message ? e.message : '');
            let userMessage = msg || 'Passkey failed.';
            if (name === 'NotAllowedError') {
                userMessage = 'Passkey was cancelled or blocked. Continuing with your PIN login; you can add a passkey later.';
            } else if (name === 'SecurityError') {
                userMessage = 'Passkeys are blocked in this context. Open in your main browser (Chrome/Safari/Edge) and ensure HTTPS.';
            } else if (name === 'UnknownError' && /credential manager/i.test(msg)) {
                userMessage = 'Your device passkey manager could not start. Enable screen lock/biometrics and ensure your passkey provider (Google/Apple/Windows/Samsung) is set up. Continuing with PIN login.';
            }
            renderStatus(registerStatus, "err", userMessage);

            const u = String(window._mhLastRegUsername || '');
            const p = String(window._mhLastRegPin || '');
            const created = !!window._mhLastRegPinCreated;
            if (created) {
                window.location.href = "/hub/";
                return;
            }
            if (u && p) {
                try {
                    const resp = await fetch("login.php?action=pin_login", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ userId: u, pin: p })
                    });
                    const data = await resp.json();
                    if (data && data.success) {
                        window.location.href = "/hub/";
                        return;
                    }
                } catch (e2) {}
            }
        }
    }

    // Detect environment but allow user override
    (function() {
        var envLabel = getDeviceEnvironmentLabel();
        var deviceLabelInput = document.getElementById("deviceLabel");
        if (deviceLabelInput) deviceLabelInput.value = envLabel;
        
        // Always default to passkey mode as it covers both platform and roaming
        setMode('passkey');
    })();
</script>
</body>
</html>
