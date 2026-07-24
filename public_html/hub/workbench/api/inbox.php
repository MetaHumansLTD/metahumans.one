<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mhw_json(['success' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$kind = isset($_POST['kind']) ? strtolower(trim((string)$_POST['kind'])) : '';
$kind = $kind === 'voice' ? 'audio' : $kind;
if (!in_array($kind, ['audio', 'image'], true)) {
    mhw_json(['success' => false, 'error' => 'invalid_kind'], 400);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    mhw_json(['success' => false, 'error' => 'missing_file'], 400);
    exit;
}

$f = $_FILES['file'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    mhw_json(['success' => false, 'error' => 'upload_failed'], 400);
    exit;
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > 25_000_000) {
    mhw_json(['success' => false, 'error' => 'invalid_size'], 400);
    exit;
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    mhw_json(['success' => false, 'error' => 'invalid_upload'], 400);
    exit;
}

$tenantRoot = mhw_get_tenant_root($ctx);
$personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
$inboxDir = $tenantRoot . '/inbox/' . $personaSafe;
if (!mhw_ensure_dir($inboxDir)) {
    mhw_json(['success' => false, 'error' => 'inbox_create_failed'], 500);
    exit;
}

$ext = '';
$orig = isset($f['name']) ? (string)$f['name'] : '';
if ($orig !== '' && strpos($orig, '.') !== false) {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
}
if ($ext === '') {
    $ext = $kind === 'audio' ? 'wav' : 'png';
}

$id = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
$filename = $kind . '_' . $id . '.' . $ext;
$dest = $inboxDir . '/' . $filename;
 $metaPath = $inboxDir . '/' . $kind . '_' . $id . '.json';

if (!move_uploaded_file($tmp, $dest)) {
    mhw_json(['success' => false, 'error' => 'store_failed'], 500);
    exit;
}
@chmod($dest, 0600);

$meta = [
    'id' => $id,
    'kind' => $kind,
    'filename' => $filename,
    'size' => $size,
    'uploaded_at_utc' => gmdate('c'),
    'tenant_id' => $ctx['tenant_id'],
    'persona_id' => $ctx['persona_id'],
    'meta_human_id' => $ctx['meta_human_id'],
    'user_id' => $ctx['user_id'],
    'stored' => [
        'path' => $dest,
        'meta' => $metaPath,
    ],
    'path' => $dest,
];
file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
@chmod($metaPath, 0600);

mhw_json([
    'success' => true,
    'id' => $id,
    'kind' => $kind,
    'stored' => [
        'path' => $dest,
        'meta' => $metaPath,
        'url' => '/hub/workbench/api/inbox_asset.php?kind=' . rawurlencode($kind) . '&id=' . rawurlencode($id),
    ],
]);
