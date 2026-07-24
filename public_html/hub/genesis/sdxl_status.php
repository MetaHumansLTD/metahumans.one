<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

mh_widget_require_auth();

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

function mh_env_get(string $key): string
{
    mh_env_load_once();
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        $v = (string)($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
    }
    return trim((string)$v);
}

$base = mh_env_get('SDXL_TURBO_API_URL');
if ($base === '') $base = mh_env_get('SDXL_API_URL');
if ($base === '') $base = mh_superhumans_url('cortex-persona/sdxl-turbo');
$base = rtrim($base, '/');

$token = mh_env_get('SDXL_TURBO_API_TOKEN');
if ($token === '') $token = mh_env_get('SDXL_API_TOKEN');

$tokenConfigured = $token !== '' && $token !== 'sdxl-change-me';

$payload = json_encode([
    'prompt' => 'health check portrait',
    'width' => 256,
    'height' => 256,
    'num_inference_steps' => 1,
], JSON_UNESCAPED_SLASHES);

$httpCode = 0;
$ok = false;
$err = '';
$imgBytes = 0;

if (!is_string($payload) || $payload === '') {
    mh_widget_json(['success' => false, 'error' => 'json_encode_failed'], 500);
    exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $base . '/v1/generate');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
$headers = ['Content-Type: application/json'];
if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_TIMEOUT, 90);
$resp = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = (string)curl_error($ch);
curl_close($ch);

if (is_string($resp) && $resp !== '' && $httpCode >= 200 && $httpCode < 300) {
    $j = json_decode($resp, true);
    if (is_array($j) && isset($j['image_png_base64']) && is_string($j['image_png_base64'])) {
        $bin = base64_decode((string)$j['image_png_base64'], true);
        if (is_string($bin) && $bin !== '') {
            $imgBytes = strlen($bin);
            $ok = $imgBytes > 1000;
        }
    }
}

mh_widget_json([
    'success' => true,
    'ok' => $ok,
    'base_url' => $base,
    'token_configured' => $tokenConfigured,
    'http_code' => $httpCode,
    'image_bytes' => $imgBytes,
    'error' => $ok ? null : ($err !== '' ? $err : null),
]);
