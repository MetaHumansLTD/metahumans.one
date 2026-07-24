<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../.cue/multica.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/hub/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
$isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
if (!$isKripz) {
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($u !== '' && function_exists('mh_auth_load_user_context')) {
        try { mh_auth_load_user_context($u); } catch (Throwable $e) {}
        $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
        $isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
    }
}
if (!$isKripz) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$cfg = mh_multica_read_cfg();
$message = '';
$error = '';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/gear/multica/settings.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/gear/multica/settings.php';
}
$flash = $_SESSION['mh_multica_settings_flash'] ?? null;
if (is_array($flash)) {
    $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $error = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
}
unset($_SESSION['mh_multica_settings_flash']);

$uiUrl = isset($cfg['ui_url']) && is_string($cfg['ui_url']) ? (string)$cfg['ui_url'] : '';
$apiUrl = isset($cfg['api_url']) && is_string($cfg['api_url']) ? (string)$cfg['api_url'] : '';
$mode = isset($cfg['mode']) && is_string($cfg['mode']) ? (string)$cfg['mode'] : 'cloud';
$runtimeLabel = isset($cfg['runtime_label']) && is_string($cfg['runtime_label']) ? (string)$cfg['runtime_label'] : 'metahumans.one';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'metahumans.one');
$selfHostUi = 'https://' . ($host !== '' ? $host : 'metahumans.one') . ':8445/';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $mode = trim((string)($_POST['mode'] ?? $mode));
        $uiUrl = trim((string)($_POST['ui_url'] ?? $uiUrl));
        $apiUrl = trim((string)($_POST['api_url'] ?? $apiUrl));
        $runtimeLabel = trim((string)($_POST['runtime_label'] ?? $runtimeLabel));
        if ($mode === '') $mode = 'cloud';
        if ($runtimeLabel === '') $runtimeLabel = 'metahumans.one';

        $cfg['mode'] = $mode;
        $cfg['ui_url'] = $uiUrl;
        $cfg['api_url'] = $apiUrl;
        $cfg['runtime_label'] = $runtimeLabel;
        mh_multica_write_cfg($cfg);
        $message = 'Saved';
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
    if (!headers_sent()) {
        $_SESSION['mh_multica_settings_flash'] = [
            'message' => $message,
            'error' => $error,
        ];
        header('Location: ' . $requestUri, true, 303);
        exit;
    }
}

$cfgPath = mh_multica_cfg_path();
$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');

$multicaBin = mh_multica_find_bin();
$multicaVer = '';
if ($multicaBin !== '' && function_exists('shell_exec')) {
    $out = @shell_exec(escapeshellarg($multicaBin) . ' --version 2>/dev/null');
    if (is_string($out)) $multicaVer = trim($out);
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Multica Settings</title>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
<style>
html,body{background-color:var(--background-color,#0a0a1a) !important;color:var(--text-color,#ffffff) !important;}
main{max-width:1100px;margin:0 auto;padding:18px 20px}
main.main-content .card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin:12px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}
main.main-content .field{display:grid;gap:6px;margin-bottom:10px}
main.main-content .label{font-size:12px;color:#bcd3f1}
main.main-content .input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#e6f0ff}
main.main-content .row{display:flex;gap:10px;align-items:center}
main.main-content .btn{border-radius:10px;border:1px solid rgba(42,167,255,.55);background:rgba(42,167,255,.18);color:#e6f0ff;padding:10px 12px;font-weight:700;cursor:pointer}
main.main-content .msg{padding:10px 12px;border-radius:10px;margin:12px 0;border:1px solid rgba(33,208,122,.35);background:rgba(33,208,122,.12)}
main.main-content .err{padding:10px 12px;border-radius:10px;margin:12px 0;border:1px solid rgba(255,91,91,.35);background:rgba(255,91,91,.12)}
pre{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.20);border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:10px;margin:0}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="row" style="justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
  <h1 style="margin:0">Multica Settings</h1>
  <div class="row" style="gap:12px">
    <a class="btn" href="/hub/agents/multi.php">Agents UI</a>
    <?php if ($uiUrl !== ''): ?><a class="btn" href="<?php echo h($uiUrl); ?>" target="_blank" rel="noopener">Open Multica</a><?php endif; ?>
  </div>
</div>
<?php if ($message !== ''): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
<form method="post" action="">
<div class="grid">
  <div class="card">
    <h2>Config</h2>
    <label class="field">
      <span class="label">Mode</span>
      <select class="input" name="mode">
        <option value="cloud" <?php echo $mode === 'cloud' ? 'selected' : ''; ?>>cloud</option>
        <option value="self_host" <?php echo $mode === 'self_host' ? 'selected' : ''; ?>>self_host</option>
      </select>
    </label>
    <label class="field"><span class="label">UI URL</span><input class="input" name="ui_url" value="<?php echo h($uiUrl); ?>" placeholder="<?php echo h($mode === 'self_host' ? $selfHostUi : 'https://multica.ai/app'); ?>"></label>
    <label class="field"><span class="label">API URL (optional)</span><input class="input" name="api_url" value="<?php echo h($apiUrl); ?>" placeholder="https://…"></label>
    <label class="field"><span class="label">Runtime label</span><input class="input" name="runtime_label" value="<?php echo h($runtimeLabel); ?>"></label>
    <div class="row" style="margin-top:10px">
      <button class="btn" type="submit">Save</button>
    </div>
    <div style="margin-top:10px">
      <div class="label">Config path</div>
      <div><code><?php echo h($cfgPath); ?></code></div>
    </div>
  </div>
  <div class="card">
    <h2>Local CLI</h2>
    <div class="label">multica</div>
    <pre><?php echo h($multicaBin !== '' ? $multicaBin : 'not_found'); ?></pre>
    <div class="label" style="margin-top:10px">version</div>
    <pre><?php echo h($multicaVer !== '' ? $multicaVer : 'unavailable'); ?></pre>
  </div>
</div>
</form>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
