<?php
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/.cue/cue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_json']);
  exit;
}

$messages = $body['messages'] ?? null;
if (!is_array($messages)) {
  $msg = trim((string)($body['message'] ?? ''));
  $messages = [];
  if ($msg !== '') {
    $messages[] = ['role' => 'user', 'content' => $msg];
  }
}

if (!is_array($messages) || count($messages) == 0) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_messages']);
  exit;
}

$cfgFile = __DIR__ . '/hermes.json';
$cfg = [];
if (file_exists($cfgFile)) {
  $cfg = json_decode(file_get_contents($cfgFile), true);
  if (!is_array($cfg)) {
    $cfg = [];
  }
}

$baseUrl = (string)($cfg['base_url'] ?? 'https://metahumans.one/hermes');
$apiKey = (string)($cfg['api_key'] ?? '');
$model = (string)($cfg['model'] ?? 'hermes-405b');
$timeoutSec = (int)($cfg['timeout_sec'] ?? 30);

$fallbackOllamaBase = function_exists('mh_internal_endpoint_url') ? (string)mh_internal_endpoint_url('ollama') : '';
if (!is_string($fallbackOllamaBase) || trim($fallbackOllamaBase) === '') $fallbackOllamaBase = getenv('MH_OLLAMA_BASE_URL');
if (!is_string($fallbackOllamaBase) || trim($fallbackOllamaBase) === '') $fallbackOllamaBase = getenv('OLLAMA_BASE_URL');
if (!is_string($fallbackOllamaBase) || trim($fallbackOllamaBase) === '') $fallbackOllamaBase = getenv('OLLAMA_HOST');
$fallbackOllamaBase = is_string($fallbackOllamaBase) ? trim($fallbackOllamaBase) : '';
if ($fallbackOllamaBase === '') $fallbackOllamaBase = 'http://meta.superhumans.one:11434';
$fallbackOllamaEndpoint = rtrim($fallbackOllamaBase, '/') . '/api/chat';

$endpoint = rtrim($baseUrl, '/');
$u = parse_url($endpoint);
$host = is_array($u) && isset($u['host']) ? (string)$u['host'] : '';
$port = is_array($u) && isset($u['port']) ? (int)$u['port'] : 0;
$path = is_array($u) && isset($u['path']) ? (string)$u['path'] : '';
$isOllama = ($port === 11434) && ($path === '' || $path === '/');
if ($isOllama) {
  $endpoint = $endpoint . '/api/chat';
  $payload = [
    'model' => $model,
    'messages' => $messages,
    'stream' => false,
    'options' => [
      'temperature' => 0.3,
    ],
  ];
} else {
  if (str_ends_with($endpoint, '/ai/chat.php')) {
    // proxy mode (forward to superhumans ai gateway)
  } else {
    $endpoint = $endpoint . '/v1/chat/completions';
  }
  $payload = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => 0.3,
  ];
}

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeoutSec));

$headers = ['Content-Type: application/json'];
if ($apiKey !== '') {
  $headers[] = 'Authorization: Bearer ' . $apiKey;
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$resBody = curl_exec($ch);
$err = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$ch = null;

if ($resBody === false || $httpCode >= 500) {
  if (!$isOllama && $endpoint !== $fallbackOllamaEndpoint) {
    $fallbackEndpoint = $fallbackOllamaEndpoint;
    $fallbackModel = 'hermes3:latest';
    $fallbackPayload = [
      'model' => $fallbackModel,
      'messages' => $messages,
      'stream' => false,
      'options' => [
        'temperature' => 0.3,
      ],
    ];
    $ch2 = curl_init($fallbackEndpoint);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch2, CURLOPT_TIMEOUT, max(5, $timeoutSec));
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($fallbackPayload));
    $resBody2 = curl_exec($ch2);
    $err2 = curl_error($ch2);
    $httpCode2 = (int)curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
    $ch2 = null;
    if (is_string($resBody2) && $resBody2 !== '' && $httpCode2 >= 200 && $httpCode2 < 300) {
      $data2 = json_decode($resBody2, true);
      $reply2 = '';
      if (is_array($data2) && isset($data2['message']['content'])) {
        $reply2 = (string)$data2['message']['content'];
      }
      echo json_encode(['reply' => $reply2, 'raw' => $data2, 'fallback' => true]);
      exit;
    }
    http_response_code(502);
    echo json_encode(['error' => 'hermes_unreachable', 'detail' => ($err2 !== '' ? $err2 : $err), 'endpoint' => $endpoint, 'fallback_endpoint' => $fallbackEndpoint]);
    exit;
  }
  http_response_code(502);
  echo json_encode(['error' => 'hermes_unreachable', 'detail' => $err, 'endpoint' => $endpoint]);
  exit;
}

$data = json_decode($resBody, true);
  if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode > 0 ? $httpCode : 502);
    if (is_array($data)) {
      echo json_encode($data);
    } else {
      echo $resBody;
    }
    exit;
  }

$reply = '';
if ($isOllama && is_array($data) && isset($data['message']['content'])) {
  $reply = (string)$data['message']['content'];
} elseif (is_array($data) && isset($data['choices'][0]['message']['content'])) {
  $reply = (string)$data['choices'][0]['message']['content'];
}

echo json_encode(['reply' => $reply, 'raw' => $data]);
