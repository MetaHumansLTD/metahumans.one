<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

mh_widget_require_auth();

$body = mh_widget_read_json_body();
$sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : '';
if ($sessionId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_session_id'], 400);
    exit;
}

$sessions = is_array($_SESSION['mh_widget_sessions'] ?? null) ? $_SESSION['mh_widget_sessions'] : [];
if (isset($sessions[$sessionId])) {
    unset($sessions[$sessionId]);
    $_SESSION['mh_widget_sessions'] = $sessions;
}

mh_widget_json([
    'success' => true,
    'stopped' => $sessionId,
]);

