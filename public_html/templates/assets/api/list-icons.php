<?php
// List local icon packs and files under templates/assets/icons
require_once '../../../cue.php';

header('Content-Type: application/json');

$iconsDir = getTemplatesPath() . '/assets/icons';
if (!is_dir($iconsDir)) {
    echo json_encode(['success' => false, 'error' => 'Icons directory not found']);
    exit;
}

// Optionally restrict to a specific pack (subdirectory)
$pack = isset($_GET['pack']) ? preg_replace('/[^a-z0-9_\-]/i', '', $_GET['pack']) : '';
if ($pack && !is_dir($iconsDir . '/' . $pack)) {
    // If requested pack does not exist, ignore filter
    $pack = '';
}

$fileExts = ['css','svg','js','json','png','jpg','jpeg','webp'];
$items = [];
$packs = [];

// List pack directories (top-level only)
$dirIter = new DirectoryIterator($iconsDir);
foreach ($dirIter as $entry) {
    if ($entry->isDot()) continue;
    if ($entry->isDir()) {
        $packs[] = $entry->getFilename();
    }
}
sort($packs);

// Build iterator: either the icons dir or selected pack subdir
$scanRoot = $pack ? ($iconsDir . '/' . $pack) : $iconsDir;
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $fileExts)) continue;

    // Normalize Windows paths and compute web-relative path safely
    $abs = str_replace('\\', '/', $file->getPathname());
    $public = str_replace('\\', '/', getPublicPath());
    if (stripos($abs, $public) === 0) {
        $rel = substr($abs, strlen($public));
    } else {
        $rel = $abs; // fallback to absolute-like path
    }
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] !== '/') { $rel = '/' . ltrim($rel, '/'); }

    // Determine pack from path
    $packName = '';
    $pathParts = explode('/', str_replace('\\', '/', $file->getPath()));
    $iconsIdx = array_search('icons', $pathParts);
    if ($iconsIdx !== false && isset($pathParts[$iconsIdx + 1])) {
        $packName = $pathParts[$iconsIdx + 1];
    }

    $items[] = [
        'name' => $file->getFilename(),
        'path' => $rel,
        'ext' => $ext,
        'pack' => $packName
    ];
}

echo json_encode([
    'success' => true,
    'packs' => $packs,
    'items' => $items,
    'pack' => $pack
]);
exit;
?>