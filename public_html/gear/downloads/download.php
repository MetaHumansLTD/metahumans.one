<?php
/**
 * Meta Humans LTD - Document Download Handler
 * Secure file download system for announcement attachments
 * 
 * @package    Meta Humans
 * @author     Meta Humans LTD (Pieter Rubeus - owner)
 * @copyright  Copyright (c) Meta Humans LTD® 2025
 * @license    Licensed
 * @link       https://metahumans.one
 */

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/downloads.php';

// Get file parameter
$fileId = filter_input(INPUT_GET, 'file', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!$fileId) {
    http_response_code(400);
    exit('Invalid file parameter');
}

// Define allowed attachments (in production, this would come from database)
$allowedAttachments = [
    'auth_guide.pdf' => [
        'name' => 'Authentication Implementation Guide.pdf',
        'path' => '../.data/documents/auth_guide.pdf',
        'type' => 'application/pdf',
        'size' => 1024000, // 1MB example
        'public' => true // Public access allowed
    ],
    'nav_specs.md' => [
        'name' => 'Navigation System Specifications.md',
        'path' => '../.data/documents/nav_specs.md',
        'type' => 'text/markdown',
        'size' => 512000, // 512KB example
        'public' => true
    ],
    'ui_framework.doc' => [
        'name' => 'UI Framework Documentation.doc',
        'path' => '../.data/documents/ui_framework.doc',
        'type' => 'application/msword',
        'size' => 2048000, // 2MB example
        'public' => true
    ]
];

// Check if file exists in allowed list
if (!isset($allowedAttachments[$fileId])) {
    http_response_code(404);
    exit('File not found');
}

$attachment = $allowedAttachments[$fileId];

if (!function_exists('getDataPath')) {
    http_response_code(500);
    exit('Missing CUE paths');
}

$dataPath = rtrim(getDataPath(), '/');
$docsBase = $dataPath . '/documents';
$safeFileId = basename((string)$fileId);
$fullPath = $docsBase . '/' . $safeFileId;

if (!is_readable($fullPath)) {
    http_response_code(404);
    exit('File not accessible');
}

mh_download_send_file($fullPath, (string)$attachment['name'], (string)$attachment['type']);

/**
 * Generate sample content for demonstration purposes
 */
function generateSampleContent($fileId, $attachment) {
    switch ($fileId) {
        case 'auth_guide.pdf':
            return generatePDFContent('Authentication Implementation Guide', [
                'Overview',
                'This document provides comprehensive guidelines for implementing secure authentication systems within the Meta Humans platform.',
                '',
                'Key Security Features:',
                '• Multi-factor authentication (MFA) support',
                '• Session management with automatic timeout',
                '• CSRF protection on all forms',
                '• SQL injection prevention',
                '• XSS protection with proper output encoding',
                '',
                'Implementation Steps:',
                '1. Configure cue.php security settings',
                '2. Implement session management',
                '3. Add CSRF token generation and validation',
                '4. Set up input sanitization',
                '5. Configure secure headers',
                '',
                'Best Practices:',
                '• Always use HTTPS in production',
                '• Implement proper password policies',
                '• Regular security audits',
                '• Keep dependencies updated',
                '',
                'For more information, contact: security@metahumans.ltd'
            ]);
            
        case 'nav_specs.md':
            return "# Navigation System Specifications\n\n## Overview\n\nThe Meta Humans navigation system provides a comprehensive, responsive menu solution with role-based access control.\n\n## Features\n\n### Core Functionality\n- Dynamic menu generation based on user roles\n- Responsive hamburger menu for mobile devices\n- Real-time permission checking\n- Smooth animations and transitions\n- Accessibility compliance (WCAG 2.1)\n\n### Technical Specifications\n\n#### Menu Structure\n```php\nclass MenuNavigator {\n    private \$realmsFile;\n    private \$menusFile;\n    private \$permissionsFile;\n    \n    public function generateMenu(\$realm) {\n        // Dynamic menu generation logic\n    }\n}\n```\n\n#### Supported Realms\n- Guest (public access)\n- Employee (authenticated users)\n- Admin (administrative access)\n- System (internal operations)\n\n### Integration Guidelines\n\n1. Include navigator.php in your page header\n2. Set the appropriate realm variable\n3. Call the menu generation function\n4. Apply custom styling as needed\n\n### Customization Options\n\n- Menu item icons and labels\n- Color schemes and themes\n- Animation speeds and effects\n- Responsive breakpoints\n\n## Support\n\nFor technical support, contact: dev@metahumans.ltd";
            
        case 'ui_framework.doc':
            return generateDOCContent('UI Framework Documentation', [
                'Meta Humans UI Framework v2.0',
                '',
                'INTRODUCTION',
                'The Meta Humans UI Framework provides a comprehensive solution for building modern, responsive web interfaces that maintain consistency across all platform components.',
                '',
                'CORE COMPONENTS',
                '',
                '1. Layout System',
                '   - CSS Grid-based responsive layouts',
                '   - Flexbox utilities for component alignment',
                '   - Mobile-first responsive design approach',
                '',
                '2. Typography',
                '   - Rajdhani font family for headings',
                '   - Optimized font loading and rendering',
                '   - Responsive font scaling with clamp()',
                '',
                '3. Color System',
                '   - Primary: #00d4ff (Cyan)',
                '   - Secondary: #7c3aed (Purple)',
                '   - Accent: #f59e0b (Orange)',
                '   - Dark backgrounds with proper contrast ratios',
                '',
                '4. Interactive Elements',
                '   - Touch-friendly button sizes (44px minimum)',
                '   - Smooth hover and focus transitions',
                '   - Accessibility-compliant focus indicators',
                '',
                '5. Animation System',
                '   - CSS custom properties for consistent timing',
                '   - Reduced motion support for accessibility',
                '   - Performance-optimized transforms',
                '',
                'IMPLEMENTATION GUIDELINES',
                '',
                'CSS Variables Usage:',
                '- Use --primary-color for brand elements',
                '- Apply --spacing-* for consistent margins/padding',
                '- Utilize --radius-* for border radius consistency',
                '',
                'Responsive Design:',
                '- Mobile: 320px - 767px',
                '- Tablet: 768px - 1023px',
                '- Desktop: 1024px+',
                '',
                'Accessibility Requirements:',
                '- Minimum 4.5:1 contrast ratio for text',
                '- Keyboard navigation support',
                '- Screen reader compatibility',
                '- Focus management for dynamic content',
                '',
                'BROWSER SUPPORT',
                '- Chrome 90+',
                '- Firefox 88+',
                '- Safari 14+',
                '- Edge 90+',
                '',
                'For questions or contributions, contact: ui@metahumans.ltd'
            ]);
            
        default:
            return 'Sample document content for ' . $attachment['name'];
    }
}

/**
 * Generate simple PDF-like content (plain text for demo)
 */
function generatePDFContent($title, $content) {
    $pdf = "%PDF-1.4\n";
    $pdf .= "1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n";
    $pdf .= "2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n";
    $pdf .= "3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\n";
    $pdf .= "xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000074 00000 n \n0000000120 00000 n \n";
    $pdf .= "trailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n181\n%%EOF";
    
    // For demo, return plain text version
    return $title . "\n" . str_repeat('=', strlen($title)) . "\n\n" . implode("\n", $content);
}

/**
 * Generate simple DOC-like content (plain text for demo)
 */
function generateDOCContent($title, $content) {
    // For demo, return formatted plain text
    return $title . "\n" . str_repeat('=', strlen($title)) . "\n\n" . implode("\n", $content);
}

if (function_exists('error_log')) {
    error_log("File download: {$safeFileId} at " . date('Y-m-d H:i:s'));
}
?>
