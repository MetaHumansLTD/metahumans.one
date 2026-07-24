<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/hub/workbench/api/_context.php';
require_once dirname(__DIR__, 2) . '/hub/memory/lib.php';
require_once dirname(__DIR__, 2) . '/hub/workbench/api/_memory_ingest_lib.php';
require_once dirname(__DIR__, 2) . '/hub/widget/_lib.php';

function mh_translate_json(array $payload, int $status = 200): void
{
    mhw_json($payload, $status);
    exit;
}

function mh_translate_json_in(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_translate_post_json(string $url, array $payload, array $headers = [], int $timeout = 60): array
{
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;
    $parsed = null;
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) $parsed = $tmp;
    }
    return [
        'ok' => $err === '' && $code >= 200 && $code < 300,
        'status' => $code,
        'error' => $err,
        'json' => $parsed,
        'raw' => is_string($body) ? $body : '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mh_translate_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$ctx = mhw_require_context();
$req = mh_translate_json_in();

$tenantId = is_string($req['tenant_id'] ?? null) ? trim((string)$req['tenant_id']) : '';
$personaId = is_string($req['persona_id'] ?? null) ? trim((string)$req['persona_id']) : '';
$userId = is_string($req['user_id'] ?? null) ? trim((string)$req['user_id']) : '';

foreach (['tenant_id' => $tenantId, 'persona_id' => $personaId, 'user_id' => $userId] as $k => $v) {
    if ($v === '') {
        mh_translate_json(['ok' => false, 'error' => 'missing_identity_fields', 'required' => ['tenant_id', 'persona_id', 'user_id']], 400);
    }
}
if ($tenantId !== (string)$ctx['tenant_id'] || $personaId !== (string)$ctx['persona_id'] || $userId !== (string)$ctx['user_id']) {
    mh_translate_json(['ok' => false, 'error' => 'identity_mismatch'], 403);
}

$text = is_string($req['text'] ?? null) ? trim((string)$req['text']) : '';
if ($text === '') {
    mh_translate_json(['ok' => false, 'error' => 'text_required'], 400);
}
if (strlen($text) > 8000) $text = substr($text, 0, 8000);

$sourceLang = is_string($req['source_lang'] ?? null) ? trim((string)$req['source_lang']) : 'auto';
$targetLang = is_string($req['target_lang'] ?? null) ? trim((string)$req['target_lang']) : '';
if ($targetLang === '') {
    mh_translate_json(['ok' => false, 'error' => 'target_lang_required'], 400);
}

$translateUrl = getenv('TRANSLATE_URL');
if (!is_string($translateUrl) || trim($translateUrl) === '') {
    $translateUrl = getenv('HERMES_URL');
}
if (!is_string($translateUrl) || trim($translateUrl) === '') {
    $translateUrl = mh_superhumans_url('hermes') . '/v1/chat/completions';
}
$translateUrl = rtrim(trim((string)$translateUrl), '/');
if (!preg_match('~/v1/chat/completions$~', $translateUrl)) {
    $translateUrl .= '/v1/chat/completions';
}

$wantDetect = ($sourceLang === '' || strtolower($sourceLang) === 'auto');

$system = implode("\n", [
    'You are a translation engine.',
    'Translate the user text accurately.',
    'Return ONLY valid JSON, no markdown, no explanations.',
    'Schema: {"translated_text": string, "detected_lang": string}.',
    'If source language is "auto", detect it and set detected_lang to a BCP-47 tag when possible (or a short ISO code).',
    'If source language is explicit, set detected_lang to the source language.',
    'Preserve meaning, names, and formatting as much as possible.',
    'Target language: ' . $targetLang,
    'Source language: ' . $sourceLang,
]);

$payload = [
    'model' => getenv('TRANSLATE_MODEL') ?: 'Hermes-4-405B',
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $text],
    ],
    'temperature' => 0.2,
    'max_tokens' => 1200,
];

$resp = mh_translate_post_json($translateUrl, $payload, [], 60);
if (!$resp['ok'] || !is_array($resp['json'])) {
    mh_translate_json([
        'ok' => false,
        'error' => 'upstream_failed',
        'upstream_status' => (int)$resp['status'],
        'upstream_error' => is_string($resp['error'] ?? null) ? (string)$resp['error'] : '',
    ], 502);
}

$detectedLang = $wantDetect ? '' : $sourceLang;
$translated = '';
if (isset($resp['json']['choices'][0]['message']['content']) && is_string($resp['json']['choices'][0]['message']['content'])) {
    $content = trim((string)$resp['json']['choices'][0]['message']['content']);
    $asJson = json_decode($content, true);
    if (is_array($asJson)) {
        if (isset($asJson['translated_text']) && is_string($asJson['translated_text'])) {
            $translated = trim((string)$asJson['translated_text']);
        }
        if (isset($asJson['detected_lang']) && is_string($asJson['detected_lang'])) {
            $detectedLang = trim((string)$asJson['detected_lang']);
        }
    }
    if ($translated === '') {
        $translated = $content;
    }
}
if ($translated === '') {
    mh_translate_json(['ok' => false, 'error' => 'empty_translation'], 502);
}

$requestId = is_string($req['request_id'] ?? null) ? trim((string)$req['request_id']) : '';
$idBase = $requestId !== '' ? $requestId : (gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)));
$tags = array_values(array_filter([
    'translation',
    'source:' . $sourceLang,
    'target:' . $targetLang,
], fn($t) => is_string($t) && trim($t) !== ''));

mhw_memory_ingest_store_one($ctx, [
    'id' => $idBase . '_t',
    'kind' => 'translation',
    'source' => 'translate',
    'text' => $text . "\n\n---\n\n" . $translated,
    'tags' => $tags,
]);

mh_translate_json([
    'ok' => true,
    'request_id' => $requestId !== '' ? $requestId : $idBase,
    'identity' => [
        'tenant_id' => (string)$ctx['tenant_id'],
        'persona_id' => (string)$ctx['persona_id'],
        'user_id' => (string)$ctx['user_id'],
        'meta_human_id' => (string)$ctx['meta_human_id'],
        'session_id' => (string)$ctx['session_id'],
        'device_id' => (string)$ctx['device_id'],
    ],
    'source_lang' => $sourceLang,
    'detected_lang' => $detectedLang,
    'target_lang' => $targetLang,
    'translated_text' => $translated,
]);
