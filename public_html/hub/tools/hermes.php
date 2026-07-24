<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php?redirect=%2Fhub%2Ftools%2Fhermes.php');
    exit;
}

$username = trim((string)($_SESSION['mh_auth_user'] ?? ''));
$tenantId = (string)($_SESSION['mh_tenant_id'] ?? '');
if ($tenantId === '' && $username !== '') {
    $tenantId = 'user:' . $username;
}

$personaId = (string)($_SESSION['mh_selected_persona'] ?? '');
if ($personaId === '') {
    $personaId = (string)($_SESSION['mh_auth_persona'] ?? '');
}
if ($personaId === '' && $username !== '') {
    $personaId = 'MH-' . $username;
}

$slugSource = $personaId !== '' ? $personaId : $username;
$slug = strtolower($slugSource);
$slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
$slug = trim((string)$slug, '_');
if ($slug === '') {
    $slug = 'user';
}
if (!preg_match('/^[a-z0-9]/', $slug)) {
    $slug = 'u_' . $slug;
}
if (strlen($slug) > 64) {
    $slug = substr($slug, 0, 64);
}
if ($slug === 'default') {
    $slug = 'u_default';
}

setcookie('hermes_profile', $slug, [
    'expires' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hermes</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.hermes-embed main.main-content { padding: 0; }
        body.hermes-embed .wrap { max-width: 1400px; margin: 0 auto; padding: 18px 24px; box-sizing: border-box; }
        body.hermes-embed .meta { display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom: 12px; }
        body.hermes-embed .pill { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); font-size:12px; color: rgba(230,246,255,.9); }
        body.hermes-embed a.pill { color: rgba(230,246,255,.9); text-decoration:none; }
        body.hermes-embed a.pill:hover { text-decoration: underline; }
        body.hermes-embed .frame { width: 100%; height: calc(100vh - 260px); min-height: 720px; border: 1px solid rgba(255,255,255,.14); border-radius: 14px; overflow: hidden; background: rgba(0,0,0,.15); }
        body.hermes-embed iframe { width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body class="hermes-embed">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <div class="meta">
            <div>
                <h1 style="margin:0">Hermes</h1>
                <div style="margin-top:6px; opacity:.85; font-size:12px;">Queen node: model routing, personas, memory, and tools.</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
                <a class="pill" href="/hermes-webui/" target="_blank" rel="noopener">open</a>
                <a class="pill" href="/gear/hermes/settings.php">settings</a>
                <a class="pill" href="/hermes/healthz" target="_blank" rel="noopener">healthz</a>
                <span class="pill">user: <?php echo h($username); ?></span>
                <span class="pill">tenant: <?php echo h($tenantId); ?></span>
                <span class="pill">persona: <?php echo h($personaId); ?></span>
                <span class="pill">profile: <?php echo h($slug); ?></span>
            </div>
        </div>

        <div class="frame">
            <iframe src="/hermes-webui/" allow="microphone; autoplay; clipboard-write" allowfullscreen></iframe>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
