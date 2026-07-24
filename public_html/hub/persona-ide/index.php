<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../.cue/cue.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    $redirect = '/hub/persona-ide/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$user = (string)($_SESSION['mh_auth_user'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persona Headless IDE</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.persona-ide-page main.main-content { background: #0a0a0f; color: #e8e8f0; font-family: Rajdhani, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 28px 16px; }
        .card { background: rgba(10,18,40,0.65); border: 1px solid rgba(0,255,255,0.18); border-radius: 14px; padding: 18px; backdrop-filter: blur(10px); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .pill { padding: 8px 10px; border-radius: 999px; background: rgba(0,255,255,0.08); border: 1px solid rgba(0,255,255,0.16); color: #b9ffff; font-size: 14px; }
        textarea { width: 100%; min-height: 160px; resize: vertical; border-radius: 12px; border: 1px solid rgba(0,255,255,0.18); background: rgba(0,0,0,0.45); color: #e8e8f0; padding: 12px; font-size: 16px; outline: none; }
        button { border: 1px solid rgba(0,255,255,0.22); background: rgba(0,255,255,0.08); color: #b9ffff; padding: 10px 14px; border-radius: 10px; cursor: pointer; font-weight: 600; }
        button:disabled { opacity: 0.55; cursor: not-allowed; }
        pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.35); border: 1px solid rgba(0,255,255,0.12); border-radius: 12px; padding: 12px; margin: 12px 0 0; }
        h1 { font-family: Orbitron, Rajdhani, sans-serif; margin: 0 0 10px; font-size: 22px; }
        .muted { color: rgba(232,232,240,0.75); }
    </style>
</head>
<body class="persona-ide-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="wrap">
    <div class="card">
        <div class="row" style="justify-content: space-between; align-items: center;">
            <h1>Persona Headless IDE</h1>
            <div class="pill"><?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="muted">Generates a plan and workspace context under your tenant/persona.</div>
        <div class="row" style="margin-top: 10px;">
            <a class="pill" style="text-decoration:none" href="/hub/workbench/?mode=do">Open Workbench</a>
        </div>
        <div style="margin-top: 14px;">
            <textarea id="prompt" placeholder="Describe what you want the Persona to build or change..."></textarea>
        </div>
        <div class="row" style="margin-top: 12px;">
            <button id="btnWorkspace">Get Workspace</button>
            <button id="btnPlan">Generate Plan</button>
        </div>
        <pre id="out"></pre>
    </div>
</div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    const out = document.getElementById('out');
    const promptEl = document.getElementById('prompt');
    const btnWorkspace = document.getElementById('btnWorkspace');
    const btnPlan = document.getElementById('btnPlan');

    function setBusy(b) {
        btnWorkspace.disabled = b;
        btnPlan.disabled = b;
    }

    async function post(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload || {})
        });
        const text = await res.text();
        try { return JSON.parse(text); } catch { return { success: false, error: 'invalid_json', raw: text }; }
    }

    btnWorkspace.addEventListener('click', async () => {
        setBusy(true);
        out.textContent = '';
        try {
            const data = await post('/hub/persona-ide/api/workspace.php', { action: 'get' });
            out.textContent = JSON.stringify(data, null, 2);
        } finally {
            setBusy(false);
        }
    });

    btnPlan.addEventListener('click', async () => {
        setBusy(true);
        out.textContent = '';
        try {
            const prompt = (promptEl.value || '').trim();
            const data = await post('/hub/persona-ide/api/agent_plan.php', { prompt });
            out.textContent = JSON.stringify(data, null, 2);
        } finally {
            setBusy(false);
        }
    });
</script>
</body>
</html>
