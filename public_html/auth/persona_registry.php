<?php
declare(strict_types=1);

function mh_persona_registry_pdo(): PDO
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_getConnectionById')) {
        throw new RuntimeException('database_module_unavailable');
    }
    $pdo = database_getConnectionById('persona');
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('shared_db_unavailable');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    mh_persona_registry_ensure_schema($pdo);
    return $pdo;
}

function mh_persona_registry_ensure_schema(PDO $pdo): void
{
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
    try { $pdo->exec("ALTER TABLE mh_personas ADD UNIQUE KEY uniq_owner_persona (owner_username, persona_name)"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_personas ADD KEY idx_owner (owner_username)"); } catch (Throwable) {}
    mh_user_directory_ensure_schema($pdo);
}

function mh_persona_registry_get_owner(PDO $pdo, string $personaName): ?string
{
    $personaName = trim($personaName);
    if ($personaName === '') return null;
    $stmt = $pdo->prepare("SELECT owner_username FROM mh_personas WHERE persona_name = ? LIMIT 1");
    $stmt->execute([$personaName]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null) return null;
    $v = trim((string)$v);
    return $v !== '' ? $v : null;
}

function mh_persona_registry_upsert(PDO $pdo, string $ownerUsername, string $personaName, ?string $personaId = null, ?string $tenantId = null, ?string $userId = null, ?string $metaHumanId = null): bool
{
    $ownerUsername = trim($ownerUsername);
    $personaName = trim($personaName);
    if ($ownerUsername === '' || $personaName === '') return false;

    $userId = is_string($userId) ? trim($userId) : '';
    if ($userId === '') $userId = $ownerUsername;

    $tenantId = is_string($tenantId) ? trim($tenantId) : '';
    if ($tenantId === '') $tenantId = 'user:' . $ownerUsername;

    $personaId = is_string($personaId) ? trim($personaId) : '';
    if ($personaId === '') {
        $personaId = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $personaName);
        $personaId = strtolower(trim((string)$personaId, '._-'));
        if ($personaId === '') $personaId = strtolower(trim((string)$ownerUsername));
    }

    $metaHumanId = is_string($metaHumanId) ? trim($metaHumanId) : '';
    if ($metaHumanId === '') $metaHumanId = 'meta:' . $personaId;

    try {
        $stmt = $pdo->prepare("SELECT owner_username FROM mh_personas WHERE persona_name = ? LIMIT 1");
        $stmt->execute([$personaName]);
        $existingOwnerByName = $stmt->fetchColumn();
        $existingOwnerByName = $existingOwnerByName !== false && $existingOwnerByName !== null ? trim((string)$existingOwnerByName) : '';
        if ($existingOwnerByName !== '' && strcasecmp($existingOwnerByName, $ownerUsername) !== 0) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT owner_username FROM mh_personas WHERE persona_id = ? LIMIT 1");
        $stmt->execute([$personaId]);
        $existingOwnerById = $stmt->fetchColumn();
        $existingOwnerById = $existingOwnerById !== false && $existingOwnerById !== null ? trim((string)$existingOwnerById) : '';
        if ($existingOwnerById !== '' && strcasecmp($existingOwnerById, $ownerUsername) !== 0) {
            return false;
        }

        $stmt = $pdo->prepare("INSERT INTO mh_personas (owner_username, user_id, tenant_id, persona_id, meta_human_id, persona_name)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                tenant_id = VALUES(tenant_id),
                persona_id = VALUES(persona_id),
                meta_human_id = VALUES(meta_human_id),
                persona_name = VALUES(persona_name)");
        $stmt->execute([$ownerUsername, $userId, $tenantId, $personaId, $metaHumanId, $personaName]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function mh_persona_registry_claim(PDO $pdo, string $ownerUsername, string $personaName): bool
{
    return mh_persona_registry_upsert($pdo, $ownerUsername, $personaName, null, null, null, null);
}

function mh_persona_registry_release(PDO $pdo, string $ownerUsername, string $personaName): bool
{
    $ownerUsername = trim($ownerUsername);
    $personaName = trim($personaName);
    if ($ownerUsername === '' || $personaName === '') return false;
    $stmt = $pdo->prepare("DELETE FROM mh_personas WHERE persona_name = ? AND owner_username = ?");
    $stmt->execute([$personaName, $ownerUsername]);
    return true;
}

function mh_persona_registry_release_all_by_owner(PDO $pdo, string $ownerUsername): int
{
    $ownerUsername = trim($ownerUsername);
    if ($ownerUsername === '') return 0;
    $stmt = $pdo->prepare("DELETE FROM mh_personas WHERE owner_username = ?");
    $stmt->execute([$ownerUsername]);
    return (int)$stmt->rowCount();
}

function mh_persona_registry_update_owner(PDO $pdo, string $oldUsername, string $newUsername): void
{
    $oldUsername = trim($oldUsername);
    $newUsername = trim($newUsername);
    if ($oldUsername === '' || $newUsername === '' || $oldUsername === $newUsername) return;
    $pdo->prepare("UPDATE mh_personas SET owner_username = ?, user_id = ?, tenant_id = ? WHERE owner_username = ?")->execute([$newUsername, $newUsername, ('user:' . $newUsername), $oldUsername]);
}

function mh_persona_tenant_insert(PDO $pdoTenant, string $ownerUsername, string $personaName, ?string $createdAt = null): void
{
    $ownerUsername = trim($ownerUsername);
    $personaName = trim($personaName);
    if ($ownerUsername === '' || $personaName === '') return;
    $pdoTenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdoTenant->exec("CREATE TABLE IF NOT EXISTS mh_personas (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        persona_name VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
        KEY idx_owner (owner_username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ts = (is_string($createdAt) && trim($createdAt) !== '') ? trim($createdAt) : gmdate('Y-m-d H:i:s');
    $stmt = $pdoTenant->prepare("INSERT INTO mh_personas (owner_username, persona_name, created_at) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE created_at = LEAST(created_at, VALUES(created_at))");
    $stmt->execute([$ownerUsername, $personaName, $ts]);
}

function mh_user_directory_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_user_directory (
        username VARCHAR(255) NOT NULL PRIMARY KEY,
        display_name VARCHAR(255) NULL,
        real_first_name VARCHAR(255) NULL,
        real_last_name VARCHAR(255) NULL,
        persona_name VARCHAR(255) NULL,
        email VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_display_name (display_name),
        KEY idx_persona_name (persona_name),
        KEY idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD COLUMN real_first_name VARCHAR(255) NULL AFTER display_name"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD COLUMN real_last_name VARCHAR(255) NULL AFTER real_first_name"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD COLUMN persona_name VARCHAR(255) NULL AFTER real_last_name"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD COLUMN email VARCHAR(255) NULL AFTER persona_name"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD KEY idx_display_name (display_name)"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD KEY idx_persona_name (persona_name)"); } catch (Throwable) {}
    try { $pdo->exec("ALTER TABLE mh_user_directory ADD KEY idx_email (email)"); } catch (Throwable) {}
}

function mh_user_directory_upsert(
    PDO $pdo,
    string $username,
    string $displayName,
    ?string $realFirstName = null,
    ?string $realLastName = null,
    ?string $personaName = null,
    ?string $email = null
): void {
    $username = trim($username);
    if ($username === '') return;
    mh_user_directory_ensure_schema($pdo);

    $displayName = trim($displayName);
    $realFirstName = is_string($realFirstName) ? trim($realFirstName) : '';
    $realLastName = is_string($realLastName) ? trim($realLastName) : '';
    $personaName = is_string($personaName) ? trim($personaName) : '';
    $email = is_string($email) ? trim($email) : '';

    if ($displayName === '' && ($realFirstName !== '' || $realLastName !== '')) {
        $displayName = trim($realFirstName . ' ' . $realLastName);
    }
    if ($displayName === '') {
        $displayName = $username;
    }

    $stmt = $pdo->prepare("INSERT INTO mh_user_directory (username, display_name, real_first_name, real_last_name, persona_name, email)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            display_name = COALESCE(NULLIF(VALUES(display_name), ''), display_name),
            real_first_name = COALESCE(NULLIF(VALUES(real_first_name), ''), real_first_name),
            real_last_name = COALESCE(NULLIF(VALUES(real_last_name), ''), real_last_name),
            persona_name = COALESCE(NULLIF(VALUES(persona_name), ''), persona_name),
            email = COALESCE(NULLIF(VALUES(email), ''), email)");
    $stmt->execute([
        $username,
        $displayName,
        $realFirstName !== '' ? $realFirstName : null,
        $realLastName !== '' ? $realLastName : null,
        $personaName !== '' ? $personaName : null,
        $email !== '' ? $email : null,
    ]);
}

function mh_user_directory_get(PDO $pdo, string $username): ?array
{
    $username = trim($username);
    if ($username === '') return null;
    mh_user_directory_ensure_schema($pdo);

    $stmt = $pdo->prepare("SELECT username, display_name, real_first_name, real_last_name, persona_name, email
        FROM mh_user_directory
        WHERE username = ?
        LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return $row;
    }

    $stmt = $pdo->prepare("SELECT owner_username, persona_name FROM mh_personas WHERE persona_name = ? LIMIT 1");
    $stmt->execute([$username]);
    $personaRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($personaRow)) {
        return null;
    }

    $owner = isset($personaRow['owner_username']) ? trim((string)$personaRow['owner_username']) : '';
    $personaName = isset($personaRow['persona_name']) ? trim((string)$personaRow['persona_name']) : '';
    if ($owner === '') {
        return null;
    }

    $ownerRow = mh_user_directory_get($pdo, $owner);
    if (is_array($ownerRow)) {
        if ($personaName !== '' && (!isset($ownerRow['persona_name']) || trim((string)$ownerRow['persona_name']) === '')) {
            $ownerRow['persona_name'] = $personaName;
        }
        return $ownerRow;
    }

    return [
        'username' => $owner,
        'display_name' => $owner,
        'real_first_name' => null,
        'real_last_name' => null,
        'persona_name' => $personaName !== '' ? $personaName : null,
        'email' => null,
    ];
}

function mh_user_directory_search(PDO $pdo, string $query, int $limit = 12): array
{
    $query = trim($query);
    if ($query === '') return [];
    mh_user_directory_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));
    $like = '%' . $query . '%';

    $stmt = $pdo->prepare("
        SELECT username, display_name, real_first_name, real_last_name, persona_name, email
        FROM mh_user_directory
        WHERE username LIKE ?
           OR display_name LIKE ?
           OR persona_name LIKE ?
           OR email LIKE ?
        ORDER BY updated_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!empty($rows)) {
        return $rows;
    }

    $stmt = $pdo->prepare("
        SELECT p.owner_username AS username,
               COALESCE(d.display_name, p.owner_username) AS display_name,
               d.real_first_name,
               d.real_last_name,
               p.persona_name,
               d.email
        FROM mh_personas p
        LEFT JOIN mh_user_directory d ON d.username = p.owner_username
        WHERE p.owner_username LIKE ? OR p.persona_name LIKE ?
        ORDER BY p.created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
