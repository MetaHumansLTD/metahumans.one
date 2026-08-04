<?php
require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/persona_registry.php';
require_once __DIR__ . '/../gear/grid/bootstrap.php';

function mh_reg_contains_at_sign(string $value): bool {
    $value = trim($value);
    if ($value === '') return false;
    return strpos($value, '@') !== false;
}

function mh_reg_validate_username_strict(string $username): void {
    $username = trim($username);
    if ($username === '') throw new Exception('Username is required.');
    if (mh_reg_contains_at_sign($username)) throw new Exception('Username cannot contain "@".');
    if (preg_match('/\s/', $username)) throw new Exception('Username cannot contain spaces.');
    if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) throw new Exception('Username can contain letters and numbers only.');
    if (strlen($username) < 5) throw new Exception('Username must be at least 5 characters.');
    if (preg_match('/^(metahuman|anon|device)/i', $username)) throw new Exception('Username is invalid. Please choose your own unique username.');
}

function mh_reg_validate_real_first_and_surname(string $first, string $surname): void {
    $first = trim($first);
    $surname = trim($surname);
    if ($first === '') throw new Exception('Real name is required.');
    if ($surname === '') throw new Exception('Real surname is required.');
    if (mh_reg_contains_at_sign($first) || mh_reg_contains_at_sign($surname)) throw new Exception('Real name/surname cannot contain "@".');
    if (function_exists('mh_registration_validate_real_first_last_for_registration')) {
        mh_registration_validate_real_first_last_for_registration($first, $surname);
        return;
    }
    $firstClean = preg_replace("/[^a-zA-Z\\-']/u", '', $first);
    $surnameClean = preg_replace("/[^a-zA-Z\\-']/u", '', $surname);
    if (!is_string($firstClean) || strlen($firstClean) < 2) throw new Exception('Real name must be at least 2 characters.');
    if (!is_string($surnameClean) || strlen($surnameClean) < 2) throw new Exception('Real surname must be at least 2 characters.');
    if (strcasecmp($firstClean, $surnameClean) === 0) throw new Exception('Real name and surname cannot be the same.');
}

require_once __DIR__ . '/auth_classes.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['mh_reg_form_ts']) || !is_int($_SESSION['mh_reg_form_ts'])) {
            $_SESSION['mh_reg_form_ts'] = time();
        }
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';

function mh_isUserLoggedInForRegistration() {
    if (isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] !== '') {
        return true;
    }
    if (isset($_SERVER['HTTP_AUTH_USER']) && $_SERVER['HTTP_AUTH_USER'] !== '') {
        return true;
    }
    if (isset($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
        return true;
    }
    return false;
}

$currentUser = $_SESSION['mh_auth_user'] ?? ($_SERVER['HTTP_AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? null));
$currentUser = is_string($currentUser) ? trim($currentUser) : '';
$allowLoggedInAddDevice = false;
if ($currentUser !== '') {
    $allowLoggedInAddDevice = isset($_GET['add_device']) && (string)$_GET['add_device'] === '1';
}
if (session_status() === PHP_SESSION_ACTIVE) {
    if ($allowLoggedInAddDevice) {
        $_SESSION['mh_allow_logged_in_add_device_for'] = $currentUser;
    } else {
        unset($_SESSION['mh_allow_logged_in_add_device_for']);
    }
}
$registrationLocked = mh_isUserLoggedInForRegistration() && !$allowLoggedInAddDevice;

function auth_json_response(array $data, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    echo json_encode($data);
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
    $action = $input['action'] ?? '';

    try {
        $hp = isset($input['website']) ? (string)$input['website'] : '';
        if ($hp !== '' && trim($hp) !== '') {
            auth_json_response(['success' => false, 'error' => 'blocked'], 400);
            exit;
        }
        $ts = isset($_SESSION['mh_reg_form_ts']) ? (int)$_SESSION['mh_reg_form_ts'] : 0;
        if ($ts > 0 && (time() - $ts) < 3 && ($action === 'start_passkey_registration' || $action === 'finish_passkey_registration')) {
            auth_json_response(['success' => false, 'error' => 'too_fast', 'message' => 'Please wait a moment and try again.'], 400);
            exit;
        }

        if ($action === 'check_user_status') {
            $username = trim((string)($input['username'] ?? ''));
            if ($username === '') {
                auth_json_response(['success' => false, 'error' => 'missing_username'], 400);
                exit;
            }
            try { mh_reg_validate_username_strict($username); } catch (Throwable $e) { auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => $e->getMessage()], 400); exit; }
             if (function_exists('cue_autoload')) { cue_autoload('database'); }
             $bioConfig = database_getConfiguration('biometrics');
             $pdo = database_getConnectionById('biometrics');
             $stmt = $pdo->prepare("SELECT id, pin_hash FROM users WHERE username = ? LIMIT 1");
             $stmt->execute([$username]);
             $user = $stmt->fetch(PDO::FETCH_ASSOC);
             $exists = is_array($user) && !empty($user);
             $pinHash = $exists ? (string)($user['pin_hash'] ?? '') : '';
             $hasPin = $exists && trim($pinHash) !== '';
             $hasPasskeys = false;
             if ($exists) {
                 try {
                     $auth = new MetaPasskeyAuth();
                     $hasPasskeys = (bool)$auth->hasUserPasskeys($username);
                 } catch (Throwable) {
                     $hasPasskeys = false;
                 }
             }
             auth_json_response([
                 'success' => true,
                 'exists' => $exists,
                 'has_pin' => $hasPin,
                 'has_passkeys' => $hasPasskeys,
             ]);
             exit;
        }

        if ($action === 'verify_pin_and_start_add_device') {
            $username = trim((string)($input['username'] ?? ''));
            $pin = trim((string)($input['pin'] ?? ''));
            $allowedLoggedInUser = isset($_SESSION['mh_allow_logged_in_add_device_for']) && is_string($_SESSION['mh_allow_logged_in_add_device_for'])
                ? trim((string)$_SESSION['mh_allow_logged_in_add_device_for'])
                : '';
            $isAllowedLoggedInAddDevice = (
                $allowedLoggedInUser !== ''
                && $currentUser !== ''
                && strcasecmp($allowedLoggedInUser, $username) === 0
                && strcasecmp($currentUser, $username) === 0
            );
            if ($registrationLocked && !$isAllowedLoggedInAddDevice) {
                auth_json_response(['success' => false, 'error' => 'registration_locked', 'message' => 'You are already signed in.'], 403);
                exit;
            }

            if ($username === '' || $pin === '') {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }

            try {
                mh_reg_validate_username_strict($username);
            } catch (Throwable $e) {
                auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => $e->getMessage()], 400);
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

            // PIN Verified. Now generate challenge for existing user.
            // Retrieve user details from DB to get correct Display Name
             if (function_exists('cue_autoload')) { cue_autoload('database'); }
             $bioConfig = database_getConfiguration('biometrics');
             $pdo = database_getConnectionById('biometrics');
             $stmt = $pdo->prepare("SELECT name, persona_name FROM users WHERE username = ?");
             $stmt->execute([$username]);
             $user = $stmt->fetch(PDO::FETCH_ASSOC);
             $displayName = $user['name'] ?? $username;
             $personaName = $user['persona_name'] ?? $username;

            $auth = new MetaPasskeyAuth();
            $challenge = $auth->generateRegistrationChallenge($username, $username, $displayName);

            $_SESSION['mh_reg_username'] = $username;
            $_SESSION['mh_reg_display'] = $displayName;
            $_SESSION['mh_reg_persona'] = $personaName;
            $_SESSION['mh_reg_pin'] = $pin; // Store PIN to skip verification later if needed
            $_SESSION['mh_reg_mode'] = 'add_device'; // Mark as add device flow

            auth_json_response([
                'success' => true,
                'challengeId' => $challenge['challengeId'],
                'publicKey' => $challenge['options'],
                'mode' => 'add_device'
            ]);
            exit;
        }

        if ($action === 'start_passkey_registration') {
            if ($registrationLocked) {
                auth_json_response(['success' => false, 'error' => 'registration_locked', 'message' => 'You are already signed in.'], 403);
                exit;
            }
            $username = trim((string)($input['username'] ?? ''));
            $firstName = trim((string)($input['firstName'] ?? ''));
            $surname = trim((string)($input['surname'] ?? ($input['lastName'] ?? '')));
            $displayName = trim((string)($input['displayName'] ?? ''));
            $personaName = trim((string)($input['persona_id'] ?? ''));
            $pin = trim((string)($input['pin'] ?? ''));

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

            if ($username === '' || $personaName === '' || $pin === '' || (($firstName === '' || $surname === '') && $displayName === '')) {
                auth_json_response(['success' => false, 'error' => 'missing_fields'], 400);
                exit;
            }
            if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                exit;
            }
            try { mh_reg_validate_username_strict($username); } catch (Throwable $e) { auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => $e->getMessage()], 400); exit; }
            try { mh_reg_validate_real_first_and_surname($firstName, $surname); } catch (Throwable $e) { auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => $e->getMessage()], 400); exit; }

            // Check for duplicate username in Biometrics DB
            try {
                // Ensure database module is loaded
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }
                
                $bioConfig = null;
                if (function_exists('database_getConfiguration')) {
                    $bioConfig = database_getConfiguration('biometrics');
                }
                
                if ($bioConfig) {
                    $pdoBio = database_getConnectionById('biometrics');
                    if ($pdoBio instanceof PDO && function_exists('mh_registration_seed_default_policy_rules')) {
                        try { mh_registration_seed_default_policy_rules($pdoBio); } catch (Throwable) {}
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
                        if (function_exists('mh_registration_rate_limit_check')) {
                            $ipCheck = mh_registration_rate_limit_check($pdoBio, 'ip', $ipAddress, 10, 600, 900);
                            if (is_array($ipCheck) && isset($ipCheck['ok']) && $ipCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $fpCheck = mh_registration_rate_limit_check($pdoBio, 'fingerprint', $deviceFingerprint, 5, 600, 3600);
                            if (is_array($fpCheck) && isset($fpCheck['ok']) && $fpCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $uCheck = mh_registration_rate_limit_check($pdoBio, 'username', $username, 10, 3600, 3600);
                            if (is_array($uCheck) && isset($uCheck['ok']) && $uCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                        }
                        if (function_exists('mh_registration_policy_evaluate')) {
                            $p1 = mh_registration_policy_evaluate($pdoBio, 'real_first_name', $firstName);
                            if (is_array($p1) && (($p1['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_first_name', (string)($p1['reason'] ?? 'policy'), $firstName);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($p1)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_first_name', (string)($p1['reason'] ?? 'policy'), $firstName);
                                auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => 'Real name is invalid.'], 400);
                                exit;
                            }
                            $p2 = mh_registration_policy_evaluate($pdoBio, 'real_last_name', $surname);
                            if (is_array($p2) && (($p2['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_last_name', (string)($p2['reason'] ?? 'policy'), $surname);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($p2)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_last_name', (string)($p2['reason'] ?? 'policy'), $surname);
                                auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => 'Real surname is invalid.'], 400);
                                exit;
                            }
                            $pU = mh_registration_policy_evaluate($pdoBio, 'username', $username);
                            if (is_array($pU) && (($pU['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'username', (string)($pU['reason'] ?? 'policy'), $username);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($pU)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'username', (string)($pU['reason'] ?? 'policy'), $username);
                                auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => 'Username is invalid.'], 400);
                                exit;
                            }
                            $pP = mh_registration_policy_evaluate($pdoBio, 'persona_name', $personaName);
                            if (is_array($pP) && (($pP['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'persona_name', (string)($pP['reason'] ?? 'policy'), $personaName);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($pP)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'persona_name', (string)($pP['reason'] ?? 'policy'), $personaName);
                                auth_json_response(['success' => false, 'error' => 'persona_invalid', 'message' => 'Persona name is invalid.'], 400);
                                exit;
                            }
                        }
                        if (function_exists('mh_registration_looks_like_fake_name')) {
                            $whyA = mh_registration_looks_like_fake_name($firstName);
                            if (is_string($whyA) && function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_first_name', $whyA, $firstName);
                            $whyB = mh_registration_looks_like_fake_name($surname);
                            if (is_string($whyB) && function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_last_name', $whyB, $surname);
                        }
                    }
                    
                    try {
                        $stDel = $pdoBio->prepare("SELECT 1 FROM mh_deleted_users WHERE username = ? LIMIT 1");
                        $stDel->execute([$username]);
                        if ((bool)$stDel->fetchColumn()) {
                            auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username is not available.'], 400);
                            exit;
                        }
                    } catch (Throwable) {
                    }
                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        // User exists - prompt for PIN to add device
                        auth_json_response(['success' => false, 'error' => 'user_exists', 'message' => 'User already exists', 'require_pin' => true], 200);
                        exit;
                    }

                    $userTenantId = 'user:' . $username;

                    try {
                        $stmtCols = $pdoBio->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
                        if ($stmtCols->rowCount() === 0) {
                            $pdoBio->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL AFTER persona_name");
                        }
                    } catch (Throwable) {
                    }

                    try {
                        $stmtCols = $pdoBio->query("SHOW COLUMNS FROM users LIKE 'genesis_status'");
                        if ($stmtCols->rowCount() === 0) {
                            $pdoBio->exec("ALTER TABLE users ADD COLUMN genesis_status INT DEFAULT 0 AFTER role");
                        }
                    } catch (Throwable) {
                    }

                    $pdoReg = mh_persona_registry_pdo();
                    if (!mh_persona_registry_claim($pdoReg, $username, $personaName)) {
                        auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Persona name already in use'], 400);
                        exit;
                    }

                    try {
                        if (function_exists('mh_ensure_user_real_name_schema')) {
                            mh_ensure_user_real_name_schema($pdoBio);
                        }
                        $ins = $pdoBio->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, role, genesis_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Users', 0, NOW())");
                        $ins->execute([$username, $displayName, $firstName, $surname, $personaName, $userTenantId]);
                    } catch (Throwable $e) {
                        mh_persona_registry_release($pdoReg, $username, $personaName);
                        throw $e;
                    }

                    $pinBackup = new MetaPinBackup();
                    $pinBackup->setPinForUser($username, $pin);

                    try {
                        $dbConfigId = mh_resolve_tenant_db_config_id($userTenantId);
                        if (!is_string($dbConfigId) || $dbConfigId === '') {
                            mh_provision_tenant_storage($userTenantId);
                            $prov = mh_provision_tenant_database($userTenantId);
                            $dbConfigId = is_array($prov) ? (string)($prov['db_config_id'] ?? '') : '';
                        }
                        if ($dbConfigId !== '') {
                            $pdoTenant = database_getConnectionById($dbConfigId);
                            if ($pdoTenant instanceof PDO) {
                            }
                        }
                        if (function_exists('mh_provision_tenant_integrations')) {
                            mh_provision_tenant_integrations($userTenantId, $username);
                        }
                    } catch (Throwable) {
                    }
                }
            } catch (Exception $e) {
                // If DB check fails, we proceed with caution or log it. 
                // For now, let's log it.
                error_log("Biometrics Check Error: " . $e->getMessage());
                auth_json_response(['success' => false, 'error' => 'registration_setup_failed', 'message' => 'Registration could not be initialized. Please try again.'], 400);
                exit;
            }

            $userId = $username;
            $auth = new MetaPasskeyAuth();
            $challenge = $auth->generateRegistrationChallenge($userId, $username, $displayName);

            $_SESSION['mh_reg_username'] = $username;
            $_SESSION['mh_reg_display'] = $displayName;
            $_SESSION['mh_reg_first'] = $firstName;
            $_SESSION['mh_reg_last'] = $surname;
            $_SESSION['mh_reg_persona'] = $personaName;
            $_SESSION['mh_reg_pin'] = $pin;

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
            $firstName = isset($input['firstName']) ? trim((string)$input['firstName']) : (isset($_SESSION['mh_reg_first']) ? trim((string)$_SESSION['mh_reg_first']) : '');
            $surname = isset($input['surname']) ? trim((string)$input['surname']) : (isset($_SESSION['mh_reg_last']) ? trim((string)$_SESSION['mh_reg_last']) : '');
            $personaName = isset($input['persona_id']) ? trim((string)$input['persona_id']) : ($_SESSION['mh_reg_persona'] ?? '');
            $pin = isset($input['pin']) ? trim((string)$input['pin']) : ($_SESSION['mh_reg_pin'] ?? '');

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
            if ($challengeId === '' || !is_array($credential) || $username === '' || $personaName === '' || $pin === '' || (($firstName === '' || $surname === '') && $displayName === '')) {
                auth_json_response(['success' => false, 'error' => 'invalid_payload'], 400);
                exit;
            }
            if (!preg_match('/^[0-9]{5,}$/', $pin)) {
                auth_json_response(['success' => false, 'error' => 'invalid_pin_format', 'message' => 'PIN must be at least 5 digits'], 400);
                exit;
            }
            try { mh_reg_validate_username_strict($username); } catch (Throwable $e) { auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => $e->getMessage()], 400); exit; }
            try { mh_reg_validate_real_first_and_surname($firstName, $surname); } catch (Throwable $e) { auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => $e->getMessage()], 400); exit; }

            $auth = new MetaPasskeyAuth();
            $auth->verifyRegistration($challengeId, $credential);

            // Save user to Biometrics Database
            try {
                // Ensure database module is loaded
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }
                
                $bioConfig = null;
                if (function_exists('database_getConfiguration')) {
                    $bioConfig = database_getConfiguration('biometrics');
                }
                
                if ($bioConfig) {
                    $pdoBio = database_getConnectionById('biometrics');
                    if ($pdoBio instanceof PDO && function_exists('mh_registration_seed_default_policy_rules')) {
                        try { mh_registration_seed_default_policy_rules($pdoBio); } catch (Throwable) {}
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $deviceFingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
                        if (function_exists('mh_registration_rate_limit_check')) {
                            $ipCheck = mh_registration_rate_limit_check($pdoBio, 'ip', $ipAddress, 10, 600, 900);
                            if (is_array($ipCheck) && isset($ipCheck['ok']) && $ipCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $fpCheck = mh_registration_rate_limit_check($pdoBio, 'fingerprint', $deviceFingerprint, 5, 600, 3600);
                            if (is_array($fpCheck) && isset($fpCheck['ok']) && $fpCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                            $uCheck = mh_registration_rate_limit_check($pdoBio, 'username', $username, 10, 3600, 3600);
                            if (is_array($uCheck) && isset($uCheck['ok']) && $uCheck['ok'] === false) {
                                auth_json_response(['success' => false, 'error' => 'rate_limited', 'message' => 'Too many attempts. Please try again later.'], 429);
                                exit;
                            }
                        }
                        if (function_exists('mh_registration_policy_evaluate')) {
                            $p1 = mh_registration_policy_evaluate($pdoBio, 'real_first_name', $firstName);
                            if (is_array($p1) && (($p1['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_first_name', (string)($p1['reason'] ?? 'policy'), $firstName);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($p1)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_first_name', (string)($p1['reason'] ?? 'policy'), $firstName);
                                auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => 'Real name is invalid.'], 400);
                                exit;
                            }
                            $p2 = mh_registration_policy_evaluate($pdoBio, 'real_last_name', $surname);
                            if (is_array($p2) && (($p2['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_last_name', (string)($p2['reason'] ?? 'policy'), $surname);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($p2)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'real_last_name', (string)($p2['reason'] ?? 'policy'), $surname);
                                auth_json_response(['success' => false, 'error' => 'invalid_name', 'message' => 'Real surname is invalid.'], 400);
                                exit;
                            }
                            $pU = mh_registration_policy_evaluate($pdoBio, 'username', $username);
                            if (is_array($pU) && (($pU['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'username', (string)($pU['reason'] ?? 'policy'), $username);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($pU)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'username', (string)($pU['reason'] ?? 'policy'), $username);
                                auth_json_response(['success' => false, 'error' => 'invalid_username', 'message' => 'Username is invalid.'], 400);
                                exit;
                            }
                            $pP = mh_registration_policy_evaluate($pdoBio, 'persona_name', $personaName);
                            if (is_array($pP) && (($pP['action'] ?? 'reject') === 'review')) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'persona_name', (string)($pP['reason'] ?? 'policy'), $personaName);
                                auth_json_response(['success' => false, 'error' => 'manual_review', 'message' => 'Registration requires manual review. Please contact support.'], 400);
                                exit;
                            }
                            if (is_array($pP)) {
                                if (function_exists('mh_registration_review_enqueue')) mh_registration_review_enqueue($pdoBio, $username, $ipAddress, $deviceFingerprint, 'persona_name', (string)($pP['reason'] ?? 'policy'), $personaName);
                                auth_json_response(['success' => false, 'error' => 'persona_invalid', 'message' => 'Persona name is invalid.'], 400);
                                exit;
                            }
                        }
                    }
                        
                    try {
                        $stDel = $pdoBio->prepare("SELECT 1 FROM mh_deleted_users WHERE username = ? LIMIT 1");
                        $stDel->execute([$username]);
                        if ((bool)$stDel->fetchColumn()) {
                            auth_json_response(['success' => false, 'error' => 'username_taken', 'message' => 'Username is not available.'], 400);
                            exit;
                        }
                    } catch (Throwable) {
                    }
                    $stmt = $pdoBio->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if (!$stmt->fetch()) {
                            // Generate tenant_id for User
                            $userTenantId = 'user:' . $username;

                            // Ensure tenant_id column exists in users
                            try {
                                $stmtCols = $pdoBio->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
                                if ($stmtCols->rowCount() === 0) {
                                    $pdoBio->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL AFTER persona_name");
                                }
                            } catch (Exception) {}

                            // Ensure genesis_status column exists in users
                            try {
                                $stmtCols = $pdoBio->query("SHOW COLUMNS FROM users LIKE 'genesis_status'");
                                if ($stmtCols->rowCount() === 0) {
                                    $pdoBio->exec("ALTER TABLE users ADD COLUMN genesis_status INT DEFAULT 0 AFTER role");
                                }
                            } catch (Exception) {}

                            $pdoReg = mh_persona_registry_pdo();
                            if (!mh_persona_registry_claim($pdoReg, $username, $personaName)) {
                                auth_json_response(['success' => false, 'error' => 'persona_taken', 'message' => 'Persona name already in use'], 400);
                                exit;
                            }

                            try {
                                if (function_exists('mh_ensure_user_real_name_schema')) {
                                    mh_ensure_user_real_name_schema($pdoBio);
                                }
                                $stmt = $pdoBio->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, role, genesis_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Users', 0, NOW())");
                                $stmt->execute([$username, $displayName, $firstName, $surname, $personaName, $userTenantId]);
                            } catch (Throwable $e) {
                                mh_persona_registry_release($pdoReg, $username, $personaName);
                                throw $e;
                            }

                            $dbConfigId = mh_resolve_tenant_db_config_id($userTenantId);
                            if (!is_string($dbConfigId) || $dbConfigId === '') {
                                mh_provision_tenant_storage($userTenantId);
                                $prov = mh_provision_tenant_database($userTenantId);
                                $dbConfigId = is_array($prov) ? (string)($prov['db_config_id'] ?? '') : '';
                            }
                            if ($dbConfigId !== '') {
                                $pdoTenant = database_getConnectionById($dbConfigId);
                                if ($pdoTenant instanceof PDO) {
                                }
                            }
                            try {
                                if (function_exists('mh_provision_tenant_integrations')) {
                                    mh_provision_tenant_integrations($userTenantId, $username);
                                }
                            } catch (Throwable) {}
                        }
                    }
            } catch (Exception $e) {
                error_log("Biometrics Insert Error: " . $e->getMessage());
            }

            $pinBackup = new MetaPinBackup();
            $pinBackup->setPinForUser($username, $pin);

            unset($_SESSION['mh_reg_username'], $_SESSION['mh_reg_display'], $_SESSION['mh_reg_pin'], $_SESSION['mh_reg_first'], $_SESSION['mh_reg_last']);
            unset($_SESSION['mh_allow_logged_in_add_device_for']);
            
            // Auto-login after registration
            $_SESSION['mh_auth_display'] = $displayName;
            $_SESSION['mh_auth_persona'] = $personaName;
            
            // Load user role and permissions
            mh_auth_load_user_context($username);
            if (function_exists('mh_grid_bootstrap_user_tenant_best_effort')) {
                $gridBootstrap = mh_grid_bootstrap_user_tenant_best_effort($username);
                if (($gridBootstrap['ok'] ?? false) !== true) {
                    error_log('[GRID BOOTSTRAP] registration bootstrap failed for ' . $username . ': ' . json_encode($gridBootstrap, JSON_UNESCAPED_SLASHES));
                }
            }

            auth_json_response(['success' => true, 'userId' => $username]);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Meta Humans Identity</title>
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
            z-index: 99999;
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
        
        /* Auth Shell Styles (Copied/Adapted from login.php) */
        .mh-auth-shell {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
            box-sizing: border-box;
        }
        .mh-auth-panel {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 16px;
            padding: 2.5rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        .mh-auth-title {
            color: #fff;
            font-size: 1.875rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        .mh-auth-subtitle {
            color: #a0a0a0;
            text-align: center;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .mh-auth-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        .mh-auth-field-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .mh-auth-field-group label {
            color: #d1d5db;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .mh-auth-input-shell input {
            width: 100%;
            padding: 0.75rem;
            background: #2a2a2a;
            border: 1px solid #444;
            border-radius: 6px;
            color: #fff;
            font-size: 1rem;
            box-sizing: border-box;
        }
        .mh-auth-input-shell input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
        }
        .mh-auth-button-main {
            width: 100%;
            padding: 0.875rem;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .mh-auth-button-main:hover {
            background: #2563eb;
        }
        .mh-auth-register-button {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #60a5fa;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .mh-auth-register-button:hover {
            text-decoration: underline;
        }
        .mh-auth-status {
            margin-top: 1rem;
            text-align: center;
            font-size: 0.875rem;
            min-height: 1.25rem;
        }
        .mh-auth-status.error { color: #ef4444; }
        .mh-auth-status.success { color: #10b981; }
        .mh-auth-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(59, 130, 246, 0.1);
            color: #60a5fa;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            justify-content: center;
            width: fit-content;
            margin-left: auto;
            margin-right: auto;
            display: flex;
        }
        .mh-auth-pill-dot {
            width: 6px;
            height: 6px;
            background: currentColor;
            border-radius: 50%;
        }
    </style>
</head>
<body class="mh-auth-body">
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>

<?php if ($registrationLocked && $currentUser): ?>
    <div class="mh-auth-popup-overlay">
        <div class="mh-auth-popup">
            <h2>Welcome Back</h2>
            <p>You are currently signed in as <strong><?php echo htmlspecialchars((string)$currentUser, ENT_QUOTES, 'UTF-8'); ?></strong>.<br>Would you like to proceed to the Hub?</p>
            <div class="mh-auth-popup-actions">
                <a href="/hub/" class="mh-auth-btn mh-auth-btn-primary">Go to Hub</a>
                <a href="<?php echo htmlspecialchars($baseUrl . '/auth/logout.php', ENT_QUOTES, 'UTF-8'); ?>" class="mh-auth-btn mh-auth-btn-secondary">Logout</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <main class="mh-auth-main">
        <section class="mh-auth-shell">
            <div class="mh-auth-panel">
                <div class="mh-auth-pill">
                    <span class="mh-auth-pill-dot"></span>
                    <span>Meta Humans Identity</span>
                </div>
                <h1 class="mh-auth-title"><?php echo $allowLoggedInAddDevice ? 'Add This Device' : 'Register New Identity'; ?></h1>
                <p class="mh-auth-subtitle">
                    <?php if ($allowLoggedInAddDevice): ?>
                        Add a new passkey on this device for <strong><?php echo htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8'); ?></strong>. Enter your PIN to verify it is you.
                    <?php else: ?>
                        Create a passkey bound to this device and a secure PIN fallback.
                    <?php endif; ?>
                </p>
                <form id="registrationForm" class="mh-auth-form" autocomplete="off">
                    <input type="text" id="regWebsite" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
                    <div class="mh-auth-field-group" style="<?php echo $allowLoggedInAddDevice ? 'display:none;' : ''; ?>">
                        <label>Real Name</label>
                        <div class="mh-auth-input-shell">
                            <input type="text" id="regFirstName" name="firstName" autocomplete="given-name" required>
                        </div>
                    </div>
                    <div class="mh-auth-field-group" style="<?php echo $allowLoggedInAddDevice ? 'display:none;' : ''; ?>">
                        <label>Real Surname</label>
                        <div class="mh-auth-input-shell">
                            <input type="text" id="regLastName" name="lastName" autocomplete="family-name" required>
                        </div>
                        <div style="opacity: 0.8; font-size: 12px; margin-top: 6px;">First Name and Surname exactly as on your ID/Passport/Drivers License as this will become important for payout requests.</div>
                    </div>
                    <div class="mh-auth-field-group">
                        <label>Username</label>
                        <div class="mh-auth-input-shell">
                            <input type="text" id="regUsername" name="username" autocomplete="username" required value="<?php echo $allowLoggedInAddDevice ? htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') : ''; ?>" <?php echo $allowLoggedInAddDevice ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    <div class="mh-auth-field-group" id="groupPersonaId" style="<?php echo $allowLoggedInAddDevice ? 'display:none;' : ''; ?>">
                        <label>Meta Human Persona Name</label>
                        <div class="mh-auth-input-shell">
                            <input type="text" id="regPersonaId" name="persona_id" autocomplete="off" placeholder="e.g. MH-Kripz" required>
                        </div>
                    </div>
                    <div class="mh-auth-field-group">
                        <label><?php echo $allowLoggedInAddDevice ? 'PIN' : 'PIN (minimum 5 digits)'; ?></label>
                        <div class="mh-auth-input-shell">
                            <input type="password" id="regPin" name="pin" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="mh-auth-field-group" style="<?php echo $allowLoggedInAddDevice ? 'display:none;' : ''; ?>">
                        <label>Confirm PIN</label>
                        <div class="mh-auth-input-shell">
                            <input type="password" id="regPinConfirm" name="pinConfirm" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button type="button" class="mh-auth-button-main" id="startRegistrationButton">
                        <?php echo $allowLoggedInAddDevice ? 'Add This Device Now' : 'Continue'; ?>
                    </button>
                    <div class="mh-auth-status" id="registrationStatus"></div>
                </form>
                <a class="mh-auth-register-button" href="<?php echo htmlspecialchars($baseUrl . ($allowLoggedInAddDevice ? '/auth/settings.php' : '/auth/login.php'), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo $allowLoggedInAddDevice ? 'Back to settings' : 'Back to login'; ?>
                </a>
            </div>
        </section>
    </main>
<?php endif; ?>

<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
    const forceAddDeviceMode = <?php echo $allowLoggedInAddDevice ? 'true' : 'false'; ?>;
    const forcedAddDeviceUsername = <?php echo json_encode($allowLoggedInAddDevice ? $currentUser : '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const registrationStatus = document.getElementById("registrationStatus");
    const startRegistrationButton = document.getElementById("startRegistrationButton");
    const usernameInput = document.getElementById("regUsername");
    const firstNameInput = document.getElementById("regFirstName");
    const lastNameInput = document.getElementById("regLastName");
    const personaIdGroup = document.getElementById("groupPersonaId");
    const pinConfirmGroup = document.getElementById("regPinConfirm").closest('.mh-auth-field-group');
    
    let isAddDeviceMode = false;
    let debounceTimer;

    function renderStatus(container, type, message) {
        if (!container) return;
        container.textContent = message;
        container.className = "mh-auth-status " + type;
    }

    function looksLikeInAppBrowser() {
        const ua = String(navigator.userAgent || '');
        if (/\bwv\b/i.test(ua)) return true;
        if (/FBAN|FBAV|Instagram|Line\/|Twitter|TikTok|Snapchat|Pinterest/i.test(ua)) return true;
        return false;
    }

    async function preflightPasskeyChecks() {
        if (!window.isSecureContext) {
            return { ok: false, message: 'Passkeys require HTTPS. Please open the site using https:// and try again.' };
        }
        if (!window.PublicKeyCredential || !navigator.credentials) {
            return { ok: false, message: 'This browser does not support passkeys. Please use Chrome, Safari, or Edge.' };
        }
        if (looksLikeInAppBrowser()) {
            return { ok: false, message: 'Passkeys are often blocked inside in-app browsers. Open this page in your main browser (Chrome/Safari/Edge) and try again.' };
        }
        try {
            if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
                const ok = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                if (!ok) {
                    return { ok: false, message: 'This device is not ready for Face ID / Touch ID / Windows Hello. Enable screen lock/biometrics and try again.' };
                }
            }
        } catch (e) {
        }
        return { ok: true, message: '' };
    }

    function explainProviderSignIn() {
        const ua = String(navigator.userAgent || '').toLowerCase();
        const provider = ua.includes('iphone') || ua.includes('ipad') || ua.includes('mac') ? 'Apple (iCloud Keychain)' :
            (ua.includes('android') ? 'Google Password Manager / Samsung Pass' :
                (ua.includes('windows') ? 'Microsoft (Windows Hello)' : 'your device passkey provider'));
        return `Next you will see a system prompt (Face ID / Touch ID / Windows Hello / Screen lock). If you see a ${provider} sign-in screen, that is your device asking you to enable passkeys. It is not a Meta Humans login.`;
    }

    function base64UrlToBuffer(base64Url) {
        const padding = '='.repeat((4 - base64Url.length % 4) % 4);
        const base64 = (base64Url + padding).replace(/\-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function bufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
    }

    async function checkUserExistence() {
        if (forceAddDeviceMode) {
            return;
        }
        const username = usernameInput.value.trim();
        if (!username) return;

        try {
            const res = await fetch('register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'check_user_status',
                    username: username
                })
            });
            const data = await res.json();
            
            const exists = !!(data && data.exists);
            const hasPin = !!(data && data.has_pin);
            const hasPasskeys = !!(data && data.has_passkeys);

            if (exists && hasPin && hasPasskeys) {
                isAddDeviceMode = true;
                if (personaIdGroup) personaIdGroup.style.display = 'none';
                if (pinConfirmGroup) pinConfirmGroup.style.display = 'none';
                startRegistrationButton.textContent = 'Add this Device';
                startRegistrationButton.disabled = false;
                renderStatus(registrationStatus, 'info', 'User found. Enter your PIN to add this device.');
                return;
            }

            if (exists && hasPin && !hasPasskeys) {
                isAddDeviceMode = true;
                if (personaIdGroup) personaIdGroup.style.display = 'none';
                if (pinConfirmGroup) pinConfirmGroup.style.display = 'none';
                startRegistrationButton.textContent = 'Create First Passkey';
                startRegistrationButton.disabled = false;
                renderStatus(registrationStatus, 'info', 'Username found. Enter your PIN to create your first passkey on this device.');
                return;
            }

            if (exists && !hasPin) {
                isAddDeviceMode = false;
                if (personaIdGroup) personaIdGroup.style.display = 'block';
                if (pinConfirmGroup) pinConfirmGroup.style.display = 'block';
                startRegistrationButton.textContent = 'Continue';
                startRegistrationButton.disabled = true;
                renderStatus(registrationStatus, 'error', 'This username already exists but has no PIN set. Login with an existing passkey first, then set a PIN, or choose a different username.');
                return;
            }

            isAddDeviceMode = false;
            if (personaIdGroup) personaIdGroup.style.display = 'block';
            if (pinConfirmGroup) pinConfirmGroup.style.display = 'block';
            startRegistrationButton.textContent = 'Continue';
            startRegistrationButton.disabled = false;
            renderStatus(registrationStatus, '', '');
        } catch (e) {
            console.error('Error checking user status:', e);
        }
    }

    async function handleRegistration() {
        if (!startRegistrationButton) return;

        const username = document.getElementById('regUsername').value.trim();
        const firstName = firstNameInput ? firstNameInput.value.trim() : '';
        const lastName = lastNameInput ? lastNameInput.value.trim() : '';
        const displayName = (firstName + ' ' + lastName).trim();
        const personaName = document.getElementById('regPersonaId').value.trim();
        const pin = document.getElementById('regPin').value.trim();
        const pinConfirm = document.getElementById('regPinConfirm').value.trim();

        if (!username || pin.length < 5) {
             renderStatus(registrationStatus, 'error', 'Please enter a username and a valid PIN (min 5 digits).');
             return;
        }

        if (!isAddDeviceMode) {
            if (!firstName || !lastName || !personaName || !pinConfirm) {
                renderStatus(registrationStatus, 'error', 'Please fill in all fields including first name, last name, and persona.');
                return;
            }
            const fnc = String(firstName || '').replace(/[^a-zA-Z\-']/g, '').toLowerCase();
            const lnc = String(lastName || '').replace(/[^a-zA-Z\-']/g, '').toLowerCase();
            if (fnc && lnc && fnc === lnc) {
                renderStatus(registrationStatus, 'error', 'Real name and surname cannot be the same.');
                return;
            }
            if (pin !== pinConfirm) {
                renderStatus(registrationStatus, 'error', 'PINs do not match.');
                return;
            }
        }

        renderStatus(registrationStatus, '', 'Initializing passkey creation...');
        startRegistrationButton.disabled = true;

        try {
            const preflight = await preflightPasskeyChecks();
            if (!preflight.ok) {
                renderStatus(registrationStatus, 'error', preflight.message);
                startRegistrationButton.disabled = false;
                return;
            }

            renderStatus(registrationStatus, 'info', explainProviderSignIn());

            let startData;

            if (isAddDeviceMode) {
                // Flow for adding device to existing user
                const startRes = await fetch('register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'verify_pin_and_start_add_device',
                        username: username,
                        pin: pin
                    })
                });
                startData = await startRes.json();
            } else {
                // Flow for new user registration
                const startRes = await fetch('register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'start_passkey_registration',
                        username: username,
                        displayName: displayName,
                        persona_id: personaName,
                        pin: pin
                    })
                });
                startData = await startRes.json();
            }

            if (!startData.success) {
                throw new Error(startData.message || startData.error);
            }

            // Common WebAuthn creation logic
            const options = startData.publicKey;
            options.challenge = base64UrlToBuffer(options.challenge);
            options.user.id = base64UrlToBuffer(options.user.id);
            if (options.user && options.user.id && options.user.id.byteLength > 64 && window.crypto && window.crypto.subtle) {
                try {
                    const digest = await window.crypto.subtle.digest('SHA-256', options.user.id);
                    options.user.id = digest.slice(0, 32);
                } catch (e) {}
            }
            
            if (!options.authenticatorSelection) {
                options.authenticatorSelection = {};
            }
            // Enforce platform authenticator (FaceID/TouchID/Windows Hello)
            options.authenticatorSelection.authenticatorAttachment = 'platform';
            options.authenticatorSelection.userVerification = 'required';
            options.authenticatorSelection.residentKey = 'preferred';
            options.authenticatorSelection.requireResidentKey = false;

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
            
            const attestationObject = bufferToBase64Url(credential.response.attestationObject);
            const clientDataJSON = bufferToBase64Url(credential.response.clientDataJSON);
            
            const finishRes = await fetch('register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'finish_passkey_registration',
                    challengeId: startData.challengeId,
                    username: username, 
                    displayName: isAddDeviceMode ? '' : displayName, 
                    persona_id: isAddDeviceMode ? '' : personaName,
                    pin: pin,
                    credential: {
                        id: credential.id,
                        rawId: bufferToBase64Url(credential.rawId),
                        type: credential.type,
                        response: {
                            attestationObject: attestationObject,
                            clientDataJSON: clientDataJSON
                        }
                    }
                })
            });

            const finishData = await finishRes.json();
            if (!finishData.success) {
                throw new Error(finishData.message || finishData.error);
            }

            renderStatus(registrationStatus, 'success', 'Success! Redirecting...');
            setTimeout(() => {
                window.location.href = isAddDeviceMode ? '/auth/settings.php?device_added=1' : '/hub/genesis/tokenization.php';
            }, 1500);

        } catch (err) {
            console.error(err);
            const name = String(err && err.name ? err.name : '');
            const msg = String(err && err.message ? err.message : '');
            let userMessage = msg || 'Operation failed.';
            if (name === 'NotAllowedError') {
                userMessage = 'Passkey creation was canceled or blocked. If you are in an in-app browser, open this page in Chrome/Safari/Edge. Make sure screen lock/biometrics is enabled, then try again.';
            } else if (name === 'SecurityError') {
                userMessage = 'Passkeys are blocked for this page. Ensure you are on HTTPS and not in private/incognito mode, then try again.';
            } else if (name === 'InvalidStateError') {
                userMessage = 'A passkey for this account may already exist on this device. Try logging in instead, or use a different device.';
            } else if (name === 'UnknownError' && /credential manager/i.test(msg)) {
                userMessage = 'Your device passkey manager could not start. If you are asked to sign in to Apple/Google/Microsoft/Samsung, that is to enable passkeys. After enabling, return here and try again.';
            }
            if (!isAddDeviceMode && username && pin) {
                try {
                    const resp = await fetch('login.php?action=pin_login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ userId: username, pin: pin })
                    });
                    const data = await resp.json();
                    if (data && data.success) {
                        window.location.href = '/hub/genesis/tokenization.php';
                        return;
                    }
                } catch (e) {
                }
                window.location.href = '/auth/login.php?user=' + encodeURIComponent(username);
                return;
            }

            renderStatus(registrationStatus, 'error', userMessage);
            startRegistrationButton.disabled = false;
        }
    }

    if (startRegistrationButton) {
        startRegistrationButton.addEventListener('click', handleRegistration);
    }
    
    if (forceAddDeviceMode) {
        isAddDeviceMode = true;
        if (usernameInput) {
            usernameInput.value = forcedAddDeviceUsername || '';
            usernameInput.readOnly = true;
        }
        if (personaIdGroup) {
            personaIdGroup.style.display = 'none';
        }
        if (pinConfirmGroup) {
            pinConfirmGroup.style.display = 'none';
        }
        renderStatus(registrationStatus, 'info', 'Enter your PIN to add this device to your account.');
    } else if (usernameInput) {
        usernameInput.addEventListener('blur', checkUserExistence);
        usernameInput.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(checkUserExistence, 500);
        });
    }
</script>
</body>
</html>
