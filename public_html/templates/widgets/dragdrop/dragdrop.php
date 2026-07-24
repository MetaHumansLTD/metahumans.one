<?php
// Load CUE framework with resilient path resolution
$cueCandidate = dirname(dirname(__DIR__)) . '/.cue/cue.php'; // two-level up (templates)
if (!file_exists($cueCandidate)) {
    // For files deeper under templates/widgets/dragdrop/, go up one more level
    $cueAlt = dirname(__DIR__, 3) . '/.cue/cue.php'; // public_html/.cue/cue.php
    if (file_exists($cueAlt)) {
        $cueCandidate = $cueAlt;
    }
}
require_once $cueCandidate;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Drag & Drop Widget Settings</title>
  <style>
    body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:24px}
    .card{background:#0b1220;border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:16px;margin-bottom:16px}
    .row{display:flex;gap:12px;align-items:center;margin:8px 0}
    label{min-width:160px;color:#cbd5e1}
    input[type=text]{background:#0f172a;border:1px solid rgba(255,255,255,0.1);border-radius:6px;padding:8px;color:#e5e7eb;flex:1}
    .btn{background:#2563eb;border:none;color:#fff;padding:10px 14px;border-radius:8px;cursor:pointer}
    code{background:#0f172a;border:1px solid rgba(255,255,255,0.1);padding:2px 6px;border-radius:6px}
    pre{background:#0f172a;border:1px solid rgba(255,255,255,0.1);padding:12px;border-radius:10px;overflow:auto}
  </style>
</head>
<body>
  <h1>Drag & Drop Widget</h1>
  <p>This page provides configuration and usage guidance for the reusable Drag & Drop widget. The widget enables keyboard, mouse, and touch reordering of items like social links while preserving interactive behaviors.</p>

  <div class="card">
    <h2>Quick Start</h2>
    <p>Include the widget where you need it:</p>
    <pre><?php echo htmlspecialchars('<?php
$path = getSecureFilePath(dirname(__DIR__) . \'/widgets/dragdrop/widget.php\');
if ($path) include $path;
?>'); ?></pre>
    <p>Mount it on a container:</p>
    <pre><code>&lt;div id="social-links" class="menu-social-container"&gt;...&lt;/div&gt;
&lt;script&gt;
  const container = document.getElementById('social-links');
  const items = [
    { id: 'gh', name: 'GitHub', url: 'https://github.com/...' },
    { id: 'tw', name: 'Twitter', url: 'https://twitter.com/...' }
  ];
  const widget = window.DragDropWidget.mount(container, items, { instanceId: 'social:main' });
  widget.on('orderChange', ({ order }) => console.log('New order:', order));
&lt;/script&gt;</code></pre>
  </div>

  <div class="card">
    <h2>Configuration Options</h2>
    <ul>
      <li><code>items</code>: Array of item objects: <code>{ id, name, url }</code>.</li>
      <li><code>instanceId</code>: Unique string for local persistence across instances.</li>
      <li><code>persist</code>: Boolean (default true). Persist order to <code>localStorage</code>.</li>
      <li><code>itemSelector</code>: CSS selector for item nodes (default <code>.ddw-item</code>).</li>
      <li><code>idAttr</code>: Attribute for item identity (default <code>data-id</code>).</li>
    </ul>
  </div>

  <div class="card">
    <h2>Events</h2>
    <ul>
      <li><code>ready</code>: Emitted when widget is initialized.</li>
      <li><code>moveStart</code>: When a drag begins (mouse, keyboard, or touch).</li>
      <li><code>drop</code>: When a drag operation completes.</li>
      <li><code>orderChange</code>: After DOM order changes; payload: <code>{ order: string[] }</code>.</li>
    </ul>
  </div>

  <div class="card">
    <h2>Notes & Limits</h2>
    <ul>
      <li>Social links remain functional; link clicks and button actions are not captured for drag.</li>
      <li>Multiple instances are supported via unique <code>instanceId</code> values.</li>
      <li>Persistence uses <code>localStorage</code> for non-sensitive UI state.</li>
      <li>Browser support: modern evergreen browsers with pointer events; gracefully degrades to mouse/keyboard.</li>
    </ul>
  </div>
</body>
</html>
