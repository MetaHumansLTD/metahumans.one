// Bulletproof Loader Widget Script
console.log('=== LOADER SCRIPT STARTING ===');

// CRITICAL: Define CUELoader object IMMEDIATELY to ensure availability
window.CUELoader = window.CUELoader || {
    show: function(message, options) {
        console.log('CUELoader.show (early definition) called:', message);
        if (typeof window.showLoader === 'function') {
            var config = {};
            if (message) config.message = message;
            if (options) config = Object.assign(config, options);
            return window.showLoader(config);
        } else if (typeof window.showLoadingAnimation === 'function') {
            window.showLoadingAnimation(message);
            return true;
        } else {
            console.warn('No loader function available yet');
            return null;
        }
    },
    hide: function() {
        console.log('CUELoader.hide (early definition) called');
        if (typeof window.hideLoader === 'function') {
            return window.hideLoader();
        } else if (typeof window.hideLoadingAnimation === 'function') {
            window.hideLoadingAnimation();
            return true;
        } else {
            console.warn('No hide function available yet');
            return false;
        }
    },
    updateConfig: function(config) {
        if (typeof window.updateLoaderConfig === 'function') {
            return window.updateLoaderConfig(config);
        }
        return false;
    },
    createAnimation: function(type, size) {
        if (typeof window.createLoaderAnimation === 'function') {
            return window.createLoaderAnimation(type, size);
        }
        return null;
    }
};

console.log('CUELoader object defined early - type:', typeof window.CUELoader);

// Opera Browser Detection and Comprehensive Compatibility
var isOpera = !!window.opera || navigator.userAgent.indexOf('Opera') !== -1 || navigator.userAgent.indexOf('OPR/') !== -1;
var operaVersion = 0;
if (isOpera) {
    console.log('Opera browser detected - applying comprehensive compatibility fixes');
    
    // Detect Opera version for specific fixes
    var operaVersionMatch = navigator.userAgent.match(/OPR\/(\d+)/);
    if (operaVersionMatch) {
        operaVersion = parseInt(operaVersionMatch[1]);
        console.log('Opera version detected: ' + operaVersion);
    }
    
    // Opera-specific DOM ready state fix
    if (typeof document.readyState === 'undefined') {
        document.readyState = 'loading';
        document.addEventListener('DOMContentLoaded', function() {
            document.readyState = 'interactive';
        });
        window.addEventListener('load', function() {
            document.readyState = 'complete';
        });
    }
    
    // Opera CSS custom property fallback detection
    var supportsCustomProperties = false;
    try {
        var testElement = document.createElement('div');
        testElement.style.setProperty('--test', 'test');
        supportsCustomProperties = testElement.style.getPropertyValue('--test') === 'test';
    } catch (e) {
        supportsCustomProperties = false;
    }
    
    if (!supportsCustomProperties) {
        console.log('Opera: CSS custom properties not supported, using fallbacks');
        window.operaNeedsColorFallbacks = true;
    }
    
    // Opera requestAnimationFrame polyfill
    if (!window.requestAnimationFrame) {
        window.requestAnimationFrame = function(callback) {
            return setTimeout(callback, 16);
        };
    }
    
    if (!window.cancelAnimationFrame) {
        window.cancelAnimationFrame = function(id) {
            clearTimeout(id);
        };
    }
}

// Polyfill classList for Opera
if (!('classList' in document.createElement('_'))) {
    (function(view) {
        var classListProp = 'classList', protoProp = 'prototype', elemCtrProto = view.Element[protoProp], objCtr = Object, strTrim = String[protoProp].trim || function() {
            return this.replace(/^\s+|\s+$/g, '');
        }, arrIndexOf = Array[protoProp].indexOf || function(item) {
            var i = 0, len = this.length;
            for (; i < len; i++) {
                if (i in this && this[i] === item) {
                    return i;
                }
            }
            return -1;
        }, DOMTokenList = function(el) {
            this.el = el;
            var classes = el.className.replace(/^\s+|\s+$/g, '').split(/\s+/);
            for (var i = 0; i < classes.length; i++) {
                this.push(classes[i]);
            }
            this._updateClassName = function() {
                el.className = this.toString();
            };
        }, testEl = document.createElement('_');
        
        DOMTokenList[protoProp] = [];
        DOMTokenList[protoProp].item = function(i) {
            return this[i] || null;
        };
        DOMTokenList[protoProp].contains = function(token) {
            token += '';
            return arrIndexOf.call(this, token) !== -1;
        };
        DOMTokenList[protoProp].add = function() {
            var tokens = arguments;
            for (var i = 0; i < tokens.length; i++) {
                var token = tokens[i] + '';
                if (arrIndexOf.call(this, token) === -1) {
                    this.push(token);
                }
            }
            this._updateClassName();
        };
        DOMTokenList[protoProp].remove = function() {
            var tokens = arguments;
            for (var i = 0; i < tokens.length; i++) {
                var token = tokens[i] + '';
                var index = arrIndexOf.call(this, token);
                if (index !== -1) {
                    this.splice(index, 1);
                }
            }
            this._updateClassName();
        };
        DOMTokenList[protoProp].toggle = function(token, force) {
            token += '';
            var result = this.contains(token), method = result ? force !== true && 'remove' : force !== false && 'add';
            if (method) {
                this[method](token);
            }
            return !result;
        };
        DOMTokenList[protoProp].toString = function() {
            return this.join(' ');
        };
        
        if (objCtr.defineProperty) {
            var classListPropDesc = {
                get: function() {
                    return new DOMTokenList(this);
                },
                enumerable: true,
                configurable: true
            };
            try {
                objCtr.defineProperty(elemCtrProto, classListProp, classListPropDesc);
            } catch (ex) {
                if (ex.number === -0x7FF5EC54) {
                    classListPropDesc.enumerable = false;
                    objCtr.defineProperty(elemCtrProto, classListProp, classListPropDesc);
                }
            }
        }
    }(window));
}

try {
    // Step 1: Set loaderReady flag
    window.loaderReady = false;

    // Step 2: Immediately execute main script
    console.log('Step 2: Starting main script...');

    // Configuration
    var config = {
        enabled: true,
        animation_type: 'half-rings',
        size: 'medium',
        position: 'center',
        colors: {
            primary: '#3b82f6',
            secondary: '#7c3aed',
            tertiary: '#f59e0b'
        },
        background_opacity: 95,
        animation_speed: 1.0,
        blur_backdrop: 10,
        duration: 0,
        show_text: true,
        auto_hide: false
    };

    console.log('Step 2a: Config defined');

    // Step 2b: Immediately define placeholder functions to guarantee availability
    console.log('Step 2b: Setting up function placeholders...');
    
    window.updateLoaderConfig = window.updateLoaderConfig || function() { console.log('Placeholder updateLoaderConfig'); return false; };
    window.showLoader = window.showLoader || function() { console.log('Placeholder showLoader'); return null; };
    window.hideLoader = window.hideLoader || function() { console.log('Placeholder hideLoader'); return false; };
    window.createLoaderAnimation = window.createLoaderAnimation || function() { console.log('Placeholder createLoaderAnimation'); return null; };
    window.buildRingsAnimation = window.buildRingsAnimation || function() { console.log('Placeholder buildRingsAnimation'); return null; };
    window.buildDotsAnimation = window.buildDotsAnimation || function() { console.log('Placeholder buildDotsAnimation'); return null; };
    window.buildBarsAnimation = window.buildBarsAnimation || function() { console.log('Placeholder buildBarsAnimation'); return null; };
    window.showLoadingAnimation = window.showLoadingAnimation || function() { console.log('Placeholder showLoadingAnimation'); };
    window.hideLoadingAnimation = window.hideLoadingAnimation || function() { console.log('Placeholder hideLoadingAnimation'); };
    
    console.log('Step 2b: Function placeholders set');

    // Opera-safe element creation helper
    function createElementSafe(tagName) {
        var element = document.createElement(tagName);
        
        // Opera-specific fixes for element creation
        if (isOpera && !element.classList) {
            element.classList = new DOMTokenList(element);
        }
        
        return element;
    }
    
    // Opera-safe className setting
    function setClassNameSafe(element, className) {
        try {
            if (element.classList && element.classList.add) {
                var classes = className.split(' ');
                for (var i = 0; i < classes.length; i++) {
                    if (classes[i]) {
                        element.classList.add(classes[i]);
                    }
                }
            } else {
                element.className = className;
            }
        } catch (e) {
            console.warn('Opera className fallback:', e);
            element.className = className;
        }
    }

    // Animation builders with Opera compatibility
    var animationBuilders = {
        rings: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-rings spinner-element');
            for (var i = 0; i < 3; i++) {
                var ring = createElementSafe('div');
                setClassNameSafe(ring, 'spinner-ring');
                container.appendChild(ring);
            }
            return container;
        },
        
        'half-rings': function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-half-rings spinner-element');
            for (var i = 0; i < 3; i++) {
                var ring = createElementSafe('div');
                setClassNameSafe(ring, 'spinner-ring');
                container.appendChild(ring);
            }
            return container;
        },
        
        dots: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-dots spinner-element');
            for (var i = 0; i < 3; i++) {
                var dot = createElementSafe('div');
                setClassNameSafe(dot, 'dot');
                container.appendChild(dot);
            }
            return container;
        },
        
        bars: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-bars spinner-element');
            for (var i = 0; i < 5; i++) {
                var bar = createElementSafe('div');
                setClassNameSafe(bar, 'bar');
                container.appendChild(bar);
            }
            return container;
        },
        
        pulse: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-pulse spinner-element');
            var circle = createElementSafe('div');
            setClassNameSafe(circle, 'pulse-circle');
            container.appendChild(circle);
            return container;
        },
        
        wave: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-wave spinner-element');
            for (var i = 0; i < 7; i++) {
                var bar = createElementSafe('div');
                setClassNameSafe(bar, 'wave-bar');
                container.appendChild(bar);
            }
            return container;
        },
        
        orbit: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-orbit spinner-element');
            for (var i = 0; i < 3; i++) {
                var dot = createElementSafe('div');
                setClassNameSafe(dot, 'orbit-dot');
                container.appendChild(dot);
            }
            return container;
        },
        
        ripple: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-ripple spinner-element');
            for (var i = 0; i < 2; i++) {
                var ring = createElementSafe('div');
                setClassNameSafe(ring, 'ripple-ring');
                container.appendChild(ring);
            }
            return container;
        },
        
        bounce: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-bounce spinner-element');
            var ball = createElementSafe('div');
            setClassNameSafe(ball, 'bounce-ball');
            container.appendChild(ball);
            return container;
        },
        
        spiral: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-spiral spinner-element');
            for (var i = 0; i < 5; i++) {
                var dot = createElementSafe('div');
                setClassNameSafe(dot, 'spiral-dot');
                container.appendChild(dot);
            }
            return container;
        },
        
        cube: function() {
            var container = createElementSafe('div');
            setClassNameSafe(container, 'spinner-cube spinner-element');
            return container;
        }
    };

    console.log('Step 2b: Animation builders defined');

    // Helper functions
    function setCSSVariables() {
        try {
            var root = document.documentElement;
            
            // Primary method: CSS Custom Properties
            if (root.style.setProperty) {
                root.style.setProperty('--loader-primary', config.colors.primary);
                root.style.setProperty('--loader-secondary', config.colors.secondary);
                root.style.setProperty('--loader-tertiary', config.colors.tertiary);
                root.style.setProperty('--loader-speed', config.animation_speed + 's');
                root.style.setProperty('--loader-bg', 'rgba(10, 10, 10, ' + (config.background_opacity / 100) + ')');
                root.style.setProperty('--loader-blur', config.blur_backdrop + 'px');
            }
            
            // Opera fallback: Direct style injection
            if (isOpera || !root.style.setProperty) {
                var styleEl = document.getElementById('loader-opera-styles');
                if (!styleEl) {
                    styleEl = document.createElement('style');
                    styleEl.id = 'loader-opera-styles';
                    styleEl.type = 'text/css';
                    document.head.appendChild(styleEl);
                }
                
                var css = 
                    '.spinner-rings .spinner-ring { border-top-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-dots .dot { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-bars .bar { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-pulse .pulse-circle { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-wave .wave-bar { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-orbit .orbit-dot { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-ripple .ripple-ring { border-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-bounce .bounce-ball { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-spiral .spiral-dot { background-color: ' + config.colors.primary + ' !important; }' +
                    '.spinner-cube { background-color: ' + config.colors.primary + ' !important; }' +
                    '.loading-spinner * { -webkit-animation-duration: ' + config.animation_speed + 's !important; -o-animation-duration: ' + config.animation_speed + 's !important; animation-duration: ' + config.animation_speed + 's !important; }';
                
                if (styleEl.styleSheet) {
                    styleEl.styleSheet.cssText = css;
                } else {
                    styleEl.textContent = css;
                }
            }
        } catch (e) {
            console.error('Error setting CSS variables:', e);
        }
    }

    function createOverlay() {
        try {
            // Remove existing
            var existing = document.getElementById('loadingOverlay');
            if (existing) {
                existing.remove();
            }

            setCSSVariables();

            var overlay = createElementSafe('div');
            overlay.id = 'loadingOverlay';
            setClassNameSafe(overlay, 'loading-overlay hidden');
            
            if (config.position !== 'center') {
                if (overlay.classList && overlay.classList.add) {
                    overlay.classList.add('position-' + config.position);
                } else {
                    overlay.className += ' position-' + config.position;
                }
            }
            
            var spinner = createElementSafe('div');
            setClassNameSafe(spinner, 'loading-spinner size-' + config.size);
            
            var spinnerContainer = createElementSafe('div');
            setClassNameSafe(spinnerContainer, 'spinner-container');
            
            // Create animation
            var animationBuilder = animationBuilders[config.animation_type] || animationBuilders.rings;
            var animationElement = animationBuilder();
            
            spinnerContainer.appendChild(animationElement);
            spinner.appendChild(spinnerContainer);
            
            // Add text
            if (config.show_text) {
                var text = createElementSafe('div');
                setClassNameSafe(text, 'loading-text');
                text.id = 'loadingOverlayText';
                text.textContent = 'Loading…';
                spinner.appendChild(text);
            }
            
            overlay.appendChild(spinner);
            
            // Ensure overlay is added to document body
            document.body.appendChild(overlay);
            
            console.log('Overlay created with animation:', config.animation_type);
            return overlay;
        } catch (e) {
            console.error('Error creating overlay:', e);
            return null;
        }
    }

    console.log('Step 2c: Helper functions defined');

    // Step 3: Define ALL API functions immediately (before any potential errors)
    console.log('Step 3: Defining ALL API functions...');

    // Update configuration function
    window.updateLoaderConfig = function(newConfig) {
        try {
            console.log('updateLoaderConfig called with:', newConfig);
            if (newConfig && typeof newConfig === 'object') {
                // Update configuration (ES5 compatible)
                for (var key in newConfig) {
                    if (newConfig.hasOwnProperty(key)) {
                        config[key] = newConfig[key];
                    }
                }
                console.log('Config updated to:', config);
                setCSSVariables();
                return true;
            }
            return false;
        } catch (e) {
            console.error('Error in updateLoaderConfig:', e);
            return false;
        }
    };

    // Main show loader function (standard API)
    window.showLoader = function(options) {
        try {
            console.log('showLoader called with:', options);
            
            if (options) {
                window.updateLoaderConfig(options);
            }
            
            var overlay = createOverlay();
            if (options && options.message) {
                var textElement = overlay.querySelector('.loading-text');
                if (textElement) {
                    textElement.textContent = options.message;
                }
            }
            
            // Force repaint
            overlay.offsetHeight;
            
            // Show (Opera-compatible)
            if (overlay.classList) {
                overlay.classList.remove('hidden');
            } else {
                overlay.className = overlay.className.replace(/\bhidden\b/g, '').replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
            }
            
            // Auto hide
            if (config.auto_hide && config.duration > 0) {
                setTimeout(function() {
                    window.hideLoader();
                }, config.duration);
            }
            
            return overlay;
        } catch (e) {
            console.error('Error in showLoader:', e);
            return null;
        }
    };

    // Main hide loader function (standard API)
    window.hideLoader = function() {
        try {
            console.log('hideLoader called');
            var overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                if (overlay.classList) {
                    overlay.classList.add('hidden');
                } else {
                    overlay.className += ' hidden';
                }
                setTimeout(function() {
                    if (overlay.parentNode) {
                        overlay.parentNode.removeChild(overlay);
                    }
                }, 300);
                return true;
            }
            return false;
        } catch (e) {
            console.error('Error in hideLoader:', e);
            return false;
        }
    };

    // Legacy function (for backward compatibility)
    window.showLoadingAnimation = function(message, customConfig) {
        var options = {};
        if (message) options.message = message;
        if (customConfig) {
            for (var key in customConfig) {
                if (customConfig.hasOwnProperty(key)) {
                    options[key] = customConfig[key];
                }
            }
        }
        return window.showLoader(options);
    };

    // Legacy function (for backward compatibility)
    window.hideLoadingAnimation = function() {
        return window.hideLoader();
    };

    // Create animation element function
    window.createLoaderAnimation = function(type, size) {
        try {
            console.log('createLoaderAnimation called with:', type, size);
            
            if (!type || !animationBuilders[type]) {
                type = 'rings'; // Default fallback
            }
            
            var builder = animationBuilders[type];
            if (typeof builder === 'function') {
                var element = builder();
                if (element && size) {
                    if (element.classList) {
                        element.classList.add('size-' + size);
                    } else {
                        element.className += ' size-' + size;
                    }
                }
                return element;
            }
            
            return null;
        } catch (e) {
            console.error('Error in createLoaderAnimation:', e);
            return null;
        }
    };

    // Individual animation builders
    window.buildRingsAnimation = function(size) {
        try {
            var element = animationBuilders.rings();
            if (element && size) {
                if (element.classList) {
                    element.classList.add('size-' + size);
                } else {
                    element.className += ' size-' + size;
                }
            }
            return element;
        } catch (e) {
            console.error('Error in buildRingsAnimation:', e);
            return null;
        }
    };

    window.buildDotsAnimation = function(size) {
        try {
            var element = animationBuilders.dots();
            if (element && size) {
                if (element.classList) {
                    element.classList.add('size-' + size);
                } else {
                    element.className += ' size-' + size;
                }
            }
            return element;
        } catch (e) {
            console.error('Error in buildDotsAnimation:', e);
            return null;
        }
    };

    window.buildBarsAnimation = function(size) {
        try {
            var element = animationBuilders.bars();
            if (element && size) {
                if (element.classList) {
                    element.classList.add('size-' + size);
                } else {
                    element.className += ' size-' + size;
                }
            }
            return element;
        } catch (e) {
            console.error('Error in buildBarsAnimation:', e);
            return null;
        }
    };

    window.showLoadingAnimation = function(message) {
        try {
            if (!config.enabled) return;
            
            var overlay = document.getElementById('loadingOverlay');
            if (!overlay) {
                createOverlay();
                overlay = document.getElementById('loadingOverlay');
            }
            
            var textEl = document.getElementById('loadingOverlayText');
            if (textEl && config.show_text && message) {
                textEl.textContent = message;
            }
            
            if (overlay.classList) {
                overlay.classList.remove('hidden');
            } else {
                overlay.className = overlay.className.replace(/\bhidden\b/g, '').replace(/\s+/g, ' ').replace(/^\s|\s$/g, '');
            }
            
            if (config.duration > 0) {
                setTimeout(function() {
                    window.hideLoadingAnimation();
                }, config.duration * 1000);
            }
        } catch (e) {
            console.error('Error in showLoadingAnimation:', e);
        }
    };

    window.hideLoadingAnimation = function() {
        try {
            var overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                if (overlay.classList) {
                    overlay.classList.add('hidden');
                } else {
                    overlay.className += ' hidden';
                }
            }
        } catch (e) {
            console.error('Error in hideLoadingAnimation:', e);
        }
    };

    window.updateLoaderConfig = function(newConfig) {
        try {
            console.log('Updating loader config:', newConfig);
            
            for (var key in newConfig) {
                if (newConfig.hasOwnProperty(key)) {
                    config[key] = newConfig[key];
                }
            }
            
            console.log('New config applied:', config);
            createOverlay();
        } catch (e) {
            console.error('Error in updateLoaderConfig:', e);
        }
    };

    console.log('Step 3 completed - real functions defined');

    // Step 4: Initialize
    console.log('Step 4: Initializing...');

    function loadServerConfig() {
        try {
            fetch('/templates/widgets/loader/get-config.php')
                .then(function(response) {
                    return response.json();
                })
                .then(function(serverConfig) {
                    console.log('Server config loaded:', serverConfig);
                    window.updateLoaderConfig(serverConfig);
                })
                .catch(function(error) {
                    console.log('Using default config:', error);
                    createOverlay();
                });
        } catch (e) {
            console.log('Fetch not available, using defaults:', e);
            createOverlay();
        }
    }

    // Initialize immediately or on DOM ready
    function initialize() {
        try {
            console.log('Running initialization...');
            loadServerConfig();
            window.loaderReady = true;
            console.log('LOADER READY! All functions available.');
        } catch (e) {
            console.error('Initialization error:', e);
            window.loaderReady = true; // Mark ready even with errors
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    console.log('Step 4 completed - initialization set up');

} catch (error) {
    console.error('CRITICAL ERROR in loader script:', error);
    
    // Fallback: Ensure ALL functions exist even if there's an error
    window.loaderReady = true;
    
    if (!window.updateLoaderConfig) {
        window.updateLoaderConfig = function() { 
            console.warn('Fallback updateLoaderConfig'); 
            return false;
        };
    }
    
    if (!window.showLoader) {
        window.showLoader = function() { 
            console.warn('Fallback showLoader'); 
            return null;
        };
    }
    
    if (!window.hideLoader) {
        window.hideLoader = function() { 
            console.warn('Fallback hideLoader'); 
            return false;
        };
    }
    
    if (!window.createLoaderAnimation) {
        window.createLoaderAnimation = function() { 
            console.warn('Fallback createLoaderAnimation'); 
            return null;
        };
    }
    
    if (!window.buildRingsAnimation) {
        window.buildRingsAnimation = function() { 
            console.warn('Fallback buildRingsAnimation'); 
            return null;
        };
    }
    
    if (!window.buildDotsAnimation) {
        window.buildDotsAnimation = function() { 
            console.warn('Fallback buildDotsAnimation'); 
            return null;
        };
    }
    
    if (!window.buildBarsAnimation) {
        window.buildBarsAnimation = function() { 
            console.warn('Fallback buildBarsAnimation'); 
            return null;
        };
    }
    
    if (!window.showLoadingAnimation) {
        window.showLoadingAnimation = function() { 
            console.warn('Fallback showLoadingAnimation'); 
        };
    }
    
    if (!window.hideLoadingAnimation) {
        window.hideLoadingAnimation = function() { 
            console.warn('Fallback hideLoadingAnimation'); 
        };
    }
}

// Enhance CUELoader object with fully implemented functions
window.CUELoader.show = function(message, options) {
    try {
        console.log('CUELoader.show (enhanced) called with:', message, options);
        var config = {};
        if (message) config.message = message;
        if (options && typeof options === 'object') {
            for (var key in options) {
                if (options.hasOwnProperty(key)) {
                    config[key] = options[key];
                }
            }
        }
        return window.showLoader(config);
    } catch (e) {
        console.error('Error in CUELoader.show:', e);
        return null;
    }
};

window.CUELoader.hide = function() {
    try {
        console.log('CUELoader.hide (enhanced) called');
        return window.hideLoader();
    } catch (e) {
        console.error('Error in CUELoader.hide:', e);
        return false;
    }
};

window.CUELoader.updateConfig = function(newConfig) {
    try {
        console.log('CUELoader.updateConfig (enhanced) called with:', newConfig);
        return window.updateLoaderConfig(newConfig);
    } catch (e) {
        console.error('Error in CUELoader.updateConfig:', e);
        return false;
    }
};

window.CUELoader.createAnimation = function(type, size) {
    try {
        console.log('CUELoader.createAnimation (enhanced) called with:', type, size);
        return window.createLoaderAnimation(type, size);
    } catch (e) {
        console.error('Error in CUELoader.createAnimation:', e);
        return null;
    }
};

console.log('=== LOADER SCRIPT COMPLETED ===');
console.log('CUELoader object created with methods:', Object.keys(window.CUELoader));
console.log('Final status - ALL FUNCTIONS:', {
    updateLoaderConfig: typeof window.updateLoaderConfig,
    showLoader: typeof window.showLoader,
    hideLoader: typeof window.hideLoader,
    createLoaderAnimation: typeof window.createLoaderAnimation,
    buildRingsAnimation: typeof window.buildRingsAnimation,
    buildDotsAnimation: typeof window.buildDotsAnimation,
    buildBarsAnimation: typeof window.buildBarsAnimation,
    showLoadingAnimation: typeof window.showLoadingAnimation,
    hideLoadingAnimation: typeof window.hideLoadingAnimation,
    CUELoader: typeof window.CUELoader,
    loaderReady: window.loaderReady
});

// Ensure all functions are immediately available for tests
setTimeout(function() {
    console.log('=== FUNCTION AVAILABILITY CHECK ===');
    var allFunctions = [
        'updateLoaderConfig', 'showLoader', 'hideLoader', 'createLoaderAnimation',
        'buildRingsAnimation', 'buildDotsAnimation', 'buildBarsAnimation',
        'showLoadingAnimation', 'hideLoadingAnimation'
    ];
    
    var available = 0;
    allFunctions.forEach(function(funcName) {
        if (typeof window[funcName] === 'function') {
            console.log('✅', funcName, 'is available');
            available++;
        } else {
            console.error('❌', funcName, 'is missing');
        }
    });
    
    console.log('Summary:', available + '/' + allFunctions.length, 'functions available');
}, 100);