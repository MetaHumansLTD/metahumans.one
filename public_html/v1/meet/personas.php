<?php
declare(strict_types=1);

require_once __DIR__ . '/v1_meet_auth.php';

$body = mh_meet_read_json_body();
$jwt = mh_meet_extract_access_token($body);
$pl = mh_meet_verify_access_token($jwt);

$username = '';
if (isset($pl['user_id']) && is_string($pl['user_id'])) $username = trim($pl['user_id']);
if ($username === '' && isset($pl['sub']) && is_string($pl['sub'])) $username = trim($pl['sub']);
if ($username === '') mh_meet_json_out(401, ['ok' => false, 'error' => 'missing_user_id']);

$items = [];
try {
    if (function_exists('cue_autoload')) call_user_func('cue_autoload', 'database');
    if (function_exists('database_getConnectionById') || function_exists('database_getContextAwareConnection')) {
        $pdo = function_exists('database_getConnectionById') ? call_user_func('database_getConnectionById', 'persona') : call_user_func('database_getContextAwareConnection');
        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_personas (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            owner_username VARCHAR(255) NOT NULL,
            user_id VARCHAR(255) NULL,
            tenant_id VARCHAR(255) NULL,
            persona_id VARCHAR(255) NULL,
            meta_human_id VARCHAR(255) NULL,
            persona_name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
            UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id),
            KEY idx_owner (owner_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN user_id VARCHAR(255) NULL AFTER owner_username"); } catch (Throwable) {}
        try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN tenant_id VARCHAR(255) NULL AFTER user_id"); } catch (Throwable) {}
        try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN persona_id VARCHAR(255) NULL AFTER tenant_id"); } catch (Throwable) {}
        try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN meta_human_id VARCHAR(255) NULL AFTER persona_id"); } catch (Throwable) {}
        try { $pdo->exec("ALTER TABLE mh_personas ADD UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id)"); } catch (Throwable) {}
        $stmt = $pdo->prepare("SELECT persona_id, persona_name FROM mh_personas WHERE owner_username = ? ORDER BY persona_name");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $p = trim((string)($row['persona_name'] ?? ''));
            if ($p === '') continue;
            $pid = trim((string)($row['persona_id'] ?? ''));
            if ($pid === '') $pid = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $p));
            $pid = trim((string)$pid, '._-');
            $items[] = [
                'persona_id' => $pid !== '' ? $pid : $p,
                'label' => $p,
            ];
        }
    }
} catch (Throwable $e) {
}

if (!$items) {
    $fallback = 'MH-' . $username;
    $items[] = [
        'persona_id' => $fallback,
        'label' => $fallback,
    ];
}

mh_meet_json_out(200, ['ok' => true, 'personas' => $items]);
