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
$size = is_file($path) ? (int)filesize($path) : 0;
$version = (int)$row['version'];
$ownerId = (string)$row['owner_id'];

header('Content-Type: application/json; charset=utf-8');

$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one';
$origin = 'https://' . preg_replace('/:\\d+$/', '', strtolower($host));

echo json_encode([
    'BaseFileName' => (string)$row['filename'],
    'Size' => $size,
    'OwnerId' => $ownerId,
    'UserId' => $ownerId,
    'UserFriendlyName' => $ownerId,
    'Version' => (string)$version,
    'SupportsLocks' => false,
    'SupportsUpdate' => true,
    'UserCanWrite' => true,
    'DisablePrint' => false,
    'DisableExport' => false,
    'DisableCopy' => false,
    'PostMessageOrigin' => $origin,
], JSON_UNESCAPED_SLASHES);
