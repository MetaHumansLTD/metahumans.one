<?php
/**
 * Enhanced Database Context Functions
 * Context-aware database selection and permission enforcement
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getDataPath() for file operations
 * ✓ Uses CUE framework functions
 * ✓ Follows enterprise security standards
 * ✓ Proper error handling and logging
 * 
 * @version 1.0.0
 * @date 2025-10-26
 * @requires CUE Framework
 */

require_once(__DIR__ . '/../../../.cue/cue.php');
require_once(__DIR__ . '/../classes/DatabaseContextManager.php');
$pagePermMgrPath = __DIR__ . '/../classes/PagePermissionManager.php';
if (is_file($pagePermMgrPath)) {
    require_once($pagePermMgrPath);
}

/**
 * Get all database configurations
 * Loads database configurations from the config file
 * 
 * @return array Database configurations
 */
function getDatabaseConfigs() {
    static $configs = null;
    
    if ($configs === null) {
        $configs = [];
        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
            if (function_exists('database_loadConfigurations')) {
                $configs = database_loadConfigurations();
            }
        } catch (Throwable $e) {
            $configs = [];
        }
    }
    
    return $configs;
}

/**
 * Register a function in the function registry
 * This is used for tracking available functions for debugging/documentation
 * 
 * @param string $functionName Name of the function to register
 * @return bool Success status
 */
function registerFunction($functionName) {
    static $registeredFunctions = [];
    
    if (!in_array($functionName, $registeredFunctions)) {
        $registeredFunctions[] = $functionName;
        
        // Optionally log to a registry file for debugging
        $paths = cue_autoload('paths');
        $registryPath = $paths ? $paths->getSecureFilePath('logs/function-registry.log', true) : null;
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'function' => $functionName,
            'registered_by' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'unknown'
        ];
        if ($registryPath && file_exists(dirname($registryPath))) {
            file_put_contents($registryPath, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    return true;
}

/**
 * Get database with context awareness
 * Automatically selects appropriate database based on page context
 * 
 * @param string|null $forcePage Force specific page context
 * @return PDO|null Database connection
 */
if (!function_exists('getContextAwareDatabase')) {
function getContextAwareDatabase($forcePage = null) {
    static $contextManager = null;
    if ($contextManager === null) {
        $contextManager = new DatabaseContextManager();
    }
    $currentPage = $forcePage ?? getCurrentPageUri();
    $contextDb = $contextManager->getContextDatabase($currentPage);
    if ($contextDb && databaseConfigExists($contextDb)) {
        if ($contextManager->isDatabaseAvailable($contextDb)) {
            if (function_exists('database_getConfiguration') && function_exists('database_inferStorageProfile')) {
                $cfg = database_getConfiguration((string)$contextDb);
                if (is_array($cfg)) {
                    $profile = database_inferStorageProfile($cfg);
                    $p = '/' . ltrim((string)$currentPage, '/');
                    if ($profile === 'whm_mysql' && strpos($p, '/gear/settings/whm-') !== 0) {
                        $contextDb = null;
                    }
                }
            }
            if ($contextDb === null) {
                if (cue_autoload('database')) {
                    return cue_autoload('database')->getContextAwareConnection();
                }
                return null;
            }
            $_SESSION['current_database_config_id'] = $contextDb;
            $_SESSION['mh_db_preference'] = $contextDb;
            logDatabaseSwitch($currentPage, $contextDb);
            if (cue_autoload('database')) {
                return cue_autoload('database')->getContextAwareConnection($contextDb);
            }
        }
    }
    if (cue_autoload('database')) {
        return cue_autoload('database')->getContextAwareConnection();
    }
    return null;
}
}

/**
 * Get current page URI from server variables
 * 
 * @return string Current page URI
 */
function getCurrentPageUri() {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Prefer SCRIPT_NAME to preserve full path like 'templates/menus/navigator.php'
    if (!empty($scriptName)) {
        $pageUri = trim($scriptName, '/');
    } else {
        // Fallback to REQUEST_URI without query string
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $pageUri = strtok($requestUri, '?');
        $pageUri = ltrim($pageUri, '/');
    }
    
    // Handle empty URI (root)
    if (empty($pageUri)) {
        $pageUri = 'index.php';
    }
    
    return $pageUri;
}

/**
 * Check if database configuration exists
 * 
 * @param string $configId Database configuration ID
 * @return bool Configuration exists status
 */
function databaseConfigExists($configId) {
    try {
        // Try CUE framework function first
        if (function_exists('getDatabaseConfigs')) {
            $configs = getDatabaseConfigs();
            return isset($configs[$configId]);
        }
        
        // Fallback: check config file directly via secure path
        $paths = cue_autoload('paths');
        $configPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;
        if ($configPath && file_exists($configPath)) {
            $configs = json_decode(file_get_contents($configPath), true);
            return isset($configs[$configId]);
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Database config check failed for {$configId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Initialize page-specific database context
 * Call this at the beginning of pages that need context-aware database selection
 * 
 * @param string|null $pageUri Specific page URI (optional)
 */
function initializePageDatabase($pageUri = null) {
    $contextManager = new DatabaseContextManager();
    
    if ($contextManager->isAutoSwitchEnabled()) {
        if (cue_autoload('database')) {
            cue_autoload('database')->getContextAwareConnection();
        }
    }
}

/**
 * Check if auto-switch is enabled
 * 
 * @return bool Auto-switch status
 */
function isAutoSwitchEnabled() {
    $contextManager = new DatabaseContextManager();
    return $contextManager->isAutoSwitchEnabled();
}

/**
 * Enforce table access permissions for current page
 * 
 * @param string $tableName Table name
 * @param string $operation Operation type (read, write, update, delete)
 * @param string|null $pageUri Page URI (optional, auto-detected if not provided)
 * @throws Exception If access is denied
 */
function enforceTableAccess($tableName, $operation = 'read', $pageUri = null) {
    if (!class_exists('PagePermissionManager')) {
        return;
    }
    $permissionManager = new PagePermissionManager();
    $currentPage = $pageUri ?? getCurrentPageUri();
    
    if (!$permissionManager->hasTableAccess($currentPage, $tableName, $operation)) {
        throw new Exception("Access denied: Page '$currentPage' cannot perform '$operation' on table '$tableName'");
    }
}

/**
 * Enforce page permissions
 * Placeholder for future implementation using PagePermissionManager
 * 
 * @param string|null $pageUri Page URI (optional)
 * @return bool Always true for now (logging only)
 */
if (!function_exists('enforcePagePermissions')) {
function enforcePagePermissions($pageUri = null) {
    $currentPage = $pageUri ?? getCurrentPageUri();
    // error_log("Page Permission Check: Allowed access to $currentPage");
    return true;
}
}

/**
 * Log database switch event
 * 
 * @param string $pageUri Page URI
 * @param string $dbConfigId Database Config ID
 */
function logDatabaseSwitch($pageUri, $dbConfigId) {
    // Optional logging
}
