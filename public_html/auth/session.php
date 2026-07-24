<?php
require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/lemonldap-handler.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowedOrigins = [
    'https://pdf.metahumans.one',
];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Content-Type: application/json; charset=utf-8');

$sso = function_exists('lemonldap_process_headers') ? lemonldap_process_headers() : null;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $json = json_decode($raw, true);
    $input = is_array($json) ? $json : [];
    if (empty($input)) {
        $input = $_POST;
    }

    $action = isset($input['action']) ? trim((string)$input['action']) : '';
    if ($action === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'missing_action']);
        exit;
    }

    $user = $_SESSION['mh_auth_user'] ?? null;
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'authenticated' => false]);
        exit;
    }

    if (function_exists('cue_autoload')) {
        try { cue_autoload('database'); } catch (Throwable $e) {}
    }
    if (!function_exists('database_getConnectionById')) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'database_unavailable']);
        exit;
    }

    try {
        $pdoBio = database_getConnectionById('biometrics');
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'biometrics_unavailable']);
        exit;
    }

    if (!(is_object($pdoBio) && $pdoBio instanceof PDO)) {
        if (is_object($pdoBio) && property_exists($pdoBio, 'pdo') && $pdoBio->pdo instanceof PDO) {
            $pdoBio = $pdoBio->pdo;
        }
    }

    if (!($pdoBio instanceof PDO)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'biometrics_unavailable']);
        exit;
    }

    $role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
    $isKripz = (stripos($role, 'KripzMaster') !== false);

    $targetUser = (string)$user;
    if ($isKripz && isset($input['username']) && is_string($input['username']) && trim($input['username']) !== '') {
        $targetUser = trim((string)$input['username']);
    }

    $stmt = $pdoBio->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$targetUser]);
    $targetRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($targetRow) || !isset($targetRow['id'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'user_not_found']);
        exit;
    }
    $targetUserId = (int)$targetRow['id'];

    try {
        if ($action === 'list_devices') {
            $stmt = $pdoBio->prepare("SELECT series_id, issued_at, expires_at, last_seen_at, revoked_at, revoked_reason, risk_last, risk_flags_last, ua_family, ua_major, os_family, asn, country, ip_prefix
                FROM user_device_tokens
                WHERE user_id = ?
                ORDER BY last_seen_at DESC, issued_at DESC
                LIMIT 50");
            $stmt->execute([$targetUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'username' => $targetUser, 'devices' => $rows], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'revoke_device') {
            $seriesId = isset($input['series_id']) ? trim((string)$input['series_id']) : '';
            if ($seriesId === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'missing_series_id']);
                exit;
            }
            $reason = isset($input['reason']) ? trim((string)$input['reason']) : 'revoked';
            $stmt = $pdoBio->prepare("UPDATE user_device_tokens
                SET revoked_at = NOW(), revoked_reason = ?
                WHERE user_id = ? AND series_id = ? AND revoked_at IS NULL");
            $stmt->execute([$reason, $targetUserId, $seriesId]);
            echo json_encode(['ok' => true, 'revoked' => $stmt->rowCount() > 0], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'revoke_all_devices') {
            $reason = isset($input['reason']) ? trim((string)$input['reason']) : 'revoked_all';
            $stmt = $pdoBio->prepare("UPDATE user_device_tokens
                SET revoked_at = NOW(), revoked_reason = ?
                WHERE user_id = ? AND revoked_at IS NULL");
            $stmt->execute([$reason, $targetUserId]);
            echo json_encode(['ok' => true, 'revoked_count' => (int)$stmt->rowCount()], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'list_sessions') {
            $stmt = $pdoBio->prepare("SELECT session_id, created_at, last_seen_at, revoked_at, ua_family, ua_major, os_family, asn, country, ip_prefix, device_token_id
                FROM user_sessions
                WHERE user_id = ?
                ORDER BY last_seen_at DESC, created_at DESC
                LIMIT 100");
            $stmt->execute([$targetUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'username' => $targetUser, 'sessions' => $rows], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'revoke_session') {
            $sid = isset($input['session_id']) ? trim((string)$input['session_id']) : '';
            if ($sid === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'missing_session_id']);
                exit;
            }
            $stmt = $pdoBio->prepare("UPDATE user_sessions
                SET revoked_at = NOW()
                WHERE user_id = ? AND session_id = ? AND revoked_at IS NULL");
            $stmt->execute([$targetUserId, $sid]);
            echo json_encode(['ok' => true, 'revoked' => $stmt->rowCount() > 0], JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown_action']);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error']);
        exit;
    }
}

$user = $_SESSION['mh_auth_user'] ?? null;
if (!$user) {
    $sn = session_name();
    $sid = session_id();
    $cookieParams = session_get_cookie_params();
    $cookieValue = null;
    if (is_string($sn) && $sn !== '' && isset($_COOKIE[$sn]) && is_string($_COOKIE[$sn]) && $_COOKIE[$sn] !== '') {
        $cookieValue = (string)$_COOKIE[$sn];
    }
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'authenticated' => false,
        'sso' => [
            'present' => is_array($sso) && isset($sso['username']) && is_string($sso['username']) && trim((string)$sso['username']) !== '',
            'username' => (is_array($sso) && isset($sso['username']) && is_string($sso['username'])) ? $sso['username'] : null,
            'groups_present' => (is_array($sso) && isset($sso['groups']) && is_string($sso['groups']) && trim((string)$sso['groups']) !== ''),
            'source' => (is_array($sso) && isset($sso['source']) && is_string($sso['source'])) ? $sso['source'] : null,
            'groups_source' => (is_array($sso) && isset($sso['groups_source']) && is_string($sso['groups_source'])) ? $sso['groups_source'] : null,
        ],
        'session' => [
            'name' => is_string($sn) ? $sn : null,
            'id' => is_string($sid) && $sid !== '' ? $sid : null,
            'cookie_value_present' => $cookieValue !== null,
            'cookie_value_equals_session_id' => ($cookieValue !== null && $sid !== '' && hash_equals($sid, $cookieValue)),
            'cookie_params' => $cookieParams,
            'samesite' => $cookieParams['samesite'] ?? null,
            'secure' => (bool)($cookieParams['secure'] ?? false),
            'domain' => $cookieParams['domain'] ?? null,
            'path' => $cookieParams['path'] ?? null,
        ],
        'php_session' => [
            'save_handler' => ini_get('session.save_handler') ?: null,
            'save_path' => ini_get('session.save_path') ?: null,
            'gc_maxlifetime' => ini_get('session.gc_maxlifetime') ?: null,
            'gc_probability' => ini_get('session.gc_probability') ?: null,
            'gc_divisor' => ini_get('session.gc_divisor') ?: null,
            'cookie_lifetime' => ini_get('session.cookie_lifetime') ?: null,
        ],
        'server' => [
            'host' => $_SERVER['HTTP_HOST'] ?? null,
            'https' => $_SERVER['HTTPS'] ?? null,
            'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$sn = session_name();
$sid = session_id();
$cookieParams = session_get_cookie_params();
$cookieValue = null;
if (is_string($sn) && $sn !== '' && isset($_COOKIE[$sn]) && is_string($_COOKIE[$sn]) && $_COOKIE[$sn] !== '') {
    $cookieValue = (string)$_COOKIE[$sn];
}
$mhLastSeen = null;
if (isset($_SESSION['mh_last_seen'])) {
    $mhLastSeen = (int)$_SESSION['mh_last_seen'];
}
$mhRole = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : null;
$mhPerms = $_SESSION['mh_auth_permissions'] ?? null;
$mhPermsSummary = null;
if (is_string($mhPerms)) {
    $d = json_decode($mhPerms, true);
    if (is_array($d)) $mhPerms = $d;
}
if (is_array($mhPerms)) {
    $mhPermsSummary = [
        'realms' => isset($mhPerms['realms']) && is_array($mhPerms['realms']) ? array_values(array_map('strval', $mhPerms['realms'])) : null,
        'menus' => isset($mhPerms['menus']) && is_array($mhPerms['menus']) ? array_values(array_map('strval', $mhPerms['menus'])) : null,
        'submenus' => isset($mhPerms['submenus']) && is_array($mhPerms['submenus']) ? array_values(array_map('strval', $mhPerms['submenus'])) : null,
    ];
}

echo json_encode([
    'ok' => true,
    'authenticated' => true,
    'user' => (string)$user,
    'sso' => [
        'present' => is_array($sso) && isset($sso['username']) && is_string($sso['username']) && trim((string)$sso['username']) !== '',
        'username' => (is_array($sso) && isset($sso['username']) && is_string($sso['username'])) ? $sso['username'] : null,
        'groups_present' => (is_array($sso) && isset($sso['groups']) && is_string($sso['groups']) && trim((string)$sso['groups']) !== ''),
        'source' => (is_array($sso) && isset($sso['source']) && is_string($sso['source'])) ? $sso['source'] : null,
        'groups_source' => (is_array($sso) && isset($sso['groups_source']) && is_string($sso['groups_source'])) ? $sso['groups_source'] : null,
    ],
    'session' => [
        'name' => is_string($sn) ? $sn : null,
        'id' => is_string($sid) && $sid !== '' ? $sid : null,
        'cookie_value_present' => $cookieValue !== null,
        'cookie_value_equals_session_id' => ($cookieValue !== null && $sid !== '' && hash_equals($sid, $cookieValue)),
        'cookie_params' => $cookieParams,
        'samesite' => $cookieParams['samesite'] ?? null,
        'secure' => (bool)($cookieParams['secure'] ?? false),
        'domain' => $cookieParams['domain'] ?? null,
        'path' => $cookieParams['path'] ?? null,
    ],
    'mh_last_seen' => $mhLastSeen,
    'mh_auth_role' => $mhRole,
    'mh_auth_permissions' => $mhPermsSummary,
    'php_session' => [
        'save_handler' => ini_get('session.save_handler') ?: null,
        'save_path' => ini_get('session.save_path') ?: null,
        'gc_maxlifetime' => ini_get('session.gc_maxlifetime') ?: null,
        'gc_probability' => ini_get('session.gc_probability') ?: null,
        'gc_divisor' => ini_get('session.gc_divisor') ?: null,
        'cookie_lifetime' => ini_get('session.cookie_lifetime') ?: null,
    ],
    'server' => [
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'https' => $_SERVER['HTTPS'] ?? null,
        'x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    ],
]);
