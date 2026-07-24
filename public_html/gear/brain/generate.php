<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
$target = dirname(__DIR__, 2) . '/v1/meta-human/respond.php';
if (!file_exists($target)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Brain runtime is not available on this server']);
    exit;
}
require $target;
