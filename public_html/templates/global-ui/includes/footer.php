<?php
/**
 * Global Footer Include
 * Include this file in any page to add the global footer
 * 
 * Usage: include_once getTemplatesPath() . '/global-ui/includes/footer.php';
 */

require_once dirname(__DIR__) . '/functions.php';

// Render the footer with default configuration
renderGlobalFooter();
?>