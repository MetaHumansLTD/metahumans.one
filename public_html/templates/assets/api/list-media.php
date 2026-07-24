<?php
// List local media images for logo selection
require_once '../../../cue.php';

header('Content-Type: application/json');

function scanImages(string $baseDir, int $max = 300): array {
    $results = [];
    $ex = ['jpg','jpeg','png','gif','svg','webp'];
    $count = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($count >= $max) break;
        if ($file->isFile()) {
            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
            if (in_array($ext, $ex)) {
                $abs = $file->getPathname();
                // Convert to web path relative to public root
                $public = getPublicPath();
                $rel = str_replace(['\\'], '/', substr($abs, strlen($public)));
                if ($rel[0] !== '/') { $rel = '/' . $rel; }
                $results[] = [
                    'name' => $file->getFilename(),
                    'path' => $rel,
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime()
                ];
                $count++;
            }
        }
    }
    return $results;
}

$imagesDir = getTemplatesPath() . '/assets/images';
if (!is_dir($imagesDir)) {
    echo json_encode(['success' => false, 'error' => 'Images directory not found']);
    exit;
}

echo json_encode(['success' => true, 'items' => scanImages($imagesDir)]);