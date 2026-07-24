<?php
require_once __DIR__ . '/cue.php';

// Configuration constants
define('JSON_GLOBAL_UNIQUENESS', true); // Enforce global uniqueness by default
define('JSON_BACKUP_PATH', (is_dir('/backup/backups') ? '/backup/backups/json/' : (getDataPath() . '/sync/backups/')));
define('JSON_AUDIT_INTERVAL', 300); // 5 minutes default for auto-audit
// Caching removed to improve performance

function json_registryPath(): string {
    return getSecureFilePath('config/json_keys_registry.json', true);
}

function json_generateUlid(): string {
    $time = (int) floor(microtime(true) * 1000);
    $time32 = strtoupper(base_convert($time, 10, 32));
    $rand = random_bytes(10);
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $out = '';
    $bits = 0;
    $value = 0;
    for ($i = 0; $i < strlen($rand); $i++) {
        $value = ($value << 8) | ord($rand[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= $alphabet[($value >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= $alphabet[($value << (5 - $bits)) & 31];
    }
    return $time32 . $out;
}

function json_error(string $code, string $message, array $details = []): array {
    return ['success' => false, 'error_code' => $code, 'message' => $message, 'details' => $details];
}

function json_success(array $data = []): array {
    $data['success'] = true;
    return $data;
}

function json_loadRegistry(): array {
    $path = json_registryPath();
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_saveRegistry(array $registry): array {
    $path = json_registryPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp';
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        return json_error('write_failed', 'Unable to open temp file', ['path' => $tmp]);
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return json_error('write_failed', 'Unable to lock temp file', ['path' => $tmp]);
    }
    $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_error('write_failed', 'JSON encoding failed');
    }
    $wrote = fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($wrote === false) {
        return json_error('write_failed', 'Unable to write registry');
    }
    if (!rename($tmp, $path)) {
        return json_error('write_failed', 'Unable to replace registry', ['from' => $tmp, 'to' => $path]);
    }
    if (function_exists('setSecureFilePermissions')) {
        call_user_func('setSecureFilePermissions', $path);
    }
    
    // Create backup
    $backupDir = JSON_BACKUP_PATH;
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $backupPath = $backupDir . 'json_keys_registry_' . date('Y-m-d_H-i-s') . '.json';
    if (copy($path, $backupPath)) {
        // Keep only last 10 backups
        $backups = glob($backupDir . 'json_keys_registry_*.json');
        if (count($backups) > 10) {
            usort($backups, function($a, $b) { return filemtime($b) - filemtime($a); });
            foreach (array_slice($backups, 10) as $old) {
                unlink($old);
            }
        }
    }
    
    return json_success(['path' => $path, 'backup' => $backupPath]);
}

function json_buildGlobalKey(string $entity, string $attribute): string {
    return 'K::' . $entity . '::' . $attribute . '::' . json_generateUlid();
}

function json_prefixExists(array $registry, string $entity, string $attribute): bool {
    foreach ($registry as $k => $meta) {
        if (($meta['entity'] ?? '') === $entity && ($meta['attribute'] ?? '') === $attribute) {
            return true;
        }
    }
    return false;
}

function json_isKeyUnique(string $globalKey): bool {
    $registry = json_loadRegistry();
    return !isset($registry[$globalKey]);
}

function json_registerKey(string $globalKey, array $meta): array {
    $registry = json_loadRegistry();
    if (isset($registry[$globalKey])) {
        return json_error('duplicate_key', 'Global key already registered', ['key' => $globalKey, 'existing_meta' => $registry[$globalKey]]);
    }
    $meta['registered_at'] = date('c');
    $registry[$globalKey] = $meta;
    $res = json_saveRegistry($registry);
    if (!($res['success'] ?? false)) {
        return $res;
    }
    return json_success(['key' => $globalKey]);
}

function json_generateKey(string $entity, string $attribute, array $meta = []): array {
    $registry = json_loadRegistry();
    if (json_prefixExists($registry, $entity, $attribute)) {
        foreach ($registry as $k => $m) {
            if (($m['entity'] ?? '') === $entity && ($m['attribute'] ?? '') === $attribute) {
                return json_error('duplicate_key', 'Prefix already registered', ['key' => $k, 'existing_meta' => $m]);
            }
        }
        return json_error('duplicate_key', 'Prefix already registered');
    }
    $key = json_buildGlobalKey($entity, $attribute);
    $entry = array_merge($meta, ['entity' => $entity, 'attribute' => $attribute, 'created_at' => date('c')]);
    return json_registerKey($key, $entry);
}

function json_atomicWrite(string $absolutePath, string $content): array {
    $dir = dirname($absolutePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = $absolutePath . '.tmp';
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        return json_error('write_failed', 'Unable to open temp file', ['path' => $tmp]);
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return json_error('write_failed', 'Unable to lock temp file', ['path' => $tmp]);
    }
    $wrote = fwrite($fp, $content);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($wrote === false) {
        return json_error('write_failed', 'Unable to write file');
    }
    if (!rename($tmp, $absolutePath)) {
        return json_error('write_failed', 'Unable to replace file', ['from' => $tmp, 'to' => $absolutePath]);
    }
    if (function_exists('setSecureFilePermissions')) {
        call_user_func('setSecureFilePermissions', $absolutePath);
    }
    return json_success(['path' => $absolutePath]);
}

function json_validateObjectKeys(array $objectData, string $heading, array &$conflicts = []): bool {
    $registry = json_loadRegistry();
    $ok = true;
    foreach ($objectData as $attribute => $value) {
        if (JSON_GLOBAL_UNIQUENESS) {
            // Check global uniqueness across all entities
            foreach ($registry as $k => $m) {
                if (($m['attribute'] ?? '') === (string) $attribute) {
                    $conflicts[] = ['key' => $k, 'existing_meta' => $m, 'conflict_type' => 'global'];
                    $ok = false;
                }
            }
        } else {
            // Original: per entity uniqueness
            if (json_prefixExists($registry, $heading, (string) $attribute)) {
                foreach ($registry as $k => $m) {
                    if (($m['entity'] ?? '') === $heading && ($m['attribute'] ?? '') === (string) $attribute) {
                        $conflicts[] = ['key' => $k, 'existing_meta' => $m, 'conflict_type' => 'entity'];
                    }
                }
                $ok = false;
            }
        }
    }
    return $ok;
}

function json_saveEntity(string $relativeFilePath, string $heading, array $objectData, array $options = []): array {
    $abs = getSecureFilePath($relativeFilePath, true);
    if (!is_string($abs) || $abs === '') {
        return json_error('path_invalid', 'Invalid path');
    }
    $existing = [];
    if (file_exists($abs)) {
        $raw = file_get_contents($abs);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    if (isset($existing[$heading])) {
        return json_error('duplicate_key', 'Heading already exists', ['file' => $relativeFilePath, 'heading' => $heading]);
    }
    $conflicts = [];
    if (!json_validateObjectKeys($objectData, $heading, $conflicts)) {
        return json_error('duplicate_key', 'Duplicate attribute detected', ['file' => $relativeFilePath, 'heading' => $heading, 'conflicts' => $conflicts]);
    }
    foreach ($objectData as $attribute => $value) {
        $meta = ['file' => $relativeFilePath, 'heading' => $heading];
        $res = json_generateKey($heading, (string) $attribute, $meta);
        if (!($res['success'] ?? false)) {
            return $res;
        }
    }
    $existing[$heading] = $objectData;
    $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return json_error('write_failed', 'JSON encoding failed');
    }
    $write = json_atomicWrite($abs, $json);
    if (!($write['success'] ?? false)) {
        return $write;
    }
    return json_success(['file' => $relativeFilePath, 'heading' => $heading]);
}

function json_collectAttributesFromFile(string $absolutePath): array {
    $result = [];
    $raw = @file_get_contents($absolutePath);
    if ($raw === false) {
        return $result;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $result;
    }
    foreach ($data as $heading => $obj) {
        if (is_array($obj)) {
            foreach ($obj as $attribute => $val) {
                $result[] = ['heading' => (string) $heading, 'attribute' => (string) $attribute];
            }
        }
    }
    return $result;
}

function json_scanAndRebuildRegistry(array $directories = [], bool $incremental = true): array {
    if (empty($directories)) {
        $directories = json_getAuditPaths();
    }
    $registry = json_loadRegistry();
    $duplicates = [];
    // Caching removed to improve performance
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getFilename(), -5) !== '.json') {
                continue;
            }
            $filePath = $file->getPathname();
            $modTime = $file->getMTime();
            // Cache logic removed to improve performance
            
            $attrs = json_collectAttributesFromFile($filePath);
            foreach ($attrs as $pair) {
                $entity = $pair['heading'];
                $attribute = $pair['attribute'];
                if (json_prefixExists($registry, $entity, $attribute)) {
                    foreach ($registry as $k => $m) {
                        if (($m['entity'] ?? '') === $entity && ($m['attribute'] ?? '') === $attribute) {
                            $duplicates[] = ['key' => $k, 'file_path' => $filePath, 'heading' => $entity, 'existing_meta' => $m];
                        }
                    }
                } else {
                    $key = json_buildGlobalKey($entity, $attribute);
                    $registry[$key] = ['entity' => $entity, 'attribute' => $attribute, 'file' => str_replace(getPublicPath() . '/', '', $filePath), 'heading' => $entity, 'created_at' => date('c'), 'registered_at' => date('c')];
                }
            }
        }
    }

    $save = json_saveRegistry($registry);
    if (!($save['success'] ?? false)) {
        return $save;
    }
    return json_success(['duplicates' => $duplicates, 'count' => count($duplicates)]);
}

function json_assertFileKeysUnique(string $relativeFilePath): array {
    $abs = getSecureFilePath($relativeFilePath, true);
    if (!is_string($abs) || !file_exists($abs)) {
        return json_error('file_not_found', 'File does not exist', ['path' => $relativeFilePath]);
    }
    $registry = json_loadRegistry();
    $attrs = json_collectAttributesFromFile($abs);
    $conflicts = [];
    foreach ($attrs as $pair) {
        foreach ($registry as $k => $m) {
            if (($m['entity'] ?? '') === $pair['heading'] && ($m['attribute'] ?? '') === $pair['attribute']) {
                $conflicts[] = ['key' => $k, 'file_path' => $relativeFilePath, 'heading' => $pair['heading'], 'existing_meta' => $m];
            }
        }
    }
    if (!empty($conflicts)) {
        return json_error('duplicate_keys', 'Duplicate keys found', ['conflicts' => $conflicts]);
    }
    return json_success(['message' => 'File has unique keys', 'attributes_count' => count($attrs)]);
}

function json_getAuditPaths(): array {
    $configFile = getDataPath() . '/config/json_audit_paths.json';
    if (file_exists($configFile)) {
        $raw = file_get_contents($configFile);
        $paths = json_decode($raw, true);
        if (is_array($paths)) {
            return $paths;
        }
    }
    return [getDataPath(), getTemplatesPath()]; // Defaults
}

function json_setAuditPaths(array $paths): array {
    $configFile = getDataPath() . '/config/json_audit_paths.json';
    $dir = dirname($configFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($paths, JSON_PRETTY_PRINT);
    if (file_put_contents($configFile, $json) === false) {
        return json_error('write_failed', 'Unable to save audit paths config');
    }
    return json_success(['paths' => $paths]);
}

function json_startAutoAudit(): array {
    $cronCommand = '*/5 * * * * php ' . __DIR__ . '/json.php audit'; // Every 5 minutes
    $cronFile = '/etc/cron.d/json_audit';
    $content = $cronCommand . "\n";
    if (file_put_contents($cronFile, $content) === false) {
        return json_error('cron_failed', 'Unable to create cron job', ['file' => $cronFile]);
    }
    return json_success(['cron_file' => $cronFile, 'command' => $cronCommand]);
}

// CLI handler for cron
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'audit') {
    $result = json_scanAndRebuildRegistry();
    echo json_encode($result) . "\n";
    exit;
}

function json_findDuplicateKeys(array $directories = []): array {
    if (empty($directories)) {
        $directories = json_getAuditPaths();
    }
    $registry = json_loadRegistry();
    $report = [];
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getFilename(), -5) !== '.json') {
                continue;
            }
            $attrs = json_collectAttributesFromFile($file->getPathname());
            foreach ($attrs as $pair) {
                foreach ($registry as $k => $m) {
                    if (($m['entity'] ?? '') === $pair['heading'] && ($m['attribute'] ?? '') === $pair['attribute']) {
                        $report[] = ['key' => $k, 'file_path' => $file->getPathname(), 'heading' => $pair['heading'], 'existing_meta' => $m];
                    }
                }
            }
        }
    }
    return $report;
}
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'audit') {
    $result = json_scanAndRebuildRegistry();
    echo json_encode($result) . "\n";
    exit;
}

function json_mergeDuplicates(string $key1, string $key2): array {
    $registry = json_loadRegistry();
    if (!isset($registry[$key1]) || !isset($registry[$key2])) {
        return json_error('key_not_found', 'One or both keys not found');
    }
    // Merge metadata, prefer newer
    $merged = array_merge($registry[$key1], $registry[$key2]);
    $merged['merged_at'] = date('c');
    $registry[$key1] = $merged;
    unset($registry[$key2]);
    return json_saveRegistry($registry);
}

function json_removeKey(string $key): array {
    $registry = json_loadRegistry();
    if (!isset($registry[$key])) {
        return json_error('key_not_found', 'Key not found');
    }
    unset($registry[$key]);
    return json_saveRegistry($registry);
}

function json_editKey(string $key, array $updates): array {
    $registry = json_loadRegistry();
    if (!isset($registry[$key])) {
        return json_error('key_not_found', 'Key not found');
    }
    $registry[$key] = array_merge($registry[$key], $updates, ['updated_at' => date('c')]);
    return json_saveRegistry($registry);
}

?>
