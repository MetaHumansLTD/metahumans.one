<?php
/**
 * Database Context Manager
 * Provides intelligent database selection based on page context and URI patterns
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses CUE framework functions
 * ✓ Follows enterprise security standards
 * ✓ Proper error handling and logging
 * ✓ Context-aware database selection
 * 
 * @version 1.0.0
 * @date 2025-10-26
 * @requires CUE Framework
 */

if (!function_exists('getDatabaseById')) {
    require_once dirname(__DIR__, 3) . '/.cue/cue.php';
}

class DatabaseContextManager {
    private $configPath;
    private $contextMappings = [];
    private $directoryMappings = [];
    private $autoSwitch = false;
    private $fallbackStrategy = 'session_preference';
    private $schemaVersion = '2.0.0';
    
    public function __construct() {
        $this->configPath = rtrim($this->getDataPath(), '/') . '/config/database-contexts.json';
        $this->loadConfiguration();
    }
    
    /**
     * Load context configuration from file
     */
    private function loadConfiguration() {
        if (file_exists($this->configPath)) {
            $config = json_decode(file_get_contents($this->configPath), true);
            if ($config) {
                $schema = $config['schema_version'] ?? null;
                $schemaStr = is_string($schema) ? trim($schema) : '';
                if ($schemaStr !== '' && $schemaStr !== $this->schemaVersion) {
                    $this->contextMappings = [];
                    $this->directoryMappings = [];
                    $this->autoSwitch = false;
                    $this->fallbackStrategy = 'session_preference';
                    return;
                }

                $this->contextMappings = is_array($config['page_mappings'] ?? null) ? ($config['page_mappings'] ?? []) : [];
                $rawDirMappings = is_array($config['directory_mappings'] ?? null) ? ($config['directory_mappings'] ?? []) : [];
                $normalized = [];
                foreach ($rawDirMappings as $dir => $db) {
                    if (!is_string($dir) || !is_string($db) || $db === '') {
                        continue;
                    }
                    $normalized[$this->normalizePathKey($dir)] = $db;
                }
                $this->directoryMappings = $normalized;
                $this->autoSwitch = is_bool($config['auto_switch'] ?? null) ? (bool)$config['auto_switch'] : false;
                $this->fallbackStrategy = is_string($config['fallback_strategy'] ?? null) ? (string)$config['fallback_strategy'] : 'session_preference';
            }
        }
    }

    private function normalizePathKey(string $path): string {
        $p = trim($path);
        if ($p === '') return '/';
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#/+#', '/', $p) ?: $p;
        if ($p[0] !== '/') $p = '/' . $p;
        if ($p !== '/') $p = rtrim($p, '/');
        return $p;
    }
    
    /**
     * Get appropriate database based on page context
     * 
     * @param string $pageUri The page URI to analyze
     * @param string $userRole User role for permission checks
     * @return string|null Database configuration ID
     */
    public function getContextDatabase($pageUri, $userRole = 'default') {
        $pageUriNorm = $this->normalizePathKey((string)$pageUri);
        // Check for exact full URI mapping first
        if (isset($this->contextMappings[$pageUriNorm])) {
            $this->logContextSelection($pageUriNorm, $this->contextMappings[$pageUriNorm], 'full_uri_mapping');
            return $this->contextMappings[$pageUriNorm];
        }
        
        $context = $this->analyzePageContext($pageUriNorm);
        
        // Check for exact page mapping (filename only)
        if (isset($this->contextMappings[$context['page']])) {
            $this->logContextSelection($pageUriNorm, $this->contextMappings[$context['page']], 'page_mapping');
            return $this->contextMappings[$context['page']];
        }
        
        // Check for directory-based mapping
        $directory = $context['directory'];
        while ($directory && $directory !== '/') {
            // Try exact match first
            if (isset($this->directoryMappings[$directory])) {
                $this->logContextSelection($pageUriNorm, $this->directoryMappings[$directory], 'directory_mapping');
                return $this->directoryMappings[$directory];
            }
            
            // Move up one directory level
            $directory = dirname($directory);
            if ($directory === '.') $directory = '/';
        }
        
        // Check root directory mapping
        if (isset($this->directoryMappings['/'])) {
            $this->logContextSelection($pageUri, $this->directoryMappings['/'], 'root_mapping');
            return $this->directoryMappings['/'];
        }
        
        // Fallback strategy
        return $this->applyFallbackStrategy();
    }
    
    /**
     * Analyze page context for intelligent routing
     * 
     * @param string $pageUri The page URI to analyze
     * @return array Context information
     */
    public function analyzePageContext($pageUri) {
        $pathInfo = pathinfo($pageUri);
        return [
            'page' => $pathInfo['filename'],
            'directory' => dirname($pageUri),
            'extension' => $pathInfo['extension'] ?? 'php',
            'depth' => substr_count($pageUri, '/'),
            'uri' => $pageUri,
            'basename' => $pathInfo['basename'] ?? ''
        ];
    }
    
    /**
     * Apply fallback strategy when no context mapping is found
     * 
     * @return string|null Database configuration ID
     */
    private function applyFallbackStrategy() {
        switch ($this->fallbackStrategy) {
            case 'session_preference':
                return $_SESSION['current_database_config_id'] ?? null;
            
            case 'default':
                return 'default';
            
            case 'none':
                return null;
            
            default:
                return $_SESSION['current_database_config_id'] ?? null;
        }
    }
    
    /**
     * Check if auto-switch is enabled
     * 
     * @return bool
     */
    public function isAutoSwitchEnabled() {
        return $this->autoSwitch;
    }
    
    /**
     * Add or update a page mapping
     * 
     * @param string $page Page identifier
     * @param string $database Database configuration ID
     * @return bool Success status
     */
    public function addPageMapping($page, $database) {
        $key = is_string($page) && strpos($page, '/') !== false ? $this->normalizePathKey($page) : $page;
        $this->contextMappings[$key] = $database;
        return $this->saveConfiguration();
    }
    
    /**
     * Add or update a directory mapping
     * 
     * @param string $directory Directory path
     * @param string $database Database configuration ID
     * @return bool Success status
     */
    public function addDirectoryMapping($directory, $database) {
        $canonical = $this->normalizePathKey((string)$directory);
        $this->directoryMappings[$canonical] = $database;
        return $this->saveConfiguration();
    }
    
    /**
     * Remove a page mapping
     * 
     * @param string $page Page identifier
     * @return bool Success status
     */
    public function removePageMapping($page) {
        if (isset($this->contextMappings[$page])) {
            unset($this->contextMappings[$page]);
            return $this->saveConfiguration();
        }
        return false;
    }
    
    /**
     * Remove a directory mapping
     * 
     * @param string $directory Directory path
     * @return bool Success status
     */
    public function removeDirectoryMapping($directory) {
        $removed = false;
        $key = $this->normalizePathKey((string)$directory);
        if (isset($this->directoryMappings[$key])) {
            unset($this->directoryMappings[$key]);
            $removed = true;
        }
        return $removed ? $this->saveConfiguration() : false;
    }
    
    /**
     * Get all current mappings
     * 
     * @return array All mappings
     */
    public function getAllMappings() {
        return [
            'page_mappings' => $this->contextMappings,
            'directory_mappings' => $this->directoryMappings,
            'auto_switch' => $this->autoSwitch,
            'fallback_strategy' => $this->fallbackStrategy
        ];
    }
    
    /**
     * Save configuration to file
     * 
     * @return bool Success status
     */
    private function saveConfiguration() {
        $config = [
            'schema_version' => $this->schemaVersion,
            'page_mappings' => $this->contextMappings,
            'directory_mappings' => $this->directoryMappings,
            'auto_switch' => $this->autoSwitch,
            'fallback_strategy' => $this->fallbackStrategy,
            'updated_at' => date('Y-m-d H:i:s'),
            'version' => $this->schemaVersion
        ];
        
        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        return file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Log context selection for audit purposes
     * 
     * @param string $pageUri Page URI
     * @param string $database Selected database
     * @param string $method Selection method
     */
    private function logContextSelection($pageUri, $database, $method) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'page_uri' => $pageUri,
            'selected_database' => $database,
            'selection_method' => $method,
            'user_id' => $_SESSION['user_id'] ?? 'anonymous',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $logFile = $this->getDataPath() . '/logs/database-context.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Generic add mapping helper for legacy API compatibility.
     */
    public function addMapping($pattern, $database, $priority = 1) {
        // If pattern looks like a directory (contains '/') treat as directory mapping
        if (strpos($pattern, '/') !== false && substr($pattern, -1) !== '.') {
            $this->directoryMappings[$this->normalizePathKey((string)$pattern)] = $database;
        } else {
            // Use filename (without extension) for page mapping
            $page = pathinfo($pattern, PATHINFO_FILENAME) ?: $pattern;
            $this->contextMappings[$page] = $database;
        }
        return $this->saveConfiguration();
    }

    /**
     * Update mapping by pattern key (page or directory).
     */
    public function updateMapping($id, $pattern, $database, $priority = 1) {
        // Remove existing mapping by id
        if (isset($this->contextMappings[$id])) unset($this->contextMappings[$id]);
        if (isset($this->directoryMappings[$id])) unset($this->directoryMappings[$id]);
        // Add new mapping
        return $this->addMapping($pattern, $database, $priority);
    }

    /**
     * Remove mapping by key (page or directory).
     */
    public function removeMapping($id) {
        $removed = false;
        if (isset($this->contextMappings[$id])) { unset($this->contextMappings[$id]); $removed = true; }
        if (isset($this->directoryMappings[$id])) { unset($this->directoryMappings[$id]); $removed = true; }
        return $removed ? $this->saveConfiguration() : false;
    }
    
    /**
     * Get data path (compatible with CUE framework)
     * 
     * @return string Data directory path
     */
    private function getDataPath() {
        if (function_exists('cue_autoload')) {
            cue_autoload('paths');
        }
        if (function_exists('paths_getDataPath')) {
            return paths_getDataPath();
        }
        
        // Fallback to standard path
        return dirname(dirname(dirname(__DIR__))) . '/.data';
    }
    
    /**
     * Enable or disable auto-switch
     * 
     * @param bool $enabled Auto-switch status
     * @return bool Success status
     */
    public function setAutoSwitch($enabled) {
        $this->autoSwitch = (bool)$enabled;
        return $this->saveConfiguration();
    }
    
    /**
     * Set fallback strategy
     * 
     * @param string $strategy Fallback strategy ('session_preference', 'default', 'none')
     * @return bool Success status
     */
    public function setFallbackStrategy($strategy) {
        $validStrategies = ['session_preference', 'default', 'none'];
        if (in_array($strategy, $validStrategies)) {
            $this->fallbackStrategy = $strategy;
            return $this->saveConfiguration();
        }
        return false;
    }
    
    /**
     * Test if a database configuration exists and is accessible
     * 
     * @param string $configId Database configuration ID
     * @return bool Availability status
     */
    public function isDatabaseAvailable($configId) {
        try {
            // Try to use CUE framework function if available
            if (function_exists('testDatabaseConnection')) {
                return testDatabaseConnection($configId);
            }
            
            // Fallback: check if config exists
            $configPath = $this->getDataPath() . '/config/databases.json';
            if (file_exists($configPath)) {
                $configs = json_decode(file_get_contents($configPath), true);
                return isset($configs[$configId]);
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Database availability check failed for {$configId}: " . $e->getMessage());
            return false;
        }
    }
}
?>
