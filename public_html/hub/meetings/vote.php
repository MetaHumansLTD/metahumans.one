<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/hub/equity/db.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
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

if (!isset($_SESSION['mh_vote_csrf']) || !is_string($_SESSION['mh_vote_csrf']) || $_SESSION['mh_vote_csrf'] === '') {
    $_SESSION['mh_vote_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['mh_vote_csrf'];

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
    $stmt = $db->prepare("SELECT id FROM mh_meetings WHERE room_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$roomLookup]);
    $id = (int)$stmt->fetchColumn();
}
if ($id < 1) {
    header('Location: /hub/meetings/', true, 302);
    exit;
}

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

$roomId = (string)($meeting['room_id'] ?? '');
$meetingTitle = (string)($meeting['title'] ?? '');
$seriesId = (int)($meeting['series_id'] ?? 0);
if ($seriesId < 1) $seriesId = 0;
$isOwner = isset($meeting['created_by_user']) && is_string($meeting['created_by_user']) && trim((string)$meeting['created_by_user']) !== '' && trim((string)$meeting['created_by_user']) === $user;

function mh_vote_tenant_safe(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') $tenantId = 'user:unknown';
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') return $safe;
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}

function mh_vote_meeting_root(string $tenantSafe, string $roomId): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $roomId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $roomId);
    return $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
}

function mh_vote_client_ip(): string
{
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string)$_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
    $ip = trim(explode(',', $ip)[0]);
    if (strlen($ip) > 64) $ip = substr($ip, 0, 64);
    return $ip;
}

function mh_vote_user_agent(): string
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
    if (strlen($ua) > 255) $ua = substr($ua, 0, 255);
    return $ua;
}

function mh_vote_audit(PDO $db, int $meetingId, int $voteId, string $action, string $actor, array $payload): void
{
    $stmt = $db->prepare("
        INSERT INTO mh_meeting_vote_audit (vote_id, meeting_id, action, actor, ip_address, user_agent, payload_json, created_at_utc)
        VALUES (:v, :m, :a, :u, :ip, :ua, :p, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        ':v' => $voteId,
        ':m' => $meetingId,
        ':a' => $action,
        ':u' => $actor,
        ':ip' => mh_vote_client_ip() ?: null,
        ':ua' => mh_vote_user_agent() ?: null,
        ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
}

function mh_vote_equity_snapshot(string $username, string $basis): array
{
    $basis = strtolower(trim($basis));
    if (!in_array($basis, ['shares', 'units'], true)) $basis = 'shares';
    try {
        $pdo = getEquityConnection();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'equity_unavailable', 'basis' => $basis, 'eligible' => true, 'weight' => 1, 'equity_issued' => false];
    }

    $totalUnits = 0;
    try {
        $totalUnits = (int)$pdo->query("SELECT SUM(units_owned) FROM equity_ledger WHERE units_owned > 0")->fetchColumn();
    } catch (Throwable) {
        $totalUnits = 0;
    }
    $equityIssued = $totalUnits > 0;
    if (!$equityIssued) {
        return [
            'ok' => true,
            'basis' => $basis,
            'equity_issued' => false,
            'total_units_issued' => 0,
            'eligible' => true,
            'weight' => 1,
            'taken_at_utc' => gmdate('c'),
        ];
    }

    $profile = [
        'user_type' => 'shareholder',
        'ordinary_votes_shareholder' => 1,
        'ordinary_votes_founder' => 1000,
    ];
    try {
        $stmt = $pdo->prepare("SELECT user_type, ordinary_votes_shareholder, ordinary_votes_founder FROM equity_user_profiles WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($p)) {
            $profile['user_type'] = isset($p['user_type']) ? (string)$p['user_type'] : $profile['user_type'];
            $profile['ordinary_votes_shareholder'] = isset($p['ordinary_votes_shareholder']) ? (int)$p['ordinary_votes_shareholder'] : $profile['ordinary_votes_shareholder'];
            $profile['ordinary_votes_founder'] = isset($p['ordinary_votes_founder']) ? (int)$p['ordinary_votes_founder'] : $profile['ordinary_votes_founder'];
        }
    } catch (Throwable) {
    }

    $holdings = [];
    try {
        $stmt = $pdo->prepare("
            SELECT l.class_id, c.name AS class_name, c.fractional_units_per_share, l.units_owned
            FROM equity_ledger l
            JOIN equity_classes c ON l.class_id = c.id
            WHERE l.username = ? AND l.units_owned > 0
            ORDER BY l.class_id ASC
        ");
        $stmt->execute([$username]);
        $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $holdings = [];
    }

    $sumUnits = 0;
    $sumShares = 0.0;
    $byClass = [];
    foreach ($holdings as $h) {
        $cid = (int)($h['class_id'] ?? 0);
        $cname = (string)($h['class_name'] ?? '');
        $ups = (int)($h['fractional_units_per_share'] ?? 400);
        if ($ups < 1) $ups = 400;
        $units = (int)($h['units_owned'] ?? 0);
        if ($cid < 1 || $units < 1) continue;
        $shares = $units / $ups;
        $sumUnits += $units;
        $sumShares += $shares;
        $byClass[] = [
            'class_id' => $cid,
            'class_name' => $cname,
            'units_owned' => $units,
            'units_per_share' => $ups,
            'shares_equivalent' => $shares,
        ];
    }

    $userType = strtolower(trim((string)$profile['user_type']));
    $votesPerShare = 0;
    if ($userType === 'mvi') {
        $votesPerShare = 0;
    } elseif ($userType === 'founder') {
        $votesPerShare = max(0, (int)$profile['ordinary_votes_founder']);
    } else {
        $votesPerShare = max(0, (int)$profile['ordinary_votes_shareholder']);
    }
    $eligible = $votesPerShare > 0;

    $weight = 0;
    if ($basis === 'units') {
        $weight = $eligible ? max(1, $sumUnits) : 0;
    } else {
        $weight = $eligible ? max(1, (int)round($sumShares * $votesPerShare)) : 0;
    }

    return [
        'ok' => true,
        'basis' => $basis,
        'equity_issued' => true,
        'total_units_issued' => $totalUnits,
        'user_type' => (string)$profile['user_type'],
        'votes_per_share' => $votesPerShare,
        'eligible' => $eligible,
        'units_total' => $sumUnits,
        'shares_total' => $sumShares,
        'classes' => $byClass,
        'weight' => $weight,
        'taken_at_utc' => gmdate('c'),
    ];
}

function mh_vote_export_build(PDO $db, array $meeting, array $vote, array $ballots): array
{
    $opts = [];
    $decoded = isset($vote['options_json']) ? json_decode((string)$vote['options_json'], true) : null;
    if (is_array($decoded)) {
        foreach ($decoded as $o) {
            $o = trim((string)$o);
            if ($o !== '') $opts[] = $o;
        }
    }
    if ($opts === []) $opts = ['yes', 'no'];

    $tallies = [];
    foreach ($opts as $o) {
        $tallies[$o] = ['votes' => 0, 'weight' => 0];
    }
    foreach ($ballots as $b) {
        $choice = isset($b['choice']) ? (string)$b['choice'] : '';
        $w = (int)($b['weight'] ?? 1);
        $w = max(1, $w);
        if (!array_key_exists($choice, $tallies)) {
            $tallies[$choice] = ['votes' => 0, 'weight' => 0];
        }
        $tallies[$choice]['votes']++;
        $tallies[$choice]['weight'] += $w;
    }

    $results = [];
    foreach ($tallies as $k => $v) {
        $results[] = ['option' => (string)$k, 'ballots' => (int)($v['votes'] ?? 0), 'weight' => (int)($v['weight'] ?? 0)];
    }
    usort($results, fn($a, $b) => (int)($b['weight'] ?? 0) <=> (int)($a['weight'] ?? 0));

    return [
        'version' => 1,
        'exported_at_utc' => gmdate('c'),
        'meeting' => [
            'id' => (int)($meeting['id'] ?? 0),
            'room_id' => (string)($meeting['room_id'] ?? ''),
            'title' => (string)($meeting['title'] ?? ''),
            'scheduled_for_text' => (string)($meeting['scheduled_for_text'] ?? ''),
            'scheduled_for_utc' => (string)($meeting['scheduled_for_utc'] ?? ''),
            'created_by_user' => (string)($meeting['created_by_user'] ?? ''),
            'series_id' => (int)($meeting['series_id'] ?? 0),
        ],
        'vote' => [
            'id' => (int)($vote['id'] ?? 0),
            'title' => (string)($vote['title'] ?? ''),
            'kind' => (string)($vote['kind'] ?? ''),
            'weight_basis' => (string)($vote['weight_basis'] ?? 'shares'),
            'status' => (string)($vote['status'] ?? ''),
            'options' => $opts,
            'equity_snapshot_json' => isset($vote['equity_snapshot_json']) ? json_decode((string)$vote['equity_snapshot_json'], true) : null,
            'created_by_user' => (string)($vote['created_by_user'] ?? ''),
            'created_at_utc' => (string)($vote['created_at_utc'] ?? ''),
            'closed_at_utc' => (string)($vote['closed_at_utc'] ?? ''),
        ],
        'results' => $results,
        'ballots' => array_map(function ($b) {
            return [
                'username' => (string)($b['username'] ?? ''),
                'choice' => (string)($b['choice'] ?? ''),
                'weight' => (int)($b['weight'] ?? 1),
                'weight_basis' => (string)($b['weight_basis'] ?? 'shares'),
                'equity_snapshot_json' => isset($b['equity_snapshot_json']) ? json_decode((string)$b['equity_snapshot_json'], true) : null,
                'created_at_utc' => (string)($b['created_at_utc'] ?? ''),
            ];
        }, $ballots),
    ];
}

$msg = '';
$err = '';

if (isset($_GET['download_vote']) && is_string($_GET['download_vote']) && trim((string)$_GET['download_vote']) !== '') {
    $vid = (int)$_GET['download_vote'];
    if ($vid > 0) {
        $stmt = $db->prepare("SELECT * FROM mh_meeting_votes WHERE id = ? AND meeting_id = ? LIMIT 1");
        $stmt->execute([$vid, $id]);
        $vote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($vote)) {
            $exportPath = isset($vote['export_path']) ? trim((string)$vote['export_path']) : '';
            if ($exportPath !== '' && is_file($exportPath)) {
                $size = filesize($exportPath);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="vote_' . $vid . '_export.json"');
                header('Content-Length: ' . (is_int($size) ? $size : 0));
                header('X-Content-Type-Options: nosniff');
                readfile($exportPath);
                exit;
            }
        }
    }
    http_response_code(404);
    echo 'Not found';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $err = 'Invalid request';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

        if ($action === 'create_vote') {
            if (!$isOwner) {
                $err = 'Only the meeting owner can create votes.';
            } else {
                $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
                $kind = isset($_POST['kind']) ? trim((string)$_POST['kind']) : 'poll';
                $weightBasis = isset($_POST['weight_basis']) ? trim((string)$_POST['weight_basis']) : 'shares';
                $optionsRaw = isset($_POST['options']) ? (string)$_POST['options'] : '';
                $opts = array_values(array_filter(array_map(fn($l) => trim((string)$l), preg_split('/\\r?\\n/', $optionsRaw)), fn($v) => $v !== ''));
                $opts = array_values(array_unique($opts));
                if ($title === '' || $opts === []) {
                    $err = 'Title and options are required.';
                } else {
                    $snap = mh_vote_equity_snapshot($user, $weightBasis);
                    $equitySnap = [
                        'taken_at_utc' => (string)($snap['taken_at_utc'] ?? gmdate('c')),
                        'basis' => (string)($snap['basis'] ?? $weightBasis),
                        'equity_issued' => (bool)($snap['equity_issued'] ?? false),
                        'total_units_issued' => (int)($snap['total_units_issued'] ?? 0),
                    ];
                    $stmt = $db->prepare("
                        INSERT INTO mh_meeting_votes (meeting_id, series_id, title, kind, weight_basis, options_json, status, equity_snapshot_json, created_by_user, created_at_utc)
                        VALUES (:m, :s, :t, :k, :wb, :o, 'open', :snap, :u, UTC_TIMESTAMP())
                    ");
                    $stmt->execute([
                        ':m' => $id,
                        ':s' => $seriesId > 0 ? $seriesId : null,
                        ':t' => strlen($title) > 255 ? substr($title, 0, 255) : $title,
                        ':k' => $kind !== '' ? (strlen($kind) > 32 ? substr($kind, 0, 32) : $kind) : 'poll',
                        ':wb' => $weightBasis !== '' ? (strlen($weightBasis) > 32 ? substr($weightBasis, 0, 32) : $weightBasis) : 'shares',
                        ':o' => json_encode($opts, JSON_UNESCAPED_SLASHES),
                        ':snap' => json_encode($equitySnap, JSON_UNESCAPED_SLASHES),
                        ':u' => $user,
                    ]);
                    $voteId = (int)$db->lastInsertId();
                    if ($voteId > 0) {
                        mh_vote_audit($db, $id, $voteId, 'create_vote', $user, ['title' => $title, 'options' => $opts, 'weight_basis' => $weightBasis]);
                        $msg = 'Vote created';
                    } else {
                        $err = 'Create failed';
                    }
                }
            }
        } elseif ($action === 'cast_ballot') {
            $voteId = isset($_POST['vote_id']) ? (int)$_POST['vote_id'] : 0;
            $choice = isset($_POST['choice']) ? trim((string)$_POST['choice']) : '';
            if ($voteId < 1 || $choice === '') {
                $err = 'Missing vote or choice.';
            } else {
                $stmt = $db->prepare("SELECT * FROM mh_meeting_votes WHERE id = ? AND meeting_id = ? LIMIT 1");
                $stmt->execute([$voteId, $id]);
                $vote = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($vote) || ((string)($vote['status'] ?? '')) !== 'open') {
                    $err = 'Vote is not open.';
                } else {
                    $weightBasis = isset($vote['weight_basis']) ? (string)$vote['weight_basis'] : 'shares';
                    $snap = mh_vote_equity_snapshot($user, $weightBasis);
                    if (($snap['ok'] ?? false) !== true) {
                        $err = 'Equity system unavailable.';
                    }
                    if (($snap['eligible'] ?? true) !== true) {
                        $err = 'Not eligible to vote.';
                    }
                    if ($err !== '') {
                        // stop processing
                    } else {
                        $weight = (int)($snap['weight'] ?? 1);
                        $weight = max(1, $weight);
                        $snapJson = json_encode($snap, JSON_UNESCAPED_SLASHES);

                        $stmt = $db->prepare("SELECT id, weight FROM mh_meeting_ballots WHERE vote_id = ? AND username = ? LIMIT 1");
                        $stmt->execute([$voteId, $user]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (is_array($existing)) {
                            $existingWeight = (int)($existing['weight'] ?? 1);
                            $bid = (int)($existing['id'] ?? 0);
                            $u = $db->prepare("UPDATE mh_meeting_ballots SET choice = :c, ip_address = :ip, user_agent = :ua WHERE id = :id");
                            $u->execute([
                                ':c' => $choice,
                                ':ip' => mh_vote_client_ip() ?: null,
                                ':ua' => mh_vote_user_agent() ?: null,
                                ':id' => $bid,
                            ]);
                            mh_vote_audit($db, $id, $voteId, 'update_ballot', $user, ['choice' => $choice, 'weight' => $existingWeight, 'weight_basis' => $weightBasis]);
                            $msg = 'Ballot updated';
                        } else {
                            $ins = $db->prepare("
                                INSERT INTO mh_meeting_ballots (vote_id, username, choice, weight, weight_basis, equity_snapshot_json, ip_address, user_agent, created_at_utc)
                                VALUES (:v, :u, :c, :w, :wb, :s, :ip, :ua, UTC_TIMESTAMP())
                            ");
                            $ins->execute([
                                ':v' => $voteId,
                                ':u' => $user,
                                ':c' => $choice,
                                ':w' => $weight,
                                ':wb' => strlen($weightBasis) > 32 ? substr($weightBasis, 0, 32) : $weightBasis,
                                ':s' => $snapJson,
                                ':ip' => mh_vote_client_ip() ?: null,
                                ':ua' => mh_vote_user_agent() ?: null,
                            ]);
                            mh_vote_audit($db, $id, $voteId, 'cast_ballot', $user, ['choice' => $choice, 'weight' => $weight, 'weight_basis' => $weightBasis]);
                            $msg = 'Ballot cast';
                        }
                    }
                }
            }
        } elseif ($action === 'close_vote') {
            $voteId = isset($_POST['vote_id']) ? (int)$_POST['vote_id'] : 0;
            if (!$isOwner) {
                $err = 'Only the meeting owner can close votes.';
            } elseif ($voteId < 1) {
                $err = 'Missing vote id.';
            } else {
                $stmt = $db->prepare("SELECT * FROM mh_meeting_votes WHERE id = ? AND meeting_id = ? LIMIT 1");
                $stmt->execute([$voteId, $id]);
                $vote = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($vote)) {
                    $err = 'Vote not found.';
                } elseif (((string)($vote['status'] ?? '')) !== 'open') {
                    $err = 'Vote is not open.';
                } else {
                    $b = $db->prepare("SELECT username, choice, weight, weight_basis, equity_snapshot_json, created_at_utc FROM mh_meeting_ballots WHERE vote_id = ? ORDER BY id ASC");
                    $b->execute([$voteId]);
                    $ballots = $b->fetchAll(PDO::FETCH_ASSOC);
                    if (!is_array($ballots)) $ballots = [];

                    $payload = mh_vote_export_build($db, $meeting, $vote, $ballots);
                    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if (!is_string($json) || $json === '') {
                        $err = 'Export failed.';
                    } else {
                        $tenantSafe = mh_vote_tenant_safe($tenantId);
                        $root = mh_vote_meeting_root($tenantSafe, $roomId);
                        @mkdir($root . '/votes', 0775, true);
                        $exportPath = $root . '/votes/vote_' . $voteId . '_export.json';
                        $ok = @file_put_contents($exportPath, $json . "\n", LOCK_EX);
                        if ($ok === false) {
                            $err = 'Failed to write export.';
                        } else {
                            $sha = (string)@hash_file('sha256', $exportPath);
                            $resultsJson = json_encode($payload['results'] ?? [], JSON_UNESCAPED_SLASHES);
                            $u = $db->prepare("UPDATE mh_meeting_votes SET status = 'closed', closed_at_utc = UTC_TIMESTAMP(), results_json = :r, export_path = :p, export_sha256 = :s WHERE id = :id AND meeting_id = :m");
                            $u->execute([':r' => $resultsJson, ':p' => $exportPath, ':s' => $sha, ':id' => $voteId, ':m' => $id]);
                            mh_vote_audit($db, $id, $voteId, 'close_vote', $user, ['export_sha256' => $sha]);
                            $msg = 'Vote closed';
                        }
                    }
                }
            }
        }
    }
}

$votes = [];
$stmt = $db->prepare("SELECT * FROM mh_meeting_votes WHERE meeting_id = ? ORDER BY id DESC");
$stmt->execute([$id]);
$votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($votes)) $votes = [];

$ballotsByVote = [];
if ($votes !== []) {
    $ids = array_map(fn($v) => (int)($v['id'] ?? 0), $votes);
    $ids = array_values(array_filter($ids, fn($n) => $n > 0));
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM mh_meeting_ballots WHERE vote_id IN ($in)");
        $stmt->execute($ids);
        $balls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($balls)) {
            foreach ($balls as $b) {
                $vid = (int)($b['vote_id'] ?? 0);
                if ($vid < 1) continue;
                if (!isset($ballotsByVote[$vid])) $ballotsByVote[$vid] = [];
                $ballotsByVote[$vid][] = $b;
            }
        }
    }
}

$audit = [];
$stmt = $db->prepare("SELECT action, actor, created_at_utc, payload_json FROM mh_meeting_vote_audit WHERE meeting_id = ? ORDER BY id DESC LIMIT 60");
$stmt->execute([$id]);
$audit = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($audit)) $audit = [];

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Votes</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.hub-votes main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .title{margin:0 0 10px 0;font-size:22px}
    .muted{color:rgba(255,255,255,.7);font-size:12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12);cursor:pointer}
    .btn.primary{border-color:rgba(0,212,255,.35);background:rgba(0,212,255,.16);color:#d7fbff}
    .btn.danger{border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.12);color:rgba(255,180,180,.95)}
    .input,select,textarea{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:rgba(255,255,255,.92)}
    textarea{min-height:90px;resize:vertical}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px;vertical-align:top}
    th{color:rgba(255,255,255,.75);font-weight:800}
    .vote-card{border-radius:14px;border:1px solid rgba(255,255,255,.12);padding:14px;margin-top:14px;background:rgba(0,0,0,.16)}
  </style>
</head>
<body class="hub-votes">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="card">
    <div class="row">
      <div>
        <h1 class="title">Votes</h1>
        <div class="muted"><?php echo htmlspecialchars($meetingTitle !== '' ? $meetingTitle : 'Meeting', ENT_QUOTES); ?> · Room: <?php echo htmlspecialchars($roomId, ENT_QUOTES); ?></div>
        <div class="muted"><?php echo htmlspecialchars((string)($meeting['scheduled_for_text'] ?? $meeting['created_at_utc'] ?? ''), ENT_QUOTES); ?></div>
      </div>
      <div class="row">
        <a class="btn" href="/hub/meetings/">Back</a>
        <a class="btn" href="/meet.php?room_id=<?php echo rawurlencode($roomId); ?>&role=<?php echo $isOwner ? 'presenter' : 'viewer'; ?>">Join</a>
        <a class="btn" href="/hub/meetings/recordings.php?room_id=<?php echo rawurlencode($roomId); ?>">Artifacts</a>
      </div>
    </div>

    <?php if ($msg !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(180,255,210,.95)"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(255,180,180,.95)"><?php echo htmlspecialchars($err, ENT_QUOTES); ?></div><?php endif; ?>

    <?php if ($isOwner): ?>
      <div class="vote-card">
        <div style="font-weight:950">Create vote</div>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
          <input type="hidden" name="action" value="create_vote">
          <div class="row" style="justify-content:flex-start">
            <div style="flex:2;min-width:240px">
              <input class="input" name="title" placeholder="Vote title (e.g. Approve budget?)" required>
            </div>
            <div style="flex:1;min-width:200px">
              <select class="input" name="weight_basis">
                <option value="shares">Weight: shares</option>
                <option value="units">Weight: units</option>
              </select>
            </div>
          </div>
          <div style="margin-top:10px">
            <textarea class="input" name="options" placeholder="One option per line (e.g. Yes&#10;No)" required></textarea>
          </div>
          <div style="margin-top:10px;display:flex;justify-content:flex-end">
            <button class="btn primary" type="submit">Create</button>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($votes === []): ?>
      <div class="muted" style="margin-top:14px">No votes yet.</div>
    <?php else: ?>
      <?php foreach ($votes as $v): ?>
        <?php
          $vid = (int)($v['id'] ?? 0);
          $vTitle = (string)($v['title'] ?? '');
          $vStatus = (string)($v['status'] ?? 'open');
          $basis = (string)($v['weight_basis'] ?? 'shares');
          $opts = json_decode((string)($v['options_json'] ?? '[]'), true);
          if (!is_array($opts)) $opts = [];
          $myBallot = null;
          $ballots = $ballotsByVote[$vid] ?? [];
          if (is_array($ballots)) {
              foreach ($ballots as $b) {
                  if (isset($b['username']) && (string)$b['username'] === $user) {
                      $myBallot = $b;
                      break;
                  }
              }
          } else {
              $ballots = [];
          }
          $tallies = [];
          foreach ($opts as $o) {
              $k = trim((string)$o);
              if ($k === '') continue;
              $tallies[$k] = ['ballots' => 0, 'weight' => 0];
          }
          foreach ($ballots as $b) {
              $c = isset($b['choice']) ? (string)$b['choice'] : '';
              $w = max(1, (int)($b['weight'] ?? 1));
              if (!isset($tallies[$c])) $tallies[$c] = ['ballots' => 0, 'weight' => 0];
              $tallies[$c]['ballots']++;
              $tallies[$c]['weight'] += $w;
          }
        ?>
        <div class="vote-card" id="vote_<?php echo (int)$vid; ?>">
          <div class="row">
            <div>
              <div style="font-weight:950"><?php echo htmlspecialchars($vTitle !== '' ? $vTitle : ('Vote #' . $vid), ENT_QUOTES); ?></div>
              <div class="muted">Status: <?php echo htmlspecialchars($vStatus, ENT_QUOTES); ?> · Weight: <?php echo htmlspecialchars($basis, ENT_QUOTES); ?> · Ballots: <?php echo htmlspecialchars((string)count($ballots), ENT_QUOTES); ?></div>
            </div>
            <div class="row">
              <?php if ($vStatus === 'closed' && isset($v['export_path']) && is_string($v['export_path']) && trim((string)$v['export_path']) !== ''): ?>
                <a class="btn" href="/hub/meetings/vote.php?id=<?php echo (int)$id; ?>&download_vote=<?php echo (int)$vid; ?>">Download export</a>
              <?php endif; ?>
              <?php if ($isOwner && $vStatus === 'open'): ?>
                <form method="post" style="margin:0">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                  <input type="hidden" name="action" value="close_vote">
                  <input type="hidden" name="vote_id" value="<?php echo (int)$vid; ?>">
                  <button class="btn danger" type="submit">Close</button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($vStatus === 'open'): ?>
            <form method="post" style="margin-top:10px">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
              <input type="hidden" name="action" value="cast_ballot">
              <input type="hidden" name="vote_id" value="<?php echo (int)$vid; ?>">
              <div class="row" style="justify-content:flex-start">
                <?php foreach ($opts as $o): ?>
                  <?php $o = trim((string)$o); if ($o === '') continue; ?>
                  <label class="muted" style="display:flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:8px 10px;cursor:pointer">
                    <input type="radio" name="choice" value="<?php echo htmlspecialchars($o, ENT_QUOTES); ?>" <?php echo is_array($myBallot) && ((string)($myBallot['choice'] ?? '') === $o) ? 'checked' : ''; ?> required>
                    <span><?php echo htmlspecialchars($o, ENT_QUOTES); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="margin-top:10px;display:flex;justify-content:flex-end">
                <button class="btn primary" type="submit"><?php echo is_array($myBallot) ? 'Update ballot' : 'Cast ballot'; ?></button>
              </div>
            </form>
          <?php endif; ?>

          <table>
            <thead>
              <tr>
                <th>Option</th>
                <th>Ballots</th>
                <th>Weight</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($tallies as $opt => $t): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$opt, ENT_QUOTES); ?></td>
                <td class="muted"><?php echo (int)($t['ballots'] ?? 0); ?></td>
                <td class="muted"><?php echo (int)($t['weight'] ?? 0); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="vote-card">
      <div style="font-weight:950">Audit log</div>
      <?php if ($audit === []): ?>
        <div class="muted" style="margin-top:10px">No audit events yet.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>When</th>
              <th>Actor</th>
              <th>Action</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($audit as $a): ?>
            <?php
              $when = (string)($a['created_at_utc'] ?? '');
              $actor = (string)($a['actor'] ?? '');
              $act = (string)($a['action'] ?? '');
              $payload = (string)($a['payload_json'] ?? '');
              if (strlen($payload) > 220) $payload = substr($payload, 0, 220) . '…';
            ?>
            <tr>
              <td class="muted"><?php echo htmlspecialchars($when, ENT_QUOTES); ?></td>
              <td class="muted"><?php echo htmlspecialchars($actor, ENT_QUOTES); ?></td>
              <td><?php echo htmlspecialchars($act, ENT_QUOTES); ?></td>
              <td class="muted"><?php echo htmlspecialchars($payload, ENT_QUOTES); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
