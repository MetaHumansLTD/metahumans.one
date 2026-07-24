/**
 * Viewport-Aware Popup Notice Widget
 * Ensures notifications stay within viewport boundaries
 * Supports multiple notification types and positions
 */

// Prevent multiple class declarations
if (typeof window.PopupNotice !== 'undefined') {
    console.log('PopupNotice already loaded, skipping redeclaration');
} else {

class PopupNotice {
    constructor(options = {}) {
        this.options = {
            position: options.position || 'top-right', // top-right, top-left, bottom-right, bottom-left, center
            maxWidth: options.maxWidth || 400,
            minWidth: options.minWidth || 300,
            margin: options.margin || 20,
            minPadding: options.minPadding || 12,
            zIndex: options.zIndex || 10000,
            duration: options.duration || 5000,
            enableSound: options.enableSound || false,
            enableAnimation: options.enableAnimation !== false,
            stackNotifications: options.stackNotifications !== false,
            maxStack: options.maxStack || 5,
            ...options
        };
        
        this.notifications = [];
        this.container = null;
        this.initialized = false;
        
        this.init();
    }
    
    init() {
        if (this.initialized && this.container) return;
        
        // Ensure DOM is ready before initializing
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.init());
            return;
        }
        
        // Ensure document.body exists
        if (!document.body) {
            setTimeout(() => this.init(), 10);
            return;
        }
        
        try {
            // Remove any existing container with same ID first
            const existingContainer = document.getElementById('popup-notice-container');
            if (existingContainer && existingContainer.parentNode) {
                existingContainer.parentNode.removeChild(existingContainer);
            }
            
            // Create container for notifications
            this.container = document.createElement('div');
            if (!this.container) {
                throw new Error('Failed to create container element');
            }
            
            this.container.id = 'popup-notice-container';
            this.container.className = 'popup-notice-container';
            
            // Add base styles
            this.addStyles();
            
            // Position container
            this.positionContainer();
            
            // Add to DOM with validation
            document.body.appendChild(this.container);
            
            // Verify container was added successfully
            if (!document.body.contains(this.container)) {
                throw new Error('Container was not successfully added to DOM');
            }
            
            // Listen for viewport changes (resize, scroll)
            window.addEventListener('resize', () => this.handleResize());
            window.addEventListener('scroll', () => this.updatePosition());

            // Listen for orientation changes
            window.addEventListener('orientationchange', () => this.handleResize());

            // Use VisualViewport where available for zoom/viewport changes
            if (window.visualViewport) {
                try {
                    window.visualViewport.addEventListener('resize', () => this.handleResize());
                    window.visualViewport.addEventListener('scroll', () => this.updatePosition());
                } catch(_) {}
            }
            
            this.initialized = true;
            console.log('PopupNotice initialized successfully');
        } catch (error) {
            console.error('PopupNotice initialization failed:', error);
            // Reset initialization state for retry
            this.initialized = false;
            this.container = null;
            
            // Don't retry immediately to avoid infinite loops
            // Let the show() method handle retries
        }
    }
    
    addStyles() {
        if (document.getElementById('popup-notice-styles')) return;
        
        const styles = document.createElement('style');
        styles.id = 'popup-notice-styles';
        styles.textContent = `
            .popup-notice-container {
                position: fixed;
                z-index: ${this.options.zIndex};
                pointer-events: none;
                transition: all 0.3s ease;
                contain: layout style paint;
            }
            
            .popup-notice {
                position: relative;
                width: 100%;
                max-width: ${this.options.maxWidth}px;
                min-width: ${this.options.minWidth}px;
                margin-bottom: 10px;
                padding: 16px 20px;
                border-radius: 12px;
                color: white;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.4;
                box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                backdrop-filter: blur(10px);
                border: 1px solid rgba(255,255,255,0.1);
                pointer-events: auto;
                cursor: pointer;
                overflow: hidden;
                transform: translateX(100%);
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                will-change: transform, opacity;
            }
            
            .popup-notice.show {
                transform: translateX(0);
                opacity: 1;
            }
            
            .popup-notice.hiding {
                transform: translateX(100%);
                opacity: 0;
                margin-bottom: 0;
                padding-top: 0;
                padding-bottom: 0;
                max-height: 0;
            }
            
            /* Position-specific animations */
            .popup-notice-container.top-left .popup-notice,
            .popup-notice-container.bottom-left .popup-notice {
                transform: translateX(-100%);
            }
            
            .popup-notice-container.top-left .popup-notice.show,
            .popup-notice-container.bottom-left .popup-notice.show {
                transform: translateX(0);
            }
            
            .popup-notice-container.top-left .popup-notice.hiding,
            .popup-notice-container.bottom-left .popup-notice.hiding {
                transform: translateX(-100%);
            }
            
            .popup-notice-container.center .popup-notice {
                transform: scale(0.8);
            }
            
            .popup-notice-container.center .popup-notice.show {
                transform: scale(1);
            }
            
            .popup-notice-container.center .popup-notice.hiding {
                transform: scale(0.8);
            }
            
            /* Notification types */
            .popup-notice.success {
                background: linear-gradient(135deg, rgba(76, 175, 80, 0.95), rgba(56, 142, 60, 0.95));
                border-left: 4px solid #4CAF50;
            }
            
            .popup-notice.error {
                background: linear-gradient(135deg, rgba(244, 67, 54, 0.95), rgba(198, 40, 40, 0.95));
                border-left: 4px solid #F44336;
            }
            
            .popup-notice.warning {
                background: linear-gradient(135deg, rgba(255, 152, 0, 0.95), rgba(245, 124, 0, 0.95));
                border-left: 4px solid #FF9800;
            }
            
            .popup-notice.info {
                background: linear-gradient(135deg, rgba(33, 150, 243, 0.95), rgba(25, 118, 210, 0.95));
                border-left: 4px solid #2196F3;
            }
            
            .popup-notice-content {
                display: flex;
                align-items: flex-start;
                gap: 12px;
            }
            
            .popup-notice-icon {
                flex-shrink: 0;
                width: 20px;
                height: 20px;
                margin-top: 1px;
            }
            
            .popup-notice-message {
                flex: 1;
                word-wrap: break-word;
            }
            
            .popup-notice-close {
                position: absolute;
                top: 8px;
                right: 8px;
                width: 24px;
                height: 24px;
                border: none;
                background: rgba(255,255,255,0.2);
                border-radius: 50%;
                color: white;
                cursor: pointer;
                font-size: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0.7;
                transition: opacity 0.2s ease;
            }
            
            .popup-notice-close:hover {
                opacity: 1;
                background: rgba(255,255,255,0.3);
            }
            
            .popup-notice-progress {
                position: absolute;
                bottom: 0;
                left: 0;
                height: 3px;
                background: rgba(255,255,255,0.3);
                transition: width linear;
            }
            
            /* Responsive design */
            @media (max-width: 480px) {
                .popup-notice {
                    max-width: calc(100vw - 20px);
                    min-width: calc(100vw - 20px);
                    margin-left: 10px;
                    margin-right: 10px;
                }
                
                .popup-notice-container {
                    left: 0 !important;
                    right: 0 !important;
                    width: 100% !important;
                }
            }
        `;
        
        document.head.appendChild(styles);
    }
    
    positionContainer() {
        if (!this.container) return;
        
        const viewport = this.getViewport();
        const margin = this.options.margin;

        // Remove previous position classes only, preserve other classes (e.g., theme-*)
        ['top-left','top-right','bottom-left','bottom-right','center','top-center','bottom-center','center-left','center-right'].forEach(cls => this.container.classList.remove(cls));

        // Reset transform by default
        this.container.style.transform = '';
        this.container.style.left = '';
        this.container.style.right = '';
        this.container.style.top = '';
        this.container.style.bottom = '';

        switch (this.options.position) {
            case 'top-left':
                this.container.style.top = margin + 'px';
                this.container.style.left = margin + 'px';
                this.container.style.right = 'auto';
                this.container.style.bottom = 'auto';
                this.container.classList.add('top-left');
                break;

            case 'top-right':
                this.container.style.top = margin + 'px';
                this.container.style.right = margin + 'px';
                this.container.style.left = 'auto';
                this.container.style.bottom = 'auto';
                this.container.classList.add('top-right');
                break;

            case 'bottom-left':
                this.container.style.bottom = margin + 'px';
                this.container.style.left = margin + 'px';
                this.container.style.right = 'auto';
                this.container.style.top = 'auto';
                this.container.classList.add('bottom-left');
                break;

            case 'bottom-right':
                this.container.style.bottom = margin + 'px';
                this.container.style.right = margin + 'px';
                this.container.style.left = 'auto';
                this.container.style.top = 'auto';
                this.container.classList.add('bottom-right');
                break;

            case 'top-center':
                this.container.style.top = margin + 'px';
                this.container.style.left = '50%';
                this.container.style.right = 'auto';
                this.container.style.bottom = 'auto';
                this.container.style.transform = 'translateX(-50%)';
                this.container.classList.add('top-center');
                break;

            case 'bottom-center':
                this.container.style.bottom = margin + 'px';
                this.container.style.left = '50%';
                this.container.style.right = 'auto';
                this.container.style.top = 'auto';
                this.container.style.transform = 'translateX(-50%)';
                this.container.classList.add('bottom-center');
                break;

            case 'center-left':
                this.container.style.top = '50%';
                this.container.style.left = margin + 'px';
                this.container.style.right = 'auto';
                this.container.style.bottom = 'auto';
                this.container.style.transform = 'translateY(-50%)';
                this.container.classList.add('center-left');
                break;

            case 'center-right':
                this.container.style.top = '50%';
                this.container.style.right = margin + 'px';
                this.container.style.left = 'auto';
                this.container.style.bottom = 'auto';
                this.container.style.transform = 'translateY(-50%)';
                this.container.classList.add('center-right');
                break;

            case 'center':
                this.container.style.top = '50%';
                this.container.style.left = '50%';
                this.container.style.transform = 'translate(-50%, -50%)';
                this.container.style.right = 'auto';
                this.container.style.bottom = 'auto';
                this.container.classList.add('center');
                break;

            default:
                // Fallback to top-right for unknown values
                this.options.position = 'top-right';
                this.container.style.top = margin + 'px';
                this.container.style.right = margin + 'px';
                this.container.style.left = 'auto';
                this.container.style.bottom = 'auto';
                this.container.classList.add('top-right');
                break;
        }
        
        // Ensure container stays within viewport
        this.ensureViewportBounds();

        // Apply theme class if provided
        if (this.options.theme) {
            this.applyTheme(this.options.theme);
        }
    }
    
    ensureViewportBounds() {
        const viewport = this.getViewport();
        const rect = this.container.getBoundingClientRect();
        const margin = this.options.margin;
        const pad = Math.max(this.options.minPadding || 0, 0);

        let adjusted = false;

        // Helper to mark a subtle visual indicator when auto-adjusting
        const markAdjusted = () => {
            if (!adjusted) return;
            try {
                this.container.classList.add('position-adjusted');
                setTimeout(() => this.container.classList.remove('position-adjusted'), 600);
            } catch(_) {}
        };

        // Clamp horizontal within viewport while preserving vertical axis
        const overflowRight = rect.right > (viewport.width - pad);
        const overflowLeft = rect.left < pad;
        if (overflowRight) {
            // Prefer shifting within same horizontal axis
            const newLeft = Math.max(pad, viewport.width - rect.width - pad);
            this.container.style.left = newLeft + 'px';
            this.container.style.right = 'auto';
            // If previously centered, drop transform to an absolute value
            if ((this.container.style.transform || '').includes('translateX(-50%)')) {
                this.container.style.transform = '';
            }
            adjusted = true;
        }

        if (overflowLeft) {
            this.container.style.left = pad + 'px';
            this.container.style.right = 'auto';
            if ((this.container.style.transform || '').includes('translateX(-50%)')) {
                this.container.style.transform = '';
            }
            adjusted = true;
        }

        // If width itself exceeds viewport, enforce max width
        if (rect.width > (viewport.width - pad * 2)) {
            this.container.style.left = pad + 'px';
            this.container.style.right = 'auto';
            this.container.style.width = (viewport.width - pad * 2) + 'px';
            adjusted = true;
        } else {
            // Clear width override when enough space
            this.container.style.width = '';
        }

        // Clamp vertical within viewport while preserving horizontal axis
        const overflowBottom = rect.bottom > (viewport.height - pad);
        const overflowTop = rect.top < pad;
        if (overflowBottom) {
            // Try same vertical axis shift first
            const newTop = Math.max(pad, viewport.height - rect.height - pad);
            // If currently bottom-anchored, prefer bottom padding; otherwise compute absolute top
            if (this.container.classList.contains('bottom-left') || this.container.classList.contains('bottom-right') || this.container.classList.contains('bottom-center')) {
                this.container.style.bottom = pad + 'px';
                this.container.style.top = 'auto';
            } else {
                this.container.style.top = newTop + 'px';
                this.container.style.bottom = 'auto';
                if ((this.container.style.transform || '').includes('translateY(-50%)')) {
                    this.container.style.transform = '';
                }
            }
            adjusted = true;
        }

        if (overflowTop) {
            if (this.container.classList.contains('top-left') || this.container.classList.contains('top-right') || this.container.classList.contains('top-center')) {
                this.container.style.top = pad + 'px';
                this.container.style.bottom = 'auto';
            } else {
                // Shift within axis first; if center, compute absolute top
                this.container.style.top = pad + 'px';
                this.container.style.bottom = 'auto';
                if ((this.container.style.transform || '').includes('translateY(-50%)')) {
                    this.container.style.transform = '';
                }
            }
            adjusted = true;
        }

        // If still outside after axis shift, try alternate quadrants
        const postRect = this.container.getBoundingClientRect();
        const stillHorizontalOverflow = postRect.left < pad || postRect.right > (viewport.width - pad);
        const stillVerticalOverflow = postRect.top < pad || postRect.bottom > (viewport.height - pad);

        if (stillVerticalOverflow) {
            // Flip vertical quadrant while preserving horizontal side if possible
            if (this.container.classList.contains('top-right')) {
                this.container.classList.remove('top-right');
                this.container.classList.add('bottom-right');
                this.container.style.bottom = pad + 'px';
                this.container.style.top = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('top-left')) {
                this.container.classList.remove('top-left');
                this.container.classList.add('bottom-left');
                this.container.style.bottom = pad + 'px';
                this.container.style.top = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('bottom-right')) {
                this.container.classList.remove('bottom-right');
                this.container.classList.add('top-right');
                this.container.style.top = pad + 'px';
                this.container.style.bottom = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('bottom-left')) {
                this.container.classList.remove('bottom-left');
                this.container.classList.add('top-left');
                this.container.style.top = pad + 'px';
                this.container.style.bottom = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('center')) {
                // Move to top-center as a safe fallback
                this.container.classList.remove('center');
                this.container.classList.add('top-center');
                this.container.style.top = pad + 'px';
                this.container.style.left = '50%';
                this.container.style.transform = 'translateX(-50%)';
                this.container.style.bottom = 'auto';
                adjusted = true;
            }
        }

        if (stillHorizontalOverflow) {
            // Flip horizontal quadrant while preserving vertical side if possible
            if (this.container.classList.contains('top-right')) {
                this.container.classList.remove('top-right');
                this.container.classList.add('top-left');
                this.container.style.left = pad + 'px';
                this.container.style.right = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('bottom-right')) {
                this.container.classList.remove('bottom-right');
                this.container.classList.add('bottom-left');
                this.container.style.left = pad + 'px';
                this.container.style.right = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('top-left')) {
                this.container.classList.remove('top-left');
                this.container.classList.add('top-right');
                this.container.style.right = pad + 'px';
                this.container.style.left = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('bottom-left')) {
                this.container.classList.remove('bottom-left');
                this.container.classList.add('bottom-right');
                this.container.style.right = pad + 'px';
                this.container.style.left = 'auto';
                adjusted = true;
            } else if (this.container.classList.contains('center')) {
                // Move to center-right as a safe fallback
                this.container.classList.remove('center');
                this.container.classList.add('center-right');
                this.container.style.top = '50%';
                this.container.style.right = pad + 'px';
                this.container.style.left = 'auto';
                this.container.style.transform = 'translateY(-50%)';
                adjusted = true;
            }
        }

        // Final minimum padding enforcement
        const finalRect = this.container.getBoundingClientRect();
        if (finalRect.left < pad) {
            this.container.style.left = pad + 'px';
            this.container.style.right = 'auto';
            adjusted = true;
        }
        if (finalRect.top < pad) {
            this.container.style.top = pad + 'px';
            this.container.style.bottom = 'auto';
            adjusted = true;
        }
        if (finalRect.right > (viewport.width - pad)) {
            const newLeft = Math.max(pad, viewport.width - finalRect.width - pad);
            this.container.style.left = newLeft + 'px';
            this.container.style.right = 'auto';
            adjusted = true;
        }
        if (finalRect.bottom > (viewport.height - pad)) {
            const newTop = Math.max(pad, viewport.height - finalRect.height - pad);
            this.container.style.top = newTop + 'px';
            this.container.style.bottom = 'auto';
            adjusted = true;
        }

        markAdjusted();
    }
    
    getViewport() {
        if (window.visualViewport) {
            return {
                width: Math.max(0, window.visualViewport.width || window.innerWidth || 0),
                height: Math.max(0, window.visualViewport.height || window.innerHeight || 0),
                scale: window.visualViewport.scale || 1
            };
        }
        return {
            width: Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0),
            height: Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0),
            scale: 1
        };
    }
    
    show(message, type = 'info', options = {}) {
        // Ensure DOM is completely ready first
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                this.show(message, type, options);
            });
            return null;
        }
        
        // Wait for body to exist
        if (!document.body) {
            setTimeout(() => this.show(message, type, options), 10);
            return null;
        }
        
        // Multiple initialization attempts with validation
        let attempts = 0;
        const maxAttempts = 3;
        
        while (attempts < maxAttempts && (!this.container || !document.body.contains(this.container))) {
            console.log(`PopupNotice initialization attempt ${attempts + 1}/${maxAttempts}`);
            
            if (attempts === 0) {
                this.ensureInitialized();
            } else {
                this.forceReinitialize();
            }
            
            attempts++;
            
            // If still no container after multiple attempts, use fallback
            if (attempts >= maxAttempts && !this.container) {
                console.error('PopupNotice: All initialization attempts failed, using fallback');
                this.fallbackNotification(message, type);
                return null;
            }
        }

        const config = { ...this.options, ...options };

        // Update theme dynamically if provided in options
        if (config.theme) {
            this.applyTheme(config.theme);
        }

        // Apply runtime position change if provided
        if (config.position && config.position !== this.options.position) {
            this.setPosition(config.position);
        }
        
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `popup-notice ${type}`;
        // Accessibility attributes
        notification.setAttribute('role', 'status');
        notification.setAttribute('aria-live', 'polite');
        notification.setAttribute('aria-atomic', 'true');
        
        // Create unique ID
        const id = 'notice-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        notification.id = id;
        
        // Icon mapping
        const iconMap = {
            success: '✓',
            error: '⚠',
            warning: '⚠',
            info: 'ℹ'
        };
        
        // Build content
        notification.innerHTML = `
            <div class="popup-notice-content">
                <div class="popup-notice-icon">${iconMap[type] || 'ℹ'}</div>
                <div class="popup-notice-message">${message}</div>
            </div>
            <button class="popup-notice-close" title="Close">&times;</button>
            ${config.duration > 0 ? '<div class="popup-notice-progress"></div>' : ''}
        `;
        
        // Add close functionality
        const closeBtn = notification.querySelector('.popup-notice-close');
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.hide(id);
        });
        
        // Auto-close on click (optional)
        if (config.clickToClose !== false) {
            notification.addEventListener('click', () => this.hide(id));
        }
        
        // Manage stack
        if (this.options.stackNotifications) {
            if (this.notifications.length >= this.options.maxStack) {
                // Remove oldest notification
                const oldest = this.notifications.shift();
                this.hide(oldest.id, false);
            }
        } else {
            // Clear existing notifications
            this.clear();
        }
        
        // Ensure container exists before adding
        if (!this.container || !document.body.contains(this.container)) {
            try {
                if (typeof this.forceReinitialize === 'function') {
                    this.forceReinitialize();
                }
            } catch(_) {}
            if (!this.container && document.body) {
                this.container = document.createElement('div');
                this.container.id = 'popup-notice-container';
                this.container.className = 'popup-notice-container';
                try { this.addStyles(); this.positionContainer(); } catch(_) {}
                try { document.body.appendChild(this.container); } catch(_) {}
            }
        }
        // Add to container with error handling
        try {
            if (this.options.position.includes('bottom')) {
                this.container.insertBefore(notification, this.container.firstChild);
            } else {
                this.container.appendChild(notification);
            }
            
            // Track notification
            const notificationData = {
                id,
                element: notification,
                type,
                message,
                timestamp: Date.now()
            };
            this.notifications.push(notificationData);
            
            // Show with animation
            requestAnimationFrame(() => {
                notification.classList.add('show');
            });
        } catch (error) {
            console.error('PopupNotice container error:', error);
            // Fallback to browser notification
            this.fallbackNotification(message, type);
            return null;
        }
        
        // Progress bar animation
        if (config.duration > 0) {
            const progressBar = notification.querySelector('.popup-notice-progress');
            if (progressBar) {
                progressBar.style.width = '100%';
                setTimeout(() => {
                    progressBar.style.width = '0%';
                    progressBar.style.transition = `width ${config.duration}ms linear`;
                }, 100);
            }
        }
        
        // Auto-hide
        if (config.duration > 0) {
            setTimeout(() => {
                this.hide(id);
            }, config.duration);
        }
        
        // Sound notification (optional)
        if (config.enableSound && typeof config.soundUrl === 'string') {
            this.playSound(config.soundUrl);
        }
        
        return id;
    }

    setPosition(newPosition) {
        const allowed = ['top-left','top-right','bottom-left','bottom-right','center','top-center','bottom-center','center-left','center-right'];
        const pos = (newPosition || '').toString().toLowerCase();
        this.options.position = allowed.includes(pos) ? pos : 'top-right';
        
        if (this.container) {
            this.positionContainer();
            try {
                const evt = new CustomEvent('popup-notice:position-changed', { detail: { position: this.options.position } });
                this.container.dispatchEvent(evt);
            } catch(_) {}
        }
    }

    applyTheme(theme) {
        if (!this.container) return;
        
        const valid = ['modern','glass','dark','minimal','classic'];
        const t = (theme || '').toString().toLowerCase();
        // Remove any existing theme-* classes
        Array.from(this.container.classList)
            .filter(cls => cls.startsWith('theme-'))
            .forEach(cls => this.container.classList.remove(cls));
        if (valid.includes(t)) {
            this.container.classList.add('theme-' + t);
        }
    }
    
    hide(id, animate = true) {
        const notification = this.notifications.find(n => n.id === id);
        if (!notification) return;
        
        if (animate) {
            notification.element.classList.add('hiding');
            setTimeout(() => {
                this.remove(id);
            }, 400);
        } else {
            this.remove(id);
        }
    }
    
    remove(id) {
        const index = this.notifications.findIndex(n => n.id === id);
        if (index === -1) return;
        
        const notification = this.notifications[index];
        if (notification.element.parentNode) {
            notification.element.parentNode.removeChild(notification.element);
        }
        
        this.notifications.splice(index, 1);
    }
    
    clear() {
        this.notifications.forEach(notification => {
            if (notification.element.parentNode) {
                notification.element.parentNode.removeChild(notification.element);
            }
        });
        this.notifications = [];
    }
    
    handleResize() {
        this.positionContainer();
    }
    
    updatePosition() {
        this.ensureViewportBounds();
    }
    
    playSound(url) {
        try {
            const audio = new Audio(url);
            audio.volume = 0.3;
            audio.play().catch(() => {
                // Ignore audio play errors
            });
        } catch (error) {
            // Ignore audio errors
        }
    }
    
    // Force initialization methods
    ensureInitialized() {
        // Check if already properly initialized
        if (this.initialized && this.container && document.body && document.body.contains(this.container)) {
            return true;
        }
        
        console.log('PopupNotice: Ensuring initialization...');
        
        // Ensure DOM is ready
        if (document.readyState === 'loading') {
            console.log('PopupNotice: DOM still loading, cannot initialize yet');
            return false;
        }
        
        // Ensure body exists
        if (!document.body) {
            console.log('PopupNotice: document.body is null, cannot initialize');
            return false;
        }
        
        // Reset state and reinitialize
        this.initialized = false;
        this.container = null;

        try {
            this.init();

            // Verify initialization was successful
            const success = this.initialized && this.container && document.body.contains(this.container);
            if (success) {
                console.log('PopupNotice: Successfully ensured initialization');
            } else {
                console.error('PopupNotice: Failed to ensure initialization');
            }

            return success;
        } catch (error) {
            console.error('PopupNotice: Error during ensureInitialized:', error);
            return false;
        }
    }
    
    forceReinitialize() {
        try {
            // Remove any existing container safely
            if (this.container) {
                try {
                    if (this.container.parentNode) {
                        this.container.parentNode.removeChild(this.container);
                    }
                } catch (e) {
                    console.warn('Error removing existing container:', e);
                }
            }
            
            // Clear any existing container by ID
            const existingContainer = document.getElementById('popup-notice-container');
            if (existingContainer && existingContainer.parentNode) {
                existingContainer.parentNode.removeChild(existingContainer);
            }
            
            // Reset state completely
            this.initialized = false;
            this.container = null;
            this.notifications = [];
            
            // Ensure document.body exists before creating container
            if (!document.body) {
                console.error('Cannot reinitialize PopupNotice: document.body is null');
                return false;
            }
            
            // Create new container with validation
            this.container = document.createElement('div');
            if (!this.container) {
                console.error('Failed to create container element');
                return false;
            }
            
            this.container.id = 'popup-notice-container';
            this.container.className = 'popup-notice-container';
            
            // Add styles and position
            this.addStyles();
            this.positionContainer();
            
            // Append to body with validation
            document.body.appendChild(this.container);
            
            // Verify container was added successfully
            if (!document.body.contains(this.container)) {
                console.error('Container was not successfully added to document.body');
                this.container = null;
                return false;
            }
            
            this.initialized = true;
            console.log('PopupNotice successfully reinitialized');
            return true;
            
        } catch (error) {
            console.error('Force reinitialization failed:', error);
            this.initialized = false;
            this.container = null;
            return false;
        }
    }
    
    // Viewport change handlers
    handleResize() {
        if (this.container) {
            this.positionContainer();
        }
    }
    
    updatePosition() {
        if (this.container) {
            this.ensureViewportBounds();
        }
    }
    
    // Fallback notification method
    fallbackNotification(message, type) {
        const typeMap = {
            'error': '❌ ERROR',
            'warning': '⚠️ WARNING', 
            'success': '✅ SUCCESS',
            'info': 'ℹ️ INFO'
        };
        
        const prefix = typeMap[type] || 'ℹ️ NOTICE';
        const fullMessage = `${prefix}\n\n${message}`;
        
        // Always log to console
        const logMethod = type === 'error' ? 'error' : type === 'warning' ? 'warn' : 'log';
        console[logMethod](`PopupNotice fallback - ${type}:`, message);
        
        // Try multiple fallback methods
        try {
            // Try creating a simple DOM notification
            if (document.body) {
                const fallbackDiv = document.createElement('div');
                fallbackDiv.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: ${type === 'error' ? '#ff4444' : type === 'warning' ? '#ff8800' : '#4444ff'};
                    color: white;
                    padding: 1rem;
                    border-radius: 8px;
                    z-index: 99999;
                    max-width: 400px;
                    word-wrap: break-word;
                    font-family: monospace;
                    font-size: 14px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                `;
                fallbackDiv.textContent = fullMessage;
                
                document.body.appendChild(fallbackDiv);
                
                // Auto-remove after 8 seconds
                setTimeout(() => {
                    if (fallbackDiv.parentNode) {
                        fallbackDiv.parentNode.removeChild(fallbackDiv);
                    }
                }, 8000);
                
                return;
            }
        } catch (domError) {
            console.error('DOM fallback failed:', domError);
        }
        
        // Final fallback to browser alert for critical errors
        if (type === 'error') {
            try {
                alert(fullMessage);
            } catch (alertError) {
                console.error('Alert fallback failed:', alertError);
                // At this point, only console remains
                console.error('CRITICAL ERROR (all notification methods failed):', message);
            }
        }
    }
    
    // Convenience methods
    success(message, options = {}) {
        return this.show(message, 'success', options);
    }
    
    error(message, options = {}) {
        return this.show(message, 'error', options);
    }
    
    warning(message, options = {}) {
        return this.show(message, 'warning', options);
    }
    
    info(message, options = {}) {
        return this.show(message, 'info', options);
    }
}

// Global instance
window.PopupNotice = PopupNotice;

// Create default instance for backward compatibility when DOM is ready
if (!window.popupNotice && !window.__popupNoticePreferGlobal) {
    function createDefaultInstance() {
        if (!window.popupNotice && !window.__popupNoticePreferGlobal) {
            window.popupNotice = new PopupNotice();
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createDefaultInstance);
    } else {
        createDefaultInstance();
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PopupNotice;
}

} // End of PopupNotice class guard
