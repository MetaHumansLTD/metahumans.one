<?php
/**
 * Complete Global UI Include
 * Include this file to add all global UI components (header, hamburger menu, footer, styles, scripts)
 * 
 * Usage: 
 * // In <head> section:
 * include_once getTemplatesPath() . '/global-ui/includes/complete-head.php';
 * 
 * // At start of <body>:
 * include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php';
 * 
 * // Before closing </body>:
 * include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php';
 */

require_once dirname(__DIR__) . '/functions.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// Include the global UI styles and theme
includeGlobalUIStyles();
renderGlobalTheme();

echo '<style>html,body{margin:0 !important;padding:0 !important}</style>';

if (isset($_SERVER['REQUEST_URI']) && strpos((string)$_SERVER['REQUEST_URI'], '/templates/global-ui/') === 0) {
    echo '<script>window.SKIP_VANTA_ANIMATIONS=true;</script>';
}

// Ensure triangle_logo is defined for the favicon
if (!isset($triangle_logo)) {
    $triangle_logo = '/templates/assets/images/branding/triangle/logo-triangle-1000.png';
}
?>
<!-- Global Favicons -->
<link rel="icon" type="image/png" href="<?php echo htmlspecialchars($triangle_logo); ?>">
<link rel="apple-touch-icon" sizes="57x57" href="/apple-icon-57x57.png">
<link rel="apple-touch-icon" sizes="60x60" href="/apple-icon-60x60.png">
<link rel="apple-touch-icon" sizes="72x72" href="/apple-icon-72x72.png">
<link rel="apple-touch-icon" sizes="76x76" href="/apple-icon-76x76.png">
<link rel="apple-touch-icon" sizes="114x114" href="/apple-icon-114x114.png">
<link rel="apple-touch-icon" sizes="120x120" href="/apple-icon-120x120.png">
<link rel="apple-touch-icon" sizes="144x144" href="/apple-icon-144x144.png">
<link rel="apple-touch-icon" sizes="152x152" href="/apple-icon-152x152.png">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-icon-180x180.png">
<link rel="icon" type="image/png" sizes="192x192"  href="/android-icon-192x192.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/manifest.json">
<meta name="msapplication-TileColor" content="#ffffff">
<meta name="msapplication-TileImage" content="/ms-icon-144x144.png">
<meta name="theme-color" content="#ffffff">
