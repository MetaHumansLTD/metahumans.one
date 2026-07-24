<?php
/**
 * Multi-Icon-Set Browser - Meta Humans Enterprise Software
 * Supports FontAwesome, Feather, Iconoir, and Phosphor Icons
 * 
 * @package    Meta Humans Icon Browser
 * @author     Meta Humans LTD (Pieter Rubeus - owner)
 * @copyright  Copyright (c) Meta Humans LTD® 2025
 * @license    Licensed
 * @link       https://metahumans.one
 */

// Start output buffering to prevent headers already sent errors
ob_start();

// Include the main cue.php configuration
require_once '../../../.cue/cue.php';

// Start secure session
//startSecureSession(); // DISABLED: function not defined

/**
 * Get asset URL for templates
 * 
 * @param string $path Asset path relative to templates/assets/
 * @return string Full asset URL
 */
function getAssetURL(string $path = ''): string {
    return getTemplateURL('assets/' . ltrim($path, '/'));
}

// Icon Set Configuration
$iconSets = [
    'fontawesome' => [
        'name' => 'FontAwesome',
        'type' => 'font',
        'css' => 'all.min.css',
        'prefix' => 'fas fa-',
        'format' => 'class'
    ],
    'feather' => [
        'name' => 'Feather',
        'type' => 'svg',
        'path' => 'feather/',
        'format' => 'svg'
    ],
    'iconoir' => [
        'name' => 'Iconoir',
        'type' => 'svg',
        'path' => 'iconoir/icons/',
        'variants' => ['regular', 'solid'],
        'format' => 'svg'
    ],
    'phosphor' => [
        'name' => 'Phosphor',
        'type' => 'svg',
        'path' => 'phosphor/SVGs/',
        'variants' => ['regular', 'bold', 'light', 'thin', 'fill', 'duotone'],
        'format' => 'svg'
    ]
];

// Get current settings
$iconSet = $_GET['set'] ?? 'fontawesome';
$variant = $_GET['variant'] ?? 'regular';
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'ALL';

// FontAwesome Icons (existing structure)
$fontAwesomeCategories = [
    'COMMON' => [
        'home' => 'fas fa-house',
        'user' => 'fas fa-user',
        'users' => 'fas fa-users',
        'cog' => 'fas fa-cog',
        'dashboard' => 'fas fa-tachometer-alt',
        'chart-line' => 'fas fa-chart-line',
        'bell' => 'fas fa-bell',
        'search' => 'fas fa-search',
        'plus' => 'fas fa-plus',
        'edit' => 'fas fa-edit',
        'trash' => 'fas fa-trash',
        'download' => 'fas fa-download',
        'upload' => 'fas fa-upload',
        'star' => 'fas fa-star',
        'heart' => 'fas fa-heart',
        'calendar' => 'fas fa-calendar',
        'metahumans' => 'fa fa-metahumans'
    ],
    'BUSINESS' => [
        'briefcase' => 'fas fa-briefcase',
        'building' => 'fas fa-building',
        'chart-bar' => 'fas fa-chart-bar',
        'chart-pie' => 'fas fa-chart-pie',
        'money-bill' => 'fas fa-money-bill',
        'handshake' => 'fas fa-handshake',
        'presentation-screen' => 'fas fa-presentation-screen',
        'project-diagram' => 'fas fa-project-diagram',
        'calculator' => 'fas fa-calculator',
        'clipboard' => 'fas fa-clipboard',
        'file-invoice' => 'fas fa-file-invoice',
        'balance-scale' => 'fas fa-balance-scale',
        'dollar-sign' => 'fas fa-dollar-sign',
        'percentage' => 'fas fa-percentage',
        'award' => 'fas fa-award',
        'gem' => 'fas fa-gem'
    ],
    'COMMUNICATION' => [
        'envelope' => 'fas fa-envelope',
        'phone' => 'fas fa-phone',
        'comments' => 'fas fa-comments',
        'comment' => 'fas fa-comment',
        'video' => 'fas fa-video',
        'microphone' => 'fas fa-microphone',
        'headphones' => 'fas fa-headphones',
        'broadcast-tower' => 'fas fa-broadcast-tower',
        'wifi' => 'fas fa-wifi',
        'signal' => 'fas fa-signal',
        'share' => 'fas fa-share',
        'paper-plane' => 'fas fa-paper-plane',
        'rss' => 'fas fa-rss',
        'at' => 'fas fa-at',
        'link' => 'fas fa-link',
        'globe' => 'fas fa-globe'
    ],
    'NAVIGATION' => [
        'bars' => 'fas fa-bars',
        'arrow-left' => 'fas fa-arrow-left',
        'arrow-right' => 'fas fa-arrow-right',
        'arrow-up' => 'fas fa-arrow-up',
        'arrow-down' => 'fas fa-arrow-down',
        'chevron-left' => 'fas fa-chevron-left',
        'chevron-right' => 'fas fa-chevron-right',
        'chevron-up' => 'fas fa-chevron-up',
        'chevron-down' => 'fas fa-chevron-down',
        'angle-left' => 'fas fa-angle-left',
        'angle-right' => 'fas fa-angle-right',
        'angle-up' => 'fas fa-angle-up',
        'angle-down' => 'fas fa-angle-down',
        'step-backward' => 'fas fa-step-backward',
        'step-forward' => 'fas fa-step-forward',
        'expand' => 'fas fa-expand'
    ],
    'FILES & MEDIA' => [
        'file' => 'fas fa-file',
        'file-alt' => 'fas fa-file-alt',
        'file-pdf' => 'fas fa-file-pdf',
        'file-word' => 'fas fa-file-word',
        'file-excel' => 'fas fa-file-excel',
        'file-powerpoint' => 'fas fa-file-powerpoint',
        'file-image' => 'fas fa-file-image',
        'file-video' => 'fas fa-file-video',
        'file-audio' => 'fas fa-file-audio',
        'file-archive' => 'fas fa-file-archive',
        'folder' => 'fas fa-folder',
        'folder-open' => 'fas fa-folder-open',
        'image' => 'fas fa-image',
        'images' => 'fas fa-images',
        'play' => 'fas fa-play',
        'pause' => 'fas fa-pause'
    ],
    'TECH & SYSTEM' => [
        'desktop' => 'fas fa-desktop',
        'laptop' => 'fas fa-laptop',
        'mobile' => 'fas fa-mobile',
        'tablet' => 'fas fa-tablet',
        'server' => 'fas fa-server',
        'database' => 'fas fa-database',
        'cloud' => 'fas fa-cloud',
        'shield-alt' => 'fas fa-shield-alt',
        'lock' => 'fas fa-lock',
        'unlock' => 'fas fa-unlock',
        'key' => 'fas fa-key',
        'code' => 'fas fa-code',
        'terminal' => 'fas fa-terminal',
        'bug' => 'fas fa-bug',
        'robot' => 'fas fa-robot',
        'microchip' => 'fas fa-microchip'
    ]
];

// Function to scan SVG icons from directories
function scanSVGIcons($basePath, $variants = null) {
    $icons = [];
    $fullBasePath = __DIR__ . '/' . $basePath;
    
    if ($variants) {
        foreach ($variants as $variant) {
            $variantPath = $fullBasePath . $variant . '/';
            if (is_dir($variantPath)) {
                $files = glob($variantPath . '*.svg');
                foreach ($files as $file) {
                    $name = basename($file, '.svg');
                    $relativePath = $basePath . $variant . '/' . basename($file);
                    $icons[$variant][$name] = [
                        'name' => $name,
                        'path' => $relativePath,
                        'fullPath' => $file,
                        'variant' => $variant
                    ];
                }
            }
        }
    } else {
        // Single directory scan
        if (is_dir($fullBasePath)) {
            $files = glob($fullBasePath . '*.svg');
            foreach ($files as $file) {
                $name = basename($file, '.svg');
                $relativePath = $basePath . basename($file);
                $icons[$name] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'fullPath' => $file
                ];
            }
        }
    }
    
    return $icons;
}

// Function to get SVG content
function getSVGContent($filePath) {
    $fullPath = __DIR__ . '/' . $filePath;
    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        // Clean up the SVG to make it controllable
        $content = preg_replace('/width="[^"]*"/', '', $content);
        $content = preg_replace('/height="[^"]*"/', '', $content);
        $content = preg_replace('/fill="[^"]*"/', 'fill="currentColor"', $content);
        $content = preg_replace('/stroke="[^"]*"/', 'stroke="currentColor"', $content);
        return $content;
    }
    return '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>';
}

// Get current search and filter (moved before switch statement)
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'ALL';

// Get icons based on selected set or search across all sets
$availableIcons = [];
$currentIconSet = $iconSets[$iconSet] ?? $iconSets['fontawesome'];
$allIconSets = []; // Store all loaded icon sets for search

// If searching, load all icon sets; otherwise load only selected set
if (!empty($search)) {
    // Load all icon sets for cross-set searching
    
    // Load FontAwesome
    $allIconSets['fontawesome'] = [
        'name' => 'FontAwesome',
        'icons' => $fontAwesomeCategories
    ];
    
    // Load Feather
    $featherIconSet = $iconSets['feather'];
    $featherIcons = scanSVGIcons($featherIconSet['path']);
    $allIconSets['feather'] = [
        'name' => 'Feather',
        'icons' => ['ALL' => $featherIcons]
    ];
    
    // Load Iconoir
    $iconoirIconSet = $iconSets['iconoir'];
    $iconoirIcons = scanSVGIcons($iconoirIconSet['path'], $iconoirIconSet['variants']);
    $allIconSets['iconoir'] = [
        'name' => 'Iconoir',
        'icons' => [
            'REGULAR' => $iconoirIcons['regular'] ?? [],
            'SOLID' => $iconoirIcons['solid'] ?? []
        ]
    ];
    
    // Load Phosphor
    $phosphorIconSet = $iconSets['phosphor'];
    $phosphorIcons = scanSVGIcons($phosphorIconSet['path'], $phosphorIconSet['variants']);
    $allIconSets['phosphor'] = [
        'name' => 'Phosphor',
        'icons' => [
            'REGULAR' => $phosphorIcons['regular'] ?? [],
            'BOLD' => $phosphorIcons['bold'] ?? [],
            'LIGHT' => $phosphorIcons['light'] ?? [],
            'THIN' => $phosphorIcons['thin'] ?? [],
            'FILL' => $phosphorIcons['fill'] ?? [],
            'DUOTONE' => $phosphorIcons['duotone'] ?? []
        ]
    ];
    
} else {
    // Load only selected icon set (original behavior)
    switch ($iconSet) {
        case 'fontawesome':
            $availableIcons = $fontAwesomeCategories;
            break;
            
        case 'feather':
            $featherIcons = scanSVGIcons($currentIconSet['path']);
            $availableIcons = ['ALL' => $featherIcons];
            break;
            
        case 'iconoir':
            $iconoirIcons = scanSVGIcons($currentIconSet['path'], $currentIconSet['variants']);
            $availableIcons = [
                'REGULAR' => $iconoirIcons['regular'] ?? [],
                'SOLID' => $iconoirIcons['solid'] ?? []
            ];
            break;
            
        case 'phosphor':
            $phosphorIcons = scanSVGIcons($currentIconSet['path'], $currentIconSet['variants']);
            $availableIcons = [
                'REGULAR' => $phosphorIcons['regular'] ?? [],
                'BOLD' => $phosphorIcons['bold'] ?? [],
                'LIGHT' => $phosphorIcons['light'] ?? [],
                'THIN' => $phosphorIcons['thin'] ?? [],
                'FILL' => $phosphorIcons['fill'] ?? [],
                'DUOTONE' => $phosphorIcons['duotone'] ?? []
            ];
            break;
    }
}

// Filter icons based on search and category
$filteredIcons = [];

if (!empty($search)) {
    // Search across all icon sets
    foreach ($allIconSets as $setKey => $setData) {
        foreach ($setData['icons'] as $categoryName => $icons) {
            if (is_array($icons)) {
                foreach ($icons as $name => $iconData) {
                    // Search in icon name
                    if (stripos($name, $search) !== false) {
                        $uniqueKey = $setKey . '_' . $name . '_' . $categoryName;
                        
                        if ($setKey === 'fontawesome') {
                            $filteredIcons[$uniqueKey] = [
                                'class' => $iconData,
                                'type' => 'fontawesome',
                                'name' => $name,
                                'set' => $setData['name'],
                                'setKey' => $setKey
                            ];
                        } else {
                            $filteredIcons[$uniqueKey] = [
                                'data' => $iconData,
                                'type' => $setKey,
                                'name' => $name,
                                'category' => $categoryName,
                                'set' => $setData['name'],
                                'setKey' => $setKey
                            ];
                        }
                    }
                }
            }
        }
    }
} else {
    // Original filtering logic for single icon set
    if ($iconSet === 'fontawesome') {
        $iconCategories = $availableIcons;
        if ($category === 'ALL') {
            foreach ($iconCategories as $cat => $icons) {
                foreach ($icons as $name => $class) {
                    $filteredIcons[$name] = [
                        'class' => $class,
                        'type' => 'fontawesome',
                        'name' => $name
                    ];
                }
            }
        } else {
            if (isset($iconCategories[$category])) {
                foreach ($iconCategories[$category] as $name => $class) {
                    $filteredIcons[$name] = [
                        'class' => $class,
                        'type' => 'fontawesome',
                        'name' => $name
                    ];
                }
            }
        }
    } else {
        if ($category === 'ALL') {
            foreach ($availableIcons as $cat => $icons) {
                if (is_array($icons)) {
                    foreach ($icons as $name => $iconData) {
                        $filteredIcons[$name] = [
                            'data' => $iconData,
                            'type' => $iconSet,
                            'name' => $name,
                            'category' => $cat
                        ];
                    }
                }
            }
        } else {
            if (isset($availableIcons[$category]) && is_array($availableIcons[$category])) {
                foreach ($availableIcons[$category] as $name => $iconData) {
                    $filteredIcons[$name] = [
                        'data' => $iconData,
                        'type' => $iconSet,
                        'name' => $name,
                        'category' => $category
                    ];
                }
            }
        }
    }
}

// Header.php include removed for standalone icon browser

// Include centralized loader widget for loading animations
require_once '../../widgets/loader/loader.php';
?>

<title>Multi-Icon Browser - Meta Humans</title>
    
    <!-- FontAwesome Icons -->
    <link href="<?php echo getAssetURL('fonts/all.min.css'); ?>" rel="stylesheet">
    
    <style>
        .fa-metahumans {
            background-image: url('<?php echo getAssetURL('icons/metahumans.png'); ?>');
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            width: 1em;
            height: 1em;
            display: inline-block;
        }
        .fa-metahumans:before { content: ''; }
        /* CSS Variables for Meta Humans Theme */
        :root {
            --dark-bg: #0a0a0a;
            --darker-bg: #1a1a2e;
            --primary-color: #00d4ff;
            --secondary-color: #7c3aed;
            --gradient-primary: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%);
            --light-text: #ffffff;
            --gray-text: #b3b3b3;
            --border-color: #333;
            --shadow-primary: 0 8px 32px rgba(0, 212, 255, 0.3);
            --shadow-card: 0 4px 16px rgba(0, 0, 0, 0.3);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Rajdhani', sans-serif;
            background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 50%, #16213e 100%);
            color: #ffffff;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        /* Header */
        .icon-header {
            background: var(--darker-bg);
            border-bottom: 2px solid var(--primary-color);
            padding: 20px 30px;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-icon {
            width: 40px;
            height: 40px;
            background: var(--gradient-primary);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .header-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Search and Filters */
        .controls {
            background: var(--darker-bg);
            padding: 25px 30px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .controls-content {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Icon Set Controls */
        .icon-set-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.1) 0%, rgba(245, 158, 11, 0.1) 100%);
            border: 2px solid rgba(124, 58, 237, 0.5);
            border-radius: 16px;
            flex-wrap: wrap;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.2);
            backdrop-filter: blur(10px);
        }
        
        .icon-set-controls::before {
            content: '🎨 Icon Set Selection';
            font-weight: 700;
            color: rgba(124, 58, 237, 1);
            font-size: 1rem;
            margin-right: 20px;
            padding: 8px 16px;
            background: rgba(124, 58, 237, 0.2);
            border-radius: 8px;
            border: 1px solid rgba(124, 58, 237, 0.5);
        }
        
        .selector-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .slider-selector {
            display: flex;
            background: var(--darker-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        .selector-option {
            padding: 10px 16px;
            color: var(--gray-text);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: transparent;
            border-right: 1px solid var(--border-color);
            position: relative;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .selector-option:last-child {
            border-right: none;
        }
        
        .selector-option:hover {
            background: rgba(124, 58, 237, 0.2);
            color: var(--light-text);
            transform: translateY(-1px);
        }
        
        .selector-option.active {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.8) 0%, rgba(245, 158, 11, 0.8) 100%);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(124, 58, 237, 0.4);
            transform: translateY(-2px);
        }
        
        .selector-option.active::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.1) 50%, transparent 70%);
            animation: shine 2s infinite;
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        /* Loading Animation - Removed duplicate, using centralized loader widget */
        
        .loading-spinner {
            text-align: center;
            position: relative;
        }
        
        .spinner-ring {
            display: inline-block;
            width: 60px;
            height: 60px;
            margin: 0 5px;
            border: 4px solid transparent;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        .spinner-ring:nth-child(2) {
            border-top-color: rgba(124, 58, 237, 1);
            animation-delay: -0.3s;
            width: 50px;
            height: 50px;
        }
        
        .spinner-ring:nth-child(3) {
            border-top-color: rgba(245, 158, 11, 1);
            animation-delay: -0.6s;
            width: 40px;
            height: 40px;
        }
        
        .loading-text {
            margin-top: 20px;
            color: var(--light-text);
            font-size: 1.1rem;
            font-weight: 600;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; }
        }
        
        .icon-set-selector select:hover {
            border-color: var(--primary-color);
        }
        
        /* Plugin Control Bar */
        .plugin-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 25px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(0, 212, 255, 0.1) 0%, rgba(124, 58, 237, 0.1) 100%);
            border: 2px solid var(--primary-color);
            border-radius: 16px;
            flex-wrap: wrap;
            box-shadow: var(--shadow-primary);
            backdrop-filter: blur(10px);
        }
        
        .plugin-controls::before {
            content: '🔧 Plugin Controls';
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1rem;
            margin-right: 20px;
            padding: 8px 16px;
            background: rgba(0, 212, 255, 0.2);
            border-radius: 8px;
            border: 1px solid var(--primary-color);
        }
        
        .plugin-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Standardized height for all plugin controls */
        .plugin-group input[type="range"] {
            width: 140px;
            height: 12px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.3) 0%, rgba(239, 68, 68, 0.3) 100%);
            border: 2px solid rgba(245, 158, 11, 0.5);
            border-radius: 8px;
            outline: none;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
            -webkit-appearance: none;
        }
        
        .plugin-group input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
            border: 2px solid #ffffff;
        }
        
        .plugin-group input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid #ffffff;
        }
        
        .plugin-group input[type="color"] {
            width: 50px;
            height: 40px;
            border: 2px solid rgba(16, 185, 129, 0.5);
            border-radius: 8px;
            cursor: pointer;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(6, 182, 212, 0.2) 100%);
            transition: all 0.3s ease;
        }
        
        .plugin-group input[type="color"]:hover {
            border-color: rgba(16, 185, 129, 0.8);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .plugin-group select {
            padding: 10px 16px;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.2) 0%, rgba(168, 85, 247, 0.2) 100%);
            border: 2px solid rgba(139, 92, 246, 0.5);
            border-radius: 8px;
            color: var(--light-text);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            height: 40px;
            min-width: 120px;
            transition: all 0.3s ease;
        }
        
        .plugin-group select:hover {
            border-color: rgba(139, 92, 246, 0.8);
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.3) 0%, rgba(168, 85, 247, 0.3) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .plugin-btn {
            padding: 10px 16px;
            height: 40px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2) 0%, rgba(220, 38, 127, 0.2) 100%);
            border: 2px solid rgba(239, 68, 68, 0.5);
            border-radius: 8px;
            color: var(--light-text);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 100px;
        }
        
        .plugin-btn:hover {
            border-color: rgba(239, 68, 68, 0.8);
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.3) 0%, rgba(220, 38, 127, 0.3) 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.3);
        }
        
        .plugin-btn:active {
            transform: translateY(0);
        }
        
        /* Special styling for different button types */
        .plugin-btn:nth-of-type(1) {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2) 0%, rgba(5, 150, 105, 0.2) 100%);
            border-color: rgba(16, 185, 129, 0.5);
        }
        
        .plugin-btn:nth-of-type(1):hover {
            border-color: rgba(16, 185, 129, 0.8);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.3) 0%, rgba(5, 150, 105, 0.3) 100%);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.3);
        }
        
        .plugin-btn:nth-of-type(2) {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(217, 119, 6, 0.2) 100%);
            border-color: rgba(245, 158, 11, 0.5);
        }
        
        .plugin-btn:nth-of-type(2):hover {
            border-color: rgba(245, 158, 11, 0.8);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.3) 0%, rgba(217, 119, 6, 0.3) 100%);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.3);
        }
        
        .plugin-btn:nth-of-type(3) {
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.2) 0%, rgba(147, 51, 234, 0.2) 100%);
            border-color: rgba(168, 85, 247, 0.5);
        }
        
        .plugin-btn:nth-of-type(3):hover {
            border-color: rgba(168, 85, 247, 0.8);
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.3) 0%, rgba(147, 51, 234, 0.3) 100%);
            box-shadow: 0 4px 16px rgba(168, 85, 247, 0.3);
        }
        
        /* Randomize button special styling */
        .plugin-btn[onclick*="randomizeColor"] {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.2) 0%, rgba(239, 68, 68, 0.2) 100%);
            border-color: rgba(245, 158, 11, 0.5);
            width: 40px;
            min-width: 40px;
            padding: 10px;
        }
        
        .plugin-btn[onclick*="randomizeColor"]:hover {
            border-color: rgba(245, 158, 11, 0.8);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.3) 0%, rgba(239, 68, 68, 0.3) 100%);
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.3);
        }
        
        #sizeDisplay {
            font-size: 13px;
            color: var(--light-text);
            font-weight: 600;
            min-width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(0, 212, 255, 0.2) 0%, rgba(59, 130, 246, 0.2) 100%);
            border: 2px solid rgba(0, 212, 255, 0.5);
            border-radius: 8px;
            padding: 0 12px;
        }
        
        .search-box {
            width: 100%;
            max-width: 700px;
            margin: 0 auto 25px auto;
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 18px 55px 18px 25px;
            background: var(--dark-bg);
            border: 2px solid var(--border-color);
            border-radius: 15px;
            color: var(--light-text);
            font-size: 16px;
            font-family: 'Rajdhani', sans-serif;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: var(--shadow-primary);
        }
        
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
            font-size: 1.2rem;
        }
        
        .category-filters {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .category-btn {
            padding: 12px 20px;
            background: var(--dark-bg);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--light-text);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        
        .category-btn:hover {
            border-color: var(--primary-color);
            background: rgba(0, 212, 255, 0.1);
            transform: translateY(-2px);
        }
        
        .category-btn.active {
            background: var(--gradient-primary);
            border-color: var(--primary-color);
            color: white;
        }
        
        /* Icon Grid */
        .icon-container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .results-info {
            margin-bottom: 25px;
            color: var(--gray-text);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .results-count {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .search-scope {
            background: rgba(0, 212, 255, 0.2);
            color: var(--primary-color);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }
        
        .icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .icon-item {
            background: var(--darker-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .icon-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .icon-item:hover {
            border-color: var(--primary-color);
            background: rgba(0, 212, 255, 0.05);
            transform: translateY(-5px);
            box-shadow: var(--shadow-card);
        }
        
        .icon-item:hover::before {
            transform: scaleX(1);
        }
        
        .icon-display {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 12px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 48px;
        }
        
        .icon-display svg {
            width: 32px;
            height: 32px;
            fill: currentColor;
            stroke: currentColor;
            transition: all 0.3s ease;
        }
        
        .icon-display i {
            font-size: 2rem;
        }
        
        .icon-item:hover .icon-display {
            transform: scale(1.2);
            color: #f59e0b;
        }
        
        .icon-item:hover .icon-display svg {
            transform: scale(1.2);
        }
        
        .icon-item.selected {
            border-color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
            box-shadow: 0 0 20px rgba(245, 158, 11, 0.3);
        }
        
        .icon-item.favorite {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        
        .icon-item.favorite::after {
            content: '⭐';
            position: absolute;
            top: 5px;
            right: 5px;
            font-size: 16px;
        }
        
        /* Icon Set Badge for Cross-Set Search */
        .icon-set-badge {
            position: absolute;
            top: 5px;
            left: 5px;
            background: var(--primary-color);
            color: #000;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.9;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            z-index: 2;
        }
        
        .icon-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--light-text);
            margin-bottom: 8px;
            word-break: break-word;
        }
        
        .icon-class {
            font-size: 0.75rem;
            color: var(--gray-text);
            font-family: 'Courier New', monospace;
            background: rgba(0, 0, 0, 0.3);
            padding: 4px 8px;
            border-radius: 6px;
            word-break: break-all;
        }
        
        /* Copy notification */
        .copy-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--gradient-primary);
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            font-weight: 600;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            z-index: 1000;
            box-shadow: var(--shadow-primary);
        }
        
        .copy-notification.show {
            transform: translateX(0);
        }
        
        /* No results */
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-text);
        }
        
        .no-results i {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-results h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .controls {
                padding: 20px 15px;
            }
            
            .icon-container {
                padding: 20px 15px;
            }
            
            .icon-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 15px;
            }
            
            .category-filters {
                justify-content: center;
            }
            
            .category-btn {
                padding: 10px 15px;
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 480px) {
            .icon-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 10px;
            }
            
            .icon-item {
                padding: 15px 10px;
            }
            
            .icon-display {
                font-size: 1.5rem;
            }
            
            .icon-name {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="icon-header">
        <div class="header-content">
            <div class="header-title">
                <div class="header-icon">
                    <i class="fas fa-icons"></i>
                </div>
                <h1>Multi-Icon Browser</h1>
            </div>
        </div>
    </header>
    
    <!-- Loading Animation - Using centralized loader widget instead -->
    
    <!-- Search and Filters -->
    <section class="controls">
        <div class="controls-content">
            <!-- Search Box - Moved to Top -->
            <div class="search-box">
                <input type="text" 
                       class="search-input" 
                       placeholder="Search icons (e.g., home, user, settings)..." 
                       value="<?php echo htmlspecialchars($search); ?>"
                       autocomplete="off">
                <i class="fas fa-search search-icon"></i>
            </div>
            
            <!-- Icon Set Controls -->
            <div class="icon-set-controls">
                <div class="selector-group">
                    <div class="slider-selector" id="iconSetSlider">
                        <?php $setIndex = 0; foreach ($iconSets as $setKey => $setData): ?>
                        <div class="selector-option <?php echo $iconSet === $setKey ? 'active' : ''; ?>" 
                             data-value="<?php echo $setKey; ?>" 
                             onclick="changeIconSet('<?php echo $setKey; ?>')">
                            <?php echo $setData['name']; ?>
                        </div>
                        <?php $setIndex++; endforeach; ?>
                    </div>
                </div>
                
                <?php if (isset($currentIconSet['variants'])): ?>
                <div class="selector-group">
                    <div class="slider-selector" id="variantSlider">
                        <?php foreach ($currentIconSet['variants'] as $variantOption): ?>
                        <div class="selector-option <?php echo $variant === $variantOption ? 'active' : ''; ?>" 
                             data-value="<?php echo $variantOption; ?>" 
                             onclick="changeVariant('<?php echo $variantOption; ?>')">
                            <?php echo ucfirst($variantOption); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Category Filters -->
                <div class="category-filters">
                    <?php 
                    // For icon sets with variants (like phosphor), don't show variants as categories
                    // since they're already shown in the variant selector
                    if (isset($currentIconSet['variants'])) {
                        // Only show 'ALL' for icon sets that have variant selectors
                        $categoryList = ['ALL'];
                    } else {
                        // For other icon sets (like fontawesome), show all categories
                        $categoryList = ['ALL'] + array_keys($availableIcons);
                    }
                    ?>
                    <?php foreach ($categoryList as $cat): ?>
                    <a href="?set=<?php echo urlencode($iconSet); ?>&variant=<?php echo urlencode($variant); ?>&category=<?php echo urlencode($cat); ?>&search=<?php echo urlencode($search); ?>" 
                       class="category-btn <?php echo $category === $cat ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Plugin Control Bar -->
            <div class="plugin-controls">
                <div class="plugin-group">
                    <input type="range" id="iconSize" min="16" max="128" value="32" oninput="updateIconSize(this.value)">
                    <span id="sizeDisplay">32px</span>
                </div>
                
                <div class="plugin-group">
                    <input type="color" id="iconColor" value="#00d4ff" onchange="updateIconColor(this.value)">
                    <button onclick="randomizeColor()" class="plugin-btn">🎲</button>
                </div>
                
                <div class="plugin-group">
                    <button onclick="downloadIcon()" class="plugin-btn" title="Download selected icon (D)">💾 Download</button>
                    <button onclick="randomizeSelection()" class="plugin-btn" title="Select random icon (R)">🎯 Randomize</button>
                    <button onclick="favoriteIcon()" class="plugin-btn" title="Favorite/unfavorite icon (F)">⭐ Favorite</button>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Icon Grid -->
    <main class="icon-container">
        <div class="results-info">
            Showing <span class="results-count"><?php echo count($filteredIcons); ?></span> icons
            <?php if (!empty($search)): ?>
                for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                <?php if (!empty($allIconSets)): ?>
                    <span class="search-scope">across all icon sets</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($category !== 'ALL'): ?>
                in <strong><?php echo htmlspecialchars($category); ?></strong>
            <?php endif; ?>
            <button onclick="showHelp()" class="plugin-btn" title="Show keyboard shortcuts" style="margin-left: auto;">❓ Help & Shortcuts</button>
        </div>
        
        <?php if (empty($filteredIcons)): ?>
        <div class="no-results">
            <i class="fas fa-search"></i>
            <h3>No icons found</h3>
            <p>Try adjusting your search terms or category filter.</p>
        </div>
        <?php else: ?>
        <div class="icon-grid">
            <?php foreach ($filteredIcons as $name => $iconData): ?>
            <?php 
                $copyText = '';
                $iconDisplay = '';
                
                if ($iconData['type'] === 'fontawesome') {
                    $copyText = $iconData['class'];
                    $iconDisplay = '<i class="' . htmlspecialchars($iconData['class']) . '"></i>';
                } else {
                    // SVG icons
                    if (isset($iconData['data']['fullPath'])) {
                        $svgContent = getSVGContent($iconData['data']['path']);
                        $copyText = $iconData['data']['path'];
                        $iconDisplay = $svgContent;
                    } else {
                        $copyText = 'Icon not found';
                        $iconDisplay = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>';
                    }
                }
            ?>
            <div class="icon-item" onclick="copyIconClass('<?php echo htmlspecialchars($copyText); ?>')" data-icon-type="<?php echo $iconData['type']; ?>">
                <div class="icon-display">
                    <?php echo $iconDisplay; ?>
                </div>
                <div class="icon-name"><?php echo htmlspecialchars($iconData['name']); ?></div>
                <div class="icon-class"><?php echo htmlspecialchars($copyText); ?></div>
                <?php if (!empty($search) && isset($iconData['set'])): ?>
                <div class="icon-set-badge"><?php echo htmlspecialchars($iconData['set']); ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
    
    <!-- Copy Notification -->
    <div class="copy-notification" id="copyNotification">
        <i class="fas fa-check"></i>
        Icon class copied to clipboard!
    </div>
    
    <script>
        // Change icon set
        function changeIconSet(setKey) {
            showLoadingAnimation();
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('set', setKey);
            currentUrl.searchParams.set('variant', 'regular'); // Reset to default variant
            currentUrl.searchParams.set('category', 'ALL'); // Reset category
            window.location.href = currentUrl.toString();
        }
        
        // Change variant
        function changeVariant(variantKey) {
            showLoadingAnimation();
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('variant', variantKey);
            currentUrl.searchParams.set('category', 'ALL'); // Reset category
            window.location.href = currentUrl.toString();
        }
        
        // Plugin Control Functions
        function updateIconSize(size) {
            document.getElementById('sizeDisplay').textContent = size + 'px';
            const icons = document.querySelectorAll('.icon-display svg, .icon-display i');
            icons.forEach(icon => {
                if (icon.tagName === 'SVG') {
                    icon.style.width = size + 'px';
                    icon.style.height = size + 'px';
                } else {
                    icon.style.fontSize = size + 'px';
                }
            });
        }
        
        function updateIconColor(color) {
            const icons = document.querySelectorAll('.icon-display');
            icons.forEach(icon => {
                icon.style.color = color;
            });
        }
        
        function randomizeColor() {
            const colors = ['#00d4ff', '#7c3aed', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#06b6d4', '#84cc16'];
            const randomColor = colors[Math.floor(Math.random() * colors.length)];
            document.getElementById('iconColor').value = randomColor;
            updateIconColor(randomColor);
        }
        
        function downloadIcon() {
            const selectedIcon = document.querySelector('.icon-item.selected .icon-display svg') || 
                                document.querySelector('.icon-item:hover .icon-display svg');
            if (selectedIcon) {
                const svgData = selectedIcon.outerHTML;
                const blob = new Blob([svgData], {type: 'image/svg+xml'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                const iconName = selectedIcon.closest('.icon-item').querySelector('.icon-name').textContent;
                a.download = iconName + '.svg';
                a.click();
                URL.revokeObjectURL(url);
                showCopyNotification('Icon downloaded successfully!');
            } else {
                showCopyNotification('Please select an icon first');
            }
        }
        
        function randomizeSelection() {
            const icons = document.querySelectorAll('.icon-item');
            if (icons.length > 0) {
                // Remove previous selection
                icons.forEach(icon => icon.classList.remove('selected'));
                
                const randomIcon = icons[Math.floor(Math.random() * icons.length)];
                randomIcon.scrollIntoView({ behavior: 'smooth', block: 'center' });
                randomIcon.classList.add('selected');
                
                // Apply random color and size
                randomizeColor();
                const randomSize = Math.floor(Math.random() * 64) + 24;
                document.getElementById('iconSize').value = randomSize;
                updateIconSize(randomSize);
                
                showCopyNotification('Random icon selected!');
            }
        }
        
        function favoriteIcon() {
            const selectedIcon = document.querySelector('.icon-item.selected') || 
                                document.querySelector('.icon-item:hover');
            if (selectedIcon) {
                selectedIcon.classList.toggle('favorite');
                const isFavorite = selectedIcon.classList.contains('favorite');
                showCopyNotification(isFavorite ? 'Added to favorites!' : 'Removed from favorites!');
            } else {
                showCopyNotification('Please select an icon first');
            }
        }
        
        // Add click selection functionality
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', function(e) {
                const iconItem = e.target.closest('.icon-item');
                if (iconItem) {
                    // Remove previous selection
                    document.querySelectorAll('.icon-item.selected').forEach(item => {
                        item.classList.remove('selected');
                    });
                    // Add selection to clicked item
                    iconItem.classList.add('selected');
                }
            });
        });
        
        // Auto-submit search form on input for cross-set search
        document.querySelector('.search-input').addEventListener('input', function() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                // Show loading animation
                showLoadingAnimation();
                
                // Trigger page reload with search parameter for cross-set search
                const searchTerm = this.value.trim();
                const currentUrl = new URL(window.location);
                if (searchTerm) {
                    currentUrl.searchParams.set('search', searchTerm);
                } else {
                    currentUrl.searchParams.delete('search');
                }
                window.location.href = currentUrl.toString();
            }, 800); // Slightly longer delay for cross-set search
        });
        
        // Loading Animation Functions - Removed duplicate, using centralized loader widget functions
        
        // Loading animation auto-hide handled by centralized loader widget
        
        // Copy icon class to clipboard
        function copyIconClass(iconClass) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(iconClass).then(() => {
                    showCopyNotification();
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = iconClass;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                
                try {
                    document.execCommand('copy');
                    showCopyNotification();
                } catch (err) {
                    console.error('Failed to copy icon class:', err);
                }
                
                document.body.removeChild(textArea);
            }
        }
        
        function showHelp() {
            const helpMessage = `
🔧 Multi-Icon Browser Help

🔍 Search: / (focus search)
🎯 Random: R (select random icon)
⭐ Favorite: F (favorite/unfavorite)
💾 Download: D (download selected icon)
🎨 Color: C (randomize color)
🚪 Close: ESC

Click any icon to select it!
            `;
            alert(helpMessage);
        }
        
        // Show copy notification
        function showCopyNotification(message = 'Icon class copied to clipboard!') {
            const notification = document.getElementById('copyNotification');
            const textNode = notification.childNodes[notification.childNodes.length - 1];
            
            // Update message
            if (textNode && textNode.nodeType === Node.TEXT_NODE) {
                textNode.textContent = ' ' + message;
            } else {
                notification.appendChild(document.createTextNode(' ' + message));
            }
            
            notification.classList.add('show');
            
            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Escape key to close
            if (e.key === 'Escape') {
                history.back();
            }
            
            // Focus search on '/' key
            if (e.key === '/' && e.target.tagName !== 'INPUT') {
                e.preventDefault();
                document.querySelector('.search-input').focus();
            }
            
            // Plugin shortcuts
            if (e.target.tagName !== 'INPUT') {
                switch(e.key) {
                    case 'r':
                    case 'R':
                        e.preventDefault();
                        randomizeSelection();
                        break;
                    case 'f':
                    case 'F':
                        e.preventDefault();
                        favoriteIcon();
                        break;
                    case 'd':
                    case 'D':
                        e.preventDefault();
                        downloadIcon();
                        break;
                    case 'c':
                    case 'C':
                        e.preventDefault();
                        randomizeColor();
                        break;
                }
            }
        });
        
        // Focus search input on page load
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('.search-input');
            if (searchInput && !searchInput.value) {
                searchInput.focus();
            }
        });
    </script>

<!-- Footer.php include removed for standalone icon browser -->
