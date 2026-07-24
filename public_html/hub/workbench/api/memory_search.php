<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$queryText = isset($input['query']) ? trim((string)$input['query']) : '';
if ($queryText === '') {
    mhw_json(['success' => false, 'error' => 'query_required'], 400);
    exit;
}

$topK = isset($input['top_k']) ? (int)$input['top_k'] : 10;
if ($topK < 1) $topK = 1;
if ($topK > 50) $topK = 50;

$tenantId = (string)$ctx['tenant_id'];
$personaId = (string)$ctx['persona_id'];
$metaHumanId = (string)$ctx['meta_human_id'];

$filters = $input['filters'] ?? [];
if (!is_array($filters)) $filters = [];
if (isset($filters['tenant_id']) && is_string($filters['tenant_id']) && $filters['tenant_id'] !== '' && $filters['tenant_id'] !== $tenantId) {
    mhw_json(['success' => false, 'error' => 'tenant_mismatch'], 403);
    exit;
}

$filters['tenant_id'] = $tenantId;
if (!isset($filters['persona_id']) || !is_string($filters['persona_id']) || $filters['persona_id'] === '') {
    $filters['persona_id'] = $personaId;
}
if (!isset($filters['meta_human_id']) || !is_string($filters['meta_human_id']) || $filters['meta_human_id'] === '') {
    $filters['meta_human_id'] = $metaHumanId;
}

try {
    if (function_exists('cue_autoload')) {
        cue_autoload('embeddings');
        cue_autoload('vector');
    }

    $vec = embeddings_embed_text($queryText);
    if (!is_array($vec) || $vec === []) {
        mhw_json(['success' => false, 'error' => 'embedding_failed'], 500);
        exit;
    }

    $res = vector_search($tenantId, $vec, $filters, $topK);
    mhw_json([
        'success' => true,
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'result' => $res,
    ]);
} catch (Throwable $e) {
    if (function_exists('cue_autoload')) cue_autoload('error');
    if (function_exists('error_logError')) {
        error_logError('Memory search failed', [
            'error' => $e->getMessage(),
            'tenant' => $tenantId,
            'username' => (string)$ctx['username'],
        ]);
    }
    mhw_json(['success' => false, 'error' => 'memory_search_failed'], 500);
}

