<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$action = isset($input['action']) ? (string)$input['action'] : '';
$relPath = isset($input['path']) ? (string)$input['path'] : '';
$projectId = isset($input['project_id']) ? (string)$input['project_id'] : 'default';

$root = mhw_get_workspace_root($ctx, $projectId);
if (!mhw_ensure_dir($root)) {
    mhw_json(['success' => false, 'error' => 'workspace_create_failed'], 500);
    exit;
}

$full = mhw_join_under($root, $relPath);

if ($action === 'list') {
    $target = $full;
    if (!is_dir($target)) {
        $target = $root;
    }
    $files = [];
    $max = 220;
    $baseLen = strlen(rtrim($root, '/')) + 1;
    $skipDirs = ['node_modules' => true, '.git' => true, '.idea' => true, '.cache' => true];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (count($files) >= $max) break;
            $path = (string)$f->getPathname();
            if (is_link($path)) continue;
            $name = (string)$f->getFilename();
            if ($f->isDir() && isset($skipDirs[$name])) {
                $it->next();
                continue;
            }
            $rel = $path;
            if (strpos($rel, $root) === 0) {
                $rel = substr($rel, $baseLen);
            }
            $rel = str_replace('\\', '/', $rel);
            $files[] = [
                'path' => $rel,
                'type' => $f->isDir() ? 'dir' : 'file',
                'size' => $f->isFile() ? (int)$f->getSize() : null,
                'mtime' => (int)$f->getMTime(),
            ];
        }
    } catch (Throwable $e) {
        mhw_json(['success' => false, 'error' => 'list_failed'], 500);
        exit;
    }
    mhw_json(['success' => true, 'root' => $root, 'files' => $files]);
    exit;
}

if ($action === 'read') {
    if (!is_file($full)) {
        mhw_json(['success' => false, 'error' => 'not_found'], 404);
        exit;
    }
    $maxBytes = 300_000;
    $content = file_get_contents($full, false, null, 0, $maxBytes);
    if ($content === false) {
        mhw_json(['success' => false, 'error' => 'read_failed'], 500);
        exit;
    }
    mhw_json([
        'success' => true,
        'path' => mhw_normalize_relpath($relPath),
        'truncated' => filesize($full) > $maxBytes,
        'content' => $content,
    ]);
    exit;
}

if ($action === 'write') {
    $content = isset($input['content']) ? (string)$input['content'] : null;
    if ($content === null) {
        mhw_json(['success' => false, 'error' => 'missing_content'], 400);
        exit;
    }
    $dir = dirname($full);
    if (!mhw_ensure_dir($dir)) {
        mhw_json(['success' => false, 'error' => 'dir_create_failed'], 500);
        exit;
    }
    if (file_put_contents($full, $content) === false) {
        mhw_json(['success' => false, 'error' => 'write_failed'], 500);
        exit;
    }
    mhw_json(['success' => true, 'path' => mhw_normalize_relpath($relPath), 'bytes' => strlen($content)]);
    exit;
}

if ($action === 'apply') {
    $ops = isset($input['ops']) && is_array($input['ops']) ? $input['ops'] : null;
    if (!$ops) {
        mhw_json(['success' => false, 'error' => 'missing_ops'], 400);
        exit;
    }
    $applied = [];
    foreach ($ops as $op) {
        if (!is_array($op)) continue;
        $p = isset($op['path']) ? (string)$op['path'] : '';
        $c = array_key_exists('content', $op) ? (string)$op['content'] : null;
        if ($p === '' || $c === null) continue;
        $dest = mhw_join_under($root, $p);
        $dir = dirname($dest);
        if (!mhw_ensure_dir($dir)) continue;
        if (file_put_contents($dest, $c) === false) continue;
        $applied[] = ['path' => mhw_normalize_relpath($p), 'bytes' => strlen($c)];
    }
    mhw_json(['success' => true, 'applied' => $applied]);
    exit;
}

mhw_json(['success' => false, 'error' => 'invalid_action'], 400);
