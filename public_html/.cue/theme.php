<?php
/**
 * CUE Framework Theme Module
 *
 * Theme management, asset loading, and UI customization functions.
 * Loaded on-demand to improve performance.
 *
 * @package    CUE Framework
 * @version    75.0.1
 */

// -----------------------------------------------------------------------------
// THEME CONFIGURATION MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Load theme configuration
 * @param string|null $themeName Theme name (optional, uses default if not specified)
 * @return array Theme configuration
 */
function theme_loadConfiguration(?string $themeName = null): array {
    static $configurations = [];

    if ($themeName === null) {
        $themeName = theme_getDefaultTheme();
    }

    if (isset($configurations[$themeName])) {
        return $configurations[$themeName];
    }

    $themeConfigFile = cue_autoload('paths')->getThemesPath() . "/{$themeName}/theme.json";

    if (!file_exists($themeConfigFile)) {
        // Return default theme configuration
        $configurations[$themeName] = theme_getDefaultConfiguration();
        return $configurations[$themeName];
    }

    try {
        $configData = json_decode(file_get_contents($themeConfigFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            cue_autoload('error')->logError('Invalid JSON in theme configuration', [
                'file' => $themeConfigFile,
                'error' => json_last_error_msg()
            ]);
            $configurations[$themeName] = theme_getDefaultConfiguration();
            return $configurations[$themeName];
        }

        // Merge with default configuration
        $configurations[$themeName] = array_merge(theme_getDefaultConfiguration(), $configData);

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to load theme configuration', [
            'theme' => $themeName,
            'file' => $themeConfigFile,
            'error' => $e->getMessage()
        ]);
        $configurations[$themeName] = theme_getDefaultConfiguration();
    }

    return $configurations[$themeName];
}

/**
 * Get default theme name
 * @return string Default theme name
 */
function theme_getDefaultTheme(): string {
    // Check for configured default theme
    $configFile = cue_autoload('paths')->getConfigPath() . '/theme.json';

    if (file_exists($configFile)) {
        try {
            $config = json_decode(file_get_contents($configFile), true);
            if (isset($config['default_theme'])) {
                return $config['default_theme'];
            }
        } catch (Exception $e) {
            // Ignore and use fallback
        }
    }

    return 'default';
}

/**
 * Get default theme configuration
 * @return array Default configuration
 */
function theme_getDefaultConfiguration(): array {
    return [
        'name' => 'Default Theme',
        'version' => '1.0.0',
        'description' => 'Default CUE Framework theme',
        'author' => 'CUE Framework',
        'colors' => [
            'primary' => '#00d4ff',
            'secondary' => '#7c3aed',
            'accent' => '#f59e0b',
            'dark-bg' => '#0a0a0a',
            'darker-bg' => '#050505',
            'light-text' => '#ffffff',
            'gray-text' => '#a1a1aa',
            'border-color' => '#1f1f1f',
            'success' => '#10b981',
            'warning' => '#f59e0b',
            'danger' => '#ef4444',
            'info' => '#3b82f6',
            'light' => '#f8f9fa',
            'dark' => '#343a40'
        ],
        'fonts' => [
            'primary' => "'Rajdhani', sans-serif",
            'heading' => "'Orbitron', sans-serif",
            'secondary' => 'Georgia, serif',
            'monospace' => 'Courier New, monospace'
        ],
        'layout' => [
            'max_width' => '1200px',
            'sidebar_width' => '250px',
            'header_height' => '60px'
        ],
        'assets' => [
            'css' => ['theme.css'],
            'js' => ['theme.js'],
            'images' => []
        ],
        'gradients' => [
            'primary' => 'linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%)',
            'secondary' => 'linear-gradient(135deg, #f59e0b 0%, #ef4444 100%)',
            'dark' => 'linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%)'
        ],
        'shadows' => [
            'glow' => '0 0 20px rgba(0, 212, 255, 0.3)',
            'card' => '0 8px 32px rgba(0, 0, 0, 0.3)',
            'soft' => '0 4px 20px rgba(0, 0, 0, 0.1)',
            'medium' => '0 8px 32px rgba(0, 0, 0, 0.2)',
            'strong' => '0 16px 64px rgba(0, 0, 0, 0.3)'
        ],
        'responsive' => true,
        'rtl_support' => false
    ];
}

/**
 * Set active theme
 * @param string $themeName Theme name
 * @return bool Success status
 */
function theme_setActive(string $themeName): bool {
    $themesPath = cue_autoload('paths')->getThemesPath();

    if (!is_dir($themesPath . '/' . $themeName)) {
        cue_autoload('error')->logError('Theme not found', ['theme' => $themeName]);
        return false;
    }

    // Save active theme to configuration
    $configFile = cue_autoload('paths')->getConfigPath() . '/theme.json';
    $configDir = dirname($configFile);

    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }

    $config = ['default_theme' => $themeName];

    try {
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to save theme configuration', [
            'theme' => $themeName,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// -----------------------------------------------------------------------------
// ASSET MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Get theme asset URL
 * @param string $assetPath Asset path relative to theme directory
 * @param string|null $themeName Theme name (optional)
 * @return string Asset URL
 */
function theme_getAssetUrl(string $assetPath, ?string $themeName = null): string {
    if ($themeName === null) {
        $themeName = theme_getDefaultTheme();
    }

    $safeTheme = preg_replace('/[^a-z0-9_\-]/i', '', $themeName);
    if (!is_string($safeTheme) || $safeTheme === '') {
        $safeTheme = 'default';
    }
    $assetPath = ltrim($assetPath, '/');
    return '/templates/assets/themes/' . $safeTheme . '/' . $assetPath;
}

/**
 * Get theme CSS files
 * @param string|null $themeName Theme name (optional)
 * @return array CSS file URLs
 */
function theme_getCSSFiles(?string $themeName = null): array {
    $config = theme_loadConfiguration($themeName);
    $cssFiles = [];

    if (isset($config['assets']['css']) && is_array($config['assets']['css'])) {
        foreach ($config['assets']['css'] as $cssFile) {
            $cssFiles[] = theme_getAssetUrl($cssFile, $themeName);
        }
    }

    return $cssFiles;
}

/**
 * Get theme JavaScript files
 * @param string|null $themeName Theme name (optional)
 * @return array JavaScript file URLs
 */
function theme_getJSFiles(?string $themeName = null): array {
    $config = theme_loadConfiguration($themeName);
    $jsFiles = [];

    if (isset($config['assets']['js']) && is_array($config['assets']['js'])) {
        foreach ($config['assets']['js'] as $jsFile) {
            $jsFiles[] = theme_getAssetUrl($jsFile, $themeName);
        }
    }

    return $jsFiles;
}

/**
 * Generate CSS variables from theme configuration
 * @param string|null $themeName Theme name (optional)
 * @param array $config Optional configuration override
 * @return string CSS variables
 */
function theme_generateCSSVariables(?string $themeName = null, array $config = []): string {
    if (empty($config)) {
        $config = theme_loadConfiguration($themeName);
    } else {
        // Merge with default to ensure all keys exist
        $config = array_replace_recursive(theme_getDefaultConfiguration(), $config);
    }

    $css = ":root {\n";

    if (isset($config['colors']) && is_array($config['colors'])) {
        foreach ($config['colors'] as $name => $color) {
            $css .= "  --theme-{$name}: {$color};\n";
        }
    }

    if (isset($config['fonts']) && is_array($config['fonts'])) {
        foreach ($config['fonts'] as $name => $font) {
            $css .= "  --font-{$name}: {$font};\n";
        }
    }

    if (isset($config['layout']) && is_array($config['layout'])) {
        foreach ($config['layout'] as $name => $value) {
            $css .= "  --layout-{$name}: {$value};\n";
        }
    }

    if (isset($config['gradients']) && is_array($config['gradients'])) {
        foreach ($config['gradients'] as $name => $gradient) {
            $css .= "  --gradient-{$name}: {$gradient};\n";
        }
    }

    if (isset($config['shadows']) && is_array($config['shadows'])) {
        foreach ($config['shadows'] as $name => $shadow) {
            $css .= "  --shadow-{$name}: {$shadow};\n";
        }
    }

    $css .= "}\n";
    return $css;
}

/**
 * Generate theme CSS file
 * @param string|null $themeName Theme name (optional)
 * @return string Complete theme CSS
 */
function theme_generateCSS(?string $themeName = null): string {
    $config = theme_loadConfiguration($themeName);
    $css = "/* Generated theme CSS for {$config['name']} v{$config['version']} */\n\n";

    // CSS Variables
    $css .= theme_generateCSSVariables($themeName) . "\n";

    // Base styles
    $css .= "html, body { background-color: #1a1a1a !important; min-height: 100vh; margin: 0; padding: 0; }\n";
    $css .= ".main-content, .page-content, #page-wrapper { background-color: #1a1a1a !important; }\n";
    $css .= "body {\n";
    $css .= "  font-family: var(--font-primary);\n";
    $css .= "  color: var(--theme-dark);\n";
    $css .= "  background-color: var(--theme-light);\n";
    $css .= "}\n\n";

    // Heading styles
    $css .= "h1, h2, h3, h4, h5, h6 {\n";
    $css .= "  font-family: var(--font-heading);\n";
    $css .= "}\n\n";

    // Button styles
    $css .= ".btn-primary {\n";
    $css .= "  background-color: var(--theme-primary);\n";
    $css .= "  border-color: var(--theme-primary);\n";
    $css .= "}\n\n";

    $css .= ".btn-secondary {\n";
    $css .= "  background-color: var(--theme-secondary);\n";
    $css .= "  border-color: var(--theme-secondary);\n";
    $css .= "}\n\n";

    // Layout styles
    $css .= ".container {\n";
    $css .= "  max-width: var(--layout-max_width);\n";
    $css .= "}\n\n";

    $css .= ".sidebar {\n";
    $css .= "  width: var(--layout-sidebar_width);\n";
    $css .= "}\n\n";

    $css .= ".header {\n";
    $css .= "  height: var(--layout-header_height);\n";
    $css .= "}\n\n";

    // Gradient backgrounds
    $css .= ".bg-gradient-primary {\n";
    $css .= "  background: var(--gradient-primary);\n";
    $css .= "}\n\n";

    $css .= ".bg-gradient-secondary {\n";
    $css .= "  background: var(--gradient-secondary);\n";
    $css .= "}\n\n";

    // Shadow classes
    $css .= ".shadow-glow {\n";
    $css .= "  box-shadow: var(--shadow-glow);\n";
    $css .= "}\n\n";

    $css .= ".shadow-card {\n";
    $css .= "  box-shadow: var(--shadow-card);\n";
    $css .= "}\n\n";

    return $css;
}

/**
 * Include theme assets in HTML head
 * @param string|null $themeName Theme name (optional)
 * @return string HTML head includes
 */
function theme_includeAssets(?string $themeName = null): string {
    $html = "";
    $config = theme_loadConfiguration($themeName);

    // Include CSS files
    $cssFiles = theme_getCSSFiles($themeName);
    foreach ($cssFiles as $cssFile) {
        $html .= "<link rel=\"stylesheet\" href=\"{$cssFile}\">\n";
    }

    // Include JavaScript files
    $jsFiles = theme_getJSFiles($themeName);
    foreach ($jsFiles as $jsFile) {
        $html .= "<script src=\"{$jsFile}\"></script>\n";
    }

    // Add theme meta tags
    $html .= "<meta name=\"theme\" content=\"{$config['name']}\">\n";
    $html .= "<meta name=\"theme-version\" content=\"{$config['version']}\">\n";

    return $html;
}

// -----------------------------------------------------------------------------
// THEME CUSTOMIZATION
// -----------------------------------------------------------------------------

/**
 * Get theme color value
 * @param string $colorName Color name
 * @param string|null $themeName Theme name (optional)
 * @return string Color value
 */
function theme_getColor(string $colorName, ?string $themeName = null): string {
    $config = theme_loadConfiguration($themeName);
    return $config['colors'][$colorName] ?? $config['colors']['primary'] ?? '#007bff';
}

/**
 * Get theme font value
 * @param string $fontName Font name
 * @param string|null $themeName Theme name (optional)
 * @return string Font value
 */
function theme_getFont(string $fontName, ?string $themeName = null): string {
    $config = theme_loadConfiguration($themeName);
    return $config['fonts'][$fontName] ?? $config['fonts']['primary'] ?? 'Arial, sans-serif';
}

/**
 * Get theme layout value
 * @param string $layoutName Layout property name
 * @param string|null $themeName Theme name (optional)
 * @return string Layout value
 */
function theme_getLayout(string $layoutName, ?string $themeName = null): string {
    $config = theme_loadConfiguration($themeName);
    return $config['layout'][$layoutName] ?? '';
}

/**
 * Apply theme to CSS class
 * @param string $baseClass Base CSS class
 * @param array $overrides Style overrides
 * @param string|null $themeName Theme name (optional)
 * @return string CSS class with theme variables
 */
function theme_applyToClass(string $baseClass, array $overrides = [], ?string $themeName = null): string {
    $styles = [];

    // Apply theme-based styles
    if (strpos($baseClass, 'text-') === 0) {
        $color = substr($baseClass, 5);
        $styles[] = "color: var(--theme-{$color})";
    }

    if (strpos($baseClass, 'bg-') === 0) {
        $color = substr($baseClass, 3);
        $styles[] = "background-color: var(--theme-{$color})";
    }

    if ($baseClass === 'btn') {
        $styles[] = "font-family: var(--font-primary)";
    }

    // Apply overrides
    foreach ($overrides as $property => $value) {
        if (strpos($value, 'theme(') === 0) {
            $themeVar = substr($value, 6, -1);
            $styles[] = "{$property}: var(--theme-{$themeVar})";
        } else {
            $styles[] = "{$property}: {$value}";
        }
    }

    if (empty($styles)) {
        return $baseClass;
    }

    return $baseClass . '" style="' . implode('; ', $styles) . '"';
}

// -----------------------------------------------------------------------------
// THEME MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * List available themes
 * @return array Available themes
 */
function theme_listAvailable(): array {
    $themesPath = cue_autoload('paths')->getThemesPath();
    $themes = [];

    if (!is_dir($themesPath)) {
        return $themes;
    }

    $directories = scandir($themesPath);
    foreach ($directories as $dir) {
        if ($dir !== '.' && $dir !== '..' && is_dir($themesPath . '/' . $dir)) {
            $config = theme_loadConfiguration($dir);
            $themes[$dir] = [
                'name' => $config['name'],
                'version' => $config['version'],
                'description' => $config['description'],
                'author' => $config['author']
            ];
        }
    }

    return $themes;
}

/**
 * Validate theme structure
 * @param string $themeName Theme name
 * @return array Validation results
 */
function theme_validate(string $themeName): array {
    $result = ['valid' => true, 'errors' => [], 'warnings' => []];
    $themePath = cue_autoload('paths')->getThemesPath() . '/' . $themeName;

    if (!is_dir($themePath)) {
        $result['valid'] = false;
        $result['errors'][] = 'Theme directory not found';
        return $result;
    }

    // Check for required files
    $requiredFiles = ['theme.json'];
    foreach ($requiredFiles as $file) {
        if (!file_exists($themePath . '/' . $file)) {
            $result['valid'] = false;
            $result['errors'][] = "Required file missing: {$file}";
        }
    }

    // Validate theme.json
    $configFile = $themePath . '/theme.json';
    if (file_exists($configFile)) {
        try {
            $config = json_decode(file_get_contents($configFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $result['valid'] = false;
                $result['errors'][] = 'Invalid theme.json: ' . json_last_error_msg();
            } else {
                // Check required configuration keys
                $requiredKeys = ['name', 'version'];
                foreach ($requiredKeys as $key) {
                    if (!isset($config[$key])) {
                        $result['valid'] = false;
                        $result['errors'][] = "Required configuration key missing: {$key}";
                    }
                }
            }
        } catch (Exception $e) {
            $result['valid'] = false;
            $result['errors'][] = 'Failed to read theme.json: ' . $e->getMessage();
        }
    }

    // Check for asset files
    $config = theme_loadConfiguration($themeName);
    if (isset($config['assets']['css'])) {
        foreach ($config['assets']['css'] as $cssFile) {
            if (!file_exists($themePath . '/' . $cssFile)) {
                $result['warnings'][] = "CSS file not found: {$cssFile}";
            }
        }
    }

    if (isset($config['assets']['js'])) {
        foreach ($config['assets']['js'] as $jsFile) {
            if (!file_exists($themePath . '/' . $jsFile)) {
                $result['warnings'][] = "JavaScript file not found: {$jsFile}";
            }
        }
    }

    return $result;
}

/**
 * Create new theme from template
 * @param string $themeName New theme name
 * @param array $options Theme options
 * @return bool Success status
 */
function theme_create(string $themeName, array $options = []): bool {
    $themesPath = cue_autoload('paths')->getThemesPath();
    $themePath = $themesPath . '/' . $themeName;

    if (is_dir($themePath)) {
        cue_autoload('error')->logError('Theme already exists', ['theme' => $themeName]);
        return false;
    }

    try {
        // Create theme directory
        mkdir($themePath, 0755, true);

        // Create default theme.json
        $defaultConfig = theme_getDefaultConfiguration();
        $config = array_merge($defaultConfig, $options);
        $config['name'] = $themeName;
        $config['created'] = date('Y-m-d H:i:s');

        file_put_contents($themePath . '/theme.json', json_encode($config, JSON_PRETTY_PRINT));

        // Create basic CSS file
        $cssContent = theme_generateCSS($themeName);
        file_put_contents($themePath . '/theme.css', $cssContent);

        // Create basic JavaScript file
        $jsContent = "// Theme JavaScript for {$themeName}
// Generated by CUE Framework

document.addEventListener('DOMContentLoaded', function() {
    console.log('Theme {$themeName} loaded');
});
";
        file_put_contents($themePath . '/theme.js', $jsContent);

        cue_autoload('error')->logInfo('Theme created successfully', ['theme' => $themeName]);
        return true;

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to create theme', [
            'theme' => $themeName,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Delete theme
 * @param string $themeName Theme name
 * @return bool Success status
 */
function theme_delete(string $themeName): bool {
    $themesPath = cue_autoload('paths')->getThemesPath();
    $themePath = $themesPath . '/' . $themeName;

    if (!is_dir($themePath)) {
        cue_autoload('error')->logError('Theme not found', ['theme' => $themeName]);
        return false;
    }

    // Prevent deletion of default theme
    if ($themeName === 'default') {
        cue_autoload('error')->logError('Cannot delete default theme', ['theme' => $themeName]);
        return false;
    }

    try {
        // Recursively delete theme directory
        theme_deleteDirectory($themePath);

        cue_autoload('error')->logInfo('Theme deleted successfully', ['theme' => $themeName]);
        return true;

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to delete theme', [
            'theme' => $themeName,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Recursively delete directory
 * @param string $dir Directory path
 */
function theme_deleteDirectory(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                theme_deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
    }

    rmdir($dir);
}

// -----------------------------------------------------------------------------
// RESPONSIVE DESIGN SUPPORT
// -----------------------------------------------------------------------------

/**
 * Generate responsive CSS breakpoints
 * @param string|null $themeName Theme name (optional)
 * @return string Responsive CSS
 */
function theme_generateResponsiveCSS(?string $themeName = null): string {
    $config = theme_loadConfiguration($themeName);

    if (!$config['responsive']) {
        return '';
    }

    $css = "/* Responsive Design */\n\n";

    // Mobile first breakpoints
    $breakpoints = [
        'sm' => '576px',
        'md' => '768px',
        'lg' => '992px',
        'xl' => '1200px'
    ];

    foreach ($breakpoints as $name => $width) {
        $css .= "@media (min-width: {$width}) {\n";
        $css .= "  .d-{$name}-none { display: none !important; }\n";
        $css .= "  .d-{$name}-block { display: block !important; }\n";
        $css .= "  .d-{$name}-inline { display: inline !important; }\n";
        $css .= "  .d-{$name}-inline-block { display: inline-block !important; }\n";
        $css .= "}\n\n";
    }

    return $css;
}

/**
 * Check if theme supports RTL
 * @param string|null $themeName Theme name (optional)
 * @return bool RTL support status
 */
function theme_supportsRTL(?string $themeName = null): bool {
    $config = theme_loadConfiguration($themeName);
    return $config['rtl_support'] ?? false;
}

/**
 * Generate RTL-specific CSS
 * @param string|null $themeName Theme name (optional)
 * @return string RTL CSS
 */
function theme_generateRTLCSS(?string $themeName = null): string {
    if (!theme_supportsRTL($themeName)) {
        return '';
    }

    $css = "/* RTL Support */\n\n";
    $css .= "[dir=\"rtl\"] .text-left { text-align: right; }\n";
    $css .= "[dir=\"rtl\"] .text-right { text-align: left; }\n";
    $css .= "[dir=\"rtl\"] .float-left { float: right; }\n";
    $css .= "[dir=\"rtl\"] .float-right { float: left; }\n";
    $css .= "[dir=\"rtl\"] .ml-auto { margin-right: auto; margin-left: 0; }\n";
    $css .= "[dir=\"rtl\"] .mr-auto { margin-left: auto; margin-right: 0; }\n\n";

    return $css;
}


// -----------------------------------------------------------------------------
// GLASSMORPHISM & UI EFFECTS SUPPORT
// -----------------------------------------------------------------------------

/**
 * Generate glassmorphism CSS utilities
 * Creates backdrop-filter and transparency effects for modern UI
 * 
 * @param array $config Optional configuration overrides
 * @return string Glassmorphism CSS classes
 */
function theme_generateGlassmorphismCSS(array $config = []): string {
    $defaults = [
        'blur_amount' => '10px',
        'opacity' => '0.05',
        'border_opacity' => '0.2',
        'primary_color' => '#00ffff',
        'dark_bg' => '#1a1a2e',
        'darker_bg' => '#0a0a1a'
    ];
    
    $config = array_merge($defaults, $config);
    
    $css = "/* Glassmorphism Effects - CUE Framework */\n\n";
    
    // Base glassmorphism container
    $css .= ".glassmorphism {\n";
    $css .= "  background: rgba(255, 255, 255, {$config['opacity']});\n";
    $css .= "  backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  -webkit-backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  border: 1px solid rgba(255, 255, 255, {$config['border_opacity']});\n";
    $css .= "  border-radius: 15px;\n";
    $css .= "}\n\n";
    
    // Glassmorphism variants
    $css .= ".glassmorphism-primary {\n";
    $css .= "  background: rgba(0, 255, 255, {$config['opacity']});\n";
    $css .= "  backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  -webkit-backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  border: 1px solid rgba(0, 255, 255, {$config['border_opacity']});\n";
    $css .= "  border-radius: 15px;\n";
    $css .= "}\n\n";
    
    $css .= ".glassmorphism-dark {\n";
    $css .= "  background: rgba(0, 0, 0, 0.3);\n";
    $css .= "  backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  -webkit-backdrop-filter: blur({$config['blur_amount']});\n";
    $css .= "  border: 1px solid rgba(255, 255, 255, 0.1);\n";
    $css .= "  border-radius: 15px;\n";
    $css .= "}\n\n";
    
    return $css;
}

/**
 * Generate dropdown-specific CSS with dark blue backgrounds and cyan text
 * Implements the required styling guidelines from codebase instructions
 * 
 * @param array $config Optional configuration overrides
 * @return string Dropdown CSS
 */
function theme_generateDropdownCSS(array $config = []): string {
    $defaults = [
        'dropdown_bg' => '#1a1a2e',      // Dark blue background
        'dropdown_text' => '#00ffff',     // Cyan text
        'dropdown_hover_bg' => '#2a2a3e',
        'dropdown_border' => 'rgba(0, 255, 255, 0.3)'
    ];
    
    $config = array_merge($defaults, $config);
    
    $css = "/* Dropdown Styling - CUE Framework Guidelines */\n\n";
    
    // Base dropdown styles
    $css .= ".dropdown, .form-select, select {\n";
    $css .= "  background-color: {$config['dropdown_bg']} !important;\n";
    $css .= "  color: {$config['dropdown_text']} !important;\n";
    $css .= "  border: 1px solid {$config['dropdown_border']};\n";
    $css .= "  border-radius: 8px;\n";
    $css .= "  padding: 12px 15px;\n";
    $css .= "  transition: all 0.3s ease;\n";
    $css .= "}\n\n";
    
    // Dropdown hover and focus states
    $css .= ".dropdown:hover, .form-select:hover, select:hover,\n";
    $css .= ".dropdown:focus, .form-select:focus, select:focus {\n";
    $css .= "  background-color: {$config['dropdown_hover_bg']} !important;\n";
    $css .= "  border-color: {$config['dropdown_text']};\n";
    $css .= "  box-shadow: 0 0 15px rgba(0, 255, 255, 0.4);\n";
    $css .= "  outline: none;\n";
    $css .= "}\n\n";
    
    // Option styles
    $css .= ".dropdown option, .form-select option, select option {\n";
    $css .= "  background-color: {$config['dropdown_bg']} !important;\n";
    $css .= "  color: {$config['dropdown_text']} !important;\n";
    $css .= "}\n\n";
    
    return $css;
}

/**
 * Apply CUE Framework styling to current page
 * Outputs CSS directly to page header
 * 
 * @param string|null $themeName Theme name (optional)
 * @param array $config Optional configuration overrides
 * @return void
 */
function theme_applyCueFrameworkStyling(?string $themeName = null, array $config = []): void {
    echo "<style>\n";
    echo theme_generateCSSVariables($themeName, $config);
    echo theme_generateGlassmorphismCSS($config);
    echo theme_generateDropdownCSS($config);
    echo "</style>\n";
}

?>
