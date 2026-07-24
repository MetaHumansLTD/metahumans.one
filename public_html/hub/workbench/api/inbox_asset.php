<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
$kind = isset($_GET['kind']) ? strtolower(trim((string)$_GET['kind'])) : '';
if ($id === '' || $kind === '') {
    mhw_json(['success' => false, 'error' => 'missing_params'], 400);
    exit;
}
$kind = $kind === 'voice' ? 'audio' : $kind;
if (!in_array($kind, ['audio', 'image'], true)) {
    mhw_json(['success' => false, 'error' => 'invalid_kind'], 400);
    exit;
}

$tenantRoot = mhw_get_tenant_root($ctx);
$personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
$inboxDir = $tenantRoot . '/inbox/' . $personaSafe;

$idSafe = mhw_sanitize_id($id);
$metaPath = $inboxDir . '/' . $kind . '_' . $idSafe . '.json';
if (!is_file($metaPath)) {
    mhw_json(['success' => false, 'error' => 'not_found'], 404);
    exit;
}
$metaRaw = file_get_contents($metaPath);
$meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
if (!is_array($meta)) {
    mhw_json(['success' => false, 'error' => 'meta_invalid'], 500);
    exit;
}
$filename = isset($meta['filename']) ? (string)$meta['filename'] : '';
if ($filename === '') {
    mhw_json(['success' => false, 'error' => 'file_missing'], 404);
    exit;
}
$filePath = $inboxDir . '/' . $filename;
if (!is_file($filePath)) {
    mhw_json(['success' => false, 'error' => 'file_missing'], 404);
    exit;
}
$inboxReal = realpath($inboxDir);
$fileReal = realpath($filePath);
if (!is_string($inboxReal) || $inboxReal === '' || !is_string($fileReal) || $fileReal === '' || strpos($fileReal, $inboxReal . DIRECTORY_SEPARATOR) !== 0) {
    mhw_json(['success' => false, 'error' => 'forbidden'], 403);
    exit;
}

$ext = strtolower(pathinfo($fileReal, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if ($kind === 'audio') {
    if ($ext === 'mp3') $mime = 'audio/mpeg';
    elseif ($ext === 'wav') $mime = 'audio/wav';
    elseif ($ext === 'webm') $mime = 'audio/webm';
    elseif ($ext === 'ogg') $mime = 'audio/ogg';
} else {
    if ($ext === 'png') $mime = 'image/png';
    elseif ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
    elseif ($ext === 'webp') $mime = 'image/webp';
}

http_response_code(200);
header('Content-Type: ' . $mime);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($fileReal));
readfile($fileReal);
exit;

