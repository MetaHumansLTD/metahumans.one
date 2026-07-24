<?php
/**
 * Global Back to Top Widget
 * Renders a back to top button with configurable settings
 */

// Load configuration from widgets/config.json
$backToTopConfig = [];
if (function_exists('cue_autoload')) {
    $paths = cue_autoload('paths');
    $configPath = $paths->getSecureFilePath('widgets/config.json');
    if ($configPath && file_exists($configPath)) {
        $jsonContent = file_get_contents($configPath);
        $configData = json_decode($jsonContent, true);
        if ($configData && isset($configData['K::WidgetUI::Configuration'])) {
            $latestConfig = reset($configData['K::WidgetUI::Configuration']);
            if ($latestConfig) {
                $backToTopConfig = $latestConfig;
            }
        }
    }
}

// Apply configuration with defaults
$enabled = $backToTopConfig['wgt_backtotop_enabled'] ?? false;
$placement = $backToTopConfig['wgt_backtotop_placement'] ?? 'bottom-right';
$size = $backToTopConfig['wgt_backtotop_size'] ?? 40;
$shape = $backToTopConfig['wgt_backtotop_shape'] ?? 'circle';
$arrowType = $backToTopConfig['wgt_backtotop_arrow_type'] ?? 'chevron';
$bgColor = $backToTopConfig['wgt_backtotop_bg_color'] ?? '#00ffff';
$arrowColor = $backToTopConfig['wgt_backtotop_arrow_color'] ?? '#000000';
$animation = $backToTopConfig['wgt_backtotop_animation'] ?? 'fade';
$scrollThreshold = $backToTopConfig['wgt_backtotop_scroll_threshold'] ?? 300;
$transitionDuration = $backToTopConfig['wgt_backtotop_transition_duration'] ?? 0.3;

// Only render if enabled
if (!$enabled) {
    return;
}

// Calculate styles
$styles = [];
$styles[] = 'position: fixed';
$styles[] = 'width: ' . $size . 'px';
$styles[] = 'height: ' . $size . 'px';
$styles[] = 'background-color: ' . $bgColor;
$styles[] = 'color: ' . $arrowColor;
$styles[] = 'display: flex';
$styles[] = 'align-items: center';
$styles[] = 'justify-content: center';
$styles[] = 'cursor: pointer';
$styles[] = 'z-index: 9999';
$styles[] = 'opacity: 0';
$styles[] = 'visibility: hidden';
$styles[] = 'transition: all ' . $transitionDuration . 's ease';

// Shape
switch ($shape) {
    case 'circle':
        $styles[] = 'border-radius: 50%';
        break;
    case 'rounded':
        $styles[] = 'border-radius: 8px';
        break;
    case 'square':
    default:
        $styles[] = 'border-radius: 0';
        break;
}

// Placement
switch ($placement) {
    case 'top-left':
        $styles[] = 'top: 20px';
        $styles[] = 'left: 20px';
        break;
    case 'top-center':
        $styles[] = 'top: 20px';
        $styles[] = 'left: 50%';
        $styles[] = 'transform: translateX(-50%)';
        break;
    case 'top-right':
        $styles[] = 'top: 20px';
        $styles[] = 'right: 20px';
        break;
    case 'bottom-left':
        $styles[] = 'bottom: 20px';
        $styles[] = 'left: 20px';
        break;
    case 'bottom-center':
        $styles[] = 'bottom: 20px';
        $styles[] = 'left: 50%';
        $styles[] = 'transform: translateX(-50%)';
        break;
    case 'bottom-right':
    default:
        $styles[] = 'bottom: 20px';
        $styles[] = 'right: 20px';
        break;
}

// Arrow SVG
$arrowSvg = '';
switch ($arrowType) {
    case 'simple':
        $arrowSvg = '<svg width="50%" height="50%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
        break;
    case 'double':
        $arrowSvg = '<svg width="50%" height="50%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 11l5-5 5 5M7 17l5-5 5 5"/></svg>';
        break;
    case 'arrow-up':
        $arrowSvg = '<svg width="50%" height="50%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>';
        break;
    case 'chevron':
    default:
        $arrowSvg = '<svg width="50%" height="50%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>';
        break;
}

// Animation Class
$animClass = 'btt-' . $animation;

echo '<div id="cue-back-to-top" class="' . $animClass . '" style="' . implode('; ', $styles) . '" onclick="window.scrollTo({top: 0, behavior: \'smooth\'})">';
echo $arrowSvg;
echo '</div>';

// JS for scroll detection
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btt = document.getElementById('cue-back-to-top');
    const threshold = <?= (int)$scrollThreshold ?>;
    const animType = '<?= $animation ?>';
    
    if (!btt) return;

    window.addEventListener('scroll', function() {
        if (window.scrollY > threshold) {
            btt.style.opacity = '1';
            btt.style.visibility = 'visible';
            
            if (animType === 'slide') {
                if (btt.style.bottom) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 0)' : 'translateY(0)';
                if (btt.style.top) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 0)' : 'translateY(0)';
            } else if (animType === 'zoom') {
                btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 0) scale(1)' : 'scale(1)';
            }
        } else {
            btt.style.opacity = '0';
            btt.style.visibility = 'hidden';
            
            if (animType === 'slide') {
                if (btt.style.bottom) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 20px)' : 'translateY(20px)';
                if (btt.style.top) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, -20px)' : 'translateY(-20px)';
            } else if (animType === 'zoom') {
                btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 0) scale(0)' : 'scale(0)';
            }
        }
    });
    
    // Initial State for Animations
    if (animType === 'slide') {
        if (btt.style.bottom) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 20px)' : 'translateY(20px)';
        if (btt.style.top) btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, -20px)' : 'translateY(-20px)';
    } else if (animType === 'zoom') {
        btt.style.transform = btt.style.left === '50%' ? 'translate(-50%, 0) scale(0)' : 'scale(0)';
    }
});
</script>
