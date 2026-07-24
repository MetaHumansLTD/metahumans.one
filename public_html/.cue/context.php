<?php
/**
 * CUE Framework Context Module - CUE PRO
 *
 * This module provides the Context Understanding Engine (CUE) functionality.
 * It gathers environment, project, and user context to enhance AI interactions.
 *
 * @package    CUE Framework
 * @version    1.0.0
 */

// -----------------------------------------------------------------------------
// CONTEXT GENERATION FUNCTIONS
// -----------------------------------------------------------------------------

/**
 * Get the full CUE PRO context string for injection into LLM prompts.
 *
 * @return string The formatted context string.
 */
function context_generateFullContext(): string {
    $systemContext = context_getSystemContext();
    $projectContext = context_getProjectContext();
    $userContext = context_getUserContext();

    $context = "[CUE PRO CONTEXT ENABLED]\n";
    $context .= "--------------------------------------------------\n";
    $context .= "SYSTEM CONTEXT:\n" . $systemContext . "\n";
    $context .= "--------------------------------------------------\n";
    $context .= "PROJECT CONTEXT:\n" . $projectContext . "\n";
    $context .= "--------------------------------------------------\n";
    if (!empty($userContext)) {
        $context .= "USER CONTEXT:\n" . $userContext . "\n";
        $context .= "--------------------------------------------------\n";
    }
    
    return $context;
}

/**
 * Get system/environment context.
 *
 * @return string
 */
function context_getSystemContext(): string {
    $os = php_uname('s') . ' ' . php_uname('r');
    $phpVersion = PHP_VERSION;
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $rootPath = defined('ROOT_PATH') ? ROOT_PATH : 'Undefined';
    
    return "- Operating System: $os
- PHP Version: $phpVersion
- Web Server: $serverSoftware
- Root Path: $rootPath
- Framework: CUE Framework (Modular)
- Editor: Monaco Editor (VS Code compatible)";
}

/**
 * Get project-specific context.
 * This could be enhanced to read from a project config file in the future.
 *
 * @return string
 */
function context_getProjectContext(): string {
    // In a real implementation, this might read from .cue/project.json or similar
    // For now, we return the standard Trae-like environment context
    return "- Project Type: Web Application / Codespace
- Tech Stack: PHP 8.2+, HTML5, CSS3 (Tailwind compatible), JavaScript (ES6+)
- Frontend Libraries: Lucide Icons, Monaco Editor
- Architecture: Modular PHP (CUE Framework), Server-Client Split (GPU Server: meta.superhumans.one, Client: metahumans.one)
- Coding Standards: Clean, modular, well-documented, WCAG AA accessible";
}

/**
 * Get user-specific context.
 *
 * @return string
 */
function context_getUserContext(): string {
    // This could retrieve user preferences from a database or session
    $context = "- Role: Developer / Architect
- Intent: Create high-quality, production-ready code
- Mode: Build Mode (Production Ready)";
    
    return $context;
}
