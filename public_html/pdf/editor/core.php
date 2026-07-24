<?php

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);

require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once dirname(dirname(__DIR__)) . '/auth/tenant_provisioning.php';
cue_autoload('database');

function mh_pdf_editor_base_dir(): string {
    return '/home/onemeta/.data/pdf-stack/pdf-editor';
}

function mh_pdf_editor_files_dir(): string {
    return mh_pdf_editor_base_dir() . '/files';
}

function mh_pdf_editor_ensure_dirs(): void {
    $base = mh_pdf_editor_base_dir();
    $files = mh_pdf_editor_files_dir();
    if (!is_dir($base)) {
        @mkdir($base, 0750, true);
    }
    if (!is_dir($files)) {
        @mkdir($files, 0750, true);
    }
}

function mh_pdf_editor_tenant_id_for_user(string $username): string {
    return 'user:' . $username;
}

function mh_pdf_editor_table(): string {
    return 'mh_pdf_wopi_files';
}

function mh_pdf_editor_ensure_schema(PDO $pdo): void {
    $t = mh_pdf_editor_table();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `{$t}` (
            `id` CHAR(32) NOT NULL,
            `owner_id` VARCHAR(190) NOT NULL,
            `filename` VARCHAR(255) NOT NULL,
            `path` TEXT NOT NULL,
            `token` VARCHAR(96) NOT NULL,
            `token_expires_at` BIGINT NOT NULL,
            `version` INT NOT NULL DEFAULT 1,
            `created_at` BIGINT NOT NULL,
            `updated_at` BIGINT NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_owner_id` (`owner_id`),
            KEY `idx_token_expires_at` (`token_expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function mh_pdf_editor_tenant_db(string $ownerId): PDO {
    static $cache = [];
    $ownerId = trim($ownerId);
    if ($ownerId === '') {
        throw new RuntimeException('owner_required');
    }
    if (isset($cache[$ownerId]) && $cache[$ownerId] instanceof PDO) {
        return $cache[$ownerId];
    }

    $tenantId = mh_pdf_editor_tenant_id_for_user($ownerId);
    $configId = mh_resolve_tenant_db_config_id($tenantId);
    if (!is_string($configId) || $configId === '') {
        mh_provision_tenant_storage($tenantId);
        $prov = mh_provision_tenant_database($tenantId);
        if (!is_array($prov) || (($prov['success'] ?? null) !== true)) {
            throw new RuntimeException('tenant_db_unavailable');
        }
        $configId = (string)($prov['db_config_id'] ?? '');
        if ($configId === '') {
            throw new RuntimeException('tenant_db_unavailable');
        }
    }

    $pdo = database_getConnectionById($configId);
    mh_pdf_editor_ensure_schema($pdo);
    $cache[$ownerId] = $pdo;
    return $pdo;
}

function mh_pdf_editor_find_owner_for_id(string $id): ?string {
    $id = trim($id);
    if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        return null;
    }
    $dir = mh_pdf_editor_files_dir();
    if (!is_dir($dir)) {
        return null;
    }
    $needle = '_' . $id . '_';
    $hits = glob(rtrim($dir, '/') . '/*' . $needle . '*');
    if (!is_array($hits) || $hits === []) {
        return null;
    }
    foreach ($hits as $p) {
        if (!is_string($p) || $p === '' || !is_file($p)) {
            continue;
        }
        $b = basename($p);
        $pos = strpos($b, $needle);
        if ($pos === false || $pos <= 0) {
            continue;
        }
        $owner = substr($b, 0, $pos);
        $owner = is_string($owner) ? trim($owner) : '';
        if ($owner !== '') {
            return $owner;
        }
    }
    return null;
}

function mh_pdf_editor_random_id(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function mh_pdf_editor_create_record(string $ownerId, string $originalName, string $path, ?string $id = null): array {
    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $id = $id ?: mh_pdf_editor_random_id(16);
    $token = mh_pdf_editor_random_id(24);
    $now = time();
    $expires = $now + 3600;
    $stmt = $pdo->prepare('
        INSERT INTO ' . mh_pdf_editor_table() . ' (id, owner_id, filename, path, token, token_expires_at, version, created_at, updated_at)
        VALUES (:id, :owner_id, :filename, :path, :token, :token_expires_at, 1, :created_at, :updated_at)
    ');
    $stmt->execute([
        ':id' => $id,
        ':owner_id' => $ownerId,
        ':filename' => $originalName,
        ':path' => $path,
        ':token' => $token,
        ':token_expires_at' => $expires,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return ['id' => $id, 'token' => $token, 'token_expires_at' => $expires];
}

function mh_pdf_editor_get_record(string $id, ?string $ownerIdHint = null): ?array {
    $id = trim($id);
    if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        return null;
    }
    $ownerId = is_string($ownerIdHint) ? trim($ownerIdHint) : '';
    if ($ownerId === '') {
        $ownerId = (string)(mh_pdf_editor_find_owner_for_id($id) ?? '');
    }
    if ($ownerId === '') {
        return null;
    }
    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $stmt = $pdo->prepare('SELECT * FROM ' . mh_pdf_editor_table() . ' WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_pdf_editor_list_records(string $ownerId, int $limit = 50): array {
    $limit = max(1, min(200, (int)$limit));
    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $stmt = $pdo->prepare('
        SELECT id, filename, version, updated_at
        FROM ' . mh_pdf_editor_table() . '
        WHERE owner_id = :owner_id
        ORDER BY updated_at DESC
        LIMIT ' . (int)$limit . '
    ');
    $stmt->bindValue(':owner_id', $ownerId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_pdf_editor_refresh_token(string $id, ?string $ownerIdHint = null): array {
    $row = mh_pdf_editor_get_record($id, $ownerIdHint);
    if (!$row) {
        throw new RuntimeException('not_found');
    }
    $ownerId = (string)($row['owner_id'] ?? '');
    if ($ownerId === '') {
        throw new RuntimeException('owner_required');
    }
    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $token = mh_pdf_editor_random_id(24);
    $now = time();
    $expires = $now + 3600;
    $stmt = $pdo->prepare('
        UPDATE ' . mh_pdf_editor_table() . '
        SET token = :token, token_expires_at = :exp, updated_at = :updated_at
        WHERE id = :id AND owner_id = :owner_id
    ');
    $stmt->execute([
        ':token' => $token,
        ':exp' => $expires,
        ':updated_at' => $now,
        ':id' => $id,
        ':owner_id' => $ownerId,
    ]);
    return ['token' => $token, 'token_expires_at' => $expires];
}

function mh_pdf_editor_put_contents(string $id, string $bytes): void {
    $row = mh_pdf_editor_get_record($id);
    if (!$row) {
        throw new RuntimeException('not_found');
    }
    $path = (string)$row['path'];
    if ($path === '' || strpos($path, mh_pdf_editor_files_dir() . '/') !== 0) {
        throw new RuntimeException('invalid_path');
    }
    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('write_failed');
    }
    $ownerId = (string)($row['owner_id'] ?? '');
    if ($ownerId === '') {
        throw new RuntimeException('owner_required');
    }
    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $now = time();
    $stmt = $pdo->prepare('
        UPDATE ' . mh_pdf_editor_table() . '
        SET version = version + 1, updated_at = :updated_at
        WHERE id = :id AND owner_id = :owner_id
    ');
    $stmt->execute([':updated_at' => $now, ':id' => $id, ':owner_id' => $ownerId]);
}

function mh_pdf_editor_validate_token(array $row, string $token): bool {
    if ($token === '') {
        return false;
    }
    if (!hash_equals((string)$row['token'], $token)) {
        return false;
    }
    $exp = (int)$row['token_expires_at'];
    return time() <= $exp;
}

function mh_pdf_editor_delete_record(string $id, string $ownerId): bool {
    $row = mh_pdf_editor_get_record($id, $ownerId);
    if (!$row) {
        return false;
    }
    if ((string)$row['owner_id'] !== $ownerId) {
        return false;
    }

    $path = (string)$row['path'];
    $filesRoot = mh_pdf_editor_files_dir();
    $filesRootReal = realpath($filesRoot);
    $fileReal = $path !== '' ? realpath($path) : false;
    if ($filesRootReal && $fileReal) {
        if (strncmp($fileReal, $filesRootReal . '/', strlen($filesRootReal) + 1) !== 0) {
            throw new RuntimeException('invalid_path');
        }
    }

    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }

    $pdo = mh_pdf_editor_tenant_db($ownerId);
    $stmt = $pdo->prepare('DELETE FROM ' . mh_pdf_editor_table() . ' WHERE id = :id AND owner_id = :owner_id');
    $stmt->execute([':id' => $id, ':owner_id' => $ownerId]);
    return $stmt->rowCount() > 0;
}
