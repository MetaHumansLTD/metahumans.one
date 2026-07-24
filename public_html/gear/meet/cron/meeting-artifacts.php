<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/calendar/calendar_helpers.php';
require_once dirname(__DIR__, 3) . '/auth/tenant_provisioning.php';
require_once dirname(__DIR__, 3) . '/hub/workbench/api/_memory_ingest_lib.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

function mh_art_list_tenant_db_config_ids(): array
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

function mh_art_tenant_safe(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        $tenantId = 'user:unknown';
    }
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') return $safe;
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}

function mh_art_meeting_root(string $tenantSafe, string $roomId): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $roomId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $roomId);
    return $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
}

function mh_art_safe_id(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'id';
}

function mh_art_sha256_file(string $path): string
{
    if (!is_file($path)) return '';
    $h = @hash_file('sha256', $path);
    return is_string($h) ? $h : '';
}

function mh_art_vtt_to_text(string $vtt): string
{
    $vtt = str_replace("\r\n", "\n", $vtt);
    $lines = explode("\n", $vtt);
    $out = [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln === 'WEBVTT') continue;
        if (preg_match('/^\\d+$/', $ln)) continue;
        if (preg_match('/^\\d\\d:\\d\\d:\\d\\d\\.\\d\\d\\d\\s+-->\\s+\\d\\d:\\d\\d:\\d\\d\\.\\d\\d\\d/', $ln)) continue;
        $ln = preg_replace('/<[^>]+>/', '', $ln);
        $ln = trim((string)$ln);
        if ($ln !== '') $out[] = $ln;
    }
    $text = implode("\n", $out);
    $text = preg_replace("/\\n{3,}/", "\n\n", (string)$text);
    return trim((string)$text);
}

function mh_art_extract_audio_wav_16k(string $src, string $dst): bool
{
    $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($src) . ' -ac 1 -ar 16000 ' . escapeshellarg($dst);
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    return $code === 0 && is_file($dst) && filesize($dst) > 44;
}

function mh_art_asr_multipart(string $url, string $path, string $task): array
{
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'status' => 0, 'err' => 'curl_init_failed', 'resp' => ''];
    $post = [
        'file' => new CURLFile($path),
        'task' => $task,
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    return ['ok' => $resp !== false && $err === '' && $status >= 200 && $status < 300, 'status' => $status, 'err' => $err, 'resp' => is_string($resp) ? $resp : ''];
}

function mh_art_transcribe(string $mediaPath): array
{
    $host = 'https://meta.superhumans.one';
    $mmsUrl = rtrim((string)(getenv('MHW_MMS_ASR_URL') ?: ($host . '/cortex-audio/mms-asr/v1/audio/transcriptions')), '/');
    $fwUrl = rtrim((string)(getenv('MHW_FASTER_WHISPER_URL') ?: ($host . '/cortex-audio/faster-whisper/v1/audio/transcriptions')), '/');

    $tmpBase = tempnam(sys_get_temp_dir(), 'mh_meet_asr_');
    if (!is_string($tmpBase) || $tmpBase === '') {
        return ['ok' => false, 'error' => 'temp_failed'];
    }
    $wavPath = $tmpBase . '.wav';
    @unlink($tmpBase);
    $usePath = $mediaPath;
    if (mh_art_extract_audio_wav_16k($mediaPath, $wavPath)) {
        $usePath = $wavPath;
    }

    $r1 = mh_art_asr_multipart($mmsUrl, $usePath, 'transcribe');
    if (($r1['ok'] ?? false) === true) {
        $body = json_decode((string)($r1['resp'] ?? ''), true);
        if (is_array($body) && is_string($body['text'] ?? null) && trim((string)$body['text']) !== '') {
            if (is_file($wavPath)) @unlink($wavPath);
            return ['ok' => true, 'lane' => 'mms_asr', 'text' => trim((string)$body['text']), 'raw' => $body];
        }
    }

    $r2 = mh_art_asr_multipart($fwUrl, $mediaPath, 'transcribe');
    if (($r2['ok'] ?? false) === true) {
        $body2 = json_decode((string)($r2['resp'] ?? ''), true);
        if (is_array($body2) && is_string($body2['text'] ?? null) && trim((string)$body2['text']) !== '') {
            if (is_file($wavPath)) @unlink($wavPath);
            return ['ok' => true, 'lane' => 'faster_whisper', 'text' => trim((string)$body2['text']), 'raw' => $body2];
        }
    }

    if (is_file($wavPath)) @unlink($wavPath);
    return ['ok' => false, 'error' => 'asr_failed', 'mms' => ['status' => (int)($r1['status'] ?? 0), 'err' => (string)($r1['err'] ?? '')], 'fw' => ['status' => (int)($r2['status'] ?? 0), 'err' => (string)($r2['err'] ?? '')]];
}

function mh_art_llm_cfg(): array
{
    $cfgPath = dirname(__DIR__, 3) . '/ai/hermes.json';
    $cfg = [];
    if (is_file($cfgPath)) {
        $decoded = json_decode((string)@file_get_contents($cfgPath), true);
        if (is_array($decoded)) $cfg = $decoded;
    }
    return [
        'base_url' => (string)($cfg['base_url'] ?? 'https://superhumans.one/ai/chat.php'),
        'api_key' => (string)($cfg['api_key'] ?? ''),
        'model' => (string)($cfg['model'] ?? 'hermes-405b'),
        'timeout_sec' => max(10, (int)($cfg['timeout_sec'] ?? 60)),
    ];
}

function mh_art_llm_chat(array $messages): array
{
    $cfg = mh_art_llm_cfg();
    $baseUrl = rtrim(trim((string)$cfg['base_url']), '/');
    $apiKey = trim((string)$cfg['api_key']);
    $model = trim((string)$cfg['model']);
    $timeoutSec = (int)$cfg['timeout_sec'];

    $endpoint = $baseUrl;
    $u = parse_url($endpoint);
    $host = is_array($u) && isset($u['host']) ? (string)$u['host'] : '';
    $port = is_array($u) && isset($u['port']) ? (int)$u['port'] : 0;
    $path = is_array($u) && isset($u['path']) ? (string)$u['path'] : '';
    $isOllama = ($port === 11434) && ($path === '' || $path === '/');

    if ($isOllama) {
        $endpoint = $endpoint . '/api/chat';
        $payload = [
            'model' => $model !== '' ? $model : 'hermes3:latest',
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
            ],
        ];
    } else {
        if (!str_ends_with($endpoint, '/ai/chat.php')) {
            $endpoint = $endpoint . '/v1/chat/completions';
        }
        $payload = [
            'model' => $model !== '' ? $model : 'hermes-405b',
            'messages' => $messages,
            'temperature' => 0.2,
        ];
    }

    $ch = curl_init($endpoint);
    if ($ch === false) return ['ok' => false, 'error' => 'curl_init_failed'];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;
    if (!is_string($resp) || $resp === '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'error' => 'llm_failed', 'status' => $status, 'err' => $err];
    }
    $decoded = json_decode($resp, true);
    if ($isOllama && is_array($decoded) && is_array($decoded['message'] ?? null) && is_string($decoded['message']['content'] ?? null)) {
        return ['ok' => true, 'text' => (string)$decoded['message']['content'], 'raw' => $decoded];
    }
    if (is_array($decoded) && isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
        return ['ok' => true, 'text' => (string)$decoded['choices'][0]['message']['content'], 'raw' => $decoded];
    }
    if (is_array($decoded) && is_string($decoded['reply'] ?? null)) {
        return ['ok' => true, 'text' => (string)$decoded['reply'], 'raw' => $decoded];
    }
    return ['ok' => false, 'error' => 'llm_bad_response'];
}

function mh_art_summarize(string $title, string $transcript, array $agendaItems, string $minutesMd): array
{
    $agendaText = '';
    if ($agendaItems !== []) {
        $lines = [];
        foreach ($agendaItems as $it) {
            if (!is_array($it)) continue;
            $t = trim((string)($it['title'] ?? ''));
            if ($t === '') continue;
            $st = trim((string)($it['status'] ?? ''));
            $lines[] = '- ' . $t . ($st !== '' ? (' [' . $st . ']') : '');
        }
        if ($lines !== []) {
            $agendaText = implode("\n", $lines);
        }
    }

    $sys = "You are an assistant that produces professional meeting summaries.\nReturn strictly valid JSON.\n";
    $user = "Meeting title: " . $title . "\n\n";
    if ($agendaText !== '') {
        $user .= "Agenda items:\n" . $agendaText . "\n\n";
    }
    if (trim($minutesMd) !== '') {
        $user .= "Minutes so far (Markdown):\n" . $minutesMd . "\n\n";
    }
    $user .= "Transcript:\n" . $transcript . "\n\n";
    $user .= "Output JSON schema:\n";
    $user .= "{\n";
    $user .= "  \"summary\": \"string\",\n";
    $user .= "  \"decisions\": [\"string\"],\n";
    $user .= "  \"action_items\": [{\"owner\":\"string\",\"task\":\"string\",\"due\":\"string\"}],\n";
    $user .= "  \"risks\": [\"string\"],\n";
    $user .= "  \"notes\": [\"string\"]\n";
    $user .= "}\n";

    $r = mh_art_llm_chat([
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $user],
    ]);
    if (!($r['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string)($r['error'] ?? 'llm_failed')];
    }
    $text = trim((string)($r['text'] ?? ''));
    $jsonStart = strpos($text, '{');
    $jsonEnd = strrpos($text, '}');
    if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
        $text = substr($text, $jsonStart, $jsonEnd - $jsonStart + 1);
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded) || !is_string($decoded['summary'] ?? null)) {
        return ['ok' => true, 'summary' => ['summary' => $text, 'decisions' => [], 'action_items' => [], 'risks' => [], 'notes' => []], 'raw' => $r['raw'] ?? null];
    }
    if (!is_array($decoded['decisions'] ?? null)) $decoded['decisions'] = [];
    if (!is_array($decoded['action_items'] ?? null)) $decoded['action_items'] = [];
    if (!is_array($decoded['risks'] ?? null)) $decoded['risks'] = [];
    if (!is_array($decoded['notes'] ?? null)) $decoded['notes'] = [];
    return ['ok' => true, 'summary' => $decoded, 'raw' => $r['raw'] ?? null];
}

function mh_art_summary_to_markdown(array $s): string
{
    $out = [];
    $sum = trim((string)($s['summary'] ?? ''));
    if ($sum !== '') {
        $out[] = "## Summary\n\n" . $sum;
    }
    $dec = $s['decisions'] ?? [];
    if (is_array($dec) && $dec !== []) {
        $lines = [];
        foreach ($dec as $d) {
            $d = trim((string)$d);
            if ($d !== '') $lines[] = '- ' . $d;
        }
        if ($lines !== []) {
            $out[] = "## Decisions\n\n" . implode("\n", $lines);
        }
    }
    $ai = $s['action_items'] ?? [];
    if (is_array($ai) && $ai !== []) {
        $lines = [];
        foreach ($ai as $it) {
            if (!is_array($it)) continue;
            $task = trim((string)($it['task'] ?? ''));
            if ($task === '') continue;
            $owner = trim((string)($it['owner'] ?? ''));
            $due = trim((string)($it['due'] ?? ''));
            $line = '- ' . $task;
            if ($owner !== '') $line .= ' (Owner: ' . $owner . ')';
            if ($due !== '') $line .= ' (Due: ' . $due . ')';
            $lines[] = $line;
        }
        if ($lines !== []) {
            $out[] = "## Action Items\n\n" . implode("\n", $lines);
        }
    }
    $risks = $s['risks'] ?? [];
    if (is_array($risks) && $risks !== []) {
        $lines = [];
        foreach ($risks as $d) {
            $d = trim((string)$d);
            if ($d !== '') $lines[] = '- ' . $d;
        }
        if ($lines !== []) {
            $out[] = "## Risks\n\n" . implode("\n", $lines);
        }
    }
    $notes = $s['notes'] ?? [];
    if (is_array($notes) && $notes !== []) {
        $lines = [];
        foreach ($notes as $d) {
            $d = trim((string)$d);
            if ($d !== '') $lines[] = '- ' . $d;
        }
        if ($lines !== []) {
            $out[] = "## Notes\n\n" . implode("\n", $lines);
        }
    }
    return trim(implode("\n\n", $out)) . "\n";
}

function mh_art_upsert_artifact(PDO $db, int $meetingId, string $recordId, string $kind, ?string $lang, string $localPath, array $meta): void
{
    $sha = mh_art_sha256_file($localPath);
    $bytes = is_file($localPath) ? (int)@filesize($localPath) : null;
    $stmt = $db->prepare("
        INSERT INTO mh_meeting_artifacts (meeting_id, record_id, kind, lang, local_path, sha256, bytes, status, meta_json, created_at_utc, updated_at_utc)
        VALUES (:m, :r, :k, :l, :p, :s, :b, 'ready', :j, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            local_path = VALUES(local_path),
            sha256 = VALUES(sha256),
            bytes = VALUES(bytes),
            status = 'ready',
            meta_json = VALUES(meta_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        ':m' => $meetingId,
        ':r' => $recordId !== '' ? $recordId : null,
        ':k' => $kind,
        ':l' => $lang !== null && trim($lang) !== '' ? trim($lang) : null,
        ':p' => $localPath,
        ':s' => $sha !== '' ? $sha : null,
        ':b' => $bytes !== null ? $bytes : null,
        ':j' => json_encode($meta, JSON_UNESCAPED_SLASHES),
    ]);
}

function mh_art_upsert_summary_index(PDO $db, int $meetingId, string $recordId, string $lang, string $summaryText, array $summaryJson): void
{
    $stmt = $db->prepare("
        INSERT INTO mh_meeting_summary_index (meeting_id, record_id, lang, summary_text, summary_json, created_at_utc, updated_at_utc)
        VALUES (:m, :r, :l, :t, :j, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            summary_text = VALUES(summary_text),
            summary_json = VALUES(summary_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        ':m' => $meetingId,
        ':r' => $recordId !== '' ? $recordId : null,
        ':l' => $lang !== '' ? $lang : null,
        ':t' => $summaryText,
        ':j' => json_encode($summaryJson, JSON_UNESCAPED_SLASHES),
    ]);
}

$tenantDbIds = mh_art_list_tenant_db_config_ids();
if ($tenantDbIds === []) {
    fwrite(STDERR, "no_tenant_dbs\n");
    exit(0);
}

$processedMeetings = 0;
$processedRecordings = 0;
$generatedTranscripts = 0;
$generatedSummaries = 0;
$memoryIngested = 0;

foreach ($tenantDbIds as $dbId) {
    $tenantId = function_exists('mh_find_tenant_id_by_db_config_id') ? mh_find_tenant_id_by_db_config_id($dbId) : null;
    if (!is_string($tenantId) || trim($tenantId) === '') {
        continue;
    }
    $tenantId = trim($tenantId);
    $tenantSafe = mh_art_tenant_safe($tenantId);

    try {
        $db = database_getConnectionById($dbId);
    } catch (Throwable) {
        continue;
    }
    if (!$db instanceof PDO) {
        continue;
    }
    calendar_ensure_tables($db);

    $stmt = $db->prepare("
        SELECT id, room_id, title, series_id, session_id, persona_mode, created_by_user
        FROM mh_meetings
        WHERE room_id IS NOT NULL AND room_id <> ''
        ORDER BY id DESC
        LIMIT 80
    ");
    $stmt->execute();
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($meetings) || $meetings === []) {
        continue;
    }

    foreach ($meetings as $m) {
        $meetingId = (int)($m['id'] ?? 0);
        $roomId = trim((string)($m['room_id'] ?? ''));
        if ($meetingId < 1 || $roomId === '') continue;
        $processedMeetings++;

        $title = trim((string)($m['title'] ?? ''));
        $createdBy = trim((string)($m['created_by_user'] ?? ''));
        $personaMode = trim((string)($m['persona_mode'] ?? ''));
        $sessionId = trim((string)($m['session_id'] ?? ''));
        $metaHumanId = $sessionId !== '' ? $sessionId : ('meeting:' . $roomId);
        $personaId = $personaMode !== '' ? $personaMode : ($createdBy !== '' ? ('MH-' . $createdBy) : 'MH-unknown');

        $root = mh_art_meeting_root($tenantSafe, $roomId);
        $recDir = $root . '/recordings';
        $trDir = $root . '/transcripts';
        $sumDir = $root . '/summaries';
        if (!is_dir($recDir)) continue;
        @mkdir($trDir, 0775, true);
        @mkdir($sumDir, 0775, true);

        $idxPath = $recDir . '/index.json';
        if (!is_file($idxPath)) continue;
        $idxRaw = (string)@file_get_contents($idxPath);
        $idx = $idxRaw !== '' ? json_decode($idxRaw, true) : null;
        if (!is_array($idx) || !is_array($idx['items'] ?? null)) continue;

        $agendaItems = [];
        $minutesMd = '';
        try {
            $a = $db->prepare("SELECT agenda_json, minutes_md FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
            $a->execute([$meetingId]);
            $ar = $a->fetch(PDO::FETCH_ASSOC);
            if (is_array($ar)) {
                $aj = isset($ar['agenda_json']) ? (string)$ar['agenda_json'] : '';
                $ajd = $aj !== '' ? json_decode($aj, true) : null;
                if (is_array($ajd) && is_array($ajd['items'] ?? null)) {
                    $agendaItems = (array)$ajd['items'];
                }
                $minutesMd = isset($ar['minutes_md']) ? (string)$ar['minutes_md'] : '';
            }
        } catch (Throwable) {
        }

        foreach ($idx['items'] as $it) {
            if (!is_array($it)) continue;
            $recordId = trim((string)($it['record_id'] ?? ''));
            $local = trim((string)($it['local_file'] ?? ''));
            if ($recordId === '' || $local === '') continue;
            $processedRecordings++;
            $prefix = mh_art_safe_id($recordId);

            $mediaPath = $recDir . '/' . basename($local);
            if (!is_file($mediaPath) || filesize($mediaPath) < 1024) continue;

            $existingVtt = '';
            $files = is_dir($trDir) ? scandir($trDir) : [];
            if (is_array($files)) {
                foreach ($files as $f) {
                    if (!is_string($f) || $f === '.' || $f === '..') continue;
                    if (strpos($f, $prefix . '_') !== 0) continue;
                    if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'vtt') continue;
                    $existingVtt = $trDir . '/' . $f;
                    break;
                }
            }

            $transcriptTxtPath = $trDir . '/' . $prefix . '_auto.txt';
            $transcriptMetaPath = $trDir . '/' . $prefix . '_auto.json';
            $transcript = '';
            if (is_file($transcriptTxtPath)) {
                $transcript = trim((string)@file_get_contents($transcriptTxtPath));
            }
            if ($transcript === '') {
                if ($existingVtt !== '' && is_file($existingVtt)) {
                    $vtt = (string)@file_get_contents($existingVtt);
                    $transcript = mh_art_vtt_to_text($vtt);
                    if ($transcript !== '') {
                        @file_put_contents($transcriptTxtPath, $transcript . "\n");
                        @file_put_contents($transcriptMetaPath, json_encode(['ok' => true, 'lane' => 'vtt', 'source' => basename($existingVtt)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                        $generatedTranscripts++;
                    }
                } else {
                    $r = mh_art_transcribe($mediaPath);
                    if (($r['ok'] ?? false) === true && is_string($r['text'] ?? null) && trim((string)$r['text']) !== '') {
                        $transcript = trim((string)$r['text']);
                        @file_put_contents($transcriptTxtPath, $transcript . "\n");
                        @file_put_contents($transcriptMetaPath, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                        $generatedTranscripts++;
                    }
                }
            }

            if ($transcript !== '') {
                mh_art_upsert_artifact($db, $meetingId, $recordId, 'transcript', 'auto', $transcriptTxtPath, [
                    'meeting_id' => $meetingId,
                    'room_id' => $roomId,
                    'record_id' => $recordId,
                    'source' => $existingVtt !== '' ? 'vtt' : 'asr',
                ]);
            }

            $summaryJsonPath = $sumDir . '/' . $prefix . '_summary.json';
            $summaryMdPath = $sumDir . '/' . $prefix . '_summary.md';
            $summaryRaw = is_file($summaryJsonPath) ? (string)@file_get_contents($summaryJsonPath) : '';
            $summaryDecoded = $summaryRaw !== '' ? json_decode($summaryRaw, true) : null;
            if (!is_array($summaryDecoded) && $transcript !== '') {
                $s = mh_art_summarize($title !== '' ? $title : $roomId, $transcript, $agendaItems, $minutesMd);
                if (($s['ok'] ?? false) === true && is_array($s['summary'] ?? null)) {
                    $payload = (array)$s['summary'];
                    $payload['_meta'] = [
                        'meeting_id' => $meetingId,
                        'room_id' => $roomId,
                        'record_id' => $recordId,
                        'created_at_utc' => gmdate('c'),
                        'model' => (string)(mh_art_llm_cfg()['model'] ?? ''),
                    ];
                    @file_put_contents($summaryJsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                    @file_put_contents($summaryMdPath, mh_art_summary_to_markdown($payload));
                    $summaryDecoded = $payload;
                    $generatedSummaries++;
                }
            }

            if (is_array($summaryDecoded)) {
                mh_art_upsert_artifact($db, $meetingId, $recordId, 'summary', 'en', $summaryJsonPath, [
                    'meeting_id' => $meetingId,
                    'room_id' => $roomId,
                    'record_id' => $recordId,
                ]);
                if (is_string($summaryDecoded['summary'] ?? null)) {
                    mh_art_upsert_summary_index($db, $meetingId, $recordId, 'en', (string)$summaryDecoded['summary'], $summaryDecoded);
                }
                try {
                    $ctx = [
                        'tenant_id' => $tenantId,
                        'persona_id' => $personaId,
                        'meta_human_id' => $metaHumanId,
                        'username' => $createdBy !== '' ? $createdBy : 'system',
                        'user_id' => $createdBy !== '' ? $createdBy : 'system',
                    ];
                    $text = (string)$summaryDecoded['summary'];
                    $tags = ['meeting', 'room:' . $roomId, 'record:' . $recordId];
                    if ((int)($m['series_id'] ?? 0) > 0) {
                        $tags[] = 'series:' . (int)$m['series_id'];
                    }
                    $r = mhw_memory_ingest_store_one($ctx, [
                        'kind' => 'meeting_summary',
                        'source' => 'meetings',
                        'text' => $text,
                        'tags' => $tags,
                        'idempotency_key' => 'meet_sum_' . $tenantSafe . '_' . $roomId . '_' . $prefix,
                    ]);
                    if (($r['ok'] ?? false) === true) {
                        $memoryIngested++;
                    }
                } catch (Throwable) {
                }
            }
        }
    }
}

fwrite(STDOUT, "ok meetings={$processedMeetings} recordings={$processedRecordings} transcripts={$generatedTranscripts} summaries={$generatedSummaries} memory={$memoryIngested}\n");
