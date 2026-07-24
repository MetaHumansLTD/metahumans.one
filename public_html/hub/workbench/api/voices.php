<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$tenantRoot = mhw_get_tenant_root($ctx);
$personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
$voicesDir = $tenantRoot . '/voices/' . $personaSafe;
if (!mhw_ensure_dir($voicesDir)) {
    mhw_json(['success' => false, 'error' => 'voices_create_failed'], 500);
    exit;
}

$serveById = function(string $voicesDir, string $id): void {
    $idSafe = mhw_sanitize_id($id);
    $metaPath = $voicesDir . '/voice_' . $idSafe . '.json';
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
    $filePath = isset($meta['path']) ? (string)$meta['path'] : '';
    if ($filePath === '' || !is_file($filePath)) {
        mhw_json(['success' => false, 'error' => 'file_missing'], 404);
        exit;
    }
    $voicesReal = realpath($voicesDir);
    $fileReal = realpath($filePath);
    if (!is_string($voicesReal) || $voicesReal === '' || !is_string($fileReal) || $fileReal === '' || strpos($fileReal, $voicesReal . DIRECTORY_SEPARATOR) !== 0) {
        mhw_json(['success' => false, 'error' => 'forbidden'], 403);
        exit;
    }
    $mime = isset($meta['mime']) ? (string)$meta['mime'] : '';
    if ($mime === '') {
        $ext = strtolower(pathinfo($fileReal, PATHINFO_EXTENSION));
        if ($ext === 'mp3') $mime = 'audio/mpeg';
        elseif ($ext === 'wav') $mime = 'audio/wav';
        else $mime = 'application/octet-stream';
    }
    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string)filesize($fileReal));
    readfile($fileReal);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    if ($id === '') {
        mhw_json(['success' => false, 'error' => 'missing_id'], 400);
        exit;
    }
    $serveById($voicesDir, $id);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$id = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
$idSafe = mhw_sanitize_id($id);

$audioBytes = null;
$mime = '';
$ext = '';
$orig = '';

if (isset($_FILES['file']) && is_array($_FILES['file'])) {
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mhw_json(['success' => false, 'error' => 'upload_failed'], 400);
        exit;
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0 || $size > 50_000_000) {
        mhw_json(['success' => false, 'error' => 'invalid_size'], 400);
        exit;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        mhw_json(['success' => false, 'error' => 'invalid_upload'], 400);
        exit;
    }
    $audioBytes = file_get_contents($tmp);
    if (!is_string($audioBytes) || $audioBytes === '') {
        mhw_json(['success' => false, 'error' => 'read_failed'], 500);
        exit;
    }
    $orig = isset($f['name']) ? (string)$f['name'] : '';
    if ($orig !== '' && strpos($orig, '.') !== false) {
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    }
} else {
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        mhw_json(['success' => false, 'error' => 'missing_file'], 400);
        exit;
    }
    $b64 = isset($json['audio_base64']) ? (string)$json['audio_base64'] : '';
    $mime = isset($json['mime']) ? (string)$json['mime'] : '';
    $ext = isset($json['ext']) ? (string)$json['ext'] : '';
    $audioBytes = base64_decode($b64, true);
    if (!is_string($audioBytes) || $audioBytes === '') {
        mhw_json(['success' => false, 'error' => 'invalid_base64'], 400);
        exit;
    }
    if ($ext !== '') {
        $ext = strtolower(preg_replace('/[^a-z0-9]+/', '', $ext));
    }
}

if ($ext === '') $ext = 'wav';
if (!in_array($ext, ['wav', 'mp3'], true)) $ext = 'wav';
if ($mime === '') $mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav';

$filename = 'voice_' . $idSafe . '.' . $ext;
$dest = $voicesDir . '/' . $filename;
if (file_put_contents($dest, $audioBytes) === false) {
    mhw_json(['success' => false, 'error' => 'store_failed'], 500);
    exit;
}
@chmod($dest, 0600);

$meta = [
    'id' => $id,
    'filename' => $filename,
    'path' => $dest,
    'mime' => $mime,
    'size' => strlen($audioBytes),
    'uploaded_at_utc' => gmdate('c'),
    'tenant_id' => $ctx['tenant_id'],
    'persona_id' => $ctx['persona_id'],
    'meta_human_id' => $ctx['meta_human_id'],
    'user_id' => $ctx['user_id'],
    'orig' => $orig,
];
$metaPath = $voicesDir . '/voice_' . $idSafe . '.json';
file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
@chmod($metaPath, 0600);

$url = '/hub/workbench/api/voices.php?id=' . rawurlencode($id);
mhw_json([
    'success' => true,
    'id' => $id,
    'stored' => [
        'path' => $dest,
        'meta' => $metaPath,
        'url' => $url,
    ],
]);
