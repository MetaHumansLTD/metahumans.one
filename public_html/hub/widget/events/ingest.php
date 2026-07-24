<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

function mh_events_tail_jsonl(string $path, int $limit): array
{
    if (!is_file($path) || $limit < 1) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return [];
    $lines = array_values(array_filter(array_map(fn($l) => is_string($l) ? trim($l) : '', $lines), fn($l) => $l !== ''));
    if (!$lines) return [];
    $slice = array_slice($lines, -max(1, min(400, $limit)));
    $out = [];
    foreach ($slice as $l) {
        $j = json_decode($l, true);
        if (is_array($j)) $out[] = $j;
    }
    return $out;
}

function mh_events_ingest_backends(array $ctx, string $tenantId, string $personaId, string $metaHumanId, string $eventId, string $kind, string $source, string $text, array $tags, string $createdAt): array
{
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
                    'created_at' => $createdAt,
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
            $personaSafe = strtolower(mh_widget_sanitize_id($personaId));
            $eid = 'persona:' . $personaSafe;
            graph_cypher(
                'MERGE (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id, entity_id: $entity_id})
                 SET e.kind = "persona", e.persona_id = $persona_id
                 MERGE (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id, memory_id: $memory_id})
                 SET m.kind = $kind, m.source = $source, m.text = $text, m.created_at_utc = $created_at_utc
                 MERGE (e)-[:HAS_MEMORY]->(m)',
                [
                    'tenant_id' => $tenantId,
                    'meta_human_id' => $metaHumanId,
                    'entity_id' => $eid,
                    'persona_id' => $personaId,
                    'memory_id' => $eventId,
                    'kind' => $kind,
                    'source' => $source,
                    'text' => $text,
                    'created_at_utc' => $createdAt,
                ]
            );
            $graphOk = true;
        }
    } catch (Throwable) {
    }

    return [
        'vector' => $vectorOk,
        'sql' => $sqlOk,
        'graph' => $graphOk,
    ];
}

$ctx = mh_widget_require_auth();
$body = mh_widget_read_json_body();

$sessionId = isset($body['session_id']) ? trim((string)$body['session_id']) : '';
if ($sessionId === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_session_id'], 400);
    exit;
}
$sessions = is_array($_SESSION['mh_widget_sessions'] ?? null) ? $_SESSION['mh_widget_sessions'] : [];
$session = isset($sessions[$sessionId]) && is_array($sessions[$sessionId]) ? $sessions[$sessionId] : null;
if (!$session) {
    mh_widget_json(['success' => false, 'error' => 'session_not_found'], 404);
    exit;
}

$tenantId = (string)($ctx['tenant_id'] ?? '');
$personaId = isset($session['persona_id']) && is_string($session['persona_id']) ? trim((string)$session['persona_id']) : '';
if ($personaId === '') $personaId = (string)($ctx['persona_id'] ?? '');
$metaHumanId = (string)($ctx['meta_human_id'] ?? ('meta:' . strtolower(mh_widget_sanitize_id($personaId))));

$tenantSafe = strtolower(mh_widget_sanitize_id($tenantId));
$personaSafe = strtolower(mh_widget_sanitize_id($personaId));

$kind = isset($body['kind']) ? trim((string)$body['kind']) : 'vision';
if ($kind === '') $kind = 'vision';
if (strlen($kind) > 64) $kind = substr($kind, 0, 64);

$source = isset($body['source']) ? trim((string)$body['source']) : 'eyes';
if ($source === '') $source = 'eyes';
if (strlen($source) > 64) $source = substr($source, 0, 64);

$payload = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];
$text = isset($body['text']) ? trim((string)$body['text']) : '';
if ($text === '' && $payload !== []) {
    $enc = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (is_string($enc) && $enc !== '') {
        $text = $enc;
    }
}
if ($text === '') {
    mh_widget_json(['success' => false, 'error' => 'missing_text_or_payload'], 400);
    exit;
}
if (strlen($text) > 8000) $text = substr($text, 0, 8000);

$tags = isset($body['tags']) && is_array($body['tags']) ? $body['tags'] : [];
$tags = array_values(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : '', $tags), fn($t) => $t !== ''));
$tags[] = 'event';
$tags[] = 'kind:' . $kind;
$tags[] = 'source:' . $source;
$tags = array_values(array_unique(array_slice($tags, 0, 20)));

$eventId = bin2hex(random_bytes(16));
$createdAt = gmdate('c');
$event = [
    'event_id' => $eventId,
    'tenant_id' => $tenantId,
    'persona_id' => $personaId,
    'meta_human_id' => $metaHumanId,
    'kind' => $kind,
    'source' => $source,
    'text' => $text,
    'payload' => $payload,
    'tags' => $tags,
    'created_at' => $createdAt,
    'user_id' => (string)($ctx['username'] ?? ''),
    'username' => (string)($ctx['username'] ?? ''),
    'session_id' => $sessionId,
];

$fileOk = false;
$filePath = '';
try {
    if ($tenantSafe !== '' && $personaSafe !== '' && $tenantSafe !== 'unknown' && $personaSafe !== 'unknown') {
        $dir = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/memory';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $filePath = $dir . '/events.jsonl';
        $line = json_encode($event, JSON_UNESCAPED_SLASHES);
        if (is_string($line) && $line !== '') {
            $fileOk = @file_put_contents($filePath, $line . "\n", FILE_APPEND) !== false;
        }
    }
} catch (Throwable) {
    $fileOk = false;
}

$backend = mh_events_ingest_backends($ctx, $tenantId, $personaId, $metaHumanId, $eventId, $kind, $source, $text, $tags, $createdAt);

mh_widget_json([
    'success' => $fileOk || !empty($backend['vector']) || !empty($backend['sql']) || !empty($backend['graph']),
    'event_id' => $eventId,
    'file' => $fileOk,
    'vector' => (bool)($backend['vector'] ?? false),
    'sql' => (bool)($backend['sql'] ?? false),
    'graph' => (bool)($backend['graph'] ?? false),
]);
