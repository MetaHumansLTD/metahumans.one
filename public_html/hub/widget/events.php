<?php
declare(strict_types=1);

require_once __DIR__ . '/_lib.php';

function mh_widget_events_tail_jsonl(string $path, int $limit): array
{
    if (!is_file($path) || $limit < 1) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return [];
    $lines = array_values(array_filter(array_map(fn($l) => is_string($l) ? trim($l) : '', $lines), fn($l) => $l !== ''));
    if (!$lines) return [];
    $slice = array_slice($lines, -max(1, min(400, $limit)));
    $out = [];
    foreach ($slice as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    return $out;
}

function mh_widget_events_parse_since(?string $since): int
{
    if (!is_string($since)) return 0;
    $since = trim($since);
    if ($since === '') return 0;
    if (ctype_digit($since)) return (int)$since;
    $ts = strtotime($since);
    return is_int($ts) ? $ts : 0;
}

$ctx = mh_widget_require_auth();

$sessionId = isset($_GET['session_id']) ? trim((string)$_GET['session_id']) : '';
if ($sessionId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_session_id'], 400);
    exit;
}

$sessions = is_array($_SESSION['mh_widget_sessions'] ?? null) ? $_SESSION['mh_widget_sessions'] : [];
$session = isset($sessions[$sessionId]) && is_array($sessions[$sessionId]) ? $sessions[$sessionId] : null;
if (!$session) {
    mh_widget_json(['success' => false, 'error' => 'session_not_found'], 404);
    exit;
}

$tenantId = (string)($ctx['tenant_id'] ?? '');
$personaId = isset($session['persona_id']) && is_string($session['persona_id']) ? trim((string)$session['persona_id']) : '';
if ($personaId === '') $personaId = (string)($ctx['persona_id'] ?? '');

$tenantSafe = strtolower(mh_widget_sanitize_id($tenantId));
$personaSafe = strtolower(mh_widget_sanitize_id($personaId));

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = max(1, min(200, $limit));
$sinceTs = mh_widget_events_parse_since(isset($_GET['since']) ? (string)$_GET['since'] : null);
$afterEventId = isset($_GET['after_event_id']) ? trim((string)$_GET['after_event_id']) : '';

$memPath = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/memory/events.jsonl';
$raw = mh_widget_events_tail_jsonl($memPath, 400);

$out = [];
$seenAfter = ($afterEventId === '');
foreach ($raw as $e) {
    if (!is_array($e)) continue;
    $eSession = isset($e['session_id']) && is_string($e['session_id']) ? (string)$e['session_id'] : '';
    if ($eSession !== '' && $eSession !== $sessionId) continue;

    $eid = isset($e['event_id']) && is_string($e['event_id']) ? trim((string)$e['event_id']) : '';
    if (!$seenAfter) {
        if ($eid !== '' && $eid === $afterEventId) {
            $seenAfter = true;
        }
        continue;
    }

    if ($sinceTs > 0) {
        $created = isset($e['created_at']) && is_string($e['created_at']) ? (string)$e['created_at'] : '';
        $cts = $created !== '' ? strtotime($created) : false;
        if ($cts !== false && is_int($cts) && $cts < $sinceTs) continue;
    }

    $out[] = $e;
}

if (count($out) > $limit) {
    $out = array_slice($out, -$limit);
}

$nextAfter = '';
if ($out) {
    $last = $out[count($out) - 1];
    if (is_array($last) && isset($last['event_id']) && is_string($last['event_id'])) {
        $nextAfter = trim((string)$last['event_id']);
    }
}

mh_widget_json([
    'success' => true,
    'session_id' => $sessionId,
    'events' => $out,
    'cursor' => [
        'after_event_id' => $nextAfter,
        'server_time_utc' => gmdate('c'),
    ],
]);
