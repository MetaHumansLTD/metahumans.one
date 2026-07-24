<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../gear/grid/admin_reprovision.php';

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    header('Location: /auth/login.php');
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
if (stripos($role, 'kripzmaster') === false) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES);
}

function mh_grid_reprovision_detail_reason(mixed $detail): string
{
    if (!is_array($detail)) {
        return '';
    }

    $json = is_array($detail['json'] ?? null) ? $detail['json'] : [];
    $code = trim((string)($json['code'] ?? ''));
    $reason = trim((string)($json['reason'] ?? ''));
    if ($code !== '' && $reason !== '') {
        return $code . ': ' . $reason;
    }
    if ($reason !== '') {
        return $reason;
    }

    $body = trim((string)($detail['body_raw'] ?? ''));
    if ($body !== '') {
        return $body;
    }

    return '';
}

function mh_grid_reprovision_recent_tenants(int $limit = 12): array
{
    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        return [];
    }
    mh_grid_ensure_tables($db);

    $limit = max(1, min(50, $limit));
    $stmt = $db->query("
        SELECT tenant_id, sr_customer_id, status, updated_at_utc
        FROM mh_settlement_customers
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT {$limit}
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tenantId = trim((string)($row['tenant_id'] ?? ''));
        if ($tenantId === '') {
            continue;
        }
        $out[] = [
            'tenantId' => $tenantId,
            'srCustomerId' => trim((string)($row['sr_customer_id'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'updatedAt' => trim((string)($row['updated_at_utc'] ?? '')),
        ];
    }

    return $out;
}

$message = '';
$error = '';
$result = null;
$tenantId = isset($_REQUEST['tenant_id']) && is_string($_REQUEST['tenant_id']) ? trim((string)$_REQUEST['tenant_id']) : '';
$confirmPhrase = '';
$currentSnapshot = null;
$recentTenants = mh_grid_reprovision_recent_tenants();
$internalEmailOtpAddress = ($tenantId !== '' && function_exists('mh_grid_internal_email_otp_address_for_tenant'))
    ? mh_grid_internal_email_otp_address_for_tenant($tenantId)
    : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $confirmPhrase = isset($_POST['confirm_phrase']) && is_string($_POST['confirm_phrase']) ? trim((string)$_POST['confirm_phrase']) : '';

    try {
        if ($tenantId === '') {
            throw new RuntimeException('Tenant ID is required.');
        }
        if ($confirmPhrase !== 'REPROVISION') {
            throw new RuntimeException('Type REPROVISION to confirm the tenant wallet reprovision.');
        }

        $result = mh_grid_admin_reprovision_bootstrap_credential($tenantId);
        if (($result['ok'] ?? false) !== true) {
            $detailReason = mh_grid_reprovision_detail_reason($result['detail'] ?? null);
            $errorMessage = trim((string)($result['message'] ?? 'Grid bootstrap reprovision failed.'));
            if ($detailReason !== '') {
                $errorMessage .= ' ' . $detailReason;
            }
            throw new RuntimeException($errorMessage);
        }

        $message = 'Tenant reprovisioned onto a fresh Grid customer/account mapping.';
        $currentSnapshot = is_array($result['current'] ?? null) ? $result['current'] : null;
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

if ($tenantId !== '' && !is_array($currentSnapshot)) {
    try {
        $db = mh_grid_get_db();
        if ($db instanceof PDO) {
            mh_grid_ensure_tables($db);
            $currentSnapshot = mh_grid_admin_reprovision_local_snapshot($db, $tenantId);
        }
    } catch (Throwable $e) {
        // Keep the page usable even if the snapshot lookup fails.
    }
}

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(dirname(__DIR__)) . '/templates');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grid Tenant Recovery</title>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
<style>
  .mh-page{max-width:1200px;margin:0 auto;padding:18px 20px}
  .mh-page-header{display:flex;flex-direction:column;gap:12px}
  .mh-page-title{margin:0}
  .mh-page-actions{display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:12px 16px;border-radius:10px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s ease;cursor:pointer}
  .btn-primary{background:linear-gradient(135deg,rgba(var(--theme-primary-rgb),.95),rgba(var(--theme-secondary-rgb),.95));color:#000;border:0}
  .btn-secondary{background:rgba(255,255,255,.06);border:1px solid rgba(var(--theme-primary-rgb),.35);color:var(--theme-text,#e8eefc)}
  .btn-secondary:hover{background:rgba(var(--theme-primary-rgb),.12);border-color:rgba(var(--theme-primary-rgb),.55)}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(var(--theme-primary-rgb),.18)}
  .card{background:rgba(255,255,255,.05);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:14px;margin:12px 0}
  .notice{background:rgba(var(--theme-primary-rgb),.08);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:12px 14px;margin-top:12px}
  .notice.error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
  .notice.success{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35)}
  .mh-help{font-size:12px;opacity:.8}
  .mh-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .form-input{width:100%;padding:12px 15px;border:1px solid rgba(var(--theme-primary-rgb),0.3);border-radius:10px;background:rgba(255,255,255,0.06);color:var(--theme-text,#e8eefc);font-size:14px;transition:all 0.3s ease;box-sizing:border-box}
  .form-input:focus{outline:none;border-color:var(--theme-primary);box-shadow:0 0 15px rgba(var(--theme-primary-rgb),0.25)}
  .mh-grid{display:grid;gap:10px}
  .mh-row{display:grid;gap:6px}
  .mh-actions{display:flex;gap:10px;flex-wrap:wrap}
  .mh-result-grid{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
  .mh-result-block{background:rgba(255,255,255,.04);border:1px solid rgba(var(--theme-primary-rgb),.16);border-radius:12px;padding:12px}
  .mh-table{width:100%;border-collapse:collapse}
  .mh-table th,.mh-table td{padding:10px 10px}
  .mh-table thead th{border-bottom:1px solid rgba(255,255,255,0.12)}
  .mh-table tbody td{border-bottom:1px solid rgba(255,255,255,0.08)}
  @media (min-width: 900px){
    .mh-page-header{flex-direction:row;align-items:flex-end;justify-content:space-between}
  }
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content mh-page">
  <div class="mh-page-header">
    <h1 class="mh-page-title">Grid Tenant Recovery</h1>
    <div class="mh-page-actions">
      <a class="btn btn-secondary" href="/hub/">Hub</a>
      <a class="btn btn-secondary" href="/control/grid-settings.php">Grid Settings</a>
      <a class="btn btn-secondary" href="/control/grid-webhooks.php<?php echo $tenantId !== '' ? '?tenant_id=' . rawurlencode($tenantId) : ''; ?>">Grid Webhooks</a>
      <a class="btn btn-secondary" href="/gear/grid/health.json.php">Grid Health</a>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="notice success"><?php echo h($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice error"><?php echo h($error); ?></div><?php endif; ?>

  <div class="card">
    <div class="notice">
      Use this only when a tenant's stale Grid <code>EMAIL_OTP</code> bootstrap credential cannot be repaired in place because the current wallet can no longer authorize Grid signed retries. This recovery flow creates a fresh Grid customer/account mapping for the tenant and clears the tenant's locally cached Grid wallet/auth state. It does not delete or repair the old Grid wallet remotely.
    </div>
    <div class="notice" style="margin-top:12px">
      Recovery stays on the platform's internal identity rail. Reprovision derives the tenant's Grid <code>EMAIL_OTP</code> address from the tenant identifier and keeps bootstrap delivery on the in-house route instead of asking for any user email or password.
    </div>
  </div>

  <form method="post" action="" style="margin-top:14px">
    <div class="card">
      <h2 style="margin:0 0 10px 0">Reprovision Tenant</h2>
      <div class="mh-grid">
        <div class="mh-row">
          <div class="mh-help">Tenant ID</div>
          <input class="form-input mh-code" name="tenant_id" value="<?php echo h($tenantId); ?>" placeholder="user:pieterrubeus">
        </div>
        <div class="mh-row">
          <div class="mh-help">Internal Grid EMAIL_OTP route</div>
          <input class="form-input mh-code" value="<?php echo h($internalEmailOtpAddress !== '' ? $internalEmailOtpAddress : 'auto-derived from tenant id'); ?>" readonly>
        </div>
        <div class="mh-row">
          <div class="mh-help">Type <code>REPROVISION</code> to confirm that you want to replace the tenant's local Grid mapping with a fresh customer/account.</div>
          <input class="form-input mh-code" name="confirm_phrase" value="<?php echo h($confirmPhrase); ?>" placeholder="REPROVISION">
        </div>
      </div>
      <div class="mh-actions" style="margin-top:12px">
        <button class="btn btn-primary" type="submit">Reprovision Tenant Wallet</button>
      </div>
    </div>
  </form>

  <?php if (is_array($currentSnapshot)): ?>
    <div class="card">
      <h2 style="margin:0 0 10px 0">Current Local Mapping</h2>
      <div class="mh-result-grid">
        <div class="mh-result-block">
          <div class="mh-help">Tenant</div>
          <div class="mh-code"><code><?php echo h($tenantId); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Current Grid Customer</div>
          <div class="mh-code"><code><?php echo h((string)($currentSnapshot['customer']['srCustomerId'] ?? 'not linked')); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Platform Customer ID</div>
          <div class="mh-code"><code><?php echo h((string)($currentSnapshot['customer']['platformCustomerId'] ?? 'not linked')); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Cached Auth Credentials</div>
          <div><?php echo h((string)($currentSnapshot['credentialCount'] ?? 0)); ?></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Cached Auth Sessions</div>
          <div><?php echo h((string)($currentSnapshot['sessionCount'] ?? 0)); ?></div>
        </div>
      </div>

      <?php if (!empty($currentSnapshot['accounts']) && is_array($currentSnapshot['accounts'])): ?>
        <div style="overflow:auto;margin-top:14px">
          <table class="mh-table">
            <thead>
              <tr>
                <th style="text-align:left">Embedded Wallet</th>
                <th style="text-align:left">Type</th>
                <th style="text-align:left">Currency</th>
                <th style="text-align:left">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($currentSnapshot['accounts'] as $acct): ?>
                <?php if (!is_array($acct)) continue; ?>
                <tr>
                  <td class="mh-code"><code><?php echo h((string)($acct['accountId'] ?? '')); ?></code></td>
                  <td><?php echo h((string)($acct['accountType'] ?? '')); ?></td>
                  <td><?php echo h((string)($acct['currency'] ?? '')); ?></td>
                  <td><?php echo h((string)($acct['status'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (is_array($result)): ?>
    <div class="card">
      <h2 style="margin:0 0 10px 0">Fresh Mapping</h2>
      <div class="mh-result-grid">
        <div class="mh-result-block">
          <div class="mh-help">Fresh Grid Customer</div>
          <div class="mh-code"><code><?php echo h((string)($result['current']['srCustomerId'] ?? '')); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Fresh Embedded Wallet</div>
          <div class="mh-code"><code><?php echo h(implode(', ', is_array($result['current']['accountIds'] ?? null) ? $result['current']['accountIds'] : [])); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Fresh Platform Customer ID</div>
          <div class="mh-code"><code><?php echo h((string)($result['current']['platformCustomerId'] ?? '')); ?></code></div>
        </div>
        <div class="mh-result-block">
          <div class="mh-help">Internal EMAIL_OTP Route</div>
          <div class="mh-code"><code><?php echo h((string)($result['current']['email'] ?? '')); ?></code></div>
        </div>
      </div>
      <div class="mh-help" style="margin-top:12px">
        After reprovision, bootstrap a new Grid session from a live browser session on the fresh wallet. Any previously cached device-local Grid session key for that tenant is now stale.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($recentTenants !== []): ?>
    <div class="card">
      <h2 style="margin:0 0 10px 0">Recent Tenants</h2>
      <div style="overflow:auto">
        <table class="mh-table">
          <thead>
            <tr>
              <th style="text-align:left">Tenant</th>
              <th style="text-align:left">Grid Customer</th>
              <th style="text-align:left">Status</th>
              <th style="text-align:left">Updated</th>
              <th style="text-align:left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentTenants as $recent): ?>
              <?php if (!is_array($recent)) continue; ?>
              <?php $recentTenantId = trim((string)($recent['tenantId'] ?? '')); ?>
              <?php if ($recentTenantId === '') continue; ?>
              <tr>
                <td class="mh-code"><code><?php echo h($recentTenantId); ?></code></td>
                <td class="mh-code"><code><?php echo h((string)($recent['srCustomerId'] ?? '')); ?></code></td>
                <td><?php echo h((string)($recent['status'] ?? '')); ?></td>
                <td><?php echo h((string)($recent['updatedAt'] ?? '')); ?></td>
                <td>
                  <a class="btn btn-secondary" href="/control/grid/reprovision.php?tenant_id=<?php echo rawurlencode($recentTenantId); ?>">Load Tenant</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
