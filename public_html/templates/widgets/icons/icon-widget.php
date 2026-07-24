<?php
$mh_is_thesvg_svg = (isset($_GET['thesvg_svg']) && (string)$_GET['thesvg_svg'] !== '') || (isset($_GET['action']) && $_GET['action'] === 'thesvg_svg');
if ($mh_is_thesvg_svg) {
    while (function_exists('ob_get_level') && ob_get_level()) { @ob_end_clean(); }
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    $slug = preg_replace('/[^a-z0-9\\-]/i', '', (string)($_GET['slug'] ?? ''));
    $variant = preg_replace('/[^a-z0-9]/i', '', (string)($_GET['variant'] ?? 'default'));
    if ($slug === '') { http_response_code(404); exit; }
    if ($variant === '') { $variant = 'default'; }
    if (!headers_sent()) {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
    }
    $u1 = 'https://thesvg.org/icons/' . $slug . '/' . $variant . '.svg';
    $u2 = 'https://cdn.jsdelivr.net/gh/glincker/thesvg@main/public/icons/' . $slug . '/' . $variant . '.svg';
    $ctx = stream_context_create(['http' => ['timeout' => 2.5, 'header' => "User-Agent: MetaHumans\r\n"]]);
    $svg = @file_get_contents($u1, false, $ctx);
    if (!is_string($svg) || trim($svg) === '') {
        $svg = @file_get_contents($u2, false, $ctx);
    }
    if (!is_string($svg) || trim($svg) === '') {
        $local = __DIR__ . '/../../assets/icons/phosphor/SVGs/regular/' . $slug . '.svg';
        if (is_file($local)) {
            $svg = (string)file_get_contents($local);
            $svg = str_replace('currentColor', '#ffffff', $svg);
        }
    }
    if (!is_string($svg) || trim($svg) === '') { http_response_code(404); exit; }
    echo $svg;
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_icons' && (string)($_GET['set'] ?? '') === 'thesvg') {
    while (function_exists('ob_get_level') && ob_get_level()) { @ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
    }
    $limit = (int)($_GET['limit'] ?? 200);
    $page = (int)($_GET['page'] ?? 0);
    $search = trim((string)($_GET['search'] ?? ''));
    $limit = max(1, min(400, $limit));
    $page = max(0, $page);

    $registryUrl = 'https://thesvg.org/api/registry.json';
    $ctx = stream_context_create(['http' => ['timeout' => 4.0, 'header' => "User-Agent: MetaHumans\r\n"]]);
    $body = @file_get_contents($registryUrl, false, $ctx);
    if (!is_string($body) || trim($body) === '') {
        echo json_encode(['success' => false, 'error' => 'registry_fetch_failed']);
        exit;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        echo json_encode(['success' => false, 'error' => 'registry_invalid_json']);
        exit;
    }
    $list = [];
    if (isset($decoded['icons']) && is_array($decoded['icons'])) $list = $decoded['icons'];
    else $list = $decoded;

    $icons = [];
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $slug = isset($row['slug']) ? (string)$row['slug'] : '';
        if ($slug === '') continue;
        $title = isset($row['title']) ? (string)$row['title'] : $slug;
        $aliases = (isset($row['aliases']) && is_array($row['aliases'])) ? $row['aliases'] : [];
        if ($search !== '') {
            $ok = stripos($slug, $search) !== false || stripos($title, $search) !== false;
            if (!$ok) {
                foreach ($aliases as $a) {
                    if (is_string($a) && stripos($a, $search) !== false) { $ok = true; break; }
                }
            }
            if (!$ok) continue;
        }
        $variants = (isset($row['variants']) && is_array($row['variants'])) ? $row['variants'] : [];
        $variant = 'default';
        if (in_array('light', $variants, true)) $variant = 'light';
        elseif (in_array('mono', $variants, true)) $variant = 'mono';
        $icons[] = [
            'name' => $slug,
            'type' => 'thesvg',
            'set' => 'thesvg',
            'variant' => $variant,
            'value' => 'thesvg:' . $slug . ':' . $variant,
            'title' => $title,
        ];
    }
    usort($icons, function($a, $b) {
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $total = count($icons);
    $start = $page * $limit;
    $slice = array_slice($icons, $start, $limit);
    echo json_encode([
        'success' => true,
        'icons' => $slice,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'set' => 'thesvg',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';

// Check if this is an API/AJAX request or Picker mode
$isApiAction = (isset($_GET['action']) && in_array($_GET['action'], ['get_icons','get_svg','thesvg_svg'], true)) || 
               (isset($_GET['mode']) && $_GET['mode'] === 'picker');

if ($isApiAction) {
    cue_disableAutoInjection();
    if (function_exists('ob_get_level')) { while (ob_get_level()) { ob_end_clean(); } }
}

if (!function_exists('loadWidgetConfig')) {
    function loadWidgetConfig() {
        $paths = cue_autoload('paths');
        $configPath = $paths->getSecureFilePath('widgets/config.json');
        if ($configPath && $paths->validateSecurePath($configPath, getDataPath()) && file_exists($configPath)) {
            $rawConfig = json_decode(file_get_contents($configPath), true);
            if (isset($rawConfig['K::WidgetUI::Configuration'])) {
                $latestConfig = reset($rawConfig['K::WidgetUI::Configuration']);
                $allowedSets = ['phosphor', 'thesvg'];
                $iconSets = array_values(array_filter(array_map('trim', explode(',', (string)($latestConfig['wgt_icon_sets'] ?? 'phosphor,thesvg')))));
                $iconSets = array_values(array_intersect($iconSets, $allowedSets));
                $iconSets = array_values(array_unique(array_merge(['phosphor', 'thesvg'], $iconSets)));
                $defaultSet = (string)($latestConfig['wgt_icon_default_set'] ?? 'phosphor');
                if (!in_array($defaultSet, $allowedSets, true)) { $defaultSet = 'phosphor'; }
                return [
                    'icon_sets' => $iconSets,
                    'default_set' => $defaultSet,
                    'icon_size' => $latestConfig['wgt_icon_size'] ?? 24,
                    'grid_columns' => $latestConfig['wgt_icon_grid_columns'] ?? 8,
                    'icon_height' => $latestConfig['wgt_icon_height'] ?? '300px',
                    'icon_color' => $latestConfig['wgt_icon_color'] ?? '#00ffff',
                    'icon_hover_color' => $latestConfig['wgt_icon_hover_color'] ?? '#ffffff',
                    'mult_realms' => (float)($latestConfig['wgt_icon_size_multiplier_realms'] ?? 1.0),
                    'mult_menus' => (float)($latestConfig['wgt_icon_size_multiplier_menus'] ?? 1.0),
                    'mult_submenus' => (float)($latestConfig['wgt_icon_size_multiplier_submenus'] ?? 0.85),
                ];
            }
        }
        return [
            'icon_sets' => ['phosphor','thesvg'], 
            'default_set' => 'phosphor', 
            'icon_size' => 24, 
            'grid_columns' => 8, 
            'icon_height' => '300px', 
            'icon_color' => '#00ffff', 
            'icon_hover_color' => '#ffffff',
            'mult_realms' => 1.0,
            'mult_menus' => 1.0,
            'mult_submenus' => 0.85
        ];
    }
}
// Check if this is picker mode
$isPickerMode = isset($_GET["mode"]) && $_GET["mode"] === "picker";
$isApiAction = isset($_GET['action']) && in_array($_GET['action'], ['get_icons','get_svg','thesvg_svg'], true);


// Check if this is an AJAX request for JSON data
if (isset($_GET['action']) && $_GET['action'] === 'get_icons') {
    if (function_exists('ob_get_level')) { while (ob_get_level()) { ob_end_clean(); } }
    header('Content-Type: application/json');
    
    $iconSet = $_GET['set'] ?? 'phosphor';
    $limit = (int)($_GET['limit'] ?? 200);
    $page = (int)($_GET['page'] ?? 0);
    $search = $_GET['search'] ?? '';
    $icons = [];
    
    $paths = cue_autoload('paths');
    $iconsBase = getTemplatesPath() . '/assets/icons';
    
        switch ($iconSet) {
        case 'fontawesome':
            $brandsCss = $paths->validateSecurePath($iconsBase . '/fontawesome/css/brands.min.css', $iconsBase);
            $regularCss = $paths->validateSecurePath($iconsBase . '/fontawesome/css/regular.min.css', $iconsBase);
            $solidCss   = $paths->validateSecurePath($iconsBase . '/fontawesome/css/solid.min.css', $iconsBase);
            $brands = $regular = $solid = [];
            if ($brandsCss && file_exists($brandsCss)) {
                $b = file_get_contents($brandsCss);
                if (preg_match_all('/\.fa-([a-zA-Z0-9-]+)(?=[^{]*\{[^}]*--fa:)/s', $b, $m)) {
                    foreach ($m[1] as $n) { $brands[$n] = true; }
                }
            }
            if ($regularCss && file_exists($regularCss)) {
                $r = file_get_contents($regularCss);
                if (preg_match_all('/\.fa-([a-zA-Z0-9-]+)(?=[^{]*\{[^}]*--fa:)/s', $r, $m)) {
                    foreach ($m[1] as $n) { $regular[$n] = true; }
                }
            }
            if ($solidCss && file_exists($solidCss)) {
                $s = file_get_contents($solidCss);
                if (preg_match_all('/\.fa-([a-zA-Z0-9-]+)(?=[^{]*\{[^}]*--fa:)/s', $s, $m)) {
                    foreach ($m[1] as $n) { $solid[$n] = true; }
                }
            }
            $custom = ['metahumans' => true];
            $union = $brands + $regular + $solid + $custom;
            $iconNames = array_keys($union);

            foreach ($iconNames as $iconName) {
                if ($search && stripos($iconName, $search) === false) continue;
                if ($iconName === 'metahumans') {
                    $icons[] = [
                        'name' => $iconName,
                        'class' => 'fa fa-metahumans',
                        'type' => 'fontawesome',
                        'set' => 'fontawesome'
                    ];
                    continue;
                }
                $style = isset($brands[$iconName]) ? 'brands' : (isset($regular[$iconName]) ? 'regular' : 'solid');
                $icons[] = [
                    'name' => $iconName,
                    'class' => 'fa-' . $style . ' fa-' . $iconName,
                    'type' => 'fontawesome',
                    'set' => 'fontawesome'
                ];
            }
            break;
            
        case 'feather':
            $featherPath = $paths->validateSecurePath($iconsBase . '/feather', $iconsBase);
            if ($featherPath && is_dir($featherPath)) {
                $svgFiles = glob($featherPath . '/*.svg');
                foreach ($svgFiles as $svgFile) {
                    $iconName = basename($svgFile, '.svg');
                    if ($search && stripos($iconName, $search) === false) continue;
                    
                    $svgContent = file_get_contents($svgFile);
                    // Ensure SVG has proper attributes
            $svgContent = preg_replace('/<svg[^>]*>/', '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" preserveAspectRatio="xMidYMid meet">', $svgContent);
                    
                    $icons[] = [
                        'name' => $iconName,
                        'svg' => $svgContent,
                        'type' => 'svg',
                        'set' => 'feather'
                    ];
                }
            }
            break;
            
        case 'iconoir':
            $iconoirPath = $paths->validateSecurePath($iconsBase . '/iconoir/icons/regular', $iconsBase);
            if ($iconoirPath && is_dir($iconoirPath)) {
                $svgFiles = glob($iconoirPath . '/*.svg');
                foreach ($svgFiles as $svgFile) {
                    $iconName = basename($svgFile, '.svg');
                    if ($search && stripos($iconName, $search) === false) continue;
                    
                    $svgContent = file_get_contents($svgFile);
                    // Ensure SVG has proper attributes
            $svgContent = preg_replace('/<svg[^>]*>/', '<svg width="1em" height="1em" viewBox="0 0 24 24" preserveAspectRatio="xMidYMid meet">', $svgContent);
                    
                    $icons[] = [
                        'name' => $iconName,
                        'svg' => $svgContent,
                        'type' => 'svg',
                        'set' => 'iconoir'
                    ];
                }
            }
            break;
            
        case 'phosphor':
            $phosphorPath = $paths->validateSecurePath($iconsBase . '/phosphor/SVGs/regular', $iconsBase);
            if ($phosphorPath && is_dir($phosphorPath)) {
                $svgFiles = glob($phosphorPath . '/*.svg');
                foreach ($svgFiles as $svgFile) {
                    $iconName = basename($svgFile, '.svg');
                    if ($search && stripos($iconName, $search) === false) continue;
                    $icons[] = [
                        'name' => $iconName,
                        'class' => 'ph ph-' . $iconName,
                        'type' => 'phosphor',
                        'set' => 'phosphor'
                    ];
                }
            }
            break;

        case 'thesvg':
            $registryUrl = 'https://thesvg.org/api/registry.json';
            $body = @file_get_contents($registryUrl);
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                $list = [];
                if (is_array($decoded) && isset($decoded['icons']) && is_array($decoded['icons'])) {
                    $list = $decoded['icons'];
                } elseif (is_array($decoded)) {
                    $list = $decoded;
                }
                foreach ($list as $row) {
                    if (!is_array($row)) continue;
                    $slug = isset($row['slug']) ? (string)$row['slug'] : '';
                    if ($slug === '') continue;
                    $title = isset($row['title']) ? (string)$row['title'] : $slug;
                    $aliases = (isset($row['aliases']) && is_array($row['aliases'])) ? $row['aliases'] : [];
                    if ($search) {
                        $ok = stripos($slug, $search) !== false || stripos($title, $search) !== false;
                        if (!$ok) {
                            foreach ($aliases as $a) {
                                if (is_string($a) && stripos($a, $search) !== false) { $ok = true; break; }
                            }
                        }
                        if (!$ok) continue;
                    }
                    $variants = (isset($row['variants']) && is_array($row['variants'])) ? $row['variants'] : [];
                    $variant = 'default';
                    if (in_array('light', $variants, true)) $variant = 'light';
                    elseif (in_array('mono', $variants, true)) $variant = 'mono';
                    $icons[] = [
                        'name' => $slug,
                        'type' => 'thesvg',
                        'set' => 'thesvg',
                        'variant' => $variant,
                        'value' => 'thesvg:' . $slug . ':' . $variant,
                        'title' => $title,
                    ];
                }
            }
            break;
            
        default:
            $icons = [['name' => 'not-found', 'class' => 'fa-solid fa-question', 'type' => 'font', 'set' => $iconSet]];
    }
    
    // Sort icons by name
    usort($icons, function($a, $b) { return strcmp($a['name'], $b['name']); });
    
    // Apply pagination
    $totalIcons = count($icons);
    $startIndex = $page * $limit;
    $pagedIcons = array_slice($icons, $startIndex, $limit);
    
    echo json_encode([
        'success' => true,
        'icons' => $pagedIcons,
        'set' => $iconSet,
        'total' => $totalIcons,
        'page' => $page,
        'hasMore' => ($startIndex + count($pagedIcons)) < $totalIcons
    ]);
    exit;
}

if (!function_exists('ensureFontAwesomeInstalled')) {
function ensureFontAwesomeInstalled(object $paths, string $iconsBase): bool {
    $cssDir = $paths->validateSecurePath($iconsBase . '/fontawesome/css', $iconsBase);
    $webfontsDir = $paths->validateSecurePath($iconsBase . '/fontawesome/webfonts', $iconsBase);
    $need = false;
    if (!$cssDir || !is_dir($cssDir)) { $need = true; }
    if (!$webfontsDir || !is_dir($webfontsDir)) { $need = true; }
    $requiredCss = ['fontawesome.min.css','all.min.css','solid.min.css','regular.min.css','brands.min.css'];
    foreach ($requiredCss as $f) {
        $p = $cssDir ? $cssDir . '/' . $f : '';
        if (!$p || !file_exists($p)) { $need = true; break; }
    }
    $requiredFonts = ['fa-solid-900.woff2','fa-regular-400.woff2','fa-brands-400.woff2'];
    foreach ($requiredFonts as $f) {
        $p = $webfontsDir ? $webfontsDir . '/' . $f : '';
        if (!$p || !file_exists($p)) { $need = true; break; }
    }
    if (!$need) { return true; }
    $zipUrl = 'https://github.com/FortAwesome/Font-Awesome/releases/download/7.1.0/fontawesome-free-7.1.0-web.zip';
    $zipPath = $paths->getSecureFilePath('downloads/fontawesome-free-7.1.0-web.zip', true);
    $extractDir = $paths->getSecureFilePath('downloads/fontawesome-free-7.1.0-web', true);
    if (!$zipPath || !$extractDir) { return false; }
    if (!file_exists($zipPath)) {
        $data = @file_get_contents($zipUrl);
        if (!$data) { return false; }
        if (@file_put_contents($zipPath, $data) === false) { return false; }
    }
    $za = class_exists('ZipArchive') ? new ZipArchive() : null;
    if (!$za || $za->open($zipPath) !== true) { return false; }
    $za->extractTo($extractDir);
    $za->close();
    $srcBase = $extractDir . '/fontawesome-free-7.1.0-web';
    $srcCss = $paths->validateSecurePath($srcBase . '/css', $extractDir);
    $srcFonts = $paths->validateSecurePath($srcBase . '/webfonts', $extractDir);
    if (!$srcCss || !$srcFonts || !is_dir($srcCss) || !is_dir($srcFonts)) { return false; }
    if ($cssDir && !is_dir($cssDir)) { @mkdir($cssDir, 0775, true); }
    if ($webfontsDir && !is_dir($webfontsDir)) { @mkdir($webfontsDir, 0775, true); }
    $copyCss = ['fontawesome.min.css','all.min.css','solid.min.css','regular.min.css','brands.min.css'];
    foreach ($copyCss as $f) {
        $src = $paths->validateSecurePath($srcCss . '/' . $f, $srcCss);
        $dst = $paths->validateSecurePath($cssDir . '/' . $f, $iconsBase);
        if ($src && $dst && file_exists($src)) { @copy($src, $dst); }
    }
    $copyFonts = ['fa-solid-900.woff2','fa-regular-400.woff2','fa-brands-400.woff2'];
    foreach ($copyFonts as $f) {
        $src = $paths->validateSecurePath($srcFonts . '/' . $f, $srcFonts);
        $dst = $paths->validateSecurePath($webfontsDir . '/' . $f, $iconsBase);
        if ($src && $dst && file_exists($src)) { @copy($src, $dst); }
    }
    return true;
}
}

// JSON: get_svg endpoint placed early to avoid any prior output
if (isset($_GET['action']) && $_GET['action'] === 'get_svg') {
    if (function_exists('ob_get_level')) { while (ob_get_level()) { ob_end_clean(); } }
    header('Content-Type: application/json');
    try {
        $paths = cue_autoload('paths');
        $iconsBase = getTemplatesPath() . '/assets/icons';
        $set = preg_replace('/[^a-z0-9_\-]/i', '', $_GET['set'] ?? 'iconoir');
        $nameRaw = preg_replace('/[^a-z0-9_\-]/i', '', $_GET['name'] ?? '');
        $name = $paths->sanitizeFilename($nameRaw);
        if (!$name) { echo json_encode(['success' => false, 'error' => 'Missing name']); exit; }
        $file = null;
        if ($set === 'feather') $file = $iconsBase . '/feather/' . $name . '.svg';
        elseif ($set === 'iconoir') $file = $iconsBase . '/iconoir/icons/regular/' . $name . '.svg';
        elseif ($set === 'phosphor') $file = $iconsBase . '/phosphor/SVGs/regular/' . $name . '.svg';
        else $file = $iconsBase . '/iconoir/icons/regular/' . $name . '.svg';
        $safeFile = $file ? $paths->validateSecurePath($file, $iconsBase) : false;
        if (!$safeFile || !file_exists($safeFile)) { echo json_encode(['success' => false, 'error' => 'SVG not found']); exit; }
        $svg = file_get_contents($safeFile);
        echo json_encode(['success' => true, 'svg' => $svg]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit;
}

// Get configuration for widget display
$widgetConfig = loadWidgetConfig();

if (!function_exists('renderIconWidgetCSS')) {
    function renderIconWidgetCSS() {
        global $widgetConfig;
        if (!isset($widgetConfig)) $widgetConfig = loadWidgetConfig();
        
        echo '<link rel="stylesheet" href="/templates/assets/icons/phosphor/Fonts/regular/style.css">';
        
        // Add custom styles for sizing
        echo '<style>
            .icon-widget-preview { 
                font-size: ' . ($widgetConfig['icon_size'] ?? 24) . 'px; 
                color: ' . ($widgetConfig['icon_color'] ?? '#00ffff') . ';
            }
            .icon-widget-preview:hover {
                color: ' . ($widgetConfig['icon_hover_color'] ?? '#ffffff') . ';
            }
        </style>';
    }
}

if (!function_exists('renderIconWidgetUI')) {
function renderIconWidgetUI() {
    global $widgetConfig;
    if (!isset($widgetConfig)) $widgetConfig = loadWidgetConfig();
    ?>
    <style>
        .icon-widget .icon-grid > div i,
        .icon-widget .icon-grid > div svg {
            color: <?= $widgetConfig['icon_color'] ?? '#00ffff' ?>;
            fill: <?= $widgetConfig['icon_color'] ?? '#00ffff' ?>;
            stroke: <?= $widgetConfig['icon_color'] ?? '#00ffff' ?>;
            transition: all 0.2s ease;
            width: 1em;
            height: 1em;
            vertical-align: middle;
        }
        .icon-widget .icon-grid > div i {
            font-size: 1em !important;
            line-height: 1em;
            display: inline-block;
            width: 1em;
            height: 1em;
        }
        .icon-widget .icon-grid > div:hover i,
        .icon-widget .icon-grid > div:hover svg {
            color: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
            fill: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
            stroke: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
        }
        .icon-widget .icon-grid > div.selected i,
        .icon-widget .icon-grid > div.selected svg {
            color: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
            fill: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
            stroke: <?= $widgetConfig['icon_hover_color'] ?? '#ffffff' ?> !important;
        }
    </style>

<div class="icon-widget" data-theme="dark" style="background: #1f2937; color: #e5e7eb; padding: 15px; width: 450px; margin: 20px auto; border-radius: 8px; overflow: hidden;">
    <h3 style="margin: 0 0 15px 0; color: #00ffff;">🎨 Dynamic Icon Library Widget</h3>
    
    <div class="icon-search" style="margin-bottom: 10px;">
        <input type="text" id="iconSearch" placeholder="Search icons..." style="width: 100%; padding: 8px 12px; border: 1px solid #374151; border-radius: 6px; background: #1f2937; color: #e5e7eb; font-size: 14px; box-sizing: border-box;">
    </div>

    <div class="icon-categories" style="margin-bottom: 10px;">
        <select id="iconSetSelector" style="width: 100%; padding: 8px 12px; border: 1px solid #374151; border-radius: 6px; background: #1f2937; color: #e5e7eb; font-size: 14px;">
            <?php 
            $iconSetLabels = [
                'phosphor' => 'Phosphor (local)',
                'thesvg' => 'theSVG (6,000+ icons)'
            ];
            foreach ($widgetConfig['icon_sets'] as $setKey): 
                $selected = ($setKey === $widgetConfig['default_set']) ? 'selected' : '';
                $label = $iconSetLabels[$setKey] ?? ucfirst($setKey);
            ?>
                <option value="<?= htmlspecialchars($setKey) ?>" <?= $selected ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="icon-stats" id="iconStats" style="margin-bottom: 10px; font-size: 12px; color: #9ca3af; text-align: center;">
        Loading...
    </div>

    <div class="icon-grid" id="iconGrid" style="display: grid; grid-template-columns: repeat(<?= $widgetConfig['grid_columns'] ?>, 1fr); gap: 8px; overflow-y: auto; border: 1px solid #374151; border-radius: 6px; padding: 10px; max-height: <?= $widgetConfig['icon_height'] ?>; font-size: <?= $widgetConfig['icon_size'] ?>px;">
        <div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #e5e7eb;">Loading icons...</div>
    </div>

    <div class="load-more" style="text-align: center; margin-top: 10px;">
        <button id="loadMoreBtn" style="padding: 8px 16px; border: 1px solid #374151; border-radius: 6px; background: #1f2937; color: #00ffff; cursor: pointer; display: none;">Load More Icons</button>
    </div>

    <div style="margin-top: 10px; font-size: 12px; color: #9ca3af;">
        <strong>Usage:</strong> Click an icon to select it. Selected icon data will be logged to console. Use search to filter icons.
    </div>
    
    <!-- Debug info -->
    <div style="margin-top: 10px; font-size: 10px; color: #6b7280;">
        Config: Sets=<?= htmlspecialchars(implode(',', $widgetConfig['icon_sets'])) ?>, Default=<?= htmlspecialchars($widgetConfig['default_set']) ?>, Size=<?= $widgetConfig['icon_size'] ?>px, Cols=<?= $widgetConfig['grid_columns'] ?>, Color=<?= htmlspecialchars($widgetConfig['icon_color'] ?? '#00ffff') ?>, Hover=<?= htmlspecialchars($widgetConfig['icon_hover_color'] ?? '#ffffff') ?>
    </div>
</div>
<script>
class DynamicIconWidget {
    constructor() {
        this.widget = document.querySelector('.icon-widget');
        this.grid = document.getElementById('iconGrid');
        this.search = document.getElementById('iconSearch');
        this.selector = document.getElementById('iconSetSelector');
        this.stats = document.getElementById('iconStats');
        this.loadMoreBtn = document.getElementById('loadMoreBtn');
        this.currentSet = '<?= htmlspecialchars($widgetConfig['default_set'] ?? 'fontawesome') ?>';
        this.icons = {};
        this.selectedIcon = null;
        this.currentPage = 0;
        this.searchTimeout = null;
        this.isPickerMode = new URLSearchParams(window.location.search).get('mode') === 'picker';

        this.init();
    }

    init() {
        console.log("🎨 Dynamic Icon Widget initialized with config:", <?= json_encode($widgetConfig ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);

        if (this.search) {
            this.search.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.searchIcons(e.target.value), 300);
            });
        }

        if (this.selector) {
            this.selector.addEventListener('change', (e) => this.switchIconSet(e.target.value));
            // Set the selector to the configured default
            this.selector.value = this.currentSet;
        }

        if (this.loadMoreBtn) {
            this.loadMoreBtn.addEventListener('click', () => this.loadMoreIcons());
        }

        this.loadIcons(this.currentSet);
    }

    async loadIcons(setKey, page = 0, search = "", append = false) {
        try {
            if (!append) {
                this.currentPage = 0;
                this.grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #e5e7eb;">Loading icons...</div>';
            }

            console.log('Loading icons for set:', setKey, 'page:', page, 'search:', search);
            const currentUrl = window.location.href.split('?')[0];
            const params = new URLSearchParams({
                action: 'get_icons',
                set: setKey,
                page: page,
                limit: 200,
                search: search,
                format: 'json'
            });

            const response = await fetch(currentUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'include'
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const ct = response.headers.get('content-type') || '';
            if (ct.toLowerCase().indexOf('application/json') === -1) {
                const t = await response.text();
                throw new Error('non_json_response:' + (t || '').slice(0, 160));
            }
            const data = await response.json();
            console.log('Loaded icons:', data);

            if (data.success) {
                if (!append) {
                    this.icons[setKey] = data.icons;
                    this.renderIcons(data.icons);
                } else {
                    this.icons[setKey] = [...(this.icons[setKey] || []), ...data.icons];
        this.appendIcons(data.icons);
        }

        this.updateStats(data);
        this.loadMoreBtn.style.display = data.hasMore ? 'inline-block' : 'none';
        this.currentPage = data.page;
            } else {
                this.showError('Failed to load icons');
            }
        } catch (error) {
            console.error('Icon loading error:', error);
            this.showError('Network error: ' + error.message);
        }
    }

    renderIcons(icons) {
        console.log('Rendering', icons.length, 'icons');
        this.grid.innerHTML = '';

        if (icons.length === 0) {
            this.grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #e5e7eb;">No icons found</div>';
            return;
        }

        this.createIconElements(icons);
    }

    appendIcons(icons) {
        console.log('Appending', icons.length, 'more icons');
        this.createIconElements(icons);
    }

    loadMoreIcons() {
        const nextPage = (this.currentPage || 0) + 1;
        const q = this.search ? this.search.value : '';
        this.loadIcons(this.currentSet, nextPage, q, true);
    }

    createIconElements(icons) {
        icons.forEach(icon => {
            const item = document.createElement('div');
            item.className = 'icon-item';
            item.style.cssText = 'display: flex; align-items: center; justify-content: center; padding: 8px; border-radius: 4px; cursor: pointer; transition: all 0.2s; background: #1f2937; border: 1px solid transparent;';
            item.title = icon.title || icon.name || '';
            item.dataset.icon = icon.value || icon.class || icon.name || '';
            item.dataset.set = icon.set || '';
            item.dataset.iconClass = icon.class || icon.value || icon.name || '';

            if (icon.type === 'phosphor') {
                item.innerHTML = `<i class="${icon.class || ('ph ph-' + (icon.name || ''))}"></i>`;
            } else if (icon.type === 'thesvg') {
                const slug = String(icon.name || '').toLowerCase().replace(/[^a-z0-9\-]/g, '');
                const variant = String(icon.variant || 'default').toLowerCase().replace(/[^a-z0-9]/g, '') || 'default';
                const proxy = `${window.location.pathname}?thesvg_svg=1&slug=${encodeURIComponent(slug)}&variant=${encodeURIComponent(variant)}`;
                const proxyAttr = proxy.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                const extra = (variant === 'mono' || variant === 'dark') ? 'filter: invert(1);' : '';
                item.innerHTML = `<img src="${proxyAttr}" data-thesvg-primary="${proxyAttr}" data-thesvg-fallback="" alt="" style="width:1em;height:1em;display:block;${extra}" onerror="try{this.remove();}catch(e){}">`;
            } else if (icon.svg) {
                item.innerHTML = icon.svg;
            } else {
                item.innerHTML = `<span style="opacity:.6">?</span>`;
            }

            item.addEventListener('mouseenter', () => {
                item.style.background = 'rgba(102, 126, 234, 0.1)';
                item.style.borderColor = '#667eea';
            });

            item.addEventListener('mouseleave', () => {
                if (!item.classList.contains('selected')) {
                    item.style.background = '#1f2937';
                    item.style.borderColor = 'transparent';
                }
            });

            item.addEventListener('click', () => this.selectIcon(item, icon));
            this.grid.appendChild(item);
        });
    }

    selectIcon(element, icon) {
        // Remove previous selection
        this.grid.querySelectorAll('.selected').forEach(item => {
            item.classList.remove('selected');
            item.style.background = '#1f2937';
            item.style.color = '#e5e7eb';
        });

        // Select new icon
        element.classList.add('selected');
        element.style.background = '#667eea';
        element.style.color = 'white';
        this.selectedIcon = icon;

        console.log('✅ Selected icon:', icon);

        // If in picker mode, send selection to parent
            if (this.isPickerMode && window.parent) {
            const stored = (icon && icon.value) ? String(icon.value) :
                (icon && icon.type === 'phosphor' ? ('ph-' + String(icon.name || '')) :
                (icon && icon.type === 'thesvg' ? ('thesvg:' + String(icon.name || '') + ':' + String(icon.variant || 'default')) :
                (icon && icon.svg ? String(icon.svg) : String(icon.class || icon.name || ''))));
            console.log('📤 Sending icon to parent:', stored);
            const iconData = {
                type: 'iconSelected',
                icon: {
                    name: stored,
                    class: stored,
                    type: icon.type || (icon.svg ? "svg" : "unknown"),
                    svg: icon.svg || null,
                    set: this.currentSet || 'phosphor'
                }
            };
            parent.postMessage(iconData, '*');
        }

        return this.selectedIcon;
    }

    updateStats(data) {
        // Update statistics display if elements exist
        const statsElement = document.querySelector('.icon-stats');
        if (statsElement) {
            const totalIcons = Object.values(this.icons).reduce((sum, arr) => sum + (arr?.length || 0), 0);
            statsElement.textContent = `Loaded ${totalIcons} icons`;
        }
        
        // Log stats to console for debugging
        console.log('📊 Icon stats:', {
            page: data.page || this.currentPage,
            loaded: data.icons?.length || 0,
            hasMore: data.hasMore,
            total: Object.values(this.icons).reduce((sum, arr) => sum + (arr?.length || 0), 0)
        });
    }

    showError(message) {
        console.error('❌ Icon widget error:', message);
        
        if (this.grid) {
            this.grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #ef4444;">
                    <i class="ph ph-warning-circle" style="font-size: 48px; margin-bottom: 16px;"></i>
                    <div style="font-size: 16px; font-weight: 600; margin-bottom: 8px;">Error Loading Icons</div>
                    <div style="font-size: 14px; opacity: 0.8;">${message}</div>
                </div>
            `;
        }
        
        // Also show notification if function exists
        if (typeof showNotification === 'function') {
            showNotification(message, 'error');
        }
    }

    switchIconSet(setKey) {
        console.log('🔄 Switching to icon set:', setKey);
        this.currentSet = setKey;
        this.currentPage = 0;
        this.icons[setKey] = [];
        this.grid.innerHTML = '';
        this.loadIcons(setKey, 0, this.search ? this.search.value : '');
    }

    searchIcons(query) {
        console.log('🔍 Searching icons:', query);
        this.currentPage = 0;
        this.grid.innerHTML = '';
        this.loadIcons(this.currentSet, 0, query);
    }

    updateStats(data) {
        const showing = data.icons.length + (this.currentPage * 200);
        const total = data.total;
        this.stats.textContent = `Showing ${showing} of ${total} ${data.set} icons`;
    }

    getSelectedIcon() {
        return this.selectedIcon;
    }
}


// Initialize widget when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 DOM ready, initializing Dynamic Icon Widget');
    window.iconWidget = new DynamicIconWidget();
});

// Global functions for external access
window.getSelectedIcon = function() {
    return window.iconWidget ? window.iconWidget.getSelectedIcon() : null;
};

window.refreshIcons = function() {
    if (window.iconWidget) {
        window.iconWidget.loadIcons(window.iconWidget.currentSet);
    }
};
</script>

 

<script>
// Icon picker mode functionality
document.addEventListener('DOMContentLoaded', function() {
    const isPickerMode = new URLSearchParams(window.location.search).get('mode') === 'picker';
    
    if (isPickerMode) {
        // Add picker-specific styling
        document.body.style.padding = '0';
        document.body.style.margin = '0';
        document.body.style.background = '#111827';
        
        
        // Update icon items to show selection cursor
        const style = document.createElement('style');
        style.textContent = `
            .icon-item {
                cursor: pointer !important;
                transition: all 0.2s ease;
            }
            .icon-item:hover {
                background: rgba(0, 255, 255, 0.1) !important;
                transform: scale(1.05);
                border: 1px solid #00ffff;
            }
        `;
        document.head.appendChild(style);
    }
});
</script>
<?php
}
}

// Auto-render if accessed directly (not included)
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    // Only render if not an API action (API actions exit earlier)
    if (!isset($_GET['action'])) {
        renderIconWidgetCSS();
        renderIconWidgetUI();
    }
}
?>
