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

$taskId = isset($_GET['task_id']) ? trim((string)$_GET['task_id']) : '';
if ($taskId === '') {
    header('Location: /hub/sop/');
    exit;
}

$ctx = sop_get_context();
$pdo = sop_get_pdo();
sop_ensure_schema($pdo);

$task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
if (!$task) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$exec = sop_get_execution($pdo, (string)$ctx['tenant_id'], (string)($task['execution_id'] ?? ''));
$sopId = (string)($task['sop_id'] ?? '');
$version = (string)($task['sop_version'] ?? '');
$stepNumber = (int)($task['step_number'] ?? 0);
$step = sop_get_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, $stepNumber);
$evidence = sop_list_evidence($pdo, (string)$ctx['tenant_id'], $taskId);
$approvals = function_exists('sop_list_approvals') ? sop_list_approvals($pdo, (string)$ctx['tenant_id'], $taskId) : [];
$requiredApprovals = $step ? sop_required_approvals_from_step($step) : ['required' => false, 'roles' => [], 'quorum' => 0];

$assignee = isset($task['assigned_to_principal_id']) ? (string)$task['assigned_to_principal_id'] : '';
$canAct = $assignee === '' || $assignee === (string)$ctx['principal_id'] || (function_exists('sop_is_director') && sop_is_director());

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrf = $security ? $security->generateCSRFToken('sop') : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Task | SOP</title>
  <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.sop-task main.main-content { color: rgba(255,255,255,0.92); }
    body.sop-task .wrap { max-width: 980px; margin: 0 auto; padding: 28px 18px; }
    body.sop-task h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 14px; }
    body.sop-task .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px); margin-bottom: 16px; }
    body.sop-task .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
    body.sop-task .btn { display: inline-flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.35); background: rgba(0, 212, 255, 0.06); color: rgba(255,255,255,0.92); text-decoration: none; cursor: pointer; font-weight: 700; }
    body.sop-task .btn.primary { background: rgba(0, 212, 255, 0.16); }
    body.sop-task .btn.danger { border-color: rgba(244,63,94,0.55); background: rgba(244,63,94,0.08); }
    body.sop-task .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    body.sop-task input, body.sop-task textarea, body.sop-task select { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(255,255,255,0.03); color: #fff; }
    body.sop-task table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    body.sop-task th, body.sop-task td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
    body.sop-task th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
    body.sop-task .two { display:grid; grid-template-columns: 1fr; gap: 12px; }
    @media (min-width: 920px){ body.sop-task .two { grid-template-columns: 1fr 1fr; } }
  </style>
</head>
<body class="sop-task">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="wrap">
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
        <div>
          <h1>Task</h1>
          <div class="muted">Task: <?php echo htmlspecialchars($taskId); ?></div>
          <div class="muted">Execution: <?php echo htmlspecialchars((string)($task['execution_id'] ?? '')); ?></div>
          <div class="muted">SOP: <?php echo htmlspecialchars($sopId . '@' . $version); ?></div>
          <div class="muted">Step: <?php echo (int)$stepNumber; ?> · <?php echo htmlspecialchars((string)($task['step_name'] ?? '')); ?></div>
          <div class="muted">Assigned: <?php echo htmlspecialchars((string)($task['assigned_to_username'] ?? '')); ?> · Role: <?php echo htmlspecialchars((string)($task['required_role'] ?? '')); ?></div>
          <div class="muted">Status: <?php echo htmlspecialchars((string)($task['status'] ?? '')); ?></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn" href="/hub/sop/execution.php?execution_id=<?php echo rawurlencode((string)($task['execution_id'] ?? '')); ?>">Back</a>
        </div>
      </div>
    </div>

    <div class="two">
      <div class="card">
        <div style="font-weight:800;margin-bottom:8px;">Evidence (MVP-B)</div>
        <div class="muted">Add evidence references (URI + optional sha256). Verifier requires at least <?php echo (int)($step['required_evidence_min'] ?? 0); ?> evidence items.</div>
        <div style="margin-top:12px;display:grid;gap:10px;">
          <input id="evType" value="artifact" placeholder="evidence_type (artifact/log/hash/...)" />
          <input id="evUri" value="" placeholder="uri (s3://... or /path/...)" />
          <input id="evSha" value="" placeholder="sha256 (optional)" />
          <button class="btn primary" type="button" onclick="addEvidence()" <?php echo $canAct ? '' : 'disabled'; ?>>Add Evidence</button>
          <div id="evStatus" class="muted"></div>
        </div>
        <table>
          <thead><tr><th>Type</th><th>URI</th><th>sha256</th><th>At</th></tr></thead>
          <tbody>
            <?php foreach ($evidence as $ev): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($ev['evidence_type'] ?? '')); ?></td>
                <td style="word-break:break-all;"><?php echo htmlspecialchars((string)($ev['uri'] ?? '')); ?></td>
                <td style="word-break:break-all;"><?php echo htmlspecialchars((string)($ev['sha256'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($ev['created_at'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div style="font-weight:800;margin-bottom:8px;">Actions</div>
        <div class="muted" style="margin-bottom:10px;">Submit triggers verification; accept advances execution and creates the next step.</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn" type="button" onclick="setStatus('in_progress')" <?php echo $canAct ? '' : 'disabled'; ?>>Start</button>
          <button class="btn" type="button" onclick="submitTask()" <?php echo $canAct ? '' : 'disabled'; ?>>Submit</button>
          <button class="btn primary" type="button" onclick="verifyAndAccept()" <?php echo $canAct ? '' : 'disabled'; ?>>Verify & Accept</button>
        </div>
        <div id="actStatus" class="muted" style="margin-top:12px;"></div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:800;margin-bottom:8px;">Approvals (MVP-B)</div>
      <?php if (($requiredApprovals['required'] ?? false)): ?>
        <div class="muted">Required: <?php echo (int)($requiredApprovals['quorum'] ?? 0); ?> approval(s) from roles: <?php echo htmlspecialchars(implode(', ', (array)($requiredApprovals['roles'] ?? []))); ?></div>
      <?php else: ?>
        <div class="muted">No approvals required for this step.</div>
      <?php endif; ?>

      <table>
        <thead><tr><th>Role</th><th>Decision</th><th>By</th><th>At</th></tr></thead>
        <tbody>
          <?php foreach ($approvals as $ap): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)($ap['role'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($ap['decision'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($ap['username'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars((string)($ap['created_at'] ?? '')); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
        <button class="btn primary" type="button" onclick="approveTask('approve')" <?php echo $canAct ? '' : 'disabled'; ?>>Approve</button>
        <button class="btn danger" type="button" onclick="approveTask('reject')" <?php echo $canAct ? '' : 'disabled'; ?>>Reject</button>
      </div>
      <div id="apStatus" class="muted" style="margin-top:10px;"></div>
    </div>
  </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const TASK = <?php echo json_encode($taskId); ?>;

function postAction(params) {
  const fd = new URLSearchParams();
  fd.set('csrf_token', CSRF);
  for (const k in params) fd.set(k, params[k]);
  return fetch('/hub/sop/action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: fd.toString()
  }).then(r => r.json());
}

function setStatus(status) {
  const el = document.getElementById('actStatus');
  if (el) el.textContent = 'Updating...';
  postAction({ action: 'task_set_status', task_id: TASK, status }).then(d => {
    if (d && d.success) window.location.reload();
    else if (el) el.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => { if (el) el.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'); });
}

function submitTask() {
  const el = document.getElementById('actStatus');
  if (el) el.textContent = 'Submitting...';
  postAction({ action: 'task_submit', task_id: TASK }).then(d => {
    if (d && d.success) window.location.reload();
    else if (el) el.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => { if (el) el.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'); });
}

function verifyAndAccept() {
  const el = document.getElementById('actStatus');
  if (el) el.textContent = 'Verifying...';
  postAction({ action: 'task_verify_and_accept', task_id: TASK }).then(d => {
    if (d && d.success && d.needs_approval) {
      if (el) el.textContent = 'Verified. Waiting for required approvals.';
      window.location.reload();
      return;
    }
    if (d && d.success) {
      const adv = d.advance || null;
      if (adv && adv.execution_id) {
        window.location.href = '/hub/sop/execution.php?execution_id=' + encodeURIComponent(adv.execution_id);
        return;
      }
      window.location.reload();
      return;
    }
    if (el) el.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => { if (el) el.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'); });
}

function approveTask(decision) {
  const el = document.getElementById('apStatus');
  if (el) el.textContent = 'Saving...';
  postAction({ action: 'task_approve', task_id: TASK, decision: decision }).then(d => {
    if (d && d.success) {
      window.location.reload();
      return;
    }
    if (el) el.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => { if (el) el.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'); });
}

function addEvidence() {
  const t = document.getElementById('evType');
  const u = document.getElementById('evUri');
  const s = document.getElementById('evSha');
  const el = document.getElementById('evStatus');
  if (el) el.textContent = 'Adding...';
  postAction({
    action: 'task_add_evidence',
    task_id: TASK,
    evidence_type: t && t.value ? t.value.trim() : 'artifact',
    uri: u && u.value ? u.value.trim() : '',
    sha256: s && s.value ? s.value.trim() : ''
  }).then(d => {
    if (d && d.success) window.location.reload();
    else if (el) el.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => { if (el) el.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'); });
}
</script>
</body>
</html>
