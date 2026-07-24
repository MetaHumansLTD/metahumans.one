<?php
require_once __DIR__ . '/_memory_ingest_lib.php';

$ctx = mhw_require_context();

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) $input = [];

$tenantId = (string)($ctx['tenant_id'] ?? '');
$personaId = (string)($ctx['persona_id'] ?? '');
$metaHumanId = (string)($ctx['meta_human_id'] ?? '');

try {
    if (isset($input['events']) && is_array($input['events'])) {
        $stored = 0;
        $failed = 0;
        $ids = [];
        $sqlInserted = 0;
        $graphOk = 0;
        foreach ($input['events'] as $e) {
            if (!is_array($e)) continue;
            $r = mhw_memory_ingest_store_one($ctx, $e);
            if (!($r['ok'] ?? false)) {
                $failed++;
                continue;
            }
            $stored++;
            $ids[] = (string)($r['event_id'] ?? '');
            if (($r['sql_inserted'] ?? false) === true) $sqlInserted++;
            if (($r['graph'] ?? false) === true) $graphOk++;
        }
        mhw_json([
            'success' => $failed === 0,
            'tenant_id' => $tenantId,
            'persona_id' => $personaId,
            'meta_human_id' => $metaHumanId,
            'count' => $stored,
            'failed' => $failed,
            'event_ids' => $ids,
            'sql_inserted' => $sqlInserted,
            'graph_ok' => $graphOk,
        ], $failed === 0 ? 200 : 207);
        exit;
    }

    $r = mhw_memory_ingest_store_one($ctx, $input);
    if (!($r['ok'] ?? false)) {
        mhw_json(['success' => false, 'error' => (string)($r['error'] ?? 'ingest_failed')], 400);
        exit;
    }

    mhw_json([
        'success' => true,
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'count' => 1,
        'event_id' => (string)($r['event_id'] ?? ''),
        'sql' => (bool)($r['sql_inserted'] ?? false),
        'graph' => (bool)($r['graph'] ?? false),
    ]);
} catch (Throwable $e) {
    if (function_exists('cue_autoload')) call_user_func('cue_autoload', 'error');
    if (function_exists('error_logError')) {
        call_user_func('error_logError', 'Memory ingest failed', [
            'error' => $e->getMessage(),
            'tenant' => $tenantId,
            'username' => (string)($ctx['username'] ?? ''),
        ]);
    }
    mhw_json(['success' => false, 'error' => 'memory_ingest_failed'], 500);
}
