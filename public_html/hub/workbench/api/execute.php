<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) $input = [];

$prompt = trim((string)($input['prompt'] ?? ''));
if ($prompt === '') {
    mhw_json(['success' => false, 'error' => 'missing_prompt'], 400);
    exit;
}

$workspaceRoot = mhw_get_workspace_root($ctx, 'default');
if (!mhw_ensure_dir($workspaceRoot)) {
    mhw_json(['success' => false, 'error' => 'workspace_create_failed'], 500);
    exit;
}

mhw_json([
    'success' => true,
    'message' => 'execution_scaffolded',
    'context' => $ctx,
    'workspace_root' => $workspaceRoot,
    'next' => [
        'runtime' => '/hub/workbench/api/runtime.php',
        'models' => '/hub/workbench/api/models.php',
        'inbox' => '/hub/workbench/api/inbox.php',
    ],
]);
