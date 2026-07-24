<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    header('Location: /hub/login.php');
    exit;
}

mh_auth_load_user_context($_SESSION['mh_auth_user']);
$tokenBalance = (int)($_SESSION['tokens'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Hide</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        input, textarea, button { font: inherit; }
        input, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 10px 14px; border: 1px solid #222; border-radius: 10px; background: #111; color: #fff; cursor: pointer; }
        pre { background: #0b0b0b; color: #e8e8e8; padding: 12px; border-radius: 10px; overflow: auto; }
        .meta { color: #666; font-size: 14px; }
        .card { border: 1px solid #e6e6e6; border-radius: 12px; padding: 16px; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Hide</h1>
    <div class="meta">
        <a href="/hub/wallet.php">Tokens: <?php echo (int)$tokenBalance; ?></a>
    </div>

    <div class="card">
        <div class="row">
            <div style="flex:1; min-width: 320px;">
                <label for="repo">Repository URL (https)</label>
                <input id="repo" placeholder="https://github.com/org/repo.git" />
            </div>
            <div style="flex:1; min-width: 320px;">
                <label for="ref">Ref (branch/tag/commit)</label>
                <input id="ref" placeholder="main" value="main" />
            </div>
        </div>
        <div style="margin-top: 12px;">
            <label for="cmd">Command</label>
            <textarea id="cmd" rows="6" placeholder="e.g. pytest -q"></textarea>
        </div>
        <div style="margin-top: 12px;">
            <button id="run">Run (2 tokens)</button>
        </div>
    </div>

    <div class="card">
        <div class="meta" id="status">Idle</div>
        <pre id="out"></pre>
    </div>

    <script>
        const out = document.getElementById('out');
        const status = document.getElementById('status');
        const runBtn = document.getElementById('run');

        async function run() {
            out.textContent = '';
            status.textContent = 'Running...';
            runBtn.disabled = true;
            try {
                const response = await fetch('/hub/hide/api/exec.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        repo: document.getElementById('repo').value,
                        ref: document.getElementById('ref').value,
                        cmd: document.getElementById('cmd').value,
                    })
                });
                const hdr = response.headers.get('X-MH-Tokens-Remaining');
                if (hdr !== null) {
                    status.textContent = `Done (tokens remaining: ${hdr})`;
                }
                const data = await response.json();
                if (!response.ok) {
                    status.textContent = `Error: ${data?.error || 'request_failed'}`;
                }
                out.textContent = data?.output || '';
            } catch (e) {
                status.textContent = 'Error';
                out.textContent = String(e);
            } finally {
                runBtn.disabled = false;
            }
        }

        runBtn.addEventListener('click', run);
    </script>
</body>
</html>
