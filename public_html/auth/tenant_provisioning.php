<?php

require_once __DIR__ . '/../.cue/cue.php';

if (!function_exists('mh_normalize_tenant_id')) {
    function mh_normalize_tenant_id(string $tenantId): string {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return '';
        }
        if (stripos($tenantId, 'user:') === 0) {
            return 'user:' . strtolower(trim(substr($tenantId, 5)));
        }
        return $tenantId;
    }
}

function mh_tenant_normalize_for_mysql_db(string $tenantId): string {
    $tenantId = mh_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return '';
    }
    $prefix = 'tenant_';
    $raw = $tenantId;
    if (str_starts_with($tenantId, 'user:')) {
        $prefix = 'tenant_user_';
        $raw = substr($tenantId, 5);
    } elseif (str_starts_with($tenantId, 'persona:')) {
        $prefix = 'tenant_persona_';
        $raw = substr($tenantId, 8);
    }
    $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $raw);
    $safe = trim($safe, '_');
    if ($safe === '') {
        $safe = substr(hash('sha256', $tenantId), 0, 16);
    }
    $name = $prefix . $safe;
    if (strlen($name) > 64) {
        $suffix = substr(hash('sha256', $tenantId), 0, 15);
        $trimLen = 64 - 1 - strlen($suffix);
        $name = substr($name, 0, max(0, $trimLen)) . '_' . $suffix;
    }
    return $name;
}

function mh_tenant_config_id(string $tenantId): string {
    $tenantId = mh_normalize_tenant_id($tenantId);
    return 'tenant_' . substr(hash('sha256', $tenantId), 0, 24);
}

function mh_tenant_storage_paths(string $tenantId): array {
    $tenantId = mh_normalize_tenant_id($tenantId);
    $suffix = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
    $suffix = $suffix !== '' ? $suffix : substr(hash('sha256', $tenantId), 0, 16);
    return [
        'vector_path' => '/vector/tenant_' . $suffix,
        'graph_path' => '/graph/tenant_' . $suffix,
    ];
}

function mh_load_tenant_map_path(): string {
    $paths = cue_autoload('paths');
    $p = $paths ? $paths->getSecureFilePath('config/tenant-contexts.json', true) : null;
    if (!$p) {
        $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
        $p = rtrim($base, '/') . '/config/tenant-contexts.json';
    }
    return $p;
}

function mh_upsert_db_config(array $config): bool {
    $paths = cue_autoload('paths');
    $configsPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;
    if (!$configsPath) {
        $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
        $configsPath = rtrim($base, '/') . '/config/db_configs.json';
    }
    $dir = dirname($configsPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $existing = [];
    if (file_exists($configsPath)) {
        $decoded = json_decode((string)file_get_contents($configsPath), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $id = (string)($config['id'] ?? '');
    if ($id === '') {
        return false;
    }

    foreach (['host', 'database', 'username', 'password'] as $field) {
        $val = $config[$field] ?? null;
        if (!is_string($val) || trim($val) === '' || strlen(trim($val)) < 44 || base64_decode($val, true) === false) {
            return false;
        }
    }

    $existing[$id] = $config;
    return file_put_contents($configsPath, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function mh_register_tenant_context(string $tenantId, array $data): bool {
    $tenantId = mh_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return false;
    }
    $path = mh_load_tenant_map_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $map = [];
    if (file_exists($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $map = $decoded;
        }
    }
    $map[$tenantId] = array_merge($map[$tenantId] ?? [], $data, ['updated_at' => date('Y-m-d H:i:s')]);
    if (!isset($map[$tenantId]['created_at'])) {
        $map[$tenantId]['created_at'] = date('Y-m-d H:i:s');
    }
    return file_put_contents($path, json_encode($map, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function mh_resolve_tenant_db_config_id(string $tenantId): ?string {
    $tenantId = mh_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return null;
    }
    $path = mh_load_tenant_map_path();
    if (!file_exists($path)) {
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
    return is_string($id) && $id !== '' ? $id : null;
}

function mh_control_plane_db_name(): string {
    return 'mh_control';
}

function mh_control_plane_config_id(): string {
    return 'control_plane';
}

function mh_control_plane_reader_username(): string {
    return 'mh_control_plane';
}

function mh_control_plane_reader_config_exists(): bool {
    $path = mh_tenant_db_configs_path();
    if (!file_exists($path)) {
        return false;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return false;
    }
    return isset($decoded[mh_control_plane_config_id()]) && is_array($decoded[mh_control_plane_config_id()]);
}

function mh_control_plane_ensure_schema(PDO $adminPdo): bool {
    $db = mh_control_plane_db_name();
    try {
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $adminPdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`tenant_db_map` (tenant_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY, db_config_id VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_db_config_id (db_config_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $adminPdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`db_configs` (db_config_id VARCHAR(64) NOT NULL PRIMARY KEY, config_json LONGTEXT NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $adminPdo->exec("ALTER TABLE `{$db}`.`tenant_db_map` MODIFY tenant_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"); } catch (Throwable $e) {}
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_control_plane_ensure_reader(PDO $adminPdo, string $adminConfigId): bool {
    if (mh_control_plane_reader_config_exists()) {
        return true;
    }
    if (!function_exists('database_getDbConfigEncryptionKey') || !function_exists('security_encryptValue')) {
        return false;
    }
    $encryptionKey = database_getDbConfigEncryptionKey();
    if (!is_string($encryptionKey) || $encryptionKey === '') {
        return false;
    }
    $user = mh_control_plane_reader_username();
    $pass = bin2hex(random_bytes(16));
    $db = mh_control_plane_db_name();
    try {
        foreach (['localhost', '127.0.0.1'] as $host) {
            $adminPdo->exec("CREATE USER IF NOT EXISTS '{$user}'@'{$host}' IDENTIFIED BY '{$pass}'");
            try { $adminPdo->exec("ALTER USER '{$user}'@'{$host}' IDENTIFIED BY '{$pass}'"); } catch (Throwable $e) {}
            $adminPdo->exec("GRANT SELECT ON `{$db}`.`tenant_db_map` TO '{$user}'@'{$host}'");
            $adminPdo->exec("GRANT SELECT ON `{$db}`.`db_configs` TO '{$user}'@'{$host}'");
        }
        $adminPdo->exec("FLUSH PRIVILEGES");
    } catch (Throwable $e) {
        return false;
    }
    $hostEnc = security_encryptValue('127.0.0.1', $encryptionKey);
    $dbEnc = security_encryptValue($db, $encryptionKey);
    $userEnc = security_encryptValue($user, $encryptionKey);
    $passEnc = security_encryptValue($pass, $encryptionKey);
    if (!is_string($hostEnc) || !is_string($dbEnc) || !is_string($userEnc) || !is_string($passEnc)) {
        return false;
    }
    $now = date('Y-m-d H:i:s');
    return mh_upsert_db_config([
        'id' => mh_control_plane_config_id(),
        'name' => 'control_plane',
        'type' => 'mariadb',
        'host' => $hostEnc,
        'port' => '3307',
        'database' => $dbEnc,
        'username' => $userEnc,
        'password' => $passEnc,
        'charset' => 'utf8mb4',
        'context' => 'control_plane',
        'page_mapping' => [],
        'priority' => 1,
        'storage_profile' => 'block_mysql',
        'admin_config_id' => $adminConfigId,
        'created_at' => $now,
        'updated_at' => $now,
        'is_active' => true,
    ]);
}

function mh_control_plane_get_pdo(): ?PDO {
    try {
        return database_getConnectionById(mh_control_plane_config_id());
    } catch (Throwable $e) {
        return null;
    }
}

function mh_control_plane_resolve_db_config_id(string $tenantId): ?string {
    $tenantId = mh_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return null;
    }
    $pdo = mh_control_plane_get_pdo();
    if (!$pdo) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT db_config_id FROM mh_control.tenant_db_map WHERE tenant_id = :t LIMIT 1');
        $stmt->execute([':t' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $id = is_array($row) ? ($row['db_config_id'] ?? null) : null;
        return is_string($id) && $id !== '' ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function mh_control_plane_migrate_batch_from_json(int $batchSize = 200, bool $dryRun = true): array {
    $batchSize = max(1, min(5000, $batchSize));
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
        cue_autoload('security');
    }
    $adminConfigId = mh_find_block_provisioner_config_id();
    if (!is_string($adminConfigId) || $adminConfigId === '') {
        return ['success' => false, 'message' => 'missing_db_provisioner_config'];
    }
    $adminPdo = null;
    try {
        $adminPdo = database_getConnectionById($adminConfigId);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'provisioner_connection_failed'];
    }
    if (!($adminPdo instanceof PDO)) {
        return ['success' => false, 'message' => 'provisioner_connection_unavailable'];
    }
    if (!mh_control_plane_ensure_schema($adminPdo)) {
        return ['success' => false, 'message' => 'control_plane_schema_failed'];
    }
    mh_control_plane_ensure_reader($adminPdo, $adminConfigId);
    $tenantMapPath = mh_load_tenant_map_path();
    if (!file_exists($tenantMapPath)) {
        return ['success' => false, 'message' => 'tenant_map_missing'];
    }
    $tenantMap = json_decode((string)file_get_contents($tenantMapPath), true);
    if (!is_array($tenantMap)) {
        return ['success' => false, 'message' => 'tenant_map_invalid'];
    }
    $configsPath = mh_tenant_db_configs_path();
    if (!file_exists($configsPath)) {
        return ['success' => false, 'message' => 'db_configs_missing'];
    }
    $configs = json_decode((string)file_get_contents($configsPath), true);
    if (!is_array($configs)) {
        return ['success' => false, 'message' => 'db_configs_invalid'];
    }
    $now = date('Y-m-d H:i:s');
    $processed = 0;
    $migrated = 0;
    $skippedMissing = 0;
    $skippedNonTenant = 0;
    $deactivateIds = [];
    foreach ($tenantMap as $tenantId => $row) {
        if ($processed >= $batchSize) {
            break;
        }
        if (!is_string($tenantId) || !is_array($row)) {
            continue;
        }
        $dbConfigId = isset($row['db_config_id']) ? trim((string)$row['db_config_id']) : '';
        if ($dbConfigId === '') {
            continue;
        }
        $processed++;
        if (!str_starts_with($dbConfigId, 'tenant_')) {
            $skippedNonTenant++;
            continue;
        }
        $cfg = $configs[$dbConfigId] ?? null;
        if (!is_array($cfg)) {
            $skippedMissing++;
            continue;
        }
        $cfg['id'] = $dbConfigId;
        if (!isset($cfg['is_active'])) {
            $cfg['is_active'] = true;
        }
        $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES);
        if (!is_string($cfgJson) || $cfgJson === '') {
            continue;
        }
        if (!$dryRun) {
            try {
                $stmt1 = $adminPdo->prepare('INSERT INTO mh_control.db_configs (db_config_id, config_json, is_active, created_at, updated_at) VALUES (:id, :j, 1, :c, :u) ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), is_active = 1, updated_at = VALUES(updated_at)');
                $stmt1->execute([':id' => $dbConfigId, ':j' => $cfgJson, ':c' => $now, ':u' => $now]);
                $stmt2 = $adminPdo->prepare('INSERT INTO mh_control.tenant_db_map (tenant_id, db_config_id, created_at, updated_at) VALUES (:t, :id, :c, :u) ON DUPLICATE KEY UPDATE db_config_id = VALUES(db_config_id), updated_at = VALUES(updated_at)');
                $stmt2->execute([':t' => $tenantId, ':id' => $dbConfigId, ':c' => $now, ':u' => $now]);
            } catch (Throwable $e) {
                continue;
            }
        }
        $migrated++;
        $deactivateIds[] = $dbConfigId;
    }
    $jsonUpdated = false;
    $deactivated = 0;
    if (!$dryRun && !empty($deactivateIds)) {
        foreach ($deactivateIds as $id) {
            if (!isset($configs[$id]) || !is_array($configs[$id])) {
                continue;
            }
            $configs[$id]['is_active'] = false;
            $configs[$id]['updated_at'] = $now;
            $deactivated++;
        }
        $ok = file_put_contents($configsPath, json_encode($configs, JSON_PRETTY_PRINT), LOCK_EX);
        $jsonUpdated = $ok !== false;
    }
    return [
        'success' => true,
        'dry_run' => $dryRun,
        'batch_size' => $batchSize,
        'processed_tenants' => $processed,
        'migrated' => $migrated,
        'skipped_missing_config' => $skippedMissing,
        'skipped_non_tenant' => $skippedNonTenant,
        'db_configs_deactivated' => $deactivated,
        'db_configs_updated' => $jsonUpdated,
    ];
}

function mh_find_block_provisioner_config_id(): ?string {
    if (function_exists('cue_autoload')) {
        cue_autoload('paths');
    }
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $configsPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;
    if (!$configsPath) {
        $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
        $configsPath = rtrim($base, '/') . '/config/db_configs.json';
    }
    if (!file_exists($configsPath)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($configsPath), true);
    if (!is_array($decoded)) {
        return null;
    }
    if (isset($decoded['db_block_provisioner_3307']) && is_array($decoded['db_block_provisioner_3307']) && (($decoded['db_block_provisioner_3307']['is_active'] ?? null) === true)) {
        return 'db_block_provisioner_3307';
    }
    foreach ($decoded as $id => $cfg) {
        if (!is_string($id) || !is_array($cfg)) continue;
        if (($cfg['is_active'] ?? null) !== true) continue;
        $port = (string)($cfg['port'] ?? '');
        if ($port !== '3307') continue;
        $name = (string)($cfg['name'] ?? '');
        if ($name === '' && $id === '') continue;
        if (stripos($name, 'provisioner') === false && stripos($id, 'provisioner') === false && stripos($name, 'block') === false) {
            continue;
        }
        return $id;
    }
    return null;
}

function mh_tenant_mysql_username(string $tenantId): string {
    return 't_' . substr(hash('sha256', $tenantId), 0, 20);
}

function mh_provision_tenant_database(string $tenantId): array {
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return ['success' => false, 'message' => 'tenant_id_required'];
    }
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
        cue_autoload('security');
    }

    $adminConfigId = mh_find_block_provisioner_config_id();
    if (!is_string($adminConfigId) || $adminConfigId === '') {
        return ['success' => false, 'message' => 'missing_db_provisioner_config'];
    }

    $dbName = mh_tenant_normalize_for_mysql_db($tenantId);
    if ($dbName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        return ['success' => false, 'message' => 'invalid_db_name'];
    }

    $tenantConfigId = mh_tenant_config_id($tenantId);
    $mysqlUser = mh_tenant_mysql_username($tenantId);
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $mysqlUser)) {
        return ['success' => false, 'message' => 'invalid_mysql_user'];
    }
    $mysqlPass = bin2hex(random_bytes(16));

    $adminPdo = null;
    try {
        $adminPdo = database_getConnectionById($adminConfigId);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'provisioner_connection_failed'];
    }

    try {
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        foreach (['localhost', '127.0.0.1'] as $host) {
            $adminPdo->exec("CREATE USER IF NOT EXISTS '{$mysqlUser}'@'{$host}' IDENTIFIED BY '{$mysqlPass}'");
            try {
                $adminPdo->exec("ALTER USER '{$mysqlUser}'@'{$host}' IDENTIFIED BY '{$mysqlPass}'");
            } catch (Throwable $e) {}
            $adminPdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$mysqlUser}'@'{$host}'");
        }
        $adminPdo->exec("FLUSH PRIVILEGES");
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'provisioner_sql_failed'];
    }

    $encryptionKey = function_exists('database_getDbConfigEncryptionKey') ? database_getDbConfigEncryptionKey() : '';
    if (!is_string($encryptionKey) || $encryptionKey === '' || !function_exists('security_encryptValue')) {
        return ['success' => false, 'message' => 'db_config_encryption_unavailable'];
    }

    $hostEnc = security_encryptValue('127.0.0.1', $encryptionKey);
    $dbEnc = security_encryptValue($dbName, $encryptionKey);
    $userEnc = security_encryptValue($mysqlUser, $encryptionKey);
    $passEnc = security_encryptValue($mysqlPass, $encryptionKey);
    if (!is_string($hostEnc) || !is_string($dbEnc) || !is_string($userEnc) || !is_string($passEnc)) {
        return ['success' => false, 'message' => 'db_config_encryption_failed'];
    }

    $now = date('Y-m-d H:i:s');
    $ok = mh_upsert_db_config([
        'id' => $tenantConfigId,
        'name' => $dbName,
        'type' => 'mariadb',
        'host' => $hostEnc,
        'port' => '3307',
        'database' => $dbEnc,
        'username' => $userEnc,
        'password' => $passEnc,
        'charset' => 'utf8mb4',
        'context' => 'tenant',
        'page_mapping' => [],
        'priority' => 10,
        'storage_profile' => 'block_mysql',
        'admin_config_id' => $adminConfigId,
        'created_at' => $now,
        'updated_at' => $now,
        'is_active' => true,
    ]);
    if (!$ok) {
        return ['success' => false, 'message' => 'db_config_write_failed'];
    }

    $controlPlaneOk = false;
    try {
        $schemaOk = mh_control_plane_ensure_schema($adminPdo);
        if ($schemaOk) {
            $readerOk = mh_control_plane_ensure_reader($adminPdo, $adminConfigId);
            if ($readerOk) {
                $cfgJson = json_encode([
                    'id' => $tenantConfigId,
                    'name' => $dbName,
                    'type' => 'mariadb',
                    'host' => $hostEnc,
                    'port' => '3307',
                    'database' => $dbEnc,
                    'username' => $userEnc,
                    'password' => $passEnc,
                    'charset' => 'utf8mb4',
                    'context' => 'tenant',
                    'page_mapping' => [],
                    'priority' => 10,
                    'storage_profile' => 'block_mysql',
                    'admin_config_id' => $adminConfigId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'is_active' => true,
                ], JSON_UNESCAPED_SLASHES);
                if (is_string($cfgJson) && $cfgJson !== '') {
                    $stmt1 = $adminPdo->prepare('INSERT INTO mh_control.db_configs (db_config_id, config_json, is_active, created_at, updated_at) VALUES (:id, :j, 1, :c, :u) ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), is_active = 1, updated_at = VALUES(updated_at)');
                    $stmt1->execute([':id' => $tenantConfigId, ':j' => $cfgJson, ':c' => $now, ':u' => $now]);
                    $stmt2 = $adminPdo->prepare('INSERT INTO mh_control.tenant_db_map (tenant_id, db_config_id, created_at, updated_at) VALUES (:t, :id, :c, :u) ON DUPLICATE KEY UPDATE db_config_id = VALUES(db_config_id), updated_at = VALUES(updated_at)');
                    $stmt2->execute([':t' => $tenantId, ':id' => $tenantConfigId, ':c' => $now, ':u' => $now]);
                    $controlPlaneOk = true;
                }
            }
        }
    } catch (Throwable $e) {
        $controlPlaneOk = false;
    }

    if ($controlPlaneOk) {
        $path = mh_tenant_db_configs_path();
        $decoded = [];
        if (file_exists($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (!is_array($decoded)) $decoded = [];
        }
        if (isset($decoded[$tenantConfigId]) && is_array($decoded[$tenantConfigId])) {
            $decoded[$tenantConfigId]['is_active'] = false;
            $decoded[$tenantConfigId]['updated_at'] = $now;
            @file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    mh_register_tenant_context($tenantId, [
        'db_config_id' => $tenantConfigId,
        'db_name' => $dbName,
    ]);

    return [
        'success' => true,
        'tenant_id' => $tenantId,
        'db_config_id' => $tenantConfigId,
        'db_name' => $dbName,
        'admin_config_id' => $adminConfigId,
    ];
}

function mh_apply_tenant_context(string $tenantId): bool {
    $tenantId = mh_normalize_tenant_id($tenantId);
    if ($tenantId === '') {
        return false;
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    if (function_exists('cue_autoload')) {
        cue_autoload('database');
        cue_autoload('security');
    }

    $dbConfigId = mh_control_plane_resolve_db_config_id($tenantId);
    if (!is_string($dbConfigId) || $dbConfigId === '') {
        $dbConfigId = mh_resolve_tenant_db_config_id($tenantId);
    }
    if (!is_string($dbConfigId) || $dbConfigId === '') {
        mh_provision_tenant_storage($tenantId);
        $provisioned = mh_provision_tenant_database($tenantId);
        if (!is_array($provisioned) || (($provisioned['success'] ?? null) !== true)) {
            return false;
        }
        $dbConfigId = (string)($provisioned['db_config_id'] ?? '');
        if ($dbConfigId === '') {
            return false;
        }
    }

    if (function_exists('database_getConfiguration')) {
        $cfg = database_getConfiguration($dbConfigId);
        if (!is_array($cfg)) {
            return false;
        }
    }

    try {
        $pdo = database_getConnectionById($dbConfigId);
        $ok = $pdo->query('SELECT 1');
        if ($ok === false) {
            throw new Exception('tenant_db_probe_failed');
        }
    } catch (Throwable $e) {
        mh_provision_tenant_storage($tenantId);
        $provisioned = mh_provision_tenant_database($tenantId);
        if (!is_array($provisioned) || (($provisioned['success'] ?? null) !== true)) {
            return false;
        }
        $dbConfigId = (string)($provisioned['db_config_id'] ?? '');
        if ($dbConfigId === '') {
            return false;
        }
    }

    $_SESSION['mh_db_preference'] = $dbConfigId;
    $_SESSION['current_database_config_id'] = $dbConfigId;

    $provisionedTenant = isset($_SESSION['mh_integrations_provisioned_tenant']) && is_string($_SESSION['mh_integrations_provisioned_tenant']) ? (string)$_SESSION['mh_integrations_provisioned_tenant'] : '';
    if ($provisionedTenant !== $tenantId) {
        $u = isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
        try { mh_provision_tenant_integrations($tenantId, $u); } catch (Throwable $e) {}
        $_SESSION['mh_integrations_provisioned_tenant'] = $tenantId;
    }

    return true;
}

function mh_provision_tenant_storage(string $tenantId, array $opts = []): array {
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return ['success' => false, 'message' => 'tenant_id_required'];
    }

    $paths = mh_tenant_storage_paths($tenantId);

    $vectorOk = null;
    $graphOk = null;
    $vectorErr = null;
    $graphErr = null;

    $vectorPath = $paths['vector_path'];
    $graphPath = $paths['graph_path'];

    if (!is_dir($vectorPath)) {
        $vectorOk = @mkdir($vectorPath, 0775, true);
        if (!$vectorOk && !is_dir($vectorPath)) {
            $vectorErr = 'mkdir_failed';
        }
    } else {
        $vectorOk = true;
    }

    if (!is_dir($graphPath)) {
        $graphOk = @mkdir($graphPath, 0775, true);
        if (!$graphOk && !is_dir($graphPath)) {
            $graphErr = 'mkdir_failed';
        }
    } else {
        $graphOk = true;
    }

    $tenantRegistered = mh_register_tenant_context($tenantId, [
        'vector_path' => $vectorPath,
        'graph_path' => $graphPath,
    ]);

    return [
        'success' => true,
        'tenant_id' => $tenantId,
        'vector' => [
            'path' => $vectorPath,
            'created_or_exists' => (bool)$vectorOk,
            'error' => $vectorErr,
        ],
        'graph' => [
            'path' => $graphPath,
            'created_or_exists' => (bool)$graphOk,
            'error' => $graphErr,
        ],
        'context_registered' => $tenantRegistered,
    ];
}

function mh_provision_tenant_integrations(string $tenantId, string $username = ''): array {
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return ['success' => false, 'message' => 'tenant_id_required'];
    }

    $tenantSafe = mh_tenant_safe($tenantId);
    if ($tenantSafe === '') {
        $tenantSafe = substr(hash('sha256', $tenantId), 0, 16);
    }
    $root = '/data/tenants/' . $tenantSafe;
    $graphifyRoot = $root . '/graphify';
    $multicaRoot = $root . '/multica';
    $accountingRoot = $root . '/accounting';
    $accountingReceiptsRoot = $accountingRoot . '/receipts';
    $accountingInvoicesRoot = $accountingRoot . '/invoices';
    $accountingExportsBoardRoot = $accountingRoot . '/exports/board';
    $automationRoot = $root . '/automation';
    $automationStateRoot = $automationRoot . '/state';
    $automationAuditRoot = $automationRoot . '/audit';

    $mkdirs = [
        'tenant_root' => $root,
        'graphify_root' => $graphifyRoot,
        'multica_root' => $multicaRoot,
        'accounting_root' => $accountingRoot,
        'accounting_receipts_root' => $accountingReceiptsRoot,
        'accounting_invoices_root' => $accountingInvoicesRoot,
        'accounting_exports_board_root' => $accountingExportsBoardRoot,
        'automation_root' => $automationRoot,
        'automation_state_root' => $automationStateRoot,
        'automation_audit_root' => $automationAuditRoot,
    ];
    $dirResults = [];
    foreach ($mkdirs as $k => $p) {
        if (is_dir($p)) {
            $dirResults[$k] = ['path' => $p, 'created_or_exists' => true, 'error' => null];
            continue;
        }
        $ok = @mkdir($p, 0775, true);
        $dirResults[$k] = ['path' => $p, 'created_or_exists' => (bool)$ok || is_dir($p), 'error' => ($ok || is_dir($p)) ? null : 'mkdir_failed'];
    }

    $multica = ['success' => null];
    try {
        require_once __DIR__ . '/../.cue/multica.php';
        $fn = 'mh_multica_provision_tenant';
        if (function_exists($fn)) {
            $multica = (array)call_user_func($fn, $tenantId, $username);
        }
    } catch (Throwable $e) {
        $multica = ['success' => false, 'error' => 'multica_provision_failed'];
    }

    return [
        'success' => true,
        'tenant_id' => $tenantId,
        'dirs' => $dirResults,
        'multica' => $multica,
    ];
}

function mh_tenant_safe(string $tenantId): string {
    $safe = preg_replace('/[^a-zA-Z0-9:_-]/', '_', $tenantId);
    $safe = preg_replace('/_+/', '_', (string)$safe);
    return trim((string)$safe, '_');
}

function mh_tenant_delete_dir_recursive(string $dir): bool {
    $allowed = ['/data/tenants/', '/vector/', '/graph/', '/mysql/'];
    $dir = rtrim($dir, '/');
    $okPrefix = false;
    foreach ($allowed as $p) {
        if ($p !== '' && str_starts_with($dir . '/', $p)) {
            $okPrefix = true;
            break;
        }
    }
    if (!$okPrefix) {
        return false;
    }

    $real = realpath($dir);
    $target = ($real !== false) ? $real : $dir;
    if (!is_dir($target)) {
        return false;
    }
    $items = scandir($target);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $target . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            mh_tenant_delete_dir_recursive($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($target);
}

function mh_tenant_db_configs_path(): string {
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $p = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;
    if (!$p) {
        $base = function_exists('getDataPath') ? getDataPath() : dirname(__DIR__, 2) . '/.data';
        $p = rtrim((string)$base, '/') . '/config/db_configs.json';
    }
    return (string)$p;
}

function mh_remove_db_config_id(string $configId): bool {
    $configId = trim($configId);
    if ($configId === '') return false;
    $path = mh_tenant_db_configs_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $decoded = [];
    if (file_exists($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) $decoded = [];
    }
    if (!array_key_exists($configId, $decoded)) {
        return true;
    }
    unset($decoded[$configId]);
    return file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function mh_unregister_tenant_context(string $tenantId): bool {
    $tenantId = trim($tenantId);
    if ($tenantId === '') return false;
    $path = mh_load_tenant_map_path();
    if (!file_exists($path)) return true;
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return true;
    if (!array_key_exists($tenantId, $decoded)) return true;
    unset($decoded[$tenantId]);
    return file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function mh_find_tenant_id_by_db_config_id(string $dbConfigId): ?string {
    $dbConfigId = trim($dbConfigId);
    if ($dbConfigId === '') return null;
    $path = mh_load_tenant_map_path();
    if (!file_exists($path)) return null;
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) return null;
    foreach ($decoded as $tenantId => $row) {
        if (!is_string($tenantId) || !is_array($row)) continue;
        $id = isset($row['db_config_id']) ? trim((string)$row['db_config_id']) : '';
        if ($id !== '' && hash_equals($id, $dbConfigId)) {
            return $tenantId;
        }
    }
    return null;
}

function mh_deprovision_tenant_resources(string $tenantId): array {
    $tenantId = trim($tenantId);
    if ($tenantId === '') return ['success' => false, 'message' => 'tenant_id_required'];

    if (function_exists('cue_autoload')) {
        cue_autoload('database');
        cue_autoload('security');
    }

    $ctxPath = mh_load_tenant_map_path();
    $ctx = null;
    if (file_exists($ctxPath)) {
        $decoded = json_decode((string)@file_get_contents($ctxPath), true);
        if (is_array($decoded) && isset($decoded[$tenantId]) && is_array($decoded[$tenantId])) {
            $ctx = $decoded[$tenantId];
        }
    }

    $dbConfigId = is_array($ctx) && isset($ctx['db_config_id']) ? trim((string)$ctx['db_config_id']) : '';
    if ($dbConfigId === '') {
        $tmp = mh_resolve_tenant_db_config_id($tenantId);
        $dbConfigId = is_string($tmp) ? $tmp : '';
    }
    if ($dbConfigId === '') {
        $dbConfigId = mh_tenant_config_id($tenantId);
    }

    $dbName = is_array($ctx) && isset($ctx['db_name']) ? trim((string)$ctx['db_name']) : '';
    if ($dbName === '') {
        $dbName = mh_tenant_normalize_for_mysql_db($tenantId);
    }

    $adminConfigId = is_array($ctx) && isset($ctx['admin_config_id']) ? trim((string)$ctx['admin_config_id']) : '';
    if ($adminConfigId === '' && $dbConfigId !== '') {
        try {
            $cfgsPath = mh_tenant_db_configs_path();
            $cfgs = file_exists($cfgsPath) ? json_decode((string)@file_get_contents($cfgsPath), true) : null;
            if (is_array($cfgs) && isset($cfgs[$dbConfigId]) && is_array($cfgs[$dbConfigId])) {
                $adminConfigId = trim((string)($cfgs[$dbConfigId]['admin_config_id'] ?? ''));
            }
        } catch (Throwable $e) {}
    }
    if ($adminConfigId === '') {
        $tmp = mh_find_block_provisioner_config_id();
        $adminConfigId = is_string($tmp) ? $tmp : '';
    }

    $deleted = [];
    $warnings = [];

    $vectorPath = is_array($ctx) && isset($ctx['vector_path']) ? (string)$ctx['vector_path'] : '';
    $graphPath = is_array($ctx) && isset($ctx['graph_path']) ? (string)$ctx['graph_path'] : '';
    if ($vectorPath === '' || $graphPath === '') {
        $paths = mh_tenant_storage_paths($tenantId);
        if ($vectorPath === '' && isset($paths['vector_path'])) $vectorPath = (string)$paths['vector_path'];
        if ($graphPath === '' && isset($paths['graph_path'])) $graphPath = (string)$paths['graph_path'];
    }
    if ($vectorPath !== '' && strpos($vectorPath, '/vector/') === 0) {
        if (is_dir($vectorPath)) {
            mh_tenant_delete_dir_recursive($vectorPath);
            $deleted[] = 'vector';
        }
    }
    if ($graphPath !== '' && strpos($graphPath, '/graph/') === 0) {
        if (is_dir($graphPath)) {
            mh_tenant_delete_dir_recursive($graphPath);
            $deleted[] = 'graph';
        }
    }

    $tenantSafe = mh_tenant_safe($tenantId);
    if ($tenantSafe !== '') {
        $tenantRoot = '/data/tenants/' . $tenantSafe;
        if (is_dir($tenantRoot)) {
            mh_tenant_delete_dir_recursive($tenantRoot);
            $deleted[] = 'data';
        }
    }

    $mysqlPaths = [];
    if ($dbName !== '') {
        $mysqlPaths[] = '/mysql/' . $dbName;
    }
    if ($tenantSafe !== '') {
        $mysqlPaths[] = '/mysql/' . $tenantSafe;
        $mysqlPaths[] = '/mysql/tenant_' . $tenantSafe;
    }
    $mysqlPaths = array_values(array_unique(array_filter($mysqlPaths)));
    foreach ($mysqlPaths as $mp) {
        if (strpos($mp, '/mysql/') !== 0) continue;
        if (is_dir($mp)) {
            mh_tenant_delete_dir_recursive($mp);
            $deleted[] = 'mysql_path';
        }
    }

    if ($adminConfigId !== '' && $dbName !== '') {
        try {
            $adminPdo = database_getConnectionById($adminConfigId);
            $mysqlUser = mh_tenant_mysql_username($tenantId);
            try { $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`"); $deleted[] = 'mysql_db'; } catch (Throwable $e) { $warnings[] = 'drop_db:' . $e->getMessage(); }
            try {
                foreach (['localhost', '127.0.0.1'] as $host) {
                    $adminPdo->exec("DROP USER IF EXISTS '{$mysqlUser}'@'{$host}'");
                }
                $adminPdo->exec("FLUSH PRIVILEGES");
                $deleted[] = 'mysql_user';
            } catch (Throwable $e) {
                $warnings[] = 'drop_user:' . $e->getMessage();
            }
        } catch (Throwable $e) {
            $warnings[] = 'admin_connection:' . $e->getMessage();
        }
    }

    if ($dbConfigId !== '') {
        if (mh_remove_db_config_id($dbConfigId)) {
            $deleted[] = 'db_config';
        }
    }
    if (!mh_unregister_tenant_context($tenantId)) {
        $warnings[] = 'tenant_context_unlink_failed';
    } else {
        $deleted[] = 'tenant_context';
    }

    return [
        'success' => true,
        'tenant_id' => $tenantId,
        'db_config_id' => $dbConfigId !== '' ? $dbConfigId : null,
        'db_name' => $dbName !== '' ? $dbName : null,
        'admin_config_id' => $adminConfigId !== '' ? $adminConfigId : null,
        'deleted' => array_values(array_unique($deleted)),
        'warnings' => $warnings,
    ];
}

function mh_deprovision_tenant_by_db_config_id(string $dbConfigId): array {
    $tenantId = mh_find_tenant_id_by_db_config_id($dbConfigId);
    if (!$tenantId) {
        return ['success' => false, 'message' => 'tenant_not_found_for_config', 'db_config_id' => $dbConfigId];
    }
    return mh_deprovision_tenant_resources($tenantId);
}
