// Simple Bulletproof CUELoader Implementation
console.log('=== SIMPLE CUELOADER STARTING ===');

// Define CUELoader IMMEDIATELY
window.CUELoader = {
    show: function(message, options) {
        console.log('CUELoader.show called:', message);
        
        // Remove existing overlay
        var existing = document.getElementById('loadingOverlay');
        if (existing) {
            existing.remove();
        }
        
        // Create simple overlay
        var overlay = document.createElement('div');
        overlay.id = 'loadingOverlay';
        overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; opacity: 1;';
        
        var spinner = document.createElement('div');
        
        // Determine animation type - prefer half-rings (original navigator animation)
        var animationType = (options && options.animation_type) ? options.animation_type : 'half-rings';
        var animationHtml = '';
        
        if (animationType === 'half-rings') {
            // Original navigator half-spinning rings with inline styles
            animationHtml = '<div style="display: flex; align-items: center; justify-content: center; gap: 5px;">' +
                '<div style="width: 60px; height: 60px; border: 4px solid transparent; border-top: 4px solid #3b82f6; border-radius: 50%; animation: spin 1s linear infinite;"></div>' +
                '<div style="width: 50px; height: 50px; border: 4px solid transparent; border-top: 4px solid rgba(124, 58, 237, 1); border-radius: 50%; animation: spin 1s linear infinite; animation-delay: -0.3s;"></div>' +
                '<div style="width: 40px; height: 40px; border: 4px solid transparent; border-top: 4px solid rgba(245, 158, 11, 1); border-radius: 50%; animation: spin 1s linear infinite; animation-delay: -0.6s;"></div>' +
                '</div>';
        } else {
            // Default single spinning ring
            animationHtml = '<div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 2s linear infinite; margin: 0 auto;"></div>';
        }
        
        spinner.innerHTML = animationHtml + '<div style="color: white; text-align: center; margin-top: 15px;">' + (message || 'Loading...') + '</div>';
        spinner.style.cssText = 'background: rgba(255,255,255,0.1); padding: 30px; border-radius: 10px; text-align: center; backdrop-filter: blur(5px);';
        
        overlay.appendChild(spinner);
        document.body.appendChild(overlay);
        
        // Add CSS animation if not present
        if (!document.getElementById('loader-spin-animation')) {
            var style = document.createElement('style');
            style.id = 'loader-spin-animation';
            style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
            document.head.appendChild(style);
        }
        
        console.log('Loader overlay created with animation type:', animationType);
        console.log('Animation HTML:', animationHtml);
        
        return overlay;
    },
    
    hide: function() {
        console.log('CUELoader.hide called');
        var overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(function() {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 300);
            return true;
        }
        return false;
    },
    
    updateConfig: function(config) {
        console.log('CUELoader.updateConfig called:', config);
        return true;
    },
    
    createAnimation: function(type, size) {
        console.log('CUELoader.createAnimation called:', type, size);
        var div = document.createElement('div');
        div.innerHTML = '<div style="border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 2s linear infinite;"></div>';
        return div;
    }
};

// Also define legacy functions for backward compatibility
window.showLoadingAnimation = function(message) {
    return window.CUELoader.show(message);
};

window.hideLoadingAnimation = function() {
    return window.CUELoader.hide();
};

window.showLoader = function(options) {
    var message = 'Loading...';
    if (options && options.message) message = options.message;
    return window.CUELoader.show(message, options);
};

window.hideLoader = function() {
    return window.CUELoader.hide();
};

window.updateLoaderConfig = function(config) {
    return window.CUELoader.updateConfig(config);
};

window.createLoaderAnimation = function(type, size) {
    return window.CUELoader.createAnimation(type, size);
};

console.log('=== SIMPLE CUELOADER READY ===');
console.log('CUELoader type:', typeof window.CUELoader);
console.log('Available methods:', Object.keys(window.CUELoader));

// Mark as ready
window.loaderReady = true;