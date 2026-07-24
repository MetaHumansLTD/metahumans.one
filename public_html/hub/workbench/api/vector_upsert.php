<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$points = $input['points'] ?? null;
if (!is_array($points) || $points === []) {
    mhw_json(['success' => false, 'error' => 'points_required'], 400);
    exit;
}

$tenantId = (string)$ctx['tenant_id'];
$personaId = (string)$ctx['persona_id'];
$metaHumanId = (string)$ctx['meta_human_id'];

$clean = [];
foreach ($points as $p) {
    if (!is_array($p)) continue;
    $vec = $p['vector'] ?? null;
    if (!is_array($vec) || $vec === []) continue;

    $payload = $p['payload'] ?? [];
    if (!is_array($payload)) $payload = [];

    if (isset($payload['tenant_id']) && is_string($payload['tenant_id']) && $payload['tenant_id'] !== '' && $payload['tenant_id'] !== $tenantId) {
        if (function_exists('cue_autoload')) cue_autoload('error');
        if (function_exists('error_logError')) {
            error_logError('Vector gateway blocked payload tenant mismatch', [
                'requested' => (string)$payload['tenant_id'],
                'tenant' => $tenantId,
                'username' => (string)$ctx['username']
            ]);
        }
        mhw_json(['success' => false, 'error' => 'tenant_mismatch'], 403);
        exit;
    }

    $payload['tenant_id'] = $tenantId;
    if (!isset($payload['persona_id']) || !is_string($payload['persona_id']) || $payload['persona_id'] === '') {
        $payload['persona_id'] = $personaId;
    }
    if (!isset($payload['meta_human_id']) || !is_string($payload['meta_human_id']) || $payload['meta_human_id'] === '') {
        $payload['meta_human_id'] = $metaHumanId;
    }

    $id = $p['id'] ?? null;

    $clean[] = [
        'id' => $id,
        'vector' => $vec,
        'payload' => $payload,
    ];
}

if ($clean === []) {
    mhw_json(['success' => false, 'error' => 'no_valid_points'], 400);
    exit;
}

try {
    if (function_exists('cue_autoload')) cue_autoload('vector');
    $ok = vector_upsert($tenantId, $clean);
    mhw_json(['success' => (bool)$ok, 'tenant_id' => $tenantId, 'count' => count($clean)]);
} catch (Throwable $e) {
    if (function_exists('cue_autoload')) cue_autoload('error');
    if (function_exists('error_logError')) {
        error_logError('Vector upsert failed', [
            'error' => $e->getMessage(),
            'tenant' => (string)$ctx['tenant_id'],
            'username' => (string)$ctx['username']
        ]);
    }
    mhw_json(['success' => false, 'error' => 'vector_upsert_failed'], 500);
}
