<?php
/**
 * CUE Framework Error Module
 *
 * Error handling, logging, and monitoring functions.
 * Loaded on-demand to improve performance.
 *
 * @package    CUE Framework
 * @version    75.0.1
 */

// -----------------------------------------------------------------------------
// ERROR HANDLING CONFIGURATION
// -----------------------------------------------------------------------------

/**
 * Initialize error handling system
 */
function error_initialize(): void {
    static $initialized = false;

    if ($initialized) {
        return;
    }

    // Set error reporting level
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

    // Set custom error handler
    set_error_handler('error_handleError');

    // Set exception handler
    set_exception_handler('error_handleException');

    // Register shutdown function for fatal errors
    register_shutdown_function('error_handleShutdown');

    $initialized = true;
}

/**
 * Configure error logging settings
 * @param array $config Error configuration
 */
function error_configure(array $config): array {
    static $configuration = [
        'log_level' => 'WARNING',
        'log_file' => null,
        'max_log_size' => 10 * 1024 * 1024, // 10MB
        'max_log_files' => 5,
        'email_errors' => false,
        'email_recipient' => null,
        'display_errors' => false
    ];

    $configuration = array_merge($configuration, $config);

    // Set PHP display_errors based on configuration
    ini_set('display_errors', $configuration['display_errors'] ? '1' : '0');

    // Ensure log directory exists
    if ($configuration['log_file'] === null) {
        // Use direct path construction to avoid autoloader dependency during initialization
        $logDir = '/data/logs';
        $configuration['log_file'] = $logDir . '/cue-error.log';
    }

    if (!is_dir(dirname($configuration['log_file']))) {
        mkdir(dirname($configuration['log_file']), 0755, true);
    }

    $logFile = (string)$configuration['log_file'];
    if ($logFile !== '' && file_exists($logFile) && !is_writable($logFile)) {
        $fallback = rtrim(dirname($logFile), '/') . '/cue-error.log';
        $configuration['log_file'] = $fallback;
    }

    return $configuration;
}

/**
 * Get current error configuration
 * @return array Error configuration
 */
function error_getConfiguration(): array {
    static $configuration = null;

    if ($configuration === null) {
        $configuration = error_configure([]); // Initialize with defaults
    }

    return $configuration;
}

// -----------------------------------------------------------------------------
// ERROR HANDLERS
// -----------------------------------------------------------------------------

/**
 * Custom error handler
 * @param int $errno Error number
 * @param string $errstr Error message
 * @param string $errfile File where error occurred
 * @param int $errline Line where error occurred
 * @return bool True to prevent default error handler
 */
function error_handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (error_reporting() === 0) {
        return true;
    }
    // Convert error number to severity level
    $severity = error_getSeverityLevel($errno);

    // Log the error
    error_logError($errstr, [
        'severity' => $severity,
        'file' => $errfile,
        'line' => $errline,
        'errno' => $errno,
        'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
    ]);

    // Don't prevent default error handler for notices and warnings in development
    $config = error_getConfiguration();
    if ($config['display_errors'] && in_array($errno, [E_NOTICE, E_WARNING, E_DEPRECATED])) {
        return false;
    }

    // Prevent default error handler for all other errors
    return true;
}

/**
 * Custom exception handler
 * @param Throwable $exception Exception object
 */
function error_handleException(Throwable $exception): void {
    error_logError('Uncaught exception: ' . $exception->getMessage(), [
        'severity' => 'ERROR',
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'exception_class' => get_class($exception),
        'trace' => $exception->getTrace()
    ]);

    // Display error page if configured
    $config = error_getConfiguration();
    if (!$config['display_errors']) {
        error_displayErrorPage($exception);
    }
}

/**
 * Shutdown handler for fatal errors
 */
function error_handleShutdown(): void {
    $error = error_get_last();

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_logError('Fatal error: ' . $error['message'], [
            'severity' => 'FATAL',
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type']
        ]);
    }
}

/**
 * Convert PHP error number to severity level
 * @param int $errno Error number
 * @return string Severity level
 */
function error_getSeverityLevel(int $errno): string {
    $levels = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'ERROR',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'ERROR',
        E_CORE_WARNING => 'WARNING',
        E_COMPILE_ERROR => 'ERROR',
        E_COMPILE_WARNING => 'WARNING',
        E_USER_ERROR => 'ERROR',
        E_USER_WARNING => 'WARNING',
        E_USER_NOTICE => 'NOTICE',
        E_RECOVERABLE_ERROR => 'ERROR',
        E_DEPRECATED => 'NOTICE',
        E_USER_DEPRECATED => 'NOTICE'
    ];

    if (defined('E_STRICT')) {
        $levels[constant('E_STRICT')] = 'NOTICE';
    }

    return $levels[$errno] ?? 'UNKNOWN';
}

// -----------------------------------------------------------------------------
// LOGGING FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Log error message with context
 * @param string $message Error message
 * @param array $context Additional context information
 * @param string $level Log level override
 */
function error_logError(string $message, array $context = [], ?string $level = null): void {
    $config = error_getConfiguration();

    // Determine log level
    if ($level === null) {
        $level = $context['severity'] ?? 'ERROR';
    }

    // Check if we should log this level
    if (!error_shouldLogLevel($level, $config['log_level'])) {
        return;
    }

    // Format log entry
    $logEntry = error_formatLogEntry($message, $level, $context);

    // Write to log file
    error_writeToLog($logEntry, $config['log_file']);

    // Send email notification if configured
    if ($config['email_errors'] && $config['email_recipient'] && in_array($level, ['ERROR', 'FATAL'])) {
        error_sendErrorEmail($message, $context);
    }

    // Log to system log if available
    if (function_exists('syslog')) {
        $syslogPriority = error_getSyslogPriority($level);
        syslog($syslogPriority, $logEntry);
    }
}

/**
 * Log informational message
 * @param string $message Info message
 * @param array $context Additional context
 */
function error_logInfo(string $message, array $context = []): void {
    error_logError($message, $context, 'INFO');
}

/**
 * Log warning message
 * @param string $message Warning message
 * @param array $context Additional context
 */
function error_logWarning(string $message, array $context = []): void {
    error_logError($message, $context, 'WARNING');
}

/**
 * Log security event
 * @param string $event Event type
 * @param string $message Event message
 * @param array $context Additional context
 */
function error_logSecurityEvent(string $event, string $message, array $context = []): void {
    $context['event_type'] = $event;
    $context['security_event'] = true;
    $context['timestamp'] = time();
    $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    error_logError("Security Event [{$event}]: {$message}", $context, 'SECURITY');
}

/**
 * Check if log level should be logged
 * @param string $messageLevel Message level
 * @param string $configLevel Configured minimum level
 * @return bool True if should log
 */
function error_shouldLogLevel(string $messageLevel, string $configLevel): bool {
    $levels = ['DEBUG' => 0, 'INFO' => 1, 'NOTICE' => 2, 'WARNING' => 3, 'ERROR' => 4, 'FATAL' => 5, 'SECURITY' => 6];

    $messagePriority = $levels[$messageLevel] ?? 0;
    $configPriority = $levels[$configLevel] ?? 3;

    return $messagePriority >= $configPriority;
}

/**
 * Format log entry
 * @param string $message Log message
 * @param string $level Log level
 * @param array $context Context information
 * @return string Formatted log entry
 */
function error_formatLogEntry(string $message, string $level, array $context = []): string {
    $timestamp = date('Y-m-d H:i:s');
    $pid = getmypid();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $logEntry = "[{$timestamp}] [{$pid}] [{$ip}] [{$level}] {$message}";

    // Add context information
    if (!empty($context)) {
        $contextStr = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logEntry .= " | Context: {$contextStr}";
    }

    // Add file and line information if available
    if (isset($context['file']) && isset($context['line'])) {
        $logEntry .= " | File: {$context['file']}:{$context['line']}";
    }

    return $logEntry;
}

/**
 * Write entry to log file with rotation
 * @param string $entry Log entry
 * @param string $logFile Log file path
 */
function error_writeToLog(string $entry, string $logFile): void {
    $config = error_getConfiguration();

    // Check if log rotation is needed
    if (file_exists($logFile) && filesize($logFile) > $config['max_log_size']) {
        error_rotateLogFile($logFile, $config['max_log_files']);
    }

    // Write to log file
    $result = @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);

    if ($result === false) {
        $fallback = '/data/logs/cue-error.log';
        if ($logFile !== $fallback) {
            $ok = @file_put_contents($fallback, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($ok !== false) {
                return;
            }
        }
        error_log("Failed to write to log file {$logFile}");
    }
}

/**
 * Rotate log file
 * @param string $logFile Current log file
 * @param int $maxFiles Maximum number of log files to keep
 */
function error_rotateLogFile(string $logFile, int $maxFiles): void {
    // Rotate existing log files
    for ($i = $maxFiles - 1; $i >= 1; $i--) {
        $oldFile = $logFile . '.' . $i;
        $newFile = $logFile . '.' . ($i + 1);

        if (file_exists($oldFile)) {
            rename($oldFile, $newFile);
        }
    }

    // Move current log file
    if (file_exists($logFile)) {
        rename($logFile, $logFile . '.1');
    }
}

/**
 * Get syslog priority for log level
 * @param string $level Log level
 * @return int Syslog priority constant
 */
function error_getSyslogPriority(string $level): int {
    $priorities = [
        'DEBUG' => LOG_DEBUG,
        'INFO' => LOG_INFO,
        'NOTICE' => LOG_NOTICE,
        'WARNING' => LOG_WARNING,
        'ERROR' => LOG_ERR,
        'FATAL' => LOG_CRIT,
        'SECURITY' => LOG_ALERT
    ];

    return $priorities[$level] ?? LOG_ERR;
}

// -----------------------------------------------------------------------------
// ERROR DISPLAY AND NOTIFICATION
// -----------------------------------------------------------------------------

/**
 * Display error page for uncaught exceptions
 * @param Throwable $exception Exception object
 */
function error_displayErrorPage(Throwable $exception): void {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set HTTP status code
    http_response_code(500);

    // Display simple error page
    echo '<!DOCTYPE html>
<html>
<head>
    <title>Server Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; }
        .details { margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="error">
        <h1>Server Error</h1>
        <p>An unexpected error occurred. Please try again later.</p>
    </div>';

    // Show details in development mode
    $config = error_getConfiguration();
    if ($config['display_errors']) {
        echo '<div class="details">
            <strong>Error:</strong> ' . htmlspecialchars($exception->getMessage()) . '<br>
            <strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '<br>
            <strong>Line:</strong> ' . $exception->getLine() . '<br>
            <strong>Trace:</strong><br>
            <pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>
        </div>';
    }

    echo '</body></html>';
    exit;
}

/**
 * Send error notification email
 * @param string $message Error message
 * @param array $context Error context
 */
function error_sendErrorEmail(string $message, array $context = []): void {
    $config = error_getConfiguration();

    if (!$config['email_errors'] || !$config['email_recipient']) {
        return;
    }

    $subject = 'CUE Framework Error Notification';
    $body = "Error occurred at " . date('Y-m-d H:i:s') . "\n\n";
    $body .= "Message: {$message}\n\n";

    if (!empty($context)) {
        $body .= "Context:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    $body .= "Server: " . ($_SERVER['SERVER_NAME'] ?? 'unknown') . "\n";
    $body .= "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";
    $body .= "Remote IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";

    $headers = 'From: CUE Framework <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>' . "\r\n";
    $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

    mail($config['email_recipient'], $subject, $body, $headers);
}

// -----------------------------------------------------------------------------
// PERFORMANCE MONITORING
// -----------------------------------------------------------------------------

/**
 * Performance monitoring class for tracking execution times and memory usage
 */
class PerformanceMonitor {
    private static float $startTime = 0.0;
    private static int $startMemory = 0;
    private static array $measurements = [];

    /**
     * Start performance monitoring
     */
    public static function start(): void {
        self::$startTime = microtime(true);
        self::$startMemory = memory_get_usage();
    }

    /**
     * Record a performance measurement
     * @param string $name Measurement name
     * @param float $startTime Start time (optional)
     */
    public static function record(string $name, ?float $startTime = null): void {
        $endTime = microtime(true);
        $startTime = $startTime ?? self::$startTime;

        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
        $memoryUsage = memory_get_usage();
        $peakMemory = memory_get_peak_usage();

        self::$measurements[$name] = [
            'duration_ms' => round($duration, 2),
            'memory_usage' => $memoryUsage,
            'peak_memory' => $peakMemory,
            'timestamp' => time()
        ];

        // Log performance metrics
        error_logInfo("Performance measurement: {$name}", [
            'duration_ms' => round($duration, 2),
            'memory_kb' => round($memoryUsage / 1024, 2),
            'peak_memory_kb' => round($peakMemory / 1024, 2)
        ]);
    }

    /**
     * Get performance statistics
     * @return array Performance statistics
     */
    public static function getStats(): array {
        $totalTime = (microtime(true) - self::$startTime) * 1000;
        $totalMemory = memory_get_usage() - self::$startMemory;
        $peakMemory = memory_get_peak_usage();

        return [
            'total_execution_time_ms' => round($totalTime, 2),
            'total_memory_usage_kb' => round($totalMemory / 1024, 2),
            'peak_memory_usage_kb' => round($peakMemory / 1024, 2),
            'measurements' => self::$measurements,
            'measurement_count' => count(self::$measurements)
        ];
    }

    /**
     * Check if performance thresholds are exceeded
     * @param array $thresholds Performance thresholds
     * @return array Exceeded thresholds
     */
    public static function checkThresholds(array $thresholds = []): array {
        $stats = self::getStats();
        $exceeded = [];

        $defaultThresholds = [
            'max_execution_time_ms' => 5000, // 5 seconds
            'max_memory_usage_kb' => 50 * 1024, // 50MB
            'max_peak_memory_kb' => 100 * 1024 // 100MB
        ];

        $thresholds = array_merge($defaultThresholds, $thresholds);

        if ($stats['total_execution_time_ms'] > $thresholds['max_execution_time_ms']) {
            $exceeded['execution_time'] = [
                'actual' => $stats['total_execution_time_ms'],
                'threshold' => $thresholds['max_execution_time_ms']
            ];
        }

        if ($stats['total_memory_usage_kb'] > $thresholds['max_memory_usage_kb']) {
            $exceeded['memory_usage'] = [
                'actual' => $stats['total_memory_usage_kb'],
                'threshold' => $thresholds['max_memory_usage_kb']
            ];
        }

        if ($stats['peak_memory_usage_kb'] > $thresholds['max_peak_memory_kb']) {
            $exceeded['peak_memory'] = [
                'actual' => $stats['peak_memory_usage_kb'],
                'threshold' => $thresholds['max_peak_memory_kb']
            ];
        }

        if (!empty($exceeded)) {
            error_logWarning('Performance thresholds exceeded', $exceeded);
        }

        return $exceeded;
    }
}

/**
 * Start performance monitoring
 */
function error_startPerformanceMonitoring(): void {
    PerformanceMonitor::start();
}

/**
 * Record performance measurement
 * @param string $name Measurement name
 */
function error_recordPerformance(string $name): void {
    PerformanceMonitor::record($name);
}

/**
 * Get performance statistics
 * @return array Performance statistics
 */
function error_getPerformanceStats(): array {
    return PerformanceMonitor::getStats();
}

/**
 * Check performance thresholds
 * @param array $thresholds Performance thresholds
 * @return array Exceeded thresholds
 */
function error_checkPerformanceThresholds(array $thresholds = []): array {
    return PerformanceMonitor::checkThresholds($thresholds);
}

// -----------------------------------------------------------------------------
// UTILITY FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Get error log file path
 * @return string Log file path
 */
function error_getLogFile(): string {
    $config = error_getConfiguration();
    return $config['log_file'];
}

/**
 * Clear error log file
 * @return bool Success status
 */
function error_clearLog(): bool {
    $logFile = error_getLogFile();

    if (file_exists($logFile)) {
        return unlink($logFile);
    }

    return true;
}

/**
 * Get recent error log entries
 * @param int $lines Number of lines to retrieve
 * @return array Log entries
 */
function error_getRecentLogs(int $lines = 50): array {
    $logFile = error_getLogFile();

    if (!file_exists($logFile)) {
        return [];
    }

    $entries = [];
    $file = fopen($logFile, 'r');

    if ($file) {
        $buffer = [];
        while (($line = fgets($file)) !== false) {
            $buffer[] = trim($line);
            if (count($buffer) > $lines) {
                array_shift($buffer);
            }
        }
        fclose($file);
        $entries = $buffer;
    }

    return $entries;
}

/**
 * Export error logs for analysis
 * @param string $format Export format (json, csv, txt)
 * @return string Exported data
 */
function error_exportLogs(string $format = 'json'): string {
    $logs = error_getRecentLogs(1000);

    switch (strtolower($format)) {
        case 'json':
            return json_encode($logs, JSON_PRETTY_PRINT);

        case 'csv':
            $output = "timestamp,pid,ip,level,message\n";
            foreach ($logs as $log) {
                // Parse log entry (simplified parsing)
                if (preg_match('/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.+)$/', $log, $matches)) {
                    $output .= '"' . str_replace('"', '""', $matches[1]) . '",';
                    $output .= '"' . str_replace('"', '""', $matches[2]) . '",';
                    $output .= '"' . str_replace('"', '""', $matches[3]) . '",';
                    $output .= '"' . str_replace('"', '""', $matches[4]) . '",';
                    $output .= '"' . str_replace('"', '""', $matches[5]) . '"';
                    $output .= "\n";
                }
            }
            return $output;

        case 'txt':
        default:
            return implode("\n", $logs);
    }
}

?>
