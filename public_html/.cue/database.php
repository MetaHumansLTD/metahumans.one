<?php
/**
 * CUE Framework Database Module
 *
 * Database connection, query execution, and context-aware operations.
 * Loaded on-demand to improve performance.
 *
 * Tenancy model (current):
 * - MySQL/MariaDB tenant application data uses DB-per-tenant (tenant_user_* / tenant_persona_*) selected via tenant-contexts routing.
 * - Vector (Qdrant) and Graph (Neo4j) are shared engines and must enforce strict tenant-scoped filtering at the query layer:
 *   - Always include tenant_id, and where applicable meta_human_id/persona_id, on every write and query.
 *
 * @package    CUE Framework
 * @version    75.0.1
 */

// Database Port Definitions
define('CUE_DB_PORT_MYSQL_PRIMARY', 3306); // Legacy/LDAP only
define('CUE_DB_PORT_MYSQL_SECONDARY', 3307); // Block storage/biometrics - AUTHORITATIVE
define('CUE_DB_PORT_QDRANT_HTTP', 6333);
define('CUE_DB_PORT_QDRANT_GRPC', 6334);
define('CUE_DB_PORT_NEO4J_HTTP', 7474);
define('CUE_DB_PORT_NEO4J_BOLT', 7687);

// NLP / Chatbot Ports (Tock)
define('CUE_PORT_TOCK_ADMIN', 3001);
define('CUE_PORT_TOCK_NLP_API', 8888);
define('CUE_PORT_TOCK_DUCKLING', 8000);
define('CUE_PORT_TOCK_KOTLIN_COMPILER', 8081);

// EMERGENCY KILL SWITCH - Set to true to completely disable database operations  
define('CUE_DATABASE_EMERGENCY_DISABLED', false);

if (!defined('CUE_DB_CANONICAL_HOST_BLOCK_MYSQL')) {
    define('CUE_DB_CANONICAL_HOST_BLOCK_MYSQL', '127.0.0.1');
}
if (!defined('CUE_DATABASE_HOST_STRICT')) {
    define('CUE_DATABASE_HOST_STRICT', true);
}
if (!defined('CUE_DATABASE_PORT_STRICT')) {
    define('CUE_DATABASE_PORT_STRICT', true);
}
if (!defined('CUE_DATABASE_PROFILE_ENFORCE')) {
    define('CUE_DATABASE_PROFILE_ENFORCE', true);
}
if (!defined('CUE_DATABASE_CONTEXTS_ALLOW_FLAT')) {
    define('CUE_DATABASE_CONTEXTS_ALLOW_FLAT', false);
}
if (!defined('CUE_DATABASE_HOST_AUDIT_INTERVAL_SECONDS')) {
    define('CUE_DATABASE_HOST_AUDIT_INTERVAL_SECONDS', 300);
}

// Emergency function to check if database operations should be skipped
function database_isEmergencyDisabled(): bool {
    return defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED;
}

function database_normalizeHostForConfig(array $config, bool $audit = false): array {
    $profile = (string)($config['storage_profile'] ?? '');
    $host = (string)($config['host'] ?? '');
    $hostNorm = strtolower(trim($host));

    if ($profile === 'block_mysql') {
        if ($hostNorm === 'localhost') {
            $config['host'] = CUE_DB_CANONICAL_HOST_BLOCK_MYSQL;
            if ($audit) {
                cue_autoload('error')->logInfo('Non-canonical host for block_mysql normalized to 127.0.0.1', [
                    'config_id' => (string)($config['id'] ?? ''),
                    'storage_profile' => $profile,
                    'original_host' => $host,
                    'normalized_host' => $config['host'],
                ]);
            }
        } elseif ($hostNorm !== CUE_DB_CANONICAL_HOST_BLOCK_MYSQL) {
            if ($audit) {
                cue_autoload('error')->logError('Non-canonical host detected for block_mysql configuration', [
                    'config_id' => (string)($config['id'] ?? ''),
                    'storage_profile' => $profile,
                    'host' => $host,
                    'expected_host' => CUE_DB_CANONICAL_HOST_BLOCK_MYSQL,
                ]);
            }
            if (CUE_DATABASE_HOST_STRICT) {
                throw new Exception('block_mysql host must be 127.0.0.1');
            }
        }
    }

    return $config;
}

function database_normalizePortForConfig(array $config, bool $audit = false): array {
    $profile = (string)($config['storage_profile'] ?? '');
    $port = (string)($config['port'] ?? '');
    if ($profile === 'block_mysql') {
        if ($port === '' || $port === (string)CUE_DB_PORT_MYSQL_PRIMARY) {
            $config['port'] = (string)CUE_DB_PORT_MYSQL_SECONDARY;
            if ($audit) {
                cue_autoload('error')->logInfo('Non-canonical port for block_mysql normalized to 3307', [
                    'config_id' => (string)($config['id'] ?? ''),
                    'storage_profile' => $profile,
                    'original_port' => $port,
                    'normalized_port' => $config['port'],
                ]);
            }
        } elseif ($port !== (string)CUE_DB_PORT_MYSQL_SECONDARY) {
            if ($audit) {
                cue_autoload('error')->logError('Non-canonical port detected for block_mysql configuration', [
                    'config_id' => (string)($config['id'] ?? ''),
                    'storage_profile' => $profile,
                    'port' => $port,
                    'expected_port' => (string)CUE_DB_PORT_MYSQL_SECONDARY,
                ]);
            }
            if (CUE_DATABASE_PORT_STRICT) {
                throw new Exception('block_mysql port must be 3307');
            }
        }
    }
    return $config;
}

function database_inferStorageProfile(array $config): string {
    $profile = (string)($config['storage_profile'] ?? '');
    if ($profile !== '') {
        return $profile;
    }
    $port = (string)($config['port'] ?? '');
    if ($port === (string)CUE_DB_PORT_MYSQL_SECONDARY) {
        return 'block_mysql';
    }
    if ($port === (string)CUE_DB_PORT_MYSQL_PRIMARY) {
        return 'whm_mysql';
    }
    return '';
}

function database_applyStorageProfileDefaults(array $config): array {
    if (!isset($config['storage_profile']) || !is_string($config['storage_profile']) || trim($config['storage_profile']) === '') {
        $inferred = database_inferStorageProfile($config);
        if ($inferred !== '') {
            $config['storage_profile'] = $inferred;
        }
    }
    return $config;
}

function database_isBlockRuntimePurpose(array $config): bool {
    $name = (string)($config['name'] ?? '');
    $id = (string)($config['id'] ?? '');
    $ctx = (string)($config['context'] ?? '');
    if (strcasecmp($name, 'biometrics') === 0) return true;
    if ($ctx !== '' && strcasecmp($ctx, 'tenant') === 0) return true;
    if ($id !== '' && (strpos($id, 'tenant_') === 0 || strpos($id, 'tenant:') === 0)) return true;
    if ($name !== '' && (strpos($name, 'tenant:') === 0 || strpos($name, 'tenant_user_') === 0)) return true;
    if (stripos($name, 'equity') !== false) return true;
    return false;
}

function database_validateProfileConstraints(array $config): bool {
    $profile = database_inferStorageProfile($config);
    if ($profile === '') {
        return true;
    }
    $port = (string)($config['port'] ?? '');
    $host = (string)($config['host'] ?? '');

    if ($profile === 'block_mysql') {
        if ($port !== (string)CUE_DB_PORT_MYSQL_SECONDARY) {
            if (CUE_DATABASE_PROFILE_ENFORCE) {
                return false;
            }
            cue_autoload('error')->logError('block_mysql configuration has non-block port', [
                'config_id' => (string)($config['id'] ?? ''),
                'port' => $port,
            ]);
        }
        if (strtolower(trim($host)) !== strtolower(CUE_DB_CANONICAL_HOST_BLOCK_MYSQL)) {
            if (CUE_DATABASE_PROFILE_ENFORCE) {
                return false;
            }
            cue_autoload('error')->logError('block_mysql configuration has non-canonical host', [
                'config_id' => (string)($config['id'] ?? ''),
                'host' => $host,
            ]);
        }
    }

    if ($profile === 'whm_mysql') {
        if ($port !== (string)CUE_DB_PORT_MYSQL_PRIMARY) {
            if (CUE_DATABASE_PROFILE_ENFORCE) {
                return false;
            }
            cue_autoload('error')->logError('whm_mysql configuration has non-WHM port', [
                'config_id' => (string)($config['id'] ?? ''),
                'port' => $port,
            ]);
        }
        if (database_isBlockRuntimePurpose($config)) {
            if (CUE_DATABASE_PROFILE_ENFORCE) {
                return false;
            }
            cue_autoload('error')->logError('whm_mysql configuration violates runtime purpose policy', [
                'config_id' => (string)($config['id'] ?? ''),
                'name' => (string)($config['name'] ?? ''),
                'context' => (string)($config['context'] ?? ''),
            ]);
        }
    }

    if (database_isBlockRuntimePurpose($config) && $profile !== 'block_mysql') {
        return !CUE_DATABASE_PROFILE_ENFORCE;
    }

    return true;
}

function database_getMysqlDataPath(): string {
    return function_exists('paths_getMysqlPath') ? paths_getMysqlPath() : (function_exists('getMysqlDataPath') ? getMysqlDataPath() : '/mysql');
}

function database_getVectorDataPath(): string {
    return function_exists('paths_getVectorPath') ? paths_getVectorPath() : (function_exists('getVectorDataPath') ? getVectorDataPath() : '/vector');
}

function database_getGraphDataPath(): string {
    return function_exists('paths_getGraphPath') ? paths_getGraphPath() : (function_exists('getGraphDataPath') ? getGraphDataPath() : '/graph');
}

/**
 * Check if there are any active databases configured (ignoring emergency state)
 * @return bool True if active databases exist in configuration
 */
function database_hasActiveConfigurations(): bool {
    // Use same path resolution as database_loadConfigurations
    try {
        $configFile = cue_autoload('paths')->getConfigPath() . '/db_configs.json';
    } catch (Exception $e) {
        // Fallback path if paths module not available
        $configFile = '/data/config/db_configs.json';
    }
    
    if (!file_exists($configFile)) {
        return false;
    }
    
    try {
        $allConfigs = json_decode(file_get_contents($configFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($allConfigs)) {
            return false;
        }
        
        foreach ($allConfigs as $configId => $configData) {
            if (isset($configData['is_active']) && $configData['is_active'] === true) {
                return true;
            }
        }
    } catch (Exception $e) {
        return false;
    }
    
    return false;
}

/**
 * Get count of active database configurations
 * @return int Number of active database configurations
 */
function database_getActiveCount(): int {
    // Use same path resolution as database_loadConfigurations
    try {
        $configFile = cue_autoload('paths')->getConfigPath() . '/db_configs.json';
    } catch (Exception $e) {
        // Fallback path if paths module not available
        $configFile = '/data/config/db_configs.json';
    }
    
    if (!file_exists($configFile)) {
        return 0;
    }
    
    try {
        $allConfigs = json_decode(file_get_contents($configFile), true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($allConfigs)) {
            return 0;
        }
        
        $count = 0;
        foreach ($allConfigs as $configId => $configData) {
            if (isset($configData['is_active']) && $configData['is_active'] === true) {
                $count++;
            }
        }
        
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

// -----------------------------------------------------------------------------
// DATABASE CONFIGURATION MANAGEMENT
// -----------------------------------------------------------------------------

function database_resetRejectedConfigurations(): void {
    $GLOBALS['_CUE_DB_REJECTED_CONFIGS'] = [];
}

function database_recordRejectedConfiguration(string $configId, string $reason): void {
    if (!isset($GLOBALS['_CUE_DB_REJECTED_CONFIGS']) || !is_array($GLOBALS['_CUE_DB_REJECTED_CONFIGS'])) {
        $GLOBALS['_CUE_DB_REJECTED_CONFIGS'] = [];
    }
    $GLOBALS['_CUE_DB_REJECTED_CONFIGS'][] = [
        'config_id' => $configId,
        'reason' => $reason,
        'recorded_at' => date('c'),
    ];
}

function database_getRejectedConfigurations(): array {
    $list = $GLOBALS['_CUE_DB_REJECTED_CONFIGS'] ?? [];
    return is_array($list) ? $list : [];
}

/**
 * Load database configurations from secure storage
 * @return array Database configurations keyed by config ID
 */
function database_loadConfigurations(): array {
    // Remove static caching to ensure fresh status detection
    // Static cache was causing stale "active" status when configs changed
    $configurations = [];
    database_resetRejectedConfigurations();
    $configFile = cue_autoload('paths')->getConfigPath() . '/db_configs.json';
    
    // EMERGENCY PERFORMANCE FIX: Quick file check to prevent 45s load times
    if (file_exists($configFile)) {
        $quickCheck = file_get_contents($configFile);
        // Check for both quoted and unquoted true values
        if (strpos($quickCheck, '"is_active": true') === false && 
            strpos($quickCheck, '"is_active":true') === false) {
            // No active databases found - return empty immediately
            return $configurations;
        }
    }

    if (!file_exists($configFile)) {
        cue_autoload('error')->logError('Database configuration file not found', [
            'file' => $configFile,
            'action' => 'database_loadConfigurations'
        ]);
        return $configurations;
    }

    try {
        $allConfigs = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            cue_autoload('error')->logError('Invalid JSON in database config file', [
                'file' => $configFile,
                'error' => json_last_error_msg()
            ]);
            return $configurations;
        }

    // Check if any databases are active before processing (performance optimization)
    $hasActiveDatabase = false;
    foreach ($allConfigs as $configData) {
        // Only consider databases with explicit is_active = true as active
        if (isset($configData['is_active']) && $configData['is_active'] === true) {
            $hasActiveDatabase = true;
            break;
        }
    }
    
    // Early return if no active databases - prevents expensive processing
    if (!$hasActiveDatabase) {
        cue_autoload('error')->logInfo('No active databases found, skipping configuration loading');
        return $configurations;
    }

    static $lastHostAudit = 0;
    $shouldAuditHosts = false;
    $now = time();
    if ($lastHostAudit === 0 || ($now - $lastHostAudit) >= CUE_DATABASE_HOST_AUDIT_INTERVAL_SECONDS) {
        $shouldAuditHosts = true;
        $lastHostAudit = $now;
    }

    // Process each configuration
    foreach ($allConfigs as $configId => $configData) {
        // Only process databases that are explicitly active
        if (!isset($configData['is_active']) || $configData['is_active'] !== true) {
            continue;
        }
        
        // Validate configuration structure
        if (!database_validateConfiguration($configData)) {
            cue_autoload('error')->logError('Invalid database configuration structure', [
                'config_id' => $configId,
                'file' => $configFile
            ]);
            database_recordRejectedConfiguration((string) $configId, 'Invalid configuration structure');
            continue;
        }

        try {
            $configData = database_decryptConfiguration($configData);
            $configData = database_applyStorageProfileDefaults($configData);
            $configData = database_normalizePortForConfig($configData, $shouldAuditHosts);
            $configData = database_normalizeHostForConfig($configData, $shouldAuditHosts);
            if (!database_validateProfileConstraints($configData)) {
                cue_autoload('error')->logError('Rejected database configuration due to profile policy', [
                    'config_id' => $configId,
                    'storage_profile' => (string)($configData['storage_profile'] ?? ''),
                    'host' => (string)($configData['host'] ?? ''),
                    'port' => (string)($configData['port'] ?? ''),
                    'name' => (string)($configData['name'] ?? ''),
                ]);
                database_recordRejectedConfiguration((string) $configId, 'Profile policy violation');
                continue;
            }
        } catch (Exception $e) {
            cue_autoload('error')->logError('Rejected database configuration due to encryption policy', [
                'config_id' => $configId,
                'file' => $configFile,
                'error' => $e->getMessage()
            ]);
            database_recordRejectedConfiguration((string) $configId, $e->getMessage());
            continue;
        }

        $configurations[$configId] = $configData;
    }    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to load database configurations', [
            'file' => $configFile,
            'error' => $e->getMessage()
        ]);
    }

    return $configurations;
}

/**
 * Validate database configuration structure
 * @param array $config Configuration array
 * @return bool True if valid
 */
function database_validateConfiguration(array $config): bool {
    // Check for either 'driver' or 'type' field
    $driver = $config['driver'] ?? $config['type'] ?? '';
    $requiredFields = ['host', 'username'];

    foreach ($requiredFields as $field) {
        if (!isset($config[$field]) || empty($config[$field])) {
            return false;
        }
    }

    // Validate driver/type
    $validDrivers = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];
    if (!in_array(strtolower($driver), $validDrivers)) {
        return false;
    }

    // Validate port if specified
    if (isset($config['port']) && (!is_numeric($config['port']) || $config['port'] < 1 || $config['port'] > 65535)) {
        return false;
    }

    return true;
}

/**
 * Detect if a configuration value looks like it is encrypted (base64 payload)
 * @param string $value Configuration value
 * @return bool True if it looks encrypted
 */
function database_valueLooksEncrypted(string $value): bool {
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    if (strlen($value) < 44) {
        return false;
    }
    return base64_decode($value, true) !== false;
}

function database_getDbConfigEncryptionKey(): string {
    $paths = cue_autoload('paths');
    $keyPath = $paths->getConfigPath() . '/db_key.key';
    if (!file_exists($keyPath)) {
        $keyPath = $paths->getEncryptionKeyPath();
    }
    return file_exists($keyPath) ? trim((string) file_get_contents($keyPath)) : '';
}

function database_decryptConfiguration(array $config): array {
    $security = cue_autoload('security');
    $sensitiveFields = ['password', 'username', 'host', 'database'];

    $needsKey = false;
    foreach ($sensitiveFields as $field) {
        if (!array_key_exists($field, $config) || !is_string($config[$field]) || $config[$field] === '') {
            throw new Exception("Missing required field: {$field}");
        }
        if (database_valueLooksEncrypted($config[$field])) {
            $needsKey = true;
        }
    }

    $encryptionKey = $needsKey ? database_getDbConfigEncryptionKey() : '';
    if ($needsKey && $encryptionKey === '') {
        throw new Exception('Database configuration encryption key not available');
    }

    foreach ($sensitiveFields as $field) {
        if (!database_valueLooksEncrypted($config[$field])) {
            continue;
        }

        $decrypted = $security->decryptValue($config[$field], $encryptionKey);
        if (!is_string($decrypted) || $decrypted === '') {
            throw new Exception("Failed to decrypt database config field: {$field}");
        }
        $config[$field] = $decrypted;
    }

    return $config;
}

/**
 * Get database configuration by ID
 * @param string $configId Configuration identifier
 * @return array|null Configuration array or null if not found
 */
function database_getConfiguration(string $configId): ?array {
    $configurations = database_loadConfigurations();
    
    // Direct ID match
    if (isset($configurations[$configId])) {
        return $configurations[$configId];
    }

    if (is_string($configId) && str_starts_with($configId, 'tenant_')) {
        $tenantCfg = database_tryGetTenantConfigurationFromControlPlane($configId);
        if (is_array($tenantCfg)) {
            return $tenantCfg;
        }
        $fileCfg = database_tryGetConfigurationFromFileById($configId);
        if (is_array($fileCfg)) {
            return $fileCfg;
        }
    }
    
    // Search by name
    foreach ($configurations as $config) {
        if (isset($config['name']) && $config['name'] === $configId) {
            return $config;
        }
    }
    
    return null;
}

function database_getControlPlanePdo(): ?PDO {
    static $pdo = null;
    static $attempted = false;
    if ($attempted) {
        return $pdo instanceof PDO ? $pdo : null;
    }
    $attempted = true;
    try {
        $configs = database_loadConfigurations();
        $cfg = $configs['control_plane'] ?? null;
        if (!is_array($cfg)) {
            return null;
        }
        $pdo = CueDatabasePool::getConnection($cfg);
        return $pdo instanceof PDO ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

function database_tryGetTenantConfigurationFromControlPlane(string $configId): ?array {
    $configId = trim($configId);
    if ($configId === '' || !str_starts_with($configId, 'tenant_')) {
        return null;
    }
    $pdo = database_getControlPlanePdo();
    if (!$pdo) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT config_json FROM mh_control.db_configs WHERE db_config_id = :id LIMIT 1');
        $stmt->execute([':id' => $configId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $json = is_array($row) ? ($row['config_json'] ?? null) : null;
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $cfg = json_decode($json, true);
        if (!is_array($cfg)) {
            return null;
        }
        $cfg['id'] = $configId;
        if (!isset($cfg['is_active'])) {
            $cfg['is_active'] = true;
        }
        return database_decryptConfiguration($cfg);
    } catch (Throwable $e) {
        return null;
    }
}

function database_tryGetConfigurationFromFileById(string $configId): ?array {
    $configId = trim($configId);
    if ($configId === '') {
        return null;
    }
    $configFile = cue_autoload('paths')->getConfigPath() . '/db_configs.json';
    if (!file_exists($configFile)) {
        return null;
    }
    try {
        $allConfigs = json_decode((string)file_get_contents($configFile), true);
        if (!is_array($allConfigs)) {
            return null;
        }
        $cfg = $allConfigs[$configId] ?? null;
        if (!is_array($cfg)) {
            return null;
        }
        $cfg['id'] = $configId;
        return database_decryptConfiguration($cfg);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get admin provisioner config ID associated with a configuration
 * @param string $configId Configuration identifier
 * @return string|null Admin config ID or null if not set
 */
function database_getAdminProvisionerId(string $configId): ?string {
    $cfg = database_getConfiguration($configId);
    if (!is_array($cfg)) return null;
    $adminId = $cfg['admin_config_id'] ?? '';
    return is_string($adminId) && $adminId !== '' ? $adminId : null;
}

/**
 * Get admin provisioner configuration array
 * @param string $configId Configuration identifier to resolve admin for
 * @return array|null Admin configuration or null if not found
 */
function database_getAdminProvisionerConfiguration(string $configId): ?array {
    $adminId = database_getAdminProvisionerId($configId);
    if (!is_string($adminId) || $adminId === '') return null;
    return database_getConfiguration($adminId);
}
/**
 * Get default database configuration
 * @return array|null Default configuration or null if not found
 */
function database_getDefaultConfiguration(): ?array {
    // Try common default config IDs
    $defaultIds = ['default', 'main', 'primary'];

    foreach ($defaultIds as $id) {
        $config = database_getConfiguration($id);
        if ($config !== null) {
            return $config;
        }
    }

    $configurations = database_loadConfigurations();
    if (empty($configurations)) {
        return null;
    }

    $candidates = [];
    foreach ($configurations as $configId => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $ctx = (string)($cfg['context'] ?? '');
        if (strcasecmp($ctx, 'default') === 0) {
            $candidates[$configId] = $cfg;
        }
    }
    if (empty($candidates)) {
        $candidates = $configurations;
    }

    $blockCandidates = [];
    foreach ($candidates as $configId => $cfg) {
        $profile = database_inferStorageProfile($cfg);
        if ($profile === 'block_mysql') {
            $blockCandidates[$configId] = $cfg;
        }
    }
    if (!empty($blockCandidates)) {
        $candidates = $blockCandidates;
    }

    $bestId = null;
    $bestPriority = null;
    foreach ($candidates as $configId => $cfg) {
        $p = $cfg['priority'] ?? null;
        $priority = is_int($p) ? $p : (is_string($p) && preg_match('/^-?\d+$/', $p) ? (int)$p : 0);
        if ($bestId === null || $priority < $bestPriority || ($priority === $bestPriority && strcmp((string)$configId, (string)$bestId) < 0)) {
            $bestId = (string)$configId;
            $bestPriority = $priority;
        }
    }

    return $bestId !== null && isset($candidates[$bestId]) ? $candidates[$bestId] : reset($candidates);
}

// -----------------------------------------------------------------------------
// CONTEXT-AWARE DATABASE SELECTION
// -----------------------------------------------------------------------------

if (!defined('CUE_DATABASE_CONTEXTS_SCHEMA_VERSION')) {
    define('CUE_DATABASE_CONTEXTS_SCHEMA_VERSION', '2.0.0');
}
if (!defined('CUE_DATABASE_CONTEXTS_STRICT')) {
    define('CUE_DATABASE_CONTEXTS_STRICT', true);
}

/**
 * Context-aware database selection based on current request/page context
 * @return array Database configuration for current context
 */
function database_getContextAwareConfiguration(?string $configIdOverride = null): array {
    static $contextConfigs = [];

    $configurations = database_loadConfigurations();
    $activeConfigIds = array_keys($configurations);

    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requestPath === '') {
        $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    $requestPathNorm = database_normalizeContextPath($requestPath);
    $scriptNameNorm = database_normalizeContextPath($scriptName);
    $cacheKey = (is_string($configIdOverride) && $configIdOverride !== '' ? $configIdOverride : '__auto__') . '|' . $scriptNameNorm . '|' . $requestPathNorm;

    if (isset($contextConfigs[$cacheKey])) {
        return $contextConfigs[$cacheKey];
    }

    if (database_isAuthCriticalRequest($requestPath, $scriptName)) {
        $bio = database_getBiometricsConfiguration($configurations);
        if (is_array($bio)) {
            $contextConfigs[$cacheKey] = $bio;
            return $contextConfigs[$cacheKey];
        }
        $msg = 'Auth-critical path requires biometrics database on port 3307, but none is active.';
        cue_autoload('error')->logError($msg, ['path' => $requestPath, 'script' => $scriptName]);
        if (CUE_DATABASE_CONTEXTS_STRICT) {
            throw new Exception($msg);
        }
    }

    $sessionPreferredId = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $pref = $_SESSION['mh_db_preference'] ?? ($_SESSION['current_database_config_id'] ?? null);
        if (is_string($pref) && $pref !== '') {
            $sessionPreferredId = $pref;
        }
    }

    if (is_string($configIdOverride) && $configIdOverride !== '') {
        if (in_array($configIdOverride, $activeConfigIds, true)) {
            $cfg = database_getConfiguration($configIdOverride);
            if (is_array($cfg)) {
                if (database_isConfigAllowedForRequest($cfg, $scriptNameNorm, $requestPathNorm, 'override')) {
                    $contextConfigs[$cacheKey] = $cfg;
                    return $contextConfigs[$cacheKey];
                }
            }
        }
        cue_autoload('error')->logError('Context-aware routing override config is missing or inactive', [
            'config_id' => $configIdOverride,
            'path' => $requestPath,
            'script' => $scriptName,
        ]);
        if (CUE_DATABASE_CONTEXTS_STRICT) {
            throw new Exception('Context-aware routing override config is missing or inactive: ' . $configIdOverride);
        }
    }

    $cfgDir = cue_autoload('paths')->getConfigPath();
    $contextFile = $cfgDir . '/database-context.json';
    if (!file_exists($contextFile)) {
        $contextFile = $cfgDir . '/database-contexts.json';
    }

    if (!file_exists($contextFile)) {
        if (database_isTenantScopedRequest($requestPath, $scriptName) && is_string($sessionPreferredId) && $sessionPreferredId !== '') {
            $cfg = database_getConfiguration($sessionPreferredId);
            if (is_array($cfg) && database_isConfigAllowedForRequest($cfg, $scriptNameNorm, $requestPathNorm, 'session')) {
                $contextConfigs[$cacheKey] = $cfg;
                return $contextConfigs[$cacheKey];
            }
        }
        // Fall back to default configuration
        $contextConfigs[$cacheKey] = database_getDefaultConfiguration() ?? [];
        return $contextConfigs[$cacheKey];
    }

    try {
        $contextData = json_decode(file_get_contents($contextFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            cue_autoload('error')->logError('Invalid JSON in database contexts file', [
                'file' => $contextFile,
                'error' => json_last_error_msg()
            ]);
            $contextConfigs[$cacheKey] = database_getDefaultConfiguration() ?? [];
            return $contextConfigs[$cacheKey];
        }

        // Determine current context
        $currentContext = database_determineCurrentContext();

        $isStructured = is_array($contextData) && (
            array_key_exists('schema_version', $contextData) ||
            array_key_exists('page_mappings', $contextData) ||
            array_key_exists('directory_mappings', $contextData)
        );

        if ($isStructured) {
            $validContexts = database_validateDatabaseContexts($contextData, $activeConfigIds, $contextFile);
            if (!$validContexts) {
                $contextConfigs[$cacheKey] = database_getDefaultConfiguration() ?? [];
                return $contextConfigs[$cacheKey];
            }
            $resolved = database_tryResolveStructuredContextConfig($contextData);
            $configId = is_array($resolved) ? ($resolved['id'] ?? null) : null;
            $source = is_array($resolved) ? (string)($resolved['source'] ?? 'unknown') : 'unknown';
            if (is_string($configId) && $configId !== '') {
                $config = database_getConfiguration($configId);
                if (is_array($config) && database_isConfigAllowedForRequest($config, $scriptNameNorm, $requestPathNorm, $source)) {
                    $contextConfigs[$cacheKey] = $config;
                    return $contextConfigs[$cacheKey];
                }
            }

            $fallback = (string)($contextData['fallback_strategy'] ?? 'session_preference');
            if ($fallback === 'deny') {
                $msg = 'Database contexts deny fallback for unmatched request';
                cue_autoload('error')->logError($msg, ['file' => $contextFile, 'path' => $requestPathNorm, 'script' => $scriptNameNorm]);
                if (CUE_DATABASE_CONTEXTS_STRICT) {
                    throw new Exception($msg);
                }
            }
            if ($fallback === 'session_preference' && is_string($sessionPreferredId) && $sessionPreferredId !== '') {
                $cfg = database_getConfiguration($sessionPreferredId);
                if (is_array($cfg) && database_isConfigAllowedForRequest($cfg, $scriptNameNorm, $requestPathNorm, 'session')) {
                    $contextConfigs[$cacheKey] = $cfg;
                    return $contextConfigs[$cacheKey];
                }
            }
        } else {
            cue_autoload('error')->logError('Non-structured database contexts file rejected', [
                'file' => $contextFile,
            ]);
            if (!CUE_DATABASE_CONTEXTS_ALLOW_FLAT) {
                $contextConfigs[$cacheKey] = database_getDefaultConfiguration() ?? [];
                return $contextConfigs[$cacheKey];
            }
            foreach ($contextData as $context => $configId) {
                if (!is_string($context) || !is_string($configId)) {
                    continue;
                }
                if (database_matchesContext($currentContext, $context)) {
                    $config = database_getConfiguration($configId);
                    if (is_array($config) && database_isConfigAllowedForRequest($config, $scriptNameNorm, $requestPathNorm, 'flat')) {
                        $contextConfigs[$cacheKey] = $config;
                        return $contextConfigs[$cacheKey];
                    }
                }
            }
        }

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to load database context configuration', [
            'file' => $contextFile,
            'error' => $e->getMessage()
        ]);
    }

    if (database_isTenantScopedRequest($requestPath, $scriptName)) {
        if (is_string($sessionPreferredId) && $sessionPreferredId !== '') {
            $cfg = database_getConfiguration($sessionPreferredId);
            if (is_array($cfg) && database_isConfigAllowedForRequest($cfg, $scriptNameNorm, $requestPathNorm, 'session')) {
                $contextConfigs[$cacheKey] = $cfg;
                return $contextConfigs[$cacheKey];
            }
        }
        if (CUE_DATABASE_CONTEXTS_STRICT && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['mh_auth_user'])) {
            throw new Exception('Tenant DB context required but no session preference is set');
        }
    }

    // Fall back to default
    $contextConfigs[$cacheKey] = database_getDefaultConfiguration() ?? [];
    return $contextConfigs[$cacheKey];
}

function database_isTenantScopedRequest(string $requestPath, string $scriptName): bool {
    $p = database_normalizeContextPath($requestPath);
    $s = database_normalizeContextPath($scriptName);
    $prefixes = ['/hub', '/studio'];
    foreach ($prefixes as $prefix) {
        $prefixNorm = database_normalizeContextPath($prefix);
        if ($p !== '' && strpos($p, $prefixNorm) === 0) return true;
        if ($s !== '' && strpos($s, $prefixNorm) === 0) return true;
    }
    return false;
}

function database_isWhmAllowedContext(string $scriptNameNorm, string $requestPathNorm): bool {
    $allowedPrefixes = ['/gear/settings/whm-'];
    foreach ($allowedPrefixes as $prefix) {
        $prefixNorm = database_normalizeContextPath($prefix);
        if ($prefixNorm === '') continue;
        if ($scriptNameNorm !== '' && strpos($scriptNameNorm, $prefixNorm) === 0) return true;
        if ($requestPathNorm !== '' && strpos($requestPathNorm, $prefixNorm) === 0) return true;
    }
    return false;
}

function database_isConfigAllowedForRequest(array $config, string $scriptNameNorm, string $requestPathNorm, string $source): bool {
    $profile = database_inferStorageProfile($config);
    if ($profile !== 'whm_mysql') {
        return true;
    }
    if (!database_isWhmAllowedContext($scriptNameNorm, $requestPathNorm)) {
        cue_autoload('error')->logError('Blocked whm_mysql selection outside allowed context', [
            'config_id' => (string)($config['id'] ?? ''),
            'name' => (string)($config['name'] ?? ''),
            'source' => $source,
            'script' => $scriptNameNorm,
            'path' => $requestPathNorm,
        ]);
        return false;
    }
    if ($source === 'session') {
        cue_autoload('error')->logError('Blocked whm_mysql selection via session preference', [
            'config_id' => (string)($config['id'] ?? ''),
            'name' => (string)($config['name'] ?? ''),
            'script' => $scriptNameNorm,
            'path' => $requestPathNorm,
        ]);
        return false;
    }
    return true;
}

function database_isAuthCriticalRequest(string $requestPath, string $scriptName): bool {
    $p = database_normalizeContextPath($requestPath);
    $s = database_normalizeContextPath($scriptName);
    $prefixes = ['/auth', '/control'];
    foreach ($prefixes as $prefix) {
        $prefixNorm = database_normalizeContextPath($prefix);
        if ($p !== '' && strpos($p, $prefixNorm) === 0) {
            return true;
        }
        if ($s !== '' && strpos($s, $prefixNorm) === 0) {
            return true;
        }
    }
    return false;
}

function database_isAllowedBiometricsTenantUse(string $requestPath, string $scriptName): bool {
    $p = database_normalizeContextPath($requestPath);
    $s = database_normalizeContextPath($scriptName);
    static $allowedExact = null;
    if ($allowedExact === null) {
        $allowedExact = [];
        $cfgDir = '/data/config';
        try {
            $paths = cue_autoload('paths');
            $p0 = is_object($paths) && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '';
            if ($p0 !== '') $cfgDir = $p0;
        } catch (Throwable $e) {}
        $cfgDir = rtrim($cfgDir, '/');
        $file = $cfgDir . '/biometrics-tenant-allowlist.json';
        if (is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $items = $decoded['allowed_exact'] ?? $decoded['allowed_paths'] ?? null;
                if (is_array($items)) {
                    foreach ($items as $k => $v) {
                        if (is_string($v)) {
                            $path = database_normalizeContextPath($v);
                            if ($path !== '') $allowedExact[$path] = true;
                            continue;
                        }
                        if (is_string($k)) {
                            $path = database_normalizeContextPath($k);
                            if ($path !== '' && ($v === true || $v === 1 || $v === '1')) $allowedExact[$path] = true;
                        }
                    }
                }
            }
        }
    }

    if ($p !== '' && isset($allowedExact[$p])) return true;
    if ($s !== '' && isset($allowedExact[$s])) return true;
    return false;
}

function database_getBiometricsConfiguration(array $configurations): ?array {
    foreach ($configurations as $id => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        $name = (string)($cfg['name'] ?? '');
        $port = (string)($cfg['port'] ?? '');
        if (strcasecmp($name, 'biometrics') !== 0) {
            continue;
        }
        if ($port === (string)CUE_DB_PORT_MYSQL_SECONDARY && database_inferStorageProfile($cfg) === 'block_mysql') {
            return $cfg;
        }
    }
    return null;
}

function database_validateDatabaseContexts(array $contextData, array $activeConfigIds, string $contextFile): bool {
    $schema = $contextData['schema_version'] ?? null;
    $schemaStr = is_string($schema) ? trim($schema) : '';
    if ($schemaStr === '' || $schemaStr !== CUE_DATABASE_CONTEXTS_SCHEMA_VERSION) {
        $msg = 'Database contexts schema mismatch: expected ' . CUE_DATABASE_CONTEXTS_SCHEMA_VERSION . ', got ' . ($schemaStr !== '' ? $schemaStr : 'missing');
        cue_autoload('error')->logError($msg, ['file' => $contextFile]);
        if (CUE_DATABASE_CONTEXTS_STRICT) {
            throw new Exception($msg);
        }
        return false;
    }

    $errors = [];
    $check = function ($configId, $key) use (&$errors, $activeConfigIds) {
        if (!is_string($configId) || $configId === '') {
            $errors[] = ['key' => $key, 'reason' => 'empty_config_id'];
            return;
        }
        if (!in_array($configId, $activeConfigIds, true)) {
            $errors[] = ['key' => $key, 'reason' => 'missing_or_inactive', 'config_id' => $configId];
        }
    };

    if (array_key_exists('page_mappings', $contextData) && !is_array($contextData['page_mappings'])) {
        $errors[] = ['key' => 'page_mappings', 'reason' => 'invalid_type'];
    }
    if (array_key_exists('directory_mappings', $contextData) && !is_array($contextData['directory_mappings'])) {
        $errors[] = ['key' => 'directory_mappings', 'reason' => 'invalid_type'];
    }
    if (array_key_exists('auto_switch', $contextData) && !is_bool($contextData['auto_switch'])) {
        $errors[] = ['key' => 'auto_switch', 'reason' => 'invalid_type'];
    }
    if (array_key_exists('fallback_strategy', $contextData) && !is_string($contextData['fallback_strategy'])) {
        $errors[] = ['key' => 'fallback_strategy', 'reason' => 'invalid_type'];
    }

    if (isset($contextData['page_mappings']) && is_array($contextData['page_mappings'])) {
        foreach ($contextData['page_mappings'] as $page => $configId) {
            $check($configId, 'page_mappings:' . (is_string($page) ? $page : 'unknown'));
        }
    }
    if (isset($contextData['directory_mappings']) && is_array($contextData['directory_mappings'])) {
        foreach ($contextData['directory_mappings'] as $dir => $configId) {
            $check($configId, 'directory_mappings:' . (is_string($dir) ? $dir : 'unknown'));
        }
    }

    if (!empty($errors)) {
        cue_autoload('error')->logError('Database contexts referential integrity violations', [
            'file' => $contextFile,
            'violations' => $errors,
        ]);
        if (CUE_DATABASE_CONTEXTS_STRICT) {
            throw new Exception('Database contexts referential integrity violations');
        }
        return false;
    }
    return true;
}

/**
 * Determine current request context
 * @return array Context information
 */
function database_determineCurrentContext(): array {
    $context = [
        'page' => basename($_SERVER['PHP_SELF'] ?? '', '.php'),
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'session_id' => session_id(),
        'time' => time()
    ];

    // Add additional context based on path
    if (strpos($context['path'], '/admin/') === 0) {
        $context['area'] = 'admin';
    } elseif (strpos($context['path'], '/api/') === 0) {
        $context['area'] = 'api';
    } elseif (strpos($context['path'], '/public/') === 0) {
        $context['area'] = 'public';
    } else {
        $context['area'] = 'default';
    }

    return $context;
}

function database_normalizeContextPath(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path) ?: $path;
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    return $path;
}

function database_selectContextConfigIdByPage(array $pageMappings, array $candidates): ?string {
    foreach ($candidates as $candidate) {
        if (isset($pageMappings[$candidate]) && is_string($pageMappings[$candidate]) && $pageMappings[$candidate] !== '') {
            return $pageMappings[$candidate];
        }
    }
    return null;
}

function database_selectContextConfigIdByDirectory(array $directoryMappings, array $candidates): ?string {
    $bestMatch = null;
    $bestLen = -1;
    foreach ($directoryMappings as $prefix => $configId) {
        if (!is_string($prefix) || !is_string($configId) || $configId === '') {
            continue;
        }
        $prefixNorm = database_normalizeContextPath($prefix);
        if ($prefixNorm === '') {
            continue;
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && strpos($candidate, $prefixNorm) === 0) {
                $len = strlen($prefixNorm);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestMatch = $configId;
                }
                break;
            }
        }
    }
    return $bestMatch;
}

function database_tryResolveStructuredContextConfig(array $contextData): array {
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($requestPath === '') {
        $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));

    $requestPathNorm = database_normalizeContextPath($requestPath);
    $scriptNameNorm = database_normalizeContextPath($scriptName);
    $candidates = array_values(array_filter([$scriptNameNorm, $requestPathNorm], function ($v) { return is_string($v) && $v !== ''; }));

    $autoSwitch = $contextData['auto_switch'] ?? true;
    if ($autoSwitch === false) {
        return ['id' => null, 'source' => 'disabled'];
    }

    $pageMappings = $contextData['page_mappings'] ?? null;
    if (is_array($pageMappings)) {
        $id = database_selectContextConfigIdByPage($pageMappings, $candidates);
        if ($id !== null) {
            return ['id' => $id, 'source' => 'page'];
        }
    }

    $directoryMappings = $contextData['directory_mappings'] ?? null;
    if (is_array($directoryMappings)) {
        $id = database_selectContextConfigIdByDirectory($directoryMappings, $candidates);
        if ($id !== null) {
            return ['id' => $id, 'source' => 'directory'];
        }
    }

    $fallback = (string)($contextData['fallback_strategy'] ?? 'session_preference');
    if ($fallback === 'session_preference' && session_status() === PHP_SESSION_ACTIVE) {
        $pref = $_SESSION['mh_db_preference'] ?? ($_SESSION['mh_db_config_preference'] ?? null);
        if (is_string($pref) && $pref !== '') {
            return ['id' => $pref, 'source' => 'session'];
        }
    }

    return ['id' => null, 'source' => 'none'];
}

/**
 * Check if current context matches a context pattern
 * @param array $currentContext Current context
 * @param string $contextPattern Context pattern to match
 * @return bool True if matches
 */
function database_matchesContext(array $currentContext, string $contextPattern): bool {
    // Simple pattern matching - can be extended for more complex rules
    $patterns = explode(',', $contextPattern);

    foreach ($patterns as $pattern) {
        $pattern = trim($pattern);

        // Check for area matches
        if (isset($currentContext['area']) && $currentContext['area'] === $pattern) {
            return true;
        }

        // Check for page matches
        if (isset($currentContext['page']) && $currentContext['page'] === $pattern) {
            return true;
        }

        // Check for path patterns
        if (strpos($currentContext['path'], $pattern) !== false) {
            return true;
        }
    }

    return false;
}

// -----------------------------------------------------------------------------
// DATABASE CONNECTION MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * High-performance database connection pool with automatic cleanup
 */
class CueDatabasePool {
    private static $connections = [];
    private static $lastCleanup = 0;
    private static $cleanupInterval = 300; // 5 minutes
    private static $maxIdleTime = 600; // 10 minutes
    private static $maxConnections = 10; // Maximum connections per config

    /**
     * Get database connection from pool or create new one
     * @param array $config Database configuration
     * @return PDO Database connection
     */
    public static function getConnection(array $config): PDO {
        $configId = database_getConfigId($config);

        // Check for existing connection
        if (isset(self::$connections[$configId])) {
            $connection = self::$connections[$configId];

            // Check if connection is still valid
            if (self::isConnectionValid($connection)) {
                return $connection;
            } else {
                // Remove invalid connection
                unset(self::$connections[$configId]);
            }
        }

        // Check connection limit
        if (count(self::$connections) >= self::$maxConnections) {
            self::cleanupExpiredConnections();
        }

        // Create new connection
        $connection = self::createConnection($config);
        self::$connections[$configId] = $connection;

        return $connection;
    }

    /**
     * Create new database connection
     * @param array $config Database configuration
     * @return PDO Database connection
     */
    private static function createConnection(array $config): PDO {
        try {
            // Rate limiting enabled to prevent connection exhaustion
            // Limit: 60 connections per minute per IP for database operations
            try {
                cue_autoload('security')->checkRateLimit('db_connection', ['max' => 60, 'window' => 60]);
            } catch (Exception $e) {
                 cue_autoload('error')->logError('Database connection rate limit exceeded', [
                    'config_id' => database_getConfigId($config),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                throw $e;
            }

            try {
                $config = database_applyStorageProfileDefaults($config);
                $config = database_normalizePortForConfig($config, false);
                $config = database_normalizeHostForConfig($config, false);
            } catch (Exception $e) {
                cue_autoload('error')->logError('Database host policy violation', [
                    'config_id' => database_getConfigId($config),
                    'host' => $config['host'] ?? null,
                    'storage_profile' => $config['storage_profile'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $dsn = self::buildDSN($config);
            $options = self::getConnectionOptions($config);
            try {
                $connection = new PDO($dsn, $config['username'], $config['password'], $options);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $port = (string)($config['port'] ?? '');
                $lastException = $e;

                if (!isset($connection)) {
                    if (self::isUnknownDatabaseError($lastException) && self::shouldAttemptCreateDatabase($config)) {
                        try {
                            if (self::createDatabaseIfMissing($config)) {
                                $connection = new PDO($dsn, $config['username'], $config['password'], $options);
                            } else {
                                throw $lastException;
                            }
                        } catch (Throwable $e2) {
                            throw $lastException;
                        }
                    } else {
                        throw $lastException;
                    }
                }
            }

            // Set connection attributes
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            // Reduced logging for performance - only log errors

            return $connection;

        } catch (PDOException $e) {
            cue_autoload('error')->logError('Database connection failed', [
                'config_id' => database_getConfigId($config),
                'host' => $config['host'],
                'database' => (string)($config['name'] ?? '') === 'biometrics' ? '[redacted]' : $config['database'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private static function isUnknownDatabaseError(PDOException $e): bool {
        $info = $e->errorInfo ?? null;
        if (is_array($info) && isset($info[1]) && (int)$info[1] === 1049) {
            return true;
        }
        return stripos($e->getMessage(), 'Unknown database') !== false;
    }

    private static function shouldAttemptCreateDatabase(array $config): bool {
        $db = (string)($config['database'] ?? '');
        if ($db === '') return false;
        $driver = strtolower((string)($config['driver'] ?? $config['type'] ?? 'mysql'));
        if ($driver === 'mariadb') $driver = 'mysql';
        if ($driver !== 'mysql') return false;
        $profile = database_inferStorageProfile($config);
        if ($profile === 'block_mysql') return true;
        return (string)($config['name'] ?? '') === 'biometrics';
    }

    private static function createDatabaseIfMissing(array $config): bool {
        $dbName = (string)($config['database'] ?? '');
        if ($dbName === '') return false;
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (string)($config['port'] ?? '');
        $charset = (string)($config['charset'] ?? 'utf8mb4');

        $dsn = "mysql:host={$host}";
        if ($port !== '') $dsn .= ";port={$port}";
        if ($charset !== '') $dsn .= ";charset={$charset}";

        $options = self::getConnectionOptions($config);
        try {
            $pdo = new PDO($dsn, (string)($config['username'] ?? ''), (string)($config['password'] ?? ''), $options);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $idq = str_replace('`', '``', $dbName);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$idq}` CHARACTER SET {$charset}");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Build DSN string from configuration
     * @param array $config Database configuration
     * @return string DSN string
     */
    private static function buildDSN(array $config): string {
        $driver = strtolower($config['driver'] ?? $config['type'] ?? '');
        $host = $config['host'];
        $database = $config['database'];
        $port = $config['port'] ?? null;

        // Handle MariaDB as MySQL
        if ($driver === 'mariadb') {
            $driver = 'mysql';
        }

        switch ($driver) {
            case 'mysql':
                $dsn = "mysql:host={$host}";
                if (is_string($database) && $database !== '') {
                    $dsn .= ";dbname={$database}";
                }
                if ($port) $dsn .= ";port={$port}";
                if (isset($config['charset'])) $dsn .= ";charset={$config['charset']}";
                break;

            case 'pgsql':
                $dsn = "pgsql:host={$host};dbname={$database}";
                if ($port) $dsn .= ";port={$port}";
                break;

            case 'sqlite':
                $dsn = "sqlite:{$database}";
                break;

            case 'sqlsrv':
                $dsn = "sqlsrv:Server={$host}";
                if ($port) $dsn .= ",{$port}";
                $dsn .= ";Database={$database}";
                break;

            default:
                throw new Exception("Unsupported database driver: {$driver}");
        }

        return $dsn;
    }

    /**
     * Get connection options based on driver
     * @param array $config Database configuration
     * @return array PDO options
     */
    private static function getConnectionOptions(array $config): array {
        $driver = strtolower($config['driver'] ?? $config['type'] ?? 'mysql');
        $options = [];
        $timeout = isset($config['timeout']) ? (int)$config['timeout'] : 3;
        $timeout = max(1, min(15, $timeout));
        $options[PDO::ATTR_TIMEOUT] = $timeout;

        switch ($driver) {
            case 'mysql':
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
                $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
                break;

            case 'pgsql':
                $options[PDO::PGSQL_ATTR_DISABLE_PREPARES] = false;
                break;
        }

        // Add SSL options if configured
        if (isset($config['ssl']) && $config['ssl']) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'] ?? null;
            $options[PDO::MYSQL_ATTR_SSL_CERT] = $config['ssl_cert'] ?? null;
            $options[PDO::MYSQL_ATTR_SSL_KEY] = $config['ssl_key'] ?? null;
        }

        return $options;
    }

    /**
     * Check if connection is still valid
     * @param PDO $connection Database connection
     * @return bool True if valid
     */
    private static function isConnectionValid(PDO $connection): bool {
        try {
            // Simple query to test connection
            $stmt = $connection->query('SELECT 1');
            return $stmt !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Clean up expired connections
     */
    private static function cleanupExpiredConnections(): void {
        $currentTime = time();
        $cleaned = 0;

        foreach (self::$connections as $configId => $connection) {
            // Check if connection has been idle too long
            // Note: PDO doesn't provide last activity time, so we use a simple cleanup
            try {
                if (!self::isConnectionValid($connection)) {
                    unset(self::$connections[$configId]);
                    $cleaned++;
                }
            } catch (Exception $e) {
                unset(self::$connections[$configId]);
                $cleaned++;
            }
        }

        self::$lastCleanup = $currentTime;

        if ($cleaned > 0) {
            cue_autoload('error')->logInfo("Cleaned up {$cleaned} expired database connections");
        }
    }

    /**
     * Get connection pool statistics
     * @return array Statistics
     */
    public static function getStats(): array {
        return [
            'active_connections' => count(self::$connections),
            'max_connections' => self::$maxConnections,
            'last_cleanup' => self::$lastCleanup,
            'cleanup_interval' => self::$cleanupInterval
        ];
    }

    /**
     * Close all connections (for shutdown)
     */
    public static function closeAll(): void {
        foreach (self::$connections as $connection) {
            try {
                $connection = null; // Close connection
            } catch (Exception $e) {
                // Ignore errors during shutdown
            }
        }
        self::$connections = [];
    }
}

/**
 * Get database connection by configuration ID
 * @param string $configId Configuration identifier
 * @return PDO Database connection
 */
function database_getConnectionById(string $configId): PDO {
    if (database_isEmergencyDisabled()) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    
    // Check if any active databases exist first (performance optimization)
    if (!database_hasActiveConfigurations()) {
        throw new Exception("No active database configurations available - all databases are currently inactive");
    }

    $config = database_getConfiguration($configId);

    if ($config === null) {
        throw new Exception("Database configuration not found: {$configId}");
    }

    $name = (string)($config['name'] ?? '');
    if (strcasecmp($name, 'biometrics') === 0) {
        $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($requestPath === '') {
            $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
        }
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
        if (database_isTenantScopedRequest($requestPath, $scriptName) && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['mh_auth_user'])) {
            if (!database_isAllowedBiometricsTenantUse($requestPath, $scriptName)) {
                throw new Exception("Blocked biometrics database usage in tenant-scoped context");
            }
        }
    }

    return CueDatabasePool::getConnection($config);
}

/**
 * Get database connection from a validated configuration array
 * Intended for internal tooling flows that need ephemeral configs (e.g., admin/no-db).
 * @param array $config Database configuration array
 * @return PDO Database connection
 */
function database_getConnectionFromConfig(array $config): PDO {
    if (database_isEmergencyDisabled()) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    if (!database_validateConfiguration($config)) {
        throw new Exception("Invalid database configuration structure");
    }
    return CueDatabasePool::getConnection($config);
}

/**
 * Get default database connection
 * @return PDO Database connection
 */
function database_getConnection(): PDO {
    if (database_isEmergencyDisabled()) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    
    // Check if any active databases exist first (performance optimization) 
    if (!database_hasActiveConfigurations()) {
        throw new Exception("No active database configurations available - all databases are currently inactive");
    }
    
    $configurations = database_loadConfigurations();
    if (empty($configurations)) {
        throw new Exception("No active database configurations loaded");
    }

    $config = database_getDefaultConfiguration();

    if ($config === null) {
        throw new Exception("No default database configuration found");
    }

    return CueDatabasePool::getConnection($config);
}

/**
 * Get context-aware database connection
 * @return PDO Database connection
 */
function database_getContextAwareConnection(?string $configIdOverride = null): PDO {
    if (database_isEmergencyDisabled()) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    
    if (!database_hasActiveConfigurations()) {
        throw new Exception("No active database configurations available - all databases are currently inactive");
    }
    
    $config = database_getContextAwareConfiguration($configIdOverride);

    if (empty($config)) {
        throw new Exception("No context-aware database configuration available");
    }

    try {
        return CueDatabasePool::getConnection($config);
    } catch (PDOException $e) {
        try {
            cue_autoload('error')->logError('Context-aware DB connection failed; falling back to biometrics', [
                'error' => $e->getMessage(),
                'config_id_override' => $configIdOverride
            ]);
        } catch (Throwable $t) {}
        if ($configIdOverride === null) {
            try {
                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }
                $tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
                if ($tenantId !== '' && !function_exists('mh_apply_tenant_context')) {
                    $p = __DIR__ . '/../auth/tenant_provisioning.php';
                    if (is_file($p)) {
                        require_once $p;
                    }
                }
                if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
                    mh_apply_tenant_context($tenantId);
                    $cfg2 = database_getContextAwareConfiguration(null);
                    if (is_array($cfg2) && !empty($cfg2)) {
                        return CueDatabasePool::getConnection($cfg2);
                    }
                }
            } catch (Throwable $t) {}
            try {
                $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
                if ($requestPath === '') {
                    $requestPath = (string) ($_SERVER['REQUEST_URI'] ?? '');
                }
                $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
                if (database_isAuthCriticalRequest($requestPath, $scriptName)) {
                    return database_getConnectionById('biometrics');
                }
            } catch (Throwable $t) {}
        }
        throw $e;
    }
}

/**
 * Generate unique configuration ID from config array
 * @param array $config Database configuration
 * @return string Configuration ID
 */
function database_getConfigId(array $config): string {
    // Create a hash of the essential connection parameters
    $driver = $config['driver'] ?? $config['type'] ?? 'mysql';
    $keyData = [
        $driver,
        $config['host'],
        $config['database'],
        $config['username']
    ];

    return md5(implode('|', $keyData));
}

// -----------------------------------------------------------------------------
// QUERY EXECUTION FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Execute SELECT query and return results
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param PDO|null $connection Database connection (optional)
 * @return array Query results
 */
function database_query(string $query, array $params = [], ?PDO $connection = null): array {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        // Skip rate limiting for better performance
        // Rate limiting disabled to prevent slow page loads

        $stmt = $connection->prepare($query);
        $stmt->execute($params);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Reduced logging for performance - only log errors

        return $results;

    } catch (PDOException $e) {
        cue_autoload('error')->logError('Database query failed', [
            'query' => $query,
            'params' => $params,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Execute INSERT, UPDATE, DELETE query
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param PDO|null $connection Database connection (optional)
 * @return int Number of affected rows
 */
function database_execute(string $query, array $params = [], ?PDO $connection = null): int {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        // Skip rate limiting for better performance
        // Rate limiting disabled to prevent slow page loads

        $stmt = $connection->prepare($query);
        $stmt->execute($params);

        $affectedRows = $stmt->rowCount();

        // Reduced logging for performance - only log errors

        return $affectedRows;

    } catch (PDOException $e) {
        cue_autoload('error')->logError('Database execute failed', [
            'query' => $query,
            'params' => $params,
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Execute query and return single row
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param PDO|null $connection Database connection (optional)
 * @return array|null Single row or null if not found
 */
function database_querySingle(string $query, array $params = [], ?PDO $connection = null): ?array {
    $results = database_query($query, $params, $connection);
    return !empty($results) ? $results[0] : null;
}

/**
 * Execute query and return single value
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param PDO|null $connection Database connection (optional)
 * @return mixed Single value or null if not found
 */
function database_queryValue(string $query, array $params = [], ?PDO $connection = null) {
    $row = database_querySingle($query, $params, $connection);
    return $row ? reset($row) : null;
}

/**
 * Execute INSERT query and return last insert ID
 * @param string $query SQL query
 * @param array $params Query parameters
 * @param PDO|null $connection Database connection (optional)
 * @return string Last insert ID
 */
function database_insert(string $query, array $params = [], ?PDO $connection = null): string {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    database_execute($query, $params, $connection);
    return $connection->lastInsertId();
}

/**
 * Start database transaction
 * @param PDO|null $connection Database connection (optional)
 * @return bool True if transaction started
 */
function database_beginTransaction(?PDO $connection = null): bool {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        return $connection->beginTransaction();
    } catch (PDOException $e) {
        cue_autoload('error')->logError('Failed to begin transaction', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Commit database transaction
 * @param PDO|null $connection Database connection (optional)
 * @return bool True if committed
 */
function database_commit(?PDO $connection = null): bool {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        return $connection->commit();
    } catch (PDOException $e) {
        cue_autoload('error')->logError('Failed to commit transaction', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Rollback database transaction
 * @param PDO|null $connection Database connection (optional)
 * @return bool True if rolled back
 */
function database_rollback(?PDO $connection = null): bool {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        return $connection->rollBack();
    } catch (PDOException $e) {
        cue_autoload('error')->logError('Failed to rollback transaction', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Execute query within transaction with automatic rollback on failure
 * @param callable $callback Function to execute within transaction
 * @param PDO|null $connection Database connection (optional)
 * @return mixed Result of callback function
 */
function database_transaction(callable $callback, ?PDO $connection = null) {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    $result = null;
    $success = false;

    try {
        database_beginTransaction($connection);
        $result = $callback($connection);
        database_commit($connection);
        $success = true;
    } catch (Exception $e) {
        database_rollback($connection);
        cue_autoload('error')->logError('Transaction failed and rolled back', [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }

    return $result;
}

// -----------------------------------------------------------------------------
// DATABASE UTILITIES
// -----------------------------------------------------------------------------

/**
 * Get database connection statistics
 * @return array Connection pool statistics
 */
function database_getStats(): array {
    return CueDatabasePool::getStats();
}

/**
 * Test database connection
 * @param string|null $configId Configuration ID (optional)
 * @return array Test results
 */
function database_testConnection(?string $configId = null): array {
    $result = ['success' => false, 'error' => '', 'latency' => 0];

    try {
        $startTime = microtime(true);

        if ($configId === null) {
            $connection = database_getConnection();
        } else {
            $connection = database_getConnectionById($configId);
        }

        // Simple test query
        $stmt = $connection->query('SELECT 1 as test');
        $row = $stmt->fetch();

        $endTime = microtime(true);
        $result['latency'] = round(($endTime - $startTime) * 1000, 2); // milliseconds
        $result['success'] = ($row['test'] == 1);

    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

/**
 * Get database schema information
 * @param PDO|null $connection Database connection (optional)
 * @return array Schema information
 */
function database_getSchema(?PDO $connection = null): array {
    if ($connection === null) {
        $connection = database_getContextAwareConnection();
    }

    try {
        $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $schema = [];

        switch (strtolower($driver)) {
            case 'mysql':
                $tables = database_query("SHOW TABLES", [], $connection);
                foreach ($tables as $table) {
                    $tableName = reset($table);
                    $columns = database_query("DESCRIBE `{$tableName}`", [], $connection);
                    $schema[$tableName] = $columns;
                }
                break;

            case 'pgsql':
                $tables = database_query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'", [], $connection);
                foreach ($tables as $table) {
                    $tableName = $table['tablename'];
                    $columns = database_query("SELECT * FROM information_schema.columns WHERE table_name = $1", [$tableName], $connection);
                    $schema[$tableName] = $columns;
                }
                break;

            case 'sqlite':
                $tables = database_query("SELECT name FROM sqlite_master WHERE type='table'", [], $connection);
                foreach ($tables as $table) {
                    $tableName = $table['name'];
                    $columns = database_query("PRAGMA table_info({$tableName})", [], $connection);
                    $schema[$tableName] = $columns;
                }
                break;
        }

        return $schema;

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to get database schema', [
            'error' => $e->getMessage()
        ]);
        return [];
    }
}

/**
 * Sanitize database input to prevent SQL injection
 * @param mixed $input Input to sanitize
 * @return mixed Sanitized input
 */
function database_sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('database_sanitizeInput', $input);
    }

    if (is_string($input)) {
        // Remove null bytes and other dangerous characters
        $input = str_replace("\x00", '', $input);
        $input = str_replace("\r", '', $input);
        $input = str_replace("\n", '', $input);
        return trim($input);
    }

    return $input;
}

?>
