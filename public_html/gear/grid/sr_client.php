<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) { define('CUE_DISABLE_AUTO_UI', true); }
if (!defined('CUE_LAYOUT_MANUAL')) { define('CUE_LAYOUT_MANUAL', true); }
if (is_file(__DIR__ . '/../../.cue/cue.php')) {
    require_once __DIR__ . '/../../.cue/cue.php';
}
if (function_exists('cue_autoload')) {
    cue_autoload('paths');
    cue_autoload('security');
}

function mh_grid_enc_key(): string
{
    if (function_exists('cue_autoload')) {
        cue_autoload('paths');
        cue_autoload('security');
    }
    $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
    $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
    return is_string($raw) ? trim($raw) : '';
}

function mh_grid_decrypt_cfg_value(string $maybeEnc): string
{
    $v = trim($maybeEnc);
    if ($v === '') return '';
    $key = mh_grid_enc_key();
    if ($key === '' || !function_exists('security_decryptValue')) {
        return $v;
    }
    $plain = security_decryptValue($v, $key);
    if (is_string($plain) && trim($plain) !== '') {
        return trim($plain);
    }
    return $v;
}

function mh_grid_cfg_path(): string
{
    return '/data/config/grid.json';
}

function mh_grid_read_cfg(): array
{
    $cfg = [];

    $p = mh_grid_cfg_path();
    if (is_file($p)) {
        $raw = @file_get_contents($p);
        if (is_string($raw) && trim($raw) !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) $cfg = $d;
        }
    }

    $baseUrl = isset($cfg['base_url']) && is_string($cfg['base_url']) ? trim($cfg['base_url']) : '';
    if ($baseUrl === '') $baseUrl = trim((string)(getenv('MH_GRID_BASE_URL') ?: ''));
    $baseUrl = rtrim($baseUrl, '/');

    $tokenId = isset($cfg['token_id']) && is_string($cfg['token_id']) ? trim($cfg['token_id']) : '';
    if ($tokenId === '') $tokenId = trim((string)(getenv('MH_GRID_TOKEN_ID') ?: ''));
    if ($tokenId !== '') $tokenId = mh_grid_decrypt_cfg_value($tokenId);

    $clientSecret = isset($cfg['client_secret']) && is_string($cfg['client_secret']) ? trim($cfg['client_secret']) : '';
    if ($clientSecret === '') $clientSecret = trim((string)(getenv('MH_GRID_CLIENT_SECRET') ?: ''));
    if ($clientSecret !== '') $clientSecret = mh_grid_decrypt_cfg_value($clientSecret);

    $internalEmailDomain = isset($cfg['internal_email_domain']) && is_string($cfg['internal_email_domain'])
        ? ltrim(trim((string)$cfg['internal_email_domain']), '@')
        : '';
    if ($internalEmailDomain === '') {
        $internalEmailDomain = ltrim(trim((string)(getenv('MH_GRID_INTERNAL_EMAIL_DOMAIN') ?: '')), '@');
    }

    $allowlist = [];
    if (isset($cfg['allowlist']) && is_array($cfg['allowlist'])) {
        foreach ($cfg['allowlist'] as $v) {
            if (!is_string($v)) continue;
            $s = trim($v);
            if ($s === '') continue;
            if ($s[0] !== '/') $s = '/' . $s;
            $allowlist[] = $s;
        }
    }

    $webhookPublicKeyPem = isset($cfg['webhook_public_key_pem']) && is_string($cfg['webhook_public_key_pem']) ? trim((string)$cfg['webhook_public_key_pem']) : '';
    if ($webhookPublicKeyPem === '') $webhookPublicKeyPem = trim((string)(getenv('MH_GRID_WEBHOOK_PUBLIC_KEY_PEM') ?: ''));

    $webhookPublicKeyPath = isset($cfg['webhook_public_key_path']) && is_string($cfg['webhook_public_key_path']) ? trim((string)$cfg['webhook_public_key_path']) : '';
    if ($webhookPublicKeyPath === '') $webhookPublicKeyPath = trim((string)(getenv('MH_GRID_WEBHOOK_PUBLIC_KEY_PATH') ?: ''));
    if ($webhookPublicKeyPem === '' && $webhookPublicKeyPath !== '' && is_file($webhookPublicKeyPath)) {
        $rawKey = @file_get_contents($webhookPublicKeyPath);
        if (is_string($rawKey) && trim($rawKey) !== '') {
            $webhookPublicKeyPem = trim($rawKey);
        }
    }
    if ($webhookPublicKeyPem !== '' && str_contains($webhookPublicKeyPem, '\\n')) {
        $webhookPublicKeyPem = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $webhookPublicKeyPem);
        $webhookPublicKeyPem = trim($webhookPublicKeyPem);
    }

    $trustedEnclaveQuorumPublicKeys = [];
    $trustedRaw = $cfg['trusted_enclave_quorum_public_keys'] ?? null;
    if (!is_array($trustedRaw)) {
        $envTrusted = trim((string)(getenv('MH_GRID_TRUSTED_ENCLAVE_QUORUM_PUBLIC_KEYS') ?: ''));
        if ($envTrusted !== '') {
            $trustedRaw = preg_split('/[\r\n,]+/', $envTrusted);
        }
    }
    if (is_array($trustedRaw)) {
        $seen = [];
        foreach ($trustedRaw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = strtolower(trim($value));
            if ($normalized === '' || (strlen($normalized) % 2) !== 0 || !preg_match('/^[0-9a-f]+$/', $normalized)) {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $trustedEnclaveQuorumPublicKeys[] = $normalized;
        }
    }

    return [
        'base_url' => $baseUrl,
        'token_id' => $tokenId,
        'client_secret' => $clientSecret,
        'internal_email_domain' => $internalEmailDomain,
        'allowlist' => $allowlist,
        'webhook_public_key_pem' => $webhookPublicKeyPem,
        'trusted_enclave_quorum_public_keys' => $trustedEnclaveQuorumPublicKeys,
    ];
}

function mh_grid_verify_webhook_signature(string $rawBody, string $signatureB64, string $publicKeyPem): bool
{
    $sigHeader = trim($signatureB64);
    if ($sigHeader === '') return false;

    $sigB64 = $sigHeader;
    $maybeObj = json_decode($sigHeader, true);
    if (is_array($maybeObj) && isset($maybeObj['s']) && is_string($maybeObj['s'])) {
        $sigB64 = trim($maybeObj['s']);
    }

    $sigB64 = strtr($sigB64, '-_', '+/');
    $pad = strlen($sigB64) % 4;
    if ($pad !== 0) {
        $sigB64 .= str_repeat('=', 4 - $pad);
    }

    $sig = base64_decode($sigB64, true);
    if (!is_string($sig) || $sig === '') return false;

    if (strlen($sig) === 64) {
        $r = substr($sig, 0, 32);
        $s = substr($sig, 32, 32);

        $r = ltrim($r, "\x00");
        if ($r === '' || (ord($r[0]) & 0x80)) $r = "\x00" . $r;
        $s = ltrim($s, "\x00");
        if ($s === '' || (ord($s[0]) & 0x80)) $s = "\x00" . $s;

        $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        $sig = "\x30" . chr(strlen($seq)) . $seq;
    }

    $key = openssl_pkey_get_public($publicKeyPem);
    if ($key === false) return false;

    $ok = openssl_verify($rawBody, $sig, $key, OPENSSL_ALGO_SHA256);
    if (is_resource($key)) {
        openssl_free_key($key);
    }
    return $ok === 1;
}

function mh_grid_is_allowlisted(string $path, array $allowlist): bool
{
    $p = trim($path);
    if ($p === '') return false;
    if ($p[0] !== '/') $p = '/' . $p;

    foreach ($allowlist as $a) {
        if (!is_string($a)) continue;
        $s = trim($a);
        if ($s === '') continue;
        if ($s[0] !== '/') $s = '/' . $s;
        if ($p === $s) return true;
        if (str_ends_with($s, '/*')) {
            $prefix = substr($s, 0, -1);
            if ($prefix !== '' && str_starts_with($p, $prefix)) return true;
        }
    }

    return false;
}

function mh_grid_idempotency_key(string $tenantId, string $action, string $referenceId = ''): string
{
    $t = trim($tenantId);
    $a = trim($action);
    $r = trim($referenceId);
    $seed = $t . "\n" . $a . "\n" . $r;
    return 'mhg_' . substr(hash('sha256', $seed), 0, 40);
}

function mh_grid_debug_target(): ?array
{
    static $target = false;
    if ($target !== false) {
        return is_array($target) ? $target : null;
    }

    $candidates = [
        '/home/onemeta/.dbg/grid-passkey-not-found.env',
        '/home/onemeta/.dbg/grid-passkey-quote.env',
    ];
    $envPath = '';
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $envPath = $candidate;
            break;
        }
    }
    if ($envPath === '') {
        $target = null;
        return null;
    }

    $raw = @file_get_contents($envPath);
    if (!is_string($raw) || trim($raw) === '') {
        $target = null;
        return null;
    }

    $url = '';
    $sessionId = '';
    $runId = 'pre-fix';
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim((string)$line);
        if ($line === '' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        if ($k === 'DEBUG_SERVER_URL') {
            $url = trim($v);
        } elseif ($k === 'DEBUG_SESSION_ID') {
            $sessionId = trim($v);
        } elseif ($k === 'DEBUG_RUN_ID') {
            $candidate = trim($v);
            if ($candidate !== '') {
                $runId = $candidate;
            }
        }
    }

    if ($url === '' || $sessionId === '') {
        $target = null;
        return null;
    }

    $target = [
        'url' => $url,
        'sessionId' => $sessionId,
        'runId' => $runId,
    ];
    return $target;
}

function mh_grid_debug_emit(string $hypothesisId, string $location, string $msg, array $data = []): void
{
    $target = mh_grid_debug_target();
    if (!is_array($target)) {
        return;
    }

    $payload = json_encode([
        'sessionId' => (string)$target['sessionId'],
        'runId' => (string)($target['runId'] ?? 'pre-fix'),
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'msg' => '[DEBUG] ' . $msg,
        'data' => $data,
        'ts' => (int)round(microtime(true) * 1000),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return;
    }

    $ch = curl_init((string)$target['url']);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 800,
        CURLOPT_CONNECTTIMEOUT_MS => 300,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function mh_grid_http_request(array $cfg, string $method, string $path, array $opts = []): array
{
    $baseUrl = isset($cfg['base_url']) && is_string($cfg['base_url']) ? trim($cfg['base_url']) : '';
    $tokenId = isset($cfg['token_id']) && is_string($cfg['token_id']) ? trim($cfg['token_id']) : '';
    $clientSecret = isset($cfg['client_secret']) && is_string($cfg['client_secret']) ? trim($cfg['client_secret']) : '';
    $allowlist = isset($cfg['allowlist']) && is_array($cfg['allowlist']) ? $cfg['allowlist'] : [];

    $m = strtoupper(trim($method));
    if ($m === '') $m = 'GET';

    $p = trim($path);
    if ($p === '' || $p[0] !== '/') $p = '/' . ltrim($p, '/');

    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'grid_base_url_missing'];
    }
    if ($tokenId === '' || $clientSecret === '') {
        return ['ok' => false, 'error' => 'grid_credentials_missing'];
    }
    if (!mh_grid_is_allowlisted($p, $allowlist)) {
        return ['ok' => false, 'error' => 'endpoint_not_allowlisted', 'path' => $p];
    }

    $timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 25;
    $timeout = max(5, min(120, $timeout));
    $connectTimeout = isset($opts['connect_timeout']) ? (int)$opts['connect_timeout'] : 5;
    $connectTimeout = max(2, min(30, $connectTimeout));

    $qs = '';
    if (isset($opts['query']) && is_array($opts['query'])) {
        $parts = [];
        foreach ($opts['query'] as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            if ($v === null) continue;
            if (is_bool($v)) $v = $v ? '1' : '0';
            if (is_int($v) || is_float($v)) $v = (string)$v;
            if (!is_string($v)) continue;
            $parts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        if ($parts !== []) $qs = '?' . implode('&', $parts);
    }

    $url = rtrim($baseUrl, '/') . $p . $qs;

    $headers = [
        'Accept: application/json',
    ];

    $extraHeaders = isset($opts['headers']) && is_array($opts['headers']) ? $opts['headers'] : [];
    foreach ($extraHeaders as $k => $v) {
        if (!is_string($k) || $k === '') continue;
        if ($v === null) continue;
        if (is_bool($v)) $v = $v ? '1' : '0';
        if (is_int($v) || is_float($v)) $v = (string)$v;
        if (!is_string($v)) continue;
        $headers[] = $k . ': ' . $v;
    }

    $body = null;
    if (array_key_exists('json', $opts)) {
        $raw = json_encode($opts['json'], JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'invalid_json_body'];
        }
        $body = $raw;
        $headers[] = 'Content-Type: application/json';
    } elseif (array_key_exists('body', $opts)) {
        $b = $opts['body'];
        if ($b !== null && !is_string($b)) {
            return ['ok' => false, 'error' => 'invalid_body'];
        }
        $body = $b;
    }

    $idempotencyKey = isset($opts['idempotency_key']) && is_string($opts['idempotency_key']) ? trim($opts['idempotency_key']) : '';
    if ($idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    // #region debug-point A:grid-http-request
    mh_grid_debug_emit('A', 'sr_client.php:mh_grid_http_request:request', 'Grid HTTP request', [
        'method' => $m,
        'path' => $p,
        'query' => isset($opts['query']) && is_array($opts['query']) ? $opts['query'] : null,
        'has_json_body' => array_key_exists('json', $opts),
        'has_raw_body' => array_key_exists('body', $opts),
        'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
    ]);
    // #endregion

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $m,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_HEADER => true,
        CURLOPT_USERPWD => $tokenId . ':' . $clientSecret,
    ]);
    if ($body !== null && $m !== 'GET' && $m !== 'HEAD') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($resp)) {
        // #region debug-point C:grid-http-curl-failure
        mh_grid_debug_emit('C', 'sr_client.php:mh_grid_http_request:curl_failed', 'Grid HTTP transport failure', [
            'method' => $m,
            'path' => $p,
            'curl_error' => $err,
        ]);
        // #endregion
        return ['ok' => false, 'error' => 'curl_failed', 'detail' => $err];
    }

    $rawHeaders = $hdrSize > 0 ? substr($resp, 0, $hdrSize) : '';
    $rawBody = $hdrSize > 0 ? substr($resp, $hdrSize) : $resp;

    $json = null;
    $t = trim($rawBody);
    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
        $d = json_decode($rawBody, true);
        if (is_array($d)) $json = $d;
    }

    // #region debug-point C:grid-http-response
    mh_grid_debug_emit('C', 'sr_client.php:mh_grid_http_request:response', 'Grid HTTP response', [
        'method' => $m,
        'path' => $p,
        'status' => $status,
        'ok' => ($status >= 200 && $status < 300),
        'body_preview' => mb_substr($rawBody, 0, 1200),
        'json' => $json,
    ]);
    // #endregion

    return [
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'headers_raw' => $rawHeaders,
        'body_raw' => $rawBody,
        'json' => $json,
    ];
}
