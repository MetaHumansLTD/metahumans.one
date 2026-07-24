<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/auth/persona_registry.php';

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

function mh_meetings_json_exit(int $code, array $payload): void
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
    echo json_encode(['ok' => false, 'error' => 'fatal', 'detail' => (string)($e['message'] ?? '')], JSON_UNESCAPED_SLASHES);
});

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    mh_meetings_json_exit(401, ['ok' => false, 'error' => 'auth_required']);
}
$user = trim($user);

$tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) && trim((string)$_SESSION['mh_tenant_id']) !== ''
    ? trim((string)$_SESSION['mh_tenant_id'])
    : ('user:' . $user);
try {
    $okCtx = mh_apply_tenant_context($tenantId);
    if ($okCtx !== true) {
        mh_meetings_json_exit(500, ['ok' => false, 'error' => 'tenant_context_unavailable']);
    }
} catch (Throwable) {
    mh_meetings_json_exit(500, ['ok' => false, 'error' => 'tenant_context_unavailable']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mh_meetings_json_exit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

$csrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
$expectedMeetings = $_SESSION['mh_meetings_csrf'] ?? '';
$expectedAgenda = $_SESSION['mh_agenda_csrf'] ?? '';
$csrfOk = false;
if (is_string($csrf) && $csrf !== '') {
    if (is_string($expectedMeetings) && $expectedMeetings !== '' && hash_equals($expectedMeetings, $csrf)) $csrfOk = true;
    if (is_string($expectedAgenda) && $expectedAgenda !== '' && hash_equals($expectedAgenda, $csrf)) $csrfOk = true;
}
if ($action !== 'user_search' && !$csrfOk) {
    mh_meetings_json_exit(403, ['ok' => false, 'error' => 'csrf']);
}

$db = calendar_get_db();
if (!$db) {
    mh_meetings_json_exit(500, ['ok' => false, 'error' => 'calendar_db_unavailable']);
}
calendar_ensure_tables($db);

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

try {
    if ($action === 'cancel') {
        if ($id < 1) {
            mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_id']);
        }
        $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
        if ($reason !== '' && strlen($reason) > 255) {
            $reason = substr($reason, 0, 255);
        }
        $stmt = $db->prepare("
            UPDATE mh_meetings
            SET status = 'canceled',
                canceled_at_utc = UTC_TIMESTAMP(),
                canceled_reason = :r,
                tock_notified = 0
            WHERE id = :id AND created_by_user = :u
        ");
        $stmt->execute([':r' => $reason !== '' ? $reason : null, ':id' => $id, ':u' => $user]);
        mh_meetings_json_exit(200, ['ok' => true, 'updated' => $stmt->rowCount() > 0]);
    }

    if ($action === 'reschedule') {
        if ($id < 1) {
            mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_id']);
        }
        $scheduledUtc = isset($_POST['scheduled_utc']) ? trim((string)$_POST['scheduled_utc']) : '';
        $scheduledText = isset($_POST['scheduled_text']) ? trim((string)$_POST['scheduled_text']) : '';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledUtc, new DateTimeZone('UTC'));
        if (!$dt instanceof DateTime) {
            mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_time']);
        }

        $stmt = $db->prepare("
            UPDATE mh_meetings
            SET scheduled_for_utc = :sutc,
                scheduled_for_text = :stxt,
                status = 'scheduled',
                canceled_at_utc = NULL,
                canceled_reason = NULL,
                tock_notified = 0
            WHERE id = :id AND created_by_user = :u
        ");
        $stmt->execute([
            ':sutc' => $dt->format('Y-m-d H:i:s'),
            ':stxt' => $scheduledText !== '' ? $scheduledText : $dt->format('Y-m-d H:i:s'),
            ':id' => $id,
            ':u' => $user,
        ]);
        mh_meetings_json_exit(200, ['ok' => true, 'updated' => $stmt->rowCount() > 0]);
    }

    if ($action === 'series_create') {
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
        if ($name === '') {
            mh_meetings_json_exit(400, ['ok' => false, 'error' => 'missing_name']);
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }
        $stmt = $db->prepare("INSERT INTO mh_meeting_series (name, created_by_user, created_at_utc) VALUES (:n, :u, UTC_TIMESTAMP())");
        $stmt->execute([':n' => $name, ':u' => $user]);
        mh_meetings_json_exit(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    if ($action === 'series_delete') {
        if ($id < 1) {
            mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_id']);
        }
        $chk = $db->prepare("SELECT id FROM mh_meeting_series WHERE id = ? AND created_by_user = ? LIMIT 1");
        $chk->execute([$id, $user]);
        $ok = (int)$chk->fetchColumn();
        if ($ok < 1) {
            mh_meetings_json_exit(404, ['ok' => false, 'error' => 'not_found']);
        }
        $db->beginTransaction();
        try {
            $u = $db->prepare("UPDATE mh_meetings SET series_id = NULL WHERE series_id = ? AND created_by_user = ?");
            $u->execute([$id, $user]);
            $d = $db->prepare("DELETE FROM mh_meeting_series WHERE id = ? AND created_by_user = ? LIMIT 1");
            $d->execute([$id, $user]);
            $db->commit();
            mh_meetings_json_exit(200, ['ok' => true, 'deleted' => $d->rowCount() > 0]);
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable) {}
            mh_meetings_json_exit(500, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if ($action === 'attendees_save') {
        if ($id < 1) mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_id']);
        // Check owner
        $chk = $db->prepare("SELECT id FROM mh_meetings WHERE id = ? AND created_by_user = ? LIMIT 1");
        $chk->execute([$id, $user]);
        if (!$chk->fetchColumn()) mh_meetings_json_exit(403, ['ok' => false, 'error' => 'owner_only']);

        $attendees = isset($_POST['attendees']) ? (string)$_POST['attendees'] : '';
        $list = json_decode($attendees, true);
        if (!is_array($list)) mh_meetings_json_exit(400, ['ok' => false, 'error' => 'invalid_attendees']);

        $db->beginTransaction();
        try {
            // Simple sync: delete all and re-insert
            $del = $db->prepare("DELETE FROM mh_meeting_attendees WHERE meeting_id = ?");
            $del->execute([$id]);

            $ins = $db->prepare("
                INSERT INTO mh_meeting_attendees (meeting_id, username, role, name_display, email, phone)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($list as $a) {
                $u_a = trim((string)($a['username'] ?? ''));
                if ($u_a === '') continue;
                $ins->execute([
                    $id,
                    $u_a,
                    trim((string)($a['role'] ?? 'participant')),
                    trim((string)($a['name_display'] ?? '')),
                    trim((string)($a['email'] ?? '')),
                    trim((string)($a['phone'] ?? '')),
                ]);
            }
            $db->commit();
            // Reset tock_notified to trigger fresh reminders for new attendees
            $db->prepare("UPDATE mh_meetings SET tock_notified = 0 WHERE id = ?")->execute([$id]);
            mh_meetings_json_exit(200, ['ok' => true]);
        } catch (Throwable $e) {
            try { $db->rollBack(); } catch (Throwable) {}
            mh_meetings_json_exit(500, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    if ($action === 'user_search') {
        $q = trim((string)($_POST['query'] ?? ''));
        if (strlen($q) < 2) mh_meetings_json_exit(200, ['ok' => true, 'results' => []]);

        $results = [];
        $like = '%' . $q . '%';

        try {
            $pdoReg = mh_persona_registry_pdo();
            if (function_exists('mh_user_directory_search')) {
                $rows = mh_user_directory_search($pdoReg, $q, 12);
                foreach ($rows as $r) {
                    $results[] = [
                        'username' => (string)($r['username'] ?? ''),
                        'name_display' => (string)($r['display_name'] ?? ''),
                        'persona_name' => (string)($r['persona_name'] ?? ''),
                        'email' => (string)($r['email'] ?? ''),
                    ];
                }
            }
        } catch (Throwable) {
        }

        if ($results === []) {
            try {
                $pdoReg = mh_persona_registry_pdo();
                if (function_exists('mh_user_directory_search')) {
                    $rows = mh_user_directory_search($pdoReg, $q, 12);
                    foreach ($rows as $r) {
                        $results[] = [
                            'username' => (string)($r['username'] ?? ''),
                            'name_display' => (string)($r['display_name'] ?? ''),
                            'persona_name' => (string)($r['persona_name'] ?? ''),
                            'email' => (string)($r['email'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable) {
            }
        }

        mh_meetings_json_exit(200, ['ok' => true, 'results' => $results]);
    }

    mh_meetings_json_exit(400, ['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    mh_meetings_json_exit(500, ['ok' => false, 'error' => $e->getMessage()]);
}
