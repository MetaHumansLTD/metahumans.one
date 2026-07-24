<?php
/**
 * CUE Framework Security Module
 *
 * Security, encryption, and validation functions.
 * Loaded on-demand to improve performance.
 *
 * @package    CUE Framework
 * @version    75.0.1
 * 
 * NOTE: This module handles encryption for Tenant Data (e.g. PINs).
 * Tenant PINs are stored ENCRYPTED in tenant_user_*, but must be HASHED
 * (using password_hash) when syncing to biometrics.users for authentication.
 */

// -----------------------------------------------------------------------------
// ENCRYPTION FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Encrypt a value using AES-256-CBC
 * @param string $value Value to encrypt
 * @param string $key Encryption key
 * @return string Base64 encoded encrypted data
 */
function security_encryptValue(string $value, string $key): string {
    if (empty($value)) return $value;

    // Validate encryption key
    if (!security_validateEncryptionKey($key)) {
        throw new InvalidArgumentException('Invalid encryption key format');
    }

    $cipher = 'aes-256-cbc';
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv = openssl_random_pseudo_bytes($ivLength);

    $encrypted = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($encrypted === false) {
        throw new Exception('Encryption failed');
    }

    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt a value using AES-256-CBC with enhanced validation
 * @param string $encryptedData Base64 encoded encrypted data
 * @param string $key Encryption key
 * @return string Decrypted value
 */
function security_decryptValue(string $encryptedData, string $key): string {
    if (empty($encryptedData)) return $encryptedData;

    // Validate encryption key
    if (!security_validateEncryptionKey($key)) {
        throw new InvalidArgumentException('Invalid encryption key format');
    }

    $cipher = 'aes-256-cbc';
    $ivLength = openssl_cipher_iv_length($cipher);

    $data = base64_decode($encryptedData, true);
    if ($data === false) {
        error_log("Invalid base64 encoded data");
        return '';
    }

    // Check if the data is long enough to contain IV + encrypted data
    if (strlen($data) < $ivLength + 1) {
        error_log("Encrypted data too short for IV extraction: " . strlen($data) . " bytes, need at least " . ($ivLength + 1));
        return '';
    }

    $iv = substr($data, 0, $ivLength);
    $encrypted = substr($data, $ivLength);

    // Verify IV length
    if (strlen($iv) !== $ivLength) {
        error_log("IV length mismatch: got " . strlen($iv) . " bytes, expected " . $ivLength);
        return '';
    }

    $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($decrypted === false) {
        error_log("Decryption failed: " . openssl_error_string());
        return '';
    }

    return $decrypted;
}

/**
 * Validate encryption key format and strength
 *
 * @param string $key The encryption key to validate
 * @return bool True if key is valid
 */
function security_validateEncryptionKey(string $key): bool {
    // Check if key is empty
    if (empty($key)) {
        return false;
    }

    // Remove whitespace
    $key = trim($key);

    // Check if key is hexadecimal (for AES-256, should be 64 hex characters = 32 bytes)
    if (!preg_match('/^[a-fA-F0-9]{64}$/', $key)) {
        return false;
    }

    // Additional entropy check - ensure key is not all zeros or simple patterns
    if (preg_match('/^0+$/', $key) || preg_match('/^(.)\1+$/', $key)) {
        return false;
    }

    return true;
}

/**
 * Generate a new secure encryption key
 *
 * @return string New 256-bit encryption key in hexadecimal format
 */
function security_generateEncryptionKey(): string {
    return bin2hex(random_bytes(32)); // 32 bytes = 256 bits
}

/**
 * Check encryption key for corruption or tampering
 *
 * @param string $key The key to check
 * @return array Check result with status and details
 */
function security_checkEncryptionKeyIntegrity(string $key): array {
    $result = [
        'valid' => false,
        'issues' => [],
        'recommendations' => []
    ];

    // Basic format validation
    if (!security_validateEncryptionKey($key)) {
        $result['issues'][] = 'Invalid key format';
        $result['recommendations'][] = 'Generate a new encryption key';
        return $result;
    }

    // Test encryption/decryption cycle
    try {
        $testData = 'test_encryption_' . time();
        $encrypted = security_encryptValue($testData, $key);
        $decrypted = security_decryptValue($encrypted, $key);

        if ($decrypted !== $testData) {
            $result['issues'][] = 'Key fails encryption/decryption test';
            $result['recommendations'][] = 'Key may be corrupted, consider rotation';
            return $result;
        }
    } catch (Exception $e) {
        $result['issues'][] = 'Encryption test failed: ' . $e->getMessage();
        $result['recommendations'][] = 'Key is corrupted, immediate rotation required';
        return $result;
    }

    // Check key age (if we can determine it)
    $keyPath = cue_autoload('paths')->getEncryptionKeyPath();
    if (file_exists($keyPath)) {
        $keyAge = time() - filemtime($keyPath);
        $maxAge = 90 * 24 * 3600; // 90 days

        if ($keyAge > $maxAge) {
            $result['recommendations'][] = 'Key is older than 90 days, consider rotation';
        }
    }

    $result['valid'] = true;
    return $result;
}

// -----------------------------------------------------------------------------
// INPUT VALIDATION FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Validate input parameters with comprehensive checks
 * @param mixed $value The value to validate
 * @param string $type Expected type (string, int, float, bool, array, email, etc.)
 * @param array $options Additional validation options
 * @return array Validation result with 'valid' boolean and 'error' message
 */
function security_validateInput($value, string $type, array $options = []): array {
    $result = ['valid' => true, 'error' => '', 'sanitized' => $value];

    try {
        // ENTERPRISE RATE LIMITING CHECK
        if (isset($options['rate_limit']) && $options['rate_limit']) {
            $rateLimitKey = 'input_validation_' . $type;
            if (!security_checkRateLimit($rateLimitKey)) {
                return [
                    'valid' => false,
                    'error' => 'Rate limit exceeded. Too many validation attempts.',
                    'sanitized' => null,
                    'rate_limited' => true
                ];
            }
        }

        // Check required
        if (isset($options['required']) && $options['required'] && (is_null($value) || $value === '')) {
            return ['valid' => false, 'error' => 'Value is required', 'sanitized' => null];
        }

        // Skip validation if value is null/empty and not required
        if (is_null($value) || $value === '') {
            return $result;
        }

        switch (strtolower($type)) {
            case 'string':
                if (!is_string($value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be a string';
                    break;
                }

                // Length validation
                if (isset($options['min_length']) && strlen($value) < $options['min_length']) {
                    $result['valid'] = false;
                    $result['error'] = "String must be at least {$options['min_length']} characters";
                    break;
                }

                if (isset($options['max_length']) && strlen($value) > $options['max_length']) {
                    $result['valid'] = false;
                    $result['error'] = "String must not exceed {$options['max_length']} characters";
                    break;
                }

                // Pattern validation
                if (isset($options['pattern']) && !preg_match($options['pattern'], $value)) {
                    $result['valid'] = false;
                    $result['error'] = 'String does not match required pattern';
                    break;
                }

                // Sanitize if requested
                if (isset($options['sanitize']) && $options['sanitize']) {
                    $result['sanitized'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }
                break;

            case 'int':
            case 'integer':
                if (!is_numeric($value) || (int)$value != $value) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be an integer';
                    break;
                }

                $intValue = (int)$value;
                $result['sanitized'] = $intValue;

                // Range validation
                if (isset($options['min']) && $intValue < $options['min']) {
                    $result['valid'] = false;
                    $result['error'] = "Value must be at least {$options['min']}";
                    break;
                }

                if (isset($options['max']) && $intValue > $options['max']) {
                    $result['valid'] = false;
                    $result['error'] = "Value must not exceed {$options['max']}";
                    break;
                }
                break;

            case 'float':
            case 'double':
                if (!is_numeric($value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be a number';
                    break;
                }

                $floatValue = (float)$value;
                $result['sanitized'] = $floatValue;

                // Range validation
                if (isset($options['min']) && $floatValue < $options['min']) {
                    $result['valid'] = false;
                    $result['error'] = "Value must be at least {$options['min']}";
                    break;
                }

                if (isset($options['max']) && $floatValue > $options['max']) {
                    $result['valid'] = false;
                    $result['error'] = "Value must not exceed {$options['max']}";
                    break;
                }
                break;

            case 'bool':
            case 'boolean':
                if (!is_bool($value) && !in_array($value, ['true', 'false', '1', '0', 1, 0], true)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be a boolean';
                    break;
                }

                $result['sanitized'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be a valid email address';
                    break;
                }

                $result['sanitized'] = filter_var($value, FILTER_SANITIZE_EMAIL);
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be a valid URL';
                    break;
                }

                $result['sanitized'] = filter_var($value, FILTER_SANITIZE_URL);
                break;

            case 'array':
                if (!is_array($value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Value must be an array';
                    break;
                }

                // Array size validation
                if (isset($options['min_items']) && count($value) < $options['min_items']) {
                    $result['valid'] = false;
                    $result['error'] = "Array must contain at least {$options['min_items']} items";
                    break;
                }

                if (isset($options['max_items']) && count($value) > $options['max_items']) {
                    $result['valid'] = false;
                    $result['error'] = "Array must not contain more than {$options['max_items']} items";
                    break;
                }
                break;

            case 'filename':
                if (!is_string($value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Filename must be a string';
                    break;
                }

                $result['sanitized'] = cue_autoload('paths')->sanitizeFilename($value);

                // Check for dangerous patterns
                if (preg_match('/\.\.|[\/\\\\]/', $value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Filename contains invalid characters';
                    break;
                }
                break;

            case 'path':
                if (!is_string($value)) {
                    $result['valid'] = false;
                    $result['error'] = 'Path must be a string';
                    break;
                }

                $basePath = $options['base_path'] ?? cue_autoload('paths')->getDataPath();
                $securePath = cue_autoload('paths')->validateSecurePath($value, $basePath);

                if ($securePath === false) {
                    $result['valid'] = false;
                    $result['error'] = 'Invalid or unsafe path';
                    break;
                }

                $result['sanitized'] = $securePath;
                break;

            default:
                $result['valid'] = false;
                $result['error'] = "Unknown validation type: $type";
        }

    } catch (Exception $e) {
        $result['valid'] = false;
        $result['error'] = 'Validation error: ' . $e->getMessage();
        error_log("Input validation error: " . $e->getMessage());
    }

    return $result;
}

/**
 * Validate multiple inputs at once
 * @param array $inputs Array of input data
 * @param array $rules Validation rules for each input
 * @return array Validation results
 */
function security_validateInputs(array $inputs, array $rules): array {
    $results = ['valid' => true, 'errors' => [], 'sanitized' => []];

    foreach ($rules as $field => $rule) {
        $value = $inputs[$field] ?? null;
        $type = $rule['type'] ?? 'string';
        $options = $rule['options'] ?? [];

        $validation = security_validateInput($value, $type, $options);

        if (!$validation['valid']) {
            $results['valid'] = false;
            $results['errors'][$field] = $validation['error'];
        }

        $results['sanitized'][$field] = $validation['sanitized'];
    }

    return $results;
}

// -----------------------------------------------------------------------------
// RATE LIMITING SYSTEM
// -----------------------------------------------------------------------------

/**
 * High-performance in-memory rate limiter with periodic persistence
 */
class InMemoryRateLimiter {
    private static $rateLimits = [];
    private static $lastCleanup = 0;
    private static $lastPersist = 0;
    private static $isInitialized = false;
    private static $persistenceInterval = 30; // seconds
    private static $cleanupInterval = 60; // seconds
    private static $maxMemoryEntries = 10000; // prevent memory exhaustion

    /**
     * Initialize the rate limiter and load persisted data
     */
    private static function initialize(): void {
        if (self::$isInitialized) {
            return;
        }

        self::loadPersistedData();
        self::$isInitialized = true;

        // Register shutdown function for graceful persistence
        register_shutdown_function([self::class, 'persistOnShutdown']);
    }

    /**
     * Load persisted rate limit data from file
     */
    private static function loadPersistedData(): void {
        $rateLimitFile = cue_autoload('paths')->getTempPath() . '/rate_limits.json';

        if (file_exists($rateLimitFile)) {
            try {
                $data = file_get_contents($rateLimitFile);
                $persistedData = json_decode($data, true);

                // Ensure consistent object structure with config IDs as keys
                if (is_array($persistedData)) {
                    foreach ($persistedData as $configId => $rateLimitData) {
                        if (self::isValidRateLimitData($rateLimitData)) {
                            self::$rateLimits[$configId] = $rateLimitData;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to load persisted rate limit data: " . $e->getMessage());
            }
        }

        self::$lastPersist = time();
        self::$lastCleanup = time();
    }

    /**
     * Validate rate limit data structure
     */
    private static function isValidRateLimitData(array $data): bool {
        return isset($data['count'], $data['first_request'], $data['window']) &&
               is_int($data['count']) && is_int($data['first_request']) && is_int($data['window']);
    }

    /**
     * Persist rate limit data to file
     */
    private static function persistData(): void {
        // Only persist if we have data and enough time has passed
        $currentTime = time();
        if (empty(self::$rateLimits) || ($currentTime - self::$lastPersist) < 30) {
            return; // Don't persist too frequently to avoid file I/O bottleneck
        }

        try {
            $rateLimitFile = cue_autoload('paths')->getTempPath() . '/rate_limits.json';

            // Use atomic write to prevent corruption during concurrent access
            $tempFile = $rateLimitFile . '.tmp.' . uniqid();

            // Ensure consistent object structure for persistence
            $dataToSave = [];
            foreach (self::$rateLimits as $configId => $rateLimitData) {
                // Only save recent entries to keep file size manageable
                if (($currentTime - $rateLimitData['first_request']) < 3600) { // Only keep last hour
                    $dataToSave[$configId] = $rateLimitData;
                }
            }

            // Write to temp file first, then atomic rename
            $result = file_put_contents(
                $tempFile,
                json_encode($dataToSave, JSON_UNESCAPED_SLASHES), // Smaller JSON without pretty print
                LOCK_EX
            );

            if ($result !== false && rename($tempFile, $rateLimitFile)) {
                self::$lastPersist = $currentTime;
            } else {
                error_log("Failed to persist rate limit data to file");
                // Clean up temp file on failure
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        } catch (Exception $e) {
            error_log("Error persisting rate limit data: " . $e->getMessage());
            // Clean up temp file on exception
            if (isset($tempFile) && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Clean expired entries from memory
     */
    private static function cleanupExpiredEntries(): void {
        $currentTime = time();
        $cleanedCount = 0;

        foreach (self::$rateLimits as $configId => $data) {
            if ($currentTime - $data['first_request'] > $data['window']) {
                unset(self::$rateLimits[$configId]);
                $cleanedCount++;
            }
        }

        // Prevent memory exhaustion by limiting total entries
        if (count(self::$rateLimits) > self::$maxMemoryEntries) {
            // Remove oldest entries (FIFO)
            $sortedByTime = self::$rateLimits;
            uasort($sortedByTime, function($a, $b) {
                return $a['first_request'] <=> $b['first_request'];
            });

            $toRemove = count(self::$rateLimits) - self::$maxMemoryEntries;
            $removed = 0;
            foreach ($sortedByTime as $configId => $data) {
                if ($removed >= $toRemove) break;
                unset(self::$rateLimits[$configId]);
                $removed++;
                $cleanedCount++;
            }
        }

        self::$lastCleanup = $currentTime;

        if ($cleanedCount > 0) {
            error_log("Rate limiter: Cleaned $cleanedCount expired entries from memory");
        }
    }

    /**
     * Check if periodic maintenance is needed
     */
    private static function performPeriodicMaintenance(): void {
        $currentTime = time();

        // Cleanup expired entries
        if ($currentTime - self::$lastCleanup > self::$cleanupInterval) {
            self::cleanupExpiredEntries();
        }

        // Persist data
        if ($currentTime - self::$lastPersist > self::$persistenceInterval) {
            self::persistData();
        }
    }

    /**
     * High-performance rate limit check
     */
    public static function checkRateLimit(string $key, array $limits = []): bool {
        self::initialize();

        // Default enterprise rate limits
        $defaultLimits = [
            'input_validation_password' => ['max' => 5, 'window' => 60],
            'input_validation_email' => ['max' => 10, 'window' => 60],
            'database_connection' => ['max' => 20, 'window' => 60],
            'encryption_operation' => ['max' => 50, 'window' => 60],
            'api_request' => ['max' => 100, 'window' => 60],
            'security_audit' => ['max' => 2, 'window' => 3600],
            'backup_creation' => ['max' => 3, 'window' => 3600],
            'file_upload' => ['max' => 20, 'window' => 60],
            'default' => ['max' => 30, 'window' => 60]
        ];

        $config = array_merge($defaultLimits[$key] ?? $defaultLimits['default'], $limits);
        $currentTime = time();

        // Use consistent config ID as key (key + config hash for uniqueness)
        $configId = $key . '_' . md5(json_encode($config));

        // Check if entry exists and is still valid
        if (!isset(self::$rateLimits[$configId])) {
            // Create new entry with consistent structure
            self::$rateLimits[$configId] = [
                'count' => 1,
                'first_request' => $currentTime,
                'window' => $config['window']
            ];
        } else {
            // Check if window has expired
            if ($currentTime - self::$rateLimits[$configId]['first_request'] > self::$rateLimits[$configId]['window']) {
                // Reset for new window
                self::$rateLimits[$configId] = [
                    'count' => 1,
                    'first_request' => $currentTime,
                    'window' => $config['window']
                ];
            } else {
                // Increment count within window
                self::$rateLimits[$configId]['count']++;
            }
        }

        // Check if limit exceeded
        $withinLimit = self::$rateLimits[$configId]['count'] <= $config['max'];

        if (!$withinLimit) {
            // Log rate limit violation
            cue_autoload('error')->logSecurityEvent('rate_limit_exceeded', 'Rate limit exceeded for operation', [
                'operation' => $key,
                'limit' => $config['max'],
                'window' => $config['window'],
                'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        // Perform periodic maintenance (non-blocking)
        self::performPeriodicMaintenance();

        return $withinLimit;
    }

    /**
     * Get rate limit status for monitoring
     */
    public static function getRateLimitStatus(string $key): array {
        self::initialize();

        // Default limits for status calculation
        $defaultLimits = [
            'input_validation_password' => 5,
            'input_validation_email' => 10,
            'database_connection' => 20,
            'encryption_operation' => 50,
            'api_request' => 100,
            'security_audit' => 2,
            'backup_creation' => 3,
            'file_upload' => 20,
            'default' => 30
        ];

        $maxLimit = $defaultLimits[$key] ?? $defaultLimits['default'];

        // Find matching config ID (use default config for status)
        $defaultConfig = ['max' => $maxLimit, 'window' => 60];
        $configId = $key . '_' . md5(json_encode($defaultConfig));

        if (!isset(self::$rateLimits[$configId])) {
            return [
                'count' => 0,
                'remaining' => $maxLimit,
                'reset_time' => time() + 60,
                'reset_in_seconds' => 60
            ];
        }

        $data = self::$rateLimits[$configId];
        $resetTime = $data['first_request'] + $data['window'];

        return [
            'count' => $data['count'],
            'remaining' => max(0, $maxLimit - $data['count']),
            'reset_time' => $resetTime,
            'reset_in_seconds' => max(0, $resetTime - time())
        ];
    }

    /**
     * Reset rate limit for specific key (admin function)
     */
    public static function resetRateLimit(string $key): bool {
        self::initialize();

        $removed = 0;
        foreach (self::$rateLimits as $configId => $data) {
            if (strpos($configId, $key . '_') === 0) {
                unset(self::$rateLimits[$configId]);
                $removed++;
            }
        }

        if ($removed > 0) {
            // Trigger immediate persistence
            self::persistData();
        }

        return true;
    }

    /**
     * Get memory usage statistics
     */
    public static function getMemoryStats(): array {
        return [
            'total_entries' => count(self::$rateLimits),
            'memory_usage_bytes' => memory_get_usage(),
            'memory_usage_kb' => round(memory_get_usage() / 1024, 2),
            'last_cleanup' => self::$lastCleanup,
            'last_persist' => self::$lastPersist,
            'max_entries' => self::$maxMemoryEntries
        ];
    }

    /**
     * Force immediate persistence (for shutdown or critical events)
     */
    public static function persistOnShutdown(): void {
        if (!empty(self::$rateLimits)) {
            self::persistData();
        }
    }

    /**
     * Configure persistence settings
     */
    public static function configurePersistence(int $persistInterval = 30, int $cleanupInterval = 60, int $maxEntries = 10000): void {
        self::$persistenceInterval = max(10, $persistInterval); // Minimum 10 seconds
        self::$cleanupInterval = max(30, $cleanupInterval); // Minimum 30 seconds
        self::$maxMemoryEntries = max(1000, $maxEntries); // Minimum 1000 entries
    }
}

/**
 * Check rate limit for specific operations
 * Enterprise configuration: Prevents brute force and DoS attacks
 * Now uses high-performance in-memory cache with periodic persistence
 *
 * @param string $key Unique identifier for the operation
 * @param array $limits Rate limit configuration
 * @return bool True if within limits, false if exceeded
 */
function security_checkRateLimit(string $key, array $limits = []): bool {
    return InMemoryRateLimiter::checkRateLimit($key, $limits);
}

/**
 * Get rate limit status for monitoring
 * Now uses high-performance in-memory cache
 *
 * @param string $key Operation key
 * @return array Rate limit status
 */
function security_getRateLimitStatus(string $key): array {
    return InMemoryRateLimiter::getRateLimitStatus($key);
}

/**
 * Reset rate limit for specific key (admin function)
 * Now uses high-performance in-memory cache
 *
 * @param string $key Operation key to reset
 * @return bool Success status
 */
function security_resetRateLimit(string $key): bool {
    return InMemoryRateLimiter::resetRateLimit($key);
}

/**
 * Get rate limiter memory usage statistics
 * Provides monitoring for the in-memory rate limiting system
 *
 * @return array Memory usage and performance statistics
 */
function security_getRateLimiterStats(): array {
    return InMemoryRateLimiter::getMemoryStats();
}

/**
 * Configure rate limiter persistence settings
 * Allows fine-tuning of persistence intervals and memory limits
 *
 * @param int $persistInterval How often to persist data (seconds)
 * @param int $cleanupInterval How often to cleanup expired entries (seconds)
 * @param int $maxEntries Maximum entries to keep in memory
 */
function security_configureRateLimiter(int $persistInterval = 30, int $cleanupInterval = 60, int $maxEntries = 10000): void {
    InMemoryRateLimiter::configurePersistence($persistInterval, $cleanupInterval, $maxEntries);
}

/**
 * Force immediate persistence of rate limit data
 * Useful before shutdown or maintenance operations
 */
function security_persistRateLimitData(): void {
    InMemoryRateLimiter::persistOnShutdown();
}

// -----------------------------------------------------------------------------
// SESSION SECURITY
// -----------------------------------------------------------------------------

/**
 * Extract the actual filesystem path from PHP's files session.save_path format.
 */
function security_getSessionFilesPath(?string $savePath = null): string {
    $savePath = is_string($savePath) ? trim($savePath) : '';
    if ($savePath === '') {
        $savePath = trim((string)session_save_path());
    }
    if ($savePath === '') {
        return '';
    }

    $parts = explode(';', $savePath);
    $path = trim((string)end($parts));
    return rtrim($path, '/');
}

/**
 * For OIDC requests only, restore the session from disk into an in-memory
 * handler when PHP's files handler fails to read the current session.
 */
function security_restoreSessionFromDiskSnapshot(?string $sessionName = null, ?string $savePath = null): array {
    $sessionName = is_string($sessionName) ? trim($sessionName) : '';
    if ($sessionName === '') {
        $sessionName = session_name();
    }

    $sessionId = ($sessionName !== '' && isset($_COOKIE[$sessionName]) && is_string($_COOKIE[$sessionName]))
        ? trim((string)$_COOKIE[$sessionName])
        : '';
    if ($sessionId === '' || !preg_match('/^[A-Za-z0-9,-]{16,256}$/', $sessionId)) {
        return [
            'restored' => false,
            'reason' => 'missing_or_invalid_session_cookie',
        ];
    }

    $filesPath = security_getSessionFilesPath($savePath);
    if ($filesPath === '') {
        return [
            'restored' => false,
            'reason' => 'missing_session_save_path',
            'session_id' => $sessionId,
        ];
    }

    $sessionFile = $filesPath . '/sess_' . $sessionId;
    if (!is_file($sessionFile) || !is_readable($sessionFile)) {
        return [
            'restored' => false,
            'reason' => 'session_file_unreadable',
            'session_id' => $sessionId,
            'session_file' => $sessionFile,
        ];
    }

    $rawSession = @file_get_contents($sessionFile);
    if (!is_string($rawSession)) {
        return [
            'restored' => false,
            'reason' => 'session_file_read_failed',
            'session_id' => $sessionId,
            'session_file' => $sessionFile,
        ];
    }

    $handler = new class($rawSession) implements SessionHandlerInterface {
        private string $rawSession;

        public function __construct(string $rawSession) {
            $this->rawSession = $rawSession;
        }

        public function open(string $path, string $name): bool {
            return true;
        }

        public function close(): bool {
            return true;
        }

        public function read(string $id): string {
            return $this->rawSession;
        }

        public function write(string $id, string $data): bool {
            return true;
        }

        public function destroy(string $id): bool {
            return true;
        }

        public function gc(int $max_lifetime): int|false {
            return 0;
        }
    };

    if (!session_set_save_handler($handler, true)) {
        return [
            'restored' => false,
            'reason' => 'session_save_handler_swap_failed',
            'session_id' => $sessionId,
            'session_file' => $sessionFile,
        ];
    }

    session_id($sessionId);

    $restoreError = null;
    set_error_handler(static function (int $severity, string $message) use (&$restoreError): bool {
        $restoreError = $message;
        return true;
    });
    try {
        $restored = session_start();
    } finally {
        restore_error_handler();
    }

    return [
        'restored' => ($restored === true && session_status() === PHP_SESSION_ACTIVE),
        'reason' => ($restored === true && session_status() === PHP_SESSION_ACTIVE) ? 'restored_from_disk_snapshot' : 'snapshot_restore_failed',
        'session_id' => $sessionId,
        'session_file' => $sessionFile,
        'error' => $restoreError,
    ];
}

/**
 * Initialize secure session with enhanced security
 */
function security_startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (function_exists('security_restoreAuthContext')) {
            security_restoreAuthContext();
        }
        return;
    }

    // Configure secure session settings
    $ttl = (int)(getenv('MH_SESSION_TTL') ?: 43200);
    $ttl = max(1800, min(1209600, $ttl));
    ini_set('session.gc_maxlifetime', (string)$ttl);
    ini_set('session.cookie_httponly', 1);
    $isSecure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') ||
        ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        ((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
    );
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_samesite', $isSecure ? 'None' : 'Lax');

    // Set session save path to secure location
    $sessionPath = cue_autoload('paths')->getSessionsPath();
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    // Start session
    // #region debug-point A:security-session-start
    $securityRequestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if ($securityRequestUri !== '' && strpos($securityRequestUri, '/oidc/') === 0) {
        $securityDebugUrl = 'http://127.0.0.1:7777/event';
        $securityDebugSession = 'oidc-sso-loop';
        $securityDebugEnvPath = ROOT_PATH . '/.dbg/oidc-sso-loop.env';
        if (is_file($securityDebugEnvPath)) {
            $securityDebugEnvRaw = (string)@file_get_contents($securityDebugEnvPath);
            if ($securityDebugEnvRaw !== '') {
                foreach (preg_split('/\r?\n/', $securityDebugEnvRaw) ?: [] as $securityDebugLine) {
                    $securityDebugLine = trim((string)$securityDebugLine);
                    if ($securityDebugLine === '' || strpos($securityDebugLine, '=') === false) continue;
                    [$securityDebugKey, $securityDebugValue] = explode('=', $securityDebugLine, 2);
                    if ($securityDebugKey === 'DEBUG_SERVER_URL' && trim($securityDebugValue) !== '') $securityDebugUrl = trim($securityDebugValue);
                    if ($securityDebugKey === 'DEBUG_SESSION_ID' && trim($securityDebugValue) !== '') $securityDebugSession = trim($securityDebugValue);
                }
            }
        }
        $securityBeforePayload = json_encode([
            'sessionId' => $securityDebugSession,
            'runId' => 'pre-fix',
            'hypothesisId' => 'A',
            'location' => '.cue/security.php:before-session-start',
            'msg' => '[DEBUG] security_startSecureSession before session_start for oidc request',
            'data' => [
                'request_uri' => $securityRequestUri,
                'session_name' => session_name(),
                'session_id' => session_id() ?: null,
                'save_path' => session_save_path(),
                'cookie_present' => (session_name() !== '' && isset($_COOKIE[session_name()])),
                'headers_sent' => headers_sent(),
            ],
            'ts' => (int)round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($securityBeforePayload) && $securityBeforePayload !== '') {
            @file_get_contents($securityDebugUrl, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $securityBeforePayload,
                    'timeout' => 1,
                    'ignore_errors' => true,
                ],
            ]));
        }
    }
    // #endregion
    $securitySessionStartResult = null;
    $securitySessionStartError = null;
    $securitySessionFallback = null;
    if ($securityRequestUri !== '' && strpos($securityRequestUri, '/oidc/') === 0) {
        set_error_handler(static function (int $severity, string $message) use (&$securitySessionStartError): bool {
            $securitySessionStartError = $message;
            return true;
        });
        try {
            $securitySessionStartResult = session_start();
        } finally {
            restore_error_handler();
        }
        if (
            $securitySessionStartResult !== true &&
            is_string($securitySessionStartError) &&
            strpos($securitySessionStartError, 'Failed to read session data: files') !== false &&
            session_status() === PHP_SESSION_NONE
        ) {
            $securitySessionFallback = security_restoreSessionFromDiskSnapshot(session_name(), session_save_path());
            if (($securitySessionFallback['restored'] ?? false) === true) {
                $securitySessionStartResult = true;
                $securitySessionStartError = null;
            }
        }
    } else {
        $securitySessionStartResult = session_start();
    }
    // #region debug-point A:security-session-after
    if ($securityRequestUri !== '' && strpos($securityRequestUri, '/oidc/') === 0) {
        $securityAfterPayload = json_encode([
            'sessionId' => $securityDebugSession,
            'runId' => 'pre-fix',
            'hypothesisId' => 'A',
            'location' => '.cue/security.php:after-session-start',
            'msg' => '[DEBUG] security_startSecureSession after session_start for oidc request',
            'data' => [
                'request_uri' => $securityRequestUri,
                'session_status' => session_status(),
                'session_name' => session_name(),
                'session_id' => session_id() ?: null,
                'mh_auth_user' => $_SESSION['mh_auth_user'] ?? null,
                'session_start_result' => $securitySessionStartResult,
                'session_start_error' => $securitySessionStartError,
                'session_fallback' => $securitySessionFallback,
            ],
            'ts' => (int)round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($securityAfterPayload) && $securityAfterPayload !== '') {
            @file_get_contents($securityDebugUrl, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $securityAfterPayload,
                    'timeout' => 1,
                    'ignore_errors' => true,
                ],
            ]));
        }
    }
    // #endregion

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 86400) { // 24 hours
        if (!headers_sent()) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }

    // Basic session security checks
    if (!isset($_SESSION['fingerprint_seed']) || !is_string($_SESSION['fingerprint_seed']) || $_SESSION['fingerprint_seed'] === '') {
        $_SESSION['fingerprint_seed'] = bin2hex(random_bytes(16));
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
    $seed = (string)$_SESSION['fingerprint_seed'];
    $fp = hash('sha256', $seed . '|' . $ua);
    $internalSessionAgents = [
        'mh_php_session_auth_map',
    ];
    $isInternalSessionAgent = static function (string $candidate) use ($internalSessionAgents): bool {
        $candidate = strtolower(trim($candidate));
        if ($candidate === '') {
            return false;
        }
        foreach ($internalSessionAgents as $internalAgent) {
            $internalAgent = strtolower(trim((string)$internalAgent));
            if ($internalAgent !== '' && str_contains($candidate, $internalAgent)) {
                return true;
            }
        }
        return false;
    };
    if (!isset($_SESSION['fingerprint']) || !is_string($_SESSION['fingerprint']) || $_SESSION['fingerprint'] === '') {
        $_SESSION['fingerprint'] = $fp;
        $_SESSION['fingerprint_user_agent'] = $ua;
    } else {
        if (!hash_equals((string)$_SESSION['fingerprint'], $fp)) {
            $previousUa = isset($_SESSION['fingerprint_user_agent']) ? trim((string)$_SESSION['fingerprint_user_agent']) : '';
            $currentIsInternal = $isInternalSessionAgent($ua);
            $previousIsInternal = $isInternalSessionAgent($previousUa);
            $suppressHijackLog = $currentIsInternal || $previousIsInternal;
            if (!$suppressHijackLog) {
                // Possible session hijacking attempt
                cue_autoload('error')->logSecurityEvent('session_hijacking_attempt', 'Session fingerprint mismatch', [
                    'session_id' => session_id(),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                $_SESSION['fingerprint_seed'] = bin2hex(random_bytes(16));
                $_SESSION['fingerprint'] = hash('sha256', (string)$_SESSION['fingerprint_seed'] . '|' . $ua);
                $_SESSION['fingerprint_user_agent'] = $ua;
            } elseif (!$currentIsInternal) {
                $_SESSION['fingerprint'] = $fp;
                $_SESSION['fingerprint_user_agent'] = $ua;
            }
        }
    }

    if (function_exists('security_restoreAuthContext')) {
        security_restoreAuthContext();
    }
}

function security_getBiometricsRestorePdo(): ?PDO {
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            return null;
        }
        $pdo = database_getConnectionById('biometrics');
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
            return $pdo->pdo;
        }
        if (is_array($pdo) && isset($pdo['pdo']) && $pdo['pdo'] instanceof PDO) {
            return $pdo['pdo'];
        }
    } catch (Throwable) {}
    return null;
}

function security_restoreRememberMeAuth(?PDO $pdoBio = null): bool {
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return !empty($_SESSION['mh_auth_user']);
    }
    if (!function_exists('mh_remember_me_get_pepper')) {
        return false;
    }

    $pdoBio = $pdoBio instanceof PDO ? $pdoBio : security_getBiometricsRestorePdo();
    $pepper = trim((string)mh_remember_me_get_pepper());
    if (!$pdoBio instanceof PDO || $pepper === '') {
        return false;
    }

    if (function_exists('mh_remember_me_ensure_schema_once')) {
        mh_remember_me_ensure_schema_once($pdoBio);
    }

    // First try the new __Host-device cookie
    if (function_exists('mh_remember_me_resolve_device') && function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_find_user')) {
        $ctx = mh_remember_me_get_context();
        // Check if __Host-device exists
        if (isset($_COOKIE['__Host-device']) && is_string($_COOKIE['__Host-device']) && $_COOKIE['__Host-device'] !== '') {
            // We need to find the user first - but wait - how?
            // Wait, let's look up user_device_tokens by hash
            $token = $_COOKIE['__Host-device'];
            $hash = function_exists('mh_remember_me_hash_token') ? mh_remember_me_hash_token($pepper, $token) : null;
            if ($hash !== null) {
                try {
                    $stmt = $pdoBio->prepare("SELECT user_id FROM user_device_tokens 
                        WHERE (token_hash = ? OR (prev_token_hash = ? AND prev_valid_until IS NOT NULL AND prev_valid_until >= NOW()))
                        AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
                    $stmt->execute([$hash, $hash]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row) && isset($row['user_id']) && (int)$row['user_id'] > 0) {
                        // Now get the user
                        $stmt2 = $pdoBio->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                        $stmt2->execute([(int)$row['user_id']]);
                        $userRow = $stmt2->fetch(PDO::FETCH_ASSOC);
                        if (is_array($userRow) && isset($userRow['username']) && trim($userRow['username']) !== '') {
                            $username = trim($userRow['username']);
                            // Now load the user
                            $authFunctions = defined('ROOT_PATH') ? ROOT_PATH . '/public_html/auth/auth_functions.php' : '';
                            if ($authFunctions !== '' && is_file($authFunctions)) {
                                require_once $authFunctions;
                            }
                            if (function_exists('mh_load_biometrics_user')) {
                                mh_load_biometrics_user($username, null, null);
                            }
                            $_SESSION['mh_auth_user'] = $username;
                            $_SESSION['mh_auth_method'] = 'remember_device';
                            
                            // Touch the device and rotate cookie if needed
                            $device = mh_remember_me_resolve_device($pdoBio, $pepper, (int)$row['user_id'], $ctx);
                            if (function_exists('mh_remember_me_touch_device') && $device['recognized'] && isset($device['device_token_id'])) {
                                mh_remember_me_touch_device($pdoBio, (int)$device['device_token_id'], $ctx);
                            }
                            if (function_exists('mh_remember_me_issue_or_rotate_cookie') && function_exists('mh_remember_me_should_rotate')) {
                                $shouldRotate = false;
                                if ($device['recognized'] && $device['row']) {
                                    $shouldRotate = mh_remember_me_should_rotate($device['row']);
                                }
                                if ($shouldRotate || !$device['recognized']) {
                                    mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, (int)$row['user_id'], $device, $ctx, $shouldRotate ? 'periodic' : 'restore');
                                }
                            }
                            return true;
                        }
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    // Fall back to old mh_account_remember_me_try_restore if available
    if (function_exists('mh_account_remember_me_try_restore')) {
        $result = mh_account_remember_me_try_restore($pdoBio, $pepper);
        // If we restored from the old cookie, issue a new device cookie
        if ($result && function_exists('mh_remember_me_issue_or_rotate_cookie') && function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_resolve_device')) {
            $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
            if ($uid > 0) {
                $ctx = mh_remember_me_get_context();
                $device = mh_remember_me_resolve_device($pdoBio, $pepper, $uid, $ctx);
                mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $uid, $device, $ctx, 'migrated_from_old_cookie');
            }
        }
        return $result;
    }
    return false;
}

function security_restoreLemonLdapAuth(?PDO $pdoBio = null): bool {
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return !empty($_SESSION['mh_auth_user']);
    }

    $handlerPath = defined('ROOT_PATH') ? ROOT_PATH . '/public_html/auth/lemonldap-handler.php' : '';
    if ($handlerPath !== '' && is_file($handlerPath)) {
        require_once $handlerPath;
    }
    if (!function_exists('lemonldap_process_headers')) {
        return false;
    }

    $ssoData = lemonldap_process_headers();
    $ssoUser = is_array($ssoData) ? trim((string)($ssoData['username'] ?? '')) : '';
    if ($ssoUser === '') {
        return false;
    }

    if (function_exists('lemonldap_sync_user')) {
        lemonldap_sync_user($ssoData);
    }

    $authFunctions = defined('ROOT_PATH') ? ROOT_PATH . '/public_html/auth/auth_functions.php' : '';
    if ($authFunctions !== '' && is_file($authFunctions)) {
        require_once $authFunctions;
    }
    if (!function_exists('mh_load_biometrics_user')) {
        return false;
    }

    mh_load_biometrics_user($ssoUser, $ssoData['groups'] ?? null, $ssoData['email'] ?? null);
    $_SESSION['mh_auth_user'] = $ssoUser;
    $_SESSION['mh_auth_method'] = 'sso_lemonldap';
    if (!empty($ssoData['groups'])) {
        $_SESSION['mh_auth_groups'] = $ssoData['groups'];
    }

    $pdoBio = $pdoBio instanceof PDO ? $pdoBio : security_getBiometricsRestorePdo();
    $pepper = function_exists('mh_remember_me_get_pepper') ? trim((string)mh_remember_me_get_pepper()) : '';
    $uid = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : 0;
    if ($pdoBio instanceof PDO && $pepper !== '' && $uid > 0 && function_exists('mh_remember_me_issue_or_rotate_cookie')) {
        if (function_exists('mh_remember_me_ensure_schema_once')) {
            mh_remember_me_ensure_schema_once($pdoBio);
        }
        if (function_exists('mh_remember_me_get_context') && function_exists('mh_remember_me_resolve_device')) {
            $ctx = mh_remember_me_get_context();
            $device = mh_remember_me_resolve_device($pdoBio, $pepper, $uid, $ctx);
            mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $uid, $device, $ctx, 'lemonldap_restore');
        }
    }

    return true;
}

function security_restoreAuthContext(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || !empty($_SESSION['mh_auth_user'])) {
        return;
    }

    $pdoBio = security_getBiometricsRestorePdo();
    if (security_restoreRememberMeAuth($pdoBio)) {
        return;
    }
    security_restoreLemonLdapAuth($pdoBio);
}

// -----------------------------------------------------------------------------
// SECURITY UTILITIES
// -----------------------------------------------------------------------------

/**
 * Check if current connection is secure (HTTPS)
 */
function security_isSecureConnection(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Generate CSRF token for form protection
 */
function security_generateCSRFToken(string $action = 'default'): string {
    if (!isset($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    if (!isset($_SESSION['csrf_tokens'][$action]) || time() - ($_SESSION['csrf_tokens'][$action]['created'] ?? 0) > 3600) {
        $_SESSION['csrf_tokens'][$action] = [
            'token' => bin2hex(random_bytes(32)),
            'created' => time()
        ];
    }

    return $_SESSION['csrf_tokens'][$action]['token'];
}

/**
 * Validate CSRF token
 */
function security_validateCSRFToken(string $token, string $action = 'default'): bool {
    if (!isset($_SESSION['csrf_tokens'][$action])) {
        return false;
    }

    $storedToken = $_SESSION['csrf_tokens'][$action]['token'] ?? '';
    $created = $_SESSION['csrf_tokens'][$action]['created'] ?? 0;

    // Check if token is expired (1 hour)
    if (time() - $created > 3600) {
        unset($_SESSION['csrf_tokens'][$action]);
        return false;
    }

    // Use timing-safe comparison
    return hash_equals($storedToken, $token);
}

/**
 * Generate a secure session token for UI installation or other session-based operations
 *
 * @param string $environment Environment identifier
 * @param string $action Action identifier
 * @return string Generated secure token
 */
function security_generateSecureSessionToken(string $environment, string $action): string {
    // Create a unique token based on environment, action, and current session
    $sessionId = session_id() ?: 'no_session';
    $timestamp = time();
    $random = bin2hex(random_bytes(16));

    // Combine factors for uniqueness
    $tokenData = $environment . $action . $sessionId . $timestamp . $random;

    // Generate SHA-256 hash for the token
    return hash('sha256', $tokenData);
}

?>
