<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
if (php_sapi_name() === 'cli' && !defined('CUE_CLI_MODE')) {
    define('CUE_CLI_MODE', true);
}

require_once dirname(__DIR__) . '/auth/auth_functions.php';

function mh_oidc_base_dir(): string {
    if (defined('ROOT_PATH')) {
        $rootPath = rtrim((string)ROOT_PATH, '/');
        $homePath = (basename($rootPath) === 'public_html') ? dirname($rootPath) : $rootPath;
        return rtrim($homePath, '/') . '/.data/oidc';
    }
    return dirname(dirname(__DIR__)) . '/.data/oidc';
}

function mh_oidc_public_proto(): string {
    $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($xfp) && trim($xfp) !== '') {
        $parts = explode(',', $xfp);
        $p = strtolower(trim((string)($parts[0] ?? '')));
        if ($p === 'http' || $p === 'https') {
            return $p;
        }
    }

    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && strtolower(trim($https)) === 'on') {
        return 'https';
    }

    return 'https';
}

function mh_oidc_public_host(): string {
    $host = '';

    $xfh = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
    if (is_string($xfh) && trim($xfh) !== '') {
        $parts = explode(',', $xfh);
        $host = trim((string)($parts[0] ?? ''));
    }

    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'metahumans.one');
        $host = is_string($host) ? $host : 'metahumans.one';
    }

    $host = strtolower(trim((string)$host));
    $host = preg_replace('/:\\d+$/', '', $host) ?: '';
    $host = rtrim($host, '.');

    if ($host === '' || $host === 'localhost' || preg_match('/^\\d{1,3}(?:\\.\\d{1,3}){3}$/', $host)) {
        $host = 'metahumans.one';
    }

    if ($host === 'www.metahumans.one') {
        return 'metahumans.one';
    }
    if (str_ends_with($host, '.metahumans.one')) {
        return 'metahumans.one';
    }

    if ($host === 'meta.superhumans.one' || str_ends_with($host, '.superhumans.one')) {
        return 'metahumans.one';
    }

    return $host;
}

function mh_oidc_issuer_override(): string {
    $forced = getenv('MH_OIDC_ISSUER');
    if (is_string($forced) && trim($forced) !== '') {
        return rtrim(trim($forced), '/');
    }
    return '';
}

function mh_oidc_issuer(): string {
    $override = mh_oidc_issuer_override();
    if ($override !== '') {
        return $override;
    }

    if (function_exists('getBaseUrl')) {
        $base = rtrim((string)getBaseUrl(), '/');
        if ($base !== '' && !str_contains($base, 'meta.superhumans.one')) {
            return $base . '/oidc';
        }
    }

    $proto = mh_oidc_public_proto();
    $host = mh_oidc_public_host();
    return $proto . '://' . $host . '/oidc';
}

function mh_oidc_ensure_dirs(): void {
    $base = mh_oidc_base_dir();
    if (!is_dir($base)) {
        @mkdir($base, 0700, true);
    }
    if (!is_dir($base . '/codes')) {
        @mkdir($base . '/codes', 0700, true);
    }
    if (!is_dir($base . '/keys')) {
        @mkdir($base . '/keys', 0700, true);
    }
}

function mh_oidc_code_dir(): string {
    mh_oidc_ensure_dirs();
    $primary = mh_oidc_base_dir() . '/codes';
    if (is_dir($primary) && is_writable($primary)) {
        return $primary;
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/');
    $fallback = $tmpBase . '/mh-oidc-codes';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0700, true);
    }
    return $fallback;
}

function mh_oidc_key_dir(): string {
    mh_oidc_ensure_dirs();
    $primary = mh_oidc_base_dir() . '/keys';
    $primaryPriv = $primary . '/private.pem';
    $primaryPub = $primary . '/public.pem';
    $primaryKid = $primary . '/kid.txt';
    if (
        is_dir($primary) &&
        is_readable($primaryPub) &&
        is_readable($primaryKid) &&
        is_readable($primaryPriv)
    ) {
        return $primary;
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/');
    $fallback = $tmpBase . '/mh-oidc-keys';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0700, true);
    }
    return $fallback;
}

function mh_oidc_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mh_oidc_base64url_decode(string $data): string {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode($data);
}

function mh_oidc_load_or_create_keys(): array {
    $base = mh_oidc_key_dir();
    $privPath = $base . '/private.pem';
    $pubPath = $base . '/public.pem';
    $kidPath = $base . '/kid.txt';

    if (!is_file($privPath) || !is_file($pubPath) || !is_file($kidPath)) {
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048
        ];
        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new RuntimeException('keygen_failed');
        }
        $privPem = '';
        openssl_pkey_export($res, $privPem);
        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || !isset($details['key'])) {
            throw new RuntimeException('keygen_failed');
        }
        $pubPem = (string)$details['key'];
        $kid = mh_oidc_base64url_encode(hash('sha256', $pubPem, true));

        file_put_contents($privPath, $privPem);
        file_put_contents($pubPath, $pubPem);
        file_put_contents($kidPath, $kid);
        @chmod($privPath, 0600);
        @chmod($pubPath, 0644);
        @chmod($kidPath, 0644);
    }

    return [
        'private_pem' => (string)file_get_contents($privPath),
        'public_pem' => (string)file_get_contents($pubPath),
        'kid' => trim((string)file_get_contents($kidPath))
    ];
}

function mh_oidc_load_clients(): array {
    mh_oidc_ensure_dirs();
    $clientsPath = mh_oidc_base_dir() . '/clients.json';
    if (!is_file($clientsPath)) {
        $cheSecretPath = mh_oidc_base_dir() . '/che_oauth_secret.txt';
        $cheSecret = is_file($cheSecretPath) ? trim((string)file_get_contents($cheSecretPath)) : '';
        if ($cheSecret === '') {
            $cheSecret = mh_oidc_base64url_encode(random_bytes(24));
        }
        $canonicalHost = mh_oidc_public_host();
        $clients = [
            'che' => [
                'client_secret' => $cheSecret,
                'redirect_uris' => [
                    'https://' . $canonicalHost . '/oauth/callback',
                    'https://' . $canonicalHost . '/oauth/callback/',
                    'https://metahumans.one/oauth/callback',
                    'https://metahumans.one/oauth/callback/',
                    'https://meta.superhumans.one/oauth/callback',
                    'https://meta.superhumans.one/oauth/callback/'
                ]
            ]
        ];
        file_put_contents($clientsPath, json_encode($clients, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($clientsPath, 0600);
    }
    $raw = (string)file_get_contents($clientsPath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $updated = false;
    $canonicalHost = mh_oidc_public_host();
    foreach ($data as $cid => $client) {
        if (!is_array($client)) continue;
        $uris = isset($client['redirect_uris']) && is_array($client['redirect_uris']) ? $client['redirect_uris'] : [];
        $normalized = [];
        foreach ($uris as $u) {
            if (!is_string($u)) continue;
            $u = trim($u);
            if ($u === '') continue;
            $normalized[] = $u;

            $parsed = parse_url($u);
            if (!is_array($parsed)) continue;
            $h = isset($parsed['host']) && is_string($parsed['host']) ? strtolower($parsed['host']) : '';
            if ($h === '' || $canonicalHost === '') continue;

            if ($h === 'meta.superhumans.one' || str_ends_with($h, '.superhumans.one') || $h === 'www.metahumans.one' || str_ends_with($h, '.metahumans.one')) {
                $rebuilt = $u;
                $rebuilt = preg_replace('#^https?://' . preg_quote($parsed['host'], '#') . '#i', 'https://' . $canonicalHost, $rebuilt) ?: $u;
                $normalized[] = $rebuilt;
            }
        }

        $unique = [];
        foreach ($normalized as $u) {
            if (!in_array($u, $unique, true)) $unique[] = $u;
        }

        if ($unique !== $uris) {
            $data[$cid]['redirect_uris'] = $unique;
            $updated = true;
        }
    }

    if ($updated) {
        @file_put_contents($clientsPath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($clientsPath, 0600);
    }

    return $data;
}

function mh_oidc_get_client(string $clientId): ?array {
    $clients = mh_oidc_load_clients();
    $c = $clients[$clientId] ?? null;
    return is_array($c) ? $c : null;
}

function mh_oidc_debug_emit(string $hypothesisId, string $location, string $msg, array $data = []): void {
    // #region debug-point A:oidc-debug-emit
    $url = 'http://127.0.0.1:7777/event';
    $sessionId = 'oidc-sso-loop';
    $envPath = dirname(dirname(__DIR__)) . '/.dbg/oidc-sso-loop.env';
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

function mh_oidc_fetch_auth_session_bridge(): ?array {
    $sessionName = session_name();
    $headers = [
        'Accept: application/json',
    ];

    if (is_string($sessionName) && $sessionName !== '' && isset($_COOKIE[$sessionName]) && is_string($_COOKIE[$sessionName]) && $_COOKIE[$sessionName] !== '') {
        $headers[] = 'Cookie: ' . $sessionName . '=' . $_COOKIE[$sessionName];
    }
    if (isset($_COOKIE['__Host-device']) && is_string($_COOKIE['__Host-device']) && $_COOKIE['__Host-device'] !== '') {
        $cookiePrefix = (count($headers) > 1) ? '; ' : 'Cookie: ';
        $lastHeaderIndex = count($headers) - 1;
        if ($lastHeaderIndex >= 0 && strpos($headers[$lastHeaderIndex], 'Cookie: ') === 0) {
            $headers[$lastHeaderIndex] .= '; __Host-device=' . $_COOKIE['__Host-device'];
        } else {
            $headers[] = 'Cookie: __Host-device=' . $_COOKIE['__Host-device'];
        }
    }
    if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) && trim($_SERVER['HTTP_USER_AGENT']) !== '') {
        $headers[] = 'User-Agent: ' . trim($_SERVER['HTTP_USER_AGENT']);
    }

    $url = mh_oidc_public_proto() . '://' . mh_oidc_public_host() . '/auth/session.php';
    $raw = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]));
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : null;
}

function mh_oidc_require_login(string $returnTo): void {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // #region debug-point A:require-login-session
    $sessionName = session_name();
    $sessionId = session_id();
    $cookieValue = (is_string($sessionName) && $sessionName !== '' && isset($_COOKIE[$sessionName]) && is_string($_COOKIE[$sessionName])) ? $_COOKIE[$sessionName] : null;
    mh_oidc_debug_emit('A', 'oidc/lib.php:mh_oidc_require_login', 'require_login session snapshot', [
        'return_to' => $returnTo,
        'mh_auth_user' => $_SESSION['mh_auth_user'] ?? null,
        'session_name' => $sessionName,
        'session_id' => $sessionId !== '' ? $sessionId : null,
        'cookie_present' => $cookieValue !== null && $cookieValue !== '',
        'cookie_matches_session' => ($cookieValue !== null && $sessionId !== '' ? hash_equals($sessionId, $cookieValue) : false),
    ]);
    // #endregion
    if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
        $bridgePayload = mh_oidc_fetch_auth_session_bridge();
        mh_oidc_debug_emit('A', 'oidc/lib.php:mh_oidc_require_login', 'auth session bridge probe', [
            'bridge_authenticated' => is_array($bridgePayload) ? ($bridgePayload['authenticated'] ?? null) : null,
            'bridge_user' => is_array($bridgePayload) ? ($bridgePayload['user'] ?? null) : null,
        ]);
        if (
            is_array($bridgePayload) &&
            !empty($bridgePayload['authenticated']) &&
            isset($bridgePayload['user']) &&
            is_string($bridgePayload['user']) &&
            trim($bridgePayload['user']) !== ''
        ) {
            $_SESSION['mh_auth_user'] = trim((string)$bridgePayload['user']);
            if (isset($bridgePayload['mh_auth_role']) && is_string($bridgePayload['mh_auth_role']) && trim($bridgePayload['mh_auth_role']) !== '') {
                $_SESSION['mh_auth_role'] = trim((string)$bridgePayload['mh_auth_role']);
            }
        }
    }
    if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
        // #region debug-point A:require-login-redirect
        mh_oidc_debug_emit('A', 'oidc/lib.php:mh_oidc_require_login', 'redirecting to auth/login because mh_auth_user missing', [
            'return_to' => $returnTo,
            'session_name' => $sessionName,
            'session_id' => $sessionId !== '' ? $sessionId : null,
        ]);
        // #endregion
        header('Location: /auth/login.php?redirect=' . rawurlencode($returnTo));
        exit;
    }
}

function mh_oidc_create_code(array $payload): string {
    $codeDir = mh_oidc_code_dir();
    $code = mh_oidc_base64url_encode(random_bytes(32));
    $payload['exp'] = time() + 90;
    $payload['code'] = $code;
    $path = rtrim($codeDir, '/') . '/' . $code . '.json';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $bytesWritten = is_string($json) ? @file_put_contents($path, $json) : false;
    mh_oidc_debug_emit('T', 'oidc/lib.php:mh_oidc_create_code', 'authorization code persisted', [
        'code' => $code,
        'path' => $path,
        'base_dir' => mh_oidc_base_dir(),
        'code_dir' => $codeDir,
        'json_ok' => is_string($json),
        'bytes_written' => $bytesWritten,
        'exists_after_write' => is_file($path),
        'is_writable_dir' => is_writable(dirname($path)),
    ]);
    if ($bytesWritten === false || !is_file($path)) {
        throw new RuntimeException('oidc_code_persist_failed');
    }
    @chmod($path, 0600);
    return $code;
}

function mh_oidc_consume_code(string $code): ?array {
    $codeDir = mh_oidc_code_dir();
    $path = rtrim($codeDir, '/') . '/' . $code . '.json';
    if (!is_file($path)) {
        mh_oidc_debug_emit('T', 'oidc/lib.php:mh_oidc_consume_code', 'authorization code file missing', [
            'code' => $code,
            'path' => $path,
            'base_dir' => mh_oidc_base_dir(),
            'code_dir' => $codeDir,
            'dir_exists' => is_dir(dirname($path)),
            'dir_readable' => is_readable(dirname($path)),
        ]);
        return null;
    }
    $raw = (string)file_get_contents($path);
    @unlink($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        mh_oidc_debug_emit('T', 'oidc/lib.php:mh_oidc_consume_code', 'authorization code payload invalid', [
            'code' => $code,
            'path' => $path,
            'raw_length' => strlen($raw),
        ]);
        return null;
    }
    $exp = isset($data['exp']) ? (int)$data['exp'] : 0;
    if ($exp < time()) {
        mh_oidc_debug_emit('T', 'oidc/lib.php:mh_oidc_consume_code', 'authorization code expired', [
            'code' => $code,
            'path' => $path,
            'exp' => $exp,
            'now' => time(),
        ]);
        return null;
    }
    mh_oidc_debug_emit('T', 'oidc/lib.php:mh_oidc_consume_code', 'authorization code consumed', [
        'code' => $code,
        'path' => $path,
        'client_id' => $data['client_id'] ?? null,
        'redirect_uri' => $data['redirect_uri'] ?? null,
        'username' => $data['username'] ?? null,
    ]);
    return $data;
}

function mh_oidc_sign_jwt(array $claims): string {
    $keys = mh_oidc_load_or_create_keys();
    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => $keys['kid']
    ];
    $encHeader = mh_oidc_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encClaims = mh_oidc_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $input = $encHeader . '.' . $encClaims;
    $sig = '';
    $ok = openssl_sign($input, $sig, $keys['private_pem'], OPENSSL_ALGO_SHA256);
    if (!$ok) {
        throw new RuntimeException('jwt_sign_failed');
    }
    return $input . '.' . mh_oidc_base64url_encode($sig);
}

function mh_oidc_verify_jwt(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $payload = json_decode(mh_oidc_base64url_decode($p), true);
    if (!is_array($payload)) return null;
    $sig = mh_oidc_base64url_decode($s);
    $keys = mh_oidc_load_or_create_keys();
    $ok = openssl_verify($h . '.' . $p, $sig, $keys['public_pem'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;
    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp !== 0 && $exp < time()) return null;
    return $payload;
}
