<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
$url = 'http://127.0.0.1:8888/rest/nlp/healthcheck';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$ch = null;
if ($error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}
http_response_code($code ?: 200);
if (stripos((string) $body, '{') === 0 || stripos((string) $body, '[') === 0) {
    header('Content-Type: application/json');
    echo $body;
} else {
    header('Content-Type: text/plain');
    echo $body;
}
