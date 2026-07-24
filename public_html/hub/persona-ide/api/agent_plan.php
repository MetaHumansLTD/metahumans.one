<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);
require_once __DIR__ . '/../../../.cue/cue.php';
require_once __DIR__ . '/../../../auth/auth_functions.php';

function mh_ollama_chat_completions_url(): string {
    if (function_exists('mh_internal_endpoint_url')) {
        $v = mh_internal_endpoint_url('ollama');
        if (is_string($v) && trim($v) !== '') {
            $v = rtrim(trim($v), '/');
            if (preg_match('~/v1/chat/completions/?$~', $v)) return rtrim($v, '/');
            return $v . '/v1/chat/completions';
        }
    }
    $v = getenv('MH_OLLAMA_BASE_URL');
    if (!is_string($v) || trim($v) === '') $v = getenv('OLLAMA_BASE_URL');
    if (!is_string($v) || trim($v) === '') $v = getenv('OLLAMA_HOST');
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') $v = 'http://meta.superhumans.one:11434';
    $v = rtrim($v, '/');
    if (preg_match('~/v1/chat/completions/?$~', $v)) return rtrim($v, '/');
    return $v . '/v1/chat/completions';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

$username = (string)$_SESSION['mh_auth_user'];
mh_auth_load_user_context($username);
$pricing = mh_charge_service_tokens($username, 'persona_ide:plan', 1, [], 2);
if (!$pricing['success']) {
    http_response_code(402);
    echo json_encode(['success' => false, 'error' => 'insufficient_tokens', 'tokens' => (int)($pricing['tokens'] ?? 0)]);
    exit;
}
header('X-MH-Tokens-Remaining: ' . (int)($pricing['tokens'] ?? 0));

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$prompt = trim((string)($input['prompt'] ?? ''));
if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_prompt']);
    exit;
}

$system = "You are the Meta Humans Persona Headless IDE planner.\nReturn a JSON object with keys: plan (array of steps), files (array of likely file paths), commands (array of shell commands to validate), risks (array).";
$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $prompt]
];

$ch = curl_init(mh_ollama_chat_completions_url());
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'hermes3:latest',
    'messages' => $messages,
    'stream' => false,
    'temperature' => 0.2,
    'max_tokens' => 900
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$resp = curl_exec($ch);
$err = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ch = null;

if ($resp === false || $err) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'ollama_unreachable']);
    exit;
}

$json = json_decode($resp, true);
$content = $json['choices'][0]['message']['content'] ?? '';
$content = is_string($content) ? trim($content) : '';

$planJson = json_decode($content, true);
if (!is_array($planJson)) {
    echo json_encode([
        'success' => true,
        'model' => 'hermes3:latest',
        'raw' => $content
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'model' => 'hermes3:latest',
    'plan' => $planJson
]);
