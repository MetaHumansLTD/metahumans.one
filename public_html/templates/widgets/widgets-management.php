<?php
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) {
    define('CUE_DISABLE_AUTO_LAYOUT', true);
}
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
?>
<div class="widget-settings-section">
    <h4><i class="fas fa-cogs"></i> Widget Configuration</h4>
    <p>Configure and customize various widgets across the platform</p>
    <div class="widget-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 25px;">
        <div class="widget-card" style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-spinner" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #3b82f6;">Loader Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">Animation and display settings</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                Configure loading animations, colors, sizes, duration and placement. Choose from multiple animation types including rings, dots, bars, and more.
            </div>
            <div class="widget-actions">
                <a href="<?php echo getWidgetURL('loader', 'settings.php'); ?>" class="btn btn-primary" style="text-decoration: none; margin-right: 10px;">
                    <i class="fas fa-cog"></i> Configure
                </a>
                <button type="button" class="btn btn-secondary" onclick="testLoaderWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
        <div class="widget-card" style="background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #22c55e, #16a34a); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-bell" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #22c55e;">Notices Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">Popup notifications and alerts</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                Configure popup notice themes, positions, duration and stacking behavior. Already configured for enterprise use.
            </div>
            <div class="widget-actions">
                <a href="<?php echo getWidgetURL('notices', 'widgets-config.php'); ?>" class="btn btn-success" style="text-decoration: none; margin-right: 10px;">
                    <i class="fas fa-cog"></i> Configure
                </a>
                <button type="button" class="btn btn-secondary" onclick="testNoticesWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
        <div class="widget-card" style="background: rgba(168, 85, 247, 0.1); border: 1px solid rgba(168, 85, 247, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #a855f7, #7c3aed); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-arrows-alt" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #a855f7;">Drag & Drop Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">File upload and management</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                File upload zones with drag and drop functionality. Configuration available for upload limits and file types.
            </div>
            <div class="widget-actions">
                <button type="button" class="btn" style="background: #a855f7; color: white; margin-right: 10px;" onclick="alert('Drag & Drop configuration coming soon!')">
                    <i class="fas fa-cog"></i> Configure
                </button>
                <button type="button" class="btn btn-secondary" onclick="testDragDropWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
        <div class="widget-card" style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #f59e0b, #d97706); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-icons" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #f59e0b;">Icons Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">Icon libraries and management</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                Manage icon libraries, custom icon sets and icon display preferences across the platform.
            </div>
            <div class="widget-actions">
                <button type="button" class="btn" style="background: #f59e0b; color: white; margin-right: 10px;" onclick="alert('Icons configuration coming soon!')">
                    <i class="fas fa-cog"></i> Configure
                </button>
                <button type="button" class="btn btn-secondary" onclick="testIconsWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
        <div class="widget-card" style="background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #ef4444, #dc2626); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-bars" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #ef4444;">Sidebar Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">Navigation and layout</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                Configure sidebar navigation, themes, and layout options for different UI frameworks and styles.
            </div>
            <div class="widget-actions">
                <button type="button" class="btn" style="background: #ef4444; color: white; margin-right: 10px;" onclick="alert('Sidebar configuration coming soon!')">
                    <i class="fas fa-cog"></i> Configure
                </button>
                <button type="button" class="btn btn-secondary" onclick="testSidebarWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
        <div class="widget-card" style="background: rgba(6, 182, 212, 0.1); border: 1px solid rgba(6, 182, 212, 0.3); border-radius: 12px; padding: 20px; transition: all 0.3s ease;">
            <div class="widget-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                <div class="widget-icon" style="width: 40px; height: 40px; background: linear-gradient(135deg, #06b6d4, #0891b2); border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px;">
                    <i class="fas fa-save" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h5 style="margin: 0; color: #06b6d4;">Autosave Widget</h5>
                    <small style="color: rgba(255,255,255,0.7);">Automatic data preservation</small>
                </div>
            </div>
            <div class="widget-description" style="color: rgba(255,255,255,0.8); margin-bottom: 15px; font-size: 14px;">
                Configure automatic saving intervals, conflict resolution and recovery options for forms and content.
            </div>
            <div class="widget-actions">
                <button type="button" class="btn" style="background: #06b6d4; color: white; margin-right: 10px;" onclick="alert('Autosave configuration coming soon!')">
                    <i class="fas fa-cog"></i> Configure
                </button>
                <button type="button" class="btn btn-secondary" onclick="testAutosaveWidget()">
                    <i class="fas fa-eye"></i> Preview
                </button>
            </div>
        </div>
    </div>
    <div class="widget-info" style="margin-top: 30px; padding: 20px; background: rgba(0,0,0,0.2); border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);">
        <h5 style="color: #00d4ff; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Widget System Information</h5>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; color: rgba(255,255,255,0.8);">
            <div>
                <strong>CUE Framework v<?php echo defined('CUE_VERSION') ? CUE_VERSION : ''; ?></strong>
                <ul style="margin: 5px 0 0 20px; font-size: 14px;">
                    <li>Modular widget architecture</li>
                    <li>Automatic asset management</li>
                    <li>Security-first design</li>
                </ul>
            </div>
            <div>
                <strong>Widget Functions Available:</strong>
                <ul style="margin: 5px 0 0 20px; font-size: 14px;">
                    <li>includeLoaderWidget()</li>
                    <li>includeNoticesWidget()</li>
                    <li>includeDragDropWidget()</li>
                </ul>
            </div>
            <div>
                <strong>Configuration Features:</strong>
                <ul style="margin: 5px 0 0 20px; font-size: 14px;">
                    <li>JSON-based settings</li>
                    <li>Real-time preview</li>
                    <li>Secure file storage</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<script>
function testLoaderWidget(){
    var css='<?php echo getWidgetURL('loader','loader.css'); ?>';
    var js='<?php echo getWidgetURL('loader','loader-simple.js'); ?>';
    var link=document.createElement('link');link.rel='stylesheet';link.href=css;document.head.appendChild(link);
    var s=document.createElement('script');s.src=js;document.body.appendChild(s);
}
function testNoticesWidget(){
    var css='<?php echo getWidgetURL('notices','popup-notice.css'); ?>';
    var js='<?php echo getWidgetURL('notices','popup-notice.js'); ?>';
    var link=document.createElement('link');link.rel='stylesheet';link.href=css;document.head.appendChild(link);
    var s=document.createElement('script');s.src=js;document.body.appendChild(s);
}
function testDragDropWidget(){
    var js='<?php echo getWidgetURL('dragdrop','widget.php'); ?>';
    window.location.href=js;
}
function testIconsWidget(){
    var url='<?php echo getWidgetURL('icons','icon-widget.php'); ?>';
    window.location.href=url;
}
function testSidebarWidget(){
    var url='<?php echo getWidgetURL('sidebar','sidebar.php'); ?>';
    window.location.href=url;
}
function testAutosaveWidget(){
    var url='<?php echo getWidgetURL('autosave','autosave.php'); ?>';
    window.location.href=url;
}
</script>
