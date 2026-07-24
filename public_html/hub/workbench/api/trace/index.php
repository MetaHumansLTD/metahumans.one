<?php
require_once dirname(__DIR__) . '/_context.php';

$ctx = mhw_require_context();

$pathInfo = isset($_SERVER['PATH_INFO']) ? (string)$_SERVER['PATH_INFO'] : '';
$traceId = '';
if ($pathInfo !== '') {
    $traceId = trim($pathInfo, '/');
}
if ($traceId === '' && isset($_GET['trace_id'])) {
    $traceId = trim((string)$_GET['trace_id']);
}

if ($traceId === '' || !preg_match('/^tr_[a-f0-9]{32}$/', $traceId)) {
    mhw_json(['success' => false, 'error' => 'invalid_trace_id'], 400);
    exit;
}

$tenantRoot = mhw_get_tenant_root($ctx);
$tracePath = rtrim($tenantRoot, '/') . '/audit/traces/' . $traceId . '.json';
if (!is_file($tracePath)) {
    mhw_json(['success' => false, 'error' => 'not_found'], 404);
    exit;
}

$raw = file_get_contents($tracePath);
if ($raw === false) {
    mhw_json(['success' => false, 'error' => 'read_failed'], 500);
    exit;
}

$json = json_decode($raw, true);
if (!is_array($json)) {
    mhw_json(['success' => false, 'error' => 'invalid_trace'], 500);
    exit;
}

mhw_json(['success' => true, 'trace' => $json]);

