<?php
declare(strict_types=1);

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
$mode = (string)(getenv('MOSIP_UPSTREAM_DEMO_MODE') ?: 'always_verify');

$verified = false;
$score = 0.0;
$reason = 'demo:failed';
if ($mode === 'always_verify') {
    $verified = true;
    $score = 1.0;
    $reason = 'demo:verified';
} elseif ($mode === 'verify_if_user_prefix') {
    $pfx = (string)(getenv('MOSIP_UPSTREAM_DEMO_PREFIX') ?: 'mosip_');
    if ($pfx !== '' && $username !== '' && strncmp($username, $pfx, strlen($pfx)) === 0) {
        $verified = true;
        $score = 0.9;
        $reason = 'demo:verified_prefix';
    }
}

$expiresAt = time() + 3600;
echo json_encode([
    'verified' => $verified,
    'score' => $score,
    'reason' => $reason,
    'expires_at' => $expiresAt,
    'echo' => [
        'username' => $username,
        'session_id' => $sessionId,
    ],
], JSON_UNESCAPED_SLASHES);
