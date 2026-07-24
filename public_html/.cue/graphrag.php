<?php
declare(strict_types=1);

function graphrag_entity_id(string $name): string {
    return substr(hash('sha256', strtolower(trim($name))), 0, 24);
}

function graphrag_slug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9:_\-\.]+/', '_', $value);
    $value = trim((string)$value, '._-');
    return $value !== '' ? $value : 'unknown';
}

function graphrag_domain_id(string $prefix, string $value): string {
    $slug = graphrag_slug($value);
    return $prefix . ':' . $slug . ':' . substr(hash('sha256', strtolower(trim($value))), 0, 10);
}

function graphrag_parse_tags(array $tags): array {
    $out = [
        'project' => [],
        'task' => [],
        'meeting' => [],
        'document' => [],
        'user' => [],
    ];
    foreach ($tags as $tag) {
        if (!is_string($tag)) continue;
        $tag = trim($tag);
        if ($tag === '') continue;
        $parts = explode(':', $tag, 2);
        if (count($parts) !== 2) continue;
        $prefix = strtolower(trim((string)$parts[0]));
        $value = trim((string)$parts[1]);
        if ($value === '' || !isset($out[$prefix])) continue;
        $out[$prefix][] = $value;
    }
    foreach ($out as $k => $values) {
        $uniq = [];
        foreach ($values as $value) {
            $uniq[strtolower($value)] = $value;
        }
        $out[$k] = array_values($uniq);
    }
    return $out;
}

function graphrag_extract_entities(string $text, int $limit = 12): array {
    $text = trim($text);
    if ($text === '') return [];
    $limit = max(1, min(50, $limit));

    $entities = [];

    if (preg_match_all('/\B[@#][a-zA-Z0-9_\-]{2,64}/', $text, $m)) {
        foreach ($m[0] as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            $entities[] = $t;
        }
    }

    if (preg_match_all('/\b(?:[A-Z][a-z0-9]+(?:\s+[A-Z][a-z0-9]+){0,3})\b/', $text, $m2)) {
        foreach ($m2[0] as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            if (strlen($t) < 3) continue;
            $entities[] = $t;
        }
    }
    if (preg_match_all('/\b[A-Z][a-z0-9]{2,}\b/', $text, $m3)) {
        foreach ($m3[0] as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;
            $entities[] = $t;
        }
    }

    $uniq = [];
    $out = [];
    foreach ($entities as $e) {
        $k = strtolower($e);
        if (isset($uniq[$k])) continue;
        $uniq[$k] = true;
        $out[] = $e;
        if (count($out) >= $limit) break;
    }
    return $out;
}

function graphrag_extract_domain_refs(string $text, array $tags = [], string $kind = 'event', array $meta = []): array {
    $refs = graphrag_parse_tags($tags);
    $refs['entities'] = graphrag_extract_entities($text, 12);

    $filename = is_string($meta['filename'] ?? null) ? trim((string)$meta['filename']) : '';
    $path = is_string($meta['path'] ?? null) ? trim((string)$meta['path']) : '';
    $documentName = $filename !== '' ? $filename : ($path !== '' ? basename($path) : '');
    if ($documentName !== '') {
        $refs['document'][] = $documentName;
    }

    $kindLower = strtolower(trim($kind));
    $textTrim = trim($text);
    if (($kindLower === 'meeting_summary' || $kindLower === 'meeting' || str_contains($kindLower, 'meeting')) && $textTrim !== '') {
        $refs['meeting'][] = substr($textTrim, 0, 80);
    }
    if (($kindLower === 'task' || $kindLower === 'reminder' || str_contains($kindLower, 'task') || str_contains($kindLower, 'reminder')) && $textTrim !== '') {
        $refs['task'][] = substr($textTrim, 0, 120);
    }
    if (($kindLower === 'document' || str_contains($kindLower, 'upload') || str_contains($kindLower, 'file')) && $documentName !== '') {
        $refs['document'][] = $documentName;
    }

    foreach (['project', 'task', 'meeting', 'document', 'user'] as $k) {
        $uniq = [];
        foreach ($refs[$k] as $value) {
            if (!is_string($value)) continue;
            $value = trim($value);
            if ($value === '') continue;
            $uniq[strtolower($value)] = $value;
        }
        $refs[$k] = array_values($uniq);
    }

    return $refs;
}

function graphrag_upsert_identity(array $ctx): void {
    if (!function_exists('graph_ensure_schema')) return;
    graph_ensure_schema();
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? trim((string)$ctx['tenant_id']) : '';
    $userId = is_string($ctx['user_id'] ?? null) ? trim((string)$ctx['user_id']) : '';
    $personaId = is_string($ctx['persona_id'] ?? null) ? trim((string)$ctx['persona_id']) : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? trim((string)$ctx['meta_human_id']) : '';
    if ($tenantId === '' || $metaHumanId === '') return;
    if ($userId === '') $userId = is_string($ctx['username'] ?? null) ? trim((string)$ctx['username']) : '';
    $cypher = <<<'CYPHER'
MERGE (mh:MetaHuman {tenant_id: $tenant_id, meta_human_id: $meta_human_id})
SET mh.persona_id = $persona_id,
    mh.updated_at_utc = $updated_at_utc
FOREACH (_ IN CASE WHEN $persona_id = '' THEN [] ELSE [1] END |
    MERGE (p:Persona {tenant_id: $tenant_id, persona_id: $persona_id})
    SET p.name = $persona_name,
        p.meta_human_id = $meta_human_id,
        p.updated_at_utc = $updated_at_utc
    MERGE (p)-[:EMBODIES]->(mh)
)
FOREACH (_ IN CASE WHEN $user_id = '' THEN [] ELSE [1] END |
    MERGE (u:User {tenant_id: $tenant_id, user_id: $user_id})
    SET u.username = $user_id,
        u.updated_at_utc = $updated_at_utc
    MERGE (u)-[:OWNS_META_HUMAN]->(mh)
)
FOREACH (_ IN CASE WHEN $user_id = '' OR $persona_id = '' THEN [] ELSE [1] END |
    MERGE (u:User {tenant_id: $tenant_id, user_id: $user_id})
    MERGE (p:Persona {tenant_id: $tenant_id, persona_id: $persona_id})
    MERGE (u)-[:OWNS_PERSONA]->(p)
)
RETURN mh.meta_human_id AS meta_human_id
CYPHER;
    graph_cypher($cypher, [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'persona_id' => $personaId,
        'persona_name' => $personaId,
        'meta_human_id' => $metaHumanId,
        'updated_at_utc' => gmdate('c'),
    ]);
}

function graphrag_upsert_memory(array $ctx, string $memoryId, string $kind, string $text, string $createdAtUtc, array $meta = []): void {
    if (!function_exists('graph_ensure_schema')) return;
    graph_ensure_schema();
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $userId = is_string($ctx['user_id'] ?? null) ? trim((string)$ctx['user_id']) : '';
    $personaId = is_string($ctx['persona_id'] ?? null) ? (string)$ctx['persona_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    if ($tenantId === '' || $metaHumanId === '') return;
    $memoryId = trim($memoryId);
    if ($memoryId === '') return;
    $kind = trim($kind) !== '' ? trim($kind) : 'event';
    $text = trim($text);
    if ($text === '') return;
    $createdAtUtc = trim($createdAtUtc) !== '' ? trim($createdAtUtc) : gmdate('c');

    $cypher = <<<'CYPHER'
MERGE (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id, memory_id: $memory_id})
SET m.persona_id = $persona_id,
    m.kind = $kind,
    m.text = $text,
    m.created_at_utc = $created_at_utc,
    m.source = $source,
    m.tags = $tags
WITH m
MERGE (mh:MetaHuman {tenant_id: $tenant_id, meta_human_id: $meta_human_id})
MERGE (mh)-[:HAS_MEMORY]->(m)
FOREACH (_ IN CASE WHEN $persona_id = '' THEN [] ELSE [1] END |
    MERGE (p:Persona {tenant_id: $tenant_id, persona_id: $persona_id})
    MERGE (p)-[:GENERATED_MEMORY]->(m)
)
FOREACH (_ IN CASE WHEN $user_id = '' THEN [] ELSE [1] END |
    MERGE (u:User {tenant_id: $tenant_id, user_id: $user_id})
    MERGE (u)-[:AUTHORED_MEMORY]->(m)
)
RETURN m.memory_id AS memory_id
CYPHER;
    $params = [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'meta_human_id' => $metaHumanId,
        'persona_id' => $personaId,
        'memory_id' => $memoryId,
        'kind' => $kind,
        'text' => $text,
        'created_at_utc' => $createdAtUtc,
        'source' => is_string($meta['source'] ?? null) ? (string)$meta['source'] : '',
        'tags' => is_array($meta['tags'] ?? null) ? array_values((array)$meta['tags']) : [],
    ];
    graph_cypher($cypher, $params);
}

function graphrag_upsert_entities_and_links(array $ctx, string $memoryId, array $entities): void {
    if (!function_exists('graph_ensure_schema')) return;
    graph_ensure_schema();
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    if ($tenantId === '' || $metaHumanId === '' || trim($memoryId) === '') return;

    $entities = array_values(array_filter(array_map(fn($e) => is_string($e) ? trim($e) : '', $entities), fn($e) => $e !== ''));
    if ($entities === []) return;

    $entityRows = [];
    foreach ($entities as $name) {
        $entityRows[] = [
            'entity_id' => graphrag_entity_id($name),
            'name' => $name,
            'name_lc' => strtolower($name),
        ];
    }

    $cypher = <<<'CYPHER'
MATCH (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id, memory_id: $memory_id})
UNWIND $rows AS row
MERGE (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id, entity_id: row.entity_id})
SET e.name = row.name,
    e.name_lc = row.name_lc,
    e.updated_at_utc = $updated_at_utc
MERGE (m)-[:MENTIONS]->(e)
RETURN count(e) AS entities
CYPHER;
    $params = [
        'tenant_id' => $tenantId,
        'meta_human_id' => $metaHumanId,
        'memory_id' => $memoryId,
        'rows' => $entityRows,
        'updated_at_utc' => gmdate('c'),
    ];
    graph_cypher($cypher, $params);
}

function graphrag_upsert_named_nodes(array $ctx, string $memoryId, string $label, string $idKey, string $nameKey, string $relationship, array $names, ?string $projectId = null, array $extra = []): void {
    if (!function_exists('graph_ensure_schema')) return;
    graph_ensure_schema();
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? trim((string)$ctx['tenant_id']) : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? trim((string)$ctx['meta_human_id']) : '';
    if ($tenantId === '' || $metaHumanId === '' || trim($memoryId) === '' || $names === []) return;

    $rows = [];
    foreach ($names as $name) {
        if (!is_string($name)) continue;
        $name = trim($name);
        if ($name === '') continue;
        $nodeId = graphrag_domain_id($idKey, $name);
        $row = [
            'node_id' => $nodeId,
            'name' => $name,
            'name_lc' => strtolower($name),
            'project_id' => $projectId ?? '',
        ];
        foreach ($extra as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $row[$k] = $v;
            }
        }
        $rows[] = $row;
    }
    if ($rows === []) return;

    $cypher = "MATCH (m:Memory {tenant_id: \$tenant_id, meta_human_id: \$meta_human_id, memory_id: \$memory_id})\n"
        . "UNWIND \$rows AS row\n"
        . "MERGE (n:" . $label . " {tenant_id: \$tenant_id, meta_human_id: \$meta_human_id, " . $idKey . ": row.node_id})\n"
        . "SET n." . $nameKey . " = row.name,\n"
        . "    n.name_lc = row.name_lc,\n"
        . "    n.updated_at_utc = \$updated_at_utc,\n"
        . "    n.filename = COALESCE(row.filename, n.filename),\n"
        . "    n.path = COALESCE(row.path, n.path)\n"
        . "MERGE (m)-[:" . $relationship . "]->(n)\n"
        . "FOREACH (_ IN CASE WHEN row.project_id = '' THEN [] ELSE [1] END |\n"
        . "    MERGE (p:Project {tenant_id: \$tenant_id, meta_human_id: \$meta_human_id, project_id: row.project_id})\n"
        . "    MERGE (n)-[:BELONGS_TO_PROJECT]->(p)\n"
        . ")\n"
        . "RETURN count(n) AS nodes";

    graph_cypher($cypher, [
        'tenant_id' => $tenantId,
        'meta_human_id' => $metaHumanId,
        'memory_id' => $memoryId,
        'rows' => $rows,
        'updated_at_utc' => gmdate('c'),
    ]);
}

function graphrag_upsert_domain_nodes_and_links(array $ctx, string $memoryId, array $refs, array $meta = []): void {
    $projectId = null;
    if (!empty($refs['project'])) {
        $projectName = $refs['project'][0];
        $projectId = graphrag_domain_id('project_id', $projectName);
        graphrag_upsert_named_nodes($ctx, $memoryId, 'Project', 'project_id', 'name', 'ABOUT_PROJECT', $refs['project']);
    }
    if (!empty($refs['task'])) {
        graphrag_upsert_named_nodes($ctx, $memoryId, 'Task', 'task_id', 'title', 'ABOUT_TASK', $refs['task'], $projectId);
    }
    if (!empty($refs['meeting'])) {
        graphrag_upsert_named_nodes($ctx, $memoryId, 'Meeting', 'meeting_id', 'title', 'ABOUT_MEETING', $refs['meeting'], $projectId);
    }
    if (!empty($refs['document'])) {
        graphrag_upsert_named_nodes($ctx, $memoryId, 'Document', 'document_id', 'title', 'ABOUT_DOCUMENT', $refs['document'], $projectId, [
            'filename' => is_string($meta['filename'] ?? null) ? (string)$meta['filename'] : null,
            'path' => is_string($meta['path'] ?? null) ? (string)$meta['path'] : null,
        ]);
    }
    if (!empty($refs['entities'])) {
        graphrag_upsert_entities_and_links($ctx, $memoryId, $refs['entities']);
    }
}

function graphrag_ingest_text(array $ctx, string $memoryId, string $kind, string $text, string $createdAtUtc, array $meta = []): void {
    graphrag_upsert_identity($ctx);
    graphrag_upsert_memory($ctx, $memoryId, $kind, $text, $createdAtUtc, $meta);
    $refs = graphrag_extract_domain_refs($text, is_array($meta['tags'] ?? null) ? (array)$meta['tags'] : [], $kind, $meta);
    graphrag_upsert_domain_nodes_and_links($ctx, $memoryId, $refs, $meta);
}

function graphrag_section_query(array $ctx, string $label, string $idField, string $nameField, string $relationship, array $names, int $limit = 4, int $memLimit = 3, bool $recentFallback = false): array {
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? trim((string)$ctx['tenant_id']) : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? trim((string)$ctx['meta_human_id']) : '';
    if ($tenantId === '' || $metaHumanId === '') return [];
    $namesLc = array_values(array_unique(array_filter(array_map(fn($v) => is_string($v) ? strtolower(trim($v)) : '', $names), fn($v) => $v !== '')));

    $where = $recentFallback && $namesLc === [] ? 'TRUE' : ('n.name_lc IN $names');
    $cypher = "MATCH (n:" . $label . " {tenant_id: \$tenant_id, meta_human_id: \$meta_human_id})\n"
        . "WHERE " . $where . "\n"
        . "OPTIONAL MATCH (m:Memory {tenant_id: \$tenant_id, meta_human_id: \$meta_human_id})-[:" . $relationship . "]->(n)\n"
        . "WITH n, m ORDER BY m.created_at_utc DESC, n.updated_at_utc DESC\n"
        . "WITH n, collect(m)[0..\$mem_limit] AS mems\n"
        . "RETURN n." . $idField . " AS id, n." . $nameField . " AS name, [x IN mems WHERE x IS NOT NULL | {kind: x.kind, text: x.text, created_at_utc: x.created_at_utc}] AS memories\n"
        . "LIMIT \$limit";
    $body = graph_cypher($cypher, [
        'tenant_id' => $tenantId,
        'meta_human_id' => $metaHumanId,
        'names' => $namesLc,
        'mem_limit' => $memLimit,
        'limit' => $limit,
    ]);
    $results = $body['results'][0]['data'] ?? [];
    if (!is_array($results)) return [];
    $out = [];
    foreach ($results as $row) {
        $r = $row['row'] ?? null;
        if (!is_array($r) || count($r) !== 3) continue;
        $out[] = ['id' => $r[0], 'name' => $r[1], 'memories' => $r[2]];
    }
    return $out;
}

function graphrag_retrieve_entities(array $ctx, array $entityNames, int $maxEntities = 6, int $maxMemories = 6): array {
    if ($entityNames === []) return [];
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    if ($tenantId === '' || $metaHumanId === '') return [];
    $entityIds = array_map(fn($n) => graphrag_entity_id($n), $entityNames);

    $cypher = <<<'CYPHER'
MATCH (e:Entity {tenant_id: $tenant_id, meta_human_id: $meta_human_id})
WHERE e.entity_id IN $entity_ids
MATCH (m:Memory {tenant_id: $tenant_id, meta_human_id: $meta_human_id})-[:MENTIONS]->(e)
WITH e, m
ORDER BY m.created_at_utc DESC
WITH e, collect(m)[0..$mem_limit] AS mems
RETURN e.name AS entity, [x IN mems | {kind: x.kind, text: x.text, created_at_utc: x.created_at_utc}] AS memories
LIMIT $entity_limit
CYPHER;
    $body = graph_cypher($cypher, [
        'tenant_id' => $tenantId,
        'meta_human_id' => $metaHumanId,
        'entity_ids' => $entityIds,
        'mem_limit' => $maxMemories,
        'entity_limit' => $maxEntities,
    ]);
    $results = $body['results'][0]['data'] ?? [];
    if (!is_array($results)) return [];
    $out = [];
    foreach ($results as $row) {
        $r = $row['row'] ?? null;
        if (!is_array($r) || count($r) !== 2) continue;
        $out[] = ['entity' => $r[0], 'memories' => $r[1]];
    }
    return $out;
}

function graphrag_retrieve_identity_summary(array $ctx): array {
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? trim((string)$ctx['tenant_id']) : '';
    $personaId = is_string($ctx['persona_id'] ?? null) ? trim((string)$ctx['persona_id']) : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? trim((string)$ctx['meta_human_id']) : '';
    $userId = is_string($ctx['user_id'] ?? null) ? trim((string)$ctx['user_id']) : '';
    if ($tenantId === '' || $metaHumanId === '') return [];
    $cypher = <<<'CYPHER'
MATCH (mh:MetaHuman {tenant_id: $tenant_id, meta_human_id: $meta_human_id})
OPTIONAL MATCH (p:Persona {tenant_id: $tenant_id, persona_id: $persona_id})-[:EMBODIES]->(mh)
OPTIONAL MATCH (u:User {tenant_id: $tenant_id, user_id: $user_id})-[:OWNS_META_HUMAN]->(mh)
RETURN mh.meta_human_id AS meta_human_id,
       COALESCE(p.name, $persona_id) AS persona_name,
       COALESCE(u.username, $user_id) AS user_name
LIMIT 1
CYPHER;
    $body = graph_cypher($cypher, [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'user_id' => $userId,
    ]);
    $results = $body['results'][0]['data'] ?? [];
    if (!is_array($results) || $results === []) return [];
    $row = $results[0]['row'] ?? null;
    if (!is_array($row) || count($row) !== 3) return [];
    return [
        'meta_human_id' => $row[0],
        'persona_name' => $row[1],
        'user_name' => $row[2],
    ];
}

function graphrag_retrieve_summary(array $ctx, string $query, int $maxEntities = 6, int $maxMemories = 6): array {
    if (!function_exists('graph_ensure_schema')) return [];
    graph_ensure_schema();
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    if ($tenantId === '' || $metaHumanId === '') return [];
    $query = trim($query);
    if ($query === '') return [];

    $refs = graphrag_extract_domain_refs($query, [], 'query', []);
    $queryLc = strtolower($query);

    return [
        'identity' => graphrag_retrieve_identity_summary($ctx),
        'projects' => graphrag_section_query($ctx, 'Project', 'project_id', 'name', 'ABOUT_PROJECT', $refs['project'], 4, 3, str_contains($queryLc, 'project')),
        'tasks' => graphrag_section_query($ctx, 'Task', 'task_id', 'title', 'ABOUT_TASK', $refs['task'], 4, 3, str_contains($queryLc, 'task') || str_contains($queryLc, 'todo') || str_contains($queryLc, 'reminder')),
        'meetings' => graphrag_section_query($ctx, 'Meeting', 'meeting_id', 'title', 'ABOUT_MEETING', $refs['meeting'], 4, 3, str_contains($queryLc, 'meeting')),
        'documents' => graphrag_section_query($ctx, 'Document', 'document_id', 'title', 'ABOUT_DOCUMENT', $refs['document'], 4, 3, str_contains($queryLc, 'document') || str_contains($queryLc, 'file') || str_contains($queryLc, 'upload')),
        'entities' => graphrag_retrieve_entities($ctx, $refs['entities'], $maxEntities, $maxMemories),
    ];
}

function graphrag_build_system_message(array $summary): ?array {
    if ($summary === []) return null;
    $lines = [];
    if (is_array($summary['identity'] ?? null) && !empty($summary['identity'])) {
        $identity = $summary['identity'];
        $lines[] = 'Identity:';
        $lines[] = '  - MetaHuman: ' . (string)($identity['meta_human_id'] ?? '');
        $lines[] = '  - Persona: ' . (string)($identity['persona_name'] ?? '');
        $lines[] = '  - User: ' . (string)($identity['user_name'] ?? '');
    }

    $sections = [
        'projects' => 'Projects',
        'tasks' => 'Tasks',
        'meetings' => 'Meetings',
        'documents' => 'Documents',
    ];
    foreach ($sections as $key => $label) {
        $items = $summary[$key] ?? [];
        if (!is_array($items) || $items === []) continue;
        $lines[] = $label . ':';
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $name = is_string($item['name'] ?? null) ? trim((string)$item['name']) : '';
            if ($name === '') continue;
            $lines[] = '  - ' . $name;
            $memories = $item['memories'] ?? [];
            if (is_array($memories)) {
                $n = 0;
                foreach ($memories as $memory) {
                    if (!is_array($memory)) continue;
                    $text = is_string($memory['text'] ?? null) ? trim((string)$memory['text']) : '';
                    if ($text === '') continue;
                    $lines[] = '      * ' . $text;
                    $n++;
                    if ($n >= 2) break;
                }
            }
        }
    }

    $entities = $summary['entities'] ?? [];
    if (is_array($entities) && $entities !== []) {
        $lines[] = 'Entities:';
        foreach ($entities as $entity) {
            if (!is_array($entity)) continue;
            $name = isset($entity['entity']) && is_string($entity['entity']) ? trim((string)$entity['entity']) : '';
            if ($name === '') continue;
            $lines[] = '  - ' . $name;
            $mems = $entity['memories'] ?? [];
            if (is_array($mems)) {
                $n = 0;
                foreach ($mems as $m) {
                    if (!is_array($m)) continue;
                    $text = isset($m['text']) && is_string($m['text']) ? trim((string)$m['text']) : '';
                    if ($text === '') continue;
                    $lines[] = '      * ' . $text;
                    $n++;
                    if ($n >= 2) break;
                }
            }
        }
    }

    if ($lines === []) return null;
    $content = "Tenant-scoped graph memory (identity + domain graph + entities):\n" . implode("\n", array_slice($lines, 0, 60));
    return ['role' => 'system', 'content' => $content];
}
