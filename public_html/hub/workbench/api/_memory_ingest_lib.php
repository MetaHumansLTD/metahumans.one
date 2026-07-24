<?php
declare(strict_types=1);

require_once __DIR__ . '/_context.php';

function mhw_memory_ingest_sanitize_event_id(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    if ($s === '') return gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
    if (strlen($s) > 160) $s = substr($s, 0, 160);
    return $s;
}

function mhw_memory_ingest_store_one(array $ctx, array $event): array
{
    $tenantId = (string)($ctx['tenant_id'] ?? '');
    $personaId = (string)($ctx['persona_id'] ?? '');
    $metaHumanId = (string)($ctx['meta_human_id'] ?? '');

    $kind = isset($event['kind']) ? trim((string)$event['kind']) : 'note';
    if ($kind === '') $kind = 'note';

    $source = isset($event['source']) ? trim((string)$event['source']) : '';
    $text = isset($event['text']) ? trim((string)$event['text']) : '';
    if ($text === '') {
        return ['ok' => false, 'error' => 'text_required'];
    }

    $tags = $event['tags'] ?? [];
    if (!is_array($tags)) $tags = [];
    $tags = array_values(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : '', $tags), fn($t) => $t !== ''));

    $eventId = null;
    $idKey = isset($event['idempotency_key']) && is_string($event['idempotency_key']) ? trim((string)$event['idempotency_key']) : '';
    if ($idKey !== '') {
        $eventId = mhw_memory_ingest_sanitize_event_id($idKey);
    } elseif (isset($event['id']) && is_string($event['id']) && trim((string)$event['id']) !== '') {
        $eventId = mhw_memory_ingest_sanitize_event_id((string)$event['id']);
    } elseif (function_exists('vector_uuidFromSeed')) {
        $seed = $tenantId . '|' . $personaId . '|' . $metaHumanId . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX);
        $eventId = (string)call_user_func('vector_uuidFromSeed', $seed);
    } else {
        $eventId = bin2hex(random_bytes(16));
    }

    if (function_exists('cue_autoload')) {
        call_user_func('cue_autoload', 'embeddings');
        call_user_func('cue_autoload', 'vector');
        call_user_func('cue_autoload', 'memory_sql');
        call_user_func('cue_autoload', 'graph');
        call_user_func('cue_autoload', 'graphrag');
    }

    $vec = function_exists('embeddings_embed_text') ? call_user_func('embeddings_embed_text', $text) : [];
    if (!is_array($vec) || $vec === []) {
        return ['ok' => false, 'error' => 'embedding_failed'];
    }

    $createdAtUtc = gmdate('c');
    $payload = [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'text' => $text,
        'kind' => $kind,
        'source' => $source,
        'tags' => $tags,
        'created_at' => $createdAtUtc,
    ];

    $point = [
        'id' => $eventId,
        'vector' => $vec,
        'payload' => $payload,
    ];

    $vectorOk = function_exists('vector_upsert') ? (bool)call_user_func('vector_upsert', $tenantId, [$point]) : false;

    $sqlInserted = false;
    try {
        if (function_exists('memory_sql_get_pdo')) {
            $pdo = memory_sql_get_pdo($tenantId);
            memory_sql_ensure_schema($pdo);
            $sqlInserted = (bool)memory_sql_insert_event($pdo, $ctx, [
                'event_id' => $eventId,
                'kind' => $kind,
                'source' => $source !== '' ? $source : 'workbench',
                'text' => $text,
                'tags' => $tags,
                'qdrant_point_id' => $eventId,
            ]);
        }
    } catch (Throwable) {
        $sqlInserted = false;
    }

    $graphOk = false;
    try {
        if (function_exists('graphrag_ingest_text')) {
            graphrag_ingest_text($ctx, $eventId, $kind, $text, $createdAtUtc, [
                'source' => $source !== '' ? $source : 'workbench',
                'tags' => $tags,
                'filename' => isset($event['filename']) && is_string($event['filename']) ? trim((string)$event['filename']) : '',
                'path' => isset($event['path']) && is_string($event['path']) ? trim((string)$event['path']) : '',
            ]);
            $graphOk = true;
        }
    } catch (Throwable) {
        $graphOk = false;
    }

    return [
        'ok' => $vectorOk,
        'event_id' => $eventId,
        'sql_inserted' => $sqlInserted,
        'graph' => $graphOk,
    ];
}
