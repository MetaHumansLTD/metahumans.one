<?php
declare(strict_types=1);

require_once __DIR__ . '/../_context.php';

$ctx = mhw_require_context();

function mh_meet_bot_sanitize_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mh_meet_bot_sanitize_plugnmeet_user_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-]+/', '_', $s);
    $s = trim((string)$s, '_-');
    return $s !== '' ? $s : 'bot';
}

function mh_meet_bot_meta_human_from_persona(string $personaId): string {
    $personaId = mh_meet_bot_sanitize_id($personaId);
    return 'meta:' . strtolower($personaId);
}

function mh_meet_bot_tenant_safe(string $tenantId): string {
    $s = trim((string)$tenantId);
    $s = strtolower((string)preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    return trim($s, '._-');
}

function mh_meet_bot_persona_settings(string $tenantId, string $personaId, string $botName): array {
    $tenantSafe = mh_meet_bot_tenant_safe($tenantId);
    $personaSafe = mh_meet_bot_tenant_safe($personaId);
    if ($tenantSafe === '' || $personaSafe === '') {
        return [];
    }
    $specPath = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/persona-spec.json';
    if (!is_file($specPath)) {
        return [];
    }
    $raw = @file_get_contents($specPath);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return [];
    }
    $speechEngine = isset($j['speech']['engine']) && is_string($j['speech']['engine']) ? strtolower(trim((string)$j['speech']['engine'])) : 'classic';
    if (!in_array($speechEngine, ['classic', 'personaplex'], true)) $speechEngine = 'classic';
    $ppVoice = isset($j['speech']['personaplex_voice']) && is_string($j['speech']['personaplex_voice']) ? strtoupper(trim((string)$j['speech']['personaplex_voice'])) : '';
    if ($ppVoice === '') $ppVoice = 'NATF2';
    $lang = isset($j['language']) && is_string($j['language']) ? trim((string)$j['language']) : 'en-US';
    if ($lang === '') $lang = 'en-US';
    $personaPrompt = isset($j['persona_description']) && is_string($j['persona_description']) ? trim((string)$j['persona_description']) : '';
    if ($personaPrompt === '') {
        $personaPrompt = 'You are ' . ($botName !== '' ? $botName : $personaSafe) . '. Speak naturally in English.';
    }
    return [
        'speech_engine' => $speechEngine,
        'personaplex_voice' => $ppVoice,
        'language' => $lang,
        'persona_prompt' => $personaPrompt,
        'voice_type' => isset($j['voice']['type']) && is_string($j['voice']['type']) ? strtolower(trim((string)$j['voice']['type'])) : 'auto',
        'translation_enabled' => !empty($j['translation_enabled']),
        'vision_enabled' => !empty($j['vision_enabled']),
        'hearing_enabled' => !empty($j['hearing_enabled']),
        'instruction_backend' => isset($j['backends']['instruction']) && is_string($j['backends']['instruction']) ? trim((string)$j['backends']['instruction']) : 'hermes',
    ];
}

function mh_meet_bot_json_in(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function mh_meet_bot_out(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_meet_bot_call(string $path, string $method, array $payload = []): array {
    $url = 'http://127.0.0.1:19100' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($method !== 'GET') {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;
    $json = null;
    if (is_string($resp) && $resp !== '') {
        $parsed = json_decode($resp, true);
        if (is_array($parsed)) $json = $parsed;
    }
    return ['ok' => $err === '' && $code >= 200 && $code < 300, 'status' => $code, 'error' => $err, 'json' => $json, 'raw' => is_string($resp) ? $resp : ''];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'status') {
    $r = mh_meet_bot_call('/status', 'GET');
    if (!$r['ok'] || !is_array($r['json'])) {
        mh_meet_bot_out(502, ['ok' => false, 'error' => 'daemon_unreachable', 'status' => $r['status'], 'detail' => $r['error']]);
    }
    mh_meet_bot_out(200, ['ok' => true, 'data' => $r['json']]);
}

if ($method === 'POST' && ($action === 'start' || $action === 'stop')) {
    $body = mh_meet_bot_json_in();
    if ($action === 'start') {
        $roomId = isset($body['room_id']) ? trim((string)$body['room_id']) : '';
        $botName = isset($body['bot_name']) ? trim((string)$body['bot_name']) : '';
        $personaOverride = isset($body['persona_id']) ? trim((string)$body['persona_id']) : '';
        if ($roomId === '') mh_meet_bot_out(400, ['ok' => false, 'error' => 'missing_room_id']);
        if ($botName === '') $botName = $personaOverride !== '' ? $personaOverride : 'Meta Human Persona';
        $role = isset($body['role']) ? (string)$body['role'] : 'presenter';
        $isAdmin = in_array(strtolower(trim((string)$role)), ['presenter', 'host', 'admin'], true);
        $joinUrl = '';
        $tenantId = (string)($ctx['tenant_id'] ?? '');
        $personaId = $personaOverride !== '' ? $personaOverride : (string)($ctx['persona_id'] ?? '');
        $personaSettings = mh_meet_bot_persona_settings($tenantId, $personaId, $botName);
        try {
            require_once dirname(__DIR__, 4) . '/gear/meet/meet_helpers.php';
            $base = $personaOverride !== '' ? $personaOverride : $botName;
            $safe = mh_meet_bot_sanitize_plugnmeet_user_id($base);
            $suffix = substr(sha1($roomId . '|' . $botName), 0, 8);
            $userId = 'bot_' . substr($safe, 0, 40) . '_' . $suffix;
            $tok = pnm_get_join_token_helper($roomId, $botName, $userId, $isAdmin);
            $token = '';
            if (is_array($tok)) {
                if (isset($tok['token']) && is_string($tok['token'])) {
                    $token = (string)$tok['token'];
                } elseif (isset($tok['data']) && is_array($tok['data']) && isset($tok['data']['token']) && is_string($tok['data']['token'])) {
                    $token = (string)$tok['data']['token'];
                }
            }
            $pub = pnm_get_public_url();
            $joinUrl = rtrim((string)$pub, '/') . '/?access_token=' . rawurlencode($token);
        } catch (Throwable $e) {
            mh_meet_bot_out(502, ['ok' => false, 'error' => 'join_token_failed', 'detail' => $e->getMessage()]);
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
                'tenant_id' => $tenantId,
                'persona_id' => $personaId,
                'user_id' => (string)($ctx['user_id'] ?? ''),
                'meta_human_id' => $personaOverride !== '' ? mh_meet_bot_meta_human_from_persona($personaOverride) : (string)($ctx['meta_human_id'] ?? ''),
                'session_id' => (string)($ctx['session_id'] ?? ''),
                'device_id' => (string)($ctx['device_id'] ?? ''),
                'username' => (string)($ctx['username'] ?? ''),
                'persona_settings' => $personaSettings,
            ],
        ];
        try {
            mh_meet_bot_call('/stop', 'POST', ['room_id' => $roomId]);
        } catch (Throwable $e) {
        }
        $r = mh_meet_bot_call('/start', 'POST', $payload);
        if (!$r['ok'] || !is_array($r['json'])) {
            mh_meet_bot_out(502, ['ok' => false, 'error' => 'daemon_start_failed', 'status' => $r['status'], 'detail' => $r['error'], 'raw' => $r['raw']]);
        }
        mh_meet_bot_out(200, ['ok' => true, 'data' => $r['json']]);
    } else {
        $roomId = isset($body['room_id']) ? trim((string)$body['room_id']) : '';
        if ($roomId === '') mh_meet_bot_out(400, ['ok' => false, 'error' => 'missing_room_id']);
        $r = mh_meet_bot_call('/stop', 'POST', ['room_id' => $roomId]);
        if (!$r['ok'] || !is_array($r['json'])) {
            mh_meet_bot_out(502, ['ok' => false, 'error' => 'daemon_stop_failed', 'status' => $r['status'], 'detail' => $r['error'], 'raw' => $r['raw']]);
        }
        mh_meet_bot_out(200, ['ok' => true, 'data' => $r['json']]);
    }
}

mh_meet_bot_out(404, ['ok' => false, 'error' => 'not_found']);
