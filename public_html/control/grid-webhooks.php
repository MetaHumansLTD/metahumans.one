<?php
declare(strict_types=1);

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/tenant_provisioning.php';
require_once __DIR__ . '/../gear/grid/grid_db.php';

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

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

function mh_grid_admin_safe_basename(string $v): string
{
    $v = trim($v);
    $v = preg_replace('/[^A-Za-z0-9:_-]/', '_', $v);
    $v = preg_replace('/_+/', '_', (string)$v);
    $v = trim((string)$v, '_');
    return $v;
}

function mh_grid_admin_list_json_files(string $dir): array
{
    if (!is_dir($dir)) return [];
    $items = @scandir($dir);
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $it) {
        if (!is_string($it) || $it === '.' || $it === '..') continue;
        if (!str_ends_with($it, '.json')) continue;
        $full = rtrim($dir, '/') . '/' . $it;
        if (!is_file($full) || !is_readable($full)) continue;
        $out[] = [
            'name' => $it,
            'path' => $full,
            'mtime' => (int)@filemtime($full),
            'size' => (int)@filesize($full),
        ];
    }
    usort($out, fn(array $a, array $b): int => ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0));
    return $out;
}

function mh_grid_admin_count_json_files(string $dir): int
{
    return count(mh_grid_admin_list_json_files($dir));
}

function mh_grid_admin_recent_tenants(string $date, int $limit = 8): array
{
    $rows = [];
    $db = mh_grid_get_db();
    if ($db instanceof PDO) {
        mh_grid_ensure_tables($db);
        $limit = max(1, min(25, $limit));
        $stmt = $db->query("
            SELECT tenant_id, MAX(received_at_utc) AS latest_received_at
            FROM mh_settlement_webhooks
            GROUP BY tenant_id
            ORDER BY latest_received_at DESC
            LIMIT {$limit}
        ");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $tenantId = trim((string)($row['tenant_id'] ?? ''));
            if ($tenantId === '') continue;
            $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : '';
            if ($tenantSafe === '') continue;
            $settlementRoot = '/data/tenants/' . $tenantSafe . '/settlement';
            $out[] = [
                'tenant_id' => $tenantId,
                'latest_received_at' => trim((string)($row['latest_received_at'] ?? '')),
                'receipts_count' => mh_grid_admin_count_json_files($settlementRoot . '/receipts/' . $date),
                'events_count' => mh_grid_admin_count_json_files($settlementRoot . '/events/' . $date),
            ];
        }
    }

    return $out;
}

$tenantId = isset($_GET['tenant_id']) ? trim((string)$_GET['tenant_id']) : '';
$date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$kind = isset($_GET['kind']) ? trim((string)$_GET['kind']) : 'receipts';
$view = isset($_GET['view']) ? trim((string)$_GET['view']) : '';
$file = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
$autoTenantMessage = '';

if ($date === '') $date = gmdate('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = gmdate('Y-m-d');
if ($kind !== 'receipts' && $kind !== 'events') $kind = 'receipts';

$recentTenants = mh_grid_admin_recent_tenants($date);
if ($tenantId === '' && $recentTenants !== []) {
    $tenantId = trim((string)($recentTenants[0]['tenant_id'] ?? ''));
    if ($tenantId !== '') {
        $autoTenantMessage = 'Showing the most recent webhook tenant by default.';
    }
}
if ($tenantId === '') {
    $platformSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe('platform:grid') : '';
    if ($platformSafe !== '') {
        $platformSettlementRoot = '/data/tenants/' . $platformSafe . '/settlement';
        $hasPlatformArtifacts =
            is_dir($platformSettlementRoot . '/receipts/' . $date) ||
            is_dir($platformSettlementRoot . '/events/' . $date);
        if ($hasPlatformArtifacts) {
            $tenantId = 'platform:grid';
            $autoTenantMessage = 'Showing platform:grid because provider test webhooks were found there.';
        }
    }
}

$tenantSafe = $tenantId !== '' && function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : '';
$base = ($tenantSafe !== '' ? ('/data/tenants/' . $tenantSafe . '/settlement') : '');
$dir = ($base !== '' ? ($base . '/' . $kind . '/' . $date) : '');

$items = ($dir !== '' ? mh_grid_admin_list_json_files($dir) : []);
$items = array_slice($items, 0, 200);

$selected = null;
$selectedJson = '';
$selectedErr = '';

if ($tenantId !== '' && $view === '1' && $file !== '') {
    $safeFile = mh_grid_admin_safe_basename($file);
    if ($safeFile !== '') {
        $full = $dir !== '' ? ($dir . '/' . $safeFile) : '';
        if ($full !== '' && is_file($full) && is_readable($full)) {
            $raw = @file_get_contents($full);
            if (is_string($raw)) {
                if (strlen($raw) > 800000) {
                    $selectedErr = 'file_too_large';
                } else {
                    $selected = $full;
                    $selectedJson = $raw;
                }
            } else {
                $selectedErr = 'read_failed';
            }
        } else {
            $selectedErr = 'not_found';
        }
    } else {
        $selectedErr = 'invalid_file';
    }
}

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__) . '/templates');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grid Webhooks</title>
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
  .card{background:rgba(255,255,255,.05);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px}
  .notice{background:rgba(var(--theme-primary-rgb),.08);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:12px 14px}
  .notice.error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
  .notice.success{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35)}
  .form-input,.form-select{width:100%;padding:12px 15px;border:1px solid rgba(var(--theme-primary-rgb),0.3);border-radius:10px;background:rgba(255,255,255,0.06);color:var(--theme-text,#e8eefc);font-size:14px;transition:all 0.3s ease;box-sizing:border-box}
  .form-input:focus,.form-select:focus{outline:none;border-color:var(--theme-primary);box-shadow:0 0 15px rgba(var(--theme-primary-rgb),0.25)}
  .mh-form-grid{padding:14px;margin:12px 0;display:grid;gap:12px}
  .mh-help{font-size:12px;opacity:.8}
  .mh-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .mh-pre{margin-top:10px;white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,0.25);padding:12px;border-radius:12px;max-height:520px;overflow:auto}
  .mh-table th,.mh-table td{padding:10px 10px}
  .mh-table thead th{border-bottom:1px solid rgba(255,255,255,0.12)}
  .mh-table tbody td{border-bottom:1px solid rgba(255,255,255,0.08)}
  .mh-table{width:100%;border-collapse:collapse}
  @media (min-width: 900px){
    .mh-page-header{flex-direction:row;align-items:flex-end;justify-content:space-between}
  }
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content mh-page">
  <div class="mh-page-header">
    <h1 class="mh-page-title">Grid Webhooks</h1>
    <div class="mh-page-actions">
      <a class="btn btn-secondary" href="/hub/">Hub</a>
      <a class="btn btn-secondary" href="/control/grid-settings.php">Grid Settings</a>
      <a class="btn btn-secondary" href="/control/grid/reprovision.php<?php echo $tenantId !== '' ? '?tenant_id=' . rawurlencode($tenantId) : ''; ?>">Tenant Recovery</a>
      <a class="btn btn-secondary" href="/gear/grid/health.json.php">Grid Health</a>
    </div>
  </div>

  <form method="get" action="" style="margin-top:14px">
    <div class="card mh-form-grid">
      <div style="display:grid;gap:6px">
        <div class="mh-help">Tenant ID</div>
        <input class="form-input" name="tenant_id" value="<?php echo h($tenantId); ?>" placeholder="user:alice or company:acme">
      </div>
      <div style="display:grid;gap:6px">
        <div class="mh-help">Date (UTC)</div>
        <input class="form-input" name="date" value="<?php echo h($date); ?>" placeholder="YYYY-MM-DD">
      </div>
      <div style="display:grid;gap:6px">
        <div class="mh-help">Kind</div>
        <select class="form-select" name="kind">
          <option value="receipts" <?php echo $kind === 'receipts' ? 'selected' : ''; ?>>Receipts</option>
          <option value="events" <?php echo $kind === 'events' ? 'selected' : ''; ?>>Events</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <button class="btn btn-primary" type="submit">Browse</button>
      </div>
    </div>
  </form>

  <?php if ($recentTenants !== []): ?>
    <div class="card" style="padding:14px;margin:12px 0">
      <h2 style="margin:0 0 10px 0">Recent Tenants</h2>
      <div style="overflow:auto">
        <table class="mh-table">
          <thead>
            <tr>
              <th style="text-align:left">Tenant</th>
              <th style="text-align:left">Latest Webhook</th>
              <th style="text-align:left">Receipts</th>
              <th style="text-align:left">Events</th>
              <th style="text-align:left">Browse</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentTenants as $recent): ?>
              <?php if (!is_array($recent)) continue; ?>
              <?php
                $recentTenantId = trim((string)($recent['tenant_id'] ?? ''));
                if ($recentTenantId === '') continue;
                $receiptHref = '/control/grid-webhooks.php?' . http_build_query([
                    'tenant_id' => $recentTenantId,
                    'date' => $date,
                    'kind' => 'receipts',
                ]);
                $eventHref = '/control/grid-webhooks.php?' . http_build_query([
                    'tenant_id' => $recentTenantId,
                    'date' => $date,
                    'kind' => 'events',
                ]);
              ?>
              <tr>
                <td class="mh-code"><code><?php echo h($recentTenantId); ?></code></td>
                <td><?php echo h((string)($recent['latest_received_at'] ?? '')); ?></td>
                <td><?php echo h((string)($recent['receipts_count'] ?? 0)); ?></td>
                <td><?php echo h((string)($recent['events_count'] ?? 0)); ?></td>
                <td style="display:flex;gap:8px;flex-wrap:wrap">
                  <a class="btn btn-secondary" href="<?php echo h($receiptHref); ?>">Receipts</a>
                  <a class="btn btn-secondary" href="<?php echo h($eventHref); ?>">Events</a>
                  <a class="btn btn-secondary" href="/control/grid/reprovision.php?tenant_id=<?php echo rawurlencode($recentTenantId); ?>">Recovery</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($tenantId === ''): ?>
    <div class="notice" style="margin-top:12px">Enter a tenant id to browse stored webhook receipts/events.</div>
  <?php else: ?>
    <?php if ($autoTenantMessage !== ''): ?>
      <div class="notice success" style="margin-top:12px"><?php echo h($autoTenantMessage); ?></div>
    <?php endif; ?>
    <div class="card" style="padding:14px;margin:12px 0">
      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between">
        <div class="mh-help">Directory</div>
        <div class="mh-code"><code><?php echo h($dir); ?></code></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($selectedErr !== ''): ?>
    <div class="notice error" style="margin-top:12px"><?php echo h($selectedErr); ?></div>
  <?php endif; ?>

  <?php if ($selected !== null): ?>
    <div class="card" style="padding:14px;margin:12px 0">
      <div class="mh-help">Viewing</div>
      <div style="margin-top:6px" class="mh-code"><code><?php echo h($selected); ?></code></div>
      <pre class="mh-pre"><?php echo h($selectedJson); ?></pre>
    </div>
  <?php endif; ?>

  <?php if ($tenantId !== ''): ?>
    <div class="card" style="padding:14px;margin:12px 0">
      <h2 style="margin:0 0 10px 0"><?php echo h(ucfirst($kind)); ?></h2>
      <?php if (!$items): ?>
        <div style="opacity:.8">No files found.</div>
      <?php else: ?>
        <div style="overflow:auto">
          <table class="mh-table">
            <thead>
              <tr>
                <th style="text-align:left">File</th>
                <th style="text-align:left">Modified (UTC)</th>
                <th style="text-align:left">Size</th>
                <th style="text-align:left">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <?php if (!is_array($it)) continue; ?>
                <?php
                  $name = (string)($it['name'] ?? '');
                  $mtime = (int)($it['mtime'] ?? 0);
                  $size = (int)($it['size'] ?? 0);
                  $qs = [
                      'tenant_id' => $tenantId,
                      'date' => $date,
                      'kind' => $kind,
                      'view' => '1',
                      'file' => $name,
                  ];
                  $href = '/control/grid-webhooks.php?' . http_build_query($qs);
                ?>
                <tr>
                  <td class="mh-code"><code><?php echo h($name); ?></code></td>
                  <td><?php echo h($mtime > 0 ? gmdate('c', $mtime) : ''); ?></td>
                  <td><?php echo h((string)$size); ?></td>
                  <td><a class="btn btn-secondary" href="<?php echo h($href); ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
