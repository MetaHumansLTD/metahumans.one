<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/calendar/calendar_helpers.php';
require_once dirname(__DIR__) . '/meet_helpers.php';
require_once dirname(__DIR__, 3) . '/auth/auth_functions.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

function mh_list_tenant_db_config_ids(): array
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_loadConfigurations')) {
        return [];
    }
    $cfgs = database_loadConfigurations();
    if (!is_array($cfgs)) {
        return [];
    }
    $out = [];
    foreach ($cfgs as $id => $cfg) {
        if (!is_string($id) || $id === '' || !is_array($cfg)) {
            continue;
        }
        $ctx = isset($cfg['context']) ? strtolower(trim((string)$cfg['context'])) : '';
        $name = isset($cfg['name']) ? strtolower(trim((string)$cfg['name'])) : '';
        $db = isset($cfg['database']) ? strtolower(trim((string)$cfg['database'])) : '';
        if ($ctx === 'tenant' || strpos($id, 'tenant_') === 0 || strpos($name, 'tenant_') === 0 || strpos($name, 'tenant:') === 0 || strpos($db, 'tenant_') === 0) {
            $out[] = $id;
        }
    }
    sort($out);
    return $out;
}

$nowUtc = new DateTime('now', new DateTimeZone('UTC'));
$nowStr = $nowUtc->format('Y-m-d H:i:s');

$tenantDbIds = mh_list_tenant_db_config_ids();
if ($tenantDbIds === []) {
    fwrite(STDERR, "no_tenant_dbs\n");
    exit(0);
}

$charged = 0;
$scanned = 0;

foreach ($tenantDbIds as $dbId) {
    try {
        $calDb = database_getConnectionById($dbId);
    } catch (Throwable) {
        continue;
    }
    if (!$calDb instanceof PDO) {
        continue;
    }
    calendar_ensure_tables($calDb);

    $stmt = $calDb->prepare("
        SELECT id, room_id, created_by_user, token_charge_amount, token_charge_due_utc
        FROM mh_meetings
        WHERE token_charge_status = 'pending'
          AND token_charge_amount > 0
          AND token_charge_due_utc IS NOT NULL
          AND token_charge_due_utc <= :now
        ORDER BY token_charge_due_utc ASC
        LIMIT 25
    ");
    $stmt->execute([':now' => $nowStr]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || $rows === []) {
        continue;
    }

    foreach ($rows as $r) {
        $scanned++;
        $meetingId = (int)($r['id'] ?? 0);
        $roomId = isset($r['room_id']) ? trim((string)$r['room_id']) : '';
        $user = isset($r['created_by_user']) ? trim((string)$r['created_by_user']) : '';
        $amount = (int)($r['token_charge_amount'] ?? 0);
        if ($meetingId < 1 || $roomId === '' || $user === '' || $amount < 1) {
            continue;
        }

        $chargeable = false;
        try {
            $active = pnm_get_active_room_info_helper($roomId);
            if (pnm_room_is_running_with_participants($active, 2)) {
                $room = is_array($active['room'] ?? null) ? $active['room'] : [];
                $roomInfo = is_array($room['room_info'] ?? null) ? $room['room_info'] : [];
                $creation = (int)($roomInfo['creation_time'] ?? 0);
                if ($creation > 0) {
                    $age = time() - $creation;
                    if ($age >= 300) {
                        $chargeable = true;
                    }
                } else {
                    $chargeable = true;
                }
            }
        } catch (Throwable) {
            $chargeable = false;
        }

        if (!$chargeable) {
            continue;
        }

        try {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            mh_tokenomics_ensure_schema($pdoTok);

            $ok = mh_tokenomics_debit_utility_tokens_exact($pdoTok, $user, $amount, 'meet:meeting', [
                'room_id' => $roomId,
                'meeting_id' => $meetingId,
                'tokens' => $amount,
            ]);
            if ($ok) {
                $u = $calDb->prepare("UPDATE mh_meetings SET token_charge_status = 'charged', token_charged_at_utc = :now, token_charge_error = NULL WHERE id = :id");
                $u->execute([':now' => $nowStr, ':id' => $meetingId]);
                $charged++;
            } else {
                $u = $calDb->prepare("UPDATE mh_meetings SET token_charge_status = 'failed', token_charge_error = 'insufficient_tokens' WHERE id = :id");
                $u->execute([':id' => $meetingId]);
            }
        } catch (Throwable $e) {
            $u = $calDb->prepare("UPDATE mh_meetings SET token_charge_status = 'failed', token_charge_error = :err WHERE id = :id");
            $err = substr($e->getMessage(), 0, 500);
            $u->execute([':err' => $err, ':id' => $meetingId]);
        }
    }
}

fwrite(STDOUT, "ok scanned={$scanned} charged={$charged}\n");
