<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$vector = $input['vector'] ?? null;
if (!is_array($vector) || $vector === []) {
    mhw_json(['success' => false, 'error' => 'vector_required'], 400);
    exit;
}

$topK = isset($input['top_k']) ? (int)$input['top_k'] : 10;
if ($topK < 1) $topK = 1;
if ($topK > 50) $topK = 50;

$filters = $input['filters'] ?? [];
if (!is_array($filters)) $filters = [];

if (isset($filters['tenant_id']) && is_string($filters['tenant_id']) && $filters['tenant_id'] !== '' && $filters['tenant_id'] !== (string)$ctx['tenant_id']) {
    if (function_exists('cue_autoload')) cue_autoload('error');
    if (function_exists('error_logError')) {
        error_logError('Vector gateway blocked tenant mismatch', ['requested' => (string)$filters['tenant_id'], 'tenant' => (string)$ctx['tenant_id'], 'username' => (string)$ctx['username']]);
    }
    mhw_json(['success' => false, 'error' => 'tenant_mismatch'], 403);
    exit;
}

$filters['tenant_id'] = (string)$ctx['tenant_id'];
if (!isset($filters['persona_id']) || !is_string($filters['persona_id']) || $filters['persona_id'] === '') {
    $filters['persona_id'] = (string)$ctx['persona_id'];
}
if (!isset($filters['meta_human_id']) || !is_string($filters['meta_human_id']) || $filters['meta_human_id'] === '') {
    $filters['meta_human_id'] = (string)$ctx['meta_human_id'];
}

try {
    if (function_exists('cue_autoload')) cue_autoload('vector');
    $tenantId = (string)$ctx['tenant_id'];
    $res = vector_search($tenantId, $vector, $filters, $topK);
    mhw_json(['success' => true, 'tenant_id' => $tenantId, 'result' => $res]);
} catch (Throwable $e) {
    if (function_exists('cue_autoload')) cue_autoload('error');
    if (function_exists('error_logError')) {
        error_logError('Vector search failed', ['error' => $e->getMessage(), 'tenant' => (string)$ctx['tenant_id'], 'username' => (string)$ctx['username']]);
    }
    mhw_json(['success' => false, 'error' => 'vector_search_failed'], 500);
}
