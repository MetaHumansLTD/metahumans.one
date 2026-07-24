<?php
require_once dirname(__DIR__, 2) . '/_context.php';

$ctx = mhw_require_context();

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$message = trim((string)($input['message'] ?? ''));
$mode = strtolower(trim((string)($input['mode'] ?? 'do')));
if (!in_array($mode, ['do', 'build', 'develop'], true)) $mode = 'do';
$preferredModel = trim((string)($input['model_pref'] ?? ($input['preferred'] ?? '')));
$hasUploads = !empty($input['has_uploads']) || !empty($input['uploads']);
$contextRefs = is_array($input['context_refs'] ?? null) ? (array)$input['context_refs'] : [];
$attachments = is_array($contextRefs['attachments'] ?? null) ? (array)$contextRefs['attachments'] : [];

if ($message === '') {
    mhw_json(['success' => false, 'error' => 'missing_message'], 400);
    exit;
}

$traceId = 'tr_' . bin2hex(random_bytes(16));
$tenantRoot = mhw_get_tenant_root($ctx);
$traceDir = rtrim($tenantRoot, '/') . '/audit/traces';
mhw_ensure_dir($traceDir);
$tracePath = $traceDir . '/' . $traceId . '.json';

$system = "You are the Meta Humans workspace assistant.\nReturn helpful, accurate answers. When appropriate, return JSON with keys: assistant_text (string), tool_calls (array, optional), ui_actions (array, optional).";
$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => "tenant_id={$ctx['tenant_id']}\npersona_id={$ctx['persona_id']}\nmode={$mode}\n\n" . $message],
];

$resolveStoredPath = function(array $ctx, array $uploaded): string {
    $stored = is_array($uploaded['stored'] ?? null) ? (array)$uploaded['stored'] : [];
    $path = isset($stored['path']) ? (string)$stored['path'] : '';
    if ($path === '' || !is_file($path)) return '';

    $tenantRoot = mhw_get_tenant_root($ctx);
    $tenantReal = realpath($tenantRoot);
    $fileReal = realpath($path);
    if (!is_string($tenantReal) || $tenantReal === '' || !is_string($fileReal) || $fileReal === '') return '';
    if (strpos($fileReal, $tenantReal . DIRECTORY_SEPARATOR) !== 0) return '';
    return $fileReal;
};

$asrMultipart = function(string $url, string $filePath, string $task, ?string $languageHint): array {
    $ch = curl_init($url);
    $post = [
        'file' => new CURLFile($filePath),
        'task' => $task,
    ];
    if (is_string($languageHint) && $languageHint !== '') {
        $post['language'] = $languageHint;
    }
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

$transcribeFirstVoiceAttachment = function(array $ctx, array $attachments) use ($resolveStoredPath, $asrMultipart, $toWav16kMono): array {
    $audio = null;
    foreach ($attachments as $a) {
        if (!is_array($a)) continue;
        $kind = strtolower(trim((string)($a['kind'] ?? '')));
        if (!in_array($kind, ['voice', 'audio'], true)) continue;
        $uploaded = is_array($a['uploaded'] ?? null) ? (array)$a['uploaded'] : [];
        $storedPath = $resolveStoredPath($ctx, $uploaded);
        if ($storedPath === '') continue;
        $audio = ['kind' => $kind, 'path' => $storedPath, 'name' => (string)($a['name'] ?? '')];
        break;
    }
    if (!is_array($audio)) return ['ok' => false, 'error' => 'no_audio_attachment'];

    $host = 'https://meta.superhumans.one';
    $mmsUrl = rtrim((string)(getenv('MHW_MMS_ASR_URL') ?: ($host . '/cortex-audio/mms-asr/v1/audio/transcriptions')), '/');
    $fwUrl = rtrim((string)(getenv('MHW_FASTER_WHISPER_URL') ?: ($host . '/cortex-audio/faster-whisper/v1/audio/transcriptions')), '/');

    $wav = $toWav16kMono($audio['path']);
    $mmsInputPath = ($wav['ok'] ?? false) ? (string)$wav['path'] : $audio['path'];

    $r1 = $asrMultipart($mmsUrl, $mmsInputPath, 'transcribe', null);
    $body = null;
    if ($r1['resp'] !== false && $r1['err'] === '' && $r1['status'] >= 200 && $r1['status'] < 300) {
        $body = json_decode((string)$r1['resp'], true);
        if (!is_array($body)) $body = null;
        if (is_array($body) && is_string($body['text'] ?? null)) {
            if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
            return ['ok' => true, 'lane' => 'mms_asr', 'text' => (string)$body['text'], 'raw' => $body];
        }
    }

    $r2 = $asrMultipart($fwUrl, $audio['path'], 'transcribe', null);
    if ($r2['resp'] !== false && $r2['err'] === '' && $r2['status'] >= 200 && $r2['status'] < 300) {
        $body2 = json_decode((string)$r2['resp'], true);
        if (is_array($body2) && is_string($body2['text'] ?? null)) {
            if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
            return ['ok' => true, 'lane' => 'faster_whisper', 'text' => (string)$body2['text'], 'raw' => $body2];
        }
    }

    if (($wav['tmp'] ?? false) && is_file((string)$wav['path'])) @unlink((string)$wav['path']);
    return [
        'ok' => false,
        'lane' => 'none',
        'error' => 'asr_failed',
        'mms' => ['status' => $r1['status'], 'err' => $r1['err']],
        'faster_whisper' => ['status' => $r2['status'], 'err' => $r2['err']],
    ];
};

$asr = null;
if (!empty($attachments)) {
    $hasUploads = true;
    $asr = $transcribeFirstVoiceAttachment($ctx, $attachments);
    if (is_array($asr) && ($asr['ok'] ?? false) && is_string($asr['text'] ?? null) && trim((string)$asr['text']) !== '') {
        $messages[1]['content'] = $messages[1]['content'] . "\n\n[AUDIO_TRANSCRIPT]\n" . trim((string)$asr['text']);
    } elseif (is_array($asr) && ($asr['ok'] ?? false) && is_string($asr['text'] ?? null) && trim((string)$asr['text']) === '') {
        $messages[1]['content'] = $messages[1]['content'] . "\n\n[AUDIO_TRANSCRIPT]\n" . "(no speech detected)";
    }
}

$makeToneWav = function(string $text): array {
    $sr = 16000;
    $secs = 0.6 + min(2.0, max(0.0, strlen($text) / 80.0));
    $n = (int)($sr * $secs);
    $freq = 440.0;
    $amp = 0.15;
    $samples = '';
    for ($i = 0; $i < $n; $i++) {
        $t = $i / $sr;
        $v = (int)(sin(2.0 * M_PI * $freq * $t) * 32767.0 * $amp);
        if ($v < -32768) $v = -32768;
        if ($v > 32767) $v = 32767;
        $samples .= pack('v', $v & 0xffff);
    }
    $dataSize = strlen($samples);
    $riffSize = 36 + $dataSize;
    $header =
        "RIFF" . pack('V', $riffSize) . "WAVE" .
        "fmt " . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $sr) .
        pack('V', $sr * 2) . pack('v', 2) . pack('v', 16) .
        "data" . pack('V', $dataSize);
    return ['bytes' => $header . $samples, 'mime' => 'audio/wav', 'ext' => 'wav'];
};

$storeVoiceBytes = function(array $ctx, string $bytes, string $mime, string $ext): array {
    $tenantRoot = mhw_get_tenant_root($ctx);
    $personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
    $voicesDir = $tenantRoot . '/voices/' . $personaSafe;
    if (!mhw_ensure_dir($voicesDir)) return ['ok' => false, 'error' => 'voices_create_failed'];

    $id = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
    $idSafe = mhw_sanitize_id($id);
    $extSafe = strtolower(preg_replace('/[^a-z0-9]+/', '', $ext));
    if ($extSafe === '') $extSafe = 'wav';

    $filename = 'voice_' . $idSafe . '.' . $extSafe;
    $dest = $voicesDir . '/' . $filename;
    if (file_put_contents($dest, $bytes) === false) return ['ok' => false, 'error' => 'store_failed'];
    @chmod($dest, 0600);

    $meta = [
        'id' => $id,
        'filename' => $filename,
        'path' => $dest,
        'mime' => $mime,
        'size' => strlen($bytes),
        'uploaded_at_utc' => gmdate('c'),
        'tenant_id' => $ctx['tenant_id'],
        'persona_id' => $ctx['persona_id'],
        'meta_human_id' => $ctx['meta_human_id'],
        'user_id' => $ctx['user_id'],
        'source' => 'tts_debug',
    ];
    $metaPath = $voicesDir . '/voice_' . $idSafe . '.json';
    file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($metaPath, 0600);

    $url = '/hub/workbench/api/voices.php?id=' . rawurlencode($id);
    return ['ok' => true, 'id' => $id, 'path' => $dest, 'meta' => $metaPath, 'url' => $url];
};

$tts = null;
$ttsTest = is_array($input['tts_test'] ?? null) ? (array)$input['tts_test'] : null;
if (is_array($ttsTest)) {
    $ttsText = (string)($ttsTest['text'] ?? $message);
    $return = strtolower(trim((string)($ttsTest['return'] ?? 'base64')));
    if (!in_array($return, ['base64', 'url', 'both'], true)) $return = 'base64';
    $wav = $makeToneWav($ttsText);
    $stored = $storeVoiceBytes($ctx, (string)$wav['bytes'], (string)$wav['mime'], (string)$wav['ext']);
    $tts = [
        'ok' => (bool)($stored['ok'] ?? false),
        'lane' => 'tts_debug_tone',
        'mime' => (string)$wav['mime'],
        'ext' => (string)$wav['ext'],
    ];
    if ($return === 'base64' || $return === 'both') {
        $tts['audio_base64'] = base64_encode((string)$wav['bytes']);
    }
    if (($stored['ok'] ?? false) && ($return === 'url' || $return === 'both')) {
        $tts['url'] = (string)$stored['url'];
    }
    if ($stored['ok'] ?? false) {
        $tts['stored'] = [
            'path' => (string)$stored['path'],
            'meta' => (string)$stored['meta'],
            'url' => (string)$stored['url'],
        ];
    } else {
        $tts['error'] = (string)($stored['error'] ?? 'unknown');
    }
}

$loadModelConfig = function(): array {
    $configPath = '/home/onemeta/.data/config/models_config.json';
    if (!is_file($configPath)) return [];
    $raw = file_get_contents($configPath);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($json) ? $json : [];
};

$getAvailableModels = function(): array {
    if (function_exists('cue_autoload')) {
        try { cue_autoload('models'); } catch (Throwable $e) {}
    }
    $models = function_exists('models_get_models') ? models_get_models() : [];
    if (!is_array($models)) $models = [];
    $available = [];
    foreach ($models as $m) {
        if (is_array($m)) {
            $id = isset($m['id']) ? (string)$m['id'] : (isset($m['model']) ? (string)$m['model'] : '');
            if ($id === '') continue;
            $available[$id] = $m;
        } elseif (is_string($m) && $m !== '') {
            $available[$m] = ['id' => $m];
        }
    }
    return $available;
};

$inferIntent = function(string $message, string $mode, bool $hasUploads): string {
    if ($hasUploads) return 'vision';
    if ($mode === 'build') return 'code';
    if (preg_match('/\\b(code|function|class|typescript|javascript|ts\\b|js\\b|php\\b|python\\b|sql\\b|refactor|bug|fix|compile|build)\\b/i', $message)) {
        return 'code';
    }
    if (preg_match('/\\b(reason|reasoning|proof|logic|derive|math|verifier|chain|think)\\b/i', $message)) {
        return 'reasoning';
    }
    return 'chat';
};

$pickFirstMatch = function(array $available, array $needles): string {
    foreach ($needles as $needle) {
        foreach (array_keys($available) as $id) {
            if ($needle !== '' && stripos($id, $needle) !== false) return $id;
        }
    }
    return '';
};

$selectModel = function(array $available, string $message, string $mode, bool $hasUploads, string $preferred) use ($inferIntent, $pickFirstMatch): array {
    $intent = $inferIntent($message, $mode, $hasUploads);

    $nemotron = $pickFirstMatch($available, ['nemotron', 'openreasoning', 'tensorrt_llm']);
    $glm = $pickFirstMatch($available, ['glm-4.7', 'glm', 'GLM']);

    $candidates = [];
    if ($intent === 'reasoning') $candidates = [$nemotron, $glm];
    elseif ($intent === 'code') $candidates = [$glm, $nemotron];
    elseif ($intent === 'vision') $candidates = [$glm, $nemotron];
    else $candidates = [$nemotron, $glm];
    $candidates = array_values(array_filter($candidates, fn($x) => is_string($x) && $x !== ''));

    $selected = '';
    if ($preferred !== '' && isset($available[$preferred])) {
        $selected = $preferred;
    } else {
        $selected = $candidates[0] ?? (array_key_first($available) ?? '');
    }

    $fallback = $candidates[0] ?? (array_key_first($available) ?? '');
    if ($fallback === '') $fallback = $selected;

    return [
        'selected' => $selected,
        'fallback' => $fallback,
        'intent' => $intent,
        'candidates' => $candidates,
    ];
};

$routeUpstream = function(array $studioConfig, string $model): array {
    $ollamaBase = getenv('MH_OLLAMA_BASE_URL');
    if (!is_string($ollamaBase) || trim($ollamaBase) === '') $ollamaBase = getenv('OLLAMA_BASE_URL');
    if (!is_string($ollamaBase) || trim($ollamaBase) === '') $ollamaBase = getenv('OLLAMA_HOST');
    $ollamaBase = is_string($ollamaBase) ? trim($ollamaBase) : '';
    if ($ollamaBase === '') $ollamaBase = 'http://meta.superhumans.one:11434';
    $ollamaBase = rtrim($ollamaBase, '/');
    $ollamaChatUrl = preg_match('~/v1/chat/completions/?$~', $ollamaBase) ? rtrim($ollamaBase, '/') : ($ollamaBase . '/v1/chat/completions');

    $localUrls = [
        'http://127.0.0.1:32104/v1/chat/completions',
        $ollamaChatUrl,
    ];

    $headers = ['Content-Type: application/json'];

    $server = null;
    $isRemote = false;

    if (strpos($model, '/') !== false) {
        $serverId = 'openrouter';
        if (isset($studioConfig['gpu_servers'][$serverId])) {
            $server = $studioConfig['gpu_servers'][$serverId];
            $isRemote = true;
        }
    } elseif (isset($studioConfig['models'][$model])) {
        $modelConfig = $studioConfig['models'][$model];
        $serverId = $modelConfig['server'] ?? '';
        if ($serverId !== '' && isset($studioConfig['gpu_servers'][$serverId])) {
            $server = $studioConfig['gpu_servers'][$serverId];
            $isRemote = true;
        }
    }

    $targetUrl = $localUrls[0];
    $targetLabel = 'local';

    $defaultGpuHost = 'meta.superhumans.one';
    $overrideHost = getenv('META_HUMANS_GPU_HOST');
    $overrideHost = is_string($overrideHost) ? trim($overrideHost) : '';
    if ($overrideHost === '') $overrideHost = $defaultGpuHost;

    if ($isRemote && is_array($server)) {
        $host = isset($server['host']) ? (string)$server['host'] : '';
        $port = isset($server['port']) ? (int)$server['port'] : 0;
        $protocol = isset($server['protocol']) ? (string)$server['protocol'] : 'https';
        $type = isset($server['type']) ? (string)$server['type'] : '';
        $token = isset($server['auth_token']) ? (string)$server['auth_token'] : '';

        if ($host === 'promptengine.one' || $host === 'gpu.promptengine.one') {
            $host = $overrideHost;
        }

        if (strpos($token, '${') === 0) {
            $envVar = substr($token, 2, -1);
            $token = getenv($envVar) ?: '';
        }
        if (is_string($token) && $token !== '') {
            $headers[] = "Authorization: Bearer {$token}";
        }

        if ($host !== '' && $port > 0) {
            $targetLabel = 'remote';
            if ($type === 'tock') {
                $targetUrl = "{$protocol}://{$host}:{$port}/chat";
            } elseif ($host === 'openrouter.ai') {
                $targetUrl = 'https://openrouter.ai/api/v1/chat/completions';
            } else {
                $targetUrl = "{$protocol}://{$host}:{$port}/v1/chat/completions";
            }
        }
    }

    return [
        'targetUrl' => $targetUrl,
        'targetLabel' => $targetLabel,
        'headers' => $headers,
        'fallbackUrls' => array_values(array_filter($localUrls, fn($u) => $u !== $targetUrl)),
    ];
};

$doRequest = function(string $url, array $headers, array $payload): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    return ['resp' => $resp, 'err' => $err, 'status' => $status];
};

$respBody = null;
$respErr = '';
$respStatus = 0;

$available = $getAvailableModels();
$selectedInfo = $selectModel($available, $message, $mode, (bool)$hasUploads, $preferredModel);
$model = $selectedInfo['selected'] !== '' ? $selectedInfo['selected'] : 'hermes3:latest';

$payload = [
    'model' => $model,
    'messages' => $messages,
    'stream' => false,
    'temperature' => 0.3,
    'max_tokens' => 900,
];

$modelConfig = $loadModelConfig();
$routing = $routeUpstream($modelConfig, $model);

$r = $doRequest($routing['targetUrl'], $routing['headers'], $payload);
$respBody = $r['resp'];
$respErr = $r['err'];
$respStatus = $r['status'];

if ($respBody === false || $respErr !== '' || $respStatus >= 400) {
    foreach ($routing['fallbackUrls'] as $u) {
        $r2 = $doRequest($u, ['Content-Type: application/json'], $payload);
        if ($r2['resp'] !== false && $r2['err'] === '' && $r2['status'] < 400) {
            $respBody = $r2['resp'];
            $respErr = $r2['err'];
            $respStatus = $r2['status'];
            break;
        }
    }
}

$assistantText = '';
$toolCalls = [];
$uiActions = [];
$rawAssistant = '';

if ($respBody !== false && $respErr === '' && $respStatus >= 200 && $respStatus < 300) {
    $json = json_decode((string)$respBody, true);
    $rawAssistant = $json['choices'][0]['message']['content'] ?? '';
    $rawAssistant = is_string($rawAssistant) ? trim($rawAssistant) : '';
    $parsed = json_decode($rawAssistant, true);
    if (is_array($parsed)) {
        $assistantText = is_string($parsed['assistant_text'] ?? null) ? (string)$parsed['assistant_text'] : $rawAssistant;
        $toolCalls = is_array($parsed['tool_calls'] ?? null) ? (array)$parsed['tool_calls'] : [];
        $uiActions = is_array($parsed['ui_actions'] ?? null) ? (array)$parsed['ui_actions'] : [];
    } else {
        $assistantText = $rawAssistant;
    }
} else {
    $assistantText = "Workspace assistant is unavailable right now.";
}

$trace = [
    'trace_id' => $traceId,
    'created_at' => time(),
    'tenant_id' => $ctx['tenant_id'],
    'persona_id' => $ctx['persona_id'],
    'mode' => $mode,
    'request' => [
        'message' => $message,
        'model_pref' => $preferredModel,
        'attachments' => array_map(function($a) {
            if (!is_array($a)) return null;
            return [
                'kind' => (string)($a['kind'] ?? ''),
                'name' => (string)($a['name'] ?? ''),
                'mime' => (string)($a['mime'] ?? ''),
                'size' => (int)($a['size'] ?? 0),
                'uploaded_ok' => (bool)((is_array($a['uploaded'] ?? null) ? ($a['uploaded']['success'] ?? false) : false)),
            ];
        }, $attachments),
        'asr' => $asr,
        'tts' => $tts,
    ],
    'response' => [
        'model' => $model,
        'intent' => $selectedInfo['intent'],
        'candidates' => $selectedInfo['candidates'],
        'assistant_text' => $assistantText,
        'tool_calls' => $toolCalls,
        'ui_actions' => $uiActions,
    ],
];

@file_put_contents($tracePath, json_encode($trace, JSON_UNESCAPED_SLASHES));

mhw_json([
    'success' => true,
    'trace_id' => $traceId,
    'model' => $model,
    'intent' => $selectedInfo['intent'],
    'candidates' => $selectedInfo['candidates'],
    'assistant_text' => $assistantText,
    'tts' => $tts,
    'tool_calls' => $toolCalls,
    'ui_actions' => $uiActions,
]);
