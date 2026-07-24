<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';

if (function_exists('cue_autoload')) {
    cue_autoload('security');
    cue_autoload('sop');
}

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim($_SESSION['mh_auth_user']) === '') {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/sop/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/hub/sop/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$ctx = sop_get_context();
$dbConfigId = '';
if (isset($_SESSION['current_database_config_id']) && is_string($_SESSION['current_database_config_id'])) {
    $dbConfigId = trim((string)$_SESSION['current_database_config_id']);
} elseif (isset($_SESSION['mh_db_preference']) && is_string($_SESSION['mh_db_preference'])) {
    $dbConfigId = trim((string)$_SESSION['mh_db_preference']);
}

$dbOk = false;
$dbMs = null;
$dbError = null;
$pdo = null;
$t0 = microtime(true);
try {
    $pdo = sop_get_pdo();
    sop_ensure_schema($pdo);
    $dbOk = true;
} catch (Throwable $e) {
    $dbError = get_class($e);
}
$dbMs = (int)round((microtime(true) - $t0) * 1000);

$sops = [];
$executions = [];
if ($pdo instanceof PDO) {
    $sops = sop_list_sops($pdo, (string)$ctx['tenant_id'], 300);
    $executions = sop_list_executions($pdo, (string)$ctx['tenant_id'], 50);
}

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrf = $security ? $security->generateCSRFToken('sop') : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SOP | Meta Humans Hub</title>
  <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.sop-page main.main-content { color: rgba(255,255,255,0.92); }
    body.sop-page .wrap { max-width: 1200px; margin: 0 auto; padding: 28px 18px; }
    body.sop-page h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 18px; }
    body.sop-page .grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
    body.sop-page .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px); }
    body.sop-page .row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
    body.sop-page .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
    body.sop-page table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    body.sop-page th, body.sop-page td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
    body.sop-page th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
    body.sop-page .btn { display: inline-flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.35); background: rgba(0, 212, 255, 0.06); color: rgba(255,255,255,0.92); text-decoration: none; cursor: pointer; font-weight: 700; }
    body.sop-page .btn.primary { background: rgba(0, 212, 255, 0.16); }
    body.sop-page .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    body.sop-page input, body.sop-page textarea, body.sop-page select { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(255,255,255,0.03); color: #fff; }
    body.sop-page .split { display: grid; grid-template-columns: 1fr; gap: 12px; }
    body.sop-page .badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(255,255,255,0.03); font-size: 0.9rem; }
    body.sop-page .badge.ok { border-color: rgba(0, 212, 255, 0.35); }
    body.sop-page .badge.fail { border-color: rgba(255, 84, 84, 0.5); background: rgba(255, 84, 84, 0.08); }
    body.sop-page .badge .dot { width: 9px; height: 9px; border-radius: 999px; background: rgba(255,255,255,0.4); }
    body.sop-page .badge.ok .dot { background: rgba(0, 212, 255, 0.9); }
    body.sop-page .badge.fail .dot { background: rgba(255, 84, 84, 0.95); }
    @media (min-width: 980px) { body.sop-page .grid { grid-template-columns: 2fr 1fr; } }
  </style>
</head>
<body class="sop-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="wrap">
    <div class="row">
      <h1>SOP</h1>
      <div class="row" style="justify-content:flex-end;">
        <div class="muted">Tenant: <?php echo htmlspecialchars((string)$ctx['tenant_id']); ?></div>
        <div class="badge <?php echo $dbOk ? 'ok' : 'fail'; ?>" title="<?php echo htmlspecialchars($dbOk ? 'Tenant DB reachable' : 'Tenant DB not reachable'); ?>">
          <span class="dot"></span>
          <span>DB</span>
          <span class="muted"><?php echo htmlspecialchars($dbConfigId !== '' ? $dbConfigId : 'unknown'); ?></span>
          <span class="muted"><?php echo htmlspecialchars($dbOk ? 'OK' : 'FAIL'); ?></span>
          <span class="muted"><?php echo htmlspecialchars((string)$dbMs . 'ms'); ?></span>
        </div>
      </div>
    </div>

    <?php if (!$dbOk): ?>
      <div class="card" style="margin-top:14px;">
        <div style="font-weight:800;">Database connection failed</div>
        <div class="muted" style="margin-top:6px;">This page uses the tenant-scoped database context. Check /data/logs/error.log and tenant provisioning for this tenant.</div>
        <?php if (is_string($dbError) && $dbError !== ''): ?>
          <div class="muted" style="margin-top:6px;">Error type: <?php echo htmlspecialchars($dbError); ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="grid">
      <div class="card">
        <div class="row">
          <div>
            <div style="font-weight:800;">Authorized SOPs</div>
            <div class="muted">Execute an SOP to create an execution record and the first task.</div>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>SOP</th>
              <th>Title</th>
              <th>Status</th>
              <th>Created</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sops as $s): ?>
              <?php $status = (string)($s['status'] ?? ''); ?>
              <?php if ($status !== 'authorized') continue; ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($s['sop_id'] ?? '') . '@' . (string)($s['version'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($s['title'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($status); ?></td>
                <td><?php echo htmlspecialchars((string)($s['created_at'] ?? '')); ?></td>
                <td style="text-align:right;">
                  <button class="btn primary" type="button" onclick="executeSop('<?php echo htmlspecialchars((string)($s['sop_id'] ?? ''), ENT_QUOTES); ?>','<?php echo htmlspecialchars((string)($s['version'] ?? ''), ENT_QUOTES); ?>')">Execute</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div style="font-weight:800;margin-bottom:8px;">Recent Executions</div>
        <div class="muted" style="margin-bottom:10px;">Click an execution to view tasks and status.</div>
        <table>
          <thead>
            <tr>
              <th>Execution</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($executions as $e): ?>
              <tr>
                <td><a class="btn" href="/hub/sop/execution.php?execution_id=<?php echo rawurlencode((string)($e['execution_id'] ?? '')); ?>">View</a></td>
                <td><?php echo htmlspecialchars((string)($e['status'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
function executeSop(sopId, version) {
  const fd = new URLSearchParams();
  fd.set('csrf_token', <?php echo json_encode($csrf); ?>);
  fd.set('action', 'sop_execute');
  fd.set('sop_id', sopId);
  fd.set('version', version);
  fetch('/hub/sop/action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: fd.toString()
  }).then(r => r.json()).then(d => {
    if (d && d.success && d.execution_id) {
      window.location.href = '/hub/sop/execution.php?execution_id=' + encodeURIComponent(d.execution_id);
      return;
    }
    alert('Execute failed: ' + (d && (d.error || d.message) ? (d.error || d.message) : 'Unknown error'));
  }).catch(e => alert('Execute failed: ' + (e && e.message ? e.message : 'Network error')));
}
</script>
</body>
</html>
