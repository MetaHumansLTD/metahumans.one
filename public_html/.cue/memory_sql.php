<?php
declare(strict_types=1);

function memory_sql_get_tenant_db_config_id(string $tenantId): ?string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return null;
    }

    $path = '/data/config/tenant-contexts.json';
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return null;
    }

    $entry = $decoded[$tenantId] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $id = $entry['db_config_id'] ?? null;
    return is_string($id) && trim($id) !== '' ? trim($id) : null;
}

function memory_sql_get_pdo(string $tenantId): PDO
{
    $cfgId = memory_sql_get_tenant_db_config_id($tenantId);
    if ($cfgId === null) {
        throw new RuntimeException('tenant_db_config_missing');
    }

    $configFile = '/data/config/db_configs.json';
    if (!is_file($configFile)) {
        throw new RuntimeException('db_configs_missing');
    }
    $decoded = json_decode((string)file_get_contents($configFile), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('db_configs_invalid');
    }
    $cfg = $decoded[$cfgId] ?? null;
    if (!is_array($cfg)) {
        throw new RuntimeException('tenant_db_config_not_found');
    }
    if (($cfg['is_active'] ?? null) !== true) {
        throw new RuntimeException('tenant_db_config_inactive');
    }

    if (function_exists('cue_autoload')) {
        call_user_func('cue_autoload', 'database');
    }
    if (!function_exists('database_getConnectionById')) {
        throw new RuntimeException('database_module_unavailable');
    }

    try {
        return database_getConnectionById($cfgId);
    } catch (Throwable $e) {
        throw new RuntimeException('tenant_db_connect_failed', 0, $e);
    }
}

function memory_sql_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mh_memory_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id VARCHAR(64) NOT NULL,
            tenant_id VARCHAR(255) NOT NULL,
            persona_id VARCHAR(255) NOT NULL,
            meta_human_id VARCHAR(255) NOT NULL,
            kind VARCHAR(64) NOT NULL DEFAULT 'note',
            source VARCHAR(64) NOT NULL DEFAULT 'workbench',
            text LONGTEXT NOT NULL,
            tags JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_id VARCHAR(255) NULL,
            username VARCHAR(255) NULL,
            session_id VARCHAR(255) NULL,
            device_id VARCHAR(255) NULL,
            qdrant_point_id VARCHAR(64) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_event_id (event_id),
            KEY idx_tenant_persona_time (tenant_id, persona_id, created_at),
            KEY idx_tenant_meta_time (tenant_id, meta_human_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function memory_sql_insert_event(PDO $pdo, array $ctx, array $row): bool
{
    $tenantId = isset($ctx['tenant_id']) ? trim((string)$ctx['tenant_id']) : '';
    $personaId = isset($ctx['persona_id']) ? trim((string)$ctx['persona_id']) : '';
    $metaHumanId = isset($ctx['meta_human_id']) ? trim((string)$ctx['meta_human_id']) : '';
    if ($tenantId === '' || $personaId === '' || $metaHumanId === '') {
        throw new RuntimeException('invalid_context');
    }

    $eventId = isset($row['event_id']) ? trim((string)$row['event_id']) : '';
    $text = isset($row['text']) ? trim((string)$row['text']) : '';
    if ($eventId === '' || $text === '') {
        throw new RuntimeException('invalid_event');
    }

    $kind = isset($row['kind']) ? trim((string)$row['kind']) : 'note';
    if ($kind === '') {
        $kind = 'note';
    }
    $source = isset($row['source']) ? trim((string)$row['source']) : 'workbench';
    if ($source === '') {
        $source = 'workbench';
    }

    $tags = $row['tags'] ?? null;
    if (is_array($tags)) {
        $tags = array_values(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : '', $tags), fn($t) => $t !== ''));
    } else {
        $tags = null;
    }
    $tagsJson = $tags !== null ? json_encode($tags, JSON_UNESCAPED_SLASHES) : null;

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO mh_memory_events
            (event_id, tenant_id, persona_id, meta_human_id, kind, source, text, tags, user_id, username, session_id, device_id, qdrant_point_id)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $eventId,
        $tenantId,
        $personaId,
        $metaHumanId,
        $kind,
        $source,
        $text,
        $tagsJson,
        isset($ctx['user_id']) ? (string)$ctx['user_id'] : null,
        isset($ctx['username']) ? (string)$ctx['username'] : null,
        isset($ctx['session_id']) ? (string)$ctx['session_id'] : null,
        isset($ctx['device_id']) ? (string)$ctx['device_id'] : null,
        isset($row['qdrant_point_id']) ? (string)$row['qdrant_point_id'] : null,
    ]);
    return $stmt->rowCount() > 0;
}

function memory_sql_recent(PDO $pdo, array $ctx, int $limit = 50): array
{
    $tenantId = isset($ctx['tenant_id']) ? trim((string)$ctx['tenant_id']) : '';
    $personaId = isset($ctx['persona_id']) ? trim((string)$ctx['persona_id']) : '';
    if ($tenantId === '' || $personaId === '') {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare(
        "SELECT event_id, kind, source, text, tags, created_at, qdrant_point_id
         FROM mh_memory_events
         WHERE tenant_id = ? AND persona_id = ?
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$tenantId, $personaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        if (isset($r['tags']) && is_string($r['tags']) && trim($r['tags']) !== '') {
            $d = json_decode($r['tags'], true);
            $r['tags'] = is_array($d) ? $d : null;
        } else {
            $r['tags'] = null;
        }
    }
    unset($r);
    return $rows;
}
