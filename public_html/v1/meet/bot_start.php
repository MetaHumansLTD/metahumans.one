<?php
declare(strict_types=1);

require_once __DIR__ . '/v1_meet_auth.php';

function mh_meet_sanitize_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mh_meet_sanitize_plugnmeet_user_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-]+/', '_', $s);
    $s = trim((string)$s, '_-');
    return $s !== '' ? $s : 'bot';
}

function mh_meet_bot_call(string $path, string $method, array $payload = []): array {
    $url = 'http://127.0.0.1:19100' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($method !== 'GET') {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $json = null;
    if (is_string($resp) && $resp !== '') {
        $parsed = json_decode($resp, true);
        if (is_array($parsed)) $json = $parsed;
    }
    return ['ok' => $err === '' && $code >= 200 && $code < 300, 'status' => $code, 'error' => $err, 'json' => $json, 'raw' => is_string($resp) ? $resp : ''];
}

$body = mh_meet_read_json_body();
$jwt = mh_meet_extract_access_token($body);
$pl = mh_meet_verify_access_token($jwt);

$roomId = isset($pl['room_id']) && is_string($pl['room_id']) ? trim($pl['room_id']) : '';
$userId = isset($pl['user_id']) && is_string($pl['user_id']) ? trim($pl['user_id']) : '';
$name = isset($pl['name']) && is_string($pl['name']) ? trim($pl['name']) : '';
if ($roomId === '' || $userId === '') mh_meet_json_out(401, ['ok' => false, 'error' => 'missing_claims']);

$personaId = isset($body['persona_id']) ? trim((string)$body['persona_id']) : '';
if ($personaId === '') $personaId = 'MH-' . $userId;
$personaId = mh_meet_sanitize_id($personaId);

$botName = isset($body['bot_name']) ? trim((string)$body['bot_name']) : '';
if ($botName === '') $botName = $personaId;

$role = isset($body['role']) ? (string)$body['role'] : 'presenter';
$isAdmin = in_array(strtolower(trim((string)$role)), ['presenter', 'host', 'admin'], true);

try {
    require_once __DIR__ . '/../../gear/meet/meet_helpers.php';
    $safe = mh_meet_sanitize_plugnmeet_user_id($personaId);
    $suffix = substr(sha1($roomId . '|' . $personaId), 0, 8);
    $pnmUserId = 'bot_' . substr($safe, 0, 40) . '_' . $suffix;
    $tok = pnm_get_join_token_helper($roomId, $botName, $pnmUserId, $isAdmin);
    $token = '';
    if (is_array($tok)) {
        if (isset($tok['token']) && is_string($tok['token'])) {
            $token = (string)$tok['token'];
        } elseif (isset($tok['data']) && is_array($tok['data']) && isset($tok['data']['token']) && is_string($tok['data']['token'])) {
            $token = (string)$tok['data']['token'];
        }
    }
    if ($token === '') mh_meet_json_out(502, ['ok' => false, 'error' => 'join_token_failed']);
    $pub = pnm_get_public_url();
    $joinUrl = rtrim((string)$pub, '/') . '/?access_token=' . rawurlencode($token);
} catch (Throwable $e) {
    mh_meet_json_out(502, ['ok' => false, 'error' => 'join_token_failed']);
}

$payload = [
    'room_id' => $roomId,
    'room_title' => isset($body['room_title']) ? (string)$body['room_title'] : '',
    'join_url' => $joinUrl,
    'bot_name' => $botName,
    'role' => $role,
    'avatar_url' => isset($body['avatar_url']) ? (string)$body['avatar_url'] : '',
    'headed' => isset($body['headed']) && $body['headed'] === true,
    'ctx' => [
        'tenant_id' => 'user:' . $userId,
        'persona_id' => $personaId,
        'user_id' => 'user:' . $userId,
        'meta_human_id' => 'meta:' . strtolower($personaId),
        'session_id' => 'meet:' . $roomId,
        'device_id' => '',
        'username' => $userId,
        'display_name' => $name !== '' ? $name : $userId,
    ],
];

try {
    mh_meet_bot_call('/stop', 'POST', ['room_id' => $roomId]);
} catch (Throwable) {
}
$r = mh_meet_bot_call('/start', 'POST', $payload);
if (!$r['ok'] || !is_array($r['json'])) {
    mh_meet_json_out(502, ['ok' => false, 'error' => 'daemon_start_failed', 'status' => $r['status'], 'detail' => $r['error'], 'raw' => $r['raw']]);
}

mh_meet_json_out(200, ['ok' => true, 'data' => $r['json']]);
