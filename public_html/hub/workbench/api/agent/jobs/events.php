<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_context.php';

mhw_require_context();

$jobId = isset($_GET['job_id']) ? trim((string)$_GET['job_id']) : '';
if ($jobId === '') {
    mhw_json(['success' => false, 'error' => 'missing_job_id'], 400);
    exit;
}

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;

$agentBase = getenv('MHW_AGENT_API_URL');
$agentBase = is_string($agentBase) ? trim($agentBase) : '';
if ($agentBase === '') $agentBase = 'https://meta.superhumans.one/api/agent';
$agentBase = rtrim($agentBase, '/');

$url = $agentBase . '/jobs/' . rawurlencode($jobId) . '/events?since=' . $since;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function($ch, $data) {
        echo $data;
        @ob_flush();
        flush();
        return strlen($data);
    },
    CURLOPT_TIMEOUT => 0,
]);

curl_exec($ch);
$ch = null;
