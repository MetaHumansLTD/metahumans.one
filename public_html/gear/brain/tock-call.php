<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

function mh_tock_load_signing_cfg(): array
{
    $cfgPath = '/data/config/tock-signing.json';
    if (!is_file($cfgPath)) {
        return ['ok' => false, 'error' => 'missing_config', 'path' => $cfgPath];
    }
    $raw = (string)@file_get_contents($cfgPath);
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'invalid_config', 'path' => $cfgPath];
    }
    $keyId = isset($decoded['key_id']) ? trim((string)$decoded['key_id']) : '';
    $secret = isset($decoded['secret']) ? trim((string)$decoded['secret']) : '';
    if ($keyId === '' || $secret === '') {
        return ['ok' => false, 'error' => 'incomplete_config', 'path' => $cfgPath];
    }
    return ['ok' => true, 'key_id' => $keyId, 'secret' => $secret, 'path' => $cfgPath];
}

function mh_tock_build_payload(string $userId, string $text, string $channel = 'calendar', string $taskType = 'general', string $routeHint = 'auto'): array
{
    $userId = trim($userId);
    $text = (string)$text;
    $tenantId = $userId !== '' ? ('user:' . $userId) : '';
    $personaId = $userId !== '' ? ('MH-' . $userId) : '';
    $metaHumanId = $personaId !== '' ? ('meta:' . $personaId) : '';
    return [
        'channel' => $channel,
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'user_id' => $userId,
        'task_type' => $taskType,
        'route_hint' => $routeHint,
        'input' => ['text' => $text],
    ];
}

function mh_tock_sign_headers(string $body, string $keyId, string $secret): array
{
    $ts = (string)time();
    $sig = hash_hmac('sha256', $ts . "\n" . $body, $secret);
    return [
        'X-MH-KeyId: ' . $keyId,
        'X-MH-Timestamp: ' . $ts,
        'X-MH-Signature: ' . $sig,
    ];
}

function mh_tock_post(array $payload, string $url = ''): array
{
    $tockUrl = $url !== '' ? $url : (string)(getenv('TOCK_URL') ?: 'https://meta.superhumans.one/tock/v1/route');
    $cfg = mh_tock_load_signing_cfg();
    if (($cfg['ok'] ?? false) !== true) {
        return $cfg;
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'error' => 'encode_failed'];
    }

    $headers = array_merge(
        ['Content-Type: application/json'],
        mh_tock_sign_headers($body, (string)$cfg['key_id'], (string)$cfg['secret'])
    );

    $ch = curl_init($tockUrl);
    if ($ch === false) return ['ok' => false, 'error' => 'curl_init_failed'];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;

    $decoded = null;
    if (is_string($resp) && $resp !== '') {
        $decoded = json_decode($resp, true);
    }
    return [
        'ok' => $err === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'curl_error' => $err,
        'response_raw' => is_string($resp) ? $resp : '',
        'response_json' => is_array($decoded) ? $decoded : null,
        'tock_url' => $tockUrl,
        'key_id' => (string)$cfg['key_id'],
        'config_path' => (string)$cfg['path'],
    ];
}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CLI only';
    exit;
}

$user = isset($argv[1]) ? (string)$argv[1] : '';
$text = isset($argv[2]) ? (string)$argv[2] : 'health check';
$payload = mh_tock_build_payload($user !== '' ? $user : 'test', $text, 'calendar', 'general', 'auto');
$result = mh_tock_post($payload);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

