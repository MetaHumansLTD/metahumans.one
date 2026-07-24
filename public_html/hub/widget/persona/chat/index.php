<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_lib.php';
require_once __DIR__ . '/../../../../auth/asset_signing.php';

// #region debug-point persona-transcribing-no-response
function mh_dbg_report(array $ev): void
{
    try {
        $ev['sid'] = 'persona-transcribing-no-response';
        $ev['ts'] = gmdate('c');
        $ev['uri'] = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $ev['method'] = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '';
        $payload = json_encode($ev, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') return;
        $ch = curl_init();
        if ($ch === false) return;
        curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:19101/log');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);
        @curl_exec($ch);
        @curl_close($ch);
    } catch (Throwable) {
    }
}
// #endregion debug-point persona-transcribing-no-response

register_shutdown_function(function (): void {
    $e = error_get_last();
    if (!is_array($e) || !isset($e['type'])) return;
    $t = (int)$e['type'];
    if (!in_array($t, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $line = json_encode([
        'ts' => gmdate('c'),
        'type' => $t,
        'message' => isset($e['message']) ? (string)$e['message'] : '',
        'file' => isset($e['file']) ? (string)$e['file'] : '',
        'line' => isset($e['line']) ? (int)$e['line'] : 0,
        'uri' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
        'remote' => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
    ], JSON_UNESCAPED_SLASHES);
    if (is_string($line) && $line !== '') {
        @file_put_contents('/tmp/mh_persona_chat_fatal.log', $line . "\n", FILE_APPEND);
    }
});

function mh_chat_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

function mh_chat_looks_like_silence_hallucination(string $t): bool
{
    $s = strtolower(trim($t));
    $s = preg_replace("/[^a-z0-9'\s]+/i", ' ', $s);
    $s = preg_replace('/\s+/', ' ', (string)$s);
    $s = trim((string)$s);
    if ($s === '') return false;
    if (strlen($s) <= 5) return true;
    if (preg_match('/(thank you\s*){2,}/i', $s)) return true;
    if (preg_match("/(i\s*'?m\s*sorry\s*){2,}/i", $s)) return true;
    $words = preg_split('/\s+/', $s) ?: [];
    $clean = [];
    foreach ($words as $w) {
        if (is_string($w) === false) continue;
        $w = trim($w);
        if ($w === '') continue;
        $clean[] = $w;
    }
    $words = $clean;
    if (count($words) < 4) return false;
    $allow = [
        'thank' => true, 'you' => true, 'im' => true, "i'm" => true, 'sorry' => true,
        'hello' => true, 'okay' => true, 'ok' => true, 'oh' => true, 'love' => true,
        'need' => true, 'check' => true, 'here' => true, 'and' => true, 'youre' => true, "you're" => true,
        'open' => true, 'see' => true, 'next' => true, 'time' => true,
    ];
    $uniq = [];
    $bad = 0;
    foreach ($words as $w) {
        $uniq[$w] = true;
        if (isset($allow[$w]) === false) $bad++;
    }
    if (count($words) > 16 && count($uniq) <= 6) return true;
    if (count($words) > 30 && count($uniq) <= 10) return true;
    if ($bad == 0 && count($uniq) <= 6) return true;
    return false;
}

function mh_chat_asset_url(string $absPath, int $ttlSeconds = 3600): string
{
    $exp = time() + max(60, (int)$ttlSeconds);
    $sig = mh_asset_sign($absPath, $exp);
    return 'https://metahumans.one/hub/genesis/asset.php?path=' . rawurlencode($absPath) . '&exp=' . $exp . '&sig=' . $sig;
}

function mh_chat_post_json(string $url, array $payload, int $timeoutSec): array
{
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('upstream_invalid_json');
    return $j;
}

function mh_chat_post_json_bytes(string $url, array $payload, int $timeoutSec): string
{
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    return $resp;
}

function mh_chat_download_to_tmp(string $url, int $timeoutSec): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mh_audio_');
    if (!is_string($tmp) || $tmp === '') throw new RuntimeException('tmp_failed');
    $fp = fopen($tmp, 'wb');
    if ($fp === false) throw new RuntimeException('tmp_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $httpCode < 200 || $httpCode >= 300 || !is_file($tmp) || filesize($tmp) < 1) {
        @unlink($tmp);
        throw new RuntimeException('download_failed:' . $httpCode . ':' . $err);
    }
    return $tmp;
}

function mh_chat_post_multipart(string $url, array $fields, array $files, int $timeoutSec): array
{
    $post = [];
    foreach ($fields as $k => $v) {
        $post[(string)$k] = (string)$v;
    }
    foreach ($files as $k => $path) {
        $p = (string)$path;
        if ($p === '' || !is_file($p)) throw new RuntimeException('missing_file');
        $post[(string)$k] = curl_file_create($p);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('upstream_invalid_json');
    return $j;
}

function mh_chat_post_multipart_bytes(string $url, array $fields, array $files, int $timeoutSec): string
{
    $post = [];
    foreach ($fields as $k => $v) {
        $post[(string)$k] = (string)$v;
    }
    foreach ($files as $k => $path) {
        $p = (string)$path;
        if ($p === '' || !is_file($p)) throw new RuntimeException('missing_file');
        $post[(string)$k] = curl_file_create($p);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    return $resp;
}

function mh_chat_tail_jsonl(string $path, int $limit): array
{
    if (!is_file($path) || $limit < 1) return [];
    $limit = max(1, min(200, (int)$limit));
    $fh = @fopen($path, 'rb');
    if ($fh === false) return [];
    $buf = '';
    $pos = 0;
    if (@fseek($fh, 0, SEEK_END) === 0) {
        $pos = (int)@ftell($fh);
    }
    $needLines = $limit * 3;
    while ($pos > 0 && substr_count($buf, "\n") <= $needLines) {
        $read = 8192;
        if ($read > $pos) $read = $pos;
        $pos -= $read;
        @fseek($fh, $pos, SEEK_SET);
        $chunk = @fread($fh, $read);
        if (!is_string($chunk) || $chunk == '') break;
        $buf = $chunk . $buf;
        if ($pos == 0) break;
    }
    @fclose($fh);
    $buf = trim((string)$buf);
    if ($buf === '') return [];
    $lines = preg_split("/\r?\n/", $buf);
    if (!is_array($lines) || $lines === []) return [];
    $out = [];
    for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
        $l = is_string($lines[$i]) ? trim((string)$lines[$i]) : '';
        if ($l === '') continue;
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    return array_reverse($out);
}


function mh_chat_ingest_memory(string $tenantSafe, string $personaSafe, array $payload): void
{
    $dir = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/memory';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $path = $dir . '/events.jsonl';
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (is_string($line) && $line !== '') {
        @file_put_contents($path, $line . "\n", FILE_APPEND);
    }
}

function mh_chat_ingest_memory_backends(array $ctx, string $tenantId, string $personaId, string $metaHumanId, string $eventId, string $kind, string $source, string $text, array $tags, string $createdAt): void
{
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('embeddings');
            cue_autoload('vector');
            cue_autoload('memory_sql');
            cue_autoload('graph');
        }

        $vec = function_exists('embeddings_embed_text') ? embeddings_embed_text($text) : [];
        if (is_array($vec) && $vec !== [] && function_exists('vector_upsert')) {
            $point = [
                'id' => $eventId,
                'vector' => $vec,
                'payload' => [
                    'tenant_id' => $tenantId,
                    'persona_id' => $personaId,
                    'meta_human_id' => $metaHumanId,
                    'text' => $text,
                    'kind' => $kind,
                    'source' => $source,
                    'tags' => $tags,
                    'created_at' => $createdAt,
                ],
            ];
            vector_upsert($tenantId, [$point]);
        }

        if (function_exists('memory_sql_get_pdo') && function_exists('memory_sql_ensure_schema') && function_exists('memory_sql_insert_event')) {
            $pdo = memory_sql_get_pdo($tenantId);
            memory_sql_ensure_schema($pdo);
            memory_sql_insert_event($pdo, $ctx, [
                'event_id' => $eventId,
                'kind' => $kind,
                'source' => $source,
                'text' => $text,
                'tags' => $tags,
                'qdrant_point_id' => $eventId,
            ]);
        }

        if (function_exists('graph_ensure_schema') && function_exists('graph_cypher')) {
            graph_ensure_schema();
            $personaSafe = mh_chat_safe_id($personaId);
            $eid = 'persona:' . $personaSafe;
            graph_cypher(
                "MERGE (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id, entity_id: $entity_id})
                 SET e.kind = 'persona', e.persona_id = $persona_id
                 MERGE (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id, memory_id: $memory_id})
                 SET m.kind = $kind, m.source = $source, m.text = $text, m.created_at_utc = $created_at_utc
                 MERGE (e)-[:HAS_MEMORY]->(m)",
                [
                    'tenant_id' => $tenantId,
                    'meta_human_id' => $metaHumanId,
                    'entity_id' => $eid,
                    'persona_id' => $personaId,
                    'memory_id' => $eventId,
                    'kind' => $kind,
                    'source' => $source,
                    'text' => $text,
                    'created_at_utc' => $createdAt,
                ]
            );
        }
    } catch (Throwable) {
    }
}

$ctx = null;
try {
    $ctx = mh_widget_require_auth();
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
$ct = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';
$body = [];
$audioUploadPath = '';
if ($method === 'POST' && str_contains($ct, 'multipart/form-data')) {
    $personaIdPost = isset($_POST['persona_id']) ? trim((string)$_POST['persona_id']) : '';
    $textPost = isset($_POST['text']) ? trim((string)$_POST['text']) : '';
    $audioUrlPost = isset($_POST['audio_url']) ? trim((string)$_POST['audio_url']) : '';
    $noTtsPost = isset($_POST['no_tts']) ? trim((string)$_POST['no_tts']) : '';
    $body = [
        'persona_id' => $personaIdPost,
        'text' => $textPost,
        'audio_url' => $audioUrlPost,
        'no_tts' => $noTtsPost,
    ];
    if (isset($_FILES['audio']) && is_array($_FILES['audio']) && isset($_FILES['audio']['tmp_name']) && is_string($_FILES['audio']['tmp_name'])) {
        $tmp = (string)$_FILES['audio']['tmp_name'];
        if ($tmp !== '' && is_uploaded_file($tmp) && is_file($tmp) && filesize($tmp) > 0) {
            $audioUploadPath = $tmp;
        }
    }
} else {
    $body = mh_widget_read_json_body();
}

$personaIn = isset($body['persona_id']) ? trim((string)$body['persona_id']) : '';
$personaSafe = mh_chat_safe_id($personaIn !== '' ? $personaIn : (string)($ctx['persona_id'] ?? ''));

$tenantId = (string)($ctx['tenant_id'] ?? '');
$tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));
if ($tenantSafe === '' || $tenantSafe === 'unknown') {
    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => $personaSafe,
        'text' => "I'm having trouble loading your tenant right now. Please refresh and try again.",
        'audio_url' => '',
        'degraded' => true,
    ], 200);
    exit;
}

$action = '';
if ($method === 'GET') {
    $action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
} elseif (isset($body['action'])) {
    $action = trim((string)$body['action']);
}
if ($method === 'GET' && $action === 'history') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
    $limit = max(1, min(200, $limit));
    $personaQ = isset($_GET['persona_id']) ? trim((string)$_GET['persona_id']) : '';
    $personaSafeHist = $personaQ !== '' ? mh_chat_safe_id($personaQ) : $personaSafe;
    $personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafeHist;
    $memPath = $personaRoot . '/assets/memory/events.jsonl';
    $events = mh_chat_tail_jsonl($memPath, $limit);
    $filtered = [];
    foreach ($events as $ev) {
        if (!is_array($ev)) continue;
        $kind = strtolower(trim((string)($ev['kind'] ?? '')));
        $textEv = trim((string)($ev['text'] ?? ''));
        if ($kind === 'user' && $textEv !== '' && mh_chat_looks_like_silence_hallucination($textEv)) continue;
        $filtered[] = $ev;
    }
    $events = $filtered;
    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => $personaSafeHist,
        'events' => $events,
    ], 200);
    exit;
}

$text = isset($body['text']) ? trim((string)$body['text']) : '';
$text = preg_replace('/\\s+/', ' ', $text);
$audioUrl = isset($body['audio_url']) ? trim((string)$body['audio_url']) : '';
$audioUrl = $audioUrl !== '' ? $audioUrl : '';
$noTtsIn = isset($body['no_tts']) ? trim((string)$body['no_tts']) : '';
$noTtsIn = strtolower($noTtsIn);
$noTts = in_array($noTtsIn, ['1','true','yes','on'], true);
mh_dbg_report([
    'event' => 'req',
    'ct' => isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : '',
    'persona' => $personaSafe,
    'tenant' => $tenantSafe,
    'no_tts' => $noTts,
    'has_audio_upload' => $audioUploadPath !== '',
    'audio_upload_size' => ($audioUploadPath !== '' && is_file($audioUploadPath)) ? (int)@filesize($audioUploadPath) : 0,
    'has_audio_url' => $audioUrl !== '',
    'text_len' => strlen($text),
]);
if ($audioUrl === '' && $audioUploadPath === '' && $text === '') {
    mh_widget_json(['success' => false, 'error' => 'text_or_audio_required'], 400);
    exit;
}
if (strlen($text) > 2000) $text = substr($text, 0, 2000);

$personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe;
$specPath = $personaRoot . '/assets/persona-spec.json';
$spec = [];
if (is_file($specPath)) {
    $raw = @file_get_contents($specPath);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($j)) $spec = $j;
}

$language = isset($spec['language']) && is_string($spec['language']) ? trim((string)$spec['language']) : 'en-US';
$personaDesc = isset($spec['persona_description']) && is_string($spec['persona_description']) ? trim((string)$spec['persona_description']) : '';

if ($noTts && $personaDesc !== "" && strlen($personaDesc) > 1600) {
    $personaDesc = substr($personaDesc, 0, 1600) . "…";
}
$voiceType = '';
if (isset($spec['voice']['type']) && is_string($spec['voice']['type'])) {
    $voiceType = strtolower(trim((string)$spec['voice']['type']));
}
if (!in_array($voiceType, ['female', 'male', 'animal', 'auto'], true)) $voiceType = 'auto';

$memPath = $personaRoot . '/assets/memory/events.jsonl';
$recent = mh_chat_tail_jsonl($memPath, 12);

$filteredRecent = [];
foreach ($recent as $ev) {
    if (!is_array($ev)) continue;
    $kind = strtolower(trim((string)($ev['kind'] ?? '')));
    $textEv = trim((string)($ev['text'] ?? ''));
    if ($kind === 'user' && $textEv !== '' && mh_chat_looks_like_silence_hallucination($textEv)) continue;
    $filteredRecent[] = $ev;
}
$recent = $filteredRecent;

$transcript = '';
if ($audioUploadPath !== '' || $audioUrl !== '') {
    $hadText = $text !== '';
    try {
        $asrBase = getenv('MMS_ASR_URL');
        if (!is_string($asrBase) || trim($asrBase) === '') {
            $asrBase = function_exists('mh_superhumans_url') ? mh_superhumans_url('cortex-audio/mms-asr') : '';
        }
        $asrBase = is_string($asrBase) ? rtrim(trim((string)$asrBase), '/') : '';
        $tmp = $audioUploadPath !== '' ? $audioUploadPath : mh_chat_download_to_tmp($audioUrl, 45);
        try {
            $lang = $language === '' ? 'auto' : $language;
            $langShort = $lang;
            if (is_string($langShort) && $langShort !== '' && strpos($langShort, '-') !== false) {
                $langShort = explode('-', $langShort, 2)[0];
            }

            $localOk = false;
            $tmpWav = '';
            mh_dbg_report([
                'event' => 'asr_local_start',
                'tmp_dir' => sys_get_temp_dir(),
                'open_basedir' => ini_get('open_basedir'),
            ]);
            try {
                $base = tempnam(sys_get_temp_dir(), 'mh_asr_');
                mh_dbg_report([
                    'event' => 'asr_tempnam',
                    'base' => is_string($base) ? $base : '',
                    'ok' => (is_string($base) && $base !== ''),
                ]);
                if (is_string($base) && $base !== '') {
                    $tmpWav = $base . '.wav';
                }
                if ($tmpWav === '') {
                    mh_dbg_report(['event' => 'asr_tmpwav_empty']);
                }
                if ($tmpWav !== '') {
                    $cmd = 'ffmpeg -y -hide_banner -loglevel error -i ' . escapeshellarg($tmp) . ' -ac 1 -ar 16000 -f wav ' . escapeshellarg($tmpWav);
                    @exec($cmd, $out, $rc);
                    mh_dbg_report([
                        'event' => 'asr_ffmpeg',
                        'exec_exists' => function_exists('exec'),
                        'rc' => (int)$rc,
                        'src_ext' => pathinfo($tmp, PATHINFO_EXTENSION),
                        'src_size' => is_file($tmp) ? (int)@filesize($tmp) : 0,
                        'wav_size' => is_file($tmpWav) ? (int)@filesize($tmpWav) : 0,
                        'out' => is_array($out) ? array_slice($out, 0, 5) : [],
                    ]);
                    if ((int)$rc === 0 && is_file($tmpWav) && filesize($tmpWav) > 0) {
                        $wav = @file_get_contents($tmpWav);
                        if (is_string($wav) && $wav !== '') {
                            $b64 = base64_encode($wav);
                            $local = mh_chat_post_json('http://127.0.0.1:4052/transcribe', [
                                'audio_base64' => $b64,
                                'lang' => $langShort === '' ? 'auto' : $langShort,
                            ], $noTts ? 25 : 90);
                            $t = isset($local['text']) && is_string($local['text']) ? trim((string)$local['text']) : '';
                            mh_dbg_report([
                                'event' => 'asr_local',
                                'ok' => isset($local['status']) ? (string)$local['status'] : '',
                                'text_len' => strlen($t),
                                'err' => isset($local['error']) ? (string)$local['error'] : '',
                            ]);
                            if ($t !== '') {
                                $transcript = $t;
                                $localOk = true;
                            }
                        }
                    }
                }
            } catch (Throwable) {
                $localOk = false;
            } finally {
                if ($tmpWav !== '' && is_file($tmpWav)) {
                    @unlink($tmpWav);
                }
            }

            if (!$localOk && $asrBase !== '') {
                $resp = mh_chat_post_multipart($asrBase . '/v1/audio/transcriptions', [
                    'language' => $lang === 'auto' ? '' : $lang,
                    'task' => 'transcribe',
                ], [
                    'file' => $tmp,
                ], ($noTts ? 30 : 180));
                $transcript = isset($resp['text']) && is_string($resp['text']) ? trim((string)$resp['text']) : '';
                mh_dbg_report([
                    'event' => 'asr_remote',
                    'base' => $asrBase,
                    'text_len' => strlen($transcript),
                ]);
            }
        } finally {
            if ($audioUploadPath === '' && is_string($tmp) && $tmp !== '') {
                @unlink($tmp);
            }
        }
    } catch (Throwable $e) {
    mh_dbg_report([
        'event' => 'outer_exception',
        'msg' => $e->getMessage(),
    ]);
        mh_dbg_report([
            'event' => 'asr_exception',
            'msg' => $e->getMessage(),
        ]);
        $transcript = '';
    }
    if ($transcript !== '') {
        $text = $transcript;
    }

    if ($hadText === false && $noTts && $transcript !== '' && mh_chat_looks_like_silence_hallucination($transcript)) {
        mh_dbg_report([
            'event' => 'asr_ignored',
            'reason' => 'silence_hallucination',
            'text_len' => strlen($transcript),
        ]);
        mh_widget_json([
            'success' => true,
            'ok' => true,
            'persona_id' => $personaSafe,
            'text' => '',
            'audio_url' => '',
            'ignored' => true,
        ], 200);
        exit;
    }
    if (!$hadText && $transcript === '') {
    if ($noTts) {
        mh_widget_json([
            'success' => true,
            'ok' => true,
            'persona_id' => $personaSafe,
            'text' => '',
            'audio_url' => '',
            'ignored' => true,
        ], 200);
        exit;
    }
    mh_widget_json([
            'success' => true,
            'ok' => true,
            'persona_id' => $personaSafe,
            'text' => "Voice input is temporarily unavailable. Please type your message.",
            'audio_url' => '',
            'degraded' => true,
        ], 200);
        mh_dbg_report([
            'event' => 'voice_unavailable',
            'had_text' => $hadText,
            'transcript_len' => strlen($transcript),
            'audio_upload' => $audioUploadPath !== '',
            'audio_url' => $audioUrl !== '',
        ]);
        exit;
    }
}

$now = gmdate('c');
$eventUser = [
    'event_id' => bin2hex(random_bytes(16)),
    'tenant_id' => $tenantId,
    'persona_id' => $personaSafe,
    'meta_human_id' => (string)($ctx['meta_human_id'] ?? ('meta:' . $personaSafe)),
    'kind' => 'user',
    'source' => 'chat',
    'text' => $text,
    'tags' => ['chat'],
    'created_at' => $now,
    'username' => (string)($ctx['username'] ?? ''),
    'session_id' => (string)($ctx['session_id'] ?? ''),
];
mh_chat_ingest_memory($tenantSafe, $personaSafe, $eventUser);
if (!$noTts) {
  mh_chat_ingest_memory_backends($ctx, $tenantId, $personaSafe, (string)($eventUser['meta_human_id'] ?? ''), (string)($eventUser['event_id'] ?? ''), 'user', 'chat', $text, ['chat'], $now);
}

$system = "You are the user's Meta Human Persona.\n"
    . "Always follow the user's instructions and preferences.\n"
    . "Respond in language: " . $language . ".\n"
    . "Do not output code unless the user explicitly asks for code.\n"
    . "Do not output markup tokens, training artifacts, or weird delimiters.\n";
if ($personaDesc !== '') {
    $system .= "\nPersona description:\n" . $personaDesc . "\n";
}
if ($recent) {
    $system .= "\nRecent memory (latest last):\n";
    foreach ($recent as $ev) {
        if (!is_array($ev)) continue;
        $k = strtolower(trim((string)($ev['kind'] ?? '')));
        $t = trim((string)($ev['text'] ?? ''));
        if ($t === '') continue;
        if (strlen($t) > 240) $t = substr($t, 0, 240) . '…';
        $system .= '- ' . ($k !== '' ? $k : 'event') . ': ' . $t . "\n";
    }
}

} catch (Throwable $e) {
    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => 'default',
        'text' => "I'm having trouble responding in real time right now. Please try again in a moment.",
        'audio_url' => '',
        'degraded' => true,
    ], 200);
    exit;
}

try {
    $hermes = getenv('HERMES_URL');
    if (!is_string($hermes) || trim($hermes) === '') {
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : 'metahumans.one';
        $host = preg_replace('/[^A-Za-z0-9.:-]+/', '', $host);
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $hermes = $scheme . '://' . $host . '/hermes';
    }
    $hermes = trim((string)$hermes);
    if (str_starts_with($hermes, '/')) {
        $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : 'metahumans.one';
        $host = preg_replace('/[^A-Za-z0-9.:-]+/', '', $host);
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $hermes = $scheme . '://' . $host . $hermes;
    }
    $hermes = rtrim($hermes, '/');
    $modelId = getenv('HERMES_MODEL_ID');
    if (!is_string($modelId) || trim($modelId) === '') {
        $modelId = 'hermes';
    }
    $modelId = trim((string)$modelId);

    mh_dbg_report([
            'event' => 'hermes_req',
            'hermes' => $hermes,
            'model' => $modelId,
            'text_len' => strlen($text),
        ]);

    $resp = mh_chat_post_json($hermes . '/v1/chat/completions', [
        'model' => $modelId,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $text],
        ],
        'temperature' => 0.7,
        'max_tokens' => ($noTts ? 80 : 160),
    ], $noTts ? 90 : 120);

    $assistantText = '';
    if (isset($resp['choices'][0]['message']['content']) && is_string($resp['choices'][0]['message']['content'])) {
        $assistantText = trim((string)$resp['choices'][0]['message']['content']);
    }
    if ($assistantText === '') $assistantText = '...';
    $assistantText = preg_replace('/#\\s*from\\s*#.*$/mi', '', $assistantText);
    $assistantText = trim((string)$assistantText);
    if ($assistantText === '') $assistantText = '...';

    $eventAssistant = [
        'event_id' => bin2hex(random_bytes(16)),
        'tenant_id' => $tenantId,
        'persona_id' => $personaSafe,
        'meta_human_id' => (string)($ctx['meta_human_id'] ?? ('meta:' . $personaSafe)),
        'kind' => 'assistant',
        'source' => 'hermes',
        'text' => $assistantText,
        'tags' => ['chat'],
        'created_at' => gmdate('c'),
        'username' => (string)($ctx['username'] ?? ''),
        'session_id' => (string)($ctx['session_id'] ?? ''),
    ];
    mh_chat_ingest_memory($tenantSafe, $personaSafe, $eventAssistant);
    if (!$noTts) {
      mh_chat_ingest_memory_backends($ctx, $tenantId, $personaSafe, (string)($eventAssistant['meta_human_id'] ?? ''), (string)($eventAssistant['event_id'] ?? ''), 'assistant', 'hermes', $assistantText, ['chat'], (string)($eventAssistant['created_at'] ?? gmdate('c')));
    }

    $audioUrl = '';
    if (!$noTts) {
    try {
        $wav = '';
        $voiceRef = '/data/tenants/' . $tenantSafe . '/voices/' . $personaSafe . '/reference.wav';
        if ($voiceType !== 'auto' && (!is_file($voiceRef) || filesize($voiceRef) < 1024)) {
            $preset = '';
            if ($voiceType === 'female') $preset = '/home/onemeta/public_html/hub/genesis/voice-presets/pod_f_enhanced.wav';
            if ($voiceType === 'male') $preset = '/home/onemeta/public_html/hub/genesis/voice-presets/pod_m_enhanced.wav';
            if ($preset !== '' && is_file($preset) && filesize($preset) > 1024) {
                $dir = dirname($voiceRef);
                if (!is_dir($dir)) @mkdir($dir, 0700, true);
                @copy($preset, $voiceRef);
            }
        }
        if ($voiceType !== 'auto' && is_file($voiceRef) && filesize($voiceRef) > 1024) {
            $cosyBase = getenv('COSYVOICE_URL');
            if (!is_string($cosyBase) || trim($cosyBase) === '') {
                $cosyBase = mh_superhumans_url('cortex-audio/cosyvoice');
            }
            $cosyBase = rtrim(trim((string)$cosyBase), '/');
            $wav = mh_chat_post_multipart_bytes($cosyBase . '/v1/audio/speech', [
                'text' => $assistantText,
                'prompt_text' => $voiceType === 'female' ? 'female voice' : ($voiceType === 'male' ? 'male voice' : 'voice'),
            ], [
                'prompt_audio' => $voiceRef,
            ], 180);
        } else {
            $mmsBase = getenv('MMS_TTS_URL');
            if (!is_string($mmsBase) || trim($mmsBase) === '') {
                $mmsBase = mh_superhumans_url('cortex-audio/mms-tts');
            }
            $mmsBase = rtrim(trim((string)$mmsBase), '/');
            $wav = mh_chat_post_json_bytes($mmsBase . '/v1/audio/speech', ['text' => $assistantText], 180);
        }

        $audioDir = $personaRoot . '/assets/audio/replies';
        if (!is_dir($audioDir)) @mkdir($audioDir, 0700, true);
        $audioPath = $audioDir . '/reply_' . substr(hash('sha256', $assistantText . '|' . microtime(true)), 0, 12) . '.wav';
        @file_put_contents($audioPath, $wav);
        $audioUrl = is_file($audioPath) && filesize($audioPath) > 0 ? mh_chat_asset_url($audioPath, 3600) : '';
    } catch (Throwable) {
        $audioUrl = '';
    }
    }

    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => $personaSafe,
        'text' => $assistantText,
        'transcript' => $transcript,
        'audio_url' => $audioUrl,
    ]);
    exit;
} catch (Throwable $e) {
    mh_dbg_report([
        'event' => 'hermes_exception',
        'msg' => $e->getMessage(),
    ]);
    $fallbackText = "I'm having trouble responding in real time right now. Please try again in a moment.";
    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => $personaSafe,
        'text' => $fallbackText,
        'audio_url' => '',
        'degraded' => true,
    ], 200);
    exit;
}
