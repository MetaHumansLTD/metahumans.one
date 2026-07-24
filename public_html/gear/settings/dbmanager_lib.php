<?php
/**
 * Lightweight DB Manager library
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getSecureFilePath() for file operations
 * ✓ Uses getDatabaseById() instead of direct PDO connections
 * ✓ Uses framework encryption functions
 * ✓ Follows enterprise security standards
 * 
 * Provides core functions without rendering the UI so it can be safely included
 * by other pages (e.g., navigator) without leaking HTML/JS.
 */

// MANDATORY: Ensure CUE framework is loaded
require_once dirname(__DIR__, 2) . '/.cue/cue.php';

if (!function_exists('connectToDatabase')) {
    /**
     * DEPRECATED: Use getDatabaseById() instead
     * This function is kept for backward compatibility only
     */
    function connectToDatabase($config) {
        error_log('DEPRECATED: connectToDatabase() called. Use database_getConnectionById() or context-aware connection.');
        
        // Extract config ID if provided
        if (isset($config['id'])) {
            if (cue_autoload('database')) {
                return cue_autoload('database')->getContextAwareConnection($config['id']);
            }
            return null;
        }
        
        // Fallback to context-aware database
        if (cue_autoload('database')) {
            return cue_autoload('database')->getContextAwareConnection();
        }
        $sessionId = $_SESSION['current_database_config_id'] ?? null;
        if ($sessionId && function_exists('database_getConfiguration')) {
             $dbConfig = database_getConfiguration($sessionId);
             if ($dbConfig && cue_autoload('database')) {
                return cue_autoload('database')->getContextAwareConnection($sessionId);
             }
        }
        return null;
    }
}

if (!function_exists('getDatabaseConfigById')) {
    function getDatabaseConfigById($configId) {
        try {
            $paths = cue_autoload('paths');
            $configsPath = $paths ? $paths->getSecureFilePath('config/db_configs.json', true) : null;

            if (!$configsPath || !file_exists($configsPath)) {
                return ['success' => false, 'message' => 'No configurations found'];
            }

            $configs = json_decode(file_get_contents($configsPath), true) ?? [];

            // Find the configuration by ID
            $config = null;
            foreach ($configs as $cfg) {
                if (isset($cfg['id']) && $cfg['id'] === $configId) {
                    $config = $cfg;
                    break;
                }
            }

            if (!$config) {
                return ['success' => false, 'message' => 'Configuration not found'];
            }

            // Get encryption key using CUE paths - prioritize db_key.key for config decryption
            $paths = cue_autoload('paths');
            $keyPath = $paths->getConfigPath() . '/db_key.key';
            if (!file_exists($keyPath)) {
                $keyPath = $paths->getEncryptionKeyPath();
            }
            $encryptionKey = file_exists($keyPath) ? trim(file_get_contents($keyPath)) : '';
            
            // Decrypt sensitive data for editing
            $security = cue_autoload('security');
            if ($security) {
                if (strlen($config['host']) > 30) {
                    $decryptedHost = $security->decryptValue($config['host'], $encryptionKey);
                    if ($decryptedHost !== '') $config['host'] = $decryptedHost;
                }
                
                if (strlen($config['database']) > 30) {
                    $decryptedDb = $security->decryptValue($config['database'], $encryptionKey);
                    if ($decryptedDb !== '') $config['database'] = $decryptedDb;
                }
                
                if (strlen($config['username']) > 30) {
                    $decryptedUser = $security->decryptValue($config['username'], $encryptionKey);
                    if ($decryptedUser !== '') $config['username'] = $decryptedUser;
                }
                
                if (strlen($config['password']) > 30) {
                    $decryptedPass = $security->decryptValue($config['password'], $encryptionKey);
                    if ($decryptedPass !== '') $config['password'] = $decryptedPass;
                }
            }

            return ['success' => true, 'config' => $config];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to get configuration: ' . $e->getMessage()];
        }
    }
}
