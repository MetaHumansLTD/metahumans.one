/**
 * Global UI Sync Manager JavaScript
 * Handles sync operations and UI interactions
 * @version 2.0.0 - Fixed API endpoints (clean-sync.php, clean-sync-status.php)
 * @updated 2025-11-17
 */

class SyncManager {
    constructor(refreshIntervalSeconds = 3600) {
        this.apiBase = 'api/';
        this.refreshInterval = null;
        this.refreshIntervalSeconds = refreshIntervalSeconds;
        this.init();
    }

    init() {
        console.log('🚀 Sync Manager v2.0.0 initializing...');
        console.log('📍 API Base:', this.apiBase);
        
        // Initialize event listeners
        this.setupEventListeners();
        
        // Start auto-refresh
        this.startAutoRefresh();
        
        console.log('✅ Sync Manager initialized with clean APIs');
    }

    setupEventListeners() {
        // Add global keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl+R or F5 - Refresh status
            if ((e.ctrlKey && e.key === 'r') || e.key === 'F5') {
                e.preventDefault();
                this.refreshStatus();
            }
            
            // Ctrl+S - Sync all
            if (e.ctrlKey && e.key === 's') {
                e.preventDefault();
                this.syncAll();
            }
        });
    }

    startAutoRefresh() {
        this.stopAutoRefresh(); // Clear any existing interval
        
        if (this.refreshIntervalSeconds > 0) {
            // Refresh status at configured interval
            this.refreshInterval = setInterval(() => {
                this.refreshStatus(true); // Silent refresh
            }, this.refreshIntervalSeconds * 1000);
            
            console.log(`Auto-refresh started: ${this.refreshIntervalSeconds} seconds`);
        } else {
            console.log('Auto-refresh disabled');
        }
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }
    
    updateRefreshInterval(seconds) {
        this.refreshIntervalSeconds = seconds;
        this.startAutoRefresh(); // Restart with new interval
        console.log(`Refresh interval updated to ${seconds} seconds`);
    }

    async refreshStatus(silent = false) {
        if (!silent) {
            this.showLoading('Refreshing status...');
        }

        try {
            const cacheBuster = '?t=' + Date.now();
            const url = this.apiBase + 'clean-sync-status.php' + cacheBuster;
            console.log('🔄 Calling refreshStatus API:', url);
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            let result;
            let responseText;
            try {
                responseText = await response.text();
                // Remove BOM if present
                const cleanText = responseText.replace(/^\uFEFF/, '');
                result = JSON.parse(cleanText);
            } catch (parseError) {
                console.error('JSON Parse Error in refreshStatus:', parseError.message);
                console.error('Response text:', responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }

            if (result.success) {
                if (!silent) {
                    this.updateUI(result.status);
                    this.showNotice('Status refreshed successfully', 'success');
                }
            } else {
                throw new Error(result.error || 'Failed to refresh status');
            }

        } catch (error) {
            console.error('Refresh status failed:', error);
            if (!silent) {
                this.showNotice('Failed to refresh status: ' + error.message, 'error');
            }
        } finally {
            if (!silent) {
                this.hideLoading();
            }
        }
    }

    async syncAll() {
        this.showLoading('Synchronizing all components...');

        try {
            const cacheBuster = '?t=' + Date.now();
            const url = this.apiBase + 'clean-sync.php' + cacheBuster;
            console.log('🔄 Calling syncAll API:', url);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                body: JSON.stringify({ action: 'sync_all' })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            let result;
            let responseText;
            try {
                responseText = await response.text();
                const cleanText = responseText.replace(/^\uFEFF/, '');
                result = JSON.parse(cleanText);
            } catch (parseError) {
                console.error('JSON Parse Error in syncAll:', parseError.message);
                console.error('Response text:', responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }

            if (result.success) {
                this.showNotice('All components synchronized successfully!', 'success');
                await this.refreshStatus(true); // Silent refresh after sync
                setTimeout(() => location.reload(), 1500); // Reload to show updated UI
            } else {
                throw new Error(result.error || 'Sync operation failed');
            }

        } catch (error) {
            console.error('Sync all failed:', error);
            this.showNotice('Sync failed: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    async syncComponent(component) {
        this.showLoading(`Synchronizing ${component}...`);

        try {
            const cacheBuster = '?t=' + Date.now();
            const url = this.apiBase + 'clean-sync.php' + cacheBuster;
            console.log('🔄 Calling syncComponent API for', component + ':', url);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                body: JSON.stringify({ 
                    action: 'sync_component', 
                    component: component 
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            let result;
            let responseText;
            try {
                responseText = await response.text();
                const cleanText = responseText.replace(/^\uFEFF/, '');
                result = JSON.parse(cleanText);
            } catch (parseError) {
                console.error('JSON Parse Error in syncComponent:', parseError.message);
                console.error('Response text:', responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }

            if (result.success) {
                this.showNotice(`${component} synchronized successfully!`, 'success');
                await this.refreshStatus(true); // Silent refresh after sync
                setTimeout(() => location.reload(), 1500); // Reload to show updated UI
            } else {
                throw new Error(result.error || `${component} sync failed`);
            }

        } catch (error) {
            console.error(`Sync ${component} failed:`, error);
            this.showNotice(`${component} sync failed: ` + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    updateUI(status) {
        // Update component status indicators
        const components = ['header', 'footer', 'navigation', 'theme'];
        
        components.forEach(component => {
            const componentStatus = status.components[component];
            if (!componentStatus) return;

            // Update status badge
            const statusElement = document.querySelector(`.component-status.${component} .status-badge`);
            if (statusElement) {
                statusElement.textContent = componentStatus.status;
                statusElement.className = `status-badge ${componentStatus.status}`;
            }

            // Update parent container class
            const containerElement = document.querySelector(`.component-status.${component}`);
            if (containerElement) {
                // Remove old status classes
                containerElement.classList.remove('synced', 'pending', 'conflict', 'json_newer', 'database_newer');
                // Add new status class
                containerElement.classList.add(componentStatus.status);
            }
        });

        // Update last sync time
        const lastSyncElements = document.querySelectorAll('.text-secondary');
        const lastSyncTime = status.last_sync ? new Date(status.last_sync).toLocaleString() : 'Never';
        
        lastSyncElements.forEach(element => {
            if (element.textContent.includes('Last sync:')) {
                element.textContent = `Last sync: ${lastSyncTime}`;
            }
        });
    }

    showLoading(message = 'Loading...') {
        if (typeof showLoadingAnimation === 'function') {
            showLoadingAnimation(message);
        } else {
            console.log('Loading:', message);
        }
    }

    hideLoading() {
        if (typeof hideLoadingAnimation === 'function') {
            hideLoadingAnimation();
        }
    }

    showNotice(message, type = 'info') {
        if (window.popupNotice) {
            window.popupNotice.show(message, type);
        } else {
            // Fallback to console
            console.log(`${type.toUpperCase()}: ${message}`);
        }
    }

    editComponent(component) {
        // Open component editor in new tab
        const editorUrl = `/gear/settings/dbmanager.php#${component}`;
        window.open(editorUrl, '_blank');
    }

    // Utility method for generating backup
    async createBackup() {
        this.showLoading('Creating backup...');

        try {
            // Backup functionality temporarily disabled
            throw new Error('Backup functionality is currently disabled');
            
            const response = await fetch(this.apiBase + 'create-backup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            let result;
            let responseText;
            try {
                responseText = await response.text();
                const cleanText = responseText.replace(/^\uFEFF/, '');
                result = JSON.parse(cleanText);
            } catch (parseError) {
                console.error('JSON Parse Error in createBackup:', parseError.message);
                console.error('Response text:', responseText ? responseText.substring(0, 500) : 'No response text');
                throw new Error(`JSON Parse Error: ${parseError.message}`);
            }

            if (result.success) {
                this.showNotice('Backup created successfully', 'success');
                return result.backup_path;
            } else {
                throw new Error(result.error || 'Backup creation failed');
            }

        } catch (error) {
            console.error('Backup creation failed:', error);
            this.showNotice('Backup failed: ' + error.message, 'error');
            return null;
        } finally {
            this.hideLoading();
        }
    }
    
    getRefreshInterval() {
        return this.refreshIntervalSeconds;
    }
    
    isAutoRefreshEnabled() {
        return this.refreshIntervalSeconds > 0 && this.refreshInterval !== null;
    }
}

// Initialize sync manager when DOM is ready
let syncManager = null;

document.addEventListener('DOMContentLoaded', function() {
    syncManager = new SyncManager();
    
    // Make functions globally available for inline onclick handlers
    window.forceSyncAll = () => syncManager.syncAll();
    window.syncComponent = (component) => syncManager.syncComponent(component);
    window.refreshStatus = () => syncManager.refreshStatus();
    window.editComponent = (component) => syncManager.editComponent(component);
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SyncManager;
}