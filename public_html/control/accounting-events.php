<?php
declare(strict_types=1);

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/tenant_provisioning.php';
require_once __DIR__ . '/../gear/grid/grid_db.php';
require_once __DIR__ . '/../gear/accounting/finance_gateway.php';

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

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES);
}

function mh_accounting_decode_json(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_accounting_actor_label(array $metadata): string
{
    $actor = isset($metadata['actor']) && is_array($metadata['actor']) ? $metadata['actor'] : [];
    $labels = [
        trim((string)($actor['username'] ?? '')),
        trim((string)($actor['principalId'] ?? '')),
        trim((string)($actor['source'] ?? '')),
    ];
    foreach ($labels as $label) {
        if ($label !== '') {
            return $label;
        }
    }
    return '';
}

function mh_accounting_metadata_summary(?string $json, string $fallbackDecision = ''): string
{
    $metadata = mh_accounting_decode_json($json);
    if ($metadata === []) {
        return '';
    }

    $parts = [];
    $decision = trim((string)($metadata['decision'] ?? $fallbackDecision));
    if ($decision !== '') {
        $parts[] = ucfirst($decision);
    }
    $actorLabel = mh_accounting_actor_label($metadata);
    if ($actorLabel !== '') {
        $parts[] = $actorLabel;
    }
    $recordedAtUtc = trim((string)($metadata['recordedAtUtc'] ?? ''));
    if ($recordedAtUtc !== '') {
        $parts[] = $recordedAtUtc;
    }
    $note = trim((string)($metadata['note'] ?? ''));
    if ($note !== '') {
        $parts[] = $note;
    }
    return implode(' | ', $parts);
}

$tenantId = isset($_GET['tenant_id']) ? trim((string)$_GET['tenant_id']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$limit = max(10, min(200, $limit));
$sessionTenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
$effectiveTenantId = $tenantId !== '' ? $tenantId : $sessionTenantId;

// `/control/*` routes default to biometrics for auth-critical work, so open the
// tenant DB explicitly when this director surface is scoped to a tenant.
if ($effectiveTenantId !== '') {
    $db = mh_finance_gateway_open_db($effectiveTenantId);
} else {
    $db = mh_grid_get_db();
}
if (!$db instanceof PDO) {
    http_response_code(500);
    echo 'Accounting DB unavailable';
    exit;
}

mh_finance_ensure_tables($db);

$message = '';
$messageType = 'success';
if (isset($_SESSION['mh_accounting_events_flash']) && is_array($_SESSION['mh_accounting_events_flash'])) {
    $flash = $_SESSION['mh_accounting_events_flash'];
    $message = trim((string)($flash['message'] ?? ''));
    $messageType = trim((string)($flash['type'] ?? 'success')) ?: 'success';
    unset($_SESSION['mh_accounting_events_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectTenantId = trim((string)($_POST['tenant_id'] ?? $tenantId));
    $action = trim((string)($_POST['action'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $note = $note !== '' ? $note : null;
    $flash = ['message' => '', 'type' => 'success'];
    if ($action === 'process_pending') {
        try {
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for processing pending events.');
            }
            $result = mh_finance_gateway_process_pending_events([
                'tenantId' => $redirectTenantId,
                'limit' => 100,
            ]);
            $flash['message'] = 'Processed pending events: ' . (int)$result['processed'] . ' posted, ' . (int)$result['exceptions'] . ' exceptions. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Processing failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'run_reconciliation') {
        try {
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for reconciliation snapshot.');
            }
            $result = mh_finance_gateway_run_reconciliation([
                'tenantId' => $redirectTenantId,
            ]);
            $flash['message'] = 'Reconciliation snapshot created at ' . (string)($result['artifactPath'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Reconciliation failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'generate_board_pack') {
        try {
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for board pack generation.');
            }
            $result = mh_finance_gateway_generate_board_pack([
                'tenantId' => $redirectTenantId,
            ]);
            $flash['message'] = 'Board pack generated under ' . (string)($result['exportRoot'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Board pack generation failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'requeue_exception') {
        try {
            $exceptionId = (int)($_POST['exception_id'] ?? 0);
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for exception replay.');
            }
            $result = mh_finance_gateway_requeue_exception([
                'tenantId' => $redirectTenantId,
                'exceptionId' => $exceptionId,
            ]);
            $flash['message'] = 'Exception replay queued for event ' . (string)($result['eventKey'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Exception replay failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'dispute_exception') {
        try {
            $exceptionId = (int)($_POST['exception_id'] ?? 0);
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for exception dispute.');
            }
            $result = mh_finance_gateway_dispute_exception([
                'tenantId' => $redirectTenantId,
                'exceptionId' => $exceptionId,
                'note' => $note,
            ]);
            $flash['message'] = 'Exception disputed for event ' . (string)($result['eventKey'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Exception dispute failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'resolve_exception') {
        try {
            $exceptionId = (int)($_POST['exception_id'] ?? 0);
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for exception resolution.');
            }
            $result = mh_finance_gateway_resolve_exception([
                'tenantId' => $redirectTenantId,
                'exceptionId' => $exceptionId,
                'status' => 'resolved',
                'note' => $note,
            ]);
            $flash['message'] = 'Exception marked ' . (string)($result['status'] ?? 'resolved') . ' for event ' . (string)($result['eventKey'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Exception resolution failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'accept_journal') {
        try {
            $entryKey = trim((string)($_POST['entry_key'] ?? ''));
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for journal acceptance.');
            }
            $result = mh_finance_gateway_accept_journal([
                'tenantId' => $redirectTenantId,
                'entryKey' => $entryKey,
                'note' => $note,
            ]);
            $flash['message'] = 'Journal accepted for entry ' . (string)($result['entryKey'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Journal acceptance failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    } elseif ($action === 'dispute_journal') {
        try {
            $entryKey = trim((string)($_POST['entry_key'] ?? ''));
            if ($redirectTenantId === '') {
                throw new RuntimeException('Tenant filter required for journal dispute.');
            }
            $result = mh_finance_gateway_dispute_journal([
                'tenantId' => $redirectTenantId,
                'entryKey' => $entryKey,
                'note' => $note,
            ]);
            $flash['message'] = 'Journal disputed for entry ' . (string)($result['entryKey'] ?? '') . '. Receipt: ' . (string)($result['gateway']['receiptPath'] ?? '');
        } catch (Throwable $e) {
            $flash['message'] = 'Journal dispute failed: ' . $e->getMessage();
            $flash['type'] = 'error';
        }
    }

    $_SESSION['mh_accounting_events_flash'] = $flash;
    $query = http_build_query([
        'tenant_id' => $redirectTenantId,
        'limit' => $limit,
    ]);
    header('Location: /control/accounting-events.php' . ($query !== '' ? ('?' . $query) : ''));
    exit;
}

$counts = mh_finance_counts($db, $tenantId !== '' ? $tenantId : null);
$events = mh_finance_recent_events($db, $tenantId !== '' ? $tenantId : null, $limit);
$exceptions = mh_finance_recent_exceptions($db, $tenantId !== '' ? $tenantId : null, $limit);
$journals = mh_finance_recent_journal_entries($db, $tenantId !== '' ? $tenantId : null, $limit);
$reconciliationRuns = mh_finance_recent_reconciliation_runs($db, $tenantId !== '' ? $tenantId : null, $limit);
$boardExports = mh_finance_recent_board_exports($db, $tenantId !== '' ? $tenantId : null, $limit);

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__) . '/templates');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accounting Events</title>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
<style>
  .mh-page{max-width:1300px;margin:0 auto;padding:18px 20px}
  .mh-page-header{display:flex;flex-direction:column;gap:12px}
  .mh-page-title{margin:0}
  .mh-page-actions{display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:12px 16px;border-radius:10px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s ease;cursor:pointer}
  .btn-primary{background:linear-gradient(135deg,rgba(var(--theme-primary-rgb),.95),rgba(var(--theme-secondary-rgb),.95));color:#000;border:0}
  .btn-secondary{background:rgba(255,255,255,.06);border:1px solid rgba(var(--theme-primary-rgb),.35);color:var(--theme-text,#e8eefc)}
  .btn-danger{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#ffd5cf}
  .btn-secondary:hover{background:rgba(var(--theme-primary-rgb),.12);border-color:rgba(var(--theme-primary-rgb),.55)}
  .btn-danger:hover{background:rgba(239,68,68,.18);border-color:rgba(239,68,68,.55)}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(var(--theme-primary-rgb),.18)}
  .btn-sm{padding:8px 11px;border-radius:8px;font-size:.78rem}
  .card{background:rgba(255,255,255,.05);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:16px}
  .notice{background:rgba(var(--theme-primary-rgb),.08);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:12px 14px}
  .notice.error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
  .grid{display:grid;gap:16px}
  .stats{display:grid;gap:16px;grid-template-columns:repeat(5,minmax(0,1fr))}
  .stat-value{font-size:1.7rem;font-weight:800}
  .form-grid{display:grid;gap:12px;grid-template-columns:1.3fr .7fr auto}
  .form-input{width:100%;padding:12px 15px;border:1px solid rgba(var(--theme-primary-rgb),0.3);border-radius:10px;background:rgba(255,255,255,0.06);color:var(--theme-text,#e8eefc);font-size:14px;box-sizing:border-box}
  .form-input-sm{min-width:180px;padding:8px 10px;font-size:.8rem;border-radius:8px}
  .table{width:100%;border-collapse:collapse}
  .table th,.table td{padding:10px;vertical-align:top;border-bottom:1px solid rgba(255,255,255,.08);text-align:left}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;word-break:break-word}
  .pill{display:inline-flex;align-items:center;justify-content:center;min-width:88px;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,0.12);font-size:.78rem;text-transform:uppercase;letter-spacing:.8px}
  .pill.pending{color:#ffd666;border-color:rgba(255,214,102,.35)}
  .pill.posted{color:#8ff0a4;border-color:rgba(143,240,164,.35)}
  .pill.exception{color:#ffb4a2;border-color:rgba(255,180,162,.35)}
  .pill.resolved,.pill.accepted{color:#8ff0a4;border-color:rgba(143,240,164,.35)}
  .pill.disputed{color:#ffb4a2;border-color:rgba(255,180,162,.35)}
  .action-form{display:grid;gap:8px}
  .action-row{display:flex;gap:8px;flex-wrap:wrap}
  .meta-stack{display:grid;gap:6px}
  .meta-line{font-size:.78rem;line-height:1.35}
  .meta-label{display:inline-block;min-width:68px;font-weight:700;color:rgba(255,255,255,.78)}
  @media (max-width: 1100px){ .stats{grid-template-columns:repeat(2,minmax(0,1fr))} .form-grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content mh-page">
  <div class="mh-page-header">
    <div>
      <h1 class="mh-page-title">Accounting Events</h1>
      <p>Director-only Phase 8 control surface for pending finance events, journal posting status, and open accounting exceptions.</p>
    </div>
    <div class="mh-page-actions">
      <a class="btn btn-secondary" href="/control/grid-webhooks.php">Grid Webhooks</a>
      <a class="btn btn-secondary" href="/hub/grid/transactions.php">Transactions</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="notice <?php echo $messageType === 'error' ? 'error' : ''; ?>"><?php echo h($message); ?></div>
  <?php endif; ?>

  <section class="card">
    <form method="get" class="form-grid">
      <input class="form-input" type="text" name="tenant_id" value="<?php echo h($tenantId); ?>" placeholder="Tenant filter, e.g. user:onemeta">
      <input class="form-input" type="number" min="10" max="200" step="10" name="limit" value="<?php echo h((string)$limit); ?>">
      <button class="btn btn-secondary" type="submit">Apply Filter</button>
    </form>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
      <form method="post">
        <input type="hidden" name="action" value="process_pending">
        <button class="btn btn-primary" type="submit">Process Pending Events</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="run_reconciliation">
        <button class="btn btn-secondary" type="submit">Run Reconciliation Snapshot</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="generate_board_pack">
        <button class="btn btn-secondary" type="submit">Generate Board Pack</button>
      </form>
    </div>
  </section>

  <section class="stats">
    <article class="card"><div>Pending Events</div><div class="stat-value"><?php echo h((string)$counts['pending']); ?></div></article>
    <article class="card"><div>Posted Events</div><div class="stat-value"><?php echo h((string)$counts['posted']); ?></div></article>
    <article class="card"><div>Exception Events</div><div class="stat-value"><?php echo h((string)$counts['exception']); ?></div></article>
    <article class="card"><div>Journal Entries</div><div class="stat-value"><?php echo h((string)$counts['journalEntries']); ?></div></article>
    <article class="card"><div>Open Exceptions</div><div class="stat-value"><?php echo h((string)$counts['openExceptions']); ?></div></article>
  </section>

  <section class="grid" style="grid-template-columns:1.5fr 1fr;margin-top:16px">
    <article class="card">
      <h2>Recent Finance Events</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Occurred</th>
            <th>Type</th>
            <th>Status</th>
            <th>Amount</th>
            <th>Source</th>
            <th>Receipt</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($events === []): ?>
          <tr><td colspan="6">No finance events recorded yet.</td></tr>
        <?php else: foreach ($events as $row): ?>
          <tr>
            <td><?php echo h((string)($row['occurred_at_utc'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['event_type'] ?? '')); ?></td>
            <td><span class="pill <?php echo h((string)($row['posting_status'] ?? 'pending')); ?>"><?php echo h((string)($row['posting_status'] ?? 'pending')); ?></span></td>
            <td><?php echo h(trim((string)($row['amount_decimal'] ?? '')) . ' ' . trim((string)($row['currency'] ?? ''))); ?></td>
            <td class="mono"><?php echo h((string)($row['source_system'] ?? '') . ':' . (string)($row['source_id'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['receipt_path'] ?? '')); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </article>

    <article class="card">
      <h2>Open Exceptions</h2>
      <table class="table">
        <thead>
          <tr>
            <th>When</th>
            <th>Tenant</th>
            <th>Event Key</th>
            <th>Type</th>
            <th>Status</th>
            <th>Message</th>
            <th>Governance</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($exceptions === []): ?>
          <tr><td colspan="8">No accounting exceptions recorded.</td></tr>
        <?php else: foreach ($exceptions as $row): ?>
          <?php
            $approvalSummary = mh_accounting_metadata_summary((string)($row['approval_json'] ?? ''), (string)($row['status'] ?? ''));
            $disputeSummary = mh_accounting_metadata_summary((string)($row['dispute_json'] ?? ''), 'disputed');
          ?>
          <tr>
            <td><?php echo h((string)($row['created_at_utc'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['tenant_id'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['event_key'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['exception_type'] ?? '')); ?></td>
            <td><span class="pill <?php echo h((string)($row['status'] ?? 'exception')); ?>"><?php echo h((string)($row['status'] ?? 'open')); ?></span></td>
            <td><?php echo h((string)($row['message'] ?? '')); ?></td>
            <td>
              <div class="meta-stack">
                <?php if ($approvalSummary !== ''): ?><div class="meta-line"><span class="meta-label">Approval</span><?php echo h($approvalSummary); ?></div><?php endif; ?>
                <?php if ($disputeSummary !== ''): ?><div class="meta-line"><span class="meta-label">Dispute</span><?php echo h($disputeSummary); ?></div><?php endif; ?>
                <?php if ($approvalSummary === '' && $disputeSummary === ''): ?><span class="mono">None</span><?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ((string)($row['status'] ?? 'open') === 'open'): ?>
                <form method="post" class="action-form">
                  <input class="form-input form-input-sm" type="text" name="note" value="" placeholder="Decision note">
                  <input type="hidden" name="tenant_id" value="<?php echo h((string)($row['tenant_id'] ?? '')); ?>">
                  <input type="hidden" name="exception_id" value="<?php echo h((string)($row['id'] ?? '0')); ?>">
                  <div class="action-row">
                    <button class="btn btn-secondary btn-sm" type="submit" name="action" value="requeue_exception">Replay</button>
                    <button class="btn btn-secondary btn-sm" type="submit" name="action" value="dispute_exception">Dispute</button>
                    <button class="btn btn-danger btn-sm" type="submit" name="action" value="resolve_exception">Resolve</button>
                  </div>
                </form>
              <?php else: ?>
                <span class="mono"><?php echo h((string)($row['status'] ?? '')); ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </article>
  </section>

  <section class="grid" style="grid-template-columns:1.15fr .85fr;margin-top:16px">
    <article class="card">
      <h2>Recent Journal Entries</h2>
      <table class="table">
        <thead>
          <tr>
            <th>Occurred</th>
            <th>Entry Key</th>
            <th>Event Key</th>
            <th>Acceptance</th>
            <th>Currency</th>
            <th>Memo</th>
            <th>Governance</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($journals === []): ?>
          <tr><td colspan="8">No journal entries posted yet.</td></tr>
        <?php else: foreach ($journals as $row): ?>
          <?php
            $approvalSummary = mh_accounting_metadata_summary((string)($row['approval_json'] ?? ''), 'accepted');
            $disputeSummary = mh_accounting_metadata_summary((string)($row['dispute_json'] ?? ''), 'disputed');
          ?>
          <tr>
            <td><?php echo h((string)($row['occurred_at_utc'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['entry_key'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['event_key'] ?? '')); ?></td>
            <td><span class="pill <?php echo h((string)($row['acceptance_status'] ?? 'pending')); ?>"><?php echo h((string)($row['acceptance_status'] ?? 'pending')); ?></span></td>
            <td><?php echo h((string)($row['currency'] ?? '')); ?></td>
            <td><?php echo h((string)($row['memo'] ?? '')); ?></td>
            <td>
              <div class="meta-stack">
                <?php if ($approvalSummary !== ''): ?><div class="meta-line"><span class="meta-label">Approval</span><?php echo h($approvalSummary); ?></div><?php endif; ?>
                <?php if ($disputeSummary !== ''): ?><div class="meta-line"><span class="meta-label">Dispute</span><?php echo h($disputeSummary); ?></div><?php endif; ?>
                <?php if ($approvalSummary === '' && $disputeSummary === ''): ?><span class="mono">None</span><?php endif; ?>
              </div>
            </td>
            <td>
              <?php if ((string)($row['acceptance_status'] ?? 'pending') !== 'accepted'): ?>
                <form method="post" class="action-form">
                  <input class="form-input form-input-sm" type="text" name="note" value="" placeholder="Acceptance note">
                  <input type="hidden" name="tenant_id" value="<?php echo h((string)($row['tenant_id'] ?? '')); ?>">
                  <input type="hidden" name="entry_key" value="<?php echo h((string)($row['entry_key'] ?? '')); ?>">
                  <div class="action-row">
                    <button class="btn btn-secondary btn-sm" type="submit" name="action" value="accept_journal">Accept</button>
                    <button class="btn btn-danger btn-sm" type="submit" name="action" value="dispute_journal">Dispute</button>
                  </div>
                </form>
              <?php else: ?>
                <span class="mono">Accepted</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </article>

    <article class="card">
      <h2>Recent Reconciliation Runs</h2>
      <table class="table">
        <thead>
          <tr>
            <th>When</th>
            <th>Type</th>
            <th>Artifact</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($reconciliationRuns === []): ?>
          <tr><td colspan="3">No reconciliation snapshots recorded yet.</td></tr>
        <?php else: foreach ($reconciliationRuns as $row): ?>
          <tr>
            <td><?php echo h((string)($row['created_at_utc'] ?? '')); ?></td>
            <td><?php echo h((string)($row['run_type'] ?? '')); ?></td>
            <td class="mono"><?php echo h((string)($row['artifact_path'] ?? '')); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </article>
  </section>

  <section class="card" style="margin-top:16px">
    <h2>Recent Board Packs</h2>
    <table class="table">
      <thead>
        <tr>
          <th>When</th>
          <th>As Of</th>
          <th>Export Root</th>
          <th>Manifest</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($boardExports === []): ?>
        <tr><td colspan="4">No board packs generated yet.</td></tr>
      <?php else: foreach ($boardExports as $row): ?>
        <tr>
          <td><?php echo h((string)($row['created_at_utc'] ?? '')); ?></td>
          <td><?php echo h((string)($row['as_of_utc'] ?? '')); ?></td>
          <td class="mono"><?php echo h((string)($row['export_root'] ?? '')); ?></td>
          <td class="mono"><?php echo h((string)($row['manifest_path'] ?? '')); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </section>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
