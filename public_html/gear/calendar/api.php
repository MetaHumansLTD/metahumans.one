<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/calendar_helpers.php';
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

try {
    $userId = isset($_SESSION['mh_auth_user']) ? (string)$_SESSION['mh_auth_user'] : null;
    $sessionId = isset($_GET['session_id']) ? trim((string)$_GET['session_id']) : null;

    $u = is_string($userId) ? trim($userId) : '';
    if ($u === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'auth_required']);
        exit;
    }
    if (function_exists('mh_kripz_is_role') && !mh_kripz_is_role()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'forbidden']);
        exit;
    }

    $rows = calendar_get_meetings($userId, $sessionId);

    $events = [];
    foreach ($rows as $row) {
        $inviteUrl = isset($row['invite_url']) ? str_replace('role=participant', 'role=viewer', (string)$row['invite_url']) : null;
        $participantJoinUrl = isset($row['participant_join_url']) ? str_replace('role=participant', 'role=viewer', (string)$row['participant_join_url']) : null;
        $startUtc = null;
        if (!empty($row['scheduled_for_utc'])) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row['scheduled_for_utc'], new DateTimeZone('UTC'));
            if ($dt instanceof DateTime) {
                $startUtc = $dt->format('c');
            }
        }

        $events[] = [
            'id' => (int)($row['id'] ?? 0),
            'room_id' => $row['room_id'] ?? '',
            'title' => $row['title'] ?? 'MetaHumans Meeting',
            'start_utc' => $startUtc,
            'scheduled_for_text' => $row['scheduled_for_text'] ?? null,
            'invite_url' => $inviteUrl,
            'presenter_join_url' => $row['presenter_join_url'] ?? null,
            'participant_join_url' => $participantJoinUrl,
            'can_present' => isset($row['created_by_user']) && (string)$row['created_by_user'] === $u,
        ];
    }

    echo json_encode([
        'success' => true,
        'events' => $events,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
