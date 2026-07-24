<?php

require_once __DIR__ . '/../../auth/asset_signing.php';

$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
$exp = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
$sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';

try {
    if ($path === '' || $exp <= 0 || $sig === '') {
        http_response_code(400);
        exit;
    }
    $now = time();
    if ($exp < ($now - 30)) {
        http_response_code(403);
        exit;
    }
    if (!mh_asset_verify($path, $exp, $sig)) {
        http_response_code(403);
        exit;
    }
    $rp = mh_asset_realpath($path);
    if (!is_file($rp)) {
        http_response_code(404);
        exit;
    }
    $size = (int)@filesize($rp);
    if ($size < 0) $size = 0;
    $mime = mh_asset_mime($rp);
    $maxAge = max(0, min(86400, $exp - $now));

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('Cache-Control: public, max-age=' . $maxAge);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    $range = isset($_SERVER['HTTP_RANGE']) ? trim((string)$_SERVER['HTTP_RANGE']) : '';
    if ($range === '' || $size === 0) {
        header('Content-Length: ' . $size);
        if ($method === 'HEAD') {
            exit;
        }
        $fp = fopen($rp, 'rb');
        if ($fp === false) {
            http_response_code(404);
            exit;
        }
        while (!feof($fp)) {
            $buf = fread($fp, 1024 * 1024);
            if ($buf === false) break;
            echo $buf;
            if (function_exists('ob_flush')) { @ob_flush(); }
            if (function_exists('flush')) { @flush(); }
        }
        fclose($fp);
        exit;
    }

    if (!preg_match('/^bytes=([0-9]*)-([0-9]*)$/', $range, $m)) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $startRaw = $m[1];
    $endRaw = $m[2];
    $start = $startRaw !== '' ? (int)$startRaw : -1;
    $end = $endRaw !== '' ? (int)$endRaw : -1;

    if ($start < 0) {
        $suffix = $end >= 0 ? $end : 0;
        if ($suffix <= 0) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        if ($end < 0 || $end >= $size) $end = $size - 1;
    }

    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $length = ($end - $start) + 1;
    http_response_code(206);
    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);

    if ($method === 'HEAD') {
        exit;
    }

    $fp = fopen($rp, 'rb');
    if ($fp === false) {
        http_response_code(404);
        exit;
    }
    if (fseek($fp, $start) !== 0) {
        fclose($fp);
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $toRead = min(1024 * 1024, $remaining);
        $buf = fread($fp, $toRead);
        if ($buf === false || $buf === '') break;
        $remaining -= strlen($buf);
        echo $buf;
        if (function_exists('ob_flush')) { @ob_flush(); }
        if (function_exists('flush')) { @flush(); }
    }
    fclose($fp);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    exit;
}
