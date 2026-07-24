<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
$isAjax = true;
mh_kripz_require('block_browser', $isAjax);

$input = json_decode((string)file_get_contents('php://input'), true);
$root = is_array($input) ? (string)($input['root'] ?? '') : '';
$path = is_array($input) ? (string)($input['path'] ?? '') : '';

$roots = [
    'data' => '/data',
    'backup' => '/backup',
    'vector' => '/vector',
    'graph' => '/graph',
    'mysql' => '/mysql',
];

if (!isset($roots[$root])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Invalid root']);
    exit;
}

$base = $roots[$root];
$rel = str_replace(['..', '\\'], ['', '/'], $path);
$rel = ltrim($rel, '/');
$full = rtrim($base, '/') . ($rel !== '' ? ('/' . $rel) : '');

$realBase = realpath($base);
$realFull = realpath($full);
if ($realBase === false || $realFull === false || strpos($realFull, $realBase) !== 0) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Directory not found or access denied']);
    exit;
}
if (!is_dir($realFull)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'Not a directory']);
    exit;
}

$folders = [];
$files = [];
$canRead = is_readable($realFull);
$canExec = is_executable($realFull);
$canWrite = is_writable($realFull);
$stat = @stat($realFull);
$items = @scandir($realFull);
if (!is_array($items)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'error' => 'Failed to read directory',
        'details' => [
            'readable' => $canRead,
            'executable' => $canExec,
            'writable' => $canWrite,
            'uid' => is_array($stat) ? ($stat[4] ?? null) : null,
            'gid' => is_array($stat) ? ($stat[5] ?? null) : null,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    if (strpos($item, '.') === 0) continue;
    $itemPath = $realFull . '/' . $item;
    if (is_dir($itemPath)) {
        $folders[] = $item;
    } else {
        $files[] = $item;
    }
}
sort($folders);
sort($files);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'success' => true,
    'root' => $root,
    'path' => $rel,
    'base' => $base,
    'folders' => $folders,
    'files' => $files,
    'total' => count($folders) + count($files),
], JSON_UNESCAPED_SLASHES);
