<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();
$workspace = mhw_get_workspace_root($ctx, 'default');

mhw_json([
    'success' => true,
    'context' => $ctx,
    'workspace_root' => $workspace,
]);
