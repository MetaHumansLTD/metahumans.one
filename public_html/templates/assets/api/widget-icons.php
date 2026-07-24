<?php
/**
 * Widget Icons API - Optimized Icon Data Provider
 *
 * Fast, lightweight API for icon widget consumption
 * Supports FontAwesome, Feather, Iconoir, and Phosphor icon sets
 *
 * @requires CUE Framework 94.9.8+
 */

// Load CUE Framework from API location  
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600'); // 1 hour cache

class WidgetIconsAPI {
    private $iconsPath;
    private $maxIcons = 200;
    
    public function __construct() {
        $this->iconsPath = getTemplatesPath() . '/assets/icons';
    }
    
    public function getIcons(string $set = 'fontawesome', int $limit = 100, string $search = ''): array {
        $limit = min($limit, $this->maxIcons);
        
        switch ($set) {
            case 'fontawesome':
                return $this->getFontAwesomeIcons($limit, $search);
            case 'feather':
                return $this->getFeatherIcons($limit, $search);
            case 'iconoir':
                return $this->getIconoirIcons($limit, $search);
            case 'phosphor':
                return $this->getPhosphorIcons($limit, $search);
            default:
                throw new Exception("Unsupported icon set: $set");
        }
    }
    
    private function getFontAwesomeIcons(int $limit, string $search): array {
        // FontAwesome icon list (subset for performance)
        $fontAwesomeIcons = [
            'home', 'user', 'search', 'heart', 'star', 'envelope', 'phone', 'calendar',
            'clock', 'map-marker-alt', 'edit', 'trash', 'save', 'download', 'upload',
            'print', 'share', 'copy', 'cut', 'paste', 'undo', 'redo', 'play', 'pause',
            'stop', 'volume-up', 'volume-down', 'volume-mute', 'camera', 'image',
            'video', 'music', 'file', 'folder', 'archive', 'bookmark', 'tag', 'tags',
            'shopping-cart', 'credit-card', 'money-bill', 'gift', 'trophy', 'award',
            'medal', 'flag', 'bell', 'comment', 'comments', 'thumbs-up', 'thumbs-down',
            'fire', 'bolt', 'magic', 'wrench', 'cog', 'tools', 'hammer', 'screwdriver',
            'paint-brush', 'palette', 'eye', 'eye-slash', 'lock', 'unlock', 'key',
            'shield-alt', 'user-shield', 'fingerprint', 'id-card', 'passport',
            'plus', 'minus', 'times', 'check', 'exclamation', 'question', 'info',
            'warning', 'ban', 'circle', 'square', 'triangle', 'diamond', 'gem',
            'arrow-up', 'arrow-down', 'arrow-left', 'arrow-right', 'chevron-up',
            'chevron-down', 'chevron-left', 'chevron-right', 'angle-up', 'angle-down',
            'wifi', 'signal', 'battery-full', 'battery-half', 'battery-empty',
            'bluetooth', 'usb', 'ethernet', 'mobile-alt', 'tablet-alt', 'laptop',
            'desktop', 'tv', 'headphones', 'microphone', 'keyboard', 'mouse'
        ];
        
        $icons = [];
        foreach ($fontAwesomeIcons as $iconName) {
            if (!empty($search) && strpos($iconName, $search) === false) {
                continue;
            }
            
            $icons[] = [
                'name' => $iconName,
                'class' => "fas fa-$iconName",
                'type' => 'font',
                'set' => 'fontawesome',
                'category' => $this->categorizeIcon($iconName)
            ];
            
            if (count($icons) >= $limit) break;
        }
        
        return $icons;
    }
    
    private function getFeatherIcons(int $limit, string $search): array {
        $featherPath = $this->iconsPath . '/feather';
        if (!is_dir($featherPath)) {
            return [];
        }
        
        $icons = [];
        $files = glob($featherPath . '/*.svg');
        
        foreach ($files as $file) {
            $iconName = pathinfo($file, PATHINFO_FILENAME);
            
            if (!empty($search) && strpos($iconName, $search) === false) {
                continue;
            }
            
            $svg = file_get_contents($file);
            if ($svg) {
                $icons[] = [
                    'name' => $iconName,
                    'svg' => $svg,
                    'type' => 'svg',
                    'set' => 'feather',
                    'category' => $this->categorizeIcon($iconName)
                ];
            }
            
            if (count($icons) >= $limit) break;
        }
        
        return $icons;
    }
    
    private function getIconoirIcons(int $limit, string $search): array {
        $iconoirPath = $this->iconsPath . '/iconoir';
        if (!is_dir($iconoirPath)) {
            return [];
        }
        
        $icons = [];
        
        // Check both regular and solid variants
        $variants = ['regular', 'solid'];
        foreach ($variants as $variant) {
            $variantPath = $iconoirPath . '/' . $variant;
            if (!is_dir($variantPath)) continue;
            
            $files = glob($variantPath . '/*.svg');
            foreach ($files as $file) {
                $iconName = pathinfo($file, PATHINFO_FILENAME);
                
                if (!empty($search) && strpos($iconName, $search) === false) {
                    continue;
                }
                
                $svg = file_get_contents($file);
                if ($svg) {
                    $icons[] = [
                        'name' => $iconName,
                        'svg' => $svg,
                        'type' => 'svg',
                        'set' => 'iconoir',
                        'variant' => $variant,
                        'category' => $this->categorizeIcon($iconName)
                    ];
                }
                
                if (count($icons) >= $limit) break 2;
            }
        }
        
        return $icons;
    }
    
    private function getPhosphorIcons(int $limit, string $search): array {
        $phosphorPath = $this->iconsPath . '/phosphor/SVGs';
        if (!is_dir($phosphorPath)) {
            return [];
        }
        
        $icons = [];
        $variants = ['regular', 'bold', 'light', 'thin', 'fill', 'duotone'];
        
        foreach ($variants as $variant) {
            $variantPath = $phosphorPath . '/' . $variant;
            if (!is_dir($variantPath)) continue;
            
            $files = glob($variantPath . '/*.svg');
            foreach ($files as $file) {
                $iconName = pathinfo($file, PATHINFO_FILENAME);
                
                if (!empty($search) && strpos($iconName, $search) === false) {
                    continue;
                }
                
                $svg = file_get_contents($file);
                if ($svg) {
                    $icons[] = [
                        'name' => $iconName,
                        'svg' => $svg,
                        'type' => 'svg',
                        'set' => 'phosphor',
                        'variant' => $variant,
                        'category' => $this->categorizeIcon($iconName)
                    ];
                }
                
                if (count($icons) >= $limit) break 2;
            }
        }
        
        return $icons;
    }
    
    private function categorizeIcon(string $iconName): string {
        $categories = [
            'interface' => ['home', 'menu', 'settings', 'search', 'close', 'back', 'forward'],
            'communication' => ['email', 'phone', 'chat', 'message', 'mail', 'envelope'],
            'media' => ['play', 'pause', 'stop', 'video', 'music', 'image', 'camera'],
            'files' => ['file', 'folder', 'document', 'archive', 'download', 'upload'],
            'editing' => ['edit', 'delete', 'copy', 'paste', 'cut', 'save', 'undo'],
            'navigation' => ['arrow', 'chevron', 'angle', 'direction', 'up', 'down'],
            'social' => ['heart', 'star', 'share', 'like', 'favorite', 'bookmark'],
            'business' => ['money', 'card', 'cart', 'shopping', 'business', 'office']
        ];
        
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($iconName, $keyword) !== false) {
                    return $category;
                }
            }
        }
        
        return 'general';
    }
    
    public function getIconSets(): array {
        $sets = [];
        
        // FontAwesome
        if (file_exists($this->iconsPath . '/fontawesome.css')) {
            $sets['fontawesome'] = [
                'name' => 'Font Awesome',
                'type' => 'font',
                'count' => 100, // Approximate
                'variants' => ['solid', 'regular', 'light']
            ];
        }
        
        // Feather
        if (is_dir($this->iconsPath . '/feather')) {
            $count = count(glob($this->iconsPath . '/feather/*.svg'));
            $sets['feather'] = [
                'name' => 'Feather Icons',
                'type' => 'svg',
                'count' => $count,
                'variants' => ['default']
            ];
        }
        
        // Iconoir
        if (is_dir($this->iconsPath . '/iconoir')) {
            $regularCount = is_dir($this->iconsPath . '/iconoir/regular') ? 
                count(glob($this->iconsPath . '/iconoir/regular/*.svg')) : 0;
            $solidCount = is_dir($this->iconsPath . '/iconoir/solid') ? 
                count(glob($this->iconsPath . '/iconoir/solid/*.svg')) : 0;
            
            $sets['iconoir'] = [
                'name' => 'Iconoir',
                'type' => 'svg',
                'count' => $regularCount + $solidCount,
                'variants' => ['regular', 'solid']
            ];
        }
        
        // Phosphor
        if (is_dir($this->iconsPath . '/phosphor/SVGs')) {
            $variants = ['regular', 'bold', 'light', 'thin', 'fill', 'duotone'];
            $totalCount = 0;
            $availableVariants = [];
            
            foreach ($variants as $variant) {
                $variantPath = $this->iconsPath . '/phosphor/SVGs/' . $variant;
                if (is_dir($variantPath)) {
                    $count = count(glob($variantPath . '/*.svg'));
                    $totalCount += $count;
                    $availableVariants[] = $variant;
                }
            }
            
            $sets['phosphor'] = [
                'name' => 'Phosphor Icons',
                'type' => 'svg',
                'count' => $totalCount,
                'variants' => $availableVariants
            ];
        }
        
        return $sets;
    }
}

// Handle API requests
try {
    $api = new WidgetIconsAPI();
    
    if (isset($_GET['action']) && $_GET['action'] === 'sets') {
        $result = [
            'success' => true,
            'sets' => $api->getIconSets()
        ];
    } else {
        $set = $_GET['set'] ?? 'fontawesome';
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $search = $_GET['search'] ?? '';
        
        $icons = $api->getIcons($set, $limit, $search);
        
        $result = [
            'success' => true,
            'set' => $set,
            'count' => count($icons),
            'icons' => $icons,
            'search' => $search
        ];
    }
    
} catch (Exception $e) {
    $result = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    http_response_code(400);
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
