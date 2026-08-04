<?php
/**
 * ⚠️ CRITICAL: READ THIS FILE FIRST BEFORE MODIFYING ANY OTHER PHP FILES
 * 
 * CUE Framework Core Module - Modular Architecture Entry Point
 * 
 * 🔥 CRITICAL AI INTEGRATION STANDARDS - READ FIRST! 🔥
 * ============================================================
 * 
 * FOR AI ASSISTANTS WORKING WITH THIS CODEBASE:
 * 1. ALWAYS read this file FIRST before making any code changes
 * 2. Check function availability with cue_autoload() before creating duplicates
 * 3. Use modular loading patterns - never bypass the autoloader
 * 4. Follow widget consistency standards defined below
 * 5. Chat/LLM base_url + model are configured via `public_html/ai/hermes.json` (may be local Ollama on 127.0.0.1:11434 or a remote gateway)
 * 6. Always-on Memory “Sleep Cycle” is implemented by `public_html/hub/memory/daemon.php` and deployed as `mh-memory-daemon.service`
 * 7. Graph ingestion is scheduled via Cron Block (`public_html/gear/sync/index.php --cron-runner`) and advances `/data/tenants/<tenant>/graph/state.json`
 * 
 * 🔥 CRITICAL DATABASE CONNECTION STANDARDS - READ FIRST! 🔥
 * ============================================================
 * 
 * USE CUE FRAMEWORK STANDARDS - NEVER BYPASS WITH DIRECT PDO!
 * 
 * 🔥 DATABASE ARCHITECTURE NOTES 🔥
 * ============================================================
 * - `mariadb-service:3306`: Northflank MariaDB runtime for `/mysql`-backed databases.
 * - Historical `127.0.0.1:3307` block-local assumptions are legacy only.
 * - ALWAYS use the canonical block MariaDB runtime for `biometrics` and `tenant_user_*` databases.
 * - User PINs in Tenant DB are ENCRYPTED. In Biometrics DB are HASHED.
 * - Biometrics boundary (ENFORCED):
 *   - `/auth/*` and `/control/*` may access biometrics (auth/security only).
 *   - `/hub/*` and `/studio/*` must not open biometrics connections (no allowlist exceptions).
 *   - In tenant-facing code use context routing (`getContextAwareConnection`) and session-derived auth fields.
 * 
 * Standard Modular Pattern (PREFERRED):
 * <?php
 * require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
 * $db = cue_autoload('database')->getConnection(); // Default database
 * $db = cue_autoload('database')->getConnectionById($configId); // Specific config
 * 
 * Context-Aware Pattern (ENTERPRISE):
 * <?php 
 * $db = cue_autoload('database')->getContextAwareConnection(); // Auto-selects appropriate DB
 * 
 * Legacy Compatibility (supported but not preferred):
 * <?php
 * require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
 * $db = getDatabase(); // Uses legacy wrapper
 * 
 * Essential initialization and loading system.
 * This module is loaded in every request and provides the foundation
 * for loading other modules when they are needed.
 *
 * CUE location and load pattern
 * - CUE lives under the active public root at /.cue
 * - The public root may be the project root itself, /html, or /public_html
 * - All PHP files that use CUE must load it with:
 *     require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
 *
 * @package    CUE Framework
 * @author     Meta Humans LTD
 */

/*
 * 2. File Structure Analysis
 * - Thoroughly examine the /.cue folder and its files to understand all specified paths including:
 *   - getCuePath() . '/cue.php'
 *   - getCuePath() . '/core.php'
 *   - getCuePath() . '/database.php'
 *   - getCuePath() . '/error.php'
 *   - getCuePath() . '/font.php'
 *   - getCuePath() . '/json.php'
 *   - getCuePath() . '/paths.php'
 *   - getCuePath() . '/security.php'
 *   - getCuePath() . '/theme.php' General styling
 *   - /data files stored outside root
 *   - getPublicPath() public directory structure
 *   - Template paths: templates/menus/navigator.php, templates/widgets/, templates/assets/, templates/global-ui/
 *   - Settings paths: gear/settings
 *   - Database configuration in gear/settings/dbmanager.php
 *
 * 3. Asset Path Configuration
 * - Implement the following pre-defined asset paths:
 *   - CSS: /templates/assets/css
 *   - JavaScript: /templates/assets/js
 *   - Fonts: /templates/assets/fonts
 *   - Icons: /templates/assets/icons
 *   - Images: /templates/assets/images
 *   - API endpoints in /templates/assets/api/:
 *     - list-fonts.php
 *     - list-icons.php
 *     - list-media.php
 *
 * Theme and Styling Guidelines
 * - Use getCuePath() . '/theme.php' for styling with glassmorphism buttons and glow elements with cyan color
 * - Ensure that the backgrounds of the dropdown selectors will be dark blue and the text cyan
 */

// -----------------------------------------------------------------------------
// CORE INITIALIZATION - Always loaded
// -----------------------------------------------------------------------------

// Prevent multiple inclusions
if (!defined('CUE_CORE_LOADED')) {
    define('CUE_CORE_LOADED', true);
    if (!defined('CUE_VERSION')) { define('CUE_VERSION', '100.1.00'); }
    define('CUE_LOAD_TIME', microtime(true));
    define('CUE_REFACTORED', true);
    if (!defined('CUE_DB_PROVISION_MODE')) { define('CUE_DB_PROVISION_MODE', 'two_user'); }
    
    // Load .env file if it exists
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    // AI Infrastructure Configuration
    if (!defined('CUE_GPU_SERVER')) { define('CUE_GPU_SERVER', 'https://meta.superhumans.one'); }
    if (!defined('CUE_GPU_HOST')) { define('CUE_GPU_HOST', 'meta.superhumans.one'); }
    if (!defined('CUE_WHISPER_MODEL')) { define('CUE_WHISPER_MODEL', 'turbo'); }
    if (!defined('CUE_DEFAULT_CODER_MODEL')) { define('CUE_DEFAULT_CODER_MODEL', 'GLM-4.7'); }
    
    // Meta Humans & Humo Generation Stack
    if (!defined('CUE_TTS_ENGINE')) { define('CUE_TTS_ENGINE', 'Qwen3-TTS'); }
    if (!defined('CUE_VIDEO_ACCELERATOR')) { define('CUE_VIDEO_ACCELERATOR', 'FastGen'); }
    if (!defined('CUE_AGENT_FRAMEWORK')) { define('CUE_AGENT_FRAMEWORK', 'Hermes'); }
    if (!defined('CUE_TTS_HTTP_ENDPOINT')) { define('CUE_TTS_HTTP_ENDPOINT', 'http://127.0.0.1:32101/tts'); }
    
    $cueObjectStorageAccessKey = getenv('CUE_OBJECT_STORAGE_ACCESS_KEY');
    if (!is_string($cueObjectStorageAccessKey) || $cueObjectStorageAccessKey === '') {
        $cueObjectStorageAccessKey = (string)($_ENV['CUE_OBJECT_STORAGE_ACCESS_KEY'] ?? ($_SERVER['CUE_OBJECT_STORAGE_ACCESS_KEY'] ?? ''));
    }
    if (!defined('CUE_OBJECT_STORAGE_ACCESS_KEY')) { define('CUE_OBJECT_STORAGE_ACCESS_KEY', $cueObjectStorageAccessKey); }

    $cueObjectStorageSecretKey = getenv('CUE_OBJECT_STORAGE_SECRET_KEY');
    if (!is_string($cueObjectStorageSecretKey) || $cueObjectStorageSecretKey === '') {
        $cueObjectStorageSecretKey = (string)($_ENV['CUE_OBJECT_STORAGE_SECRET_KEY'] ?? ($_SERVER['CUE_OBJECT_STORAGE_SECRET_KEY'] ?? ''));
    }
    if (!defined('CUE_OBJECT_STORAGE_SECRET_KEY')) { define('CUE_OBJECT_STORAGE_SECRET_KEY', $cueObjectStorageSecretKey); }

    $cueObjectStorageGateway = getenv('CUE_OBJECT_STORAGE_GATEWAY');
    if (!is_string($cueObjectStorageGateway) || $cueObjectStorageGateway === '') {
        $cueObjectStorageGateway = (string)($_ENV['CUE_OBJECT_STORAGE_GATEWAY'] ?? ($_SERVER['CUE_OBJECT_STORAGE_GATEWAY'] ?? ''));
    }
    if (!defined('CUE_OBJECT_STORAGE_GATEWAY')) { define('CUE_OBJECT_STORAGE_GATEWAY', $cueObjectStorageGateway); }

    $cueObjectStorageUniqueId = getenv('CUE_OBJECT_STORAGE_UNIQUE_ID');
    if (!is_string($cueObjectStorageUniqueId) || $cueObjectStorageUniqueId === '') {
        $cueObjectStorageUniqueId = (string)($_ENV['CUE_OBJECT_STORAGE_UNIQUE_ID'] ?? ($_SERVER['CUE_OBJECT_STORAGE_UNIQUE_ID'] ?? ''));
    }
    if (!defined('CUE_OBJECT_STORAGE_UNIQUE_ID')) { define('CUE_OBJECT_STORAGE_UNIQUE_ID', $cueObjectStorageUniqueId); }
    
    // Debug mode - set to true to enable verbose logging
    if (!defined('CUE_DEBUG')) { define('CUE_DEBUG', false); }

    if (!defined('CUE_DISABLE_AUTO_UI')) { define('CUE_DISABLE_AUTO_UI', true); }
    
    // Animation widget initialization will happen after ROOT_PATH is defined
} else {
    return;
}

// -----------------------------------------------------------------------------
// CRITICAL PATH RESOLUTION
// -----------------------------------------------------------------------------

/**
 * Log debug messages only when CUE_DEBUG is enabled
 * 
 * @param string $message The debug message to log
 */
function cue_debug_log(mixed $message): void {
    if (defined('CUE_DEBUG') && CUE_DEBUG) {
        if (!is_string($message)) {
            $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $message = is_string($encoded) ? $encoded : (string)$message;
        }
        error_log($message);
    }
}

/**
 * Internal Endpoint Resolution (Single Source of Truth)
 *
 * Precedence (highest → lowest):
 * 1) Environment variables (SetEnv / .env / process env)
 * 2) /data/config/superhumans.json (shared host defaults)
 * 3) Safe built-in defaults
 *
 * Supported environment variables:
 * - SUPERHUMANS_BASE_URL / MH_SUPERHUMANS_BASE_URL
 * - MH_OLLAMA_BASE_URL / OLLAMA_BASE_URL / OLLAMA_HOST
 * - MH_LIVEKIT_URL / LIVEKIT_URL
 * - MH_VIMAX_BASE_URL / VIMAX_BASE_URL / VIMAX_HOST
 * - MH_PERSONAPLEX_BASE_URL / PERSONAPLEX_BASE_URL / PERSONAPLEX_HOST
 *
 * Supported /data/config/superhumans.json keys:
 * - base_url (string) e.g. "https://meta.superhumans.one"
 * - services.ollama.base_url
 * - services.livekit.url
 * - services.vimax.base_url
 * - services.personaplex.base_url
 */
function mh_internal_cfg_path_superhumans(): string
{
    $base = null;
    if (function_exists('paths_getDataPath')) {
        $base = paths_getDataPath();
    } elseif (function_exists('getDataPath')) {
        $base = getDataPath();
    }
    if (!is_string($base) || trim($base) === '') {
        $base = '/data';
    }
    return rtrim((string)$base, '/') . '/config/superhumans.json';
}

function mh_internal_env_str(string $key): string
{
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        $v = isset($_ENV[$key]) ? (string)$_ENV[$key] : (isset($_SERVER[$key]) ? (string)$_SERVER[$key] : '');
    }
    return is_string($v) ? trim((string)$v) : '';
}

function mh_internal_read_json(string $path): array
{
    if (!is_string($path) || $path === '' || !is_file($path)) return [];
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_internal_json_get(array $cfg, array $path): string
{
    $cur = $cfg;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return '';
        $cur = $cur[$k];
    }
    return is_string($cur) ? trim((string)$cur) : '';
}

function mh_internal_normalize_url(string $raw, string $defaultScheme = 'https'): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $raw)) {
        $raw = $defaultScheme . '://' . ltrim($raw, '/');
    }
    return rtrim($raw, '/');
}

function mh_internal_host_from_url(string $url): string
{
    $u = parse_url($url);
    $host = is_array($u) && isset($u['host']) ? trim((string)$u['host']) : '';
    if ($host !== '') return $host;
    if (preg_match('~^[a-z][a-z0-9+.-]*://([^/]+)~i', $url, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function mh_internal_http_url(string $host, int $port): string
{
    $host = trim($host);
    if ($host === '') return '';
    if (strpos($host, ':') !== false) {
        $host = explode(':', $host, 2)[0];
    }
    if ($host === '') return '';
    return 'http://' . $host . ':' . (int)$port;
}

function mh_internal_endpoints(): array
{
    static $cached = null;
    if (is_array($cached)) return $cached;

    $cfgPath = mh_internal_cfg_path_superhumans();
    $jsonCfg = mh_internal_read_json($cfgPath);

    $baseUrl = mh_internal_env_str('SUPERHUMANS_BASE_URL');
    if ($baseUrl === '') $baseUrl = mh_internal_env_str('MH_SUPERHUMANS_BASE_URL');
    if ($baseUrl === '') $baseUrl = is_array($jsonCfg) ? mh_internal_json_get($jsonCfg, ['base_url']) : '';
    if ($baseUrl === '') $baseUrl = 'https://meta.superhumans.one';
    $baseUrl = mh_internal_normalize_url($baseUrl, 'https');
    $baseHost = mh_internal_host_from_url($baseUrl);
    if ($baseHost === '') $baseHost = 'meta.superhumans.one';

    $ollama = mh_internal_env_str('MH_OLLAMA_BASE_URL');
    if ($ollama === '') $ollama = mh_internal_env_str('OLLAMA_BASE_URL');
    if ($ollama === '') $ollama = mh_internal_env_str('OLLAMA_HOST');
    if ($ollama === '') $ollama = mh_internal_json_get($jsonCfg, ['services', 'ollama', 'base_url']);
    if ($ollama === '') $ollama = mh_internal_json_get($jsonCfg, ['ollama_base_url']);
    if ($ollama === '') $ollama = mh_internal_http_url($baseHost, 11434);
    if ($ollama === '') $ollama = 'http://meta.superhumans.one:11434';
    $ollama = mh_internal_normalize_url($ollama, 'http');

    $livekit = mh_internal_env_str('MH_LIVEKIT_URL');
    if ($livekit === '') $livekit = mh_internal_env_str('LIVEKIT_URL');
    if ($livekit === '') $livekit = mh_internal_json_get($jsonCfg, ['services', 'livekit', 'url']);
    if ($livekit === '') $livekit = mh_internal_json_get($jsonCfg, ['livekit_url']);
    if ($livekit === '') $livekit = 'wss://metahumans.one/livekit';
    $livekit = mh_internal_normalize_url($livekit, 'wss');

    $vimax = mh_internal_env_str('MH_VIMAX_BASE_URL');
    if ($vimax === '') $vimax = mh_internal_env_str('VIMAX_BASE_URL');
    if ($vimax === '') $vimax = mh_internal_env_str('VIMAX_HOST');
    if ($vimax === '') $vimax = mh_internal_json_get($jsonCfg, ['services', 'vimax', 'base_url']);
    if ($vimax === '') $vimax = mh_internal_json_get($jsonCfg, ['vimax_base_url']);
    if ($vimax === '') $vimax = mh_internal_http_url($baseHost, 9020);
    if ($vimax === '') $vimax = 'http://meta.superhumans.one:9020';
    $vimax = mh_internal_normalize_url($vimax, 'http');

    $personaplex = mh_internal_env_str('MH_PERSONAPLEX_BASE_URL');
    if ($personaplex === '') $personaplex = mh_internal_env_str('PERSONAPLEX_BASE_URL');
    if ($personaplex === '') $personaplex = mh_internal_env_str('PERSONAPLEX_HOST');
    if ($personaplex === '') $personaplex = mh_internal_json_get($jsonCfg, ['services', 'personaplex', 'base_url']);
    if ($personaplex === '') $personaplex = mh_internal_json_get($jsonCfg, ['personaplex_base_url']);
    if ($personaplex === '') $personaplex = mh_internal_http_url($baseHost, 8998);
    if ($personaplex === '') $personaplex = 'http://meta.superhumans.one:8998';
    $personaplex = mh_internal_normalize_url($personaplex, 'http');

    $cached = [
        'cfg_path' => $cfgPath,
        'base_url' => $baseUrl,
        'ollama_base_url' => $ollama,
        'livekit_url' => $livekit,
        'vimax_base_url' => $vimax,
        'personaplex_base_url' => $personaplex,
    ];
    return $cached;
}

function mh_internal_endpoint_url(string $service): string
{
    $service = strtolower(trim($service));
    $cfg = mh_internal_endpoints();
    if ($service === 'ollama') return (string)($cfg['ollama_base_url'] ?? '');
    if ($service === 'livekit') return (string)($cfg['livekit_url'] ?? '');
    if ($service === 'vimax') return (string)($cfg['vimax_base_url'] ?? '');
    if ($service === 'personaplex') return (string)($cfg['personaplex_base_url'] ?? '');
    return '';
}

function cue_db_getProvisionMode(): string {
    return defined('CUE_DB_PROVISION_MODE') ? (string) CUE_DB_PROVISION_MODE : 'single_user';
}



/**
 * Dynamic path resolution function to locate cue.php file
 * Handles various environments and directory structures
 *
 * @param string|null $startPath Starting path for resolution (optional)
 * @return string|false Path to cue.php or false if not found
 */
function getCue(?string $startPath = null): string|false {
    // Validate and normalize start path
    $currentPath = $startPath ? realpath($startPath) : __DIR__;
    if (!$currentPath || !is_dir($currentPath)) {
        $currentPath = __DIR__;
    }

    // SIMPLIFIED PRIORITY PATHS: Remove redundant patterns
    $searchPaths = [
        // 1. Current location (preferred)
        $currentPath . '/cue.php',
        $currentPath . '/.cue/cue.php',

        // 2. Document root structure (if available)
        isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/.cue/cue.php' : null,
        isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/cue.php' : null,

        // 3. Essential parent directories only
        dirname($currentPath) . '/.cue/cue.php',
        dirname($currentPath, 2) . '/.cue/cue.php',
        dirname($currentPath) . '/cue.php',
        dirname($currentPath, 2) . '/cue.php',
    ];

    // Remove null entries
    $searchPaths = array_filter($searchPaths);

    // Try each path in priority order
    foreach ($searchPaths as $path) {
        if (!$path) continue;

        $realPath = realpath($path);
        if ($realPath && file_exists($realPath) && is_readable($realPath)) {
            // Validate this is actually cue.php by checking for framework marker
            $content = file_get_contents($realPath, false, null, 0, 512);
            if ($content && (strpos($content, 'CUE FRAMEWORK') !== false || strpos($content, 'META_HUMANS_CORE') !== false)) {
                return $realPath;
            }
        }
    }

    // Fallback: limited upward search (max 3 levels for performance)
    $searchPath = $currentPath;
    $maxLevels = 3;

    for ($i = 0; $i < $maxLevels; $i++) {
        $candidates = [
            $searchPath . '/.cue/cue.php',
            $searchPath . '/cue.php'
        ];

        foreach ($candidates as $testPath) {
            if (file_exists($testPath) && is_readable($testPath)) {
                $content = file_get_contents($testPath, false, null, 0, 512);
                if ($content && (strpos($content, 'CUE FRAMEWORK') !== false || strpos($content, 'META_HUMANS_CORE') !== false)) {
                    return realpath($testPath);
                }
            }
        }

        $parentPath = dirname($searchPath);
        if ($parentPath === $searchPath || !$parentPath || $parentPath === '.') {
            break;
        }
        $searchPath = $parentPath;
    }

    return false;
}

// -----------------------------------------------------------------------------
// ERROR HANDLING FOR PATH RESOLUTION
// -----------------------------------------------------------------------------

/**
 * Handle cue.php loading errors with proper fallbacks
 */
function handleCueError(mixed $message, bool $fatal = true): void {
    $errorMsg = "[CUE ERROR] " . (is_string($message) ? $message : (string)$message);

    if (function_exists('error_log')) {
        error_log($errorMsg);
    }

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMsg . PHP_EOL);
    } else {
        // Web environment
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain');
        }
        echo htmlspecialchars($errorMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . PHP_EOL;
    }

    if ($fatal) {
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// CRITICAL: Bootstrap validation - Ensure proper loading order
// -----------------------------------------------------------------------------
if (defined('APP_INITIALIZED') && !defined('CUE_REINIT')) {
    handleCueError('cue.php must be loaded before any other application files', true);
}

// Start output buffering for clean error handling
// Force output buffering even if one exists to ensure we capture everything
ob_start();

// Auto-injection is disabled by default. Pages must explicitly include Global UI:
// - include_once getTemplatesPath() . '/global-ui/includes/complete-head.php';
// - include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php';
// - include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php';
$autoUiDisabled = (defined('CUE_DISABLE_AUTO_UI') && CUE_DISABLE_AUTO_UI === true) || (bool)getenv('CUE_DISABLE_AUTO_UI');

// Register auto-injection only if enabled
if (!$autoUiDisabled && php_sapi_name() !== 'cli') {
    register_shutdown_function('cue_autoInjectGlobalUI');
    $GLOBALS['_CUE_GLOBAL_UI_REGISTERED'] = true;
    if (!defined('CUE_CLI_MODE')) {
        cue_debug_log('CUE Debug: Auto-injection system registered');
    }
}

// -----------------------------------------------------------------------------
// PATH DEFINITIONS - Enhanced Dynamic Structure with Robust Detection
// -----------------------------------------------------------------------------

// SECURITY FIX: Robust root path detection to prevent .data folder misplacement
// This search-based approach ensures ROOT_PATH is correct regardless of inclusion context
function findProjectRoot(): string {
    $currentDir = dirname(__DIR__); // Start from the public root above .cue
    $maxDepth = 5;

    // Pass 0: direct layouts used by modern deployments.
    for ($i = 0; $i < $maxDepth; $i++) {
        $testPath = ($i === 0) ? $currentDir : dirname($currentDir, $i + 1);

        if (!$testPath || !is_dir($testPath) || !is_readable($testPath)) {
            continue;
        }

        if (is_dir($testPath . '/.cue') && is_dir($testPath . '/templates')) {
            return $testPath;
        }

        if (is_dir($testPath . '/html/.cue') && is_dir($testPath . '/html/templates')) {
            return $testPath . '/html';
        }
    }

    // Pass 1: Strongest indicator for the legacy public subdirectory layout.
    // This confirms the standard split public-root structure.
    for ($i = 0; $i < $maxDepth; $i++) {
        $testPath = ($i === 0) ? $currentDir : dirname($currentDir, $i + 1);
        
        if (!$testPath || !is_dir($testPath) || !is_readable($testPath)) {
            continue;
        }

        if (is_dir($testPath . '/public_html/.cue')) {
            return $testPath;
        }
    }

    // Pass 2: Secondary indicators (fallback for non-standard structures)
    for ($i = 0; $i < $maxDepth; $i++) {
        $testPath = ($i === 0) ? $currentDir : dirname($currentDir, $i + 1);

        if (!$testPath || !is_dir($testPath) || !is_readable($testPath)) {
            continue;
        }

        if (
            // Secondary indicators
            (is_dir($testPath . '/public_html')) ||
            file_exists($testPath . '/xampp-control.ini') ||
            file_exists($testPath . '/setup_xampp.bat') ||
            (is_dir($testPath . '/apache') && is_dir($testPath . '/mysql'))
        ) {
            return $testPath;
        }
    }

    // Enhanced fallback to current working directory if available
    $cwd = getcwd();
    if ($cwd && is_dir($cwd) && is_readable($cwd)) {
        // Check if current working directory has project indicators
        if (is_dir($cwd . '/public_html')) {
            return $cwd;
        }
    }

    // Final fallback with validation
    $fallbackPath = dirname(__DIR__, 2);
    if (is_dir($fallbackPath) && is_readable($fallbackPath)) {
        return $fallbackPath;
    }

    // If all else fails, use the directory containing .cue
    return dirname(__DIR__);
}

$rootPath = findProjectRoot();
define('ROOT_PATH', $rootPath);

// Validate root path
if (!is_dir(ROOT_PATH) || !is_readable(ROOT_PATH)) {
    handleCueError('Invalid ROOT_PATH detected: ' . ROOT_PATH, true);
}

if (php_sapi_name() !== 'cli') {
    $ttl = (int)(getenv('MH_SESSION_TTL') ?: 43200);
    $ttl = max(1800, min(1209600, $ttl));
    ini_set('session.gc_maxlifetime', (string)$ttl);
    $isSecure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') ||
        ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        ((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
    );
    ini_set('session.cookie_secure', $isSecure ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_samesite', $isSecure ? 'None' : 'Lax');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = '/data/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        ini_set('session.save_path', $sessionPath);
        session_save_path($sessionPath);
    }
}

if (!function_exists('startSecureSession')) {
    function startSecureSession(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('security');
            }
            if (function_exists('security_startSecureSession')) {
                call_user_func('security_startSecureSession');
                return;
            }
        } catch (Throwable) {}

        if (!headers_sent()) {
            $ttl = (int)(getenv('MH_SESSION_TTL') ?: 43200);
            $ttl = max(1800, min(1209600, $ttl));
            ini_set('session.gc_maxlifetime', (string)$ttl);
            ini_set('session.cookie_httponly', '1');
            ini_set('session.use_only_cookies', '1');
            $isSecure = (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') ||
                ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
                ((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
            );
            ini_set('session.cookie_secure', $isSecure ? '1' : '0');
            ini_set('session.cookie_path', '/');
            ini_set('session.cookie_samesite', $isSecure ? 'None' : 'Lax');
        }

        $sessionPath = '/data/sessions';
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            ini_set('session.save_path', $sessionPath);
            session_save_path($sessionPath);
        }

        session_start();
    }
}

// Auto-initialize animation widget now that ROOT_PATH is defined
// Skip for AJAX requests to prevent HTML contamination
$isAjaxRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
    (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) ||
    (isset($_POST['action']) || isset($_GET['action'])) ||
    (php_sapi_name() === 'cli') ||
    (basename($_SERVER['PHP_SELF']) === 'file-browser-api.php') || (basename($_SERVER['PHP_SELF']) === 'global-ui-manager.php') ||
    (basename($_SERVER['PHP_SELF']) === 'session.php') || (basename($_SERVER['PHP_SELF']) === 'token.php')
);

if (!defined('CUE_CLI_MODE') && php_sapi_name() !== 'cli') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Whitelist Logic: Auth paths and Index page are allowed
    $isAuthPath = (strpos($uri, '/auth/') === 0) || (strpos($scriptName, '/auth/') === 0) || (strpos($uri, '/oidc/') === 0) || (strpos($scriptName, '/oidc/') === 0);
    $isIndex = ($scriptName === '/index.php' || $uri === '/' || $uri === '/index.php');
    
    $ext = strtolower(pathinfo($scriptName, PATHINFO_EXTENSION) ?: '');
    $isPhp = ($ext === 'php' || $ext === '');
    
    // 1. Global Login Enforcement
    if ($isPhp && !$isAuthPath && !$isIndex) {
        if (session_status() === PHP_SESSION_NONE) {
            try {
                if (function_exists('cue_autoload')) {
                    cue_autoload('security');
                }
                } catch (Throwable) {}

            if (function_exists('security_startSecureSession')) {
            call_user_func('security_startSecureSession');
            } elseif (function_exists('startSecureSession')) {
                startSecureSession();
            } else {
                if (!headers_sent()) {
                    ini_set('session.cookie_httponly', 1);
                    ini_set('session.use_only_cookies', 1);
                }
                session_start();
            }
        }
        
        // Auto-login via SSO if not already logged in
        if (!isset($_SESSION['mh_auth_user'])) {
            $handler = getPublicPath() . '/auth/lemonldap-handler.php';
            if (file_exists($handler)) {
                require_once $handler;
                if (function_exists('lemonldap_process_headers')) {
                    $ssoData = lemonldap_process_headers();
                    if ($ssoData && isset($ssoData['username'])) {
                        $u = $ssoData['username'];
                        $g = $ssoData['groups'] ?? null;
                        $authFunctions = getPublicPath() . '/auth/auth_functions.php';
                        if (file_exists($authFunctions)) {
                            require_once $authFunctions;
                        }
                        if (function_exists('mh_load_biometrics_user')) {
                            mh_load_biometrics_user($u, $g);
                        }
                        $_SESSION['mh_auth_user'] = $u;
                        $_SESSION['mh_auth_method'] = 'sso_lemonldap';
                    }
                }
            }
        }

        if (!isset($_SESSION['mh_auth_user'])) {
            try {
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }
                if (function_exists('database_getConnectionById')) {
                    $pdoBio = call_user_func('database_getConnectionById', 'biometrics');
                    if (!($pdoBio instanceof PDO)) {
                        if (is_object($pdoBio) && property_exists($pdoBio, 'pdo') && $pdoBio->pdo instanceof PDO) {
                            $pdoBio = $pdoBio->pdo;
                        } elseif (is_array($pdoBio) && isset($pdoBio['pdo']) && $pdoBio['pdo'] instanceof PDO) {
                            $pdoBio = $pdoBio['pdo'];
                        }
                    }
                    if ($pdoBio instanceof PDO) {
                        $pepper = mh_remember_me_get_pepper();
                        if (is_string($pepper) && trim($pepper) !== '') {
                            mh_remember_me_ensure_schema_once($pdoBio);
                            if (function_exists('mh_account_remember_me_try_restore')) {
                                mh_account_remember_me_try_restore($pdoBio, $pepper);
                            }
                        }
                    }
                }
                } catch (Throwable) {}
        }
        
        // If still not logged in, block access
        if (!isset($_SESSION['mh_auth_user'])) {
            if ($isAjaxRequest || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                if (!headers_sent()) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'error' => 'unauthenticated']);
                exit;
            } else {
                $redir = '/auth/login.php';
                $rq = $_SERVER['REQUEST_URI'] ?? '';
                // #region debug-point B:core-oidc-auth-gate
                if (is_string($rq) && strpos($rq, '/oidc/') === 0) {
                    $debugUrl = 'http://127.0.0.1:7777/event';
                    $debugSession = 'oidc-sso-loop';
                    $debugEnvPath = ROOT_PATH . '/.dbg/oidc-sso-loop.env';
                    if (is_file($debugEnvPath)) {
                        $debugEnvRaw = (string)@file_get_contents($debugEnvPath);
                        if ($debugEnvRaw !== '') {
                            foreach (preg_split('/\r?\n/', $debugEnvRaw) ?: [] as $debugLine) {
                                $debugLine = trim((string)$debugLine);
                                if ($debugLine === '' || strpos($debugLine, '=') === false) continue;
                                [$debugKey, $debugValue] = explode('=', $debugLine, 2);
                                if ($debugKey === 'DEBUG_SERVER_URL' && trim($debugValue) !== '') $debugUrl = trim($debugValue);
                                if ($debugKey === 'DEBUG_SESSION_ID' && trim($debugValue) !== '') $debugSession = trim($debugValue);
                            }
                        }
                    }
                    $debugPayload = json_encode([
                        'sessionId' => $debugSession,
                        'runId' => 'pre-fix',
                        'hypothesisId' => 'B',
                        'location' => '.cue/core.php:auth-gate',
                        'msg' => '[DEBUG] core auth gate is redirecting unauthenticated oidc request',
                        'data' => [
                            'request_uri' => $rq,
                            'session_name' => session_name(),
                            'session_id' => session_id() ?: null,
                            'mh_auth_user' => $_SESSION['mh_auth_user'] ?? null,
                            'cookie_present' => (session_name() !== '' && isset($_COOKIE[session_name()])),
                        ],
                        'ts' => (int)round(microtime(true) * 1000),
                    ], JSON_UNESCAPED_SLASHES);
                    if (is_string($debugPayload) && $debugPayload !== '') {
                        @file_get_contents($debugUrl, false, stream_context_create([
                            'http' => [
                                'method' => 'POST',
                                'header' => "Content-Type: application/json\r\n",
                                'content' => $debugPayload,
                                'timeout' => 1,
                                'ignore_errors' => true,
                            ],
                        ]));
                    }
                }
                // #endregion
                $redirectTarget = is_string($rq) ? $rq : '';
                if (is_string($redirectTarget) && strpos($redirectTarget, '/hub/widget/') === 0) {
                    $ref = isset($_SERVER['HTTP_REFERER']) ? trim((string)$_SERVER['HTTP_REFERER']) : '';
                    $p = $ref !== '' ? parse_url($ref) : null;
                    $refHost = is_array($p) ? strtolower((string)($p['host'] ?? '')) : '';
                    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
                    $refPath = is_array($p) ? (string)($p['path'] ?? '') : '';
                    $refQuery = is_array($p) ? (string)($p['query'] ?? '') : '';
                    if ($refHost !== '' && $host !== '' && $refHost === $host && $refPath !== '' && $refPath[0] === '/' && strpos($refPath, '/auth/') !== 0 && strpos($refPath, '/hub/widget/') !== 0) {
                        $redirectTarget = $refPath . ($refQuery !== '' ? ('?' . $refQuery) : '');
                    } else {
                        $redirectTarget = '/hub/';
                    }
                }
                if ($redirectTarget !== '' && mh_is_interactive_last_page($redirectTarget)) {
                    $redir .= '?redirect=' . urlencode($redirectTarget);
                }
                error_log('[CUE AUTH] Redirecting unauthenticated request: ' . $rq . ' -> ' . $redir);
                header('Location: ' . $redir);
                exit;
            }
        }
    }

    if (!$isAjaxRequest && $_SERVER['REQUEST_METHOD'] === 'GET' && session_status() === PHP_SESSION_ACTIVE) {
        $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
        if ($u !== '') {
            $uri = isset($_SERVER['REQUEST_URI']) ? trim((string)$_SERVER['REQUEST_URI']) : '';
            if (mh_is_interactive_last_page($uri)) {
                $_SESSION['mh_last_page'] = $uri;
            }
        }
    }
    
    // 2. KripzMasters Content Protection (RBAC)
    // Specific check for menu-permission-manager.php
    if (strpos($scriptName, 'menu-permission-manager.php') !== false) {
         if (session_status() === PHP_SESSION_NONE) {
             session_start();
         }
         $userRole = $_SESSION['mh_auth_role'] ?? '';
         $isKripzMaster = (strcasecmp($userRole, 'KripzMasters') === 0 || strcasecmp($userRole, 'KripzMaster') === 0);
         
         if (!$isKripzMaster) {
             if (!headers_sent()) {
                 header('HTTP/1.0 403 Forbidden');
                 header('Content-Type: application/json');
             }
             echo json_encode(['success' => false, 'error' => 'Access denied: KripzMasters only.']);
             exit;
         }
    }
}

if (!defined('CUE_ANIMATIONS_INITIALIZED') && !$isAjaxRequest && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/pdf-tools') !== 0) {
    define('CUE_ANIMATIONS_INITIALIZED', true);
    cue_auto_initialize_widgets();
}

// -----------------------------------------------------------------------------
// ESSENTIAL PATH FUNCTIONS - Always available
// -----------------------------------------------------------------------------

function getDataPath(): string {
    return '/data';
}

function getPublicPath(): string {
    $currentPublic = dirname(__DIR__);
    if (is_dir($currentPublic . '/.cue') && is_dir($currentPublic . '/templates')) {
        return $currentPublic;
    }

    $root = rtrim(ROOT_PATH, '/\\');
    if ($root === '') {
        return $currentPublic;
    }

    foreach ([$root, $root . '/html', $root . '/public_html'] as $candidate) {
        if (is_dir($candidate . '/.cue') && is_dir($candidate . '/templates')) {
            return $candidate;
        }
    }

    return $currentPublic;
}

function getTemplatesPath(): string {
    return getPublicPath() . '/templates';
}

function getCuePath(): string {
    return getPublicPath() . '/.cue';
}

function getGlobalUIPath(): string {
    return '/data/global-ui';
}

function getSyncPath(): string {
    return '/data/sync';
}

function getSyncPortalPath(): string {
    return getPublicPath() . '/gear/sync';
}

function getMeetingPath(): string {
    return '/data/meetings/public/js';
}

function getWhisperPath(): string {
    return getPublicPath() . '/studio/transcribe.php';
}

function getTranscribeUrl(): string {
    return '/studio/transcribe.php';
}

function getMysqlDataPath(): string {
    return '/mysql';
}

function getVectorDataPath(): string {
    return '/vector';
}

function getGraphDataPath(): string {
    return '/graph';
}

// -----------------------------------------------------------------------------
// SYNC SYSTEM MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Initialize sync system directories and ensure proper structure
 */
function initializeSyncSystem(): bool {
    $directories = [
        getGlobalUIPath() . '/header',
        getGlobalUIPath() . '/footer', 
        getGlobalUIPath() . '/navigation',
        getGlobalUIPath() . '/theme',
        getSyncPath() . '/backups',
        getSyncPortalPath() . '/api',
        getSyncPortalPath() . '/assets'
    ];

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }
    }

    $navCfg = getGlobalUIPath() . '/navigation/menu-config.json';
    if (!is_file($navCfg)) {
        @file_put_contents($navCfg, json_encode(new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    return true;
}

/**
 * Get global UI configuration file path
 * @param string $component Component name (header, footer, navigation, theme)
 * @param string $configType Configuration type (config, templates, items, etc.)
 * @return string Full path to configuration file
 */
function getGlobalUIConfigPath(string $component, string $configType): string {
    // Handle navigation component special case
    if ($component === 'navigation' && $configType === 'config') {
        return getGlobalUIPath() . "/navigation/menu-config.json";
    }
    return getGlobalUIPath() . "/{$component}/{$component}-{$configType}.json";
}

/**
 * Get sync status file path
 * @param string $statusType Status type (status, log, conflict-resolution)
 * @return string Full path to sync status file
 */
function getSyncStatusPath(string $statusType): string {
    return getSyncPath() . "/sync-{$statusType}.json";
}

/**
 * Get sync backup path with timestamp
 * @param string $backupType Backup type (database, files)
 * @param string|null $timestamp Optional timestamp (defaults to current)
 * @return string Full path to backup file
 */
function getSyncBackupPath(string $backupType, ?string $timestamp = null): string {
    if ($timestamp === null) {
        $timestamp = date('Y-m-d_H-i-s');
    }
    return getSyncPath() . "/backups/{$timestamp}-{$backupType}.json";
}

// -----------------------------------------------------------------------------
// AUTOLOADER SYSTEM
// -----------------------------------------------------------------------------

/**
 * CueModule class for lazy-loading modules
 */
class CueModule {
    private string $moduleName;
    private array $functions = [];

    public function __construct(string $moduleName) {
        $this->moduleName = $moduleName;
    }

    public function __call(string $method, array $args): mixed {
        $functionName = $this->moduleName . '_' . $method;

        // Check if function exists
        if (!function_exists($functionName)) {
            throw new Exception("Function '{$functionName}' not found in module '{$this->moduleName}'");
        }

        return call_user_func_array($functionName, $args);
    }

    public function __get(string $property): string {
        $functionName = $this->moduleName . '_' . $property;

        if (!function_exists($functionName)) {
            throw new Exception("Function '{$functionName}' not found in module '{$this->moduleName}'");
        }

        return $functionName;
    }
}

/**
 * Smart autoloader for CUE modules
 * Loads modules on-demand to improve performance
 *
 * @param string $module Module name to load
 * @return CueModule Module instance
 * @throws Exception If module not found
 */
function cue_autoload(string $module): CueModule {
    static $loadedModules = [];

    if (!isset($loadedModules[$module])) {
        $modulePath = __DIR__ . "/{$module}.php";

        if (!file_exists($modulePath)) {
            throw new Exception("CUE Module not found: {$module} (expected at {$modulePath})");
        }

        require_once $modulePath;
        $loadedModules[$module] = new CueModule($module);
    }

    return $loadedModules[$module];
}

/**
 * Load modules based on current request context
 * Reduces memory usage by loading only necessary modules
 */
function loadContextualModules(): void {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';

    // EMERGENCY FIX: Skip automatic database loading to prevent 45s load times
    // Database loading disabled until performance issues are resolved
    /*
    // Database-heavy pages (settings, dbmanager)
    if (strpos($uri, '/settings/') !== false || strpos($script, 'dbmanager.php') !== false) {
        cue_autoload('database');
    }
    */

    // Security-heavy pages (admin, auth)
    if (strpos($uri, '/admin/') !== false || strpos($uri, '/auth/') !== false) {
        cue_autoload('security');
    }

    // Theme-related pages
    if (strpos($uri, '/theme/') !== false || strpos($script, 'ThemeManager.php') !== false) {
        cue_autoload('theme');
    }

    // Font-related pages
    if (strpos($uri, '/fonts/') !== false || strpos($script, 'list-fonts.php') !== false) {
        cue_autoload('font');
    }

    // API endpoints
    if (strpos($uri, '/api/') !== false) {
        cue_autoload('database'); // Database loading enabled for APIs
        cue_autoload('security'); // APIs need security validation
    }

    try {
        $tok = cue_autoload('tokenization');
        if ($tok) {
            $tok->enforce_request();
        }
    } catch (Throwable) {}

    // Global UI Components - Load on all pages (except API endpoints and raw outputs)
    if (strpos($uri, '/pdf-tools') !== 0 &&
        strpos($uri, '/api/') === false && 
        strpos($script, 'ajax') === false && 
        strpos($script, 'json') === false &&
        !headers_sent()) {
        loadGlobalUIComponents();
    }

    if (function_exists('mh_remember_me_middleware')) {
        mh_remember_me_middleware();
    }
}

function mh_is_interactive_last_page(string $uri): bool {
    $uri = trim($uri);
    if ($uri === '' || $uri[0] !== '/') {
        return false;
    }

    $parts = parse_url($uri);
    $path = is_array($parts) ? (string)($parts['path'] ?? '') : $uri;
    if ($path === '' || $path[0] !== '/') {
        return false;
    }
    if (strpos($path, '/auth/') === 0 || strpos($path, '/hub/widget/') === 0) {
        return false;
    }
    if ($path === '/hub/notices.php') {
        return false;
    }

    $query = [];
    if (is_array($parts) && isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $query);
        if (!is_array($query)) {
            $query = [];
        }
    }
    foreach (['ajax', 'b2_jobs', 'b2_policy', 'bucket_policy_status', 'snapshot_monitor', 'download', 'format'] as $blockedKey) {
        if (array_key_exists($blockedKey, $query)) {
            return false;
        }
    }

    return true;
}

function mh_manual_logout_guard_active(): bool {
    return isset($_COOKIE['mh_sso_logged_out'])
        && is_string($_COOKIE['mh_sso_logged_out'])
        && trim((string)$_COOKIE['mh_sso_logged_out']) === '1';
}

function mh_remember_me_middleware(): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (mh_manual_logout_guard_active()) {
        return;
    }

    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');

    if ($uri === '' || $script === '') {
        return;
    }

    if (strpos($uri, '/templates/assets/') === 0 || strpos($uri, '/assets/') === 0) {
        return;
    }

    $usernameHeader = '';
    if (isset($_SERVER['HTTP_AUTH_USER']) && is_string($_SERVER['HTTP_AUTH_USER']) && $_SERVER['HTTP_AUTH_USER'] !== '') {
        $usernameHeader = (string)$_SERVER['HTTP_AUTH_USER'];
    } elseif (isset($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
        $usernameHeader = (string)$_SERVER['REMOTE_USER'];
    }

    $hasSessionCookie = false;
    $sn = session_name();
    if (is_string($sn) && $sn !== '' && isset($_COOKIE[$sn]) && is_string($_COOKIE[$sn]) && $_COOKIE[$sn] !== '') {
        $hasSessionCookie = true;
    }

    if (session_status() !== PHP_SESSION_ACTIVE && ($hasSessionCookie || $usernameHeader !== '')) {
        mh_remember_me_start_session();
    }

    $username = '';
    $authSource = '';

    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] !== '') {
        $username = (string)$_SESSION['mh_auth_user'];
        $authSource = 'session';
    } elseif ($usernameHeader !== '') {
        $username = $usernameHeader;
        $authSource = 'sso';
    } else {
        return;
    }

    $pepper = mh_remember_me_get_pepper();
    if ($pepper === null) {
        return;
    }

    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
    } catch (Throwable) {
        return;
    }

    if (!function_exists('database_getConnectionById')) {
        return;
    }

    try {
        $pdoBio = call_user_func('database_getConnectionById', 'biometrics');
    } catch (Throwable) {
        return;
    }

    if (!(is_object($pdoBio) && $pdoBio instanceof PDO)) {
        if (is_object($pdoBio) && property_exists($pdoBio, 'pdo') && $pdoBio->pdo instanceof PDO) {
            $pdoBio = $pdoBio->pdo;
        }
    }
    if (!($pdoBio instanceof PDO)) {
        return;
    }

    mh_remember_me_ensure_schema_once($pdoBio);

    $userRow = mh_remember_me_find_user($pdoBio, $username);
    if (!is_array($userRow)) {
        return;
    }

    $userId = isset($userRow['id']) ? (int)$userRow['id'] : 0;
    if ($userId <= 0) {
        return;
    }

    $userRow = mh_remember_me_legacy_backfill($pdoBio, $userRow, $username);

    $ctx = mh_remember_me_get_context();
    $device = mh_remember_me_resolve_device($pdoBio, $pepper, $userId, $ctx);

    $sessionId = (session_status() === PHP_SESSION_ACTIVE) ? (string)session_id() : '';
    $sessionWasNew = false;
    if ($sessionId !== '') {
        $sessionWasNew = mh_remember_me_upsert_session($pdoBio, $userId, $sessionId, $device['device_token_id'] ?? null, $ctx);
    }

    $needsStepUp = false;
    $riskScore = 0;
    $riskFlags = [];

    if ($device['recognized'] ?? false) {
        [$riskScore, $riskFlags] = mh_remember_me_score_risk($device['row'] ?? null, $ctx);
        $needsStepUp = ($riskScore >= 60);
        mh_remember_me_update_device_risk($pdoBio, (int)($device['device_token_id'] ?? 0), $riskScore, $riskFlags, $ctx);
        mh_remember_me_touch_device($pdoBio, (int)($device['device_token_id'] ?? 0), $ctx);
    } else {
        $needsStepUp = true;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['mh_auth_step_up_required'] = $needsStepUp ? 1 : 0;
        $_SESSION['mh_auth_step_up_score'] = $riskScore;
        $_SESSION['mh_auth_step_up_flags'] = $riskFlags;
    }
    $GLOBALS['mh_auth_step_up_required'] = $needsStepUp;

    $rotateReason = '';
    if (!($device['recognized'] ?? false) && $authSource !== '') {
        $rotateReason = 'new_device';
    } elseif ($sessionWasNew) {
        $rotateReason = 'new_session';
    } elseif (($device['recognized'] ?? false) && mh_remember_me_should_rotate($device['row'] ?? null)) {
        $rotateReason = 'periodic';
    }

    if ($rotateReason !== '') {
        mh_remember_me_issue_or_rotate_cookie($pdoBio, $pepper, $userId, $device, $ctx, $rotateReason);
    }
}

function mh_remember_me_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('security');
        }
        if (function_exists('security_startSecureSession')) {
            call_user_func('security_startSecureSession');
            return;
        }
    } catch (Throwable) {}

    try {
        if (function_exists('startSecureSession')) {
            startSecureSession();
            return;
        }
    } catch (Throwable) {}

    try {
        session_start();
    } catch (Throwable) {}
}

function mh_remember_me_get_pepper(): ?string {
    $pepper = getenv('DEVICE_COOKIE_PEPPER');
    if (is_string($pepper) && trim($pepper) !== '') {
        return $pepper;
    }

    if (function_exists('getEncryptionKey')) {
        try {
            $k = (string)getEncryptionKey();
            if ($k !== '') {
                return $k;
            }
        } catch (Throwable) {}
    }

    $pepperFile = '/data/config/device_cookie_pepper';
    try {
        if (is_file($pepperFile)) {
            $raw = trim((string)file_get_contents($pepperFile));
            if ($raw !== '') {
                return $raw;
            }
        }
        if (is_dir('/data/config') || @mkdir('/data/config', 0700, true)) {
            $raw = bin2hex(random_bytes(32));
            file_put_contents($pepperFile, $raw);
            @chmod($pepperFile, 0600);
            return $raw;
        }
    } catch (Throwable) {}

    return null;
}

function mh_account_remember_me_cookie_name(): string {
    return '__Secure-mh_remember';
}

function mh_account_remember_me_b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function mh_account_remember_me_b64url_decode(string $b64u): string {
    $b64u = strtr($b64u, '-_', '+/');
    $pad = strlen($b64u) % 4;
    if ($pad) {
        $b64u .= str_repeat('=', 4 - $pad);
    }
    $out = base64_decode($b64u, true);
    return is_string($out) ? $out : '';
}

function mh_account_remember_me_hash(string $pepper, string $tokenRaw): string {
    return hash_hmac('sha256', $tokenRaw, $pepper, true);
}

function mh_account_remember_me_cookie_domain(): string {
    $host = strtolower(preg_replace('/:\\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === 'metahumans.one' || ($host !== '' && str_ends_with($host, '.metahumans.one'))) {
        return '.metahumans.one';
    }
    return '';
}

function mh_account_remember_me_issue(PDO $pdoBio, string $pepper, int $userId, string $username): void {
    if ($userId <= 0 || trim($username) === '' || headers_sent()) {
        return;
    }

    $tokenRaw = random_bytes(32);
    $token = mh_account_remember_me_b64url_encode($tokenRaw);
    $hash = mh_account_remember_me_hash($pepper, $tokenRaw);
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + 2592000);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ipPrefix = $ip !== '' ? $ip : null;

    try {
        $stmt = $pdoBio->prepare("INSERT INTO remember_me_tokens (user_id, username, token_hash, issued_at, expires_at, last_used_at, ua_family, ip_prefix) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $hash, $now, $expires, $now, $ua !== '' ? $ua : null, $ipPrefix]);
    } catch (Throwable) {
        return;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $domain = mh_account_remember_me_cookie_domain();
    if ($domain !== '') {
        $isSecure = true;
    }

    $cookie = [
        'expires' => time() + 2592000,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $cookie['domain'] = $domain;
    }

    setcookie(mh_account_remember_me_cookie_name(), $token, $cookie);
}

function mh_account_remember_me_try_restore(PDO $pdoBio, string $pepper): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (!empty($_SESSION['mh_auth_user'])) {
        return true;
    }

    $cookieName = mh_account_remember_me_cookie_name();
    $token = isset($_COOKIE[$cookieName]) ? trim((string)$_COOKIE[$cookieName]) : '';
    if ($token === '') {
        return false;
    }
    $tokenRaw = mh_account_remember_me_b64url_decode($token);
    if ($tokenRaw === '' || strlen($tokenRaw) < 16) {
        return false;
    }
    $hash = mh_account_remember_me_hash($pepper, $tokenRaw);

    try {
        $stmt = $pdoBio->prepare("SELECT id, user_id, username FROM remember_me_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || empty($row['username'])) {
            return false;
        }
        $username = (string)$row['username'];
        $_SESSION['mh_auth_user'] = $username;
        $_SESSION['mh_auth_method'] = 'remember_me';

        $authFunctions = getPublicPath() . '/auth/auth_functions.php';
        if (file_exists($authFunctions)) {
            require_once $authFunctions;
        }
        if (function_exists('mh_load_biometrics_user')) {
            mh_load_biometrics_user($username, null, null);
        }

        try {
            $pdoBio->prepare("UPDATE remember_me_tokens SET last_used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        } catch (Throwable) {}

        return true;
    } catch (Throwable) {
        return false;
    }
}

function mh_remember_me_ensure_schema_once(PDO $pdoBio): void {
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['__schema_remember_me']) && is_int($_SESSION['__schema_remember_me'])) {
        return;
    }

    try {
        $pdoBio->exec("CREATE TABLE IF NOT EXISTS user_device_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            series_id CHAR(36) NOT NULL,
            token_hash VARBINARY(32) NOT NULL,
            prev_token_hash VARBINARY(32) DEFAULT NULL,
            prev_valid_until DATETIME DEFAULT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            last_rotated_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            revoked_reason VARCHAR(255) DEFAULT NULL,
            ua_family VARCHAR(32) DEFAULT NULL,
            ua_major INT DEFAULT NULL,
            os_family VARCHAR(32) DEFAULT NULL,
            asn INT DEFAULT NULL,
            country CHAR(2) DEFAULT NULL,
            ip_prefix VARCHAR(64) DEFAULT NULL,
            risk_last INT NOT NULL DEFAULT 0,
            risk_flags_last TEXT DEFAULT NULL,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_user_state (user_id, revoked_at, expires_at),
            KEY idx_series (series_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdoBio->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            device_token_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            ua_family VARCHAR(32) DEFAULT NULL,
            ua_major INT DEFAULT NULL,
            os_family VARCHAR(32) DEFAULT NULL,
            asn INT DEFAULT NULL,
            country CHAR(2) DEFAULT NULL,
            ip_prefix VARCHAR(64) DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_user_session (user_id, session_id),
            KEY idx_device_token (device_token_id),
            KEY idx_user_last_seen (user_id, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdoBio->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            credential_id VARCHAR(255) NOT NULL,
            user_handle VARCHAR(255) DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            public_key MEDIUMTEXT DEFAULT NULL,
            sign_count INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_credential_id (credential_id),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdoBio->exec("CREATE TABLE IF NOT EXISTS remember_me_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(255) NOT NULL,
            token_hash VARBINARY(32) NOT NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            revoked_at DATETIME DEFAULT NULL,
            revoked_reason VARCHAR(255) DEFAULT NULL,
            ua_family VARCHAR(32) DEFAULT NULL,
            ip_prefix VARCHAR(64) DEFAULT NULL,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_user_state (user_id, revoked_at, expires_at),
            KEY idx_username_state (username, revoked_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $pdoBio->query("SELECT tenant_id FROM users LIMIT 1"); } catch (Throwable) { $pdoBio->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL"); }
        try { $pdoBio->query("SELECT device_id FROM users LIMIT 1"); } catch (Throwable) { $pdoBio->exec("ALTER TABLE users ADD COLUMN device_id VARCHAR(255) DEFAULT NULL"); }
        try { $pdoBio->query("SELECT persona_name FROM users LIMIT 1"); } catch (Throwable) { $pdoBio->exec("ALTER TABLE users ADD COLUMN persona_name VARCHAR(255) DEFAULT NULL"); }
        try { $pdoBio->query("SELECT real_first_name FROM users LIMIT 1"); } catch (Throwable) { try { $pdoBio->exec("ALTER TABLE users ADD COLUMN real_first_name VARCHAR(64) DEFAULT NULL AFTER name"); } catch (Throwable) {} }
        try { $pdoBio->query("SELECT real_last_name FROM users LIMIT 1"); } catch (Throwable) { try { $pdoBio->exec("ALTER TABLE users ADD COLUMN real_last_name VARCHAR(64) DEFAULT NULL AFTER real_first_name"); } catch (Throwable) {} }
        try {
            $stmt = $pdoBio->query("SELECT id, name, real_first_name, real_last_name FROM users WHERE (real_first_name IS NULL OR real_first_name = '' OR real_last_name IS NULL OR real_last_name = '') AND name IS NOT NULL AND name <> '' LIMIT 500");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $id = isset($r['id']) ? (int)$r['id'] : 0;
                    if ($id <= 0) continue;
                    $rf = isset($r['real_first_name']) ? trim((string)$r['real_first_name']) : '';
                    $rl = isset($r['real_last_name']) ? trim((string)$r['real_last_name']) : '';
                    if ($rf !== '' && $rl !== '') continue;
                    $name = isset($r['name']) ? trim((string)$r['name']) : '';
                    $parts = $name !== '' ? preg_split('/\\s+/', $name) : [];
                    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
                    if (count($parts) < 2) continue;
                    $rf2 = $rf !== '' ? $rf : (string)$parts[0];
                    $rl2 = $rl !== '' ? $rl : (string)$parts[count($parts) - 1];
                    $pdoBio->prepare("UPDATE users SET real_first_name = COALESCE(NULLIF(real_first_name,''), ?), real_last_name = COALESCE(NULLIF(real_last_name,''), ?) WHERE id = ?")->execute([$rf2, $rl2, $id]);
                }
            }
        } catch (Throwable) {}
    } catch (Throwable) {}

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['__schema_remember_me'] = time();
    }
}

function mh_remember_me_find_user(PDO $pdoBio, string $username): ?array {
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    try {
        $stmt = $pdoBio->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function mh_remember_me_legacy_backfill(PDO $pdoBio, array $userRow, string $username): array {
    $tenantId = isset($userRow['tenant_id']) && is_string($userRow['tenant_id']) ? trim($userRow['tenant_id']) : '';
    $personaName = isset($userRow['persona_name']) && is_string($userRow['persona_name']) ? trim($userRow['persona_name']) : '';
    $deviceId = isset($userRow['device_id']) && is_string($userRow['device_id']) ? trim($userRow['device_id']) : '';

    $updates = [];
    $params = [];

    if ($tenantId === '') {
        $tenantId = 'user:' . $username;
        $updates[] = "tenant_id = ?";
        $params[] = $tenantId;
        $userRow['tenant_id'] = $tenantId;
    }

    if ($personaName === '') {
        $personaName = $username;
        $updates[] = "persona_name = ?";
        $params[] = $personaName;
        $userRow['persona_name'] = $personaName;
    }

    if ($deviceId === '') {
        $deviceId = 'MetaHuman_' . substr(hash('sha256', $username), 0, 8);
        $updates[] = "device_id = ?";
        $params[] = $deviceId;
        $userRow['device_id'] = $deviceId;
    }

    if (!empty($updates) && isset($userRow['id'])) {
        try {
            $params[] = (int)$userRow['id'];
            $stmt = $pdoBio->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);
        } catch (Throwable) {}
    }

    if (function_exists('mh_provision_tenant_storage') && is_string($tenantId) && $tenantId !== '') {
        try { mh_provision_tenant_storage($tenantId); } catch (Throwable) {}
    }

    $tenantSafe = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $tenantId));
    $tenantSafe = trim((string)$tenantSafe, '._-');
    if ($tenantSafe !== '') {
        $root = '/data/tenants/' . $tenantSafe;
        $devicesRoot = $root . '/devices';
        $deviceSafe = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $deviceId));
        $deviceSafe = trim((string)$deviceSafe, '._-');
        if ($deviceSafe !== '') {
            $deviceDir = $devicesRoot . '/' . $deviceSafe;
            if (!is_dir($deviceDir)) @mkdir($deviceDir, 0700, true);
        }
        if (!is_dir($root)) @mkdir($root, 0700, true);
        if (!is_dir($devicesRoot)) @mkdir($devicesRoot, 0700, true);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['mh_tenant_id'] = $tenantId;
        $_SESSION['mh_device_id'] = $deviceId;
    }

    return $userRow;
}

function mh_remember_me_get_context(): array {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    [$uaFamily, $uaMajor] = mh_remember_me_parse_ua_family_major($ua);
    $osFamily = mh_remember_me_parse_os_family($ua);
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ipPrefix = mh_remember_me_ip_prefix($ip);
    return [
        'ua_family' => $uaFamily,
        'ua_major' => $uaMajor,
        'os_family' => $osFamily,
        'asn' => null,
        'country' => null,
        'ip_prefix' => $ipPrefix,
    ];
}

function mh_remember_me_parse_ua_family_major(string $ua): array {
    $ua = (string)$ua;
    $family = 'Other';
    $major = null;

    $candidates = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
    ];

    foreach ($candidates as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $family = $name;
            break;
        }
    }

    $re = '/(Edg|OPR|Chrome|Firefox|Version)\\/([0-9]+)/';
    if (preg_match($re, $ua, $m)) {
        $major = (int)$m[2];
        if (($m[1] ?? '') === 'Version' && $family === 'Safari') {
            $major = (int)$m[2];
        }
    }

    return [$family, $major];
}

function mh_remember_me_parse_os_family(string $ua): string {
    $ua = (string)$ua;
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) return 'iOS';
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) return 'macOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Other';
}

function mh_remember_me_ip_prefix(string $ip): ?string {
    $ip = trim($ip);
    if ($ip === '') return null;
    if (strpos($ip, ':') !== false) {
        $parts = explode(':', $ip);
        $parts = array_pad($parts, 8, '0');
        $prefix = implode(':', array_slice($parts, 0, 4));
        return $prefix . '::/64';
    }
    $parts = explode('.', $ip);
    if (count($parts) !== 4) return null;
    return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
}

function mh_remember_me_decode_token(string $token): ?string {
    $token = trim($token);
    if ($token === '') return null;
    if (!preg_match('/^[A-Za-z0-9\\-_]{20,200}$/', $token)) return null;
    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad) $b64 .= str_repeat('=', 4 - $pad);
    $raw = base64_decode($b64, true);
    return is_string($raw) && $raw !== '' ? $raw : null;
}

function mh_remember_me_hash_token(string $pepper, string $tokenB64u): ?string {
    $raw = mh_remember_me_decode_token($tokenB64u);
    if ($raw === null) return null;
    return hash_hmac('sha256', $raw, $pepper, true);
}

function mh_remember_me_resolve_device(PDO $pdoBio, string $pepper, int $userId, array $ctx): array {
    $cookie = isset($_COOKIE['__Host-device']) && is_string($_COOKIE['__Host-device']) ? (string)$_COOKIE['__Host-device'] : '';
    $hash = $cookie !== '' ? mh_remember_me_hash_token($pepper, $cookie) : null;
    if ($hash === null) {
        return ['recognized' => false, 'device_token_id' => null, 'row' => null];
    }

    try {
        $stmt = $pdoBio->prepare("SELECT * FROM user_device_tokens
            WHERE (token_hash = ? OR (prev_token_hash = ? AND prev_valid_until IS NOT NULL AND prev_valid_until >= NOW()))
              AND revoked_at IS NULL
              AND expires_at > NOW()
            LIMIT 1");
        $stmt->execute([$hash, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['recognized' => false, 'device_token_id' => null, 'row' => null];
        }
        $rowUserId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
        if ($rowUserId !== $userId) {
            return ['recognized' => false, 'device_token_id' => null, 'row' => null];
        }

        return [
            'recognized' => true,
            'device_token_id' => isset($row['id']) ? (int)$row['id'] : null,
            'row' => $row,
        ];
    } catch (Throwable) {
        return ['recognized' => false, 'device_token_id' => null, 'row' => null];
    }
}

function mh_remember_me_upsert_session(PDO $pdoBio, int $userId, string $sessionId, mixed $deviceTokenId, array $ctx): bool {
    try {
        $now = date('Y-m-d H:i:s');
        $uaFamily = $ctx['ua_family'] ?? null;
        $uaMajor = $ctx['ua_major'] ?? null;
        $osFamily = $ctx['os_family'] ?? null;
        $asn = $ctx['asn'] ?? null;
        $country = $ctx['country'] ?? null;
        $ipPrefix = $ctx['ip_prefix'] ?? null;

        $stmt = $pdoBio->prepare("INSERT INTO user_sessions
            (user_id, session_id, device_token_id, created_at, last_seen_at, ua_family, ua_major, os_family, asn, country, ip_prefix)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                device_token_id = VALUES(device_token_id),
                last_seen_at = VALUES(last_seen_at),
                ua_family = VALUES(ua_family),
                ua_major = VALUES(ua_major),
                os_family = VALUES(os_family),
                asn = VALUES(asn),
                country = VALUES(country),
                ip_prefix = VALUES(ip_prefix)");

        $stmt->execute([
            $userId,
            $sessionId,
            $deviceTokenId ? (int)$deviceTokenId : null,
            $now,
            $now,
            $uaFamily,
            $uaMajor,
            $osFamily,
            $asn,
            $country,
            $ipPrefix,
        ]);

        return $stmt->rowCount() === 1;
    } catch (Throwable) {
        return false;
    }
}

function mh_remember_me_touch_device(PDO $pdoBio, int $deviceTokenId, array $ctx): void {
    if ($deviceTokenId <= 0) return;
    try {
        $stmt = $pdoBio->prepare("UPDATE user_device_tokens
            SET last_seen_at = NOW(),
                ua_family = ?,
                ua_major = ?,
                os_family = ?,
                asn = ?,
                country = ?,
                ip_prefix = ?
            WHERE id = ?");
        $stmt->execute([
            $ctx['ua_family'] ?? null,
            $ctx['ua_major'] ?? null,
            $ctx['os_family'] ?? null,
            $ctx['asn'] ?? null,
            $ctx['country'] ?? null,
            $ctx['ip_prefix'] ?? null,
            $deviceTokenId,
        ]);
    } catch (Throwable) {}
}

function mh_remember_me_score_risk(mixed $deviceRow, array $ctx): array {
    $score = 0;
    $flags = [];
    if (!is_array($deviceRow)) {
        return [$score, $flags];
    }

    $uaFamilyOld = isset($deviceRow['ua_family']) ? (string)$deviceRow['ua_family'] : '';
    $osFamilyOld = isset($deviceRow['os_family']) ? (string)$deviceRow['os_family'] : '';
    $asnOld = isset($deviceRow['asn']) ? $deviceRow['asn'] : null;
    $countryOld = isset($deviceRow['country']) ? (string)$deviceRow['country'] : '';

    $uaFamilyNow = (string)($ctx['ua_family'] ?? '');
    $osFamilyNow = (string)($ctx['os_family'] ?? '');
    $asnNow = $ctx['asn'] ?? null;
    $countryNow = (string)($ctx['country'] ?? '');

    if ($uaFamilyOld !== '' && $uaFamilyNow !== '' && ($uaFamilyOld !== $uaFamilyNow || ($osFamilyOld !== '' && $osFamilyNow !== '' && $osFamilyOld !== $osFamilyNow))) {
        $score += 40;
        $flags[] = 'UA_OS_CHANGE';
    }

    if ($asnOld !== null && $asnNow !== null && (int)$asnOld !== (int)$asnNow) {
        $score += 30;
        $flags[] = 'ASN_CHANGE';
    }

    if ($countryOld !== '' && $countryNow !== '' && strtoupper($countryOld) !== strtoupper($countryNow)) {
        $score += 50;
        $flags[] = 'COUNTRY_CHANGE';
    }

    return [$score, $flags];
}

function mh_remember_me_update_device_risk(PDO $pdoBio, int $deviceTokenId, int $riskScore, array $riskFlags, array $ctx): void {
    if ($deviceTokenId <= 0) return;
    try {
        $flagsJson = json_encode(array_values($riskFlags));
        $stmt = $pdoBio->prepare("UPDATE user_device_tokens
            SET risk_last = ?,
                risk_flags_last = ?,
                ua_family = ?,
                ua_major = ?,
                os_family = ?,
                asn = ?,
                country = ?,
                ip_prefix = ?
            WHERE id = ?");
        $stmt->execute([
            $riskScore,
            $flagsJson !== false ? $flagsJson : null,
            $ctx['ua_family'] ?? null,
            $ctx['ua_major'] ?? null,
            $ctx['os_family'] ?? null,
            $ctx['asn'] ?? null,
            $ctx['country'] ?? null,
            $ctx['ip_prefix'] ?? null,
            $deviceTokenId,
        ]);
    } catch (Throwable) {}
}

function mh_remember_me_should_rotate(mixed $deviceRow): bool {
    if (!is_array($deviceRow)) return true;
    $last = isset($deviceRow['last_rotated_at']) ? (string)$deviceRow['last_rotated_at'] : '';
    if ($last === '' || $last === '0000-00-00 00:00:00') return true;
    $ts = strtotime($last);
    if ($ts === false) return true;
    return (time() - $ts) > (14 * 24 * 3600);
}

function mh_remember_me_issue_or_rotate_cookie(PDO $pdoBio, string $pepper, int $userId, array $device, array $ctx, string $reason): void {
    if (headers_sent()) {
        return;
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host = strtolower(preg_replace('/:\\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === 'metahumans.one' || ($host !== '' && str_ends_with($host, '.metahumans.one'))) {
        $isSecure = true;
    }

    $tokenRaw = random_bytes(32);
    $token = rtrim(strtr(base64_encode($tokenRaw), '+/', '-_'), '=');
    $hash = hash_hmac('sha256', $tokenRaw, $pepper, true);

    $expiresTs = time() + 31536000;
    $expiresAt = date('Y-m-d H:i:s', $expiresTs);

    if (($device['recognized'] ?? false) && isset($device['device_token_id']) && (int)$device['device_token_id'] > 0) {
        $deviceTokenId = (int)$device['device_token_id'];
        $prevHash = null;
        if (is_array($device['row'] ?? null) && isset($device['row']['token_hash'])) {
            $prevHash = $device['row']['token_hash'];
        }
        try {
            $stmt = $pdoBio->prepare("UPDATE user_device_tokens
                SET prev_token_hash = ?,
                    prev_valid_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                    token_hash = ?,
                    last_rotated_at = NOW(),
                    last_seen_at = NOW(),
                    expires_at = ?,
                    revoked_at = NULL,
                    revoked_reason = NULL,
                    ua_family = ?,
                    ua_major = ?,
                    os_family = ?,
                    asn = ?,
                    country = ?,
                    ip_prefix = ?
                WHERE id = ? AND user_id = ?");
            $stmt->execute([
                $prevHash,
                $hash,
                $expiresAt,
                $ctx['ua_family'] ?? null,
                $ctx['ua_major'] ?? null,
                $ctx['os_family'] ?? null,
                $ctx['asn'] ?? null,
                $ctx['country'] ?? null,
                $ctx['ip_prefix'] ?? null,
                $deviceTokenId,
                $userId,
            ]);
        } catch (Throwable) {}
    } else {
        $seriesId = mh_remember_me_uuid();
        $issuedAt = date('Y-m-d H:i:s');
        $tries = 0;
        while ($tries < 3) {
            $tries++;
            try {
                $stmt = $pdoBio->prepare("INSERT INTO user_device_tokens
                    (user_id, series_id, token_hash, issued_at, expires_at, last_seen_at, last_rotated_at, ua_family, ua_major, os_family, asn, country, ip_prefix, risk_last, risk_flags_last)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, 0, NULL)");
                $stmt->execute([
                    $userId,
                    $seriesId,
                    $hash,
                    $issuedAt,
                    $expiresAt,
                    $ctx['ua_family'] ?? null,
                    $ctx['ua_major'] ?? null,
                    $ctx['os_family'] ?? null,
                    $ctx['asn'] ?? null,
                    $ctx['country'] ?? null,
                    $ctx['ip_prefix'] ?? null,
                ]);
                break;
            } catch (Throwable) {
                $tokenRaw = random_bytes(32);
                $token = rtrim(strtr(base64_encode($tokenRaw), '+/', '-_'), '=');
                $hash = hash_hmac('sha256', $tokenRaw, $pepper, true);
            }
        }
    }

    setcookie('__Host-device', $token, [
        'expires' => $expiresTs,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['__Host-device'] = $token;
}

function mh_remember_me_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * Load Global UI Components on all pages
 * Makes global functions available for header, footer, and hamburger menu
 */
function loadGlobalUIComponents(): void {
    static $globalUILoaded = false;
    
    if ($globalUILoaded) {
        return; // Prevent duplicate loading
    }
    
    $globalUILoaded = true;
    
    // Define the global UI includes path
    $globalUIPath = getTemplatesPath() . '/global-ui/includes';
    
    // Debug tracking via logs (no output)
    if (!defined('CUE_CLI_MODE')) {
        cue_debug_log('CUE Debug: Global UI path: ' . $globalUIPath);
        cue_debug_log('CUE Debug: Header exists: ' . (file_exists($globalUIPath . '/header.php') ? 'yes' : 'no'));
        cue_debug_log('CUE Debug: Footer exists: ' . (file_exists($globalUIPath . '/footer.php') ? 'yes' : 'no'));
        cue_debug_log('CUE Debug: Hamburger exists: ' . (file_exists($globalUIPath . '/hamburger.php') ? 'yes' : 'no'));
    }
    
    // Check if global UI components exist
    if (!file_exists($globalUIPath . '/header.php') || 
        !file_exists($globalUIPath . '/footer.php') || 
        !file_exists($globalUIPath . '/hamburger.php')) {
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log('CUE Debug: Skipping auto-injection - files missing');
        }
        return; // Skip if components don't exist
    }
    
    // Make global UI functions available
    $functionsPath = getTemplatesPath() . '/global-ui/functions.php';
    require_once $functionsPath;
    
    if (!function_exists('includeGlobalUIStyles')) {
        if (!defined('CUE_CLI_MODE')) {
            error_log('CUE CRITICAL: includeGlobalUIStyles NOT defined after loading ' . $functionsPath);
        }
    }
    
    // Register automatic injection for full HTML pages (only if not already registered)
    if (!isset($GLOBALS['_CUE_GLOBAL_UI_REGISTERED']) && 
        !defined('CUE_DISABLE_AUTO_UI') &&
        strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === false) {
        
        $GLOBALS['_CUE_GLOBAL_UI_REGISTERED'] = true;
        
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log('CUE Debug: Auto-injection registered late');
        }
        
        // Ensure output buffering is active for auto-injection
        if (!ob_get_level()) {
            ob_start();
        }
        register_shutdown_function('cue_autoInjectGlobalUI');
    }
}

/**
 * Auto-inject Global UI Components at shutdown
 * Automatically adds header, footer, hamburger menu, and styles to HTML pages
 */
function cue_autoInjectGlobalUI(): void {
    
    $envVar = getenv('CUE_DISABLE_AUTO_UI');
    $constDefined = defined('CUE_DISABLE_AUTO_UI');
    $constValue = $constDefined ? CUE_DISABLE_AUTO_UI : 'NOT_DEFINED';
    
    error_log("CUE DEBUG: check - Env: " . var_export($envVar, true) . ", Const Defined: " . ($constDefined ? 'YES' : 'NO') . ", Const Value: " . var_export($constValue, true));
    
    if (defined('CUE_DISABLE_AUTO_UI') && CUE_DISABLE_AUTO_UI === true) {
         error_log("CUE Debug: Disabled via CONST");
         return;
    }
    
    if ($envVar) {
         error_log("CUE Debug: Disabled via ENV");
         return;
    }


    // Capture all output buffer contents
    $output = ob_get_contents();
    
    // Enhanced debugging
    if (!defined('CUE_CLI_MODE')) {
        $bufferLevel = ob_get_level();
        $bufferStatus = $output !== false ? 'valid' : 'false';
        $outputPreview = $output ? substr($output, 0, 100) : 'empty';
        cue_debug_log("CUE Debug: Auto-injection shutdown function called. Buffer level: $bufferLevel, Status: $bufferStatus, Output length: " . strlen($output ?: '0'));
        cue_debug_log("CUE Debug: Output preview: " . str_replace(["\n", "\r"], ['\\n', '\\r'], $outputPreview));
    }
    
    // Validate output content
    if ($output === false) {
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log("CUE Debug: No output buffer content found");
        }
        return;
    }
    
    if (strlen($output) < 50) {
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log("CUE Debug: Output too small for injection (" . strlen($output) . " chars)");
        }
        return;
    }
    
    ob_clean();
    
    // Skip auto-injection for AJAX requests
    if (isset($_GET['ajax_header']) || isset($_GET['debug_config']) || isset($_POST['auto_save_header']) || 
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        echo $output;
        return;
    }
    
    // Only inject if this looks like an HTML page (be more permissive for partial content)
    $isHTML = (strpos($output, '<html') !== false || 
               strpos($output, '<body') !== false || 
               strpos($output, '<!DOCTYPE') !== false ||
               strpos($output, '<head') !== false ||
               strpos($output, '</body>') !== false ||
               strpos($output, '</html>') !== false ||
               preg_match('/<[a-zA-Z][^>]*>/', $output)); // Any HTML tags
    
    if (!$isHTML) {
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log("CUE Debug: Content doesn't appear to be HTML, skipping injection. Content preview: " . substr($output, 0, 200));
        }
        echo $output;
        return;
    }
    
    // If we only have partial content (no full page structure), try alternative injection
    $hasCompleteStructure = (strpos($output, '<html') !== false && strpos($output, '<body') !== false);
    if (!$hasCompleteStructure) {
        if (!defined('CUE_CLI_MODE')) {
            cue_debug_log("CUE Debug: Partial content detected, attempting alternative injection strategy");
        }
    }
    
    if (!defined('CUE_CLI_MODE')) {
        cue_debug_log("CUE Debug: HTML detected, proceeding with injection");
    }
    
    try {
        // Load the functions if not already loaded
        if (!function_exists('renderGlobalHeader')) {
            $functionsPath = getTemplatesPath() . '/global-ui/functions.php';
            if (!defined('CUE_CLI_MODE')) {
                cue_debug_log("CUE Debug: Loading functions from: " . $functionsPath);
            }
            
            if (!file_exists($functionsPath)) {
                if (!defined('CUE_CLI_MODE')) {
                    error_log("CUE Error: Functions file not found at: " . $functionsPath);
                }
                $faContent = '';
                if (empty($GLOBALS['_FA_LOADED'])) {
                    $faUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/icons/fontawesome/css/all.min.css') : '/templates/assets/icons/fontawesome/css/all.min.css';
                    $faPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__) . '/templates/assets/icons/fontawesome/css/all.min.css');
                    if (file_exists($faPath)) {
                        $faContent .= '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
                        $GLOBALS['_FA_LOADED'] = true;
                    } else {
                        $faAltUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/fonts/all.min.css') : '/templates/assets/fonts/all.min.css';
                        $faAltPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/fonts/all.min.css') : (dirname(__DIR__) . '/templates/assets/fonts/all.min.css');
                        if (file_exists($faAltPath)) {
                            $faContent .= '<link rel="stylesheet" href="' . htmlspecialchars($faAltUrl, ENT_QUOTES) . '">';
                            $GLOBALS['_FA_LOADED'] = true;
                        }
                    }
                }
                if (empty($GLOBALS['_FA_META_LOADED'])) {
                    $metaUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB64.png') : '/templates/assets/images/branding/logo/MHlogoTB64.png';
                    $faContent .= '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
                    $GLOBALS['_FA_META_LOADED'] = true;
                }
                if ($faContent) {
                    if (preg_match('/(<\/head>)/i', $output)) {
                        $output = preg_replace('/(<\/head>)/i', $faContent . '$1', $output, 1);
                    } else if (preg_match('/(<body[^>]*>)/i', $output)) {
                        $output = preg_replace('/(<body[^>]*>)/i', '<head>' . $faContent . '</head>$1', $output, 1);
                    } else {
                        $output = $faContent . $output;
                    }
                }
                echo $output;
                return;
            }
            
            require_once $functionsPath;
            
            // Double-check the function was loaded
            if (!function_exists('renderGlobalHeader')) {
                if (!defined('CUE_CLI_MODE')) {
                    error_log("CUE Error: renderGlobalHeader function not found after loading functions.php");
                }
                echo $output;
                return;
            }
        }
        
        // Capture header components
        ob_start();
        try {
            renderGlobalHeader();
            if (function_exists('renderGlobalHamburgerMenu')) {
                renderGlobalHamburgerMenu();
            }
        } catch (Exception $e) {
            if (!defined('CUE_CLI_MODE')) {
                error_log("CUE Error rendering header: " . $e->getMessage());
            }
            echo '<div class="cue-global-header" style="background: #1a1a2e; color: #00ffff; padding: 10px; border-bottom: 1px solid #00ffff;">⚠️ Header rendering error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        $headerContent = ob_get_clean();
        
        // Capture footer components  
        ob_start();
        try {
            if (function_exists('renderGlobalFooter')) {
                renderGlobalFooter();
            }
            if (function_exists('includeGlobalUIScripts')) {
                includeGlobalUIScripts();
            }
        } catch (Exception $e) {
            if (!defined('CUE_CLI_MODE')) {
                error_log("CUE Error rendering footer: " . $e->getMessage());
            }
            echo '<div class="cue-global-footer" style="background: #1a1a2e; color: #00ffff; padding: 10px; border-top: 1px solid #00ffff;">⚠️ Footer rendering error</div>';
        }
        $footerContent = ob_get_clean();
        
        // Capture styles
        ob_start();
        try {
            if (!function_exists('includeGlobalUIStyles')) {
                $p = dirname(__DIR__) . '/templates/global-ui/functions.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (function_exists('includeGlobalUIStyles')) {
                call_user_func('includeGlobalUIStyles');
            }
        } catch (Throwable) {}
        $stylesContent = ob_get_clean();
        $faContent = '';
        if (empty($GLOBALS['_FA_LOADED'])) {
            $faUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/icons/fontawesome/css/all.min.css') : '/templates/assets/icons/fontawesome/css/all.min.css';
            $faPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/icons/fontawesome/css/all.min.css') : (dirname(__DIR__) . '/templates/assets/icons/fontawesome/css/all.min.css');
            if (file_exists($faPath)) {
                $faContent .= '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
                $GLOBALS['_FA_LOADED'] = true;
            } else {
                $faAltUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/fonts/all.min.css') : '/templates/assets/fonts/all.min.css';
                $faAltPath = function_exists('getPublicPath') ? (getPublicPath() . '/templates/assets/fonts/all.min.css') : (dirname(__DIR__) . '/templates/assets/fonts/all.min.css');
                if (file_exists($faAltPath)) {
                    $faContent .= '<link rel="stylesheet" href="' . htmlspecialchars($faAltUrl, ENT_QUOTES) . '">';
                    $GLOBALS['_FA_LOADED'] = true;
                }
            }
        }
        if (empty($GLOBALS['_FA_META_LOADED'])) {
            $metaUrl = function_exists('getTemplateURL') ? getTemplateURL('assets/images/branding/logo/MHlogoTB64.png') : '/templates/assets/images/branding/logo/MHlogoTB64.png';
            $faContent .= '<style>.fa-metahumans:before{content:"";background-image:url(' . htmlspecialchars($metaUrl, ENT_QUOTES) . ');background-size:contain;background-repeat:no-repeat;background-position:center;display:inline-block;width:1em;height:1em}</style>';
            $GLOBALS['_FA_META_LOADED'] = true;
        }
        $stylesContent = $faContent . $stylesContent;
        
        // Inject styles in head (check if styles were already injected by looking for specific marker)
        if (strpos($output, '/* CUE Global UI Styles */') === false) {
            if (preg_match('/(<\/head>)/i', $output)) {
                $output = preg_replace('/(<\/head>)/i', $stylesContent . '$1', $output, 1);
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: Styles injected before </head>");
                }
            } else if (preg_match('/(<body[^>]*>)/i', $output)) {
                $output = preg_replace('/(<body[^>]*>)/i', '<head>' . $stylesContent . '</head>$1', $output, 1);
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: Created <head> section and injected styles");
                }
            }
        } else {
            if (!defined('CUE_CLI_MODE')) {
                cue_debug_log("CUE Debug: Global UI styles already injected, skipping style injection");
            }
        }
        
        // Inject header after body tag and add spacing class
        if (strpos($output, '<header class="cue-global-header"') === false && 
            strpos($output, 'data-component="header"') === false) {
            $bodyMatches = preg_match('/(<body[^>]*>)/i', $output);
            if ($bodyMatches) {
                // Add animation preservation script before header injection
                $animationPreservationScript = '
                <script>
                // Store any existing VANTA animation before header injection
                if (typeof window.currentVantaEffect !== "undefined" && window.currentVantaEffect) {
                    console.log("🔄 CUE Core: Preserving existing animation during header injection");
                    window.preInjectionVantaEffect = window.currentVantaEffect;
                    window.preInjectionAnimation = {
                        element: document.getElementById("headerAnimationBg"),
                        config: window.currentVantaEffect.options || {}
                    };
                }
                </script>';
                
                // Add cue-header-spacing class to body for specificity
                $output = preg_replace('/(<body[^>]*)>/i', '$1 class="cue-header-spacing">' . $animationPreservationScript . $headerContent, $output, 1);
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: Header injected after <body> with spacing class and animation preservation");
                }
            } else {
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: No <body> tag found for header injection");
                }
            }
        } else {
            if (!defined('CUE_CLI_MODE')) {
                cue_debug_log("CUE Debug: Global header HTML already exists, skipping header injection");
            }
        }
        
        // Inject footer before closing body tag (check for actual HTML element, not CSS)
        if (strpos($output, '<footer class="cue-global-footer"') === false && 
            strpos($output, 'data-component="footer"') === false) {
            if (strpos($output, '</body>') !== false) {
                $output = str_replace('</body>', $footerContent . '</body>', $output);
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: Footer injected before </body>");
                }
            } else {
                if (!defined('CUE_CLI_MODE')) {
                    cue_debug_log("CUE Debug: No </body> tag found for footer injection");
                }
            }
        } else {
            if (!defined('CUE_CLI_MODE')) {
                cue_debug_log("CUE Debug: Global footer HTML already exists, skipping footer injection");
            }
        }
        
    } catch (Exception $e) {
        error_log("CUE Global UI auto-injection failed: " . $e->getMessage());
    }
    
    echo $output;
}

/**
 * Manual Global UI injection functions for explicit control
 */
function cue_renderGlobalHeader(mixed $config = []): void {
    $cfg = is_array($config) ? $config : [];
    if (function_exists('renderGlobalHeader')) {
        renderGlobalHeader($cfg);
    } else {
        include_once getTemplatesPath() . '/global-ui/includes/header.php';
    }
}

function cue_renderGlobalFooter(mixed $config = []): void {
    $cfg = is_array($config) ? $config : [];
    if (function_exists('renderGlobalFooter')) {
        renderGlobalFooter($cfg);
    } else {
        include_once getTemplatesPath() . '/global-ui/includes/footer.php';
    }
}

function cue_renderGlobalHamburgerMenu(mixed $config = []): void {
    $cfg = is_array($config) ? $config : [];
    if (function_exists('renderGlobalHamburgerMenu')) {
        renderGlobalHamburgerMenu($cfg);
    } else {
        include_once getTemplatesPath() . '/global-ui/includes/hamburger.php';
    }
}

function cue_includeGlobalUIStyles(): void {
    if (function_exists('includeGlobalUIStyles')) {
        includeGlobalUIStyles();
    } else {
        include_once getTemplatesPath() . '/global-ui/includes/styles.php';
    }
}

function cue_includeGlobalUIScripts(): void {
    if (function_exists('includeGlobalUIScripts')) {
        includeGlobalUIScripts();
    } else {
        include_once getTemplatesPath() . '/global-ui/includes/scripts.php';
    }
}

/**
 * Fallback function for manual Global UI rendering
 * Use this if auto-injection is disabled or not working
 * Call this function in your page to manually render all Global UI components
 * 
 * @param bool $includeStyles Whether to include CSS styles (default: true)
 * @param bool $includeScripts Whether to include JavaScript (default: true)
 * @param array $config Optional configuration override
 * 
 * Example usage:
 * // In <head>: 
 * cue_renderGlobalUIFallback(true, false);
 * 
 * // After <body>:
 * cue_renderGlobalUIFallback(false, false, ['header_only' => true]);
 * 
 * // Before </body>:
 * cue_renderGlobalUIFallback(false, true, ['footer_only' => true]);
 */
function cue_renderGlobalUIFallback(mixed $includeStyles = true, mixed $includeScripts = true, mixed $config = []): void {
    $includeStyles = (bool)$includeStyles;
    $includeScripts = (bool)$includeScripts;
    $config = is_array($config) ? $config : [];
    try {
        // Load functions if not available
        if (!function_exists('renderGlobalHeader')) {
            require_once getTemplatesPath() . '/global-ui/functions.php';
        }
        
        // Render styles if requested
        if ($includeStyles && empty($config['header_only']) && empty($config['footer_only'])) {
            echo "\n<!-- CUE Global UI Styles -->\n";
            cue_includeGlobalUIStyles();
        }
        
        // Render header if not header-only mode or footer-only mode
        if (empty($config['footer_only']) && empty($config['scripts_only'])) {
            echo "\n<!-- CUE Global UI Header -->\n";
            cue_renderGlobalHeader($config);
            cue_renderGlobalHamburgerMenu($config);
        }
        
        // Render footer if not header-only mode
        if (empty($config['header_only']) && empty($config['scripts_only'])) {
            echo "\n<!-- CUE Global UI Footer -->\n";
            cue_renderGlobalFooter($config);
        }
        
        // Render scripts if requested
        if ($includeScripts && empty($config['header_only']) && empty($config['footer_only'])) {
            echo "\n<!-- CUE Global UI Scripts -->\n";
            cue_includeGlobalUIScripts();
        }
        
    } catch (Exception $e) {
        if (!defined('CUE_CLI_MODE')) {
            echo "<!-- CUE Global UI Fallback Error: " . htmlspecialchars($e->getMessage()) . " -->\n";
            error_log("CUE Global UI fallback failed: " . $e->getMessage());
        }
    }
}

/**
 * Simple helper to disable auto-injection and use manual fallback
 * Call this early in your page (before any output) to disable auto-injection
 */
function cue_disableAutoInjection(): void {
    define('CUE_DISABLE_AUTO_UI', true);
    $GLOBALS['_CUE_GLOBAL_UI_REGISTERED'] = true; // Prevent auto-injection registration
}

// -----------------------------------------------------------------------------
// BACKWARD COMPATIBILITY HELPERS
// -----------------------------------------------------------------------------

/**
 * Legacy function to maintain compatibility
 * @deprecated Use cue_autoload('paths')->getDataPath() instead
 */
function getSecureFilePath(string $relativePath, bool $createDir = false): string|false {
    return cue_autoload('paths')->getSecureFilePath($relativePath, $createDir);
}

/**
 * Legacy function to maintain compatibility
 * @deprecated Use cue_autoload('security')->encryptValue() instead
 */
function encryptValue(string $value, ?string $key = null): string {
    if ($key === null) {
        $key = getEncryptionKey();
    }
    return cue_autoload('security')->encryptValue($value, $key);
}

/**
 * Legacy function to maintain compatibility
 * @deprecated Use cue_autoload('security')->decryptValue() instead
 */
function decryptValue(string $encryptedData, ?string $key = null): string {
    if ($key === null) {
        $key = getEncryptionKey();
    }
    return cue_autoload('security')->decryptValue($encryptedData, $key);
}

/**
 * Legacy function to maintain compatibility
 * @deprecated Use cue_autoload('database')->getConnection() instead
 */
function getDatabase(): PDO {
    // Emergency check - throw immediately if database operations disabled
    if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    
    // Check if any active databases exist
    if (function_exists('database_hasActiveConfigurations') && !database_hasActiveConfigurations()) {
        throw new Exception("No active database configurations available - all databases are currently inactive");
    }
    
    return cue_autoload('database')->getConnection();
}

/**
 * Legacy function to maintain compatibility
 * @deprecated Use cue_autoload('database')->getContextAwareConnection() instead
 */
function getContextAwareDatabase(): PDO {
    // Emergency check - throw immediately if database operations disabled
    if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
        throw new Exception("Database operations temporarily disabled for performance");
    }
    
    // Check if any active databases exist
    if (function_exists('database_hasActiveConfigurations') && !database_hasActiveConfigurations()) {
        throw new Exception("No active database configurations available - all databases are currently inactive");
    }
    
    return cue_autoload('database')->getContextAwareConnection();
}

// -----------------------------------------------------------------------------
// BACKWARD COMPATIBILITY FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Get database connection by configuration ID
 * @param string $configId Configuration identifier
 * @return object Database connection wrapper with success/error properties and PDO interface
 */
function getDatabaseById(string $configId): object {
    // Emergency check - return error object if database operations disabled
    if (defined('CUE_DATABASE_EMERGENCY_DISABLED') && CUE_DATABASE_EMERGENCY_DISABLED) {
        return (object) [
            'success' => false,
            'error' => 'Database operations temporarily disabled for performance',
            'pdo' => null
        ];
    }
    
    // Check if any active databases exist
    if (function_exists('database_hasActiveConfigurations') && !database_hasActiveConfigurations()) {
        return (object) [
            'success' => false,
            'error' => 'No active database configurations available - all databases are currently inactive',
            'pdo' => null
        ];
    }
    
    try {
        $pdo = cue_autoload('database')->getConnectionById($configId);

        // Create a wrapper object that has both success/error properties and PDO methods
        return new class($pdo) {
            public mixed $pdo;
            public bool $success = true;
            public ?string $error = null;

            public function __construct(mixed $pdo) {
                $this->pdo = $pdo;
            }

            // Delegate PDO methods to the underlying PDO object
            public function __call(string $method, array $args): mixed {
                return call_user_func_array([$this->pdo, $method], $args);
            }

            // Allow property access to PDO properties
            public function __get(string $property): mixed {
                return $this->pdo->$property;
            }

            // Allow setting PDO properties
            public function __set(string $property, mixed $value): void {
                $this->pdo->$property = $value;
            }
        };
    } catch (Exception $e) {
        // Return error object
        return (object) [
            'success' => false,
            'error' => $e->getMessage(),
            'pdo' => null
        ];
    }
}

/**
 * Get encryption key for data operations
 * @return string Encryption key
 */
function getEncryptionKey(): string {
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    
    $keyFile = getConfigPath() . '/db_key.key';
    if (file_exists($keyFile)) {
        $key = trim(file_get_contents($keyFile));
        // Load security module to validate the key
        cue_autoload('security');
        if (validateEncryptionKey($key)) {
            return $key;
        }
    }
    
    // Fallback to generated key if file not found or invalid
    $key = cue_autoload('security')->generateEncryptionKey();
    return $key;
}

/**
 * Get configuration path
 * @return string Configuration directory path
 */
function getConfigPath(): string {
    return cue_autoload('paths')->getConfigPath();
}

/**
 * Get temp path
 * @return string Temp directory path
 */
function getTempPath(): string {
    return cue_autoload('paths')->getTempPath();
}

/**
 * Get branding path
 * @return string Branding directory path
 */
function getBrandingPath(): string {
    return cue_autoload('paths')->getBrandingPath();
}

/**
 * Validate secure path
 * @param string $path Path to validate
 * @param string $allowedBasePath Allowed base path
 * @return string|false Validated path or false
 */
function validateSecurePath(string $path, string $allowedBasePath): string|false {
    return cue_autoload('paths')->validateSecurePath($path, $allowedBasePath);
}

/**
 * Validate encryption key (wrapper for security module)
 * @param string $key Encryption key to validate
 * @return bool True if key is valid
 */
function validateEncryptionKey(string $key): bool {
    return cue_autoload('security')->validateEncryptionKey($key);
}

/**
 * Generate a secure session token for UI installation or other session-based operations
 * @param string $environment Environment identifier
 * @param string $action Action identifier
 * @return string Generated secure token
 */
function generateSecureSessionToken(string $environment, string $action): string {
    return cue_autoload('security')->generateSecureSessionToken($environment, $action);
}

/**
 * Execute a database query with error handling (backward compatibility)
 * @param PDO|object $connection Database connection
 * @param string $query SQL query
 * @param array $params Query parameters
 * @return array Result with success/data/error keys
 */
function cueExecuteQuery(mixed $connection, string $query, array $params = []): array {
    try {
        // If connection is our wrapper object, get the PDO
        if (is_object($connection) && property_exists($connection, 'pdo') && $connection->pdo instanceof PDO) {
            $pdo = $connection->pdo;
        } elseif ($connection instanceof PDO) {
            $pdo = $connection;
        } else {
            return [
                'success' => false,
                'error' => 'Invalid database connection',
                'data' => []
            ];
        }

        $results = cue_autoload('database')->query($query, $params, $pdo);

        return [
            'success' => true,
            'data' => $results,
            'error' => null
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Get decrypted database configuration
 * @param string $configId Configuration ID
 * @return array|null Decrypted configuration or null if not found
 */
function getDecryptedConfig(string $configId): ?array {
    try {
        $config = cue_autoload('database')->getConfiguration($configId);
        if ($config === null) {
            return null;
        }
        return cue_autoload('database')->decryptConfiguration($config);
    } catch (Exception) {
        return null;
    }
}

/**
 * Enforce page-level permissions for current request
 * @throws Exception If permissions are not granted
 */
function enforcePagePermissions(): void {
    // For now, just check if we can get a database connection
    // More sophisticated permission checking can be added later
    try {
        $db = cue_autoload('database')->getContextAwareConnection();
        if (!$db) {
            // Don't throw an exception for now, just log it
            error_log('Page permission check: No database connection available');
            return;
        }
    } catch (Exception $e) {
        // Log the error but don't fail the request
        error_log('Page permission check failed: ' . $e->getMessage());
        return;
    }
}

/**
 * Start a secure session with framework security settings
 */
function startSecureSession(): void {
    cue_autoload('security')->startSecureSession();
}

/**
 * Get current environment identifier
 * @return string Environment name
 */
function getEnvironment(): string {
    return getenv('CUE_ENVIRONMENT') ?: 'production';
}

/**
 * Generate a CSRF token for form protection
 * @param string $action Action identifier
 * @return string CSRF token
 */
function generateCSRFToken(string $action = 'default'): string {
    return cue_autoload('security')->generateCSRFToken($action);
}

/**
 * Get the base URL of the application
 * @return string Base URL
 */
function getBaseUrl(): string {
    // Check if we're in CLI mode
    if (php_sapi_name() === 'cli') {
        return 'https://metahumans.one';
    }
    
    // Detect environment based on multiple factors
    $isLocalhost = false;
    
    // Get host from SERVER variables
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'metahumans.one';
    
    // Check for localhost indicators
    if (
        // Direct localhost checks
        $host === 'localhost' || 
        $host === '127.0.0.1' ||
        $host === '::1' ||
        // Local development patterns
        strpos($host, 'localhost:') === 0 ||
        strpos($host, '127.0.0.1:') === 0 ||
        // XAMPP/Local development
        strpos($host, '.local') !== false ||
        strpos($host, '.test') !== false ||
        strpos($host, '.dev') !== false ||
        // Private IP ranges
        preg_match('/^192\.168\./', $host) ||
        preg_match('/^10\./', $host) ||
        preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) ||
        // Check for development environment variables
        (isset($_SERVER['ENVIRONMENT']) && $_SERVER['ENVIRONMENT'] === 'development') ||
        (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'local')
    ) {
        $isLocalhost = true;
    }
    
    // Determine protocol
    if (!$isLocalhost) {
        // Production server - prefer HTTPS
        $protocol = 'https';
    } else {
        // Localhost - check actual HTTPS status
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    }
    
    // Override host for production if needed
    if (!$isLocalhost && ($host === 'localhost' || $host === '127.0.0.1')) {
        $host = 'metahumans.one';
    }
    
    // Get port
    $port = $_SERVER['SERVER_PORT'] ?? ($protocol === 'https' ? 443 : 80);
    
    $baseUrl = $protocol . '://' . $host;
    
    // Add port if it's not the default
    if (($protocol === 'https' && $port != 443) || ($protocol === 'http' && $port != 80)) {
        $baseUrl .= ':' . $port;
    }
    
    return $baseUrl;
}

/**
 * Get template URL for assets
 * @param string $path Relative path within templates
 * @return string Full URL to template asset
 */
function getTemplateURL(string $path = ''): string {
    $templatesPath = getTemplatesPath();
    
    // Convert file path to URL path
    $relativePath = str_replace(getPublicPath(), '', $templatesPath);
    $relativePath = ltrim($relativePath, '/');
    
    $rel = '/' . trim($relativePath, '/');
    $suffix = ltrim($path, '/');
    if ($suffix !== '') {
        return $rel . '/' . $suffix;
    }
    return $rel . '/';
}

// -----------------------------------------------------------------------------
// WIDGET MANAGEMENT SYSTEM - CONSISTENCY ENFORCEMENT
// -----------------------------------------------------------------------------

/**
 * Get widget path - ONLY function for accessing widget files
 * Enforces consistency across the entire codebase
 * 
 * @param string $widgetName Widget directory name (autosave, dragdrop, loader, notices, icons, sidebar, animations, status-bar)
 * @param string $file Optional file within widget directory
 * @return string Full path to widget or widget file
 * @throws Exception If widget doesn't exist
 */
function getWidgetPath(string $widgetName, string $file = ''): string {
    $validWidgets = ['autosave', 'dragdrop', 'loader', 'notices', 'icons', 'sidebar', 'animations', 'status-bar'];
    
    if (!in_array($widgetName, $validWidgets)) {
        throw new Exception("Invalid widget name: {$widgetName}. Valid widgets: " . implode(', ', $validWidgets));
    }
    
    $widgetPath = getTemplatesPath() . '/widgets/' . $widgetName;
    
    if (!is_dir($widgetPath)) {
        throw new Exception("Widget directory not found: {$widgetPath}");
    }
    
    if ($file) {
        $filePath = $widgetPath . '/' . ltrim($file, '/');
        if (!file_exists($filePath)) {
            throw new Exception("Widget file not found: {$filePath}");
        }
        return $filePath;
    }
    
    return $widgetPath;
}

/**
 * Include widget file with security validation
 * ONLY function for including widget files - enforces consistency
 * 
 * @param string $widgetName Widget directory name
 * @param string $file File to include within widget
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget or file doesn't exist
 */
function includeWidget(string $widgetName, string $file, bool $once = true): mixed {
    $filePath = getWidgetPath($widgetName, $file);
    
    // Security validation
    if (strpos(realpath($filePath), realpath(getTemplatesPath() . '/widgets/')) !== 0) {
        throw new Exception("Security violation: Widget path outside allowed directory: {$filePath}");
    }
    
    if ($once) {
        return require_once $filePath;
    } else {
        return require $filePath;
    }
}

/**
 * Get widget URL for web access
 * 
 * @param string $widgetName Widget directory name
 * @param string $file Optional file within widget directory
 * @return string Web URL to widget or widget file
 */
function getWidgetURL(string $widgetName, string $file = ''): string {
    $baseUrl = getBaseUrl();
    $widgetUrl = $baseUrl . '/templates/widgets/' . $widgetName;
    
    if ($file) {
        $widgetUrl .= '/' . ltrim($file, '/');
    }
    
    return $widgetUrl;
}

// =============================================================================
// AUTO-INITIALIZATION FUNCTIONS
// =============================================================================

/**
 * Auto-initialize widgets that should load on every page
 * Called by register_shutdown_function to ensure widgets load after page content
 */
function cue_auto_initialize_widgets(): void {
    // Skip in CLI mode or during reset/start operations
    if (php_sapi_name() === 'cli' || defined('CUE_LAYOUT_MANUAL')) {
        return;
    }
    
    // Skip for theme files that handle their own initialization
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($requestUri) && strpos($requestUri, '/auth/') === 0) {
        return;
    }
    if (strpos($requestUri, 'start.php') !== false || strpos($requestUri, 'reset.php') !== false) {
        return;
    }
    
    // Skip for AJAX requests to avoid contaminating responses
    if (isset($_GET['ajax_header']) || isset($_GET['debug_config']) || isset($_POST['auto_save_header']) || 
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        return;
    }
    
    // Auto-initialize animation widget for enhanced user experience
    try {
        if (function_exists('initializeAnimationWidget')) {
            initializeAnimationWidget();
        }
    } catch (Throwable $e) {
        // Fail silently - animation widget is enhancement, not critical
        error_log("CUE Framework: Animation widget auto-initialization failed: " . $e->getMessage());
    }
}

// =============================================================================
// SPECIFIC WIDGET INCLUSION FUNCTIONS
// =============================================================================

/**
 * Include loader widget with all its assets (CSS, JS, HTML)
 * Provides showLoadingAnimation() and hideLoadingAnimation() functions
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget files don't exist
 */
function includeLoaderWidget(bool $once = true): mixed {
    // Include main widget file (HTML + inline styles/scripts)
    $result = includeWidget('loader', 'loader.php', $once);
    
    // Also include CSS and JS files if they exist
    try {
        $cssPath = getWidgetPath('loader', 'loader.css');
        $jsPath = getWidgetPath('loader', 'loader-simple.js');
        
        // Output CSS link tag
        if (file_exists($cssPath)) {
            echo '<link rel="stylesheet" href="' . getWidgetURL('loader', 'loader.css') . '">' . "\n";
        }
        
        // Output JS script tag
        if (file_exists($jsPath)) {
            echo '<script src="' . getWidgetURL('loader', 'loader-simple.js') . '"></script>' . "\n";
        }
    } catch (Exception $e) {
        // Don't break if CSS/JS files are missing - main widget should still work
        error_log('Warning: Loader widget CSS/JS files not found: ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Include drag and drop widget
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function includeDragDropWidget(bool $once = true): mixed {
    return includeWidget('dragdrop', 'widget.php', $once);
}

/**
 * Include notices widget with all its assets
 * Provides popup notification system
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function includeNoticesWidget(bool $once = true): mixed {
    // Only include CSS and JS assets - do not include the full config page
    try {
        echo '<link rel="stylesheet" href="' . getWidgetURL('notices', 'popup-notice.css') . '">' . "\n";
        echo '<script src="' . getWidgetURL('notices', 'popup-notice.js') . '"></script>' . "\n";
        
        // Load configuration from both possible locations
        $configPaths = [
            getDataPath() . '/widgets/notices/widgets-config.json',
            getWidgetPath('notices') . '/widgets-config.json'
        ];
        
        $config = [];
        foreach ($configPaths as $configPath) {
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true) ?: [];
                break;
            }
        }
        
        // Only proceed if configuration was loaded from JSON file
        if (empty($config)) {
            error_log('Warning: Notices widget configuration not found in JSON file');
            return false;
        }
        
        // Map config to PopupNotice options with proper field mapping
        $options = [
            'enabled' => $config['enabled'],
            'theme' => $config['theme'],
            'position' => $config['position'],
            // Handle both duration formats (seconds and milliseconds)
            'duration' => is_numeric($config['duration']) ? 
                ((int)$config['duration'] < 1000 ? (int)$config['duration'] * 1000 : (int)$config['duration']) : 5000,
            // Map both 'stack' and 'stackNotifications' field names
            'stackNotifications' => $config['stackNotifications'] ?? $config['stack'] ?? true,
            'maxStack' => $config['maxStack'] ?? 5,
            'enableAnimation' => $config['enableAnimation'] ?? true
        ];
        
        // Initialize popupNotice instance with config
        $constructorOptions = $options;
        unset($constructorOptions['theme']); // Remove theme from constructor options
        unset($constructorOptions['enabled']); // Remove enabled from constructor options
        
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo 'if (typeof PopupNotice !== "undefined" && !window.popupNotice) {';
        echo 'window.popupNotice = new PopupNotice(' . json_encode($constructorOptions) . ');';
        
        // Apply theme separately after initialization
        if (!empty($options['theme'])) {
            echo 'window.popupNotice.applyTheme("' . addslashes($options['theme']) . '");';
        }
        
        echo '}';
        echo '});';
        echo '</script>' . "\n";
        
        return true;
    } catch (Exception $e) {
        error_log('Warning: Notices widget assets not found: ' . $e->getMessage());
        return false;
    }
}

/**
 * Include icons widget
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function includeIconsWidget(bool $once = true): mixed {
    $result = includeWidget('icons', 'icon-widget.php', $once);
    if (function_exists('includeIconWidget')) {
        call_user_func('includeIconWidget', []);
    }
    return $result;
}

/**
 * Include sidebar widget
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function includeSidebarWidget(bool $once = true): mixed {
    return includeWidget('sidebar', 'sidebar.php', $once);
}

/**
 * Include and initialize autosave widget
 * 
 * @param array $options Options for autosave (interval, eventName, etc)
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function initializeAutosaveWidget(array $options = [], bool $once = true): mixed {
    // Include the widget file which defines includeAutosaveWidget()
    $result = includeWidget('autosave', 'autosave.php', $once);
    
    // Call the widget's own initialization function if it exists
    if (function_exists('includeAutosaveWidget')) {
        includeAutosaveWidget($options);
    }
    
    return $result;
}

/**
 * Include and initialize animation widget
 * 
 * @param array $options Options for animation widget (vanta_enabled, css_animations_enabled, etc)
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function initializeAnimationWidget(array $options = [], bool $once = true): mixed {
    // Include the widget file which defines includeAnimationWidget()
    $result = includeWidget('animations', 'animation.php', $once);
    
    // Call the widget's own initialization function if it exists
    if (function_exists('initializeAnimationWidgetContent')) {
        initializeAnimationWidgetContent($options);
    }
    
    return $result;
}

/**
 * Include animation widget (legacy compatibility)
 * 
 * @param array $options Options for animation widget
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 * @throws Exception If widget doesn't exist
 */
function includeAnimationWidget(array $options = [], bool $once = true): mixed {
    return initializeAnimationWidget($options, $once);
}

/**
 * Include status bar widget
 * 
 * @param bool $once Use require_once instead of require
 * @return mixed Result of file inclusion
 */
function includeStatusBarWidget(bool $once = true): mixed {
    // Include main widget file
    $result = includeWidget('status-bar', 'status-bar.php', $once);
    
    // Also include CSS file if it exists
    try {
        $cssPath = getWidgetPath('status-bar', 'status-bar.css');
        
        // Output CSS link tag
        if (file_exists($cssPath)) {
            echo '<link rel="stylesheet" href="' . getWidgetURL('status-bar', 'status-bar.css') . '">' . "\n";
        }
    } catch (Exception) {}
    
    return $result;
}

/**
 * List all available widgets
 * 
 * @return array Array of widget names
 */
function getAvailableWidgets(): array {
    $widgetPath = getTemplatesPath() . '/widgets';
    $widgets = [];
    
    if (is_dir($widgetPath)) {
        $directories = scandir($widgetPath);
        foreach ($directories as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($widgetPath . '/' . $dir)) {
                $widgets[] = $dir;
            }
        }
    }
    
    return $widgets;
}

// -----------------------------------------------------------------------------
// PERFORMANCE MONITORING
// -----------------------------------------------------------------------------

/**
 * Log CUE performance metrics for monitoring
 */
function logCuePerformance(): void {
    // Skip performance logging if disabled (e.g., for API endpoints)
    if (defined('CUE_DISABLE_PERFORMANCE_LOG') && constant('CUE_DISABLE_PERFORMANCE_LOG')) {
        return;
    }
    
    $loadTime = microtime(true) - CUE_LOAD_TIME;
    $memory = memory_get_usage(true);
    $peakMemory = memory_get_peak_usage(true);

    $metrics = [
        'load_time' => round($loadTime, 4),
        'memory_current' => $memory,
        'memory_peak' => $peakMemory,
        'modules_loaded' => count(get_loaded_extensions()),
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
    ];

    cue_debug_log("CUE Performance: " . json_encode($metrics));
}

// Register performance monitoring
register_shutdown_function('logCuePerformance');

// -----------------------------------------------------------------------------
// GLOBAL LAYOUT AUTO-INCLUSION SYSTEM
// -----------------------------------------------------------------------------

/**
 * Include global layout system
 * Auto-includes header/footer from theme directory unless disabled
 */
function cue_includeGlobalLayout(): void {
    // Skip if explicitly disabled (for installer, standalone pages, etc.)
    if (defined('CUE_DISABLE_AUTO_LAYOUT') && CUE_DISABLE_AUTO_LAYOUT) {
        return;
    }
    
    // Skip during installation or reset phases
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, 'start.php') !== false || strpos($requestUri, 'reset.php') !== false) {
        return;
    }
    
    // Include global layout from theme directory
    $layoutPath = getTemplatesPath() . '/theme/layout.inc.php';
    if (file_exists($layoutPath)) {
        include_once $layoutPath;
    }
}

// Auto-initialize global layout system unless in CLI mode
if (php_sapi_name() !== 'cli' && !defined('CUE_LAYOUT_MANUAL')) {
    // Use shutdown function to ensure layout loads after page content
    register_shutdown_function('cue_includeGlobalLayout');
}

// -----------------------------------------------------------------------------
// GLOBAL TEMPLATE SYSTEM
// -----------------------------------------------------------------------------

/**
 * Include global header template with consistent styling
 */
function includeGlobalHeader(): void {
    $headerFile = dirname(__DIR__) . '/templates/global-ui/header.php';
    if (file_exists($headerFile)) {
        include $headerFile;
    }
}

/**
 * Include global footer template with consistent styling
 */
function includeGlobalFooter(): void {
    $footerFile = dirname(__DIR__) . '/templates/global-ui/footer.php';
    if (file_exists($footerFile)) {
        include $footerFile;
    }
}

/**
 * Include global hamburger menu with consistent styling
 */
function includeGlobalHamburger(): void {
    $hamburgerFile = dirname(__DIR__) . '/templates/global-ui/hamburger.php';
    if (file_exists($hamburgerFile)) {
        include $hamburgerFile;
    }
}

/**
 * Get global template content without outputting
 */
function getGlobalTemplate(mixed $template): string {
    $template = is_string($template) ? $template : (string)$template;
    ob_start();
    switch($template) {
        case 'header':
            includeGlobalHeader();
            break;
        case 'footer':
            includeGlobalFooter();
            break;
        case 'hamburger':
            includeGlobalHamburger();
            break;
    }
    return ob_get_clean();
}

/**
 * Include complete global layout wrapper
 */
function includeGlobalLayoutWrapper(string $content = ''): void {
    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>CUE Framework</title>';
    echo '</head><body style="margin: 0; padding: 0; font-family: Arial, sans-serif;">';
    
    includeGlobalHeader();
    
    if ($content) {
        echo '<main>' . $content . '</main>';
    }
    
    includeGlobalFooter();
    // Note: Hamburger menu should be included explicitly where needed
    // includeGlobalHamburger(); // Disabled to prevent auto-loading
    
    echo '</body></html>';
}

// -----------------------------------------------------------------------------
// INITIALIZE GLOBAL UI COMPONENTS
// -----------------------------------------------------------------------------
loadGlobalUIComponents();

?>
