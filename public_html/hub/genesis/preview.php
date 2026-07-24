<?php

require_once __DIR__ . '/../../auth/asset_signing.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function mh_superhumans_base_url(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $cfg = [];
    $raw = @file_get_contents('/data/config/superhumans.json');
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $cfg = $decoded;
    }
    $baseUrlEnv = getenv('SUPERHUMANS_BASE_URL');
    if (is_string($baseUrlEnv) && trim($baseUrlEnv) !== '') {
        $cfg['base_url'] = trim($baseUrlEnv);
    }
    $base = isset($cfg['base_url']) && is_string($cfg['base_url']) ? trim((string)$cfg['base_url']) : '';
    if ($base === '') {
        $base = 'https://meta.superhumans.one';
    }
    $cached = rtrim($base, '/');
    return $cached;
}

function mh_superhumans_url(string $path): string
{
    $path = '/' . ltrim((string)$path, '/');
    return mh_superhumans_base_url() . $path;
}

function mh_genesis_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s;
}

function mh_genesis_tenant_id(string $username): string
{
    $t = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($t !== '') return $t;
    $u = trim((string)$username);
    return $u !== '' ? ('user:' . $u) : '';
}

function mh_genesis_persona_name(string $username): string
{
    $p = isset($_POST['persona']) ? trim((string)$_POST['persona']) : '';
    if ($p === '') {
        $p = isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
    }
    if ($p === '') $p = 'Master';
    return $p;
}

function mh_genesis_preview_text(): string
{
    $t = isset($_POST['text']) ? (string)$_POST['text'] : '';
    $t = trim(preg_replace('/\\s+/', ' ', $t));
    if ($t === '') {
        $t = 'Hello. I am your persona.';
    }
    if (strlen($t) > 220) {
        $t = substr($t, 0, 220);
    }
    return $t;
}

function mh_genesis_preview_tts_engine(): string
{
    $t = isset($_POST['tts_engine']) ? (string)$_POST['tts_engine'] : '';
    $t = strtolower(trim($t));
    if ($t === '') $t = 'mms';
    if (!in_array($t, ['mms', 'luxtts', 'cosyvoice'], true)) $t = 'mms';
    return $t;
}

function mh_genesis_signed_asset_url(string $absPath, int $ttlSeconds = 3600): string
{
    $exp = time() + max(60, (int)$ttlSeconds);
    $sig = mh_asset_sign($absPath, $exp);
    return 'https://metahumans.one/hub/genesis/asset.php?path=' . rawurlencode($absPath) . '&exp=' . $exp . '&sig=' . $sig;
}

function mh_genesis_write_wav_sine(string $path, float $freqHz, float $secs, int $rate = 16000): void
{
    $rate = max(8000, (int)$rate);
    $secs = max(0.2, (float)$secs);
    $n = (int)round($rate * $secs);
    $ampl = 0.2;
    $data = '';
    for ($i = 0; $i < $n; $i++) {
        $v = (int)round($ampl * 32767.0 * sin(2.0 * M_PI * $freqHz * $i / $rate));
        if ($v < -32768) $v = -32768;
        if ($v > 32767) $v = 32767;
        $data .= pack('v', $v & 0xFFFF);
    }
    $subchunk2Size = strlen($data);
    $chunkSize = 36 + $subchunk2Size;
    $hdr = '';
    $hdr .= "RIFF";
    $hdr .= pack('V', $chunkSize);
    $hdr .= "WAVE";
    $hdr .= "fmt ";
    $hdr .= pack('V', 16);
    $hdr .= pack('v', 1);
    $hdr .= pack('v', 1);
    $hdr .= pack('V', $rate);
    $hdr .= pack('V', $rate * 2);
    $hdr .= pack('v', 2);
    $hdr .= pack('v', 16);
    $hdr .= "data";
    $hdr .= pack('V', $subchunk2Size);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (file_put_contents($path, $hdr . $data) === false) throw new RuntimeException('write_failed');
}

function mh_http_get_to_file(string $url, string $path, int $timeoutSec = 1800): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    $fp = fopen($path, 'wb');
    if ($fp === false) throw new RuntimeException('write_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_USERAGENT, 'metahumans-genesis/1');
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        @unlink($path);
        throw new RuntimeException('download_failed');
    }
}

function mh_post_json(string $url, array $payload, int $timeoutSec = 1800): array
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
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('upstream_failed');
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('upstream_invalid_json');
    return $j;
}

function mh_post_multipart(string $url, array $fields, array $files, int $timeoutSec = 1800): array
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
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('upstream_failed');
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('upstream_invalid_json');
    return $j;
}

function mh_post_multipart_bytes(string $url, array $fields, array $files, int $timeoutSec = 1800): string
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
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('upstream_failed');
    }
    return $resp;
}

function mh_post_json_bytes(string $url, array $payload, int $timeoutSec = 1800): string
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
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('upstream_failed');
    }
    return $resp;
}

function mh_genesis_tts_mms_to_file(string $text, string $dstWav): void
{
    $base = mh_asset_env('MMS_TTS_URL');
    if ($base === '') {
        $base = mh_superhumans_url('cortex-audio/mms-tts');
    }
    $base = rtrim($base, '/');
    $wav = mh_post_json_bytes($base . '/v1/audio/speech', ['text' => $text], 120);
    $dir = dirname($dstWav);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (file_put_contents($dstWav, $wav) === false) throw new RuntimeException('write_failed');
    if (!is_file($dstWav) || filesize($dstWav) < 1024) throw new RuntimeException('tts_failed');
}

function mh_genesis_tts_cosyvoice_to_file(string $text, string $promptWav, string $dstWav): void
{
    $base = mh_asset_env('COSYVOICE_URL');
    if ($base === '') {
        $base = mh_superhumans_url('cortex-audio/cosyvoice');
    }
    $base = rtrim($base, '/');
    $wav = mh_post_multipart_bytes($base . '/v1/audio/speech', ['text' => $text, 'prompt_text' => ''], ['prompt_audio' => $promptWav], 180);
    $dir = dirname($dstWav);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (file_put_contents($dstWav, $wav) === false) throw new RuntimeException('write_failed');
    if (!is_file($dstWav) || filesize($dstWav) < 1024) throw new RuntimeException('tts_failed');
}

function mh_genesis_tts_luxtts_to_file(string $text, string $promptWav, string $dstWav): void
{
    $base = mh_asset_env('LUXTTS_URL');
    if ($base === '') {
        $base = mh_superhumans_url('cortex-audio/luxtts');
    }
    $base = rtrim($base, '/');
    $wav = mh_post_multipart_bytes(
        $base . '/v1/audio/speech',
        [
            'text' => $text,
            'num_steps' => '6',
            't_shift' => '0.9',
            'speed' => '1.0',
            'rms' => '0.01',
            'ref_duration' => '5.0',
        ],
        ['prompt_audio' => $promptWav],
        240
    );
    $dir = dirname($dstWav);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (file_put_contents($dstWav, $wav) === false) throw new RuntimeException('write_failed');
    if (!is_file($dstWav) || filesize($dstWav) < 1024) throw new RuntimeException('tts_failed');
}

function mh_genesis_save_upload(string $field, string $dstPath, int $maxBytes): bool
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return false;
    $f = $_FILES[$field];
    $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return false;
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('upload_failed');
    $tmp = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('upload_failed');
    $size = isset($f['size']) ? (int)$f['size'] : 0;
    if ($size <= 0 || $size > $maxBytes) throw new RuntimeException('upload_too_large');
    $dir = dirname($dstPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (!move_uploaded_file($tmp, $dstPath)) throw new RuntimeException('upload_failed');
    @chmod($dstPath, 0600);
    return true;
}

try {
    if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
        exit;
    }
    set_time_limit(0);
    $username = trim((string)$_SESSION['mh_auth_user']);
    $tenantId = mh_genesis_tenant_id($username);
    $tenantSafe = mh_genesis_safe_id($tenantId);
    if ($tenantSafe === '') throw new RuntimeException('invalid_tenant');

    $personaName = mh_genesis_persona_name($username);
    $personaId = mh_genesis_safe_id($personaName);
    if ($personaId === '') $personaId = 'master';
    $previewText = mh_genesis_preview_text();
    $ttsEngine = mh_genesis_preview_tts_engine();

    $personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaId;
    $avatarPng = $personaRoot . '/assets/images/normalized/avatar.png';
    if (!is_file($avatarPng) || filesize($avatarPng) < 1024) throw new RuntimeException('avatar_missing');

    $promptWav = $personaRoot . '/assets/audio/tts_prompt.wav';
    $motionVideo = $personaRoot . '/assets/video/motion_ref.mp4';
    mh_genesis_save_upload('prompt_audio', $promptWav, 15 * 1024 * 1024);
    mh_genesis_save_upload('motion_video', $motionVideo, 120 * 1024 * 1024);

    $cacheKey = hash(
        'sha256',
        implode('|', [
            $personaId,
            $previewText,
            $ttsEngine,
            (string)@filemtime($avatarPng),
            (string)@filesize($avatarPng),
            (string)@filemtime($promptWav),
            (string)@filesize($promptWav),
            (string)@filemtime($motionVideo),
            (string)@filesize($motionVideo),
        ])
    );
    $short = substr($cacheKey, 0, 12);

    $audioWav = $personaRoot . '/assets/audio/preview_' . $short . '.wav';
    $videoOut = $personaRoot . '/assets/video/lipsync_preview_' . $short . '.mp4';
    if (is_file($videoOut) && filesize($videoOut) > 4096) {
        $signedOutUrl = mh_genesis_signed_asset_url($videoOut, 3600);
        echo json_encode(['ok' => true, 'persona_id' => $personaId, 'video_url' => $signedOutUrl], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!is_file($audioWav) || filesize($audioWav) < 1024) {
        try {
            if ($ttsEngine === 'cosyvoice') {
                if (!is_file($promptWav) || filesize($promptWav) <= 1024) throw new RuntimeException('missing_prompt_audio');
                mh_genesis_tts_cosyvoice_to_file($previewText, $promptWav, $audioWav);
            } elseif ($ttsEngine === 'luxtts') {
                if (!is_file($promptWav) || filesize($promptWav) <= 1024) throw new RuntimeException('missing_prompt_audio');
                mh_genesis_tts_luxtts_to_file($previewText, $promptWav, $audioWav);
            } else {
                mh_genesis_tts_mms_to_file($previewText, $audioWav);
            }
        } catch (Throwable $e) {
            if ($ttsEngine === 'cosyvoice' || $ttsEngine === 'luxtts') {
                if ($e->getMessage() === 'missing_prompt_audio') throw $e;
                throw new RuntimeException('tts_failed');
            }
            mh_genesis_write_wav_sine($audioWav, 220.0, 1.2);
        }
    }

    $musetalkBase = mh_asset_env('MUSETALK_PROXY_URL');
    if ($musetalkBase === '') {
        $musetalkBase = mh_superhumans_url('cortex-persona/musetalk');
    }
    $musetalkBase = rtrim($musetalkBase, '/');
    if (is_file($motionVideo) && filesize($motionVideo) > 4096) {
        $resp = mh_post_multipart($musetalkBase . '/v1/lipsync-upload', [
            'persona_id' => $personaId,
        ], [
            'audio' => $audioWav,
            'video' => $motionVideo,
        ], 1800);
    } else {
        $resp = mh_post_multipart($musetalkBase . '/v1/lipsync-image-upload', [
            'persona_id' => $personaId,
        ], [
            'audio' => $audioWav,
            'image' => $avatarPng,
        ], 1800);
    }

    $outUrl = isset($resp['video_url']) ? trim((string)$resp['video_url']) : '';
    if ($outUrl === '') throw new RuntimeException('missing_output_url');
    if (str_starts_with($outUrl, '/')) {
        $outUrl = $musetalkBase . $outUrl;
    }

    mh_http_get_to_file($outUrl, $videoOut, 1800);

    $signedOutUrl = mh_genesis_signed_asset_url($videoOut, 3600);
    echo json_encode(['ok' => true, 'persona_id' => $personaId, 'video_url' => $signedOutUrl], JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
