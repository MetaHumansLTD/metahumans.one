<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../../.cue/cue.php';
require_once __DIR__ . '/../../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    $redirect = '/hub/workbench/panels/persona-panel-preview.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$username = (string)($_SESSION['mh_auth_user'] ?? '');
mh_auth_load_user_context($username);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persona Panel Preview</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.persona-panel-preview-page main.main-content { background: #0a0a0f; color: #e8e8f0; font-family: Rajdhani, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 28px 16px; }
        .card { background: rgba(10,18,40,0.65); border: 1px solid rgba(0,255,255,0.18); border-radius: 14px; padding: 18px; backdrop-filter: blur(10px); }
        pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.35); border: 1px solid rgba(0,255,255,0.12); border-radius: 12px; padding: 12px; margin: 12px 0 0; font-size: 12px; }
        button { border: 1px solid rgba(0,255,255,0.22); background: rgba(0,255,255,0.08); color: #b9ffff; padding: 10px 14px; border-radius: 10px; cursor: pointer; font-weight: 600; }
        .row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
        .pill { padding: 8px 10px; border-radius: 999px; background: rgba(0,255,255,0.08); border: 1px solid rgba(0,255,255,0.16); color: #b9ffff; font-size: 14px; }
        a { color: #b9ffff; text-decoration: none; }
    </style>
</head>
<body class="persona-panel-preview-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="wrap">
    <div class="card">
        <div class="row">
            <div>
                <div style="font-family: Orbitron, Rajdhani, sans-serif; font-size: 18px; margin-bottom: 6px;">Persona Panel Preview</div>
                <div style="color: rgba(232,232,240,0.75);">This is the same context wiring used by the Persona panel MVP.</div>
            </div>
            <div class="row">
                <span class="pill"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></span>
                <a class="pill" href="/hub/workbench/?mode=develop">Back</a>
                <button id="btn">Refresh</button>
            </div>
        </div>
        <pre id="out"></pre>
    </div>
</div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    const out = document.getElementById('out');
    const btn = document.getElementById('btn');
    async function load() {
        btn.disabled = true;
        try {
            const r = await fetch('/hub/workbench/api/context.php', { headers: { 'accept': 'application/json' } });
            const t = await r.text();
            out.textContent = t;
        } finally {
            btn.disabled = false;
        }
    }
    btn.addEventListener('click', load);
    load();
</script>
</body>
</html>
