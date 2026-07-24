<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function mh_in(): string
{
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
}

function mh_json(string $raw): array
{
    if (trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mh_out(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_get_token(): string
{
    $h = $_SERVER['HTTP_X_CUE_GPU_TOKEN'] ?? '';
    return is_string($h) ? trim((string)$h) : '';
}

function mh_post_json(string $url, array $payload, int $timeoutSec): array
{
    $ch = curl_init();
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return ['ok' => false, 'status' => 0, 'body' => 'json_encode_failed'];
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp)) $resp = '';
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'body' => $resp, 'error' => $err];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mh_out(405, ['error' => 'method_not_allowed']);
}

$expected = trim((string)(getenv('CUE_GPU_TOKEN') ?: 'demo-insights-token'));
if ($expected !== '' && mh_get_token() !== $expected) {
    mh_out(403, ['error' => 'forbidden']);
}

$req = mh_json(mh_in());
$history = $req['history'] ?? [];
if (!is_array($history)) $history = [];

$messages = [];
foreach ($history as $m) {
    if (!is_array($m)) continue;
    $role = isset($m['role']) && is_string($m['role']) ? strtolower(trim((string)$m['role'])) : '';
    $text = isset($m['text']) && is_string($m['text']) ? trim((string)$m['text']) : '';
    if ($role === '' || $text === '') continue;
    if (!in_array($role, ['system', 'user', 'assistant'], true)) $role = 'user';
    $messages[] = ['role' => $role, 'content' => $text];
}
if ($messages === []) {
    mh_out(400, ['error' => 'missing_history']);
}

$model = trim((string)(getenv('CUE_GPU_MODEL') ?: 'Hermes-4-405B'));

$res = mh_post_json('https://metahumans.one/ai/chat.php', [
    'model' => $model,
    'messages' => $messages,
    'max_tokens' => 400,
], 60);

if (!$res['ok']) {
    mh_out(502, ['error' => 'upstream_failed', 'status' => (int)($res['status'] ?? 0)]);
}

$raw = (string)($res['body'] ?? '');
$j = json_decode($raw, true);
if (!is_array($j)) {
    mh_out(502, ['error' => 'upstream_invalid_json']);
}

$reply = '';
if (is_string($j['reply'] ?? null)) {
    $reply = trim((string)$j['reply']);
}
if ($reply === '' && is_array($j['raw'] ?? null)) {
    $raw2 = $j['raw'];
    if (is_string($raw2['reply'] ?? null)) {
        $reply = trim((string)$raw2['reply']);
    }
    if ($reply === '' && is_array($raw2['raw'] ?? null)) {
        $raw3 = $raw2['raw'];
        if (is_array($raw3['choices'][0]['message'] ?? null) && is_string($raw3['choices'][0]['message']['content'] ?? null)) {
            $reply = trim((string)$raw3['choices'][0]['message']['content']);
        }
    }
}

if ($reply === '') {
    mh_out(502, ['error' => 'empty_reply']);
}

mh_out(200, ['reply' => $reply]);
