<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('block_browser', false);

$root = isset($_GET['root']) ? (string)$_GET['root'] : 'data';
$path = isset($_GET['path']) ? (string)$_GET['path'] : '';
$root = preg_replace('/[^a-z_]/i', '', $root);
$path = str_replace(['..', '\\'], ['', '/'], $path);
$path = ltrim($path, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Block Browser</title>
    <style>
        body { background:#0a0a0a; color:#e0e0e0; font-family: Arial, sans-serif; margin:0; padding:20px; }
        .bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
        select,input,button { background: rgba(255,255,255,0.05); border:1px solid rgba(0,212,255,0.25); color:#fff; padding:10px 12px; border-radius:10px; }
        button { cursor:pointer; }
        a { color:#00d4ff; text-decoration:none; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
        .panel { border:1px solid rgba(0,212,255,0.2); border-radius:12px; padding:14px; background: rgba(255,255,255,0.03); }
        .title { font-weight:700; margin-bottom:10px; color:#00d4ff; }
        ul { margin:0; padding-left:18px; }
        li { margin:6px 0; }
        .muted { color:#9aa; font-size: 12px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="bar">
        <label>Root</label>
        <select id="root">
            <option value="data">/data</option>
            <option value="backup">/backup</option>
            <option value="vector">/vector</option>
            <option value="graph">/graph</option>
            <option value="mysql">/mysql</option>
        </select>
        <label>Path</label>
        <input id="path" type="text" style="min-width:320px" />
        <button id="go" type="button">Open</button>
        <span id="status" class="muted"></span>
    </div>

    <div class="grid">
        <div class="panel">
            <div class="title">Folders</div>
            <ul id="folders"></ul>
        </div>
        <div class="panel">
            <div class="title">Files</div>
            <ul id="files"></ul>
        </div>
    </div>

    <script>
        const rootEl = document.getElementById('root');
        const pathEl = document.getElementById('path');
        const foldersEl = document.getElementById('folders');
        const filesEl = document.getElementById('files');
        const statusEl = document.getElementById('status');

        function setStatus(msg) { statusEl.textContent = msg || ''; }

        async function load() {
            const root = rootEl.value;
            const path = pathEl.value || '';
            setStatus('Loading...');
            foldersEl.innerHTML = '';
            filesEl.innerHTML = '';
            const resp = await fetch('/gear/settings/browse-block.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ root, path })
            });
            const data = await resp.json().catch(() => null);
            if (!data || !data.success) {
                setStatus((data && data.error) ? data.error : 'Failed');
                return;
            }
            setStatus(data.base + '/' + (data.path || ''));

            const up = (data.path || '').split('/').filter(Boolean);
            if (up.length > 0) {
                const parent = up.slice(0, -1).join('/');
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = '..';
                a.onclick = (e) => { e.preventDefault(); pathEl.value = parent; load(); };
                li.appendChild(a);
                foldersEl.appendChild(li);
            }

            (data.folders || []).forEach(f => {
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.href = '#';
                a.textContent = f + '/';
                a.onclick = (e) => { e.preventDefault(); pathEl.value = (data.path ? (data.path + '/') : '') + f; load(); };
                li.appendChild(a);
                foldersEl.appendChild(li);
            });

            (data.files || []).forEach(f => {
                const li = document.createElement('li');
                li.textContent = f;
                filesEl.appendChild(li);
            });
        }

        document.getElementById('go').addEventListener('click', () => load());
        rootEl.addEventListener('change', () => { pathEl.value = ''; load(); });

        rootEl.value = <?php echo json_encode($root); ?>;
        pathEl.value = <?php echo json_encode($path); ?>;
        load();
    </script>
</body>
</html>
