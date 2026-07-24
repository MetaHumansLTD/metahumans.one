<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/pdf_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$html = (string)($data['html'] ?? '');
$fileName = (string)($data['file_name'] ?? 'document.pdf');
if ($html === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'html is required']);
    exit;
}

try {
    $pdf = mh_pdf_convert_html_to_pdf_bytes($html, $fileName);
    mh_pdf_send_pdf_bytes($pdf, $fileName);
} catch (Throwable $e) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
