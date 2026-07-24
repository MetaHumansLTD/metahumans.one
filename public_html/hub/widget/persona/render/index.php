<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_lib.php';
require_once __DIR__ . '/../../../../auth/asset_signing.php';

function mh_widget_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

function mh_widget_asset_url(string $absPath, int $ttlSeconds = 3600): string
{
    $exp = time() + max(60, (int)$ttlSeconds);
    $sig = mh_asset_sign($absPath, $exp);
    return 'https://metahumans.one/hub/genesis/asset.php?path=' . rawurlencode($absPath) . '&exp=' . $exp . '&sig=' . $sig;
}

function mh_widget_job_dir(string $personaRoot): string
{
    return $personaRoot . '/jobs/render';
}

function mh_widget_job_file(string $jobDir, string $jobId): string
{
    $jobId = mh_widget_safe_id($jobId);
    return $jobDir . '/job_' . $jobId . '.json';
}

function mh_widget_job_read(string $jobFile): ?array
{
    if (!is_file($jobFile)) return null;
    $raw = @file_get_contents($jobFile);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

function mh_widget_job_write(string $jobFile, array $job): void
{
    $dir = dirname($jobFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('mkdir_failed');
        }
    }
    $job['updated_at'] = gmdate('c');
    $json = json_encode($job, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    if (file_put_contents($jobFile, $json) === false) throw new RuntimeException('write_failed');
}

function mh_widget_spawn_worker(string $jobFile): bool
{
    if ($jobFile === '' || !is_file($jobFile)) return false;
    $php = defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $worker = __DIR__ . '/worker.php';
    if (!is_file($worker)) return false;
    $disabled = (string)ini_get('disable_functions');
    if ($disabled !== '' && preg_match('/(^|,)\\s*(exec|proc_open)\\s*(,|$)/i', $disabled)) {
        return false;
    }
    $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($jobFile);
    $cmd .= ' > /dev/null 2>&1 &';
    try {
        if (function_exists('proc_open')) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ];
            $p = @proc_open($cmd, $descriptors, $pipes);
            if (is_resource($p)) {
                @proc_close($p);
                return true;
            }
        }
        if (function_exists('exec')) {
            @exec($cmd);
            return true;
        }
    } catch (Throwable) {
        return false;
    }
    return false;
}

function mh_widget_http_get_json_with_session(string $url, int $timeoutSec = 6): ?array
{
    $sid = session_id();
    $sn = session_name();
    if ($sid === '' || $sn === '') return null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: metahumans.one',
        'Cookie: ' . $sn . '=' . $sid,
        'User-Agent: mh-widget-render/1',
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

function mh_widget_http_post_json_with_session(string $url, array $payload, int $timeoutSec = 6): ?array
{
    $sid = session_id();
    $sn = session_name();
    if ($sid === '' || $sn === '') return null;
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') return null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: metahumans.one',
        'Cookie: ' . $sn . '=' . $sid,
        'User-Agent: mh-widget-render/1',
        'Content-Type: application/json',
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

function mh_detect_preset(string $text): string
{
    $t = strtolower($text);
    if (preg_match('/\\b(wink)\\b/', $t)) return 'wink.pkl';
    if (preg_match('/\\b(crazy|insane|wild)\\b/', $t)) return 'shake_face.pkl';
    if (preg_match('/\\b(laugh|funny|haha|lol|happy|smile)\\b/', $t)) return 'laugh.pkl';
    if (preg_match('/\\b(cry|sad|unhappy|depressed|aggrieved)\\b/', $t)) return 'aggrieved.pkl';
    if (preg_match('/\\b(shy|blush)\\b/', $t)) return 'shy.pkl';
    if (preg_match('/\\b(surprise|shocked|wow)\\b/', $t)) return 'open_lip.pkl';
    return 'talking.pkl';
}

$ctx = mh_widget_require_auth();
$body = mh_widget_read_json_body();

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

$personaIn = isset($body['persona_id']) ? (string)$body['persona_id'] : '';
if ($personaIn === '') $personaIn = (string)($ctx['persona_id'] ?? '');
$personaSafe = mh_widget_safe_id($personaIn);

$tenantId = (string)($ctx['tenant_id'] ?? '');
$tenantSafe = mh_widget_safe_id(strtolower($tenantId));

$text = isset($body['text']) ? (string)$body['text'] : '';
$text = trim(preg_replace('/\\s+/', ' ', $text));
if ($text === '') $text = 'Hello. I am your persona.';
if (strlen($text) > 220) $text = substr($text, 0, 220);

$audioUrl = isset($body['audio_url']) ? trim((string)$body['audio_url']) : '';

$emotionPreset = mh_detect_preset($text);

$personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe;
$avatarPng = $personaRoot . '/assets/images/normalized/avatar.png';
$avatarDebug = mh_widget_http_get_json_with_session('http://127.0.0.1/hub/genesis/persona-images.php?persona=' . rawurlencode($personaIn) . '&debug=1', 6);
$avatarSizeHint = 0;
if (is_array($avatarDebug) && isset($avatarDebug['avatar_path']) && is_string($avatarDebug['avatar_path']) && $avatarDebug['avatar_path'] !== '' && !empty($avatarDebug['avatar_exists'])) {
    $avatarPng = (string)$avatarDebug['avatar_path'];
    $avatarSizeHint = isset($avatarDebug['avatar_size']) ? (int)$avatarDebug['avatar_size'] : 0;
    $personaRoot = preg_replace('#/assets/images/normalized/avatar\\.png$#', '', $avatarPng);
    $personaRoot = is_string($personaRoot) && $personaRoot !== '' ? $personaRoot : $personaRoot;
    $parts = explode('/', trim((string)$personaRoot, '/'));
    $last = is_array($parts) && count($parts) > 0 ? (string)$parts[count($parts) - 1] : '';
    if ($last !== '') $personaSafe = mh_widget_safe_id($last);
}

$avatarPngUrl = '';

$voiceRef = '/data/tenants/' . $tenantSafe . '/voices/' . $personaSafe . '/reference.wav';
$voiceMode = is_file($voiceRef) && filesize($voiceRef) > 1024 ? 'manual' : 'auto';

$cacheKey = hash('sha256', implode('|', [
    $personaSafe,
    $text,
    $audioUrl,
    $emotionPreset,
    $voiceMode,
    (string)($avatarSizeHint > 0 ? $avatarSizeHint : @filesize($avatarPng)),
    (string)@filemtime($voiceRef),
    (string)@filesize($voiceRef),
]));
$short = substr($cacheKey, 0, 12);

$outMp4 = $personaRoot . '/assets/video/previews/render_' . $short . '.mp4';
if (is_file($outMp4) && filesize($outMp4) > 4096) {
    mh_widget_json([
        'success' => true,
        'ok' => true,
        'persona_id' => $personaSafe,
        'video_url' => mh_widget_asset_url($outMp4, 3600),
        'preset' => $emotionPreset,
        'voice_mode' => $voiceMode,
    ]);
    exit;
}

$jobId = $short;
$jobDir = mh_widget_job_dir($personaRoot);
$jobFile = mh_widget_job_file($jobDir, $jobId);

if ($method === 'GET') {
    $qPersona = isset($_GET['persona_id']) ? (string)$_GET['persona_id'] : '';
    $qJob = isset($_GET['job_id']) ? (string)$_GET['job_id'] : '';
    $qPersonaSafe = mh_widget_safe_id($qPersona !== '' ? $qPersona : $personaSafe);
    $qJobSafe = mh_widget_safe_id($qJob);
    $qPersonaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $qPersonaSafe;
    $qJobFile = mh_widget_job_file(mh_widget_job_dir($qPersonaRoot), $qJobSafe);
    $j = mh_widget_job_read($qJobFile);
    if (!$j) {
        mh_widget_json(['success' => false, 'error' => 'job_not_found'], 404);
        exit;
    }
    if (isset($j['status']) && in_array((string)$j['status'], ['queued', 'running'], true)) {
        mh_widget_spawn_worker($qJobFile);
    }
    if (isset($j['status']) && $j['status'] === 'done' && isset($j['out_mp4']) && is_string($j['out_mp4']) && is_file($j['out_mp4']) && filesize($j['out_mp4']) > 4096) {
        $j['ok'] = true;
        $j['video_url'] = mh_widget_asset_url((string)$j['out_mp4'], 3600);
    }
    $j['success'] = true;
    mh_widget_json($j, 200);
    exit;
}

try {
    $existing = mh_widget_job_read($jobFile);
    if (is_array($existing) && isset($existing['status']) && in_array((string)$existing['status'], ['queued', 'running'], true)) {
        mh_widget_spawn_worker($jobFile);
        mh_widget_json([
            'success' => true,
            'ok' => false,
            'status' => (string)$existing['status'],
            'stage' => isset($existing['stage']) ? (string)$existing['stage'] : '',
            'job_id' => $jobId,
            'poll_url' => '/hub/widget/persona/render/?persona_id=' . rawurlencode($personaSafe) . '&job_id=' . rawurlencode($jobId),
        ], 202);
        exit;
    }

    mh_widget_job_write($jobFile, [
        'job_id' => $jobId,
        'persona_id' => $personaSafe,
        'tenant_id' => $tenantId,
        'meta_human_id' => (string)($ctx['meta_human_id'] ?? ('meta:' . $personaSafe)),
        'status' => 'queued',
        'stage' => 'queued',
        'created_at' => gmdate('c'),
        'preset' => $emotionPreset,
        'voice_mode' => $voiceMode,
        'text' => $text,
          'audio_url' => $audioUrl,
        'avatar_png' => $avatarPng,
        'avatar_png_url' => $avatarPngUrl,
        'voice_ref' => $voiceRef,
        'out_mp4' => $outMp4,
    ]);

    mh_widget_http_post_json_with_session('http://127.0.0.1/hub/widget/persona/memory/ingest.php', [
        'persona_id' => $personaSafe,
        'kind' => 'user_text',
        'source' => 'preview',
        'text' => $text,
        'tags' => ['preview', 'render'],
    ], 2);

    $spawned = mh_widget_spawn_worker($jobFile);
    if (!$spawned) {
        register_shutdown_function(function () use ($jobFile) {
            $worker = __DIR__ . '/worker.php';
            if (!is_file($worker)) return;
            require_once $worker;
            if (function_exists('mh_render_worker_run')) {
                mh_render_worker_run($jobFile);
            }
        });
    }

    mh_widget_json([
        'success' => true,
        'ok' => false,
        'status' => 'queued',
        'stage' => 'queued',
        'job_id' => $jobId,
        'poll_url' => '/hub/widget/persona/render/?persona_id=' . rawurlencode($personaSafe) . '&job_id=' . rawurlencode($jobId),
    ], 202);
    exit;
} catch (Throwable $e) {
    mh_widget_json(['success' => false, 'error' => $e->getMessage()], 500);
    exit;
}
