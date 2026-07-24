<?php
/**
 * Icon Widget Integration Test
 * Fixed version with proper error handling
 */

echo "<h2>Icon Widget Integration Test</h2>";

// Test 1: Framework Loading
echo "<h3>1. CUE Framework Status</h3>";
try {
    $cuePath = '/home/onemeta/public_html/.cue/cue.php';
    if (file_exists($cuePath)) {
        require_once $cuePath;
        if (function_exists('getDataPath')) {
            echo "✅ CUE Framework loaded successfully<br>";
            echo "📁 Data Path: " . getDataPath() . "<br>";
            echo "📁 Templates Path: " . getTemplatesPath() . "<br>";
            echo "🌐 Base URL: " . getBaseUrl() . "<br>";
        } else {
            echo "❌ CUE Framework functions not available<br>";
        }
    } else {
        echo "❌ CUE Framework file not found at: $cuePath<br>";
    }
} catch (Exception $e) {
    echo "❌ Error loading CUE Framework: " . $e->getMessage() . "<br>";
}

// Test 2: Widget Configuration
echo "<h3>2. Widget Configuration</h3>";
$configPath = '/home/onemeta/.data/widgets/config.json';
if (file_exists($configPath)) {
    $config = json_decode(file_get_contents($configPath), true);
    if ($config && isset($config['K::WidgetUI::Configuration'])) {
        $latestConfig = reset($config['K::WidgetUI::Configuration']);
        echo "✅ Widget config loaded<br>";
        echo "🎨 Icon default set: " . ($latestConfig['wgt_icon_default_set'] ?? 'not set') . "<br>";
        echo "🌈 Icon color: " . ($latestConfig['wgt_icon_color'] ?? 'not set') . "<br>";
        echo "✨ Icon hover color: " . ($latestConfig['wgt_icon_hover_color'] ?? 'not set') . "<br>";
        echo "📏 Icon size: " . ($latestConfig['wgt_icon_size'] ?? 'not set') . "<br>";
    } else {
        echo "❌ Config parsing failed<br>";
    }
} else {
    echo "❌ Config file not found<br>";
}

// Test 3: Icon Assets
echo "<h3>3. Icon Assets Status</h3>";
// Prefer CUE helpers; fallback to absolute paths used previously
$templatesBase = function_exists('getTemplatesPath') ? rtrim(getTemplatesPath(), DIRECTORY_SEPARATOR) : '/home/onemeta/public_html/templates';
$assetsPath = $templatesBase . '/assets/icons';
echo "📂 Assets path: $assetsPath<br>";

// Resolve Font Awesome CSS (try FA7 location first, then FA6 fallback)
$faCssPrimary = $assetsPath . '/fontawesome/css/all.min.css';
$faCssFallback = $templatesBase . '/assets/fonts/all.min.css';
$faCssPath = file_exists($faCssPrimary) ? $faCssPrimary : (file_exists($faCssFallback) ? $faCssFallback : $assetsPath . '/all.min.css');

$iconSets = [
    'fontawesome' => $faCssPath,
    'feather' => $assetsPath . '/feather',
    'iconoir' => $assetsPath . '/iconoir/icons/regular',
    'phosphor' => $assetsPath . '/phosphor/SVGs/regular'
];

foreach ($iconSets as $setName => $setPath) {
    if (file_exists($setPath)) {
        if (is_dir($setPath)) {
            $count = count(glob($setPath . '/*.svg'));
            echo "✅ $setName: $count SVG files<br>";
        } else {
            echo "✅ $setName: CSS file exists<br>";
        }
    } else {
        echo "❌ $setName: Not found at $setPath<br>";
    }
}

// Load Font Awesome CSS on the page to render font icons inside this test
if (file_exists($faCssPath)) {
    $faUrl = '';
    if (function_exists('getTemplateURL')) {
        // Map filesystem path to web URL
        if (strpos($faCssPath, '/fontawesome/css/all.min.css') !== false) {
            $faUrl = getTemplateURL('assets/icons/fontawesome/css/all.min.css');
        } elseif (strpos($faCssPath, '/assets/fonts/all.min.css') !== false) {
            $faUrl = getTemplateURL('assets/fonts/all.min.css');
        }
    } else {
        // Fallback relative URLs
        if (strpos($faCssPath, '/fontawesome/css/all.min.css') !== false) {
            $faUrl = '/templates/assets/icons/fontawesome/css/all.min.css';
        } elseif (strpos($faCssPath, '/assets/fonts/all.min.css') !== false) {
            $faUrl = '/templates/assets/fonts/all.min.css';
        }
    }
    if ($faUrl) {
        echo '<link rel="stylesheet" href="' . htmlspecialchars($faUrl, ENT_QUOTES) . '">';
        echo '<div style="margin:10px 0;color:#00ffff"><i class="fas fa-check"></i> Font Awesome CSS loaded: ' . htmlspecialchars($faUrl, ENT_QUOTES) . '</div>';
    }
}

// Test 4: Widget Integration
echo "<h3>4. Live Widget Test</h3>";
echo '<div style="border: 2px solid #00ffff; border-radius: 8px; padding: 10px; margin: 10px 0;">';
echo '<h4>Icon Widget (Live)</h4>';
echo '<iframe src="icon-widget.php" width="480" height="450" style="border: 1px solid #374151; border-radius: 6px;"></iframe>';
echo '</div>';

// Test 5: API Endpoint Test
echo "<h3>5. JSON API Test</h3>";
echo "Testing API endpoints:<br>";
$apiTests = [
    'FontAwesome' => 'icon-widget.php?action=get_icons&set=fontawesome&limit=5',
    'Phosphor' => 'icon-widget.php?action=get_icons&set=phosphor&limit=5',
    'Feather' => 'icon-widget.php?action=get_icons&set=feather&limit=5'
];

foreach ($apiTests as $testName => $url) {
    $response = file_get_contents("https://metahumans.one/templates/widgets/icons/$url");
    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['success']) {
            echo "✅ $testName: {$data['total']} icons available<br>";
        } else {
            echo "❌ $testName: API response invalid<br>";
        }
    } else {
        echo "❌ $testName: API request failed<br>";
    }
}

echo "<hr>";
echo "<p><strong>Integration Test Complete</strong> - " . date('Y-m-d H:i:s') . "</p>";
