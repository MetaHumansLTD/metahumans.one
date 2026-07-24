// Icon Widget JavaScript
class DynamicIconWidget {
    constructor() {
        this.grid = document.getElementById('icon-grid');
        this.search = document.getElementById('icon-search');
        this.selector = document.getElementById('icon-set-selector');
        this.loadMoreBtn = document.getElementById('load-more-icons');
        this.currentSet = 'fontawesome';
        this.currentPage = 0;
        this.searchTimeout = null;
        this.selectedIcon = null;
        
        if (this.grid || document.querySelector('.icon-widget')) {
            this.init();
        }
    }

    init() {
        console.log('🎨 Dynamic Icon Widget initialized');

        if (this.search) {
            this.search.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.searchIcons(e.target.value), 300);
            });
        }

        if (this.selector) {
            this.selector.addEventListener('change', (e) => this.switchIconSet(e.target.value));
        }

        if (this.loadMoreBtn) {
            this.loadMoreBtn.addEventListener('click', () => this.loadMoreIcons());
        }

        // Initialize with default set
        this.loadIcons(this.currentSet);
    }

    async loadIcons(setKey, page = 0, search = '', append = false) {
        if (!this.grid) return;

        try {
            if (!append) {
                this.currentPage = 0;
                this.grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #e5e7eb;">Loading icons...</div>';
            }

            const response = await fetch(`icon-widget.php?action=get_icons&set=${setKey}&page=${page}&search=${search}&limit=200`);
            const data = await response.json();

            if (data.success) {
                if (!append) {
                    this.grid.innerHTML = '';
                }
                
                this.createIconElements(data.icons || []);
                
                // Update load more button
                if (this.loadMoreBtn) {
                    if (data.hasMore) {
                        this.loadMoreBtn.style.display = 'block';
                        this.loadMoreBtn.textContent = `Load More (${data.total - data.icons.length} remaining)`;
                    } else {
                        this.loadMoreBtn.style.display = 'none';
                    }
                }
            }
        } catch (error) {
            console.error('Error loading icons:', error);
            if (this.grid) {
                this.grid.innerHTML = '<div style="grid-column: 1 / -1; text-align: center; padding: 20px; color: #ef4444;">Error loading icons</div>';
            }
        }
    }

    createIconElements(icons) {
        if (!this.grid) return;

        icons.forEach(icon => {
            const item = document.createElement('div');
            item.className = 'icon-item';
            item.style.cssText = 'display: flex; align-items: center; justify-content: center; padding: 8px; border-radius: 4px; cursor: pointer; transition: all 0.2s; background: #1f2937; border: 1px solid transparent;';
            item.title = icon.name;
            item.dataset.icon = icon.name;
            item.dataset.set = icon.set;
            item.dataset.iconClass = icon.class || `icon-${icon.name}`;

            if (icon.type === 'font') {
                item.innerHTML = `<i class="${icon.class}"></i>`;
            } else if (icon.svg) {
                item.innerHTML = icon.svg;
            } else {
                item.innerHTML = `<i class="icon-${icon.name}"></i>`;
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
        if (!this.grid) return;

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
        const isPickerMode = new URLSearchParams(window.location.search).get('mode') === 'picker';
        if (isPickerMode && window.parent) {
            const iconClass = icon.class || `icon-${icon.name}`;
            console.log('📤 Sending icon to parent:', iconClass, icon.name);
            window.parent.postMessage({
                type: 'iconSelected',
                icon: iconClass,
                name: icon.name
            }, '*');
        }

        return this.selectedIcon;
    }

    switchIconSet(setKey) {
        this.currentSet = setKey;
        this.currentPage = 0;
        this.loadIcons(setKey);
    }

    searchIcons(query) {
        this.currentPage = 0;
        this.loadIcons(this.currentSet, 0, query);
    }

    loadMoreIcons() {
        this.currentPage++;
        const searchQuery = this.search ? this.search.value : '';
        this.loadIcons(this.currentSet, this.currentPage, searchQuery, true);
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
