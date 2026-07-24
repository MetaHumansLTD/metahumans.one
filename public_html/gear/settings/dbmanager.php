<?php

// Handle AJAX requests FIRST - before loading any frameworks
if (
    isset($_SERVER['REQUEST_METHOD']) &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {
    // Prevent animation widget from loading for AJAX requests
    define('CUE_ANIMATIONS_INITIALIZED', true);
}

 

// Include the main bootstrap
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';

if (!function_exists('mh_kripz_require')) {
    function mh_kripz_require(string $perm, bool $ajax = false): void {
        if ($perm === '' && $ajax) {
            return;
        }
    }
}
if (!function_exists('database_getConnectionById')) {
    function database_getConnectionById(mixed ...$args): mixed {
        if ($args) { $args = []; }
        throw new RuntimeException('database_getConnectionById unavailable');
    }
}
if (!function_exists('database_getConnectionFromConfig')) {
    function database_getConnectionFromConfig(mixed ...$args): mixed {
        if ($args) { $args = []; }
        throw new RuntimeException('database_getConnectionFromConfig unavailable');
    }
}
if (!function_exists('database_query')) {
    function database_query(mixed ...$args): array {
        if ($args) { $args = []; }
        throw new RuntimeException('database_query unavailable');
    }
}
if (!function_exists('database_queryValue')) {
    function database_queryValue(mixed ...$args): mixed {
        if ($args) { $args = []; }
        throw new RuntimeException('database_queryValue unavailable');
    }
}
if (!function_exists('database_querySingle')) {
    function database_querySingle(mixed ...$args): array {
        if ($args) { $args = []; }
        throw new RuntimeException('database_querySingle unavailable');
    }
}
if (!function_exists('database_execute')) {
    function database_execute(mixed ...$args): bool {
        if ($args) { $args = []; }
        throw new RuntimeException('database_execute unavailable');
    }
}

$dbmIsAjax = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
mh_kripz_require('dbmanager', $dbmIsAjax);

require_once __DIR__ . '/dbmanager_monitoring.php';
dbmanager_monitoring_init($dbmIsAjax);

// Include the new database context classes
require_once __DIR__ . '/classes/DatabaseContextManager.php';
$pagePermMgrPath = __DIR__ . '/classes/PagePermissionManager.php';
if (is_file($pagePermMgrPath)) {
    require_once $pagePermMgrPath;
}
require_once __DIR__ . '/dbmanager_lib.php';
if (is_file(dirname(dirname(__DIR__)) . '/auth/tenant_provisioning.php')) {
    require_once dirname(dirname(__DIR__)) . '/auth/tenant_provisioning.php';
}

cue_autoload('database');

/**
 * Resolve PDO from CUE wrapper or return PDO directly
 */
function resolvePDO(mixed $connection): mixed {
    if (is_object($connection) && property_exists($connection, 'pdo')) {
        return $connection->pdo;
    }
    return $connection;
}

function dbmanager_createContextFiles(): array {
    $paths = cue_autoload('paths');
    $cfgDir = (string)$paths->getConfigPath();
    if ($cfgDir === '') {
        return ['success' => false, 'message' => 'Config path unavailable'];
    }
    if (!is_dir($cfgDir) && !@mkdir($cfgDir, 0755, true) && !is_dir($cfgDir)) {
        return ['success' => false, 'message' => 'Unable to create config directory'];
    }
    $targets = [
        'persona-context.json' => (string)$paths->getPersonaContextFile(),
        'meta_humans_context.json' => (string)$paths->getMetaHumansContextFile(),
    ];
    $created = [];
    $skipped = [];
    foreach ($targets as $name => $filePath) {
        if ($filePath === '' || strpos($filePath, $cfgDir) !== 0) {
            return ['success' => false, 'message' => 'Invalid context file path: ' . $name];
        }
        if (file_exists($filePath)) {
            $skipped[] = $name;
            continue;
        }
        $payload = $name === 'persona-context.json'
            ? ['schema_version' => '1.0.0', 'personas' => (object)[]]
            : ['schema_version' => '1.0.0', 'meta_humans' => (object)[]];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return ['success' => false, 'message' => 'JSON encoding failed'];
        }
        $ok = @file_put_contents($filePath, $json . "\n", LOCK_EX);
        if ($ok === false) {
            return ['success' => false, 'message' => 'Failed to write file: ' . $name];
        }
        $created[] = $name;
    }
    return ['success' => true, 'created' => $created, 'skipped' => $skipped];
}

/**
 * Get database connection by ID (replaces missing function)
 * @param string $configId Database configuration ID
 * @return PDO Database connection
 */
// Function getDatabaseById is now provided by .cue/core.php
// If it's not available, we can fallback to using database_autoload
if (!function_exists('getDatabaseById')) {
    function getDatabaseById(mixed $configId): mixed {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            throw new Exception("Database module not available");
        }
        return database_getConnectionById((string)$configId);
    }
}

/**
 * Verify tables in the selected database
 */
function verifyTables(?string $configId): array {
    try {
        if (empty($configId)) {
            return ['success' => false, 'message' => 'Database configuration ID is required'];
        }
        
        // Use CUE Framework modular database access
        try {
            $db = getDatabaseById($configId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        $pdo = resolvePDO($db);
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        // Get the database configuration to access database name
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Failed to get database configuration: ' . $configResult['message']];
        }
        $config = $configResult['config'];
        
        // Get all tables using CUE framework database_query
        try {
            $tables = database_query("SHOW TABLES", [], $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to retrieve tables: ' . $e->getMessage()];
        }
        
        $existingTables = [];
        foreach ($tables as $row) {
            $existingTables[] = array_values($row)[0]; // Get first column value
        }
        
        // Get detailed information about each table
        $tableDetails = [];
        foreach ($existingTables as $table) {
            try {
                $statusResult = database_query("SHOW TABLE STATUS LIKE ?", [$table], $pdo);
                $status = $statusResult[0] ?? [];
                
                $countResult = database_query("SELECT COUNT(*) as row_count FROM `$table`", [], $pdo);
                $rowCountResult = $countResult[0] ?? ['row_count' => 0];
            } catch (Exception $e) {
                $status = [];
                $rowCountResult = ['row_count' => 0];
            }
            
            $tableDetails[] = [
                'name' => $table,
                'engine' => $status['Engine'] ?? 'Unknown',
                'rows' => $rowCountResult['row_count'] ?? 0,
                'data_length' => $status['Data_length'] ?? 0,
                'index_length' => $status['Index_length'] ?? 0,
                'collation' => $status['Collation'] ?? 'Unknown',
                'created' => $status['Create_time'] ?? null
            ];
        }
        
        // Look for schema file to compare against (optional)
        $schemaPath = null;
        try {
            $paths = cue_autoload('paths');
            $cfgBase = $paths->getConfigPath();
            $candidate = $cfgBase . DIRECTORY_SEPARATOR . 'db.sql';
            $safe = $paths->validateSecurePath($candidate, $cfgBase);
            if (is_string($safe) && $safe !== '') {
                $schemaPath = $safe;
            } elseif (is_array($safe)) {
                $schemaPath = isset($safe['resolved_path']) && is_string($safe['resolved_path']) ? $safe['resolved_path'] : (isset($safe['path']) && is_string($safe['path']) ? $safe['path'] : null);
            } else {
                $schemaPath = null;
            }
        } catch (Throwable $e) { $schemaPath = null; }
        $requiredTables = [];
        $missingTables = [];
        $extraTables = $existingTables; // Start with all existing tables as "extra"
        
        if (file_exists($schemaPath)) {
            // Read schema file and extract required tables
            $schemaContent = file_get_contents($schemaPath);
            if ($schemaContent) {
                preg_match_all('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $schemaContent, $matches);
                $requiredTables = $matches[1];
                
                // Find missing tables (in schema but not in database)
                $missingTables = array_diff($requiredTables, $existingTables);
                
                // Find extra tables (in database but not in schema)
                $extraTables = array_diff($existingTables, $requiredTables);
            }
        } else {
            // No schema file - treat all existing tables as required
            $requiredTables = $existingTables;
            $extraTables = [];
        }
        
        return [
            'success' => true,
            'data' => [
                'database_name' => $config['database'],
                'schema_file' => file_exists($schemaPath) ? $schemaPath : null,
                'required_tables' => $requiredTables,
                'existing_tables' => $existingTables,
                'missing_tables' => array_values($missingTables),
                'extra_tables' => array_values($extraTables),
                'table_details' => $tableDetails,
                'total_tables' => count($existingTables),
                'verification_time' => date('Y-m-d H:i:s')
            ]
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to verify tables: ' . $e->getMessage()];
    }
}

/**
 * Download SQL file
 */
function downloadSqlFile(string $filePath): void {
    try {
        if (empty($filePath)) {
            echo json_encode(['success' => false, 'message' => 'File path is required']);
            return;
        }
        
        // Security check - ensure file is within allowed directory
        $basePath = cue_autoload('paths')->getSecureFilePath('config/databases/', true);
        $fullPath = $basePath . ltrim($filePath, '/');
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);
        
        if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid file path']);
            return;
        }
        
        if (!file_exists($fullPath)) {
            echo json_encode(['success' => false, 'message' => 'File not found']);
            return;
        }
        
        if (!is_file($fullPath)) {
            echo json_encode(['success' => false, 'message' => 'Path is not a file']);
            return;
        }
        
        // Set headers for file download
        $fileName = basename($fullPath);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        // Output file content
        readfile($fullPath);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Download failed: ' . $e->getMessage()]);
    }
}

/**
 * Delete SQL file
 */
function deleteSqlFile(string $filePath): array {
    try {
        if (empty($filePath)) {
            return ['success' => false, 'message' => 'File path is required'];
        }
        
        // Security check - ensure file is within allowed directory
        $basePath = cue_autoload('paths')->getSecureFilePath('config/databases/', true);
        $fullPath = $basePath . ltrim($filePath, '/');
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);
        
        if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
            return ['success' => false, 'message' => 'Invalid file path'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        if (!is_file($fullPath)) {
            return ['success' => false, 'message' => 'Path is not a file'];
        }
        
        // Delete the file
        if (unlink($fullPath)) {
            return ['success' => true, 'message' => 'File deleted successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to delete file'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()];
    }
}

/**
 * Database Manager - Complete CRUD Operations
 * 
 * Modern database configuration management with enhanced UI/UX improvements:
 * - Multiple database configurations with responsive table display
 * - Real-time connection testing with loading indicators
 * - AES-256-CBC encryption for secure credential storage
 * - Glassmorphism UI design with enhanced visual feedback
 * - Professional modal interfaces with improved structure display
 * - Responsive table overflow handling with horizontal scrolling
 * - Enhanced button functionality with proper click handlers
 * - Descriptive tooltips and metadata for better user experience
 * - Loading spinners and timeout handling for database operations
 * - Mobile-responsive design with improved touch interactions
 * 
 * Recent UI/UX Improvements (2025):
 * ✓ Fixed table overflow issues with responsive container sizing
 * ✓ Enhanced button states with hover/active/disabled feedback
 * ✓ Added comprehensive tooltips and table metadata display
 * ✓ Implemented loading indicators for all database operations
 * ✓ Enhanced modal system for table structure viewing
 * ✓ Improved mobile responsiveness across all components
 * ✓ Added proper error handling and user feedback
 * 
 * @package    Meta Humans Enterprise
 * @author     Meta Humans LTD
 * @copyright  Copyright (c) Meta Humans LTD® 2025
 * @license    Licensed
 */


// Start secure session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Force Hub Realm for consistent menu for KripzMaster
$_SESSION['current_realm'] = 'hub';

// Note: Encryption functions are now provided by cue.php
// - decryptValue() is available from cue.php
// - getEncryptionKeyPath() provides the key file path
// - Use getSecureFilePath() for consistent path management (updated from getDataPath())
// - Fixed Page-Database Access Control table to display actual database names instead of "Unknown"

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clean any previous output and ensure proper JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Disable error display to prevent HTML errors in JSON responses
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    // Ensure we output JSON even on fatal errors
    if (!defined('DBM_JSON_SHUTDOWN')) {
        define('DBM_JSON_SHUTDOWN', true);
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                // Attempt to output JSON body for the frontend instead of empty response
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                http_response_code(500);
                $message = isset($error['message']) ? $error['message'] : 'Unexpected server error';
                // Keep response succinct to avoid leaking sensitive details
                echo json_encode([
                    'success' => false,
                    'message' => 'Server error: ' . $message,
                ]);
            }
        });
    }
    
    if (isset($_POST['action']) && $_POST['action'] !== 'download_sql_file') {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
    }
    
    try {
        switch ($_POST['action']) {
            case 'test_connection':
                $result = testDatabaseConnection($_POST);
                echo json_encode($result);
                break;

            case 'create_database':
                $result = dbmanager_createDatabaseIfMissing($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;

            case 'get_db_status':
                $result = dbmanager_getDatabaseStatus($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
            
            case 'create_provisioner':
                $result = dbmanager_createProvisioner($_POST['prov_user'] ?? '', $_POST['prov_pass'] ?? '', $_POST['admin_config_id'] ?? '');
                echo json_encode($result);
                break;

            case 'migrate_control_plane_tenant_configs':
                $batchSize = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 200;
                $dryRun = isset($_POST['dry_run']) ? filter_var($_POST['dry_run'], FILTER_VALIDATE_BOOLEAN) : true;
                $result = dbmanager_migrateTenantConfigsToControlPlane($batchSize, $dryRun);
                echo json_encode($result);
                break;
                
            case 'save_config':
                $result = saveDatabaseConfig($_POST);
                echo json_encode($result);
                break;
                
            case 'delete_config':
                $cfgId = isset($_POST['config_id']) ? trim((string)$_POST['config_id']) : '';
                $preCfg = null;
                try {
                    if ($cfgId !== '' && function_exists('getDatabaseConfigById')) {
                        $preCfg = getDatabaseConfigById($cfgId);
                    }
                } catch (Throwable $e) { $preCfg = null; }
                $tenantCleanup = null;
                if ($cfgId !== '' && function_exists('mh_deprovision_tenant_by_db_config_id')) {
                    try {
                        $tenantCleanup = mh_deprovision_tenant_by_db_config_id($cfgId);
                    } catch (Throwable $e) {
                        $tenantCleanup = ['success' => false, 'message' => $e->getMessage()];
                    }
                }

                $mysqlCleanup = null;
                try {
                    if ($tenantCleanup === null && is_array($preCfg) && (($preCfg['success'] ?? null) === true) && isset($preCfg['config']) && is_array($preCfg['config'])) {
                        $c = (array)$preCfg['config'];
                        $ctx = isset($c['context']) ? (string)$c['context'] : '';
                        $dbName = isset($c['database']) ? trim((string)$c['database']) : '';
                        if ($dbName !== '' && ($ctx === 'tenant' || str_starts_with($dbName, 'tenant_user_') || str_starts_with($dbName, 'tenant_persona_'))) {
                            $adminConfigId = function_exists('mh_find_block_provisioner_config_id') ? mh_find_block_provisioner_config_id() : null;
                            if (is_string($adminConfigId) && $adminConfigId !== '') {
                                try {
                                    $adminPdo = database_getConnectionById($adminConfigId);
                                    if ($adminPdo instanceof PDO) {
                                        try { $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (Throwable $e) {}
                                    }
                                } catch (Throwable $e) {}
                            }
                            if (function_exists('mh_tenant_delete_dir_recursive')) {
                                $mysqlDir = '/mysql/' . $dbName;
                                if (is_dir($mysqlDir)) {
                                    mh_tenant_delete_dir_recursive($mysqlDir);
                                }
                            }
                            $mysqlCleanup = ['db_name' => $dbName, 'mysql_dir' => '/mysql/' . $dbName];
                        }
                    }
                } catch (Throwable $e) { $mysqlCleanup = ['error' => $e->getMessage()]; }

                $result = deleteDatabaseConfig($cfgId);
                if (is_array($result) && $tenantCleanup !== null) {
                    $result['tenant_cleanup'] = $tenantCleanup;
                }
                if (is_array($result) && $mysqlCleanup !== null) {
                    $result['mysql_cleanup'] = $mysqlCleanup;
                }
                echo json_encode($result);
                break;
                
            case 'set_active':
                $result = toggleActiveDatabaseConfig($_POST['config_id']);
                echo json_encode($result);
                break;
                
            case 'switch_database':
                $result = switchToDatabaseForManager($_POST['config_id']);
                echo json_encode($result);
                break;
                
            case 'get_config':
                $result = getDatabaseConfigById($_POST['config_id']);
                echo json_encode($result);
                break;
                
            // Database Operations
            case 'get_active_databases':
                $base = getActiveDatabases();
                if (!is_array($base) || (($base['success'] ?? null) !== true) || !isset($base['databases']) || !is_array($base['databases'])) {
                    echo json_encode($base);
                    break;
                }
                $core = [];
                foreach ($base['databases'] as $cfg) {
                    if (!is_array($cfg)) continue;
                    if (dbmanager_isTenantDbConfig($cfg)) {
                        continue;
                    }
                    $core[] = $cfg;
                }
                $includeTenants = isset($_POST['include_tenants']) ? filter_var($_POST['include_tenants'], FILTER_VALIDATE_BOOLEAN) : false;
                $tenantLimit = isset($_POST['tenant_limit']) ? (int)$_POST['tenant_limit'] : 200;
                $tenantOffset = isset($_POST['tenant_offset']) ? (int)$_POST['tenant_offset'] : 0;
                $tenantQuery = isset($_POST['tenant_query']) ? (string)$_POST['tenant_query'] : '';
                $tenantPayload = $includeTenants ? dbmanager_listTenantDbConfigsFromControlPlane($tenantLimit, $tenantOffset, $tenantQuery) : ['success' => true, 'tenants' => [], 'has_more' => false, 'offset' => $tenantOffset, 'limit' => $tenantLimit, 'query' => $tenantQuery];
                echo json_encode([
                    'success' => true,
                    'databases' => $core,
                    'tenants' => is_array($tenantPayload['tenants'] ?? null) ? $tenantPayload['tenants'] : [],
                    'tenant_pagination' => [
                        'offset' => (int)($tenantPayload['offset'] ?? $tenantOffset),
                        'limit' => (int)($tenantPayload['limit'] ?? $tenantLimit),
                        'has_more' => (bool)($tenantPayload['has_more'] ?? false),
                        'query' => (string)($tenantPayload['query'] ?? $tenantQuery),
                    ],
                ]);
                break;

            case 'create_context_files':
                $result = dbmanager_createContextFiles();
                echo json_encode($result);
                break;
                
            case 'get_tables':
                $configId = $_POST['config_id'] ?? $_POST['database'] ?? null;
                $result = getDatabaseTables($configId);
                echo json_encode($result);
                break;
                
            case 'get_table_structure':
                $result = getTableStructure($_POST['config_id'] ?? null, $_POST['table'] ?? '');
                echo json_encode($result);
                break;
                
            case 'get_records':
                $result = getTableRecords($_POST);
                echo json_encode($result);
                break;
                
            case 'create_record':
                $result = createTableRecord($_POST);
                echo json_encode($result);
                break;
                
            case 'update_record':
                $result = updateTableRecord($_POST);
                echo json_encode($result);
                break;
                
            case 'delete_record':
                $result = deleteTableRecord($_POST);
                echo json_encode($result);
                break;
                
            case 'create_table':
                $result = createNewTable($_POST);
                echo json_encode($result);
                break;
                
            case 'drop_table':
                $result = dropTable($_POST['config_id'] ?? null, $_POST['table'] ?? '');
                echo json_encode($result);
                break;
                
            // SQL File Operations
            case 'upload_sql_file':
                $result = uploadSqlFile($_FILES);
                echo json_encode($result);
                break;
                
            case 'browse_sql_files':
                $result = browseSqlFiles($_POST['path'] ?? '');
                echo json_encode($result);
                break;
                
            case 'execute_sql_file':
                $result = executeSqlFile($_POST);
                echo json_encode($result);
                break;
                
            case 'search_sql_files':
                $result = searchSqlFiles($_POST['query'] ?? '');
                echo json_encode($result);
                break;
                
            case 'save_all_tables':
                try {
                    // Clean any output buffers to ensure clean JSON response
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    
                    $includeData = isset($_POST['include_data']) ? filter_var($_POST['include_data'], FILTER_VALIDATE_BOOLEAN) : false;
                    $result = saveAllTables($_POST['config_id'] ?? '', $includeData);
                    echo json_encode($result);
                } catch (Exception $e) {
                    // Clean any output buffers before error response
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    echo json_encode(['success' => false, 'message' => 'Error in save_all_tables: ' . $e->getMessage()]);
                }
                break;
                
            case 'save_selected_tables':
                try {
                    // Clean any output buffers to ensure clean JSON response
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    
                    $includeData = isset($_POST['include_data']) ? filter_var($_POST['include_data'], FILTER_VALIDATE_BOOLEAN) : false;
                    
                    // Handle tables parameter - could be JSON string or array
                    $tables = $_POST['tables'] ?? [];
                    if (is_string($tables)) {
                        $tables = json_decode($tables, true) ?: [];
                    }
                    
                    $result = saveSelectedTables($_POST['config_id'] ?? '', $tables, $includeData);
                    echo json_encode($result);
                } catch (Exception $e) {
                    // Clean any output buffers before error response
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    echo json_encode(['success' => false, 'message' => 'Error in save_selected_tables: ' . $e->getMessage()]);
                }
                break;
                
            // Schema Management Operations
            case 'verify_schema':
                $result = verifySchemaIntegrity($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
                
            case 'create_missing_tables':
                $result = createMissingTables($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
                
            case 'verify_tables':
                $result = verifyTables($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
                
            case 'load_schema_info':
                $result = loadSchemaInfo($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
                
            case 'optimize_tables':
                $result = optimizeTables($_POST['config_id'] ?? '');
                echo json_encode($result);
                break;
                
            case 'delete_sql_file':
                $result = deleteSqlFile($_POST['file_path'] ?? '');
                echo json_encode($result);
                break;
                
            case 'download_sql_file':
                $filePath = $_GET['file_path'] ?? '';
                if ($filePath) {
                    downloadSqlFile($filePath);
                } else {
                    echo json_encode(['success' => false, 'message' => 'File path not provided']);
                }
                break;
                
            case 'browse_schema_files':
                $result = browseSchemaFiles($_POST['path'] ?? '');
                echo json_encode($result);
                break;

            case 'get_databases':
                $databases = getActiveDatabases();
                echo json_encode(['success' => true, 'databases' => $databases]);
                break;



            case 'validate_path':
                $result = validatePath($_POST['path'] ?? '');
                echo json_encode($result);
                break;
                
            // Context Mapping Management
            case 'add_context_mapping':
                $type = $_POST['mappingType'] ?? '';
                $path = $_POST['mappingPath'] ?? '';
                $database = $_POST['mappingDatabase'] ?? '';
                
                if (empty($type) || empty($path) || empty($database)) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    break;
                }
                
                $contextManager = new DatabaseContextManager();
                
                if ($type === 'page') {
                    $success = $contextManager->addPageMapping($path, $database);
                } elseif ($type === 'directory') {
                    $success = $contextManager->addDirectoryMapping($path, $database);
                } else {
                    $success = false;
                }
                
                echo json_encode(['success' => $success]);
                break;
                
            case 'delete_context_mapping':
                $type = $_POST['type'] ?? '';
                $path = $_POST['path'] ?? '';
                
                if (empty($type) || empty($path)) {
                    echo json_encode(['success' => false, 'message' => 'Type and path are required']);
                    break;
                }
                
                $contextManager = new DatabaseContextManager();
                
                if ($type === 'page') {
                    $success = $contextManager->removePageMapping($path);
                } elseif ($type === 'directory') {
                    $success = $contextManager->removeDirectoryMapping($path);
                } else {
                    $success = false;
                }
                
                echo json_encode(['success' => $success]);
                break;
                
            case 'get_context_mappings':
                $contextManager = new DatabaseContextManager();
                $mappings = $contextManager->getAllMappings();
                
                echo json_encode(['success' => true, 'mappings' => $mappings]);
                break;
                
            case 'toggle_auto_switch':
                $enabled = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                
                $contextManager = new DatabaseContextManager();
                $success = $contextManager->setAutoSwitch($enabled);
                
                echo json_encode(['success' => $success]);
                break;


                
            // Page Permissions Actions
            case 'get_page_permissions':
                $result = getPagePermissions();
                echo json_encode($result);
                break;
                
            case 'add_page_permission':
                $result = addPagePermission($_POST);
                echo json_encode($result);
                break;
                
            case 'update_page_permission':
                $result = updatePagePermission($_POST);
                echo json_encode($result);
                break;
                
            case 'delete_page_permission':
                $result = deletePagePermission($_POST);
                echo json_encode($result);
                break;
                
            case 'get_available_pages':
                $result = getAvailablePages();
                echo json_encode($result);
                break;
                
            case 'get_available_tables_for_db':
                $result = getAvailableTablesForDatabase($_POST['config_id'] ?? null);
                echo json_encode($result);
                break;
                
            case 'verify_context_mapping_status':
                $directory = $_POST['directory'] ?? '/templates/theme';
                $expectedDatabase = $_POST['expected_database'] ?? null;
                $result = verifyContextMappingStatus($directory, $expectedDatabase);
                echo json_encode($result);
                break;

            case 'resolve_context_db':
                $uri = $_POST['uri'] ?? '';
                if ($uri === '' || $uri === null) {
                    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                    if (!empty($scriptName)) {
                        $uri = trim($scriptName, '/');
                    } else {
                        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                        $uri = ltrim(strtok($requestUri, '?'), '/');
                    }
                }
                $mgr = new DatabaseContextManager();
                $resolved = $mgr->getContextDatabase($uri);
                echo json_encode(['success' => true, 'resolved_config_id' => $resolved, 'uri' => $uri]);
                break;

            case 'vector_admin_enforcement_test':
                $result = dbmanager_vectorAdminEnforcementTest();
                echo json_encode($result);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        $action = isset($_POST['action']) ? $_POST['action'] : 'unknown';
        error_log('dbmanager.php POST error on action ' . $action . ': ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/**
 * Test database connection
 */
function testDatabaseConnection(array $config): array {
    try {
        $storageProfile = $config['storage_profile'] ?? null;
        $host = $config['host'] ?? 'localhost';
        if ($storageProfile !== null && strpos($storageProfile, 'block_') === 0 && !dbmanager_isAllowedBlockStorageHost($host)) {
            return ['success' => false, 'message' => 'Invalid host for block storage profile'];
        }
        $driver = strtolower($config['type'] ?? 'mysql');
        $cfg = [
            'driver' => $driver,
            'type' => $driver,
            'host' => $host,
            'port' => $config['port'] ?? '3306',
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'storage_profile' => $storageProfile,
            'id' => $config['id'] ?? '',
        ];
        $pdo = database_getConnectionFromConfig($cfg);
        $ok = database_queryValue('SELECT 1', [], $pdo);
        if ($ok === 1 || $ok === '1') {
            return ['success' => true, 'message' => 'Connection successful!'];
        }
        return ['success' => false, 'message' => 'Connection test failed'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()];
    }
}

function dbmanager_isAllowedBlockStorageHost(string $host): bool {
    $host = trim((string)$host);
    if ($host === '') {
        return false;
    }
    $allowedHosts = ['127.0.0.1', 'localhost'];
    return in_array($host, $allowedHosts, true);
}

function dbmanager_isKripzMaster(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
    return $role !== '' && strpos($role, 'kripzmaster') !== false;
}

function dbmanager_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $h = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;
    return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $err];
}

function dbmanager_vectorAdminEnforcementTest(): array {
    if (!dbmanager_isKripzMaster()) {
        http_response_code(403);
        return ['success' => false, 'message' => 'Forbidden'];
    }

    if (function_exists('cue_autoload')) {
        cue_autoload('vector');
    }
    $cfg = function_exists('vector_config') ? vector_config() : [];
    $qdrantUrl = is_array($cfg) && isset($cfg['qdrant_url']) && is_string($cfg['qdrant_url']) ? (string)$cfg['qdrant_url'] : '';
    $usesGateway = $qdrantUrl !== '' && strpos($qdrantUrl, '/hub/memory/api/qdrant.php') !== false;

    $base = 'http://127.0.0.1';
    $list = dbmanager_http($base . '/hub/memory/api/qdrant.php/collections', 'GET', null);
    $listOk = ($list['status'] === 200);
    $collections = [];
    if ($listOk) {
        $decoded = json_decode((string)$list['body'], true);
        $cols = $decoded['result']['collections'] ?? null;
        if (is_array($cols)) {
            foreach ($cols as $c) {
                if (is_array($c) && isset($c['name']) && is_string($c['name'])) {
                    $collections[] = (string)$c['name'];
                }
            }
        }
    }
    $collection = '';
    foreach ($collections as $c) {
        if (strpos($c, 'mh_shard_') === 0) { $collection = $c; break; }
    }
    if ($collection === '' && $collections) $collection = (string)$collections[0];

    $searchRes = ['status' => 0, 'ok' => false];
    $writeRes = ['status' => 0, 'ok' => false];
    if ($collection !== '') {
        $search = dbmanager_http($base . '/hub/memory/api/qdrant.php/collections/' . rawurlencode($collection) . '/points/search', 'POST', '{}');
        $searchRes = ['status' => (int)$search['status'], 'ok' => (int)$search['status'] === 403];

        $writeBody = json_encode(['points' => [['id' => '_dbm_test', 'vector' => [0], 'payload' => (object)[]]]], JSON_UNESCAPED_SLASHES);
        $write = dbmanager_http($base . '/hub/memory/api/qdrant.php/collections/' . rawurlencode($collection) . '/points', 'PUT', is_string($writeBody) ? $writeBody : '');
        $writeRes = ['status' => (int)$write['status'], 'ok' => (int)$write['status'] === 403];
    }

    return [
        'success' => true,
        'uses_gateway_url' => $usesGateway,
        'gateway_collections' => ['ok' => $listOk, 'status' => (int)$list['status']],
        'enforcement_search_requires_filter' => $searchRes,
        'enforcement_write_requires_tenant' => $writeRes,
        'sample_collection' => $collection,
    ];
}

function dbmanager_isTcpPortReachable(string $host, int $port, float $timeoutSeconds = 1.0): bool {
    $host = trim($host);
    if ($host === '') {
        return false;
    }
    if ($port < 1 || $port > 65535) {
        return false;
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

function dbmanager_getDbConfigsJsonStatus(): array {
    $paths = cue_autoload('paths');
    $configsPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;
    if (!is_string($configsPath) || $configsPath === '') {
        return ['ok' => false, 'message' => 'db_configs.json path could not be resolved'];
    }
    return [
        'ok' => true,
        'path' => $configsPath,
        'exists' => file_exists($configsPath),
        'writable_file' => file_exists($configsPath) ? is_writable($configsPath) : false,
        'writable_dir' => is_writable(dirname($configsPath)),
    ];
}

function dbmanager_getDatabaseStatus(string $configId): array {
    $configId = trim($configId);
    if ($configId === '') {
        return ['success' => false, 'message' => 'Configuration ID is required'];
    }

    $cfgStatus = dbmanager_getDbConfigsJsonStatus();
    $configResult = getDatabaseConfigById($configId);
    if (!$configResult['success']) {
        return ['success' => false, 'message' => $configResult['message'] ?? 'Configuration not found'];
    }
    $config = $configResult['config'];

    $storageProfile = (string)($config['storage_profile'] ?? '');
    $host = (string)($config['host'] ?? '127.0.0.1');
    $port = (int)($config['port'] ?? 0);
    if ($storageProfile === 'block_mysql') {
        if (strtolower(trim($host)) === 'localhost') {
            $host = '127.0.0.1';
        }
        if ($port === 0 || $port === 3306) {
            $port = 3307;
        }
    }
    $dbName = (string)($config['database'] ?? '');
    $user = (string)($config['username'] ?? '');

    $reachable = dbmanager_isTcpPortReachable($host, $port, 0.8);
    if (!$reachable) {
        return [
            'success' => true,
            'status' => [
                'config_id' => $configId,
                'is_active' => ($config['is_active'] ?? false) === true,
                'port_reachable' => false,
                'can_authenticate' => false,
                'database_exists' => null,
                'user' => $user,
                'host' => $host,
                'port' => (string)($port ?: ($config['port'] ?? '')),
                'db_name' => $dbName,
                'db_configs' => $cfgStatus,
            ],
        ];
    }

    $driver = strtolower((string)($config['type'] ?? 'mysql'));
    if ($driver === 'mariadb') {
        $driver = 'mysql';
    }

    $baseCfg = [
        'driver' => $driver,
        'type' => $driver,
        'host' => $host,
        'port' => (string)$port,
        'username' => $user,
        'password' => (string)($config['password'] ?? ''),
        'charset' => (string)($config['charset'] ?? 'utf8mb4'),
        'storage_profile' => $storageProfile,
        'id' => (string)($config['id'] ?? $configId),
    ];

    $exists = null;
    $pdo = null;
    try {
        $pdo = database_getConnectionFromConfig($baseCfg + ['database' => 'information_schema']);
        if ($dbName !== '') {
            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$dbName]);
            $exists = $stmt->fetchColumn() !== false;
        }
    } catch (Throwable $e) {
        try {
            $targetDb = $dbName !== '' ? $dbName : 'information_schema';
            $pdo = database_getConnectionFromConfig($baseCfg + ['database' => $targetDb]);
            if ($dbName !== '') {
                $exists = true;
            }
        } catch (Throwable $e2) {
            $msg = $e2->getMessage();
            $isAuth = stripos($msg, 'SQLSTATE[HY000] [1045]') !== false || stripos($msg, 'Access denied') !== false;
            return [
                'success' => true,
                'status' => [
                    'config_id' => $configId,
                    'is_active' => ($config['is_active'] ?? false) === true,
                    'port_reachable' => true,
                    'can_authenticate' => false,
                    'database_exists' => null,
                    'auth_failed' => $isAuth,
                    'error' => $isAuth ? 'auth_failed' : 'connection_failed',
                    'user' => $user,
                    'host' => $host,
                    'port' => (string)$port,
                    'db_name' => $dbName,
                    'db_configs' => $cfgStatus,
                ],
            ];
        }
    }

    return [
        'success' => true,
        'status' => [
            'config_id' => $configId,
            'is_active' => ($config['is_active'] ?? false) === true,
            'port_reachable' => true,
            'can_authenticate' => true,
            'database_exists' => $exists,
            'user' => $user,
            'host' => $host,
            'port' => (string)$port,
            'db_name' => $dbName,
            'db_configs' => $cfgStatus,
        ],
    ];
}

function dbmanager_createProvisioner(string $provUser, string $provPass, string $adminConfigId = ''): array {
    $provUser = trim($provUser);
    $provPass = (string)$provPass;
    if ($provUser === '' || $provPass === '') {
        return ['success' => false, 'message' => 'Provisioner username and password are required'];
    }
    if ($adminConfigId === '') {
        $configs = getAllDatabaseConfigs();
        $candidates = array_values(array_filter($configs, function($c){
            return ($c['is_active'] ?? false) === true && (string)($c['port'] ?? '') === '3307' && (string)($c['storage_profile'] ?? '') === 'block_mysql' && (strpos(strtolower($c['name'] ?? ''), 'provisioner') !== false || strpos(strtolower($c['username'] ?? ''), 'root') !== false);
        }));
        if (empty($candidates)) {
            // fallback
            $candidates = array_values(array_filter($configs, function($c){
                return ($c['is_active'] ?? false) === true && (string)($c['port'] ?? '') === '3307' && (string)($c['storage_profile'] ?? '') === 'block_mysql';
            }));
        }
        if (!empty($candidates)) {
            $adminConfigId = (string)$candidates[0]['id'];
        }
    }
    $adminResult = getDatabaseConfigById($adminConfigId);
    if (!$adminResult['success']) {
        return ['success' => false, 'message' => 'Admin config not found or inactive; select a valid admin config on 3307'];
    }
    $admin = $adminResult['config'];
    if (($admin['is_active'] ?? false) !== true) {
        return ['success' => false, 'message' => 'Admin config is inactive'];
    }
    $host = (string)($admin['host'] ?? '127.0.0.1');
    $port = (string)($admin['port'] ?? '3307');
    if (!dbmanager_isAllowedBlockStorageHost($host) || $port !== '3307') {
        return ['success' => false, 'message' => 'Admin config must use 127.0.0.1:3307'];
    }
    if (!dbmanager_isTcpPortReachable($host, 3307, 0.8)) {
        return ['success' => false, 'message' => 'Port 3307 not reachable'];
    }
    $driver = strtolower((string)($admin['type'] ?? 'mysql'));
    if ($driver === 'mariadb') $driver = 'mysql';
    $adminCfg = [
        'driver' => $driver,
        'type' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => '',
        'username' => (string)($admin['username'] ?? ''),
        'password' => (string)($admin['password'] ?? ''),
        'charset' => (string)($admin['charset'] ?? 'utf8mb4'),
    ];
    try {
        $pdo = database_getConnectionFromConfig($adminCfg);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Admin authentication failed on 3307'];
    }
    try {
        $u = $pdo->quote($provUser);
        $p = $pdo->quote($provPass);
        $pdo->exec("CREATE USER IF NOT EXISTS {$u}@'localhost' IDENTIFIED BY {$p}");
        $pdo->exec("CREATE USER IF NOT EXISTS {$u}@'127.0.0.1' IDENTIFIED BY {$p}");
        $pdo->exec("ALTER USER {$u}@'localhost' IDENTIFIED BY {$p}");
        $pdo->exec("ALTER USER {$u}@'127.0.0.1' IDENTIFIED BY {$p}");
        $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO {$u}@'localhost' WITH GRANT OPTION");
        $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO {$u}@'127.0.0.1' WITH GRANT OPTION");
        $pdo->exec("FLUSH PRIVILEGES");
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Failed to create provisioner user: ' . $e->getMessage()];
    }
    $save = saveDatabaseConfig([
        'config_id' => uniqid('admin_', true),
        'name' => 'DB Provisioner (3307)',
        'storage_profile' => 'block_mysql',
        'host' => '127.0.0.1',
        'port' => '3307',
        'database' => 'information_schema',
        'username' => $provUser,
        'password' => $provPass,
        'type' => 'mysql',
        'charset' => 'utf8mb4',
        'context' => 'admin',
        'priority' => 0,
        'is_active' => true,
    ]);
    if (!$save['success']) {
        return ['success' => false, 'message' => 'Provisioner created on server but saving config failed: ' . ($save['message'] ?? '')];
    }
    return ['success' => true, 'message' => 'Provisioner created and saved: ' . ($save['config']['id'] ?? '')];
}
function dbmanager_valueLooksEncrypted(mixed $value): bool {
    if (!is_string($value)) {
        return false;
    }
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    if (strlen($value) < 44) {
        return false;
    }
    return base64_decode($value, true) !== false;
}

function dbmanager_encryptAndAssert(mixed $security, string $key, string $plain, string $field): string {
    $cipher = $security->encryptValue($plain, $key);
    if (!dbmanager_valueLooksEncrypted($cipher)) {
        throw new Exception("Encryption failed for {$field}");
    }
    $roundTrip = $security->decryptValue($cipher, $key);
    if (!is_string($roundTrip) || $roundTrip === '' || $roundTrip !== $plain) {
        throw new Exception("Encryption validation failed for {$field}");
    }
    return $cipher;
}

function dbmanager_load_db_configs_cached(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $configsPath = cue_autoload('paths')->getSecureFilePath('config/db_configs.json', true);
    if ($configsPath === false || !file_exists($configsPath)) {
        $cache = ['path' => is_string($configsPath) ? $configsPath : '', 'configs' => []];
        return $cache;
    }
    $decoded = json_decode((string)file_get_contents($configsPath), true);
    $configs = is_array($decoded) ? $decoded : [];
    if (isset($configs[0]) && is_array($configs[0])) {
        $converted = [];
        foreach ($configs as $cfg) {
            if (is_array($cfg) && isset($cfg['id'])) {
                $converted[(string)$cfg['id']] = $cfg;
            }
        }
        $configs = $converted;
    }
    $cache = ['path' => $configsPath, 'configs' => $configs];
    return $cache;
}

function dbmanager_migrateTenantConfigsToControlPlane(int $batchSize = 200, bool $dryRun = true): array {
    $batchSize = max(1, min(5000, $batchSize));
    if (!function_exists('mh_find_block_provisioner_config_id')) {
        return ['success' => false, 'message' => 'tenant_provisioning_unavailable'];
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
    if (function_exists('mh_control_plane_ensure_schema')) {
        if (!mh_control_plane_ensure_schema($adminPdo)) {
            return ['success' => false, 'message' => 'control_plane_schema_failed'];
        }
    } else {
        return ['success' => false, 'message' => 'control_plane_helpers_missing'];
    }
    if (function_exists('mh_control_plane_ensure_reader')) {
        mh_control_plane_ensure_reader($adminPdo, $adminConfigId);
    }
    if (!function_exists('mh_load_tenant_map_path')) {
        return ['success' => false, 'message' => 'tenant_map_path_unavailable'];
    }
    $tenantMapPath = mh_load_tenant_map_path();
    if (!is_string($tenantMapPath) || $tenantMapPath === '' || !file_exists($tenantMapPath)) {
        return ['success' => false, 'message' => 'tenant_map_missing'];
    }
    $tenantMap = json_decode((string)file_get_contents($tenantMapPath), true);
    if (!is_array($tenantMap)) {
        return ['success' => false, 'message' => 'tenant_map_invalid'];
    }
    $loaded = dbmanager_load_db_configs_cached();
    $configs = $loaded['configs'] ?? [];
    $configsPath = $loaded['path'] ?? '';
    if (!is_array($configs)) {
        $configs = [];
    }
    if (!is_string($configsPath) || $configsPath === '' || !file_exists($configsPath)) {
        return ['success' => false, 'message' => 'db_configs_missing'];
    }
    $now = date('Y-m-d H:i:s');
    $migrated = 0;
    $processed = 0;
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
        $fresh = json_decode((string)file_get_contents($configsPath), true);
        if (is_array($fresh)) {
            foreach ($deactivateIds as $id) {
                if (!isset($fresh[$id]) || !is_array($fresh[$id])) {
                    continue;
                }
                $fresh[$id]['is_active'] = false;
                $fresh[$id]['updated_at'] = $now;
                $deactivated++;
            }
            $ok = file_put_contents($configsPath, json_encode($fresh, JSON_PRETTY_PRINT), LOCK_EX);
            $jsonUpdated = $ok !== false;
        }
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

/**
 * Connect to database using configuration
 */
function connectToDatabase(array $config): mixed {
    try {
        // Use framework connection pool instead of direct PDO
        $driver = strtolower($config['type'] ?? 'mysql');
        $cfg = [
            'driver' => $driver,
            'type' => $driver,
            'host' => $config['host'] ?? 'localhost',
            'port' => $config['port'] ?? '3306',
            'database' => $config['database'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'charset' => $config['charset'] ?? 'utf8mb4',
        ];
        return database_getConnectionFromConfig($cfg);
    } catch (Exception $e) {
        error_log('Database connect error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Save database configuration
 */
function saveDatabaseConfig(array $config): array {
    try {
        // Use secure path validation consistent with cue.php
        $configsPath = cue_autoload('paths')->getSecureFilePath('config/db_configs.json', true);
        if ($configsPath === false) {
            throw new Exception('Invalid or unsafe configuration path');
        }
        // Ensure target directory exists and is writable (do NOT create directories implicitly)
        $configDir = dirname($configsPath);
        if (function_exists('validateSecurePath')) {
            $paths = cue_autoload('paths');
            $allowedBase = is_object($paths) && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '/data/config';
            if (validateSecurePath($configDir, $allowedBase) === false) {
                throw new Exception('Configuration directory failed secure path validation');
            }
        }
        if (!is_dir($configDir)) {
            throw new Exception('Configuration directory not found: ' . $configDir);
        }
        if (!is_writable($configDir)) {
            throw new Exception('Configuration directory not writable: ' . $configDir);
        }

        $configs = [];
        $storageProfile = $config['storage_profile'] ?? 'custom';
        $host = $config['host'] ?? 'localhost';
        
        // Force 127.0.0.1 for port 3307 to ensure TCP connection (avoid socket fallback)
        if (isset($config['port']) && (int)$config['port'] === 3307 && $host === 'localhost') {
            $host = '127.0.0.1';
        }

        if ($storageProfile !== null && strpos($storageProfile, 'block_') === 0 && !dbmanager_isAllowedBlockStorageHost($host)) {
            throw new Exception('Invalid host for block storage profile. Allowed hosts: localhost, 127.0.0.1');
        }
        
        if (file_exists($configsPath)) {
            $existingConfigs = json_decode(file_get_contents($configsPath), true) ?? [];
            
            // Convert array format to object format if needed (backward compatibility)
            if (isset($existingConfigs[0])) {
                // It's an array, convert to associative object
                foreach ($existingConfigs as $cfg) {
                    if (isset($cfg['id'])) {
                        $configs[$cfg['id']] = $cfg;
                    }
                }
            } else {
                // Already in object format
                $configs = $existingConfigs;
            }
        }

        // Generate proper config ID
        if (isset($config['config_id']) && !empty($config['config_id'])) {
            $configId = $config['config_id'];
            $isNew = !isset($configs[$configId]);
        } else {
            // Generate new unique ID for new configurations
            $configId = uniqid('db_', true);
            $isNew = true;
        }

        $adminConfigId = trim((string)($config['admin_config_id'] ?? ''));
        if ($adminConfigId !== '') {
            if ($adminConfigId === $configId) {
                throw new Exception('Admin config cannot reference itself');
            }
            if (!isset($configs[$adminConfigId]) || !is_array($configs[$adminConfigId])) {
                throw new Exception('Admin config not found: ' . $adminConfigId);
            }
            $adminCfg = $configs[$adminConfigId];
            if (($adminCfg['is_active'] ?? false) !== true) {
                throw new Exception('Admin config is inactive: ' . $adminConfigId);
            }
        }

        // Handle context-aware database selection
        $context = $config['context'] ?? 'default';
        $pageMapping = $config['page_mapping'] ?? [];
        
        // ENTERPRISE FEATURE: Multiple active databases based on context
        // No longer enforce single active database - allow multiple based on context/page mapping
        
        // Encrypt sensitive data
        $encryptionKey = getEncryptionKey();
        if (empty($encryptionKey)) {
            throw new Exception('Encryption key not available. Initialize CUE encryption key.');
        }
        $security = cue_autoload('security');
        $encHost = dbmanager_encryptAndAssert($security, $encryptionKey, (string) $host, 'host');
        $encDatabase = dbmanager_encryptAndAssert($security, $encryptionKey, (string) ($config['database'] ?? ''), 'database');
        $encUsername = dbmanager_encryptAndAssert($security, $encryptionKey, (string) ($config['username'] ?? ''), 'username');
        $encPassword = dbmanager_encryptAndAssert($security, $encryptionKey, (string) ($config['password'] ?? ''), 'password');
        $configs[$configId] = [
            'id' => $configId,
            'name' => $config['name'],
            'host' => $encHost,
            'port' => $config['port'],
            'database' => $encDatabase,
            'username' => $encUsername,
            'password' => $encPassword,
            'type' => $config['type'] ?? 'mysql',
            'charset' => $config['charset'] ?? 'utf8mb4',
            'context' => $context,
            'page_mapping' => $pageMapping,
            'priority' => $config['priority'] ?? 1,
            'created_at' => $configs[$configId]['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'is_active' => $config['is_active'] ?? true,
            'storage_profile' => $storageProfile,
            'admin_config_id' => $adminConfigId
        ];
        
        $encoded = json_encode($configs, JSON_PRETTY_PRINT);
        if (!is_string($encoded) || $encoded === '') {
            throw new Exception('Failed to encode database configurations');
        }
        $bytes = file_put_contents($configsPath, $encoded, LOCK_EX);
        if ($bytes === false) {
            throw new Exception('Failed to write database configurations file');
        }
        
        $message = $isNew ? 'Database configuration created successfully!' : 'Database configuration updated successfully!';
        return ['success' => true, 'message' => $message, 'config_id' => $configId];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to save configuration: ' . $e->getMessage()];
    }
}

/**
 * Delete database configuration
 */
function deleteDatabaseConfig(?string $configId): array {
    try {
        $configsPath = cue_autoload('paths')->getSecureFilePath('config/db_configs.json', true);
        if ($configsPath === false || !file_exists($configsPath)) {
            return ['success' => false, 'message' => 'No configurations found'];
        }
        
        $configs = json_decode(file_get_contents($configsPath), true) ?? [];
        
        // Convert array format to object format if needed (backward compatibility)
        if (isset($configs[0])) {
            // It's an array, convert to associative object
            $convertedConfigs = [];
            foreach ($configs as $cfg) {
                if (isset($cfg['id'])) {
                    $convertedConfigs[$cfg['id']] = $cfg;
                }
            }
            $configs = $convertedConfigs;
        }
        
        // ENTERPRISE FEATURE: Allow deletion of all databases
        // No restriction on minimum number of databases - enterprise systems may need complete cleanup
        
        // Find the configuration to delete
        if (!isset($configs[$configId])) {
            return ['success' => false, 'message' => 'Configuration not found'];
        }

        // Remove the configuration
        unset($configs[$configId]);
        
        $encoded = json_encode($configs, JSON_PRETTY_PRINT);
        if (!is_string($encoded) || $encoded === '') {
            throw new Exception('Failed to encode database configurations');
        }
        $bytes = file_put_contents($configsPath, $encoded, LOCK_EX);
        if ($bytes === false) {
            throw new Exception('Failed to write database configurations file');
        }
        
        return ['success' => true, 'message' => 'Configuration deleted successfully!'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to delete configuration: ' . $e->getMessage()];
    }
}

/**
 * Set active database configuration
 */
function toggleActiveDatabaseConfig(?string $configId): array {
    try {
        $configsPath = cue_autoload('paths')->getSecureFilePath('config/db_configs.json', true);
        
        if (!file_exists($configsPath)) {
            return ['success' => false, 'message' => 'No configurations found'];
        }
        
        $configs = json_decode(file_get_contents($configsPath), true) ?? [];
        
        // Find the configuration by ID
        $found = false;
        foreach ($configs as &$config) {
            if (isset($config['id']) && $config['id'] === $configId) {
                // Toggle the active status
                $config['is_active'] = !($config['is_active'] ?? false);
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return ['success' => false, 'message' => 'Configuration not found'];
        }
        
        $encoded = json_encode($configs, JSON_PRETTY_PRINT);
        if (!is_string($encoded) || $encoded === '') {
            return ['success' => false, 'message' => 'Failed to encode database configurations'];
        }
        $bytes = file_put_contents($configsPath, $encoded, LOCK_EX);
        if ($bytes === false) {
            return ['success' => false, 'message' => 'Failed to write database configurations file'];
        }
        
        $status = $config['is_active'] ? 'activated' : 'deactivated';
        return ['success' => true, 'message' => "Database configuration {$status} successfully!"];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to toggle configuration: ' . $e->getMessage()];
    }
}

/**
 * Get database configuration by ID
 */
function getDatabaseConfigById(?string $configId): array {
    try {
        $loaded = dbmanager_load_db_configs_cached();
        $configs = $loaded['configs'] ?? [];
        if (!is_array($configs) || empty($configs)) {
            return ['success' => false, 'message' => 'No configurations found'];
        }
        if (!is_string($configId) || $configId === '') {
            return ['success' => false, 'message' => 'Configuration not found'];
        }
        
        // Find the configuration by ID
        if (!isset($configs[$configId])) {
            return ['success' => false, 'message' => 'Configuration not found'];
        }
        
        $config = $configs[$configId];
        $encryptionKey = getEncryptionKey();

        $security = cue_autoload('security');
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!isset($config[$field]) || !dbmanager_valueLooksEncrypted($config[$field])) {
                return ['success' => false, 'message' => "Configuration violates encryption policy: {$field} is not encrypted"];
            }
        }

        $config['host'] = $security->decryptValue($config['host'], $encryptionKey);
        $config['database'] = $security->decryptValue($config['database'], $encryptionKey);
        $config['username'] = $security->decryptValue($config['username'], $encryptionKey);
        $config['password'] = $security->decryptValue($config['password'], $encryptionKey);
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!is_string($config[$field]) || $config[$field] === '') {
                return ['success' => false, 'message' => "Configuration decryption failed: {$field}"];
            }
        }
        if (!isset($config['storage_profile'])) {
            $config['storage_profile'] = 'legacy';
        }
        if (!isset($config['admin_config_id'])) {
            $config['admin_config_id'] = '';
        }
        
        return ['success' => true, 'config' => $config];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to get configuration: ' . $e->getMessage()];
    }
}

/**
 * Get all database configurations
 */
function getAllDatabaseConfigs(): array {
    try {
        $loaded = dbmanager_load_db_configs_cached();
        $configs = $loaded['configs'] ?? [];
        if (!is_array($configs) || empty($configs)) {
            return [];
        }
        $configArray = [];
        foreach ($configs as $configId => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (!isset($config['id']) && is_string($configId) && $configId !== '') {
                $config['id'] = $configId;
            }
            unset($config['password']);
            if (!isset($config['storage_profile'])) {
                $config['storage_profile'] = 'legacy';
            }
            $configArray[] = $config;
        }
        return $configArray;
        
    } catch (Exception $e) {
        error_log("Error in getAllDatabaseConfigs: " . $e->getMessage());
        return [];
    }
}

/**
 * Switch to a specific database configuration for dbmanager interface
 */
function switchToDatabaseForManager(?string $configId): array {
    try {
        $config = getDatabaseConfigById($configId);
        
        if (!$config['success']) {
            return ['success' => false, 'message' => 'Configuration not found'];
        }
        
        // Test the connection first
        $testResult = testDatabaseConnection($config['config']);
        
        if (!$testResult['success']) {
            return ['success' => false, 'message' => 'Cannot switch to database: ' . $testResult['message']];
        }
        
        // Store the current database configuration in session or a temporary file
        // This allows the CRUD interface to use this specific database
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) { session_start(); }
        }
        $_SESSION['current_database_config'] = $config['config'];
        
        return ['success' => true, 'message' => 'Successfully switched to database: ' . $config['config']['name']];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to switch database: ' . $e->getMessage()];
    }
}

/**
 * Get active databases
 */
function getActiveDatabases(): array {
    try {
        $loaded = dbmanager_load_db_configs_cached();
        $configs = $loaded['configs'] ?? [];
        if (!is_array($configs) || empty($configs)) {
            return ['success' => false, 'message' => 'No database configurations found'];
        }
        $activeConfigs = [];

        foreach ($configs as $configId => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (!isset($config['id']) && is_string($configId) && $configId !== '') {
                $config['id'] = $configId;
            }
            // Only include databases that are explicitly active
            if (!isset($config['is_active']) || $config['is_active'] !== true) {
                continue; // Skip inactive databases
            }
            
            // Remove password for security
            $safeConfig = $config;
            unset($safeConfig['password']);
            $activeConfigs[] = $safeConfig;
        }
        
        if (empty($activeConfigs)) {
            $all = getAllDatabaseConfigs();
            return [
                'success' => true,
                'databases' => array_map(function($cfg){
                    $safe = $cfg;
                    unset($safe['password']);
                    return $safe;
                }, $all)
            ];
        }
        
        return [
            'success' => true,
            'databases' => $activeConfigs
        ];
    } catch (Exception $e) {
        error_log("Error in getActiveDatabases: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error loading database configurations'];
    }
}

function dbmanager_isTenantDbConfig(array $cfg): bool {
    $id = isset($cfg['id']) ? (string)$cfg['id'] : '';
    if ($id !== '' && str_starts_with($id, 'tenant_')) {
        return true;
    }
    $ctx = isset($cfg['context']) ? (string)$cfg['context'] : '';
    if ($ctx !== '' && strcasecmp($ctx, 'tenant') === 0) {
        return true;
    }
    $name = isset($cfg['name']) ? (string)$cfg['name'] : '';
    if ($name !== '' && (str_starts_with($name, 'tenant_user_') || str_starts_with($name, 'tenant_persona_'))) {
        return true;
    }
    $db = isset($cfg['database']) ? (string)$cfg['database'] : '';
    if ($db !== '' && (str_starts_with($db, 'tenant_user_') || str_starts_with($db, 'tenant_persona_'))) {
        return true;
    }
    return false;
}

function dbmanager_listTenantDbConfigsFromControlPlane(int $limit = 200, int $offset = 0, string $query = ''): array {
    $limit = max(1, min(2000, $limit));
    $offset = max(0, $offset);
    $query = trim($query);
    try {
        $pdo = null;
        try {
            $pdo = database_getConnectionById('control_plane');
        } catch (Throwable $e) {
            $pdo = null;
        }
        if (!($pdo instanceof PDO)) {
            return ['success' => true, 'tenants' => [], 'has_more' => false, 'offset' => $offset, 'limit' => $limit, 'query' => $query];
        }
        $sql = "SELECT c.db_config_id, c.config_json, c.is_active, m.tenant_id
                FROM mh_control.db_configs c
                LEFT JOIN mh_control.tenant_db_map m ON m.db_config_id = c.db_config_id
                WHERE c.db_config_id LIKE 'tenant\\_%'";
        $params = [];
        if ($query !== '') {
            $sql .= " AND (m.tenant_id LIKE :q OR c.db_config_id LIKE :q)";
            $params[':q'] = '%' . $query . '%';
        }
        $sql .= " ORDER BY m.tenant_id ASC, c.db_config_id ASC LIMIT :lim OFFSET :off";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit + 1, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = is_array($rows) && count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $tenants = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['db_config_id']) ? (string)$row['db_config_id'] : '';
            $json = isset($row['config_json']) ? (string)$row['config_json'] : '';
            if ($id === '' || $json === '') continue;
            $cfg = json_decode($json, true);
            if (!is_array($cfg)) continue;
            $cfg['id'] = $id;
            $cfg['is_active'] = ((int)($row['is_active'] ?? 1)) === 1;
            unset($cfg['password']);
            $tenantId = isset($row['tenant_id']) ? (string)$row['tenant_id'] : '';
            if ($tenantId !== '') {
                $cfg['tenant_id'] = $tenantId;
            }
            $tenants[] = $cfg;
        }
        return ['success' => true, 'tenants' => $tenants, 'has_more' => $hasMore, 'offset' => $offset, 'limit' => $limit, 'query' => $query];
    } catch (Throwable $e) {
        return ['success' => true, 'tenants' => [], 'has_more' => false, 'offset' => $offset, 'limit' => $limit, 'query' => $query];
    }
}

function dbmanager_createDatabaseIfMissing(?string $configId): array {
    try {
        if (empty($configId)) {
            return ['success' => false, 'message' => 'Configuration ID is required'];
        }
        $cfgStatus = dbmanager_getDbConfigsJsonStatus();
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        $config = $configResult['config'];
        if (isset($config['is_active']) && $config['is_active'] !== true) {
            return ['success' => false, 'message' => 'Database configuration is inactive. Activate it before creating the database.'];
        }
        $adminConfigId = trim((string)($config['admin_config_id'] ?? ''));
        if ($adminConfigId === '') {
            return ['success' => false, 'message' => 'Admin config is required for Create Database. Edit this configuration and select an Admin Config (Provisioner).'];
        }
        $adminResult = getDatabaseConfigById($adminConfigId);
        if (!$adminResult['success']) {
            return ['success' => false, 'message' => 'Admin config not found or not accessible: ' . ($adminResult['message'] ?? $adminConfigId)];
        }
        $admin = $adminResult['config'];
        if (($admin['is_active'] ?? false) !== true) {
            return ['success' => false, 'message' => 'Admin config is inactive: ' . $adminConfigId];
        }
        $dbType = strtolower($config['type'] ?? '');
        if (!in_array($dbType, ['mysql', 'mariadb'], true)) {
            return ['success' => false, 'message' => 'Create database is only supported for MySQL/MariaDB configurations'];
        }
        $driver = strtolower((string)($admin['type'] ?? $dbType));
        if ($driver === 'mariadb') {
            $driver = 'mariadb';
        }
        
        $adminHost = $admin['host'] ?? '127.0.0.1';
        $adminPort = $admin['port'] ?? '3307';
        $adminUser = $admin['username'] ?? '';
        $adminPass = $admin['password'] ?? '';
        
        $storageProfile = $config['storage_profile'] ?? '';
        
        // Force block storage check if port is 3307 (Block Storage Port)
        if (isset($config['port']) && (int)$config['port'] === 3307) {
            $storageProfile = 'block_mysql';
        }

        if ($storageProfile === 'block_mysql') {
            if ((string)$adminPort !== '3307') {
                return ['success' => false, 'message' => 'Admin config must use port 3307 for block_mysql. Selected admin config: ' . $adminConfigId];
            }
            if (!dbmanager_isAllowedBlockStorageHost((string)$adminHost)) {
                return ['success' => false, 'message' => 'Admin config must use localhost/127.0.0.1 for block_mysql. Selected admin config: ' . $adminConfigId];
            }
            $reachable = dbmanager_isTcpPortReachable($adminHost, (int)$adminPort, 0.8);
            if (!$reachable) {
                $details = [];
                $details[] = "3307 instance not running or not reachable at {$adminHost}:{$adminPort}";
                $details[] = "Expected block storage MariaDB to be listening for block_mysql (e.g. mariadb-biometrics.service)";
                if (is_array($cfgStatus) && ($cfgStatus['ok'] ?? false) === true) {
                    $details[] = "db_configs.json=" . ($cfgStatus['path'] ?? '');
                    $details[] = "db_configs.exists=" . (($cfgStatus['exists'] ?? false) ? 'true' : 'false');
                    $details[] = "db_configs.writable_file=" . (($cfgStatus['writable_file'] ?? false) ? 'true' : 'false');
                } else {
                    $details[] = "db_configs.status=" . ($cfgStatus['message'] ?? 'unknown');
                }
                return ['success' => false, 'message' => implode(' / ', $details)];
            }
        }
        
        $adminCfg = [
            'driver' => $driver,
            'type' => $driver,
            'host' => $adminHost,
            'port' => $adminPort,
            'database' => '',
            'username' => $adminUser,
            'password' => $adminPass,
            'charset' => $config['charset'] ?? 'utf8mb4',
        ];
        try {
            $pdo = database_getConnectionFromConfig($adminCfg);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'SQLSTATE[HY000] [1045]') !== false || stripos($msg, 'Access denied') !== false) {
                $details = [];
                $details[] = 'Access denied while connecting to the target server for CREATE DATABASE';
                $details[] = 'This uses the Admin Config (Provisioner) credentials, not the app user.';
                $details[] = 'Ensure the admin DB user can CREATE DATABASE, CREATE USER, and GRANT on port 3307.';
                $details[] = 'admin_config_id=' . $adminConfigId;
                if (is_array($cfgStatus) && ($cfgStatus['ok'] ?? false) === true) {
                    $details[] = "db_configs.json=" . ($cfgStatus['path'] ?? '');
                }
                return ['success' => false, 'message' => implode(' / ', $details)];
            }
            throw $e;
        }
        $databaseName = $config['database'] ?? '';
        if ($databaseName === '') {
            return ['success' => false, 'message' => 'Database name is required in configuration'];
        }
        $dbIdentifier = str_replace('`', '``', $databaseName);
        $charset = $config['charset'] ?? 'utf8mb4';
        $charsetSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $charset);
        if ($charsetSafe === '') {
            $charsetSafe = 'utf8mb4';
        }
        $createSql = "CREATE DATABASE IF NOT EXISTS `" . $dbIdentifier . "` CHARACTER SET " . $charsetSafe;
        try {
            $pdo->exec($createSql);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'SQLSTATE[HY000] [1045]') !== false || stripos($msg, 'Access denied') !== false) {
                $details = [];
                $details[] = 'Access denied while executing CREATE DATABASE';
                $details[] = 'The configured user lacks CREATE DATABASE on the target MariaDB instance.';
                $details[] = 'Create a dedicated admin DB user on port 3307 with minimum required privileges and store it as an encrypted config in db_configs.json; then run Create Database again.';
                return ['success' => false, 'message' => implode(' / ', $details)];
            }
            throw $e;
        }
        $user = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $grantsApplied = false;
        $grantMessages = [];
        if ($user !== '') {
            $userQuoted = $pdo->quote((string)$user);
            $passQuoted = $pdo->quote((string)$password);
            try {
                $pdo->exec("CREATE USER IF NOT EXISTS {$userQuoted}@'localhost' IDENTIFIED BY {$passQuoted}");
                $pdo->exec("CREATE USER IF NOT EXISTS {$userQuoted}@'127.0.0.1' IDENTIFIED BY {$passQuoted}");
                $pdo->exec("ALTER USER {$userQuoted}@'localhost' IDENTIFIED BY {$passQuoted}");
                $pdo->exec("ALTER USER {$userQuoted}@'127.0.0.1' IDENTIFIED BY {$passQuoted}");
            } catch (Exception $e) {
                $grantMessages[] = 'Could not ensure database user: ' . $e->getMessage();
            }
            try {
                $pdo->exec("GRANT ALL PRIVILEGES ON `" . $dbIdentifier . "`.* TO {$userQuoted}@'localhost'");
                $pdo->exec("GRANT ALL PRIVILEGES ON `" . $dbIdentifier . "`.* TO {$userQuoted}@'127.0.0.1'");
                $pdo->exec("FLUSH PRIVILEGES");
                $grantsApplied = true;
            } catch (Exception $e) {
                $grantMessages[] = 'Could not grant privileges: ' . $e->getMessage();
            }
        }
        $message = 'Database created or already exists: ' . $databaseName;
        if ($grantsApplied) {
            $message .= '. User privileges applied.';
        } elseif (!empty($grantMessages)) {
            $message .= '. Permissions were not fully applied automatically. Please ensure the configured user has access to this database. Details: ' . implode(' | ', $grantMessages);
        }
        return ['success' => true, 'message' => $message];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to create database: ' . $e->getMessage()];
    }
}

/**
 * Get tables from a specific database with persistent caching
 */
function getDatabaseTables(?string $configId = null): array {
    // Disable output buffering to prevent HTML errors in JSON responses
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    try {
        $currentTime = time();
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                try {
                    $db = getDatabaseById($configId);
                    
                    // Check if connection failed
                    if (!$db) {
                        throw new Exception('Database connection returned null');
                    }
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                try {
                    $db = cue_autoload('database')->getContextAwareConnection();
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!$db) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        $pdo = (is_object($db) && property_exists($db, 'pdo')) ? $db->pdo : $db;
        
        if (!$pdo) {
             return ['success' => false, 'message' => 'Failed to establish PDO connection'];
        }
        
        // Get table information using CUE framework
        $infoQuery = "
            SELECT 
                t.TABLE_NAME as table_name,
                COALESCE(t.TABLE_ROWS, 0) as table_rows,
                t.TABLE_TYPE as table_type,
                t.ENGINE as table_engine,
                COALESCE(t.DATA_LENGTH + t.INDEX_LENGTH, 0) as size_bytes
            FROM information_schema.TABLES t 
            WHERE t.TABLE_SCHEMA = DATABASE() 
            AND t.TABLE_TYPE = 'BASE TABLE'
            ORDER BY t.TABLE_NAME ASC
        ";
        
        try {
            $tables = database_query($infoQuery, [], $pdo);
        } catch (Exception $e) {
            try {
                $fallbackRows = database_query("SHOW TABLES", [], $pdo);
                $formattedTables = [];
                foreach ($fallbackRows as $row) {
                    $tableName = array_values($row)[0] ?? null;
                    if ($tableName) {
                        $formattedTables[] = [
                            'name' => $tableName,
                            'rows' => 0,
                            'type' => 'BASE TABLE',
                            'engine' => 'Unknown',
                            'size' => 0
                        ];
                    }
                }
                return [
                    'success' => true,
                    'tables' => $formattedTables,
                    'cached' => false,
                    'timestamp' => $currentTime,
                    'database_type' => 'MariaDB/MySQL'
                ];
            } catch (Exception $e2) {
                return ['success' => false, 'message' => 'Failed to retrieve table information: ' . $e2->getMessage()];
            }
        }
        
        // Format the data
        $formattedTables = [];
        foreach ($tables as $table) {
            $formattedTables[] = [
                'name' => $table['table_name'],
                'rows' => (int)$table['table_rows'],
                'type' => $table['table_type'] ?? 'BASE TABLE',
                'engine' => $table['table_engine'] ?? 'Unknown',
                'size' => (int)$table['size_bytes']
            ];
        }
        
        $result = [
            'success' => true, 
            'tables' => $formattedTables,
            'cached' => false,
            'timestamp' => $currentTime,
            'database_type' => 'MariaDB/MySQL'
        ];
        
        // Caching removed to improve performance
        
        return $result;
        
    } catch (Exception $e) {
        return [
            'success' => false, 
            'message' => 'Failed to get tables: ' . $e->getMessage(),
            'error_code' => $e->getCode(),
            'database_type' => 'MariaDB/MySQL'
        ];
    }
}

/**
 * Get table structure
 */
function getTableStructure(?string $configId, string $tableName): array {
    try {
        if (empty($tableName)) {
            return ['success' => false, 'message' => 'Table name is required'];
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                try {
                    $db = getDatabaseById($configId);
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                try {
                    $db = cue_autoload('database')->getContextAwareConnection();
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!$db) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        $pdo = resolvePDO($db);
        if (!$pdo) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        try {
            $structure = database_query("DESCRIBE `{$tableName}`", [], $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to get table structure: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'structure' => $structure];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to get table structure: ' . $e->getMessage()];
    }
}

/**
 * Get table records with pagination
 */
function getTableRecords(array $params): array {
    try {
        $configId = $params['config_id'] ?? null;
        $tableName = $params['table'] ?? '';
        $page = max(1, (int)($params['page'] ?? 1));
        $rawLimit = $params['limit'] ?? 20;
        $search = isset($params['search']) ? trim((string)$params['search']) : '';
        if (strlen($search) > 200) {
            $search = substr($search, 0, 200);
        }
        
        if (empty($tableName)) {
            return ['success' => false, 'message' => 'Table name is required'];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$tableName)) {
            return ['success' => false, 'message' => 'Invalid table name'];
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                try {
                    $db = getDatabaseById($configId);
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                try {
                    $db = cue_autoload('database')->getContextAwareConnection();
                } catch (Throwable $e) {
                    return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }

            $limitAll = false;
            $limit = 20;
            if (is_string($rawLimit) && strtolower(trim($rawLimit)) === 'all') {
                $limitAll = true;
            } else {
                $limit = (int)$rawLimit;
                if ($limit < 1) $limit = 20;
                if ($limit > 100) $limit = 100;
            }

            $columns = [];
            $primaryKey = '';
            $desc = database_query("DESCRIBE `{$tableName}`", [], $pdo);
            if (is_array($desc)) {
                foreach ($desc as $row) {
                    if (!is_array($row)) continue;
                    $field = isset($row['Field']) ? (string)$row['Field'] : '';
                    if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) continue;
                    $columns[] = $field;
                    $key = isset($row['Key']) ? (string)$row['Key'] : '';
                    if ($primaryKey === '' && $key === 'PRI') {
                        $primaryKey = $field;
                    }
                }
            }
            if (!$columns) {
                return ['success' => false, 'message' => 'Table has no columns'];
            }

            $whereSql = '';
            $binds = [];
            if ($search !== '') {
                $like = '%' . $search . '%';
                $whereParts = [];
                $maxCols = min(50, count($columns));
                for ($i = 0; $i < $maxCols; $i++) {
                    $col = $columns[$i];
                    $whereParts[] = "`{$col}` LIKE ?";
                    $binds[] = $like;
                }
                if ($whereParts) {
                    $whereSql = ' WHERE (' . implode(' OR ', $whereParts) . ')';
                }
            }

            $countSql = "SELECT COUNT(*) as count FROM `{$tableName}`" . $whereSql;
            $countResult = database_query($countSql, $binds, $pdo);
            $totalRecords = isset($countResult[0]['count']) ? (int)$countResult[0]['count'] : 0;

            $totalPages = 1;
            if (!$limitAll) {
                $totalPages = max(1, (int)ceil($totalRecords / $limit));
                if ($page > $totalPages) $page = $totalPages;
            } else {
                $page = 1;
                $limit = max(1, $totalRecords);
            }

            $orderSql = '';
            if ($primaryKey !== '') {
                $orderSql = " ORDER BY `{$primaryKey}` DESC";
            }

            $recordsSql = "SELECT * FROM `{$tableName}`" . $whereSql . $orderSql;
            $offset = 0;
            if (!$limitAll) {
                $offset = ($page - 1) * $limit;
                $recordsSql .= " LIMIT {$limit} OFFSET {$offset}";
            }

            $records = database_query($recordsSql, $binds, $pdo);
            if (!is_array($records)) $records = [];

            $from = $totalRecords > 0 ? ($offset + 1) : 0;
            $to = $limitAll ? $totalRecords : min($totalRecords, $offset + count($records));
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to get table records: ' . $e->getMessage()];
        }
        
        return [
            'success' => true,
            'records' => $records,
            'current_page' => $page,
            'total_records' => $totalRecords,
            'records_per_page' => $limitAll ? 'all' : $limit,
            'total_pages' => $limitAll ? 1 : $totalPages,
            'pagination' => [
                'current_page' => $page,
                'total_records' => $totalRecords,
                'records_per_page' => $limitAll ? 'all' : $limit,
                'total_pages' => $limitAll ? 1 : $totalPages,
                'from' => $from,
                'to' => $to,
                'search' => $search
            ]
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to get records: ' . $e->getMessage()];
    }
}

/**
 * Create new table record
 */
function createTableRecord(array $params): array {
    try {
        $configId = $params['config_id'] ?? null;
        $tableName = $params['table'] ?? '';
        $data = $params['data'] ?? [];
        
        if (empty($tableName) || empty($data)) {
            return ['success' => false, 'message' => 'Table name and data are required'];
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                $db = getDatabaseById($configId);
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                $db = cue_autoload('database')->getContextAwareConnection();
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            
            $sql = "INSERT INTO `{$tableName}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
            
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }
            database_execute($sql, array_values($data), $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create record: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'message' => 'Record created successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to create record: ' . $e->getMessage()];
    }
}

/**
 * Update table record
 */
function updateTableRecord(array $params): array {
    try {
        $configId = $params['config_id'] ?? null;
        $tableName = $params['table'] ?? '';
        $data = $params['data'] ?? [];
        $where = $params['where'] ?? [];
        
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) { $data = $decoded; }
        }
        if (is_string($where)) {
            $decodedW = json_decode($where, true);
            if (is_array($decodedW)) { $where = $decodedW; }
        }
        
        if (empty($tableName) || empty($data) || empty($where)) {
            return ['success' => false, 'message' => 'Table name, data, and where conditions are required'];
        }

        if ($tableName === 'users') {
            if (isset($where['id']) && $where['id'] !== '' && $where['id'] !== null) {
                $where = ['id' => $where['id']];
            } elseif (isset($where['username']) && $where['username'] !== '' && $where['username'] !== null) {
                $where = ['username' => $where['username']];
            } else {
                return ['success' => false, 'message' => 'Missing primary key for users table'];
            }
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                $db = getDatabaseById($configId);
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                $db = cue_autoload('database')->getContextAwareConnection();
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $setParts = array_map(fn($col) => "`{$col}` = ?", array_keys($data));
            $whereParts = array_map(fn($col) => "`{$col}` = ?", array_keys($where));
            
            $sql = "UPDATE `{$tableName}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);
            
            $params = array_merge(array_values($data), array_values($where));
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }
            database_execute($sql, $params, $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to update record: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'message' => 'Record updated successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to update record: ' . $e->getMessage()];
    }
}

/**
 * Delete table record
 */
function deleteTableRecord(array $params): array {
    try {
        $configId = $params['config_id'] ?? null;
        $tableName = $params['table'] ?? '';
        $where = $params['where'] ?? [];
        
        if (is_string($where)) {
            $decoded = json_decode($where, true);
            if (is_array($decoded)) {
                $where = $decoded;
            } else {
                return ['success' => false, 'message' => 'Invalid where format'];
            }
        }
        
        if (empty($tableName) || empty($where)) {
            return ['success' => false, 'message' => 'Table name and where conditions are required'];
        }

        if ($tableName === 'users') {
            if (isset($where['id']) && $where['id'] !== '' && $where['id'] !== null) {
                $where = ['id' => $where['id']];
            } elseif (isset($where['username']) && $where['username'] !== '' && $where['username'] !== null) {
                $where = ['username' => $where['username']];
            } else {
                return ['success' => false, 'message' => 'Missing primary key for users table'];
            }
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                $db = getDatabaseById($configId);
                if (!$db) {
                    return ['success' => false, 'message' => 'Failed to connect to database'];
                }
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                $db = cue_autoload('database')->getContextAwareConnection();
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $whereParts = array_map(fn($col) => "`{$col}` = ?", array_keys($where));
            
            $sql = "DELETE FROM `{$tableName}` WHERE " . implode(' AND ', $whereParts);
            
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }
            database_execute($sql, array_values($where), $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete record: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'message' => 'Record deleted successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to delete record: ' . $e->getMessage()];
    }
}

/**
 * Create new table
 */
function createNewTable(array $params): array {
    try {
        $configId = $params['config_id'] ?? null;
        $tableName = $params['table_name'] ?? '';
        $columns = $params['columns'] ?? [];
        
        if (empty($tableName) || empty($columns)) {
            return ['success' => false, 'message' => 'Table name and columns are required'];
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                $db = getDatabaseById($configId);
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                $db = cue_autoload('database')->getContextAwareConnection();
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $columnDefinitions = [];
            foreach ($columns as $column) {
                $name = $column['name'] ?? '';
                $type = $column['type'] ?? 'VARCHAR(255)';
                $nullable = ($column['nullable'] ?? false) ? '' : ' NOT NULL';
                $default = !empty($column['default']) ? " DEFAULT '{$column['default']}'" : '';
                
                if (!empty($name)) {
                    $columnDefinitions[] = "`{$name}` {$type}{$nullable}{$default}";
                }
            }
            
            if (empty($columnDefinitions)) {
                return ['success' => false, 'message' => 'At least one valid column is required'];
            }
            
            $sql = "CREATE TABLE `{$tableName}` (" . implode(', ', $columnDefinitions) . ")";
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }
            database_execute($sql, [], $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create table: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'message' => 'Table created successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to create table: ' . $e->getMessage()];
    }
}

/**
 * Drop table
 */
function dropTable(?string $configId, string $tableName): array {
    try {
        if (empty($tableName)) {
            return ['success' => false, 'message' => 'Table name is required'];
        }
        
        $db = null;
        
        if ($configId) {
            // Use CUE Framework function for specific database
            try {
                $db = getDatabaseById($configId);
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
            }
        } else {
            try {
                $db = cue_autoload('database')->getContextAwareConnection();
            } catch (Throwable $e) {
                return ['success' => false, 'message' => 'Failed to connect to default database: ' . $e->getMessage()];
            }
        }
        
        if (!resolvePDO($db)) {
            return ['success' => false, 'message' => 'Database connection not available'];
        }
        
        try {
            $pdo = resolvePDO($db);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Database connection not available'];
            }
            database_execute("DROP TABLE `{$tableName}`", [], $pdo);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to drop table: ' . $e->getMessage()];
        }
        
        return ['success' => true, 'message' => 'Table dropped successfully'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to drop table: ' . $e->getMessage()];
    }
}

/**
 * Upload SQL file
 */
function uploadSqlFile(array $files): array {
    try {
        if (!isset($files['sql_file']) || $files['sql_file']['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No file uploaded or upload error'];
        }
        
        $file = $files['sql_file'];
        $fileName = $file['name'];
        $tmpName = $file['tmp_name'];
        
        // Validate file extension
        $allowedExtensions = ['sql', 'txt'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            return ['success' => false, 'message' => 'Only SQL and TXT files are allowed'];
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = cue_autoload('paths')->getSecureFilePath('config/databases/uploaded/', true);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename to prevent conflicts
        $uniqueFileName = date('Y-m-d_H-i-s') . '_' . $fileName;
        $uploadPath = $uploadDir . $uniqueFileName;
        
        if (move_uploaded_file($tmpName, $uploadPath)) {
            return [
                'success' => true, 
                'message' => 'File uploaded successfully',
                'file_path' => $uploadPath,
                'file_name' => $uniqueFileName
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to move uploaded file'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()];
    }
}

/**
 * Browse SQL files in databases directory
 */
function browseSqlFiles(string $path = ''): array {
    try {
        $basePath = cue_autoload('paths')->getSecureFilePath('config/databases/', true);
        $fullPath = $basePath . ltrim($path, '/');
        
        // Security check - ensure path is within allowed directory
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);
        
        if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
            return ['success' => false, 'message' => 'Invalid path'];
        }
        
        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => 'Directory not found'];
        }
        
        $items = [];
        $iterator = new DirectoryIterator($fullPath);
        
        foreach ($iterator as $item) {
            if ($item->isDot()) continue;
            
            $itemRealPath = realpath($item->getPathname());
            $relativePath = str_replace($realBasePath, '', $itemRealPath);
            $relativePath = str_replace('\\', '/', $relativePath);
            $relativePath = ltrim($relativePath, '/');
            
            $itemInfo = [
                'name' => $item->getFilename(),
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
                'modified' => $item->getMTime(),
                'path' => $relativePath
            ];
            
            // Only include SQL files and directories
            if ($item->isDir() || in_array(strtolower($item->getExtension()), ['sql', 'txt'])) {
                $items[] = $itemInfo;
            }
        }
        
        // Sort items - directories first, then files
        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        return ['success' => true, 'items' => $items, 'current_path' => $path];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to browse files: ' . $e->getMessage()];
    }
}

/**
 * Parse SQL statements from content - handles complex SQL with JSON, comments, and multi-line statements
 */
function parseSqlStatements(string $sqlContent): array {
    // Remove SQL comments (-- and /* */)
    $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);
    $sqlContent = preg_replace('/\/\*.*?\*\//s', '', $sqlContent);
    
    $statements = [];
    $currentStatement = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sqlContent);
    
    for ($i = 0; $i < $length; $i++) {
        $char = $sqlContent[$i];
        
        // Handle string literals
        if (!$inString && ($char === '"' || $char === "'")) {
            $inString = true;
            $stringChar = $char;
            $currentStatement .= $char;
        } elseif ($inString && $char === $stringChar) {
            // Check for escaped quotes
            if ($i > 0 && $sqlContent[$i - 1] === '\\') {
                $currentStatement .= $char;
            } else {
                $inString = false;
                $currentStatement .= $char;
            }
        } elseif (!$inString && $char === ';') {
            // End of statement
            $statement = trim($currentStatement);
            if (!empty($statement)) {
                $statements[] = $statement;
            }
            $currentStatement = '';
        } else {
            $currentStatement .= $char;
        }
    }
    
    // Add final statement if exists
    $statement = trim($currentStatement);
    if (!empty($statement)) {
        $statements[] = $statement;
    }
    
    return array_filter($statements, function($stmt) {
        return !empty(trim($stmt));
    });
}

/**
 * Execute SQL file
 */
function executeSqlFile(array $params): array {
    try {
        $database = $params['database'] ?? null;
        $filePath = $params['file_path'] ?? '';
        
        if (empty($filePath)) {
            return ['success' => false, 'message' => 'File path is required'];
        }
        
        if (empty($database)) {
            return ['success' => false, 'message' => 'Database is required'];
        }
        
        // Security check - ensure file is within allowed directory
        $basePath = cue_autoload('paths')->getSecureFilePath('config/databases/', true);
        $fullPath = $basePath . ltrim($filePath, '/');
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);
        
        if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
            return ['success' => false, 'message' => 'Invalid file path'];
        }
        
        if (!file_exists($fullPath)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        // Get database configuration by name
        $configs = getAllDatabaseConfigs();
        $configId = null;
        
        foreach ($configs as $cfg) {
            if ($cfg['database'] === $database) {
                $configId = $cfg['id'];
                break;
            }
        }
        
        if (!$configId) {
            return ['success' => false, 'message' => 'Database configuration not found'];
        }
        
        // Use CUE Framework function for database connection
        try {
            $db = getDatabaseById($configId);
            if (!$db) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        $sqlContent = file_get_contents($fullPath);
        
        // Parse SQL content into individual statements (improved parsing)
        $statements = parseSqlStatements($sqlContent);
        
        $executedCount = 0;
        $errors = [];
        
        try {
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    try {
                        database_execute($statement, [], resolvePDO($db));
                        $executedCount++;
                    } catch (Exception $e) {
                        $errors[] = "Statement failed: " . substr($statement, 0, 100) . "... Error: " . $e->getMessage();
                        // Continue with next statement instead of failing completely
                    }
                }
            }
            
            $successMessage = "Successfully executed {$executedCount} SQL statements";
            if (!empty($errors)) {
                $successMessage .= ". " . count($errors) . " statements had errors.";
            }
            
            return [
                'success' => true, 
                'message' => $successMessage,
                'executed_count' => $executedCount,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'SQL execution failed: ' . $e->getMessage()];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to execute SQL file: ' . $e->getMessage()];
    }
}

/**
 * Search SQL files
 */
function searchSqlFiles(string $query): array {
    try {
        if (empty($query)) {
            return ['success' => false, 'message' => 'Search query is required'];
        }
        
        $basePath = cue_autoload('paths')->getSecureFilePath('config/databases/', true);
        $results = [];
        
        if (!is_dir($basePath)) {
            return ['success' => true, 'results' => []];
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['sql', 'txt'])) {
                $fileName = $file->getFilename();
                $relativePath = str_replace($basePath, '', $file->getPathname());
                
                // Search in filename
                if (stripos($fileName, $query) !== false) {
                    $results[] = [
                        'name' => $fileName,
                        'path' => $relativePath,
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                        'match_type' => 'filename'
                    ];
                }
            }
        }
        
        return ['success' => true, 'results' => $results, 'query' => $query];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Search failed: ' . $e->getMessage()];
    }
}

/**
 * Save all tables from a database to SQL files
 */
function saveAllTables(?string $configId, bool $includeData = false): array {
    try {
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        $config = $configResult['config'];

        // Get database connection using CUE framework
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        // Get all tables directly using the connection to avoid additional rate limit hits
        $tables = [];
        $dbType = strtolower($config['type'] ?? '');
        
        try {
            if (in_array($dbType, ['mysql', 'mariadb'])) {
                $stmt = $pdo->query("SHOW TABLES");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }
            } else if ($dbType === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $tables[] = $row['name'];
                }
            } else {
                // Try a generic approach for unknown database types
                $stmt = $pdo->query("SHOW TABLES");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                        $tables[] = $row[0];
                    }
                }
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to retrieve tables: ' . $e->getMessage() . ' (DB Type: ' . $dbType . ')'];
        }
        
        if (empty($tables)) {
            return ['success' => false, 'message' => 'No tables found in database (DB Type: ' . $dbType . ')'];
        }

        $exportDir = cue_autoload('paths')->getSecureFilePath('config/databases/uploaded/', true);
        if (!is_dir($exportDir)) {
            if (!mkdir($exportDir, 0755, true)) {
                return ['success' => false, 'message' => 'Failed to create export directory: ' . $exportDir];
            }
        }

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = $timestamp . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $config['name']) . '_all_tables.sql';
        $filePath = $exportDir . $fileName;

        $storagePath = '';
        if (in_array($dbType, ['mysql', 'mariadb'], true)) {
            if (function_exists('getMysqlDataPath')) {
                $storagePath = getMysqlDataPath();
            } elseif (function_exists('database_getMysqlDataPath')) {
                $storagePath = database_getMysqlDataPath();
            }
        }

        // Generate SQL export
        $sql = generateTableSQL($pdo, $config, $tables, $includeData);
        
        if (file_put_contents($filePath, $sql) === false) {
            return ['success' => false, 'message' => 'Failed to write SQL file'];
        }

        return [
            'success' => true,
            'message' => 'All tables saved successfully',
            'file_name' => $fileName,
            'file_path' => $filePath,
            'storage_path' => $storagePath,
            'table_count' => count($tables)
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Export failed: ' . $e->getMessage()];
    }
}

/**
 * Save selected tables from a database to SQL files
 */
function saveSelectedTables(?string $configId, array $tables = [], bool $includeData = false): array {
    try {
        if (empty($tables)) {
            return ['success' => false, 'message' => 'No tables selected'];
        }

        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        $config = $configResult['config'];

        // Get database connection using CUE framework
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        // Validate tables exist in the database using existing connection
        $availableTables = [];
        $dbType = strtolower($config['type'] ?? '');
        
        try {
            if (in_array($dbType, ['mysql', 'mariadb'])) {
                $stmt = $pdo->query("SHOW TABLES");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $availableTables[] = $row[0];
                }
            } else if ($dbType === 'sqlite') {
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $availableTables[] = $row['name'];
                }
            } else {
                // Try a generic approach for unknown database types
                $stmt = $pdo->query("SHOW TABLES");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                        $availableTables[] = $row[0];
                    }
                }
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to retrieve tables: ' . $e->getMessage() . ' (DB Type: ' . $dbType . ')'];
        }
        
        // Check if all requested tables exist
        $invalidTables = array_diff($tables, $availableTables);
        if (!empty($invalidTables)) {
            return ['success' => false, 'message' => 'Invalid tables: ' . implode(', ', $invalidTables)];
        }

        $exportDir = cue_autoload('paths')->getSecureFilePath('config/databases/uploaded/', true);
        if (!is_dir($exportDir)) {
            if (!mkdir($exportDir, 0755, true)) {
                return ['success' => false, 'message' => 'Failed to create export directory: ' . $exportDir];
            }
        }

        $timestamp = date('Y-m-d_H-i-s');
        $fileName = $timestamp . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $config['name']) . '_selected_tables.sql';
        $filePath = $exportDir . $fileName;

        $storagePath = '';
        if (in_array($dbType, ['mysql', 'mariadb'], true)) {
            if (function_exists('getMysqlDataPath')) {
                $storagePath = getMysqlDataPath();
            } elseif (function_exists('database_getMysqlDataPath')) {
                $storagePath = database_getMysqlDataPath();
            }
        }

        // Generate SQL export
        $sql = generateTableSQL($pdo, $config, $tables, $includeData);
        
        if (file_put_contents($filePath, $sql) === false) {
            return ['success' => false, 'message' => 'Failed to write SQL file'];
        }

        return [
            'success' => true,
            'message' => 'Selected tables saved successfully',
            'file_name' => $fileName,
            'file_path' => $filePath,
            'storage_path' => $storagePath,
            'table_count' => count($tables)
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Export failed: ' . $e->getMessage()];
    }
}

/**
 * Generate SQL DDL and optionally DML for specified tables
 */
function generateTableSQL(mixed $pdo, array $config, array $tables, bool $includeData = false): string {
    $sql = "-- Database Export\n";
    $sql .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Database: " . $config['name'] . "\n";
    $sql .= "-- Tables: " . implode(', ', $tables) . "\n\n";

    foreach ($tables as $tableName) {
        $sql .= "-- --------------------------------------------------------\n";
        $sql .= "-- Table structure for table `$tableName`\n";
        $sql .= "-- --------------------------------------------------------\n\n";

        try {
            if ($config['type'] === 'mysql' || $config['type'] === 'mariadb') {
                // Get CREATE TABLE statement for MySQL/MariaDB
                try {
                    $row = database_querySingle("SHOW CREATE TABLE `$tableName`", [], $pdo);
                    if ($row) {
                        $sql .= "DROP TABLE IF EXISTS `$tableName`;\n";
                        $sql .= $row['Create Table'] . ";\n\n";
                    }
                } catch (Exception $e) {
                    $sql .= "-- Error getting MySQL table structure for $tableName: " . $e->getMessage() . "\n\n";
                }
            } else if ($config['type'] === 'sqlite') {
                // Get CREATE TABLE statement for SQLite
                try {
                    $row = database_querySingle("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$tableName], $pdo);
                    if ($row) {
                        $sql .= "DROP TABLE IF EXISTS `$tableName`;\n";
                        $sql .= $row['sql'] . ";\n\n";
                    }
                } catch (Exception $e) {
                    $sql .= "-- Error getting SQLite table structure for $tableName: " . $e->getMessage() . "\n\n";
                }
            }

            // Add data if requested
            if ($includeData) {
                $sql .= "-- Data for table `$tableName`\n";
                try {
                    $rows = database_query("SELECT * FROM `$tableName`", [], $pdo);
                } catch (Exception $e) {
                    $rows = [];
                    $sql .= "-- Error getting data for table $tableName: " . $e->getMessage() . "\n";
                }
                
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $columnList = '`' . implode('`, `', $columns) . '`';
                    
                    foreach ($rows as $row) {
                        $values = array_map(function($value) {
                            if ($value === null) {
                                return 'NULL';
                            } elseif (is_numeric($value)) {
                                return $value;
                            } else {
                                return "'" . str_replace("'", "''", $value) . "'";
                            }
                        }, array_values($row));
                        
                        $sql .= "INSERT INTO `$tableName` ($columnList) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    $sql .= "\n";
                }
            }

        } catch (Exception $e) {
            $sql .= "-- Error exporting table $tableName: " . $e->getMessage() . "\n\n";
        }
    }

    return $sql;
}

/**
 * Verify database schema integrity
 */
function verifySchemaIntegrity(?string $configId): array {
    try {
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        // Get schema file path
        $schemaPath = cue_autoload('paths')->getSecureFilePath('config/db.sql', true);
        if (!file_exists($schemaPath)) {
            return ['success' => false, 'message' => 'Schema file not found at: ' . $schemaPath];
        }
        
        $schemaContent = file_get_contents($schemaPath);
        $requiredTables = parseSchemaForTables($schemaContent);
        
        // Get existing tables
        try {
            $tablesResult = database_query("SHOW TABLES", [], $pdo);
            $existingTables = array_column($tablesResult, array_keys($tablesResult[0])[0]);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to get existing tables: ' . $e->getMessage()];
        }
        
        $results = [
            'required_tables' => $requiredTables,
            'existing_tables' => $existingTables,
            'missing_tables' => array_diff($requiredTables, $existingTables),
            'extra_tables' => array_diff($existingTables, $requiredTables),
            'integrity_issues' => []
        ];
        
        // Check table structure for existing tables
        foreach ($requiredTables as $table) {
            if (in_array($table, $existingTables)) {
                $tableInfo = getTableStructure($pdo, $table);
                $results['table_details'][$table] = $tableInfo;
            }
        }
        
        return ['success' => true, 'data' => $results];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Schema verification failed: ' . $e->getMessage()];
    }
}

/**
 * Create missing database tables
 */
function createMissingTables(?string $configId): array {
    try {
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        // Get schema file path
        $schemaPath = cue_autoload('paths')->getSecureFilePath('config/db.sql', true);
        if (!file_exists($schemaPath)) {
            return ['success' => false, 'message' => 'Schema file not found at: ' . $schemaPath];
        }
        
        $schemaContent = file_get_contents($schemaPath);
        $statements = explode(';', $schemaContent);
        $results = [];
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (empty($statement)) continue;
            
            try {
                database_execute($statement, [], $pdo);
                if (preg_match('/CREATE TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                    $results[] = ['table' => $matches[1], 'status' => 'created', 'message' => 'Table created successfully'];
                }
            } catch (Exception $e) {
                if (preg_match('/CREATE TABLE\s+`?(\w+)`?/i', $statement, $matches)) {
                    $results[] = ['table' => $matches[1], 'status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
        
        return ['success' => true, 'results' => $results];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Table creation failed: ' . $e->getMessage()];
    }
}

/**
 * Load schema information
 */
function loadSchemaInfo(?string $configId): array {
    try {
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        // Get database name
        try {
            $dbInfo = database_querySingle("SELECT DATABASE() as db_name", [], $pdo);
            
            // Get table count
            $tableCountResult = database_querySingle("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = DATABASE()", [], $pdo);
            $tableCount = $tableCountResult['table_count'] ?? 0;
            
            // Get database size
            $dbSizeResult = database_querySingle("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS db_size_mb FROM information_schema.tables WHERE table_schema = DATABASE()", [], $pdo);
            $dbSize = $dbSizeResult['db_size_mb'] ?? 0;
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to load schema info: ' . $e->getMessage()];
        }
        
        return [
            'success' => true,
            'data' => [
                'database_name' => $dbInfo['db_name'],
                'table_count' => $tableCount,
                'database_size' => $dbSize . ' MB'
            ]
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to load schema info: ' . $e->getMessage()];
    }
}

/**
 * Optimize database tables
 */
function optimizeTables(?string $configId): array {
    try {
        $configResult = getDatabaseConfigById($configId);
        if (!$configResult['success']) {
            return ['success' => false, 'message' => 'Database configuration not found: ' . $configResult['message']];
        }
        
        // Get database connection using CUE framework
        try {
            $pdo = getDatabaseById($configId);
            if (!$pdo) {
                return ['success' => false, 'message' => 'Failed to connect to database'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to connect to database: ' . $e->getMessage()];
        }
        
        $startTime = microtime(true);
        
        // Get all tables
        try {
            $tablesResult = database_query("SHOW TABLES", [], $pdo);
            $tables = array_column($tablesResult, array_keys($tablesResult[0])[0]);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to get tables: ' . $e->getMessage()];
        }
        
        $results = [];
        $totalSizeBefore = 0;
        $totalSizeAfter = 0;
        
        foreach ($tables as $table) {
            $tableStartTime = microtime(true);
            
            // Get table info before optimization
            $beforeInfo = getTableInfo($pdo, $table);
            $totalSizeBefore += $beforeInfo['size_bytes'];
            
            try {
                // Optimize table
                database_execute("OPTIMIZE TABLE `$table`", [], $pdo);
                
                // Get table info after optimization
                $afterInfo = getTableInfo($pdo, $table);
                $totalSizeAfter += $afterInfo['size_bytes'];
                
                $spaceSaved = $beforeInfo['size_bytes'] - $afterInfo['size_bytes'];
                $percentSaved = $beforeInfo['size_bytes'] > 0 ? ($spaceSaved / $beforeInfo['size_bytes']) * 100 : 0;
                
                $results[] = [
                    'table' => $table,
                    'status' => 'success',
                    'engine' => $beforeInfo['engine'],
                    'rows' => $afterInfo['rows'],
                    'size_before' => formatBytes($beforeInfo['size_bytes']),
                    'size_after' => formatBytes($afterInfo['size_bytes']),
                    'space_saved' => formatBytes($spaceSaved),
                    'percent_saved' => round($percentSaved, 2),
                    'optimization_time' => round((microtime(true) - $tableStartTime) * 1000, 2) . ' ms'
                ];
                
            } catch (PDOException $e) {
                $results[] = [
                    'table' => $table,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $totalSpaceSaved = $totalSizeBefore - $totalSizeAfter;
        $totalPercentSaved = $totalSizeBefore > 0 ? ($totalSpaceSaved / $totalSizeBefore) * 100 : 0;
        
        return [
            'success' => true,
            'results' => $results,
            'summary' => [
                'total_tables' => count($tables),
                'total_time' => $totalTime . ' ms',
                'size_before' => formatBytes($totalSizeBefore),
                'size_after' => formatBytes($totalSizeAfter),
                'space_saved' => formatBytes($totalSpaceSaved),
                'percent_saved' => round($totalPercentSaved, 2)
            ]
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Table optimization failed: ' . $e->getMessage()];
    }
}

/**
 * Browse schema files
 */
function browseSchemaFiles(string $path = ''): array {
    try {
        $basePath = cue_autoload('paths')->getSecureFilePath('config/schemas', true);
        $fullPath = $basePath . '/' . ltrim($path, '/');
        
        if (!is_dir($fullPath)) {
            return ['success' => false, 'message' => 'Directory not found'];
        }
        
        $files = [];
        $directories = [];
        
        $items = scandir($fullPath);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $itemPath = $fullPath . '/' . $item;
            $relativePath = $path . '/' . $item;
            
            if (is_dir($itemPath)) {
                $directories[] = [
                    'name' => $item,
                    'path' => ltrim($relativePath, '/'),
                    'type' => 'directory'
                ];
            } elseif (in_array(strtolower(pathinfo($item, PATHINFO_EXTENSION)), ['sql', 'txt', 'json'])) {
                $files[] = [
                    'name' => $item,
                    'path' => ltrim($relativePath, '/'),
                    'size' => filesize($itemPath),
                    'modified' => filemtime($itemPath),
                    'type' => 'file'
                ];
            }
        }
        
        return [
            'success' => true,
            'current_path' => $path,
            'directories' => $directories,
            'files' => $files
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Failed to browse files: ' . $e->getMessage()];
    }
}

/**
 * Validate a filesystem path using CUE secure path helpers.
 */
function validatePath(string $path, mixed $base = null): array {
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
        if (!is_string($path) || trim($path) === '') {
            return ['success' => false, 'message' => 'Path is required'];
        }
        // Resolve validation base root. Defaults to config/schemas.
        $basePath = null;
        if (is_object($base)) {
            $baseKey = property_exists($base, 'base') ? strtolower((string) $base->base) : '';
            $subpath = property_exists($base, 'subpath') ? (string) $base->subpath : '';
            if ($baseKey === 'data') {
                $root = getDataPath();
                $basePath = $subpath ? cue_autoload('paths')->getSecureFilePath(trim($subpath, '/'), true) : $root;
            } elseif ($baseKey === 'config') {
                $paths = cue_autoload('paths');
                $root = is_object($paths) && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '/data/config';
                $basePath = $subpath ? cue_autoload('paths')->getSecureFilePath('config/' . trim($subpath, '/'), true) : $root;
            } elseif ($baseKey === 'schemas' || $baseKey === 'config/schemas') {
                $basePath = cue_autoload('paths')->getSecureFilePath('config/schemas', true);
            }
        } elseif (is_string($base) && trim($base) !== '') {
            switch (strtolower($base)) {
                case 'data':
                    $basePath = getDataPath();
                    break;
                case 'config':
                    $paths = cue_autoload('paths');
                    $basePath = is_object($paths) && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '/data/config';
                    break;
                case 'schemas':
                case 'config/schemas':
                    $basePath = cue_autoload('paths')->getSecureFilePath('config/schemas', true);
                    break;
                default:
                    // Fallback: treat as relative subpath under config
                    $basePath = cue_autoload('paths')->getSecureFilePath('config/' . trim($base, '/'), true);
            }
        }
        if (!$basePath) {
            $basePath = cue_autoload('paths')->getSecureFilePath('config/schemas', true);
        }
        $validation = validateSecurePath($path, $basePath);
        if (!$validation || (is_array($validation) && empty($validation['success']))) {
            $error = is_array($validation) ? ($validation['error'] ?? 'Invalid path') : 'Invalid path';
            return ['success' => false, 'message' => $error];
        }
        $resolved = cue_autoload('paths')->getSecureFilePath($path, true);
        return ['success' => true, 'resolved_path' => $resolved];
    } catch (Throwable $e) {
        error_log('validatePath error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Path validation failed'];
    }
}

/**
 * Helper functions for schema management
 */
function parseSchemaForTables(string $schemaContent): array {
    preg_match_all('/CREATE TABLE\s+`?(\w+)`?/i', $schemaContent, $matches);
    return $matches[1] ?? [];
}



function getTableInfo(mixed $pdo, string $table): array {
    try {
        $result = database_querySingle("
            SELECT 
                table_rows as rows,
                data_length + index_length as size_bytes,
                engine
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = ?
        ", [$table], $pdo);
        return $result ?: ['rows' => 0, 'size_bytes' => 0, 'engine' => 'Unknown'];
    } catch (Exception) {
        return ['rows' => 0, 'size_bytes' => 0, 'engine' => 'Unknown'];
    }
}

if (!function_exists('formatBytes')) {
    function formatBytes(int|float $bytes): string {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
}

// =============================================================================

// (Backup functionality removed - function initializeBackupDirectories() has been deleted)

// (Backup functionality removed - function getBackupEncryptionKey() has been deleted)

// (Backup functionality removed - function createDatabaseBackup() has been deleted)

// (Backup functionality removed - function createBackupSQLDump() has been deleted)

// (Backup functionality removed - function encryptBackupData() has been deleted)

// (Backup functionality removed - function decryptBackupData() has been deleted)

// (Backup functionality removed - all remaining backup functions have been deleted)

// // PAGE PERMISSIONS FUNCTIONS
// =============================================================================

/**
 * Get page permissions from storage
 */
function getPagePermissions(): array {
    try {
        if (!class_exists('PagePermissionManager')) {
            return [
                'success' => true,
                'permissions' => []
            ];
        }
        $permissionManager = new PagePermissionManager();
        $permissions = $permissionManager->getAllPermissions();
        
        return [
            'success' => true,
            'permissions' => $permissions
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to load page permissions: ' . $e->getMessage()
        ];
    }
}

/**
 * Add new page permission (supports multiple pages)
 */
function addPagePermission(array $data): array {
    try {
        if (!class_exists('PagePermissionManager')) {
            return [
                'success' => false,
                'message' => 'Page permissions are disabled'
            ];
        }
        $permissionManager = new PagePermissionManager();
        
        $pageUri = $data['page_uri'] ?? '';
        $pageUris = $data['page_uris'] ?? [];
        $databaseId = $data['database_id'] ?? '';
        $tables = $data['tables'] ?? [];
        
        // If tables is a string (JSON), decode it
        if (is_string($tables)) {
            $tables = json_decode($tables, true) ?? [];
        }
        // Accept JSON string for multiple page URIs as sent by the UI
        if (is_string($pageUris)) {
            $decodedUris = json_decode($pageUris, true);
            if (is_array($decodedUris)) {
                $pageUris = $decodedUris;
            }
        }
        
        // Handle page URIs - check if multiple pages or single page
        $pagesToProcess = [];
        
        if (!empty($pageUris) && is_array($pageUris)) {
            // Multiple pages provided
            $pagesToProcess = array_filter($pageUris, function($uri) {
                return !empty(trim($uri));
            });
        } elseif (!empty($pageUri)) {
            // Single page provided
            $pagesToProcess = [trim($pageUri)];
        }
        
        if (empty($pagesToProcess) || empty($databaseId)) {
            return [
                'success' => false,
                'message' => 'Page URI(s) and Database are required'
            ];
        }
        
        $successCount = 0;
        $failureCount = 0;
        $errors = [];
        
        foreach ($pagesToProcess as $uri) {
            try {
                $success = $permissionManager->addPagePermission($uri, $databaseId, $tables);
                if ($success) {
                    $successCount++;
                } else {
                    $failureCount++;
                    $errors[] = "Failed to add permission for: $uri";
                }
            } catch (Exception $e) {
                $failureCount++;
                $errors[] = "Error with $uri: " . $e->getMessage();
            }
        }
        
        $totalPages = count($pagesToProcess);
        
        if ($successCount === $totalPages) {
            $message = $totalPages === 1 
                ? 'Page permission added successfully' 
                : "All $totalPages page permissions added successfully";
            return [
                'success' => true,
                'message' => $message,
                'details' => [
                    'total' => $totalPages,
                    'success' => $successCount,
                    'failed' => $failureCount
                ]
            ];
        } elseif ($successCount > 0) {
            return [
                'success' => true,
                'message' => "Partial success: $successCount of $totalPages permissions added",
                'details' => [
                    'total' => $totalPages,
                    'success' => $successCount,
                    'failed' => $failureCount,
                    'errors' => $errors
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => "Failed to add any permissions. Errors: " . implode('; ', $errors),
                'details' => [
                    'total' => $totalPages,
                    'success' => $successCount,
                    'failed' => $failureCount,
                    'errors' => $errors
                ]
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error adding page permission: ' . $e->getMessage()
        ];
    }
}

/**
 * Update existing page permission
 */
function updatePagePermission(array $data): array {
    try {
        if (!class_exists('PagePermissionManager')) {
            return [
                'success' => false,
                'message' => 'Page permissions are disabled'
            ];
        }
        $permissionManager = new PagePermissionManager();
        
        $pageUri = $data['page_uri'] ?? '';
        $databaseId = $data['database_id'] ?? '';
        $tables = $data['tables'] ?? [];
        
        $success = $permissionManager->updatePagePermission($pageUri, $databaseId, $tables);
        
        return [
            'success' => $success,
            'message' => $success ? 'Page permission updated successfully' : 'Failed to update page permission'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating page permission: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete page permission
 */
function deletePagePermission(array $data): array {
    try {
        if (!class_exists('PagePermissionManager')) {
            return [
                'success' => false,
                'message' => 'Page permissions are disabled'
            ];
        }
        $permissionManager = new PagePermissionManager();
        
        $pageUri = $data['page_uri'] ?? '';
        
        if (empty($pageUri)) {
            return [
                'success' => false,
                'message' => 'Page URI is required'
            ];
        }
        
        $success = $permissionManager->removePagePermission($pageUri);
        
        return [
            'success' => $success,
            'message' => $success ? 'Page permission deleted successfully' : 'Failed to delete page permission'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error deleting page permission: ' . $e->getMessage()
        ];
    }
}

/**
 * Get available pages from the codebase
 */
function getAvailablePages(): array {
    try {
        $pages = [];
        $publicPath = getPublicPath();
        
        // Recursive function to scan directories
        function scanDirectory(string $path): array {
            $files = [];
            if (!is_dir($path) || !is_readable($path)) {
                return $files;
            }
            
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $extension = strtolower($file->getExtension());
                        
                        // Include various web file types
                        $allowedExtensions = ['php', 'html', 'htm', 'js', 'css', 'json', 'xml'];
                        
                        if (in_array($extension, $allowedExtensions)) {
                            $filePath = $file->getPathname();
                            $relativeFilePath = str_replace([$path, '\\'], ['', '/'], $filePath);
                            $relativeFilePath = ltrim($relativeFilePath, '/');
                            
                            // Skip hidden files, backup files, and vendor directories
                            if (strpos($relativeFilePath, '.') === 0 || 
                                strpos($relativeFilePath, '~') !== false ||
                                strpos($relativeFilePath, 'vendor/') === 0 ||
                                strpos($relativeFilePath, 'node_modules/') === 0 ||
                                strpos($relativeFilePath, '.git/') === 0) {
                                continue;
                            }
                            
                            // Determine directory label
                            $dirParts = explode('/', dirname($relativeFilePath));
                            $dirLabel = $dirParts[0] === '.' ? 'Root' : ucfirst($dirParts[0]);
                            
                            $files[] = [
                                'path' => $relativeFilePath,
                                'directory' => $dirLabel,
                                'filename' => $file->getFilename(),
                                'extension' => $extension,
                                'size' => $file->getSize(),
                                'modified' => $file->getMTime()
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error scanning directory $path: " . $e->getMessage());
            }
            
            return $files;
        }
        
        // Get all pages from public_html
        $pages = scanDirectory($publicPath);
        
        // Sort by directory and filename
        usort($pages, function($a, $b) {
            $dirCompare = strcmp($a['directory'], $b['directory']);
            if ($dirCompare === 0) {
                return strcmp($a['filename'], $b['filename']);
            }
            return $dirCompare;
        });
        
        return [
            'success' => true,
            'pages' => $pages,
            'total_count' => count($pages),
            'scan_path' => $publicPath
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error scanning pages: ' . $e->getMessage(),
            'pages' => [],
            'scan_path' => $publicPath ?? 'unknown'
        ];
    }
}

/**
 * Get available tables for a specific database (integrates with existing system)
 */
function getAvailableTablesForDatabase(?string $configId): array {
    try {
        // Use the existing getDatabaseTables function
        $result = getDatabaseTables($configId);
        
        if (!$result['success']) {
            return $result;
        }
        
        // Format for table selection
        $tables = [];
        foreach ($result['tables'] as $table) {
            $tables[] = [
                'name' => $table['name'],
                'rows' => $table['rows'] ?? 0
            ];
        }
        
        return [
            'success' => true,
            'tables' => $tables
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error loading tables: ' . $e->getMessage(),
            'tables' => []
        ];
    }
}

// =============================================================================
// CONTEXT MAPPING FUNCTIONS
// =============================================================================

/**
 * Get context mappings from storage
 */
function getContextMappings(): array {
    try {
        $contextManager = new DatabaseContextManager();
        $mappings = $contextManager->getAllMappings();
        
        return [
            'success' => true,
            'mappings' => $mappings
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to load context mappings: ' . $e->getMessage()
        ];
    }
}

/**
 * Add new context mapping
 */
function addContextMapping(array $data): array {
    try {
        $contextManager = new DatabaseContextManager();
        
        $pattern = $data['pattern'] ?? '';
        $databaseId = $data['database_id'] ?? '';
        $priority = intval($data['priority'] ?? 1);
        
        if (empty($pattern) || empty($databaseId)) {
            return [
                'success' => false,
                'message' => 'Pattern and Database are required'
            ];
        }
        
        $success = $contextManager->addMapping($pattern, $databaseId, $priority);
        
        return [
            'success' => $success,
            'message' => $success ? 'Context mapping added successfully' : 'Failed to add context mapping'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error adding context mapping: ' . $e->getMessage()
        ];
    }
}

/**
 * Update existing context mapping
 */
function updateContextMapping(array $data): array {
    try {
        $contextManager = new DatabaseContextManager();
        
        $id = $data['id'] ?? '';
        $pattern = $data['pattern'] ?? '';
        $databaseId = $data['database_id'] ?? '';
        $priority = intval($data['priority'] ?? 1);
        
        $success = $contextManager->updateMapping($id, $pattern, $databaseId, $priority);
        
        return [
            'success' => $success,
            'message' => $success ? 'Context mapping updated successfully' : 'Failed to update context mapping'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating context mapping: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete context mapping
 */
function deleteContextMapping(array $data): array {
    try {
        $contextManager = new DatabaseContextManager();
        
        $id = $data['id'] ?? '';
        
        if (empty($id)) {
            return [
                'success' => false,
                'message' => 'Mapping ID is required'
            ];
        }
        
        $success = $contextManager->removeMapping($id);
        
        return [
            'success' => $success,
            'message' => $success ? 'Context mapping deleted successfully' : 'Failed to delete context mapping'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error deleting context mapping: ' . $e->getMessage()
        ];
    }
}

/**
 * Test context mapping for a specific page
 */
function testContextMapping(array $data): array {
    try {
        $contextManager = new DatabaseContextManager();
        
        $testPage = $data['test_page'] ?? '';
        
        if (empty($testPage)) {
            return [
                'success' => false,
                'message' => 'Test page URI is required'
            ];
        }
        
        $databaseId = $contextManager->getContextDatabase($testPage);
        $analysis = $contextManager->analyzePageContext($testPage);
        
        return [
            'success' => true,
            'result' => [
                'page' => $testPage,
                'matched_database' => $databaseId,
                'analysis' => $analysis
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error testing context mapping: ' . $e->getMessage()
        ];
    }
}

/**
 * Verify specific Context Mapping status for a directory and database
 */
function verifyContextMappingStatus(string $directory = '/templates/theme', mixed $expectedDatabase = null): array {
    try {
        $contextManager = new DatabaseContextManager();
        $mappings = $contextManager->getAllMappings();
        
        // Check if directory mapping exists
        $directoryMapped = isset($mappings['directory_mappings'][$directory]);
        $mappedDatabase = $mappings['directory_mappings'][$directory] ?? null;
        
        // Test various files in the directory
        $testFiles = [
            '/templates/theme/index.php',
            '/templates/theme/header.php',
            '/templates/theme/footer.php',
            '/templates/theme/theme-dashboard.php',
            '/templates/theme/theme-integration.php',
            '/templates/theme/theme-builder-interface.php',
            '/templates/theme/components/header.php',
            '/templates/theme/install/unified_theme_schema.sql'
        ];
        
        $testResults = [];
        foreach ($testFiles as $testFile) {
            $resolvedDb = $contextManager->getContextDatabase($testFile);
            $testResults[$testFile] = [
                'resolved_database' => $resolvedDb,
                'matches_directory_mapping' => ($resolvedDb === $mappedDatabase),
                'has_access' => !is_null($resolvedDb)
            ];
        }
        
        return [
            'success' => true,
            'status' => [
                'directory' => $directory,
                'is_mapped' => $directoryMapped,
                'mapped_database' => $mappedDatabase,
                'expected_database' => $expectedDatabase,
                'mapping_matches_expected' => ($mappedDatabase === $expectedDatabase),
                'total_mappings' => [
                    'directories' => count($mappings['directory_mappings']),
                    'pages' => count($mappings['page_mappings'])
                ],
                'all_directory_mappings' => $mappings['directory_mappings'],
                'test_results' => $testResults
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error verifying context mapping: ' . $e->getMessage()
        ];
    }
}

// Get all configurations for display
$allConfigs = getAllDatabaseConfigs();
$tenantConfigsInJson = [];
$coreConfigs = [];
foreach ($allConfigs as $cfg) {
    if (is_array($cfg) && function_exists('dbmanager_isTenantDbConfig') && dbmanager_isTenantDbConfig($cfg)) {
        $tenantConfigsInJson[] = $cfg;
        continue;
    }
    $coreConfigs[] = $cfg;
}
$allConfigs = $coreConfigs;

$rejectedConfigs = [];
try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (function_exists('database_loadConfigurations')) {
        database_loadConfigurations();
    }
    if (function_exists('database_getRejectedConfigurations')) {
        $rejectedConfigs = database_getRejectedConfigurations();
    }
} catch (Throwable $e) {
    $rejectedConfigs = [
        [
            'config_id' => 'runtime',
            'reason' => $e->getMessage(),
            'recorded_at' => date('c'),
        ]
    ];
}

$tenantCount = 0;
try {
    if (function_exists('database_getConnectionById')) {
        $pdo = null;
        try { $pdo = database_getConnectionById('control_plane'); } catch (Throwable $e) { $pdo = null; }
        if ($pdo instanceof PDO) {
            $row = $pdo->query('SELECT COUNT(*) AS c FROM mh_control.tenant_db_map')->fetch(PDO::FETCH_ASSOC);
            $tenantCount = is_array($row) ? (int)($row['c'] ?? 0) : 0;
        }
    }
} catch (Throwable $e) {
    $tenantCount = 0;
}

$storageTabs = [
    'all' => ['label' => 'All', 'profiles' => null],
    'tenants' => ['label' => 'Tenants', 'profiles' => ['tenant']],
    'mysql' => ['label' => 'MySQL (/mysql)', 'profiles' => ['block_mysql']],
    'vector' => ['label' => 'Vector (/vector)', 'profiles' => ['block_vector']],
    'graph' => ['label' => 'Graph (/graph)', 'profiles' => ['block_graph']],
    'data' => ['label' => 'Data', 'profiles' => ['block_data']],
    'backups' => ['label' => 'Backups', 'profiles' => ['block_backup']],
    'legacy' => ['label' => 'Legacy / Custom', 'profiles' => ['legacy', 'custom', '']],
    'maintenance' => ['label' => 'Maintenance', 'profiles' => null],
];

$tabCounts = [
    'all' => count($allConfigs),
    'tenants' => $tenantCount,
    'mysql' => 0,
    'vector' => 0,
    'graph' => 0,
    'data' => 0,
    'backups' => 0,
    'legacy' => 0,
    'maintenance' => count($rejectedConfigs),
];
foreach ($allConfigs as $cfg) {
    $p = (string)($cfg['storage_profile'] ?? 'legacy');
    if ($p === 'block_mysql') { $tabCounts['mysql']++; continue; }
    if ($p === 'block_vector') { $tabCounts['vector']++; continue; }
    if ($p === 'block_graph') { $tabCounts['graph']++; continue; }
    if ($p === 'block_data') { $tabCounts['data']++; continue; }
    if ($p === 'block_backup') { $tabCounts['backups']++; continue; }
    $tabCounts['legacy']++;
}

$footerSafeOffsetPx = 0;
try {
    $footerBasePath = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    if (trim($footerBasePath) === '') {
        $footerBasePath = '/data';
    }
    $footerConfigPath = rtrim($footerBasePath, '/') . '/global-ui/footer/footer-config.json';
    if (is_file($footerConfigPath)) {
        $cfgJson = json_decode((string)file_get_contents($footerConfigPath), true);
        if (is_array($cfgJson) && isset($cfgJson['K::FooterUI::Configuration']) && is_array($cfgJson['K::FooterUI::Configuration'])) {
            $keys = array_keys($cfgJson['K::FooterUI::Configuration']);
            if (!empty($keys) && is_array($cfgJson['K::FooterUI::Configuration'][$keys[0]] ?? null)) {
                $f = $cfgJson['K::FooterUI::Configuration'][$keys[0]];
                $pos = (string)($f['ftr_position'] ?? 'bottom');
                if ($pos === 'fixed' || $pos === 'absolute') {
                    $h = (int)($f['ftr_footer_height'] ?? 0);
                    $gap = (int)($f['ftr_footer_content_spacing'] ?? 0);
                    $extra = (!empty($f['ftr_extra_content_spacing_enabled'])) ? (int)($f['ftr_extra_content_spacing'] ?? 0) : 0;
                    $footerSafeOffsetPx = max(0, $h + $gap + $extra);
                }
            }
        }
    }
} catch (Throwable $e) {
    $footerSafeOffsetPx = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Manager - Meta Humans Enterprise</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php
        if (empty($GLOBALS['_FA_LOADED'])) {
            $faUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/icons/fontawesome/css/all.min.css') : '/templates/assets/icons/fontawesome/css/all.min.css';
            $faPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/icons/fontawesome/css/all.min.css');
            if (file_exists($faPath)) {
                echo '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
                $GLOBALS['_FA_LOADED'] = true;
            } else {
                $faAltUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/fonts/all.min.css') : '/templates/assets/fonts/all.min.css';
                $faAltPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/fonts/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/fonts/all.min.css');
                if (file_exists($faAltPath)) {
                    echo '<link rel="stylesheet" href="' . htmlspecialchars($faAltUrl, ENT_QUOTES) . '">';
                    $GLOBALS['_FA_LOADED'] = true;
                }
            }
        }
    ?>
    <style>
        .fa-metahumans {
            background-image: url('<?php echo htmlspecialchars((function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB64.png') : '/templates/assets/images/branding/logo/MHlogoTB64.png'), ENT_QUOTES); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            width: 1em;
            height: 1em;
            display: inline-block;
        }
        .fa-metahumans:before {
            content: "";
            display: inline-block;
            width: 1em;
            height: 1em;
            background-image: url('<?php echo htmlspecialchars((function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB64.png') : '/templates/assets/images/branding/logo/MHlogoTB64.png'), ENT_QUOTES); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
        }
    </style>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('i[class]').forEach(function(el){
    var c = el.className;
    if (c.indexOf('fa-metahumans') !== -1) { return; }
    c = c.replace(/\bfas\b/g, 'fa-solid').replace(/\bfar\b/g, 'fa-regular').replace(/\bfab\b/g, 'fa-brands').replace(/\bfa\b(?!-(solid|regular|brands|metahumans))/g, 'fa-solid');
    el.className = c;
  });
});
</script>

    <style>
        :root {
            --primary-bg: #1a1a1a;
            --primary-text: #00FFFF;
            --secondary-bg: rgba(26, 26, 26, 0.8);
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(0, 255, 255, 0.2);
            --success-color: #00FF7F;
            --error-color: #FF6B6B;
            --warning-color: #FFD700;
            --spacing: 16px;
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            font-weight: 400;
            background-color: #1a1a1a !important;
            color: var(--primary-text);
            min-height: 100vh;
            overflow-x: hidden;
        }

        main.main-content {
            padding-bottom: <?php echo (int)$footerSafeOffsetPx; ?>px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--spacing);
        }

        .operations-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .operation-section {
            margin-bottom: calc(var(--spacing) * 1.5);
        }

        .header {
            text-align: center;
            margin-bottom: calc(var(--spacing) * 2);
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 0 20px rgba(0, 255, 255, 0.5);
        }

        .header p {
            font-size: 1.2rem;
            opacity: 0.8;
        }

        .glass-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: calc(var(--spacing) * 1.5);
            margin-bottom: var(--spacing);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-text), #0099CC);
            color: var(--primary-bg);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 255, 255, 0.3);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(0, 255, 255, 0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Loading state for buttons */
        .btn.loading {
            pointer-events: none;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 16px;
            height: 16px;
            margin: -8px 0 0 -8px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error-color), #CC0000);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #00CC00);
            color: var(--primary-bg);
        }

        .form-group {
            margin-bottom: var(--spacing);
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--primary-text);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            background: rgba(0, 255, 255, 0.05);
            color: var(--primary-text);
            font-family: 'Rajdhani', sans-serif;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-text);
            box-shadow: 0 0 0 2px rgba(0, 255, 255, 0.2);
        }

        /* Page permissions dropdown dark blue styling */
        .page-permissions-section .form-control {
            background: #1e3a8a;
            color: white;
            border-color: #3b82f6;
        }

        .page-permissions-section .form-control:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 2px rgba(96, 165, 250, 0.3);
        }

        #selectedDatabase {
            background: #00008B;
            color: white;
            border-color: #0000CD;
            position: relative;
        }

        /* Enhanced database selector with loading state */
        #selectedDatabase.loading {
            background-image: url('data:image/svg+xml;charset=utf-8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="rgba(0,255,255,0.3)" stroke-width="2"/><path d="M12 2a10 10 0 0 1 10 10" stroke="rgba(0,255,255,1)" stroke-width="2" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" values="0 12 12;360 12 12" dur="1s" repeatCount="indefinite"/></path></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px 16px;
            padding-right: 40px;
        }

        /* Enhanced loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-text);
            animation: spin 1s ease-in-out infinite;
            margin-right: 8px;
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 139, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--border-radius);
            backdrop-filter: blur(4px);
        }

        #selectedDatabase option {
            background: #00008B;
            color: white;
        }

        #selectedDatabase:focus {
            border-color: #4169E1;
            box-shadow: 0 0 0 2px rgba(65, 105, 225, 0.3);
        }

        /* Records tab dropdown styling */
        #selectedTable {
            background: #00008B !important;
            color: white !important;
            border-color: #0000CD !important;
        }

        #selectedTable option {
            background: #00008B !important;
            color: white !important;
        }

        #selectedTable:focus {
            border-color: #4169E1 !important;
            box-shadow: 0 0 0 2px rgba(65, 105, 225, 0.3) !important;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing);
        }

        .configs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: var(--spacing);
            margin-top: var(--spacing);
        }

        .config-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            position: relative;
            transition: all 0.3s ease;
        }

        .config-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 255, 255, 0.2);
        }

        .config-card.active {
            border-color: var(--success-color);
            box-shadow: 0 0 20px rgba(0, 255, 127, 0.3);
        }

        .config-card .active-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: var(--success-color);
            color: var(--primary-bg);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .config-info h3 {
            margin-bottom: 8px;
            color: var(--primary-text);
        }

        .config-info p {
            margin-bottom: 4px;
            opacity: 0.8;
        }

        .config-actions {
            margin-top: var(--spacing);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: linear-gradient(135deg, var(--primary-bg) 0%, #000080 50%, #191970 100%);
            margin: 5% auto;
            padding: 0;
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: 0 16px 64px rgba(0, 0, 0, 0.5);
        }

        .modal-header {
            padding: var(--spacing);
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-body {
            padding: var(--spacing);
        }

        .modal-footer {
            padding: var(--spacing);
            border-top: 1px solid var(--glass-border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .close {
            color: var(--primary-text);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--accent-color);
        }

        /* Page Browser Modal Specific Styles */
        #pageBrowserModal .modal-content {
            max-width: 800px;
        }

        .page-browser-container {
            color: white;
        }

        .page-list-container {
            max-height: 400px;
            overflow-y: auto;
            margin-top: 15px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            background: rgba(0,0,0,0.2);
        }

        .page-list-container::-webkit-scrollbar {
            width: 8px;
        }

        .page-list-container::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        .page-list-container::-webkit-scrollbar-thumb {
            background: rgba(0,255,255,0.3);
            border-radius: 4px;
        }

        .page-list-container::-webkit-scrollbar-thumb:hover {
            background: rgba(0,255,255,0.5);
        }

        .close:hover {
            color: var(--error-color);
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, var(--success-color), #00AA00);
        }

        .toast.error {
            background: linear-gradient(135deg, var(--error-color), #AA0000);
        }

        .db-status {
            margin: 10px 0 8px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.06);
            color: var(--primary-text);
            user-select: none;
        }

        .badge-green {
            border-color: rgba(0, 255, 127, 0.35);
            background: rgba(0, 255, 127, 0.10);
            color: rgba(0, 255, 127, 0.95);
        }

        .badge-red {
            border-color: rgba(255, 0, 64, 0.35);
            background: rgba(255, 0, 64, 0.10);
            color: rgba(255, 0, 64, 0.95);
        }

        .badge-gray {
            border-color: rgba(200,200,200,0.25);
            background: rgba(200,200,200,0.10);
            color: rgba(220,220,220,0.9);
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--primary-text);
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-online {
            background: var(--success-color);
            box-shadow: 0 0 8px rgba(0, 255, 127, 0.5);
        }

        .status-offline {
            background: var(--error-color);
            box-shadow: 0 0 8px rgba(255, 107, 107, 0.5);
        }

        /* Database Operations Styles */
        .operations-container {
            margin-top: var(--spacing);
            max-width: 1400px;
            margin: 0 auto;
        }

        .operation-section {
            margin-bottom: calc(var(--spacing) * 2);
        }

        .operation-section h3 {
            margin-bottom: var(--spacing);
            color: var(--primary-text);
            font-weight: 600;
        }

        .operation-tabs {
            display: flex;
            gap: 4px;
            margin-bottom: var(--spacing);
            border-bottom: 1px solid var(--glass-border);
        }

        .tab-btn {
            background: transparent;
            border: none;
            padding: 12px 20px;
            color: var(--primary-text);
            font-family: 'Rajdhani', sans-serif;
            font-weight: 500;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s ease;
            opacity: 0.7;
        }

        .tab-btn.active {
            background: var(--glass-bg);
            opacity: 1;
            border-bottom: 2px solid var(--primary-text);
        }

        .tab-btn:hover {
            opacity: 1;
            background: rgba(0, 255, 255, 0.05);
        }

        .tab-content {
            display: none;
            padding: var(--spacing) 0;
        }

        .tab-content.active {
            display: block;
        }

        .table-controls, .record-controls, .sql-file-controls {
            display: flex;
            gap: var(--spacing);
            margin-bottom: var(--spacing);
            align-items: center;
            flex-wrap: wrap;
        }

        /* Enhanced Table Container Styles - Fix for overflow issues */
        .tables-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: var(--spacing);
            max-width: 100%;
            overflow-x: auto;
            padding: 4px; /* Prevent shadow clipping */
        }

        .table-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            transition: all 0.3s ease;
            min-width: 260px; /* Ensure minimum width */
            max-width: 100%;
            word-wrap: break-word;
            overflow: hidden;
            position: relative;
        }

        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 255, 255, 0.2);
        }

        .table-card h4 {
            margin-bottom: 8px;
            color: var(--primary-text);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Table metadata enhancements */
        .table-metadata {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-metadata .metadata-item {
            display: inline-block;
            padding: 2px 6px;
            background: rgba(0, 255, 255, 0.1);
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
        }

        /* Status indicators for table health */
        .table-status {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .table-status.healthy {
            background: var(--success-color);
            box-shadow: 0 0 6px rgba(0, 255, 127, 0.5);
        }

        .table-status.warning {
            background: var(--warning-color);
            box-shadow: 0 0 6px rgba(255, 215, 0, 0.5);
        }

        .table-status.error {
            background: var(--error-color);
            box-shadow: 0 0 6px rgba(255, 107, 107, 0.5);
        }

        /* Enhanced table actions with better responsive behavior */
        .table-card .table-actions {
            display: flex;
            gap: 6px;
            margin-top: var(--spacing);
            flex-wrap: wrap;
            justify-content: flex-start;
        }

        /* Improved button sizing for table actions */
        .table-actions .btn {
            flex: 1;
            min-width: 70px;
            max-width: 90px;
            padding: 8px 12px;
            font-size: 0.85rem;
            text-align: center;
            white-space: nowrap;
            position: relative;
        }

        /* Enhanced records table with horizontal scroll */
        .records-table-container {
            max-width: 100%;
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            margin-bottom: var(--spacing);
        }

        .records-table {
            width: 100%;
            min-width: 600px; /* Ensure minimum width for readability */
            border-collapse: collapse;
            background: transparent;
        }

        .records-table th,
        .records-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
            white-space: normal;
            word-break: break-word;
            max-width: 200px;
        }

        .records-table th {
            background: rgba(0, 255, 255, 0.1);
            font-weight: 600;
            color: var(--primary-text);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .records-table td {
            color: var(--primary-text);
            opacity: 0.9;
        }

        .records-table tr:hover {
            background: rgba(0, 255, 255, 0.05);
        }

        /* Enhanced pagination with better mobile support */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: var(--spacing);
            flex-wrap: wrap;
        }

        .pagination button {
            padding: 8px 12px;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            color: var(--primary-text);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 40px;
        }

        .pagination button:hover,
        .pagination button.active {
            background: var(--primary-text);
            color: var(--primary-bg);
            transform: translateY(-1px);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .pagination .page-info {
            padding: 8px 12px;
            color: var(--primary-text);
            opacity: 0.8;
            font-size: 0.9rem;
        }

        /* Tooltip styles for enhanced UX */
        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip::before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .tooltip::after {
            content: '';
            position: absolute;
            bottom: 115%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: rgba(0, 0, 0, 0.9);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .tooltip:hover::before,
        .tooltip:hover::after {
            opacity: 1;
            visibility: visible;
        }

        /* Enhanced Modal Styles */
        .structure-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }

        .modal-container {
            position: relative;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow-lg);
            max-width: 90vw;
            max-height: 90vh;
            width: 800px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-header {
            padding: var(--spacing);
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.05);
        }

        .modal-header h2 {
            margin: 0;
            color: var(--text-primary);
            font-size: 1.2rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: var(--border-radius);
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }

        .modal-body {
            padding: var(--spacing);
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: var(--spacing);
            border-top: 1px solid var(--glass-border);
            display: flex;
            justify-content: flex-end;
            gap: var(--spacing-sm);
            background: rgba(255, 255, 255, 0.05);
        }

        /* Record Form Styles */
        .record-form {
            max-width: 100%;
        }

        .record-form h3 {
            margin-bottom: var(--spacing);
            color: var(--primary-text);
            font-size: 1.2em;
        }

        .record-form .form-group {
            margin-bottom: var(--spacing);
        }

        .record-form .field-info {
            font-size: 0.85em;
            color: var(--text-secondary);
            margin-top: 4px;
            font-style: italic;
        }

        .table-structure-header {
            margin-bottom: var(--spacing);
        }

        .table-structure-header h3 {
            margin: 0 0 0.5rem 0;
            color: var(--text-primary);
        }

        .table-info {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .text-success {
            color: var(--success-color);
        }

        .text-danger {
            color: var(--error-color);
        }

        .error-message {
            color: var(--error-color);
            text-align: center;
            padding: var(--spacing);
            font-style: italic;
        }

        /* Enhanced mobile responsiveness */
        @media (max-width: 768px) {
            .tables-grid {
                grid-template-columns: 1fr;
                gap: calc(var(--spacing) * 0.75);
            }
            
            .table-card {
                min-width: unset;
            }
            
            .table-actions .btn {
                flex: 1;
                min-width: 60px;
                font-size: 0.8rem;
                padding: 6px 8px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .table-controls, .record-controls, .sql-file-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .table-controls > *, .record-controls > *, .sql-file-controls > * {
                width: 100%;
                margin-bottom: 8px;
            }
        }

        .upload-section, .browse-section {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            margin-bottom: var(--spacing);
        }

        .upload-section h4, .browse-section h4 {
            margin-bottom: var(--spacing);
            color: var(--primary-text);
        }

        .file-input-wrapper {
            position: relative;
            display: inline-block;
            margin-right: var(--spacing);
        }

        .file-input {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .file-input-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--glass-bg);
            border: 2px dashed var(--glass-border);
            border-radius: var(--border-radius);
            color: var(--primary-text);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-input-label:hover {
            border-color: var(--primary-text);
            background: rgba(0, 255, 255, 0.1);
        }

        .search-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-controls input {
            flex: 1;
        }

        .file-browser {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            max-height: 400px;
            overflow-y: auto;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid var(--glass-border);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            background: rgba(0, 255, 255, 0.05);
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-item .file-icon {
            margin-right: 12px;
            width: 20px;
            text-align: center;
        }

        .file-item .file-info {
            flex: 1;
        }

        .file-item .file-name {
            font-weight: 500;
            color: var(--primary-text);
        }

        .file-item .file-details {
            font-size: 0.9rem;
            opacity: 0.7;
            margin-top: 2px;
        }

        .file-item .file-actions {
            display: flex;
            gap: 8px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: var(--spacing);
            padding: 8px 12px;
            background: var(--glass-bg);
            border-radius: var(--border-radius);
        }

        .breadcrumb-item {
            color: var(--primary-text);
            text-decoration: none;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .breadcrumb-item:hover {
            opacity: 1;
        }

        .breadcrumb-item.active {
            opacity: 1;
            font-weight: 600;
        }

        .section-header {
            margin-bottom: var(--spacing);
        }

        .section-header h2 {
            margin-bottom: 8px;
            color: var(--primary-text);
        }

        .section-header p {
            opacity: 0.8;
        }

        /* Schema Management Styles */
        .schema-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing);
            margin-bottom: var(--spacing);
        }

        .verification-results, .creation-results, .optimization-results {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            margin-top: var(--spacing);
        }

        .result-summary {
            margin-bottom: var(--spacing);
        }

        .summary-stats, .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .stat-item, .summary-item {
            background: rgba(0, 255, 255, 0.05);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 255, 255, 0.1);
        }

        .stat-item.success, .summary-item.success {
            border-color: var(--success-color);
            background: rgba(0, 255, 127, 0.1);
        }

        .stat-item.error, .summary-item.error {
            border-color: var(--error-color);
            background: rgba(255, 107, 107, 0.1);
        }

        .stat-item.warning, .summary-item.warning {
            border-color: var(--warning-color);
            background: rgba(255, 215, 0, 0.1);
        }

        .stat-label, .summary-label {
            display: block;
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 4px;
        }

        .stat-value, .summary-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-text);
        }

        /* Table details styles */
        .table-details {
            margin-top: var(--spacing);
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            border: 1px solid var(--glass-border);
        }

        .table-info {
            width: 100%;
            border-collapse: collapse;
            background: var(--glass-bg);
        }

        .table-info th,
        .table-info td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
        }

        .table-info th {
            background: rgba(0, 255, 255, 0.1);
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--accent-color);
        }

        .table-info td {
            font-size: 0.9rem;
        }

        .table-info tr:hover {
            background: rgba(0, 255, 255, 0.05);
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            opacity: 0.8;
        }

        .info-value {
            font-weight: 600;
            color: var(--accent-color);
        }

        .summary-info {
            margin-bottom: var(--spacing);
        }

        .missing-tables, .extra-tables {
            margin-top: var(--spacing);
        }

        .missing-tables ul, .extra-tables ul {
            list-style: none;
            padding: 0;
        }

        .missing-tables li, .extra-tables li {
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 6px;
            border-left: 4px solid;
        }

        .missing-tables li.error {
            border-left-color: var(--error-color);
            background: rgba(255, 107, 107, 0.1);
        }

        .extra-tables li.warning {
            border-left-color: var(--warning-color);
            background: rgba(255, 215, 0, 0.1);
        }

        .result-item {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid;
        }

        .result-item.success {
            border-left-color: var(--success-color);
            background: rgba(0, 255, 127, 0.1);
        }

        .result-item.error {
            border-left-color: var(--error-color);
            background: rgba(255, 107, 107, 0.1);
        }

        .schema-info {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .info-item {
            background: rgba(0, 255, 255, 0.05);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 255, 255, 0.1);
        }

        .info-label {
            display: block;
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-text);
        }

        .table-results {
            margin-top: var(--spacing);
        }

        .table-result {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            border-left: 4px solid;
        }

        .table-result.success {
            border-left-color: var(--success-color);
            background: rgba(0, 255, 127, 0.1);
        }

        .table-result.error {
            border-left-color: var(--error-color);
            background: rgba(255, 107, 107, 0.1);
        }

        .table-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .table-details {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .file-browser {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            max-height: 400px;
            overflow-y: auto;
        }

        .current-path {
            font-weight: 600;
            margin-bottom: var(--spacing);
            padding-bottom: 8px;
            border-bottom: 1px solid var(--glass-border);
        }

        .file-item {
            padding: 8px 12px;
            margin: 4px 0;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .file-item:hover {
            background: rgba(0, 255, 255, 0.1);
        }

        .file-item.directory {
            color: var(--warning-color);
        }

        .file-info {
            display: flex;
            flex-direction: column;
        }

        .file-details {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-top: 2px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .configs-grid {
                grid-template-columns: 1fr;
            }
            
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }

        /* Progress Bar Styles */
        .progress-container {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius);
            padding: var(--spacing);
            margin: 20px 0;
        }

        .progress-label {
            margin-bottom: 10px;
            font-weight: 500;
            color: var(--primary-text);
        }

        .progress-bar-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .progress-bar {
            flex: 1;
            height: 20px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success-color), #00BFFF);
            border-radius: 10px;
            width: 0%;
            transition: width 0.3s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .progress-percentage {
            min-width: 40px;
            text-align: right;
            font-weight: 600;
            color: var(--primary-text);
        }

        .progress-container.success .progress-fill {
            background: linear-gradient(90deg, var(--success-color), #32CD32);
        }

        .progress-container.error .progress-fill {
            background: linear-gradient(90deg, var(--error-color), #FF4444);
        }
    /* Force dark background for DB Manager */
    body { background-color: #1a1a1a !important; color: #e0e0e0; font-family: 'Rajdhani', sans-serif; }
    main.main-content { padding-bottom: 0; }
    /* Ensure modal backgrounds are also dark */
    .modal-content, .card { background-color: #1a1a1a !important; border: 1px solid rgba(0, 212, 255, 0.2); }
    .config-tabs { display: flex; gap: 10px; flex-wrap: wrap; margin: 0 0 var(--spacing) 0; }
    .config-tab-btn { padding: 10px 14px; border: 1px solid rgba(0, 212, 255, 0.2); border-radius: 10px; background: rgba(0, 212, 255, 0.06); color: #e0e0e0; cursor: pointer; font-weight: 600; }
    .config-tab-btn.active { background: rgba(0, 212, 255, 0.18); box-shadow: 0 0 16px rgba(0, 212, 255, 0.25); }
    .db-search { width: 380px; max-width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(0,0,0,0.35); color: #e0e0e0; }
    .maintenance-panel { display: none; margin-top: var(--spacing); }
    .maintenance-table { width: 100%; border-collapse: collapse; margin-top: var(--spacing); }
    .maintenance-table th, .maintenance-table td { padding: 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); text-align: left; vertical-align: top; }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-database"></i> <i class="fa fa-metahumans" style="font-size: 1.2em; vertical-align: middle;"></i> Database Manager</h1>
            <p>Manage multiple database configurations with enterprise-grade security</p>
        </div>

        <div class="glass-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing);">
                <h2><i class="fas fa-server"></i> Database Configurations</h2>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
                    <input id="dbSearch" class="db-search" type="text" placeholder="Search databases (name, id, type, storage)..." oninput="filterDatabaseCards()">
                    <button class="btn" onclick="openConfigModal()">
                        <i class="fas fa-plus"></i> Add New Configuration
                    </button>
                </div>
            </div>

            <?php if (empty($allConfigs)): ?>
                <div style="text-align: center; padding: calc(var(--spacing) * 2); opacity: 0.7;">
                    <i class="fas fa-database" style="font-size: 3rem; margin-bottom: var(--spacing);"></i>
                    <h3>No Database Configurations</h3>
                    <p>Create your first database configuration to get started.</p>
                </div>
            <?php else: ?>
                <div class="config-tabs" role="tablist" aria-label="Storage Tabs">
                    <?php foreach ($storageTabs as $tabKey => $tab): ?>
                        <button class="config-tab-btn <?php echo $tabKey === 'all' ? 'active' : ''; ?>" type="button" data-tab="<?php echo htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8'); ?>" onclick="showStorageTab('<?php echo htmlspecialchars($tabKey, ENT_QUOTES, 'UTF-8'); ?>')">
                            <?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)($tabCounts[$tabKey] ?? 0); ?>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <div id="maintenancePanel" class="maintenance-panel">
                    <div style="opacity:0.9;">
                        <h3 style="margin: 0 0 10px 0;"><i class="fas fa-shield-alt"></i> Policy Violations</h3>
                        <div style="opacity:0.8;">Rejected configurations (ID + reason) detected by runtime validation.</div>
                        <?php if (empty($rejectedConfigs)): ?>
                            <div style="margin-top: var(--spacing); opacity:0.8;">No rejected configurations detected.</div>
                        <?php else: ?>
                            <table class="maintenance-table">
                                <thead>
                                    <tr>
                                        <th style="width: 220px;">Config ID</th>
                                        <th>Reason</th>
                                        <th style="width: 200px;">Recorded At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rejectedConfigs as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)($row['config_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($row['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($row['recorded_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="configs-grid">
                    <?php foreach ($allConfigs as $config): ?>
                        <?php
                            $profile = (string)($config['storage_profile'] ?? 'legacy');
                            $searchBlob = strtolower(implode(' ', [
                                (string)($config['id'] ?? ''),
                                (string)($config['name'] ?? ''),
                                (string)($config['type'] ?? ''),
                                (string)($config['port'] ?? ''),
                                (string)($config['charset'] ?? ''),
                                $profile
                            ]));
                        ?>
                        <div class="config-card <?php echo $config['is_active'] ? 'active' : ''; ?>" data-config-id="<?php echo htmlspecialchars((string)($config['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-storage-profile="<?php echo htmlspecialchars($profile, ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($config['is_active']): ?>
                                <div class="active-badge">
                                    <i class="fas fa-check"></i> Active
                                </div>
                            <?php endif; ?>
                            
                            <div class="config-info">
                                <h3><i class="fas fa-database"></i> <?php echo htmlspecialchars($config['name']); ?></h3>
                                <div class="db-status" data-config-id="<?php echo htmlspecialchars((string)($config['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></div>
                                <p><strong>Type:</strong> <?php echo htmlspecialchars($config['type'] ?? 'MySQL'); ?></p>
                                <p><strong>Port:</strong> <?php echo htmlspecialchars($config['port'] ?? 'N/A'); ?></p>
                                <p><strong>Charset:</strong> <?php echo htmlspecialchars($config['charset'] ?? 'utf8mb4'); ?></p>
                                <?php
                                $storageProfile = $config['storage_profile'] ?? 'legacy';
                                $storageLabel = 'Custom / Legacy';
                                if ($storageProfile === 'block_mysql') {
                                    $path = '';
                                    if (function_exists('paths_getMysqlPath')) {
                                        $path = paths_getMysqlPath();
                                    } elseif (function_exists('getMysqlDataPath')) {
                                        $path = getMysqlDataPath();
                                    } elseif (function_exists('database_getMysqlDataPath')) {
                                        $path = database_getMysqlDataPath();
                                    }
                                    $storageLabel = 'Block Storage - MySQL' . ($path !== '' ? ' (' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ')' : '');
                                } elseif ($storageProfile === 'block_vector') {
                                    $path = '';
                                    if (function_exists('paths_getVectorPath')) {
                                        $path = paths_getVectorPath();
                                    } elseif (function_exists('getVectorDataPath')) {
                                        $path = getVectorDataPath();
                                    } elseif (function_exists('database_getVectorDataPath')) {
                                        $path = database_getVectorDataPath();
                                    }
                                    $storageLabel = 'Block Storage - Vector' . ($path !== '' ? ' (' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ')' : '');
                                } elseif ($storageProfile === 'block_graph') {
                                    $path = '';
                                    if (function_exists('paths_getGraphPath')) {
                                        $path = paths_getGraphPath();
                                    } elseif (function_exists('getGraphDataPath')) {
                                        $path = getGraphDataPath();
                                    } elseif (function_exists('database_getGraphDataPath')) {
                                        $path = database_getGraphDataPath();
                                    }
                                    $storageLabel = 'Block Storage - Graph' . ($path !== '' ? ' (' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ')' : '');
                                } elseif ($storageProfile === 'block_data') {
                                    $path = '';
                                    if (function_exists('paths_getDataPath')) {
                                        $path = paths_getDataPath();
                                    }
                                    $storageLabel = 'Block Storage - App Data' . ($path !== '' ? ' (' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ')' : '');
                                } elseif ($storageProfile === 'block_backup') {
                                    $path = '';
                                    if (function_exists('paths_getBackupsPath')) {
                                        $path = paths_getBackupsPath();
                                    }
                                    $storageLabel = 'Block Storage - System Backups' . ($path !== '' ? ' (' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ')' : '');
                                }
                                ?>
                                <p><strong>Storage:</strong> <?php echo htmlspecialchars($storageLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Created:</strong> <?php echo date('M j, Y', strtotime($config['created_at'])); ?></p>
                            </div>
                            
                            <div class="config-actions">
                                <button class="btn test-connection-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-plug"></i> Test
                                </button>
                                <button class="btn btn-info create-database-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-database"></i> Create DB
                                </button>
                                <button class="btn edit-config-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($config['is_active']): ?>
                                    <button class="btn btn-warning toggle-active-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-pause"></i> Deactivate
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-success toggle-active-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="fas fa-play"></i> Activate
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-danger delete-config-btn" data-config-id="<?php echo htmlspecialchars($config['id'], ENT_QUOTES, 'UTF-8'); ?>" data-config-name="<?php echo htmlspecialchars($config['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="tenantConfigsControls" style="display:none; margin-top: var(--spacing);">
                    <button class="btn" type="button" onclick="loadMoreTenantConfigCards()">
                        <i class="fas fa-users"></i> Load more tenants
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Database Operations Section -->
    <div class="glass-panel">
        <div class="section-header">
            <h2><i class="fas fa-cogs"></i> Database Operations</h2>
            <p>Manage tables, records, and execute SQL files on active databases</p>
        </div>
        
        <div class="operations-container">
            <!-- Database Selection -->
            <div class="operation-section">
                <h3><i class="fas fa-database"></i> Select Database</h3>
                <div class="form-group">
                    <select id="selectedDatabase" class="form-control" onchange="loadDatabaseTables()">
                    <option value="">Select an active database...</option>
                </select>
                </div>
            </div>

            <!-- Provisioner Tools -->
            <div class="operation-section">
                <h3><i class="fas fa-user-shield"></i> Provisioner Tools</h3>
                <div class="form-inline" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <input type="text" id="provUser" class="form-control" placeholder="Provisioner username (e.g., db_provisioner)" style="min-width:260px;">
                    <input type="password" id="provPass" class="form-control" placeholder="Provisioner password" style="min-width:260px;">
                    <select id="provAdminConfig" class="form-control" style="min-width:280px;">
                        <option value="">Use first active 3307 admin</option>
                    </select>
                    <button class="btn" id="createProvisionerBtn"><i class="fas fa-user-plus"></i> Create Provisioner</button>
                </div>
                <p style="color:#ccc;margin-top:8px;">Creates a privileged admin account on 127.0.0.1:3307 and saves it as an encrypted configuration.</p>
            </div>

            <!-- Table Operations -->
            <div id="tableOperations" class="operation-section" style="display: none;">
                <h3><i class="fas fa-table"></i> Table Operations</h3>
                
                <div class="operation-tabs">
                    <button class="tab-btn active" onclick="showTab('tables-tab')">
                        <i class="fas fa-list"></i> Tables
                    </button>
                    <button class="tab-btn" onclick="showTab('records-tab')">
                        <i class="fas fa-th"></i> Records
                    </button>
                    <button class="tab-btn" onclick="showTab('sql-files-tab')">
                        <i class="fas fa-file-code"></i> SQL Files
                    </button>
                    <button class="tab-btn" onclick="showTab('schema-tab')">
                        <i class="fas fa-database"></i> Schema Manager
                    </button>
                    <button class="tab-btn" onclick="showTab('page-permissions-tab')">
                        <i class="fas fa-key"></i> Page Permissions
                    </button>
                    <button class="tab-btn" onclick="showTab('context-mapping-tab')">
                        <i class="fas fa-route"></i> Context Mapping
                    </button>
                </div>

                <!-- Tables Tab -->
                <div id="tables-tab" class="tab-content active">
                    <div class="table-controls">
                        <button class="btn" onclick="showCreateTableModal()">
                            <i class="fas fa-plus"></i> Create Table
                        </button>
                        <button class="btn" onclick="refreshTables()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                    </div>
                    
                    <div id="tablesContainer" class="tables-grid">
                        <!-- Tables will be loaded here -->
                    </div>
                </div>

                <!-- Records Tab -->
                <div id="records-tab" class="tab-content">
                    <div class="record-controls">
                        <div class="form-group">
                            <select id="selectedTable" class="form-control" onchange="loadTableRecords()">
                                <option value="">Select a table...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <input type="text" id="recordsSearchQuery" class="form-control" placeholder="Search records..." autocomplete="off">
                        </div>
                        <div class="form-group">
                            <select id="recordsPerPage" class="form-control" title="Rows per page">
                                <option value="10">10</option>
                                <option value="15">15</option>
                                <option value="30">30</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="all">all</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <select id="recordsPageSelect" class="form-control" title="Page" disabled>
                                <option value="1">Page 1</option>
                            </select>
                        </div>
                        <div id="recordsCountInfo" style="color:#ccc;font-size:12px;min-width:220px;"></div>
                        <button class="btn" onclick="showCreateRecordModal()">
                            <i class="fas fa-plus"></i> Add Record
                        </button>
                    </div>
                    
                    <div id="recordsContainer">
                        <!-- Records will be loaded here -->
                    </div>
                    
                    <div id="paginationContainer">
                        <!-- Pagination will be loaded here -->
                    </div>
                </div>

                <!-- SQL Files Tab -->
                <div id="sql-files-tab" class="tab-content">
                    <div class="sql-file-controls">
                        <!-- Database Selection for SQL Files -->
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="sqlSelectedDatabase">Select Database Configuration:</label>
                            <select id="sqlSelectedDatabase" class="form-control" style="background-color: #1e3a8a; color: white;">
                                <option value="">Select a database configuration...</option>
                            </select>
                        </div>
                        
                        <div class="upload-section">
                            <h4><i class="fas fa-upload"></i> Upload SQL File</h4>
                            <form id="sqlUploadForm" enctype="multipart/form-data">
                                <div class="file-input-wrapper">
                                    <input type="file" id="sqlFile" name="sql_file" accept=".sql,.txt" class="file-input" onchange="updateFileLabel()">
                                    <label for="sqlFile" class="file-input-label" id="sqlFileLabel">
                                        <i class="fas fa-cloud-upload-alt"></i> Choose SQL File
                                    </label>
                                </div>
                                <button type="button" class="btn" onclick="uploadSqlFile()">
                                    <i class="fas fa-upload"></i> Upload
                                </button>
                            </form>
                        </div>
                        
                        <div class="save-tables-section" style="margin-top: 20px;">
                            <h4><i class="fas fa-save"></i> Save Tables</h4>
                            <p style="color: #ccc; margin-bottom: 15px;">Export table structures from the selected database to SQL files</p>
                            <div class="save-controls" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <button type="button" class="btn" onclick="saveAllTables()" style="background: #4CAF50;">
                                    <i class="fas fa-download"></i> Save All Tables
                                </button>
                                <button type="button" class="btn" onclick="showSelectTablesModal()" style="background: #2196F3;">
                                    <i class="fas fa-list-check"></i> Select Tables to Save
                                </button>
                                <div style="display: flex; align-items: center; gap: 5px;">
                                    <input type="checkbox" id="includeData" style="margin: 0;">
                                    <label for="includeData" style="color: white; margin: 0; cursor: pointer;">Include Data</label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Progress Bar for SQL Execution -->
                        <div id="sqlProgressContainer" class="progress-container" style="display: none; margin: 20px 0;">
                            <div id="sqlProgressLabel" class="progress-label">Executing SQL file...</div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar">
                                    <div id="sqlProgressFill" class="progress-fill"></div>
                                </div>
                                <span id="sqlProgressPercentage" class="progress-percentage">0%</span>
                            </div>
                        </div>
                        
                        <div class="browse-section">
                            <h4><i class="fas fa-folder-open"></i> Browse SQL Files</h4>
                            <div class="search-controls">
                                <input type="text" id="sqlSearchQuery" placeholder="Search SQL files..." class="form-control">
                                <button class="btn" onclick="searchSqlFiles()">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button class="btn" onclick="browseSqlFiles('')">
                                    <i class="fas fa-folder"></i> Browse
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="sqlFilesContainer">
                        <!-- SQL files browser will be loaded here -->
                    </div>
                </div>

                <!-- Schema Manager Tab -->
                <div id="schema-tab" class="tab-content">
                    <div class="schema-section">
                        <h4><i class="fas fa-database"></i> Schema Management</h4>
                        <p>Verify and create database tables based on schema definitions</p>
                        
                        <!-- Database Selection for Schema Manager -->
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label for="schemaSelectedDatabase">Select Database Configuration:</label>
                            <select id="schemaSelectedDatabase" class="form-control" style="background-color: #1e3a8a; color: white;">
                                <option value="">Select a database configuration...</option>
                            </select>
                        </div>
                        
                <div class="schema-controls">
                    <button class="btn" onclick="verifyTables()">
                        <i class="fas fa-check-circle"></i> Verify Tables
                    </button>
                    <button class="btn btn-success" onclick="createMissingTables()">
                        <i class="fas fa-plus-circle"></i> Create Missing Tables
                    </button>
                    <button class="btn" onclick="loadSchemaInfo()">
                        <i class="fas fa-info-circle"></i> Load Schema Info
                    </button>
                    <button class="btn" onclick="optimizeTables()">
                        <i class="fas fa-tachometer-alt"></i> Optimize Tables
                            </button>
                        </div>
                    </div>
                    
                <div id="schemaResults" style="display: none;">
                    <h4><i class="fas fa-clipboard-list"></i> Schema Verification Results</h4>
                    <div id="schemaVerificationResults"></div>
                </div>
                    
                    <div id="schemaCreationResults" style="display: none;">
                        <h4><i class="fas fa-plus-circle"></i> Table Creation Results</h4>
                        <div id="schemaCreationDetails"></div>
                    </div>
                    
                    <div id="schemaInfo" style="display: none;">
                        <h4><i class="fas fa-info-circle"></i> Schema Information</h4>
                        <div id="schemaInfoResults"></div>
                    </div>
                    
                    <div id="optimizationResults" style="display: none;">
                        <h4><i class="fas fa-tachometer-alt"></i> Optimization Results</h4>
                        <div id="optimizationDetails"></div>
                    </div>
                    
                    <div class="schema-file-section">
                        <h4><i class="fas fa-file-upload"></i> Upload Schema Files</h4>
                        <div class="upload-section">
                            <form id="schemaUploadForm" enctype="multipart/form-data">
                                <div class="file-input-wrapper">
                                    <input type="file" id="schemaFile" name="schema_file" accept=".sql,.txt" class="file-input">
                                    <label for="schemaFile" class="file-input-label">
                                        <i class="fas fa-cloud-upload-alt"></i> Choose Schema File
                                    </label>
                                </div>
                                <button type="button" class="btn" onclick="uploadSchemaFile()">
                                    <i class="fas fa-upload"></i> Upload
                                </button>
                            </form>
                        </div>
                        
                        <div class="browse-section">
                            <h4><i class="fas fa-folder-open"></i> Browse Schema Files</h4>
                            <div class="search-controls">
                                <button class="btn" onclick="browseSchemaFiles()">
                                    <i class="fas fa-folder"></i> Browse Schema Files
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="schemaFilesContainer">
                        <!-- Schema files browser will be loaded here -->
                    </div>
                </div>

                <!-- Page Permissions Tab -->
                <div id="page-permissions-tab" class="tab-content">
                    <div class="permission-section page-permissions-section">
                        <h4><i class="fas fa-key"></i> Page-Database Access Control</h4>
                        <p>Manage which pages can access specific databases and tables</p>
                        
                        <!-- Permission Grid -->
                        <div class="page-permission-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr auto; gap: 10px; background: rgba(0,0,0,0.2); border-radius: 8px; overflow: hidden; margin: 20px 0;">
                            <div style="display: contents; font-weight: bold; background: #4CAF50; color: white;">
                                <span style="padding: 12px; background: #4CAF50;">Page</span>
                                <span style="padding: 12px; background: #4CAF50;">Database</span>
                                <span style="padding: 12px; background: #4CAF50;">Database Name</span>
                                <span style="padding: 12px; background: #4CAF50;">Tables</span>
                                <span style="padding: 12px; background: #4CAF50;">Operations</span>
                                <span style="padding: 12px; background: #4CAF50;">Actions</span>
                            </div>
                            <div id="permissionRows" style="display: contents;">
                                <!-- Permission rows will be loaded here -->
                            </div>
                        </div>
                        
                        <!-- Add New Permission Section -->
                        <div class="add-permission-section" style="margin-top: 30px; padding: 20px; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <h5><i class="fas fa-plus"></i> Add Page Permission</h5>
                            
                            <form id="addPagePermissionForm">
                                <input type="hidden" id="selectedDatabaseId" name="database" value="">
                                <div class="form-row" style="display: grid; grid-template-columns: 1fr; gap: 15px; margin-bottom: 15px;">
                                    <div class="form-group">
                                        <label style="color: white; margin-bottom: 5px; display: block;">Page URI(s):</label>
                                        <div style="display: flex; gap: 8px; align-items: flex-start;">
                                            <div style="flex: 1;">
                                                <input type="text" id="pageUriInput" class="form-control" placeholder="Select single page or enter custom path..." readonly style="cursor: pointer; margin-bottom: 8px;">
                                                <div id="selectedPagesContainer" style="min-height: 40px; background: rgba(0,0,0,0.3); border-radius: 4px; padding: 8px; border: 1px solid rgba(255,255,255,0.1);">
                                                    <div id="selectedPagesList" style="display: flex; flex-wrap: wrap; gap: 5px;">
                                                        <span style="color: rgba(255,255,255,0.5); font-style: italic;">No pages selected</span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                                <button type="button" class="btn btn-primary" onclick="openPageBrowser()" style="min-width: 120px; font-size: 12px;">
                                                    <i class="fas fa-folder-open"></i> Browse Pages
                                                </button>
                                                <button type="button" class="btn btn-secondary" onclick="clearSelectedPages()" style="min-width: 120px; font-size: 12px;">
                                                    <i class="fas fa-times"></i> Clear All
                                                </button>
                                            </div>
                                        </div>
                                        <small style="color: rgba(255,255,255,0.6); margin-top: 5px; display: block;">
                                            <strong>Multiple Selection:</strong> Use "Browse Pages" to select multiple pages with the same permissions, or enter a single custom path above
                                        </small>
                                    </div>
                                </div>
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label style="color: white; margin-bottom: 10px; display: block;">Table Permissions:</label>
                                    
                                    <!-- Select All Controls -->
                                    <div style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 4px; margin-bottom: 10px; display: flex; gap: 20px; align-items: center;">
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                <input type="checkbox" id="selectAllTables" onchange="toggleAllTables(this.checked)"> Select All Tables
                                            </label>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <span style="color: #ccc;">Operations:</span>
                                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                <input type="checkbox" id="selectAllRead" onchange="toggleAllOperations('read', this.checked)"> All Read
                                            </label>
                                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                <input type="checkbox" id="selectAllWrite" onchange="toggleAllOperations('write', this.checked)"> All Write
                                            </label>
                                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                <input type="checkbox" id="selectAllUpdate" onchange="toggleAllOperations('update', this.checked)"> All Update
                                            </label>
                                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                                <input type="checkbox" id="selectAllDelete" onchange="toggleAllOperations('delete', this.checked)"> All Delete
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div id="tablePermissions" style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1);">
                                        <button type="button" class="btn" onclick="addTablePermission()">
                                            <i class="fas fa-plus"></i> Add Table Permission
                                        </button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save"></i> Add Permission
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Performance Information -->
                        <div class="performance-section" style="margin-top: 30px; padding: 20px; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <h5 style="color: white; margin-bottom: 15px;">
                                <i class="fas fa-tachometer-alt"></i> Live Database Performance
                            </h5>
                            <div style="color: rgba(255,255,255,0.6); font-size: 13px;">
                                <i class="fas fa-database"></i> Table data is loaded directly from MariaDB for real-time accuracy. 
                                All table information reflects the current database state.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Context Mapping Tab -->
                <div id="context-mapping-tab" class="tab-content">
                    <div class="mapping-section">
                        <h4><i class="fas fa-route"></i> Database Context Mapping</h4>
                        <p>Configure automatic database selection based on page patterns</p>
                        
                        <!-- Feature Comparison Info Box -->
                        <div class="feature-info" style="margin-bottom: 25px; padding: 15px; background: rgba(0, 123, 255, 0.1); border-radius: 8px; border: 1px solid rgba(0, 123, 255, 0.3);">
                            <h5 style="color: #007bff; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Context Mapping vs Page Permissions</h5>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; color: rgba(255,255,255,0.8);">
                                <div>
                                    <strong style="color: #00d4ff;">Context Mapping (This Tab):</strong>
                                    <ul style="margin: 8px 0 0 20px; font-size: 14px;">
                                        <li>Automatically switches databases based on URL patterns</li>
                                        <li>Pattern-based routing (page, directory, wildcard, regex)</li>
                                        <li>Seamless user experience</li>
                                        <li>One database per pattern</li>
                                    </ul>
                                </div>
                                <div>
                                    <strong style="color: #4caf50;">Page Permissions (Other Tab):</strong>
                                    <ul style="margin: 8px 0 0 20px; font-size: 14px;">
                                        <li>Controls access permissions per page</li>
                                        <li>Table-level security and operation restrictions</li>
                                        <li>Fine-grained access control (read/write/delete)</li>
                                        <li>Multiple tables with different permissions</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Auto-Switch Control -->
                        <div class="auto-switch-control" style="margin-bottom: 25px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <label style="display: flex; align-items: center; gap: 10px; color: white; cursor: pointer;">
                                <input type="checkbox" id="autoSwitchEnabled" onchange="toggleAutoSwitch()" style="transform: scale(1.2);">
                                <span><i class="fas fa-magic"></i> Enable Automatic Database Switching</span>
                            </label>
                            <p style="margin: 10px 0 0 32px; color: rgba(255,255,255,0.7); font-size: 14px;">
                                Automatically switch to the appropriate database based on page context
                            </p>
                        </div>
                        
                        <!-- Mapping Grid -->
                        <div class="mapping-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 10px; background: rgba(0,0,0,0.2); border-radius: 8px; overflow: hidden; margin: 20px 0;">
                            <div style="display: contents; font-weight: bold; background: #2196F3; color: white;">
                                <span style="padding: 12px; background: #2196F3;">Pattern</span>
                                <span style="padding: 12px; background: #2196F3;">Type</span>
                                <span style="padding: 12px; background: #2196F3;">Database</span>
                                <span style="padding: 12px; background: #2196F3;">Actions</span>
                            </div>
                            <div id="mappingRows" style="display: contents;">
                                <!-- Mapping rows will be loaded here -->
                            </div>
                        </div>
                        
                        <!-- Add New Mapping Section -->
                        <div class="add-mapping-section" style="margin-top: 30px; padding: 20px; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid rgba(255,255,255,0.1);">
                            <h5><i class="fas fa-plus"></i> Add Context Mapping</h5>
                            <p style="color: rgba(255,255,255,0.7); margin-bottom: 20px;">
                                Create automatic database switching rules based on page patterns. When a user visits a matching page, the system will automatically switch to the specified database.
                            </p>
                            <form id="addContextMappingForm">
                                <div class="form-row" style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 15px;">
                                    <div class="form-group">
                                        <label style="color: white; margin-bottom: 5px; display: block;">Type:</label>
                                        <select id="mappingType" name="mappingType" class="form-control" required onchange="updatePatternPlaceholder()">
                                            <option value="page">Specific Page</option>
                                            <option value="directory">Directory Pattern</option>
                                            <option value="wildcard">Wildcard Pattern</option>
                                            <option value="regex">Regular Expression</option>
                                        </select>
                                        <small style="color: rgba(255,255,255,0.6); margin-top: 5px; display: block;" id="typeHelp">
                                            Match a specific page file
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label style="color: white; margin-bottom: 5px; display: block;">Path Pattern:</label>
                                        <input type="text" id="mappingPath" name="mappingPath" placeholder="e.g., dashboard.php" class="form-control" required>
                                        <small style="color: rgba(255,255,255,0.6); margin-top: 5px; display: block;" id="pathHelp">
                                            Enter the specific page filename to match
                                        </small>
                                    </div>
                                </div>
                                <!-- Database selection is now automatic and dedicated to the main interface selection -->
                                <input type="hidden" id="mappingDatabase" name="mappingDatabase" value="">
                                <div class="form-group">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save"></i> Add Mapping
                                    </button>
                                    <button type="button" class="btn" onclick="testPatternMatch()" style="margin-left: 10px;">
                                        <i class="fas fa-flask"></i> Test Pattern
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Configuration Modal -->
    <div id="configModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle"><i class="fas fa-database"></i> Add Database Configuration</h2>
                <span class="close" onclick="closeConfigModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="configForm">
                    <input type="hidden" id="configId" name="config_id">
                    
                    <div class="form-group">
                        <label for="configName">Configuration Name *</label>
                        <input type="text" id="configName" name="name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="configStorageProfile">Storage Profile</label>
                        <select id="configStorageProfile" name="storage_profile" class="form-control" style="background-color:#333333;color:#ffffff;">
                            <option value="block_mysql">Block Storage - MySQL (/mysql)</option>
                            <option value="block_vector">Block Storage - Vector (/vector)</option>
                            <option value="block_graph">Block Storage - Graph (/graph)</option>
                            <option value="block_data">Block Storage - App Data (/data)</option>
                            <option value="block_backup">Block Storage - System Backups (/backup)</option>
                            <option value="custom">Custom / Legacy (manual host)</option>
                        </select>
                    </div>

                    <div class="form-group" id="adminConfigGroup" style="display:none;">
                        <label for="configAdminConfigId">Admin Config (Provisioner)</label>
                        <select id="configAdminConfigId" name="admin_config_id" class="form-control" style="background-color:#333333;color:#ffffff;">
                            <option value="">None</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="configHost">Host *</label>
                            <input type="text" id="configHost" name="host" class="form-control" value="localhost" required>
                        </div>
                        <div class="form-group">
                            <label for="configPort">Port *</label>
                            <input type="number" id="configPort" name="port" class="form-control" value="3306" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="configDatabase">Database Name *</label>
                        <input type="text" id="configDatabase" name="database" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="configUsername">Username *</label>
                            <input type="text" id="configUsername" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="configPassword">Password</label>
                            <input type="password" id="configPassword" name="password" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="configType">Database Type</label>
                            <select id="configType" name="type" class="form-control" style="background-color:#333333;color:#ffffff;">
                                <option value="mysql">MySQL</option>
                                <option value="mariadb">MariaDB</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="configCharset">Charset</label>
                            <select id="configCharset" name="charset" class="form-control">
                                <option value="utf8mb4">utf8mb4</option>
                                <option value="utf8">utf8</option>
                                <option value="latin1">latin1</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="testConnectionFromModal()">
                    <i class="fas fa-plug"></i> Test Connection
                </button>
                <button type="button" class="btn" onclick="saveConfig(true)">
                    <i class="fas fa-database"></i> Save & Create Database
                </button>
                <button type="button" class="btn btn-success" onclick="saveConfig()">
                    <i class="fas fa-save"></i> Save Configuration
                </button>
                <button type="button" class="btn" onclick="closeConfigModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h2>
                <span class="close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the configuration "<strong id="deleteConfigName"></strong>"?</p>
                <p style="color: var(--error-color); margin-top: var(--spacing);">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> This action cannot be undone.
                </p>
                <p style="color: var(--warning-color); margin-top: var(--spacing); font-size: 0.9em;">
                    <i class="fas fa-info-circle"></i> Note: You cannot delete the last remaining configuration or an active configuration.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button type="button" class="btn" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Page Browser Modal -->
    <div id="pageBrowserModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2><i class="fas fa-folder-open"></i> Browse Pages</h2>
                <span class="close" onclick="closePageBrowser()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="page-browser-container">
                    <!-- Selection Mode Toggle -->
                    <div class="form-group" style="margin-bottom: 15px; padding: 10px; background: rgba(0,123,255,0.1); border-radius: 6px; border: 1px solid rgba(0,123,255,0.3);">
                        <label style="color: white; margin-bottom: 10px; display: block;"><i class="fas fa-mouse-pointer"></i> Selection Mode:</label>
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="selectionMode" value="single" checked onchange="updateSelectionMode(this.value)"> Single Page
                            </label>
                            <label style="color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
                                <input type="radio" name="selectionMode" value="multiple" onchange="updateSelectionMode(this.value)"> Multiple Pages
                            </label>
                            <span id="selectedCount" style="color: #4CAF50; font-weight: bold; margin-left: 15px;">Selected: 0</span>
                        </div>
                    </div>
                    
                    <!-- Multiple Selection Controls -->
                    <div id="multipleSelectionControls" style="margin-bottom: 15px; padding: 10px; background: rgba(76,175,80,0.1); border-radius: 6px; border: 1px solid rgba(76,175,80,0.3); display: none;">
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <button type="button" class="btn btn-sm btn-success" onclick="selectAllVisiblePages()">
                                <i class="fas fa-check-double"></i> Select All Visible
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" onclick="clearPageSelection()">
                                <i class="fas fa-times"></i> Clear Selection
                            </button>
                            <button type="button" class="btn btn-sm btn-info" onclick="togglePageSelection()">
                                <i class="fas fa-exchange-alt"></i> Toggle Selection
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label style="color: white; margin-bottom: 10px; display: block;">Search Pages:</label>
                        <input type="text" id="pageSearchInput" placeholder="Type to search pages..." class="form-control" 
                               style="background: #1e3a8a; color: white; border-color: #3b82f6;" 
                               onkeyup="filterPages()">
                    </div>
                    
                    <div class="page-list-container" style="max-height: 400px; overflow-y: auto; margin-top: 15px; border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; background: rgba(0,0,0,0.2);">
                        <div id="pageList" style="padding: 10px;">
                            <div class="loading-placeholder" style="text-align: center; padding: 20px; color: rgba(255,255,255,0.6);">
                                <i class="fas fa-spinner fa-spin"></i> Loading pages...
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 15px;">
                        <label style="color: white; margin-bottom: 5px; display: block;">Or enter custom path:</label>
                        <input type="text" id="customPagePath" placeholder="e.g., admin/dashboard.php or /custom/page.php" 
                               class="form-control" style="background: #1e3a8a; color: white; border-color: #3b82f6;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" id="confirmSelectionBtn" onclick="confirmPageSelection()">
                    <i class="fas fa-check"></i> Confirm Selection
                </button>
                <button type="button" class="btn btn-secondary" onclick="selectCustomPage()">
                    <i class="fas fa-edit"></i> Use Custom Path
                </button>
                <button type="button" class="btn" onclick="closePageBrowser()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Select Tables Modal -->
    <div id="selectTablesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-list-check"></i> Select Tables to Save</h3>
                <span class="close" onclick="closeSelectTablesModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 15px;">
                    <label style="color: white; display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <input type="checkbox" id="selectAllTablesInModal" onchange="toggleAllTablesInModal(this.checked)">
                        <span>Select All Tables</span>
                    </label>
                </div>
                <div id="tablesList" style="max-height: 300px; overflow-y: auto; background: rgba(0,0,0,0.3); padding: 15px; border-radius: 4px;">
                    <!-- Tables will be loaded here -->
                </div>
                <div style="margin-top: 15px;">
                    <label style="color: white; display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="includeDataModal">
                        <span>Include Data</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" onclick="saveSelectedTables()">
                    <i class="fas fa-download"></i> Save Selected Tables
                </button>
                <button type="button" class="btn" onclick="closeSelectTablesModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
        const DBM_CONFIGS_SUMMARY = <?php echo json_encode($allConfigs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let currentConfigId = null;
        let deleteConfigId = null;
        let pagePermissions = {}; // Global variable to store page permissions
        let availablePages = []; // Store all available pages
        let selectedPages = new Set(); // Track selected pages for multiple selection
        let selectionMode = 'single'; // 'single' or 'multiple'
        let activeStorageTab = 'all';
        let tenantCardsLoadedOnce = false;
        let tenantCardsOffset = 0;
        let tenantCardsLimit = 200;
        let tenantCardsHasMore = false;

        const storageTabProfiles = {
            all: null,
            tenants: ['tenant'],
            mysql: ['block_mysql'],
            vector: ['block_vector'],
            graph: ['block_graph'],
            data: ['block_data'],
            backups: ['block_backup'],
            legacy: ['legacy', 'custom', '']
        };

        const knownStorageProfiles = new Set(['block_mysql', 'block_vector', 'block_graph', 'block_data', 'block_backup', 'tenant']);

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function escapeJs(value) {
            return String(value)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/\r/g, '\\r')
                .replace(/\n/g, '\\n');
        }

        function buildTenantCard(cfg) {
            const id = String(cfg && cfg.id ? cfg.id : '');
            const tenantId = String(cfg && cfg.tenant_id ? cfg.tenant_id : '');
            const name = String(cfg && cfg.name ? cfg.name : id);
            const type = String(cfg && cfg.type ? cfg.type : 'mariadb');
            const port = String(cfg && cfg.port ? cfg.port : '');
            const charset = String(cfg && cfg.charset ? cfg.charset : 'utf8mb4');
            const storageProfile = 'tenant';
            const active = cfg && cfg.is_active === true;
            const searchBlob = [id, tenantId, name, type, port, charset, storageProfile].join(' ').toLowerCase();
            const div = document.createElement('div');
            div.className = 'config-card ' + (active ? 'active' : '');
            div.setAttribute('data-config-id', id);
            div.setAttribute('data-storage-profile', storageProfile);
            div.setAttribute('data-search', searchBlob);
            div.innerHTML = `
                ${active ? '<div class="active-badge"><i class="fas fa-check"></i> Active</div>' : ''}
                <div class="config-info">
                    <h3><i class="fas fa-user"></i> ${escapeHtml(tenantId !== '' ? tenantId : name)}</h3>
                    <p><strong>Config ID:</strong> ${escapeHtml(id)}</p>
                    <p><strong>DB Name:</strong> ${escapeHtml(name)}</p>
                    <p><strong>Type:</strong> ${escapeHtml(type)}</p>
                    <p><strong>Port:</strong> ${escapeHtml(port)}</p>
                    <p><strong>Charset:</strong> ${escapeHtml(charset)}</p>
                </div>
                <div class="config-actions">
                    <button class="btn btn-info" type="button" onclick="openConfigModal('${escapeJs(id)}')">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
            `;
            return div;
        }

        function clearTenantCards() {
            document.querySelectorAll('.config-card[data-storage-profile="tenant"]').forEach(el => el.remove());
        }

        function setTenantControlsVisible(visible) {
            const el = document.getElementById('tenantConfigsControls');
            if (!el) return;
            el.style.display = visible ? 'block' : 'none';
        }

        function loadTenantConfigCards(reset = false) {
            if (reset) {
                tenantCardsOffset = 0;
                tenantCardsHasMore = false;
                clearTenantCards();
            }
            const bodyParams = new URLSearchParams();
            bodyParams.set('action', 'get_active_databases');
            bodyParams.set('include_tenants', '1');
            bodyParams.set('tenant_limit', String(tenantCardsLimit));
            bodyParams.set('tenant_offset', String(tenantCardsOffset));
            bodyParams.set('tenant_query', '');
            return fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: bodyParams.toString()
            })
            .then(r => r.json())
            .then(data => {
                const grid = document.querySelector('.configs-grid');
                if (!grid) return;
                const tenantList = Array.isArray(data.tenants) ? data.tenants : [];
                tenantList.forEach(cfg => {
                    grid.appendChild(buildTenantCard(cfg));
                });
                const pag = data.tenant_pagination || {};
                tenantCardsHasMore = !!pag.has_more;
                setTenantControlsVisible(activeStorageTab === 'tenants' && tenantCardsHasMore);
                tenantCardsLoadedOnce = true;
                filterDatabaseCards();
            })
            .catch(() => {
                setTenantControlsVisible(false);
            });
        }

        function loadMoreTenantConfigCards() {
            tenantCardsOffset = tenantCardsOffset + tenantCardsLimit;
            loadTenantConfigCards(false);
        }

        function getAdminCandidateConfigs(currentId = null) {
            const configs = Array.isArray(DBM_CONFIGS_SUMMARY) ? DBM_CONFIGS_SUMMARY : [];
            let candidates = configs.filter(cfg => {
                if (!cfg || typeof cfg !== 'object') return false;
                const id = String(cfg.id || '');
                if (!id) return false;
                if (currentId && id === String(currentId)) return false;
                const active = cfg.is_active === true;
                if (!active) return false;
                const profile = String(cfg.storage_profile || '');
                const port = String(cfg.port || '');
                if (profile !== 'block_mysql') return false;
                if (port !== '3307') return false;
                return true;
            });
            // Sort to put provisioner/root first
            candidates.sort((a, b) => {
                const aName = (a.name || '').toLowerCase();
                const bName = (b.name || '').toLowerCase();
                const aIsProv = aName.includes('provisioner') || aName.includes('root');
                const bIsProv = bName.includes('provisioner') || bName.includes('root');
                if (aIsProv && !bIsProv) return -1;
                if (!aIsProv && bIsProv) return 1;
                return 0;
            });
            return candidates;
        }

        function updateAdminConfigUI(storageProfile, currentId = null, selectedAdminId = '') {
            const group = document.getElementById('adminConfigGroup');
            const select = document.getElementById('configAdminConfigId');
            if (!group || !select) return;
            const sp = String(storageProfile || 'custom');
            if (sp !== 'block_mysql') {
                group.style.display = 'none';
                select.innerHTML = '<option value=\"\">None</option>';
                select.value = '';
                return;
            }
            group.style.display = 'block';
            const candidates = getAdminCandidateConfigs(currentId);
            const options = ['<option value=\"\">None</option>'].concat(
                candidates.map(cfg => {
                    const id = String(cfg.id || '');
                    const name = String(cfg.name || id);
                    return `<option value=\"${id}\">${name} (${id})</option>`;
                })
            );
            select.innerHTML = options.join('');
            if (selectedAdminId) {
                select.value = selectedAdminId;
            } else if (candidates.length > 0) {
                select.value = String(candidates[0].id || '');
            }
        }

        function showStorageTab(tabKey) {
            activeStorageTab = tabKey;
            document.querySelectorAll('.config-tab-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tabKey);
            });

            const maintenance = document.getElementById('maintenancePanel');
            const grid = document.querySelector('.configs-grid');
            if (maintenance) {
                maintenance.style.display = (tabKey === 'maintenance') ? 'block' : 'none';
            }
            if (grid) {
                grid.style.display = (tabKey === 'maintenance') ? 'none' : 'grid';
            }
            if (tabKey === 'tenants' && !tenantCardsLoadedOnce) {
                loadTenantConfigCards(true);
            }
            setTenantControlsVisible(tabKey === 'tenants' && tenantCardsHasMore);
            filterDatabaseCards();
        }

        function filterDatabaseCards() {
            const input = document.getElementById('dbSearch');
            const q = input ? (input.value || '').toLowerCase().trim() : '';
            document.querySelectorAll('.config-card').forEach(function (card) {
                const blob = (card.getAttribute('data-search') || '').toLowerCase();
                const profile = (card.getAttribute('data-storage-profile') || '').toLowerCase();
                let okTab = true;
                if (activeStorageTab === 'maintenance') {
                    okTab = false;
                } else if (activeStorageTab === 'all') {
                    okTab = profile !== 'tenant';
                } else if (activeStorageTab === 'legacy') {
                    okTab = !knownStorageProfiles.has(profile);
                } else {
                    const allowed = storageTabProfiles[activeStorageTab] || null;
                    okTab = allowed ? allowed.indexOf(profile) !== -1 : true;
                }
                const okSearch = q === '' ? true : (blob.indexOf(q) !== -1);
                card.style.display = (okTab && okSearch) ? '' : 'none';
            });
        }

        // Open configuration modal
        function openConfigModal(configId = null) {
            currentConfigId = configId;
            const modal = document.getElementById('configModal');
            const title = document.getElementById('modalTitle');
            const form = document.getElementById('configForm');
            
            if (configId) {
                title.innerHTML = '<i class="fas fa-edit"></i> Edit Database Configuration';
                loadConfigForEdit(configId);
            } else {
                title.innerHTML = '<i class="fas fa-plus"></i> Add Database Configuration';
                form.reset();
                document.getElementById('configId').value = '';
                const sp = document.getElementById('configStorageProfile') ? document.getElementById('configStorageProfile').value : 'custom';
                updateAdminConfigUI(sp, null, '');
            }
            
            modal.style.display = 'block';
        }

        // Close configuration modal
        function closeConfigModal() {
            document.getElementById('configModal').style.display = 'none';
            currentConfigId = null;
        }

        // Load configuration for editing
        function loadConfigForEdit(configId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_config&config_id=${configId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const config = data.config;
                    document.getElementById('configId').value = config.id;
                    document.getElementById('configName').value = config.name;
                    document.getElementById('configStorageProfile').value = config.storage_profile || 'custom';
                    document.getElementById('configHost').value = config.host;
                    document.getElementById('configPort').value = config.port;
                    document.getElementById('configDatabase').value = config.database;
                    document.getElementById('configUsername').value = config.username;
                    document.getElementById('configPassword').value = config.password;
                    document.getElementById('configType').value = config.type;
                    document.getElementById('configCharset').value = config.charset;
                    updateAdminConfigUI(config.storage_profile || 'custom', config.id, config.admin_config_id || '');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to load configuration', 'error');
            });
        }

        function showDbManagerNotice(message, type = 'info') {
            if (typeof showToast === 'function') {
                showToast(message, type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info'));
                return;
            }
            alert(message);
        }

        function createDatabase(configId) {
            if (!configId) {
                showDbManagerNotice('Configuration ID is missing', 'error');
                return Promise.resolve({ success: false, message: 'Configuration ID is missing' });
            }
            return fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_database&config_id=${encodeURIComponent(configId)}`
            })
            .then(response => response.json())
            .then(data => {
                showDbManagerNotice(data.message || 'Create database completed', data.success ? 'success' : 'error');
                if (typeof refreshDbStatuses === 'function') {
                    try { refreshDbStatuses(); } catch (e) {}
                }
                return data;
            })
            .catch(error => {
                showDbManagerNotice('Failed to create database: ' + error.message, 'error');
                return { success: false, message: error.message };
            });
        }

        // Save configuration
        function saveConfig(createDatabaseAfterSave = false) {
            const form = document.getElementById('configForm');
            const formData = new FormData(form);
            formData.append('action', 'save_config');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (typeof showToast === 'function') {
                        showToast(data.message, 'success');
                    } else {
                        alert(data.message);
                    }
                    const savedConfigId = data.config_id || document.getElementById('configId').value;
                    if (createDatabaseAfterSave) {
                        return createDatabase(savedConfigId).then(() => {
                            closeConfigModal();
                            setTimeout(() => location.reload(), 1500);
                        });
                    }
                    closeConfigModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to save configuration', 'error');
            });
        }

        // Test connection from modal
        function testConnectionFromModal() {
            const form = document.getElementById('configForm');
            const formData = new FormData(form);
            formData.append('action', 'test_connection');

            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<div class="loading"></div> Testing...';
            button.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
            })
            .catch(error => {
                showToast('Connection test failed', 'error');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Test connection for existing config
        function testConnection(configId) {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<div class="loading"></div> Testing...';
            button.disabled = true;

            // First get the config, then test it
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_config&config_id=${configId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const config = data.config;
                    const formData = new FormData();
                    formData.append('action', 'test_connection');
                    formData.append('storage_profile', config.storage_profile || 'custom');
                    formData.append('type', config.type);
                    formData.append('charset', config.charset);
                    formData.append('host', config.host);
                    formData.append('port', config.port);
                    formData.append('database', config.database);
                    formData.append('username', config.username);
                    formData.append('password', config.password);

                    return fetch('', {
                        method: 'POST',
                        body: formData
                    });
                } else {
                    throw new Error(data.message);
                }
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
            })
            .catch(error => {
                showToast('Connection test failed: ' + error.message, 'error');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }

        // Edit configuration
        function editConfig(configId) {
            openConfigModal(configId);
        }

        // Toggle active configuration
        function toggleActive(configId) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=set_active&config_id=${configId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to set active configuration', 'error');
            });
        }

        function renderStatusBadges(status) {
            const badges = [];
            badges.push(`<span class="badge ${status.is_active ? 'badge-green' : 'badge-gray'}">${status.is_active ? 'Config Active' : 'Config Inactive'}</span>`);
            badges.push(`<span class="badge ${status.port_reachable ? 'badge-green' : 'badge-red'}">${status.port_reachable ? 'Online' : 'Offline'}</span>`);
            if (status.can_authenticate) {
                badges.push(`<span class="badge badge-green">Auth OK</span>`);
            } else if (status.port_reachable) {
                badges.push(`<span class="badge badge-red">Auth Failed</span>`);
            }
            if (status.database_exists === true) {
                badges.push(`<span class="badge badge-green">DB Exists</span>`);
            } else if (status.database_exists === false) {
                badges.push(`<span class="badge badge-red">DB Missing</span>`);
            }
            return badges.join(' ');
        }

        function refreshDbStatuses() {
            const nodes = document.querySelectorAll('.db-status[data-config-id]');
            nodes.forEach(node => {
                const configId = node.getAttribute('data-config-id');
                if (!configId) return;
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_db_status&config_id=${encodeURIComponent(configId)}`
                })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success || !data.status) {
                        node.innerHTML = '';
                        return;
                    }
                    node.innerHTML = renderStatusBadges(data.status);
                })
                .catch(() => {
                    node.innerHTML = '';
                });
            });
        }

        // Delete configuration
        function deleteConfig(configId, configName) {
            deleteConfigId = configId;
            document.getElementById('deleteConfigName').textContent = configName;
            document.getElementById('deleteModal').style.display = 'block';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            deleteConfigId = null;
        }

        // Confirm deletion
        function confirmDelete() {
            if (!deleteConfigId) return;

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_config&config_id=${deleteConfigId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    closeDeleteModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to delete configuration', 'error');
            });
        }

        // Show toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'}"></i> ${message}`;
            
            document.body.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 100);
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => document.body.removeChild(toast), 300);
            }, 4000);
        }

        // API request function (backup operations removed)
        async function makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message || 'Operation completed successfully', 'success');
                } else {
                    showToast(result.message || 'Operation failed', 'error');
                }
                
                return result;
            } catch (error) {
                showToast('Request failed: ' + error.message, 'error');
                return { success: false, message: error.message };
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const configModal = document.getElementById('configModal');
            const deleteModal = document.getElementById('deleteModal');
            const selectTablesModal = document.getElementById('selectTablesModal');
            
            if (event.target === configModal) {
                closeConfigModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
            if (event.target === selectTablesModal) {
                closeSelectTablesModal();
            }
        }

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeConfigModal();
                closeDeleteModal();
            }
        });

        // Database Operations Functions
        let currentPage = 1;
        let recordsPerPage = 10;
        let currentTable = '';
        let recordsSearch = '';
        let recordsSearchDebounce = null;
        let lastRecordsPagination = null;
        let tenantPagination = { offset: 0, limit: 200, has_more: false, query: '' };

        // Load active databases with enhanced error handling
        function loadActiveDatabases(opts = {}) {
            console.log('Loading active databases...');
            const appendTenants = !!opts.appendTenants;
            
            // Check if CUE framework functions are available
            if (typeof fetch === 'undefined') {
                console.error('Fetch API not available');
                showToast('Browser not supported - fetch API required', 'error');
                return;
            }

            const includeTenants = true;
            const bodyParams = new URLSearchParams();
            bodyParams.set('action', 'get_active_databases');
            bodyParams.set('include_tenants', includeTenants ? '1' : '0');
            bodyParams.set('tenant_limit', String(tenantPagination.limit));
            bodyParams.set('tenant_offset', String(tenantPagination.offset));
            bodyParams.set('tenant_query', tenantPagination.query || '');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: bodyParams.toString()
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Active databases response:', data);
                const select = document.getElementById('selectedDatabase');
                const schemaSelect = document.getElementById('schemaSelectedDatabase');
                const sqlSelect = document.getElementById('sqlSelectedDatabase');
                
                if (!select) {
                    console.error('selectedDatabase element not found!');
                    return;
                }

                function labelForDb(db) {
                    const tenantId = db && typeof db.tenant_id === 'string' ? db.tenant_id : '';
                    const name = db && typeof db.name === 'string' ? db.name : '';
                    const type = db && typeof db.type === 'string' ? db.type : '';
                    if (tenantId !== '') {
                        return `${tenantId} (${name})`;
                    }
                    return `${name} (${type})`;
                }

                function clearAndSeedSelect(sel, placeholder) {
                    sel.innerHTML = '';
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = placeholder;
                    sel.appendChild(opt);
                }

                function ensureGroup(sel, label) {
                    const groups = Array.from(sel.querySelectorAll('optgroup'));
                    const existing = groups.find(g => g.label === label);
                    if (existing) return existing;
                    const g = document.createElement('optgroup');
                    g.label = label;
                    sel.appendChild(g);
                    return g;
                }

                function removeLoadMoreOption(sel) {
                    const opt = sel.querySelector('option[value="__load_more_tenants__"]');
                    if (opt && opt.parentElement) {
                        opt.parentElement.removeChild(opt);
                    }
                }

                function appendOptions(group, list, sel, addDataDatabase) {
                    list.forEach(db => {
                        const option = document.createElement('option');
                        option.value = db.id;
                        if (addDataDatabase) {
                            option.setAttribute('data-database', db.database);
                        }
                        option.textContent = labelForDb(db);
                        group.appendChild(option);
                    });
                }

                if (!appendTenants) {
                    clearAndSeedSelect(select, 'Select a database...');
                    if (schemaSelect) clearAndSeedSelect(schemaSelect, 'Select a database configuration...');
                    if (sqlSelect) clearAndSeedSelect(sqlSelect, 'Select a database configuration...');
                } else {
                    removeLoadMoreOption(select);
                    if (schemaSelect) removeLoadMoreOption(schemaSelect);
                    if (sqlSelect) removeLoadMoreOption(sqlSelect);
                }
                
                if (data.success) {
                    const coreList = Array.isArray(data.databases) ? data.databases : [];
                    const tenantList = Array.isArray(data.tenants) ? data.tenants : [];
                    const coreGroup = ensureGroup(select, 'Core');
                    const tenantGroup = ensureGroup(select, 'Tenants');
                    const schemaCoreGroup = schemaSelect ? ensureGroup(schemaSelect, 'Core') : null;
                    const schemaTenantGroup = schemaSelect ? ensureGroup(schemaSelect, 'Tenants') : null;
                    const sqlCoreGroup = sqlSelect ? ensureGroup(sqlSelect, 'Core') : null;
                    const sqlTenantGroup = sqlSelect ? ensureGroup(sqlSelect, 'Tenants') : null;

                    if (!appendTenants) {
                        coreGroup.innerHTML = '';
                        tenantGroup.innerHTML = '';
                        if (schemaCoreGroup) schemaCoreGroup.innerHTML = '';
                        if (schemaTenantGroup) schemaTenantGroup.innerHTML = '';
                        if (sqlCoreGroup) sqlCoreGroup.innerHTML = '';
                        if (sqlTenantGroup) sqlTenantGroup.innerHTML = '';
                    }

                    appendOptions(coreGroup, coreList, select, false);
                    if (schemaCoreGroup) appendOptions(schemaCoreGroup, coreList, schemaSelect, false);
                    if (sqlCoreGroup) appendOptions(sqlCoreGroup, coreList, sqlSelect, true);

                    appendOptions(tenantGroup, tenantList, select, false);
                    if (schemaTenantGroup) appendOptions(schemaTenantGroup, tenantList, schemaSelect, false);
                    if (sqlTenantGroup) appendOptions(sqlTenantGroup, tenantList, sqlSelect, true);

                    const pag = data.tenant_pagination || {};
                    tenantPagination.has_more = !!pag.has_more;
                    if (typeof pag.offset === 'number') tenantPagination.offset = pag.offset;
                    if (typeof pag.limit === 'number') tenantPagination.limit = pag.limit;
                    if (typeof pag.query === 'string') tenantPagination.query = pag.query;

                    if (tenantPagination.has_more) {
                        const moreOpt = document.createElement('option');
                        moreOpt.value = '__load_more_tenants__';
                        moreOpt.textContent = 'Load more tenants...';
                        tenantGroup.appendChild(moreOpt);
                        if (schemaTenantGroup) schemaTenantGroup.appendChild(moreOpt.cloneNode(true));
                        if (sqlTenantGroup) sqlTenantGroup.appendChild(moreOpt.cloneNode(true));
                    }
                    console.log('Databases loaded successfully');
                } else {
                    console.error('Failed to load databases:', data.message || 'Unknown error');
                    showToast('Failed to load active databases: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error loading databases:', error);
                showToast('Failed to load active databases: ' + error.message, 'error');
            });
        }

        // Enhanced database switching with loading indicators
        function switchDatabase() {
            const select = document.getElementById('selectedDatabase');
            const configId = select.value;
            
            if (!configId) {
                document.getElementById('tableOperations').style.display = 'none';
                return;
            }

            // Add loading state to database selector
            select.classList.add('loading');
            const originalText = select.options[select.selectedIndex].text;
            select.options[select.selectedIndex].text = 'Loading...';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=switch_database&config_id=${configId}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('tableOperations').style.display = 'block';
                    loadDatabaseTables();
                } else {
                    showToast(data.message, 'error');
                    document.getElementById('tableOperations').style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error switching database:', error);
                showToast('Failed to switch database', 'error');
                document.getElementById('tableOperations').style.display = 'none';
            })
            .finally(() => {
                // Remove loading state
                select.classList.remove('loading');
                select.options[select.selectedIndex].text = originalText;
            });
        }

        // Show tab content
        function showTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // Load content based on tab
            if (tabId === 'tables-tab') {
                loadDatabaseTables();
            } else if (tabId === 'records-tab') {
                loadTablesList();
            } else if (tabId === 'sql-files-tab') {
                browseSqlFiles('');
            } else if (tabId === 'schema-tab') {
                // Initialize schema manager - no specific loading needed as buttons handle their own actions
                console.log('Schema Manager tab activated');
            } else if (tabId === 'page-permissions-tab') {
                initializePagePermissionsTabContent();
            } else if (tabId === 'context-mapping-tab') {
                initializeContextMappingTabContent();
            }
        }

        // Load tables with enhanced loading states and error handling
        function loadDatabaseTables() {
            const select = document.getElementById('selectedDatabase');
            if (!select) {
                console.error('selectedDatabase element not found');
                return;
            }
            
            const configId = select.value;
            
            if (!configId) {
                document.getElementById('tablesContainer').innerHTML = '<p>Please select a database first.</p>';
                document.getElementById('tableOperations').style.display = 'none';
                return;
            }
            if (configId === '__load_more_tenants__') {
                tenantPagination.offset = tenantPagination.offset + tenantPagination.limit;
                select.value = '';
                document.getElementById('tableOperations').style.display = 'none';
                loadActiveDatabases({ appendTenants: true });
                return;
            }
            
            // Show loading state
            const container = document.getElementById('tablesContainer');
            container.innerHTML = '<div class="loading-overlay"><div class="loading-spinner"></div><p>Loading tables...</p></div>';
            
            // Show the table operations section when a database is selected
            document.getElementById('tableOperations').style.display = 'block';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_tables&config_id=${configId}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.tables) {
                    displayTables(data.tables);
                    
                    // Update our new tabs with the selected database
                    updatePagePermissionsDatabase(configId);
                    updateContextMappingDatabase(configId);
                } else {
                    container.innerHTML = '<p class="error-message">No tables found or failed to load tables.</p>';
                    showToast(data.message || 'Failed to load tables', 'error');
                }
            })
            .catch(error => {
                console.error('Error loading tables:', error);
                container.innerHTML = '<p class="error-message">Failed to load tables. Please try again.</p>';
                showToast('Failed to load tables', 'error');
            });
        }

        // Enhanced table display with metadata and tooltips
        function displayTables(tables) {
            const container = document.getElementById('tablesContainer');
            container.innerHTML = '';
            
            tables.forEach(table => {
                const tableCard = document.createElement('div');
                tableCard.className = 'table-card';
                
                // Determine table status based on row count
                const rowCount = parseInt(table.rows) || 0;
                let statusClass = 'healthy';
                let statusIcon = 'check-circle';
                let statusText = 'Healthy';
                
                if (rowCount === 0) {
                    statusClass = 'warning';
                    statusIcon = 'exclamation-triangle';
                    statusText = 'Empty';
                } else if (rowCount > 100000) {
                    statusClass = 'warning';
                    statusIcon = 'exclamation-triangle';
                    statusText = 'Large';
                }
                
                tableCard.innerHTML = `
                    <h4 title="Table: ${table.name}">
                        <i class="fas fa-table"></i> ${table.name}
                    </h4>
                    <div class="table-metadata">
                        <div class="metadata-item" title="Number of rows in table">
                            <i class="fas fa-list-ol"></i>
                            <span>Rows: ${formatNumber(rowCount)}</span>
                        </div>
                        <div class="metadata-item table-status ${statusClass}" title="Table status: ${statusText}">
                            <i class="fas fa-${statusIcon}"></i>
                            <span>${statusText}</span>
                        </div>
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-sm" onclick="viewTableStructure('${table.name}')" 
                                title="View table structure and column definitions">
                            <i class="fas fa-eye"></i> Structure
                        </button>
                        <button class="btn btn-sm" onclick="viewTableRecords('${table.name}')" 
                                title="Browse table records and data">
                            <i class="fas fa-th"></i> Records
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="dropTable('${table.name}')" 
                                title="Delete this table permanently">
                            <i class="fas fa-trash"></i> Drop
                        </button>
                    </div>
                `;
                container.appendChild(tableCard);
            });
        }

        // Enhanced number formatting
        function formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num.toString();
        }

        // Load tables list for records tab
        function loadTablesList() {
            const select = document.getElementById('selectedDatabase');
            if (!select) {
                console.error('selectedDatabase element not found');
                return;
            }
            
            const configId = select.value;
            
            if (!configId) {
                document.getElementById('recordsTableSelect').innerHTML = '<option value="">Please select a database first</option>';
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_tables&config_id=${configId}`
            })
            .then(response => response.json())
            .then(data => {
                const select = document.getElementById('selectedTable');
                select.innerHTML = '<option value="">Select a table...</option>';
                
                if (data.success && data.tables) {
                    data.tables.forEach(table => {
                        const option = document.createElement('option');
                        option.value = table.name;
                        option.textContent = table.name;
                        select.appendChild(option);
                    });
                }
            })
            .catch(error => {
                showToast('Failed to load tables list', 'error');
            });
        }

        // Load table records
        function loadTableRecords() {
            const select = document.getElementById('selectedDatabase');
            const configId = select.value;
            const tableName = document.getElementById('selectedTable').value;
            
            if (!configId) {
                document.getElementById('recordsContainer').innerHTML = '<p>Please select a database first.</p>';
                document.getElementById('paginationContainer').innerHTML = '';
                return;
            }
            
            if (!tableName) {
                document.getElementById('recordsContainer').innerHTML = '';
                document.getElementById('paginationContainer').innerHTML = '';
                const infoEl = document.getElementById('recordsCountInfo');
                if (infoEl) infoEl.textContent = '';
                return;
            }

            if (currentTable !== tableName) {
                currentPage = 1;
            }
            currentTable = tableName;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: (() => {
                    const form = new URLSearchParams();
                    form.set('action', 'get_records');
                    form.set('config_id', String(configId || ''));
                    form.set('table', String(tableName || ''));
                    form.set('page', String(currentPage || 1));
                    form.set('limit', String(recordsPerPage || 10));
                    if (recordsSearch) {
                        form.set('search', String(recordsSearch));
                    }
                    return form.toString();
                })()
            })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('recordsContainer');
                
                if (data.success && data.records) {
                    if (data.records.length > 0) {
                        currentRecords = data.records;
                        const p = (data.pagination && typeof data.pagination === 'object') ? data.pagination : {};
                        const totalPages = Number(p.total_pages ?? data.total_pages ?? 1) || 1;
                        const currentPageNum = Number(p.current_page ?? data.current_page ?? currentPage) || 1;
                        const totalRecords = Number(p.total_records ?? data.total_records ?? 0) || 0;
                        lastRecordsPagination = p;
                        currentPage = Math.max(1, currentPageNum);

                        const infoEl = document.getElementById('recordsCountInfo');
                        if (infoEl) {
                            const from = Number(p.from ?? 0) || 0;
                            const to = Number(p.to ?? 0) || 0;
                            if (totalRecords > 0) {
                                infoEl.textContent = (from > 0 && to > 0)
                                    ? `Showing ${from}-${to} of ${totalRecords}`
                                    : `Showing ${data.records.length} of ${totalRecords}`;
                            } else {
                                infoEl.textContent = `Showing ${data.records.length}`;
                            }
                        }

                        // Create table
                        let tableHtml = '<div class="records-table-container"><table class="records-table"><thead><tr>';
                        
                        // Add headers
                        Object.keys(data.records[0]).forEach(key => {
                            tableHtml += `<th>${key}</th>`;
                        });
                        tableHtml += '<th>Actions</th></tr></thead><tbody>';
                        
                        // Add rows
                        data.records.forEach((record, index) => {
                            tableHtml += '<tr>';
                            Object.values(record).forEach(value => {
                                tableHtml += `<td>${value || ''}</td>`;
                            });
                            tableHtml += `
                                <td>
                                    <button class="btn btn-sm" onclick="editRecord(${index})">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteRecord(${index})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>`;
                        });
                        
                        tableHtml += '</tbody></table></div>';
                        container.innerHTML = tableHtml;
                        
                        // Update pagination
                        updatePagination(totalPages, currentPageNum);
                    } else {
                        container.innerHTML = '<p>No records found in this table.</p>';
                        document.getElementById('paginationContainer').innerHTML = '';
                        const infoEl = document.getElementById('recordsCountInfo');
                        if (infoEl) infoEl.textContent = '';
                        updatePagination(1, 1);
                    }
                } else {
                    container.innerHTML = '<p>Failed to load records.</p>';
                    document.getElementById('paginationContainer').innerHTML = '';
                    const infoEl = document.getElementById('recordsCountInfo');
                    if (infoEl) infoEl.textContent = '';
                }
            })
            .catch(error => {
                showToast('Failed to load table records', 'error');
            });
        }

        // Update pagination
        function updatePagination(totalPages, currentPageNum) {
            const container = document.getElementById('paginationContainer');
            
            if (!container) return;
            totalPages = Number(totalPages) || 1;
            currentPageNum = Number(currentPageNum) || 1;
            if (totalPages < 1) totalPages = 1;
            if (currentPageNum < 1) currentPageNum = 1;
            if (currentPageNum > totalPages) currentPageNum = totalPages;

            const pageSelect = document.getElementById('recordsPageSelect');
            if (pageSelect) {
                pageSelect.innerHTML = '';
                if (totalPages <= 1) {
                    const o = document.createElement('option');
                    o.value = '1';
                    o.textContent = 'Page 1';
                    pageSelect.appendChild(o);
                    pageSelect.disabled = true;
                    pageSelect.value = '1';
                } else {
                    const ids = new Set();
                    const addRange = (a, b) => {
                        for (let i = a; i <= b; i++) ids.add(i);
                    };
                    if (totalPages <= 200) {
                        addRange(1, totalPages);
                    } else {
                        addRange(1, 50);
                        addRange(Math.max(1, currentPageNum - 25), Math.min(totalPages, currentPageNum + 25));
                        addRange(Math.max(1, totalPages - 49), totalPages);
                    }
                    const pages = Array.from(ids).sort((a, b) => a - b);
                    for (const p of pages) {
                        const o = document.createElement('option');
                        o.value = String(p);
                        o.textContent = `Page ${p}`;
                        pageSelect.appendChild(o);
                    }
                    pageSelect.disabled = false;
                    pageSelect.value = String(currentPageNum);
                }
            }
            
            let paginationHtml = '<div class="pagination">';
            
            // Previous button
            paginationHtml += `<button ${currentPageNum <= 1 ? 'disabled' : ''} onclick="changePage(${currentPageNum - 1})">
                <i class="fas fa-chevron-left"></i>
            </button>`;
            
            // Page numbers
            if (totalPages <= 25) {
                for (let i = 1; i <= totalPages; i++) {
                    paginationHtml += `<button class="${i === currentPageNum ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
                }
            } else {
                for (let i = 1; i <= totalPages; i++) {
                    if (i === currentPageNum || i === 1 || i === totalPages || (i >= currentPageNum - 1 && i <= currentPageNum + 1)) {
                        paginationHtml += `<button class="${i === currentPageNum ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
                    } else if (i === currentPageNum - 2 || i === currentPageNum + 2) {
                        paginationHtml += '<span>...</span>';
                    }
                }
            }

            paginationHtml += `<span class="page-info">Page ${currentPageNum} of ${totalPages}</span>`;
            
            // Next button
            paginationHtml += `<button ${currentPageNum >= totalPages ? 'disabled' : ''} onclick="changePage(${currentPageNum + 1})">
                <i class="fas fa-chevron-right"></i>
            </button>`;
            
            paginationHtml += '</div>';
            container.innerHTML = paginationHtml;
        }

        // Change page
        function changePage(page) {
            page = Number(page) || 1;
            if (page < 1) page = 1;
            currentPage = page;
            loadTableRecords();
        }

        // Refresh tables
        function refreshTables() {
            loadDatabaseTables();
        }

        // Update file label to show selected file name
        function updateFileLabel() {
            const fileInput = document.getElementById('sqlFile');
            const label = document.getElementById('sqlFileLabel');
            
            if (fileInput.files.length > 0) {
                const fileName = fileInput.files[0].name;
                label.innerHTML = `<i class="fas fa-file-code"></i> ${fileName}`;
                label.style.color = '#28a745';
            } else {
                label.innerHTML = '<i class="fas fa-cloud-upload-alt"></i> Choose SQL File';
                label.style.color = '';
            }
        }

        // Upload SQL file
        function uploadSqlFile() {
            const fileInput = document.getElementById('sqlFile');
            const file = fileInput.files[0];
            
            if (!file) {
                showToast('Please select a SQL file', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'upload_sql_file');
            formData.append('sql_file', file);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    fileInput.value = '';
                    updateFileLabel(); // Reset the label
                    browseSqlFiles(''); // Refresh the file list
                }
            })
            .catch(error => {
                showToast('Failed to upload SQL file', 'error');
            });
        }

        // Browse SQL files
        function browseSqlFiles(path = '') {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=browse_sql_files&path=${encodeURIComponent(path)}`
            })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('sqlFilesContainer');
                
                if (data.success && data.items) {
                    let html = '';
                    
                    // Breadcrumb
                    if (path) {
                        html += '<div class="breadcrumb">';
                        const pathParts = path.split('/').filter(p => p);
                        html += '<a href="#" class="breadcrumb-item" onclick="browseSqlFiles(\'\')">Home</a>';
                        
                        let currentPath = '';
                        pathParts.forEach((part, index) => {
                            currentPath += part + '/';
                            if (index === pathParts.length - 1) {
                                html += ` / <span class="breadcrumb-item active">${part}</span>`;
                            } else {
                                html += ` / <a href="#" class="breadcrumb-item" onclick="browseSqlFiles('${currentPath}')">${part}</a>`;
                            }
                        });
                        html += '</div>';
                    }
                    
                    // File browser
                    html += '<div class="file-browser">';
                    
                    data.items.forEach(file => {
                        html += `
                            <div class="file-item">
                                <div class="file-icon">
                                    <i class="fas fa-${file.type === 'directory' ? 'folder' : 'file-code'}"></i>
                                </div>
                                <div class="file-info">
                                    <div class="file-name">${file.name}</div>
                                    <div class="file-details">${file.size || ''} ${file.modified || ''}</div>
                                </div>
                                <div class="file-actions">
                        `;
                        
                        if (file.type === 'directory') {
                            html += `<button class="btn btn-sm" onclick="browseSqlFiles('${file.path}')">
                                <i class="fas fa-folder-open"></i> Open
                            </button>`;
                        } else {
                            html += `
                                <button class="btn btn-sm" onclick="executeSqlFile('${file.path}')">
                                    <i class="fas fa-play"></i> Execute
                                </button>
                                <button class="btn btn-sm" onclick="downloadSqlFile('${file.path}')">
                                    <i class="fas fa-download"></i> Download
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deleteSqlFile('${file.path}')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            `;
                        }
                        
                        html += '</div></div>';
                    });
                    
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p>No files found or failed to browse directory.</p>';
                }
            })
            .catch(error => {
                showToast('Failed to browse SQL files', 'error');
            });
        }

        // Search SQL files
        function searchSqlFiles() {
            const query = document.getElementById('sqlSearchQuery').value;
            
            if (!query.trim()) {
                showToast('Please enter a search query', 'error');
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=search_sql_files&query=${encodeURIComponent(query)}`
            })
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('sqlFilesContainer');
                
                if (data.success && data.files) {
                    let html = '<div class="file-browser">';
                    
                    if (data.files.length > 0) {
                        data.files.forEach(file => {
                            html += `
                                <div class="file-item">
                                    <div class="file-icon">
                                        <i class="fas fa-file-code"></i>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name">${file.name}</div>
                                        <div class="file-details">${file.path}</div>
                                    </div>
                                    <div class="file-actions">
                                        <button class="btn btn-sm" onclick="executeSqlFile('${file.path}')">
                                            <i class="fas fa-play"></i> Execute
                                        </button>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        html += '<p>No SQL files found matching your search.</p>';
                    }
                    
                    html += '</div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p>Search failed or no results found.</p>';
                }
            })
            .catch(error => {
                showToast('Failed to search SQL files', 'error');
            });
        }

        // Save All Tables
        function saveAllTables() {
            const databaseId = document.getElementById('sqlSelectedDatabase').value;
            const includeData = document.getElementById('includeData').checked;
            
            if (!databaseId) {
                showNotification('Please select a database first', 'warning');
                return;
            }
            
            showNotification('Exporting all tables...', 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save_all_tables&config_id=${databaseId}&include_data=${includeData}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = `All tables saved successfully! File: ${data.file_name}`;
                    if (data.storage_path) {
                        message += ` (Block Storage: ${data.storage_path})`;
                    }
                    showNotification(message, 'success');
                    browseSqlFiles(''); // Refresh the file list
                } else {
                    showNotification('Failed to save tables: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error saving tables: ' + error.message, 'error');
            });
        }

        // Show Select Tables Modal
        function showSelectTablesModal() {
            const databaseId = document.getElementById('sqlSelectedDatabase').value;
            
            if (!databaseId) {
                showNotification('Please select a database first', 'warning');
                return;
            }
            
            // Load tables for the selected database
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_tables&config_id=${databaseId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.tables) {
                    populateTablesModal(data.tables);
                    document.getElementById('selectTablesModal').style.display = 'block';
                } else {
                    showNotification('Failed to load tables: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                showNotification('Error loading tables: ' + error.message, 'error');
            });
        }

        // Populate Tables Modal
        function populateTablesModal(tables) {
            const container = document.getElementById('tablesList');
            
            if (tables.length === 0) {
                container.innerHTML = '<p style="color: #ccc;">No tables found in the selected database.</p>';
                return;
            }
            
            let html = '';
            tables.forEach(table => {
                html += `
                    <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px; color: white; cursor: pointer;">
                        <input type="checkbox" class="table-checkbox" value="${table.name}">
                        <span>${table.name}</span>
                    </label>
                `;
            });
            
            container.innerHTML = html;
        }

        // Toggle All Tables in Modal
        function toggleAllTablesInModal(selectAll) {
            const checkboxes = document.querySelectorAll('#tablesList .table-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll;
            });
        }

        // Save Selected Tables
        function saveSelectedTables() {
            const databaseId = document.getElementById('sqlSelectedDatabase').value;
            const includeData = document.getElementById('includeDataModal').checked;
            const selectedTables = [];
            
            document.querySelectorAll('#tablesList .table-checkbox:checked').forEach(checkbox => {
                selectedTables.push(checkbox.value);
            });
            
            if (selectedTables.length === 0) {
                showNotification('Please select at least one table', 'warning');
                return;
            }
            
            showNotification(`Exporting ${selectedTables.length} selected tables...`, 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=save_selected_tables&config_id=${databaseId}&include_data=${includeData}&tables=${encodeURIComponent(JSON.stringify(selectedTables))}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = `Selected tables saved successfully! File: ${data.file_name}`;
                    if (data.storage_path) {
                        message += ` (Block Storage: ${data.storage_path})`;
                    }
                    showNotification(message, 'success');
                    closeSelectTablesModal();
                    browseSqlFiles(''); // Refresh the file list
                } else {
                    showNotification('Failed to save tables: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error saving tables: ' + error.message, 'error');
            });
        }

        // Close Select Tables Modal
        function closeSelectTablesModal() {
            document.getElementById('selectTablesModal').style.display = 'none';
            document.getElementById('selectAllTablesInModal').checked = false;
            document.getElementById('includeDataModal').checked = false;
        }

        // Delete SQL file
        function downloadSqlFile(filePath) {
            // Create a temporary link to download the file
            const link = document.createElement('a');
            link.href = `?action=download_sql_file&file_path=${encodeURIComponent(filePath)}`;
            link.download = filePath.split('/').pop(); // Get filename from path
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function deleteSqlFile(filePath) {
            if (!confirm('Are you sure you want to delete this SQL file? This action cannot be undone.')) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=delete_sql_file&file_path=${encodeURIComponent(filePath)}`
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    // Refresh the file browser
                    const currentPath = filePath.substring(0, filePath.lastIndexOf('/'));
                    browseSqlFiles(currentPath);
                }
            })
            .catch(error => {
                showToast('Failed to delete SQL file', 'error');
            });
        }

        // Execute SQL file
        function executeSqlFile(filePath) {
            const select = document.getElementById('sqlSelectedDatabase');
            const configId = select ? select.value : null;
            
            if (!configId) {
                showToast('Please select a database first', 'error');
                return;
            }
            
            if (!confirm('Are you sure you want to execute this SQL file? This action cannot be undone.')) {
                return;
            }

            // Get the database name from the selected option
            const selectedOption = select.options[select.selectedIndex];
            const databaseName = selectedOption ? selectedOption.getAttribute('data-database') : null;
            
            if (!databaseName) {
                showToast('Database information not available', 'error');
                return;
            }

            // Show progress bar
            const progressContainer = document.getElementById('sqlProgressContainer');
            const progressLabel = document.getElementById('sqlProgressLabel');
            const progressFill = document.getElementById('sqlProgressFill');
            const progressPercentage = document.getElementById('sqlProgressPercentage');
            
            if (progressContainer) {
                progressContainer.style.display = 'block';
                progressContainer.className = 'progress-container';
                progressLabel.textContent = 'Preparing SQL execution...';
                progressFill.style.width = '10%';
                progressPercentage.textContent = '10%';
            }

            // Simulate upload progress
            setTimeout(() => {
                if (progressContainer) {
                    progressLabel.textContent = 'Uploading SQL file...';
                    progressFill.style.width = '30%';
                    progressPercentage.textContent = '30%';
                }
            }, 200);

            setTimeout(() => {
                if (progressContainer) {
                    progressLabel.textContent = 'Executing SQL commands...';
                    progressFill.style.width = '60%';
                    progressPercentage.textContent = '60%';
                }
            }, 500);
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=execute_sql_file&database=${encodeURIComponent(databaseName)}&file_path=${encodeURIComponent(filePath)}`
            })
            .then(response => {
                if (progressContainer) {
                    progressLabel.textContent = 'Processing response...';
                    progressFill.style.width = '90%';
                    progressPercentage.textContent = '90%';
                }
                return response.json();
            })
            .then(data => {
                if (progressContainer) {
                    progressFill.style.width = '100%';
                    progressPercentage.textContent = '100%';
                    
                    if (data.success) {
                        progressContainer.className = 'progress-container success';
                        let message = 'Tables created successfully! SQL execution completed.';
                        if (data.executed_count) {
                            message += ` (${data.executed_count} statements executed)`;
                        }
                        if (data.errors && data.errors.length > 0) {
                            message += ` Warning: ${data.errors.length} statements had errors.`;
                        }
                        progressLabel.textContent = message;
                        
                        // Add a close button for manual dismissal
                        if (!progressContainer.querySelector('.progress-close-btn')) {
                            const closeBtn = document.createElement('button');
                            closeBtn.className = 'btn btn-sm progress-close-btn';
                            closeBtn.innerHTML = '<i class="fas fa-times"></i> Close';
                            closeBtn.style.marginTop = '10px';
                            closeBtn.onclick = () => {
                                progressContainer.style.display = 'none';
                                progressFill.style.width = '0%';
                                progressPercentage.textContent = '0%';
                                progressContainer.className = 'progress-container';
                                if (closeBtn.parentNode) {
                                    closeBtn.parentNode.removeChild(closeBtn);
                                }
                            };
                            progressContainer.appendChild(closeBtn);
                        }
                        
                        // Show detailed errors if any
                        if (data.errors && data.errors.length > 0) {
                            const errorDetails = document.createElement('div');
                            errorDetails.className = 'error-details';
                            errorDetails.style.marginTop = '10px';
                            errorDetails.style.padding = '10px';
                            errorDetails.style.backgroundColor = '#fff3cd';
                            errorDetails.style.border = '1px solid #ffeaa7';
                            errorDetails.style.borderRadius = '4px';
                            errorDetails.style.fontSize = '12px';
                            errorDetails.style.maxHeight = '200px';
                            errorDetails.style.overflowY = 'auto';
                            
                            const errorTitle = document.createElement('strong');
                            errorTitle.textContent = 'Statement Errors:';
                            errorDetails.appendChild(errorTitle);
                            
                            data.errors.forEach(error => {
                                const errorDiv = document.createElement('div');
                                errorDiv.style.marginTop = '5px';
                                errorDiv.style.padding = '5px';
                                errorDiv.style.backgroundColor = '#f8f9fa';
                                errorDiv.style.border = '1px solid #dee2e6';
                                errorDiv.style.borderRadius = '3px';
                                errorDiv.textContent = error;
                                errorDetails.appendChild(errorDiv);
                            });
                            
                            progressContainer.appendChild(errorDetails);
                        }
                    } else {
                        progressContainer.className = 'progress-container error';
                        progressLabel.textContent = 'SQL execution failed!';
                        
                        // Show detailed error message
                        if (data.message) {
                            const errorDetails = document.createElement('div');
                            errorDetails.className = 'error-details';
                            errorDetails.style.marginTop = '10px';
                            errorDetails.style.padding = '10px';
                            errorDetails.style.backgroundColor = '#f8d7da';
                            errorDetails.style.border = '1px solid #f5c6cb';
                            errorDetails.style.borderRadius = '4px';
                            errorDetails.style.fontSize = '12px';
                            errorDetails.style.maxHeight = '200px';
                            errorDetails.style.overflowY = 'auto';
                            
                            const errorTitle = document.createElement('strong');
                            errorTitle.textContent = 'Error Details:';
                            errorDetails.appendChild(errorTitle);
                            
                            const errorMsg = document.createElement('div');
                            errorMsg.style.marginTop = '5px';
                            errorMsg.style.padding = '5px';
                            errorMsg.style.backgroundColor = '#f8f9fa';
                            errorMsg.style.border = '1px solid #dee2e6';
                            errorMsg.style.borderRadius = '3px';
                            errorMsg.textContent = data.message;
                            errorDetails.appendChild(errorMsg);
                            
                            progressContainer.appendChild(errorDetails);
                        }
                        
                        // Add a close button for manual dismissal
                        if (!progressContainer.querySelector('.progress-close-btn')) {
                            const closeBtn = document.createElement('button');
                            closeBtn.className = 'btn btn-sm progress-close-btn';
                            closeBtn.innerHTML = '<i class="fas fa-times"></i> Close';
                            closeBtn.style.marginTop = '10px';
                            closeBtn.onclick = () => {
                                progressContainer.style.display = 'none';
                                progressFill.style.width = '0%';
                                progressPercentage.textContent = '0%';
                                progressContainer.className = 'progress-container';
                                if (closeBtn.parentNode) {
                                    closeBtn.parentNode.removeChild(closeBtn);
                                }
                            };
                            progressContainer.appendChild(closeBtn);
                        }
                    }
                }
                
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    // Refresh tables if we're on the tables tab
                    if (document.getElementById('tables-tab').classList.contains('active')) {
                        loadDatabaseTables();
                    }
                }
            })
            .catch(error => {
                if (progressContainer) {
                    progressContainer.className = 'progress-container error';
                    progressLabel.textContent = 'Connection error occurred!';
                    progressFill.style.width = '100%';
                    progressPercentage.textContent = '100%';
                    
                    // Add a close button for manual dismissal
                    if (!progressContainer.querySelector('.progress-close-btn')) {
                        const closeBtn = document.createElement('button');
                        closeBtn.className = 'btn btn-sm progress-close-btn';
                        closeBtn.innerHTML = '<i class="fas fa-times"></i> Close';
                        closeBtn.style.marginTop = '10px';
                        closeBtn.onclick = () => {
                            progressContainer.style.display = 'none';
                            progressFill.style.width = '0%';
                            progressPercentage.textContent = '0%';
                            progressContainer.className = 'progress-container';
                            if (closeBtn.parentNode) {
                                closeBtn.parentNode.removeChild(closeBtn);
                            }
                        };
                        progressContainer.appendChild(closeBtn);
                    }
                }
                showToast('Failed to execute SQL file', 'error');
            });
        }

        // Enhanced view table structure with better modal handling
        function viewTableStructure(tableName) {
            const select = document.getElementById('selectedDatabase');
            const configId = select.value;
            
            if (!configId) {
                showToast('Please select a database first', 'error');
                return;
            }
            
            // Show loading toast
            showToast('Loading table structure...', 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_table_structure&config_id=${configId}&table=${encodeURIComponent(tableName)}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.structure) {
                    displayTableStructure(tableName, data.structure);
                } else {
                    showToast(data.message || 'Failed to load table structure', 'error');
                }
            })
            .catch(error => {
                console.error('Error loading table structure:', error);
                showToast('Failed to load table structure', 'error');
            });
        }

        // Enhanced table structure display with better formatting
        function displayTableStructure(tableName, structure) {
            let structureHtml = `
                <div class="table-structure-header">
                    <h3><i class="fas fa-table"></i> Structure of table: ${tableName}</h3>
                    <p class="table-info">Total columns: ${structure.length}</p>
                </div>
            `;
            
            structureHtml += `
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Type</th>
                                <th>Null</th>
                                <th>Key</th>
                                <th>Default</th>
                                <th>Extra</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            structure.forEach(column => {
                const keyIcon = column.Key === 'PRI' ? '<i class="fas fa-key" title="Primary Key"></i>' : 
                               column.Key === 'UNI' ? '<i class="fas fa-fingerprint" title="Unique Key"></i>' : 
                               column.Key === 'MUL' ? '<i class="fas fa-link" title="Index"></i>' : '';
                
                structureHtml += `
                    <tr>
                        <td><strong>${column.Field}</strong></td>
                        <td><code>${column.Type}</code></td>
                        <td>${column.Null === 'YES' ? '<span class="text-success">YES</span>' : '<span class="text-danger">NO</span>'}</td>
                        <td>${keyIcon} ${column.Key}</td>
                        <td>${column.Default !== null ? `<code>${column.Default}</code>` : '<em>NULL</em>'}</td>
                        <td>${column.Extra}</td>
                    </tr>
                `;
            });
            
            structureHtml += '</tbody></table></div>';
            
            showModal('Table Structure', structureHtml);
        }

        // Enhanced modal creation with better styling and functionality
        function showModal(title, content) {
            // Remove existing modal if any
            const existingModal = document.querySelector('.structure-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const modal = document.createElement('div');
            modal.className = 'structure-modal';
            modal.innerHTML = `
                <div class="modal-overlay" onclick="closeModal()"></div>
                <div class="modal-container">
                    <div class="modal-header">
                        <h2>${title}</h2>
                        <button class="modal-close" onclick="closeModal()" title="Close modal">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                    <div class="modal-footer">
                        <button class="btn" onclick="closeModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add escape key listener
            const escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escapeHandler);
                }
            };
            document.addEventListener('keydown', escapeHandler);
        }

        // Enhanced modal closing
        function closeModal() {
            const modal1 = document.querySelector('.structure-modal');
            if (modal1) modal1.remove();
            const modal2 = document.querySelector('.modal-container')?.parentElement;
            if (modal2 && modal2.classList.contains('modal-overlay')) {
                modal2.remove();
            }
        }

        // Show create record modal
        function showCreateRecordModal() {
            const tableName = document.getElementById('selectedTable').value;
            
            if (!tableName) {
                showToast('Please select a table first', 'error');
                return;
            }

            // Get table structure to build the form
            const configId = document.getElementById('selectedDatabase').value;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_table_structure&config_id=${configId}&table=${tableName}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.structure) {
                    let formHtml = '<form id="createRecordForm">';
                    
                    data.structure.forEach(column => {
                        // Skip auto-increment primary keys
                        if (column.Extra && column.Extra.includes('auto_increment')) {
                            return;
                        }
                        
                        const fieldName = column.Field;
                        const fieldType = column.Type;
                        const isRequired = column.Null === 'NO' && !column.Default;
                        
                        formHtml += `
                            <div class="form-group">
                                <label for="field_${fieldName}">${fieldName}${isRequired ? ' *' : ''}</label>
                                <input type="text" 
                                       id="field_${fieldName}" 
                                       name="${fieldName}" 
                                       class="form-control"
                                       placeholder="${fieldType}"
                                       ${isRequired ? 'required' : ''}>
                                <small class="field-info">Type: ${fieldType}${column.Default ? `, Default: ${column.Default}` : ''}</small>
                            </div>
                        `;
                    });
                    
                    formHtml += '</form>';
                    
                    const content = `
                        <div class="record-form">
                            <h3>Add New Record to ${tableName}</h3>
                            ${formHtml}
                        </div>
                    `;
                    
                    const modal = document.createElement('div');
                    modal.className = 'structure-modal';
                    modal.innerHTML = `
                        <div class="modal-overlay" onclick="closeModal()"></div>
                        <div class="modal-container">
                            <div class="modal-header">
                                <h2>Add New Record</h2>
                                <button class="modal-close" onclick="closeModal()" title="Close modal">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="modal-body">
                                ${content}
                            </div>
                            <div class="modal-footer">
                                <button class="btn btn-primary" onclick="submitCreateRecord()">
                                    <i class="fas fa-save"></i> Save Record
                                </button>
                                <button class="btn" onclick="closeModal()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </div>
                    `;
                    
                    document.body.appendChild(modal);
                } else {
                    showToast('Failed to get table structure', 'error');
                }
            })
            .catch(error => {
                showToast('Error loading table structure', 'error');
            });
        }

        // Submit create record form
        function submitCreateRecord() {
            const form = document.getElementById('createRecordForm');
            const formData = new FormData(form);
            const tableName = document.getElementById('selectedTable').value;
            const configId = document.getElementById('selectedDatabase').value;
            
            // Convert FormData to regular object
            const data = {};
            for (let [key, value] of formData.entries()) {
                if (value.trim() !== '') {
                    data[key] = value;
                }
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_record&config_id=${configId}&table=${tableName}&data=${encodeURIComponent(JSON.stringify(data))}`
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast('Record created successfully', 'success');
                    closeModal();
                    loadTableRecords(); // Reload the records
                } else {
                    showToast('Failed to create record: ' + result.message, 'error');
                }
            })
            .catch(error => {
                showToast('Error creating record', 'error');
            });
        }

        // Edit a record (placeholder for now)
        let currentRecords = [];
        
        function editRecord(index) {
            const tableName = document.getElementById('selectedTable').value;
            const configId = document.getElementById('selectedDatabase').value;
            if (!tableName || !configId) {
                showToast('Select database and table first', 'error');
                return;
            }
            const record = (currentRecords && currentRecords[index]) ? currentRecords[index] : null;
            if (!record) {
                showToast('Record not found in current page', 'error');
                return;
            }
            // Build edit form dynamically
            let content = '<form id="editRecordForm">';
            Object.keys(record).forEach(key => {
                const val = record[key] ?? '';
                content += `
                    <div class="form-group">
                        <label>${key}</label>
                        <input type="text" name="${key}" value="${String(val).replace(/"/g,'&quot;')}">
                    </div>`;
            });
            content += '</form>';
            
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-overlay" onclick="closeModal()"></div>
                <div class="modal-container">
                    <div class="modal-header">
                        <h2>Edit Record</h2>
                        <button class="modal-close" onclick="closeModal()" title="Close modal">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" onclick="submitEditRecord(${index})">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <button class="btn" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>`;
            document.body.appendChild(modal);
        }
        
        function getPrimaryWhere(tableName, record) {
            if (!record) return {};
            if (tableName === 'users') {
                if (record.id !== undefined && record.id !== null && String(record.id) !== '') {
                    return { id: record.id };
                }
                if (record.username !== undefined && record.username !== null && String(record.username) !== '') {
                    return { username: record.username };
                }
                return {};
            }
            if (record.id !== undefined && record.id !== null && String(record.id) !== '') {
                return { id: record.id };
            }
            if (record.username !== undefined && record.username !== null && String(record.username) !== '') {
                return { username: record.username };
            }
            const keys = Object.keys(record);
            if (keys.length > 0) {
                const k = keys[0];
                return { [k]: record[k] };
            }
            return {};
        }
        
        function submitEditRecord(index) {
            const tableName = document.getElementById('selectedTable').value;
            const configId = document.getElementById('selectedDatabase').value;
            const record = (currentRecords && currentRecords[index]) ? currentRecords[index] : null;
            if (!record) {
                showToast('Record not found in current page', 'error');
                return;
            }
            const where = getPrimaryWhere(tableName, record);
            if (!where || Object.keys(where).length === 0) {
                showToast('Missing primary key for this record', 'error');
                return;
            }
            const form = document.getElementById('editRecordForm');
            const formData = new FormData(form);
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value;
            }
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_record&config_id=${encodeURIComponent(configId)}&table=${encodeURIComponent(tableName)}&data=${encodeURIComponent(JSON.stringify(data))}&where=${encodeURIComponent(JSON.stringify(where))}`
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    showToast('Record updated successfully', 'success');
                    closeModal();
                    loadTableRecords();
                } else {
                    showToast('Failed to update record: ' + (result.message || 'Unknown error'), 'error');
                }
            })
            .catch(() => showToast('Error updating record', 'error'));
        }

        // Delete a record  
        function deleteRecord(index) {
            if (confirm('Are you sure you want to delete this record?')) {
                const tableName = document.getElementById('selectedTable').value;
                const configId = document.getElementById('selectedDatabase').value;
                const record = (currentRecords && currentRecords[index]) ? currentRecords[index] : null;
                if (!record) {
                    showToast('Record not found in current page', 'error');
                    return;
                }
                const where = getPrimaryWhere(tableName, record);
                if (!where || Object.keys(where).length === 0) {
                    showToast('Missing primary key for this record', 'error');
                    return;
                }
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete_record&config_id=${encodeURIComponent(configId)}&table=${encodeURIComponent(tableName)}&where=${encodeURIComponent(JSON.stringify(where))}`
                })
                .then(r => r.json())
                .then(result => {
                    if (result.success) {
                        showToast('Record deleted', 'success');
                        // If last item on page deleted, adjust page
                        currentPage = Math.max(1, currentPage);
                        loadTableRecords();
                    } else {
                        showToast('Failed to delete record: ' + (result.message || 'Unknown error'), 'error');
                    }
                })
                .catch(() => showToast('Error deleting record', 'error'));
            }
        }

        // Enhanced view table records with better navigation
        function viewTableRecords(tableName) {
            // Switch to records tab and load records for this table
            const recordsTab = document.getElementById('records-tab');
            if (recordsTab) {
                recordsTab.click();
                
                // Wait a moment for tab to switch, then set the table and load records
                setTimeout(() => {
                    const tableSelect = document.getElementById('selectedTable');
                    if (tableSelect) {
                        tableSelect.value = tableName;
                        loadTableRecords();
                        showToast(`Switched to records view for table: ${tableName}`, 'success');
                    } else {
                        showToast('Records tab not available', 'error');
                    }
                }, 100);
            } else {
                showToast('Records tab not found', 'error');
            }
        }

        // Drop table
        function dropTable(tableName) {
            const select = document.getElementById('selectedDatabase');
            const configId = select.value;
            
            if (!configId) {
                showToast('Please select a database first', 'error');
                return;
            }
            
            if (!confirm(`Are you sure you want to drop table "${tableName}"? This action cannot be undone.`)) {
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=drop_table&config_id=${configId}&table=${encodeURIComponent(tableName)}`
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    loadDatabaseTables(); // Refresh tables list
                }
            })
            .catch(error => {
                showToast('Failed to drop table', 'error');
            });
        }

        // Execute SQL file
        // Refresh tables
        function refreshTables() {
            loadDatabaseTables();
        }

        // Show create table modal
        function showCreateTableModal() {
            // Switch to SQL tab for table creation
            document.getElementById('sql-tab').click();
            
            // Pre-fill with CREATE TABLE template
            setTimeout(() => {
                const sqlTextarea = document.getElementById('sqlQuery');
                if (sqlTextarea) {
                    sqlTextarea.value = `CREATE TABLE new_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);`;
                }
            }, 100);
        }

        // Initialize database operations on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOMContentLoaded - Initializing dbmanager...');
            try {
                showStorageTab('all');
            } catch (e) {}
            // Add delay to ensure all elements are ready
            setTimeout(() => {
                try {
                    loadActiveDatabases();
                } catch(error) {
                    console.error('Error in loadActiveDatabases:', error);
                    showToast('Failed to initialize database manager: ' + error.message, 'error');
                }
            }, 100);
            // Initialize SQL files browser
            browseSqlFiles('');

            const perPageSel = document.getElementById('recordsPerPage');
            if (perPageSel) {
                perPageSel.value = String(recordsPerPage);
                perPageSel.addEventListener('change', function () {
                    recordsPerPage = String(perPageSel.value || '10');
                    currentPage = 1;
                    loadTableRecords();
                });
            }

            const pageSel = document.getElementById('recordsPageSelect');
            if (pageSel) {
                pageSel.addEventListener('change', function () {
                    const v = Number(pageSel.value) || 1;
                    currentPage = Math.max(1, v);
                    loadTableRecords();
                });
            }

            const searchInput = document.getElementById('recordsSearchQuery');
            if (searchInput) {
                searchInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        recordsSearch = String(searchInput.value || '').trim();
                        currentPage = 1;
                        loadTableRecords();
                    } else if (e.key === 'Escape') {
                        searchInput.value = '';
                        recordsSearch = '';
                        currentPage = 1;
                        loadTableRecords();
                    }
                });
                searchInput.addEventListener('input', function () {
                    if (recordsSearchDebounce) {
                        clearTimeout(recordsSearchDebounce);
                    }
                    recordsSearchDebounce = setTimeout(function () {
                        recordsSearch = String(searchInput.value || '').trim();
                        currentPage = 1;
                        loadTableRecords();
                    }, 250);
                });
            }

            const storageProfileSelect = document.getElementById('configStorageProfile');
            if (storageProfileSelect) {
                storageProfileSelect.addEventListener('change', function() {
                    const cfgId = document.getElementById('configId') ? document.getElementById('configId').value : null;
                    const selectedAdmin = document.getElementById('configAdminConfigId') ? document.getElementById('configAdminConfigId').value : '';
                    updateAdminConfigUI(storageProfileSelect.value, cfgId, selectedAdmin);
                });
            }
            
            const provAdminSelect = document.getElementById('provAdminConfig');
            if (provAdminSelect) {
                const candidates = getAdminCandidateConfigs(null);
                const opts = ['<option value=\"\">Use first active 3307 admin</option>'].concat(
                    candidates.map(c => `<option value=\"${String(c.id||'')}\">${String(c.name||c.id)} (${String(c.id||'')})</option>`)
                );
                provAdminSelect.innerHTML = opts.join('');
            }
            const createProvBtn = document.getElementById('createProvisionerBtn');
            if (createProvBtn) {
                createProvBtn.addEventListener('click', async function(){
                    const u = document.getElementById('provUser').value.trim();
                    const p = document.getElementById('provPass').value;
                    const aid = document.getElementById('provAdminConfig').value;
                    if (!u || !p) { showToast('Enter provisioner username and password', 'error'); return; }
                    const form = new URLSearchParams();
                    form.set('action','create_provisioner');
                    form.set('prov_user', u);
                    form.set('prov_pass', p);
                    if (aid) form.set('admin_config_id', aid);
                    try {
                        const res = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: form.toString() });
                        const data = await res.json();
                        showToast(data.message || (data.success?'Provisioner created':'Provisioner creation failed'), data.success?'success':'error');
                    } catch(e) {
                        showToast('Provisioner request failed', 'error');
                    }
                });
            }
            
            // Add event listeners for delete and toggle buttons
            document.addEventListener('click', function(e) {
                // Handle delete config buttons
                if (e.target.closest('.delete-config-btn')) {
                    const btn = e.target.closest('.delete-config-btn');
                    const configId = btn.dataset.configId;
                    const configName = btn.dataset.configName;
                    deleteConfig(configId, configName);
                }
                
                // Handle toggle active buttons  
                if (e.target.closest('.toggle-active-btn')) {
                    const btn = e.target.closest('.toggle-active-btn');
                    const configId = btn.dataset.configId;
                    toggleActive(configId);
                }
                
                // Handle test connection buttons
                if (e.target.closest('.test-connection-btn')) {
                    const btn = e.target.closest('.test-connection-btn');
                    const configId = btn.dataset.configId;
                    testConnection(configId);
                }
                
                // Handle edit config buttons
                if (e.target.closest('.edit-config-btn')) {
                    const btn = e.target.closest('.edit-config-btn');
                    const configId = btn.dataset.configId;
                    editConfig(configId);
                }
                
                // Handle create database buttons
                if (e.target.closest('.create-database-btn')) {
                    const btn = e.target.closest('.create-database-btn');
                    const configId = btn.dataset.configId;
                    createDatabase(configId);
                }
            });
            refreshDbStatuses();
        });

        // Schema Management Functions
        function verifyTables() {
            const selectedDb = document.getElementById('schemaSelectedDatabase');
            if (!selectedDb) {
                showToast('Database selector not found', 'error');
                return;
            }
            
            const configId = selectedDb.value;
            if (!configId) {
                showToast('Please select a database configuration', 'error');
                return;
            }
            
            showToast('Verifying tables...', 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=verify_tables&config_id=${encodeURIComponent(configId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayVerificationResults(data.data);
                    showToast('Table verification completed', 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to verify tables', 'error');
            });
        }
        
        function createMissingTables() {
            const selectedDb = document.getElementById('schemaSelectedDatabase');
            if (!selectedDb) {
                showToast('Database selector not found', 'error');
                return;
            }
            
            const configId = selectedDb.value;
            if (!configId) {
                showToast('Please select a database configuration', 'error');
                return;
            }
            
            if (!confirm('Are you sure you want to create missing tables? This will execute SQL statements from the schema file.')) {
                return;
            }
            
            showToast('Creating missing tables...', 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=create_missing_tables&config_id=${encodeURIComponent(configId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayCreationResults(data.results);
                    showToast('Table creation completed', 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to create tables', 'error');
            });
        }
        
        function loadSchemaInfo() {
            const selectedDb = document.getElementById('schemaSelectedDatabase');
            if (!selectedDb) {
                showToast('Database selector not found', 'error');
                return;
            }
            
            const configId = selectedDb.value;
            if (!configId) {
                showToast('Please select a database configuration', 'error');
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=load_schema_info&config_id=${encodeURIComponent(configId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySchemaInfo(data.data);
                    showToast('Schema information loaded', 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to load schema info', 'error');
            });
        }
        
        function optimizeTables() {
            const selectedDb = document.getElementById('schemaSelectedDatabase');
            if (!selectedDb) {
                showToast('Database selector not found', 'error');
                return;
            }
            
            const configId = selectedDb.value;
            if (!configId) {
                showToast('Please select a database configuration', 'error');
                return;
            }
            
            if (!confirm('Are you sure you want to optimize all tables? This may take some time for large databases.')) {
                return;
            }
            
            showToast('Optimizing tables...', 'info');
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=optimize_tables&config_id=${encodeURIComponent(configId)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayOptimizationResults(data);
                    showToast('Table optimization completed', 'success');
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to optimize tables', 'error');
            });
        }
        
        function browseSchemaFiles(path = '') {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=browse_schema_files&path=${encodeURIComponent(path)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySchemaFiles(data);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                showToast('Failed to browse schema files', 'error');
            });
        }
        
        // Display functions for schema management results
        function displayVerificationResults(data) {
            const container = document.getElementById('schemaVerificationResults');
            let html = '<div class="verification-results">';
            
            // Summary
            html += `
                <div class="result-summary">
                    <h4>Database Verification Results</h4>
                    <div class="summary-info">
                        <div class="info-item">
                            <span class="info-label">Database:</span>
                            <span class="info-value">${data.database_name}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Verification Time:</span>
                            <span class="info-value">${data.verification_time}</span>
                        </div>
                        ${data.schema_file ? `
                        <div class="info-item">
                            <span class="info-label">Schema File:</span>
                            <span class="info-value">${data.schema_file}</span>
                        </div>
                        ` : '<div class="info-item"><span class="info-label">Schema File:</span><span class="info-value">No schema file found - showing current database state</span></div>'}
                    </div>
                    <div class="summary-stats">
                        <div class="stat-item">
                            <span class="stat-label">Total Tables:</span>
                            <span class="stat-value">${data.total_tables}</span>
                        </div>
                        ${data.required_tables.length > 0 ? `
                        <div class="stat-item">
                            <span class="stat-label">Required Tables:</span>
                            <span class="stat-value">${data.required_tables.length}</span>
                        </div>
                        ` : ''}
                        ${data.missing_tables.length > 0 ? `
                        <div class="stat-item error">
                            <span class="stat-label">Missing Tables:</span>
                            <span class="stat-value">${data.missing_tables.length}</span>
                        </div>
                        ` : ''}
                        ${data.extra_tables.length > 0 ? `
                        <div class="stat-item warning">
                            <span class="stat-label">Extra Tables:</span>
                            <span class="stat-value">${data.extra_tables.length}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            // Missing tables
            if (data.missing_tables.length > 0) {
                html += '<div class="missing-tables"><h5><i class="fas fa-exclamation-triangle"></i> Missing Tables:</h5><ul>';
                data.missing_tables.forEach(table => {
                    html += `<li class="error">${table}</li>`;
                });
                html += '</ul></div>';
            }
            
            // Extra tables
            if (data.extra_tables.length > 0) {
                html += '<div class="extra-tables"><h5><i class="fas fa-info-circle"></i> Extra Tables:</h5><ul>';
                data.extra_tables.forEach(table => {
                    html += `<li class="warning">${table}</li>`;
                });
                html += '</ul></div>';
            }
            
            // Table details
            if (data.table_details && data.table_details.length > 0) {
                html += `
                    <div class="table-details">
                        <h5><i class="fas fa-table"></i> Table Details:</h5>
                        <div class="table-container">
                            <table class="table-info">
                                <thead>
                                    <tr>
                                        <th>Table Name</th>
                                        <th>Engine</th>
                                        <th>Rows</th>
                                        <th>Data Size</th>
                                        <th>Index Size</th>
                                        <th>Collation</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                data.table_details.forEach(table => {
                    const dataSize = formatBytes(table.data_length);
                    const indexSize = formatBytes(table.index_length);
                    const created = table.created ? new Date(table.created).toLocaleDateString() : 'Unknown';
                    
                    html += `
                        <tr>
                            <td><strong>${table.name}</strong></td>
                            <td>${table.engine}</td>
                            <td>${table.rows.toLocaleString()}</td>
                            <td>${dataSize}</td>
                            <td>${indexSize}</td>
                            <td>${table.collation}</td>
                            <td>${created}</td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }
            
            html += '</div>';
            container.innerHTML = html;
            document.getElementById('schemaResults').style.display = 'block';
        }
        
        // Helper function to format bytes
        function formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        function displayCreationResults(results) {
            const container = document.getElementById('schemaCreationDetails');
            let html = '<div class="creation-results">';
            
            results.forEach(result => {
                const statusClass = result.status === 'created' ? 'success' : 'error';
                html += `
                    <div class="result-item ${statusClass}">
                        <strong>${result.table}:</strong> ${result.message}
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
            document.getElementById('schemaCreationResults').style.display = 'block';
        }
        
        function displaySchemaInfo(data) {
            const container = document.getElementById('schemaInfoResults');
            const html = `
                <div class="schema-info">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Database Name:</span>
                            <span class="info-value">${data.database_name}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Table Count:</span>
                            <span class="info-value">${data.table_count}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Database Size:</span>
                            <span class="info-value">${data.database_size}</span>
                        </div>
                    </div>
                </div>
            `;
            container.innerHTML = html;
            document.getElementById('schemaInfo').style.display = 'block';
        }
        
        function displayOptimizationResults(data) {
            const container = document.getElementById('optimizationResults');
            let html = '<div class="optimization-results">';
            
            // Summary
            if (data.summary) {
                html += `
                    <div class="optimization-summary">
                        <h4>Optimization Summary</h4>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <span class="summary-label">Total Tables:</span>
                                <span class="summary-value">${data.summary.total_tables}</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Total Time:</span>
                                <span class="summary-value">${data.summary.total_time}</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Size Before:</span>
                                <span class="summary-value">${data.summary.size_before}</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Size After:</span>
                                <span class="summary-value">${data.summary.size_after}</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Space Saved:</span>
                                <span class="summary-value">${data.summary.space_saved} (${data.summary.percent_saved}%)</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Individual table results
            html += '<div class="table-results"><h5>Table Results:</h5>';
            data.results.forEach(result => {
                const statusClass = result.status === 'success' ? 'success' : 'error';
                html += `
                    <div class="table-result ${statusClass}">
                        <div class="table-name">${result.table}</div>
                        <div class="table-details">
                            ${result.status === 'success' ? `
                                Engine: ${result.engine} | Rows: ${result.rows} | 
                                Size: ${result.size_before} → ${result.size_after} | 
                                Saved: ${result.space_saved} (${result.percent_saved}%) | 
                                Time: ${result.optimization_time}
                            ` : `Error: ${result.message}`}
                        </div>
                    </div>
                `;
            });
            html += '</div></div>';
            
            container.innerHTML = html;
        }
        
        function displaySchemaFiles(data) {
            const container = document.getElementById('schemaFilesContainer');
            if (!container) {
                console.error('schemaFilesContainer not found');
                return;
            }
            
            let html = '<div class="file-browser">';
            
            // Current path
            html += `<div class="current-path">Path: /${data.current_path || ''}</div>`;
            
            // Parent directory link
            if (data.current_path) {
                const parentPath = data.current_path.split('/').slice(0, -1).join('/');
                html += `
                    <div class="file-item directory" onclick="browseSchemaFiles('${parentPath}')">
                        <i class="fas fa-folder"></i> ..
                    </div>
                `;
            }
            
            // Check if items exist and display them
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    if (item.type === 'directory') {
                        html += `
                            <div class="file-item directory" onclick="browseSchemaFiles('${item.path}')">
                                <i class="fas fa-folder"></i> ${item.name}
                            </div>
                        `;
                    } else {
                        const date = new Date(item.modified * 1000).toLocaleDateString();
                        const size = formatFileSize(item.size);
                        html += `
                            <div class="file-item">
                                <div class="file-info">
                                    <i class="fas fa-file-code"></i> ${item.name}
                                    <div class="file-details">${size} | ${date}</div>
                                </div>
                            </div>
                        `;
                    }
                });
            } else {
                html += '<div class="no-files">No schema files found in this directory.</div>';
            }
            
            html += '</div>';
            container.innerHTML = html;
        }

        // Add event listener for page permissions and context mapping
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize new tabs
            initializePagePermissionsTab();
            initializeContextMappingTab();
            
            // Add modal close event handlers
            window.addEventListener('click', function(event) {
                // Close page browser modal when clicking outside
                const pageBrowserModal = document.getElementById('pageBrowserModal');
                if (event.target === pageBrowserModal) {
                    closePageBrowser();
                }
                
                // Close config modal when clicking outside
                const configModal = document.getElementById('configModal');
                if (event.target === configModal) {
                    closeConfigModal();
                }
                
                // Close delete modal when clicking outside
                const deleteModal = document.getElementById('deleteModal');
                if (event.target === deleteModal) {
                    closeDeleteModal();
                }
            });
        });

        // Page Permissions Functions
        function initializePagePermissionsTab() {
            loadPagePermissions();
            loadDatabasesForPermissions();
            loadAvailablePages();
            
            // Add form event listener
            document.getElementById('addPagePermissionForm').addEventListener('submit', function(e) {
                e.preventDefault();
                addPagePermission();
            });
        }
        
        // Function called when Page Permissions tab is shown
        function initializePagePermissionsTabContent() {
            console.log('=== Page Permissions tab initialization started ===');
            
            // Small delay to ensure DOM is ready
            setTimeout(() => {
                // Set the hidden database field from main selection
                const currentDbId = getCurrentDatabaseId();
                const hiddenField = document.getElementById('selectedDatabaseId');
                if (hiddenField) {
                    hiddenField.value = currentDbId;
                    console.log('Set hidden database field to:', currentDbId);
                } else {
                    console.log('Warning: Hidden database field not found');
                }
                
                // No need to load databases for permissions since we're using main selection
                console.log('Using main database selection for permissions...');
                
                // Load available pages if not loaded
                console.log('About to call loadAvailablePages...');
                loadAvailablePages();
                
                // Verify main database is selected
                console.log('Current database ID from main interface:', currentDbId);
                if (!currentDbId) {
                    console.log('No database selected in main interface - user needs to select one first');
                }
                
                // Reload permissions
                console.log('About to load page permissions...');
                loadPagePermissions();
                
                console.log('=== Page Permissions tab initialization completed ===');
            }, 100);
        }
        
        // Function called when Context Mapping tab is shown
        function initializeContextMappingTabContent() {
            console.log('Context Mapping tab activated');
            
            // Always set the hidden database field to the main selection
            const currentDbId = getCurrentDatabaseId();
            const contextDbHidden = document.getElementById('mappingDatabase');
            if (contextDbHidden) {
                contextDbHidden.value = currentDbId || '';
                if (currentDbId) {
                    showToast('Context Mapping will use the selected database from the main interface.', 'info');
                } else {
                    showToast('Please select a database in the main interface before adding a mapping.', 'warning');
                }
            }
            // Reload mappings
            loadContextMappings();
        }
        
        // Page Browser Functions
        // Avoid redeclaring when this UI is embedded inside other pages (e.g., navigator)
        // If a global availablePages already exists, reuse it; otherwise initialize on window
        if (typeof availablePages === 'undefined') {
            window.availablePages = [];
        }
        
        function openPageBrowser() {
            const modal = document.getElementById('pageBrowserModal');
            modal.style.display = 'block';
            loadPagesForBrowser();
        }
        
        function closePageBrowser() {
            document.getElementById('pageBrowserModal').style.display = 'none';
        }
        
        function loadPagesForBrowser() {
            console.log('Loading pages for browser...');
            const pageList = document.getElementById('pageList');
            pageList.innerHTML = '<div class="loading-placeholder" style="text-align: center; padding: 20px; color: rgba(255,255,255,0.6);"><i class="fas fa-spinner fa-spin"></i> Loading pages...</div>';
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_available_pages'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Available pages response:', data);
                if (data.success) {
                    availablePages = data.pages;
                    displayPages(availablePages);
                } else {
                    pageList.innerHTML = '<div style="text-align: center; padding: 20px; color: #f44336;"><i class="fas fa-exclamation-triangle"></i> Failed to load pages</div>';
                    console.error('Failed to load pages:', data.message);
                }
            })
            .catch(error => {
                pageList.innerHTML = '<div style="text-align: center; padding: 20px; color: #f44336;"><i class="fas fa-exclamation-triangle"></i> Error loading pages</div>';
                console.error('Error loading pages:', error);
            });
        }
        
        function displayPages(pages) {
            const pageList = document.getElementById('pageList');
            pageList.innerHTML = '';
            
            if (pages.length === 0) {
                pageList.innerHTML = '<div style="text-align: center; padding: 20px; color: rgba(255,255,255,0.6);"><i class="fas fa-folder-open"></i> No pages found</div>';
                return;
            }
            
            // Group pages by directory
            const grouped = {};
            pages.forEach(page => {
                if (!grouped[page.directory]) {
                    grouped[page.directory] = [];
                }
                grouped[page.directory].push(page);
            });
            
            // Display grouped pages
            Object.keys(grouped).sort().forEach(directory => {
                // Directory header with count
                const dirHeader = document.createElement('div');
                dirHeader.style.cssText = 'background: #2196F3; color: white; padding: 8px 12px; margin: 5px 0; border-radius: 4px; font-weight: bold; display: flex; justify-content: space-between; align-items: center;';
                dirHeader.innerHTML = `
                    <span><i class="fas fa-folder"></i> ${directory}</span>
                    <span style="background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 12px; font-size: 12px;">${grouped[directory].length}</span>
                `;
                pageList.appendChild(dirHeader);
                
                // Pages in directory (sorted by filename)
                grouped[directory].sort((a, b) => a.filename.localeCompare(b.filename)).forEach(page => {
                    const pageItem = document.createElement('div');
                    const isSelected = selectedPages.has(page.path);
                    const selectionBg = isSelected ? 'rgba(76,175,80,0.3)' : 'rgba(255,255,255,0.1)';
                    const selectionBorder = isSelected ? '2px solid #4CAF50' : '1px solid transparent';
                    
                    pageItem.style.cssText = `padding: 8px 12px; margin: 2px 0; background: ${selectionBg}; border-radius: 4px; cursor: pointer; transition: all 0.3s ease; border: ${selectionBorder};`;
                    
                    // Extension badge color
                    const extColors = {
                        'php': '#777bb4',
                        'html': '#e34c26',
                        'htm': '#e34c26',
                        'js': '#f7df1e',
                        'css': '#1572b6',
                        'json': '#000000',
                        'xml': '#ff6600'
                    };
                    
                    const extColor = extColors[page.extension] || '#666666';
                    
                    const checkboxHtml = selectionMode === 'multiple' ? 
                        `<input type="checkbox" ${isSelected ? 'checked' : ''} onclick="event.stopPropagation(); togglePageInSelection('${page.path}');" style="margin-right: 8px;">` : '';
                    
                    pageItem.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center;">
                                ${checkboxHtml}
                                <div>
                                    <div style="font-weight: bold; color: white; display: flex; align-items: center; gap: 8px;">
                                        ${page.filename}
                                        <span style="background: ${extColor}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; text-transform: uppercase;">${page.extension}</span>
                                        ${isSelected ? '<i class="fas fa-check-circle" style="color: #4CAF50; margin-left: 5px;"></i>' : ''}
                                    </div>
                                    <div style="font-size: 12px; color: rgba(255,255,255,0.7);">${page.path}</div>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 11px; color: rgba(255,255,255,0.5);">
                                ${formatFileSize(page.size)}
                            </div>
                        </div>
                    `;
                    
                    if (selectionMode === 'single') {
                        pageItem.onclick = () => selectPage(page.path);
                    } else {
                        pageItem.onclick = () => togglePageInSelection(page.path);
                    }
                    
                    // Hover effects
                    pageItem.onmouseenter = () => {
                        if (!selectedPages.has(page.path)) {
                            pageItem.style.background = 'rgba(0,255,255,0.2)';
                            pageItem.style.borderLeft = '4px solid #00ffff';
                        }
                    };
                    pageItem.onmouseleave = () => {
                        const isSelected = selectedPages.has(page.path);
                        const selectionBg = isSelected ? 'rgba(76,175,80,0.3)' : 'rgba(255,255,255,0.1)';
                        pageItem.style.background = selectionBg;
                        pageItem.style.borderLeft = 'none';
                    };
                    
                    pageList.appendChild(pageItem);
                });
            });
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }




        
        // Multiple page selection functions
        function updateSelectionMode(mode) {
            selectionMode = mode;
            const multipleControls = document.getElementById('multipleSelectionControls');
            const confirmBtn = document.getElementById('confirmSelectionBtn');
            
            if (mode === 'multiple') {
                multipleControls.style.display = 'block';
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (' + selectedPages.size + ')';
            } else {
                multipleControls.style.display = 'none';
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection';
                selectedPages.clear();
                updateSelectedCount();
            }
            
            // Refresh the display to show/hide checkboxes
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            if (searchTerm === '') {
                displayPages(availablePages);
            } else {
                const filtered = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
                displayPages(filtered);
            }
        }
        
        function togglePageInSelection(pagePath) {
            if (selectedPages.has(pagePath)) {
                selectedPages.delete(pagePath);
            } else {
                selectedPages.add(pagePath);
            }
            updateSelectedCount();
            
            // Update the confirm button text
            const confirmBtn = document.getElementById('confirmSelectionBtn');
            if (selectionMode === 'multiple') {
                confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (' + selectedPages.size + ')';
            }
            
            // Refresh display to update visual selection
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            if (searchTerm === '') {
                displayPages(availablePages);
            } else {
                const filtered = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
                displayPages(filtered);
            }
        }
        
        function updateSelectedCount() {
            const countElement = document.getElementById('selectedCount');
            countElement.textContent = 'Selected: ' + selectedPages.size;
        }
        
        function selectAllVisiblePages() {
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            let visiblePages;
            
            if (searchTerm === '') {
                visiblePages = availablePages;
            } else {
                visiblePages = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
            }
            
            visiblePages.forEach(page => {
                selectedPages.add(page.path);
            });
            
            updateSelectedCount();
            const confirmBtn = document.getElementById('confirmSelectionBtn');
            confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (' + selectedPages.size + ')';
            displayPages(visiblePages);
        }
        
        function clearPageSelection() {
            selectedPages.clear();
            updateSelectedCount();
            const confirmBtn = document.getElementById('confirmSelectionBtn');
            confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (0)';
            
            // Refresh display
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            if (searchTerm === '') {
                displayPages(availablePages);
            } else {
                const filtered = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
                displayPages(filtered);
            }
        }
        
        function togglePageSelection() {
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            let visiblePages;
            
            if (searchTerm === '') {
                visiblePages = availablePages;
            } else {
                visiblePages = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
            }
            
            visiblePages.forEach(page => {
                if (selectedPages.has(page.path)) {
                    selectedPages.delete(page.path);
                } else {
                    selectedPages.add(page.path);
                }
            });
            
            updateSelectedCount();
            const confirmBtn = document.getElementById('confirmSelectionBtn');
            confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (' + selectedPages.size + ')';
            displayPages(visiblePages);
        }
        
        function confirmPageSelection() {
            if (selectionMode === 'multiple' && selectedPages.size > 0) {
                // Multiple selection mode
                updateSelectedPagesDisplay(Array.from(selectedPages));
                closePageBrowser();
                showNotification(`Selected ${selectedPages.size} pages for permissions`, 'success');
            } else if (selectionMode === 'single') {
                // Single selection mode - use the single input value
                const singleInput = document.getElementById('pageUriInput');
                if (singleInput.value.trim()) {
                    updateSelectedPagesDisplay([singleInput.value.trim()]);
                    closePageBrowser();
                    showNotification(`Selected page: ${singleInput.value}`, 'success');
                } else {
                    showNotification('Please select a page or enter a custom path', 'warning');
                }
            } else {
                showNotification('Please select at least one page', 'warning');
            }
        }
        
        function updateSelectedPagesDisplay(pages) {
            const container = document.getElementById('selectedPagesList');
            const input = document.getElementById('pageUriInput');
            
            if (pages.length === 0) {
                container.innerHTML = '<span style="color: rgba(255,255,255,0.5); font-style: italic;">No pages selected</span>';
                input.value = '';
                return;
            }
            
            if (pages.length === 1) {
                // Single page - show in input field too
                input.value = pages[0];
                container.innerHTML = `
                    <span class="selected-page-tag" style="background: #4CAF50; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        ${pages[0]}
                        <i class="fas fa-times" onclick="removeSelectedPage('${pages[0]}')" style="cursor: pointer; opacity: 0.8;"></i>
                    </span>
                `;
            } else {
                // Multiple pages - clear input field and show all as tags
                input.value = `${pages.length} pages selected`;
                input.placeholder = 'Multiple pages selected - see below';
                
                const tagsHtml = pages.map(page => `
                    <span class="selected-page-tag" style="background: #4CAF50; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; display: inline-flex; align-items: center; gap: 5px; margin: 2px;">
                        ${page}
                        <i class="fas fa-times" onclick="removeSelectedPage('${page}')" style="cursor: pointer; opacity: 0.8;"></i>
                    </span>
                `).join('');
                
                container.innerHTML = tagsHtml;
            }
        }
        
        function removeSelectedPage(pagePath) {
            selectedPages.delete(pagePath);
            const remainingPages = Array.from(selectedPages);
            updateSelectedPagesDisplay(remainingPages);
            
            if (remainingPages.length === 0) {
                // Reset to single selection mode if no pages left
                document.querySelector('input[name="selectionMode"][value="single"]').checked = true;
                updateSelectionMode('single');
            }
            
            showNotification(`Removed: ${pagePath}`, 'info');
        }
        
        function clearSelectedPages() {
            selectedPages.clear();
            updateSelectedPagesDisplay([]);
            // Reset to single selection mode
            document.querySelector('input[name="selectionMode"][value="single"]').checked = true;
            updateSelectionMode('single');
            showNotification('All pages cleared', 'info');
        }
        
        function filterPages() {
            const searchTerm = document.getElementById('pageSearchInput').value.toLowerCase();
            if (searchTerm === '') {
                displayPages(availablePages);
            } else {
                const filtered = availablePages.filter(page => 
                    page.filename.toLowerCase().includes(searchTerm) ||
                    page.path.toLowerCase().includes(searchTerm) ||
                    page.directory.toLowerCase().includes(searchTerm)
                );
                displayPages(filtered);
            }
        }
        
        function selectPage(pagePath) {
            if (selectionMode === 'single') {
                // Single selection mode - update input and close modal
                updateSelectedPagesDisplay([pagePath]);
                selectedPages.clear();
                selectedPages.add(pagePath);
                closePageBrowser();
                showNotification(`Selected page: ${pagePath}`, 'success');
            } else {
                // Multiple selection mode - toggle this page
                togglePageInSelection(pagePath);
            }
        }
        
        function selectCustomPage() {
            const customPath = document.getElementById('customPagePath').value.trim();
            if (customPath) {
                if (selectionMode === 'single') {
                    updateSelectedPagesDisplay([customPath]);
                    selectedPages.clear();
                    selectedPages.add(customPath);
                    closePageBrowser();
                    showNotification(`Custom page path set: ${customPath}`, 'success');
                } else {
                    // Multiple selection mode - add to selection
                    selectedPages.add(customPath);
                    updateSelectedCount();
                    const confirmBtn = document.getElementById('confirmSelectionBtn');
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Selection (' + selectedPages.size + ')';
                    document.getElementById('customPagePath').value = ''; // Clear input
                    showNotification(`Added custom path: ${customPath}`, 'success');
                }
            } else {
                showNotification('Please enter a custom page path', 'warning');
            }
        }
        
        function refreshAvailablePages() {
            loadPagesForBrowser();
            showNotification('Page list refreshed', 'success');
        }

        function loadAvailablePages() {
            // This function is no longer needed since we use the page browser
            console.log('loadAvailablePages called - using page browser instead');
        }
        
        function refreshAvailablePages() {
            loadAvailablePages();
            showNotification('Page list refreshed', 'success');
        }

        function loadPagePermissions() {
            // First load databases for name lookup, then load permissions
            loadDatabasesForPermissions().then(() => {
                // Now load the page permissions after databases are loaded
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_page_permissions'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        pagePermissions = data.permissions; // Store in global variable
                        displayPagePermissions(data.permissions);
                    } else {
                        showNotification('Failed to load page permissions: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error loading page permissions: ' + error.message, 'error');
                });
            }).catch(error => {
                console.error('Error loading databases for permissions:', error);
                // Still try to load permissions even if database loading fails
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_page_permissions'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        pagePermissions = data.permissions; // Store in global variable
                        displayPagePermissions(data.permissions);
                    } else {
                        showNotification('Failed to load page permissions: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error loading page permissions: ' + error.message, 'error');
                });
            });
        }

        function getDatabaseNameById(databaseId) {
            // Look up database name from the loaded databases
            if (window.databases && Array.isArray(window.databases)) {
                const db = window.databases.find(database => database.id === databaseId);
                return db ? db.name : null;
            }
            return null;
        }

        function displayPagePermissions(permissions) {
            const container = document.getElementById('permissionRows');
            container.innerHTML = '';
            
            for (const [page, config] of Object.entries(permissions)) {
                const tableCount = Object.keys(config.tables || {}).length;
                const allOperations = new Set();
                
                Object.values(config.tables || {}).forEach(table => {
                    (table.operations || []).forEach(op => allOperations.add(op));
                });
                
                // Get database name by looking up the database ID in the databases list
                const databaseName = getDatabaseNameById(config.database);
                
                container.innerHTML += `
                    <div style="display: contents;">
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${page}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${config.database || 'None'}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${databaseName || 'Unknown'}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${tableCount} tables</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${Array.from(allOperations).join(', ')}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center; gap: 5px;">
                            <button onclick="editPagePermissions('${page}')" class="btn" style="padding: 5px 10px; font-size: 12px; background: #2196F3;">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button onclick="deletePagePermissions('${page}')" class="btn" style="padding: 5px 10px; font-size: 12px; background: #f44336;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </span>
                    </div>
                `;
            }
        }

        function loadDatabasesForPermissions() {
            console.log('Loading databases for context mapping and permission display...');
            const bodyParams = new URLSearchParams();
            bodyParams.set('action', 'get_active_databases');
            bodyParams.set('include_tenants', '1');
            bodyParams.set('tenant_limit', '200');
            bodyParams.set('tenant_offset', '0');
            bodyParams.set('tenant_query', '');
            return fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: bodyParams.toString()
            })
            .then(response => {
                console.log('Database response status:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('Full database response:', data);
                if (data.success && data.databases) {
                    // Store databases globally for permission display
                    const coreList = Array.isArray(data.databases) ? data.databases : [];
                    const tenantList = Array.isArray(data.tenants) ? data.tenants : [];
                    window.databases = [...coreList, ...tenantList];
                    console.log('Stored databases globally:', window.databases);
                    
                    // Update context mapping dropdown
                    const contextSelect = document.getElementById('mappingDatabase');
                    
                    if (contextSelect) {
                        contextSelect.innerHTML = '<option value="">Select Database</option>';
                        
                        const coreGroup = document.createElement('optgroup');
                        coreGroup.label = 'Core';
                        contextSelect.appendChild(coreGroup);
                        coreList.forEach(config => {
                            const option = document.createElement('option');
                            option.value = config.id;
                            option.textContent = config.name;
                            coreGroup.appendChild(option);
                        });

                        const tenantGroup = document.createElement('optgroup');
                        tenantGroup.label = 'Tenants';
                        contextSelect.appendChild(tenantGroup);
                        tenantList.forEach(config => {
                            const tenantId = typeof config.tenant_id === 'string' ? config.tenant_id : '';
                            const option = document.createElement('option');
                            option.value = config.id;
                            option.textContent = tenantId !== '' ? `${tenantId} (${config.name})` : config.name;
                            tenantGroup.appendChild(option);
                        });
                    } else {
                        console.log('mappingDatabase element not found - skipping dropdown update');
                    }
                } else {
                    console.error('Database loading failed or no databases property:', data);
                    window.databases = []; // Initialize as empty array
                }
                return data; // Return the data for chaining
            })
            .catch(error => {
                console.error('Error loading databases:', error);
                window.databases = []; // Initialize as empty array on error
                throw error; // Re-throw for error handling in calling function
            });
        }
        
        function addTablePermission(tableName = '', permissions = []) {
            console.log('=== ADD TABLE PERMISSION CLICKED ===');
            console.log('Table name:', tableName, 'Permissions:', permissions);
            const container = document.getElementById('tablePermissions');
            
            // Always use the main database selection
            const databaseId = getCurrentDatabaseId();
            console.log('Using main database ID:', databaseId);
            
            if (!databaseId && !tableName) {
                console.log('No database selected in main interface! Showing warning.');
                showNotification('Please select a database from the main dropdown first', 'warning');
                return;
            }
            
            const index = container.children.length;
            
            const permissionDiv = document.createElement('div');
            permissionDiv.className = 'table-permission-row';
            permissionDiv.style.cssText = 'display: flex; gap: 10px; margin: 10px 0; align-items: center; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 4px;';
            
            // Determine checkbox states for pre-population
            const isRead = permissions.includes('read');
            const isWrite = permissions.includes('write');
            const isUpdate = permissions.includes('update');
            const isDelete = permissions.includes('delete');
            
            permissionDiv.innerHTML = `
                <select name="tableName[]" required style="flex: 1; padding: 8px; background: rgba(0,0,0,0.3); color: white; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px;">
                    <option value="">Loading tables...</option>
                </select>
                <div style="display: flex; gap: 15px; flex: 2;">
                    <label style="display: flex; align-items: center; gap: 5px; color: white; cursor: pointer;">
                        <input type="checkbox" name="operations[]" value="${index}_read" ${isRead ? 'checked' : ''}> Read
                    </label>
                    <label style="display: flex; align-items: center; gap: 5px; color: white; cursor: pointer;">
                        <input type="checkbox" name="operations[]" value="${index}_write" ${isWrite ? 'checked' : ''}> Write
                    </label>
                    <label style="display: flex; align-items: center; gap: 5px; color: white; cursor: pointer;">
                        <input type="checkbox" name="operations[]" value="${index}_update" ${isUpdate ? 'checked' : ''}> Update
                    </label>
                    <label style="display: flex; align-items: center; gap: 5px; color: white; cursor: pointer;">
                        <input type="checkbox" name="operations[]" value="${index}_delete" ${isDelete ? 'checked' : ''}> Delete
                    </label>
                </div>
                <button type="button" onclick="removeTablePermission(this)" class="btn" style="padding: 5px 10px; background: #f44336;">
                    <i class="fas fa-trash"></i> Remove
                </button>
            `;
            
            container.appendChild(permissionDiv);
            
            // Load tables for the new dropdown using the main database selection
            const selectElement = permissionDiv.querySelector('select');
            loadTablesForNewPermission(selectElement, databaseId, tableName);
        }
        
        function loadTablesForNewPermission(selectElement, databaseId, preSelectedTable = '') {
            console.log('Loading tables for database ID:', databaseId, 'Pre-selected table:', preSelectedTable);
            
            // Show loading state
            selectElement.innerHTML = '<option value="">Loading tables...</option>';
            selectElement.disabled = true;
            selectElement.style.opacity = '0.6';
            
            // Add loading class if available
            if (selectElement.classList) {
                selectElement.classList.add('loading');
            }
            
            const startTime = performance.now();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_tables&config_id=${databaseId}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                const loadTime = performance.now() - startTime;
                console.log(`Tables loaded in ${loadTime.toFixed(2)}ms:`, data);
                
                // Remove loading state
                selectElement.disabled = false;
                selectElement.style.opacity = '1';
                if (selectElement.classList) {
                    selectElement.classList.remove('loading');
                }
                
                if (data.success && data.tables) {
                    selectElement.innerHTML = '<option value="">Select table...</option>';
                    
                    // Sort tables by name for better UX
                    const sortedTables = data.tables.sort((a, b) => {
                        const nameA = typeof a === 'object' ? a.name : a;
                        const nameB = typeof b === 'object' ? b.name : b;
                        return nameA.localeCompare(nameB);
                    });
                    
                    sortedTables.forEach(table => {
                        // Fix: Check if table is an object or string
                        const tableName = typeof table === 'object' ? table.name : table;
                        const tableRows = typeof table === 'object' ? table.rows : 0;
                        const displayText = tableRows > 0 ? `${tableName} (${tableRows} rows)` : tableName;
                        const selected = tableName === preSelectedTable ? 'selected' : '';
                        
                        selectElement.innerHTML += `<option value="${tableName}" title="${displayText}" ${selected}>${displayText}</option>`;
                    });
                    
                    console.log('Loaded', data.tables.length, 'tables successfully');
                    if (preSelectedTable) {
                        console.log('Pre-selected table:', preSelectedTable);
                    }
                    
                    // Show cache status if available
                    if (data.cached) {
                        console.log('Tables loaded from cache');
                    }
                } else {
                    selectElement.innerHTML = '<option value="">No tables found</option>';
                    console.error('Failed to load tables:', data.message || 'Unknown error');
                    showNotification(`Failed to load tables: ${data.message || 'Unknown error'}`, 'warning');
                }
            })
            .catch(error => {
                const loadTime = performance.now() - startTime;
                console.error(`Table loading failed after ${loadTime.toFixed(2)}ms:`, error);
                
                // Remove loading state
                selectElement.disabled = false;
                selectElement.style.opacity = '1';
                if (selectElement.classList) {
                    selectElement.classList.remove('loading');
                }
                
                selectElement.innerHTML = '<option value="">Error loading tables</option>';
                showNotification(`Error loading tables: ${error.message}`, 'error');
            });
        }
        
        function loadTablesForPermissions() {
            // Reload tables for all existing permission rows when database changes
            const databaseId = getCurrentDatabaseId();
            const tableSelects = document.querySelectorAll('#tablePermissions select[name="tableName[]"]');
            tableSelects.forEach(select => {
                loadTablesForNewPermission(select, databaseId);
            });
        }

        function removeTablePermission(button) {
            button.parentElement.remove();
        }

        function toggleAllTables(selectAll) {
            const databaseId = getCurrentDatabaseId();
            if (!databaseId) {
                showNotification('Please select a database first', 'warning');
                document.getElementById('selectAllTables').checked = false;
                return;
            }
            
            if (selectAll) {
                // Need to fetch all tables for the selected database and add them
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_tables&config_id=${databaseId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.tables && data.tables.length > 0) {
                        // Clear existing permissions first
                        const container = document.getElementById('tablePermissions');
                        container.innerHTML = '<button type="button" class="btn" onclick="addTablePermission()"><i class="fas fa-plus"></i> Add Table Permission</button>';
                        
                        // Add a permission row for each table
                        data.tables.forEach(table => {
                            addTablePermission(table.name, []);
                        });
                        showNotification(`Added permissions for ${data.tables.length} tables`, 'success');
                    } else {
                        showNotification('No tables found in selected database', 'warning');
                        document.getElementById('selectAllTables').checked = false;
                    }
                })
                .catch(error => {
                    showNotification('Error loading tables: ' + error.message, 'error');
                    document.getElementById('selectAllTables').checked = false;
                });
            } else {
                // Clear all table permissions
                const container = document.getElementById('tablePermissions');
                container.innerHTML = '<button type="button" class="btn" onclick="addTablePermission()"><i class="fas fa-plus"></i> Add Table Permission</button>';
                showNotification('Cleared all table permissions', 'info');
            }
        }

        function toggleAllOperations(operation, selectAll) {
            const checkboxes = document.querySelectorAll(`#tablePermissions input[type="checkbox"][value$="_${operation}"]`);
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll;
            });
            
            const count = checkboxes.length;
            if (count > 0) {
                showNotification(`${selectAll ? 'Selected' : 'Deselected'} ${operation} operation for ${count} table${count !== 1 ? 's' : ''}`, 'info');
            }
        }

        function addPagePermission() {
            console.log('=== ADD PAGE PERMISSION TRIGGERED ===');
            
            const form = document.getElementById('addPagePermissionForm');
            const pageUriInput = document.getElementById('pageUriInput').value.trim();
            const databaseId = getCurrentDatabaseId(); // Use the main database selection
            const isEditMode = form.hasAttribute('data-edit-mode');
            
            // Collect selected pages
            let pagesToProcess = [];
            
            if (selectedPages.size > 0) {
                // Multiple pages selected
                pagesToProcess = Array.from(selectedPages);
            } else if (pageUriInput) {
                // Single page from input
                pagesToProcess = [pageUriInput];
            }
            
            console.log('Pages to process:', pagesToProcess);
            console.log('Database ID:', databaseId);
            console.log('Edit Mode:', isEditMode);
            
            // Enhanced validation - stricter for edit mode
            if (pagesToProcess.length === 0) {
                showNotification('Please select or enter at least one page URI', 'warning');
                document.getElementById('pageUriInput').focus();
                return;
            }
            
            if (!databaseId) {
                const message = isEditMode ? 
                    'EDIT MODE: You must re-select a database. Previous database selection was cleared.' : 
                    'Please select a database from the main dropdown first';
                showNotification(message, 'warning');
                return;
            }
            
            // Collect table permissions with enhanced validation for edit mode
            const tables = {};
            const tableRows = document.querySelectorAll('.table-permission-row');
            
            if (tableRows.length === 0) {
                const message = isEditMode ? 
                    'EDIT MODE: You must re-assign ALL required tables. No previous assignments are retained.' : 
                    'Please add at least one table permission';
                showNotification(message, 'warning');
                return;
            }
            
            let hasValidPermission = false;
            let incompleteAssignments = [];
            
            tableRows.forEach((row, index) => {
                const tableSelect = row.querySelector('select[name="tableName[]"]');
                const operationCheckboxes = row.querySelectorAll('input[type="checkbox"]:checked');
                
                if (tableSelect && tableSelect.value) {
                    const tableName = tableSelect.value;
                    const operations = [];
                    
                    operationCheckboxes.forEach(checkbox => {
                        const operation = checkbox.value.split('_')[1]; // Extract operation from "index_operation" format
                        if (operation) {
                            operations.push(operation);
                        }
                    });
                    
                    if (operations.length > 0) {
                        tables[tableName] = {
                            operations: operations
                        };
                        hasValidPermission = true;
                        console.log(`Added permission for table ${tableName}:`, operations);
                    } else {
                        incompleteAssignments.push(tableName);
                    }
                } else if (tableSelect) {
                    incompleteAssignments.push('Unnamed table row ' + (index + 1));
                }
            });
            
            // Enhanced validation for edit mode
            if (!hasValidPermission) {
                const message = isEditMode ? 
                    'EDIT MODE: You must re-assign at least one table with valid operations. All previous assignments were cleared and must be manually re-configured.' : 
                    'Please select at least one table and one operation';
                showNotification(message, 'warning');
                return;
            }
            
            if (incompleteAssignments.length > 0 && isEditMode) {
                showNotification(`EDIT MODE WARNING: Incomplete assignments detected for: ${incompleteAssignments.join(', ')}. Please complete all table assignments.`, 'warning');
                return;
            }
            
            // Additional confirmation for multiple pages
            if (pagesToProcess.length > 1 && !isEditMode) {
                const tableCount = Object.keys(tables).length;
                const confirmMessage = `CONFIRM MULTIPLE PAGES: You are adding permissions for ${pagesToProcess.length} pages:

${pagesToProcess.slice(0, 5).join('\n')}${pagesToProcess.length > 5 ? '\n... and ' + (pagesToProcess.length - 5) + ' more' : ''}

Each page will get ${tableCount} table permission(s): ${Object.keys(tables).join(', ')}

Continue with batch creation?`;
                
                if (!confirm(confirmMessage)) {
                    showNotification('Batch operation cancelled by user', 'info');
                    return;
                }
            }
            
            // Additional confirmation for edit mode
            if (isEditMode) {
                const originalPage = form.getAttribute('data-original-page');
                const tableCount = Object.keys(tables).length;
                const confirmMessage = `CONFIRM EDIT: You are updating permissions for "${originalPage}". 
                
You have assigned ${tableCount} table(s): ${Object.keys(tables).join(', ')}

All previous assignments were cleared. This will REPLACE the existing configuration.

Continue with update?`;
                
                if (!confirm(confirmMessage)) {
                    showNotification('Edit operation cancelled by user', 'info');
                    return;
                }
            }
            
            // Show loading state with appropriate text
            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;
            const loadingText = isEditMode ? 
                '<i class="fas fa-spinner fa-spin"></i> Updating Permission...' : 
                (pagesToProcess.length > 1 ? 
                    `<i class="fas fa-spinner fa-spin"></i> Adding ${pagesToProcess.length} Permissions...` :
                    '<i class="fas fa-spinner fa-spin"></i> Adding Permission...');
            submitButton.innerHTML = loadingText;
            submitButton.disabled = true;
            
            const requestData = {
                action: 'add_page_permission',
                database_id: databaseId,
                tables: JSON.stringify(tables)
            };
            
            // Send multiple pages if available, otherwise send single page
            if (pagesToProcess.length > 1) {
                requestData.page_uris = JSON.stringify(pagesToProcess);
            } else {
                requestData.page_uri = pagesToProcess[0];
            }
            
            console.log('Sending request data:', requestData);
            console.log('Processing', pagesToProcess.length, 'page(s)');
            
            const startTime = performance.now();
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(requestData).toString()
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                const responseTime = performance.now() - startTime;
                console.log(`Permission ${isEditMode ? 'updated' : 'added'} in ${responseTime.toFixed(2)}ms:`, data);
                
                if (data.success) {
                    let successMessage;
                    
                    if (isEditMode) {
                        successMessage = 'Page permission updated successfully! All new assignments have been saved.';
                    } else if (pagesToProcess.length > 1) {
                        // Multiple pages processed
                        if (data.details) {
                            const { total, success, failed } = data.details;
                            if (failed === 0) {
                                successMessage = `All ${total} page permissions added successfully!`;
                            } else {
                                successMessage = `Partial success: ${success} of ${total} permissions added`;
                                if (data.details.errors && data.details.errors.length > 0) {
                                    console.warn('Some permissions failed:', data.details.errors);
                                }
                            }
                        } else {
                            successMessage = `${pagesToProcess.length} page permissions added successfully!`;
                        }
                    } else {
                        successMessage = 'Page permission added successfully!';
                    }
                    
                    showNotification(successMessage, 'success');
                    
                    // Reset form - different behavior for edit vs add
                    if (isEditMode) {
                        // In edit mode, reset to add mode after successful update
                        resetPagePermissionForm();
                    } else {
                        // In add mode, clear the form and selected pages
                        document.getElementById('pageUriInput').value = '';
                        document.getElementById('tablePermissions').innerHTML = '<button type="button" class="btn" onclick="addTablePermission()"><i class="fas fa-plus"></i> Add Table Permission</button>';
                        
                        // Clear selected pages and reset to single mode
                        selectedPages.clear();
                        updateSelectedPagesDisplay([]);
                        document.querySelector('input[name="selectionMode"][value="single"]').checked = true;
                        updateSelectionMode('single');
                    }
                    
                    // Reload permissions to show the updated/new one
                    loadPagePermissions();
                } else {
                    console.error('Server error:', data.message);
                    let errorMessage;
                    
                    if (isEditMode) {
                        errorMessage = `Error updating permission: ${data.message}`;
                    } else if (pagesToProcess.length > 1) {
                        errorMessage = `Error adding ${pagesToProcess.length} permissions: ${data.message}`;
                        if (data.details && data.details.errors) {
                            errorMessage += ' Details: ' + data.details.errors.join('; ');
                        }
                    } else {
                        errorMessage = `Error: ${data.message}`;
                    }
                    
                    showNotification(errorMessage, 'error');
                }
            })
            .catch(error => {
                const responseTime = performance.now() - startTime;
                console.error(`Permission addition failed after ${responseTime.toFixed(2)}ms:`, error);
                showNotification(`Error adding permission: ${error.message}`, 'error');
            })
            .finally(() => {
                // Restore button state
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            });
        }

        function editPagePermissions(page) {
            // Implementation for editing permissions - POPULATE WITH PREVIOUS DATA
            console.log('=== EDIT PAGE PERMISSIONS CALLED ===');
            console.log('Page:', page);
            console.log('All pagePermissions:', pagePermissions);
            
            const currentPermissions = pagePermissions[page];
            if (!currentPermissions) {
                console.error('Page permissions not found for:', page);
                showNotification('Page permissions not found', 'error');
                return;
            }
            
            console.log('Current permissions for page:', currentPermissions);
            
            // Show edit form by scrolling to the add permission section
            const addSection = document.querySelector('.add-permission-section');
            addSection.scrollIntoView({ behavior: 'smooth' });
            
            // Clear existing table permissions container first
            const tablePermissionsContainer = document.getElementById('tablePermissions');
            tablePermissionsContainer.innerHTML = '<button type="button" class="btn" onclick="addTablePermission()"><i class="fas fa-plus"></i> Add Table Permission</button>';
            
            // Populate page information
            document.getElementById('pageUriInput').value = page;
            
            // Set database selection if available
            if (currentPermissions.database) {
                console.log('Setting database to:', currentPermissions.database);
                
                // Set the main database dropdown first
                const databaseSelect = document.getElementById('selectedDatabase');
                if (databaseSelect) {
                    databaseSelect.value = currentPermissions.database;
                    console.log('Database dropdown set to:', databaseSelect.value);
                }
                
                // Set the hidden database ID field
                const hiddenField = document.getElementById('selectedDatabaseId');
                if (hiddenField) {
                    hiddenField.value = currentPermissions.database;
                    console.log('Hidden database field set to:', hiddenField.value);
                }
                
                // Populate existing table permissions
                if (currentPermissions.tables && typeof currentPermissions.tables === 'object') {
                    console.log('Tables data:', currentPermissions.tables);
                    
                    // Convert tables object to array for processing
                    Object.keys(currentPermissions.tables).forEach(tableName => {
                        const tableData = currentPermissions.tables[tableName];
                        const permissions = tableData.operations || [];
                        console.log('Adding table permission for:', tableName, 'with operations:', permissions);
                        addTablePermission(tableName, permissions);
                    });
                } else {
                    console.log('No tables data found or invalid format:', currentPermissions.tables);
                }
            } else {
                console.log('No database specified in permissions');
            }
            
            // Set form to edit mode
            const form = document.getElementById('addPagePermissionForm');
            form.setAttribute('data-edit-mode', 'true');
            form.setAttribute('data-original-page', page);
            
            // Update UI to indicate edit mode
            const sectionTitle = addSection.querySelector('h5');
            sectionTitle.innerHTML = '<i class="fas fa-edit" style="color: #ff9800;"></i> Edit Page Permission';
            
            // Add info notification about editing
            const infoDiv = document.createElement('div');
            infoDiv.className = 'edit-info';
            infoDiv.style.cssText = 'background: #e3f2fd; border: 1px solid #2196f3; color: #0d47a1; padding: 12px; margin: 10px 0; border-radius: 4px; border-left: 4px solid #2196f3;';
            infoDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-info-circle" style="color: #2196f3;"></i>
                    <strong>EDIT MODE:</strong> Previous data has been loaded for modification.
                </div>
                <div style="margin-top: 8px; font-size: 14px;">
                    Existing table permissions are pre-populated. Make your changes and save.
                </div>
            `;
            
            // Remove any existing info first
            const existingInfo = addSection.querySelector('.edit-info');
            if (existingInfo) {
                existingInfo.remove();
            }
            
            // Insert info after the title
            sectionTitle.parentNode.insertBefore(infoDiv, sectionTitle.nextSibling);
            
            // Update submit button text for edit mode
            const submitBtn = addSection.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Permission';
                submitBtn.style.background = '#ff9800';
            }
            
            // Add cancel button
            let cancelBtn = submitBtn.parentNode.querySelector('.cancel-edit-btn');
            if (!cancelBtn) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'btn btn-secondary cancel-edit-btn';
                cancelBtn.style.cssText = 'margin-left: 10px; background: #6c757d; border-color: #6c757d;';
                cancelBtn.innerHTML = '<i class="fas fa-times"></i> Cancel Edit';
                cancelBtn.onclick = resetPagePermissionForm;
                submitBtn.parentNode.appendChild(cancelBtn);
            }
            
            // Show notification about edit mode
            showNotification('Edit mode: Previous data loaded for modification', 'info');
        }

        function resetPagePermissionForm() {
            // Clear form values completely
            document.getElementById('pageUriInput').value = '';
            document.getElementById('selectedDatabaseId').value = '';
            
            // Clear table permissions completely
            const tablePermissionsContainer = document.getElementById('tablePermissions');
            tablePermissionsContainer.innerHTML = '<button type="button" class="btn" onclick="addTablePermission()"><i class="fas fa-plus"></i> Add Table Permission</button>';
            
            // Remove edit mode attributes
            const form = document.getElementById('addPagePermissionForm');
            form.removeAttribute('data-edit-mode');
            form.removeAttribute('data-original-page');
            
            // Reset section title to default
            const addSection = document.querySelector('.add-permission-section');
            const sectionTitle = addSection.querySelector('h5');
            sectionTitle.innerHTML = '<i class="fas fa-plus"></i> Add Page Permission';
            
            // Remove any edit info messages
            const existingInfo = addSection.querySelector('.edit-info');
            if (existingInfo) {
                existingInfo.remove();
            }
            
            // Reset submit button text and styling
            const submitBtn = addSection.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-plus"></i> Add Permission';
                submitBtn.style.background = ''; // Reset to default
                
                // Remove cancel button if it exists
                const cancelBtn = submitBtn.parentNode.querySelector('.cancel-edit-btn');
                if (cancelBtn) {
                    cancelBtn.remove();
                }
            }
            
            showNotification('Form reset to add mode - ready for new permission', 'info');
        }

        function deletePagePermissions(page) {
            if (confirm('Are you sure you want to delete permissions for ' + page + '?')) {
                const formData = new FormData();
                formData.append('action', 'delete_page_permission');
                formData.append('page', page);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Permission deleted successfully', 'success');
                        loadPagePermissions();
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting permission: ' + error.message, 'error');
                });
            }
        }

        // Context Mapping Functions
        function initializeContextMappingTab() {
            loadContextMappings();
            
            // Add form event listener
            document.getElementById('addContextMappingForm').addEventListener('submit', function(e) {
                e.preventDefault();
                addContextMapping();
            });
        }

        function loadContextMappings() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_context_mappings'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayContextMappings(data.mappings);
                    document.getElementById('autoSwitchEnabled').checked = data.mappings.auto_switch || false;
                } else {
                    showNotification('Failed to load context mappings: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error loading context mappings: ' + error.message, 'error');
            });
        }

        function displayContextMappings(mappings) {
            const container = document.getElementById('mappingRows');
            container.innerHTML = '';
            
            // Display page mappings
            for (const [page, database] of Object.entries(mappings.page_mappings || {})) {
                container.innerHTML += `
                    <div style="display: contents;">
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${page}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">Page</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${database}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">
                            <button onclick="deleteContextMapping('page', '${page}')" class="btn" style="padding: 5px 10px; font-size: 12px; background: #f44336;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </span>
                    </div>
                `;
            }
            
            // Display directory mappings
            for (const [directory, database] of Object.entries(mappings.directory_mappings || {})) {
                container.innerHTML += `
                    <div style="display: contents;">
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${directory}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">Directory</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">${database}</span>
                        <span style="padding: 10px 12px; border-bottom: 1px solid #444; display: flex; align-items: center;">
                            <button onclick="deleteContextMapping('directory', '${directory}')" class="btn" style="padding: 5px 10px; font-size: 12px; background: #f44336;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </span>
                    </div>
                `;
            }
        }

        function addContextMapping() {
            const form = document.getElementById('addContextMappingForm');
            
            // Ensure the hidden database field is set to the current selection
            const currentDbId = getCurrentDatabaseId();
            const mappingDbField = document.getElementById('mappingDatabase');
            if (mappingDbField) {
                mappingDbField.value = currentDbId || '';
            }
            
            const formData = new FormData(form);
            formData.append('action', 'add_context_mapping');
            
            // Debug logging
            console.log('Form data being sent:', {
                mappingType: formData.get('mappingType'),
                mappingPath: formData.get('mappingPath'),
                mappingDatabase: formData.get('mappingDatabase'),
                currentDbId: currentDbId
            });
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Context mapping added successfully', 'success');
                    form.reset();
                    // Re-set the database field after reset
                    if (mappingDbField) {
                        mappingDbField.value = currentDbId || '';
                    }
                    loadContextMappings();
                } else {
                    showNotification('Error: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error adding mapping: ' + error.message, 'error');
            });
        }

        function deleteContextMapping(type, path) {
            if (confirm('Are you sure you want to delete this mapping?')) {
                const formData = new FormData();
                formData.append('action', 'delete_context_mapping');
                formData.append('type', type);
                formData.append('path', path);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Mapping deleted successfully', 'success');
                        loadContextMappings();
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('Error deleting mapping: ' + error.message, 'error');
                });
            }
        }

        function toggleAutoSwitch() {
            const enabled = document.getElementById('autoSwitchEnabled').checked;
            
            const formData = new FormData();
            formData.append('action', 'toggle_auto_switch');
            formData.append('enabled', enabled);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Auto-switch ' + (enabled ? 'enabled' : 'disabled'), 'success');
                } else {
                    showNotification('Error updating auto-switch: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showNotification('Error: ' + error.message, 'error');
            });
        }

        
        // Helper functions to sync our tabs with main database selection
        function updatePagePermissionsDatabase(configId) {
            const hiddenDbField = document.getElementById('selectedDatabaseId');
            if (hiddenDbField && configId) {
                // Set the selected database in permissions tab hidden field
                hiddenDbField.value = configId;
                console.log('Updated page permissions database ID to:', configId);
                
                // Refresh table dropdowns if any exist
                const tableSelects = document.querySelectorAll('#tablePermissions select[name="tableName[]"]');
                tableSelects.forEach(select => {
                    loadTablesForNewPermission(select, configId);
                });
            }
        }
        
        function updateContextMappingDatabase(configId) {
            const contextDbHidden = document.getElementById('mappingDatabase');
            if (contextDbHidden && configId) {
                // Set the selected database in context mapping tab
                contextDbHidden.value = configId;
                console.log('Updated context mapping database to:', configId);
            }
        }
        
        // Context Mapping helper functions
        function updatePatternPlaceholder() {
            const typeSelect = document.getElementById('mappingType');
            const pathInput = document.getElementById('mappingPath');
            const typeHelp = document.getElementById('typeHelp');
            const pathHelp = document.getElementById('pathHelp');
            
            const type = typeSelect.value;
            
            switch (type) {
                case 'page':
                    pathInput.placeholder = 'e.g., dashboard.php';
                    typeHelp.textContent = 'Match a specific page file';
                    pathHelp.textContent = 'Enter the specific page filename to match';
                    break;
                case 'directory':
                    pathInput.placeholder = 'e.g., /admin or admin/';
                    typeHelp.textContent = 'Match all pages in a directory';
                    pathHelp.textContent = 'Enter directory path (with or without leading/trailing slashes)';
                    break;
                case 'wildcard':
                    pathInput.placeholder = 'e.g., admin_*.php or /users/*';
                    typeHelp.textContent = 'Use * as wildcard for pattern matching';
                    pathHelp.textContent = 'Use asterisks (*) to match any characters';
                    break;
                case 'regex':
                    pathInput.placeholder = 'e.g., ^(dashboard|admin)\.php$';
                    typeHelp.textContent = 'Advanced pattern matching with regex';
                    pathHelp.textContent = 'Enter a valid regular expression pattern';
                    break;
            }
        }
        
        function testPatternMatch() {
            const pattern = document.getElementById('mappingPath').value;
            const type = document.getElementById('mappingType').value;
            
            if (!pattern) {
                showToast('Please enter a pattern to test', 'warning');
                return;
            }
            
            // Simple test - prompt for a URL to test against
            const testUrl = prompt('Enter a URL path to test against the pattern:', window.location.pathname);
            if (!testUrl) return;
            
            let matches = false;
            try {
                switch (type) {
                    case 'page':
                        // For pages, check exact match or ending match
                        matches = testUrl === pattern || testUrl.endsWith('/' + pattern);
                        break;
                    case 'directory':
                        // For directories, check if URL starts with the pattern
                        const cleanPattern = pattern.replace(/^\/+|\/+$/g, ''); // Remove leading/trailing slashes
                        const cleanUrl = testUrl.replace(/^\/+/, ''); // Remove leading slashes
                        matches = cleanUrl.startsWith(cleanPattern) || testUrl.startsWith('/' + cleanPattern);
                        break;
                    case 'wildcard':
                        const regexPattern = pattern.replace(/\*/g, '.*').replace(/\?/g, '.');
                        matches = new RegExp(regexPattern).test(testUrl);
                        break;
                    case 'regex':
                        matches = new RegExp(pattern).test(testUrl);
                        break;
                }
                
                const result = matches ? 'MATCHES' : 'NO MATCH';
                const color = matches ? 'success' : 'error';
                showToast(`Test Result: ${result} - Pattern "${pattern}" vs URL "${testUrl}"`, color);
                
            } catch (error) {
                showToast('Pattern test failed: ' + error.message, 'error');
            }
        }
        
        // Function to get currently selected database ID from main interface
        function getCurrentDatabaseId() {
            const mainSelect = document.getElementById('selectedDatabase');
            console.log('Main database select element:', mainSelect);
            const value = mainSelect ? mainSelect.value : null;
            console.log('Main database select value:', value);
            return value;
        }
        
        // Enhanced function to check if a database is currently selected
        function isDatabaseSelected() {
            const configId = getCurrentDatabaseId();
            return configId && configId.trim() !== '';
        }

        
        // Diagnostic function to force reload databases
        function forceReloadDatabases() {
            console.log('=== FORCE RELOAD DATABASES ===');
            
            // Check if elements exist
            const mainSelect = document.getElementById('selectedDatabase');
            console.log('selectedDatabase element:', mainSelect);
            
            if (!mainSelect) {
                console.error('selectedDatabase element not found!');
                return;
            }
            
            // Force call loadActiveDatabases
            console.log('Calling loadActiveDatabases...');
            loadActiveDatabases();
            
            // Also test the endpoint directly
            console.log('Testing endpoint directly...');
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_active_databases'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Direct endpoint test result:', data);
                if (data.success && data.databases) {
                    console.log('Found', data.databases.length, 'databases');
                    data.databases.forEach((db, index) => {
                        console.log(`Database ${index + 1}:`, db.name, '(' + db.type + ')');
                    });
                }
            })
            .catch(error => {
                console.error('Direct endpoint test failed:', error);
            });
        }
        
        // Add to global scope for manual calling
        window.forceReloadDatabases = forceReloadDatabases;
        
        // Also call it automatically after a delay
        setTimeout(() => {
            console.log('Auto-calling forceReloadDatabases after 2 seconds...');
            forceReloadDatabases();
        }, 2000);

        // Widget Testing Functions
        function testLoaderWidget() {
            if (typeof showLoadingAnimation === 'function') {
                showLoadingAnimation('Testing loader widget configuration...');
                setTimeout(() => {
                    if (typeof hideLoadingAnimation === 'function') {
                        hideLoadingAnimation();
                    }
                }, 3000);
            } else {
                // Try to load loader widget if not available
                const script = document.createElement('script');
                script.src = '/templates/widgets/loader/loader.js';
                script.onload = function() {
                    setTimeout(() => testLoaderWidget(), 500);
                };
                document.head.appendChild(script);
                
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = '/templates/widgets/loader/loader.css';
                document.head.appendChild(link);
            }
        }

        function testNoticesWidget() {
            if (typeof window.popupNotice !== 'undefined' && window.popupNotice) {
                window.popupNotice.show('Testing notices widget configuration!', 'info');
            } else if (typeof window.globalPopupNotice !== 'undefined' && window.globalPopupNotice) {
                window.globalPopupNotice.show('Testing notices widget configuration!', 'info');
            } else {
                alert('Notices widget not available on this page. Please visit the configuration page for full testing.');
            }
        }

        function testDragDropWidget() {
            showToast('Drag & Drop widget preview coming soon. Visit individual widget configuration pages for detailed testing.', 'info');
        }

        function testIconsWidget() {
            showToast('Icons widget preview coming soon. Visit individual widget configuration pages for detailed testing.', 'info');
        }

        function testSidebarWidget() {
            showToast('Sidebar widget preview coming soon. Visit individual widget configuration pages for detailed testing.', 'info');
        }

        function testAutosaveWidget() {
            showToast('Autosave widget preview coming soon. Visit individual widget configuration pages for detailed testing.', 'info');
        }

    </script>
<?php
    $paths = cue_autoload('paths');
    $fmtBytes = function ($bytes) {
        $bytes = (float)$bytes;
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        $prec = $i === 0 ? 0 : 1;
        return number_format($bytes, $prec) . ' ' . $units[$i];
    };
    $mounts = [
        ['label' => 'MySQL', 'path' => $paths->getMysqlPath()],
        ['label' => 'Vector', 'path' => $paths->getVectorPath()],
        ['label' => 'Graph', 'path' => $paths->getGraphPath()],
        ['label' => 'Data', 'path' => $paths->getDataPath()],
        ['label' => 'Backup', 'path' => '/backup'],
        ['label' => 'WHM MySQL', 'path' => '/var/lib/mysql'],
    ];
    echo '<section style="padding:20px;border-top:1px solid #2b899e">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    echo '<h3 style="margin:0">Storage & Context</h3>';
    echo '<button type="button" style="padding:8px 12px;border-radius:10px;border:1px solid #2b899e;background:rgba(10,160,182,0.15);color:#fff;cursor:pointer" onclick="(function(){const el=document.getElementById(\'mhStorageContextFrame\'); if(el){ el.scrollIntoView({behavior:\'smooth\', block:\'start\'});} })()">Jump to Panel</button>';
    echo '</div>';
    echo '<div id="mhStorageContextFrame" style="margin-top:12px;height:60vh;min-height:280px;max-height:640px;overflow:auto;-webkit-overflow-scrolling:touch;border:1px solid rgba(43,137,158,0.35);border-radius:12px;background:rgba(0,0,0,0.18)">';
    echo '<div style="position:sticky;top:0;z-index:5;display:flex;gap:10px;justify-content:flex-start;align-items:center;padding:12px;border-bottom:1px solid rgba(43,137,158,0.35);background:rgba(0,0,0,0.90);backdrop-filter:blur(8px)">';
    echo '<button type="button" id="mhCreateContextFilesBtnTop" style="display:inline-block;padding:10px 14px;border-radius:10px;border:1px solid #2b899e;background:#0aa0b6;color:#fff;cursor:pointer">Create Missing Context Files</button>';
    echo '<span style="opacity:0.75;font-size:12px">This stays visible while you scroll.</span>';
    echo '</div>';
    echo '<table style="width:100%;border-collapse:collapse">';
    echo '<tr><th style="text-align:left;padding:8px">Store</th><th style="text-align:left;padding:8px">Path</th><th style="text-align:left;padding:8px">Exists</th><th style="text-align:left;padding:8px">Writable</th><th style="text-align:left;padding:8px">Free</th><th style="text-align:left;padding:8px">Total</th><th style="text-align:left;padding:8px">Browse</th></tr>';
    foreach ($mounts as $m) {
        $p = (string)$m['path'];
        $exists = is_dir($p) ? 'Yes' : 'No';
        $w = is_dir($p) && is_writable($p) ? 'Yes' : 'No';
        $free = '';
        $total = '';
        if (is_dir($p)) {
            $f = @disk_free_space($p);
            $t = @disk_total_space($p);
            if (is_float($f) || is_int($f)) $free = $fmtBytes($f);
            if (is_float($t) || is_int($t)) $total = $fmtBytes($t);
        }
        $browse = '';
        if (strpos($p, '/data') === 0) {
            $browse = '/gear/settings/block-browser.php?root=data&path=' . urlencode(ltrim(substr($p, strlen('/data')), '/'));
        } elseif (strpos($p, '/backup') === 0) {
            $browse = '/gear/settings/block-browser.php?root=backup&path=' . urlencode(ltrim(substr($p, strlen('/backup')), '/'));
        } elseif (strpos($p, '/vector') === 0) {
            $browse = '/gear/settings/block-browser.php?root=vector&path=' . urlencode(ltrim(substr($p, strlen('/vector')), '/'));
        } elseif (strpos($p, '/graph') === 0) {
            $browse = '/gear/settings/block-browser.php?root=graph&path=' . urlencode(ltrim(substr($p, strlen('/graph')), '/'));
        } elseif (strpos($p, '/mysql') === 0) {
            $browse = '/gear/settings/block-browser.php?root=mysql&path=' . urlencode(ltrim(substr($p, strlen('/mysql')), '/'));
        }
        $browseCell = ($browse !== '' && is_dir($p) && is_readable($p)) ? ('<a href="' . htmlspecialchars($browse, ENT_QUOTES) . '" target="_blank" rel="noopener" style="color:#00d4ff;text-decoration:none">Open</a>') : '';
        echo '<tr><td style="padding:8px">'.htmlspecialchars($m['label'], ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars($p, ENT_QUOTES).'</td><td style="padding:8px">'.$exists.'</td><td style="padding:8px">'.$w.'</td><td style="padding:8px">'.htmlspecialchars($free, ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars($total, ENT_QUOTES).'</td><td style="padding:8px">'.$browseCell.'</td></tr>';
    }
    $cfgRoot = $paths->getConfigPath();
    $files = [
        'db_configs.json' => $cfgRoot.'/db_configs.json',
        'database-contexts.json' => $cfgRoot.'/database-contexts.json',
        'tenant-contexts.json' => $cfgRoot.'/tenant-contexts.json',
        'persona-context.json' => $paths->getPersonaContextFile(),
        'meta_humans_context.json' => $paths->getMetaHumansContextFile(),
    ];
    echo '<tr><th colspan="7" style="padding-top:16px;text-align:left">Config Files</th></tr>';
    foreach ($files as $name => $fp) {
        $exists = file_exists($fp) ? 'Yes' : 'No';
        echo '<tr><td style="padding:8px" colspan="2">'.htmlspecialchars($name, ENT_QUOTES).'</td><td style="padding:8px" colspan="5">'.$exists.'</td></tr>';
    }

    $cueFiles = [
        'cue.php' => dirname(dirname(__DIR__)) . '/.cue/cue.php',
        'core.php' => dirname(dirname(__DIR__)) . '/.cue/core.php',
        'security.php' => dirname(dirname(__DIR__)) . '/.cue/security.php',
        'database.php' => dirname(dirname(__DIR__)) . '/.cue/database.php',
        'instructions.php' => dirname(dirname(__DIR__)) . '/.cue/instructions.php',
        'memory.php' => dirname(dirname(__DIR__)) . '/.cue/memory.php',
        'graph.php' => dirname(dirname(__DIR__)) . '/.cue/graph.php',
        'graphrag.php' => dirname(dirname(__DIR__)) . '/.cue/graphrag.php',
    ];
    echo '<tr><th colspan="7" style="padding-top:16px;text-align:left">CUE Modules</th></tr>';
    foreach ($cueFiles as $name => $fp) {
        $exists = file_exists($fp) ? 'Yes' : 'No';
        echo '<tr><td style="padding:8px" colspan="2">'.htmlspecialchars($name, ENT_QUOTES).'</td><td style="padding:8px" colspan="5">'.$exists.'</td></tr>';
    }

    $dbCfgPath = $cfgRoot . '/db_configs.json';
    $dbCfg = is_file($dbCfgPath) ? json_decode((string)file_get_contents($dbCfgPath), true) : null;
    if (is_array($dbCfg)) {
        echo '<tr><th colspan="7" style="padding-top:16px;text-align:left">DB Configs (Summary)</th></tr>';
        echo '<tr><th style="text-align:left;padding:8px" colspan="2">Name</th><th style="text-align:left;padding:8px">ID</th><th style="text-align:left;padding:8px">Type</th><th style="text-align:left;padding:8px">Port</th><th style="text-align:left;padding:8px">Storage Profile</th><th style="text-align:left;padding:8px">Active</th></tr>';
        $n = 0;
        foreach ($dbCfg as $id => $c) {
            if (!is_array($c)) continue;
            $name = is_string($c['name'] ?? null) ? (string)$c['name'] : (string)$id;
            $type = is_string($c['type'] ?? null) ? (string)$c['type'] : '';
            $port = is_string($c['port'] ?? null) ? (string)$c['port'] : '';
            $profile = is_string($c['storage_profile'] ?? null) ? (string)$c['storage_profile'] : '';
            $active = !empty($c['is_active']) ? 'Yes' : 'No';
            echo '<tr><td style="padding:8px" colspan="2">'.htmlspecialchars($name, ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars((string)$id, ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars($type, ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars($port, ENT_QUOTES).'</td><td style="padding:8px">'.htmlspecialchars($profile, ENT_QUOTES).'</td><td style="padding:8px">'.$active.'</td></tr>';
            $n++;
            if ($n >= 25) break;
        }
    }

    echo '<tr><th colspan="7" style="padding-top:16px;text-align:left">Vector DB Admin UI (Safe)</th></tr>';
    echo '<tr><td style="padding:8px" colspan="7">';
    echo '<div style="opacity:0.85;line-height:1.4">';
    echo '<div>Policy: do not connect admin UIs directly to Qdrant:6333. Use the internal gateway enforcement boundary.</div>';
    echo '<div>Run enforcement checks to confirm tenant filter requirements are active.</div>';
    echo '</div>';
    echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
    echo '<button type="button" id="mhVectorAdminTestBtn" style="padding:10px 14px;border-radius:10px;border:1px solid #2b899e;background:#0aa0b6;color:#fff;cursor:pointer">Run Vector Enforcement Tests</button>';
    echo '<span style="opacity:0.8;font-size:12px">See database-runbook.md: Vector DB Admin UI (Safe)</span>';
    echo '</div>';
    echo '<pre id="mhVectorAdminTestOut" style="margin-top:10px;white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,0.03);border:1px solid rgba(43,137,158,0.35);border-radius:12px;padding:12px;display:none"></pre>';
    echo '</td></tr>';

    echo '<tr><th colspan="7" style="padding-top:16px;text-align:left">Docs</th></tr>';
    echo '<tr><td style="padding:8px" colspan="2">Identifiers</td><td style="padding:8px" colspan="5"><a href="/gear/settings/id_identifiers.php" target="_blank" rel="noopener" style="color:#00d4ff;text-decoration:none">Open</a></td></tr>';
    echo '</table>';
    echo '</div>';
    echo '</section>';
?>
<script>
    (function () {
        const btn = document.getElementById('mhCreateContextFilesBtnTop');
        if (!btn) return;
        btn.addEventListener('click', async function () {
            try {
                const resp = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=create_context_files'
                });
                const data = await resp.json();
                if (data && data.success) {
                    if (typeof showToast === 'function') showToast('Context files ensured', 'success');
                    else alert('Context files ensured');
                    setTimeout(() => location.reload(), 500);
                    return;
                }
                const msg = (data && data.message) ? data.message : 'Failed';
                if (typeof showToast === 'function') showToast(msg, 'error');
                else alert(msg);
            } catch (e) {
                if (typeof showToast === 'function') showToast('Request failed', 'error');
                else alert('Request failed');
            }
        });
    })();
</script>
<script>
    (function () {
        const btn = document.getElementById('mhVectorAdminTestBtn');
        const out = document.getElementById('mhVectorAdminTestOut');
        if (!btn || !out) return;
        btn.addEventListener('click', async function () {
            out.style.display = 'block';
            out.textContent = 'Running...';
            try {
                const resp = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=vector_admin_enforcement_test'
                });
                const data = await resp.json();
                out.textContent = JSON.stringify(data, null, 2);
            } catch (e) {
                out.textContent = 'Request failed';
            }
        });
    })();
</script>
 </main>
 <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
 <?php
// Guard: Only inject icon CSS on normal page views (not AJAX)
if (!(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))) {
    if (empty($GLOBALS['_FA_LOADED'])) {
        $faCss = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/icons/fontawesome/css/all.min.css');
        if (file_exists($faCss)) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars((function_exists('getTemplateURL') ? getTemplateURL('assets/icons/fontawesome/css/all.min.css') : '/templates/assets/icons/fontawesome/css/all.min.css'), ENT_QUOTES) . '">';
            $GLOBALS['_FA_LOADED'] = true;
        } elseif (file_exists((function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/fonts/all.min.css') : (dirname(__DIR__, 2) . '/templates/assets/fonts/all.min.css')))) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars((function_exists('getTemplateURL') ? getTemplateURL('assets/fonts/all.min.css') : '/templates/assets/fonts/all.min.css'), ENT_QUOTES) . '">';
            $GLOBALS['_FA_LOADED'] = true;
        }
    }
    $metaUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB64.png') : '/templates/assets/images/branding/logo/MHlogoTB64.png';
    echo '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
    echo '<style>html,body{height:auto !important;display:block !important}body{flex-direction:initial !important}main.main-content{flex:none !important;min-height:auto !important}</style>';
}
?>
 </body>
 </html>
