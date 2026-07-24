<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'auth_required']);
    exit;
}
$user = trim($user);
mh_apply_tenant_context('user:' . $user);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$csrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
$expected = $_SESSION['mh_calendar_csrf'] ?? '';
if (!is_string($expected) || $expected === '' || !hash_equals($expected, $csrf)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id < 1) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_id']);
    exit;
}

$db = calendar_get_db();
if (!$db) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'calendar_db_unavailable']);
    exit;
}
calendar_ensure_tables($db);

try {
    if ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM mh_meetings WHERE id = :id AND created_by_user = :u");
        $stmt->execute([':id' => $id, ':u' => $user]);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
        exit;
    }

    if ($action === 'update') {
        $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
        if ($title === '') {
            $title = 'MetaHumans Meeting';
        }
        $scheduledUtcInput = isset($_POST['scheduled_utc']) ? trim((string)$_POST['scheduled_utc']) : '';
        $scheduledLocal = isset($_POST['scheduled_local']) ? trim((string)$_POST['scheduled_local']) : '';
        $scheduledUtc = null;
        $scheduledText = null;
        if ($scheduledUtcInput !== '') {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledUtcInput, new DateTimeZone('UTC'));
            if ($dt instanceof DateTime) {
                $scheduledUtc = $dt->format('Y-m-d H:i:s');
                $scheduledText = $scheduledUtc;
            }
        } elseif ($scheduledLocal !== '') {
            try {
                $dt = new DateTime($scheduledLocal);
                $dt->setTimezone(new DateTimeZone('UTC'));
                $scheduledUtc = $dt->format('Y-m-d H:i:s');
                $scheduledText = $scheduledUtc;
            } catch (Throwable) {
                $scheduledUtc = null;
                $scheduledText = null;
            }
        }

        $stmt = $db->prepare("
            UPDATE mh_meetings
            SET title = :t,
                scheduled_for_utc = :sutc,
                scheduled_for_text = :stxt,
                tock_notified = 0
            WHERE id = :id AND created_by_user = :u
        ");
        $stmt->execute([
            ':t' => $title,
            ':sutc' => $scheduledUtc,
            ':stxt' => $scheduledText,
            ':id' => $id,
            ':u' => $user,
        ]);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'updated' => $stmt->rowCount() > 0]);
        exit;
    }

    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
