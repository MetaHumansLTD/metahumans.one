<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth_required'], JSON_UNESCAPED_SLASHES);
    exit;
}
$user = trim($user);

$tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) && trim((string)$_SESSION['mh_tenant_id']) !== ''
    ? trim((string)$_SESSION['mh_tenant_id'])
    : ('user:' . $user);
try {
    $okCtx = mh_apply_tenant_context($tenantId);
    if ($okCtx !== true) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'tenant_context_unavailable'], JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'tenant_context_unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

$minutes = isset($_GET['minutes']) ? (int)$_GET['minutes'] : 120;
if ($minutes < 1) $minutes = 120;
if ($minutes > 1440) $minutes = 1440;

$db = calendar_get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'calendar_db_unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}
calendar_ensure_tables($db);

$now = new DateTime('now', new DateTimeZone('UTC'));
$until = clone $now;
$until->modify('+' . $minutes . ' minutes');
$nowUtc = $now->format('Y-m-d H:i:s');
$untilUtc = $until->format('Y-m-d H:i:s');

$stmt = $db->prepare("
    SELECT id, room_id, title, scheduled_for_utc, scheduled_for_text
    FROM mh_meetings
    WHERE created_by_user = ?
      AND status <> 'canceled'
      AND scheduled_for_utc IS NOT NULL
      AND scheduled_for_utc >= ?
      AND scheduled_for_utc <= ?
    ORDER BY scheduled_for_utc ASC
    LIMIT 5
");
$stmt->execute([$user, $nowUtc, $untilUtc]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows)) $rows = [];

echo json_encode(['ok' => true, 'now_utc' => $nowUtc, 'until_utc' => $untilUtc, 'meetings' => $rows], JSON_UNESCAPED_SLASHES);

