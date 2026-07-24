<?php
declare(strict_types=1);

function graph_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $cfg = [
        'neo4j_url' => 'http://127.0.0.1:7474',
        'database' => 'neo4j',
        'timeout_seconds' => 8,
        'auth_file' => '/data/security/neo4j_auth',
    ];
    $file = '/data/config/graph-config.json';
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $cfg = array_merge($cfg, $decoded);
        }
    }
    $cfg['neo4j_url'] = is_string($cfg['neo4j_url'] ?? null) ? rtrim((string)$cfg['neo4j_url'], '/') : 'http://127.0.0.1:7474';
    $cfg['database'] = is_string($cfg['database'] ?? null) ? trim((string)$cfg['database']) : 'neo4j';
    if ($cfg['database'] === '') $cfg['database'] = 'neo4j';
    $cfg['timeout_seconds'] = max(1, (int)($cfg['timeout_seconds'] ?? 8));
    $cfg['auth_file'] = is_string($cfg['auth_file'] ?? null) ? (string)$cfg['auth_file'] : '/data/security/neo4j_auth';
    return $cfg;
}

function graph_basic_auth_header(): ?string {
    $cfg = graph_config();
    $authFile = (string)$cfg['auth_file'];
    if (!is_file($authFile)) return null;
    $auth = trim((string)file_get_contents($authFile));
    if ($auth === '' || strpos($auth, '/') === false) return null;
    $auth = preg_replace('/\\//', ':', $auth, 1);
    $b64 = base64_encode($auth);
    return 'Authorization: Basic ' . $b64;
}

function graph_http_tx(array $statements): array {
    $cfg = graph_config();
    $base = (string)$cfg['neo4j_url'];
    $db = (string)$cfg['database'];
    $url = $base . '/db/' . rawurlencode($db) . '/tx/commit';

    $norm = [];
    foreach ($statements as $s) {
        if (!is_array($s)) continue;
        if (isset($s['parameters']) && is_array($s['parameters']) && $s['parameters'] === []) {
            $s['parameters'] = (object)[];
        }
        $norm[] = $s;
    }

    $headers = ['Content-Type: application/json'];
    $auth = graph_basic_auth_header();
    if ($auth) $headers[] = $auth;

    $payload = ['statements' => $norm];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$cfg['timeout_seconds']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;

    $decoded = null;
    if (is_string($resp) && trim($resp) !== '') {
        $decoded = json_decode($resp, true);
    }
    return [
        'ok' => $httpCode >= 200 && $httpCode < 300 && is_array($decoded),
        'status' => $httpCode,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => is_string($resp) ? $resp : '',
    ];
}

function graph_cypher(string $cypher, array $params = [], bool $includeStats = false): array {
    $stmt = [
        'statement' => $cypher,
        'parameters' => $params,
    ];
    if ($includeStats) {
        $stmt['includeStats'] = true;
    }
    $res = graph_http_tx([$stmt]);
    if (empty($res['ok']) || !is_array($res['body'])) {
        throw new RuntimeException('neo4j_request_failed_' . (int)($res['status'] ?? 0));
    }
    $body = (array)$res['body'];
    if (!empty($body['errors'])) {
        $first = is_array($body['errors']) ? ($body['errors'][0] ?? null) : null;
        $msg = is_array($first) && isset($first['message']) ? (string)$first['message'] : 'neo4j_error';
        throw new RuntimeException($msg);
    }
    return $body;
}

function graph_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    $queries = [
        'CREATE CONSTRAINT entity_key IF NOT EXISTS FOR (e:Entity) REQUIRE (e.tenant_id, e.meta_human_id, e.entity_id) IS UNIQUE',
        'CREATE CONSTRAINT memory_key IF NOT EXISTS FOR (m:Memory) REQUIRE (m.tenant_id, m.meta_human_id, m.memory_id) IS UNIQUE',
        'CREATE CONSTRAINT metahuman_key IF NOT EXISTS FOR (mh:MetaHuman) REQUIRE (mh.tenant_id, mh.meta_human_id) IS UNIQUE',
        'CREATE CONSTRAINT persona_key IF NOT EXISTS FOR (p:Persona) REQUIRE (p.tenant_id, p.persona_id) IS UNIQUE',
        'CREATE CONSTRAINT user_key IF NOT EXISTS FOR (u:User) REQUIRE (u.tenant_id, u.user_id) IS UNIQUE',
        'CREATE CONSTRAINT project_key IF NOT EXISTS FOR (p:Project) REQUIRE (p.tenant_id, p.meta_human_id, p.project_id) IS UNIQUE',
        'CREATE CONSTRAINT task_key IF NOT EXISTS FOR (t:Task) REQUIRE (t.tenant_id, t.meta_human_id, t.task_id) IS UNIQUE',
        'CREATE CONSTRAINT meeting_key IF NOT EXISTS FOR (m:Meeting) REQUIRE (m.tenant_id, m.meta_human_id, m.meeting_id) IS UNIQUE',
        'CREATE CONSTRAINT document_key IF NOT EXISTS FOR (d:Document) REQUIRE (d.tenant_id, d.meta_human_id, d.document_id) IS UNIQUE',
        'CREATE INDEX memory_tenant_meta IF NOT EXISTS FOR (m:Memory) ON (m.tenant_id, m.meta_human_id)',
        'CREATE INDEX entity_tenant_meta IF NOT EXISTS FOR (e:Entity) ON (e.tenant_id, e.meta_human_id)',
        'CREATE INDEX persona_tenant_meta IF NOT EXISTS FOR (p:Persona) ON (p.tenant_id, p.meta_human_id)',
        'CREATE INDEX user_tenant_user IF NOT EXISTS FOR (u:User) ON (u.tenant_id, u.user_id)',
        'CREATE INDEX project_tenant_meta IF NOT EXISTS FOR (p:Project) ON (p.tenant_id, p.meta_human_id)',
        'CREATE INDEX task_tenant_meta IF NOT EXISTS FOR (t:Task) ON (t.tenant_id, t.meta_human_id)',
        'CREATE INDEX meeting_tenant_meta IF NOT EXISTS FOR (m:Meeting) ON (m.tenant_id, m.meta_human_id)',
        'CREATE INDEX document_tenant_meta IF NOT EXISTS FOR (d:Document) ON (d.tenant_id, d.meta_human_id)',
    ];
    foreach ($queries as $q) {
        try {
            graph_cypher($q, []);
        } catch (Throwable) {}
    }
    $done = true;
}
