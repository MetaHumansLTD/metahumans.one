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

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

ob_start();

header('Content-Type: application/json; charset=UTF-8');

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
    echo json_encode(['success' => false, 'error' => 'fatal', 'detail' => (string)($e['message'] ?? '')]);
});

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'auth_required']);
    exit;
}
$user = trim($user);

try {
    mh_apply_tenant_context('user:' . $user);

    $rows = calendar_get_meetings($user, null);
    if (!is_array($rows) || $rows === []) {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (function_exists('database_loadConfigurations') && function_exists('database_getConnectionById') && function_exists('mh_resolve_tenant_db_config_id')) {
            $targetId = mh_resolve_tenant_db_config_id('user:' . $user);
            if (is_string($targetId) && $targetId !== '') {
                try {
                    $target = database_getConnectionById($targetId);
                } catch (Throwable) {
                    $target = null;
                }
                if ($target instanceof PDO) {
                    calendar_ensure_tables($target);
                    $cfgs = database_loadConfigurations();
                    if (is_array($cfgs)) {
                        foreach ($cfgs as $id => $_cfg) {
                            if (!is_string($id) || $id === '' || $id === $targetId) {
                                continue;
                            }
                            try {
                                $src = database_getConnectionById($id);
                            } catch (Throwable) {
                                continue;
                            }
                            if (!$src instanceof PDO) {
                                continue;
                            }
                            try {
                                $q = $src->prepare("SELECT * FROM mh_meetings WHERE created_by_user = ? ORDER BY id DESC LIMIT 25");
                                $q->execute([$user]);
                                $found = $q->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Throwable) {
                                continue;
                            }
                            if (!is_array($found) || $found === []) {
                                continue;
                            }

                            $existing = [];
                            try {
                                $q2 = $target->prepare("SELECT room_id, created_at_utc FROM mh_meetings WHERE created_by_user = ? ORDER BY id DESC LIMIT 500");
                                $q2->execute([$user]);
                                $erows = $q2->fetchAll(PDO::FETCH_ASSOC);
                                if (is_array($erows)) {
                                    foreach ($erows as $er) {
                                        $rk = (string)($er['room_id'] ?? '');
                                        $ck = (string)($er['created_at_utc'] ?? '');
                                        if ($rk !== '' && $ck !== '') {
                                            $existing[$rk . '|' . $ck] = true;
                                        }
                                    }
                                }
                            } catch (Throwable) {
                            }

                            $ins = $target->prepare("
                                INSERT INTO mh_meetings (
                                    room_id, title, invite_url, presenter_join_url, participant_join_url,
                                    scheduled_for_utc, scheduled_for_text, created_at_utc, created_by_user,
                                    session_id, persona_mode, tock_notified,
                                    token_charge_status, token_charge_amount, token_charge_due_utc, token_charged_at_utc, token_charge_error
                                ) VALUES (
                                    :room_id, :title, :invite_url, :presenter_join_url, :participant_join_url,
                                    :scheduled_for_utc, :scheduled_for_text, :created_at_utc, :created_by_user,
                                    :session_id, :persona_mode, :tock_notified,
                                    :token_charge_status, :token_charge_amount, :token_charge_due_utc, :token_charged_at_utc, :token_charge_error
                                )
                            ");

                            foreach ($found as $f) {
                                if (!is_array($f)) continue;
                                $roomId = isset($f['room_id']) ? (string)$f['room_id'] : '';
                                $createdAt = isset($f['created_at_utc']) ? (string)$f['created_at_utc'] : '';
                                if ($roomId === '' || $createdAt === '') continue;
                                if (isset($existing[$roomId . '|' . $createdAt])) continue;

                                $ins->execute([
                                    ':room_id' => $roomId,
                                    ':title' => (string)($f['title'] ?? 'MetaHumans Meeting'),
                                    ':invite_url' => isset($f['invite_url']) ? (string)$f['invite_url'] : null,
                                    ':presenter_join_url' => isset($f['presenter_join_url']) ? (string)$f['presenter_join_url'] : null,
                                    ':participant_join_url' => isset($f['participant_join_url']) ? (string)$f['participant_join_url'] : null,
                                    ':scheduled_for_utc' => isset($f['scheduled_for_utc']) ? (string)$f['scheduled_for_utc'] : null,
                                    ':scheduled_for_text' => isset($f['scheduled_for_text']) ? (string)$f['scheduled_for_text'] : null,
                                    ':created_at_utc' => $createdAt,
                                    ':created_by_user' => $user,
                                    ':session_id' => isset($f['session_id']) ? (string)$f['session_id'] : null,
                                    ':persona_mode' => isset($f['persona_mode']) ? (string)$f['persona_mode'] : null,
                                    ':tock_notified' => isset($f['tock_notified']) ? (int)$f['tock_notified'] : 0,
                                    ':token_charge_status' => isset($f['token_charge_status']) ? (string)$f['token_charge_status'] : 'none',
                                    ':token_charge_amount' => isset($f['token_charge_amount']) ? (int)$f['token_charge_amount'] : 0,
                                    ':token_charge_due_utc' => isset($f['token_charge_due_utc']) ? (string)$f['token_charge_due_utc'] : null,
                                    ':token_charged_at_utc' => isset($f['token_charged_at_utc']) ? (string)$f['token_charged_at_utc'] : null,
                                    ':token_charge_error' => isset($f['token_charge_error']) ? (string)$f['token_charge_error'] : null,
                                ]);
                            }
                            break;
                        }
                    }
                }
            }
        }
        $rows = calendar_get_meetings($user, null);
    }

    $events = [];
    foreach ($rows as $row) {
        $inviteUrl = isset($row['invite_url']) ? str_replace('role=participant', 'role=viewer', (string)$row['invite_url']) : null;
        $participantJoinUrl = isset($row['participant_join_url']) ? str_replace('role=participant', 'role=viewer', (string)$row['participant_join_url']) : null;
        $startUtc = null;
        if (!empty($row['scheduled_for_utc'])) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$row['scheduled_for_utc'], new DateTimeZone('UTC'));
            if ($dt instanceof DateTime) {
                $startUtc = $dt->format('c');
            }
        } elseif (!empty($row['scheduled_for_text'])) {
            try {
                $dt = new DateTime((string)$row['scheduled_for_text'], new DateTimeZone('UTC'));
                $startUtc = $dt->format('c');
            } catch (Throwable) {
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
            'can_present' => isset($row['created_by_user']) && (string)$row['created_by_user'] === $user,
        ];
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo json_encode(['success' => true, 'events' => $events]);
} catch (Throwable $e) {
    http_response_code(500);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
