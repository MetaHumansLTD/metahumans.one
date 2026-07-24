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

$executionId = isset($_GET['execution_id']) ? trim((string)$_GET['execution_id']) : '';
if ($executionId === '') {
    header('Location: /hub/sop/');
    exit;
}

$ctx = sop_get_context();
$pdo = sop_get_pdo();
sop_ensure_schema($pdo);

$exec = sop_get_execution($pdo, (string)$ctx['tenant_id'], $executionId);
if (!$exec) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}
$tasks = sop_list_tasks_for_execution($pdo, (string)$ctx['tenant_id'], $executionId);

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrf = $security ? $security->generateCSRFToken('sop') : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Execution | SOP</title>
  <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.sop-exec main.main-content { color: rgba(255,255,255,0.92); }
    body.sop-exec .wrap { max-width: 1100px; margin: 0 auto; padding: 28px 18px; }
    body.sop-exec h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 14px; }
    body.sop-exec .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px); margin-bottom: 16px; }
    body.sop-exec .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
    body.sop-exec table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    body.sop-exec th, body.sop-exec td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
    body.sop-exec th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
    body.sop-exec .btn { display: inline-flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.35); background: rgba(0, 212, 255, 0.06); color: rgba(255,255,255,0.92); text-decoration: none; cursor: pointer; font-weight: 700; }
    body.sop-exec .btn.primary { background: rgba(0, 212, 255, 0.16); }
  </style>
</head>
<body class="sop-exec">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="wrap">
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
        <div>
          <h1>Execution</h1>
          <div class="muted">ID: <?php echo htmlspecialchars($executionId); ?></div>
          <div class="muted">SOP: <?php echo htmlspecialchars((string)($exec['sop_id'] ?? '') . '@' . (string)($exec['sop_version'] ?? '')); ?></div>
          <div class="muted">Status: <?php echo htmlspecialchars((string)($exec['status'] ?? '')); ?></div>
        </div>
        <div>
          <a class="btn" href="/hub/sop/">Back</a>
        </div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:800;">Tasks</div>
      <table>
        <thead>
          <tr>
            <th>Step</th>
            <th>Name</th>
            <th>Role</th>
            <th>Assigned</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tasks as $t): ?>
            <tr>
              <td><?php echo (int)($t['step_number'] ?? 0); ?></td>
              <td><?php echo htmlspecialchars((string)($t['step_name'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($t['required_role'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($t['assigned_to_username'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($t['status'] ?? '')); ?></td>
              <td style="text-align:right;">
                <a class="btn primary" href="/hub/sop/task.php?task_id=<?php echo rawurlencode((string)($t['task_id'] ?? '')); ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>

