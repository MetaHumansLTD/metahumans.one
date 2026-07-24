<?php
declare(strict_types=1);

function mh_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

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

function mh_job_read(string $jobFile): ?array
{
    if (!is_file($jobFile)) return null;
    $raw = @file_get_contents($jobFile);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

function mh_job_write(string $jobFile, array $job): void
{
    $dir = dirname($jobFile);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    $job['updated_at'] = gmdate('c');
    $json = json_encode($job, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    if (file_put_contents($jobFile, $json) === false) throw new RuntimeException('write_failed');
}

function mh_http_get_to_file(string $url, string $path, int $timeoutSec): void
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
    curl_setopt($ch, CURLOPT_USERAGENT, 'metahumans-render-worker/1');
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        @unlink($path);
        throw new RuntimeException('download_failed:' . $httpCode . ':' . ($err !== '' ? $err : ''));
    }
}

function mh_try_memory_ingest(array $job, string $kind, string $text, array $extra = []): void
{
    $tenantId = isset($job['tenant_id']) ? (string)$job['tenant_id'] : '';
    $personaId = isset($job['persona_id']) ? (string)$job['persona_id'] : '';
    $metaHumanId = isset($job['meta_human_id']) ? (string)$job['meta_human_id'] : ('meta:' . strtolower(mh_safe_id($personaId)));
    $payload = [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'kind' => $kind,
        'source' => 'render',
        'text' => $text,
        'tags' => ['render'],
        'extra' => $extra,
        'created_at' => gmdate('c'),
    ];
    $tenantSafe = strtolower(mh_safe_id($tenantId));
    $personaSafe = strtolower(mh_safe_id($personaId));
    if ($tenantSafe !== '' && $personaSafe !== '') {
        $dir = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/memory';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = $dir . '/events.jsonl';
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (is_string($line) && $line !== '') {
            @file_put_contents($path, $line . "\n", FILE_APPEND);
        }
    }

    $token = getenv('MH_WORKBENCH_TOKEN');
    if (!is_string($token) || trim($token) === '') return;
    $token = trim((string)$token);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://metahumans.one/hub/workbench/api/memory/ingest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_exec($ch);
    curl_close($ch);
}

function mh_post_json_bytes(string $url, array $payload, int $timeoutSec): string
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
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    return $resp;
}

function mh_post_multipart(string $url, array $fields, array $files, int $timeoutSec): array
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
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('upstream_invalid_json');
    return $j;
}

function mh_post_multipart_bytes(string $url, array $fields, array $files, int $timeoutSec): string
{
    $post = [];
    foreach ($fields as $k => $v) {
        $post[(string)$k] = (string)$v;
    }
    foreach ($files as $k => $path) {
        $p = (string)$path;
        if ($p == '' || !is_file($p)) throw new RuntimeException('missing_file');
        $post[(string)$k] = curl_file_create($p);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        $snippet = is_string($resp) ? substr($resp, 0, 300) : '';
        throw new RuntimeException('upstream_failed:' . $httpCode . ':' . ($err !== '' ? $err : $snippet));
    }
    return $resp;
}

function mh_rmdir_recursive(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $it) {
        if (!is_string($it) || $it === '.' || $it === '..') continue;
        $p = $path . '/' . $it;
        if (is_link($p) || is_file($p)) {
            @unlink($p);
        } elseif (is_dir($p)) {
            mh_rmdir_recursive($p);
        }
    }
    @rmdir($path);
}

set_time_limit(0);

function mh_render_worker_run(string $jobFile): void
{
    if ($jobFile === '' || !is_file($jobFile)) {
        return;
    }

    $lockFile = $jobFile . '.lock';
    $lockFp = fopen($lockFile, 'c');
    if ($lockFp === false) {
        return;
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        return;
    }

    $job = mh_job_read($jobFile);
    if (!$job) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        return;
    }

    try {
        $job['status'] = 'running';
        $job['stage'] = 'tts';
        mh_job_write($jobFile, $job);

        $personaId = isset($job['persona_id']) ? mh_safe_id((string)$job['persona_id']) : 'default';
        $text = isset($job['text']) ? (string)$job['text'] : '';
        $preset = isset($job['preset']) ? (string)$job['preset'] : 'talking.pkl';
        $voiceMode = isset($job['voice_mode']) ? (string)$job['voice_mode'] : 'auto';
          $audioUrlIn = isset($job['audio_url']) ? trim((string)$job['audio_url']) : '';
        $avatarPng = isset($job['avatar_png']) ? (string)$job['avatar_png'] : '';
        $avatarPngUrl = isset($job['avatar_png_url']) ? (string)$job['avatar_png_url'] : '';
        $voiceRef = isset($job['voice_ref']) ? (string)$job['voice_ref'] : '';
        $outMp4 = isset($job['out_mp4']) ? (string)$job['out_mp4'] : '';
        mh_try_memory_ingest($job, 'render_request', $text, ['preset' => (string)($job['preset'] ?? '')]);

        $tmpDir = sys_get_temp_dir() . '/mh_render_' . mh_safe_id((string)($job['job_id'] ?? 'job')) . '_' . bin2hex(random_bytes(4));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) throw new RuntimeException('mkdir_failed');

        if (($avatarPng === '' || !is_file($avatarPng) || filesize($avatarPng) < 1) && $avatarPngUrl !== '') {
            $downloaded = $tmpDir . '/avatar.png';
            mh_http_get_to_file($avatarPngUrl, $downloaded, 120);
            $avatarPng = $downloaded;
        }
        if ($avatarPng === '' || !is_file($avatarPng) || filesize($avatarPng) < 1) {
            throw new RuntimeException('avatar_missing');
        }

          $audioWav = $tmpDir . '/audio.wav';
          if ($audioUrlIn !== '') {
              $job['stage'] = 'audio';
              mh_job_write($jobFile, $job);
              mh_http_get_to_file($audioUrlIn, $audioWav, 300);
          } else {
              $job['stage'] = 'tts';
              mh_job_write($jobFile, $job);
              if ($voiceMode === 'manual' && is_file($voiceRef) && filesize($voiceRef) > 1024) {
                  $luxttsBase = getenv('LUXTTS_URL');
                  if (!is_string($luxttsBase) || trim($luxttsBase) === '') $luxttsBase = mh_superhumans_url('cortex-audio/luxtts');
                  $luxttsBase = rtrim(trim((string)$luxttsBase), '/');
                  $wav = mh_post_multipart_bytes($luxttsBase . '/v1/audio/speech', [
                      'text' => $text,
                      'num_steps' => '6',
                      't_shift' => '0.9',
                      'speed' => '1.0',
                      'rms' => '0.01',
                      'ref_duration' => '5.0',
                  ], [
                      'prompt_audio' => $voiceRef,
                  ], 300);
                  if (file_put_contents($audioWav, $wav) === false) throw new RuntimeException('tts_failed');
              } else {
                  $mmsBase = getenv('MMS_TTS_URL');
                  if (!is_string($mmsBase) || trim($mmsBase) === '') $mmsBase = mh_superhumans_url('cortex-audio/mms-tts');
                  $mmsBase = rtrim(trim((string)$mmsBase), '/');
                  $wav = mh_post_json_bytes($mmsBase . '/v1/audio/speech', ['text' => $text], 180);
                  if (file_put_contents($audioWav, $wav) === false) throw new RuntimeException('tts_failed');
              }
          }
          if (!is_file($audioWav) || filesize($audioWav) < 1024) throw new RuntimeException('audio_failed');

        $job['stage'] = 'motion';
        mh_job_write($jobFile, $job);


        $liveportraitBase = getenv('LIVEPORTRAIT_API_URL');
        if (!is_string($liveportraitBase) || trim($liveportraitBase) === '') $liveportraitBase = mh_superhumans_url('cortex-persona/liveportrait');
        $liveportraitBase = rtrim(trim((string)$liveportraitBase), '/');
        $lp = mh_post_multipart($liveportraitBase . '/v1/animate-preset-upload', [
            'persona_id' => $personaId,
            'preset' => $preset,
        ], [
            'image' => $avatarPng,
        ], 1800);
        $motionUrl = isset($lp['video_url']) ? trim((string)$lp['video_url']) : '';
        if ($motionUrl === '') throw new RuntimeException('liveportrait_failed');
        if (str_starts_with($motionUrl, '/')) $motionUrl = $liveportraitBase . $motionUrl;

        $motionMp4 = $tmpDir . '/motion.mp4';
        mh_http_get_to_file($motionUrl, $motionMp4, 1800);
        if (!is_file($motionMp4) || filesize($motionMp4) < 4096) throw new RuntimeException('liveportrait_failed');

        $job['stage'] = 'lipsync';
        mh_job_write($jobFile, $job);

        $musetalkBase = getenv('MUSETALK_API_URL');
        if (!is_string($musetalkBase) || trim($musetalkBase) === '') $musetalkBase = mh_superhumans_url('cortex-persona/musetalk');
        $musetalkBase = rtrim(trim((string)$musetalkBase), '/');
        $mt = mh_post_multipart($musetalkBase . '/v1/lipsync-upload', [
            'persona_id' => $personaId,
        ], [
            'audio' => $audioWav,
            'video' => $motionMp4,
        ], 1800);
        $finalUrl = isset($mt['video_url']) ? trim((string)$mt['video_url']) : '';
        if ($finalUrl === '') throw new RuntimeException('musetalk_failed');
        if (str_starts_with($finalUrl, '/')) $finalUrl = $musetalkBase . $finalUrl;

        $job['stage'] = 'download';
        mh_job_write($jobFile, $job);

        mh_http_get_to_file($finalUrl, $outMp4, 1800);
        if (!is_file($outMp4) || filesize($outMp4) < 4096) throw new RuntimeException('render_failed');

        mh_rmdir_recursive($tmpDir);

        $job['status'] = 'done';
        $job['stage'] = 'done';
        $job['error'] = '';
        mh_job_write($jobFile, $job);
        mh_try_memory_ingest($job, 'render_result', 'render_done', ['out_mp4' => $outMp4]);
    } catch (Throwable $e) {
        $job = mh_job_read($jobFile) ?: [];
        $job['status'] = 'error';
        $job['stage'] = isset($job['stage']) ? (string)$job['stage'] : 'error';
        $job['error'] = $e->getMessage();
        mh_job_write($jobFile, $job);
        mh_try_memory_ingest($job, 'render_error', (string)$e->getMessage(), ['stage' => (string)($job['stage'] ?? '')]);
    }

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

$jobFile = isset($argv[1]) ? (string)$argv[1] : '';
if (PHP_SAPI === 'cli') {
    mh_render_worker_run($jobFile);
    exit(0);
}
