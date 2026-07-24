<?php
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

function mh_agenda_json_exit(int $code, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/hub/meetings/'), true, 302);
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
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Tenant context unavailable';
        exit;
    }
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Tenant context unavailable';
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!isset($_SESSION['mh_agenda_csrf']) || !is_string($_SESSION['mh_agenda_csrf']) || $_SESSION['mh_agenda_csrf'] === '') {
    $_SESSION['mh_agenda_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['mh_agenda_csrf'];

$db = calendar_get_db();
if (!$db) {
    http_response_code(500);
    echo 'Calendar database unavailable';
    exit;
}
calendar_ensure_tables($db);

$roomLookup = $id < 1 ? trim((string)($_GET['room_id'] ?? '')) : '';
if ($id < 1 && $roomLookup !== '') {
    $roomLookup = preg_replace('/[^A-Za-z0-9_-]+/', '_', $roomLookup);
    // Check if creator OR attendee
    $stmt = $db->prepare("
        SELECT m.id FROM mh_meetings m
        LEFT JOIN mh_meeting_attendees a ON a.meeting_id = m.id AND a.username = :u_join
        WHERE m.room_id = :r AND (m.created_by_user = :u_owner OR a.id IS NOT NULL)
        ORDER BY m.id DESC LIMIT 1
    ");
    $stmt->execute([':r' => $roomLookup, ':u_join' => $user, ':u_owner' => $user]);
    $id = (int)$stmt->fetchColumn();
}
if ($id < 1) {
    header('Location: /hub/meetings/', true, 302);
    exit;
}

// Full meeting lookup: creator OR attendee
$stmt = $db->prepare("
    SELECT m.id, m.room_id, m.title, m.series_id, m.scheduled_for_text, m.scheduled_for_utc, m.created_at_utc, m.created_by_user
    FROM mh_meetings m
    LEFT JOIN mh_meeting_attendees a ON a.meeting_id = m.id AND a.username = :u_join
    WHERE m.id = :id AND (m.created_by_user = :u_owner OR a.id IS NOT NULL)
    LIMIT 1
");
$stmt->execute([':id' => $id, ':u_join' => $user, ':u_owner' => $user]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($meeting)) {
    http_response_code(404);
    echo 'Meeting not found or access denied';
    exit;
}

$isOwner = (trim((string)($meeting['created_by_user'] ?? '')) === $user);
$roomId = (string)($meeting['room_id'] ?? '');
$seriesId = (int)($meeting['series_id'] ?? 0);
if ($seriesId < 1) $seriesId = 0;

$seriesList = [];
try {
    $st = $db->prepare("SELECT id, name, created_by_user, created_at_utc FROM mh_meeting_series ORDER BY id DESC LIMIT 300");
    $st->execute();
    $seriesList = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($seriesList)) $seriesList = [];
} catch (Throwable) {
    $seriesList = [];
}

$agendaTemplates = [];
try {
    $st = $db->prepare("SELECT id, name, template_json, created_by_user, created_at_utc, updated_at_utc FROM mh_meeting_agenda_templates ORDER BY id DESC LIMIT 200");
    $st->execute();
    $agendaTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($agendaTemplates)) $agendaTemplates = [];
} catch (Throwable) {
    $agendaTemplates = [];
}

$seriesMeetings = [];
if ($seriesId > 0) {
    try {
        $st = $db->prepare("
            SELECT id, room_id, title, scheduled_for_text, scheduled_for_utc, created_at_utc
            FROM mh_meetings
            WHERE series_id = ? AND created_by_user = ?
            ORDER BY COALESCE(scheduled_for_utc, created_at_utc) DESC
            LIMIT 20
        ");
        $st->execute([$seriesId, $user]);
        $seriesMeetings = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($seriesMeetings)) $seriesMeetings = [];
    } catch (Throwable) {
        $seriesMeetings = [];
    }
}

$nextSuggestions = [];
try {
    $baseUtc = isset($meeting['scheduled_for_utc']) && is_string($meeting['scheduled_for_utc']) && trim((string)$meeting['scheduled_for_utc']) !== ''
        ? trim((string)$meeting['scheduled_for_utc'])
        : '';
    $base = $baseUtc !== ''
        ? DateTime::createFromFormat('Y-m-d H:i:s', $baseUtc, new DateTimeZone('UTC'))
        : new DateTime('now', new DateTimeZone('UTC'));
    if ($base instanceof DateTime) {
        foreach ([7, 14, 21] as $days) {
            $d = clone $base;
            $d->modify('+' . $days . ' days');
            $prefillDate = $d->format('Y-m-d');
            $prefillTime = $d->format('H:i');
            $nextSuggestions[] = [
                'days' => $days,
                'date' => $prefillDate,
                'time' => $prefillTime,
                'url' => '/hub/meetings/?prefill_title=' . rawurlencode((string)($meeting['title'] ?? '')) .
                    '&prefill_date=' . rawurlencode($prefillDate) .
                    '&prefill_time=' . rawurlencode($prefillTime) .
                    '&prefill_series_id=' . rawurlencode((string)$seriesId),
            ];
        }
    }
} catch (Throwable) {
    $nextSuggestions = [];
}

function mh_agenda_tenant_safe(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') $tenantId = 'user:unknown';
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') return $safe;
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}

function mh_agenda_meeting_root(string $tenantSafe, string $roomId): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
}

function mh_agenda_seed_items(PDO $db, int $meetingId, int $seriesId, string $user): array
{
    if ($seriesId < 1) {
        return [];
    }
    $prev = $db->prepare("SELECT id FROM mh_meetings WHERE series_id = ? AND created_by_user = ? AND id <> ? ORDER BY COALESCE(scheduled_for_utc, created_at_utc) DESC LIMIT 1");
    $prev->execute([$seriesId, $user, $meetingId]);
    $prevId = (int)$prev->fetchColumn();
    if ($prevId < 1) {
        return [];
    }
    $a = $db->prepare("SELECT agenda_json FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
    $a->execute([$prevId]);
    $raw = (string)$a->fetchColumn();
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return [];
    }
    $out = [];
    foreach ($decoded['items'] as $it) {
        if (!is_array($it)) continue;
        $status = isset($it['status']) ? (string)$it['status'] : 'open';
        if ($status !== 'open') continue;
        $out[] = [
            'id' => 'carry_' . bin2hex(random_bytes(6)),
            'type' => isset($it['type']) ? (string)$it['type'] : 'action',
            'title' => isset($it['title']) ? (string)$it['title'] : '',
            'status' => 'open',
            'notes' => isset($it['notes']) ? (string)$it['notes'] : '',
        ];
    }
    return $out;
}

function mh_agenda_default_items(): array
{
    $mk = fn(string $type, string $title) => ['id' => 'it_' . bin2hex(random_bytes(6)), 'type' => $type, 'title' => $title, 'status' => 'open', 'notes' => ''];
    return [
        $mk('info', 'Call to order'),
        $mk('info', 'Notice / waiver of notice'),
        $mk('info', 'Roll call / attendance'),
        $mk('decision', 'Confirm quorum'),
        $mk('decision', 'Approve prior minutes'),
        $mk('info', 'Reports (CEO/CFO/committees)'),
        $mk('info', 'Old business'),
        $mk('info', 'New business'),
        $mk('decision', 'Resolutions'),
        $mk('info', 'Adjournment'),
    ];
}

function mh_agenda_default_doc(string $meetingTitle, string $scheduledText, ?string $scheduledUtc): string
{
    $when = trim($scheduledText);
    if ($when === '' && is_string($scheduledUtc) && $scheduledUtc !== '') {
        $when = $scheduledUtc;
    }
    $out = '';
    $out .= "# Board of Directors Meeting Agenda\n\n";
    if (trim($meetingTitle) !== '') {
        $out .= "## Title\n" . trim($meetingTitle) . "\n\n";
    }
    $out .= "## Meeting Details\n";
    $out .= "- Corporation: \n";
    $out .= "- Date/Time: " . $when . "\n";
    $out .= "- Location / Teleconference: \n";
    $out .= "- Chair: \n";
    $out .= "- Secretary: \n\n";
    $out .= "## Attendance\n";
    $out .= "- Directors present: \n";
    $out .= "- Directors absent: \n";
    $out .= "- Officers / guests present: \n\n";
    $out .= "## Notice and Quorum\n";
    $out .= "- Notice given / waiver obtained: \n";
    $out .= "- Quorum present: \n\n";
    $out .= "## Agenda Items\n";
    $out .= "Agenda items are tracked in the Agenda Items table.\n";
    return $out;
}

$agendaRow = null;
$stmt = $db->prepare("SELECT id, agenda_json, minutes_md, created_at_utc, updated_at_utc FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
$stmt->execute([$id]);
$agendaRow = $stmt->fetch(PDO::FETCH_ASSOC);

$agenda = ['version' => 1, 'meeting_id' => $id, 'series_id' => $seriesId > 0 ? $seriesId : null, 'items' => []];
$minutesMd = '';
$docMd = '';
$delaware = [];

if (is_array($agendaRow)) {
    $raw = (string)($agendaRow['agenda_json'] ?? '');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $agenda = $decoded;
    }
    $minutesMd = (string)($agendaRow['minutes_md'] ?? '');
} else {
    $seed = mh_agenda_seed_items($db, $id, $seriesId, $user);
    $agenda['items'] = $seed !== [] ? $seed : mh_agenda_default_items();
    $agenda['document_md'] = mh_agenda_default_doc((string)($meeting['title'] ?? ''), (string)($meeting['scheduled_for_text'] ?? ''), isset($meeting['scheduled_for_utc']) ? (string)$meeting['scheduled_for_utc'] : null);
    $agenda['delaware'] = [
        'meeting_type' => 'board',
        'scheduled_text' => (string)($meeting['scheduled_for_text'] ?? ''),
        'scheduled_utc' => isset($meeting['scheduled_for_utc']) ? (string)$meeting['scheduled_for_utc'] : null,
        'corporation' => '',
        'location' => '',
        'chair' => '',
        'secretary' => '',
        'directors_present' => '',
        'directors_absent' => '',
        'officers_present' => '',
        'notice' => '',
        'quorum' => '',
    ];
    $stmt = $db->prepare("INSERT INTO mh_meeting_agendas (meeting_id, series_id, agenda_version, agenda_json, minutes_md, created_at_utc) VALUES (?, ?, 1, ?, NULL, UTC_TIMESTAMP())");
    $stmt->execute([$id, $seriesId > 0 ? $seriesId : null, json_encode($agenda, JSON_UNESCAPED_SLASHES)]);
}

$docMd = isset($agenda['document_md']) && is_string($agenda['document_md']) ? (string)$agenda['document_md'] : '';
$delaware = isset($agenda['delaware']) && is_array($agenda['delaware']) ? (array)$agenda['delaware'] : [];
if (trim($docMd) === '') {
    $docMd = mh_agenda_default_doc((string)($meeting['title'] ?? ''), (string)($meeting['scheduled_for_text'] ?? ''), isset($meeting['scheduled_for_utc']) ? (string)$meeting['scheduled_for_utc'] : null);
}

$serverSavedAtUtc = '';
if (is_array($agendaRow)) {
    $serverSavedAtUtc = (string)($agendaRow['updated_at_utc'] ?? '');
    if (trim($serverSavedAtUtc) === '') $serverSavedAtUtc = (string)($agendaRow['created_at_utc'] ?? '');
}

$voteSummaries = [];
try {
    $voteRows = [];
    $st = $db->prepare("SELECT id, title, status, weight_basis, options_json FROM mh_meeting_votes WHERE meeting_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    $voteRows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($voteRows)) $voteRows = [];
    $voteIds = [];
    foreach ($voteRows as $vr) {
        $vid = (int)($vr['id'] ?? 0);
        if ($vid > 0) $voteIds[] = $vid;
    }
    $ballotsByVote = [];
    if ($voteIds !== []) {
        $in = implode(',', array_fill(0, count($voteIds), '?'));
        $bst = $db->prepare("SELECT vote_id, choice, weight FROM mh_meeting_ballots WHERE vote_id IN ($in)");
        $bst->execute($voteIds);
        $ballots = $bst->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($ballots)) {
            foreach ($ballots as $b) {
                $vid = (int)($b['vote_id'] ?? 0);
                if ($vid < 1) continue;
                if (!isset($ballotsByVote[$vid])) $ballotsByVote[$vid] = [];
                $ballotsByVote[$vid][] = $b;
            }
        }
    }
    foreach ($voteRows as $vr) {
        $vid = (int)($vr['id'] ?? 0);
        if ($vid < 1) continue;
        $opts = json_decode((string)($vr['options_json'] ?? '[]'), true);
        if (!is_array($opts)) $opts = [];
        $tallies = [];
        foreach ($opts as $o) {
            $k = trim((string)$o);
            if ($k === '') continue;
            $tallies[$k] = 0;
        }
        $ballots = $ballotsByVote[$vid] ?? [];
        $count = 0;
        foreach ($ballots as $b) {
            $count++;
            $c = isset($b['choice']) ? (string)$b['choice'] : '';
            $w = max(1, (int)($b['weight'] ?? 1));
            if (!isset($tallies[$c])) $tallies[$c] = 0;
            $tallies[$c] += $w;
        }
        arsort($tallies);
        $leader = '';
        $leaderWeight = 0;
        foreach ($tallies as $k => $w) {
            $leader = (string)$k;
            $leaderWeight = (int)$w;
            break;
        }
        $voteSummaries[$vid] = [
            'id' => $vid,
            'title' => (string)($vr['title'] ?? ''),
            'status' => (string)($vr['status'] ?? 'open'),
            'ballots' => $count,
            'leader' => $leader,
            'leader_weight' => $leaderWeight,
        ];
    }
} catch (Throwable) {
    $voteSummaries = [];
}

$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $isAutosave = isset($_POST['autosave']) && (string)$_POST['autosave'] === '1';
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $err = 'Invalid request';
        if ($isAutosave) {
            mh_agenda_json_exit(403, ['ok' => false, 'error' => 'csrf']);
        }
    } else {
        if (!$isOwner) {
            $err = 'Only the meeting owner can edit the agenda.';
            if ($isAutosave) {
                mh_agenda_json_exit(403, ['ok' => false, 'error' => 'owner_only']);
            }
        }
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'delete_agenda') {
            try {
                $stmt = $db->prepare("DELETE FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
                $stmt->execute([$id]);
            } catch (Throwable) {
            }
            try {
                $tenantSafe = mh_agenda_tenant_safe($tenantId);
                $root = mh_agenda_meeting_root($tenantSafe, $roomId);
                @unlink($root . '/agenda/agenda.json');
                @unlink($root . '/minutes/minutes.md');
            } catch (Throwable) {
            }
            header('Location: /hub/meetings/agendas.php', true, 302);
            exit;
        }
        $itemsRaw = isset($_POST['items_json']) ? (string)$_POST['items_json'] : '';
        $minutesMd = isset($_POST['minutes_md']) ? (string)$_POST['minutes_md'] : '';
        $docMd = isset($_POST['document_md']) ? (string)$_POST['document_md'] : '';
        $postedSeriesId = isset($_POST['series_id']) ? (int)$_POST['series_id'] : $seriesId;
        if ($postedSeriesId < 1) $postedSeriesId = 0;
        if ($postedSeriesId > 0) {
            try {
                $chk = $db->prepare("SELECT id FROM mh_meeting_series WHERE id = ? LIMIT 1");
                $chk->execute([$postedSeriesId]);
                $ok = (int)$chk->fetchColumn();
                if ($ok < 1) $postedSeriesId = 0;
            } catch (Throwable) {
                $postedSeriesId = 0;
            }
        }
        $decoded = $itemsRaw !== '' ? json_decode($itemsRaw, true) : null;
        if (!is_array($decoded)) {
            $err = 'Invalid items payload';
            if ($isAutosave) {
                mh_agenda_json_exit(400, ['ok' => false, 'error' => 'invalid_items']);
            }
        } else {
            $items = [];
            foreach ($decoded as $it) {
                if (!is_array($it)) continue;
                $voteId = isset($it['vote_id']) ? (int)$it['vote_id'] : 0;
                $items[] = [
                    'id' => isset($it['id']) ? (string)$it['id'] : ('it_' . bin2hex(random_bytes(6))),
                    'type' => isset($it['type']) ? (string)$it['type'] : 'action',
                    'title' => isset($it['title']) ? (string)$it['title'] : '',
                    'status' => isset($it['status']) ? (string)$it['status'] : 'open',
                    'notes' => isset($it['notes']) ? (string)$it['notes'] : '',
                    'vote_id' => $voteId > 0 ? $voteId : null,
                ];
            }
            $delawarePayload = [
                'meeting_type' => 'board',
                'scheduled_text' => (string)($meeting['scheduled_for_text'] ?? ''),
                'scheduled_utc' => isset($meeting['scheduled_for_utc']) ? (string)$meeting['scheduled_for_utc'] : null,
                'corporation' => isset($_POST['corp_name']) ? trim((string)$_POST['corp_name']) : '',
                'location' => isset($_POST['meeting_location']) ? trim((string)$_POST['meeting_location']) : '',
                'chair' => isset($_POST['chair']) ? trim((string)$_POST['chair']) : '',
                'secretary' => isset($_POST['secretary']) ? trim((string)$_POST['secretary']) : '',
                'directors_present' => isset($_POST['directors_present']) ? trim((string)$_POST['directors_present']) : '',
                'directors_absent' => isset($_POST['directors_absent']) ? trim((string)$_POST['directors_absent']) : '',
                'officers_present' => isset($_POST['officers_present']) ? trim((string)$_POST['officers_present']) : '',
                'notice' => isset($_POST['notice']) ? trim((string)$_POST['notice']) : '',
                'quorum' => isset($_POST['quorum']) ? trim((string)$_POST['quorum']) : '',
            ];

            if ($action === 'save_template') {
                $tplName = isset($_POST['template_name']) ? trim((string)$_POST['template_name']) : '';
                if ($tplName === '') {
                    $err = 'Template name is required';
                    if ($isAutosave) {
                        mh_agenda_json_exit(400, ['ok' => false, 'error' => 'missing_template_name']);
                    }
                } else {
                    if (strlen($tplName) > 255) $tplName = substr($tplName, 0, 255);
                    $tpl = [
                        'version' => 1,
                        'series_id' => $postedSeriesId > 0 ? $postedSeriesId : null,
                        'items' => $items,
                        'document_md' => $docMd,
                        'delaware' => $delawarePayload,
                    ];
                    $ins = $db->prepare("
                        INSERT INTO mh_meeting_agenda_templates (name, template_json, created_by_user, created_at_utc, updated_at_utc)
                        VALUES (:n, :j, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                        ON DUPLICATE KEY UPDATE
                            template_json = VALUES(template_json),
                            updated_at_utc = UTC_TIMESTAMP(),
                            created_by_user = VALUES(created_by_user)
                    ");
                    $ins->execute([
                        ':n' => $tplName,
                        ':j' => json_encode($tpl, JSON_UNESCAPED_SLASHES),
                        ':u' => $user,
                    ]);

                    try {
                        $tenantSafe = mh_agenda_tenant_safe($tenantId);
                        $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
                        $base = $base !== '' ? rtrim($base, '/') : '/data';
                        $dir = $base . '/tenants/' . $tenantSafe . '/agenda_templates';
                        @mkdir($dir, 0775, true);
                        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $tplName);
                        $safe = trim((string)$safe, '._-');
                        if ($safe === '') $safe = 'template_' . bin2hex(random_bytes(4));
                        @file_put_contents($dir . '/' . $safe . '.json', json_encode($tpl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                    } catch (Throwable) {
                    }

                    try {
                        $st = $db->prepare("SELECT id, name, template_json, created_by_user, created_at_utc, updated_at_utc FROM mh_meeting_agenda_templates ORDER BY id DESC LIMIT 200");
                        $st->execute();
                        $agendaTemplates = $st->fetchAll(PDO::FETCH_ASSOC);
                        if (!is_array($agendaTemplates)) $agendaTemplates = [];
                    } catch (Throwable) {
                        $agendaTemplates = [];
                    }
                    $msg = 'Template saved';
                    if ($isAutosave) {
                        mh_agenda_json_exit(200, ['ok' => true, 'saved' => 'template']);
                    }
                }
            } else {
                // Create votes for agenda items marked as vote
                $createdVoteSummaries = [];
                try {
                    foreach ($items as &$it) {
                        if (!is_array($it) || (string)($it['type'] ?? '') !== 'vote') continue;
                        $voteId = isset($it['vote_id']) ? (int)$it['vote_id'] : 0;
                        $validExisting = false;
                        if ($voteId > 0) {
                            $chk = $db->prepare("SELECT id FROM mh_meeting_votes WHERE id = ? AND meeting_id = ? LIMIT 1");
                            $chk->execute([$voteId, $id]);
                            $validExisting = (int)$chk->fetchColumn() > 0;
                        }
                        if ($validExisting) continue;
                        $vTitle = trim((string)($it['title'] ?? ''));
                        if ($vTitle === '') $vTitle = 'Vote';
                        $opts = json_encode(['Yes', 'No', 'Abstain'], JSON_UNESCAPED_SLASHES);
                        $ins = $db->prepare("
                            INSERT INTO mh_meeting_votes (meeting_id, series_id, title, kind, weight_basis, options_json, status, equity_snapshot_json, created_by_user, created_at_utc)
                            VALUES (:m, :s, :t, 'poll', 'shares', :o, 'open', NULL, :u, UTC_TIMESTAMP())
                        ");
                        $ins->execute([
                            ':m' => $id,
                            ':s' => $postedSeriesId > 0 ? $postedSeriesId : null,
                            ':t' => $vTitle,
                            ':o' => $opts,
                            ':u' => $user,
                        ]);
                        $newId = (int)$db->lastInsertId();
                        if ($newId > 0) {
                            $it['vote_id'] = $newId;
                            $createdVoteSummaries[$newId] = [
                                'id' => $newId,
                                'title' => $vTitle,
                                'status' => 'open',
                                'ballots' => 0,
                                'leader' => '',
                                'leader_weight' => 0,
                            ];
                        }
                    }
                    unset($it);
                } catch (Throwable) {
                }

                $agenda['items'] = $items;
                $agenda['updated_at_utc'] = gmdate('c');
                $agenda['document_md'] = $docMd;
                $agenda['delaware'] = $delawarePayload;
                $agenda['series_id'] = $postedSeriesId > 0 ? $postedSeriesId : null;

                if ($postedSeriesId !== $seriesId) {
                    $u1 = $db->prepare("UPDATE mh_meetings SET series_id = ? WHERE id = ? AND created_by_user = ? LIMIT 1");
                    $u1->execute([$postedSeriesId > 0 ? $postedSeriesId : null, $id, $user]);
                    $u2 = $db->prepare("UPDATE mh_meeting_agendas SET series_id = ? WHERE meeting_id = ? LIMIT 1");
                    $u2->execute([$postedSeriesId > 0 ? $postedSeriesId : null, $id]);
                    $seriesId = $postedSeriesId;
                }

                $stmt = $db->prepare("UPDATE mh_meeting_agendas SET agenda_json = ?, minutes_md = ?, updated_at_utc = UTC_TIMESTAMP() WHERE meeting_id = ?");
                $stmt->execute([json_encode($agenda, JSON_UNESCAPED_SLASHES), $minutesMd, $id]);

                try {
                    $tenantSafe = mh_agenda_tenant_safe($tenantId);
                    $root = mh_agenda_meeting_root($tenantSafe, $roomId);
                    @mkdir($root . '/agenda', 0775, true);
                    @mkdir($root . '/minutes', 0775, true);
                    @file_put_contents($root . '/agenda/agenda.json', json_encode($agenda, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                    @file_put_contents($root . '/minutes/minutes.md', $minutesMd . "\n");
                } catch (Throwable) {
                }

                $msg = 'Saved';
                if ($isAutosave) {
                    $merged = $voteSummaries;
                    foreach ($createdVoteSummaries as $k => $v) {
                        $merged[(int)$k] = $v;
                    }
                    mh_agenda_json_exit(200, ['ok' => true, 'saved' => 'agenda', 'items' => $agenda['items'], 'vote_summaries' => $merged]);
                }
            }
        }
    }
}

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agenda</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
  <script src="/templates/widgets/notices/popup-notice.js"></script>
  <script src="/gear/editors/TinyMCE/tinymce.min.js"></script>
  <style>
    body.hub-agenda main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .title{margin:0 0 10px 0;font-size:22px}
    .muted{color:rgba(255,255,255,.7);font-size:12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12);cursor:pointer}
    .btn.primary{border-color:rgba(0,212,255,.35);background:rgba(0,212,255,.16);color:#d7fbff}
    .input,select,textarea{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:rgba(255,255,255,.92)}
    textarea{min-height:110px;resize:vertical}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px;vertical-align:top}
    th{color:rgba(255,255,255,.75);font-weight:800}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .panel{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.16);padding:12px}
    .label{display:block;margin:0 0 6px 0;font-weight:900;font-size:12px;color:rgba(255,255,255,.82)}
    .mh-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:stretch;justify-content:center;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);padding:18px}
    .mh-modal.open{display:flex}
    .mh-modal .mh-modal-card{width:min(1100px, 100%);height:min(92vh, 100%);border-radius:16px;border:1px solid rgba(255,255,255,.14);background:rgba(10,15,24,.96);display:flex;flex-direction:column;overflow:hidden}
    .mh-modal .mh-modal-head{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.10)}
    .mh-modal .mh-modal-title{font-weight:950}
    .mh-modal iframe{flex:1;width:100%;border:0;background:transparent}
    .mce-tinymce{border-radius:14px!important;overflow:hidden;border:1px solid rgba(255,255,255,.12)!important}
    .mce-container-body,.mce-panel{background:rgba(0,0,0,.18)!important;border-color:rgba(255,255,255,.10)!important}
    .mce-toolbar .mce-btn button,.mce-toolbar .mce-btn .mce-ico,.mce-menubar .mce-menubtn button,.mce-menubar .mce-menubtn button span{color:rgba(255,255,255,.92)!important}
    .mce-btn{background:transparent!important}
    .mce-btn:hover,.mce-btn.mce-active,.mce-btn.mce-active:hover{background:rgba(255,255,255,.08)!important}
    .mce-path-item{color:rgba(255,255,255,.55)!important}
  </style>
</head>
<body class="hub-agenda">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="card">
    <div class="row">
      <div>
        <h1 class="title">Agenda</h1>
        <div class="muted"><?php echo htmlspecialchars((string)($meeting['title'] ?? ''), ENT_QUOTES); ?> · Room: <?php echo htmlspecialchars($roomId, ENT_QUOTES); ?></div>
        <div class="muted"><?php echo htmlspecialchars((string)($meeting['scheduled_for_text'] ?? $meeting['created_at_utc'] ?? ''), ENT_QUOTES); ?></div>
      </div>
      <div class="row">
        <a class="btn" href="/hub/meetings/">Back</a>
        <a class="btn" href="/meet.php?room_id=<?php echo rawurlencode($roomId); ?>&role=<?php echo $isOwner ? 'presenter' : 'viewer'; ?>">Join</a>
      </div>
    </div>

    <?php if ($seriesId > 0): ?>
      <div class="panel" style="margin-top:14px">
        <div class="row">
          <div class="muted" style="font-weight:900">Series</div>
          <div class="row" style="justify-content:flex-end">
            <a class="btn" href="/hub/meetings/?tab=meetings&series_id=<?php echo (int)$seriesId; ?>">View series meetings</a>
          </div>
        </div>
        <div class="grid" style="margin-top:10px">
          <div>
            <div class="muted" style="font-weight:900;margin-bottom:6px">Previous meetings</div>
            <?php if ($seriesMeetings === []): ?>
              <div class="muted">No series meetings found.</div>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:6px">
                <?php foreach ($seriesMeetings as $m): ?>
                  <?php
                    $mid = (int)($m['id'] ?? 0);
                    $t = (string)($m['title'] ?? '');
                    $when = (string)($m['scheduled_for_text'] ?? '');
                    if ($when === '') $when = (string)($m['created_at_utc'] ?? '');
                  ?>
                  <div class="row" style="justify-content:space-between">
                    <div>
                      <div style="font-weight:900"><?php echo htmlspecialchars($t !== '' ? $t : ('Meeting #' . $mid), ENT_QUOTES); ?></div>
                      <div class="muted"><?php echo htmlspecialchars($when, ENT_QUOTES); ?></div>
                    </div>
                    <div class="row" style="justify-content:flex-end">
                      <a class="btn" href="/hub/meetings/agenda.php?id=<?php echo (int)$mid; ?>">Agenda</a>
                      <a class="btn" href="/hub/meetings/vote.php?id=<?php echo (int)$mid; ?>">Votes</a>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div>
            <div class="muted" style="font-weight:900;margin-bottom:6px">Next meeting suggestions</div>
            <?php if ($nextSuggestions === []): ?>
              <div class="muted">No suggestions available.</div>
            <?php else: ?>
              <div style="display:flex;flex-direction:column;gap:8px">
                <?php foreach ($nextSuggestions as $s): ?>
                  <a class="btn" href="<?php echo htmlspecialchars((string)$s['url'], ENT_QUOTES); ?>">Schedule +<?php echo (int)$s['days']; ?> days (<?php echo htmlspecialchars((string)$s['date'] . ' ' . (string)$s['time'] . ' UTC', ENT_QUOTES); ?>)</a>
                <?php endforeach; ?>
              </div>
              <div class="muted" style="margin-top:8px">Links open the Meetings scheduler with date/time/series prefilled.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($msg !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(180,255,210,.95)"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(255,180,180,.95)"><?php echo htmlspecialchars($err, ENT_QUOTES); ?></div><?php endif; ?>

    <form method="post" onsubmit="return mhAgendaSubmit(this);">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
      <input type="hidden" name="items_json" id="items_json">

      <div style="margin-top:14px" class="panel">
        <div class="row" style="justify-content:space-between">
          <div class="muted" style="font-weight:900">Participants & Access</div>
        </div>
        <div style="margin-top:10px">
          <div id="attendee_pills" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px"></div>
          <div class="muted">Add participants by username or name in the fields above. They will be granted access to this agenda and receive reminders.</div>
        </div>
      </div>

      <div style="margin-top:14px" class="panel">
        <div class="row" style="justify-content:space-between">
          <div class="muted" style="font-weight:900">Delaware-style agenda fields</div>
        </div>
        <div class="grid" style="margin-top:10px">
          <div>
            <label class="label" for="series_id">Series</label>
            <select class="input" id="series_id" name="series_id">
              <option value="0"<?php echo $seriesId < 1 ? ' selected' : ''; ?>>None</option>
              <?php foreach ($seriesList as $s): ?>
                <?php $sid = (int)($s['id'] ?? 0); $sname = (string)($s['name'] ?? ''); ?>
                <option value="<?php echo (int)$sid; ?>"<?php echo $seriesId === $sid ? ' selected' : ''; ?>><?php echo htmlspecialchars($sname, ENT_QUOTES); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="corp_name">Corporation</label>
            <input class="input" id="corp_name" name="corp_name" value="<?php echo htmlspecialchars((string)($delaware['corporation'] ?? ''), ENT_QUOTES); ?>" placeholder="e.g. Meta Humans, Inc.">
          </div>
          <div>
            <label class="label" for="meeting_location">Location / Teleconference</label>
            <input class="input" id="meeting_location" name="meeting_location" value="<?php echo htmlspecialchars((string)($delaware['location'] ?? ''), ENT_QUOTES); ?>" placeholder="e.g. Zoom / Board room">
          </div>
          <div>
            <label class="label" for="chair">Chair</label>
            <div class="row" style="justify-content:flex-start;gap:6px">
              <input class="input" id="chair" name="chair" value="<?php echo htmlspecialchars((string)($delaware['chair'] ?? ''), ENT_QUOTES); ?>" placeholder="Name" list="user_list_chair">
              <datalist id="user_list_chair"></datalist>
            </div>
          </div>
          <div>
            <label class="label" for="secretary">Secretary</label>
            <div class="row" style="justify-content:flex-start;gap:6px">
              <input class="input" id="secretary" name="secretary" value="<?php echo htmlspecialchars((string)($delaware['secretary'] ?? ''), ENT_QUOTES); ?>" placeholder="Name" list="user_list_secretary">
              <datalist id="user_list_secretary"></datalist>
            </div>
          </div>
          <div>
            <label class="label" for="directors_present">Directors present</label>
            <div class="row" style="justify-content:flex-start;gap:6px">
              <input class="input" id="directors_present" name="directors_present" value="<?php echo htmlspecialchars((string)($delaware['directors_present'] ?? ''), ENT_QUOTES); ?>" placeholder="Comma-separated names" list="user_list_directors">
              <datalist id="user_list_directors"></datalist>
            </div>
          </div>
          <div>
            <label class="label" for="directors_absent">Directors absent</label>
            <div class="row" style="justify-content:flex-start;gap:6px">
              <input class="input" id="directors_absent" name="directors_absent" value="<?php echo htmlspecialchars((string)($delaware['directors_absent'] ?? ''), ENT_QUOTES); ?>" placeholder="Comma-separated names" list="user_list_directors_absent">
              <datalist id="user_list_directors_absent"></datalist>
            </div>
          </div>
          <div>
            <label class="label" for="officers_present">Officers / guests present</label>
            <div class="row" style="justify-content:flex-start;gap:6px">
              <input class="input" id="officers_present" name="officers_present" value="<?php echo htmlspecialchars((string)($delaware['officers_present'] ?? ''), ENT_QUOTES); ?>" placeholder="Comma-separated names" list="user_list_officers">
              <datalist id="user_list_officers"></datalist>
            </div>
          </div>
          <div>
            <label class="label" for="notice">Notice / waiver</label>
            <input class="input" id="notice" name="notice" value="<?php echo htmlspecialchars((string)($delaware['notice'] ?? ''), ENT_QUOTES); ?>" placeholder="e.g. Notice given / waiver signed">
          </div>
          <div>
            <label class="label" for="quorum">Quorum</label>
            <input class="input" id="quorum" name="quorum" value="<?php echo htmlspecialchars((string)($delaware['quorum'] ?? ''), ENT_QUOTES); ?>" placeholder="e.g. Quorum present">
          </div>
        </div>
      </div>

      <div style="margin-top:14px" class="panel">
        <div class="row" style="justify-content:space-between">
          <div class="muted" style="font-weight:900">Agenda templates</div>
          <div class="row" style="justify-content:flex-end">
            <button class="btn" type="button" onclick="mhAgendaInsertSelectedTemplate()">Insert selected</button>
          </div>
        </div>
        <div class="grid" style="margin-top:10px">
          <div>
            <label class="label" for="agenda_template_select">Saved templates</label>
            <select class="input" id="agenda_template_select">
              <option value="">Select…</option>
              <?php foreach ($agendaTemplates as $t): ?>
                <?php $tid = (int)($t['id'] ?? 0); $tname = (string)($t['name'] ?? ''); $towner = (string)($t['created_by_user'] ?? ''); ?>
                <option value="<?php echo (int)$tid; ?>"><?php echo htmlspecialchars($tname . ($towner !== '' ? (' · ' . $towner) : ''), ENT_QUOTES); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label" for="template_name">Save as default</label>
            <div class="row" style="justify-content:flex-start">
              <input class="input" id="template_name" name="template_name" placeholder="Template name (e.g. Board default)" style="flex:1;min-width:240px">
              <button class="btn primary" type="submit" name="action" value="save_template">Save template</button>
            </div>
          </div>
        </div>
      </div>

      <div style="margin-top:14px" class="row">
        <div class="muted">Agenda items</div>
        <div class="row" style="justify-content:flex-end">
          <a class="btn" href="/hub/meetings/agendas.php">Agendas</a>
          <button class="btn primary" type="button" onclick="mhAgendaAdd()">Add item</button>
        </div>
      </div>

      <table id="agendaTable">
        <thead>
          <tr>
            <th style="width:140px">Type</th>
            <th>Title</th>
            <th style="width:140px">Status</th>
            <th>Notes</th>
            <th style="width:90px"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <div style="margin-top:14px" class="panel">
        <div class="row" style="justify-content:space-between">
          <div class="muted" style="font-weight:900">Agenda document (Markdown)</div>
        </div>
        <textarea class="input" name="document_md" id="document_md" style="min-height:260px"><?php echo htmlspecialchars($docMd, ENT_QUOTES); ?></textarea>
      </div>

      <div style="margin-top:18px" class="muted">Minutes (Markdown)</div>
      <textarea class="input" name="minutes_md" id="minutes_md"><?php echo htmlspecialchars($minutesMd, ENT_QUOTES); ?></textarea>

      <div style="margin-top:14px;display:flex;justify-content:flex-end;">
        <button class="btn primary" type="submit">Save</button>
      </div>
    </form>
    <form method="post" style="margin-top:10px;display:flex;justify-content:flex-end" onsubmit="return confirm('Delete this agenda?');">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
      <input type="hidden" name="action" value="delete_agenda">
      <button class="btn" style="border-color:rgba(255,80,80,.35);color:rgba(255,180,180,.95)" type="submit">Delete agenda</button>
    </form>
  </div>
</main>
<div class="mh-modal" id="mhVoteModal" onclick="if(event.target===this) mhCloseVoteModal()">
  <div class="mh-modal-card">
    <div class="mh-modal-head">
      <div class="mh-modal-title" id="mhVoteModalTitle">Vote</div>
      <div class="row" style="justify-content:flex-end">
        <button class="btn" type="button" onclick="mhCloseVoteModal()">Back to agenda</button>
      </div>
    </div>
    <iframe id="mhVoteFrame" src="about:blank" title="Vote"></iframe>
  </div>
</div>
<?php
  $autosaveWidget = $templates . '/widgets/autosave/autosave.php';
  if (is_file($autosaveWidget)) {
      require_once $autosaveWidget;
      if (function_exists('includeAutosaveWidget')) {
          includeAutosaveWidget(['interval' => 8000, 'eventName' => 'mh_agenda_autosave']);
      }
  }
?>
<script>
let agendaItems = <?php echo json_encode(is_array($agenda['items'] ?? null) ? $agenda['items'] : []); ?>;
const MH_SERVER_SAVED_AT_UTC = <?php echo json_encode((string)$serverSavedAtUtc); ?>;
let mhVoteSummaries = <?php echo json_encode($voteSummaries, JSON_UNESCAPED_SLASHES); ?>;
const AGENDA_TEMPLATES = <?php
  $tplOut = [];
  foreach ($agendaTemplates as $t) {
      $payload = null;
      $raw = isset($t['template_json']) ? (string)$t['template_json'] : '';
      $decoded = $raw !== '' ? json_decode($raw, true) : null;
      if (is_array($decoded)) $payload = $decoded;
      $tplOut[] = [
          'id' => (int)($t['id'] ?? 0),
          'name' => (string)($t['name'] ?? ''),
          'payload' => $payload,
      ];
  }
  echo json_encode($tplOut, JSON_UNESCAPED_SLASHES);
?>;
let mhDirty = false;
let mhAutosaveInflight = false;
let mhLastAutosaveToastAt = 0;

function mhNotice(type, message){
  try{
    if(window.PopupNotice && !window.globalPopupNotice){
      window.globalPopupNotice = new PopupNotice({position:'top-right', theme:'modern', duration:3800});
    }
    if(window.globalPopupNotice){
      const fn = window.globalPopupNotice[type] || window.globalPopupNotice.info;
      fn.call(window.globalPopupNotice, String(message||''));
      return;
    }
  }catch(e){}
}

const MH_API_CSRF = <?php echo json_encode((string)$csrf); ?>;
let mhPendingVoteOpenByItemId = {};
let mhAgendaScrollY = 0;

function mhOpenVoteModal(voteId, title){
  try{
    const modal = document.getElementById('mhVoteModal');
    const frame = document.getElementById('mhVoteFrame');
    const ttl = document.getElementById('mhVoteModalTitle');
    if(!modal || !frame) return;
    mhAgendaScrollY = window.scrollY || 0;
    if(ttl) ttl.textContent = title ? String(title) : 'Vote';
    frame.src = '/hub/meetings/vote.php?id=<?php echo (int)$id; ?>#vote_' + encodeURIComponent(String(voteId));
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }catch(e){}
}

function mhCloseVoteModal(){
  try{
    const modal = document.getElementById('mhVoteModal');
    const frame = document.getElementById('mhVoteFrame');
    if(frame) frame.src = 'about:blank';
    if(modal) modal.classList.remove('open');
    document.body.style.overflow = '';
    window.scrollTo(0, mhAgendaScrollY || 0);
  }catch(e){}
}

window.addEventListener('keydown', (e) => {
  if(e.key === 'Escape'){
    const modal = document.getElementById('mhVoteModal');
    if(modal && modal.classList.contains('open')){
      mhCloseVoteModal();
    }
  }
});

function mhMarkDirty(){
  mhDirty = true;
  try{
    const key = 'mh_agenda_draft_' + <?php echo json_encode((string)$roomId); ?>;
    const docEd = window.tinymce && tinymce.get('document_md') ? tinymce.get('document_md') : null;
    const minEd = window.tinymce && tinymce.get('minutes_md') ? tinymce.get('minutes_md') : null;
    const draft = {
      ts: Date.now(),
      series_id: document.getElementById('series_id')?.value||'0',
      items: mhCollectItems(),
      document_md: docEd ? docEd.getContent({format:'html'}) : (document.getElementById('document_md')?.value||''),
      minutes_md: minEd ? minEd.getContent({format:'html'}) : (document.getElementById('minutes_md')?.value||''),
      delaware: {
        corporation: document.getElementById('corp_name')?.value||'',
        location: document.getElementById('meeting_location')?.value||'',
        chair: document.getElementById('chair')?.value||'',
        secretary: document.getElementById('secretary')?.value||'',
        directors_present: document.getElementById('directors_present')?.value||'',
        directors_absent: document.getElementById('directors_absent')?.value||'',
        officers_present: document.getElementById('officers_present')?.value||'',
        notice: document.getElementById('notice')?.value||'',
        quorum: document.getElementById('quorum')?.value||'',
      },
      attendees: mhAttendeesList || []
    };
    localStorage.setItem(key, JSON.stringify(draft));
  }catch(e){}
}

function mhRenderAttendeePills(){
  const container = document.getElementById('attendee_pills');
  if(!container) return;
  container.innerHTML = '';
  mhAttendeesList.forEach(a => {
    const pill = document.createElement('div');
    pill.className = 'btn';
    pill.style.padding = '4px 8px';
    pill.style.borderRadius = '16px';
    pill.style.fontSize = '11px';
    pill.style.gap = '6px';
    pill.innerHTML = `<span>${a.name_display || a.username} <small>(${a.role})</small></span> <span style="cursor:pointer;opacity:0.6">&times;</span>`;
    pill.querySelector('span:last-child').onclick = () => {
      mhAttendeesList = mhAttendeesList.filter(x => x.username !== a.username);
      mhAttendeesChanged = true;
      mhMarkDirty();
      mhRenderAttendeePills();
    };
    container.appendChild(pill);
  });
}

function mhTryRestoreDraft(){
  try{
    const key = 'mh_agenda_draft_' + <?php echo json_encode((string)$roomId); ?>;
    const raw = localStorage.getItem(key);
    if(!raw) return;
    const draft = JSON.parse(raw);
    if(!draft || typeof draft !== 'object') return;
    const draftTs = Number(draft.ts||0) || 0;
    const serverTs = Date.parse(String(MH_SERVER_SAVED_AT_UTC||'')) || 0;
    const serverEmpty = (agendaItems||[]).length===0 && ((document.getElementById('minutes_md')?.value||'').trim()==='' );
    if(!(serverEmpty || draftTs > serverTs + 5000)) return;

    const ok = confirm('Restore last draft from your browser?');
    if(!ok) return;

    if(draft.series_id !== undefined){
      const s = document.getElementById('series_id'); if(s) s.value = String(draft.series_id||'0');
    }
    if(Array.isArray(draft.items)){
      agendaItems = draft.items;
      mhAgendaRender();
    }
    if(draft.delaware){
      const d = draft.delaware;
      const setVal = (id,val)=>{ const el=document.getElementById(id); if(el) el.value = (val===null||val===undefined)?'':String(val); };
      setVal('corp_name', d.corporation || '');
      setVal('meeting_location', d.location || '');
      setVal('chair', d.chair || '');
      setVal('secretary', d.secretary || '');
      setVal('directors_present', d.directors_present || '');
      setVal('directors_absent', d.directors_absent || '');
      setVal('officers_present', d.officers_present || '');
      setVal('notice', d.notice || '');
      setVal('quorum', d.quorum || '');
    }
    if(Array.isArray(draft.attendees)){
      mhAttendeesList = draft.attendees;
      mhAttendeesChanged = true;
      mhRenderAttendeePills();
    }

    const applyEditors = () => {
      const docEd = window.tinymce && tinymce.get('document_md') ? tinymce.get('document_md') : null;
      const minEd = window.tinymce && tinymce.get('minutes_md') ? tinymce.get('minutes_md') : null;
      if(docEd) docEd.setContent(String(draft.document_md||'')); else { const ta=document.getElementById('document_md'); if(ta) ta.value=String(draft.document_md||''); }
      if(minEd) minEd.setContent(String(draft.minutes_md||'')); else { const ta=document.getElementById('minutes_md'); if(ta) ta.value=String(draft.minutes_md||''); }
      mhMarkDirty();
      mhNotice('info','Draft restored');
      return true;
    };

    let tries = 0;
    const t = setInterval(() => {
      tries++;
      try{
        if(applyEditors() || tries > 25){
          clearInterval(t);
        }
      }catch(e){}
    }, 200);
  }catch(e){}
}

function mhAgendaRow(it){
  const tr=document.createElement('tr');
  tr.dataset.id = it.id || ('it_' + Math.random().toString(16).slice(2));
  tr.dataset.voteId = (it.vote_id!==null && it.vote_id!==undefined) ? String(it.vote_id) : '';
  tr.innerHTML = `
    <td>
      <select>
        <option value="info">info</option>
        <option value="action">action</option>
        <option value="decision">decision</option>
        <option value="vote">vote</option>
      </select>
    </td>
    <td>
      <div style="display:flex;flex-direction:column;gap:6px">
        <input class="input" placeholder="Title">
        <div class="muted" style="display:none"></div>
      </div>
    </td>
    <td>
      <select>
        <option value="open">open</option>
        <option value="resolved">resolved</option>
        <option value="approved">approved</option>
      </select>
    </td>
    <td><textarea class="input" placeholder="Notes / outcomes"></textarea></td>
    <td><button class="btn" type="button">Remove</button></td>
  `;
  const selects = tr.querySelectorAll('select');
  const title = tr.querySelector('input');
  const notes = tr.querySelector('textarea');
  const meta = tr.querySelector('.muted');
  selects[0].value = it.type || 'action';
  selects[1].value = it.status || 'open';
  title.value = it.title || '';
  notes.value = it.notes || '';
  const voteId = Number(tr.dataset.voteId||0);
  if(selects[0].value === 'vote' && voteId > 0){
    const sum = mhVoteSummaries && mhVoteSummaries[String(voteId)] ? mhVoteSummaries[String(voteId)] : null;
    const s = sum ? (`${sum.status} · ballots: ${sum.ballots}` + (sum.leader ? (` · leader: ${sum.leader} (${sum.leader_weight})`) : '')) : `vote #${voteId}`;
    meta.style.display = 'block';
    meta.innerHTML = `<button class="btn" type="button" style="padding:4px 8px;border-radius:999px" data-open-vote="${voteId}">Open vote</button> <button class="btn" type="button" style="padding:4px 8px;border-radius:999px;opacity:.85" data-unlink-vote="${voteId}">Remove vote</button> <span style="margin-left:8px">${s}</span>`;
    const btn = meta.querySelector('button[data-open-vote]');
    if(btn){
      btn.onclick = () => {
        const title = (sum && sum.title) ? sum.title : (title.value || 'Vote');
        mhOpenVoteModal(voteId, title);
      };
    }
    const ub = meta.querySelector('button[data-unlink-vote]');
    if(ub){
      ub.onclick = () => {
        tr.dataset.voteId = '';
        meta.style.display = 'none';
        meta.textContent = '';
        if(selects[0].value === 'vote') selects[0].value = 'action';
        mhMarkDirty();
      };
    }
  }
  tr.querySelector('button').addEventListener('click', () => { tr.remove(); mhMarkDirty(); });
  selects[0].addEventListener('change', () => {
    mhMarkDirty();
    if(selects[0].value === 'vote'){
      const curVoteId = Number(tr.dataset.voteId||0);
      if(curVoteId > 0){
        const sum = mhVoteSummaries && mhVoteSummaries[String(curVoteId)] ? mhVoteSummaries[String(curVoteId)] : null;
        const t = sum && sum.title ? sum.title : (title.value || 'Vote');
        mhOpenVoteModal(curVoteId, t);
        return;
      }
      mhPendingVoteOpenByItemId[tr.dataset.id] = true;
      mhNotice('info','Creating vote…');
      setTimeout(() => { try{ mhAutosave(); }catch(e){} }, 250);
    } else {
      mhPendingVoteOpenByItemId[tr.dataset.id] = false;
      tr.dataset.voteId = '';
      meta.style.display = 'none';
      meta.textContent = '';
    }
  });
  selects[1].addEventListener('change', mhMarkDirty);
  title.addEventListener('input', mhMarkDirty);
  notes.addEventListener('input', mhMarkDirty);
  return tr;
}

function mhAgendaRender(){
  const tbody=document.querySelector('#agendaTable tbody');
  tbody.innerHTML='';
  for(const it of agendaItems){
    tbody.appendChild(mhAgendaRow(it));
  }
}

function mhAgendaAdd(){
  const tbody=document.querySelector('#agendaTable tbody');
  tbody.appendChild(mhAgendaRow({id:'it_'+Math.random().toString(16).slice(2), type:'action', status:'open', title:'', notes:''}));
  mhMarkDirty();
}

async function mhAgendaSubmit(form){
  const rows=[...document.querySelectorAll('#agendaTable tbody tr')];
  const items=rows.map(tr=>{
    const id=tr.dataset.id||'';
    const type=tr.querySelectorAll('select')[0].value;
    const status=tr.querySelectorAll('select')[1].value;
    const title=tr.querySelector('input').value;
    const notes=tr.querySelector('textarea').value;
    return {id,type,status,title,notes};
  });
  document.getElementById('items_json').value = JSON.stringify(items);

  if (mhAttendeesChanged) {
    const fd = new FormData();
    fd.append('csrf', MH_API_CSRF);
    fd.append('action', 'attendees_save');
    fd.append('id', '<?php echo $id; ?>');
    fd.append('attendees', JSON.stringify(mhAttendeesList));
    await fetch('/hub/meetings/api.php', {method:'POST', body:fd});
    mhAttendeesChanged = false;
  }
  return true;
}

function mhCollectItems(){
  const rows=[...document.querySelectorAll('#agendaTable tbody tr')];
  return rows.map(tr=>{
    const id=tr.dataset.id||'';
    const voteId = Number(tr.dataset.voteId||0) || null;
    const type=tr.querySelectorAll('select')[0].value;
    const status=tr.querySelectorAll('select')[1].value;
    const title=tr.querySelector('input').value;
    const notes=tr.querySelector('textarea').value;
    return {id,type,status,title,notes,vote_id: (type === 'vote' ? voteId : null)};
  });
}

async function mhAutosave(){
  if(mhAutosaveInflight) return;
  if(!mhDirty) return;
  const csrfEl=document.querySelector('input[name="csrf"]');
  if(!csrfEl) return;
  mhAutosaveInflight = true;
  try{
    const fd=new FormData();
    fd.append('csrf', csrfEl.value||'');
    fd.append('autosave','1');
    const seriesSel=document.getElementById('series_id');
    if(seriesSel) fd.append('series_id', seriesSel.value||'0');
    fd.append('items_json', JSON.stringify(mhCollectItems()));
    const docEd = window.tinymce && tinymce.get('document_md') ? tinymce.get('document_md') : null;
    const minEd = window.tinymce && tinymce.get('minutes_md') ? tinymce.get('minutes_md') : null;
    const docVal = docEd ? docEd.getContent({format:'html'}) : (document.getElementById('document_md')?.value||'');
    const minVal = minEd ? minEd.getContent({format:'html'}) : (document.getElementById('minutes_md')?.value||'');
    fd.append('document_md', docVal||'');
    fd.append('minutes_md', minVal||'');
    fd.append('corp_name', document.getElementById('corp_name')?.value||'');
    fd.append('meeting_location', document.getElementById('meeting_location')?.value||'');
    fd.append('chair', document.getElementById('chair')?.value||'');
    fd.append('secretary', document.getElementById('secretary')?.value||'');
    fd.append('directors_present', document.getElementById('directors_present')?.value||'');
    fd.append('directors_absent', document.getElementById('directors_absent')?.value||'');
    fd.append('officers_present', document.getElementById('officers_present')?.value||'');
    fd.append('notice', document.getElementById('notice')?.value||'');
    fd.append('quorum', document.getElementById('quorum')?.value||'');
    if (mhAttendeesChanged) {
      try{
        const afd = new FormData();
        afd.append('csrf', MH_API_CSRF);
        afd.append('action', 'attendees_save');
        afd.append('id', '<?php echo $id; ?>');
        afd.append('attendees', JSON.stringify(mhAttendeesList));
        await fetch('/hub/meetings/api.php', {method:'POST', body:afd, credentials:'include'});
        mhAttendeesChanged = false;
      }catch(e){}
    }

    const res=await fetch(location.href,{method:'POST',body:fd,credentials:'include'});
    const data=await res.json().catch(()=>null);
    if(res.ok && data && data.ok===true){
      if(Array.isArray(data.items)){
        agendaItems = data.items;
        mhAgendaRender();
      }
      if(data.vote_summaries && typeof data.vote_summaries === 'object'){
        mhVoteSummaries = data.vote_summaries;
        mhAgendaRender();
      }
      try{
        for(const it of (agendaItems||[])){
          if(!it || !it.id) continue;
          if(mhPendingVoteOpenByItemId[it.id] === true && it.vote_id){
            mhPendingVoteOpenByItemId[it.id] = false;
            const vid = Number(it.vote_id||0);
            if(vid > 0){
              const sum = mhVoteSummaries && mhVoteSummaries[String(vid)] ? mhVoteSummaries[String(vid)] : null;
              const t = sum && sum.title ? sum.title : (it.title || 'Vote');
              mhOpenVoteModal(vid, t);
              break;
            }
          }
        }
      }catch(e){}
      mhDirty = false;
      const now=Date.now();
      if(now - mhLastAutosaveToastAt > 30000){
        mhLastAutosaveToastAt = now;
        mhNotice('info','Autosaved');
      }
    }
  }catch(e){
  }finally{
    mhAutosaveInflight = false;
  }
}

function mhAgendaFillTemplate(){
  const v = <?php echo json_encode(mh_agenda_default_doc((string)($meeting['title'] ?? ''), (string)($meeting['scheduled_for_text'] ?? ''), isset($meeting['scheduled_for_utc']) ? (string)$meeting['scheduled_for_utc'] : null)); ?>;
  const ed = window.tinymce && tinymce.get('document_md') ? tinymce.get('document_md') : null;
  if(ed){
    const cur = ed.getContent({format:'text'}) || '';
    if(cur.trim() !== '') return;
    ed.setContent('<pre>' + v.replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])) + '</pre>');
    mhMarkDirty();
    return;
  }
  const ta=document.getElementById('document_md');
  if(!ta) return;
  if((ta.value||'').trim()!=='') return;
  ta.value = v;
  mhMarkDirty();
}

function mhAgendaInsertSelectedTemplate(){
  const sel=document.getElementById('agenda_template_select');
  if(!sel) return;
  const id = Number(sel.value||0);
  if(!id) return;
  const t = (AGENDA_TEMPLATES||[]).find(x => Number(x.id||0) === id);
  if(!t || !t.payload) return;
  const p = t.payload;
  if(p.series_id !== undefined){
    const s=document.getElementById('series_id');
    if(s) s.value = String(p.series_id||0);
  }
  const d = p.delaware || {};
  const setVal = (id, val) => { const el=document.getElementById(id); if(el) el.value = (val===null||val===undefined)?'':String(val); };
  setVal('corp_name', d.corporation || '');
  setVal('meeting_location', d.location || '');
  setVal('chair', d.chair || '');
  setVal('secretary', d.secretary || '');
  setVal('directors_present', d.directors_present || '');
  setVal('directors_absent', d.directors_absent || '');
  setVal('officers_present', d.officers_present || '');
  setVal('notice', d.notice || '');
  setVal('quorum', d.quorum || '');
  if(p.document_md !== undefined){
    const ed = window.tinymce && tinymce.get('document_md') ? tinymce.get('document_md') : null;
    if(ed) ed.setContent(String(p.document_md||'')); else { const ta=document.getElementById('document_md'); if(ta) ta.value=String(p.document_md||''); }
  }
  if(Array.isArray(p.items)){
    agendaItems = p.items;
    mhAgendaRender();
    mhMarkDirty();
  }
}

function mhInitEditors(){
  if(!window.tinymce) return;
  try { tinymce.baseURL = '/gear/editors/TinyMCE'; } catch(e) {}
  try { tinymce.suffix = '.min'; } catch(e) {}

  const common = {
    theme: 'modern',
    skin_url: '/gear/editors/TinyMCE/skins/lightgray',
    plugins: 'advlist autolink link image lists charmap preview hr anchor searchreplace wordcount visualblocks visualchars code fullscreen insertdatetime media table paste autosave',
    menubar: 'file edit insert view format table tools',
    toolbar1: 'undo redo | styleselect | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent',
    toolbar2: 'link unlink anchor | image media | table | forecolor backcolor | removeformat | code preview fullscreen',
    statusbar: true,
    branding: false,
    content_style: 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#f3f6ff;background:#0b1220;} a{color:#5de0ff;}',
    setup: function (ed) {
      ed.on('change keyup SetContent Paste Undo Redo input', function(){ mhMarkDirty(); });
    }
  };

  tinymce.init(Object.assign({}, common, { selector: '#document_md', height: 320 }));
  tinymce.init(Object.assign({}, common, { selector: '#minutes_md', height: 220, menubar: false }));
}

function mhBindDirtyInputs(){
  const ids=['series_id','corp_name','meeting_location','chair','secretary','directors_present','directors_absent','officers_present','notice','quorum','template_name'];
  for(const id of ids){
    const el=document.getElementById(id);
    if(!el) continue;
    el.addEventListener('input', mhMarkDirty);
    el.addEventListener('change', mhMarkDirty);
  }
}

let mhAttendeesList = <?php
  $at = [];
  try {
    $st = $db->prepare("SELECT username, name_display, email, phone, role FROM mh_meeting_attendees WHERE meeting_id = ?");
    $st->execute([$id]);
    $at = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch(Throwable $e) {}
  echo json_encode($at ?: []);
?>;
let mhAttendeesChanged = false;

function mhInitUserSearch(){
  const inputs = ['chair','secretary','directors_present','directors_absent','officers_present'];
  inputs.forEach(id => {
    const el = document.getElementById(id);
    if(!el) return;
    const list = document.getElementById('user_list_' + (id === 'directors_present' ? 'directors' : (id === 'directors_absent' ? 'directors_absent' : id)));
    if(!list) return;
    
    let timer = null;
    el.addEventListener('input', () => {
      clearTimeout(timer);
      const parts = el.value.split(',');
      const val = parts.pop().trim();
      if(val.length < 2) return;
      timer = setTimeout(async () => {
        const fd = new FormData();
        fd.append('csrf', MH_API_CSRF);
        fd.append('action', 'user_search');
        fd.append('query', val);
        const res = await fetch('/hub/meetings/api.php', {method:'POST', body:fd});
        const data = await res.json().catch(()=>null);
        if(data && data.ok && Array.isArray(data.results)){
          list.innerHTML = '';
          data.results.forEach(u => {
            const uname = String(u.username||'').trim();
            if(!uname) return;
            const real = String(u.name_display||'').trim();
            const persona = String(u.persona_name||'').trim();
            const label = (real ? real : uname) + (persona ? (' · ' + persona) : '') + ' @' + uname;
            const opt = document.createElement('option');
            opt.value = label;
            list.appendChild(opt);
          });
        }
      }, 300);
    });

    el.addEventListener('change', () => {
      const parts = el.value.split(',').map(s => s.trim()).filter(s => s !== '');
      parts.forEach(p => {
        let uname = p;
        if(p.includes('@')){
          uname = p.split('@').pop().trim();
        }
        uname = uname.replace(/[^A-Za-z0-9._-]+/g,'');
        if(!uname) return;
        if(!mhAttendeesList.find(a => a.username === uname)){
          mhAttendeesList.push({username: uname, role: id, name_display: p});
          mhAttendeesChanged = true;
          mhMarkDirty();
        }
      });
      mhRenderAttendeePills();
    });
  });
}

mhAgendaRender();
mhRenderAttendeePills();
mhInitEditors();
mhBindDirtyInputs();
mhInitUserSearch();
mhTryRestoreDraft();
window.addEventListener('mh_agenda_autosave', mhAutosave);
setInterval(mhAutosave, 8000);
window.addEventListener('beforeunload', (e) => {
  if(!mhDirty) return;
  e.preventDefault();
  e.returnValue = '';
});
</script>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
