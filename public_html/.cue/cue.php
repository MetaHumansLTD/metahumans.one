<?php
require_once __DIR__ . '/core.php';

if (!defined('CUE_FRAMEWORK_INITIALIZED')) {
    define('CUE_FRAMEWORK_INITIALIZED', true);

    try {
        cue_autoload('error');
        if (function_exists('error_initialize')) {
            error_initialize();
        }
        if (function_exists('error_startPerformanceMonitoring')) {
            error_startPerformanceMonitoring();
        }
    } catch (Throwable $e) {
        error_log('[CUE] Error module initialization failed: ' . $e->getMessage());
    }

    try {
        if (function_exists('loadContextualModules')) {
            loadContextualModules();
        }
    } catch (Throwable $e) {
        error_log('[CUE] Contextual module load failed: ' . $e->getMessage());
    }

    if (function_exists('error_recordPerformance')) {
        try {
            error_recordPerformance('cue_initialization');
        } catch (Throwable $e) {}
    }
}
