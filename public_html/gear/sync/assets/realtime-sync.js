/**
 * Real-time UI Synchronization Manager
 * Handles immediate updates between UI changes and database storage
 * 
 * @version 1.0.0
 * @author CUE Framework
 */

class RealtimeSyncManager {
    constructor(options = {}) {
        this.apiEndpoint = options.apiEndpoint || '/gear/sync/api/clean-sync.php';
        this.debug = options.debug || false;
        this.retryAttempts = options.retryAttempts || 3;
        this.retryDelay = options.retryDelay || 1000;
        
        // Component selectors for real-time updates
        this.componentSelectors = {
            header: '.cue-global-header, header, [data-component="header"]',
            footer: '.cue-global-footer, footer, [data-component="footer"]',
            hamburger: '.hamburger-menu, .cue-hamburger, [data-component="hamburger"]',
            navigation: '.navigation-menu, nav, [data-component="navigation"]'
        };
        
        // Event listeners storage
        this.eventListeners = new Map();
        
        // Sync queue for batch operations
        this.syncQueue = new Map();
        this.syncTimeout = null;
        
        this.init();
    }
    
    /**
     * Initialize the sync manager
     */
    init() {
        this.log('Initializing Real-time Sync Manager...');
        
        // Set up event listeners
        this.setupEventListeners();
        
        // Test API connectivity
        this.testConnection();
        
        // Set up cross-tab communication
        this.setupCrossTabSync();
        
        this.log('Real-time Sync Manager initialized successfully');
    }
    
    /**
     * Set up event listeners for configuration changes
     */
    setupEventListeners() {
        // Listen for component update events
        Object.keys(this.componentSelectors).forEach(component => {
            const eventName = `${component}:update`;
            
            document.addEventListener(eventName, (event) => {
                this.handleComponentUpdate(component, event.detail);
            });
            
            this.log(`Listening for ${eventName} events`);
        });
        
        // Listen for layout configuration changes
        document.addEventListener('layout:update', (event) => {
            this.handleLayoutUpdate(event.detail);
        });
        
        // Listen for sync events from layout manager
        document.addEventListener('config:saved', (event) => {
            this.handleConfigSaved(event.detail);
        });
    }
    
    /**
     * Set up cross-tab synchronization via localStorage
     */
    setupCrossTabSync() {
        window.addEventListener('storage', (event) => {
            if (event.key === 'realtime_sync_update' && event.newValue) {
                try {
                    const updateData = JSON.parse(event.newValue);
                    this.handleCrossTabUpdate(updateData);
                } catch (error) {
                    this.log('Error parsing cross-tab sync data:', error);
                }
            }
        });
        
        this.log('Cross-tab synchronization enabled');
    }
    
    /**
     * Handle component configuration updates
     */
    async handleComponentUpdate(componentType, configuration) {
        this.log(`Handling ${componentType} update:`, configuration);
        
        try {
            // Queue the sync operation
            this.queueSync(componentType, configuration);
            
            // Apply immediate UI updates
            this.applyImmediateUIUpdate(componentType, configuration);
            
            // Broadcast to other tabs
            this.broadcastUpdate(componentType, configuration);
            
        } catch (error) {
            this.log(`Error handling ${componentType} update:`, error);
        }
    }
    
    /**
     * Handle layout configuration updates
     */
    async handleLayoutUpdate(updateData) {
        if (updateData.component && updateData.config) {
            await this.handleComponentUpdate(updateData.component, updateData.config);
        }
    }
    
    /**
     * Handle configuration saved events
     */
    async handleConfigSaved(saveData) {
        if (saveData.component && saveData.config) {
            // Sync to database immediately
            await this.syncToDatabase(saveData.component, saveData.config);
        }
    }
    
    /**
     * Queue sync operation for batch processing
     */
    queueSync(componentType, configuration) {
        this.syncQueue.set(componentType, configuration);
        
        // Clear existing timeout
        if (this.syncTimeout) {
            clearTimeout(this.syncTimeout);
        }
        
        // Set new timeout for batch processing
        this.syncTimeout = setTimeout(() => {
            this.processSyncQueue();
        }, 500); // 500ms debounce
    }
    
    /**
     * Process queued sync operations
     */
    async processSyncQueue() {
        if (this.syncQueue.size === 0) return;
        
        const syncPromises = [];
        
        for (const [componentType, configuration] of this.syncQueue) {
            syncPromises.push(this.syncToDatabase(componentType, configuration));
        }
        
        this.syncQueue.clear();
        
        try {
            const results = await Promise.allSettled(syncPromises);
            this.log('Batch sync completed:', results);
        } catch (error) {
            this.log('Batch sync error:', error);
        }
    }
    
    /**
     * Sync configuration to database
     */
    async syncToDatabase(componentType, configuration, retryCount = 0) {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'sync_config',
                    component_type: componentType,
                    settings: JSON.stringify(configuration)
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            let result;
            let responseText;
            try {
                responseText = await response.text();
                this.log(`Raw response for ${componentType}:`, responseText.substring(0, 200));
                result = JSON.parse(responseText);
            } catch (parseError) {
                this.log(`JSON Parse Error for ${componentType}:`, parseError.message);
                this.log(`Response text:`, responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }
            
            if (result.success) {
                this.log(`Database sync successful for ${componentType}:`, result);
                
                // Dispatch success event
                document.dispatchEvent(new CustomEvent('sync:success', {
                    detail: { component: componentType, result }
                }));
                
                return result;
            } else {
                throw new Error(result.error || 'Unknown sync error');
            }
            
        } catch (error) {
            this.log(`Database sync failed for ${componentType}:`, error);
            
            // Retry logic
            if (retryCount < this.retryAttempts) {
                this.log(`Retrying sync for ${componentType} (attempt ${retryCount + 1})`);
                
                await new Promise(resolve => setTimeout(resolve, this.retryDelay * (retryCount + 1)));
                return this.syncToDatabase(componentType, configuration, retryCount + 1);
            }
            
            // Dispatch error event
            document.dispatchEvent(new CustomEvent('sync:error', {
                detail: { component: componentType, error: error.message }
            }));
            
            throw error;
        }
    }
    
    /**
     * Apply immediate UI updates without waiting for database sync
     */
    applyImmediateUIUpdate(componentType, configuration) {
        const selector = this.componentSelectors[componentType];
        const elements = document.querySelectorAll(selector);
        
        if (elements.length === 0) {
            this.log(`No elements found for ${componentType} with selector: ${selector}`);
            return;
        }
        
        elements.forEach(element => {
            this.updateElementConfiguration(element, componentType, configuration);
        });
        
        this.log(`Applied immediate UI updates to ${elements.length} ${componentType} elements`);
    }
    
    /**
     * Update individual element configuration
     */
    updateElementConfiguration(element, componentType, configuration) {
        // Add updating class for visual feedback
        element.classList.add('sync-updating');
        
        // Apply configuration based on component type
        switch (componentType) {
            case 'header':
                this.updateHeaderElement(element, configuration);
                break;
            case 'footer':
                this.updateFooterElement(element, configuration);
                break;
            case 'hamburger':
                this.updateHamburgerElement(element, configuration);
                break;
            case 'navigation':
                this.updateNavigationElement(element, configuration);
                break;
        }
        
        // Remove updating class and add updated class
        setTimeout(() => {
            element.classList.remove('sync-updating');
            element.classList.add('sync-updated');
            
            setTimeout(() => {
                element.classList.remove('sync-updated');
            }, 1000);
        }, 300);
    }
    
    /**
     * Update header element
     */
    updateHeaderElement(element, config) {
        if (config.title) {
            const titleEl = element.querySelector('.header-title, .logo-text, h1');
            if (titleEl) titleEl.textContent = config.title;
        }
        
        if (config.background_color) {
            element.style.backgroundColor = config.background_color;
        }
        
        if (config.height) {
            element.style.height = config.height;
        }
        
        if (config.text_color) {
            element.style.color = config.text_color;
        }
        
        if (config.glassmorphism !== undefined) {
            if (config.glassmorphism) {
                element.classList.add('glassmorphism');
            } else {
                element.classList.remove('glassmorphism');
            }
        }
        
        // Update data attribute for debugging
        element.dataset.lastUpdated = new Date().toISOString();
    }
    
    /**
     * Update footer element
     */
    updateFooterElement(element, config) {
        if (config.copyright_text) {
            const copyrightEl = element.querySelector('.copyright-text, .footer-text');
            if (copyrightEl) {
                copyrightEl.textContent = config.copyright_text;
            } else {
                // Create copyright element if it doesn't exist
                const newCopyright = document.createElement('div');
                newCopyright.className = 'copyright-text';
                newCopyright.textContent = config.copyright_text;
                element.appendChild(newCopyright);
            }
        }
        
        if (config.background_color) {
            element.style.backgroundColor = config.background_color;
        }
        
        if (config.height) {
            element.style.height = config.height;
        }
        
        if (config.text_color) {
            element.style.color = config.text_color;
        }
        
        // Update data attribute for debugging
        element.dataset.lastUpdated = new Date().toISOString();
    }
    
    /**
     * Update hamburger menu element
     */
    updateHamburgerElement(element, config) {
        if (config.position) {
            element.className = element.className.replace(/position-\w+/g, '');
            element.classList.add(`position-${config.position}`);
        }
        
        if (config.style) {
            element.className = element.className.replace(/style-\w+/g, '');
            element.classList.add(`style-${config.style}`);
        }
        
        if (config.color) {
            const lines = element.querySelectorAll('.hamburger-line, .line');
            lines.forEach(line => {
                line.style.backgroundColor = config.color;
            });
        }
        
        if (config.size) {
            element.style.fontSize = config.size;
        }
        
        // Ensure menu opens below header
        if (config.menu_position === 'below_header') {
            element.classList.add('menu-below-header');
        }
        
        // Update data attribute for debugging
        element.dataset.lastUpdated = new Date().toISOString();
    }
    
    /**
     * Update navigation element
     */
    updateNavigationElement(element, config) {
        if (config.items && Array.isArray(config.items)) {
            this.updateNavigationItems(element, config.items);
        }
        
        if (config.background_color) {
            element.style.backgroundColor = config.background_color;
        }
        
        if (config.text_color) {
            element.style.color = config.text_color;
        }
        
        // Update data attribute for debugging
        element.dataset.lastUpdated = new Date().toISOString();
    }
    
    /**
     * Update navigation items
     */
    updateNavigationItems(element, items) {
        const navList = element.querySelector('ul, .nav-list, .menu-list');
        if (!navList) return;
        
        // Clear existing items
        navList.innerHTML = '';
        
        // Add new items
        items.forEach(item => {
            const listItem = document.createElement('li');
            listItem.className = 'nav-item';
            
            const link = document.createElement('a');
            link.href = item.url || '#';
            link.textContent = item.text || item.label || '';
            link.className = 'nav-link';
            
            listItem.appendChild(link);
            navList.appendChild(listItem);
        });
    }
    
    /**
     * Broadcast update to other tabs
     */
    broadcastUpdate(componentType, configuration) {
        const updateData = {
            component: componentType,
            config: configuration,
            timestamp: Date.now(),
            source: 'realtime_sync'
        };
        
        localStorage.setItem('realtime_sync_update', JSON.stringify(updateData));
        
        // Clear the localStorage item after a short delay to allow other tabs to read it
        setTimeout(() => {
            localStorage.removeItem('realtime_sync_update');
        }, 100);
        
        this.log(`Broadcasted update for ${componentType} to other tabs`);
    }
    
    /**
     * Handle cross-tab updates
     */
    handleCrossTabUpdate(updateData) {
        this.log('Received cross-tab update:', updateData);
        
        // Apply the update immediately
        this.applyImmediateUIUpdate(updateData.component, updateData.config);
        
        // Dispatch event for other components to listen to
        document.dispatchEvent(new CustomEvent('sync:cross_tab_update', {
            detail: updateData
        }));
    }
    
    /**
     * Get current configuration from server
     */
    async getCurrentConfiguration(componentType) {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({
                    action: 'get_config',
                    component_type: componentType
                })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            let result;
            let responseText;
            try {
                responseText = await response.text();
                result = JSON.parse(responseText);
            } catch (parseError) {
                this.log(`JSON Parse Error in getConfiguration:`, parseError.message);
                this.log(`Response text:`, responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }
            
            if (result.success) {
                return result.configuration;
            } else {
                throw new Error(result.error || 'Failed to get configuration');
            }
            
        } catch (error) {
            this.log(`Error getting configuration for ${componentType}:`, error);
            throw error;
        }
    }
    
    /**
     * Test API connection
     */
    async testConnection() {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({ action: 'ping' })
            });
            
            if (response.ok) {
                let result;
                try {
                    const responseText = await response.text();
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    this.log(`JSON Parse Error in testConnection:`, parseError.message);
                    throw new Error(`JSON Parse Error: ${parseError.message}`);
                }
                if (result.success) {
                    this.log('API connection test successful:', result);
                    return true;
                }
            }
            
            throw new Error('API ping failed');
            
        } catch (error) {
            this.log('API connection test failed:', error);
            return false;
        }
    }
    
    /**
     * Force sync all components
     */
    async forceSyncAll() {
        try {
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new URLSearchParams({ action: 'force_sync_all' })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            let result;
            let responseText;
            try {
                responseText = await response.text();
                result = JSON.parse(responseText);
            } catch (parseError) {
                this.log(`JSON Parse Error in forceSyncAll:`, parseError.message);
                this.log(`Response text:`, responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }
            
            if (result.success) {
                this.log('Force sync all completed:', result);
                
                // Dispatch event
                document.dispatchEvent(new CustomEvent('sync:force_all_complete', {
                    detail: result
                }));
                
                return result;
            } else {
                throw new Error(result.error || 'Force sync failed');
            }
            
        } catch (error) {
            this.log('Force sync all failed:', error);
            throw error;
        }
    }
    
    /**
     * Debug logging
     */
    log(message, data = null) {
        if (this.debug) {
            const timestamp = new Date().toLocaleTimeString();
            console.log(`[${timestamp}] RealtimeSync:`, message, data || '');
        }
    }
    
    /**
     * Destroy the sync manager
     */
    destroy() {
        // Clear timeout
        if (this.syncTimeout) {
            clearTimeout(this.syncTimeout);
        }
        
        // Clear sync queue
        this.syncQueue.clear();
        
        // Remove event listeners would go here if we stored references
        this.log('Real-time Sync Manager destroyed');
    }
}

// Auto-initialize if in browser environment
if (typeof window !== 'undefined') {
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.realtimeSync = new RealtimeSyncManager({ 
                debug: localStorage.getItem('sync_debug') === 'true' 
            });
        });
    } else {
        window.realtimeSync = new RealtimeSyncManager({ 
            debug: localStorage.getItem('sync_debug') === 'true' 
        });
    }
}

// Export for module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RealtimeSyncManager;
}