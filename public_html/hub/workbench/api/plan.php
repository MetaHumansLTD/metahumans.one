<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$prompt = trim((string)($input['prompt'] ?? ''));
if ($prompt === '') {
    mhw_json(['success' => false, 'error' => 'missing_prompt'], 400);
    exit;
}

$system = "You are the Meta Humans Workbench planner.\nReturn a JSON object with keys: plan (array of steps), files (array of likely relative paths), commands (array of safe validation commands), risks (array).";
$messages = [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => "tenant_id={$ctx['tenant_id']}\npersona_id={$ctx['persona_id']}\n\n" . $prompt],
];

$ch = curl_init(mhw_ollama_chat_completions_url());
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'hermes3:latest',
    'messages' => $messages,
    'stream' => false,
    'temperature' => 0.2,
    'max_tokens' => 900,
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$resp = curl_exec($ch);
$err = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ch = null;

if ($resp === false || $err || $status < 200 || $status >= 300) {
    mhw_json(['success' => false, 'error' => 'planner_unreachable'], 502);
    exit;
}

$json = json_decode($resp, true);
$content = $json['choices'][0]['message']['content'] ?? '';
$content = is_string($content) ? trim($content) : '';
$planJson = json_decode($content, true);

if (!is_array($planJson)) {
    mhw_json(['success' => true, 'model' => 'hermes3:latest', 'raw' => $content]);
    exit;
}

mhw_json(['success' => true, 'model' => 'hermes3:latest', 'plan' => $planJson]);
