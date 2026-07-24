<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once __DIR__ . '/meet_helpers.php';
require_once __DIR__ . '/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=UTF-8');

function mh_sched_json_exit(int $code, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if (!is_array($e)) {
        return;
    }
    $type = (int)($e['type'] ?? 0);
    $fatal = in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    if (!$fatal) {
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8', true, 500);
    echo json_encode(['ok' => false, 'error' => 'fatal', 'detail' => (string)($e['message'] ?? '')]);
});

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    mh_sched_json_exit(401, ['ok' => false, 'error' => 'auth_required']);
}
$user = trim($user);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mh_sched_json_exit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (function_exists('mh_auth_load_user_context')) {
    try {
        mh_auth_load_user_context($user, $_SESSION['mh_auth_groups'] ?? null, null);
    } catch (Throwable) {
    }
}

function mh_sched_meeting_cost_tokens(): int
{
    if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_get_service_pricing')) {
        return 50;
    }
    try {
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdo);
        $row = mh_tokenomics_get_service_pricing($pdo, 'meet:meeting', 50);
        $tpu = (int)($row['tokens_per_unit'] ?? 50);
        return max(1, $tpu);
    } catch (Throwable) {
        return 50;
    }
}

function mh_sched_token_balance(string $username): int
{
    $candidates = array_values(array_unique(array_filter([
        $username,
        strtolower($username),
        strtoupper($username),
    ], function ($v) { return is_string($v) && trim($v) !== ''; })));

    $tok = null;
    if (function_exists('mh_get_token_balance')) {
        foreach ($candidates as $u) {
            $b = mh_get_token_balance($u);
            if (is_int($b)) {
                $tok = max((int)($tok ?? 0), $b);
            }
        }
    }

    $sess = isset($_SESSION['tokens']) ? (int)$_SESSION['tokens'] : 0;
    if (is_int($tok)) {
        return max($sess, $tok);
    }
    return $sess;
}

function mh_sched_normalize_room(string $raw): array
{
    $title = trim($raw);
    if ($title === '') {
        return ['room_id' => '', 'title' => ''];
    }

    $ascii = preg_replace('/[^ -~]/', '', $title);
    $room = preg_replace('/\s+/', '_', $ascii);
    $room = preg_replace('/[^A-Za-z0-9_-]/', '_', $room);
    $room = preg_replace('/_+/', '_', $room);
    $room = trim($room, "_- ");

    if ($room === '') {
        $room = 'mh_' . substr(hash('sha256', $title), 0, 12);
    }

    if (strlen($room) > 64) {
        $room = substr($room, 0, 48) . '_' . substr(hash('sha256', $room), 0, 12);
    }

    return ['room_id' => $room, 'title' => $title];
}

try {
    $tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($tenantId === '') {
        $tenantId = 'user:' . $user;
    }
    mh_apply_tenant_context($tenantId);

    $titleRaw = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
    $roomRaw = isset($_POST['room_id']) ? trim((string)$_POST['room_id']) : '';
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : '';
    $time = isset($_POST['time']) ? trim((string)$_POST['time']) : '';
    $scheduledUtcRaw = isset($_POST['scheduled_utc']) ? trim((string)$_POST['scheduled_utc']) : '';
    $seriesId = isset($_POST['series_id']) ? (int)$_POST['series_id'] : 0;
    if ($seriesId < 1) {
        $seriesId = 0;
    }
    $norm = mh_sched_normalize_room($titleRaw !== '' ? $titleRaw : $roomRaw);
    $roomId = $roomRaw !== '' ? $roomRaw : (string)($norm['room_id'] ?? '');
    $roomTitle = $titleRaw !== '' ? $titleRaw : (string)($norm['title'] ?? $roomId);

    if ($roomId === '') {
        mh_sched_json_exit(400, ['ok' => false, 'error' => 'missing_title']);
    }

    $scheduledText = '';
    if ($date !== '' || $time !== '') {
        $scheduledText = trim($date . ' ' . $time);
    }
    $scheduledUtc = null;
    if ($scheduledUtcRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledUtcRaw, new DateTimeZone('UTC'));
        if ($dt instanceof DateTime) {
            $scheduledUtc = $dt->format('Y-m-d H:i:s');
            if ($scheduledText === '') {
                $scheduledText = $scheduledUtc;
            }
        }
    }

    $meetingCost = mh_sched_meeting_cost_tokens();
    $balance = mh_sched_token_balance($user);

    $calDb = calendar_get_db();
    if (!$calDb) {
        throw new RuntimeException('calendar_db_unavailable');
    }
    calendar_ensure_tables($calDb);

    if ($seriesId > 0) {
        $chk = $calDb->prepare("SELECT id FROM mh_meeting_series WHERE id = ? LIMIT 1");
        $chk->execute([$seriesId]);
        $okSeries = (int)$chk->fetchColumn();
        if ($okSeries < 1) {
            $seriesId = 0;
        }
    }

    $existingMeeting = calendar_find_active_meeting_by_room($calDb, $roomId);
    $existingId = (int)($existingMeeting['id'] ?? 0);

    if ($existingId < 1 && $balance < $meetingCost) {
        mh_sched_json_exit(402, ['ok' => false, 'error' => 'insufficient_tokens', 'balance' => $balance, 'cost' => $meetingCost]);
    }

    try {
        pnm_create_room_helper($roomId, $roomTitle);
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, 'exist') === false && strpos($msg, 'already') === false) {
            throw $e;
        }
    }

    $name = $user;
    $userId = 'presenter_' . bin2hex(random_bytes(8));
    $res = null;
    for ($i = 0; $i < 5; $i++) {
        $res = pnm_get_join_token_helper($roomId, $name, $userId, true);
        if (is_array($res) && !empty($res['status']) && !empty($res['token'])) {
            break;
        }
        $msg = is_array($res) && isset($res['msg']) ? strtolower((string)$res['msg']) : '';
        if (strpos($msg, 'room is not active') === false && strpos($msg, 'room not found') === false) {
            break;
        }
        pnm_create_room_helper($roomId, $roomTitle);
        usleep(300000);
    }
    if (!is_array($res) || empty($res['status']) || empty($res['token'])) {
        $m = is_array($res) && isset($res['msg']) ? (string)$res['msg'] : 'join_token_failed';
        throw new RuntimeException($m);
    }

    $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string)$_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one');
    $host = trim(explode(',', $host)[0]);
    $joinUrl = 'https://' . $host . '/meet/?access_token=' . $res['token'];

    $participantUrl = 'https://' . $host . '/meet.php?room_id=' . rawurlencode($roomId) . '&role=viewer';
    $presenterUrl = 'https://' . $host . '/meet.php?room_id=' . rawurlencode($roomId) . '&role=presenter';

    $meetingId = 0;
    if ($existingId < 1) {
        $meetingId = calendar_store_meeting([
            'room_id' => $roomId,
            'title' => $roomTitle !== '' ? $roomTitle : $roomId,
            'invite_url' => $participantUrl,
            'presenter_join_url' => $presenterUrl,
            'participant_join_url' => $participantUrl,
            'scheduled_for_utc' => $scheduledUtc,
            'scheduled_for_text' => $scheduledText,
            'created_by_user' => $user,
            'series_id' => $seriesId > 0 ? $seriesId : null,
            'status' => 'scheduled',
        ]);
        if ($meetingId > 0) {
            $due = (new DateTime('now', new DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d H:i:s');
            calendar_set_meeting_token_charge_pending($calDb, $meetingId, $meetingCost, $due);
        }

        try {
            $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
            $rootPath = rtrim((string)getDataPath(), '/') . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
            if (!is_dir($rootPath)) {
                @mkdir($rootPath, 0775, true);
            }
            $meta = [
                'room_id' => $roomId,
                'title' => $roomTitle,
                'tenant_id' => $tenantId,
                'created_by' => $user,
                'created_at_utc' => gmdate('c'),
                'invite_url' => $participantUrl,
                'presenter_join_url' => $presenterUrl,
                'scheduled_for_text' => $scheduledText,
                'series_id' => $seriesId > 0 ? $seriesId : null,
            ];
            @file_put_contents($rootPath . '/meeting.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } catch (Throwable) {
        }
    }

    if ($meetingId < 1 && $existingId > 0) {
        $meetingId = $existingId;
    }

    if ($meetingId > 0) {
        try {
            $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
            $seed = [];
            if ($seriesId > 0) {
                $prev = $calDb->prepare("SELECT id FROM mh_meetings WHERE series_id = ? AND created_by_user = ? AND id <> ? ORDER BY COALESCE(scheduled_for_utc, created_at_utc) DESC LIMIT 1");
                $prev->execute([$seriesId, $user, $meetingId]);
                $prevId = (int)$prev->fetchColumn();
                if ($prevId > 0) {
                    $a = $calDb->prepare("SELECT agenda_json FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
                    $a->execute([$prevId]);
                    $raw = (string)$a->fetchColumn();
                    $decoded = $raw !== '' ? json_decode($raw, true) : null;
                    if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                        foreach ($decoded['items'] as $it) {
                            if (!is_array($it)) continue;
                            $st = isset($it['status']) ? (string)$it['status'] : 'open';
                            if ($st !== 'open') continue;
                            $seed[] = [
                                'id' => 'carry_' . bin2hex(random_bytes(6)),
                                'type' => isset($it['type']) ? (string)$it['type'] : 'action',
                                'title' => isset($it['title']) ? (string)$it['title'] : '',
                                'status' => 'open',
                                'notes' => isset($it['notes']) ? (string)$it['notes'] : '',
                            ];
                        }
                    }
                }
            }

            if ($seed === []) {
                $seed = [
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Call to order', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Notice / waiver of notice', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Roll call / attendance', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'decision', 'title' => 'Confirm quorum', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'decision', 'title' => 'Approve prior minutes', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Reports (CEO/CFO/committees)', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Old business', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'New business', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'decision', 'title' => 'Resolutions', 'status' => 'open', 'notes' => ''],
                    ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => 'info', 'title' => 'Adjournment', 'status' => 'open', 'notes' => ''],
                ];
            }

            $scheduledLine = $scheduledText !== '' ? $scheduledText : ($scheduledUtc !== null ? $scheduledUtc : '');
            $doc = '';
            $doc .= "# Board of Directors Meeting Agenda\n\n";
            $doc .= "## Meeting Details\n";
            $doc .= "- Corporation: \n";
            $doc .= "- Date/Time: " . $scheduledLine . "\n";
            $doc .= "- Location / Teleconference: \n";
            $doc .= "- Chair: \n";
            $doc .= "- Secretary: \n\n";
            $doc .= "## Attendance\n";
            $doc .= "- Directors present: \n";
            $doc .= "- Directors absent: \n";
            $doc .= "- Officers / guests present: \n\n";
            $doc .= "## Notice and Quorum\n";
            $doc .= "- Notice given / waiver obtained: \n";
            $doc .= "- Quorum present: \n\n";
            $doc .= "## Agenda Items\n";
            $doc .= "Agenda items are tracked in the Agenda Items table.\n";

            $agenda = [
                'version' => 1,
                'meeting_id' => $meetingId,
                'series_id' => $seriesId > 0 ? $seriesId : null,
                'items' => $seed,
                'document_md' => $doc,
                'delaware' => [
                    'meeting_type' => 'board',
                    'scheduled_text' => $scheduledText,
                    'scheduled_utc' => $scheduledUtc,
                ],
            ];

            $ins = $calDb->prepare("INSERT IGNORE INTO mh_meeting_agendas (meeting_id, series_id, agenda_version, agenda_json, minutes_md, created_at_utc) VALUES (?, ?, 1, ?, NULL, UTC_TIMESTAMP())");
            $ins->execute([$meetingId, $seriesId > 0 ? $seriesId : null, json_encode($agenda, JSON_UNESCAPED_SLASHES)]);

            $root = rtrim((string)getDataPath(), '/') . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
            @mkdir($root . '/agenda', 0775, true);
            @file_put_contents($root . '/agenda/agenda.json', json_encode($agenda, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } catch (Throwable) {
        }
    }

    mh_sched_json_exit(200, [
        'ok' => true,
        'meeting_id' => $meetingId,
        'room_id' => $roomId,
        'title' => $roomTitle,
        'scheduled_text' => $scheduledText,
        'join_url' => $joinUrl,
        'presenter_url' => $presenterUrl,
        'participant_url' => $participantUrl,
        'cost_tokens' => $meetingCost,
        'balance_tokens' => $balance,
    ]);
} catch (Throwable $e) {
    mh_sched_json_exit(500, ['ok' => false, 'error' => $e->getMessage()]);
}
