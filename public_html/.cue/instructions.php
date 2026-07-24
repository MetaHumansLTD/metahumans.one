<?php
/**
 * CUE Framework Instructions Module
 * 
 * THE ULTIMATE INSTRUCTION GUIDE & DYNAMIC CONTEXT ENGINE
 * 
 * This module is the SINGLE SOURCE OF TRUTH for:
 * 1. Framework Architecture & Standards (formerly codebase.instructions.md)
 * 2. Dynamic Project State (Real-time scanning)
 * 3. AI Persona & Prompt Generation
 * 
 * @package    CUE Framework
 * @version    100.1.00
 */

if (!defined('CUE_CORE_LOADED')) {
    require_once __DIR__ . '/cue.php';
}

class cue_instructions {
    
    private $instructionsPath;
    private $dynamicContext = [];

    public function __construct() {
        $dataPath = null;
        if (function_exists('paths_getDataPath')) {
            $dataPath = paths_getDataPath();
        }
        if (!is_string($dataPath) || trim($dataPath) === '') {
            $dataPath = '/data';
        }
        $this->instructionsPath = rtrim((string)$dataPath, '/') . '/instructions';
        $this->scanProjectState();
    }

    /**
     * DYNAMICALLY SCAN PROJECT STATE
     * This replaces static placeholders with real-time data.
     */
    private function scanProjectState() {
        // 1. Framework Version
        $this->dynamicContext['framework_version'] = defined('CUE_VERSION') ? CUE_VERSION : 'Unknown';
        
        // 2. Database Schema (Live Scan)
        $this->dynamicContext['database_schema'] = $this->scanDatabaseSchema();
        
        // 3. Directory Structure (Key Paths)
        $this->dynamicContext['paths'] = [
            'root' => ROOT_PATH,
            'cue' => ROOT_PATH . '/public_html/.cue',
            'templates' => ROOT_PATH . '/public_html/templates',
            'studio' => ROOT_PATH . '/public_html/studio'
        ];
        
        // 4. Active Modules
        $this->dynamicContext['active_modules'] = $this->scanActiveModules();
    }

    private function scanDatabaseSchema() {
        // Lightweight schema scan - avoids heavy overhead
        // In production, this might be cached
        try {
            if (function_exists('cue_autoload')) {
                $db = cue_autoload('database')->getConnection();
                $tables = [];
                $stmt = $db->query("SHOW TABLES");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tables[] = $row[0];
                }
                return $tables;
            }
        } catch (Exception $e) {
            return ['error' => 'Database scan failed: ' . $e->getMessage()];
        }
        return ['status' => 'Database module not loaded'];
    }

    private function scanActiveModules() {
        $modules = [];
        $files = glob(__DIR__ . '/*.php');
        foreach ($files as $file) {
            $modules[] = basename($file, '.php');
        }
        return $modules;
    }

    /**
     * GET THE ULTIMATE INSTRUCTION SET
     * Merges static architectural rules with dynamic live context.
     */
    public function getUltimateInstructions() {
        $staticRules = <<<'EOT'
# CUE Framework 100.1.00 Modular Implementation Requirements

⚠️ **CRITICAL: This codebase uses the NEW MODULAR CUE Framework architecture.**
The file `.cue/core.php` is the SINGLE SOURCE OF TRUTH for the core version.

## 1. Modular Framework Initialization
- **Framework Loading:** `require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';`
- **Database Access:** `cue_autoload('database')->getConnection();`
- **Context-Aware DB:** `cue_autoload('database')->getContextAwareConnection();`

## 2. AI & GPU Infrastructure (MANDATORY)
- **Primary GPU Host:** `superhumans.one` `superbrains.one` (Remote GPU Server)
- **Local Hermes/Ollama (optional):** `http://127.0.0.1:11434` (Ollama HTTP)
- **Config Source:** `public_html/ai/hermes.json` controls the chat base_url/model used by internal agents.
- **Rule:** Always call the configured gateway/base_url (never hardcode model endpoints). Local Ollama is allowed when configured.

## 3. Modular Path Management
- **Data:** `paths_getDataPath()` -> `/data`
- **MySQL:** `paths_getMysqlPath()` -> `/mysql` (Block Storage)
- **Vector:** `paths_getVectorPath()` -> `/vector` (Block Storage)
- **Graph:** `paths_getGraphPath()` -> `/graph` (Block Storage)

## 4. Codespaces Integration
- **Role:** "Hands/Tools" layer.
- **LLMStack:** "Brains/Orchestrator" layer.
- **Rule:** LLMStack calls high-level APIs, not raw PTY.

## 5. Database Ports & Roles
- **MariaDB Primary (3306):** Legacy/LDAP data (`onemeta_ldap`, `autobill`). NOT authoritative for runtime user authentication or tenant data.
- **MariaDB Secondary (3307):** **AUTHORITATIVE SOURCE.**
  - `biometrics`: Central Authentication, Permissions, Token Balance.
  - `tenant_user_*`: Tenant-specific user data (including encrypted PINs).
- **Qdrant (6333/6334):** Vector Memory.
- **Neo4j (7474/7687):** Graph Knowledge Base.

## 6. Authentication Architecture
- **Source of Truth:** `biometrics` database (Port 3307).
- **PIN Verification:**
  - Tenant DB (`tenant_user_*`): Stores **Encrypted** PIN (`security_encryptValue`).
  - Biometrics DB (`biometrics`): Stores **Hashed** PIN (`password_hash`).
  - **Login Logic:** Authenticates against `biometrics.users.pin_hash`.
  - **Recovery:** Decrypt Tenant PIN -> Hash -> Update Biometrics.

## 6.1 Biometrics Boundary (Strict)
- **Rule:** `/hub/*` and `/studio/*` must never open biometrics connections.
- **Enforcement:** DB router blocks `database_getConnectionById('biometrics')` in tenant-scoped requests (no allowlist).
- **Pattern:** Use `database_getContextAwareConnection()` for tenant DB access and session-derived auth fields (`mh_auth_user`, `mh_auth_role`, `mh_auth_permissions`, `mh_device_id`).

## 7. Always-on Memory (“Sleep Cycle”)
- **Durable ingest:** `/data/tenants/<tenant>/memory/inbox/...` is the source-of-truth event queue.
- **Daemon:** `public_html/hub/memory/daemon.php` runs ingest continuously and consolidation on an interval.
- **Deep consolidation:** Allowed under strict budgets when user is idle for > 60 minutes and at most once per scope per 24h.

## 8. GraphRAG Ingestion
- **Daemon:** `public_html/hub/graph/daemon.php ingest --max=500`
- **Scheduling:** Run via system cron or the Cron Block runner (`gear/sync/index.php --cron-runner`).
EOT;

        $dynamicState = "\n\n# LIVE PROJECT STATE (Dynamic)\n";
        $dynamicState .= "- **Framework Version:** " . $this->dynamicContext['framework_version'] . "\n";
        $dynamicState .= "- **Active Modules:** " . implode(', ', $this->dynamicContext['active_modules']) . "\n";
        
        if (is_array($this->dynamicContext['database_schema'])) {
            $dynamicState .= "- **Database Tables:** " . implode(', ', array_slice($this->dynamicContext['database_schema'], 0, 20)) . (count($this->dynamicContext['database_schema']) > 20 ? '...' : '') . "\n";
        } else {
            $dynamicState .= "- **Database Status:** " . json_encode($this->dynamicContext['database_schema']) . "\n";
        }

        return $staticRules . $dynamicState;
    }

    /**
     * Loads a specific persona/instruction set by name.
     */
    public function load($name) {
        // Fallback to standard loading if specific file requested
        $path = $this->instructionsPath . '/' . $name . '.md';
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        // Default to the Ultimate Instructions if not found
        return $this->getUltimateInstructions();
    }

    /**
     * Builds a structured prompt using the Microsoft Prompt Engine pattern.
     */
    public function buildEnginePrompt($persona, $context, $task) {
        $systemText = $this->getUltimateInstructions(); // ALWAYS include ultimate instructions
        
        $prompt = "### SYSTEM ARCHITECTURE & LIVE CONTEXT\n" . $systemText . "\n\n";
        
        if (!empty($context)) {
            $prompt .= "### REQUEST CONTEXT\n";
            foreach ($context as $key => $value) {
                $prompt .= "- **" . $key . "**: " . $value . "\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "### TASK\n" . $task . "\n\n";
        $prompt .= "### OUTPUT FORMAT\nPlease provide the response in a structured format, using Markdown code blocks where appropriate.";
        
        return $prompt;
    }
}

if (!isset($GLOBALS['_CUE_INSTRUCTIONS_INSTANCE']) || !($GLOBALS['_CUE_INSTRUCTIONS_INSTANCE'] instanceof cue_instructions)) {
    $GLOBALS['_CUE_INSTRUCTIONS_INSTANCE'] = new cue_instructions();
}

// Microsoft Prompt Engine Implementation for CUE Framework
// Based on https://github.com/microsoft/prompt-engine

class PromptEngine {
    protected $contextManager;
    protected $chatEngine;
    protected $codeEngine;

    public function __construct() {
        $this->contextManager = new ContextManager();
        $this->chatEngine = new ChatEngine($this->contextManager);
        $this->codeEngine = new CodeEngine($this->contextManager);
    }

    public function getChatEngine() {
        return $this->chatEngine;
    }

    public function getCodeEngine() {
        return $this->codeEngine;
    }

    public function getContextManager() {
        return $this->contextManager;
    }
}

class ContextManager {
    protected $context = [];
    protected $projectContext = [];

    public function __construct() {
        $this->loadProjectContext();
    }

    protected function loadProjectContext() {
        // DYNAMIC CONTEXT LOADER
        $this->projectContext = [
            'framework' => defined('CUE_VERSION') ? 'CUE ' . CUE_VERSION : 'CUE Framework',
            'architecture' => 'Modular, Context-Aware',
            'database' => 'MariaDB (Block Storage Ports 3306/3307)',
            'ai_server' => defined('CUE_GPU_SERVER') ? CUE_GPU_SERVER : 'https://promptengine.one',
            'paths' => [
                'root' => ROOT_PATH,
                'gear' => ROOT_PATH . '/public_html/gear',
                'cue' => ROOT_PATH . '/public_html/.cue'
            ]
        ];
        
        // Attempt to inject live DB context if available
        try {
            if (function_exists('cue_autoload')) {
                // Just check connection status
                $this->projectContext['db_status'] = 'Active';
            }
        } catch (Exception $e) {
            $this->projectContext['db_status'] = 'Offline: ' . $e->getMessage();
        }
    }

    public function addContext($key, $value) {
        $this->context[$key] = $value;
    }

    public function getContext() {
        return array_merge($this->projectContext, $this->context);
    }
    
    public function getProjectContext() {
        return $this->projectContext;
    }
}

class ChatEngine {
    protected $contextManager;
    protected $messages = [];

    public function __construct(ContextManager $contextManager) {
        $this->contextManager = $contextManager;
    }

    public function buildPrompt($userInput) {
        $context = $this->contextManager->getContext();
        
        // Get the dynamic instructions instance
        $instructions = new cue_instructions();
        $ultimateGuide = $instructions->getUltimateInstructions();
        
        $systemPrompt = "You are an AI assistant in the Meta Humans Studio environment.\n";
        $systemPrompt .= "Here is the ULTIMATE INSTRUCTION GUIDE for this framework:\n\n";
        $systemPrompt .= $ultimateGuide . "\n\n";
        $systemPrompt .= "Request Context:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        
        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userInput]
        ];
    }
}

class CodeEngine {
    protected $contextManager;

    public function __construct(ContextManager $contextManager) {
        $this->contextManager = $contextManager;
    }

    public function optimizeCode($code) {
        return $code; 
    }
}

// Global helper to access the engine
function cue_prompt_engine() {
    static $engine = null;
    if ($engine === null) {
        $engine = new PromptEngine();
    }
    return $engine;
}

// Helper function expected by run.php
if (!function_exists('instructions_getSystemPrompt')) {
    function instructions_getSystemPrompt($intent, $context = []) {
        // Use the new dynamic system
        $instructions = new cue_instructions();
        return $instructions->buildEnginePrompt('system', $context, "Intent: " . ucfirst($intent));
    }
}

return $GLOBALS['_CUE_INSTRUCTIONS_INSTANCE'];
