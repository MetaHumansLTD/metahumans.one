<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

function mh_download_send_file(string $fullPath, string $downloadName, string $mimeType = 'application/octet-stream', string $disposition = 'attachment'): void
{
    if ($fullPath === '' || !is_file($fullPath) || !is_readable($fullPath)) {
        http_response_code(404);
        exit;
    }

    while (ob_get_level()) {
        ob_end_clean();
    }

    $downloadName = basename($downloadName !== '' ? $downloadName : basename($fullPath));
    $mimeType = $mimeType !== '' ? $mimeType : 'application/octet-stream';
    $disposition = $disposition === 'inline' ? 'inline' : 'attachment';

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)filesize($fullPath));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    readfile($fullPath);
    exit;
}

function mh_download_secure_rel(string $relPath, string $downloadName, string $mimeType = 'application/octet-stream', string $disposition = 'attachment'): void
{
    $relPath = str_replace(["..\\", "../"], '', $relPath);
    $relPath = ltrim($relPath, '/');
    if ($relPath === '' || (stripos($relPath, 'tenants/') !== 0 && stripos($relPath, 'documents/') !== 0 && stripos($relPath, 'global/') !== 0)) {
        http_response_code(403);
        exit;
    }

    $paths = cue_autoload('paths');
    if (!$paths || !method_exists($paths, 'getSecureFilePath')) {
        http_response_code(500);
        exit;
    }

    $full = $paths->getSecureFilePath($relPath, false);
    if (!is_string($full) || $full === '') {
        http_response_code(404);
        exit;
    }

    mh_download_send_file($full, $downloadName, $mimeType, $disposition);
}

