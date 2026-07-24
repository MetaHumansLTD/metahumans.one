<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) { define('CUE_DISABLE_AUTO_UI', true); }
if (!defined('CUE_LAYOUT_MANUAL')) { define('CUE_LAYOUT_MANUAL', true); }

function mh_mosip_env(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $p = '/data/kyc/env_overrides.json';
        if (is_file($p)) {
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

function mh_mosip_public_proto(): string
{
    $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if (is_string($xfp) && trim($xfp) !== '') {
        $parts = explode(',', $xfp);
        $p = strtolower(trim((string)($parts[0] ?? '')));
        if ($p === 'http' || $p === 'https') return $p;
    }
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && strtolower(trim($https)) === 'on') return 'https';
    return 'https';
}

function mh_mosip_public_host(): string
{
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
    if ($host === 'www.metahumans.one') return 'metahumans.one';
    if (str_ends_with($host, '.metahumans.one')) return 'metahumans.one';
    if ($host === 'meta.superhumans.one' || str_ends_with($host, '.superhumans.one')) return 'metahumans.one';
    return $host;
}

function mh_mosip_public_base_url(): string
{
    return mh_mosip_public_proto() . '://' . mh_mosip_public_host();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$secret = mh_mosip_env('MH_KYC_MOSIP_SECRET', '');
if ($secret === '') $secret = (string)(getenv('MOSIP_ADAPTER_SECRET') ?: '');
$maxSkew = (int)(getenv('MOSIP_ADAPTER_MAX_SKEW') ?: 90);
$maxSkew = max(15, min(600, $maxSkew));

$ts = isset($_SERVER['HTTP_X_MH_TIMESTAMP']) ? trim((string)$_SERVER['HTTP_X_MH_TIMESTAMP']) : '';
$nonce = isset($_SERVER['HTTP_X_MH_NONCE']) ? trim((string)$_SERVER['HTTP_X_MH_NONCE']) : '';
$sig = isset($_SERVER['HTTP_X_MH_SIGNATURE']) ? trim((string)$_SERVER['HTTP_X_MH_SIGNATURE']) : '';

if ($secret !== '') {
    if ($ts === '' || $nonce === '' || $sig === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'missing_signature_headers'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $tsInt = (int)$ts;
    if ($tsInt < 1 || abs(time() - $tsInt) > $maxSkew) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'timestamp_out_of_range'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || trim($raw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty_body'], JSON_UNESCAPED_SLASHES);
    exit;
}
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_SLASHES);
    exit;
}

$username = isset($payload['username']) ? trim((string)$payload['username']) : '';
$sessionId = isset($payload['session_id']) ? trim((string)$payload['session_id']) : '';

if ($secret !== '') {
    $msg = $ts . "\n" . $nonce . "\n" . $username . "\n" . $sessionId;
    $expect = hash_hmac('sha256', $msg, $secret);
    if (!hash_equals($expect, $sig)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'bad_signature'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$verified = false;
$score = 0.0;
$reason = 'mosip_adapter_not_configured';
$expiresAt = 0;

$upstream = mh_mosip_env('MOSIP_UPSTREAM_URL', '');
if ($upstream === '') $upstream = (string)(getenv('MH_KYC_MOSIP_UPSTREAM_URL') ?: '');
$upstream = trim((string)$upstream);
if ($upstream === '') {
    $upstream = mh_mosip_public_base_url() . '/gear/mosip/upstream-demo.php';
}
if ($upstream !== '') {
    $ch = curl_init($upstream);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;

    if (is_string($resp) && $resp !== '' && $status >= 200 && $status < 300) {
        $d = json_decode($resp, true);
        if (is_array($d)) {
            $verified = (bool)($d['verified'] ?? false);
            $score = isset($d['score']) ? (float)$d['score'] : 0.0;
            $reason = isset($d['reason']) ? (string)$d['reason'] : ($verified ? 'mosip_verified' : 'mosip_failed');
            $expiresAt = isset($d['expires_at']) ? (int)$d['expires_at'] : 0;
        } else {
            $reason = 'mosip_upstream_invalid_json';
        }
    } else {
        $reason = 'mosip_upstream_http_failed';
        if (is_string($err) && $err !== '') {
            $reason .= ':' . $err;
        } else {
            $reason .= ':' . (string)$status;
        }
    }
}

$respTs = (string)time();
$respNonce = bin2hex(random_bytes(16));
$out = [
    'ok' => true,
    'verified' => $verified,
    'score' => $score,
    'reason' => $reason,
    'expires_at' => $expiresAt,
    'ts' => $respTs,
    'nonce' => $respNonce,
];

if ($secret !== '') {
    $sigPayload = json_encode($out, JSON_UNESCAPED_SLASHES);
    $sigPayload = is_string($sigPayload) ? $sigPayload : '';
    $out['signature'] = hash_hmac('sha256', $respTs . "\n" . $respNonce . "\n" . $sigPayload, $secret);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
