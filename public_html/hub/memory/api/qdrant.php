<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 3) . '/.cue/cue.php';

function mh_qdrant_gateway_json(array $payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function mh_qdrant_gateway_is_localhost(): bool
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    $serverIp = isset($_SERVER['SERVER_ADDR']) ? (string)$_SERVER['SERVER_ADDR'] : '';
    if ($ip !== '' && $serverIp !== '' && $ip === $serverIp) return true;
    if ($ip === '127.0.0.1' || $ip === '::1') return true;
    if (strpos($ip, '10.') === 0) return true;
    if (strpos($ip, '192.168.') === 0) return true;
    if (preg_match('/^172\\.(1[6-9]|2\\d|3[0-1])\\./', $ip)) return true;
    return false;
}

function mh_qdrant_gateway_path(): string
{
    $pi = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
    if ($pi !== '') return $pi;
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if ($uri !== '' && $script !== '' && strpos($uri, $script) === 0) {
        $rest = substr($uri, strlen($script));
        $rest = explode('?', $rest, 2)[0];
        return $rest !== '' ? $rest : '/';
    }
    return '/';
}

function mh_qdrant_gateway_require_tenant_filter(array $filter): bool
{
    if (!isset($filter['must']) || !is_array($filter['must'])) return false;
    foreach ($filter['must'] as $m) {
        if (!is_array($m)) continue;
        if (($m['key'] ?? null) !== 'tenant_id') continue;
        if (!isset($m['match']) || !is_array($m['match'])) continue;
        if (!isset($m['match']['value']) || !is_string($m['match']['value']) || trim((string)$m['match']['value']) === '') continue;
        return true;
    }
    return false;
}

function mh_qdrant_gateway_proxy(string $method, string $path, ?string $rawBody): void
{
    $qs = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
    $url = 'http://127.0.0.1:6333' . $path . ($qs !== '' ? ('?' . $qs) : '');
    $opts = [
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 10,
            'content' => $rawBody,
        ]
    ];
    $ctx = stream_context_create($opts);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^HTTP\\/\\d+\\.\\d+\\s+(\\d+)/', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    http_response_code($code > 0 ? $code : 502);
    header('Content-Type: application/json; charset=UTF-8');
    echo is_string($resp) ? $resp : '';
}

if (!mh_qdrant_gateway_is_localhost()) {
    mh_qdrant_gateway_json(['ok' => false, 'error' => 'forbidden'], 403);
    exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
$path = mh_qdrant_gateway_path();
if ($path === '') $path = '/';

$rawBody = null;
$decoded = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    $rawBody = is_string($raw) ? $raw : '';
    if (trim((string)$rawBody) !== '') {
        $decoded = json_decode((string)$rawBody, true);
    }
}

$allowed = false;

if (preg_match('#^/collections/?$#', $path) && $method === 'GET') {
    $allowed = true;
}

if (preg_match('#^/collections/[^/]+$#', $path) && in_array($method, ['GET', 'PUT', 'DELETE'], true)) {
    $allowed = true;
}

if (preg_match('#^/collections/[^/]+/points$#', $path) && $method === 'PUT') {
    if (!is_array($decoded) || !isset($decoded['points']) || !is_array($decoded['points'])) {
        mh_qdrant_gateway_json(['ok' => false, 'error' => 'points_required'], 400);
        exit;
    }
    foreach ($decoded['points'] as $p) {
        if (!is_array($p)) {
            mh_qdrant_gateway_json(['ok' => false, 'error' => 'point_invalid'], 400);
            exit;
        }
        if (!isset($p['payload']) || !is_array($p['payload'])) {
            mh_qdrant_gateway_json(['ok' => false, 'error' => 'payload_required'], 400);
            exit;
        }
        $tenant = $p['payload']['tenant_id'] ?? null;
        if (!is_string($tenant) || trim((string)$tenant) === '') {
            mh_qdrant_gateway_json(['ok' => false, 'error' => 'tenant_id_required'], 403);
            exit;
        }
    }
    $allowed = true;
}

if (preg_match('#^/collections/[^/]+/points/search$#', $path) && $method === 'POST') {
    if (!is_array($decoded) || !isset($decoded['filter']) || !is_array($decoded['filter'])) {
        mh_qdrant_gateway_json(['ok' => false, 'error' => 'filter_required'], 403);
        exit;
    }
    if (!mh_qdrant_gateway_require_tenant_filter((array)$decoded['filter'])) {
        mh_qdrant_gateway_json(['ok' => false, 'error' => 'tenant_filter_required'], 403);
        exit;
    }
    $allowed = true;
}

if (!$allowed) {
    mh_qdrant_gateway_json(['ok' => false, 'error' => 'not_allowed', 'path' => $path, 'method' => $method], 403);
    exit;
}

mh_qdrant_gateway_proxy($method, $path, $rawBody);
