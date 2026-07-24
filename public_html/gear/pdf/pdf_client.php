<?php
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'pdf_client.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

require_once __DIR__ . '/pdf_config.php';

function mh_pdf_convert_html_to_pdf_bytes(string $html, string $fileName = 'document.pdf'): string
{
    $cfg = mh_pdf_load_config();
    $base = (string)($cfg['stirling_base_url'] ?? '');
    $path = (string)($cfg['stirling_html_to_pdf_path'] ?? '/api/v1/convert/html/pdf');
    $apiKey = (string)($cfg['stirling_api_key'] ?? '');

    $base = rtrim($base, '/');
    if ($base === '') {
        throw new RuntimeException('PDF engine is not configured (missing base URL)');
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/api/v1/convert/html/pdf';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'mh_pdf_');
    if (!is_string($tmp) || $tmp === '') {
        throw new RuntimeException('Failed to allocate temp file');
    }
    $tmpHtml = $tmp . '.html';
    @rename($tmp, $tmpHtml);
    file_put_contents($tmpHtml, $html);

    $ch = curl_init($base . $path);
    $headers = [];
    if ($apiKey !== '') {
        $headers[] = 'X-API-KEY: ' . $apiKey;
    }

    $post = [
        'fileInput' => new CURLFile($tmpHtml, 'text/html', 'document.html'),
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;
    @unlink($tmpHtml);

    if ($body === false) {
        throw new RuntimeException('PDF conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
    }
    if ((int)$code < 200 || (int)$code >= 300) {
        $msg = is_string($body) ? trim($body) : '';
        throw new RuntimeException('PDF conversion HTTP ' . (int)$code . ($msg !== '' ? (': ' . substr($msg, 0, 500)) : ''));
    }

    $fileName = basename($fileName !== '' ? $fileName : 'document.pdf');
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'pdf') {
        $fileName .= '.pdf';
    }

    return (string)$body;
}

function mh_pdf_send_pdf_bytes(string $pdfBytes, string $fileName = 'document.pdf'): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    $fileName = basename($fileName !== '' ? $fileName : 'document.pdf');
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'pdf') {
        $fileName .= '.pdf';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string)strlen($pdfBytes));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdfBytes;
    exit;
}
