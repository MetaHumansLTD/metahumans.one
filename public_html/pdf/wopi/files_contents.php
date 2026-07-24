<?php
require_once dirname(__DIR__) . '/editor/core.php';

$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
    http_response_code(400);
    exit;
}

$row = mh_pdf_editor_get_record($id);
if (!$row) {
    http_response_code(404);
    exit;
}

$token = isset($_GET['access_token']) ? (string)$_GET['access_token'] : '';
if (!mh_pdf_editor_validate_token($row, $token)) {
    http_response_code(401);
    exit;
}

$path = (string)$row['path'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$override = strtoupper((string)($_SERVER['HTTP_X_WOPI_OVERRIDE'] ?? ''));

if ($method === 'GET') {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

if ($method === 'POST' && $override === 'PUT') {
    $bytes = (string)file_get_contents('php://input');
    try {
        mh_pdf_editor_put_contents($id, $bytes);
    } catch (Throwable $e) {
        http_response_code(500);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
