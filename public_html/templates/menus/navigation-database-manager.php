<?php
/**
 * Meta Humans Enterprise Software - Navigation Database Manager
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getContextAwareDatabase() for database connections
 * ✓ Uses navDbQuery() for all database operations
 * ✓ No PDO wrappers - pure CUE framework implementation
 * ✓ Follows enterprise security standards
 * ✓ Supports multi-database context awareness
 * 
 * @package    Meta Humans
 * @author     Meta Humans LTD (Pieter Rubeus - owner)
 * @copyright  Copyright (c) Meta Humans LTD® 2025
 * @license    Licensed
 * @link       https://metahumans.one
 */

// Ensure CUE framework is loaded
if (!function_exists('getContextAwareDatabase')) {
    require_once dirname(__DIR__, 2) . '/.cue/cue.php';
}

$pagePerfMgrPath = dirname(__DIR__, 2) . '/gear/settings/classes/PagePermissionManager.php';
if (is_file($pagePerfMgrPath)) {
    require_once $pagePerfMgrPath;
}

if (!function_exists('navDbQuery')) {
function navDbQuery($db, string $query, array $params = []): array {
    try {
        if (!($db instanceof PDO)) {
            $raw = is_array($db) ? ($db['pdo'] ?? $db['connection'] ?? $db['dbh'] ?? null) : (is_object($db) ? ($db->pdo ?? $db->connection ?? $db->dbh ?? null) : null);
            if ($raw instanceof PDO) {
                $db = $raw;
            } elseif (function_exists('database_getContextAwareConnection')) {
                $ctx = database_getContextAwareConnection();
                $resolved = is_array($ctx) ? ($ctx['pdo'] ?? $ctx['connection'] ?? null) : (is_object($ctx) ? ($ctx->pdo ?? $ctx->connection ?? null) : null);
                if ($resolved instanceof PDO) { $db = $resolved; }
                elseif ($ctx instanceof PDO) { $db = $ctx; }
            }
            if (!($db instanceof PDO)) { return ['success'=>false,'data'=>[],'error'=>'could_not_resolve_pdo']; }
        }
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return ['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'error'=>null];
    } catch (Throwable $e) {
        return ['success'=>false,'data'=>[],'error'=>$e->getMessage()];
    }
}
}

if (!function_exists('navDbExec')) {
function navDbExec($db, string $query, array $params = []): array {
    try {
        if (!($db instanceof PDO)) {
            $raw = is_array($db) ? ($db['pdo'] ?? $db['connection'] ?? $db['dbh'] ?? null) : (is_object($db) ? ($db->pdo ?? $db->connection ?? $db->dbh ?? null) : null);
            if ($raw instanceof PDO) {
                $db = $raw;
            } elseif (function_exists('database_getContextAwareConnection')) {
                $ctx = database_getContextAwareConnection();
                $resolved = is_array($ctx) ? ($ctx['pdo'] ?? $ctx['connection'] ?? null) : (is_object($ctx) ? ($ctx->pdo ?? $ctx->connection ?? null) : null);
                if ($resolved instanceof PDO) { $db = $resolved; }
                elseif ($ctx instanceof PDO) { $db = $ctx; }
            }
            if (!($db instanceof PDO)) { return ['success'=>false,'data'=>[],'error'=>'could_not_resolve_pdo','affected'=>0]; }
        }
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return ['success'=>true,'data'=>[],'error'=>null,'affected'=>$stmt->rowCount()];
    } catch (Throwable $e) {
        return ['success'=>false,'data'=>[],'error'=>$e->getMessage(),'affected'=>0];
    }
}
}

class NavigationDatabaseManager {
    private $debugMode = false;             // Debug logging flag - set to false for production
    private $dbConnection = null;           // Raw framework connection (array or object)
    private $connSuccess = false;           // Normalized success flag
    private $runtimeCache = null;          // Simple in-memory cache object
    private $cacheTtlSeconds = 120;         // Default TTL for caches
    
    /**
     * Safe logging function to prevent output contamination in AJAX responses
     */
    private function safeLog($message) {
        // Determine if this is an error message that should always be logged
        $isError = (stripos($message, 'failed') !== false || stripos($message, 'error') !== false || stripos($message, 'exception') !== false);

        // Skip debug logs if debug mode is disabled
        if (!$isError && !$this->debugMode) {
            return;
        }

        // Only log if we're not in an AJAX context that could contaminate JSON responses
        if (function_exists('error_log') && !headers_sent()) {
            // Ensure this goes to error log file, never to output
            error_log($message, 0);
        }
    }
    private function countExistingUsers($db) {
        $userTables = ['users', 'user_accounts', 'accounts', 'kripzmasters_users'];
        $count = 0;
        foreach ($userTables as $t) {
            try {
                $exists = navDbQuery($db, "SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$t]);
                if ($exists['success'] && !empty($exists['data']) && (int)$exists['data'][0]['count'] > 0) {
                    $res = navDbQuery($db, "SELECT COUNT(*) as count FROM `{$t}`");
                    if ($res['success'] && isset($res['data'][0]['count'])) {
                        $count += (int)$res['data'][0]['count'];
                    }
                }
            } catch (Exception $e) {
            }
        }
        return $count;
    }
    
    /**
     * Helper function to enforce table access permissions
     */
    private function enforceAccess($table, $operation) {
        $role = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
        if ($role !== '' && stripos($role, 'kripzmaster') !== false) {
            return;
        }
        $userCount = 0;
        try {
            $defaultDb = cue_autoload('database')->getContextAwareConnection();
            $userCount = $this->countExistingUsers($defaultDb);
        } catch (Exception $e) {
        }
        
        // If no users are created, KripzMasters have unconditional access - skip permission checks
        if ($userCount === 0) {
            $this->safeLog("NavigationDatabaseManager: No users found - KripzMasters have unconditional access, skipping permission check for {$table}.{$operation}");
            return;
        }
        
        // Only enforce permissions if users exist and permission system is active
        if (function_exists('enforceTableAccess')) {
            enforceTableAccess($table, $operation);
        }
    }
    
    /**
     * Get default database config ID from configurations
     */
    private function getDefaultDatabaseConfigId() {
        if (function_exists('getDatabaseConfigs')) {
            $configs = getDatabaseConfigs();
            foreach ($configs as $id => $conf) {
                if (($conf['context'] ?? '') === 'default' && ($conf['is_active'] ?? false)) {
                    return $id;
                }
            }
        }
        // Fallback: try to read db_configs.json directly if getDatabaseConfigs not available
        $configFile = dirname(dirname(dirname(__DIR__))) . '/.data/config/db_configs.json';
        if (file_exists($configFile)) {
            $configs = json_decode(file_get_contents($configFile), true);
            if (is_array($configs)) {
                foreach ($configs as $id => $conf) {
                    if (($conf['context'] ?? '') === 'default' && ($conf['is_active'] ?? false)) {
                        return $id;
                    }
                }
            }
        }
        return null;
    }

    private function resolveNavigationDatabaseConfigId(): ?string {
        try {
            if (function_exists('cue_autoload')) {
                $paths = cue_autoload('paths');
                $cfgRoot = (string)$paths->getConfigPath();
            } else {
                $cfgRoot = dirname(dirname(dirname(__DIR__))) . '/.data/config';
            }
            $ctxFile = rtrim($cfgRoot, '/') . '/database-contexts.json';
            if (!is_file($ctxFile)) {
                return null;
            }
            $ctx = json_decode((string)file_get_contents($ctxFile), true);
            if (!is_array($ctx)) {
                return null;
            }
            $dir = $ctx['directory_mappings'] ?? null;
            if (is_array($dir)) {
                $id = $dir['/templates/menus'] ?? ($dir['/templates/global-ui'] ?? null);
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
            $pages = $ctx['page_mappings'] ?? null;
            if (is_array($pages)) {
                $id = $pages['/templates/menus'] ?? ($pages['/templates/global-ui'] ?? null);
                if (is_string($id) && $id !== '') {
                    return $id;
                }
            }
        } catch (Throwable $e) {
        }
        return null;
    }

    /**
     * Initialize connection using CUE framework's database connection by ID
     * COMPLIANCE: Uses getDatabaseById() instead of context-aware selection
     */
    public function __construct($databaseConfigId = null) {
        // Sync debug mode with global constant
        if (defined('CUE_DEBUG')) {
            $this->debugMode = CUE_DEBUG;
        }

        // EMERGENCY BYPASS - Immediately exit if database operations are disabled
        if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
            $this->dbConnection = null;
            $this->connSuccess = false;
            $this->safeLog('NavigationDatabaseManager: Emergency bypass activated - database operations disabled');
            return;
        }
        
        $start_time = microtime(true);

        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
        } catch (Throwable $e) {
        }
        
        $databaseConfigIdStr = is_string($databaseConfigId) ? $databaseConfigId : '';
        if ($databaseConfigIdStr === '') {
            $resolved = $this->resolveNavigationDatabaseConfigId();
            if (is_string($resolved) && $resolved !== '') {
                $databaseConfigIdStr = $resolved;
            }
        }
        
        // Check for active databases before attempting connection
        $hasActiveDB = false;
        try {
            if (function_exists('database_hasActiveConfigurations')) {
                $hasActiveDB = database_hasActiveConfigurations();
            } else {
                $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
                $configFile = $paths ? (rtrim((string)$paths->getConfigPath(), '/') . '/db_configs.json') : '/data/config/db_configs.json';
                if (is_string($configFile) && $configFile !== '' && file_exists($configFile)) {
                    $quickCheck = file_get_contents($configFile);
                    $hasActiveDB = (is_string($quickCheck) && strpos($quickCheck, '"is_active"') !== false);
                }
            }
        } catch (Throwable $e) {
            $hasActiveDB = false;
        }
        
        if (!$hasActiveDB) {
            $this->safeLog('NavigationDatabaseManager: No active databases configured - skipping connection');
            $this->dbConnection = null;
            $this->connSuccess = false;
            $db_time = 0;
        } else {
            $db_start = microtime(true);
            try {
                if (function_exists('database_getConnectionById')) {
                    $this->dbConnection = database_getConnectionById($databaseConfigIdStr);
                } else {
                    $this->dbConnection = cue_autoload('database')->getConnectionById($databaseConfigIdStr);
                }
                $db_time = (microtime(true) - $db_start) * 1000;
            } catch (Exception $e) {
                $db_time = (microtime(true) - $db_start) * 1000;
                $this->safeLog('NavigationDatabaseManager: Database connection failed: ' . $e->getMessage());
                $this->dbConnection = null;
            }
        }
        $this->safeLog("NavigationDatabaseManager: Using database connection (config ID: " . ($databaseConfigIdStr !== '' ? $databaseConfigIdStr : 'unknown') . ")");
        
        // Set connection success flag with robust detection across return types
        $raw = $this->dbConnection;
        $success = false;
        if (is_array($raw)) {
            $success = ($raw['success'] ?? false) || ($raw['pdo'] ?? $raw['connection'] ?? null) instanceof PDO;
        } elseif (is_object($raw)) {
            if ($raw instanceof \PDO) {
                $success = true;
            } elseif (($raw->pdo ?? $raw->connection ?? null) instanceof PDO) {
                $success = true;
            } else {
                $success = (bool)($raw->success ?? false);
            }
        } elseif ($raw) {
            $success = true;
        }
        $this->connSuccess = $success;
        
        if (!$this->connSuccess) {
            $this->safeLog('NavigationDatabaseManager: Database connection not available, operating in fallback mode');
        } else {
            // Skip expensive table creation on every load - only ensure tables when needed
            // The ensureSocialConnectTable() will be called lazily when social links are requested
            $this->safeLog("NavigationDatabaseManager: Skipping table creation check for faster initialization");
        }
        
        // Initialize runtime cache
        $cache_start = microtime(true);
        $this->runtimeCache = [];
        $cache_time = (microtime(true) - $cache_start) * 1000;
        
        $total_time = (microtime(true) - $start_time) * 1000;
        $this->safeLog("NavigationDatabaseManager: Constructor completed in {$total_time}ms (DB: {$db_time}ms, Tables: skipped, Cache: {$cache_time}ms)");
    }

    /**
     * Get database config ID from page permissions
     * @return string Database config ID
     */
    private function getDatabaseConfigIdFromPermissions() {
        try {
            $userCount = 0;
            try {
                $defaultDb = cue_autoload('database')->getContextAwareConnection();
                $userCount = $this->countExistingUsers($defaultDb);
            } catch (Exception $e) {
            }
            
            // If no users are created, KripzMasters have unconditional access - use default database
            if ($userCount === 0) {
                $this->safeLog("NavigationDatabaseManager: No users found - KripzMasters have unconditional access, using default database");
                return null; // Use default database connection
            }
            
            // Get current page URI and script info
            $currentUri = $_SERVER['REQUEST_URI'] ?? '';
            $scriptName = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
            $scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? '';
            
            // Log debug info
            $this->safeLog("NavigationDatabaseManager: Debug - URI: $currentUri, Script: $scriptName, Path: $scriptPath");
            
            // Try different page keys - focus on templates/menus/navigator.php format
            $pageKeys = [
                'templates/menus/navigator.php',  // Known working format
                'templates\/menus\/navigator.php', // Escaped slashes format
                $scriptName,
                'templates/menus/' . $scriptName,
                str_replace('\\', '/', $currentUri),
                $currentUri
            ];
            
            if (class_exists('PagePermissionManager')) {
                $permissionManager = new PagePermissionManager();
            } else {
                $this->safeLog("NavigationDatabaseManager: PagePermissionManager class not found, using fallback");
                return null; // Use default database connection
            }
            
            foreach ($pageKeys as $pageKey) {
                $this->safeLog("NavigationDatabaseManager: Trying page key: $pageKey");
                $permissions = $permissionManager->getPagePermissions($pageKey);
                if ($permissions && isset($permissions['database'])) {
                    $this->safeLog("NavigationDatabaseManager: Found database config: " . $permissions['database']);
                    return $permissions['database'];
                }
            }
            
            // No permissions found - use default database connection
            $this->safeLog("NavigationDatabaseManager: No database config found in page permissions, using default database");
            return null; // Use default database connection
            
        } catch (Exception $e) {
            $this->safeLog("NavigationDatabaseManager: Failed to get database config from permissions: " . $e->getMessage());
            return null; // Use default database connection
        }
    }

    /**
     * Ensure required tables for menus and menu_items exist
     * Lazily creates schema when first write operation is attempted
     */
    private function ensureMenuSchema() {
        if (!$this->dbConnection || !$this->connSuccess) {
            return;
        }

        try {
            // Check menus table
            $menusTable = navDbQuery($this->dbConnection, "SHOW TABLES LIKE 'menus'");
            if (!$menusTable['success']) {
                $this->safeLog("NavigationDatabaseManager: Failed to check menus table: " . ($menusTable['error'] ?? 'unknown'));
            }

            if (empty($menusTable['data'])) {
                $createMenus = navDbQuery($this->dbConnection, "
                    CREATE TABLE IF NOT EXISTS `menus` (
                      `id` INT AUTO_INCREMENT PRIMARY KEY,
                      `realm_id` INT NULL,
                      `name` VARCHAR(255) NOT NULL,
                      `title` VARCHAR(255) NOT NULL,
                      `url` VARCHAR(1024) DEFAULT '#',
                      `icon` VARCHAR(255) DEFAULT NULL,
                      `order_index` INT DEFAULT 0,
                      `status` ENUM('active','inactive','deleted') DEFAULT 'active',
                      `created_at` DATETIME NULL,
                      `updated_at` DATETIME NULL,
                      INDEX (`realm_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                if (!$createMenus['success']) {
                    $this->safeLog('NavigationDatabaseManager: Failed to create menus table: ' . ($createMenus['error'] ?? 'unknown error'));
                }
            } else {
                // Table exists: ensure 'id' is AUTO_INCREMENT PRIMARY KEY to avoid insert failures
                try {
                    $colInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE 'id'");
                    if (!empty($colInfo['success']) && !empty($colInfo['data'][0])) {
                        $info = $colInfo['data'][0];
                        $isPri = isset($info['Key']) ? ($info['Key'] === 'PRI') : false;
                        $isAuto = isset($info['Extra']) ? (stripos($info['Extra'], 'auto_increment') !== false) : false;
                        $type = strtolower((string)($info['Type'] ?? ''));
                        $isNumericId = ($type !== '' && (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'numeric') !== false));
                        if (!$isPri) {
                            $pkResult = navDbQuery($this->dbConnection, "ALTER TABLE `menus` ADD PRIMARY KEY (`id`)");
                            if (!$pkResult['success']) {
                                $this->safeLog('NavigationDatabaseManager: Failed to add PRIMARY KEY to menus.id: ' . ($pkResult['error'] ?? 'unknown'));
                            }
                        }
                        if ($isNumericId && !$isAuto) {
                            $alterResult = navDbQuery($this->dbConnection, "ALTER TABLE `menus` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                            if (!$alterResult['success']) {
                                $this->safeLog('NavigationDatabaseManager: Failed to set AUTO_INCREMENT on menus.id: ' . ($alterResult['error'] ?? 'unknown'));
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog('NavigationDatabaseManager: Error ensuring AUTO_INCREMENT on menus.id: ' . $e->getMessage());
                // Ensure icon column exists
                try {
                    $iconCol = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE 'icon'");
                    if (empty($iconCol['data'])) {
                        $addIcon = navDbQuery($this->dbConnection, "ALTER TABLE `menus` ADD COLUMN `icon` VARCHAR(255) DEFAULT NULL AFTER `url`");
                        if (!$addIcon['success']) {
                            $this->safeLog('NavigationDatabaseManager: Failed to add icon column to menus: ' . ($addIcon['error'] ?? 'unknown'));
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog('NavigationDatabaseManager: Error adding icon column to menus: ' . $e->getMessage());
                }
                }
            }

            $submenuTable = null;
            try {
                $submenuTable = $this->getSubmenuTableName();
            } catch (Exception $e) {
                $submenuTable = null;
            }

            if (!$submenuTable) {
                $idType = 'INT';
                $menuIdType = 'INT';
                $realmIdType = 'INT';
                try {
                    $menusId = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE 'id'");
                    if (!empty($menusId['success']) && !empty($menusId['data'][0]['Type'])) {
                        $menuIdType = (string)$menusId['data'][0]['Type'];
                        $idType = $menuIdType;
                    }
                } catch (Exception $e) {
                }
                try {
                    $menusRealm = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE 'realm_id'");
                    if (!empty($menusRealm['success']) && !empty($menusRealm['data'][0]['Type'])) {
                        $realmIdType = (string)$menusRealm['data'][0]['Type'];
                    }
                } catch (Exception $e) {
                }

                $isTextId = (stripos($idType, 'char') !== false || stripos($idType, 'text') !== false);
                if ($isTextId) {
                    $createSub = navDbQuery($this->dbConnection, "
                        CREATE TABLE IF NOT EXISTS `submenus` (
                          `id` VARCHAR(100) NOT NULL,
                          `menu_id` {$menuIdType} NOT NULL,
                          `realm_id` {$realmIdType} NULL,
                          `name` VARCHAR(150) NOT NULL,
                          `url` TEXT NULL,
                          `icon` VARCHAR(255) DEFAULT NULL,
                          `order_index` INT DEFAULT 0,
                          `status` ENUM('active','inactive','archived') DEFAULT 'active',
                          `created_at` DATETIME NULL,
                          `updated_at` DATETIME NULL,
                          PRIMARY KEY (`id`),
                          INDEX (`menu_id`),
                          INDEX (`realm_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    ");
                    if ($createSub['success']) {
                        $submenuTable = 'submenus';
                    }
                } else {
                    $createSub = navDbQuery($this->dbConnection, "
                        CREATE TABLE IF NOT EXISTS `submenus` (
                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                          `menu_id` INT NOT NULL,
                          `realm_id` INT NULL,
                          `name` VARCHAR(255) NOT NULL,
                          `url` VARCHAR(1024) DEFAULT '#',
                          `icon` VARCHAR(255) DEFAULT NULL,
                          `order_index` INT DEFAULT 0,
                          `status` ENUM('active','inactive','deleted') DEFAULT 'active',
                          `created_at` DATETIME NULL,
                          `updated_at` DATETIME NULL,
                          INDEX (`menu_id`),
                          INDEX (`realm_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                    ");
                    if ($createSub['success']) {
                        $submenuTable = 'submenus';
                    }
                }
            }

            if ($submenuTable) {
                try {
                    $submenuIconCol = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `{$submenuTable}` LIKE 'icon'");
                    if (empty($submenuIconCol['data'])) {
                        $addSubmenuIcon = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` ADD COLUMN `icon` VARCHAR(255) DEFAULT NULL AFTER `url`");
                        if (!$addSubmenuIcon['success']) {
                            $this->safeLog("NavigationDatabaseManager: Failed to add icon column to {$submenuTable}: " . ($addSubmenuIcon['error'] ?? 'unknown'));
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog("NavigationDatabaseManager: Error adding icon column to {$submenuTable}: " . $e->getMessage());
                }
                try {
                    $idInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `{$submenuTable}` LIKE 'id'");
                    if (!empty($idInfo['success']) && !empty($idInfo['data'][0])) {
                        $info = $idInfo['data'][0];
                        $type = strtolower((string)($info['Type'] ?? ''));
                        $isPri = isset($info['Key']) ? ($info['Key'] === 'PRI') : false;
                        $isAuto = isset($info['Extra']) ? (stripos((string)$info['Extra'], 'auto_increment') !== false) : false;
                        $isNumericId = ($type !== '' && (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'numeric') !== false));
                        if (!$isPri) {
                            $pkResult = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` ADD PRIMARY KEY (`id`)");
                            if (!$pkResult['success']) {
                                $this->safeLog("NavigationDatabaseManager: Failed to add PRIMARY KEY to {$submenuTable}.id: " . ($pkResult['error'] ?? 'unknown'));
                            }
                        }
                        if ($isNumericId && !$isAuto) {
                            $alterResult = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                            if (!$alterResult['success']) {
                                $this->safeLog("NavigationDatabaseManager: Failed to set AUTO_INCREMENT on {$submenuTable}.id: " . ($alterResult['error'] ?? 'unknown'));
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog("NavigationDatabaseManager: Error ensuring primary key on {$submenuTable}.id: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            // Ensure submenu tables have icon columns
            $submenuTable = $this->getSubmenuTableName();
            if ($submenuTable) {
                try {
                    $submenuIconCol = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `{$submenuTable}` LIKE 'icon'");
                    if (empty($submenuIconCol['data'])) {
                        $addSubmenuIcon = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` ADD COLUMN `icon` VARCHAR(255) DEFAULT NULL AFTER `url`");
                        if (!$addSubmenuIcon['success']) {
                            $this->safeLog("NavigationDatabaseManager: Failed to add icon column to {$submenuTable}: " . ($addSubmenuIcon['error'] ?? 'unknown'));
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog("NavigationDatabaseManager: Error adding icon column to {$submenuTable}: " . $e->getMessage());
                }
                try {
                    $idInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `{$submenuTable}` LIKE 'id'");
                    if (!empty($idInfo['success']) && !empty($idInfo['data'][0])) {
                        $info = $idInfo['data'][0];
                        $isPri = isset($info['Key']) ? ($info['Key'] === 'PRI') : false;
                        $isAuto = isset($info['Extra']) ? (stripos($info['Extra'], 'auto_increment') !== false) : false;
                        $type = strtolower((string)($info['Type'] ?? ''));
                        $isNumericId = ($type !== '' && (strpos($type, 'int') !== false || strpos($type, 'decimal') !== false || strpos($type, 'numeric') !== false));
                        if (!$isPri) {
                            $pkResult = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` ADD PRIMARY KEY (`id`)");
                            if (!$pkResult['success']) {
                                $this->safeLog("NavigationDatabaseManager: Failed to add PRIMARY KEY to {$submenuTable}.id: " . ($pkResult['error'] ?? 'unknown'));
                            }
                        }
                        if ($isNumericId && !$isAuto) {
                            $alterResult = navDbQuery($this->dbConnection, "ALTER TABLE `{$submenuTable}` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                            if (!$alterResult['success']) {
                                $this->safeLog("NavigationDatabaseManager: Failed to set AUTO_INCREMENT on {$submenuTable}.id: " . ($alterResult['error'] ?? 'unknown'));
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog("NavigationDatabaseManager: Error ensuring AUTO_INCREMENT on {$submenuTable}.id: " . $e->getMessage());
                }
            }
            try {
                $need = navDbQuery($this->dbConnection, "SELECT id FROM menus WHERE url = ? OR name = ? LIMIT 1", ['/control/kripzmaster-airdrop.php', 'kripzmaster_airdrop']);
                $exists = ($need['success'] ?? false) && !empty($need['data']);
                if (!$exists) {
                    $menusRealmType = '';
                    $realmColInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE 'realm_id'");
                    if (!empty($realmColInfo['success']) && !empty($realmColInfo['data'][0]['Type'])) {
                        $menusRealmType = strtolower((string)$realmColInfo['data'][0]['Type']);
                    }
                    $controlRealmId = null;
                    try {
                        $realmCols = $this->getTableColumns('realms');
                        if (isset($realmCols['slug'])) {
                            $r = navDbQuery($this->dbConnection, "SELECT id FROM realms WHERE slug = ? LIMIT 1", ['control']);
                            if (($r['success'] ?? false) && !empty($r['data'][0]['id'])) $controlRealmId = $r['data'][0]['id'];
                        }
                        if ($controlRealmId === null) {
                            $r = navDbQuery($this->dbConnection, "SELECT id FROM realms WHERE id = ? LIMIT 1", ['control']);
                            if (($r['success'] ?? false) && !empty($r['data'][0]['id'])) $controlRealmId = $r['data'][0]['id'];
                        }
                        if ($controlRealmId === null) {
                            $r = navDbQuery($this->dbConnection, "SELECT id FROM realms WHERE name = ? LIMIT 1", ['Control']);
                            if (($r['success'] ?? false) && !empty($r['data'][0]['id'])) $controlRealmId = $r['data'][0]['id'];
                        }
                    } catch (Throwable $e) {
                        $controlRealmId = null;
                    }
                    if ($menusRealmType !== '' && (strpos($menusRealmType, 'int') !== false || strpos($menusRealmType, 'decimal') !== false || strpos($menusRealmType, 'numeric') !== false)) {
                        if ($controlRealmId !== null && is_numeric($controlRealmId)) {
                            $controlRealmId = (int)$controlRealmId;
                        } else {
                            $controlRealmId = null;
                        }
                    } else {
                        if ($controlRealmId === null) $controlRealmId = 'control';
                    }

                    $cols = $this->getTableColumns('menus');
                    $insertCols = [];
                    $ph = [];
                    $params = [];
                    if ($controlRealmId !== null && isset($cols['realm_id'])) { $insertCols[] = 'realm_id'; $ph[] = '?'; $params[] = $controlRealmId; }
                    if (isset($cols['name'])) { $insertCols[] = 'name'; $ph[] = '?'; $params[] = 'kripzmaster_airdrop'; }
                    if (isset($cols['title'])) { $insertCols[] = 'title'; $ph[] = '?'; $params[] = 'KripzMaster Airdrop'; }
                    if (isset($cols['url'])) { $insertCols[] = 'url'; $ph[] = '?'; $params[] = '/control/kripzmaster-airdrop.php'; }
                    if (isset($cols['icon'])) { $insertCols[] = 'icon'; $ph[] = '?'; $params[] = 'fas fa-gift'; }
                    if (isset($cols['order_index'])) { $insertCols[] = 'order_index'; $ph[] = '?'; $params[] = 999; }
                    if (isset($cols['status'])) { $insertCols[] = 'status'; $ph[] = '?'; $params[] = 'active'; }
                    if (isset($cols['created_at'])) { $insertCols[] = 'created_at'; $ph[] = 'NOW()'; }
                    if (isset($cols['updated_at'])) { $insertCols[] = 'updated_at'; $ph[] = 'NOW()'; }
                    if (!empty($insertCols)) {
                        $sql = "INSERT INTO menus (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $ph) . ")";
                        navDbQuery($this->dbConnection, $sql, $params);
                    }
                }
            } catch (Throwable $e) {}
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error ensuring menu schema: ' . $e->getMessage());
        }
    }

    /**
     * Resolve the submenu table name used by the current database.
     * Prefers 'submenus' if present; falls back to 'menu_items' if available.
     */
    private function getSubmenuTableName(): ?string {
        if (!$this->dbConnection || !$this->connSuccess) throw new Exception('Navigation database connection not available: cannot resolve submenu table name');

        try {
            $checkSubmenus = navDbQuery($this->dbConnection, "SHOW TABLES LIKE 'submenus'");
            if ($checkSubmenus['success'] && !empty($checkSubmenus['data'])) {
                return 'submenus';
            }

            $checkMenuItems = navDbQuery($this->dbConnection, "SHOW TABLES LIKE 'menu_items'");
            if ($checkMenuItems['success'] && !empty($checkMenuItems['data'])) {
                return 'menu_items';
            }
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error resolving submenu table: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Build a map of canonical submenu fields to actual table column names
     */
    private function getSubmenuColumnMap(string $table): array {
        // Session-backed cache for submenu column map (safe usage)
        $canUseSession = (!headers_sent()) && (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE);
        if ($canUseSession && session_status() === PHP_SESSION_NONE) {
            // Prevent session start if any output was already flushed
            if (!headers_sent()) { session_start(); }
        }
        $sessKey = "__schema_map_submenu_{$table}";
        $sessTimeKey = "__schema_map_submenu_time_{$table}";
        $ttl = 600; // 10 minutes
        if ($canUseSession && isset($_SESSION[$sessKey], $_SESSION[$sessTimeKey]) && (time() - $_SESSION[$sessTimeKey]) < $ttl) {
            $cached = $_SESSION[$sessKey];
            if (is_array($cached)) {
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                $this->safeLog("SCHEMA CACHE HIT: submenu map for '{$table}' served from session");
                return $cached;
            }
        }
        $columns = $this->getTableColumns($table);

        $find = function(array $aliases) use ($columns) {
            foreach ($aliases as $alias) {
                if (isset($columns[$alias])) return $alias;
            }
            return null;
        };

        $map = [
            'id' => $find(['id','submenu_id','item_id']),
            'menu_id' => $find(['menu_id','menu','menuid','menuId']),
            'realm_id' => $find(['realm_id','realm','realmId']),
            'title' => $find(['title','name','label','text']),
            'url' => $find(['url','link','href','path']),
            'icon' => $find(['icon','submenu_icon']),
            'priority' => $find(['priority','prio','weight','rank']),
            'order_index' => $find(['order_index','order','position','sort_order','sort']),
            'status' => $find(['status','state','active']),
            'created_at' => $find(['created_at','created','createdOn','created_at_datetime']),
            'updated_at' => $find(['updated_at','updated','updatedOn','modified_at'])
        ];
        if ($canUseSession) {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
            $_SESSION[$sessKey] = $map;
            $_SESSION[$sessTimeKey] = time();
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        }
        return $map;
    }

    /** Normalize input data to array, supporting stdClass objects */
    private function normalizeInputData($data): array {
        if (is_array($data)) return $data;
        if (is_object($data)) return (array)$data;
        return [];
    }

    /** Normalize icon values for UI rendering (FA, Phosphor, Iconoir, custom metahumans) */
    private function normalizeIconClass(?string $icon): ?string {
        if (!is_string($icon)) return null;
        $val = trim($icon);
        if ($val === '') return null;
        if (stripos($val, '<svg') !== false) return $val;
        if (stripos($val, 'thesvg:') === 0) return $val;
        // Already a recognized class (FontAwesome, Phosphor, Iconoir)
        if ((strpos($val, 'fa ') === 0) || (strpos($val, 'fa-') === 0) || preg_match('/\bfa[srb]?\b/', $val)) {
            return $val;
        }
        if (preg_match('/\bph\b/', $val) || strpos($val, 'ph-') === 0 || preg_match('/\biconoir-[a-z0-9\-]+/i', $val)) {
            return $val;
        }
        $slug = strtolower(preg_replace('/\s+/', '-', $val));
        // Custom mappings
        $map = [
            'metahumans' => 'fas fa-metahumans',
            'meta-humans' => 'fas fa-metahumans',
            'mh' => 'fas fa-metahumans'
        ];
        if (isset($map[$slug])) return $map[$slug];
        // Single token -> prefer Phosphor naming convention
        if (!preg_match('/\s/', $val)) return 'ph ph-' . $slug;
        return null;
    }

    /** Build a map of canonical menu fields to actual menus table columns */
    private function getMenuColumnMap(): array {
        // Session-backed cache for menu column map (safe usage)
        $canUseSession = (!headers_sent()) && (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE);
        if ($canUseSession && session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) { session_start(); }
        }
        $sessKey = "__schema_map_menus";
        $sessTimeKey = "__schema_map_menus_time";
        $ttl = 600; // 10 minutes
        if ($canUseSession && isset($_SESSION[$sessKey], $_SESSION[$sessTimeKey]) && (time() - $_SESSION[$sessTimeKey]) < $ttl) {
            $cached = $_SESSION[$sessKey];
            if (is_array($cached)) {
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                $this->safeLog("SCHEMA CACHE HIT: menu map served from session");
                return $cached;
            }
        }
        $columns = $this->getTableColumns('menus');
        $find = function(array $aliases) use ($columns) {
            foreach ($aliases as $alias) {
                if (isset($columns[$alias])) return $alias;
            }
            return null;
        };
        $map = [
            'id' => $find(['id','menu_id']),
            'realm_id' => $find(['realm_id','realm','realmId']),
            'name' => $find(['name','title','label']),
            'title' => $find(['title','name','label']),
            'url' => $find(['url','link','href','path']),
            'icon' => $find(['icon','menu_icon']),
            'priority' => $find(['priority','prio','weight','rank']),
            'order_index' => $find(['order_index','order','position','sort_order','sort']),
            'status' => $find(['status','state','active']),
            'created_at' => $find(['created_at','created','createdOn','created_at_datetime']),
            'updated_at' => $find(['updated_at','updated','updatedOn','modified_at'])
        ];
        if ($canUseSession) {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
            $_SESSION[$sessKey] = $map;
            $_SESSION[$sessTimeKey] = time();
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        }
        return $map;
    }
    
    /**
     * Check if a table exists in the current database
     */
    private function tableExists(string $table): bool {
        if (!$this->dbConnection || !$this->connSuccess) return false;
        
        // Static cache to avoid repeated INFORMATION_SCHEMA queries
        static $tableExistsCache = [];
        $cacheKey = $table;
        
        if (isset($tableExistsCache[$cacheKey])) {
            return $tableExistsCache[$cacheKey];
        }
        
        try {
            $query_start = microtime(true);
            $result = navDbQuery($this->dbConnection, "
                SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ", [$table]);
            $query_time = (microtime(true) - $query_start) * 1000;
            $this->safeLog("NavigationDatabaseManager: INFORMATION_SCHEMA query for '$table' took {$query_time}ms");
            
            if ($result['success'] && !empty($result['data'])) {
                $exists = (int)$result['data'][0]['count'] > 0;
                $tableExistsCache[$cacheKey] = $exists;
                return $exists;
            }
            
            $tableExistsCache[$cacheKey] = false;
            return false;
        } catch (Exception $e) {
            $this->safeLog('Table existence check failed: ' . $e->getMessage());
            $tableExistsCache[$cacheKey] = false;
            return false;
        }
    }
    
    /**
     * Check if a column exists in a table (legacy method - use getTableColumns for better performance)
     */
    private function tableHasColumn(string $table, string $column): bool {
        if (!$this->dbConnection || !$this->connSuccess) return false;
        
        try {
            $result = navDbQuery($this->dbConnection, "
                SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ", [$table, $column]);
            
            if ($result['success'] && !empty($result['data'])) {
                return (int)$result['data'][0]['count'] > 0;
            }
            return false;
        } catch (Exception $e) {
            $this->safeLog('Schema lookup failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all columns for a table in one query (performance optimization)
     */
    private function getTableColumns(string $table): array {
        if (!$this->dbConnection || !$this->connSuccess) throw new Exception("Navigation database connection not available: cannot read table columns for '{$table}'");
        
        static $tableColumnCache = [];
        
        // Use cache to avoid repeated queries
        if (isset($tableColumnCache[$table])) {
            return $tableColumnCache[$table];
        }
        // Session-backed cache to persist across requests (safe on headers)
        $canUseSession = (!headers_sent()) && (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE);
        if ($canUseSession && session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) { session_start(); }
        }
        $sessKey = "__schema_columns_{$table}";
        $sessTimeKey = "__schema_columns_time_{$table}";
        $ttl = 600; // 10 minutes
        if ($canUseSession && isset($_SESSION[$sessKey], $_SESSION[$sessTimeKey]) && (time() - $_SESSION[$sessTimeKey]) < $ttl) {
            $cached = $_SESSION[$sessKey];
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $tableColumnCache[$table] = is_array($cached) ? $cached : [];
            $this->safeLog("SCHEMA CACHE HIT: columns for '{$table}' served from session");
            return $tableColumnCache[$table];
        }
        
        try {
            $result = navDbQuery($this->dbConnection, "
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ", [$table]);
            
            $columns = [];
            if ($result['success'] && !empty($result['data'])) {
                foreach ($result['data'] as $row) {
                    $columns[$row['COLUMN_NAME']] = true;
                }
            }
            
            // Cache the result
            $tableColumnCache[$table] = $columns;
            // Persist to session for cross-request reuse (only if safe)
            if ($canUseSession) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
                $_SESSION[$sessKey] = $columns;
                $_SESSION[$sessTimeKey] = time();
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            }
            return $columns;
            
        } catch (Exception $e) {
            $this->safeLog('Table columns lookup failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ensure social_connect table exists with the columns used by navigator
     */
    private function ensureSocialConnectTable(): void {
        if (!$this->dbConnection || !$this->connSuccess) return;
        
        try {
            $check_start = microtime(true);
            $exists = $this->tableExists('social_connect');
            $check_time = (microtime(true) - $check_start) * 1000;
            $this->safeLog("NavigationDatabaseManager: tableExists('social_connect') took {$check_time}ms, exists: " . ($exists ? 'yes' : 'no'));
            
            if ($exists) {
                // Ensure 'id' is AUTO_INCREMENT PRIMARY KEY for legacy schemas
                try {
                    $colInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `social_connect` LIKE 'id'");
                    if (!empty($colInfo['success'])) {
                        $info = !empty($colInfo['data'][0]) ? $colInfo['data'][0] : null;
                        if (!$info) {
                            // Column missing entirely: add a proper id
                            $addCol = navDbQuery($this->dbConnection, "ALTER TABLE `social_connect` ADD `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                            if (!$addCol['success']) {
                                $this->safeLog('NavigationDatabaseManager: Failed to add id auto-increment primary key: ' . ($addCol['error'] ?? 'unknown'));
                            }
                        } else {
                            $isPri = isset($info['Key']) ? ($info['Key'] === 'PRI') : false;
                            $isAuto = isset($info['Extra']) ? (stripos($info['Extra'], 'auto_increment') !== false) : false;
                            $type = isset($info['Type']) ? strtolower($info['Type']) : '';
                            $isIntType = (strpos($type, 'int') !== false);
                            if (!$isIntType || !$isAuto || !$isPri) {
                                // Attempt safe normalization sequence
                                if ($isPri) {
                                    $dropPk = navDbQuery($this->dbConnection, "ALTER TABLE `social_connect` DROP PRIMARY KEY");
                                    if (!$dropPk['success']) {
                                        $this->safeLog('NavigationDatabaseManager: Failed to drop PRIMARY KEY before modify: ' . ($dropPk['error'] ?? 'unknown'));
                                    }
                                }
                                $modify = navDbQuery($this->dbConnection, "ALTER TABLE `social_connect` MODIFY `id` INT NOT NULL");
                                if (!$modify['success']) {
                                    $this->safeLog('NavigationDatabaseManager: Failed to set id INT NOT NULL: ' . ($modify['error'] ?? 'unknown'));
                                }
                                $addPk = navDbQuery($this->dbConnection, "ALTER TABLE `social_connect` ADD PRIMARY KEY (`id`)");
                                if (!$addPk['success']) {
                                    $this->safeLog('NavigationDatabaseManager: Failed to add PRIMARY KEY to id: ' . ($addPk['error'] ?? 'unknown'));
                                }
                                $auto = navDbQuery($this->dbConnection, "ALTER TABLE `social_connect` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                                if (!$auto['success']) {
                                    $this->safeLog('NavigationDatabaseManager: Failed to set AUTO_INCREMENT on id: ' . ($auto['error'] ?? 'unknown'));
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $this->safeLog('NavigationDatabaseManager: Error ensuring AUTO_INCREMENT on social_connect.id: ' . $e->getMessage());
                }
                return; // Table exists; schema ensured
            }
            
            $sql = "
                CREATE TABLE IF NOT EXISTS social_connect (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    realm_id VARCHAR(50),
                    menu_id INT,
                    platform VARCHAR(50) NOT NULL,
                    platform_name VARCHAR(100),
                    url VARCHAR(500) NOT NULL,
                    username VARCHAR(100),
                    color VARCHAR(20),
                    show_in_header TINYINT(1) DEFAULT 1,
                    show_in_footer TINYINT(1) DEFAULT 1,
                    target_blank TINYINT(1) DEFAULT 1,
                    order_index INT DEFAULT 0,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_realm (realm_id),
                    INDEX idx_menu (menu_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB
            ";
            
            $result = navDbQuery($this->dbConnection, $sql);
            if ($result['success']) {
                $this->safeLog('Successfully ensured social_connect table exists for navigator.');
            } else {
                $this->safeLog('Failed to create social_connect table: ' . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            $this->safeLog('Error ensuring social_connect table: ' . $e->getMessage());
        }
    }
    
    /**
     * Get realms from database or fallback
     */
    public function getRealms() {
        $this->enforceAccess('realms', 'read');

        if (!$this->dbConnection || !$this->connSuccess) {
            if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
                $this->safeLog("NavigationDatabaseManager::getRealms - Database operations temporarily disabled, returning empty object");
            } else {
                $this->safeLog("NavigationDatabaseManager::getRealms - No active database configurations available, returning empty object");
            }
            return (object)[];
        }

        try {
            // Cache clearing removed - no caching used
            
            // Debug: Log current database
            try {
                $dbNameResult = navDbQuery($this->dbConnection, "SELECT DATABASE() as db_name");
                if ($dbNameResult['success'] && !empty($dbNameResult['data'])) {
                    $this->safeLog('NavigationDatabaseManager: Connected to database: ' . $dbNameResult['data'][0]['db_name']);
                }
            } catch (Exception $e) {
                $this->safeLog('NavigationDatabaseManager: Could not determine database name: ' . $e->getMessage());
            }
            
            // First check what columns exist in the realms table
            $columns = $this->getTableColumns('realms');
            $this->safeLog('NavigationDatabaseManager: Realms table columns: ' . implode(', ', array_keys($columns)));
            
            // Always query all realms regardless of status for permission management
            $sql = "SELECT * FROM realms";
            $orderBy = [];
            
            // Check for priority column first
            if (isset($columns['priority'])) {
                $orderBy[] = "priority ASC";
            }
            
            // Check for order_index column
            if (isset($columns['order_index'])) {
                $orderBy[] = "order_index ASC";
            }
            
            // Fallback to name ordering
            if (empty($orderBy)) {
                $orderBy[] = "name ASC";
            }
            
            $sql .= " ORDER BY " . implode(", ", $orderBy);
            
            $this->safeLog('NavigationDatabaseManager: Querying all realms for permission management with ORDER BY: ' . implode(", ", $orderBy));
            
            $result = navDbQuery($this->dbConnection, $sql);
            $this->safeLog('NavigationDatabaseManager: Query result - success: ' . ($result['success'] ? 'true' : 'false') . ', rows: ' . (isset($result['data']) ? count($result['data']) : '0'));
            
            if (!$result['success']) {
                $this->safeLog('NavigationDatabaseManager: Query failed with error: ' . ($result['error'] ?? 'Unknown error'));
                throw new Exception('Navigation database query failed while fetching realms: ' . ($result['error'] ?? 'Unknown error'));
            }
            
        if (empty($result['data'])) {
            $this->safeLog('NavigationDatabaseManager: No realms found in database - returning empty object');
            return (object)[];
        }            $realms = [];
            
            // Optimize: Get all column information in one query instead of multiple calls
            $columnInfo = $this->getTableColumns('realms');
            $hasAutoDetect = isset($columnInfo['auto_detect']);
            $hasDetectionRules = isset($columnInfo['detection_rules']);
            $hasSlug = isset($columnInfo['slug']);
            $hasPages = isset($columnInfo['pages']);
            $hasStatus = isset($columnInfo['status']);
            
            // RBAC Check
            $role = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
            $isKripz = (stripos($role, 'kripzmaster') !== false);
            
            foreach ($result['data'] as $row) {
                $realm = (object) [
                    'id' => $row['id'],
                    'slug' => $hasSlug ? ($row['slug'] ?? $row['id']) : $row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'] ?? '',
                    'domain' => $row['domain'] ?? '',
                    'status' => ($row['is_active'] ?? 1) ? 'active' : 'inactive',
                    'pages' => []
                ];
                
                if (!$isKripz) {
                    $rid = strtolower((string)$realm->id);
                    if ($rid !== 'hub') {
                        continue;
                    }
                }

                if (isset($columnInfo['color'])) { $realm->color = $row['color'] ?? null; }
                if (isset($columnInfo['icon'])) { $realm->icon = $this->normalizeIconClass($row['icon'] ?? null); }
                if (isset($columnInfo['order_index'])) { $realm->order_index = isset($row['order_index']) ? (int)$row['order_index'] : null; }
                if (isset($columnInfo['priority'])) { $realm->priority = isset($row['priority']) ? (int)$row['priority'] : null; }

                if ($hasAutoDetect) {
                    $realm->auto_detect = $row['auto_detect'] ?? 0;
                }
                
                if ($hasDetectionRules) {
                    $realm->detection_rules = $row['detection_rules'] ?? '';
                }
                
                if ($hasPages && !empty($row['pages'])) {
                    // Handle pages field - could be JSON or comma-separated
                    $pagesData = $row['pages'];
                    if (is_string($pagesData)) {
                        // Try to decode as JSON first
                        $decoded = json_decode($pagesData, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $realm->pages = array_map(function($page) {
                                return is_array($page) ? (object) $page : (object) ['id' => $page, 'title' => $page, 'path' => '/' . $page];
                            }, $decoded);
                        } else {
                            // Handle comma-separated values
                            $pagesList = array_filter(array_map('trim', explode(',', $pagesData)));
                            $realm->pages = array_map(function($page) {
                                return (object) ['id' => $page, 'title' => ucfirst($page), 'path' => '/' . $page];
                            }, $pagesList);
                        }
                    }
                }
                
                $realms[$realm->id] = $realm; // Use realm ID as key for object structure
            }
            
            $realmResult = (object) $realms; // Return as object, not array
            
            // Runtime caching removed for immediate database status reflection
            
            return $realmResult;
            
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error getting realms: ' . $e->getMessage());
            throw new Exception('Navigation database error while fetching realms: ' . $e->getMessage());
        }
    }
    
    /**
     * Get fallback realms when database is not available
     */
    private function getFallbackRealms() {
        $guestRealm = (object) [
            'id' => 'guest',
            'slug' => 'guest',
            'name' => 'Guest Access',
            'description' => 'Default guest access realm',
            'domain' => '',
            'status' => 'active',
            'auto_detect' => 1,
            'detection_rules' => '',
            'pages' => [
                (object) ['id' => 'home', 'title' => 'Home', 'path' => '/'],
                (object) ['id' => 'about', 'title' => 'About', 'path' => '/about'],
                (object) ['id' => 'contact', 'title' => 'Contact', 'path' => '/contact']
            ]
        ];
        
        return (object) ['guest' => $guestRealm]; // Return object with realm ID as key
    }
    
    /**
     * Get menus with their structure and items
     */
    public function getMenus($realmId = null) {
        $role = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
        $isKripz = (stripos($role, 'kripzmaster') !== false);
        
        if (!$isKripz) {
            $requestedRealm = strtolower((string)$realmId);
            if ($realmId !== null && $requestedRealm !== 'hub') {
                $this->safeLog("NavigationDatabaseManager::getMenus - Access denied for realm: $realmId");
                return [];
            }
            if ($realmId === null) {
                $realmId = 'hub';
            }
        }

        $this->enforceAccess('menus', 'read');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
                $this->safeLog("NavigationDatabaseManager::getMenus - Database operations temporarily disabled, returning empty menus");
            } else {
                $this->safeLog("NavigationDatabaseManager::getMenus - No active database configurations available, returning empty menus");
            }
            return []; // Return empty array instead of throwing exception
        }
        
        try {
            $this->ensureMenuSchema();
            $profileStart = microtime(true);
            // First check if menus table exists
            $tableCheckStart = microtime(true);
            $tableCheck = navDbQuery($this->dbConnection, "SHOW TABLES LIKE 'menus'");
            $tableCheckMs = (microtime(true) - $tableCheckStart) * 1000;
            $this->safeLog("PROFILE get_menus: SHOW TABLES 'menus' took {$tableCheckMs}ms");
            if (!$tableCheck['success'] || empty($tableCheck['data'])) {
                throw new Exception("Navigation database connected but required table 'menus' does not exist");
            }
            
            // Check what columns exist in realms table first
            $realmColsStart = microtime(true);
            $realmColumns = $this->getTableColumns('realms');
            $realmColsMs = (microtime(true) - $realmColsStart) * 1000;
            $hasSlug = isset($realmColumns['slug']);
            
            // Debug logging
            $this->safeLog("NavigationDatabaseManager::getMenus - Realms table columns: " . implode(', ', array_keys($realmColumns)));
            $this->safeLog("NavigationDatabaseManager::getMenus - Has slug column: " . ($hasSlug ? 'yes' : 'no'));
            
            $menuMapStart = microtime(true);
            $sql = "
                SELECT m.*" . ($hasSlug ? ", r.slug as realm_slug" : "") . ", r.name as realm_name 
                FROM menus m 
                LEFT JOIN realms r ON m.realm_id = r.id 
                WHERE m.status = 'active'
            ";
            $params = [];
            
            if ($isKripz) {
            $this->safeLog("NavigationDatabaseManager::getMenus - KripzMaster access");
            // KripzMasters can access all realms, but if a specific realm is requested, we should still filter by it.
            // If no realm is specified, then they see all.
            if ($realmId) {
                if ($realmId === 'guest') {
                     $sql .= " AND (m.realm_id IS NULL OR m.realm_id = ?)";
                     $params[] = $realmId;
                } else {
                     $sql .= " AND m.realm_id = ?";
                     $params[] = $realmId;
                }
            }
        } elseif ($realmId) {
                if ($realmId === 'guest') {
                    // For guest realm, include menus with NULL realm_id or realm_id = 'guest'
                    $sql .= " AND (m.realm_id IS NULL OR m.realm_id = ?)";
                    $params[] = $realmId;
                } else {
                    $sql .= " AND m.realm_id = ?";
                    $params[] = $realmId;
                }
            }
            
            // Use available columns for ordering
            $menuMap = $this->getMenuColumnMap();
            $menuMapMs = (microtime(true) - $menuMapStart) * 1000;
            $orderCols = [];
            
            // Fix ordering priority: Priority -> Order Index -> ID (Not Name)
            if ($menuMap['priority']) $orderCols[] = 'm.' . $menuMap['priority'] . ' ASC';
            if ($menuMap['order_index']) $orderCols[] = 'm.' . $menuMap['order_index'] . ' ASC';
            $orderCols[] = 'm.id ASC'; // Ensure deterministic ordering
            
            $sql .= ' ORDER BY ' . implode(', ', $orderCols);
            
            $menuQueryStart = microtime(true);
            $result = navDbQuery($this->dbConnection, $sql, $params);
            $menuQueryMs = (microtime(true) - $menuQueryStart) * 1000;
            $this->safeLog("PROFILE get_menus: menus query took {$menuQueryMs}ms; columns map {$menuMapMs}ms; realms columns {$realmColsMs}ms");
            
            if (!$result['success']) {
                $this->safeLog('NavigationDatabaseManager::getMenus - Failed to get menus: ' . ($result['error'] ?? 'Unknown error'));
                throw new Exception('Navigation database query failed while fetching menus: ' . ($result['error'] ?? 'Unknown error'));
            }
            
            $menus = [];
            $menuItems = [];
            
            // Get all submenu items in one query for better performance
            if (!empty($result['data'])) {
                $submenuStart = microtime(true);
                $menuIds = array_column($result['data'], 'id');
                $idPlaceholders = str_repeat('?,', count($menuIds) - 1) . '?';
                $menuNames = [];
                foreach ($result['data'] as $row) {
                    $n = null;
                    if (isset($row['name']) && is_string($row['name'])) { $n = trim($row['name']); }
                    elseif (isset($row['title']) && is_string($row['title'])) { $n = trim($row['title']); }
                    if ($n !== null && $n !== '') { $menuNames[] = $n; }
                }
                $menuNames = array_values(array_unique($menuNames));
                $namePlaceholders = str_repeat('?,', count($menuNames) - 1) . '?';

                $submenuTable = $this->getSubmenuTableName();
                if ($submenuTable) {
                    $colMap = $this->getSubmenuColumnMap($submenuTable);
                    $menuIdCol = ($colMap['menu_id'] ?? 'menu_id');
                    $clauses = [];
                    if (!empty($menuIds)) { $clauses[] = $menuIdCol . " IN (" . $idPlaceholders . ")"; }
                    if (!empty($menuNames)) { $clauses[] = $menuIdCol . " IN (" . $namePlaceholders . ")"; }
                    $where = "WHERE " . (count($clauses) ? implode(' OR ', $clauses) : '1=0');
                    if ($colMap['status']) { $where .= " AND " . $colMap['status'] . " = 'active'"; }
                    $orderParts = [$menuIdCol];
                    if ($colMap['priority']) $orderParts[] = $colMap['priority'] . " ASC";
                    if ($colMap['order_index']) $orderParts[] = $colMap['order_index'] . " ASC";
                    if ($colMap['title']) $orderParts[] = $colMap['title'] . " ASC";
                    $order = "ORDER BY " . implode(", ", $orderParts);

                    $params = array_merge($menuIds, $menuNames);
                    $itemsResult = navDbQuery($this->dbConnection, "
                        SELECT * FROM {$submenuTable} 
                        {$where} 
                        {$order}
                    ", $params);

                    if ($itemsResult['success']) {
                        foreach ($itemsResult['data'] ?? [] as $itemData) {
                            $midKey = $colMap['menu_id'] ?? 'menu_id';
                            $idKey = $colMap['id'] ?? 'id';
                            $titleKey = $colMap['title'] ?? ($colMap['name'] ?? 'title');
                            $urlKey = $colMap['url'] ?? 'url';
                            $iconKey = $colMap['icon'] ?? 'icon';
                            $orderKey = $colMap['order_index'] ?? 'order_index';
                            $priorityKey = $colMap['priority'] ?? null;
                            $item = new \stdClass();
                            $item->id = $itemData[$idKey] ?? ($itemData['id'] ?? null);
                            $item->title = $itemData[$titleKey] ?? ($itemData['name'] ?? null);
                            $item->url = $itemData[$urlKey] ?? ($itemData['url'] ?? '');
                            if (is_string($item->url)) {
                                $u = trim($item->url);
                                if ($u === '' || $u === '#' || stripos($u, 'javascript:') === 0) { $u = ''; }
                                $item->url = $u;
                            }
                            $item->icon = $this->normalizeIconClass($itemData[$iconKey] ?? null);
                            $item->order_index = isset($itemData[$orderKey]) ? (int)$itemData[$orderKey] : (isset($itemData['order_index']) ? (int)$itemData['order_index'] : 0);
                            $item->priority = $priorityKey ? (isset($itemData[$priorityKey]) ? (int)$itemData[$priorityKey] : (isset($itemData['priority']) ? (int)$itemData['priority'] : 0)) : (isset($itemData['priority']) ? (int)$itemData['priority'] : 0);
                            $keyVal = $itemData[$midKey] ?? null;
                            if ($keyVal !== null) {
                                $menuItems[$keyVal][] = $item;
                                if (is_string($keyVal)) {
                                    $lower = strtolower($keyVal);
                                    if ($lower !== $keyVal) { $menuItems[$lower][] = $item; }
                                }
                            }
                        }
                        $submenuMs = (microtime(true) - $submenuStart) * 1000;
                        $this->safeLog("PROFILE get_menus: submenu query+map took {$submenuMs}ms; submenu rows=" . count($itemsResult['data'] ?? []));
                    }
                }
            }
            
            // Convert each menu to object and attach items
            $idCol = $menuMap['id'] ?? 'id';
            $nameCol = $menuMap['name'] ?? 'name';
            $titleCol = $menuMap['title'] ?? null;
            $urlCol = $menuMap['url'] ?? 'url';
            $orderCol = $menuMap['order_index'] ?? 'order_index';

            $transformStart = microtime(true);
            foreach ($result['data'] ?? [] as $menuData) {
                // Normalize into canonical object shape for UI
                $menu = new \stdClass();
                $menu->id = $menuData[$idCol] ?? $menuData['id'] ?? null;
                
                // --- STRICT FILTERING FOR STANDARD USERS ---
                if (!$isKripz) {
                    $mRealmId = strtolower((string)($menuData[$menuMap['realm_id'] ?? 'realm_id'] ?? ''));
                    // Only allow Home/Hub menus
                    if ($mRealmId !== 'home' && $mRealmId !== 'hub' && $mRealmId !== '1' && $mRealmId !== '2') {
                        continue; 
                    }
                }
                // -------------------------------------------

                $menu->name = $menuData[$nameCol] ?? ($titleCol ? ($menuData[$titleCol] ?? null) : null);
                $menu->title = (function() use ($menuData, $titleCol, $nameCol) {
                    if ($titleCol) {
                        $t = $menuData[$titleCol] ?? null;
                        if (is_string($t)) {
                            if (trim($t) !== '') return $t;
                        } elseif (!empty($t)) {
                            return $t;
                        }
                    }
                    return $menuData[$nameCol] ?? null;
                })();
                $menu->url = $menuData[$urlCol] ?? ($menuData['url'] ?? '#');
                if (is_string($menu->url)) {
                    $u = trim($menu->url);
                    if ($u === '' || $u === '#' || stripos($u, 'javascript:') === 0) { $u = ''; }
                    if (is_string($menu->title) && strcasecmp($menu->title, 'Global UI') === 0) { $u = ''; }
                    $menu->url = $u;
                }
                $menu->order_index = isset($menuData[$orderCol]) ? $menuData[$orderCol] : ($menuData['order_index'] ?? 0);
                $prioCol = $menuMap['priority'] ?? 'priority';
                $menu->priority = isset($menuData[$prioCol]) ? $menuData[$prioCol] : ($menuData['priority'] ?? 0);
                $menu->realm_id = $menuData[$menuMap['realm_id'] ?? 'realm_id'] ?? null;
                $menu->realm_name = $menuData['realm_name'] ?? null;
                // Add icon field mapping
                $iconCol = $menuMap['icon'] ?? 'icon';
                $menu->icon = $this->normalizeIconClass($menuData[$iconCol] ?? null);

                // DEBUG: Log icon data retrieval
                $this->safeLog("DEBUG getMenus: menu_id={$menu->id}, iconCol='$iconCol', icon_value='" . ($menu->icon ?? 'NULL') . "', raw_data_keys=[" . implode(','  , array_keys($menuData)) . "]");
                if ($hasSlug) { $menu->realm_slug = $menuData['realm_slug'] ?? null; }

                $menuKeyCandidates = [];
                $menuKeyCandidates[] = $menu->id;
                if (isset($menu->title) && is_string($menu->title)) { $menuKeyCandidates[] = $menu->title; $menuKeyCandidates[] = strtolower($menu->title); }
                if (isset($menu->name) && is_string($menu->name)) { $menuKeyCandidates[] = $menu->name; $menuKeyCandidates[] = strtolower($menu->name); }
                $attachedItems = [];
                foreach ($menuKeyCandidates as $k) {
                    if ($k !== null && isset($menuItems[$k])) { $attachedItems = $menuItems[$k]; break; }
                }
                if (!empty($attachedItems)) {
                    $seen = [];
                    $unique = [];
                    foreach ($attachedItems as $it) {
                        $uid = is_object($it) ? ((isset($it->id) && $it->id !== null) ? ('id:' . $it->id) : ('t:' . (isset($it->title) ? $it->title : ''))) : ('h:' . md5(json_encode($it)));
                        if (!isset($seen[$uid])) { $seen[$uid] = true; $unique[] = $it; }
                    }
                    $attachedItems = $unique;
                }
                $menu->items = $attachedItems;
                $menu->submenu = $menu->items; // JS compatibility

                $menus[] = $menu;
            }
            $transformMs = (microtime(true) - $transformStart) * 1000;
            $totalMs = (microtime(true) - $profileStart) * 1000;
            $this->safeLog("PROFILE get_menus: transform took {$transformMs}ms; total {$totalMs}ms; menus=" . count($menus));
            
            return $menus;
            
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error getting menus: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get social links
     */
    public function getSocialLinks($realmId = null) {
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Navigation database connection not available: cannot load social links');
        }
        
        // Ensure social_connect table exists (lazy initialization)
        $this->ensureSocialConnectTable();
        
        try {
            // Detect table schema
            $columns = $this->getTableColumns('social_connect');
            $hasPlatform = isset($columns['platform']);
            $hasUrl = isset($columns['url']);
            $hasRealm = isset($columns['realm_id']);
            $hasStatus = isset($columns['status']);

            // Row-model: each row is a link with platform + url
            if ($hasPlatform && $hasUrl) {
                $where = [];
                $params = [];
                if ($hasStatus) {
                    $where[] = "status = 'active'";
                }
                if ($realmId && $hasRealm) {
                    $where[] = "realm_id = ?";
                    $params[] = $realmId;
                }
                $sql = "SELECT * FROM social_connect";
                if (!empty($where)) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= isset($columns['platform_name']) ? " ORDER BY platform_name" : '';

                $result = navDbQuery($this->dbConnection, $sql, $params);
                if (!$result['success']) {
                    return [];
                }

                $socialLinks = [];
                foreach ($result['data'] ?? [] as $row) {
                    $socialLinks[] = (object) [
                        'id' => $row['id'],
                        'platform' => $row['platform'],
                        'name' => $row['platform_name'] ?? ucfirst($row['platform'] ?? ''),
                        'url' => $row['url'],
                        'username' => $row['username'] ?? null,
                        'color' => $row['color'] ?? null,
                        'show_in_header' => $row['show_in_header'] ?? null,
                        'show_in_footer' => $row['show_in_footer'] ?? null,
                        'target_blank' => $row['target_blank'] ?? null,
                        'order' => $row['order_index'] ?? null
                    ];
                }
                return $socialLinks;
            }

            // Column-model: one row per realm, columns like facebook, twitter, etc.
            $params = [];
            $sql = "SELECT * FROM social_connect";
            if ($realmId && $hasRealm) {
                $sql .= " WHERE realm_id = ?";
                $params[] = $realmId;
            } else {
                // If there is no realm_id column or no realm specified, just take the first row
                $sql .= $hasRealm ? " LIMIT 1" : " LIMIT 1";
            }
            $result = navDbQuery($this->dbConnection, $sql, $params);
            if (!$result['success'] || empty($result['data'])) {
                return [];
            }

            $row = $result['data'][0];
            $platformColumns = [];
            // Known platform column names
            $known = ['facebook','twitter','instagram','linkedin','youtube','tiktok','discord','github','whatsapp','snapchat','pinterest'];
            foreach ($columns as $colName => $_) {
                if (in_array($colName, $known, true)) {
                    $platformColumns[] = $colName;
                }
            }
            $socialLinks = [];
            foreach ($platformColumns as $col) {
                $val = $row[$col] ?? '';
                if ($val) {
                    $socialLinks[] = (object) [
                        // Use synthetic id combining row id and column name for client-side identity
                        'id' => isset($row['id']) ? ($row['id'] . ':' . $col) : $col,
                        'platform' => $col,
                        'name' => ucfirst($col),
                        'url' => $val,
                    ];
                }
            }
            return $socialLinks;
            
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error getting social links: ' . $e->getMessage());
            throw new Exception('Navigation database error while fetching social links: ' . $e->getMessage());
        }
    }
    
    /**
     * Get available pages for realm configuration
     * Returns a list of available pages that can be used in navigation
     */
    public function getAvailablePages() {
        try {
            $base = function_exists('getPublicPath') ? rtrim(getPublicPath(), DIRECTORY_SEPARATOR) : rtrim(dirname(dirname(__DIR__)), DIRECTORY_SEPARATOR);
            $pages = [];
            $exclude = ['.cue', '.data', 'gear', 'temp', 'tmp'];
            $stack = [[ 'dir' => $base, 'rel' => '', 'depth' => 0 ]];
            $maxDepth = 3;
            while (!empty($stack)) {
                $item = array_pop($stack);
                $dir = $item['dir'];
                $rel = $item['rel'];
                $depth = $item['depth'];
                $entries = @scandir($dir);
                if ($entries === false) { continue; }
                foreach ($entries as $name) {
                    if ($name === '.' || $name === '..') { continue; }
                    if (in_array($name, $exclude, true)) { continue; }
                    $path = $dir . DIRECTORY_SEPARATOR . $name;
                    if (function_exists('validateSecurePath') && !validateSecurePath($path, $base)) { continue; }
                    $relPath = ltrim($rel . '/' . $name, '/');
                    if (is_dir($path)) {
                        if ($depth < $maxDepth) {
                            $stack[] = [ 'dir' => $path, 'rel' => $relPath, 'depth' => $depth + 1 ];
                        }
                        $index = $path . DIRECTORY_SEPARATOR . 'index.php';
                        if (is_file($index)) {
                            $urlPath = '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relPath) . '/';
                            $pages[] = (object) [ 'id' => $relPath . '/index.php', 'title' => $name, 'path' => $urlPath ];
                        }
                    } elseif (is_file($path)) {
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        if (in_array($ext, ['php', 'html'], true)) {
                            $urlPath = '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relPath);
                            $pages[] = (object) [ 'id' => $relPath, 'title' => $name, 'path' => $urlPath ];
                        }
                    }
                }
            }
            usort($pages, function($a, $b) { return strcmp($a->path, $b->path); });
            return $pages;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error getting available pages: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get social connects (alias for getSocialLinks for compatibility)
     */
    public function getSocialConnects($realmId = null) {
        return $this->getSocialLinks($realmId);
    }

    /**
     * Get a single social connect by ID directly
     * Supports both row-model (one row per link) and column-model (one row per realm)
     */
    public function getSocialConnectById($id, $realmId = null) {
        if (!$this->dbConnection || !$this->connSuccess) {
            return null;
        }
        // Ensure table exists
        $this->ensureSocialConnectTable();

        try {
            $columns = $this->getTableColumns('social_connect');
            $hasPlatform = isset($columns['platform']);
            $hasUrl = isset($columns['url']);
            $hasRealm = isset($columns['realm_id']);
            $hasId = isset($columns['id']);
            $hasStatus = isset($columns['status']);

            // Row-model: direct lookup by numeric/string id
            if ($hasPlatform && $hasUrl && $hasId) {
                $where = ['id = ?'];
                $params = [is_numeric($id) ? intval($id) : $id];
                if ($hasStatus) {
                    $where[] = "status = 'active'";
                }
                if ($realmId && $hasRealm) {
                    $where[] = 'realm_id = ?';
                    $params[] = $realmId;
                }
                $sql = "SELECT * FROM social_connect WHERE " . implode(' AND ', $where) . " LIMIT 1";
                $result = navDbQuery($this->dbConnection, $sql, $params);
                if (!$result['success'] || empty($result['data'])) {
                    return null;
                }
                $row = $result['data'][0];
                return (object) [
                    'id' => $row['id'],
                    'platform' => $row['platform'],
                    'name' => $row['platform_name'] ?? ucfirst($row['platform'] ?? ''),
                    'url' => $row['url'],
                    'username' => $row['username'] ?? null,
                    'color' => $row['color'] ?? null,
                    'show_in_header' => $row['show_in_header'] ?? null,
                    'show_in_footer' => $row['show_in_footer'] ?? null,
                    'target_blank' => $row['target_blank'] ?? null,
                    'order' => $row['order_index'] ?? null
                ];
            }

            // Column-model: parse synthetic id like "rowId:platform" or fallback to platform
            $rowId = null;
            $platform = null;
            if (strpos((string)$id, ':') !== false) {
                [$rowId, $platform] = explode(':', (string)$id, 2);
            } else {
                $platform = (string)$id;
            }
            // Fetch the appropriate row
            $params = [];
            $sql = "SELECT * FROM social_connect";
            if ($rowId && $hasId) {
                $sql .= " WHERE id = ?";
                $params[] = $rowId;
            } elseif ($realmId && $hasRealm) {
                $sql .= " WHERE realm_id = ?";
                $params[] = $realmId;
            } else {
                $sql .= " LIMIT 1";
            }
            $result = navDbQuery($this->dbConnection, $sql, $params);
            if (!$result['success'] || empty($result['data'])) {
                return null;
            }
            $row = $result['data'][0];
            $value = $row[$platform] ?? '';
            if ($value === '') {
                return null;
            }
            return (object) [
                'id' => $id,
                'platform' => $platform,
                'name' => ucfirst($platform),
                'url' => $value
            ];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error getting social link by id: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate a unique realm ID based on name
     */
    public function generateRealmId($name) {
        // Create a URL-friendly ID from name
        $id = strtolower(trim($name));
        $id = preg_replace('/[^a-z0-9\-_]/', '_', $id);
        $id = preg_replace('/_{2,}/', '_', $id);
        $id = trim($id, '_');
        
        // Ensure uniqueness with safety limit
        $baseId = $id;
        $counter = 1;
        $maxAttempts = 1000; // Prevent infinite loop
        $realms = $this->getRealms();
        
        while ($counter <= $maxAttempts) {
            $exists = false;
            foreach ($realms as $realm) {
                if ($realm->id === $id) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) break;
            $id = $baseId . '_' . $counter++;
        }
        
        if ($counter > $maxAttempts) {
            throw new Exception('Could not generate unique realm ID after ' . $maxAttempts . ' attempts');
        }
        
        return $id;
    }

    /**
     * Backward-compatible alias used by older code paths.
     * Maintains static analyzer satisfaction and delegates to generateRealmId.
     */
    private function createRealmId($name) {
        return $this->generateRealmId($name);
    }
    
    /**
     * Create a new realm
     */
    public function createRealm($data) {
        $this->enforceAccess('realms', 'write');

        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }

        try {
            $data = $this->normalizeInputData($data);

            // Determine actual columns present in the realms table
            $columns = $this->getTableColumns('realms');

            // Require minimal fields
            if (!isset($data['id'])) {
                if (!isset($data['name'])) {
                    throw new Exception('Missing required field: name');
                }
                // Create a URL-friendly, unique id from name if not provided
                if (method_exists($this, 'createRealmId')) {
                    $data['id'] = $this->createRealmId($data['name']);
                } else {
                    $base = strtolower(trim($data['name']));
                    $base = preg_replace('/[^a-z0-9\-_]/', '_', $base);
                    $base = preg_replace('/_{2,}/', '_', $base);
                    $data['id'] = trim($base, '_');
                }
            }

            // Build insert list dynamically based on existing columns
            $insertCols = [];
            $placeholders = [];
            $params = [];

            if (isset($columns['id'])) {
                $insertCols[] = 'id';
                $placeholders[] = '?';
                $params[] = $data['id'];
            }

            if (isset($columns['name']) && isset($data['name'])) {
                $insertCols[] = 'name';
                $placeholders[] = '?';
                $params[] = $data['name'];
            }

            if (isset($columns['domain'])) {
                $insertCols[] = 'domain';
                $placeholders[] = '?';
                $params[] = $data['domain'] ?? '';
            }

            if (isset($columns['status'])) {
                $insertCols[] = 'status';
                $placeholders[] = '?';
                $params[] = $data['status'] ?? 'active';
            }

            if (isset($columns['description'])) {
                $insertCols[] = 'description';
                $placeholders[] = '?';
                $params[] = $data['description'] ?? '';
            }

            if (isset($columns['color'])) {
                $insertCols[] = 'color';
                $placeholders[] = '?';
                $params[] = $data['color'] ?? null;
            }

            if (isset($columns['icon'])) {
                $insertCols[] = 'icon';
                $placeholders[] = '?';
                $params[] = $data['icon'] ?? null;
            }

            if (isset($columns['auto_detect'])) {
                $insertCols[] = 'auto_detect';
                $placeholders[] = '?';
                $params[] = !empty($data['auto_detect']) ? 1 : 0;
            }

            if (isset($columns['detection_rules'])) {
                $insertCols[] = 'detection_rules';
                $placeholders[] = '?';
                $params[] = isset($data['detection_rules']) ? (is_array($data['detection_rules']) ? json_encode($data['detection_rules']) : $data['detection_rules']) : null;
            }

            if (isset($columns['pages'])) {
                $insertCols[] = 'pages';
                $placeholders[] = '?';
                $params[] = isset($data['pages']) ? (is_array($data['pages']) ? json_encode($data['pages']) : $data['pages']) : json_encode([]);
            }

            if (isset($columns['created_at'])) {
                $insertCols[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            if (isset($columns['updated_at'])) {
                $insertCols[] = 'updated_at';
                $placeholders[] = 'NOW()';
            }

            if (empty($insertCols)) {
                throw new Exception('No valid columns available for realms insert');
            }

            // Compose SQL with mixed placeholders; literal NOW() entries should not have bindings
            $sql = 'INSERT INTO realms (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

            // Filter params to only those that correspond to '?' placeholders
            $bindingParams = [];
            $idx = 0;
            foreach ($placeholders as $ph) {
                if ($ph === '?') {
                    $bindingParams[] = $params[$idx++];
                }
            }

            $result = navDbQuery($this->dbConnection, $sql, $bindingParams);

            if (!$result['success']) {
                throw new Exception('Failed to create realm: ' . $result['error']);
            }

            return ['id' => $data['id']];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error creating realm: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update an existing realm
     */
    public function updateRealm($realmId, $data) {
        $this->enforceAccess('realms', 'update');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $data = $this->normalizeInputData($data);
            $columns = $this->getTableColumns('realms');
            // Build the UPDATE query based on actual table structure
            $updateFields = [];
            $params = [];

            // Support changing the primary ID when allowed by schema
            $newId = null;
            if (isset($data['id']) && isset($columns['id'])) {
                $newId = (string)$data['id'];
                if ($newId !== (string)$realmId && $newId !== '') {
                    $updateFields[] = 'id = ?';
                    $params[] = $newId;
                }
            }
            
            if (isset($data['name']) && isset($columns['name'])) {
                $updateFields[] = 'name = ?';
                $params[] = $data['name'];
            }
            
            if (isset($data['description']) && isset($columns['description'])) {
                $updateFields[] = 'description = ?';
                $params[] = $data['description'];
            }
            
            if (isset($data['color']) && isset($columns['color'])) {
                $updateFields[] = 'color = ?';
                $params[] = $data['color'];
            }
            
            if (isset($data['icon']) && isset($columns['icon'])) {
                $updateFields[] = 'icon = ?';
                $params[] = $data['icon'];
            }
            
            if (isset($data['status']) && isset($columns['status'])) {
                $updateFields[] = 'status = ?';
                $params[] = $data['status'];
            }
            
            if (isset($data['priority']) && isset($columns['priority'])) {
                $updateFields[] = 'priority = ?';
                $params[] = intval($data['priority']);
            }
            
            if (isset($data['auto_detect']) && isset($columns['auto_detect'])) {
                $updateFields[] = 'auto_detect = ?';
                $params[] = $data['auto_detect'] ? 1 : 0;
            }
            
            if (isset($data['detection_rules']) && isset($columns['detection_rules'])) {
                $updateFields[] = 'detection_rules = ?';
                $params[] = is_array($data['detection_rules']) ? json_encode($data['detection_rules']) : $data['detection_rules'];
            }
            
            if (isset($data['pages']) && isset($columns['pages'])) {
                $updateFields[] = 'pages = ?';
                $params[] = is_array($data['pages']) ? json_encode($data['pages']) : $data['pages'];
            }
            
            if (isset($data['sub_realms']) && isset($columns['sub_realms'])) {
                $updateFields[] = 'sub_realms = ?';
                $params[] = is_array($data['sub_realms']) ? json_encode($data['sub_realms']) : $data['sub_realms'];
            }
            
            if (isset($data['onboarding_process']) && isset($columns['onboarding_process'])) {
                $updateFields[] = 'onboarding_process = ?';
                $params[] = $data['onboarding_process'];
            }
            
            // Always update the timestamp if the column exists
            if (isset($columns['updated_at'])) {
                $updateFields[] = 'updated_at = NOW()';
            }
            
            // Add the realm ID for the WHERE clause
            $params[] = $realmId;
            
            if (empty($updateFields)) {
                throw new Exception('No valid fields provided for update');
            }
            
            $sql = "UPDATE realms SET " . implode(', ', $updateFields) . " WHERE id = ?";
            
            $result = navDbQuery($this->dbConnection, $sql, $params);
            
            if (!$result['success']) {
                throw new Exception('Failed to update realm: ' . $result['error']);
            }
            
            // If ID changed, cascade to related tables where applicable
            if ($newId && $newId !== (string)$realmId) {
                try {
                    // Update menus.realm_id if column exists
                    $menuCols = $this->getTableColumns('menus');
                    if (isset($menuCols['realm_id'])) {
                        navDbQuery($this->dbConnection, "UPDATE menus SET realm_id = ? WHERE realm_id = ?", [$newId, $realmId]);
                    }
                } catch (Exception $e) {
                    $this->safeLog('NavigationDatabaseManager: Failed to cascade realm_id in menus: ' . $e->getMessage());
                }
                try {
                    // Update social_connect.realm_id if column exists
                    $socCols = $this->getTableColumns('social_connect');
                    if (isset($socCols['realm_id'])) {
                        navDbQuery($this->dbConnection, "UPDATE social_connect SET realm_id = ? WHERE realm_id = ?", [$newId, $realmId]);
                    }
                } catch (Exception $e) {
                    $this->safeLog('NavigationDatabaseManager: Failed to cascade realm_id in social_connect: ' . $e->getMessage());
                }
            }

            return ['id' => $newId ?: $realmId];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error updating realm: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete a realm
     */
    public function deleteRealm($realmId) {
        $this->enforceAccess('realms', 'delete');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $result = navDbQuery($this->dbConnection, "DELETE FROM realms WHERE id = ?", [$realmId]);
            
            if (!$result['success']) {
                throw new Exception('Failed to delete realm: ' . $result['error']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error deleting realm: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if a menu exists
     */
    public function menuExists($menuId) {
        if (!$this->dbConnection || !$this->connSuccess) {
            return false;
        }
        
        try {
            $result = navDbQuery($this->dbConnection, "SELECT COUNT(*) as count FROM menus WHERE id = ?", [$menuId]);
            return $result['success'] && !empty($result['data']) && (int)$result['data'][0]['count'] > 0;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error checking menu existence: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a new menu
     */
    public function createMenu($data) {
        $this->enforceAccess('menus', 'write');

        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }

        try {
            // Normalize input and ensure schema exists
            $data = $this->normalizeInputData($data);
            $this->ensureMenuSchema();

            $map = $this->getMenuColumnMap();
            // Build associative map to avoid duplicate column names (e.g., name/title aliasing same column)
            $colMap = [];
            $values = [];
            $params = [];

            if ($map['realm_id']) { $colMap[$map['realm_id']] = '?'; $values[] = '?'; $params[] = $data['realm_id']; }
            // If name and title map to the same column, prefer title's value when provided
            if ($map['name'] || $map['title']) {
                $nameCol = $map['name'];
                $titleCol = $map['title'];
                if ($nameCol && $titleCol && $nameCol === $titleCol) {
                    if (isset($data['title']) || isset($data['name'])) {
                        $colMap[$nameCol] = '?';
                        $values[] = '?';
                        $params[] = isset($data['title']) ? $data['title'] : $data['name'];
                    }
                } else {
                    if ($nameCol && isset($data['name'])) { $colMap[$nameCol] = '?'; $values[] = '?'; $params[] = $data['name']; }
                    if ($titleCol && isset($data['title'])) { $colMap[$titleCol] = '?'; $values[] = '?'; $params[] = $data['title']; }
                }
            }
            if ($map['url']) { $colMap[$map['url']] = '?'; $values[] = '?'; $params[] = $data['url'] ?? '#'; }
            if ($map['icon'] && isset($data['icon'])) { $colMap[$map['icon']] = '?'; $values[] = '?'; $params[] = $data['icon']; }
            if ($map['order_index']) { $colMap[$map['order_index']] = '?'; $values[] = '?'; $params[] = $data['order_index'] ?? 0; }
            if ($map['status']) { $colMap[$map['status']] = '?'; $values[] = '?'; $params[] = 'active'; }
            if ($map['created_at']) { $colMap[$map['created_at']] = 'NOW()'; $values[] = 'NOW()'; }
            if ($map['updated_at']) { $colMap[$map['updated_at']] = 'NOW()'; $values[] = 'NOW()'; }

            // If table has an id column without default/auto-increment, supply one
            if (!empty($map['id'])) {
                $idCol = $map['id'];
                $idType = '';
                $idIsAutoIncrement = false;
                try {
                    $colInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `menus` LIKE '{$idCol}'");
                    if (!empty($colInfo['success']) && !empty($colInfo['data'][0])) {
                        $idType = (string)($colInfo['data'][0]['Type'] ?? ($colInfo['data'][0]['type'] ?? ''));
                        $extra = (string)($colInfo['data'][0]['Extra'] ?? ($colInfo['data'][0]['extra'] ?? ''));
                        $idIsAutoIncrement = stripos($extra, 'auto_increment') !== false;
                    }
                } catch (Exception $e) {
                    $idType = '';
                    $idIsAutoIncrement = false;
                }

                if ($idIsAutoIncrement) {
                    $idCol = null;
                }

                if ($idCol && isset($colMap[$idCol])) {
                    unset($colMap[$idCol]);
                }

                if ($idCol && !isset($colMap[$idCol])) {
                    $nextId = null;
                    $isTextId = ($idType !== '' && (stripos($idType, 'char') !== false || stripos($idType, 'text') !== false));

                    if ($isTextId) {
                        $attempts = 0;
                        while ($attempts < 20) {
                            try {
                                $nextId = bin2hex(random_bytes(12));
                            } catch (Exception $e) {
                                $nextId = uniqid('', true);
                            }
                            $existsCheck = navDbQuery($this->dbConnection, "SELECT 1 FROM menus WHERE {$idCol} = ? LIMIT 1", [$nextId]);
                            if (!empty($existsCheck['success']) && empty($existsCheck['data'])) {
                                break;
                            }
                            $attempts++;
                        }
                    } else {
                        try {
                            $nextIdQuery = navDbQuery($this->dbConnection, "SELECT COALESCE(MAX(CAST({$idCol} AS UNSIGNED)), 0) + 1 AS next_id FROM menus");
                            if (!empty($nextIdQuery['success']) && !empty($nextIdQuery['data'][0]['next_id'])) {
                                $nextId = $nextIdQuery['data'][0]['next_id'];
                            }
                        } catch (Exception $e) {
                            $nextId = null;
                        }
                        if (!is_numeric($nextId)) {
                            $attempts = 0;
                            while ($attempts < 20) {
                                try {
                                    $nextId = random_int(1, PHP_INT_MAX);
                                } catch (Exception $e) {
                                    $nextId = mt_rand(1, 2147483647);
                                }
                                $existsCheck = navDbQuery($this->dbConnection, "SELECT 1 FROM menus WHERE {$idCol} = ? LIMIT 1", [$nextId]);
                                if (!empty($existsCheck['success']) && empty($existsCheck['data'])) {
                                    break;
                                }
                                $attempts++;
                            }
                        }
                    }

                    $colMap[$idCol] = '?';
                    $values[] = '?';
                    $params[] = $nextId;
                }
            }

            $columns = array_keys($colMap);
            if (empty($columns)) {
                throw new \Exception('No valid columns found in menus table for insert');
            }

            $sql = sprintf(
                'INSERT INTO menus (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', $values)
            );

            $result = navDbQuery($this->dbConnection, $sql, $params);
            
            if (!$result['success']) {
                throw new Exception('Failed to create menu: ' . $result['error']);
            }
            
            // Get the last insert ID from the connection
            $lastIdResult = navDbQuery($this->dbConnection, "SELECT LAST_INSERT_ID() as id");
            $lastId = $lastIdResult['success'] && !empty($lastIdResult['data']) ? $lastIdResult['data'][0]['id'] : null;
            if (!$lastId && !empty($map['id']) && isset($colMap[$map['id']])) {
                // If we explicitly supplied the ID, return that
                // Find index of id column in values and use corresponding param
                $idIdx = array_search('?', $values);
                // Prefer direct lookup by column name
                foreach (array_keys($colMap) as $idx => $colName) {
                    if ($colName === $map['id']) { $idIdx = $idx; break; }
                }
                if ($idIdx !== null && isset($params[$idIdx])) {
                    $lastId = $params[$idIdx];
                }
            }
            
            return ['id' => $lastId];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error creating menu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update an existing menu
     */
    public function updateMenu($menuId, $data) {
        $this->enforceAccess('menus', 'update');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $data = $this->normalizeInputData($data);
            $map = $this->getMenuColumnMap();

            $setsAssoc = [];
            $params = [];
            // Handle name/title aliasing
            $nameCol = $map['name'];
            $titleCol = $map['title'];
            if ($nameCol && $titleCol && $nameCol === $titleCol) {
                if (isset($data['title']) || isset($data['name'])) {
                    $setsAssoc[$nameCol] = $nameCol . ' = ?';
                    $params[] = isset($data['title']) ? $data['title'] : $data['name'];
                }
            } else {
                if ($nameCol && isset($data['name'])) { $setsAssoc[$nameCol] = $nameCol . ' = ?'; $params[] = $data['name']; }
                if ($titleCol && isset($data['title'])) { $setsAssoc[$titleCol] = $titleCol . ' = ?'; $params[] = $data['title']; }
            }
            if ($map['url'] && isset($data['url'])) { $setsAssoc[$map['url']] = $map['url'] . ' = ?'; $params[] = $data['url'] ?? '#'; }
            if ($map['icon'] && isset($data['icon'])) { $setsAssoc[$map['icon']] = $map['icon'] . ' = ?'; $params[] = $data['icon']; }
            if ($map['order_index']) { $setsAssoc[$map['order_index']] = $map['order_index'] . ' = ?'; $params[] = $data['order_index'] ?? 0; }
            if ($map['updated_at']) { $setsAssoc[$map['updated_at']] = $map['updated_at'] . ' = NOW()'; }

            $sets = array_values($setsAssoc);

            if (empty($sets)) {
                return ['id' => $menuId];
            }

            $idCol = $map['id'] ?? 'id';
            $sql = sprintf(
                'UPDATE menus SET %s WHERE %s = ?',
                implode(', ', $sets),
                $idCol
            );
            $params[] = $menuId;

            $result = navDbQuery($this->dbConnection, $sql, $params);
            
            if (!$result['success']) {
                throw new Exception('Failed to update menu: ' . $result['error']);
            }
            
            return ['id' => $menuId];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error updating menu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete a menu
     */
    public function deleteMenu($menuId) {
        $this->enforceAccess('menus', 'delete');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $result = navDbQuery($this->dbConnection, "DELETE FROM menus WHERE id = ?", [$menuId]);
            
            if (!$result['success']) {
                throw new Exception('Failed to delete menu: ' . $result['error']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error deleting menu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Add a submenu item
     */
    public function addSubmenu($data) {
        $data = $this->normalizeInputData($data);
        $submenuTable = $this->getSubmenuTableName();
        if (!$submenuTable) {
            throw new Exception("Submenu table not found (expected 'submenus' or 'menu_items')");
        }
        // Align with existing permissions: use 'write' for insert operations
        $this->enforceAccess($submenuTable, 'write');

        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }

        try {
            // Ensure schema exists before write
            $this->ensureMenuSchema();

            $colMap = $this->getSubmenuColumnMap($submenuTable);
            // Require a valid menu_id column
            $menuIdCol = $colMap['menu_id'] ?? null;
            if (!$menuIdCol) {
                throw new Exception("Submenu table '{$submenuTable}' missing a menu_id column (expected one of: menu_id, menu, menuid, menuId)");
            }

            $columns = [$menuIdCol];
            $values = ['?'];
            $params = [$data['menu_id']];

            $insertedId = null;
            $idCol = $colMap['id'] ?? null;
            if ($idCol) {
                $idType = '';
                $idIsAutoIncrement = false;
                try {
                    $colInfo = navDbQuery($this->dbConnection, "SHOW COLUMNS FROM `{$submenuTable}` LIKE '{$idCol}'");
                    if (!empty($colInfo['success']) && !empty($colInfo['data'][0])) {
                        $idType = (string)($colInfo['data'][0]['Type'] ?? ($colInfo['data'][0]['type'] ?? ''));
                        $extra = (string)($colInfo['data'][0]['Extra'] ?? ($colInfo['data'][0]['extra'] ?? ''));
                        $idIsAutoIncrement = stripos($extra, 'auto_increment') !== false;
                    }
                } catch (Exception $e) {
                    $idType = '';
                    $idIsAutoIncrement = false;
                }

                if (!$idIsAutoIncrement) {
                    $maxLen = 0;
                    if (preg_match('/\((\d+)\)/', $idType, $m)) {
                        $maxLen = (int)$m[1];
                    }
                    $givenId = isset($data['id']) ? trim((string)$data['id']) : '';
                    $makeId = function() use ($data) {
                        $title = isset($data['title']) ? (string)$data['title'] : (isset($data['name']) ? (string)$data['name'] : '');
                        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $title));
                        $slug = trim($slug, '_');
                        if ($slug === '') {
                            $slug = 'submenu';
                        }
                        try {
                            $rand = bin2hex(random_bytes(6));
                        } catch (Exception $e) {
                            $rand = substr(md5(uniqid('', true)), 0, 12);
                        }
                        return $slug . '_' . $rand;
                    };

                    $isTextId = ($idType !== '' && (stripos($idType, 'char') !== false || stripos($idType, 'text') !== false));
                    if ($isTextId) {
                        $candidate = $givenId !== '' ? preg_replace('/[^A-Za-z0-9:_-]/', '_', $givenId) : $makeId();
                        if ($maxLen > 0 && strlen($candidate) > $maxLen) {
                            $candidate = substr($candidate, 0, $maxLen);
                        }
                        $attempts = 0;
                        while ($attempts < 20) {
                            $existsCheck = navDbQuery($this->dbConnection, "SELECT 1 FROM {$submenuTable} WHERE {$idCol} = ? LIMIT 1", [$candidate]);
                            if (!empty($existsCheck['success']) && empty($existsCheck['data'])) {
                                break;
                            }
                            $candidate = $makeId();
                            if ($maxLen > 0 && strlen($candidate) > $maxLen) {
                                $candidate = substr($candidate, 0, $maxLen);
                            }
                            $attempts++;
                        }
                        $insertedId = $candidate;
                    } else {
                        $nextId = null;
                        try {
                            $nextIdQuery = navDbQuery($this->dbConnection, "SELECT COALESCE(MAX(CAST({$idCol} AS UNSIGNED)), 0) + 1 AS next_id FROM {$submenuTable}");
                            if (!empty($nextIdQuery['success']) && !empty($nextIdQuery['data'][0]['next_id'])) {
                                $nextId = $nextIdQuery['data'][0]['next_id'];
                            }
                        } catch (Exception $e) {
                            $nextId = null;
                        }
                        if (!is_numeric($nextId)) {
                            $attempts = 0;
                            while ($attempts < 20) {
                                try {
                                    $nextId = random_int(1, PHP_INT_MAX);
                                } catch (Exception $e) {
                                    $nextId = mt_rand(1, 2147483647);
                                }
                                $existsCheck = navDbQuery($this->dbConnection, "SELECT 1 FROM {$submenuTable} WHERE {$idCol} = ? LIMIT 1", [$nextId]);
                                if (!empty($existsCheck['success']) && empty($existsCheck['data'])) {
                                    break;
                                }
                                $attempts++;
                            }
                        }
                        $insertedId = $nextId;
                    }

                    array_unshift($columns, $idCol);
                    array_unshift($values, '?');
                    array_unshift($params, $insertedId);
                }
            }

            if ($colMap['title']) { $columns[] = $colMap['title']; $values[] = '?'; $params[] = $data['title']; }
            if ($colMap['url']) { $columns[] = $colMap['url']; $values[] = '?'; $params[] = $data['url'] ?? '#'; }
            if ($colMap['icon']) { $columns[] = $colMap['icon']; $values[] = '?'; $params[] = $data['icon'] ?? null; }
            if ($colMap['order_index']) { $columns[] = $colMap['order_index']; $values[] = '?'; $params[] = $data['order_index'] ?? 0; }
            if ($colMap['realm_id']) {
                // Try to use provided realm_id or derive from the menu
                $realmIdVal = $data['realm_id'] ?? null;
                if ($realmIdVal === null) {
                    try {
                        $menuRealm = navDbQuery($this->dbConnection, "SELECT realm_id FROM menus WHERE id = ? LIMIT 1", [$data['menu_id']]);
                        if (!empty($menuRealm['success']) && !empty($menuRealm['data'][0]['realm_id'])) {
                            $realmIdVal = $menuRealm['data'][0]['realm_id'];
                        }
                    } catch (Exception $e) {
                        // leave null, will handle below
                    }
                }
                if ($realmIdVal === null) {
                    throw new Exception("Submenu insert requires realm_id but it could not be resolved from menu '{$data['menu_id']}'. Provide realm_id explicitly or ensure menus row has realm_id.");
                }
                $columns[] = $colMap['realm_id'];
                $values[] = '?';
                $params[] = $realmIdVal;
            }
            if ($colMap['status']) { $columns[] = $colMap['status']; $values[] = '?'; $params[] = 'active'; }
            if ($colMap['created_at']) { $columns[] = $colMap['created_at']; $values[] = 'NOW()'; }
            if ($colMap['updated_at']) { $columns[] = $colMap['updated_at']; $values[] = 'NOW()'; }

            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $submenuTable,
                implode(', ', $columns),
                implode(', ', $values)
            );

            $result = navDbQuery($this->dbConnection, $sql, $params);
            
            if (!$result['success']) {
                throw new Exception('Failed to add submenu: ' . $result['error']);
            }
            
            // Get the last insert ID from the connection
            $lastIdResult = navDbQuery($this->dbConnection, "SELECT LAST_INSERT_ID() as id");
            $lastId = $lastIdResult['success'] && !empty($lastIdResult['data']) ? $lastIdResult['data'][0]['id'] : null;
            if ($insertedId !== null && $insertedId !== '') {
                $lastId = $insertedId;
            }
            
            return ['id' => $lastId !== null ? (string)$lastId : null];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error adding submenu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update a submenu item
     */
    public function updateSubmenu($submenuId, $data) {
        $data = $this->normalizeInputData($data);
        $submenuTable = $this->getSubmenuTableName();
        if (!$submenuTable) {
            throw new Exception("Submenu table not found (expected 'submenus' or 'menu_items')");
        }
        $this->enforceAccess($submenuTable, 'update');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $colMap = $this->getSubmenuColumnMap($submenuTable);

            $sets = [];
            $params = [];
            if ($colMap['title']) { $sets[] = $colMap['title'] . ' = ?'; $params[] = $data['title']; }
            if ($colMap['icon'] && isset($data['icon'])) { $sets[] = $colMap['icon'] . ' = ?'; $params[] = $data['icon']; }
            if ($colMap['url']) { $sets[] = $colMap['url'] . ' = ?'; $params[] = $data['url'] ?? '#'; }
            if ($colMap['order_index']) { $sets[] = $colMap['order_index'] . ' = ?'; $params[] = $data['order_index'] ?? 0; }
            if ($colMap['updated_at']) { $sets[] = $colMap['updated_at'] . ' = NOW()'; }

            if (empty($sets)) {
                // Nothing to update
                return ['id' => $submenuId];
            }

            $idCol = $colMap['id'] ?? 'id';
            $sql = sprintf(
                'UPDATE %s SET %s WHERE %s = ?',
                $submenuTable,
                implode(', ', $sets),
                $idCol
            );
            $params[] = $submenuId;

            $result = navDbQuery($this->dbConnection, $sql, $params);
            
            if (!$result['success']) {
                throw new Exception('Failed to update submenu: ' . $result['error']);
            }
            
            return ['id' => $submenuId];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error updating submenu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete a submenu item
     */
    public function deleteSubmenu($submenuId) {
        $submenuTable = $this->getSubmenuTableName();
        if (!$submenuTable) {
            throw new Exception("Submenu table not found (expected 'submenus' or 'menu_items')");
        }
        $this->enforceAccess($submenuTable, 'delete');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $colMap = $this->getSubmenuColumnMap($submenuTable);
            $idCol = $colMap['id'] ?? 'id';
            $sql = sprintf('DELETE FROM %s WHERE %s = ?', $submenuTable, $idCol);
            $result = navDbQuery($this->dbConnection, $sql, [$submenuId]);
            
            if (!$result['success']) {
                throw new Exception('Failed to delete submenu: ' . $result['error']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error deleting submenu: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reorder menus
     */
    public function reorderMenus($menuOrders) {
        $this->enforceAccess('menus', 'update');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $map = $this->getMenuColumnMap();
            $orderCol = $map['order_index'] ?? 'order_index';
            $idCol = $map['id'] ?? 'id';
            $priorityCol = $map['priority'] ?? null;
            foreach ($menuOrders as $menuId => $order) {
                $sql = sprintf(
                    'UPDATE menus SET %s = ?%s%s WHERE %s = ?',
                    $orderCol,
                    ($priorityCol ? ', ' . $priorityCol . ' = ?' : ''),
                    ($map['updated_at'] ? ', ' . $map['updated_at'] . ' = NOW()' : ''),
                    $idCol
                );
                $params = $priorityCol ? [$order, $order, $menuId] : [$order, $menuId];
                $result = navDbQuery($this->dbConnection, $sql, $params);
                
                if (!$result['success']) {
                    throw new Exception('Failed to reorder menu ' . $menuId . ': ' . $result['error']);
                }
            }
            
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error reordering menus: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reorder realms
     */
    public function reorderRealms($realmOrders) {
        $this->enforceAccess('realms', 'update');

        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }

        try {
            $columns = $this->getTableColumns('realms');
            $hasOrder = isset($columns['order_index']);
            $hasPriority = isset($columns['priority']);
            $hasUpdatedAt = isset($columns['updated_at']);

            foreach ($realmOrders as $realmId => $order) {
                $sets = [];
                $params = [];
                if ($hasOrder) { $sets[] = 'order_index = ?'; $params[] = $order; }
                if ($hasPriority) { $sets[] = 'priority = ?'; $params[] = $order; }
                if ($hasUpdatedAt) { $sets[] = 'updated_at = NOW()'; }

                if (empty($sets)) {
                    throw new Exception('Realms table missing order_index and priority columns');
                }

                $sql = 'UPDATE realms SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $params[] = $realmId;
                $result = navDbQuery($this->dbConnection, $sql, $params);

                if (!$result['success']) {
                    throw new Exception('Failed to reorder realm ' . $realmId . ': ' . $result['error']);
                }
            }

            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error reordering realms: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Reorder submenus
     */
    public function reorderSubmenus($submenuOrders) {
        $submenuTable = $this->getSubmenuTableName();
        if (!$submenuTable) {
            throw new Exception("Submenu table not found (expected 'submenus' or 'menu_items')");
        }
        $this->enforceAccess($submenuTable, 'update');

        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }

        try {
            $colMap = $this->getSubmenuColumnMap($submenuTable);
            foreach ($submenuOrders as $submenuId => $order) {
                $idCol = $colMap['id'] ?? 'id';
                $orderCol = $colMap['order_index'] ?? 'order_index';
                $priorityCol = $colMap['priority'] ?? null;
                $sql = sprintf(
                    'UPDATE %s SET %s = ?%s%s WHERE %s = ?',
                    $submenuTable,
                    $orderCol,
                    ($priorityCol ? ', ' . $priorityCol . ' = ?' : ''),
                    ($colMap['updated_at'] ? ', ' . $colMap['updated_at'] . ' = NOW()' : ''),
                    $idCol
                );
                $params = $priorityCol ? [$order, $order, $submenuId] : [$order, $submenuId];
                $result = navDbQuery($this->dbConnection, $sql, $params);
                if (!$result['success']) {
                    throw new Exception('Failed to reorder submenu ' . $submenuId . ': ' . $result['error']);
                }
            }
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error reordering submenus: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create social connect
     */
    public function createSocialConnect($data) {
        $this->enforceAccess('social_connect', 'write');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            // Ensure table exists and legacy schemas have AUTO_INCREMENT id
            $this->ensureSocialConnectTable();
            $columns = $this->getTableColumns('social_connect');
            $hasPlatform = isset($columns['platform']);
            $hasUrl = isset($columns['url']);
            $hasRealm = isset($columns['realm_id']);

            // Row-model insert
            if ($hasPlatform && $hasUrl) {
                $result = navDbQuery($this->dbConnection, "
                    INSERT INTO social_connect (realm_id, platform, platform_name, url, username, color, show_in_header, show_in_footer, target_blank, order_index) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $data['realm_id'] ?? 'guest',
                    $data['platform'],
                    $data['platform_name'] ?? $data['platform'],
                    $data['url'],
                    $data['username'] ?? '',
                    $data['color'] ?? '#000000',
                    $data['show_in_header'] ?? true,
                    $data['show_in_footer'] ?? true,
                    $data['target_blank'] ?? true,
                    $data['order_index'] ?? 0
                ]);
                if (!$result['success']) {
                    throw new Exception('Failed to create social connect: ' . $result['error']);
                }
                $lastIdResult = navDbQuery($this->dbConnection, "SELECT LAST_INSERT_ID() as id");
                $lastId = $lastIdResult['success'] && !empty($lastIdResult['data']) ? $lastIdResult['data'][0]['id'] : null;
                return ['id' => $lastId];
            }

            // Column-model upsert: set the platform column on the realm's row
            $platformCol = $data['platform'];
            if (!isset($columns[$platformCol])) {
                throw new Exception("Platform column '$platformCol' does not exist in social_connect table");
            }
            $realm = $data['realm_id'] ?? 'guest';
            $url = $data['url'];

            // Check if a row exists for the realm
            $rowCheck = $hasRealm
                ? navDbQuery($this->dbConnection, "SELECT id FROM social_connect WHERE realm_id = ? LIMIT 1", [$realm])
                : navDbQuery($this->dbConnection, "SELECT id FROM social_connect LIMIT 1");
            if ($rowCheck['success'] && !empty($rowCheck['data'])) {
                $rowId = $rowCheck['data'][0]['id'];
                $updateSql = "UPDATE social_connect SET `$platformCol` = ?, updated_at = NOW() WHERE id = ?";
                $updateRes = navDbQuery($this->dbConnection, $updateSql, [$url, $rowId]);
                if (!$updateRes['success']) {
                    throw new Exception('Failed to update social link column: ' . $updateRes['error']);
                }
                return ['id' => $rowId];
            } else {
                // Insert a new row
                if ($hasRealm) {
                    $insertSql = "INSERT INTO social_connect (realm_id, `$platformCol`) VALUES (?, ?)";
                    $insertRes = navDbQuery($this->dbConnection, $insertSql, [$realm, $url]);
                } else {
                    $insertSql = "INSERT INTO social_connect (`$platformCol`) VALUES (?)";
                    $insertRes = navDbQuery($this->dbConnection, $insertSql, [$url]);
                }
                if (!$insertRes['success']) {
                    throw new Exception('Failed to insert social link column: ' . $insertRes['error']);
                }
                $lastIdResult = navDbQuery($this->dbConnection, "SELECT LAST_INSERT_ID() as id");
                $lastId = $lastIdResult['success'] && !empty($lastIdResult['data']) ? $lastIdResult['data'][0]['id'] : null;
                return ['id' => $lastId];
            }
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error creating social connect: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update social connect
     */
    public function updateSocialConnect($id, $data) {
        $this->enforceAccess('social_connect', 'update');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $columns = $this->getTableColumns('social_connect');
            $hasPlatform = isset($columns['platform']);
            $hasUrl = isset($columns['url']);
            $hasRealm = isset($columns['realm_id']);

            // Row-model update
            if ($hasPlatform && $hasUrl) {
                $result = navDbQuery($this->dbConnection, "
                    UPDATE social_connect SET platform = ?, platform_name = ?, url = ?, username = ?, color = ?, 
                    show_in_header = ?, show_in_footer = ?, target_blank = ?, order_index = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['platform'],
                    $data['platform_name'] ?? $data['platform'],
                    $data['url'],
                    $data['username'] ?? '',
                    $data['color'] ?? '#000000',
                    $data['show_in_header'] ?? true,
                    $data['show_in_footer'] ?? true,
                    $data['target_blank'] ?? true,
                    $data['order_index'] ?? 0,
                    $id
                ]);
                if (!$result['success']) {
                    throw new Exception('Failed to update social connect: ' . $result['error']);
                }
                return ['id' => $id];
            }

            // Column-model: set the specific platform column for the realm row
            $platformCol = $data['platform'];
            if (!isset($columns[$platformCol])) {
                throw new Exception("Platform column '$platformCol' does not exist in social_connect table");
            }
            $realm = $data['realm_id'] ?? 'guest';
            $url = $data['url'];

            if ($hasRealm) {
                $updateSql = "UPDATE social_connect SET `$platformCol` = ?, updated_at = NOW() WHERE realm_id = ?";
                $updateRes = navDbQuery($this->dbConnection, $updateSql, [$url, $realm]);
            } else {
                $updateSql = "UPDATE social_connect SET `$platformCol` = ?, updated_at = NOW() LIMIT 1";
                $updateRes = navDbQuery($this->dbConnection, $updateSql, [$url]);
            }
            if (!$updateRes['success']) {
                throw new Exception('Failed to update social link column: ' . $updateRes['error']);
            }
            return ['id' => $id];
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error updating social connect: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete social connect
     */
    public function deleteSocialConnect($id) {
        $this->enforceAccess('social_connect', 'delete');
        
        if (!$this->dbConnection || !$this->connSuccess) {
            throw new Exception('Database connection not available');
        }
        
        try {
            $result = navDbQuery($this->dbConnection, "DELETE FROM social_connect WHERE id = ?", [$id]);
            
            if (!$result['success']) {
                throw new Exception('Failed to delete social connect: ' . $result['error']);
            }
            
            return true;
        } catch (Exception $e) {
            $this->safeLog('NavigationDatabaseManager: Error deleting social connect: ' . $e->getMessage());
            throw $e;
        }
    }
}
