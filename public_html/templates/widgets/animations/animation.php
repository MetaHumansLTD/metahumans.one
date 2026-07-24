<?php
/**
 * Animation Widget
 * CUE Framework 100.0.99 Compliant Version
 * 
 * A comprehensive animation system that provides various effects for images,
 * backgrounds, headers, footers, navigation, and content elements.
 * 
 * Features:
 * - 3D animated backgrounds (birds, waves, clouds, cells, etc.)
 * - CSS animations (wobble, bounce, fade, rotate, scale, etc.)
 * - GSAP TweenLite powered smooth animations
 * - Background rotation system
 * - Configurable animation settings per element
 * 
 * Usage:
 * - Include this file in your page
 * - Call initializeAnimations() to start animations
 * - Use setElementAnimation('selector', 'animation-type') for specific elements
 * 
 * @version 1.0
 * @author Navigator System
 */

// Security: Prevent direct access to this widget file

// Ensure CUE framework is loaded via standard include
if (!function_exists('cue_autoload')) {
    require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
}

$isAjax = false;
if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
    isset($_POST['action']) || isset($_GET['action']) ||
    isset($_POST['ajax']) || isset($_GET['ajax']) ||
    (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
    (isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    $isAjax = true;
}
if ($isAjax) {
    return;
}

/**
 * Initialize Animation Widget Content Function
 * Main entry point for including animation functionality
 */
function initializeAnimationWidgetContent($options = []) {
    // Skip animation initialization for AJAX requests to prevent HTML contamination
    if ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
        isset($_POST['action']) || isset($_GET['action']) ||
        (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)) {
        return; // Exit early for AJAX requests
    }
    
    echo '<script>console.log("Animation Widget: initializeAnimationWidgetContent() called");</script>';
    
    // Default options
    $defaults = [
        'background_effects_enabled' => true,
        'css_animations_enabled' => true,
        'gsap_enabled' => true,
        'background_rotation_enabled' => false,
        'auto_init' => true,
        'page_aware' => true,
        'debug_mode' => false
    ];
    
    $config = array_merge($defaults, $options);
    
    // Load configuration if available
    try {
        $paths = cue_autoload('paths');
        if ($paths) {
            $configPath = $paths->getSecureFilePath('widgets/animations/animation-config.json');
            if ($configPath && (!function_exists('validateSecurePath') || $paths->validateSecurePath($configPath, getDataPath())) && file_exists($configPath)) {
                $configContent = file_get_contents($configPath);
                $savedConfig = json_decode($configContent, true);
                if ($savedConfig && is_array($savedConfig)) {
                    $config = array_merge($config, $savedConfig);
                }
            }
        }
    } catch (Exception $e) {
        if ($config['debug_mode']) {
            error_log('Animation widget configuration loading failed: ' . $e->getMessage());
        }
    }
    
    // Inject configuration for JavaScript
    echo '<script type="text/javascript">';
    echo 'window.animationWidgetConfig = ' . json_encode($config) . ';';
    echo '</script>';
    
    // Load CSS and JavaScript assets
    loadAnimationAssets($config);
    
    // Auto-initialize if enabled
    if ($config['auto_init']) {
        echo '<script type="text/javascript">';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    console.log("Animation widget: DOM ready, preparing libraries...");';
        echo '    console.log("Animation config:", window.animationWidgetConfig);';
        echo '    var startInit = function() {';
        if (isset($config['page_aware']) && $config['page_aware']) {
            echo '        loadPageSpecificAnimation();';
        } else {
            echo '        initializeAnimations();';
        }
        echo '    };';
        echo '    if (typeof window.ensureBackgroundLibrariesLoaded === "function") {';
        echo '        window.ensureBackgroundLibrariesLoaded().then(startInit).catch(function(err){';
        echo '            console.warn("Animation widget: library prep failed", err); startInit();';
        echo '        });';
        echo '    } else {';
        echo '        startInit();';
        echo '    }';
        echo '});';
        echo '</script>';
    }
    
    // Add page-specific animation loading function
    if (isset($config['page_aware']) && $config['page_aware']) {
        addPageSpecificAnimationLoader();
    }
}

/**
 * Load Animation Assets
 * Includes CSS and JavaScript files for animations
 */
function loadAnimationAssets($config) {
    $assetPath = '/templates/assets/animations/';
    
    
    
    // Load CSS files
    echo '<link rel="stylesheet" href="' . $assetPath . 'css/animation-builder.css">';
    echo '<link rel="stylesheet" href="' . $assetPath . 'css/component.css">';
    echo '<link rel="stylesheet" href="' . $assetPath . 'css/normalize.css">';
    
    
    echo '<script>(function(){
        var assetPath = "' . $assetPath . '";
        try { console.log("Animation Widget: dynamic loader ready"); } catch(e) {}
        function detectRequiredEffects(){
            var set = {};
            var nodes = document.querySelectorAll("[data-background-effect]");
            for (var i=0;i<nodes.length;i++){
                var v = (nodes[i].getAttribute("data-background-effect")||"").toLowerCase();
                if (v && v !== "none") { set[v] = true; }
            }
            var cfg = (window.animationWidgetConfig && window.animationWidgetConfig.background_effects) ? window.animationWidgetConfig.background_effects : null;
            if (cfg) {
                for (var key in cfg) {
                    if (cfg.hasOwnProperty(key)) {
                        var eff = (cfg[key].effect||"").toLowerCase();
                        var enabled = !!cfg[key].enabled;
                        if (enabled && eff && eff !== "none") { set[eff] = true; }
                    }
                }
            }
            return Object.keys(set);
        }
        function loadScriptOnce(src){
            return new Promise(function(resolve, reject){
                var existing = (function(url){
                    var scripts = document.getElementsByTagName("script");
                    for (var i = 0; i < scripts.length; i++) {
                        if (scripts[i].src === url) return scripts[i];
                    }
                    return null;
                })(src);
                if (existing){
                    if (existing.getAttribute("data-loaded") === "1") { resolve(); return; }
                    existing.addEventListener("load", function(){ resolve(); });
                    return;
                }
                var s = document.createElement("script");
                s.src = src;
                s.onload = function(){ s.setAttribute("data-loaded","1"); resolve(); };
                s.onerror = function(e){ reject(e); };
                document.head.appendChild(s);
            });
        }
        window.ensureBackgroundLibrariesLoaded = function(){
            var needed = detectRequiredEffects();
            try { console.log("Animation Widget: loader effects:", needed); } catch(e) {}
            if (needed.length === 0) { return Promise.resolve(); }
            var loadThree = (typeof window.THREE === "undefined") ? loadScriptOnce(assetPath + "three.r134.min.js") : Promise.resolve();
            return loadThree.then(function(){
                var loader = (typeof window.loadAnimationScript === "function") ? function(type){ return window.loadAnimationScript(type); } : function(type){ return loadScriptOnce(assetPath + "vanta." + type + ".min.js"); };
                return Promise.all(needed.map(loader));
            });
        };
    })();</script>';
    
    // Load 3D background effects if enabled
    if ($config['background_effects_enabled']) {
        
    }
    
    // Load GSAP if enabled
    if ($config['gsap_enabled']) {
        echo '<script src="' . $assetPath . 'TweenLite.min.js"></script>';
    }
    
    // Load background rotation system if enabled
    if ($config['background_rotation_enabled']) {
        // Background rotation handled internally to prevent variable conflicts
        // echo '<script src="' . $assetPath . 'background-rotator.js"></script>';
    }
    
    // Load demo scripts only in debug mode or if explicitly enabled
    if (isset($config['load_demo_scripts']) && $config['load_demo_scripts']) {
        echo '<script src="' . $assetPath . 'demo-1.js"></script>';
        echo '<script src="' . $assetPath . 'demo-2.js"></script>';
        echo '<script src="' . $assetPath . 'demo-3.js"></script>';
    }
}
?>

<!-- Animation Widget Container -->
<div id="animation-widget-container" style="display: none;">
    <!-- 3D background containers will be created dynamically -->
    <div id="background-container"></div>
    
    <!-- Animation control indicators -->
    <div id="animation-controls" class="animation-controls hidden">
        <div class="animation-status">Animations: <span id="animation-status-text">Loading...</span></div>
    </div>
</div>

<style>
/* Animation Widget Base Styles */
.animation-widget {
    position: relative;
    overflow: hidden;
}

/* 3D Background Styles */
.background-effect {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
}

/* CSS Animation Classes */
.animate-wobble {
    animation: wobble 1s ease-in-out infinite;
}

.animate-bounce {
    animation: bounce 2s infinite;
}

.animate-fade-in {
    animation: fadeIn 1s ease-in-out;
}

.animate-fade-out {
    animation: fadeOut 1s ease-in-out;
}

.animate-slide-in-left {
    animation: slideInLeft 0.8s ease-out;
}

.animate-slide-in-right {
    animation: slideInRight 0.8s ease-out;
}

.animate-scale-up {
    animation: scaleUp 0.6s ease-out;
}

.animate-rotate {
    animation: rotate 2s linear infinite;
}

.animate-pulse {
    animation: pulse 1.5s ease-in-out infinite;
}

.animate-float {
    animation: float 3s ease-in-out infinite;
}

.animate-glow {
    animation: glow 2s ease-in-out infinite alternate;
}

/* Keyframe Definitions */
@keyframes wobble {
    0% { transform: translateX(0%); }
    15% { transform: translateX(-25%) rotate(-5deg); }
    30% { transform: translateX(20%) rotate(3deg); }
    45% { transform: translateX(-15%) rotate(-3deg); }
    60% { transform: translateX(10%) rotate(2deg); }
    75% { transform: translateX(-5%) rotate(-1deg); }
    100% { transform: translateX(0%); }
}

@keyframes bounce {
    0%, 20%, 53%, 80%, 100% {
        animation-timing-function: cubic-bezier(0.215, 0.610, 0.355, 1.000);
        transform: translate3d(0,0,0);
    }
    40%, 43% {
        animation-timing-function: cubic-bezier(0.755, 0.050, 0.855, 0.060);
        transform: translate3d(0, -30px, 0);
    }
    70% {
        animation-timing-function: cubic-bezier(0.755, 0.050, 0.855, 0.060);
        transform: translate3d(0, -15px, 0);
    }
    90% {
        transform: translate3d(0,-4px,0);
    }
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes fadeOut {
    from { opacity: 1; }
    to { opacity: 0; }
}

@keyframes slideInLeft {
    from {
        transform: translate3d(-100%, 0, 0);
        visibility: visible;
    }
    to {
        transform: translate3d(0, 0, 0);
    }
}

@keyframes slideInRight {
    from {
        transform: translate3d(100%, 0, 0);
        visibility: visible;
    }
    to {
        transform: translate3d(0, 0, 0);
    }
}

@keyframes scaleUp {
    from {
        opacity: 0;
        transform: scale3d(0.3, 0.3, 0.3);
    }
    50% {
        opacity: 1;
    }
}

@keyframes rotate {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

@keyframes pulse {
    0% {
        transform: scale3d(1, 1, 1);
    }
    50% {
        transform: scale3d(1.05, 1.05, 1.05);
    }
    100% {
        transform: scale3d(1, 1, 1);
    }
}

@keyframes float {
    0% {
        transform: translatey(0px);
    }
    50% {
        transform: translatey(-20px);
    }
    100% {
        transform: translatey(0px);
    }
}

@keyframes glow {
    from {
        box-shadow: 0 0 10px var(--glow-color, #00ffff);
    }
    to {
        box-shadow: 0 0 20px var(--glow-color, #00ffff), 0 0 30px var(--glow-color, #00ffff);
    }
}

/* Animation Controls */
.animation-controls {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    padding: 10px;
    border-radius: 5px;
    font-size: 12px;
    z-index: 9999;
    transition: opacity 0.3s ease;
}

.animation-controls.hidden {
    opacity: 0;
    pointer-events: none;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .animation-controls {
        bottom: 10px;
        right: 10px;
        padding: 8px;
        font-size: 11px;
    }
}
</style>

<script>
/**
 * Animation Widget JavaScript
 * Handles initialization and management of all animation systems
 */

// Global animation state
window.AnimationWidget = {
    backgroundInstances: {},
    activeAnimations: new Set(),
    initialized: false,
    config: {}
};

/**
 * Initialize all animation systems
 */
function initializeAnimations() {
    if (window.AnimationWidget.initialized) {
        console.log('Animation Widget: Already initialized');
        return;
    }
    
    console.log('Animation Widget: Initializing...');
    
    // Load configuration
    window.AnimationWidget.config = window.animationWidgetConfig || {};
    
    try {
        // Initialize 3D backgrounds if enabled (ensure libraries first)
        if (window.AnimationWidget.config.background_effects_enabled) {
            var startBg = function(){ if (typeof VANTA !== 'undefined') { initializeBackgroundEffects(); } else { console.warn('Animation widget: VANTA not available after loader'); } };
            if (typeof window.ensureBackgroundLibrariesLoaded === 'function') {
                window.ensureBackgroundLibrariesLoaded().then(startBg).catch(function(){ startBg(); });
            } else {
                startBg();
            }
        }
        
        // Initialize CSS animations
        if (window.AnimationWidget.config.css_animations_enabled) {
            initializeCSSAnimations();
        }
        
        // Initialize GSAP animations if enabled
        if (window.AnimationWidget.config.gsap_enabled && typeof TweenLite !== 'undefined') {
            initializeGSAPAnimations();
        }
        
        // Initialize background rotation if enabled
        if (window.AnimationWidget.config.background_rotation_enabled) {
            initializeBackgroundRotation();
        }
        
        window.AnimationWidget.initialized = true;
        updateAnimationStatus('Active');
        
        console.log('Animation Widget: Initialization complete');
        
    } catch (error) {
        console.error('Animation Widget: Initialization failed', error);
        updateAnimationStatus('Error');
    }
}

/**
 * Initialize 3D background effects
 */
function initializeBackgroundEffects() {
    if (typeof VANTA === 'undefined') {
        console.warn('Animation widget: VANTA not loaded yet, skipping background effects initialization');
        return;
    }
    console.log('Animation widget: Initializing background effects...');
    const backgroundElements = document.querySelectorAll('[data-background-effect]');
    console.log('Animation widget: Found', backgroundElements.length, 'background elements');
    
    backgroundElements.forEach((element, index) => {
        const effect = element.getAttribute('data-background-effect');
        const contained = element.getAttribute('data-animation-contained') === 'true';
        let scale = parseFloat(element.getAttribute('data-animation-scale')) || 1.0;
        const opacity = parseFloat(element.getAttribute('data-animation-opacity')) || 1.0;
        const elementId = element.id || `bg-effect-${index}`;
        
        // For header, don't scale to prevent size adjustments
        if (element.id === 'global-header') {
            scale = 1.0;
        }
        
        if (!element.id) {
            element.id = elementId;
        }
        
        // Handle containment
        if (contained) {
            element.style.overflow = 'hidden';
            element.style.position = 'relative';
        } else {
            element.style.overflow = 'visible';
        }
        
        try {
            let backgroundInstance;
            
            switch (effect) {
                case 'birds':
                    backgroundInstance = VANTA.BIRDS({
                        el: element,
                        mouseControls: false,
                        touchControls: false,
                        gyroControls: false,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        color1: 0xff6b6b,
                        color2: 0x4ecdc4,
                        birdSize: 1.20,
                        wingSpan: 20.00,
                        speedLimit: 3.00,
                        separation: 20.00,
                        alignment: 20.00,
                        cohesion: 20.00,
                        quantity: 3.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'waves':
                    backgroundInstance = VANTA.WAVES({
                        el: element,
                        mouseControls: false,
                        touchControls: false,
                        gyroControls: false,
                        scale: scale,
                        scaleMobile: scale,
                        color: 0x23153c,
                        shininess: 30.00,
                        waveHeight: 15.00,
                        waveSpeed: 1.25,
                        zoom: 0.65,
                        zIndex: 1
                    });
                    break;
                    
                case 'clouds':
                    backgroundInstance = VANTA.CLOUDS({
                        el: element,
                        mouseControls: false,
                        touchControls: false,
                        gyroControls: false,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        skyColor: 0x68b8d7,
                        cloudColor: 0xadc1de,
                        cloudShadowColor: 0x183550,
                        sunColor: 0xff9919,
                        sunGlareColor: 0xff6633,
                        sunlightColor: 0xff9933,
                        zIndex: 1
                    });
                    break;
                    break;
                    
                case 'cells':
                    backgroundInstance = VANTA.CELLS({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        color1: 0xff6b6b,
                        color2: 0x4ecdc4,
                        size: 1.50,
                        speed: 1.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'fog':
                    backgroundInstance = VANTA.FOG({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        color1: 0xff6b6b,
                        color2: 0x4ecdc4,
                        speed: 1.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'halo':
                    backgroundInstance = VANTA.HALO({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        baseColor: 0xff6b6b,
                        blurFactor: 1.00,
                        speed: 1.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'net':
                    backgroundInstance = VANTA.NET({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        color: 0xff6b6b,
                        points: 10.00,
                        maxDistance: 20.00,
                        spacing: 15.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'rings':
                    backgroundInstance = VANTA.RINGS({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        color: 0xff6b6b,
                        zIndex: 1
                    });
                    break;
                    
                case 'ripple':
                    backgroundInstance = VANTA.RIPPLE({
                        el: element,
                        mouseControls: true,
                        touchControls: true,
                        gyroControls: false,
                        minHeight: 200.00,
                        minWidth: 200.00,
                        scale: scale,
                        scaleMobile: scale,
                        backgroundColor: 0x1a1a2e,
                        color1: 0xff6b6b,
                        color2: 0x4ecdc4,
                        rippleRadius: 100.00,
                        zIndex: 1
                    });
                    break;
                    
                case 'topology':
                    backgroundInstance = VANTA.TOPOLOGY({
                        el: element,
                        color: 0x00d4ff,
                        backgroundColor: 0x0a0a0a
                    });
                    break;
            }
            
            if (backgroundInstance) {
                window.AnimationWidget.backgroundInstances[elementId] = backgroundInstance;
                window.AnimationWidget.activeAnimations.add(elementId);
                console.log(`Animation Widget: Vanta ${effect} initialized for ${elementId}`);
                
                // Apply opacity to the canvas
                if (backgroundInstance.canvas) {
                    backgroundInstance.canvas.style.opacity = opacity;
                }
            }
            
        } catch (error) {
            console.error(`Animation Widget: Failed to initialize Vanta ${effect}`, error);
        }
    });
}

/**
 * Initialize CSS animations
 */
function initializeCSSAnimations() {
    const animatedElements = document.querySelectorAll('[data-css-animation]');
    
    animatedElements.forEach((element) => {
        const animation = element.getAttribute('data-css-animation');
        const delay = element.getAttribute('data-animation-delay') || '0s';
        const duration = element.getAttribute('data-animation-duration') || '1s';
        
        element.style.animationDelay = delay;
        element.style.animationDuration = duration;
        
        if (animation) {
            element.classList.add(`animate-${animation}`);
            window.AnimationWidget.activeAnimations.add(element.id || `css-anim-${Date.now()}`);
        }
    });
    
    console.log('Animation Widget: CSS animations initialized');
}

/**
 * Initialize GSAP animations
 */
function initializeGSAPAnimations() {
    const gsapElements = document.querySelectorAll('[data-gsap-animation]');
    
    gsapElements.forEach((element) => {
        const animation = element.getAttribute('data-gsap-animation');
        const duration = parseFloat(element.getAttribute('data-animation-duration')) || 1;
        
        try {
            switch (animation) {
                case 'slideIn':
                    TweenLite.fromTo(element, duration, {x: -100, opacity: 0}, {x: 0, opacity: 1});
                    break;
                case 'fadeIn':
                    TweenLite.fromTo(element, duration, {opacity: 0}, {opacity: 1});
                    break;
                case 'scaleIn':
                    TweenLite.fromTo(element, duration, {scale: 0, opacity: 0}, {scale: 1, opacity: 1});
                    break;
                default:
                    console.warn(`Animation Widget: Unknown GSAP animation: ${animation}`);
                    return;
            }
            
            window.AnimationWidget.activeAnimations.add(element.id || `gsap-anim-${Date.now()}`);
            
        } catch (error) {
            console.error(`Animation Widget: Failed to initialize GSAP animation ${animation}`, error);
        }
    });
    
    console.log('Animation Widget: GSAP animations initialized');
}

/**
 * Initialize background rotation system
 */
function initializeBackgroundRotation() {
    // This will be handled by the background-rotator.js file
    if (typeof initBackgroundRotator === 'function') {
        initBackgroundRotator();
        console.log('Animation Widget: Background rotation initialized');
    }
}

/**
 * Set animation for a specific element
 */
function setElementAnimation(selector, animationType, options = {}) {
    const element = document.querySelector(selector);
    if (!element) {
        console.warn(`Animation Widget: Element not found: ${selector}`);
        return;
    }
    
    const duration = options.duration || '1s';
    const delay = options.delay || '0s';
    
    // Remove existing animation classes
    element.classList.forEach(className => {
        if (className.startsWith('animate-')) {
            element.classList.remove(className);
        }
    });
    
    // Apply new animation
    element.style.animationDelay = delay;
    element.style.animationDuration = duration;
    element.classList.add(`animate-${animationType}`);
    
    console.log(`Animation Widget: Applied ${animationType} to ${selector}`);
}

/**
 * Remove animations from an element
 */
function removeElementAnimation(selector) {
    const element = document.querySelector(selector);
    if (!element) {
        console.warn(`Animation Widget: Element not found: ${selector}`);
        return;
    }
    
    // Remove animation classes
    element.classList.forEach(className => {
        if (className.startsWith('animate-')) {
            element.classList.remove(className);
        }
    });
    
    console.log(`Animation Widget: Removed animations from ${selector}`);
}

/**
 * Update animation status indicator
 */
function updateAnimationStatus(status) {
    const statusElement = document.getElementById('animation-status-text');
    if (statusElement) {
        statusElement.textContent = status;
    }
    
    // Show/hide controls based on debug mode
    const controls = document.getElementById('animation-controls');
    if (controls && window.AnimationWidget.config.debug_mode) {
        controls.classList.remove('hidden');
    }
}

/**
 * Cleanup function for animations
 */
function cleanupAnimations() {
    // Destroy Vanta instances
    Object.values(window.AnimationWidget.backgroundInstances).forEach(instance => {
        if (instance && typeof instance.destroy === 'function') {
            instance.destroy();
        }
    });
    
    window.AnimationWidget.backgroundInstances = {};
    window.AnimationWidget.activeAnimations.clear();
    window.AnimationWidget.initialized = false;
    
    console.log('Animation Widget: Cleanup complete');
}

// Cleanup on page unload
window.addEventListener('beforeunload', cleanupAnimations);

// Export functions to global scope
window.initializeAnimations = initializeAnimations;
window.setElementAnimation = setElementAnimation;
window.removeElementAnimation = removeElementAnimation;
window.cleanupAnimations = cleanupAnimations;
window.AnimationWidget.initializeBackgroundEffects = initializeBackgroundEffects;
</script>

<?php
/**
 * Add page-specific animation loading functionality
 */
function addPageSpecificAnimationLoader() {
    echo '<script type="text/javascript">';
    echo '
function loadPageSpecificAnimation() {
    console.log("🎨 Loading page-specific animation...");
    
    const currentPath = window.location.pathname;
    console.log("Current path:", currentPath);
    
    // Fetch saved animations for this page
    fetch("/templates/widgets/animations/settings.php?action=get_saved_animations")
        .then(response => response.json())
        .then(data => {
            if (data.success && data.animations) {
                console.log("Available animations:", data.animations);
                
                let pageAnimation = null;
                
                // Try to find animation for current page
                for (const [key, animation] of Object.entries(data.animations)) {
                    console.log("Checking key:", key, "against path:", currentPath);
                    
                    if (key === currentPath || 
                        currentPath.includes(key) ||
                        key.includes(currentPath.split("/").pop())) {
                        pageAnimation = animation;
                        console.log("✅ Found matching animation:", pageAnimation);
                        break;
                    }
                }
                
                if (pageAnimation && pageAnimation.enabled) {
                    console.log("🎯 Applying animation:", pageAnimation.animation);
                    applyBackgroundAnimation(pageAnimation.animation);
                } else {
                    console.log("❌ No animation found for this page, using default initialization");
                    initializeAnimations();
                }
            } else {
                console.log("❌ No animations data available, using default initialization");
                initializeAnimations();
            }
        })
        .catch(error => {
            console.error("❌ Error loading page animations:", error);
            initializeAnimations();
        });
}

function applyBackgroundAnimation(animationType) {
    console.log("🎨 Applying background animation:", animationType);
    
    // Direct VANTA animation application
    if (typeof VANTA === "undefined") {
        console.error("❌ VANTA is not loaded");
        return;
    }
    
    // Clean up any existing animations
    if (window.animationWidgetEffect && typeof window.animationWidgetEffect.destroy === "function") {
        window.animationWidgetEffect.destroy();
    }
    
    // Create animation container if it doesn\'t exist
    var animationContainer = document.getElementById("vanta-bg");
    if (!animationContainer) {
        animationContainer = document.createElement("div");
        animationContainer.id = "vanta-bg";
        animationContainer.style.cssText = "position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none;";
        document.body.appendChild(animationContainer);
    }
    
    // Color palette
    var primaryColor = 0x00d4ff;
    var secondaryColor = 0x7c3aed;
    var darkBg = 0x0a0a0a;
    
    console.log("🚀 Creating", animationType, "animation on element:", animationContainer);
    
    // Apply specific animation type
    try {
        switch (animationType.toLowerCase()) {
            case "birds":
                if (typeof VANTA.BIRDS !== "undefined") {
                    window.animationWidgetEffect = VANTA.BIRDS({
                        el: animationContainer,
                        backgroundColor: darkBg,
                        color1: primaryColor,
                        color2: secondaryColor,
                        colorMode: "variance",
                        birdSize: 1.5,
                        wingSpan: 25,
                        speedLimit: 3,
                        separation: 20,
                        alignment: 20,
                        cohesion: 20
                    });
                }
                break;
            case "waves":
                if (typeof VANTA.WAVES !== "undefined") {
                    window.animationWidgetEffect = VANTA.WAVES({
                        el: animationContainer,
                        color: primaryColor,
                        shininess: 50,
                        waveHeight: 15,
                        waveSpeed: 0.5,
                        backgroundColor: darkBg
                    });
                }
                break;
            case "topology":
                if (typeof VANTA.TOPOLOGY !== "undefined") {
                    window.animationWidgetEffect = VANTA.TOPOLOGY({
                        el: animationContainer,
                        color: primaryColor,
                        backgroundColor: darkBg
                    });
                }
                break;
            case "net":
                if (typeof VANTA.NET !== "undefined") {
                    window.animationWidgetEffect = VANTA.NET({
                        el: animationContainer,
                        color: primaryColor,
                        backgroundColor: darkBg,
                        points: 8,
                        maxDistance: 20,
                        spacing: 15
                    });
                }
                break;
            default:
                console.log("🔄 Unknown animation type, using default initialization");
                initializeAnimations();
        }
        
        if (window.animationWidgetEffect) {
            console.log("✅ Animation applied successfully:", animationType);
        } else {
            console.error("❌ Failed to create animation:", animationType);
        }
        
    } catch (error) {
        console.error("❌ Error applying animation:", error);
        initializeAnimations();
    }
}
';
    echo '</script>';
}

// Auto-include the widget if called directly (for testing)
if (basename($_SERVER['PHP_SELF']) === 'animation.php') {
    initializeAnimationWidgetContent(['debug_mode' => true]);
}
?>
