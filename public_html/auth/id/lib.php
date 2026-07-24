<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
if (is_file(dirname(__DIR__) . '/tenant_provisioning.php')) {
    require_once dirname(__DIR__) . '/tenant_provisioning.php';
}

function mh_id_start_session(): void
{
    if (function_exists('startSecureSession')) {
        call_user_func('startSecureSession');
    } elseif (function_exists('security_startSecureSession')) {
        call_user_func('security_startSecureSession');
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    mh_id_restore_auth_context();
}

function mh_id_current_user(): string
{
    $u = $_SESSION['mh_auth_user'] ?? ($_SERVER['HTTP_AUTH_USER'] ?? ($_SERVER['REMOTE_USER'] ?? ''));
    return is_string($u) ? trim($u) : '';
}

function mh_id_require_user(): string
{
    $u = mh_id_current_user();
    if ($u === '') {
        http_response_code(401);
        exit;
    }
    return $u;
}

function mh_id_biometrics_restore_pdo(): ?PDO
{
    try {
        if (function_exists('cue_autoload')) {
            call_user_func('cue_autoload', 'database');
        }
        if (!function_exists('database_getConnectionById')) {
            return null;
        }
        $pdo = call_user_func('database_getConnectionById', 'biometrics');
        return $pdo instanceof PDO ? $pdo : null;
    } catch (Throwable) {
        return null;
    }
}

function mh_id_restore_from_remember_me(?PDO $pdoBio = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return !empty($_SESSION['mh_auth_user']);
    }
    if (!function_exists('mh_remember_me_get_pepper')) {
        return false;
    }

    $pdoBio = $pdoBio instanceof PDO ? $pdoBio : mh_id_biometrics_restore_pdo();
    $pepper = trim((string)call_user_func('mh_remember_me_get_pepper'));
    if (!$pdoBio instanceof PDO || $pepper === '') {
        return false;
    }

    if (function_exists('mh_remember_me_ensure_schema_once')) {
        mh_remember_me_ensure_schema_once($pdoBio);
    }

    // First try the new __Host-device cookie
    if (function_exists('mh_remember_me_resolve_device') && function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_find_user')) {
        $ctx = call_user_func('mh_remember_me_get_context');
        // Check if __Host-device exists
        if (isset($_COOKIE['__Host-device']) && is_string($_COOKIE['__Host-device']) && $_COOKIE['__Host-device'] !== '') {
            // Let's look up user_device_tokens by hash
            $token = $_COOKIE['__Host-device'];
            $hash = function_exists('mh_remember_me_hash_token') ? call_user_func('mh_remember_me_hash_token', $pepper, $token) : null;
            if ($hash !== null) {
                try {
                    $stmt = $pdoBio->prepare("SELECT user_id FROM user_device_tokens 
                        WHERE (token_hash = ? OR (prev_token_hash = ? AND prev_valid_until IS NOT NULL AND prev_valid_until >= NOW()))
                        AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
                    $stmt->execute([$hash, $hash]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row) && isset($row['user_id']) && (int)$row['user_id'] > 0) {
                        // Now get the user
                        $stmt2 = $pdoBio->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                        $stmt2->execute([(int)$row['user_id']]);
                        $userRow = $stmt2->fetch(PDO::FETCH_ASSOC);
                        if (is_array($userRow) && isset($userRow['username']) && trim($userRow['username']) !== '') {
                            $username = trim($userRow['username']);
                            // Now load user context
                            if (function_exists('mh_auth_load_user_context')) {
                                mh_auth_load_user_context($username, null, null);
                            }
                            $_SESSION['mh_auth_user'] = $username;
                            $_SESSION['mh_auth_method'] = 'remember_device';
                            
                            // Touch the device and rotate cookie if needed
                            $device = call_user_func('mh_remember_me_resolve_device', $pdoBio, $pepper, (int)$row['user_id'], $ctx);
                            if (function_exists('mh_remember_me_touch_device') && $device['recognized'] && isset($device['device_token_id'])) {
                                call_user_func('mh_remember_me_touch_device', $pdoBio, (int)$device['device_token_id'], $ctx);
                            }
                            if (function_exists('mh_remember_me_issue_or_rotate_cookie') && function_exists('mh_remember_me_should_rotate')) {
                                $shouldRotate = false;
                                if ($device['recognized'] && $device['row']) {
                                    $shouldRotate = call_user_func('mh_remember_me_should_rotate', $device['row']);
                                }
                                if ($shouldRotate || !$device['recognized']) {
                                    call_user_func('mh_remember_me_issue_or_rotate_cookie', $pdoBio, $pepper, (int)$row['user_id'], $device, $ctx, $shouldRotate ? 'periodic' : 'restore');
                                }
                            }
                            return true;
                        }
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    // Fall back to old mh_account_remember_me_try_restore if available
    if (function_exists('mh_account_remember_me_try_restore')) {
        $result = mh_account_remember_me_try_restore($pdoBio, $pepper);
        // If we restored from the old cookie, issue a new device cookie
        if ($result && function_exists('mh_remember_me_issue_or_rotate_cookie') && function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_resolve_device')) {
            $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
            if ($uid > 0) {
                $ctx = call_user_func('mh_remember_me_get_context');
                $device = call_user_func('mh_remember_me_resolve_device', $pdoBio, $pepper, $uid, $ctx);
                call_user_func('mh_remember_me_issue_or_rotate_cookie', $pdoBio, $pepper, $uid, $device, $ctx, 'migrated_from_old_cookie');
            }
        }
        return $result;
    }
    return false;
}

function mh_id_restore_from_lemonldap(?PDO $pdoBio = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return !empty($_SESSION['mh_auth_user']);
    }

    $handlerPath = dirname(__DIR__) . '/lemonldap-handler.php';
    if (is_file($handlerPath)) {
        require_once $handlerPath;
    }
    if (!function_exists('lemonldap_process_headers')) {
        return false;
    }

    $ssoData = lemonldap_process_headers();
    $ssoUser = is_array($ssoData) ? trim((string)($ssoData['username'] ?? '')) : '';
    if ($ssoUser === '') {
        return false;
    }

    if (function_exists('lemonldap_sync_user')) {
        lemonldap_sync_user($ssoData);
    }
    if (!function_exists('mh_auth_load_user_context')) {
        return false;
    }

    mh_auth_load_user_context($ssoUser, $ssoData['groups'] ?? null, $ssoData['email'] ?? null);
    $_SESSION['mh_auth_user'] = $ssoUser;
    $_SESSION['mh_auth_method'] = 'sso_lemonldap';
    if (!empty($ssoData['groups'])) {
        $_SESSION['mh_auth_groups'] = $ssoData['groups'];
    }

    $pdoBio = $pdoBio instanceof PDO ? $pdoBio : mh_id_biometrics_restore_pdo();
    $pepper = function_exists('mh_remember_me_get_pepper') ? trim((string)call_user_func('mh_remember_me_get_pepper')) : '';
    $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
    if ($pdoBio instanceof PDO && $pepper !== '' && $uid > 0 && function_exists('mh_remember_me_issue_or_rotate_cookie')) {
        if (function_exists('mh_remember_me_ensure_schema_once')) {
            mh_remember_me_ensure_schema_once($pdoBio);
        }
        if (function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_resolve_device')) {
            $ctx = call_user_func('mh_remember_me_get_context');
            $device = call_user_func('mh_remember_me_resolve_device', $pdoBio, $pepper, $uid, $ctx);
            call_user_func('mh_remember_me_issue_or_rotate_cookie', $pdoBio, $pepper, $uid, $device, $ctx, 'lemonldap_restore');
        }
    }

    return true;
}

function mh_id_restore_auth_context(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return;
    }

    $pdoBio = mh_id_biometrics_restore_pdo();
    if (mh_id_restore_from_remember_me($pdoBio)) {
        return;
    }
    mh_id_restore_from_lemonldap($pdoBio);
}

function mh_id_biometrics_pdo(): PDO
{
    if (function_exists('cue_autoload')) {
        call_user_func('cue_autoload', 'database');
    }
    if (!function_exists('database_getConnectionById')) {
        throw new RuntimeException('missing_database_provider');
    }
    $pdo = call_user_func('database_getConnectionById', 'biometrics');
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('biometrics_connection_failed');
    }
    return $pdo;
}

function mh_id_paths()
{
    return function_exists('cue_autoload') ? call_user_func('cue_autoload', 'paths') : null;
}

function mh_id_secure_path(string $relative, bool $createDir = false): string
{
    $relative = ltrim(str_replace(["..\\", "../"], '', $relative), '/');
    $paths = mh_id_paths();
    if ($paths && method_exists($paths, 'getSecureFilePath')) {
        $p = $paths->getSecureFilePath($relative, $createDir);
        return is_string($p) ? $p : '';
    }
    if (!function_exists('getDataPath')) {
        return '';
    }
    $base = rtrim((string)call_user_func('getDataPath'), '/');
    $full = $base . '/' . $relative;
    if ($createDir) {
        $dir = dirname($full);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    return $full;
}

function mh_id_tenant_safe_from_username(string $username): string
{
    $username = trim($username);
    $tenantId = 'user:' . $username;
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)call_user_func('mh_tenant_safe', $tenantId);
        if ($safe !== '') return $safe;
    }
    $safe = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
    $safe = str_replace(':', '_', (string)$safe);
    $safe = preg_replace('/_+/', '_', (string)$safe);
    return trim((string)$safe, '_');
}

function mh_id_evidence_relative_path(string $tenantSafe, string $roomId): string
{
    $tenantSafe = trim($tenantSafe);
    $roomId = trim($roomId);
    $tenantSafe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $tenantSafe);
    $roomId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $roomId);
    $tenantSafe = trim((string)$tenantSafe, '_');
    $roomId = trim((string)$roomId, '_');
    return 'tenants/' . $tenantSafe . '/meetings/' . $roomId . '/id';
}

function mh_id_ensure_schema(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_kyc (
        username VARCHAR(255) PRIMARY KEY,
        status VARCHAR(32) NOT NULL DEFAULT 'none',
        level INT NOT NULL DEFAULT 0,
        method VARCHAR(32) NULL,
        verified_at DATETIME NULL,
        expires_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        evidence_json LONGTEXT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_kyc_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        session_id VARCHAR(64) NOT NULL,
        token_sha256 VARCHAR(64) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'created',
        method VARCHAR(32) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        expires_at DATETIME NULL,
        evidence_path VARCHAR(512) NULL,
        evidence_json LONGTEXT NULL,
        KEY idx_user_kyc_sessions_user (username, created_at),
        UNIQUE KEY uniq_user_kyc_sessions (session_id),
        UNIQUE KEY uniq_user_kyc_token (token_sha256)
    )");
}

function mh_id_get_user_kyc_record(string $username): ?array
{
    $username = trim($username);
    if ($username === '') return null;
    try {
        $pdo = mh_id_biometrics_pdo();
        mh_id_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT username, status, level, method, verified_at, expires_at, updated_at, evidence_json FROM user_kyc WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function mh_id_user_has_verified_mosip(string $username): bool
{
    $row = mh_id_get_user_kyc_record($username);
    if (!is_array($row)) return false;
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $method = strtolower(trim((string)($row['method'] ?? '')));
    return $status === 'verified' && $method === 'mosip';
}

function mh_id_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_id_read_json_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) return $decoded;
        }
    }
    return $_POST ?: [];
}

function mh_id_find_session_by_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') return null;
    $sha = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT * FROM user_kyc_sessions WHERE token_sha256 = ? LIMIT 1");
    $stmt->execute([$sha]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_id_evidence_extract_hashes_from_json(?string $evidenceJson): array
{
    $evidenceJson = is_string($evidenceJson) ? trim($evidenceJson) : '';
    if ($evidenceJson === '') return [];
    $d = json_decode($evidenceJson, true);
    if (!is_array($d)) return [];
    if (isset($d['hashes']) && is_array($d['hashes'])) {
        return $d['hashes'];
    }
    return $d;
}

function mh_id_evidence_upsert_hash_json(?string $evidenceJson, string $name, string $sha): string
{
    $name = trim($name);
    $sha = trim($sha);
    if ($name === '' || $sha === '') return (string)($evidenceJson ?? '');
    $evidenceJson = is_string($evidenceJson) ? trim($evidenceJson) : '';
    $d = $evidenceJson !== '' ? json_decode($evidenceJson, true) : null;
    if (!is_array($d)) {
        $d = [];
    }
    if (isset($d['hashes']) && is_array($d['hashes'])) {
        $d['hashes'][$name] = $sha;
    } else {
        $d[$name] = $sha;
    }
    $out = json_encode($d, JSON_UNESCAPED_SLASHES);
    return is_string($out) ? $out : '';
}

function mh_id_session_bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($h) || $h === '') return '';
    if (stripos($h, 'Bearer ') !== 0) return '';
    return trim(substr($h, 7));
}

function mh_id_normalize_kind(string $kind): string
{
    $k = strtolower(trim($kind));
    if ($k === 'passport') return 'passport';
    if ($k === 'national_id' || $k === 'national-id') return 'national_id';
    if ($k === 'mosip') return 'mosip';
    return '';
}

function mh_id_env(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $p = mh_id_secure_path('kyc/env_overrides.json', false);
        if ($p !== '' && is_file($p)) {
            $raw = @file_get_contents($p);
            if (is_string($raw) && $raw !== '') {
                $d = json_decode($raw, true);
                if (is_array($d)) $cache = $d;
            }
        }
    }
    if (is_array($cache) && array_key_exists($key, $cache)) {
        $v = $cache[$key];
        if ($v === null) return $default;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_int($v) || is_float($v)) return trim((string)$v);
        if (is_string($v)) return trim($v);
    }
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        return $default;
    }
    return trim($v);
}

function mh_id_env_set(string $key, ?string $value): bool
{
    $key = trim($key);
    if ($key === '') return false;
    $p = mh_id_secure_path('kyc/env_overrides.json', true);
    if ($p === '') return false;
    $dir = dirname($p);
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $cur = [];
    if (is_file($p)) {
        $raw = @file_get_contents($p);
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($d)) $cur = $d;
    }
    if ($value === null || trim($value) === '') {
        unset($cur[$key]);
    } else {
        $cur[$key] = trim($value);
    }
    $json = json_encode($cur, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;
    return file_put_contents($p, $json, LOCK_EX) !== false;
}

function mh_id_hmac_sha256_hex(string $data, string $secret): string
{
    return hash_hmac('sha256', $data, $secret);
}

function mh_id_safe_nonce(): string
{
    return bin2hex(random_bytes(16));
}

function mh_id_http_post_multipart_json(string $url, array $fields, array $files, array $headers = [], int $timeoutSeconds = 20): array
{
    $post = $fields;
    foreach ($files as $name => $file) {
        if (!is_array($file)) continue;
        $path = isset($file['path']) ? (string)$file['path'] : '';
        if ($path === '' || !is_file($path)) continue;
        $filename = isset($file['filename']) ? (string)$file['filename'] : basename($path);
        $mime = isset($file['mime']) ? (string)$file['mime'] : 'application/octet-stream';
        $post[$name] = curl_file_create($path, $mime, $filename);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;

    return [
        'ok' => is_string($resp) && $resp !== '' && $status >= 200 && $status < 300,
        'status' => $status,
        'error' => is_string($err) ? $err : '',
        'body' => is_string($resp) ? $resp : '',
    ];
}

function mh_id_kyc_verifier_call(array $sess, array $evidenceHashes, string $videoPath, ?string $selfiePath = null): array
{
    $url = mh_id_env('MH_KYC_VERIFIER_URL', '');
    $secret = mh_id_env('MH_KYC_VERIFIER_SECRET', '');
    if ($url === '') {
        $base = function_exists('getBaseUrl') ? rtrim((string)getBaseUrl(), '/') : '';
        if ($base === '' || str_contains($base, 'meta.superhumans.one')) {
            $base = 'https://metahumans.one';
        }
        $url = $base . '/auth/id/verifier.php';
    }
    if ($secret === '') {
        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('paths');
            }
            $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
            $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
            $appKey = is_string($raw) ? trim((string)$raw) : '';
            if ($appKey !== '') {
                $secret = hash('sha256', 'mh_kyc_verifier:' . $appKey);
            }
        } catch (Throwable $e) {
            $secret = '';
        }
    }
    if ($url === '' || $secret === '') {
        return ['ok' => false, 'error' => 'verifier_not_configured'];
    }

    $username = isset($sess['username']) ? (string)$sess['username'] : '';
    $sessionId = isset($sess['session_id']) ? (string)$sess['session_id'] : '';
    if ($username === '' || $sessionId === '') {
        return ['ok' => false, 'error' => 'invalid_session'];
    }

    $ts = (string)time();
    $nonce = mh_id_safe_nonce();
    $videoSha = isset($evidenceHashes['selfie_video.mp4']) ? (string)$evidenceHashes['selfie_video.mp4'] : '';
    $selfieSha = isset($evidenceHashes['selfie.jpg']) ? (string)$evidenceHashes['selfie.jpg'] : '';
    if ($videoSha === '' || !is_file($videoPath)) {
        return ['ok' => false, 'error' => 'missing_video'];
    }

    $sigBase = implode("\n", [
        $ts,
        $nonce,
        $username,
        $sessionId,
        $videoSha,
        $selfieSha,
    ]);
    $sig = mh_id_hmac_sha256_hex($sigBase, $secret);

    $payload = [
        'username' => $username,
        'session_id' => $sessionId,
        'kind' => isset($sess['method']) ? (string)$sess['method'] : '',
        'video_sha256' => $videoSha,
        'selfie_sha256' => $selfieSha,
    ];

    $fields = [
        'meta' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ];
    $files = [
        'video' => ['path' => $videoPath, 'filename' => 'selfie_video.mp4', 'mime' => 'video/mp4'],
    ];
    if (is_string($selfiePath) && $selfiePath !== '' && is_file($selfiePath)) {
        $files['selfie'] = ['path' => $selfiePath, 'filename' => 'selfie.jpg', 'mime' => 'image/jpeg'];
    }

    $headers = [
        'X-MH-Timestamp: ' . $ts,
        'X-MH-Nonce: ' . $nonce,
        'X-MH-Signature: ' . $sig,
        'Accept: application/json',
    ];

    $endpoint = $url;
    if (!str_contains($endpoint, '/v1/kyc/verify') && !str_ends_with(strtolower($endpoint), '.php')) {
        $endpoint = rtrim($endpoint, '/') . '/v1/kyc/verify';
    }
    $res = mh_id_http_post_multipart_json($endpoint, $fields, $files, $headers, 25);
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => 'verifier_http_failed', 'status' => $res['status'] ?? 0, 'http_error' => $res['error'] ?? '', 'body' => substr((string)($res['body'] ?? ''), 0, 500)];
    }

    $decoded = json_decode((string)$res['body'], true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'verifier_invalid_json'];
    }

    $respSig = isset($decoded['signature']) && is_string($decoded['signature']) ? (string)$decoded['signature'] : '';
    $verified = isset($decoded['verified']) ? (bool)$decoded['verified'] : false;
    $score = isset($decoded['score']) ? (float)$decoded['score'] : 0.0;
    $reason = isset($decoded['reason']) ? (string)$decoded['reason'] : '';
    $expiresAt = isset($decoded['expires_at']) ? (int)$decoded['expires_at'] : 0;
    $respBase = implode("\n", [
        $ts,
        $nonce,
        $username,
        $sessionId,
        $videoSha,
        $selfieSha,
        $verified ? '1' : '0',
        (string)$score,
        $reason,
        (string)$expiresAt,
    ]);
    $expected = mh_id_hmac_sha256_hex($respBase, $secret);
    if ($respSig === '' || !hash_equals($expected, $respSig)) {
        return ['ok' => false, 'error' => 'verifier_bad_signature'];
    }

    $decoded['verified'] = $verified;
    $decoded['score'] = $score;
    $decoded['reason'] = $reason;
    $decoded['expires_at'] = $expiresAt;
    return ['ok' => true, 'result' => $decoded];
}

function mh_id_load_json_file(string $path, int $maxBytes = 8000000): ?array
{
    $path = trim($path);
    if ($path === '' || !is_file($path)) return null;
    $size = @filesize($path);
    if (is_int($size) && $size > $maxBytes) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mh_id_get_bool(array $data, string $path): ?bool
{
    $path = trim($path);
    if ($path === '') return null;
    $cur = $data;
    foreach (explode('.', $path) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    if (is_bool($cur)) return $cur;
    return null;
}

function mh_id_get_string(array $data, string $path): ?string
{
    $path = trim($path);
    if ($path === '') return null;
    $cur = $data;
    foreach (explode('.', $path) as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
        $cur = $cur[$k];
    }
    if (is_string($cur)) return $cur;
    return null;
}

function mh_id_extract_country_code(array $dump, array $checks): string
{
    $candidates = [
        'passport.issuing_country',
        'passport.issuingCountry',
        'passport.issuer',
        'passport.country',
        'issuing_country',
        'issuingCountry',
        'issuing_state',
        'issuingState',
        'issuer',
        'country',
        'mrz.issuer',
        'mrz.issuing_state',
        'mrz.issuingState',
        'mrz.country',
        'mrz.issuingCountry',
    ];
    foreach ($candidates as $p) {
        $v = mh_id_get_string($dump, $p);
        if (!is_string($v) || trim($v) === '') {
            $v = mh_id_get_string($checks, $p);
        }
        if (is_string($v) && trim($v) !== '') {
            $raw = strtoupper(trim($v));
            $raw = preg_replace('/[^A-Z]/', '', $raw);
            if (is_string($raw) && $raw !== '') {
                return $raw;
            }
        }
    }
    return '';
}

function mh_id_parse_country_allowlist(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    $parts = preg_split('/[\\s,;|]+/', $raw);
    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
    $out = [];
    foreach ($parts as $p) {
        $v = strtoupper((string)$p);
        $v = preg_replace('/[^A-Z]/', '', $v);
        if (!is_string($v) || $v === '') continue;
        $out[$v] = true;
    }
    return array_keys($out);
}

function mh_id_validate_nfc_evidence(string $kind, string $evidenceRel, array $evidenceHashes): array
{
    $kind = mh_id_normalize_kind($kind);
    if ($kind !== 'passport' && $kind !== 'national_id') {
        return ['ok' => true, 'summary' => []];
    }
    $dumpPath = mh_id_secure_path($evidenceRel . '/nfc_dump.json', false);
    $checksPath = mh_id_secure_path($evidenceRel . '/checks.json', false);
    if ($dumpPath === '' || !is_file($dumpPath)) return ['ok' => false, 'error' => 'missing_nfc_dump'];
    if ($checksPath === '' || !is_file($checksPath)) return ['ok' => false, 'error' => 'missing_checks'];

    $dump = mh_id_load_json_file($dumpPath, 12000000);
    $checks = mh_id_load_json_file($checksPath, 3000000);
    if (!is_array($dump)) return ['ok' => false, 'error' => 'invalid_nfc_dump'];
    if (!is_array($checks)) return ['ok' => false, 'error' => 'invalid_checks'];

    $nfcOk = false;
    foreach (['nfc_read_ok', 'nfc.ok', 'nfc.read_ok', 'read_ok'] as $k) {
        $v = mh_id_get_bool($checks, $k);
        if ($v === true) { $nfcOk = true; break; }
    }
    if (!$nfcOk) return ['ok' => false, 'error' => 'nfc_not_ok'];

    if ($kind === 'passport') {
        $passiveOk = false;
        foreach (['passive_auth_ok', 'passport.passive_auth_ok', 'passport.passive_authentication_ok', 'sod_ok', 'passport.sod_ok', 'dg_hashes_ok', 'passport.dg_hashes_ok'] as $k) {
            $v = mh_id_get_bool($checks, $k);
            if ($v === true) { $passiveOk = true; break; }
        }
        if (!$passiveOk) return ['ok' => false, 'error' => 'passport_not_verified'];

        if (mh_id_env('MH_NFC_FULL_VERIFY', '') === '1') {
            $trustMode = strtolower(trim((string)mh_id_env('MH_PASSPORT_TRUST_MODE', 'none')));
            if ($trustMode !== 'none' && $trustMode !== 'csca') $trustMode = 'csca';
            $country = mh_id_extract_country_code($dump, $checks);
            $cscaCountries = mh_id_parse_country_allowlist((string)mh_id_env('MH_PASSPORT_CSCA_COUNTRIES', ''));
            $cscaAllowed = false;
            if ($trustMode === 'csca') {
                if ($country !== '' && !empty($cscaCountries)) {
                    $cscaAllowed = in_array($country, $cscaCountries, true);
                }
                if (!$cscaAllowed) {
                    $trustMode = 'none';
                }
            }

            $sodB64 = mh_id_get_string($dump, 'sod_base64') ?? mh_id_get_string($dump, 'passport.sod_base64') ?? mh_id_get_string($dump, 'sod') ?? mh_id_get_string($dump, 'passport.sod');
            $dg1B64 = mh_id_get_string($dump, 'dg1_base64') ?? mh_id_get_string($dump, 'passport.dg1_base64') ?? mh_id_get_string($dump, 'dg1') ?? mh_id_get_string($dump, 'passport.dg1');
            if (!is_string($sodB64) || trim($sodB64) === '') return ['ok' => false, 'error' => 'missing_sod'];
            if (!is_string($dg1B64) || trim($dg1B64) === '') return ['ok' => false, 'error' => 'missing_dg1'];

            $sodDer = base64_decode($sodB64, true);
            $dg1 = base64_decode($dg1B64, true);
            if (!is_string($sodDer) || $sodDer === '') return ['ok' => false, 'error' => 'invalid_sod'];
            if (!is_string($dg1) || $dg1 === '') return ['ok' => false, 'error' => 'invalid_dg1'];

            $hashes = null;
            $hashes = $hashes ?? (isset($dump['sod_dg_hashes']) && is_array($dump['sod_dg_hashes']) ? $dump['sod_dg_hashes'] : null);
            $hashes = $hashes ?? (isset($checks['sod_dg_hashes']) && is_array($checks['sod_dg_hashes']) ? $checks['sod_dg_hashes'] : null);
            $hashes = $hashes ?? (isset($checks['passport']) && is_array($checks['passport']) && isset($checks['passport']['sod_dg_hashes']) && is_array($checks['passport']['sod_dg_hashes']) ? $checks['passport']['sod_dg_hashes'] : null);
            if (!is_array($hashes)) return ['ok' => false, 'error' => 'missing_sod_dg_hashes'];

            $algo = 'sha256';
            if (isset($checks['hash_alg']) && is_string($checks['hash_alg'])) $algo = strtolower(trim((string)$checks['hash_alg']));
            if ($algo !== 'sha256' && $algo !== 'sha1') $algo = 'sha256';

            $dg1Expect = null;
            if (isset($hashes['dg1']) && is_string($hashes['dg1'])) $dg1Expect = strtolower(trim((string)$hashes['dg1']));
            if (isset($hashes['DG1']) && is_string($hashes['DG1'])) $dg1Expect = strtolower(trim((string)$hashes['DG1']));
            if (!is_string($dg1Expect) || $dg1Expect === '') return ['ok' => false, 'error' => 'missing_dg1_hash'];

            $dg1Hash = hash($algo, $dg1);
            if (!hash_equals($dg1Expect, $dg1Hash)) return ['ok' => false, 'error' => 'dg1_hash_mismatch'];

            $dg2B64 = mh_id_get_string($dump, 'dg2_base64') ?? mh_id_get_string($dump, 'passport.dg2_base64') ?? mh_id_get_string($dump, 'dg2') ?? mh_id_get_string($dump, 'passport.dg2');
            $dg2Expect = null;
            if (isset($hashes['dg2']) && is_string($hashes['dg2'])) $dg2Expect = strtolower(trim((string)$hashes['dg2']));
            if (isset($hashes['DG2']) && is_string($hashes['DG2'])) $dg2Expect = strtolower(trim((string)$hashes['DG2']));
            if (is_string($dg2B64) && trim($dg2B64) !== '' && is_string($dg2Expect) && $dg2Expect !== '') {
                $dg2 = base64_decode($dg2B64, true);
                if (!is_string($dg2) || $dg2 === '') return ['ok' => false, 'error' => 'invalid_dg2'];
                $dg2Hash = hash($algo, $dg2);
                if (!hash_equals($dg2Expect, $dg2Hash)) return ['ok' => false, 'error' => 'dg2_hash_mismatch'];
            }

            if ($trustMode === 'none') {
                return ['ok' => true, 'summary' => [
                    'kind' => $kind,
                    'nfc_dump_sha256' => $evidenceHashes['nfc_dump.json'] ?? '',
                    'checks_sha256' => $evidenceHashes['checks.json'] ?? '',
                    'nfc_ok' => $nfcOk,
                    'passport_full_verify' => 'hash_integrity_only',
                    'hash_alg' => $algo,
                    'passport_country' => $country,
                    'passport_csca_enabled_for_country' => $cscaAllowed,
                    'passport_csca_countries' => $cscaCountries,
                ]];
            }

            $csca = mh_id_env('MH_PASSPORT_CSCA_BUNDLE', '');
            if ($csca === '' || !is_file($csca)) return ['ok' => false, 'error' => 'csca_bundle_missing'];

            $openssl = trim((string)@shell_exec('command -v openssl'));
            if ($openssl === '') return ['ok' => false, 'error' => 'openssl_missing'];

            $tmpSod = tempnam(sys_get_temp_dir(), 'mh_sod_');
            $tmpOut = tempnam(sys_get_temp_dir(), 'mh_lds_');
            if (!is_string($tmpSod) || $tmpSod === '' || !is_string($tmpOut) || $tmpOut === '') return ['ok' => false, 'error' => 'temp_failed'];
            file_put_contents($tmpSod, $sodDer, LOCK_EX);

            $cmd = escapeshellcmd($openssl) . ' cms -verify -inform DER -in ' . escapeshellarg($tmpSod) . ' -CAfile ' . escapeshellarg($csca) . ' -out ' . escapeshellarg($tmpOut) . ' 2>&1';
            $outLines = [];
            $code = 0;
            @exec($cmd, $outLines, $code);
            @unlink($tmpSod);
            @unlink($tmpOut);
            if ($code !== 0) return ['ok' => false, 'error' => 'sod_verify_failed'];
        }
    }

    $summary = [
        'kind' => $kind,
        'nfc_dump_sha256' => $evidenceHashes['nfc_dump.json'] ?? '',
        'checks_sha256' => $evidenceHashes['checks.json'] ?? '',
        'nfc_ok' => $nfcOk,
    ];
    return ['ok' => true, 'summary' => $summary];
}

function mh_id_mosip_verify_call(array $sess, array $evidenceHashes): array
{
    $enabled = mh_id_env('MH_KYC_MOSIP_ENABLED', '') === '1';
    $url = mh_id_env('MH_KYC_MOSIP_VERIFY_URL', '');
    if (!$enabled || $url === '') {
        return ['ok' => false, 'error' => 'mosip_not_configured'];
    }

    $secret = mh_id_env('MH_KYC_MOSIP_SECRET', '');
    $ts = (string)time();
    $nonce = mh_id_safe_nonce();
    $username = isset($sess['username']) ? (string)$sess['username'] : '';
    $sessionId = isset($sess['session_id']) ? (string)$sess['session_id'] : '';
    if ($username === '' || $sessionId === '') return ['ok' => false, 'error' => 'invalid_session'];

    $payload = [
        'username' => $username,
        'session_id' => $sessionId,
        'kind' => isset($sess['method']) ? (string)$sess['method'] : '',
        'evidence_hashes' => $evidenceHashes,
    ];
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'X-MH-Timestamp: ' . $ts,
        'X-MH-Nonce: ' . $nonce,
    ];
    if ($secret !== '') {
        $sig = mh_id_hmac_sha256_hex($ts . "\n" . $nonce . "\n" . $username . "\n" . $sessionId, $secret);
        $headers[] = 'X-MH-Signature: ' . $sig;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;
    if (!is_string($resp) || $resp === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'mosip_http_failed', 'status' => $status, 'http_error' => is_string($err) ? $err : ''];
    }
    $decoded = json_decode($resp, true);
    if (!is_array($decoded)) return ['ok' => false, 'error' => 'mosip_invalid_json'];
    if ($secret !== '') {
        $respTs = isset($decoded['ts']) ? trim((string)$decoded['ts']) : '';
        $respNonce = isset($decoded['nonce']) ? trim((string)$decoded['nonce']) : '';
        $respSig = isset($decoded['signature']) ? trim((string)$decoded['signature']) : '';
        if ($respTs === '' || $respNonce === '' || $respSig === '') return ['ok' => false, 'error' => 'mosip_missing_signature'];
        $tmp = $decoded;
        unset($tmp['signature']);
        $payload = json_encode($tmp, JSON_UNESCAPED_SLASHES);
        $payload = is_string($payload) ? $payload : '';
        $expect = mh_id_hmac_sha256_hex($respTs . "\n" . $respNonce . "\n" . $payload, $secret);
        if (!hash_equals($expect, $respSig)) return ['ok' => false, 'error' => 'mosip_bad_signature'];
    }
    return ['ok' => true, 'result' => $decoded];
}
