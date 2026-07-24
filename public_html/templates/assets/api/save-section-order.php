<?php
/**
 * Save Section Order API
 * Saves the order of sections in the layout manager
 */

require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get the JSON data
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['order'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$order = $data['order'];

// Save to configuration file
$configPath = getDataPath() . '/global-ui/layout-manager/section-order.json';

try {
    $result = file_put_contents($configPath, json_encode([
        'order' => $order,
        'timestamp' => time()
    ], JSON_PRETTY_PRINT));

    if ($result !== false) {
        echo json_encode(['success' => true, 'message' => 'Section order saved']);
    } else {
        throw new Exception('Failed to write file');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save section order']);
}
?>