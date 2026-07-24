<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_context.php';

$ctx = mhw_require_context();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) $input = [];

$id = isset($input['id']) ? trim((string)$input['id']) : '';
$kind = isset($input['kind']) ? strtolower(trim((string)$input['kind'])) : 'audio';
$kind = $kind === 'voice' ? 'audio' : $kind;
if ($id === '' || !in_array($kind, ['audio'], true)) {
    mhw_json(['success' => false, 'error' => 'invalid_request', 'required' => ['id', 'kind=audio']], 400);
    exit;
}

$tenantRoot = mhw_get_tenant_root($ctx);
$personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
$inboxDir = $tenantRoot . '/inbox/' . $personaSafe;
$metaPath = $inboxDir . '/' . $kind . '_' . mhw_sanitize_id($id) . '.json';
if (!is_file($metaPath)) {
    mhw_json(['success' => false, 'error' => 'not_found'], 404);
    exit;
}

$meta = json_decode((string)file_get_contents($metaPath), true);
if (!is_array($meta)) {
    mhw_json(['success' => false, 'error' => 'invalid_meta'], 500);
    exit;
}

$filePath = isset($meta['stored']['path']) ? (string)$meta['stored']['path'] : (isset($meta['path']) ? (string)$meta['path'] : '');
if ($filePath === '' && isset($meta['filename']) && is_string($meta['filename'])) {
    $candidate = $inboxDir . '/' . basename((string)$meta['filename']);
    if (is_file($candidate)) {
        $filePath = $candidate;
    }
}
if ($filePath === '' || !is_file($filePath)) {
    mhw_json(['success' => false, 'error' => 'missing_file'], 500);
    exit;
}

$tenantReal = realpath($tenantRoot);
$fileReal = realpath($filePath);
if (!is_string($tenantReal) || $tenantReal === '' || !is_string($fileReal) || $fileReal === '' || strpos($fileReal, $tenantReal . DIRECTORY_SEPARATOR) !== 0) {
    mhw_json(['success' => false, 'error' => 'invalid_path'], 403);
    exit;
}

$asrMultipart = function(string $url, string $path, string $task): array {
    $ch = curl_init($url);
    $post = [
        'file' => new CURLFile($path),
        'task' => $task,
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    return ['resp' => $resp, 'err' => $err, 'status' => $status];
};

$toWav16kMono = function(string $srcPath): array {
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if ($ext === 'wav') {
        return ['ok' => true, 'path' => $srcPath, 'tmp' => false];
    }
    $tmpBase = tempnam(sys_get_temp_dir(), 'mhw_asr_');
    if (!is_string($tmpBase) || $tmpBase === '') {
        return ['ok' => false, 'error' => 'temp_failed'];
    }
    $dstPath = $tmpBase . '.wav';
    @unlink($tmpBase);
    $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($srcPath) . ' -ac 1 -ar 16000 ' . escapeshellarg($dstPath);
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0 || !is_file($dstPath) || filesize($dstPath) <= 44) {
        if (is_file($dstPath)) @unlink($dstPath);
        return ['ok' => false, 'error' => 'ffmpeg_failed', 'code' => $code, 'output' => implode("\n", $out)];
    }
    return ['ok' => true, 'path' => $dstPath, 'tmp' => true];
};

$host = 'https://meta.superhumans.one';
$mmsUrl = rtrim((string)(getenv('MHW_MMS_ASR_URL') ?: ($host . '/cortex-audio/mms-asr/v1/audio/transcriptions')), '/');
$fwUrl = rtrim((string)(getenv('MHW_FASTER_WHISPER_URL') ?: ($host . '/cortex-audio/faster-whisper/v1/audio/transcriptions')), '/');

$wav = $toWav16kMono($fileReal);
$mmsInputPath = ($wav['ok'] ?? false) ? (string)$wav['path'] : $fileReal;

$r1 = $asrMultipart($mmsUrl, $mmsInputPath, 'transcribe');
if ($r1['resp'] !== false && $r1['err'] === '' && $r1['status'] >= 200 && $r1['status'] < 300) {
    $body = json_decode((string)$r1['resp'], true);
    if (is_array($body) && is_string($body['text'] ?? null)) {
        if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
        mhw_json(['success' => true, 'lane' => 'mms_asr', 'text' => (string)$body['text'], 'raw' => $body]);
        exit;
    }
}

$r2 = $asrMultipart($fwUrl, $fileReal, 'transcribe');
if ($r2['resp'] !== false && $r2['err'] === '' && $r2['status'] >= 200 && $r2['status'] < 300) {
    $body2 = json_decode((string)$r2['resp'], true);
    if (is_array($body2) && is_string($body2['text'] ?? null)) {
        if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
        mhw_json(['success' => true, 'lane' => 'faster_whisper', 'text' => (string)$body2['text'], 'raw' => $body2]);
        exit;
    }
}

if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
mhw_json([
    'success' => false,
    'error' => 'asr_failed',
    'mms_asr' => ['status' => (int)$r1['status'], 'err' => (string)$r1['err']],
    'faster_whisper' => ['status' => (int)$r2['status'], 'err' => (string)$r2['err']],
], 502);
