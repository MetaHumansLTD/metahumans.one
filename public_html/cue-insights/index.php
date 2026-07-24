<?php
declare(strict_types=1);

$__cueDebug = getenv('CUE_DEBUG') ?: '';
if ($__cueDebug === '1' || $__cueDebug === 'true') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

require_once __DIR__ . '/../.cue/cue.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CUE-GPU-TOKEN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ci_send(int $status, array $data): void {
    http_response_code($status);
    $encoded = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        $encoded = '{"error":"json_encode_failed"}';
    }
    echo $encoded;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ci_send(405, ['error' => 'Method not allowed']);
}

$gatewayToken = getenv('CUE_INSIGHTS_TOKEN');
if (is_string($gatewayToken) && trim($gatewayToken) !== '') {
    $hdr = $_SERVER['HTTP_X_CUE_GPU_TOKEN'] ?? '';
    if (!hash_equals($gatewayToken, $hdr)) {
        ci_send(401, ['error' => 'Unauthorized']);
    }
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    ci_send(400, ['error' => 'Invalid JSON']);
}

$endpoint = $_GET['endpoint'] ?? null;
if (!is_string($endpoint) || $endpoint === '') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $endpoint = trim(preg_replace('#^/cue-insights#', '', $path), '/');
}

switch ($endpoint) {
    case 'transcribe':
        ci_handle_transcribe($body);
        break;
    case 'translate':
        ci_handle_translate($body);
        break;
    case 'chat':
        ci_handle_chat($body);
        break;
    case 'speak':
        ci_handle_speak($body);
        break;
    case 'summarize-text':
        ci_handle_summarize_text($body);
        break;
    case 'summarize-audio':
        ci_handle_summarize_audio($body);
        break;
    case 'summarize-status':
        ci_handle_summarize_status($body);
        break;
    default:
        ci_send(404, ['error' => 'Unknown endpoint']);
}

function ci_get_tmp_file(string $suffix): string {
    $dir = sys_get_temp_dir();
    return $dir . '/' . uniqid('cueins_', true) . $suffix;
}


function ci_run_whisper_local(string $localPath, string $lang): array {
    $binary = @file_get_contents($localPath);
    if ($binary === false) {
        return ['status' => 'error', 'message' => 'Failed to read temp audio file'];
    }

    $payload = json_encode(['audio_base64' => base64_encode($binary), 'lang' => $lang], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) {
        return ['status' => 'error', 'message' => 'Failed to encode request'];
    }

    $ch = curl_init('http://127.0.0.1:4052/transcribe');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;

    if ($res === false || $res === '') {
        return ['status' => 'error', 'message' => 'Transcribe gateway error', 'debug' => $err ?: null];
    }

    $data = json_decode($res, true);
    if ($http !== 200 || !is_array($data) || ($data['status'] ?? '') !== 'success') {
        return ['status' => 'error', 'message' => 'Transcription failed', 'debug' => $data ?: $res];
    }

    return $data;
}

function ci_run_whisper(string $localPath, string $lang): array {
    $r = ci_run_whisper_superhumans($localPath, $lang);
    if (($r['status'] ?? '') === 'success') return $r;
    return ci_run_whisper_local($localPath, $lang);
}

function ci_norm_lang(string $lang): string {
    $lang = trim($lang);
    if ($lang === '') return 'auto';
    if ($lang === 'auto') return 'auto';
    if (strpos($lang, '-') !== false) {
        $lang = explode('-', $lang, 2)[0];
    }
    return strtolower($lang);
}

function ci_run_whisper_superhumans(string $localPath, string $lang): array {
    $binary = @file_get_contents($localPath);
    if ($binary === false) {
        return ['status' => 'error', 'message' => 'Failed to read temp audio file'];
    }

    $host = 'https://meta.superhumans.one';
    $mmsUrl = getenv('MHW_MMS_ASR_URL');
    if (!is_string($mmsUrl) || trim($mmsUrl) === '') $mmsUrl = $host . '/cortex-audio/mms-asr/v1/audio/transcriptions';
    $fwUrl = getenv('MHW_FASTER_WHISPER_URL');
    if (!is_string($fwUrl) || trim($fwUrl) === '') $fwUrl = $host . '/cortex-audio/faster-whisper/v1/audio/transcriptions';

    $lang = ci_norm_lang($lang);

    $post = function (string $url) use ($binary, $lang): ?array {
        $tmp = ci_get_tmp_file('.wav');
        if (file_put_contents($tmp, $binary) === false) return null;
        $cfile = curl_file_create($tmp, 'audio/wav', 'audio.wav');
        $fields = ['file' => $cfile, 'task' => 'transcribe'];
        if ($lang !== 'auto') {
            $fields['language'] = $lang;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 240);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $ch = null;
        @unlink($tmp);
        if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) return ['status' => 'error', 'message' => 'ASR failed', 'debug' => $err ?: $res];
        $j = json_decode($res, true);
        if (!is_array($j) || !is_string($j['text'] ?? null)) return ['status' => 'error', 'message' => 'ASR invalid response', 'debug' => $res];
        $text = trim((string)$j['text']);
        if ($text === '') return ['status' => 'error', 'message' => 'ASR empty'];
        return ['status' => 'success', 'text' => $text, 'language' => $lang !== 'auto' ? $lang : (is_string($j['language'] ?? null) ? (string)$j['language'] : 'auto')];
    };

    $r = $post($mmsUrl);
    if (is_array($r) && ($r['status'] ?? '') === 'success') return $r;
    $r2 = $post($fwUrl);
    if (is_array($r2) && ($r2['status'] ?? '') === 'success') return $r2;

    return is_array($r2) ? $r2 : ['status' => 'error', 'message' => 'ASR failed'];
}

function ci_ollama_generate(string $prompt, string $model, array $extraParams = []): array {
    $ollamaHost = function_exists('mh_internal_endpoint_url') ? (string)mh_internal_endpoint_url('ollama') : '';
    if (!is_string($ollamaHost) || trim($ollamaHost) === '') {
        $ollamaHost = getenv('OLLAMA_HOST') ?: 'https://promptengine.one';
    }
    $url = rtrim($ollamaHost, '/') . '/api/generate';
    $gpuAuthToken = getenv('GPU_AUTH_TOKEN') ?: null;

    $payload = array_merge([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
    ], $extraParams);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $headers = ['Content-Type: application/json'];
    if ($gpuAuthToken) {
        $headers[] = 'Authorization: Bearer ' . $gpuAuthToken;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;

    if ($err) {
        return ['error' => $err];
    }
    if ($status < 200 || $status >= 300) {
        return ['error' => 'HTTP ' . $status, 'body' => $body];
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['error' => 'Invalid JSON from Ollama'];
    }
    return ['data' => $data];
}

function ci_build_chat_prompt(array $history): string {
    $buf = '';
    foreach ($history as $item) {
        $role = isset($item['role']) ? strtolower((string)$item['role']) : 'user';
        $text = (string)($item['text'] ?? '');
        if ($text === '') {
            continue;
        }
        if ($role === 'assistant' || $role === 'model') {
            $buf .= "[AI]: " . $text . "\n\n";
        } elseif ($role === 'system') {
            $buf .= "[System]: " . $text . "\n\n";
        } else {
            $buf .= "[User]: " . $text . "\n\n";
        }
    }
    return $buf;
}

function ci_handle_transcribe(array $body): void {
    $audioBase64 = $body['audio_base64'] ?? '';
    if ($audioBase64 === '') {
        ci_send(400, ['error' => 'audio_base64 is required']);
    }
    $binary = base64_decode($audioBase64, true);
    if ($binary === false) {
        ci_send(400, ['error' => 'Invalid base64 audio']);
    }

    $tmpPath = ci_get_tmp_file('.wav');
    if (file_put_contents($tmpPath, $binary) === false) {
        ci_send(500, ['error' => 'Failed to write temp audio file']);
    }

    $lang = (string)($body['lang'] ?? 'auto');
    $transLangs = [];
    if (isset($body['trans_langs']) && is_array($body['trans_langs'])) {
        foreach ($body['trans_langs'] as $l) {
            $transLangs[] = (string)$l;
        }
    }

    $result = ci_run_whisper($tmpPath, $lang);
    @unlink($tmpPath);

    if (($result['status'] ?? '') !== 'success') {
        ci_send(500, ['error' => $result['message'] ?? 'Transcription failed', 'details' => $result['debug'] ?? null]);
    }

    $translations = [];
    if (!empty($transLangs)) {
        $translations = ci_translate_text($result['text'], $result['language'], $transLangs);
    }

    ci_send(200, [
        'text' => $result['text'],
        'lang' => $result['language'],
        'translations' => $translations,
    ]);
}

function ci_translate_text(string $text, string $sourceLang, array $targetLangs): array {
    $translations = [];
    $host = 'https://meta.superhumans.one';
    $nmtUrl = getenv('NMT_URL');
    if (!is_string($nmtUrl) || trim($nmtUrl) === '') $nmtUrl = $host . '/cortex-audio/nmt-translate/api/v1/translate';
    $src = ci_norm_lang($sourceLang);
    if ($src === 'auto') $src = 'en';

    foreach ($targetLangs as $target) {
        $tgt = ci_norm_lang((string)$target);
        $payload = json_encode(['src' => $src, 'tgt' => $tgt, 'text' => $text], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) continue;
        $ch = curl_init($nmtUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $ch = null;
        if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) {
            $model = getenv('CUE_TRANSLATE_MODEL') ?: 'phi4:latest';
            $prompt = "Translate the following text from {$sourceLang} to {$target}. Return only the translated text.\n\n" . $text;
            $resp = ci_ollama_generate($prompt, $model);
            if (isset($resp['data']['response'])) {
                $translations[(string)$target] = trim((string)$resp['data']['response']);
            }
            continue;
        }
        $j = json_decode($res, true);
        if (!is_array($j) || !is_string($j['translation'] ?? null)) {
            continue;
        }
        $translations[(string)$target] = trim((string)$j['translation']);
    }
    return $translations;
}

function ci_handle_translate(array $body): void {
    $text = (string)($body['text'] ?? '');
    $source = (string)($body['source_lang'] ?? 'en-US');
    $targets = $body['target_langs'] ?? [];
    if ($text === '' || !is_array($targets) || empty($targets)) {
        ci_send(400, ['error' => 'text and target_langs are required']);
    }

    $targetLangs = [];
    foreach ($targets as $t) {
        $targetLangs[] = (string)$t;
    }

    $translations = ci_translate_text($text, $source, $targetLangs);
    ci_send(200, ['translations' => $translations]);
}

function ci_handle_chat(array $body): void {
    $history = $body['history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }
    $messages = [];
    foreach ($history as $m) {
        if (!is_array($m)) continue;
        $role = isset($m['role']) && is_string($m['role']) ? strtolower(trim((string)$m['role'])) : 'user';
        if (!in_array($role, ['system', 'user', 'assistant'], true)) $role = 'user';
        $text = isset($m['text']) && is_string($m['text']) ? trim((string)$m['text']) : '';
        if ($text === '') continue;
        $messages[] = ['role' => $role, 'content' => $text];
    }
    if ($messages === []) {
        ci_send(400, ['error' => 'history is required']);
    }

    $resp = ci_ai_chat($messages);
    if (($resp['ok'] ?? false) !== true) {
        ci_send(502, ['error' => $resp['error'] ?? 'upstream_failed', 'body' => $resp['body'] ?? null]);
    }

    $text = trim((string)($resp['reply'] ?? ''));
    if ($text === '') {
        ci_send(502, ['error' => 'empty_reply']);
    }

    ci_send(200, [
        'text' => $text,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ]);
}

function ci_ai_chat(array $messages): array {
    $url = 'https://metahumans.one/ai/chat.php';
    $payload = json_encode(['messages' => $messages], JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload) || $payload === '') {
        return ['ok' => false, 'error' => 'json_encode_failed'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);

    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;

    if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => 'HTTP ' . ($http ?: 0), 'body' => is_string($res) ? $res : null, 'detail' => $err ?: null];
    }

    $data = json_decode($res, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'invalid_json', 'body' => $res];
    }

    $reply = '';
    if (is_string($data['reply'] ?? null)) {
        $reply = (string)$data['reply'];
    }
    if ($reply === '' && is_array($data['raw'] ?? null) && is_string($data['raw']['reply'] ?? null)) {
        $reply = (string)$data['raw']['reply'];
    }

    return ['ok' => true, 'reply' => $reply, 'raw' => $data];
}


function ci_voicebox_base_url(): string {
    $url = getenv('VOICEBOX_URL');
    if (!is_string($url) || trim($url) === '') $url = 'http://127.0.0.1:17493';
    return rtrim(trim($url), '/');
}

function ci_voicebox_output_dir(): string {
    $dir = getenv('VOICEBOX_OUTPUT_DIR');
    if (!is_string($dir) || trim($dir) === '') $dir = '/opt/voicebox/output';
    return rtrim(trim($dir), '/');
}

function ci_voicebox_json(string $method, string $path, ?array $body, int $timeoutSeconds): array {
    $base = ci_voicebox_base_url();
    $url = $base . $path;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

    $headers = [];
    if ($body !== null) {
        $payload = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload) || $payload === '') return ['ok' => false, 'http' => 0, 'error' => 'json_encode_failed'];
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    if ($headers !== []) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;

    if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) {
        return ['ok' => false, 'http' => $http, 'error' => 'HTTP ' . ($http ?: 0), 'body' => is_string($res) ? $res : null];
    }

    $data = json_decode($res, true);
    if (!is_array($data)) return ['ok' => false, 'http' => $http, 'error' => 'invalid_json', 'body' => $res];
    return ['ok' => true, 'http' => $http, 'data' => $data];
}

function ci_voicebox_get_or_create_preset_profile(string $engine, string $voiceId): string {
    $engine = trim($engine);
    $voiceId = trim($voiceId);
    if ($engine === '' || $voiceId === '') return '';

    $resp = ci_voicebox_json('GET', '/profiles', null, 15);
    if (($resp['ok'] ?? false) === true && is_array($resp['data'] ?? null)) {
        foreach ($resp['data'] as $p) {
            if (!is_array($p)) continue;
            if (($p['voice_type'] ?? null) !== 'preset') continue;
            if (($p['preset_engine'] ?? null) !== $engine) continue;
            if (($p['preset_voice_id'] ?? null) !== $voiceId) continue;
            if (is_string($p['id'] ?? null) && trim((string)$p['id']) !== '') return (string)$p['id'];
        }
    }

    $create = ci_voicebox_json('POST', '/profiles', [
        'name' => 'Preset ' . $engine . ' ' . $voiceId,
        'language' => 'en',
        'voice_type' => 'preset',
        'preset_engine' => $engine,
        'preset_voice_id' => $voiceId,
        'default_engine' => $engine,
    ], 20);

    if (($create['ok'] ?? false) === true && is_array($create['data'] ?? null) && is_string($create['data']['id'] ?? null)) {
        return (string)$create['data']['id'];
    }

    return '';
}

function ci_tts_voicebox_wav(string $text, string $engine = 'kokoro', string $voiceId = 'af_bella'): string {
    $text = trim($text);
    if ($text === '') return '';

    $profileId = ci_voicebox_get_or_create_preset_profile($engine, $voiceId);
    if ($profileId === '') {
        $profileId = getenv('VOICEBOX_DEFAULT_PROFILE');
        if (!is_string($profileId)) $profileId = '';
        $profileId = trim($profileId);
    }
    if ($profileId === '') return '';

    $resp = ci_voicebox_json('POST', '/speak', [
        'text' => $text,
        'profile' => $profileId,
    ], 20);

    if (($resp['ok'] ?? false) !== true) return '';
    $data = $resp['data'] ?? null;
    if (!is_array($data) || !is_string($data['id'] ?? null)) return '';
    $gid = trim((string)$data['id']);
    if ($gid === '') return '';

    $outDir = ci_voicebox_output_dir();
    $wavPath = $outDir . '/' . $gid . '.wav';

    $deadline = microtime(true) + 30.0;
    while (microtime(true) < $deadline) {
        if (is_file($wavPath)) {
            $sz = @filesize($wavPath);
            if (is_int($sz) && $sz > 44) {
                $wav = @file_get_contents($wavPath);
                if (is_string($wav) && $wav !== '') return $wav;
            }
        }
        usleep(250000);
    }

    return '';
}


function ci_tts_supertonic_wav(string $text, string $voice = 'F1', string $lang = 'en'): string {
    $text = trim($text);
    if ($text === '') return '';

    $url = getenv('SUPERTONIC_URL');
    if (!is_string($url) || trim($url) === '') $url = 'http://127.0.0.1:17655/v1/audio/speech';

    $payload = json_encode(['text' => $text, 'voice' => $voice, 'lang' => $lang], JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($payload) || $payload === '') return '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;

    if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) return '';
    return $res;
}

function ci_tts_base_wav(string $text): string {
    $text = trim($text);
    if ($text === '') return '';

    $st = ci_tts_supertonic_wav($text, 'F1', 'en');
    if ($st !== '') return $st;

    $vb = ci_tts_voicebox_wav($text, 'kokoro', 'af_bella');
    if ($vb !== '') return $vb;

    $host = 'https://meta.superhumans.one';
    $url = getenv('MHW_MMS_TTS_URL');
    if (!is_string($url) || trim($url) === '') $url = $host . '/cortex-audio/mms-tts/v1/audio/speech';

    $payload = json_encode(['text' => $text], JSON_INVALID_UTF8_SUBSTITUTE);
    if ($payload === false) return '';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) return '';
    return $res;
}

function ci_personaplex_convert(string $wavBinary, string $voice, string $prompt): string {
    if ($wavBinary === '') return '';
    $url = getenv('PERSONAPLEX_OFFLINE_URL');
    if (!is_string($url) || trim($url) === '') {
        $base = function_exists('mh_internal_endpoint_url') ? (string)mh_internal_endpoint_url('personaplex') : '';
        if (is_string($base) && trim($base) !== '') {
            $url = rtrim(trim($base), '/') . '/api/offline';
        }
    }
    if (!is_string($url) || trim($url) === '') $url = 'https://meta.superhumans.one/cortex-audio/personaplex/api/offline';
    $tmp = ci_get_tmp_file('.wav');
    if (file_put_contents($tmp, $wavBinary) === false) return '';
    $fields = [
        'file' => curl_file_create($tmp, 'audio/wav', 'input.wav'),
        'voice_prompt' => (substr($voice, -3) === '.pt') ? $voice : ($voice . '.pt'),
        'text_prompt' => $prompt !== '' ? $prompt : 'You are a helpful assistant.',
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    @unlink($tmp);
    if (!is_string($res) || $res === '' || $http < 200 || $http >= 300) return '';
    return $res;
}

function ci_handle_speak(array $body): void {
    $text = isset($body['text']) && is_string($body['text']) ? trim((string)$body['text']) : '';
    if ($text === '') {
        ci_send(400, ['error' => 'text is required']);
    }

    $speechEngine = isset($body['speech_engine']) && is_string($body['speech_engine']) ? strtolower(trim((string)$body['speech_engine'])) : 'classic';
    if (!in_array($speechEngine, ['classic', 'personaplex', 'voicebox'], true)) $speechEngine = 'classic';

    $ppVoice = isset($body['personaplex_voice']) && is_string($body['personaplex_voice']) ? strtoupper(trim((string)$body['personaplex_voice'])) : 'NATF2';
    if ($ppVoice === '') $ppVoice = 'NATF2';

    $personaPrompt = isset($body['persona_prompt']) && is_string($body['persona_prompt']) ? trim((string)$body['persona_prompt']) : '';

    $vbEngine = isset($body['voicebox_engine']) && is_string($body['voicebox_engine']) ? strtolower(trim((string)$body['voicebox_engine'])) : 'kokoro';
    if ($vbEngine === '') $vbEngine = 'kokoro';

    $vbVoice = isset($body['voicebox_voice']) && is_string($body['voicebox_voice']) ? trim((string)$body['voicebox_voice']) : 'af_bella';
    if ($vbVoice === '') $vbVoice = 'af_bella';

    $wav = '';

    if ($speechEngine === 'voicebox') {
        $wav = ci_tts_voicebox_wav($text, $vbEngine, $vbVoice);
        if ($wav === '') {
            ci_send(502, ['error' => 'voicebox_failed']);
        }
    } else {
        $wav = ci_tts_base_wav($text);
        if ($wav === '') {
            ci_send(502, ['error' => 'tts_failed']);
        }
        if ($speechEngine === 'personaplex') {
            $pp = ci_personaplex_convert($wav, $ppVoice, $personaPrompt);
            if ($pp !== '') $wav = $pp;
        }
    }

    ci_send(200, [
        'audio_base64' => base64_encode($wav),
        'audio_mime' => 'audio/wav',
        'speech_engine' => $speechEngine,
        'personaplex_voice' => $ppVoice,
        'voicebox_engine' => $vbEngine,
        'voicebox_voice' => $vbVoice,
    ]);
}

function ci_handle_summarize_text(array $body): void {
    $history = $body['history'] ?? [];
    if (!is_array($history)) {
        $history = [];
    }
    $base = ci_build_chat_prompt($history);
    if ($base === '') {
        ci_send(400, ['error' => 'history is required']);
    }
    $prompt = "Summarize the following meeting conversation in a concise way for participants and include key decisions and action items.\n\n" . $base;

    $resp = ci_ai_chat([['role' => 'user', 'content' => $prompt]]);
    if (($resp['ok'] ?? false) !== true) {
        ci_send(502, ['error' => $resp['error'] ?? 'upstream_failed', 'body' => $resp['body'] ?? null]);
    }
    $text = trim((string)($resp['reply'] ?? ''));
    if ($text === '') {
        ci_send(502, ['error' => 'empty_reply']);
    }

    ci_send(200, [
        'text' => $text,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ]);
}

function ci_jobs_dir(): string {
    $base = '/home/onemeta/.data/cue-insights/jobs';
    if (!is_dir($base)) {
        mkdir($base, 0755, true);
    }
    return $base;
}

function ci_handle_summarize_audio(array $body): void {
    $audioBase64 = $body['audio_base64'] ?? '';
    if ($audioBase64 === '') {
        ci_send(400, ['error' => 'audio_base64 is required']);
    }
    $binary = base64_decode($audioBase64, true);
    if ($binary === false) {
        ci_send(400, ['error' => 'Invalid base64 audio']);
    }
    $summarizeModel = (string)($body['summarize_model'] ?? 'phi4:latest');
    $userPrompt = (string)($body['user_prompt'] ?? '');
    $roomId = (string)($body['room_id'] ?? '');

    $tmpPath = ci_get_tmp_file('.wav');
    if (file_put_contents($tmpPath, $binary) === false) {
        ci_send(500, ['error' => 'Failed to write temp audio file']);
    }

    $whisperResult = ci_run_whisper($tmpPath, 'auto');
    unlink($tmpPath);
    if (($whisperResult['status'] ?? '') !== 'success') {
        ci_send(500, ['error' => $whisperResult['message'] ?? 'Transcription failed', 'details' => $whisperResult['debug'] ?? null]);
    }

    $summaryPrompt = "You are an AI assistant that summarizes meeting transcripts.\n";
    if ($userPrompt !== '') {
        $summaryPrompt .= $userPrompt . "\n\n";
    } else {
        $summaryPrompt .= "Summarize the following meeting, including key decisions and action items.\n\n";
    }
    $summaryPrompt .= $whisperResult['text'];

    $resp = ci_ollama_generate($summaryPrompt, $summarizeModel);
    $jobId = bin2hex(random_bytes(8));
    $fileId = $jobId;

    $job = [
        'job_id' => $jobId,
        'file_id' => $fileId,
        'room_id' => $roomId,
        'status' => isset($resp['error']) ? 'failed' : 'completed',
        'error' => $resp['error'] ?? '',
        'summary' => isset($resp['data']['response']) ? trim((string)$resp['data']['response']) : '',
        'prompt_tokens' => isset($resp['data']['prompt_eval_count']) ? (int)$resp['data']['prompt_eval_count'] : 0,
        'completion_tokens' => isset($resp['data']['eval_count']) ? (int)$resp['data']['eval_count'] : 0,
        'total_tokens' => 0,
    ];
    $job['total_tokens'] = $job['prompt_tokens'] + $job['completion_tokens'];

    $jobsDir = ci_jobs_dir();
    $jobPath = $jobsDir . '/' . $jobId . '.json';
    file_put_contents($jobPath, json_encode($job));

    ci_send(200, ['job_id' => $jobId, 'file_id' => $fileId]);
}

function ci_handle_summarize_status(array $body): void {
    $jobId = (string)($body['job_id'] ?? '');
    if ($jobId === '') {
        ci_send(400, ['error' => 'job_id is required']);
    }
    $jobPath = ci_jobs_dir() . '/' . $jobId . '.json';
    if (!file_exists($jobPath)) {
        ci_send(200, [
            'status' => 'running',
            'error' => '',
            'summary' => '',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ]);
    }
    $data = json_decode((string)file_get_contents($jobPath), true);
    if (!is_array($data)) {
        ci_send(500, ['error' => 'Invalid job data']);
    }

    ci_send(200, [
        'status' => (string)($data['status'] ?? 'failed'),
        'error' => (string)($data['error'] ?? ''),
        'summary' => (string)($data['summary'] ?? ''),
        'prompt_tokens' => (int)($data['prompt_tokens'] ?? 0),
        'completion_tokens' => (int)($data['completion_tokens'] ?? 0),
        'total_tokens' => (int)($data['total_tokens'] ?? 0),
    ]);
}
