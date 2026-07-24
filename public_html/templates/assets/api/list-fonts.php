<?php
// List local fonts and families under templates/assets/fonts
require_once '../../../cue.php';

header('Content-Type: application/json');

$fontsDir = getTemplatesPath() . '/assets/fonts';
if (!is_dir($fontsDir)) {
    echo json_encode(['success' => false, 'error' => 'Fonts directory not found']);
    exit;
}

$fontExts = ['woff2','woff','ttf','otf','eot','svg','css'];
$items = [];
$families = [];

// Helper: derive family name from filename heuristics
function familyFromFilename(string $name): string {
    $base = preg_replace('/\.(woff2|woff|ttf|otf|eot|svg|css)$/i', '', $name);
    // Remove common weight/style tags
    $base = preg_replace('/\b(light|regular|bold|ultrabold|black|medium|semi|demi|italic|it|thin|normal)\b/i', '', $base);
    $base = trim(preg_replace('/[-_]+/', ' ', $base));
    return ucfirst($base);
}

// Scan directory (non-recursive, then recursive for subfolders)
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fontsDir, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $fontExts)) continue;

    // Skip icon-related files from families list
    $lower = strtolower($file->getFilename());
    if (strpos($lower, 'fontawesome') !== false || strpos($lower, 'fa-') !== false || strpos($lower, 'glyphicons') !== false) {
        // Still include as item but not a text family
        $isIcon = true;
    } else {
        $isIcon = false;
    }

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

    $fam = familyFromFilename($file->getFilename());
    $items[] = [
        'name' => $file->getFilename(),
        'path' => $rel,
        'ext' => $ext,
        'family' => $fam,
        'isIcon' => $isIcon
    ];
    if (!$isIcon && !empty($fam)) {
        $families[$fam] = true;
    }
}

// Also parse CSS files for explicit font-family declarations
foreach (glob($fontsDir . '/*.css') as $cssFile) {
    $base = basename($cssFile);
    // Skip known icon CSS files
    if (preg_match('/^(all\.min\.css|fontawesome.*\.css|fa-.*\.css|glyphicons.*\.css)$/i', $base)) {
        continue;
    }
    $content = @file_get_contents($cssFile);
    if ($content && preg_match_all('/font-family\s*:\s*([\'\"]?)([^;\'\"]+)\1/i', $content, $matches)) {
        foreach ($matches[2] as $fam) {
            $fam = trim($fam);
            // Filter out common icon family names if present
            if ($fam === '' || stripos($fam, 'font awesome') !== false || stripos($fam, 'glyphicons') !== false) {
                continue;
            }
            $families[$fam] = true;
        }
    }
}

// Prepare response
$familiesList = array_values(array_unique(array_keys($families)));
sort($familiesList);

echo json_encode([
    'success' => true,
    'families' => $familiesList,
    'items' => $items
]);
exit;