<?php
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'pdf_config.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

if (!function_exists('cue_autoload')) {
    require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
}

function mh_pdf_config_path(): string
{
    if (function_exists('getDataPath')) {
        return rtrim(getDataPath(), '/') . '/config/pdf-platform.json';
    }
    return dirname(dirname(__DIR__)) . '/.data/config/pdf-platform.json';
}

function mh_pdf_load_config(): array
{
    $path = mh_pdf_config_path();
    if (!is_file($path)) {
        return [
            'stirling_base_url' => '',
            'stirling_api_key' => '',
            'stirling_html_to_pdf_path' => '/api/v1/convert/html/pdf',
        ];
    }
    $raw = (string)file_get_contents($path);
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        $cfg = [];
    }
    $base = isset($cfg['stirling_base_url']) ? (string)$cfg['stirling_base_url'] : '';
    $base = rtrim($base, '/');
    if ($base === '') $base = '';
    $pathConv = isset($cfg['stirling_html_to_pdf_path']) ? (string)$cfg['stirling_html_to_pdf_path'] : '/api/v1/convert/html/pdf';
    if ($pathConv === '') {
        $pathConv = '/api/v1/convert/html/pdf';
    }
    if ($pathConv[0] !== '/') {
        $pathConv = '/' . $pathConv;
    }
    return [
        'stirling_base_url' => $base,
        'stirling_api_key' => isset($cfg['stirling_api_key']) ? (string)$cfg['stirling_api_key'] : '',
        'stirling_html_to_pdf_path' => $pathConv,
    ];
}

function mh_pdf_save_config(array $cfg): void
{
    $path = mh_pdf_config_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $base = isset($cfg['stirling_base_url']) ? rtrim((string)$cfg['stirling_base_url'], '/') : '';
    $apiKey = isset($cfg['stirling_api_key']) ? (string)$cfg['stirling_api_key'] : '';
    $convPath = isset($cfg['stirling_html_to_pdf_path']) ? (string)$cfg['stirling_html_to_pdf_path'] : '/api/v1/convert/html/pdf';
    if ($convPath !== '' && $convPath[0] !== '/') {
        $convPath = '/' . $convPath;
    }
    $out = [
        'stirling_base_url' => $base,
        'stirling_api_key' => $apiKey,
        'stirling_html_to_pdf_path' => $convPath !== '' ? $convPath : '/api/v1/convert/html/pdf',
    ];
    file_put_contents($path, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}
