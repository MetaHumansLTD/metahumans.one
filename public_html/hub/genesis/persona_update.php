<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

$ctx = mh_widget_require_auth();

function mh_env_load_once(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $candidates = [];
    $envFile = getenv('MH_ENV_FILE');
    if (is_string($envFile) && trim($envFile) !== '') $candidates[] = trim((string)$envFile);
    $candidates[] = '/home/onemeta/.env';
    $candidates[] = '/home/onemeta/public_html/.env';
    foreach ($candidates as $path) {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) continue;
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (!is_string($line)) continue;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k === '') continue;
            if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) $v = substr($v, 1, -1);
            if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) $v = substr($v, 1, -1);
            if (getenv($k) === false) {
                @putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
        break;
    }
}

function mh_http_get_json_with_session(string $url, int $timeoutSec = 6): ?array
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
        'User-Agent: mh-genesis-update/1',
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

$tenantId = (string)($ctx['tenant_id'] ?? '');
$tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));
if ($tenantSafe === '' || $tenantSafe === 'unknown') {
    mh_widget_json(['success' => false, 'error' => 'invalid_tenant_id'], 500);
    exit;
}

$personaIn = isset($_POST['persona_id']) ? trim((string)$_POST['persona_id']) : '';
$personaIn = $personaIn !== '' ? $personaIn : (string)($ctx['persona_id'] ?? '');
$personaSafe = mh_widget_sanitize_id($personaIn);
if ($personaSafe === '' || $personaSafe === 'unknown') {
    mh_widget_json(['success' => false, 'error' => 'persona_id_required'], 400);
    exit;
}

$debug = mh_http_get_json_with_session('http://127.0.0.1/hub/genesis/persona-images.php?persona=' . rawurlencode($personaIn) . '&debug=1', 6);
$personaDir = '';
if (is_array($debug) && isset($debug['persona_dir']) && is_string($debug['persona_dir'])) {
    $personaDir = (string)$debug['persona_dir'];
}
if ($personaDir === '' || !is_dir($personaDir)) {
    $personaDir = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe;
}
if (!is_dir($personaDir)) {
    mh_widget_json(['success' => false, 'error' => 'persona_not_found'], 404);
    exit;
}

$specPath = $personaDir . '/assets/persona-spec.json';
$manifestPath = $personaDir . '/assets/manifest.json';

$spec = [];
if (is_file($specPath)) {
    $raw = @file_get_contents($specPath);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($j)) $spec = $j;
}

$voiceType = isset($_POST['voice_type']) ? trim((string)$_POST['voice_type']) : '';
$voiceType = strtolower(mh_widget_sanitize_id($voiceType));
if (!in_array($voiceType, ['male', 'female', 'animal', 'auto'], true)) {
    $voiceType = isset($spec['voice']['type']) && is_string($spec['voice']['type']) ? strtolower(trim((string)$spec['voice']['type'])) : 'auto';
    if (!in_array($voiceType, ['male', 'female', 'animal', 'auto'], true)) $voiceType = 'auto';
}

$language = isset($_POST['language']) ? trim((string)$_POST['language']) : '';
$language = $language !== '' ? mh_widget_sanitize_id($language) : '';
if ($language === '' || $language === 'unknown') {
    $language = isset($spec['language']) && is_string($spec['language']) ? mh_widget_sanitize_id((string)$spec['language']) : 'en-US';
    if ($language === '' || $language === 'unknown') $language = 'en-US';
}

$speechEngine = isset($_POST['speech_engine']) ? trim((string)$_POST['speech_engine']) : '';
$speechEngine = strtolower(mh_widget_sanitize_id($speechEngine));
if (!in_array($speechEngine, ['classic', 'personaplex'], true)) {
    $speechEngine = isset($spec['speech']['engine']) && is_string($spec['speech']['engine']) ? strtolower(trim((string)$spec['speech']['engine'])) : 'classic';
    if (!in_array($speechEngine, ['classic', 'personaplex'], true)) $speechEngine = 'classic';
}

$ppVoice = isset($_POST['personaplex_voice']) ? trim((string)$_POST['personaplex_voice']) : '';
$ppVoice = strtoupper(mh_widget_sanitize_id($ppVoice));
if ($ppVoice === '' || $ppVoice === 'UNKNOWN') {
    $ppVoice = isset($spec['speech']['personaplex_voice']) && is_string($spec['speech']['personaplex_voice']) ? strtoupper(trim((string)$spec['speech']['personaplex_voice'])) : '';
}
if ($ppVoice === '' || $ppVoice === 'UNKNOWN') {
    $ppVoice = $voiceType === 'female' ? 'NATF2' : ($voiceType === 'male' ? 'NATM1' : 'NATF2');
}

$spec['voice'] = is_array($spec['voice'] ?? null) ? $spec['voice'] : [];
$spec['voice']['type'] = $voiceType;
$spec['language'] = $language;
$spec['speech'] = is_array($spec['speech'] ?? null) ? $spec['speech'] : [];
$spec['speech']['engine'] = $speechEngine;
$spec['speech']['personaplex_voice'] = $ppVoice;
$spec['updated_at'] = gmdate('c');

@file_put_contents($specPath, json_encode($spec, JSON_UNESCAPED_SLASHES));

$manifest = [];
if (is_file($manifestPath)) {
    $raw = @file_get_contents($manifestPath);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($j)) $manifest = $j;
}
if (!is_array($manifest)) $manifest = [];
$manifest['spec'] = $spec;
$manifest['updated_at'] = gmdate('c');
@file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

if ($voiceType === 'male' || $voiceType === 'female') {
    $voiceRef = '/data/tenants/' . $tenantSafe . '/voices/' . $personaSafe . '/reference.wav';
    $preset = $voiceType === 'female'
        ? '/home/onemeta/public_html/hub/genesis/voice-presets/pod_f_enhanced.wav'
        : '/home/onemeta/public_html/hub/genesis/voice-presets/pod_m_enhanced.wav';
    if (is_file($preset) && filesize($preset) > 1024) {
        $dir = dirname($voiceRef);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @copy($preset, $voiceRef);
    }
}

mh_widget_json([
    'success' => true,
    'persona_id' => $personaSafe,
    'persona_dir' => $personaDir,
    'language' => $language,
    'voice_type' => $voiceType,
    'speech_engine' => $speechEngine,
    'personaplex_voice' => $ppVoice,
]);
