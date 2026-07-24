<?php
/**
 * Page Permission Manager
 * Handles page-specific database and table access permissions
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses CUE framework functions
 * ✓ Follows enterprise security standards
 * ✓ Proper error handling and logging
 * ✓ Page-specific permission management
 * 
 * @version 1.0.0
 * @date 2025-10-26
 * @requires CUE Framework
 */

if (!function_exists('getDatabaseById')) {
    require_once dirname(__DIR__, 3) . '/.cue/cue.php';
}

class PagePermissionManager {
    private $permissionsFile;
    private $permissions = [];
    private $defaultPermissions = [];
    
    public function __construct() {
        $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
        $this->permissionsFile = $paths ? $paths->getSecureFilePath('config/page-permissions.json', true) : null;
        if ($this->permissionsFile) {
            $this->loadPermissions();
        }
    }
    
    /**
     * Load permissions from file
     */
    private function loadPermissions() {
        if ($this->permissionsFile && file_exists($this->permissionsFile)) {
            $data = json_decode(file_get_contents($this->permissionsFile), true);
            if ($data) {
                $this->permissions = $data['pages'] ?? [];
                $this->defaultPermissions = $data['default_permissions'] ?? [
                    'operations' => ['read'],
                    'auto_grant' => false
                ];
            }
        }
    }
    
    /**
     * Save permissions to file
     * 
     * @return bool Success status
     */
    private function savePermissions() {
        if (!$this->permissionsFile) {
            return false;
        }
        $data = [
            'pages' => $this->permissions,
            'default_permissions' => $this->defaultPermissions,
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => '1.0.0'
        ];
        $dir = dirname($this->permissionsFile);
        if (!is_dir($dir)) {
            return false;
        }
        return file_put_contents($this->permissionsFile, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Get all permissions
     * 
     * @return array All page permissions
     */
    public function getAllPermissions() {
        return $this->permissions;
    }
    
    /**
     * Get permissions for a specific page
     * 
     * @param string $pageUri Page URI
     * @return array|null Page permissions
     */
    public function getPagePermissions($pageUri) {
        $pageKey = $this->normalizePageKey($pageUri);
        return $this->permissions[$pageKey] ?? null;
    }
    
    /**
     * Get user role and permissions from Biometrics DB
     * 
     * @param string $username
     * @return array|null ['role' => string, 'permissions' => array] or null if not found
     */
    public function getUserPermissionsData($username) {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        $bioDb = null;
        try {
            if (function_exists('database_getConnectionById')) {
                $bioDb = database_getConnectionById('biometrics');
            }
        } catch (Throwable $e) {
            return null;
        }
        
        if (!$bioDb) return null;

        $cols = [];
        try {
            $cols = $bioDb->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Throwable $e) {
            $cols = [];
        }
        $cols = is_array($cols) ? $cols : [];
        $keyCol = in_array('username', $cols, true) ? 'username' : (in_array('uid', $cols, true) ? 'uid' : null);
        $roleCol = in_array('role', $cols, true) ? 'role' : (in_array('roles', $cols, true) ? 'roles' : null);
        $permCol = in_array('permissions', $cols, true) ? 'permissions' : null;

        if ($keyCol === null) {
            return null;
        }

        $select = [];
        if ($roleCol !== null) $select[] = "{$roleCol} AS role_val";
        if ($permCol !== null) $select[] = "{$permCol} AS perms_val";
        if (empty($select)) $select[] = "'' AS role_val";

        $stmt = $bioDb->prepare("SELECT " . implode(', ', $select) . " FROM users WHERE {$keyCol} = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return null;

        return [
            'role' => (string)($user['role_val'] ?? ''),
            'permissions' => (isset($user['perms_val']) && is_string($user['perms_val'])) ? (json_decode($user['perms_val'], true) ?: []) : []
        ];
    }

    /**
     * Check permissions against Biometrics DB
     * 
     * @param string $pageUri Page URI
     * @return bool Access status
     */
    public function checkBiometricAccess($pageUri) {
        // 1. Get current user
        $username = $_SESSION['mh_auth_user'] ?? null;
        if (!$username) {
            // Allow login/register pages
            if (strpos($pageUri, '/auth/') !== false) return true;
            return false;
        }

        // 2. Get User Role & Permissions
        $userData = $this->getUserPermissionsData($username);
        if (!$userData) return false;

        // 3. Check KripzMasters
        // Allow 'KripzMaster', 'KripzMasters', 'Admin', 'Admins' (Legacy support for system admins)
        $role = $userData['role'];
        if (stripos($role, 'KripzMasters') !== false || stripos($role, 'KripzMaster') !== false || 
            stripos($role, 'Admin') !== false || stripos($role, 'Admins') !== false) {
            return true;
        }

        // 4. STRICT RBAC (two roles):
        // - KripzMasters: full access (handled above)
        // - Users: access limited to /hub/*
        $cleanUri = parse_url($pageUri, PHP_URL_PATH);
        $cleanUri = is_string($cleanUri) ? $cleanUri : (string)$pageUri;
        if ($cleanUri === '') {
            return false;
        }
        if ($cleanUri === '/hub' || strpos($cleanUri, '/hub/') === 0) {
            return true;
        }
        return false;
    }

    /**
     * Check if page has access to specific table and operation
     * 
     * @param string $pageUri Page URI
     * @param string $tableName Table name
     * @param string $operation Operation type (read, write, update, delete)
     * @return bool Access status
     */
    public function hasTableAccess($pageUri, $tableName, $operation = 'read') {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (!isset($this->permissions[$pageKey])) {
            // Check if auto-grant is enabled for default permissions
            if ($this->defaultPermissions['auto_grant'] && 
                in_array($operation, $this->defaultPermissions['operations'])) {
                return true;
            }
            return false; // Deny by default
        }
        
        $tablePerms = $this->permissions[$pageKey]['tables'][$tableName] ?? [];
        return in_array($operation, $tablePerms['operations'] ?? []);
    }
    
    /**
     * Get all accessible tables for a page
     * 
     * @param string $pageUri Page URI
     * @return array Array of table names
     */
    public function getAccessibleTables($pageUri) {
        $pageKey = $this->normalizePageKey($pageUri);
        return array_keys($this->permissions[$pageKey]['tables'] ?? []);
    }
    
    /**
     * Get accessible tables with their operations
     * 
     * @param string $pageUri Page URI
     * @return array Array of tables with operations
     */
    public function getAccessibleTablesWithOperations($pageUri) {
        $pageKey = $this->normalizePageKey($pageUri);
        return $this->permissions[$pageKey]['tables'] ?? [];
    }
    
    /**
     * Add page permission
     * 
     * @param string $pageUri Page URI
     * @param string $database Database configuration ID
     * @param array $tables Table permissions array
     * @return bool Success status
     */
    public function addPagePermission($pageUri, $database, $tables = []) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        $this->permissions[$pageKey] = [
            'database' => $database,
            'tables' => $tables,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => $_SESSION['user_id'] ?? 'system',
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $this->logPermissionChange('add_page', $pageUri, $database, $tables);
        return $this->savePermissions();
    }
    
    /**
     * Update page permission
     * 
     * @param string $pageUri Page URI
     * @param string $database Database configuration ID (optional)
     * @param array $tables Table permissions array (optional)
     * @return bool Success status
     */
    public function updatePagePermission($pageUri, $database = null, $tables = null) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (!isset($this->permissions[$pageKey])) {
            return false;
        }
        
        if ($database !== null) {
            $this->permissions[$pageKey]['database'] = $database;
        }
        
        if ($tables !== null) {
            $this->permissions[$pageKey]['tables'] = $tables;
        }
        
        $this->permissions[$pageKey]['updated_at'] = date('Y-m-d H:i:s');
        $this->permissions[$pageKey]['updated_by'] = $_SESSION['user_id'] ?? 'system';
        
        $this->logPermissionChange('update_page', $pageUri, $database, $tables);
        return $this->savePermissions();
    }
    
    /**
     * Remove page permission
     * 
     * @param string $pageUri Page URI
     * @return bool Success status
     */
    public function removePagePermission($pageUri) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (isset($this->permissions[$pageKey])) {
            $oldPermissions = $this->permissions[$pageKey];
            unset($this->permissions[$pageKey]);
            $this->logPermissionChange('remove_page', $pageUri, $oldPermissions['database'] ?? null);
            return $this->savePermissions();
        }
        
        return false;
    }
    
    /**
     * Grant table access for a specific page
     * 
     * @param string $pageUri Page URI
     * @param string $tableName Table name
     * @param array $operations Operations to grant (read, write, update, delete)
     * @return bool Success status
     */
    public function grantTableAccess($pageUri, $tableName, $operations = ['read']) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (!isset($this->permissions[$pageKey])) {
            // Create page permission if it doesn't exist
            $this->permissions[$pageKey] = [
                'database' => null,
                'tables' => [],
                'created_at' => date('Y-m-d H:i:s'),
                'created_by' => $_SESSION['user_id'] ?? 'system'
            ];
        }
        
        $this->permissions[$pageKey]['tables'][$tableName] = [
            'operations' => array_unique($operations),
            'granted_at' => date('Y-m-d H:i:s'),
            'granted_by' => $_SESSION['user_id'] ?? 'system'
        ];
        
        $this->permissions[$pageKey]['updated_at'] = date('Y-m-d H:i:s');
        
        $this->logPermissionChange('grant_table', $pageUri, null, [$tableName => $operations]);
        return $this->savePermissions();
    }
    
    /**
     * Revoke table access for a specific page
     * 
     * @param string $pageUri Page URI
     * @param string $tableName Table name
     * @return bool Success status
     */
    public function revokeTableAccess($pageUri, $tableName) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (isset($this->permissions[$pageKey]['tables'][$tableName])) {
            unset($this->permissions[$pageKey]['tables'][$tableName]);
            $this->permissions[$pageKey]['updated_at'] = date('Y-m-d H:i:s');
            
            $this->logPermissionChange('revoke_table', $pageUri, null, [$tableName]);
            return $this->savePermissions();
        }
        
        return false;
    }
    
    /**
     * Update table operations for a specific page
     * 
     * @param string $pageUri Page URI
     * @param string $tableName Table name
     * @param array $operations New operations array
     * @return bool Success status
     */
    public function updateTableOperations($pageUri, $tableName, $operations) {
        $pageKey = $this->normalizePageKey($pageUri);
        
        if (isset($this->permissions[$pageKey]['tables'][$tableName])) {
            $this->permissions[$pageKey]['tables'][$tableName]['operations'] = array_unique($operations);
            $this->permissions[$pageKey]['tables'][$tableName]['updated_at'] = date('Y-m-d H:i:s');
            $this->permissions[$pageKey]['tables'][$tableName]['updated_by'] = $_SESSION['user_id'] ?? 'system';
            $this->permissions[$pageKey]['updated_at'] = date('Y-m-d H:i:s');
            
            $this->logPermissionChange('update_table', $pageUri, null, [$tableName => $operations]);
            return $this->savePermissions();
        }
        
        return false;
    }
    
    /**
     * Batch check table access for multiple tables and operations
     * 
     * @param string $pageUri Page URI
     * @param array $tableOperations Array of table => operations mappings
     * @return array Results array
     */
    public function batchCheckTableAccess($pageUri, $tableOperations) {
        $results = [];
        
        foreach ($tableOperations as $table => $operations) {
            $results[$table] = [];
            foreach ($operations as $operation) {
                $results[$table][$operation] = $this->hasTableAccess($pageUri, $table, $operation);
            }
        }
        
        return $results;
    }
    
    /**
     * Get pages that have access to a specific table
     * 
     * @param string $tableName Table name
     * @return array Array of page URIs
     */
    public function getPagesWithTableAccess($tableName) {
        $pages = [];
        
        foreach ($this->permissions as $pageUri => $pageData) {
            if (isset($pageData['tables'][$tableName])) {
                $pages[] = $pageUri;
            }
        }
        
        return $pages;
    }
    
    /**
     * Validate operation type
     * 
     * @param string $operation Operation to validate
     * @return bool Valid operation status
     */
    public function isValidOperation($operation) {
        $validOperations = ['read', 'write', 'update', 'delete'];
        return in_array($operation, $validOperations);
    }
    
    /**
     * Normalize page key for consistency
     * 
     * @param string $pageUri Page URI
     * @return string Normalized page key
     */
    private function normalizePageKey($pageUri) {
        // Remove leading/trailing slashes and normalize
        $normalized = trim($pageUri, '/');
        
        // Handle empty string (root)
        if (empty($normalized)) {
            return 'index';
        }
        
        return $normalized;
    }
    
    /**
     * Log permission changes for audit purposes
     * 
     * @param string $action Action performed
     * @param string $pageUri Page URI
     * @param string|null $database Database ID
     * @param array|null $tables Table data
     */
    private function logPermissionChange($action, $pageUri, $database = null, $tables = null) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'page_uri' => $pageUri,
            'database' => $database,
            'tables' => $tables,
            'user_id' => $_SESSION['user_id'] ?? 'system',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
        $logFile = $paths ? $paths->getSecureFilePath('logs/page-permissions.log', true) : null;
        if ($logFile && file_exists(dirname($logFile))) {
            file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Get data path (compatible with CUE framework)
     * 
     * @return string Data directory path
     */
    private function getDataPath() {
        if (function_exists('cue_autoload') && cue_autoload('paths')) {
            return cue_autoload('paths')->getDataPath();
        }
        return dirname(dirname(dirname(__DIR__))) . '/.data';
    }
    
    /**
     * Export permissions for backup or migration
     * 
     * @return array Complete permissions data
     */
    public function exportPermissions() {
        return [
            'pages' => $this->permissions,
            'default_permissions' => $this->defaultPermissions,
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => $_SESSION['user_id'] ?? 'system',
            'version' => '1.0.0'
        ];
    }
    
    /**
     * Import permissions from backup or migration
     * 
     * @param array $data Permissions data to import
     * @param bool $overwrite Whether to overwrite existing permissions
     * @return bool Success status
     */
    public function importPermissions($data, $overwrite = false) {
        if (!is_array($data) || !isset($data['pages'])) {
            return false;
        }
        
        if ($overwrite) {
            $this->permissions = $data['pages'];
        } else {
            // Merge with existing permissions
            $this->permissions = array_merge($this->permissions, $data['pages']);
        }
        
        if (isset($data['default_permissions'])) {
            $this->defaultPermissions = $data['default_permissions'];
        }
        
        $this->logPermissionChange('import_permissions', 'bulk', null, ['overwrite' => $overwrite, 'count' => count($data['pages'])]);
        return $this->savePermissions();
    }
    
    /**
     * Clean up orphaned permissions (pages that no longer exist)
     * 
     * @param array $existingPages Array of existing page URIs
     * @return int Number of permissions cleaned up
     */
    public function cleanupOrphanedPermissions($existingPages = []) {
        $cleaned = 0;
        
        foreach (array_keys($this->permissions) as $pageKey) {
            if (!empty($existingPages) && !in_array($pageKey, $existingPages)) {
                unset($this->permissions[$pageKey]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->logPermissionChange('cleanup_orphaned', 'bulk', null, ['cleaned_count' => $cleaned]);
            $this->savePermissions();
        }
        
        return $cleaned;
    }
}
?>
