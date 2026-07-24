<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) { define('CUE_DISABLE_AUTO_UI', true); }
if (!defined('CUE_LAYOUT_MANUAL')) { define('CUE_LAYOUT_MANUAL', true); }

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/sr_client.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function mh_grid_health_wants_json(): bool
{
    $format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : '';
    if ($format === 'json') return true;
    return false;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$wantsJson = mh_grid_health_wants_json();

if (!isset($_SESSION['mh_auth_user'])) {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'not_authenticated'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: /auth/login.php');
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
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$cfg = mh_grid_read_cfg();
$baseUrl = isset($cfg['base_url']) && is_string($cfg['base_url']) ? (string)$cfg['base_url'] : '';
$tokenId = isset($cfg['token_id']) && is_string($cfg['token_id']) ? (string)$cfg['token_id'] : '';
$clientSecret = isset($cfg['client_secret']) && is_string($cfg['client_secret']) ? (string)$cfg['client_secret'] : '';
$allowlist = isset($cfg['allowlist']) && is_array($cfg['allowlist']) ? $cfg['allowlist'] : [];
$cfgPath = mh_grid_cfg_path();

$payload = [
    'ok' => true,
    'cfg_path' => $cfgPath,
    'base_url_present' => ($baseUrl !== ''),
    'credentials_present' => ($tokenId !== '' && $clientSecret !== ''),
    'allowlist_count' => count($allowlist),
];

if ($wantsJson) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grid Health</title>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
<style>
  .mh-page{max-width:1100px;margin:0 auto;padding:18px 20px}
  .mh-page-header{display:flex;flex-direction:column;gap:12px}
  .mh-page-title{margin:0}
  .mh-page-actions{display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:12px 16px;border-radius:10px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s ease;cursor:pointer}
  .btn-primary{background:linear-gradient(135deg,rgba(var(--theme-primary-rgb),.95),rgba(var(--theme-secondary-rgb),.95));color:#000;border:0}
  .btn-secondary{background:rgba(255,255,255,.06);border:1px solid rgba(var(--theme-primary-rgb),.35);color:var(--theme-text,#e8eefc)}
  .btn-secondary:hover{background:rgba(var(--theme-primary-rgb),.12);border-color:rgba(var(--theme-primary-rgb),.55)}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(var(--theme-primary-rgb),.18)}
  .card{background:rgba(255,255,255,.05);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:14px;margin:12px 0}
  .mh-help{font-size:12px;opacity:.8}
  .mh-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .mh-kv{display:grid;gap:12px}
  .mh-row{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center}
  .mh-pill{display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:11px;letter-spacing:.02em;padding:5px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04)}
  .mh-pill.ok{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.12)}
  .mh-pill.bad{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12)}
  @media (min-width: 900px){
    .mh-page-header{flex-direction:row;align-items:flex-end;justify-content:space-between}
  }
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content mh-page">
  <div class="mh-page-header">
    <h1 class="mh-page-title">Grid Health</h1>
    <div class="mh-page-actions">
      <a class="btn btn-secondary" href="/hub/">Hub</a>
      <a class="btn btn-secondary" href="/control/grid-settings.php">Grid Settings</a>
      <a class="btn btn-secondary" href="/control/grid-webhooks.php">Grid Webhooks</a>
    </div>
  </div>

  <div class="card">
    <div class="mh-kv">
      <div class="mh-row">
        <div class="mh-help">Config path</div>
        <div class="mh-code"><code><?php echo h($cfgPath); ?></code></div>
      </div>
      <div class="mh-row">
        <div>Base URL</div>
        <div class="mh-pill <?php echo $payload['base_url_present'] ? 'ok' : 'bad'; ?>"><?php echo $payload['base_url_present'] ? 'Present' : 'Missing'; ?></div>
      </div>
      <div class="mh-row">
        <div>Credentials</div>
        <div class="mh-pill <?php echo $payload['credentials_present'] ? 'ok' : 'bad'; ?>"><?php echo $payload['credentials_present'] ? 'Present' : 'Missing'; ?></div>
      </div>
      <div class="mh-row">
        <div>Allowlist count</div>
        <div class="mh-code"><code><?php echo h((string)$payload['allowlist_count']); ?></code></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="mh-help">Raw</div>
    <div class="mh-code" style="margin-top:8px"><code><?php echo h(json_encode($payload, JSON_UNESCAPED_SLASHES)); ?></code></div>
    <div style="margin-top:10px">
      <a class="btn btn-secondary" href="/gear/grid/health.json.php?format=json">View JSON</a>
    </div>
  </div>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
