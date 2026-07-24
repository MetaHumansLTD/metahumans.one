<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';
require_once __DIR__ . '/../../../gear/meet/meet_helpers.php';

function mh_widget_agent_identity(string $roomId, string $personaId): string
{
    $seed = $roomId . '|' . $personaId . '|' . (string)($_SESSION['mh_auth_user'] ?? '');
    $hex = substr(hash('sha256', $seed), 0, 20);
    return 'wgt_agent_' . $hex;
}

$ctx = mh_widget_require_auth();
$body = mh_widget_read_json_body();

$sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : '';
if ($sessionId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_session_id'], 400);
    exit;
}

$sessions = is_array($_SESSION['mh_widget_sessions'] ?? null) ? $_SESSION['mh_widget_sessions'] : [];
$session = isset($sessions[$sessionId]) && is_array($sessions[$sessionId]) ? $sessions[$sessionId] : null;
if (!$session) {
    mh_widget_json(['success' => false, 'error' => 'session_not_found'], 404);
    exit;
}

$roomId = isset($session['room_id']) && is_string($session['room_id']) ? trim((string)$session['room_id']) : '';
if ($roomId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_room_id'], 500);
    exit;
}

$personaId = isset($session['persona_id']) && is_string($session['persona_id']) ? trim((string)$session['persona_id']) : '';
if ($personaId === '') {
    $personaId = (string)($ctx['persona_id'] ?? '');
}

$agentName = isset($body['agent_name']) && is_string($body['agent_name']) ? trim((string)$body['agent_name']) : '';
if ($agentName === '') {
    $agentName = $personaId !== '' ? $personaId : 'Agent';
}

$identity = isset($session['agent_identity']) && is_string($session['agent_identity']) ? trim((string)$session['agent_identity']) : '';
if ($identity === '') {
    $identity = mh_widget_agent_identity($roomId, $personaId);
    $sessions[$sessionId]['agent_identity'] = $identity;
    $_SESSION['mh_widget_sessions'] = $sessions;
}

$isAdmin = true;
$join = [];
try {
    $join = pnm_get_join_token_helper($roomId, $agentName, $identity, $isAdmin);
} catch (Throwable) {
    mh_widget_json(['success' => false, 'error' => 'token_generation_failed'], 502);
    exit;
}

$token = isset($join['token']) ? trim((string)$join['token']) : '';
if ($token === '' && isset($join['data']) && is_array($join['data']) && isset($join['data']['token']) && is_string($join['data']['token'])) {
    $token = trim((string)$join['data']['token']);
}
if ($token === '') {
    mh_widget_json(['success' => false, 'error' => 'token_generation_failed'], 502);
    exit;
}

mh_widget_json([
    'success' => true,
    'session_id' => $sessionId,
    'agent' => [
        'identity' => $identity,
        'name' => $agentName,
    ],
    'realtime' => [
        'provider' => 'livekit',
        'url' => mh_widget_livekit_url(),
        'token' => $token,
        'room' => $roomId,
    ],
]);
