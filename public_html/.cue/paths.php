<?php
/**
 * CUE Framework Paths Module
 *
 * Centralized path management for the framework.
 * Loaded on-demand to improve performance.
 *
 * @package    CUE Framework
 * @version    100.0.1
 */

// -----------------------------------------------------------------------------
// PATH CONFIGURATION
// -----------------------------------------------------------------------------

/**
 * Get the data directory path
 * Located outside public_html for security
 * @return string Full path to data directory
 */
function paths_getDataPath(): string {
    return '/data';
}

/**
 * Get the backups directory path
 * Located outside public_html for security
 * @return string Full path to backups directory
 */
function paths_getBackupsPath(): string {
    if (is_dir('/backup')) {
        return '/backup';
    }
    return ROOT_PATH . '/.backups';
}

/**
 * Get the configuration directory path
 * @return string Full path to config directory
 */
function paths_getConfigPath(): string {
    return paths_getDataPath() . '/config';
}

/**
 * Get the sessions directory path
 * @return string Full path to sessions directory
 */
function paths_getSessionsPath(): string {
    return paths_getDataPath() . '/sessions';
}

/**
 * Get the temporary directory path
 * @return string Full path to temp directory
 */
function paths_getTempPath(): string {
    return paths_getDataPath() . '/temp';
}

/**
 * Get the cache directory path
 * @return string Full path to cache directory
 */
function paths_getCachePath(): string {
    return paths_getDataPath() . '/cache';
}

/**
 * Get the themes configuration directory path
 * @return string Full path to themes config directory
 */
function paths_getThemesPath(): string {
    return paths_getDataPath() . '/theme';
}

/**
 * Get the encryption key path
 * @return string Full path to encryption key file
 */
function paths_getEncryptionKeyPath(): string {
    return paths_getDataPath() . '/security/app.key';
}

/**
 * Get the public assets directory path
 * @return string Full path to assets directory
 */
function paths_getAssetsPath(): string {
    return ROOT_PATH . '/public_html/templates/assets';
}

/**
 * Get the base URL for assets
 * @return string Base URL
 */
function paths_getBaseUrl(): string {
    return 'https://metahumans.one/templates/assets';
}

/**
 * Get the studio directory path
 * @return string Full path to studio directory
 */
function paths_getStudioPath(): string {
    return ROOT_PATH . '/public_html/studio';
}

/**
 * Get tenant-specific path from context if available
 * @param string $type 'vector' or 'graph'
 * @return string|null Path or null if not found
 */
function paths_getTenantContextPath(string $type): ?string {
    if (session_status() === PHP_SESSION_NONE) {
        return null;
    }
    if (!isset($_SESSION['mh_auth_user'])) {
        return null;
    }
    
    // Check for cached context in session
    $sessionKey = 'mh_tenant_' . $type . '_path';
    if (isset($_SESSION[$sessionKey])) {
        return $_SESSION[$sessionKey];
    }

    $username = $_SESSION['mh_auth_user'];
    $tenantId = 'user:' . $username;
    
    // Load tenant contexts
    $contextFile = paths_getConfigPath() . '/tenant-contexts.json';
    if (!file_exists($contextFile)) {
        return null;
    }
    
    $contexts = json_decode(file_get_contents($contextFile), true);
    if (!is_array($contexts) || !isset($contexts[$tenantId])) {
        return null;
    }
    
    $path = $contexts[$tenantId][$type . '_path'] ?? null;
    if ($path) {
        $_SESSION[$sessionKey] = $path; // Cache it
    }
    
    return $path;
}

/**
 * Get the MySQL storage path
 * @return string Full path to MySQL storage
 */
function paths_getMysqlPath(): string {
    return '/mysql';
}

/**
 * Get the vector storage path
 * @return string Full path to vector storage
 */
function paths_getVectorPath(): string {
    $tenantPath = paths_getTenantContextPath('vector');
    if ($tenantPath) {
        return $tenantPath;
    }
    // Fallback to root /vector if it exists, otherwise .data/vector
    if (is_dir('/vector')) {
        return '/vector';
    }
    return paths_getDataPath() . '/vector';
}

/**
 * Get the graph storage path
 * @return string Full path to graph storage
 */
function paths_getGraphPath(): string {
    $tenantPath = paths_getTenantContextPath('graph');
    if ($tenantPath) {
        return $tenantPath;
    }
    // Fallback to root /graph if it exists, otherwise .data/graph
    if (is_dir('/graph')) {
        return '/graph';
    }
    return paths_getDataPath() . '/graph';
}

function paths_getPersonaContextFile(): string {
    return paths_getConfigPath() . '/persona-context.json';
}

function paths_getMetaHumansContextFile(): string {
    return paths_getConfigPath() . '/meta_humans_context.json';
}

function paths_getModelStorePath(): string {
    if (is_dir('/mh-modelstore')) {
        return '/mh-modelstore';
    }
    return paths_getDataPath() . '/models';
}

/**
 * Get the authentication URL
 * @return string Auth URL
 */
function paths_getAuthUrl(): string {
    return 'https://metahumans.one/auth';
}

// -----------------------------------------------------------------------------
// HELPER FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Validate and secure a file path
 * Prevents directory traversal attacks
 *
 * @param string $relativePath Relative path to validate
 * @param bool $createDir Whether to create directory if missing
 * @return string|false Full secure path or false if invalid
 */
function paths_getSecureFilePath(string $relativePath, bool $createDir = false): string|false {
    $basePath = paths_getDataPath();
    $fullPath = $basePath . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
    
    $securePath = paths_validateSecurePath($fullPath, $basePath);
    
    if ($securePath === false) {
        return false;
    }
    
    if ($createDir) {
        $dir = dirname($securePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: $dir");
                return false;
            }
        }
    }
    
    return $securePath;
}

/**
 * Validate that a path is within a base directory
 *
 * @param string $path Path to validate
 * @param string $basePath Base directory to restrict to
 * @return string|false Canonical path or false if invalid
 */
function paths_validateSecurePath(string $path, string $basePath): string|false {
    $realBase = realpath($basePath);
    if ($realBase === false) {
        // If base path doesn't exist, try to create it?
        // No, strict validation means base must exist.
        // But for .data/config/db_configs.json, .data must exist.
        // We know .data exists.
        return false;
    }

    // Resolve .. in path but don't require file to exist
    // However, realpath() requires file to exist.
    // If file doesn't exist, we can check dirname.
    
    // For now, simple string check + realpath check if exists
    if (file_exists($path)) {
        $realPath = realpath($path);
        if ($realPath === false || strpos($realPath, $realBase) !== 0) {
            return false;
        }
        return $realPath;
    }

    // If file doesn't exist, check parent dir
    $dir = dirname($path);
    if (is_dir($dir)) {
        $realDir = realpath($dir);
        if ($realDir === false || strpos($realDir, $realBase) !== 0) {
            return false;
        }
    } else {
        // If parent dir doesn't exist, we can't validate easily without creating it.
        // But getSecureFilePath handles creation.
        // Here we just validate string path isn't traversing up.
        // We can use normalized string check.
    }

    // Fallback: simple string validation
    // Remove ./ and ../
    $normalized = str_replace(['\\', '//'], '/', $path);
    if (strpos($normalized, '../') !== false) {
        return false;
    }
    
    return $path;
}

/**
 * Sanitize a filename
 * Remove unsafe characters
 *
 * @param string $filename Filename to sanitize
 * @return string Sanitized filename
 */
function paths_sanitizeFilename(string $filename): string {
    return preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $filename);
}
