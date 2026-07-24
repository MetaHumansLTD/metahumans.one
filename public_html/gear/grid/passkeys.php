<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) {
    define('CUE_DISABLE_AUTO_UI', true);
}
if (!defined('CUE_LAYOUT_MANUAL')) {
    define('CUE_LAYOUT_MANUAL', true);
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../oidc/lib.php';
require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';
require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/internal_accounts.php';
require_once __DIR__ . '/passkey_flow.php';
require_once __DIR__ . '/passkey_webauthn.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function mh_grid_passkeys_json(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_grid_passkeys_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_grid_passkeys_current_user(): string
{
    $user = $_SESSION['mh_auth_user'] ?? '';
    return is_string($user) ? trim($user) : '';
}

function mh_grid_passkeys_current_tenant_id(): string
{
    if (function_exists('mh_grid_current_tenant_id')) {
        return mh_grid_current_tenant_id();
    }

    $user = mh_grid_passkeys_current_user();
    if ($user === '') {
        return '';
    }
    return 'user:' . strtolower($user);
}

function mh_grid_passkeys_display_name(string $user): string
{
    $user = trim($user);
    if ($user === '') {
        return 'Meta Humans User';
    }
    $display = $_SESSION['mh_auth_display'] ?? null;
    if (is_string($display) && trim($display) !== '') {
        return trim($display);
    }
    return $user;
}

function mh_grid_passkeys_identity_claims(string $user): array
{
    $user = trim($user);
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($user !== '') {
        try {
            mh_auth_load_user_context(
                $user,
                $_SESSION['mh_auth_groups'] ?? null,
                isset($_SESSION['mh_auth_email']) && is_string($_SESSION['mh_auth_email']) ? (string)$_SESSION['mh_auth_email'] : null
            );
        } catch (Throwable) {
        }
    }

    $groups = $_SESSION['mh_auth_groups'] ?? null;
    if (is_string($groups)) {
        $groups = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $groups) ?: []), static function ($group): bool {
            return $group !== '';
        }));
    } elseif (!is_array($groups)) {
        $groups = [];
    }

    $role = $_SESSION['mh_auth_role'] ?? null;
    if (is_string($role) && trim($role) !== '' && !in_array(trim($role), $groups, true)) {
        $groups[] = trim($role);
    }

    $displayName = mh_grid_passkeys_display_name($user);
    $email = '';
    if ($tenantId !== '' && function_exists('mh_grid_internal_email_otp_address_for_tenant')) {
        try {
            $email = trim((string)mh_grid_internal_email_otp_address_for_tenant($tenantId));
        } catch (Throwable) {
            $email = '';
        }
    }
    if ($email === '' && isset($_SESSION['mh_auth_email']) && is_string($_SESSION['mh_auth_email'])) {
        $email = trim((string)$_SESSION['mh_auth_email']);
    }
    if ($email === '') {
        $email = $user;
    }

    return [
        'sub' => $user,
        'name' => $displayName,
        'preferred_username' => $user,
        'email' => $email,
        'groups' => $groups,
    ];
}

function mh_grid_passkeys_error_context(): array
{
    $currentUser = mh_grid_passkeys_current_user();
    $tenantId = mh_grid_passkeys_current_tenant_id();

    return [
        'currentUser' => $currentUser,
        'tenantId' => $tenantId,
        'loginRedirect' => '/auth/login.php?redirect=' . rawurlencode('/hub/grid/passkey.php'),
    ];
}

function mh_grid_passkeys_debug_mode(): bool
{
    $debug = isset($_GET['debug']) ? trim((string)$_GET['debug']) : '';
    if ($debug !== '1') {
        return false;
    }

    $role = $_SESSION['mh_auth_role'] ?? '';
    return is_string($role) && stripos($role, 'kripzmaster') !== false;
}

function mh_grid_passkeys_debug_record(string $key, array $payload): void
{
    if (!mh_grid_passkeys_debug_mode()) {
        return;
    }

    $_SESSION['mh_grid_passkeys_debug'][$key] = [
        'capturedAt' => gmdate('c'),
        'payload' => $payload,
    ];
}

function mh_grid_passkeys_debug_take(string $key): ?array
{
    if (!mh_grid_passkeys_debug_mode()) {
        return null;
    }

    $store = $_SESSION['mh_grid_passkeys_debug'] ?? [];
    if (!is_array($store) || !isset($store[$key]) || !is_array($store[$key])) {
        return null;
    }

    $payload = $store[$key];
    unset($store[$key]);
    $_SESSION['mh_grid_passkeys_debug'] = $store;
    return $payload;
}

function mh_grid_passkeys_platform_config(): array
{
    static $platformConfig = null;
    if (is_array($platformConfig)) {
        return $platformConfig;
    }

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'GET', '/config');
    $platformConfig = (($resp['ok'] ?? false) === true && is_array($resp['json'] ?? null))
        ? $resp['json']
        : [];

    return $platformConfig;
}

function mh_grid_passkeys_registration_flow(): array
{
    static $flow = null;
    if (is_array($flow)) {
        return $flow;
    }

    $flow = mh_grid_passkeys_registration_policy(
        mh_grid_passkeys_platform_config(),
        mh_grid_passkeys_debug_mode()
    );

    return $flow;
}

function mh_grid_passkeys_db(): PDO
{
    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('db_unavailable');
    }
    mh_grid_ensure_tables($db);
    return $db;
}

function mh_grid_passkeys_account_id_for_tenant(PDO $db, string $tenantId): string
{
    $stmt = $db->prepare("
        SELECT sr_internal_account_id
        FROM mh_settlement_accounts
        WHERE tenant_id = ? AND account_type = 'EMBEDDED_WALLET'
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $accountId = trim((string)($row['sr_internal_account_id'] ?? ''));
        if ($accountId !== '') {
            return $accountId;
        }
    }

    $discover = mh_grid_discover_embedded_wallet_accounts_for_tenant($tenantId);
    if (($discover['ok'] ?? false) !== true) {
        return '';
    }

    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }

    return trim((string)($row['sr_internal_account_id'] ?? ''));
}

function mh_grid_passkeys_validate_client_public_key(string $clientPublicKey): string
{
    $clientPublicKey = strtolower(trim($clientPublicKey));
    if ($clientPublicKey === '') {
        throw new RuntimeException('missing_client_public_key');
    }
    if (!preg_match('/^04[0-9a-f]{128}$/', $clientPublicKey)) {
        throw new RuntimeException('invalid_client_public_key');
    }

    return $clientPublicKey;
}

function mh_grid_passkeys_grid_oidc_client_id(): string
{
    $clientId = trim((string)(getenv('MH_GRID_OIDC_CLIENT_ID') ?: 'grid'));
    return $clientId !== '' ? $clientId : 'grid';
}

function mh_grid_passkeys_issue_grid_oidc_token(string $user, string $clientPublicKey = ''): string
{
    $user = trim($user);
    if ($user === '') {
        throw new RuntimeException('auth_required');
    }

    $now = time();
    $identity = mh_grid_passkeys_identity_claims($user);
    $claims = [
        'iss' => mh_oidc_issuer(),
        'aud' => mh_grid_passkeys_grid_oidc_client_id(),
        'sub' => (string)($identity['sub'] ?? $user),
        'iat' => $now,
        'exp' => $now + 300,
        'name' => (string)($identity['name'] ?? $user),
        'preferred_username' => (string)($identity['preferred_username'] ?? $user),
        'email' => (string)($identity['email'] ?? $user),
        'groups' => isset($identity['groups']) && is_array($identity['groups']) ? array_values($identity['groups']) : [],
    ];
    if ($clientPublicKey !== '') {
        $claims['nonce'] = hash('sha256', strtolower(trim($clientPublicKey)));
    }

    return mh_oidc_sign_jwt($claims);
}

function mh_grid_passkeys_local_upsert(PDO $db, string $tenantId, string $accountId, array $credential, ?string $platformCredentialId = null): void
{
    $srCredentialId = trim((string)($credential['id'] ?? ''));
    if ($srCredentialId === '') {
        return;
    }

    $type = trim((string)($credential['type'] ?? 'unknown'));
    if ($type === '') {
        $type = 'unknown';
    }
    $nickname = isset($credential['nickname']) ? trim((string)$credential['nickname']) : null;
    $status = isset($credential['status']) ? trim((string)$credential['status']) : 'active';
    if ($status === '') {
        $status = 'active';
    }
    $platformCredentialId = is_string($platformCredentialId) ? trim($platformCredentialId) : null;
    if (($platformCredentialId === null || $platformCredentialId === '') && strtoupper($type) === 'PASSKEY') {
        $platformCredentialId = trim((string)($credential['credentialId'] ?? ''));
    }

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_auth_credentials
            (tenant_id, sr_internal_account_id, sr_auth_credential_id, credential_type, nickname, platform_credential_id, status, raw_snapshot_json, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            sr_internal_account_id = VALUES(sr_internal_account_id),
            credential_type = VALUES(credential_type),
            nickname = VALUES(nickname),
            platform_credential_id = COALESCE(VALUES(platform_credential_id), platform_credential_id),
            status = VALUES(status),
            raw_snapshot_json = VALUES(raw_snapshot_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        $tenantId,
        $accountId,
        $srCredentialId,
        $type,
        ($nickname !== '' ? $nickname : null),
        ($platformCredentialId !== '' ? $platformCredentialId : null),
        $status,
        json_encode($credential, JSON_UNESCAPED_SLASHES),
    ]);
}

function mh_grid_passkeys_platform_credential_id(array $credential): string
{
    $platformCredentialId = trim((string)($credential['platformCredentialId'] ?? ''));
    if ($platformCredentialId !== '') {
        return $platformCredentialId;
    }

    if (strtoupper(trim((string)($credential['type'] ?? ''))) === 'PASSKEY') {
        return trim((string)($credential['credentialId'] ?? ''));
    }

    return '';
}

function mh_grid_passkeys_credential_timestamp(array $credential): int
{
    foreach (['updatedAt', 'createdAt'] as $field) {
        $raw = trim((string)($credential[$field] ?? ''));
        if ($raw === '') {
            continue;
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return (int)$ts;
        }
    }

    return 0;
}

function mh_grid_passkeys_select_passkey_credential(array $credentials, string $preferredCredentialId = ''): ?array
{
    $preferredCredentialId = trim($preferredCredentialId);
    $fallback = null;
    $fallbackHasPlatformCredentialId = false;
    $fallbackTs = 0;

    foreach ($credentials as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        if (strtoupper(trim((string)($credential['type'] ?? ''))) !== 'PASSKEY') {
            continue;
        }

        $status = strtolower(trim((string)($credential['status'] ?? 'active')));
        if ($status !== '' && $status !== 'active' && $status !== 'verified') {
            continue;
        }

        $credentialId = trim((string)($credential['id'] ?? ''));
        if ($credentialId === '') {
            continue;
        }

        if ($preferredCredentialId !== '' && hash_equals($preferredCredentialId, $credentialId)) {
            return $credential;
        }

        $hasPlatformCredentialId = mh_grid_passkeys_platform_credential_id($credential) !== '';
        $credentialTs = mh_grid_passkeys_credential_timestamp($credential);
        if (
            $fallback === null
            || ($hasPlatformCredentialId && !$fallbackHasPlatformCredentialId)
            || ($hasPlatformCredentialId === $fallbackHasPlatformCredentialId && $credentialTs > $fallbackTs)
        ) {
            $fallback = $credential;
            $fallbackHasPlatformCredentialId = $hasPlatformCredentialId;
            $fallbackTs = $credentialTs;
        }
    }

    return $fallback;
}

function mh_grid_passkeys_select_email_otp_credential(array $credentials, string $preferredCredentialId = ''): ?array
{
    $preferredCredentialId = trim($preferredCredentialId);
    $fallback = null;

    foreach ($credentials as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        if (strtoupper(trim((string)($credential['type'] ?? ''))) !== 'EMAIL_OTP') {
            continue;
        }

        $status = strtolower(trim((string)($credential['status'] ?? 'active')));
        if ($status !== '' && $status !== 'active' && $status !== 'verified') {
            continue;
        }

        $credentialId = trim((string)($credential['id'] ?? ''));
        if ($credentialId === '') {
            continue;
        }

        if ($preferredCredentialId !== '' && hash_equals($preferredCredentialId, $credentialId)) {
            return $credential;
        }
        if ($fallback === null) {
            $fallback = $credential;
        }
    }

    return $fallback;
}

function mh_grid_passkeys_email_otp_address(array $credential): string
{
    $email = strtolower(trim((string)($credential['email'] ?? '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $nickname = strtolower(trim((string)($credential['nickname'] ?? '')));
    if ($nickname !== '' && filter_var($nickname, FILTER_VALIDATE_EMAIL)) {
        return $nickname;
    }

    return '';
}

function mh_grid_passkeys_select_oauth_credential(array $credentials, string $preferredCredentialId = ''): ?array
{
    $preferredCredentialId = trim($preferredCredentialId);
    $fallback = null;
    $fallbackTs = 0;

    foreach ($credentials as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        if (strtoupper(trim((string)($credential['type'] ?? ''))) !== 'OAUTH') {
            continue;
        }

        $status = strtolower(trim((string)($credential['status'] ?? 'active')));
        if ($status !== '' && $status !== 'active' && $status !== 'verified') {
            continue;
        }

        $credentialId = trim((string)($credential['id'] ?? ''));
        if ($credentialId === '') {
            continue;
        }

        if ($preferredCredentialId !== '' && hash_equals($preferredCredentialId, $credentialId)) {
            return $credential;
        }

        $credentialTs = mh_grid_passkeys_credential_timestamp($credential);
        if ($fallback === null || $credentialTs > $fallbackTs) {
            $fallback = $credential;
            $fallbackTs = $credentialTs;
        }
    }

    return $fallback;
}

function mh_grid_passkeys_select_bootstrap_credential(array $credentials, string $preferredCredentialId = ''): ?array
{
    $preferredCredentialId = trim($preferredCredentialId);
    if ($preferredCredentialId !== '') {
        foreach ($credentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }
            $type = strtoupper(trim((string)($credential['type'] ?? '')));
            if ($type !== 'OAUTH' && $type !== 'EMAIL_OTP') {
                continue;
            }
            $status = strtolower(trim((string)($credential['status'] ?? 'active')));
            if ($status !== '' && $status !== 'active' && $status !== 'verified') {
                continue;
            }
            $credentialId = trim((string)($credential['id'] ?? ''));
            if ($credentialId !== '' && hash_equals($preferredCredentialId, $credentialId)) {
                return $credential;
            }
        }
    }

    $emailOtp = mh_grid_passkeys_select_email_otp_credential($credentials);
    if (is_array($emailOtp)) {
        return $emailOtp;
    }

    return mh_grid_passkeys_select_oauth_credential($credentials);
}

function mh_grid_passkeys_store_auth_session(PDO $db, string $tenantId, string $accountId, string $credentialId, array $session): void
{
    $sessionId = trim((string)($session['id'] ?? ($session['sessionId'] ?? '')));
    $status = trim((string)($session['status'] ?? 'active'));
    if ($status === '') {
        $status = 'active';
    }
    $expiresAt = trim((string)($session['expiresAt'] ?? ''));
    $expiresAtSql = null;
    if ($expiresAt !== '') {
        $ts = strtotime($expiresAt);
        if ($ts !== false) {
            $expiresAtSql = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_auth_sessions
            (tenant_id, sr_internal_account_id, sr_auth_credential_id, sr_auth_session_id, session_status, expires_at_utc, raw_snapshot_json, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            session_status = VALUES(session_status),
            expires_at_utc = VALUES(expires_at_utc),
            raw_snapshot_json = VALUES(raw_snapshot_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        $tenantId,
        $accountId,
        $credentialId,
        ($sessionId !== '' ? $sessionId : null),
        $status,
        $expiresAtSql,
        json_encode($session, JSON_UNESCAPED_SLASHES),
    ]);
}

function mh_grid_passkeys_local_credential_map(PDO $db, string $tenantId): array
{
    $stmt = $db->prepare("
        SELECT sr_auth_credential_id, platform_credential_id
        FROM mh_settlement_auth_credentials
        WHERE tenant_id = ?
    ");
    $stmt->execute([$tenantId]);

    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $srCredentialId = trim((string)($row['sr_auth_credential_id'] ?? ''));
        $platformCredentialId = trim((string)($row['platform_credential_id'] ?? ''));
        if ($srCredentialId === '' || $platformCredentialId === '') {
            continue;
        }
        $map[$srCredentialId] = $platformCredentialId;
    }

    return $map;
}

function mh_grid_passkeys_local_delete(PDO $db, string $tenantId, string $credentialId): void
{
    $tenantId = trim($tenantId);
    $credentialId = trim($credentialId);
    if ($tenantId === '' || $credentialId === '') {
        return;
    }

    $stmt = $db->prepare("
        DELETE FROM mh_settlement_auth_credentials
        WHERE tenant_id = ?
          AND sr_auth_credential_id = ?
    ");
    $stmt->execute([$tenantId, $credentialId]);

    $stmt = $db->prepare("
        DELETE FROM mh_settlement_auth_sessions
        WHERE tenant_id = ?
          AND sr_auth_credential_id = ?
    ");
    $stmt->execute([$tenantId, $credentialId]);
}

function mh_grid_passkeys_http_error_summary(array $resp, string $fallback): string
{
    $fallback = trim($fallback) !== '' ? trim($fallback) : 'grid_request_failed';
    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $code = trim((string)($json['code'] ?? ''));
    $reason = trim((string)($json['reason'] ?? ''));
    if ($code !== '' && $reason !== '') {
        return $code . ': ' . $reason;
    }
    if ($reason !== '') {
        return $reason;
    }
    $body = trim((string)($resp['body_raw'] ?? ''));
    if ($body !== '') {
        return $body;
    }
    $status = (int)($resp['status'] ?? 0);
    if ($status > 0) {
        return $fallback . ' (HTTP ' . $status . ')';
    }
    return $fallback;
}

function mh_grid_passkeys_active_session(PDO $db, string $tenantId, string $accountId): ?array
{
    $tenantId = trim($tenantId);
    $accountId = trim($accountId);
    if ($tenantId === '' || $accountId === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT sr_auth_credential_id, sr_auth_session_id, session_status, expires_at_utc, raw_snapshot_json
        FROM mh_settlement_auth_sessions
        WHERE tenant_id = ?
          AND sr_internal_account_id = ?
          AND (expires_at_utc IS NULL OR expires_at_utc > UTC_TIMESTAMP())
        ORDER BY expires_at_utc DESC, updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $raw = [];
    $snapshot = trim((string)($row['raw_snapshot_json'] ?? ''));
    if ($snapshot !== '') {
        $decoded = json_decode($snapshot, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    return [
        'credentialId' => trim((string)($row['sr_auth_credential_id'] ?? '')),
        'sessionId' => trim((string)($row['sr_auth_session_id'] ?? '')),
        'type' => trim((string)($raw['type'] ?? '')),
        'status' => trim((string)($row['session_status'] ?? 'active')),
        'expiresAt' => isset($raw['expiresAt']) ? trim((string)$raw['expiresAt']) : trim((string)($row['expires_at_utc'] ?? '')),
    ];
}

function mh_grid_passkeys_sync_remote_credentials(PDO $db, string $tenantId, string $accountId): array
{
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'GET', '/auth/credentials', [
        'query' => [
            'accountId' => $accountId,
        ],
    ]);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    $data = is_array($resp['json'] ?? null) ? ($resp['json']['data'] ?? []) : [];
    if (!is_array($data)) {
        $data = [];
    }

    foreach ($data as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        mh_grid_passkeys_local_upsert($db, $tenantId, $accountId, $credential);
    }

    $localCredentialMap = mh_grid_passkeys_local_credential_map($db, $tenantId);
    foreach ($data as $idx => $credential) {
        if (!is_array($credential)) {
            continue;
        }
        $srCredentialId = trim((string)($credential['id'] ?? ''));
        if ($srCredentialId !== '' && isset($localCredentialMap[$srCredentialId])) {
            $credential['platformCredentialId'] = $localCredentialMap[$srCredentialId];
            $data[$idx] = $credential;
            continue;
        }

        $platformCredentialId = mh_grid_passkeys_platform_credential_id($credential);
        if ($platformCredentialId === '') {
            continue;
        }
        $credential['platformCredentialId'] = $platformCredentialId;
        $data[$idx] = $credential;
    }

    return [
        'ok' => true,
        'status' => (int)($resp['status'] ?? 200),
        'credentials' => array_values(array_filter($data, 'is_array')),
    ];
}

function mh_grid_passkeys_pending_store(): array
{
    $pending = $_SESSION['mh_grid_passkey_pending'] ?? [];
    return is_array($pending) ? $pending : [];
}

function mh_grid_passkeys_pending_save(array $pending): void
{
    $_SESSION['mh_grid_passkey_pending'] = $pending;
}

function mh_grid_passkeys_pending_prune(): void
{
    $pending = mh_grid_passkeys_pending_store();
    $changed = false;
    foreach ($pending as $requestId => $payload) {
        $createdAt = is_array($payload) ? (int)($payload['created_at'] ?? 0) : 0;
        if ($createdAt <= 0 || (time() - $createdAt) > 900) {
            unset($pending[$requestId]);
            $changed = true;
        }
    }
    if ($changed) {
        mh_grid_passkeys_pending_save($pending);
    }
}

function mh_grid_passkeys_pending_get(string $requestId): ?array
{
    mh_grid_passkeys_pending_prune();
    $pending = mh_grid_passkeys_pending_store();
    $payload = $pending[$requestId] ?? null;
    return is_array($payload) ? $payload : null;
}

function mh_grid_passkeys_pending_set(string $requestId, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return;
    }

    $pending = mh_grid_passkeys_pending_store();
    $payload['request_id'] = $requestId;
    $payload['created_at'] = time();
    $pending[$requestId] = $payload;
    mh_grid_passkeys_pending_save($pending);
}

function mh_grid_passkeys_pending_clear(string $requestId): void
{
    $pending = mh_grid_passkeys_pending_store();
    if (array_key_exists($requestId, $pending)) {
        unset($pending[$requestId]);
        mh_grid_passkeys_pending_save($pending);
    }
}

function mh_grid_passkeys_revoke_pending_store(): array
{
    $pending = $_SESSION['mh_grid_passkey_revoke_pending'] ?? [];
    return is_array($pending) ? $pending : [];
}

function mh_grid_passkeys_revoke_pending_save(array $pending): void
{
    $_SESSION['mh_grid_passkey_revoke_pending'] = $pending;
}

function mh_grid_passkeys_revoke_pending_prune(): void
{
    $pending = mh_grid_passkeys_revoke_pending_store();
    $changed = false;
    foreach ($pending as $requestId => $payload) {
        $createdAt = (int)($payload['created_at'] ?? 0);
        if ($createdAt > 0 && (time() - $createdAt) > 900) {
            unset($pending[$requestId]);
            $changed = true;
        }
    }
    if ($changed) {
        mh_grid_passkeys_revoke_pending_save($pending);
    }
}

function mh_grid_passkeys_revoke_pending_get(string $requestId): ?array
{
    mh_grid_passkeys_revoke_pending_prune();
    $pending = mh_grid_passkeys_revoke_pending_store();
    $payload = $pending[$requestId] ?? null;
    return is_array($payload) ? $payload : null;
}

function mh_grid_passkeys_revoke_pending_set(string $requestId, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return;
    }

    $pending = mh_grid_passkeys_revoke_pending_store();
    $payload['request_id'] = $requestId;
    $payload['created_at'] = time();
    $pending[$requestId] = $payload;
    mh_grid_passkeys_revoke_pending_save($pending);
}

function mh_grid_passkeys_revoke_pending_clear(string $requestId): void
{
    $pending = mh_grid_passkeys_revoke_pending_store();
    if (array_key_exists($requestId, $pending)) {
        unset($pending[$requestId]);
        mh_grid_passkeys_revoke_pending_save($pending);
    }
}

function mh_grid_passkeys_auth_pending_store(): array
{
    $pending = $_SESSION['mh_grid_passkey_auth_pending'] ?? [];
    return is_array($pending) ? $pending : [];
}

function mh_grid_passkeys_bootstrap_pending_store(): array
{
    $pending = $_SESSION['mh_grid_passkey_bootstrap_pending'] ?? [];
    return is_array($pending) ? $pending : [];
}

function mh_grid_passkeys_bootstrap_pending_save(array $pending): void
{
    $_SESSION['mh_grid_passkey_bootstrap_pending'] = $pending;
}

function mh_grid_passkeys_bootstrap_pending_prune(): void
{
    $pending = mh_grid_passkeys_bootstrap_pending_store();
    $changed = false;
    foreach ($pending as $requestId => $payload) {
        $createdAt = is_array($payload) ? (int)($payload['created_at'] ?? 0) : 0;
        if ($createdAt <= 0 || (time() - $createdAt) > 900) {
            unset($pending[$requestId]);
            $changed = true;
        }
    }
    if ($changed) {
        mh_grid_passkeys_bootstrap_pending_save($pending);
    }
}

function mh_grid_passkeys_bootstrap_pending_get(string $requestId): ?array
{
    mh_grid_passkeys_bootstrap_pending_prune();
    $pending = mh_grid_passkeys_bootstrap_pending_store();
    $payload = $pending[$requestId] ?? null;
    return is_array($payload) ? $payload : null;
}

function mh_grid_passkeys_bootstrap_pending_set(string $requestId, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return;
    }

    $pending = mh_grid_passkeys_bootstrap_pending_store();
    $payload['request_id'] = $requestId;
    $payload['created_at'] = time();
    $pending[$requestId] = $payload;
    mh_grid_passkeys_bootstrap_pending_save($pending);
}

function mh_grid_passkeys_bootstrap_pending_clear(string $requestId): void
{
    $pending = mh_grid_passkeys_bootstrap_pending_store();
    if (array_key_exists($requestId, $pending)) {
        unset($pending[$requestId]);
        mh_grid_passkeys_bootstrap_pending_save($pending);
    }
}

function mh_grid_passkeys_oauth_pending_store(): array
{
    $pending = $_SESSION['mh_grid_passkey_oauth_pending'] ?? [];
    return is_array($pending) ? $pending : [];
}

function mh_grid_passkeys_oauth_pending_save(array $pending): void
{
    $_SESSION['mh_grid_passkey_oauth_pending'] = $pending;
}

function mh_grid_passkeys_oauth_pending_prune(): void
{
    $pending = mh_grid_passkeys_oauth_pending_store();
    $changed = false;
    foreach ($pending as $requestId => $payload) {
        $createdAt = is_array($payload) ? (int)($payload['created_at'] ?? 0) : 0;
        if ($createdAt <= 0 || (time() - $createdAt) > 900) {
            unset($pending[$requestId]);
            $changed = true;
        }
    }
    if ($changed) {
        mh_grid_passkeys_oauth_pending_save($pending);
    }
}

function mh_grid_passkeys_oauth_pending_get(string $requestId): ?array
{
    mh_grid_passkeys_oauth_pending_prune();
    $pending = mh_grid_passkeys_oauth_pending_store();
    $payload = $pending[$requestId] ?? null;
    return is_array($payload) ? $payload : null;
}

function mh_grid_passkeys_oauth_pending_set(string $requestId, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return;
    }

    $pending = mh_grid_passkeys_oauth_pending_store();
    $payload['request_id'] = $requestId;
    $payload['created_at'] = time();
    $pending[$requestId] = $payload;
    mh_grid_passkeys_oauth_pending_save($pending);
}

function mh_grid_passkeys_oauth_pending_clear(string $requestId): void
{
    $pending = mh_grid_passkeys_oauth_pending_store();
    if (array_key_exists($requestId, $pending)) {
        unset($pending[$requestId]);
        mh_grid_passkeys_oauth_pending_save($pending);
    }
}

function mh_grid_passkeys_auth_pending_save(array $pending): void
{
    $_SESSION['mh_grid_passkey_auth_pending'] = $pending;
}

function mh_grid_passkeys_auth_pending_prune(): void
{
    $pending = mh_grid_passkeys_auth_pending_store();
    $changed = false;
    foreach ($pending as $requestId => $payload) {
        $createdAt = is_array($payload) ? (int)($payload['created_at'] ?? 0) : 0;
        if ($createdAt <= 0 || (time() - $createdAt) > 900) {
            unset($pending[$requestId]);
            $changed = true;
        }
    }
    if ($changed) {
        mh_grid_passkeys_auth_pending_save($pending);
    }
}

function mh_grid_passkeys_auth_pending_get(string $requestId): ?array
{
    mh_grid_passkeys_auth_pending_prune();
    $pending = mh_grid_passkeys_auth_pending_store();
    $payload = $pending[$requestId] ?? null;
    return is_array($payload) ? $payload : null;
}

function mh_grid_passkeys_auth_pending_set(string $requestId, array $payload): void
{
    $requestId = trim($requestId);
    if ($requestId === '') {
        return;
    }

    $pending = mh_grid_passkeys_auth_pending_store();
    $payload['request_id'] = $requestId;
    $payload['created_at'] = time();
    $pending[$requestId] = $payload;
    mh_grid_passkeys_auth_pending_save($pending);
}

function mh_grid_passkeys_auth_pending_clear(string $requestId): void
{
    $pending = mh_grid_passkeys_auth_pending_store();
    if (array_key_exists($requestId, $pending)) {
        unset($pending[$requestId]);
        mh_grid_passkeys_auth_pending_save($pending);
    }
}

function mh_grid_passkeys_status_payload(): array
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        throw new RuntimeException('embedded_wallet_account_missing');
    }

    $remote = mh_grid_passkeys_sync_remote_credentials($db, $tenantId, $accountId);
    if (($remote['ok'] ?? false) !== true) {
        throw new RuntimeException((string)($remote['error'] ?? 'credential_sync_failed'));
    }

    $credentials = $remote['credentials'] ?? [];
    $hasPasskey = false;
    foreach ($credentials as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        if (strtoupper(trim((string)($credential['type'] ?? ''))) === 'PASSKEY') {
            $hasPasskey = true;
            break;
        }
    }

    $selectedEmailOtp = mh_grid_passkeys_select_email_otp_credential($credentials);
    $selectedOauth = mh_grid_passkeys_select_oauth_credential($credentials);
    $selectedBootstrap = mh_grid_passkeys_select_bootstrap_credential($credentials);
    mh_grid_passkeys_pending_prune();
    $pending = [];
    foreach (mh_grid_passkeys_pending_store() as $requestId => $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $pending[] = [
            'requestId' => trim((string)($payload['request_id'] ?? $requestId)),
            'accountId' => trim((string)($payload['account_id'] ?? '')),
            'nickname' => trim((string)($payload['nickname'] ?? '')),
            'createdAt' => (int)($payload['created_at'] ?? 0),
        ];
    }

    $selectedPasskey = mh_grid_passkeys_select_passkey_credential($credentials);
    $passkeyCount = 0;
    foreach ($credentials as $credential) {
        if (!is_array($credential)) {
            continue;
        }
        if (strtoupper(trim((string)($credential['type'] ?? ''))) === 'PASSKEY') {
            $passkeyCount++;
        }
    }
    $activeSession = mh_grid_passkeys_active_session($db, $tenantId, $accountId);
    $emailOtpAutomation = mh_grid_whm_internal_mailbox_automation_status($tenantId);
    $customer = mh_grid_customer_get_by_tenant($db, $tenantId);
    $accountStatus = '';
    if ($accountId !== '') {
        $stmt = $db->prepare("
            SELECT status
            FROM mh_settlement_accounts
            WHERE tenant_id = ?
              AND sr_internal_account_id = ?
            ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $accountId]);
        $accountStatus = trim((string)($stmt->fetchColumn() ?: ''));
    }

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'hasBootstrapCredential' => is_array($selectedBootstrap),
        'bootstrapCredentialId' => is_array($selectedBootstrap) ? trim((string)($selectedBootstrap['id'] ?? '')) : '',
        'bootstrapCredentialType' => is_array($selectedBootstrap) ? strtoupper(trim((string)($selectedBootstrap['type'] ?? ''))) : '',
        'oauthCredentialId' => is_array($selectedOauth) ? trim((string)($selectedOauth['id'] ?? '')) : '',
        'emailOtpCredentialId' => is_array($selectedEmailOtp) ? trim((string)($selectedEmailOtp['id'] ?? '')) : '',
        'hasPasskey' => $hasPasskey,
        'passkeyCount' => $passkeyCount,
        'passkeyCredentialId' => is_array($selectedPasskey) ? trim((string)($selectedPasskey['id'] ?? '')) : '',
        'emailOtpAutomationReady' => (bool)($emailOtpAutomation['ready'] ?? false),
        'emailOtpAutomationMessage' => (string)($emailOtpAutomation['message'] ?? ''),
        'activeSession' => $activeSession,
        'customerStatus' => is_array($customer) ? trim((string)($customer['status'] ?? '')) : '',
        'platformCustomerId' => is_array($customer) ? trim((string)($customer['platform_customer_id'] ?? '')) : '',
        'accountStatus' => $accountStatus,
        'credentials' => $credentials,
        'pendingRegistrations' => $pending,
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ];
}

function mh_grid_passkeys_handle_status(): void
{
    mh_grid_passkeys_json(200, mh_grid_passkeys_status_payload());
}

function mh_grid_passkeys_handle_start_registration(): void
{
    $user = mh_grid_passkeys_current_user();
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($user === '' || $tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    $broker = new MhGridPasskeyWebAuthn($tenantId);
    $challenge = $broker->startRegistration(
        'grid:' . $tenantId,
        $user,
        mh_grid_passkeys_display_name($user)
    );

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'accountId' => $accountId,
        'challengeId' => $challenge['challengeId'],
        'publicKey' => $challenge['options'],
    ]);
}

function mh_grid_passkeys_handle_init_registration(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $challengeId = isset($input['challengeId']) ? trim((string)$input['challengeId']) : '';
    $nickname = isset($input['nickname']) ? trim((string)$input['nickname']) : 'This device';
    $credential = $input['credential'] ?? null;

    if ($challengeId === '' || !is_array($credential)) {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    // #region debug-point A:passkey-init-registration-context
    mh_grid_debug_emit('A', 'passkeys.php:init_registration:context', 'Init registration context', [
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'challengeId' => $challengeId,
        'hasCredential' => is_array($credential),
    ]);
    // #endregion

    $verified = null;
    try {
        $broker = new MhGridPasskeyWebAuthn($tenantId);
        $verified = $broker->verifyRegistration($challengeId, $credential);
    } catch (Throwable $e) {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'registration_verification_failed', 'message' => $e->getMessage()]);
    }

    if (
        !is_array($verified)
        || !isset(
            $verified['challenge'],
            $verified['credentialId'],
            $verified['clientDataJson'],
            $verified['attestationObject'],
            $verified['transports']
        )
    ) {
        mh_grid_passkeys_json(500, ['ok' => false, 'error' => 'registration_verification_incomplete']);
    }

    $gridPayload = [
        'type' => 'PASSKEY',
        'accountId' => $accountId,
        'nickname' => ($nickname !== '' ? $nickname : 'This device'),
        'challenge' => $verified['challenge'],
        'attestation' => [
            'credentialId' => $verified['credentialId'],
            'clientDataJson' => $verified['clientDataJson'],
            'attestationObject' => $verified['attestationObject'],
            'transports' => $verified['transports'],
        ],
    ];

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials', [
        'json' => $gridPayload,
    ]);

    if (($resp['ok'] ?? false) !== true) {
        // #region debug-point C:passkey-init-registration-grid-reject
        mh_grid_debug_emit('C', 'passkeys.php:init_registration:grid_reject', 'Grid rejected passkey registration init', [
            'tenantId' => $tenantId,
            'accountId' => $accountId,
            'status' => $resp['status'] ?? null,
            'json' => $resp['json'] ?? null,
            'body' => $resp['body_raw'] ?? null,
        ]);
        // #endregion
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_registration_start_failed',
            'detail' => $resp,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $requestId = trim((string)($json['requestId'] ?? ''));
    $payloadToSign = trim((string)($json['payloadToSign'] ?? ''));
    $gridCredentialId = trim((string)($json['id'] ?? ''));
    $registrationFlow = mh_grid_passkeys_registration_flow();

    if ($requestId !== '' && $payloadToSign !== '') {
        mh_grid_passkeys_pending_set($requestId, [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'request_body' => $gridPayload,
            'platform_credential_id' => (string)$verified['credentialId'],
            'nickname' => $gridPayload['nickname'],
        ]);

        if (!empty($registrationFlow['autoCompletePendingSignature'])) {
            try {
                $result = mh_grid_passkeys_complete_pending_request($requestId, 'sandbox-valid-signature');
                $result['message'] = 'Grid passkey registration completed.';
                $result['autoCompleted'] = true;
                $result['registrationFlow'] = $registrationFlow;
                mh_grid_passkeys_json(200, $result);
            } catch (Throwable $e) {
                if (empty($registrationFlow['showManualRetryUi'])) {
                    mh_grid_passkeys_json(502, [
                        'ok' => false,
                        'error' => 'registration_auto_complete_failed',
                        'message' => $e->getMessage(),
                        'registrationFlow' => $registrationFlow,
                    ]);
                }
            }
        }

        mh_grid_passkeys_json(202, [
            'ok' => true,
            'stage' => 'pending_signature',
            'requestId' => $requestId,
            'payloadToSign' => $payloadToSign,
            'message' => 'Grid accepted the passkey attestation and is waiting for the signed retry.',
            'registrationFlow' => $registrationFlow,
        ]);
    }

    if ($gridCredentialId !== '') {
        mh_grid_passkeys_local_upsert($db, $tenantId, $accountId, $json, (string)$verified['credentialId']);
        mh_grid_passkeys_json(200, [
            'ok' => true,
            'stage' => 'registered',
            'credential' => $json,
            'status' => mh_grid_passkeys_status_payload(),
            'registrationFlow' => $registrationFlow,
        ]);
    }

    mh_grid_passkeys_json(400, [
        'ok' => false,
        'error' => 'unexpected_grid_registration_response',
        'detail' => $resp,
    ]);
}

function mh_grid_passkeys_complete_pending_request(string $requestId, string $gridWalletSignature): array
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $requestId = trim($requestId);
    $gridWalletSignature = trim($gridWalletSignature);
    if ($requestId === '') {
        throw new RuntimeException('missing_request_id');
    }
    if ($gridWalletSignature === '') {
        throw new RuntimeException('missing_grid_wallet_signature');
    }

    $pending = mh_grid_passkeys_pending_get($requestId);
    if (!is_array($pending)) {
        throw new RuntimeException('pending_registration_not_found');
    }

    $requestBody = is_array($pending['request_body'] ?? null) ? $pending['request_body'] : [];
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials', [
        'json' => $requestBody,
        'headers' => [
            'Request-Id' => $requestId,
            'Grid-Wallet-Signature' => $gridWalletSignature,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        throw new RuntimeException(mh_grid_passkeys_http_error_summary($resp, 'grid_registration_complete_failed'));
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $accountId = trim((string)($pending['account_id'] ?? ''));
    $platformCredentialId = trim((string)($pending['platform_credential_id'] ?? ''));

    $db = mh_grid_passkeys_db();
    mh_grid_passkeys_pending_clear($requestId);
    if ($accountId !== '' && !empty($json)) {
        mh_grid_passkeys_local_upsert($db, $tenantId, $accountId, $json, $platformCredentialId !== '' ? $platformCredentialId : null);
    }

    return [
        'ok' => true,
        'stage' => 'registered',
        'credential' => $json,
        'status' => mh_grid_passkeys_status_payload(),
    ];
}

function mh_grid_passkeys_complete_bootstrap_request(string $requestId, string $gridWalletSignature): array
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $requestId = trim($requestId);
    $gridWalletSignature = trim($gridWalletSignature);
    if ($requestId === '') {
        throw new RuntimeException('missing_request_id');
    }
    if ($gridWalletSignature === '') {
        throw new RuntimeException('missing_grid_wallet_signature');
    }

    $pending = mh_grid_passkeys_bootstrap_pending_get($requestId);
    if (!is_array($pending)) {
        throw new RuntimeException('pending_bootstrap_not_found');
    }

    $credentialId = trim((string)($pending['credential_id'] ?? ''));
    if ($credentialId === '') {
        throw new RuntimeException('pending_bootstrap_invalid');
    }

    $requestBody = is_array($pending['request_body'] ?? null) ? $pending['request_body'] : [];
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/verify', [
        'json' => $requestBody,
        'headers' => [
            'Request-Id' => $requestId,
            'Grid-Wallet-Signature' => $gridWalletSignature,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        mh_grid_passkeys_debug_record('complete_bootstrap_failure', [
            'requestId' => $requestId,
            'credentialId' => $credentialId,
            'response' => $resp,
        ]);
        throw new RuntimeException(mh_grid_passkeys_http_error_summary($resp, 'grid_bootstrap_complete_failed'));
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $accountId = trim((string)($pending['account_id'] ?? ''));

    $db = mh_grid_passkeys_db();
    mh_grid_passkeys_bootstrap_pending_clear($requestId);
    if ($accountId !== '' && !empty($json)) {
        mh_grid_passkeys_store_auth_session($db, $tenantId, $accountId, $credentialId, $json);
    }

    return [
        'ok' => true,
        'stage' => 'session_issued',
        'credentialId' => $credentialId,
        'authSession' => $json,
        'status' => mh_grid_passkeys_status_payload(),
    ];
}

function mh_grid_passkeys_complete_auth_session_request(string $requestId, string $gridWalletSignature): array
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $requestId = trim($requestId);
    $gridWalletSignature = trim($gridWalletSignature);
    if ($requestId === '') {
        throw new RuntimeException('missing_request_id');
    }
    if ($gridWalletSignature === '') {
        throw new RuntimeException('missing_grid_wallet_signature');
    }

    $pending = mh_grid_passkeys_auth_pending_get($requestId);
    if (!is_array($pending)) {
        throw new RuntimeException('pending_auth_session_not_found');
    }

    $credentialId = trim((string)($pending['credential_id'] ?? ''));
    if ($credentialId === '') {
        throw new RuntimeException('pending_auth_session_invalid');
    }

    $requestBody = is_array($pending['request_body'] ?? null) ? $pending['request_body'] : [];
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/verify', [
        'json' => $requestBody,
        'headers' => [
            'Request-Id' => $requestId,
            'Grid-Wallet-Signature' => $gridWalletSignature,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        throw new RuntimeException(mh_grid_passkeys_http_error_summary($resp, 'grid_auth_session_complete_failed'));
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $accountId = trim((string)($pending['account_id'] ?? ''));

    $db = mh_grid_passkeys_db();
    mh_grid_passkeys_auth_pending_clear($requestId);
    if ($accountId !== '' && !empty($json)) {
        mh_grid_passkeys_store_auth_session($db, $tenantId, $accountId, $credentialId, $json);
    }

    return [
        'ok' => true,
        'stage' => 'session_issued',
        'credentialId' => $credentialId,
        'authSession' => $json,
        'status' => mh_grid_passkeys_status_payload(),
    ];
}

function mh_grid_passkeys_handle_complete_registration(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $requestId = isset($input['requestId']) ? trim((string)$input['requestId']) : '';
    $gridWalletSignature = isset($input['gridWalletSignature']) ? trim((string)$input['gridWalletSignature']) : '';

    if ($requestId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_request_id']);
    }

    $pending = mh_grid_passkeys_pending_get($requestId);
    if (!is_array($pending)) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'pending_registration_not_found']);
    }

    if ($gridWalletSignature === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_grid_wallet_signature']);
    }

    try {
        $result = mh_grid_passkeys_complete_pending_request($requestId, $gridWalletSignature);
    } catch (RuntimeException $e) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => $e->getMessage(),
            'message' => $e->getMessage(),
        ]);
    }

    $result['registrationFlow'] = mh_grid_passkeys_registration_flow();
    mh_grid_passkeys_json(200, $result);
}

function mh_grid_passkeys_handle_start_bootstrap_session(array $input): void
{
    $user = mh_grid_passkeys_current_user();
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($user === '' || $tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $preferredCredentialId = isset($input['credentialId']) ? trim((string)$input['credentialId']) : '';

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    $remote = mh_grid_passkeys_sync_remote_credentials($db, $tenantId, $accountId);
    if (($remote['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'credential_sync_failed',
            'detail' => $remote,
        ]);
    }

    $credential = mh_grid_passkeys_select_bootstrap_credential($remote['credentials'] ?? [], $preferredCredentialId);
    if (!is_array($credential)) {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'bootstrap_credential_missing']);
    }

    $credentialId = trim((string)($credential['id'] ?? ''));
    $credentialType = strtoupper(trim((string)($credential['type'] ?? '')));
    $cfg = mh_grid_read_cfg();
    if ($credentialType === 'OAUTH') {
        $clientPublicKey = mh_grid_passkeys_validate_client_public_key((string)($input['clientPublicKey'] ?? ''));
        $oidcToken = mh_grid_passkeys_issue_grid_oidc_token($user, $clientPublicKey);
        $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/verify', [
            'json' => [
                'type' => 'OAUTH',
                'oidcToken' => $oidcToken,
                'clientPublicKey' => $clientPublicKey,
            ],
        ]);

        if (($resp['ok'] ?? false) !== true) {
            mh_grid_passkeys_json(400, [
                'ok' => false,
                'error' => 'grid_oidc_bootstrap_verify_failed',
                'detail' => $resp,
            ]);
        }

        $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        if (!empty($json)) {
            mh_grid_passkeys_store_auth_session($db, $tenantId, $accountId, $credentialId, $json);
        }

        mh_grid_passkeys_json(200, [
            'ok' => true,
            'stage' => 'session_issued',
            'bootstrapType' => 'OAUTH',
            'credentialId' => $credentialId,
            'authSession' => $json,
            'status' => mh_grid_passkeys_status_payload(),
            'registrationFlow' => mh_grid_passkeys_registration_flow(),
        ]);
    }

    $expectedEmail = strtolower(trim(mh_grid_internal_email_otp_address_for_tenant($tenantId)));
    $credentialEmail = mh_grid_passkeys_email_otp_address($credential);
    if ($expectedEmail !== '' && $credentialEmail !== '' && !hash_equals($expectedEmail, $credentialEmail)) {
        mh_grid_passkeys_json(409, [
            'ok' => false,
            'error' => 'grid_bootstrap_email_stale',
            'message' => 'The stored Grid EMAIL_OTP bootstrap credential still points to an older internal mailbox address. Reprovision the tenant bootstrap mailbox from /control before retrying bootstrap.',
            'credentialId' => $credentialId,
            'credentialEmail' => $credentialEmail,
            'expectedEmail' => $expectedEmail,
        ]);
    }

    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/challenge');

    if (($resp['ok'] ?? false) !== true) {
        $respJson = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $respCode = strtoupper(trim((string)($respJson['code'] ?? '')));
        $respReason = trim((string)($respJson['reason'] ?? ''));
        if ($respCode === 'INVALID_INPUT' && strcasecmp($respReason, 'Invalid email address.') === 0) {
            mh_grid_passkeys_json(409, [
                'ok' => false,
                'error' => 'grid_bootstrap_email_invalid',
                'message' => 'Grid rejected the stored EMAIL_OTP bootstrap credential because its email address is no longer valid. Reprovision the tenant bootstrap mailbox from /control or repair EMAIL_OTP from a live Grid session.',
                'credentialId' => $credentialId,
                'credentialEmail' => $credentialEmail,
                'expectedEmail' => $expectedEmail,
                'detail' => $resp,
            ]);
        }
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_bootstrap_challenge_failed',
            'detail' => $resp,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $otpEncryptionTargetBundle = trim((string)($json['otpEncryptionTargetBundle'] ?? ''));
    if ($otpEncryptionTargetBundle === '') {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'unexpected_grid_bootstrap_challenge_response',
            'detail' => $resp,
        ]);
    }

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'otp_challenge',
        'bootstrapType' => 'EMAIL_OTP',
        'credentialId' => $credentialId,
        'challengeIssuedAtMs' => (int) round(microtime(true) * 1000),
        'otpEncryptionTargetBundle' => $otpEncryptionTargetBundle,
        'trustedEnclaveQuorumPublicKeys' => $cfg['trusted_enclave_quorum_public_keys'] ?? [],
        'nickname' => trim((string)($json['nickname'] ?? '')),
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ]);
}

function mh_grid_passkeys_handle_verify_bootstrap_session(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $credentialId = isset($input['credentialId']) ? trim((string)$input['credentialId']) : '';
    $encryptedOtpBundle = isset($input['encryptedOtpBundle']) ? trim((string)$input['encryptedOtpBundle']) : '';
    $clientPublicKey = isset($input['clientPublicKey']) ? trim((string)$input['clientPublicKey']) : '';
    $challengeIssuedAtMs = (int)($input['challengeIssuedAtMs'] ?? 0);
    $relayMessageDate = isset($input['relayMessageDate']) ? trim((string)$input['relayMessageDate']) : '';
    if ($credentialId === '' || $encryptedOtpBundle === '' || $clientPublicKey === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    $customer = mh_grid_customer_get_by_tenant($db, $tenantId);
    $remote = mh_grid_passkeys_sync_remote_credentials($db, $tenantId, $accountId);
    $selectedCredential = null;
    if (($remote['ok'] ?? false) === true) {
        $selectedCredential = mh_grid_passkeys_select_bootstrap_credential($remote['credentials'] ?? [], $credentialId);
    }

    $issuedAfterTs = $challengeIssuedAtMs > 0 ? (int)floor($challengeIssuedAtMs / 1000) : 0;
    $relayProbe = null;
    $registrationFlow = mh_grid_passkeys_registration_flow();
    $skipRelayProbe = !empty($registrationFlow['allowSandboxOtpShortcut']);
    if ($relayMessageDate === '' && $issuedAfterTs > 0 && !$skipRelayProbe) {
        $relayProbe = mh_grid_whm_fetch_internal_mailbox_otp($tenantId, $issuedAfterTs);
        mh_grid_passkeys_debug_record('verify_bootstrap_missing_relay_probe', [
            'tenantId' => $tenantId,
            'challengeIssuedAtMs' => $challengeIssuedAtMs,
            'challengeIssuedAtIso' => gmdate('c', $issuedAfterTs),
            'relayProbe' => $relayProbe,
        ]);
        if (($relayProbe['ok'] ?? false) === true && ($relayProbe['found'] ?? false) !== true) {
            mh_grid_passkeys_json(409, [
                'ok' => false,
                'error' => 'grid_bootstrap_otp_missing',
                'message' => 'Grid sent the challenge, but no fresh Grid EMAIL_OTP reached the hidden mailbox for this tenant.',
                'debug' => mh_grid_passkeys_debug_mode() ? [
                    'challengeIssuedAtMs' => $challengeIssuedAtMs,
                    'relayMessageDate' => '',
                    'relayProbe' => $relayProbe,
                ] : null,
            ]);
        }
    }

    $requestBody = [
        'type' => 'EMAIL_OTP',
        'encryptedOtpBundle' => $encryptedOtpBundle,
        'clientPublicKey' => $clientPublicKey,
    ];

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/verify', [
        'json' => $requestBody,
    ]);

    if (($resp['ok'] ?? false) !== true) {
        $debugPayload = [
            'credentialId' => $credentialId,
            'challengeIssuedAtMs' => $challengeIssuedAtMs,
            'relayMessageDate' => $relayMessageDate,
            'encryptedOtpBundleLength' => strlen($encryptedOtpBundle),
            'clientPublicKeyLength' => strlen($clientPublicKey),
            'tenantId' => $tenantId,
            'accountId' => $accountId,
            'customerId' => is_array($customer) ? trim((string)($customer['sr_customer_id'] ?? '')) : '',
            'platformCustomerId' => is_array($customer) ? trim((string)($customer['platform_customer_id'] ?? '')) : '',
            'selectedCredential' => is_array($selectedCredential) ? [
                'id' => trim((string)($selectedCredential['id'] ?? '')),
                'accountId' => trim((string)($selectedCredential['accountId'] ?? '')),
                'type' => trim((string)($selectedCredential['type'] ?? '')),
                'status' => trim((string)($selectedCredential['status'] ?? '')),
                'email' => mh_grid_passkeys_email_otp_address($selectedCredential),
                'nickname' => trim((string)($selectedCredential['nickname'] ?? '')),
            ] : null,
            'relayProbe' => $relayProbe,
            'response' => $resp,
        ];
        mh_grid_passkeys_debug_record('verify_bootstrap_failure', $debugPayload);
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_bootstrap_verify_failed',
            'message' => mh_grid_passkeys_http_error_summary($resp, 'grid_bootstrap_verify_failed'),
            'detail' => $resp,
            'debug' => mh_grid_passkeys_debug_mode() ? $debugPayload : null,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $retryRequestId = trim((string)($json['requestId'] ?? ''));
    $payloadToSign = trim((string)($json['payloadToSign'] ?? ''));
    if ($retryRequestId !== '' && $payloadToSign !== '') {
        mh_grid_passkeys_bootstrap_pending_set($retryRequestId, [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'credential_id' => $credentialId,
            'request_body' => $requestBody,
        ]);

        mh_grid_passkeys_json(202, [
            'ok' => true,
            'stage' => 'pending_signature',
        'bootstrapType' => 'EMAIL_OTP',
            'credentialId' => $credentialId,
            'requestId' => $retryRequestId,
            'payloadToSign' => $payloadToSign,
            'message' => 'Grid accepted the encrypted OTP bundle and is waiting for the signed retry.',
            'registrationFlow' => $registrationFlow,
        ]);
    }

    if (!empty($json)) {
        mh_grid_passkeys_store_auth_session($db, $tenantId, $accountId, $credentialId, $json);
    }

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'session_issued',
        'bootstrapType' => 'EMAIL_OTP',
        'credentialId' => $credentialId,
        'authSession' => $json,
        'status' => mh_grid_passkeys_status_payload(),
        'registrationFlow' => $registrationFlow,
    ]);
}

function mh_grid_passkeys_handle_debug_take(array $input): void
{
    if (!mh_grid_passkeys_debug_mode()) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'not_found']);
    }

    $key = isset($input['key']) ? trim((string)$input['key']) : '';
    if ($key === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_key']);
    }

    $payload = mh_grid_passkeys_debug_take($key);
    mh_grid_passkeys_json(200, [
        'ok' => true,
        'key' => $key,
        'payload' => $payload,
    ]);
}

function mh_grid_passkeys_handle_fetch_bootstrap_otp(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $issuedAfterMs = (int)($input['issuedAfterMs'] ?? 0);
    $issuedAfterTs = $issuedAfterMs > 0 ? (int)floor($issuedAfterMs / 1000) : 0;
    $result = mh_grid_whm_fetch_internal_mailbox_otp($tenantId, $issuedAfterTs);
    mh_grid_passkeys_debug_record('fetch_bootstrap_otp', [
        'tenantId' => $tenantId,
        'issuedAfterMs' => $issuedAfterMs,
        'issuedAfterIso' => $issuedAfterTs > 0 ? gmdate('c', $issuedAfterTs) : '',
        'result' => $result,
    ]);
    if (($result['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(409, [
            'ok' => false,
            'error' => (string)($result['error'] ?? 'bootstrap_otp_fetch_failed'),
            'message' => (string)($result['message'] ?? 'Unable to retrieve the Grid EMAIL_OTP from the internal mailbox.'),
        ]);
    }

    if (($result['found'] ?? false) !== true) {
        mh_grid_passkeys_json(200, [
            'ok' => true,
            'found' => false,
            'pollAfterMs' => 2000,
        ]);
    }

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'found' => true,
        'otpCode' => (string)($result['otp'] ?? ''),
        'messageDate' => (string)($result['message_date'] ?? ''),
    ]);
}

function mh_grid_passkeys_handle_complete_bootstrap_session(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $requestId = isset($input['requestId']) ? trim((string)$input['requestId']) : '';
    $gridWalletSignature = isset($input['gridWalletSignature']) ? trim((string)$input['gridWalletSignature']) : '';

    if ($requestId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_request_id']);
    }

    $pending = mh_grid_passkeys_bootstrap_pending_get($requestId);
    if (!is_array($pending)) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'pending_bootstrap_not_found']);
    }

    if ($gridWalletSignature === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_grid_wallet_signature']);
    }

    try {
        $result = mh_grid_passkeys_complete_bootstrap_request($requestId, $gridWalletSignature);
    } catch (RuntimeException $e) {
        $payload = [
            'ok' => false,
            'error' => $e->getMessage(),
            'message' => $e->getMessage(),
        ];
        $debug = mh_grid_passkeys_debug_take('complete_bootstrap_failure');
        if (is_array($debug)) {
            $payload['debug'] = $debug;
        }
        mh_grid_passkeys_json(400, $payload);
    }

    $result['registrationFlow'] = mh_grid_passkeys_registration_flow();
    mh_grid_passkeys_json(200, $result);
}

function mh_grid_passkeys_complete_oauth_bootstrap_credential(string $requestId, string $gridWalletSignature): array
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $requestId = trim($requestId);
    $gridWalletSignature = trim($gridWalletSignature);
    if ($requestId === '') {
        throw new RuntimeException('missing_request_id');
    }
    if ($gridWalletSignature === '') {
        throw new RuntimeException('missing_grid_wallet_signature');
    }

    $pending = mh_grid_passkeys_oauth_pending_get($requestId);
    if (!is_array($pending)) {
        throw new RuntimeException('pending_oauth_bootstrap_not_found');
    }

    $requestBody = is_array($pending['request_body'] ?? null) ? $pending['request_body'] : [];
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials', [
        'json' => $requestBody,
        'headers' => [
            'Request-Id' => $requestId,
            'Grid-Wallet-Signature' => $gridWalletSignature,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        throw new RuntimeException(mh_grid_passkeys_http_error_summary($resp, 'grid_oauth_bootstrap_complete_failed'));
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $accountId = trim((string)($pending['account_id'] ?? ''));

    $db = mh_grid_passkeys_db();
    mh_grid_passkeys_oauth_pending_clear($requestId);
    if ($accountId !== '' && !empty($json)) {
        mh_grid_passkeys_local_upsert($db, $tenantId, $accountId, $json);
    }

    return [
        'ok' => true,
        'stage' => 'created',
        'credential' => $json,
        'status' => mh_grid_passkeys_status_payload(),
    ];
}

function mh_grid_passkeys_handle_ensure_oauth_bootstrap_credential(): void
{
    mh_grid_passkeys_json(409, [
        'ok' => false,
        'error' => 'grid_oauth_bootstrap_vendor_blocked',
        'message' => 'Grid embedded-wallet bootstrap does not currently accept the Meta Humans issuer. Third-party issuers like Google or Apple are not permitted for this product, so EMAIL_OTP remains the supported bootstrap path.',
    ]);
}

function mh_grid_passkeys_handle_complete_oauth_bootstrap_credential(array $input): void
{
    mh_grid_passkeys_json(409, [
        'ok' => false,
        'error' => 'grid_oauth_bootstrap_vendor_blocked',
        'message' => 'Grid embedded-wallet bootstrap does not currently accept the Meta Humans issuer. Third-party issuers like Google or Apple are not permitted for this product, so EMAIL_OTP remains the supported bootstrap path.',
    ]);
}

function mh_grid_passkeys_handle_start_auth_session(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $clientPublicKey = mh_grid_passkeys_validate_client_public_key((string)($input['clientPublicKey'] ?? ''));
    $preferredCredentialId = isset($input['credentialId']) ? trim((string)$input['credentialId']) : '';

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    $remote = mh_grid_passkeys_sync_remote_credentials($db, $tenantId, $accountId);
    if (($remote['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'credential_sync_failed',
            'detail' => $remote,
        ]);
    }

    $credential = mh_grid_passkeys_select_passkey_credential($remote['credentials'] ?? [], $preferredCredentialId);
    if (!is_array($credential)) {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'passkey_credential_missing']);
    }

    $credentialId = trim((string)($credential['id'] ?? ''));
    $platformCredentialId = mh_grid_passkeys_platform_credential_id($credential);
    // #region debug-point E:passkey-selected-credential
    mh_grid_debug_emit('E', 'passkeys.php:mh_grid_passkeys_handle_start_auth_session:selected', 'Selected PASSKEY auth credential', [
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'preferredCredentialId' => $preferredCredentialId !== '' ? $preferredCredentialId : null,
        'credentialId' => $credentialId,
        'platformCredentialId' => $platformCredentialId !== '' ? $platformCredentialId : null,
        'nickname' => trim((string)($credential['nickname'] ?? '')),
        'updatedAt' => trim((string)($credential['updatedAt'] ?? '')),
    ]);
    // #endregion
    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/challenge', [
        'json' => [
            'clientPublicKey' => $clientPublicKey,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_auth_challenge_failed',
            'detail' => $resp,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $requestId = trim((string)($json['requestId'] ?? ''));
    $challenge = trim((string)($json['challenge'] ?? ''));
    if ($requestId === '' || $challenge === '') {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'unexpected_grid_auth_challenge_response',
            'detail' => $resp,
        ]);
    }

    mh_grid_passkeys_auth_pending_set($requestId, [
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'credential_id' => $credentialId,
        'platform_credential_id' => $platformCredentialId,
        'client_public_key' => $clientPublicKey,
    ]);

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'auth_challenge',
        'credentialId' => $credentialId,
        'requestId' => $requestId,
        'challenge' => $challenge,
        'expiresAt' => $json['expiresAt'] ?? null,
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ]);
}

function mh_grid_passkeys_handle_verify_auth_session(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $requestId = isset($input['requestId']) ? trim((string)$input['requestId']) : '';
    $assertion = $input['assertion'] ?? null;
    if ($requestId === '' || !is_array($assertion)) {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $normalizedAssertion = [
        'credentialId' => trim((string)($assertion['credentialId'] ?? '')),
        'clientDataJson' => trim((string)($assertion['clientDataJson'] ?? '')),
        'authenticatorData' => trim((string)($assertion['authenticatorData'] ?? '')),
        'signature' => trim((string)($assertion['signature'] ?? '')),
    ];
    if (
        $normalizedAssertion['credentialId'] === ''
        || $normalizedAssertion['clientDataJson'] === ''
        || $normalizedAssertion['authenticatorData'] === ''
        || $normalizedAssertion['signature'] === ''
    ) {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'invalid_payload']);
    }
    $userHandle = trim((string)($assertion['userHandle'] ?? ''));
    if ($userHandle !== '') {
        $normalizedAssertion['userHandle'] = $userHandle;
    }

    $pending = mh_grid_passkeys_auth_pending_get($requestId);
    if (!is_array($pending)) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'pending_auth_challenge_not_found']);
    }

    $credentialId = trim((string)($pending['credential_id'] ?? ''));
    if ($credentialId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'pending_auth_challenge_invalid']);
    }
    $platformCredentialId = trim((string)($pending['platform_credential_id'] ?? ''));
    $assertionCredentialId = $normalizedAssertion['credentialId'];

    // #region debug-point E:passkey-verify-compare
    mh_grid_debug_emit('E', 'passkeys.php:mh_grid_passkeys_handle_verify_auth_session:compare', 'Comparing PASSKEY auth credential ids', [
        'requestId' => $requestId,
        'credentialId' => $credentialId,
        'expectedPlatformCredentialId' => $platformCredentialId !== '' ? $platformCredentialId : null,
        'assertionCredentialId' => $assertionCredentialId !== '' ? $assertionCredentialId : null,
        'assertionMatchesExpected' => ($platformCredentialId !== '' && $assertionCredentialId !== '' ? hash_equals($platformCredentialId, $assertionCredentialId) : null),
        'assertionUserHandlePresent' => array_key_exists('userHandle', $normalizedAssertion),
    ]);
    // #endregion

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/auth/credentials/' . rawurlencode($credentialId) . '/verify', [
        'json' => [
            'type' => 'PASSKEY',
            'assertion' => $normalizedAssertion,
        ],
        'headers' => [
            'Request-Id' => $requestId,
        ],
    ]);

    if (($resp['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_auth_verify_failed',
            'detail' => $resp,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $retryRequestId = trim((string)($json['requestId'] ?? ''));
    $payloadToSign = trim((string)($json['payloadToSign'] ?? ''));
    if ($retryRequestId !== '' && $payloadToSign !== '') {
        mh_grid_passkeys_auth_pending_clear($requestId);
        mh_grid_passkeys_auth_pending_set($retryRequestId, [
            'tenant_id' => $tenantId,
            'account_id' => trim((string)($pending['account_id'] ?? '')),
            'credential_id' => $credentialId,
            'client_public_key' => trim((string)($pending['client_public_key'] ?? '')),
            'request_body' => [
                'type' => 'PASSKEY',
                'assertion' => $normalizedAssertion,
            ],
        ]);

        mh_grid_passkeys_json(202, [
            'ok' => true,
            'stage' => 'pending_signature',
            'requestId' => $retryRequestId,
            'payloadToSign' => $payloadToSign,
            'message' => 'Grid requires a signed retry to mint the auth session.',
            'registrationFlow' => mh_grid_passkeys_registration_flow(),
        ]);
    }

    $db = mh_grid_passkeys_db();
    $accountId = trim((string)($pending['account_id'] ?? ''));
    mh_grid_passkeys_auth_pending_clear($requestId);
    if ($accountId !== '') {
        mh_grid_passkeys_store_auth_session($db, $tenantId, $accountId, $credentialId, $json);
    }

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'session_issued',
        'credentialId' => $credentialId,
        'authSession' => $json,
        'status' => mh_grid_passkeys_status_payload(),
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ]);
}

function mh_grid_passkeys_handle_complete_auth_session(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $requestId = isset($input['requestId']) ? trim((string)$input['requestId']) : '';
    $gridWalletSignature = isset($input['gridWalletSignature']) ? trim((string)$input['gridWalletSignature']) : '';

    if ($requestId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_request_id']);
    }

    $pending = mh_grid_passkeys_auth_pending_get($requestId);
    if (!is_array($pending)) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'pending_auth_session_not_found']);
    }

    if ($gridWalletSignature === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'missing_grid_wallet_signature']);
    }

    try {
        $result = mh_grid_passkeys_complete_auth_session_request($requestId, $gridWalletSignature);
    } catch (RuntimeException $e) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => $e->getMessage(),
            'message' => $e->getMessage(),
        ]);
    }

    $result['registrationFlow'] = mh_grid_passkeys_registration_flow();
    mh_grid_passkeys_json(200, $result);
}

function mh_grid_passkeys_handle_start_reset_passkey(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $preferredCredentialId = isset($input['credentialId']) ? trim((string)$input['credentialId']) : '';

    $db = mh_grid_passkeys_db();
    $accountId = mh_grid_passkeys_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    // #region debug-point A:passkey-reset-start-context
    mh_grid_debug_emit('A', 'passkeys.php:start_reset_passkey:context', 'Start reset passkey context', [
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'preferredCredentialId' => $preferredCredentialId !== '' ? $preferredCredentialId : null,
    ]);
    // #endregion

    $remote = mh_grid_passkeys_sync_remote_credentials($db, $tenantId, $accountId);
    if (($remote['ok'] ?? false) !== true) {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'credential_sync_failed',
            'detail' => $remote,
        ]);
    }

    $credential = mh_grid_passkeys_select_passkey_credential($remote['credentials'] ?? [], $preferredCredentialId);
    if (!is_array($credential)) {
        mh_grid_passkeys_json(409, ['ok' => false, 'error' => 'passkey_credential_missing']);
    }

    $credentialId = trim((string)($credential['id'] ?? ''));
    if ($credentialId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'passkey_credential_missing']);
    }

    // #region debug-point B:passkey-reset-start-selected
    mh_grid_debug_emit('B', 'passkeys.php:start_reset_passkey:selected', 'Selected passkey credential for reset', [
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'credentialId' => $credentialId,
    ]);
    // #endregion

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'DELETE', '/auth/credentials/' . rawurlencode($credentialId));
    if (($resp['ok'] ?? false) !== true) {
        // #region debug-point C:passkey-reset-start-grid-reject
        mh_grid_debug_emit('C', 'passkeys.php:start_reset_passkey:grid_reject', 'Grid rejected passkey reset start', [
            'tenantId' => $tenantId,
            'accountId' => $accountId,
            'credentialId' => $credentialId,
            'status' => $resp['status'] ?? null,
            'json' => $resp['json'] ?? null,
            'body' => $resp['body_raw'] ?? null,
        ]);
        // #endregion
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_passkey_reset_start_failed',
            'message' => mh_grid_passkeys_http_error_summary($resp, 'grid_passkey_reset_start_failed'),
            'detail' => $resp,
        ]);
    }

    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $requestId = trim((string)($json['requestId'] ?? ''));
    $payloadToSign = trim((string)($json['payloadToSign'] ?? ''));
    if ($requestId === '' || $payloadToSign === '') {
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'unexpected_grid_passkey_reset_response',
            'detail' => $resp,
        ]);
    }

    mh_grid_passkeys_revoke_pending_set($requestId, [
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'credential_id' => $credentialId,
    ]);

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'pending_signature',
        'requestId' => $requestId,
        'payloadToSign' => $payloadToSign,
        'credentialId' => $credentialId,
        'message' => 'Grid requires the current session key to authorize passkey revocation.',
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ]);
}

function mh_grid_passkeys_handle_complete_reset_passkey(array $input): void
{
    $tenantId = mh_grid_passkeys_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_passkeys_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $requestId = isset($input['requestId']) ? trim((string)$input['requestId']) : '';
    $gridWalletSignature = isset($input['gridWalletSignature']) ? trim((string)$input['gridWalletSignature']) : '';
    if ($requestId === '' || $gridWalletSignature === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'invalid_payload']);
    }

    $pending = mh_grid_passkeys_revoke_pending_get($requestId);
    if (!is_array($pending)) {
        mh_grid_passkeys_json(404, ['ok' => false, 'error' => 'pending_passkey_reset_not_found']);
    }

    $credentialId = trim((string)($pending['credential_id'] ?? ''));
    if ($credentialId === '') {
        mh_grid_passkeys_json(400, ['ok' => false, 'error' => 'pending_passkey_reset_invalid']);
    }

    // #region debug-point A:passkey-reset-complete-context
    mh_grid_debug_emit('A', 'passkeys.php:complete_reset_passkey:context', 'Complete reset passkey context', [
        'tenantId' => $tenantId,
        'requestId' => $requestId,
        'credentialId' => $credentialId,
        'hasSignature' => $gridWalletSignature !== '',
    ]);
    // #endregion

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'DELETE', '/auth/credentials/' . rawurlencode($credentialId), [
        'headers' => [
            'Request-Id' => $requestId,
            'Grid-Wallet-Signature' => $gridWalletSignature,
        ],
    ]);
    if (($resp['ok'] ?? false) !== true) {
        // #region debug-point C:passkey-reset-complete-grid-reject
        mh_grid_debug_emit('C', 'passkeys.php:complete_reset_passkey:grid_reject', 'Grid rejected passkey reset completion', [
            'tenantId' => $tenantId,
            'requestId' => $requestId,
            'credentialId' => $credentialId,
            'status' => $resp['status'] ?? null,
            'json' => $resp['json'] ?? null,
            'body' => $resp['body_raw'] ?? null,
        ]);
        // #endregion
        mh_grid_passkeys_json(400, [
            'ok' => false,
            'error' => 'grid_passkey_reset_complete_failed',
            'message' => mh_grid_passkeys_http_error_summary($resp, 'grid_passkey_reset_complete_failed'),
            'detail' => $resp,
        ]);
    }

    $db = mh_grid_passkeys_db();
    mh_grid_passkeys_revoke_pending_clear($requestId);
    mh_grid_passkeys_local_delete($db, $tenantId, $credentialId);

    mh_grid_passkeys_json(200, [
        'ok' => true,
        'stage' => 'revoked',
        'credentialId' => $credentialId,
        'status' => mh_grid_passkeys_status_payload(),
        'registrationFlow' => mh_grid_passkeys_registration_flow(),
    ]);
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'status';
$method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
$input = mh_grid_passkeys_input();

try {
    if ($action === 'status') {
        mh_grid_passkeys_handle_status();
    }
    if ($action === 'start_registration' && $method === 'POST') {
        mh_grid_passkeys_handle_start_registration();
    }
    if ($action === 'init_registration' && $method === 'POST') {
        mh_grid_passkeys_handle_init_registration($input);
    }
    if ($action === 'complete_registration' && $method === 'POST') {
        mh_grid_passkeys_handle_complete_registration($input);
    }
    if ($action === 'start_bootstrap_session' && $method === 'POST') {
        mh_grid_passkeys_handle_start_bootstrap_session($input);
    }
    if ($action === 'verify_bootstrap_session' && $method === 'POST') {
        mh_grid_passkeys_handle_verify_bootstrap_session($input);
    }
    if ($action === 'debug_take' && $method === 'POST') {
        mh_grid_passkeys_handle_debug_take($input);
    }
    if ($action === 'fetch_bootstrap_otp' && $method === 'POST') {
        mh_grid_passkeys_handle_fetch_bootstrap_otp($input);
    }
    if ($action === 'complete_bootstrap_session' && $method === 'POST') {
        mh_grid_passkeys_handle_complete_bootstrap_session($input);
    }
    if ($action === 'ensure_oauth_bootstrap_credential' && $method === 'POST') {
        mh_grid_passkeys_handle_ensure_oauth_bootstrap_credential();
    }
    if ($action === 'complete_oauth_bootstrap_credential' && $method === 'POST') {
        mh_grid_passkeys_handle_complete_oauth_bootstrap_credential($input);
    }
    if ($action === 'start_auth_session' && $method === 'POST') {
        mh_grid_passkeys_handle_start_auth_session($input);
    }
    if ($action === 'verify_auth_session' && $method === 'POST') {
        mh_grid_passkeys_handle_verify_auth_session($input);
    }
    if ($action === 'complete_auth_session' && $method === 'POST') {
        mh_grid_passkeys_handle_complete_auth_session($input);
    }
    if ($action === 'start_reset_passkey' && $method === 'POST') {
        mh_grid_passkeys_handle_start_reset_passkey($input);
    }
    if ($action === 'complete_reset_passkey' && $method === 'POST') {
        mh_grid_passkeys_handle_complete_reset_passkey($input);
    }

    mh_grid_passkeys_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
} catch (Throwable $e) {
    $status = 500;
    $error = 'internal_error';
    if ($e->getMessage() === 'auth_required') {
        $status = 401;
        $error = 'auth_required';
    } elseif ($e->getMessage() === 'embedded_wallet_account_missing') {
        $status = 409;
        $error = 'embedded_wallet_account_missing';
    } elseif ($e->getMessage() === 'missing_client_public_key' || $e->getMessage() === 'invalid_client_public_key') {
        $status = 400;
        $error = $e->getMessage();
    }

    mh_grid_passkeys_json($status, array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $e->getMessage(),
    ], ($error === 'auth_required' || $error === 'embedded_wallet_account_missing') ? mh_grid_passkeys_error_context() : []));
}
