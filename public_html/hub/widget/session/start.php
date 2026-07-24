<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

$ctx = mh_widget_require_auth();
$username = (string)$ctx['username'];

$body = mh_widget_read_json_body();
$personaId = isset($body['persona_id']) ? trim((string)$body['persona_id']) : '';
if ($personaId !== '') {
    $_SESSION['mh_selected_persona'] = $personaId;
    $ctx['persona_id'] = $personaId;
}
$requestedMode = isset($body['requested_mode']) ? trim((string)$body['requested_mode']) : '';
if ($requestedMode === '') {
    $requestedMode = 'auto';
}

require_once __DIR__ . '/../../../gear/meet/meet_helpers.php';

$roomId = mh_widget_make_room_id($ctx);
$roomTitle = (string)($ctx['persona_id'] ?? $roomId);

function mh_b64url(string $bin): string
{
    $s = rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    return $s !== '' ? $s : '';
}

function mh_jwt_hs256(array $header, array $payload, string $secret): string
{
    $h = mh_b64url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = mh_b64url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $msg = $h . '.' . $p;
    $sig = mh_b64url(hash_hmac('sha256', $msg, $secret, true));
    return $msg . '.' . $sig;
}

$identity = 'wgt_' . bin2hex(random_bytes(8));
$name = $username;

$apiKey = '';
$apiSecret = '';
try {
    $apiKey = pnm_get_api_key();
    $apiSecret = pnm_get_api_secret();
} catch (Throwable) {
    $apiKey = '';
    $apiSecret = '';
}
if ($apiKey === '' || $apiSecret === '') {
    mh_widget_json(['success' => false, 'error' => 'token_generation_failed'], 200);
    exit;
}

$now = time();
$livekitToken = mh_jwt_hs256(
    ['alg' => 'HS256', 'typ' => 'JWT'],
    [
        'iss' => $apiKey,
        'sub' => $identity,
        'name' => $name,
        'nbf' => $now - 10,
        'exp' => $now + 3600,
        'video' => [
            'roomJoin' => true,
            'room' => $roomId,
            'canPublish' => true,
            'canSubscribe' => true,
        ],
    ],
    $apiSecret
);

$meetToken = '';
try {
    pnm_create_room_helper($roomId, $roomTitle);
    $join = pnm_get_join_token_helper($roomId, $name, $identity, false);
    $meetToken = isset($join['token']) ? trim((string)$join['token']) : '';
} catch (Throwable) {
    $meetToken = '';
}

$sessionId = bin2hex(random_bytes(16));
$_SESSION['mh_widget_sessions'] = is_array($_SESSION['mh_widget_sessions'] ?? null) ? $_SESSION['mh_widget_sessions'] : [];
$_SESSION['mh_widget_sessions'][$sessionId] = [
    'room_id' => $roomId,
    'identity' => $identity,
    'persona_id' => (string)($ctx['persona_id'] ?? ''),
    'requested_mode' => $requestedMode,
    'created_at' => gmdate('c'),
];

$hubBase = '/hub';

mh_widget_json([
    'success' => true,
    'session_id' => $sessionId,
    'realtime' => [
        'provider' => 'livekit',
        'url' => mh_widget_livekit_url(),
        'token' => $livekitToken,
        'room' => $roomId,
    ],
    'events_url' => $hubBase . '/widget/events?session_id=' . rawurlencode($sessionId),
    'meet_url' => $meetToken !== '' ? ('/hub/meet/room.php?embed=1&room_id=' . rawurlencode($roomId) . '&access_token=' . rawurlencode($meetToken)) : null,
]);
