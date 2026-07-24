<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__, 2) . '/.cue/security.php';
require_once dirname(__DIR__, 2) . '/.cue/embeddings.php';
require_once dirname(__DIR__, 2) . '/.cue/vector.php';
require_once dirname(__DIR__, 2) . '/.cue/memory.php';
require_once dirname(__DIR__, 2) . '/.cue/memory_sql.php';
require_once dirname(__DIR__, 2) . '/.cue/graph.php';
require_once dirname(__DIR__, 2) . '/.cue/graphrag.php';
define('MH_ORCH_LIB', true);
require_once dirname(__DIR__, 1) . '/respond/index.php';

function mh_meetbot_raw_body(): string
{
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
}

function mh_meetbot_json_in(string $raw): array
{
    if (trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_meetbot_out(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_meetbot_ingest_sanitize_event_id(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    if ($s === '') return gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
    if (strlen($s) > 160) $s = substr($s, 0, 160);
    return $s;
}

function mh_meetbot_memory_ingest_store_one(array $ctx, array $event): array
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
        $eventId = mh_meetbot_ingest_sanitize_event_id($idKey);
    } elseif (isset($event['id']) && is_string($event['id']) && trim((string)$event['id']) !== '') {
        $eventId = mh_meetbot_ingest_sanitize_event_id((string)$event['id']);
    } elseif (function_exists('vector_uuidFromSeed')) {
        $seed = $tenantId . '|' . $personaId . '|' . $metaHumanId . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX);
        $eventId = (string)call_user_func('vector_uuidFromSeed', $seed);
    } else {
        $eventId = bin2hex(random_bytes(16));
    }

    $vec = function_exists('embeddings_embed_text') ? (array)call_user_func('embeddings_embed_text', $text) : [];
    if (!is_array($vec) || $vec === []) {
        return ['ok' => false, 'error' => 'embedding_failed'];
    }

    $payload = [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'text' => $text,
        'kind' => $kind,
        'source' => $source,
        'tags' => $tags,
        'created_at' => date('c'),
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
        if (function_exists('graph_ensure_schema') && function_exists('graph_cypher')) {
            call_user_func('graph_ensure_schema');
            $eid = 'persona:' . $personaId;
            call_user_func(
                'graph_cypher',
                'MERGE (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id, entity_id: $entity_id})
                 SET e.kind = \'persona\', e.persona_id = $persona_id
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
                    'source' => $source !== '' ? $source : 'workbench',
                    'text' => $text,
                    'created_at_utc' => gmdate('c'),
                ]
            );
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

function mh_meetbot_key(): string
{
    $p = '/data/security/app.key';
    $k = @file_get_contents($p);
    $k = is_string($k) ? trim($k) : '';
    if ($k === '') {
        throw new RuntimeException('meetbot_key_missing');
    }
    return $k;
}

function mh_meetbot_verify_signature(string $raw): void
{
    $sig = isset($_SERVER['HTTP_X_MH_SIGNATURE']) ? trim((string)$_SERVER['HTTP_X_MH_SIGNATURE']) : '';
    $ts = isset($_SERVER['HTTP_X_MH_TS']) ? (int)$_SERVER['HTTP_X_MH_TS'] : 0;
    if ($sig === '' || $ts < 1) {
        throw new RuntimeException('missing_signature');
    }
    if (abs(time() - $ts) > 120) {
        throw new RuntimeException('signature_expired');
    }
    $key = mh_meetbot_key();
    $expected = hash_hmac('sha256', $ts . "\n" . $raw, $key);
    if (!hash_equals($expected, $sig)) {
        throw new RuntimeException('invalid_signature');
    }
}

function mh_meetbot_require_ctx(array $ctx): array
{
    $tenantId = isset($ctx['tenant_id']) && is_string($ctx['tenant_id']) ? trim((string)$ctx['tenant_id']) : '';
    $personaId = isset($ctx['persona_id']) && is_string($ctx['persona_id']) ? trim((string)$ctx['persona_id']) : '';
    $userId = isset($ctx['user_id']) && is_string($ctx['user_id']) ? trim((string)$ctx['user_id']) : '';
    $metaHumanId = isset($ctx['meta_human_id']) && is_string($ctx['meta_human_id']) ? trim((string)$ctx['meta_human_id']) : '';
    if ($tenantId === '' || $personaId === '' || $userId === '' || $metaHumanId === '') {
        throw new RuntimeException('missing_identity_fields');
    }
    return [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'user_id' => $userId,
        'meta_human_id' => $metaHumanId,
        'username' => isset($ctx['username']) && is_string($ctx['username']) ? (string)$ctx['username'] : $userId,
        'device_id' => isset($ctx['device_id']) && is_string($ctx['device_id']) ? (string)$ctx['device_id'] : '',
        'session_id' => isset($ctx['session_id']) && is_string($ctx['session_id']) ? (string)$ctx['session_id'] : '',
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mh_meetbot_out(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = mh_meetbot_raw_body();
try {
    mh_meetbot_verify_signature($raw);
} catch (Throwable $e) {
    mh_meetbot_out(403, ['ok' => false, 'error' => $e->getMessage()]);
}

$req = mh_meetbot_json_in($raw);
$action = isset($req['action']) && is_string($req['action']) ? strtolower(trim((string)$req['action'])) : '';
if ($action === '') {
    mh_meetbot_out(400, ['ok' => false, 'error' => 'missing_action']);
}

try {
    $ctx = mh_meetbot_require_ctx(is_array($req['ctx'] ?? null) ? (array)$req['ctx'] : []);
} catch (Throwable $e) {
    mh_meetbot_out(400, ['ok' => false, 'error' => $e->getMessage()]);
}

if ($action !== 'persona_reply') {
    mh_meetbot_out(404, ['ok' => false, 'error' => 'not_found']);
}

try {
    $roomId = isset($req['room_id']) && is_string($req['room_id']) ? trim((string)$req['room_id']) : '';
    $text = isset($req['text']) && is_string($req['text']) ? trim((string)$req['text']) : '';
    if ($roomId === '' || $text === '') {
        mh_meetbot_out(400, ['ok' => false, 'error' => 'missing_fields', 'required' => ['room_id', 'text']]);
    }

    $conversationId = isset($req['conversation_id']) && is_string($req['conversation_id']) ? trim((string)$req['conversation_id']) : '';
    if ($conversationId === '') {
        $conversationId = 'meeting:' . $roomId;
    }

    $tags = ['meeting', 'live', 'room:' . $roomId];

    $userIngest = mh_meetbot_memory_ingest_store_one($ctx, [
        'kind' => 'meeting_live_transcript',
        'source' => 'meet_bot',
        'text' => $text,
        'tags' => $tags,
        'idempotency_key' => 'meet_live_u_' . substr(hash('sha256', $ctx['tenant_id'] . '|' . $ctx['persona_id'] . '|' . $roomId . '|' . $text), 0, 24),
    ]);

    $memoryCtx = [
        'tenant_id' => (string)$ctx['tenant_id'],
        'persona_id' => (string)$ctx['persona_id'],
        'meta_human_id' => (string)$ctx['meta_human_id'],
        'user_id' => (string)$ctx['user_id'],
        'username' => (string)$ctx['username'],
        'device_id' => (string)$ctx['device_id'],
        'session_id' => (string)$ctx['session_id'],
    ];

    $semanticHits = [];
    $semanticMsg = null;
    if (function_exists('memory_retrieve') && function_exists('memory_build_system_message')) {
        $semanticHits = memory_retrieve($memoryCtx, $text, 6);
        $semanticMsg = memory_build_system_message($semanticHits);
    }

    $graphSummary = [];
    $graphMsg = null;
    if (function_exists('graphrag_retrieve_summary') && function_exists('graphrag_build_system_message')) {
        $graphSummary = graphrag_retrieve_summary($memoryCtx, $text, 6, 6);
        $graphMsg = graphrag_build_system_message($graphSummary);
    }

    $memoryBundle = [
        'built_at_utc' => gmdate('c'),
        'semantic' => [
            'hits' => $semanticHits,
            'system_message' => $semanticMsg,
        ],
        'graph' => [
            'summary' => $graphSummary,
            'system_message' => $graphMsg,
        ],
        'meeting' => [
            'room_id' => $roomId,
            'conversation_id' => $conversationId,
        ],
    ];

    $prompt = "You are a live meeting assistant operating as persona_id={$ctx['persona_id']}.\n"
        . "You are inside a realtime meeting room_id={$roomId}.\n"
        . "When you respond, keep it short, speakable, and include next-action items if needed.\n\n"
        . $text;

    [$status, $resp] = mh_orchestrate([
        'tenant_id' => $ctx['tenant_id'],
        'persona_id' => $ctx['persona_id'],
        'user_id' => $ctx['user_id'],
        'meta_human_id' => $ctx['meta_human_id'],
        'session_id' => $ctx['session_id'],
        'device_id' => $ctx['device_id'],
        'input' => [
            'text' => $prompt,
            'images' => [],
            'camera_frames' => [],
            'uploads' => [],
        ],
        'route_hint' => 'auto',
        'task_type' => 'meeting_live',
        'vision_mode' => 'off',
        'tools' => [],
        'memory' => $memoryBundle,
    ]);

    $assistantText = '';
    if (is_array($resp)) {
        $result = $resp['result'] ?? null;
        if (is_array($result)) {
            $assistantText = is_string($result['choices'][0]['message']['content'] ?? null) ? (string)$result['choices'][0]['message']['content'] : '';
            if ($assistantText === '' && is_string($result['reply'] ?? null)) {
                $assistantText = (string)$result['reply'];
                $maybeJson = json_decode($assistantText, true);
                if (is_array($maybeJson)) {
                    if (is_string($maybeJson['response_text'] ?? null)) {
                        $assistantText = (string)$maybeJson['response_text'];
                    } elseif (is_string($maybeJson['text_output'] ?? null)) {
                        $assistantText = (string)$maybeJson['text_output'];
                    } elseif (is_string($maybeJson['reply'] ?? null)) {
                        $assistantText = (string)$maybeJson['reply'];
                    }
                }
            }
        } elseif (is_string($resp['reply'] ?? null)) {
            $assistantText = (string)$resp['reply'];
        }
    }
    $assistantText = trim($assistantText);
    if ($assistantText !== '' && strlen($assistantText) < 20000 && $assistantText[0] === '{') {
        $maybeJson = json_decode($assistantText, true);
        if (is_array($maybeJson)) {
            if (is_string($maybeJson['response_text'] ?? null)) {
                $assistantText = trim((string)$maybeJson['response_text']);
            } elseif (is_string($maybeJson['text_output'] ?? null)) {
                $assistantText = trim((string)$maybeJson['text_output']);
            } elseif (is_string($maybeJson['text'] ?? null)) {
                $assistantText = trim((string)$maybeJson['text']);
            } elseif (is_string($maybeJson['reply'] ?? null)) {
                $assistantText = trim((string)$maybeJson['reply']);
            } elseif (is_string($maybeJson['content'] ?? null)) {
                $assistantText = trim((string)$maybeJson['content']);
            }
        }
    }
    if ($assistantText === '') {
        mh_meetbot_out(502, ['ok' => false, 'error' => 'empty_reply', 'upstream_status' => $status]);
    }

    $assistantIngest = mh_meetbot_memory_ingest_store_one($ctx, [
        'kind' => 'meeting_live_reply',
        'source' => 'meet_bot',
        'text' => $assistantText,
        'tags' => $tags,
        'idempotency_key' => 'meet_live_a_' . substr(hash('sha256', $ctx['tenant_id'] . '|' . $ctx['persona_id'] . '|' . $roomId . '|' . $assistantText), 0, 24),
    ]);

    mh_meetbot_out(200, [
        'ok' => true,
        'reply_text' => $assistantText,
        'memory' => [
            'user' => $userIngest,
            'assistant' => $assistantIngest,
        ],
    ]);
} catch (Throwable $e) {
    error_log('v1_meet_bot_bridge_error: ' . $e->getMessage());
    mh_meetbot_out(500, ['ok' => false, 'error' => 'internal_error']);
}
