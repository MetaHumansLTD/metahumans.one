<?php
require_once __DIR__ . '/../.cue/cue.php';

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
    $redirect = $_SERVER['REQUEST_URI'] ?? '/control/sop-manager.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/control/sop-manager.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

if (!function_exists('sop_is_director') || !sop_is_director()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$ctx = sop_get_context();
$pdo = sop_get_pdo();
sop_ensure_schema($pdo);

$sopId = isset($_GET['sop_id']) ? trim((string)$_GET['sop_id']) : '';
$version = isset($_GET['version']) ? trim((string)$_GET['version']) : '';

$selected = null;
$actions = [];
if ($sopId !== '' && $version !== '') {
    $selected = sop_get_sop($pdo, (string)$ctx['tenant_id'], $sopId, $version);
    if ($selected) {
        $actions = sop_list_actions($pdo, (string)$ctx['tenant_id'], $sopId, $version);
    }
}

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrf = $security ? $security->generateCSRFToken('sop') : '';

$sops = sop_list_sops($pdo, (string)$ctx['tenant_id'], 300);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SOP Manager | Control</title>
  <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.sop-admin main.main-content { color: rgba(255,255,255,0.92); }
    body.sop-admin .wrap { max-width: 1200px; margin: 0 auto; padding: 28px 18px; }
    body.sop-admin h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 14px; }
    body.sop-admin .grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
    @media (min-width: 980px) { body.sop-admin .grid { grid-template-columns: 1.2fr 0.8fr; } }
    body.sop-admin .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px); }
    body.sop-admin .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
    body.sop-admin .btn { display: inline-flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.35); background: rgba(0, 212, 255, 0.06); color: rgba(255,255,255,0.92); text-decoration: none; cursor: pointer; font-weight: 800; }
    body.sop-admin .btn.primary { background: rgba(0, 212, 255, 0.16); }
    body.sop-admin input, body.sop-admin textarea, body.sop-admin select { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(255,255,255,0.03); color: #fff; }
    body.sop-admin table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    body.sop-admin th, body.sop-admin td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
    body.sop-admin th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
    body.sop-admin .two { display: grid; grid-template-columns: 1fr; gap: 10px; }
    @media (min-width: 980px) { body.sop-admin .two { grid-template-columns: 1fr 1fr; } }
  </style>
</head>
<body class="sop-admin">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="wrap">
    <h1>SOP Manager</h1>

    <div class="grid">
      <div class="card">
        <div style="font-weight:900;margin-bottom:8px;">SOPs</div>
        <div class="muted">Draft → Submit → Authorize. Only Authorized SOPs can be executed.</div>
        <table>
          <thead>
            <tr>
              <th>SOP</th>
              <th>Title</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sops as $s): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($s['sop_id'] ?? '') . '@' . (string)($s['version'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($s['title'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($s['status'] ?? '')); ?></td>
                <td style="text-align:right;">
                  <a class="btn primary" href="/control/sop-manager.php?sop_id=<?php echo rawurlencode((string)($s['sop_id'] ?? '')); ?>&version=<?php echo rawurlencode((string)($s['version'] ?? '')); ?>">Open</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div style="font-weight:900;margin-bottom:8px;">Create SOP</div>
        <div class="muted" style="margin-bottom:10px;">Creates a Draft SOP (version 1.0.0).</div>
        <div class="two">
          <input id="newTitle" placeholder="Title" />
          <input id="newScope" placeholder="Scope" />
        </div>
        <div style="margin-top:10px;">
          <textarea id="newDesc" rows="3" placeholder="Description"></textarea>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:flex-end;">
          <button class="btn primary" type="button" onclick="createSop()">Create</button>
        </div>
        <div id="createStatus" class="muted" style="margin-top:10px;"></div>
      </div>
    </div>

    <?php if ($selected): ?>
      <div class="card" style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
          <div>
            <div style="font-weight:900;"><?php echo htmlspecialchars((string)($selected['title'] ?? '')); ?></div>
            <div class="muted">SOP: <?php echo htmlspecialchars($sopId . '@' . $version); ?> · Status: <?php echo htmlspecialchars((string)($selected['status'] ?? '')); ?></div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn" type="button" onclick="submitSop()" <?php echo ((string)($selected['status'] ?? '') === 'draft') ? '' : 'disabled'; ?>>Submit</button>
            <button class="btn primary" type="button" onclick="authorizeSop()" <?php echo ((string)($selected['status'] ?? '') === 'submitted') ? '' : 'disabled'; ?>>Authorize</button>
          </div>
        </div>
      </div>

      <div class="card">
        <div style="font-weight:900;margin-bottom:8px;">Actions</div>
        <div class="muted">Steps must start at 1. Mark one step as terminal.</div>
        <table>
          <thead>
            <tr>
              <th>Step</th>
              <th>Name</th>
              <th>Role</th>
              <th>Actor</th>
              <th>Evidence Min</th>
              <th>Terminal</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($actions as $a): ?>
              <tr>
                <td><?php echo (int)($a['step_number'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars((string)($a['step_name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($a['required_role'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars((string)($a['actor_type_allowed'] ?? '')); ?></td>
                <td><?php echo (int)($a['required_evidence_min'] ?? 0); ?></td>
                <td><?php echo ((int)($a['is_terminal'] ?? 0) === 1) ? 'yes' : 'no'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:14px;font-weight:900;">Add / Update Step</div>
        <div class="two" style="margin-top:8px;">
          <input id="stepNum" placeholder="Step #" />
          <input id="stepName" placeholder="Step name" />
        </div>
        <div class="two" style="margin-top:8px;">
          <input id="stepRole" placeholder="Required role (e.g. director, reviewer, engineer)" />
          <select id="stepActor">
            <option value="either">either</option>
            <option value="human">human</option>
            <option value="machine">machine</option>
          </select>
        </div>
        <div class="two" style="margin-top:8px;">
          <input id="stepEvidenceMin" placeholder="Required evidence min (0+)" />
          <select id="stepTerminal">
            <option value="0">not terminal</option>
            <option value="1">terminal</option>
          </select>
        </div>
        <div class="two" style="margin-top:8px;">
          <input id="stepVerifiers" placeholder="Verifiers (comma-separated): evidence_min, uri_exists, sha256_matches, pdf_valid, ci_passed" />
          <input id="stepApprovalRoles" placeholder="Approval roles (comma-separated): director, client" />
        </div>
        <div class="two" style="margin-top:8px;">
          <select id="stepApprovalRequired">
            <option value="0">Approval not required</option>
            <option value="1">Approval required</option>
          </select>
          <input id="stepApprovalQuorum" placeholder="Approval quorum (e.g. 1)" />
        </div>
        <div style="margin-top:10px;display:flex;justify-content:flex-end;">
          <button class="btn primary" type="button" onclick="upsertStep()" <?php echo ((string)($selected['status'] ?? '') === 'draft') ? '' : 'disabled'; ?>>Save Step</button>
        </div>
        <div id="stepStatus" class="muted" style="margin-top:10px;"></div>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
const CSRF = <?php echo json_encode($csrf); ?>;
const SOP_ID = <?php echo json_encode($sopId); ?>;
const SOP_VER = <?php echo json_encode($version); ?>;

function post(params) {
  const fd = new URLSearchParams();
  fd.set('csrf_token', CSRF);
  for (const k in params) fd.set(k, params[k]);
  return fetch('/hub/sop/action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: fd.toString()
  }).then(r => r.json());
}

function createSop() {
  const title = document.getElementById('newTitle').value.trim();
  const scope = document.getElementById('newScope').value.trim();
  const desc = document.getElementById('newDesc').value.trim();
  const st = document.getElementById('createStatus');
  if (!title) { st.textContent = 'Title is required.'; return; }
  st.textContent = 'Creating...';
  post({ action: 'sop_create', title, scope, description: desc }).then(d => {
    if (d && d.success && d.sop_id) {
      window.location.href = '/control/sop-manager.php?sop_id=' + encodeURIComponent(d.sop_id) + '&version=' + encodeURIComponent(d.version || '1.0.0');
      return;
    }
    st.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => st.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'));
}

function upsertStep() {
  const st = document.getElementById('stepStatus');
  const step_number = document.getElementById('stepNum').value.trim();
  const step_name = document.getElementById('stepName').value.trim();
  const required_role = document.getElementById('stepRole').value.trim();
  const actor_type_allowed = document.getElementById('stepActor').value;
  const required_evidence_min = document.getElementById('stepEvidenceMin').value.trim();
  const is_terminal = document.getElementById('stepTerminal').value;
  const verifiers = document.getElementById('stepVerifiers').value.trim();
  const approval_roles = document.getElementById('stepApprovalRoles').value.trim();
  const approval_required = document.getElementById('stepApprovalRequired').value;
  const approval_quorum = document.getElementById('stepApprovalQuorum').value.trim();
  st.textContent = 'Saving...';
  post({ action: 'sop_action_upsert', sop_id: SOP_ID, version: SOP_VER, step_number, step_name, required_role, actor_type_allowed, required_evidence_min, is_terminal, verifiers, approval_roles, approval_required, approval_quorum }).then(d => {
    if (d && d.success) { window.location.reload(); return; }
    st.textContent = 'Failed: ' + (d && d.error ? d.error : 'Unknown');
  }).catch(e => st.textContent = 'Failed: ' + (e && e.message ? e.message : 'Network'));
}

function submitSop() {
  post({ action: 'sop_submit', sop_id: SOP_ID, version: SOP_VER }).then(d => {
    if (d && d.success) { window.location.reload(); return; }
    alert('Submit failed: ' + (d && d.error ? d.error : 'Unknown'));
  }).catch(e => alert('Submit failed: ' + (e && e.message ? e.message : 'Network')));
}

function authorizeSop() {
  post({ action: 'sop_authorize', sop_id: SOP_ID, version: SOP_VER }).then(d => {
    if (d && d.success) { window.location.reload(); return; }
    alert('Authorize failed: ' + (d && d.error ? d.error : 'Unknown'));
  }).catch(e => alert('Authorize failed: ' + (e && e.message ? e.message : 'Network')));
}
</script>
</body>
</html>
