<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/hub/workbench/api/_context.php';
require_once dirname(__DIR__, 2) . '/hub/memory/lib.php';
require_once dirname(__DIR__, 2) . '/hub/workbench/api/_memory_ingest_lib.php';

cue_autoload('memory');
cue_autoload('graph');
cue_autoload('graphrag');

function mh_resp_json(array $payload, int $status = 200): void {
    mhw_json($payload, $status);
    exit;
}

function mh_resp_json_in(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_resp_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 240): array {
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
    mh_resp_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$ctx = mhw_require_context();
$req = mh_resp_json_in();

$tenantId = is_string($req['tenant_id'] ?? null) ? trim((string)$req['tenant_id']) : '';
$personaId = is_string($req['persona_id'] ?? null) ? trim((string)$req['persona_id']) : '';
$userId = is_string($req['user_id'] ?? null) ? trim((string)$req['user_id']) : '';
$metaHumanId = is_string($req['meta_human_id'] ?? null) ? trim((string)$req['meta_human_id']) : '';
$sessionId = is_string($req['session_id'] ?? null) ? trim((string)$req['session_id']) : '';
$deviceId = is_string($req['device_id'] ?? null) ? trim((string)$req['device_id']) : '';

foreach (['tenant_id' => $tenantId, 'persona_id' => $personaId, 'user_id' => $userId] as $k => $v) {
    if ($v === '') {
        mh_resp_json(['ok' => false, 'error' => 'missing_identity_fields', 'required' => ['tenant_id', 'persona_id', 'user_id']], 400);
    }
}

if ($tenantId !== (string)$ctx['tenant_id'] || $personaId !== (string)$ctx['persona_id'] || $userId !== (string)$ctx['user_id']) {
    mh_resp_json(['ok' => false, 'error' => 'identity_mismatch'], 403);
}
if ($metaHumanId !== '' && $metaHumanId !== (string)$ctx['meta_human_id']) {
    mh_resp_json(['ok' => false, 'error' => 'identity_mismatch'], 403);
}
if ($sessionId !== '' && $sessionId !== (string)$ctx['session_id']) {
    mh_resp_json(['ok' => false, 'error' => 'identity_mismatch'], 403);
}
if ($deviceId !== '' && $deviceId !== (string)$ctx['device_id']) {
    mh_resp_json(['ok' => false, 'error' => 'identity_mismatch'], 403);
}

$requestId = is_string($req['request_id'] ?? null) ? trim((string)$req['request_id']) : '';
$channel = is_string($req['channel'] ?? null) ? trim((string)$req['channel']) : 'web';
$conversationId = is_string($req['conversation_id'] ?? null) ? trim((string)$req['conversation_id']) : '';

$messages = $req['messages'] ?? null;
if (!is_array($messages)) $messages = [];
$lastUserText = function_exists('memory_extract_last_user_text') ? memory_extract_last_user_text($messages) : '';
if ($lastUserText === '') {
    $input = $req['input'] ?? null;
    if (is_array($input) && isset($input['text']) && is_string($input['text'])) {
        $lastUserText = trim((string)$input['text']);
    }
}

$images = [];
$cameraFrames = [];
$uploads = [];
if (is_array($req['input'] ?? null)) {
    $images = is_array(($req['input']['images'] ?? null)) ? (array)$req['input']['images'] : [];
    $cameraFrames = is_array(($req['input']['camera_frames'] ?? null)) ? (array)$req['input']['camera_frames'] : [];
}
if (is_array($req['uploads'] ?? null)) {
    $uploads = (array)$req['uploads'];
}

if ($lastUserText === '' && empty($images) && empty($cameraFrames)) {
    mh_resp_json(['ok' => false, 'error' => 'missing_input', 'required' => ['messages or input.text or input.images or input.camera_frames']], 400);
}

$idBase = $requestId !== '' ? $requestId : (gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6)));
$userTags = array_values(array_filter([
    $channel !== '' ? ('channel:' . $channel) : '',
    $conversationId !== '' ? ('conversation:' . $conversationId) : '',
], fn($t) => $t !== ''));
mhw_memory_ingest_store_one($ctx, [
    'id' => $idBase . '_u',
    'kind' => 'chat_user',
    'source' => 'meta_human_respond',
    'text' => $lastUserText,
    'tags' => $userTags,
]);

$memoryCtx = [
    'tenant_id' => (string)$ctx['tenant_id'],
    'persona_id' => (string)$ctx['persona_id'],
    'meta_human_id' => (string)$ctx['meta_human_id'],
    'user_id' => (string)$ctx['user_id'],
    'username' => (string)$ctx['username'],
    'device_id' => (string)$ctx['device_id'],
    'session_id' => (string)$ctx['session_id'],
];

$semanticHits = [];
$semanticMsg = null;
if ($lastUserText !== '' && function_exists('memory_retrieve') && function_exists('memory_build_system_message')) {
    $semanticHits = memory_retrieve($memoryCtx, $lastUserText, 6);
    $semanticMsg = memory_build_system_message($semanticHits);
}

$graphSummary = [];
$graphMsg = null;
if ($lastUserText !== '' && function_exists('graphrag_retrieve_summary') && function_exists('graphrag_build_system_message')) {
    $graphSummary = graphrag_retrieve_summary($memoryCtx, $lastUserText, 6, 6);
    $graphMsg = graphrag_build_system_message($graphSummary);
}

$memoryBundle = [
    'built_at_utc' => gmdate('c'),
    'semantic' => [
        'hits' => $semanticHits,
        'system_message' => $semanticMsg,
    ],
    'graph' => [
        'summary' => $graphSummary,
        'system_message' => $graphMsg,
    ],
    'ledger' => [
        'auth_role' => isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '',
        'tokens' => isset($_SESSION['tokens']) ? $_SESSION['tokens'] : null,
    ],
];

$routeHint = is_string($req['route_hint'] ?? null) ? (string)$req['route_hint'] : 'auto';
$taskType = is_string($req['task_type'] ?? null) ? (string)$req['task_type'] : 'general';
$visionMode = is_string($req['vision_mode'] ?? null) ? (string)$req['vision_mode'] : 'auto';
$tools = is_array($req['tools'] ?? null) ? (array)$req['tools'] : [];

$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $isHttps = true;
}
$scheme = $isHttps ? 'https' : 'http';
$orchLib = dirname(__DIR__) . '/respond/index.php';
$orchFn = null;
$orchLoaded = false;
$orchError = '';
try {
    if (is_file($orchLib)) {
        if (!defined('MH_ORCH_LIB')) {
            define('MH_ORCH_LIB', true);
        }
        require_once $orchLib;
        if (function_exists('mh_orchestrate')) {
            $orchFn = 'mh_orchestrate';
            $orchLoaded = true;
        } else {
            $orchError = 'missing_mh_orchestrate';
        }
    } else {
        $orchError = 'missing_orchestrator_file';
    }
} catch (Throwable $e) {
    $orchError = 'orchestrator_include_failed:' . $e->getMessage();
}
$studioPayload = [
    'tenant_id' => (string)$ctx['tenant_id'],
    'persona_id' => (string)$ctx['persona_id'],
    'user_id' => (string)$ctx['user_id'],
    'meta_human_id' => (string)$ctx['meta_human_id'],
    'session_id' => (string)$ctx['session_id'],
    'device_id' => (string)$ctx['device_id'],
    'input' => [
        'text' => $lastUserText,
        'images' => $images,
        'camera_frames' => $cameraFrames,
        'uploads' => $uploads,
    ],
    'route_hint' => $routeHint,
    'task_type' => $taskType,
    'vision_mode' => $visionMode,
    'tools' => $tools,
    'memory' => $memoryBundle,
];

if (!$orchLoaded || !is_callable($orchFn)) {
    mh_resp_json([
        'ok' => false,
        'error' => 'upstream_failed',
        'upstream_status' => 0,
        'upstream_error' => $orchError !== '' ? $orchError : 'orchestrator_unavailable',
    ], 502);
}

$studioResp = null;
try {
    [$st, $json] = $orchFn($studioPayload);
    $studioResp = ['ok' => (int)$st >= 200 && (int)$st < 300, 'status' => (int)$st, 'json' => $json];
} catch (Throwable $e) {
    $studioResp = ['ok' => false, 'status' => 0, 'error' => $e->getMessage(), 'json' => null];
}

if (!$studioResp['ok'] || !is_array($studioResp['json'])) {
    $err = is_string($studioResp['error'] ?? null) ? (string)$studioResp['error'] : '';
    mh_resp_json([
        'ok' => false,
        'error' => 'upstream_failed',
        'upstream_status' => (int)($studioResp['status'] ?? 0),
        'upstream_error' => $err,
        'upstream_body' => is_array($studioResp['json'] ?? null) ? $studioResp['json'] : null,
    ], 502);
}

$assistantText = '';
$result = $studioResp['json']['result'] ?? null;
if (is_array($result)) {
    $assistantText = is_string($result['choices'][0]['message']['content'] ?? null) ? (string)$result['choices'][0]['message']['content'] : '';
}
if (trim($assistantText) !== '') {
    mhw_memory_ingest_store_one($ctx, [
        'id' => $idBase . '_a',
        'kind' => 'chat_assistant',
        'source' => 'meta_human_respond',
        'text' => $assistantText,
        'tags' => $userTags,
    ]);
}

mh_resp_json([
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
    'memory' => [
        'semantic_hits' => is_array($semanticHits) ? count($semanticHits) : 0,
        'graph_items' => is_array($graphSummary) ? count($graphSummary) : 0,
    ],
    'result' => $studioResp['json'],
]);
