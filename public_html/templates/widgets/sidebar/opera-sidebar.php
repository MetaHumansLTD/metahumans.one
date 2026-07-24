<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Opera-Style Sidebar App</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: #1a1a1a;
      color: #fff;
      overflow: hidden;
      height: 100vh;
    }

    .sidebar-container {
      position: fixed;
      left: 0;
      top: 0;
      height: 100vh;
      display: flex;
      z-index: 1000;
    }

    .sidebar {
      width: 60px;
      background: linear-gradient(180deg, #2d2d2d 0%, #1e1e1e 100%);
      border-right: 1px solid #333;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 10px 0;
      transition: all 0.3s ease;
    }

    .sidebar-item {
      width: 40px;
      height: 40px;
      margin: 8px 0;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      background: rgba(255, 255, 255, 0.1);
    }

    .sidebar-item:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: scale(1.1);
    }

    .sidebar-item.active {
      background: #0078d4;
      box-shadow: 0 0 10px rgba(0, 120, 212, 0.5);
    }

    .sidebar-item i {
      font-size: 18px;
      color: #fff;
    }

    .content-panel {
      width: 0;
      height: 100vh;
      background: #2a2a2a;
      border-right: 1px solid #333;
      overflow: hidden;
      transition: width 0.3s ease;
      display: flex;
      flex-direction: column;
    }

    .content-panel.active {
      width: 300px;
    }

    .panel-header {
      padding: 20px;
      border-bottom: 1px solid #333;
      background: #333;
    }

    .panel-title {
      font-size: 18px;
      font-weight: 600;
      margin-bottom: 5px;
    }

    .panel-subtitle {
      font-size: 12px;
      color: #aaa;
    }

    .panel-content {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
    }

    .main-content {
      margin-left: 60px;
      height: 100vh;
      background: #1a1a1a;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: margin-left 0.3s ease;
    }

    .main-content.shifted {
      margin-left: 360px;
    }

    .welcome-text {
      text-align: center;
      color: #aaa;
    }

    .welcome-text h1 {
      font-size: 2.5em;
      margin-bottom: 20px;
      color: #fff;
    }

    .app-item {
      display: flex;
      align-items: center;
      padding: 12px;
      margin: 8px 0;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s ease;
    }

    .app-item:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .app-icon {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      margin-right: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
    }

    .app-info h4 {
      font-size: 14px;
      margin-bottom: 2px;
    }

    .app-info p {
      font-size: 12px;
      color: #aaa;
    }

    .search-box {
      width: 100%;
      padding: 10px;
      background: #333;
      border: 1px solid #444;
      border-radius: 6px;
      color: #fff;
      margin-bottom: 15px;
    }

    .search-box:focus {
      outline: none;
      border-color: #0078d4;
    }

    .close-btn {
      position: absolute;
      top: 10px;
      right: 10px;
      background: none;
      border: none;
      color: #aaa;
      cursor: pointer;
      font-size: 16px;
      padding: 5px;
      border-radius: 4px;
    }

    .close-btn:hover {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
    }

    /* Custom scrollbar */
    .panel-content::-webkit-scrollbar {
      width: 6px;
    }

    .panel-content::-webkit-scrollbar-track {
      background: #2a2a2a;
    }

    .panel-content::-webkit-scrollbar-thumb {
      background: #555;
      border-radius: 3px;
    }

    .panel-content::-webkit-scrollbar-thumb:hover {
      background: #666;
    }

    /* Right-side widget */
    .right-widget {
      position: fixed;
      top: 20px;
      right: 20px;
      width: 300px;
      background: linear-gradient(180deg, #2d2d2d 0%, #1f1f1f 100%);
      border: 1px solid #333;
      border-radius: 12px;
      box-shadow: 0 6px 18px rgba(0,0,0,0.35);
      overflow: hidden;
      z-index: 1001;
    }

    .rw-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      background: #333;
      border-bottom: 1px solid #3d3d3d;
    }

    .rw-title {
      font-size: 15px;
      font-weight: 600;
    }

    .rw-actions {
      display: flex;
      gap: 8px;
    }

    .rw-btn {
      background: rgba(255,255,255,0.08);
      color: #ddd;
      border: 1px solid #444;
      border-radius: 6px;
      padding: 6px 8px;
      cursor: pointer;
      transition: all 0.2s ease;
      font-size: 13px;
    }

    .rw-btn:hover {
      background: rgba(255,255,255,0.15);
      color: #fff;
    }

    .rw-content {
      padding: 14px;
    }

    .rw-block + .rw-block {
      margin-top: 12px;
    }

    .rw-icons {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
    }

    .rw-icon-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 48px;
      border-radius: 10px;
      background: rgba(255,255,255,0.08);
      border: 1px solid #444;
      color: #ddd;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .rw-icon-btn:hover {
      background: rgba(255,255,255,0.15);
      color: #fff;
      transform: translateY(-1px);
    }

    .rw-icon-btn i {
      font-size: 18px;
    }

    .rw-label {
      font-size: 12px;
      color: #aaa;
      margin-bottom: 6px;
    }

    .rw-clock {
      font-size: 20px;
      font-weight: 600;
      letter-spacing: 0.5px;
    }

    .rw-textarea {
      width: 100%;
      min-height: 90px;
      resize: vertical;
      background: #2b2b2b;
      color: #eee;
      border: 1px solid #444;
      border-radius: 8px;
      padding: 10px;
      font-family: inherit;
      font-size: 13px;
    }

    .right-widget.collapsed .rw-content {
      display: none;
    }
  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
  <div class="sidebar-container">
    <!-- Sidebar with icons -->
    <div class="sidebar">
      <div class="sidebar-item" data-panel="messenger">
        <i class="fab fa-facebook-messenger"></i>
      </div>
      <div class="sidebar-item" data-panel="whatsapp">
        <i class="fab fa-whatsapp"></i>
      </div>
      <div class="sidebar-item" data-panel="telegram">
        <i class="fab fa-telegram"></i>
      </div>
      <div class="sidebar-item" data-panel="discord">
        <i class="fab fa-discord"></i>
      </div>
      <div class="sidebar-item" data-panel="spotify">
        <i class="fab fa-spotify"></i>
      </div>
      <div class="sidebar-item" data-panel="youtube">
        <i class="fab fa-youtube"></i>
      </div>
      <div class="sidebar-item" data-panel="twitter">
        <i class="fab fa-twitter"></i>
      </div>
      <div class="sidebar-item" data-panel="instagram">
        <i class="fab fa-instagram"></i>
      </div>
      <div class="sidebar-item" data-panel="mail">
        <i class="fas fa-envelope"></i>
      </div>
      <div class="sidebar-item" data-panel="calendar">
        <i class="fas fa-calendar"></i>
      </div>
      <div class="sidebar-item" data-panel="notes">
        <i class="fas fa-note-sticky"></i>
      </div>
      <div class="sidebar-item" data-panel="settings">
        <i class="fas fa-cog"></i>
      </div>
    </div>

    <!-- Content panels -->
    <div class="content-panel" id="messenger-panel">
      <div class="panel-header">
        <button class="close-btn" onclick="closePanel()">&times;</button>
        <div class="panel-title">Messenger</div>
        <div class="panel-subtitle">Facebook Messenger</div>
      </div>
      <div class="panel-content">
        <input type="text" class="search-box" placeholder="Search conversations...">
        <div class="app-item">
          <div class="app-icon" style="background: #0084ff;"><i class="fas fa-user"></i></div>
          <div class="app-info">
            <h4>John Doe</h4>
            <p>Hey, how are you doing?</p>
          </div>
        </div>
        <div class="app-item">
          <div class="app-icon" style="background: #00d084;"><i class="fas fa-user"></i></div>
          <div class="app-info">
            <h4>Jane Smith</h4>
            <p>See you tomorrow!</p>
          </div>
        </div>
      </div>
    </div>

    <div class="content-panel" id="whatsapp-panel">
      <div class="panel-header">
        <button class="close-btn" onclick="closePanel()">&times;</button>
        <div class="panel-title">WhatsApp</div>
        <div class="panel-subtitle">WhatsApp Web</div>
      </div>
      <div class="panel-content">
        <input type="text" class="search-box" placeholder="Search or start new chat">
        <div class="app-item">
          <div class="app-icon" style="background: #25d366;"><i class="fas fa-users"></i></div>
          <div class="app-info">
            <h4>Family Group</h4>
            <p>Mom: Don't forget dinner tonight</p>
          </div>
        </div>
        <div class="app-item">
          <div class="app-icon" style="background: #128c7e;"><i class="fas fa-user"></i></div>
          <div class="app-info">
            <h4>Alex Johnson</h4>
            <p>Thanks for the help!</p>
          </div>
        </div>
      </div>
    </div>

    <div class="content-panel" id="spotify-panel">
      <div class="panel-header">
        <button class="close-btn" onclick="closePanel()">&times;</button>
        <div class="panel-title">Spotify</div>
        <div class="panel-subtitle">Music Player</div>
      </div>
      <div class="panel-content">
        <input type="text" class="search-box" placeholder="Search songs, artists...">
        <div class="app-item">
          <div class="app-icon" style="background: #1db954;"><i class="fas fa-music"></i></div>
          <div class="app-info">
            <h4>Recently Played</h4>
            <p>Your recent music</p>
          </div>
        </div>
        <div class="app-item">
          <div class="app-icon" style="background: #1ed760;"><i class="fas fa-heart"></i></div>
          <div class="app-info">
            <h4>Liked Songs</h4>
            <p>Songs you've liked</p>
          </div>
        </div>
      </div>
    </div>

    <div class="content-panel" id="settings-panel">
      <div class="panel-header">
        <button class="close-btn" onclick="closePanel()">&times;</button>
        <div class="panel-title">Settings</div>
        <div class="panel-subtitle">App Configuration</div>
      </div>
      <div class="panel-content">
        <div class="app-item">
          <div class="app-icon" style="background: #666;"><i class="fas fa-palette"></i></div>
          <div class="app-info">
            <h4>Theme</h4>
            <p>Dark mode enabled</p>
          </div>
        </div>
        <div class="app-item">
          <div class="app-icon" style="background: #666;"><i class="fas fa-bell"></i></div>
          <div class="app-info">
            <h4>Notifications</h4>
            <p>Manage notifications</p>
          </div>
        </div>
        <div class="app-item">
          <div class="app-icon" style="background: #666;"><i class="fas fa-shield-alt"></i></div>
          <div class="app-info">
            <h4>Privacy</h4>
            <p>Privacy settings</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Main content area -->
  <div class="main-content" id="main-content">
    <div class="welcome-text">
      <h1>Opera-Style Sidebar</h1>
      <p>Click on any sidebar icon to expand the panel</p>
      <p>Hover over icons for smooth animations</p>
    </div>
  </div>

  <!-- Right-side widget -->
  <div class="right-widget" id="right-widget">
    <div class="rw-header">
      <div class="rw-title"><i class="fas fa-tools" style="margin-right:8px;color:#bbb;"></i>Quick Widget</div>
      <div class="rw-actions">
        <button class="rw-btn" id="rw-toggle" title="Collapse"><i class="fas fa-chevron-up"></i></button>
      </div>
    </div>
    <div class="rw-content">
      <div class="rw-block">
        <div class="rw-label">Quick Apps</div>
        <div class="rw-icons">
          <button class="rw-icon-btn" data-open="messenger" title="Messenger"><i class="fab fa-facebook-messenger"></i></button>
          <button class="rw-icon-btn" data-open="whatsapp" title="WhatsApp"><i class="fab fa-whatsapp"></i></button>
          <button class="rw-icon-btn" data-open="telegram" title="Telegram"><i class="fab fa-telegram"></i></button>
          <button class="rw-icon-btn" data-open="discord" title="Discord"><i class="fab fa-discord"></i></button>
          <button class="rw-icon-btn" data-open="spotify" title="Spotify"><i class="fab fa-spotify"></i></button>
          <button class="rw-icon-btn" data-open="youtube" title="YouTube"><i class="fab fa-youtube"></i></button>
          <button class="rw-icon-btn" data-open="twitter" title="Twitter"><i class="fab fa-twitter"></i></button>
          <button class="rw-icon-btn" data-open="instagram" title="Instagram"><i class="fab fa-instagram"></i></button>
          <button class="rw-icon-btn" data-open="settings" title="Settings"><i class="fas fa-cog"></i></button>
          <button class="rw-icon-btn" data-open="mail" title="Mail"><i class="fas fa-envelope"></i></button>
          <button class="rw-icon-btn" data-open="calendar" title="Calendar"><i class="fas fa-calendar"></i></button>
          <button class="rw-icon-btn" data-open="notes" title="Notes"><i class="fas fa-note-sticky"></i></button>
        </div>
      </div>
      <div class="rw-block">
        <div class="rw-label">Current Time</div>
        <div id="rw-clock" class="rw-clock">--:--:--</div>
      </div>
      <div class="rw-block">
        <div class="rw-label">Quick Notes</div>
        <textarea id="rw-notes" class="rw-textarea" placeholder="Write a quick note..."></textarea>
        <div style="margin-top:8px; display:flex; gap:8px;">
          <button class="rw-btn" id="rw-save"><i class="fas fa-save" style="margin-right:6px;"></i>Save</button>
          <button class="rw-btn" id="rw-clear"><i class="fas fa-trash" style="margin-right:6px;"></i>Clear</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    let activePanel = null;
    const sidebarItems = document.querySelectorAll('.sidebar-item');
    const contentPanels = document.querySelectorAll('.content-panel');
    const mainContent = document.getElementById('main-content');
    const rightWidget = document.getElementById('right-widget');
    const rwToggleBtn = document.getElementById('rw-toggle');
    const rwClock = document.getElementById('rw-clock');
    const rwNotes = document.getElementById('rw-notes');
    const rwSave = document.getElementById('rw-save');
    const rwClear = document.getElementById('rw-clear');
    const rwIconButtons = document.querySelectorAll('.rw-icon-btn');

    // Add click event listeners to sidebar items
    sidebarItems.forEach(item => {
      item.addEventListener('click', () => {
        const panelName = item.getAttribute('data-panel');
        togglePanel(panelName, item);
      });
    });

    function togglePanel(panelName, clickedItem) {
      const panel = document.getElementById(panelName + '-panel');
      
      if (!panel) return;

      // If clicking the same panel, close it
      if (activePanel === panelName) {
        closePanel();
        return;
      }

      // Close any open panel
      closePanel();

      // Open the new panel
      panel.classList.add('active');
      clickedItem.classList.add('active');
      mainContent.classList.add('shifted');
      activePanel = panelName;
    }

    function closePanel() {
      if (activePanel) {
        const panel = document.getElementById(activePanel + '-panel');
        const item = document.querySelector(`[data-panel="${activePanel}"]`);
        
        if (panel) panel.classList.remove('active');
        if (item) item.classList.remove('active');
        
        mainContent.classList.remove('shifted');
        activePanel = null;
      }
    }

    // Close panel when clicking outside
    document.addEventListener('click', (e) => {
      if (!e.target.closest('.sidebar-container') && activePanel) {
        closePanel();
      }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && activePanel) {
        closePanel();
      }
    });

    // Add more panels for other apps (simplified)
    const otherPanels = ['telegram', 'discord', 'youtube', 'twitter', 'instagram', 'mail', 'calendar', 'notes'];
    otherPanels.forEach(panelName => {
      if (!document.getElementById(panelName + '-panel')) {
        const panel = document.createElement('div');
        panel.className = 'content-panel';
        panel.id = panelName + '-panel';
        panel.innerHTML = `
          <div class="panel-header">
            <button class="close-btn" onclick="closePanel()">&times;</button>
            <div class="panel-title">${panelName.charAt(0).toUpperCase() + panelName.slice(1)}</div>
            <div class="panel-subtitle">${panelName.charAt(0).toUpperCase() + panelName.slice(1)} App</div>
          </div>
          <div class="panel-content">
            <input type="text" class="search-box" placeholder="Search...">
            <div class="app-item">
              <div class="app-icon" style="background: #555;"><i class="fas fa-star"></i></div>
              <div class="app-info">
                <h4>${panelName.charAt(0).toUpperCase() + panelName.slice(1)} Content</h4>
                <p>Your ${panelName} content will appear here</p>
              </div>
            </div>
          </div>
        `;
        document.querySelector('.sidebar-container').appendChild(panel);
      }
    });

    // Right widget behavior
    function updateClock() {
      const now = new Date();
      const hh = String(now.getHours()).padStart(2, '0');
      const mm = String(now.getMinutes()).padStart(2, '0');
      const ss = String(now.getSeconds()).padStart(2, '0');
      rwClock.textContent = `${hh}:${mm}:${ss}`;
    }

    function toggleRightWidget() {
      rightWidget.classList.toggle('collapsed');
      const isCollapsed = rightWidget.classList.contains('collapsed');
      rwToggleBtn.innerHTML = isCollapsed ? '<i class="fas fa-chevron-down"></i>' : '<i class="fas fa-chevron-up"></i>';
      rwToggleBtn.title = isCollapsed ? 'Expand' : 'Collapse';
    }

    function loadNotes() {
      try {
        const saved = localStorage.getItem('rightWidgetNotes');
        if (saved !== null) rwNotes.value = saved;
      } catch (_) {}
    }

    function saveNotes() {
      try {
        localStorage.setItem('rightWidgetNotes', rwNotes.value);
      } catch (_) {}
    }

    function clearNotes() {
      rwNotes.value = '';
      saveNotes();
    }

    // Init
    updateClock();
    setInterval(updateClock, 1000);
    loadNotes();
    rwToggleBtn.addEventListener('click', toggleRightWidget);
    rwSave.addEventListener('click', saveNotes);
    rwClear.addEventListener('click', clearNotes);

    // Open panels via right-widget icons
    rwIconButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const panelName = btn.getAttribute('data-open');
        const relatedSidebarItem = document.querySelector(`[data-panel="${panelName}"]`);
        togglePanel(panelName, relatedSidebarItem);
      });
    });
  </script>
</body>
</html>