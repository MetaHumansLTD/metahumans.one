<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_context.php';

$ctx = mhw_require_context();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) $input = [];

$agentBase = getenv('MHW_AGENT_API_URL');
$agentBase = is_string($agentBase) ? trim($agentBase) : '';
if ($agentBase === '') $agentBase = 'https://meta.superhumans.one/api/agent';
$agentBase = rtrim($agentBase, '/');

$payload = array_merge($input, [
    'tenant_id' => (string)($ctx['tenant_id'] ?? ''),
    'user_id' => (string)($ctx['user_id'] ?? ''),
    'persona_id' => (string)($ctx['persona_id'] ?? ''),
    'meta_human_id' => (string)($ctx['meta_human_id'] ?? ''),
    'session_id' => (string)($ctx['session_id'] ?? ''),
    'device_id' => (string)($ctx['device_id'] ?? ''),
]);

$ch = curl_init($agentBase . '/jobs');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 60,
]);
$body = curl_exec($ch);
$err = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$ch = null;

if ($err !== '') {
    mhw_json(['success' => false, 'error' => 'agent_api_unreachable', 'detail' => $err], 502);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
http_response_code($code ?: 502);
echo is_string($body) ? $body : json_encode(['ok' => false, 'error' => 'empty_response'], JSON_UNESCAPED_SLASHES);
