<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_lib.php';

$ctx = mh_widget_require_auth();
$body = mh_widget_read_json_body();

$text = isset($body['text']) ? trim((string)$body['text']) : '';
if ($text === '') {
    mh_widget_json(['success' => false, 'error' => 'text_required'], 400);
    exit;
}
if (strlen($text) > 8000) {
    mh_widget_json(['success' => false, 'error' => 'text_too_large'], 413);
    exit;
}

$kind = isset($body['kind']) ? trim((string)$body['kind']) : 'note';
$kind = $kind !== '' ? $kind : 'note';
if (strlen($kind) > 64) $kind = substr($kind, 0, 64);
$source = isset($body['source']) ? trim((string)$body['source']) : 'persona';
$source = $source !== '' ? $source : 'persona';
if (strlen($source) > 64) $source = substr($source, 0, 64);

$tags = $body['tags'] ?? [];
if (!is_array($tags)) $tags = [];
$tags = array_values(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : '', $tags), fn($t) => $t !== ''));
if (count($tags) > 20) $tags = array_slice($tags, 0, 20);
$tags = array_values(array_filter(array_map(fn($t) => strlen($t) > 64 ? substr($t, 0, 64) : $t, $tags), fn($t) => $t !== ''));

$tenantId = (string)($ctx['tenant_id'] ?? '');
$personaId = isset($body['persona_id']) ? trim((string)$body['persona_id']) : (string)($ctx['persona_id'] ?? '');
$personaId = $personaId !== '' ? $personaId : (string)($ctx['persona_id'] ?? '');
$personaSafe = strtolower(mh_widget_sanitize_id($personaId));
$tenantSafe = strtolower(mh_widget_sanitize_id($tenantId));
$metaHumanId = (string)($ctx['meta_human_id'] ?? ('meta:' . $personaSafe));

$eventId = bin2hex(random_bytes(16));
$payload = [
    'event_id' => $eventId,
    'tenant_id' => $tenantId,
    'persona_id' => $personaId,
    'meta_human_id' => $metaHumanId,
    'kind' => $kind,
    'source' => $source,
    'text' => $text,
    'tags' => $tags,
    'created_at' => gmdate('c'),
    'user_id' => (string)($ctx['username'] ?? ''),
    'username' => (string)($ctx['username'] ?? ''),
    'session_id' => (string)($ctx['session_id'] ?? ''),
];

$fileOk = false;
try {
    if ($tenantSafe !== '' && $personaSafe !== '' && $tenantSafe !== 'unknown' && $personaSafe !== 'unknown') {
        $dir = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/memory';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $path = $dir . '/events.jsonl';
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (is_string($line) && $line !== '') {
            $fileOk = @file_put_contents($path, $line . "\n", FILE_APPEND) !== false;
        }
    }
} catch (Throwable) {
    $fileOk = false;
}

$vectorOk = false;
$sqlOk = false;
$graphOk = false;
try {
    if (function_exists('cue_autoload')) {
        cue_autoload('embeddings');
        cue_autoload('vector');
        cue_autoload('memory_sql');
        cue_autoload('graph');
    }

    $vec = function_exists('embeddings_embed_text') ? embeddings_embed_text($text) : [];
    if (is_array($vec) && $vec !== [] && function_exists('vector_upsert')) {
        $point = [
            'id' => $eventId,
            'vector' => $vec,
            'payload' => [
                'tenant_id' => $tenantId,
                'persona_id' => $personaId,
                'meta_human_id' => $metaHumanId,
                'text' => $text,
                'kind' => $kind,
                'source' => $source,
                'tags' => $tags,
                'created_at' => $payload['created_at'],
            ],
        ];
        $vectorOk = (bool)vector_upsert($tenantId, [$point]);
    }

    if (function_exists('memory_sql_get_pdo') && function_exists('memory_sql_ensure_schema') && function_exists('memory_sql_insert_event')) {
        $pdo = memory_sql_get_pdo($tenantId);
        memory_sql_ensure_schema($pdo);
        $sqlOk = (bool)memory_sql_insert_event($pdo, $ctx, [
            'event_id' => $eventId,
            'kind' => $kind,
            'source' => $source,
            'text' => $text,
            'tags' => $tags,
            'qdrant_point_id' => $eventId,
        ]);
    }

    if (function_exists('graph_ensure_schema') && function_exists('graph_cypher')) {
        graph_ensure_schema();
        $eid = 'persona:' . $personaSafe;
        graph_cypher(
            "MERGE (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id, entity_id: $entity_id})
             SET e.kind = 'persona', e.persona_id = $persona_id
             MERGE (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id, memory_id: $memory_id})
             SET m.kind = $kind, m.source = $source, m.text = $text, m.created_at_utc = $created_at_utc
             MERGE (e)-[:HAS_MEMORY]->(m)",
            [
                'tenant_id' => $tenantId,
                'meta_human_id' => $metaHumanId,
                'entity_id' => $eid,
                'persona_id' => $personaId,
                'memory_id' => $eventId,
                'kind' => $kind,
                'source' => $source,
                'text' => $text,
                'created_at_utc' => $payload['created_at'],
            ]
        );
        $graphOk = true;
    }
} catch (Throwable) {
}

mh_widget_json([
    'success' => $fileOk || $vectorOk || $sqlOk || $graphOk,
    'event_id' => $eventId,
    'file' => $fileOk,
    'vector' => $vectorOk,
    'sql' => $sqlOk,
    'graph' => $graphOk,
]);

