<?php
/**
 * CUE Framework Font Module
 *
 * Font management, loading, and optimization functions.
 * Loaded on-demand to improve performance.
 *
 * @package    CUE Framework
 * @version    75.0.1
 */

// -----------------------------------------------------------------------------
// FONT CONFIGURATION MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Load font configuration
 * @return array Font configuration
 */
function font_loadConfiguration(): array {
    static $configuration = null;

    if ($configuration !== null) {
        return $configuration;
    }

    $configFile = cue_autoload('paths')->getConfigPath() . '/fonts.json';

    if (!file_exists($configFile)) {
        $configuration = font_getDefaultConfiguration();
        return $configuration;
    }

    try {
        $configData = json_decode(file_get_contents($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            cue_autoload('error')->logError('Invalid JSON in font configuration', [
                'file' => $configFile,
                'error' => json_last_error_msg()
            ]);
            $configuration = font_getDefaultConfiguration();
            return $configuration;
        }

        $configuration = array_merge(font_getDefaultConfiguration(), $configData);

    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to load font configuration', [
            'file' => $configFile,
            'error' => $e->getMessage()
        ]);
        $configuration = font_getDefaultConfiguration();
    }

    return $configuration;
}

/**
 * Get default font configuration
 * @return array Default configuration
 */
function font_getDefaultConfiguration(): array {
    return [
        'google_fonts' => [
            'enabled' => true,
            'families' => [
                'Roboto' => [300, 400, 500, 700],
                'Open Sans' => [300, 400, 600, 700],
                'Lato' => [300, 400, 700],
                'Montserrat' => [400, 500, 600, 700]
            ],
            'display' => 'swap'
        ],
        'local_fonts' => [
            'enabled' => true,
            'path' => 'fonts/',
            'preload' => ['primary', 'secondary']
        ],
        'system_fonts' => [
            'primary' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
            'secondary' => 'Georgia, "Times New Roman", Times, serif',
            'monospace' => '"Courier New", Courier, monospace'
        ],
        'font_loading' => [
            'preload_critical' => true,
            'async_loading' => true,
            'font_display' => 'swap'
        ],
        'optimization' => [
            'subset' => true,
            'unicode_range' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
            'cache_control' => 'public, max-age=31536000'
        ]
    ];
}

/**
 * Save font configuration
 * @param array $config Font configuration
 * @return bool Success status
 */
function font_saveConfiguration(array $config): bool {
    $configFile = cue_autoload('paths')->getConfigPath() . '/fonts.json';
    $configDir = dirname($configFile);

    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }

    try {
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to save font configuration', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// -----------------------------------------------------------------------------
// GOOGLE FONTS MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Generate Google Fonts URL
 * @param array|null $families Font families (optional, uses config if not specified)
 * @param array $options Additional options
 * @return string Google Fonts URL
 */
function font_generateGoogleFontsUrl(array $families = null, array $options = []): string {
    $config = font_loadConfiguration();

    if (!$config['google_fonts']['enabled']) {
        return '';
    }

    if ($families === null) {
        $families = $config['google_fonts']['families'];
    }

    if (empty($families)) {
        return '';
    }

    $baseUrl = 'https://fonts.googleapis.com/css2';
    $params = [];

    foreach ($families as $family => $weights) {
        if (is_array($weights)) {
            $weightStr = implode(',', array_map('strval', $weights));
            $params[] = "family=" . urlencode($family) . ":wght@" . $weightStr;
        } else {
            $params[] = "family=" . urlencode($family);
        }
    }

    // Add display option
    $display = $options['display'] ?? $config['google_fonts']['display'] ?? 'swap';
    $params[] = "display={$display}";

    return $baseUrl . '?' . implode('&', $params);
}

/**
 * Get Google Fonts HTML link tag
 * @param array|null $families Font families (optional)
 * @return string HTML link tag
 */
function font_getGoogleFontsLink(array $families = null): string {
    $url = font_generateGoogleFontsUrl($families);

    if (empty($url)) {
        return '';
    }

    return "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n" .
           "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n" .
           "<link href=\"{$url}\" rel=\"stylesheet\">";
}

/**
 * Check if Google Fonts are available
 * @return bool Availability status
 */
function font_checkGoogleFontsAvailability(): bool {
    $url = font_generateGoogleFontsUrl(['Roboto' => [400]]);

    if (empty($url)) {
        return false;
    }

    // Simple connectivity check
    $headers = @get_headers($url);
    return $headers && strpos($headers[0], '200') !== false;
}

// -----------------------------------------------------------------------------
// LOCAL FONT MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Get local font file path
 * @param string $fontName Font name
 * @param string $format Font format (woff2, woff, ttf, etc.)
 * @return string Font file path
 */
function font_getLocalFontPath(string $fontName, string $format = 'woff2'): string {
    $config = font_loadConfiguration();
    $fontPath = $config['local_fonts']['path'] ?? 'fonts/';

    return cue_autoload('paths')->getAssetsPath() . "/{$fontPath}{$fontName}.{$format}";
}

/**
 * Get local font URL
 * @param string $fontName Font name
 * @param string $format Font format
 * @return string Font URL
 */
function font_getLocalFontUrl(string $fontName, string $format = 'woff2'): string {
    $filePath = font_getLocalFontPath($fontName, $format);
    $basePath = cue_autoload('paths')->getAssetsPath();

    if (strpos($filePath, $basePath) === 0) {
        return cue_autoload('paths')->getBaseUrl() . str_replace($basePath, '', $filePath);
    }

    return '';
}

/**
 * Generate @font-face CSS for local fonts
 * @param string $fontName Font name
 * @param array $options Font options
 * @return string CSS @font-face rule
 */
function font_generateFontFace(string $fontName, array $options = []): string {
    $config = font_loadConfiguration();

    $defaults = [
        'weight' => 400,
        'style' => 'normal',
        'display' => $config['font_loading']['font_display'] ?? 'swap',
        'unicode_range' => $config['optimization']['unicode_range'] ?? null
    ];

    $options = array_merge($defaults, $options);

    $css = "@font-face {\n";
    $css .= "  font-family: '{$fontName}';\n";
    $css .= "  font-weight: {$options['weight']};\n";
    $css .= "  font-style: {$options['style']};\n";
    $css .= "  font-display: {$options['display']};\n";

    // Add font sources
    $sources = [];

    // WOFF2 (preferred)
    $woff2Url = font_getLocalFontUrl($fontName, 'woff2');
    if (!empty($woff2Url)) {
        $sources[] = "url('{$woff2Url}') format('woff2')";
    }

    // WOFF (fallback)
    $woffUrl = font_getLocalFontUrl($fontName, 'woff');
    if (!empty($woffUrl)) {
        $sources[] = "url('{$woffUrl}') format('woff')";
    }

    // TTF (fallback)
    $ttfUrl = font_getLocalFontUrl($fontName, 'ttf');
    if (!empty($ttfUrl)) {
        $sources[] = "url('{$ttfUrl}') format('truetype')";
    }

    if (!empty($sources)) {
        $css .= "  src: " . implode(",\n       ", $sources) . ";\n";
    }

    if ($options['unicode_range']) {
        $css .= "  unicode-range: {$options['unicode_range']};\n";
    }

    $css .= "}\n";

    return $css;
}

/**
 * Generate preload links for critical fonts
 * @return string HTML preload links
 */
function font_generatePreloadLinks(): string {
    $config = font_loadConfiguration();
    $html = '';

    if (!$config['font_loading']['preload_critical']) {
        return $html;
    }

    $preloadFonts = $config['local_fonts']['preload'] ?? [];

    foreach ($preloadFonts as $fontName) {
        $fontUrl = font_getLocalFontUrl($fontName, 'woff2');
        if (!empty($fontUrl)) {
            $html .= "<link rel=\"preload\" href=\"{$fontUrl}\" as=\"font\" type=\"font/woff2\" crossorigin>\n";
        }
    }

    return $html;
}

// -----------------------------------------------------------------------------
// FONT LOADING OPTIMIZATION
// -----------------------------------------------------------------------------

/**
 * Generate font loading CSS
 * @return string Font loading CSS
 */
function font_generateLoadingCSS(): string {
    $config = font_loadConfiguration();

    $css = "/* Font Loading Optimization */\n\n";

    // Font loading class
    $css .= ".fonts-loading {\n";
    $css .= "  visibility: hidden;\n";
    $css .= "}\n\n";

    $css .= ".fonts-loaded {\n";
    $css .= "  visibility: visible;\n";
    $css .= "}\n\n";

    // Fallback fonts
    $systemFonts = $config['system_fonts'];
    $css .= ":root {\n";
    foreach ($systemFonts as $type => $fontStack) {
        $css .= "  --font-{$type}-fallback: {$fontStack};\n";
    }
    $css .= "}\n\n";

    return $css;
}

/**
 * Generate font loading JavaScript
 * @return string Font loading JavaScript
 */
function font_generateLoadingJS(): string {
    $js = "// Font Loading Optimization
document.documentElement.classList.add('fonts-loading');

if ('fonts' in document) {
  // Modern browsers with Font Loading API
  Promise.all([
    document.fonts.load('1em \"Primary Font\"'),
    document.fonts.load('1em \"Secondary Font\"')
  ]).then(function() {
    document.documentElement.classList.remove('fonts-loading');
    document.documentElement.classList.add('fonts-loaded');
  }).catch(function() {
    // Fallback for loading errors
    document.documentElement.classList.remove('fonts-loading');
    document.documentElement.classList.add('fonts-loaded');
  });
} else {
  // Fallback for older browsers
  setTimeout(function() {
    document.documentElement.classList.remove('fonts-loading');
    document.documentElement.classList.add('fonts-loaded');
  }, 100);
}
";

    return $js;
}

/**
 * Get optimized font stack
 * @param string $type Font type (primary, secondary, monospace)
 * @return string Optimized font stack
 */
function font_getOptimizedStack(string $type = 'primary'): string {
    $config = font_loadConfiguration();

    $stacks = [];

    // Add web fonts first (if enabled)
    if ($config['google_fonts']['enabled']) {
        $googleFamilies = $config['google_fonts']['families'];
        foreach ($googleFamilies as $family => $weights) {
            if (is_array($weights) && in_array(400, $weights)) {
                $stacks[] = "\"{$family}\"";
                break; // Use first available Google font
            }
        }
    }

    // Add local fonts
    if ($config['local_fonts']['enabled']) {
        $stacks[] = "\"Local Font\"";
    }

    // Add system font fallback
    if (isset($config['system_fonts'][$type])) {
        $stacks[] = $config['system_fonts'][$type];
    }

    return implode(', ', $stacks);
}

// -----------------------------------------------------------------------------
// FONT UTILITIES
// -----------------------------------------------------------------------------

/**
 * Measure text width for font optimization
 * @param string $text Text to measure
 * @param string $font Font family and size
 * @return int Approximate width in pixels
 */
function font_measureTextWidth(string $text, string $font = 'Arial 12px'): int {
    // Simple approximation - in a real implementation, you'd use canvas or external service
    $charWidth = 8; // Average character width for Arial 12px

    // Adjust for different fonts
    if (stripos($font, 'monospace') !== false) {
        $charWidth = 7.2;
    } elseif (stripos($font, 'serif') !== false) {
        $charWidth = 7.8;
    }

    return strlen($text) * $charWidth;
}

/**
 * Generate font subset for optimization
 * @param string $text Text to include in subset
 * @param string $fontFamily Font family
 * @return array Subset information
 */
function font_generateSubset(string $text, string $fontFamily): array {
    $config = font_loadConfiguration();

    if (!$config['optimization']['subset']) {
        return ['subset' => false, 'text' => $text];
    }

    // Extract unique characters
    $chars = [];
    $textLength = mb_strlen($text);

    for ($i = 0; $i < $textLength; $i++) {
        $char = mb_substr($text, $i, 1);
        $chars[$char] = true;
    }

    $uniqueChars = array_keys($chars);
    sort($uniqueChars);

    return [
        'subset' => true,
        'characters' => implode('', $uniqueChars),
        'count' => count($uniqueChars),
        'coverage' => count($uniqueChars) / 256 // Rough coverage estimate
    ];
}

/**
 * Validate font file
 * @param string $fontPath Font file path
 * @return array Validation results
 */
function font_validateFile(string $fontPath): array {
    $result = ['valid' => false, 'type' => '', 'size' => 0, 'errors' => []];

    if (!file_exists($fontPath)) {
        $result['errors'][] = 'Font file does not exist';
        return $result;
    }

    $size = filesize($fontPath);
    $result['size'] = $size;

    if ($size === 0) {
        $result['errors'][] = 'Font file is empty';
        return $result;
    }

    // Check file extension
    $extension = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));
    $validExtensions = ['woff2', 'woff', 'ttf', 'otf', 'eot'];

    if (!in_array($extension, $validExtensions)) {
        $result['errors'][] = 'Invalid font file extension';
        return $result;
    }

    $result['type'] = $extension;

    // Basic file header validation
    $handle = fopen($fontPath, 'rb');
    if ($handle) {
        $header = fread($handle, 4);
        fclose($handle);

        $headers = [
            'woff2' => 'wOF2',
            'woff' => 'wOFF',
            'ttf' => "\x00\x01\x00\x00",
            'otf' => 'OTTO'
        ];

        if (isset($headers[$extension]) && $header === $headers[$extension]) {
            $result['valid'] = true;
        } else {
            $result['errors'][] = 'Invalid font file header';
        }
    } else {
        $result['errors'][] = 'Cannot read font file';
    }

    return $result;
}

/**
 * Get font metrics
 * @param string $fontPath Font file path
 * @return array Font metrics
 */
function font_getMetrics(string $fontPath): array {
    $metrics = [
        'family' => '',
        'style' => 'normal',
        'weight' => 400,
        'size' => 0,
        'ascent' => 0,
        'descent' => 0,
        'line_gap' => 0
    ];

    if (!file_exists($fontPath)) {
        return $metrics;
    }

    // This is a simplified implementation
    // In a real scenario, you'd use a proper font parsing library
    $extension = strtolower(pathinfo($fontPath, PATHINFO_EXTENSION));

    // Try to extract basic info from filename
    $filename = basename($fontPath, '.' . $extension);
    $parts = explode('-', $filename);

    if (count($parts) > 1) {
        $lastPart = end($parts);

        // Check for weight
        if (is_numeric($lastPart)) {
            $metrics['weight'] = (int)$lastPart;
            array_pop($parts);
        }

        // Check for style
        $styleKeywords = ['italic', 'oblique'];
        foreach ($styleKeywords as $style) {
            if (stripos($lastPart, $style) !== false) {
                $metrics['style'] = $style;
                break;
            }
        }
    }

    $metrics['family'] = ucwords(implode(' ', $parts));
    $metrics['size'] = filesize($fontPath);

    return $metrics;
}

// -----------------------------------------------------------------------------
// FONT CACHE MANAGEMENT
// -----------------------------------------------------------------------------

/**
 * Generate font cache key
 * @param array $fonts Font configuration
 * @return string Cache key
 */
function font_generateCacheKey(array $fonts): string {
    return 'font_cache_' . md5(json_encode($fonts));
}

/**
 * Cache font CSS
 * @param string $css CSS content
 * @param string $cacheKey Cache key
 * @param int $ttl Time to live in seconds
 * @return bool Success status
 */
function font_cacheCSS(string $css, string $cacheKey, int $ttl = 3600): bool {
    $cacheDir = cue_autoload('paths')->getCachePath() . '/fonts/';

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . $cacheKey . '.css';

    try {
        file_put_contents($cacheFile, $css);
        // Set cache expiration
        touch($cacheFile, time() + $ttl);
        return true;
    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to cache font CSS', [
            'cache_key' => $cacheKey,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Get cached font CSS
 * @param string $cacheKey Cache key
 * @return string|null Cached CSS or null if not found/expired
 */
function font_getCachedCSS(string $cacheKey): ?string {
    $cacheFile = cue_autoload('paths')->getCachePath() . '/fonts/' . $cacheKey . '.css';

    if (!file_exists($cacheFile)) {
        return null;
    }

    // Check if cache is expired
    if (time() > filemtime($cacheFile)) {
        unlink($cacheFile);
        return null;
    }

    try {
        return file_get_contents($cacheFile);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Clear font cache
 * @return bool Success status
 */
function font_clearCache(): bool {
    $cacheDir = cue_autoload('paths')->getCachePath() . '/fonts/';

    if (!is_dir($cacheDir)) {
        return true;
    }

    try {
        $files = glob($cacheDir . '*.css');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    } catch (Exception $e) {
        cue_autoload('error')->logError('Failed to clear font cache', [
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

// -----------------------------------------------------------------------------
// INTEGRATION HELPERS
// -----------------------------------------------------------------------------

/**
 * Generate complete font CSS include
 * @return string Complete font CSS
 */
function font_generateCompleteCSS(): string {
    $config = font_loadConfiguration();
    $css = "/* CUE Framework Font CSS */\n\n";

    // Font loading CSS
    $css .= font_generateLoadingCSS() . "\n";

    // Local font faces
    if ($config['local_fonts']['enabled']) {
        $css .= "/* Local Font Faces */\n";
        $css .= font_generateFontFace('Primary Font', ['weight' => 400]);
        $css .= font_generateFontFace('Primary Font', ['weight' => 700]);
        $css .= font_generateFontFace('Secondary Font', ['weight' => 400]);
        $css .= "\n";
    }

    // Font stacks
    $css .= "/* Font Stacks */\n";
    $css .= ":root {\n";
    $css .= "  --font-primary: " . font_getOptimizedStack('primary') . ";\n";
    $css .= "  --font-secondary: " . font_getOptimizedStack('secondary') . ";\n";
    $css .= "  --font-monospace: " . font_getOptimizedStack('monospace') . ";\n";
    $css .= "}\n\n";

    // Apply fonts to elements
    $css .= "body {\n";
    $css .= "  font-family: var(--font-primary);\n";
    $css .= "}\n\n";

    $css .= "h1, h2, h3, h4, h5, h6 {\n";
    $css .= "  font-family: var(--font-secondary);\n";
    $css .= "}\n\n";

    $css .= "code, pre {\n";
    $css .= "  font-family: var(--font-monospace);\n";
    $css .= "}\n\n";

    return $css;
}

/**
 * Generate complete font HTML includes
 * @return string Complete HTML includes
 */
function font_generateCompleteHTML(): string {
    $html = '';

    // Preload links
    $html .= font_generatePreloadLinks();

    // Google Fonts
    $html .= font_getGoogleFontsLink();

    // Font loading JavaScript
    $config = font_loadConfiguration();
    if ($config['font_loading']['async_loading']) {
        $html .= "<script>\n" . font_generateLoadingJS() . "\n</script>\n";
    }

    return $html;
}

?>