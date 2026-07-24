<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}
if (isset($data['query']) && !isset($data['queries'])) {
    $data['queries'] = [$data['query']];
}
if (empty($data['queries']) || !is_array($data['queries'])) {
    http_response_code(400);
    echo json_encode(['error' => 'queries array is required']);
    exit;
}
if (empty($data['namespace'])) {
    $data['namespace'] = 'app';
}
if (empty($data['applicationName'])) {
    $data['applicationName'] = 'metahumans';
}
if (empty($data['context']) || !is_array($data['context'])) {
    $data['context'] = [];
}
if (empty($data['context']['language'])) {
    $data['context']['language'] = 'en';
}
if (!array_key_exists('registerQuery', $data['context'])) {
    $data['context']['registerQuery'] = true;
}
$url = 'http://127.0.0.1:8888/rest/nlp/parse';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$ch = null;
if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach Tock NLP API', 'details' => $error]);
    exit;
}
http_response_code($code ?: 200);
echo $body;
