<?php

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../gear/downloads/downloads.php';
require_once __DIR__ . '/../../gear/pdf/pdf_client.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/finance/finora.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/finance/finora.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$userId = (string)($_SESSION['mh_auth_user'] ?? '');
if ($userId !== '' && function_exists('mh_auth_load_user_context')) {
    mh_auth_load_user_context($userId);
}
$tenantId = $userId !== '' ? ('user:' . $userId) : 'user:unknown';
$personaId = (string)($_SESSION['mh_selected_persona'] ?? ($_SESSION['mh_auth_persona'] ?? ($userId !== '' ? ('MH-' . $userId) : 'MH-unknown')));
$metaHumanId = $personaId !== '' ? $personaId : 'MH-unknown';
$sessionId = (string)session_id();
$deviceIdFallback = (string)($_SESSION['mh_device_id'] ?? ($_SESSION['device_id'] ?? ($_SESSION['fingerprint'] ?? '')));
$deviceId = $deviceIdFallback;

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
$database = function_exists('cue_autoload') ? cue_autoload('database') : null;

$statusMessage = null;
$errorMessage = null;

$db = finora_get_db($database);
if (!$db) {
    finora_render_error('Database unavailable');
}

finora_init_schema($db, $paths);
$dbStatus = finora_get_db_status($db);

$csrfToken = $security ? $security->generateCSRFToken('finora') : '';
$view = finora_get_view_param();
$month = finora_get_month_param();
[$startDate, $endDate] = finora_month_range($month);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $getAction = (string)($_GET['action'] ?? '');
    if ($getAction === 'download_backup') {
        $token = (string)($_GET['csrf_token'] ?? '');
        $ok = $security && $security->validateCSRFToken($token, 'finora');
        if (!$ok) {
            finora_render_error('Invalid CSRF token');
        }
        $backupId = (string)($_GET['backup_id'] ?? '');
        finora_download_backup($db, $paths, $tenantId, $userId, $backupId);
        exit;
    }
    if ($getAction === 'download_import') {
        $token = (string)($_GET['csrf_token'] ?? '');
        $ok = $security && $security->validateCSRFToken($token, 'finora');
        if (!$ok) {
            finora_render_error('Invalid CSRF token');
        }
        $importId = (string)($_GET['import_id'] ?? '');
        finora_download_import_file($db, $paths, $tenantId, $userId, $importId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $ok = $security && $security->validateCSRFToken($token, 'finora');
    if (!$ok) {
        finora_render_error('Invalid CSRF token');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add_category') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name is required');
            }
            finora_add_category($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, $name);
            $statusMessage = 'Category added';
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'category.add', ['name' => $name]);
        } elseif ($action === 'add_entry') {
            $entryType = (string)($_POST['entry_type'] ?? 'expense');
            $amount = (string)($_POST['amount'] ?? '');
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            $occurredOn = (string)($_POST['occurred_on'] ?? '');
            $category = trim((string)($_POST['category'] ?? ''));
            $method = trim((string)($_POST['method'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            finora_add_entry(
                $db,
                $tenantId,
                $userId,
                $personaId,
                $metaHumanId,
                $sessionId,
                $deviceId !== '' ? $deviceId : null,
                $entryType,
                $amount,
                $currency,
                $occurredOn,
                $category !== '' ? $category : null,
                $method !== '' ? $method : null,
                $description !== '' ? $description : null
            );
            $statusMessage = 'Entry saved';
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'entry.add', ['type' => $entryType, 'amount' => $amount, 'currency' => $currency, 'occurred_on' => $occurredOn, 'category' => $category, 'method' => $method]);
        } elseif ($action === 'delete_entry') {
            $id = (string)($_POST['id'] ?? '');
            finora_delete_entry($db, $tenantId, $userId, $id);
            $statusMessage = 'Entry deleted';
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'entry.delete', ['id' => $id]);
        } elseif ($action === 'add_method') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Method name is required');
            }
            finora_add_method($db, $tenantId, $userId, $name);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'method.add', ['name' => $name]);
            $statusMessage = 'Method added';
        } elseif ($action === 'add_recurring') {
            $name = trim((string)($_POST['name'] ?? ''));
            $entryType = (string)($_POST['entry_type'] ?? 'expense');
            $amount = (string)($_POST['amount'] ?? '');
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            $category = trim((string)($_POST['category'] ?? ''));
            $method = trim((string)($_POST['method'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $startOn = (string)($_POST['start_on'] ?? '');
            $intervalUnit = (string)($_POST['interval_unit'] ?? 'month');
            $intervalCount = (string)($_POST['interval_count'] ?? '1');
            $dayOfMonth = (string)($_POST['day_of_month'] ?? '');
            $weekday = (string)($_POST['weekday'] ?? '');
            $endOn = (string)($_POST['end_on'] ?? '');
            finora_add_recurring_rule(
                $db,
                $tenantId,
                $userId,
                $name,
                $entryType,
                $amount,
                $currency,
                $category !== '' ? $category : null,
                $method !== '' ? $method : null,
                $description !== '' ? $description : null,
                $startOn,
                $intervalUnit,
                $intervalCount,
                $dayOfMonth !== '' ? $dayOfMonth : null,
                $weekday !== '' ? $weekday : null,
                $endOn !== '' ? $endOn : null
            );
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'recurring.add', ['name' => $name]);
            $statusMessage = 'Recurring rule created';
        } elseif ($action === 'set_budget') {
            $budgetMonth = (string)($_POST['budget_month'] ?? $month);
            $category = trim((string)($_POST['category'] ?? ''));
            $amount = (string)($_POST['amount'] ?? '');
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            finora_set_budget($db, $tenantId, $userId, $budgetMonth, $category !== '' ? $category : null, $amount, $currency);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'budget.set', ['month' => $budgetMonth, 'category' => $category]);
            $statusMessage = 'Budget saved';
        } elseif ($action === 'add_goal') {
            $name = trim((string)($_POST['name'] ?? ''));
            $targetAmount = (string)($_POST['target_amount'] ?? '');
            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
            $dueOn = (string)($_POST['due_on'] ?? '');
            finora_add_goal($db, $tenantId, $userId, $name, $targetAmount, $currency, $dueOn !== '' ? $dueOn : null);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'goal.add', ['name' => $name]);
            $statusMessage = 'Goal created';
        } elseif ($action === 'add_goal_contribution') {
            $goalId = (string)($_POST['goal_id'] ?? '');
            $amount = (string)($_POST['amount'] ?? '');
            $occurredOn = (string)($_POST['occurred_on'] ?? gmdate('Y-m-d'));
            $note = trim((string)($_POST['note'] ?? ''));
            finora_add_goal_contribution($db, $tenantId, $userId, $goalId, $amount, $occurredOn, $note !== '' ? $note : null);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'goal.contribute', ['goal_id' => $goalId]);
            $statusMessage = 'Contribution saved';
        } elseif ($action === 'import_entries') {
            if (!$paths) {
                throw new RuntimeException('Paths module unavailable');
            }
            if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
                throw new RuntimeException('Import file missing');
            }
            $result = finora_import_entries_file(
                $db,
                $paths,
                $tenantId,
                $userId,
                $personaId,
                $metaHumanId,
                $sessionId,
                $deviceId !== '' ? $deviceId : null,
                $_FILES['import_file']
            );
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'import.run', $result);
            $statusMessage = 'Import completed: ' . ((int)($result['imported_rows'] ?? 0)) . ' rows';
        } elseif ($action === 'export_csv') {
            finora_export_csv($db, $tenantId, $userId, $month);
            exit;
        } elseif ($action === 'export_pdf') {
            $metricsPdf = finora_get_metrics($db, $tenantId, $userId, $startDate, $endDate);
            $entriesPdf = finora_list_entries($db, $tenantId, $userId, $startDate, $endDate);
            $html = finora_build_monthly_report_html($tenantId, $userId, $personaId, $month, $startDate, $endDate, $metricsPdf, $entriesPdf);
            $tenantKey = finora_sanitize_key($tenantId);
            $fileName = 'finora_' . $tenantKey . '_' . $month . '.pdf';
            $pdf = mh_pdf_convert_html_to_pdf_bytes($html, $fileName);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'export.pdf', ['month' => $month]);
            mh_pdf_send_pdf_bytes($pdf, $fileName);
            exit;
        } elseif ($action === 'backup_json') {
            if (!$paths) {
                throw new RuntimeException('Paths module unavailable');
            }
            $backup = finora_create_backup($db, $paths, $tenantId, $userId, $month, 20);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'backup.create', ['backup_id' => $backup['id'] ?? null]);
            $statusMessage = 'Backup created: ' . ($backup['file_name'] ?? '');
        } elseif ($action === 'delete_backup') {
            if (!$paths) {
                throw new RuntimeException('Paths module unavailable');
            }
            $backupId = (string)($_POST['backup_id'] ?? '');
            finora_delete_backup($db, $paths, $tenantId, $userId, $backupId);
            finora_log_activity($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, 'backup.delete', ['backup_id' => $backupId]);
            $statusMessage = 'Backup deleted';
        } else {
            throw new RuntimeException('Unknown action');
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

finora_generate_recurring_for_range($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId !== '' ? $deviceId : null, $startDate, $endDate);

$categories = finora_list_categories($db, $tenantId, $userId);
$methods = finora_list_methods($db, $tenantId, $userId);

$metrics = finora_get_metrics($db, $tenantId, $userId, $startDate, $endDate);
$entries = finora_list_entries($db, $tenantId, $userId, $startDate, $endDate);
$recurringRules = $view === 'recurring' ? finora_list_recurring_rules($db, $tenantId, $userId) : [];
$budgets = $view === 'budgets' ? finora_list_budgets($db, $tenantId, $userId, $month) : [];
$budgetSpent = $view === 'budgets' ? finora_budget_spent_by_category($db, $tenantId, $userId, $startDate, $endDate) : [];
$goals = $view === 'goals' ? finora_list_goals($db, $tenantId, $userId) : [];
$goalProgress = $view === 'goals' ? finora_goal_progress_map($db, $tenantId, $userId) : [];
$backups = $view === 'backups' ? finora_list_backups($db, $tenantId, $userId, 50) : [];
$imports = $view === 'import' ? finora_list_import_batches($db, $tenantId, $userId, 50) : [];
$activity = $view === 'profile' ? finora_list_activity($db, $tenantId, $userId, 50) : [];

if (!function_exists('renderGlobalHeader')) {
    require_once __DIR__ . '/../../templates/global-ui/functions.php';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finora</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.hub-finora main.main-content { color:#fff; font-family: var(--font-primary, 'Rajdhani', sans-serif); }
        body.hub-finora { --primary:#00d4ff; --bg:#0a0a0a; --panel:rgba(255,255,255,0.04); --border:rgba(255,255,255,0.12); --muted:#a1a1aa; }
        body.hub-finora .wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .card { background: var(--panel); border:1px solid var(--border); border-radius: 14px; padding: 16px; }
        .title { font-family:'Orbitron',sans-serif; color: var(--primary); margin: 0 0 10px; }
        .row { display:flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        label { color: var(--muted); font-size: 0.9rem; }
        input, select, textarea { background: rgba(0,0,0,0.35); border:1px solid var(--border); color:#fff; border-radius: 10px; padding: 10px 12px; font-family: inherit; }
        textarea { width: 100%; min-height: 70px; }
        .btn { background: linear-gradient(45deg, var(--primary), #7c3aed); border: none; color: #000; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn.secondary { background: rgba(255,255,255,0.08); color: #fff; border: 1px solid var(--border); }
        table { width:100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: left; font-size: 0.95rem; }
        th { color: var(--muted); font-weight: 600; }
        .right { text-align: right; }
        .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.10); font-size: 0.85rem; color: var(--muted); }
        .kpi { display:flex; gap: 12px; flex-wrap: wrap; }
        .kpi .box { padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.10); background: rgba(0,0,0,0.2); min-width: 160px; }
        .kpi .v { font-family: 'Orbitron', sans-serif; color: var(--primary); font-size: 1.1rem; }
    </style>
    <?php
    try {
        if (function_exists('includeNoticesWidget')) {
            includeNoticesWidget();
        }
    } catch (Throwable $e) {
    }
    ?>
</head>
<body class="hub-finora">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">

<div class="wrap">
    <div class="row" style="justify-content: space-between;">
        <h1 class="title" style="margin:0;">Finora Financial Planning Tool</h1>
        <div class="row">
            <span class="pill">Tenant <?php echo htmlspecialchars($tenantId); ?></span>
            <span class="pill">Persona: <?php echo htmlspecialchars($personaId); ?></span>
        </div>
    </div>

    <div class="row" style="margin-top: 14px;">
        <?php
        $tabs = [
            'dashboard' => 'Dashboard',
            'recurring' => 'Recurring',
            'budgets' => 'Budgets',
            'goals' => 'Goals',
            'import' => 'Import',
            'backups' => 'Backups',
            'profile' => 'Profile',
        ];
        foreach ($tabs as $k => $label) {
            $href = '?view=' . rawurlencode($k) . '&month=' . rawurlencode($month);
            $active = $view === $k;
            $style = $active ? 'border-color: rgba(0,212,255,0.6); color: var(--primary);' : '';
            echo '<a class="pill" href="' . htmlspecialchars($href) . '" style="text-decoration:none;' . $style . '">' . htmlspecialchars($label) . '</a>';
        }
        ?>
    </div>

    <?php if ($view === 'dashboard'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Month</h2>
            <form method="GET" class="row">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
                <label for="month">Month</label>
                <input id="month" name="month" type="month" value="<?php echo htmlspecialchars($month); ?>">
                <button class="btn secondary" type="submit">Load</button>
            </form>
            <div class="kpi" style="margin-top: 14px;">
                <div class="box">
                    <div style="color:var(--muted);">Income</div>
                    <div class="v"><?php echo htmlspecialchars($metrics['income']); ?></div>
                </div>
                <div class="box">
                    <div style="color:var(--muted);">Expense</div>
                    <div class="v"><?php echo htmlspecialchars($metrics['expense']); ?></div>
                </div>
                <div class="box">
                    <div style="color:var(--muted);">Net</div>
                    <div class="v"><?php echo htmlspecialchars($metrics['net']); ?></div>
                </div>
            </div>
            <div class="row" style="margin-top: 14px;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="export_csv">
                    <button class="btn secondary" type="submit">Export CSV</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="export_pdf">
                    <button class="btn secondary" type="submit">Export PDF</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="backup_json">
                    <button class="btn secondary" type="submit">Create Backup</button>
                </form>
            </div>
        </div>

        <div class="card">
            <h2 class="title">Add Entry</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_entry">
                <div class="row">
                    <label for="entry_type">Type</label>
                    <select id="entry_type" name="entry_type">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>

                    <label for="amount">Amount</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0" required>

                    <label for="currency">Currency</label>
                    <input id="currency" name="currency" value="USD" maxlength="3" style="width:80px;">

                    <label for="occurred_on">Date</label>
                    <input id="occurred_on" name="occurred_on" type="date" value="<?php echo htmlspecialchars(gmdate('Y-m-d')); ?>" required>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label for="category">Category</label>
                    <select id="category" name="category" style="min-width:220px;">
                        <option value="">(none)</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="method">Method</label>
                    <input id="method" name="method" list="finora-methods" placeholder="Card / Cash / Bank" style="min-width: 220px;">
                    <datalist id="finora-methods">
                        <?php foreach ($methods as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div style="margin-top: 10px;">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Optional note..."></textarea>
                </div>
                <div class="row" style="margin-top: 12px;">
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>

            <h3 class="title" style="margin-top: 16px;">Categories</h3>
            <form method="POST" class="row">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_category">
                <input name="name" placeholder="New category" required>
                <button class="btn secondary" type="submit">Add</button>
            </form>

            <h3 class="title" style="margin-top: 16px;">Methods</h3>
            <form method="POST" class="row">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_method">
                <input name="name" placeholder="New method" required>
                <button class="btn secondary" type="submit">Add</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <div class="row" style="justify-content: space-between;">
            <h2 class="title" style="margin:0;">Entries</h2>
            <span class="pill"><?php echo htmlspecialchars($startDate . ' → ' . $endDate); ?></span>
        </div>
        <div style="overflow:auto; margin-top: 10px;">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Method</th>
                        <th class="right">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$entries): ?>
                    <tr><td colspan="7" style="color:var(--muted);">No entries for this month.</td></tr>
                <?php else: ?>
                    <?php foreach ($entries as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($e['occurred_on']); ?></td>
                            <td><?php echo htmlspecialchars($e['entry_type']); ?></td>
                            <td><?php echo htmlspecialchars((string)($e['category'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($e['description'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($e['method'] ?? '')); ?></td>
                            <td class="right"><?php echo htmlspecialchars($e['currency'] . ' ' . $e['amount']); ?></td>
                            <td class="right">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_entry">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$e['id']); ?>">
                                    <button class="btn secondary" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($view === 'recurring'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Create Recurring</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_recurring">
                <div class="row">
                    <label>Name</label>
                    <input name="name" required style="min-width: 240px;">
                    <label>Type</label>
                    <select name="entry_type">
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                    <label>Amount</label>
                    <input name="amount" type="number" step="0.01" min="0" required>
                    <label>Currency</label>
                    <input name="currency" value="USD" maxlength="3" style="width:80px;">
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label>Category</label>
                    <select name="category" style="min-width:220px;">
                        <option value="">(none)</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Method</label>
                    <input name="method" list="finora-methods" style="min-width:220px;">
                </div>
                <div style="margin-top: 10px;">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label>Start</label>
                    <input name="start_on" type="date" value="<?php echo htmlspecialchars($startDate); ?>" required>
                    <label>End</label>
                    <input name="end_on" type="date">
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label>Every</label>
                    <input name="interval_count" type="number" min="1" value="1" style="width:90px;">
                    <select name="interval_unit">
                        <option value="day">Day</option>
                        <option value="week">Week</option>
                        <option value="month" selected>Month</option>
                        <option value="year">Year</option>
                    </select>
                    <label>Day of month</label>
                    <input name="day_of_month" type="number" min="1" max="31" style="width:90px;">
                    <label>Weekday</label>
                    <select name="weekday">
                        <option value="">(none)</option>
                        <option value="1">Mon</option>
                        <option value="2">Tue</option>
                        <option value="3">Wed</option>
                        <option value="4">Thu</option>
                        <option value="5">Fri</option>
                        <option value="6">Sat</option>
                        <option value="7">Sun</option>
                    </select>
                </div>
                <div class="row" style="margin-top: 12px;">
                    <button class="btn" type="submit">Create</button>
                </div>
            </form>
        </div>
        <div class="card">
            <h2 class="title">Rules</h2>
            <div style="overflow:auto;">
                <table>
                    <thead><tr><th>Name</th><th>Type</th><th>Amount</th><th>Schedule</th><th>Start</th><th>End</th></tr></thead>
                    <tbody>
                    <?php if (!$recurringRules): ?>
                        <tr><td colspan="6" style="color:var(--muted);">No recurring rules yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recurringRules as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($r['name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['entry_type'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['currency'] ?? '') . ' ' . (string)($r['amount'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(finora_format_rule_schedule($r)); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['start_on'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['end_on'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($view === 'budgets'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Set Budget</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="set_budget">
                <div class="row">
                    <label>Month</label>
                    <input name="budget_month" type="month" value="<?php echo htmlspecialchars($month); ?>">
                    <label>Category</label>
                    <select name="category" style="min-width:220px;">
                        <option value="">(all expenses)</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label>Amount</label>
                    <input name="amount" type="number" step="0.01" min="0" required>
                    <label>Currency</label>
                    <input name="currency" value="USD" maxlength="3" style="width:80px;">
                    <button class="btn" type="submit">Save</button>
                </div>
            </form>
        </div>
        <div class="card">
            <h2 class="title">This Month</h2>
            <div style="overflow:auto;">
                <table>
                    <thead><tr><th>Category</th><th class="right">Budget</th><th class="right">Spent</th><th class="right">Remaining</th></tr></thead>
                    <tbody>
                    <?php if (!$budgets): ?>
                        <tr><td colspan="4" style="color:var(--muted);">No budgets set for this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($budgets as $b): ?>
                            <?php
                            $cat = (string)($b['category'] ?? '');
                            $spent = (float)($budgetSpent[$cat === '' ? '__ALL__' : $cat] ?? 0);
                            $budgetAmt = (float)($b['amount'] ?? 0);
                            $rem = $budgetAmt - $spent;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat === '' ? '(all expenses)' : $cat); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)($b['currency'] ?? 'USD') . ' ' . number_format($budgetAmt, 2, '.', '')); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)($b['currency'] ?? 'USD') . ' ' . number_format($spent, 2, '.', '')); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)($b['currency'] ?? 'USD') . ' ' . number_format($rem, 2, '.', '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($view === 'goals'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Create Goal</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_goal">
                <div class="row">
                    <label>Name</label>
                    <input name="name" required style="min-width:240px;">
                    <label>Target</label>
                    <input name="target_amount" type="number" step="0.01" min="0" required>
                    <label>Currency</label>
                    <input name="currency" value="USD" maxlength="3" style="width:80px;">
                </div>
                <div class="row" style="margin-top: 10px;">
                    <label>Due</label>
                    <input name="due_on" type="date">
                    <button class="btn" type="submit">Create</button>
                </div>
            </form>

            <h2 class="title" style="margin-top: 16px;">Add Contribution</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_goal_contribution">
                <div class="row">
                    <label>Goal</label>
                    <select name="goal_id" required style="min-width: 240px;">
                        <option value="">Select...</option>
                        <?php foreach ($goals as $g): ?>
                            <option value="<?php echo htmlspecialchars((string)$g['id']); ?>"><?php echo htmlspecialchars((string)$g['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Amount</label>
                    <input name="amount" type="number" step="0.01" min="0" required>
                    <label>Date</label>
                    <input name="occurred_on" type="date" value="<?php echo htmlspecialchars(gmdate('Y-m-d')); ?>" required>
                </div>
                <div style="margin-top:10px;">
                    <label>Note</label>
                    <input name="note">
                </div>
                <div class="row" style="margin-top: 12px;">
                    <button class="btn secondary" type="submit">Save</button>
                </div>
            </form>
        </div>
        <div class="card">
            <h2 class="title">Goals</h2>
            <div style="overflow:auto;">
                <table>
                    <thead><tr><th>Name</th><th>Due</th><th class="right">Target</th><th class="right">Progress</th><th class="right">Remaining</th></tr></thead>
                    <tbody>
                    <?php if (!$goals): ?>
                        <tr><td colspan="5" style="color:var(--muted);">No goals yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($goals as $g): ?>
                            <?php
                            $gid = (string)$g['id'];
                            $prog = (float)($goalProgress[$gid] ?? 0);
                            $target = (float)($g['target_amount'] ?? 0);
                            $rem = $target - $prog;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$g['name']); ?></td>
                                <td><?php echo htmlspecialchars((string)($g['due_on'] ?? '')); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)$g['currency'] . ' ' . number_format($target, 2, '.', '')); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)$g['currency'] . ' ' . number_format($prog, 2, '.', '')); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)$g['currency'] . ' ' . number_format($rem, 2, '.', '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($view === 'import'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Import</h2>
            <div style="color:var(--muted); font-size: 0.95rem;">
                CSV columns: occurred_on, entry_type, amount, currency, category, method, description
            </div>
            <form method="POST" enctype="multipart/form-data" style="margin-top: 12px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="import_entries">
                <div class="row">
                    <input type="file" name="import_file" accept=".csv,.xlsx" required>
                    <button class="btn" type="submit">Import</button>
                </div>
            </form>
        </div>
        <div class="card">
            <h2 class="title">Recent Imports</h2>
            <div style="overflow:auto;">
                <table>
                    <thead><tr><th>When</th><th>File</th><th class="right">Imported</th><th class="right">Skipped</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$imports): ?>
                        <tr><td colspan="5" style="color:var(--muted);">No imports yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($imports as $im): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$im['created_at_utc']); ?></td>
                                <td><?php echo htmlspecialchars((string)$im['file_name']); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)$im['imported_rows']); ?></td>
                                <td class="right"><?php echo htmlspecialchars((string)$im['skipped_rows']); ?></td>
                                <td class="right">
                                    <a class="pill" href="<?php echo htmlspecialchars('?view=import&month=' . rawurlencode($month) . '&action=download_import&import_id=' . rawurlencode((string)$im['id']) . '&csrf_token=' . rawurlencode($csrfToken)); ?>" style="text-decoration:none;">Download</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($view === 'backups'): ?>
    <div class="card" style="margin-top: 16px;">
        <div class="row" style="justify-content: space-between;">
            <h2 class="title" style="margin:0;">Backups</h2>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="backup_json">
                <button class="btn secondary" type="submit">Create Backup</button>
            </form>
        </div>
        <div style="overflow:auto; margin-top: 10px;">
            <table>
                <thead><tr><th>When</th><th>Month</th><th>File</th><th class="right">Size</th><th></th></tr></thead>
                <tbody>
                <?php if (!$backups): ?>
                    <tr><td colspan="5" style="color:var(--muted);">No backups yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$b['created_at_utc']); ?></td>
                            <td><?php echo htmlspecialchars((string)$b['month']); ?></td>
                            <td><?php echo htmlspecialchars((string)$b['file_name']); ?></td>
                            <td class="right"><?php echo htmlspecialchars(number_format(((int)$b['file_bytes']) / 1024, 1, '.', '') . ' KB'); ?></td>
                            <td class="right">
                                <a class="pill" href="<?php echo htmlspecialchars('?view=backups&month=' . rawurlencode($month) . '&action=download_backup&backup_id=' . rawurlencode((string)$b['id']) . '&csrf_token=' . rawurlencode($csrfToken)); ?>" style="text-decoration:none;">Download</a>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_backup">
                                    <input type="hidden" name="backup_id" value="<?php echo htmlspecialchars((string)$b['id']); ?>">
                                    <button class="btn secondary" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($view === 'profile'): ?>
    <div class="grid" style="margin-top: 16px;">
        <div class="card">
            <h2 class="title">Methods</h2>
            <form method="POST" class="row">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_method">
                <input name="name" placeholder="New method" required>
                <button class="btn secondary" type="submit">Add</button>
            </form>
            <div style="margin-top: 10px; color:var(--muted);">
                <?php echo htmlspecialchars(implode(', ', $methods)); ?>
            </div>
        </div>
        <div class="card">
            <h2 class="title">Activity</h2>
            <div style="overflow:auto;">
                <table>
                    <thead><tr><th>When</th><th>Type</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php if (!$activity): ?>
                        <tr><td colspan="3" style="color:var(--muted);">No activity yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activity as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$a['created_at_utc']); ?></td>
                                <td><?php echo htmlspecialchars((string)$a['event_type']); ?></td>
                                <td><?php echo htmlspecialchars((string)$a['event_data_json']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
function finoraNotice(message, type) {
    try {
        var inst = window.popupNotice || window.globalPopupNotice || null;
        if (!inst && typeof window.PopupNotice !== 'undefined') {
            inst = new window.PopupNotice();
            window.popupNotice = inst;
        }
        if (inst && typeof inst.show === 'function') {
            inst.show(message, type || 'info');
        }
    } catch (_) {}
}
document.addEventListener('DOMContentLoaded', function () {
    <?php if (is_string($statusMessage) && $statusMessage !== ''): ?>
    finoraNotice(<?php echo json_encode($statusMessage); ?>, 'success');
    <?php endif; ?>
    <?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    finoraNotice(<?php echo json_encode($errorMessage); ?>, 'error');
    <?php endif; ?>
});
</script>
</body>
</html>

<?php

function finora_get_db($database): ?PDO
{
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getContextAwareConnection')) {
            return null;
        }
        return database_getContextAwareConnection();
    } catch (Throwable $e) {
        return null;
    }
}

function finora_init_schema(PDO $db, $paths): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finora_meta (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            schema_version INT UNSIGNED NOT NULL,
            updated_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $row = $db->query("SELECT schema_version FROM mh_finora_meta WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->exec("INSERT INTO mh_finora_meta (id, schema_version) VALUES (1, 0)");
        $row = ['schema_version' => 0];
    }
    $version = (int)($row['schema_version'] ?? 0);

    if ($version < 1) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                persona_id VARCHAR(191) NOT NULL,
                meta_human_id VARCHAR(191) NOT NULL,
                session_id VARCHAR(191) NOT NULL,
                device_id VARCHAR(191) NULL,
                entry_type ENUM('income','expense') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                category VARCHAR(191) NULL,
                method VARCHAR(191) NULL,
                description TEXT NULL,
                occurred_on DATE NOT NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_date (tenant_id, user_id, occurred_on, id),
                INDEX idx_tenant_persona_date (tenant_id, persona_id, occurred_on, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                persona_id VARCHAR(191) NOT NULL,
                meta_human_id VARCHAR(191) NOT NULL,
                session_id VARCHAR(191) NOT NULL,
                device_id VARCHAR(191) NULL,
                name VARCHAR(191) NOT NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_user_name (tenant_id, user_id, name),
                INDEX idx_tenant_user (tenant_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("UPDATE mh_finora_meta SET schema_version = 1 WHERE id = 1");
        $version = 1;
    }

    if ($version < 2) {
        finora_schema_try_exec($db, "ALTER TABLE mh_finora_entries ADD COLUMN updated_at_utc TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
        finora_schema_try_exec($db, "ALTER TABLE mh_finora_entries ADD COLUMN source ENUM('manual','recurring','import') NOT NULL DEFAULT 'manual'");
        finora_schema_try_exec($db, "ALTER TABLE mh_finora_entries ADD COLUMN recurring_rule_id BIGINT UNSIGNED NULL");
        finora_schema_try_exec($db, "ALTER TABLE mh_finora_entries ADD COLUMN import_batch_id BIGINT UNSIGNED NULL");
        finora_schema_try_exec($db, "CREATE UNIQUE INDEX uq_tenant_user_recurring_date ON mh_finora_entries (tenant_id, user_id, recurring_rule_id, occurred_on)");
        finora_schema_try_exec($db, "CREATE INDEX idx_tenant_user_source_date ON mh_finora_entries (tenant_id, user_id, source, occurred_on, id)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_methods (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                name VARCHAR(191) NOT NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_user_method (tenant_id, user_id, name),
                INDEX idx_tenant_user (tenant_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_budgets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                month CHAR(7) NOT NULL,
                category VARCHAR(191) NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_user_budget (tenant_id, user_id, month, category),
                INDEX idx_tenant_user_month (tenant_id, user_id, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_goals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                name VARCHAR(191) NOT NULL,
                target_amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                due_on DATE NULL,
                status ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_status (tenant_id, user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_goal_contributions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                goal_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                occurred_on DATE NOT NULL,
                note VARCHAR(255) NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_goal (goal_id),
                INDEX idx_tenant_user_date (tenant_id, user_id, occurred_on),
                CONSTRAINT fk_finora_goal FOREIGN KEY (goal_id) REFERENCES mh_finora_goals(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_recurring_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                name VARCHAR(191) NOT NULL,
                entry_type ENUM('income','expense') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'USD',
                category VARCHAR(191) NULL,
                method VARCHAR(191) NULL,
                description TEXT NULL,
                start_on DATE NOT NULL,
                end_on DATE NULL,
                interval_unit ENUM('day','week','month','year') NOT NULL DEFAULT 'month',
                interval_count INT UNSIGNED NOT NULL DEFAULT 1,
                day_of_month TINYINT UNSIGNED NULL,
                weekday TINYINT UNSIGNED NULL,
                last_generated_on DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_active (tenant_id, user_id, is_active),
                INDEX idx_tenant_user_start (tenant_id, user_id, start_on)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_import_batches (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_rel_path VARCHAR(500) NOT NULL,
                file_sha256 CHAR(64) NOT NULL,
                imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
                skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_created (tenant_id, user_id, created_at_utc)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_backups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                month CHAR(7) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_rel_path VARCHAR(500) NOT NULL,
                file_sha256 CHAR(64) NOT NULL,
                file_bytes BIGINT UNSIGNED NOT NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_created (tenant_id, user_id, created_at_utc),
                INDEX idx_tenant_user_month (tenant_id, user_id, month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS mh_finora_activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id VARCHAR(191) NOT NULL,
                user_id VARCHAR(191) NOT NULL,
                persona_id VARCHAR(191) NOT NULL,
                meta_human_id VARCHAR(191) NOT NULL,
                session_id VARCHAR(191) NOT NULL,
                device_id VARCHAR(191) NULL,
                event_type VARCHAR(64) NOT NULL,
                event_data_json JSON NULL,
                created_at_utc TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tenant_user_created (tenant_id, user_id, created_at_utc),
                INDEX idx_tenant_user_type (tenant_id, user_id, event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("UPDATE mh_finora_meta SET schema_version = 2 WHERE id = 1");
        $version = 2;
    }
}

function finora_schema_try_exec(PDO $db, string $sql): void
{
    try {
        $db->exec($sql);
    } catch (Throwable $e) {
    }
}

function finora_add_category(PDO $db, string $tenantId, string $userId, string $personaId, string $metaHumanId, string $sessionId, ?string $deviceId, string $name): void
{
    $stmt = $db->prepare("
        INSERT INTO mh_finora_categories (tenant_id, user_id, persona_id, meta_human_id, session_id, device_id, name)
        VALUES (:tenant_id, :user_id, :persona_id, :meta_human_id, :session_id, :device_id, :name)
        ON DUPLICATE KEY UPDATE name = VALUES(name)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':persona_id' => $personaId,
        ':meta_human_id' => $metaHumanId,
        ':session_id' => $sessionId,
        ':device_id' => $deviceId,
        ':name' => $name,
    ]);
}

function finora_add_entry(
    PDO $db,
    string $tenantId,
    string $userId,
    string $personaId,
    string $metaHumanId,
    string $sessionId,
    ?string $deviceId,
    string $entryType,
    string $amount,
    string $currency,
    string $occurredOn,
    ?string $category,
    ?string $method,
    ?string $description,
    string $source = 'manual',
    ?string $recurringRuleId = null,
    ?string $importBatchId = null
): void {
    $entryType = $entryType === 'income' ? 'income' : 'expense';
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency must be a 3-letter code');
    }
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $occurredOn)) {
        throw new RuntimeException('Invalid date');
    }
    $amountNum = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($amountNum === false || $amountNum < 0) {
        throw new RuntimeException('Invalid amount');
    }

    $source = in_array($source, ['manual', 'recurring', 'import'], true) ? $source : 'manual';
    $recurringRuleId = is_string($recurringRuleId) && preg_match('/^\\d+$/', $recurringRuleId) ? $recurringRuleId : null;
    $importBatchId = is_string($importBatchId) && preg_match('/^\\d+$/', $importBatchId) ? $importBatchId : null;

    $stmt = $db->prepare("
        INSERT INTO mh_finora_entries
            (tenant_id, user_id, persona_id, meta_human_id, session_id, device_id, source, recurring_rule_id, import_batch_id, entry_type, amount, currency, category, method, description, occurred_on)
        VALUES
            (:tenant_id, :user_id, :persona_id, :meta_human_id, :session_id, :device_id, :source, :recurring_rule_id, :import_batch_id, :entry_type, :amount, :currency, :category, :method, :description, :occurred_on)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':persona_id' => $personaId,
        ':meta_human_id' => $metaHumanId,
        ':session_id' => $sessionId,
        ':device_id' => $deviceId,
        ':source' => $source,
        ':recurring_rule_id' => $recurringRuleId,
        ':import_batch_id' => $importBatchId,
        ':entry_type' => $entryType,
        ':amount' => number_format((float)$amountNum, 2, '.', ''),
        ':currency' => $currency,
        ':category' => $category,
        ':method' => $method,
        ':description' => $description,
        ':occurred_on' => $occurredOn,
    ]);

    if ($category !== null && $category !== '') {
        finora_add_category($db, $tenantId, $userId, $personaId, $metaHumanId, $sessionId, $deviceId, $category);
    }
}

function finora_delete_entry(PDO $db, string $tenantId, string $userId, string $id): void
{
    if (!preg_match('/^\\d+$/', $id)) {
        throw new RuntimeException('Invalid entry id');
    }
    $stmt = $db->prepare("
        DELETE FROM mh_finora_entries
        WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $id,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);
}

function finora_list_categories(PDO $db, string $tenantId, string $userId): array
{
    $stmt = $db->prepare("
        SELECT name
        FROM mh_finora_categories
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY name ASC
        LIMIT 500
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $n = (string)($r['name'] ?? '');
        if ($n !== '') {
            $out[] = $n;
        }
    }
    return $out;
}

function finora_get_metrics(PDO $db, string $tenantId, string $userId, string $startDate, string $endDate): array
{
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN entry_type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN entry_type = 'expense' THEN amount ELSE 0 END), 0) AS expense
        FROM mh_finora_entries
        WHERE tenant_id = :tenant_id
          AND user_id = :user_id
          AND occurred_on BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $income = (float)($row['income'] ?? 0);
    $expense = (float)($row['expense'] ?? 0);
    $net = $income - $expense;
    return [
        'income' => number_format($income, 2, '.', ''),
        'expense' => number_format($expense, 2, '.', ''),
        'net' => number_format($net, 2, '.', ''),
    ];
}

function finora_list_entries(PDO $db, string $tenantId, string $userId, string $startDate, string $endDate): array
{
    $stmt = $db->prepare("
        SELECT id, entry_type, amount, currency, category, method, description, occurred_on
        FROM mh_finora_entries
        WHERE tenant_id = :tenant_id
          AND user_id = :user_id
          AND occurred_on BETWEEN :start_date AND :end_date
        ORDER BY occurred_on DESC, id DESC
        LIMIT 200
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_export_csv(PDO $db, string $tenantId, string $userId, string $month): void
{
    [$startDate, $endDate] = finora_month_range($month);
    $entries = finora_list_entries($db, $tenantId, $userId, $startDate, $endDate);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="finora_' . $month . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['occurred_on', 'entry_type', 'amount', 'currency', 'category', 'method', 'description']);
    foreach ($entries as $e) {
        fputcsv($out, [
            (string)($e['occurred_on'] ?? ''),
            (string)($e['entry_type'] ?? ''),
            (string)($e['amount'] ?? ''),
            (string)($e['currency'] ?? ''),
            (string)($e['category'] ?? ''),
            (string)($e['method'] ?? ''),
            (string)($e['description'] ?? ''),
        ]);
    }
    fclose($out);
}

function finora_build_monthly_report_html(
    string $tenantId,
    string $userId,
    string $personaId,
    string $month,
    string $startDate,
    string $endDate,
    array $metrics,
    array $entries
): string {
    $title = 'Finora Monthly Report';
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMonth = htmlspecialchars($month, ENT_QUOTES, 'UTF-8');
    $safeTenant = htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars($userId, ENT_QUOTES, 'UTF-8');
    $safePersona = htmlspecialchars($personaId, ENT_QUOTES, 'UTF-8');
    $safeRange = htmlspecialchars($startDate . ' → ' . $endDate, ENT_QUOTES, 'UTF-8');

    $income = htmlspecialchars((string)($metrics['income'] ?? '0.00'), ENT_QUOTES, 'UTF-8');
    $expense = htmlspecialchars((string)($metrics['expense'] ?? '0.00'), ENT_QUOTES, 'UTF-8');
    $net = htmlspecialchars((string)($metrics['net'] ?? '0.00'), ENT_QUOTES, 'UTF-8');

    $rows = '';
    foreach ($entries as $e) {
        $rows .= '<tr>';
        $rows .= '<td>' . htmlspecialchars((string)($e['occurred_on'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '<td>' . htmlspecialchars((string)($e['entry_type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '<td>' . htmlspecialchars((string)($e['category'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '<td>' . htmlspecialchars((string)($e['method'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '<td>' . htmlspecialchars((string)($e['description'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '<td style="text-align:right;">' . htmlspecialchars((string)($e['currency'] ?? '') . ' ' . (string)($e['amount'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        $rows .= '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="6" style="color:#94a3b8;">No entries for this month.</td></tr>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safeTitle . ' - ' . $safeMonth . '</title>'
        . '<style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,sans-serif;background:#050816;color:#fff;margin:0;padding:24px;}'
        . '.wrap{max-width:1000px;margin:0 auto;}'
        . '.h{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}'
        . '.title{font-size:22px;font-weight:800;letter-spacing:0.3px;color:#00d4ff;}'
        . '.muted{color:#94a3b8;font-size:13px;}'
        . '.kpi{display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;}'
        . '.kpi .box{border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:10px 12px;min-width:160px;background:rgba(255,255,255,0.03);}'
        . '.kpi .v{font-size:16px;font-weight:800;color:#00d4ff;margin-top:4px;}'
        . 'table{width:100%;border-collapse:collapse;margin-top:18px;}'
        . 'th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.08);text-align:left;font-size:13px;vertical-align:top;}'
        . 'th{color:#94a3b8;font-weight:700;}'
        . '</style></head><body><div class="wrap">'
        . '<div class="h"><div><div class="title">Finora</div><div class="muted">Monthly report • ' . $safeRange . '</div></div>'
        . '<div class="muted">Tenant: ' . $safeTenant . '<br>User: ' . $safeUser . '<br>Persona: ' . $safePersona . '<br>Month: ' . $safeMonth . '</div></div>'
        . '<div class="kpi">'
        . '<div class="box"><div class="muted">Income</div><div class="v">' . $income . '</div></div>'
        . '<div class="box"><div class="muted">Expense</div><div class="v">' . $expense . '</div></div>'
        . '<div class="box"><div class="muted">Net</div><div class="v">' . $net . '</div></div>'
        . '</div>'
        . '<table><thead><tr><th>Date</th><th>Type</th><th>Category</th><th>Method</th><th>Description</th><th style="text-align:right;">Amount</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . '</div></body></html>';
}

function finora_build_backup(PDO $db, string $tenantId, string $userId, string $month): array
{
    [$startDate, $endDate] = finora_month_range($month);
    $entries = finora_list_entries($db, $tenantId, $userId, $startDate, $endDate);
    $categories = finora_list_categories($db, $tenantId, $userId);

    return [
        'version' => '0.1.0',
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'month' => $month,
        'generated_at_utc' => gmdate('c'),
        'categories' => $categories,
        'entries' => $entries,
    ];
}

function finora_get_month_param(): string
{
    $m = (string)($_GET['month'] ?? '');
    if (isset($_POST['month'])) {
        $m = (string)($_POST['month'] ?? '');
    }
    if (!preg_match('/^\\d{4}-\\d{2}$/', $m)) {
        $m = gmdate('Y-m');
    }
    return $m;
}

function finora_month_range(string $month): array
{
    $start = $month . '-01';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $start, new DateTimeZone('UTC'));
    if (!$dt) {
        $dt = new DateTimeImmutable(gmdate('Y-m-01'), new DateTimeZone('UTC'));
    }
    $end = $dt->modify('last day of this month')->format('Y-m-d');
    return [$dt->format('Y-m-d'), $end];
}

function finora_sanitize_key(string $s): string
{
    $out = preg_replace('/[^a-zA-Z0-9_\\-\\.]/', '_', $s);
    $out = is_string($out) ? $out : 'tenant';
    return substr($out, 0, 80);
}

function finora_render_error(string $message): void
{
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Finora</title></head><body style="font-family:Arial,sans-serif;background:#0a0a0a;color:#fff;padding:20px;">';
    echo '<h1 style="color:#ff4444;margin:0 0 12px;">Finora</h1>';
    echo '<div style="background:rgba(255,68,68,0.12);border:1px solid rgba(255,68,68,0.35);border-radius:12px;padding:12px;">' . htmlspecialchars($message) . '</div>';
    echo '</body></html>';
    exit;
}

function finora_get_db_status(PDO $db): array
{
    try {
        $row = $db->query("SELECT DATABASE() AS db, @@port AS port")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['db' => null, 'port' => null];
        }
        return [
            'db' => $row['db'] ?? null,
            'port' => $row['port'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['db' => null, 'port' => null];
    }
}

function finora_get_view_param(): string
{
    $v = (string)($_GET['view'] ?? 'dashboard');
    $allowed = ['dashboard', 'recurring', 'budgets', 'goals', 'import', 'backups', 'profile'];
    return in_array($v, $allowed, true) ? $v : 'dashboard';
}

function finora_add_method(PDO $db, string $tenantId, string $userId, string $name): void
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 191) {
        throw new RuntimeException('Invalid method name');
    }
    $stmt = $db->prepare("
        INSERT INTO mh_finora_methods (tenant_id, user_id, name)
        VALUES (:tenant_id, :user_id, :name)
        ON DUPLICATE KEY UPDATE name = VALUES(name)
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':name' => $name]);
}

function finora_list_methods(PDO $db, string $tenantId, string $userId): array
{
    $stmt = $db->prepare("
        SELECT name
        FROM mh_finora_methods
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY name ASC
        LIMIT 500
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $n = (string)($r['name'] ?? '');
        if ($n !== '') {
            $out[] = $n;
        }
    }
    return $out;
}

function finora_add_recurring_rule(
    PDO $db,
    string $tenantId,
    string $userId,
    string $name,
    string $entryType,
    string $amount,
    string $currency,
    ?string $category,
    ?string $method,
    ?string $description,
    string $startOn,
    string $intervalUnit,
    string $intervalCount,
    ?string $dayOfMonth,
    ?string $weekday,
    ?string $endOn
): void {
    $name = trim($name);
    if ($name === '' || strlen($name) > 191) {
        throw new RuntimeException('Name is required');
    }
    $entryType = $entryType === 'income' ? 'income' : 'expense';
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency must be a 3-letter code');
    }
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startOn)) {
        throw new RuntimeException('Invalid start date');
    }
    if ($endOn !== null && $endOn !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endOn)) {
        throw new RuntimeException('Invalid end date');
    }
    $amountNum = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($amountNum === false || $amountNum < 0) {
        throw new RuntimeException('Invalid amount');
    }
    $intervalUnit = in_array($intervalUnit, ['day', 'week', 'month', 'year'], true) ? $intervalUnit : 'month';
    $count = (int)($intervalCount !== '' ? $intervalCount : '1');
    if ($count < 1) {
        $count = 1;
    }
    $dom = null;
    if ($dayOfMonth !== null && $dayOfMonth !== '') {
        $domVal = (int)$dayOfMonth;
        if ($domVal >= 1 && $domVal <= 31) {
            $dom = $domVal;
        }
    }
    $wday = null;
    if ($weekday !== null && $weekday !== '') {
        $wVal = (int)$weekday;
        if ($wVal >= 1 && $wVal <= 7) {
            $wday = $wVal;
        }
    }

    $stmt = $db->prepare("
        INSERT INTO mh_finora_recurring_rules
            (tenant_id, user_id, name, entry_type, amount, currency, category, method, description, start_on, end_on, interval_unit, interval_count, day_of_month, weekday)
        VALUES
            (:tenant_id, :user_id, :name, :entry_type, :amount, :currency, :category, :method, :description, :start_on, :end_on, :interval_unit, :interval_count, :day_of_month, :weekday)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':name' => $name,
        ':entry_type' => $entryType,
        ':amount' => number_format((float)$amountNum, 2, '.', ''),
        ':currency' => $currency,
        ':category' => $category,
        ':method' => $method,
        ':description' => $description,
        ':start_on' => $startOn,
        ':end_on' => $endOn,
        ':interval_unit' => $intervalUnit,
        ':interval_count' => $count,
        ':day_of_month' => $dom,
        ':weekday' => $wday,
    ]);
}

function finora_list_recurring_rules(PDO $db, string $tenantId, string $userId): array
{
    $stmt = $db->prepare("
        SELECT id, name, entry_type, amount, currency, category, method, start_on, end_on, interval_unit, interval_count, day_of_month, weekday, last_generated_on, is_active
        FROM mh_finora_recurring_rules
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at_utc DESC
        LIMIT 200
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_generate_recurring_for_range(
    PDO $db,
    string $tenantId,
    string $userId,
    string $personaId,
    string $metaHumanId,
    string $sessionId,
    ?string $deviceId,
    string $startDate,
    string $endDate
): void {
    try {
        $stmt = $db->prepare("
            SELECT *
            FROM mh_finora_recurring_rules
            WHERE tenant_id = :tenant_id AND user_id = :user_id AND is_active = 1
        ");
        $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rules as $r) {
            $ruleId = (string)($r['id'] ?? '');
            if ($ruleId === '') {
                continue;
            }
            $ruleStart = (string)($r['start_on'] ?? '');
            if ($ruleStart === '' || $ruleStart > $endDate) {
                continue;
            }
            $ruleEnd = (string)($r['end_on'] ?? '');
            if ($ruleEnd !== '' && $ruleEnd < $startDate) {
                continue;
            }
            $genFrom = $startDate;
            $lastGen = (string)($r['last_generated_on'] ?? '');
            if ($lastGen !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $lastGen)) {
                $next = (new DateTimeImmutable($lastGen, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
                if ($next > $genFrom) {
                    $genFrom = $next;
                }
            }
            if ($genFrom < $ruleStart) {
                $genFrom = $ruleStart;
            }
            if ($ruleEnd !== '' && $endDate > $ruleEnd) {
                $capEnd = $ruleEnd;
            } else {
                $capEnd = $endDate;
            }
            if ($genFrom > $capEnd) {
                continue;
            }

            $dates = finora_rule_dates_in_range($r, $genFrom, $capEnd);
            $maxGenerated = null;
            foreach ($dates as $d) {
                try {
                    finora_add_entry(
                        $db,
                        $tenantId,
                        $userId,
                        $personaId,
                        $metaHumanId,
                        $sessionId,
                        $deviceId,
                        (string)($r['entry_type'] ?? 'expense'),
                        (string)($r['amount'] ?? '0'),
                        (string)($r['currency'] ?? 'USD'),
                        $d,
                        isset($r['category']) ? (string)$r['category'] : null,
                        isset($r['method']) ? (string)$r['method'] : null,
                        isset($r['description']) ? (string)$r['description'] : null,
                        'recurring',
                        $ruleId,
                        null
                    );
                    $maxGenerated = $d;
                } catch (Throwable $e) {
                }
            }
            if ($maxGenerated !== null) {
                $upd = $db->prepare("
                    UPDATE mh_finora_recurring_rules
                    SET last_generated_on = :d
                    WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
                ");
                $upd->execute([':d' => $maxGenerated, ':id' => $ruleId, ':tenant_id' => $tenantId, ':user_id' => $userId]);
            }
        }
    } catch (Throwable $e) {
    }
}

function finora_rule_dates_in_range(array $rule, string $startDate, string $endDate): array
{
    $unit = (string)($rule['interval_unit'] ?? 'month');
    $count = (int)($rule['interval_count'] ?? 1);
    if ($count < 1) $count = 1;
    $ruleStart = (string)($rule['start_on'] ?? $startDate);
    $dom = isset($rule['day_of_month']) ? (int)$rule['day_of_month'] : 0;
    $wday = isset($rule['weekday']) ? (int)$rule['weekday'] : 0;

    $out = [];
    $tz = new DateTimeZone('UTC');
    $start = new DateTimeImmutable($startDate, $tz);
    $end = new DateTimeImmutable($endDate, $tz);
    $seed = new DateTimeImmutable($ruleStart, $tz);
    if ($seed > $end) return [];

    if ($unit === 'day') {
        $cur = $seed;
        while ($cur < $start) {
            $cur = $cur->modify('+' . $count . ' day');
        }
        while ($cur <= $end) {
            $out[] = $cur->format('Y-m-d');
            $cur = $cur->modify('+' . $count . ' day');
        }
        return $out;
    }

    if ($unit === 'week') {
        $cur = $seed;
        $targetDow = $wday >= 1 && $wday <= 7 ? $wday : (int)$cur->format('N');
        $curDow = (int)$cur->format('N');
        if ($curDow !== $targetDow) {
            $cur = $cur->modify(($targetDow - $curDow) . ' day');
        }
        while ($cur < $start) {
            $cur = $cur->modify('+' . $count . ' week');
        }
        while ($cur <= $end) {
            $out[] = $cur->format('Y-m-d');
            $cur = $cur->modify('+' . $count . ' week');
        }
        return $out;
    }

    if ($unit === 'year') {
        $cur = $seed;
        while ($cur < $start) {
            $cur = $cur->modify('+' . $count . ' year');
        }
        while ($cur <= $end) {
            $out[] = $cur->format('Y-m-d');
            $cur = $cur->modify('+' . $count . ' year');
        }
        return $out;
    }

    $cur = $seed;
    $targetDom = $dom >= 1 && $dom <= 31 ? $dom : (int)$cur->format('j');
    $cur = $cur->modify('first day of this month');
    while ($cur->format('Y-m') < $start->format('Y-m')) {
        $cur = $cur->modify('+' . $count . ' month');
    }
    while ($cur <= $end) {
        $daysInMonth = (int)$cur->format('t');
        $d = min($targetDom, $daysInMonth);
        $date = $cur->setDate((int)$cur->format('Y'), (int)$cur->format('m'), $d);
        if ($date >= $start && $date <= $end) {
            $out[] = $date->format('Y-m-d');
        }
        $cur = $cur->modify('+' . $count . ' month');
    }
    return $out;
}

function finora_format_rule_schedule(array $r): string
{
    $count = (int)($r['interval_count'] ?? 1);
    if ($count < 1) $count = 1;
    $unit = (string)($r['interval_unit'] ?? 'month');
    $tail = $count === 1 ? $unit : ($count . ' ' . $unit . 's');
    $dom = isset($r['day_of_month']) ? (int)$r['day_of_month'] : 0;
    $wday = isset($r['weekday']) ? (int)$r['weekday'] : 0;
    if ($unit === 'month' && $dom >= 1 && $dom <= 31) {
        return 'every ' . $tail . ' on day ' . $dom;
    }
    if ($unit === 'week' && $wday >= 1 && $wday <= 7) {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        return 'every ' . $tail . ' on ' . ($names[$wday] ?? 'day');
    }
    return 'every ' . $tail;
}

function finora_set_budget(PDO $db, string $tenantId, string $userId, string $month, ?string $category, string $amount, string $currency): void
{
    if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) {
        throw new RuntimeException('Invalid month');
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency must be a 3-letter code');
    }
    $amountNum = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($amountNum === false || $amountNum < 0) {
        throw new RuntimeException('Invalid amount');
    }
    $stmt = $db->prepare("
        INSERT INTO mh_finora_budgets (tenant_id, user_id, month, category, amount, currency)
        VALUES (:tenant_id, :user_id, :month, :category, :amount, :currency)
        ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':month' => $month,
        ':category' => $category,
        ':amount' => number_format((float)$amountNum, 2, '.', ''),
        ':currency' => $currency,
    ]);
}

function finora_list_budgets(PDO $db, string $tenantId, string $userId, string $month): array
{
    $stmt = $db->prepare("
        SELECT category, amount, currency
        FROM mh_finora_budgets
        WHERE tenant_id = :tenant_id AND user_id = :user_id AND month = :month
        ORDER BY category ASC
        LIMIT 200
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':month' => $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_budget_spent_by_category(PDO $db, string $tenantId, string $userId, string $startDate, string $endDate): array
{
    $stmt = $db->prepare("
        SELECT COALESCE(category, '') AS category, COALESCE(SUM(amount),0) AS spent
        FROM mh_finora_entries
        WHERE tenant_id = :tenant_id
          AND user_id = :user_id
          AND entry_type = 'expense'
          AND occurred_on BETWEEN :start_date AND :end_date
        GROUP BY COALESCE(category,'')
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':start_date' => $startDate, ':end_date' => $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = ['__ALL__' => 0.0];
    foreach ($rows as $r) {
        $cat = (string)($r['category'] ?? '');
        $spent = (float)($r['spent'] ?? 0);
        $out[$cat] = $spent;
        $out['__ALL__'] += $spent;
    }
    return $out;
}

function finora_add_goal(PDO $db, string $tenantId, string $userId, string $name, string $targetAmount, string $currency, ?string $dueOn): void
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 191) {
        throw new RuntimeException('Invalid goal name');
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new RuntimeException('Currency must be a 3-letter code');
    }
    if ($dueOn !== null && $dueOn !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueOn)) {
        throw new RuntimeException('Invalid due date');
    }
    $amountNum = filter_var($targetAmount, FILTER_VALIDATE_FLOAT);
    if ($amountNum === false || $amountNum <= 0) {
        throw new RuntimeException('Invalid target amount');
    }
    $stmt = $db->prepare("
        INSERT INTO mh_finora_goals (tenant_id, user_id, name, target_amount, currency, due_on)
        VALUES (:tenant_id, :user_id, :name, :target_amount, :currency, :due_on)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':name' => $name,
        ':target_amount' => number_format((float)$amountNum, 2, '.', ''),
        ':currency' => $currency,
        ':due_on' => $dueOn,
    ]);
}

function finora_list_goals(PDO $db, string $tenantId, string $userId): array
{
    $stmt = $db->prepare("
        SELECT id, name, target_amount, currency, due_on, status
        FROM mh_finora_goals
        WHERE tenant_id = :tenant_id AND user_id = :user_id AND status <> 'archived'
        ORDER BY created_at_utc DESC
        LIMIT 200
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_add_goal_contribution(PDO $db, string $tenantId, string $userId, string $goalId, string $amount, string $occurredOn, ?string $note): void
{
    if (!preg_match('/^\\d+$/', $goalId)) {
        throw new RuntimeException('Invalid goal id');
    }
    $amountNum = filter_var($amount, FILTER_VALIDATE_FLOAT);
    if ($amountNum === false || $amountNum <= 0) {
        throw new RuntimeException('Invalid amount');
    }
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $occurredOn)) {
        throw new RuntimeException('Invalid date');
    }
    $stmt = $db->prepare("
        INSERT INTO mh_finora_goal_contributions (tenant_id, user_id, goal_id, amount, occurred_on, note)
        VALUES (:tenant_id, :user_id, :goal_id, :amount, :occurred_on, :note)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':goal_id' => $goalId,
        ':amount' => number_format((float)$amountNum, 2, '.', ''),
        ':occurred_on' => $occurredOn,
        ':note' => $note,
    ]);
}

function finora_goal_progress_map(PDO $db, string $tenantId, string $userId): array
{
    $stmt = $db->prepare("
        SELECT goal_id, COALESCE(SUM(amount),0) AS total
        FROM mh_finora_goal_contributions
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        GROUP BY goal_id
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $gid = (string)($r['goal_id'] ?? '');
        if ($gid !== '') {
            $out[$gid] = (float)($r['total'] ?? 0);
        }
    }
    return $out;
}

function finora_list_backups(PDO $db, string $tenantId, string $userId, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare("
        SELECT id, month, file_name, file_rel_path, file_sha256, file_bytes, created_at_utc
        FROM mh_finora_backups
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at_utc DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_create_backup(PDO $db, object $paths, string $tenantId, string $userId, string $month, int $retentionCount): array
{
    if (!$paths || !method_exists($paths, 'getSecureFilePath')) {
        throw new RuntimeException('Paths module unavailable');
    }
    $tenantKey = finora_sanitize_key($tenantId);
    $filename = 'finora_' . $tenantKey . '_' . $month . '_' . gmdate('Ymd_His') . '.json';
    $rel = 'tenants/' . $tenantKey . '/finance/finora/backups/' . $filename;
    $full = $paths->getSecureFilePath($rel, true);
    if (!is_string($full) || $full === '') {
        throw new RuntimeException('Failed to allocate backup path');
    }
    $data = finora_build_backup($db, $tenantId, $userId, $month);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Failed to encode backup');
    }
    file_put_contents($full, $json);
    $sha = hash('sha256', $json);
    $bytes = (int)strlen($json);
    $stmt = $db->prepare("
        INSERT INTO mh_finora_backups (tenant_id, user_id, month, file_name, file_rel_path, file_sha256, file_bytes)
        VALUES (:tenant_id, :user_id, :month, :file_name, :file_rel_path, :file_sha256, :file_bytes)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':month' => $month,
        ':file_name' => $filename,
        ':file_rel_path' => $rel,
        ':file_sha256' => $sha,
        ':file_bytes' => $bytes,
    ]);
    $id = (string)$db->lastInsertId();
    finora_enforce_backup_retention($db, $paths, $tenantId, $userId, $retentionCount);
    return ['id' => $id, 'file_name' => $filename, 'file_rel_path' => $rel];
}

function finora_enforce_backup_retention(PDO $db, object $paths, string $tenantId, string $userId, int $retentionCount): void
{
    $retentionCount = max(1, min(200, $retentionCount));
    $stmt = $db->prepare("
        SELECT id, file_rel_path
        FROM mh_finora_backups
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at_utc DESC
        LIMIT 500
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) <= $retentionCount) {
        return;
    }
    $toDelete = array_slice($rows, $retentionCount);
    foreach ($toDelete as $r) {
        $bid = (string)($r['id'] ?? '');
        $rel = (string)($r['file_rel_path'] ?? '');
        if ($bid === '' || $rel === '') continue;
        finora_delete_backup($db, $paths, $tenantId, $userId, $bid);
    }
}

function finora_delete_backup(PDO $db, object $paths, string $tenantId, string $userId, string $backupId): void
{
    if (!preg_match('/^\\d+$/', $backupId)) {
        throw new RuntimeException('Invalid backup id');
    }
    $stmt = $db->prepare("
        SELECT file_rel_path
        FROM mh_finora_backups
        WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $backupId, ':tenant_id' => $tenantId, ':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return;
    }
    $rel = (string)($row['file_rel_path'] ?? '');
    if ($paths && method_exists($paths, 'getSecureFilePath')) {
        $full = $paths->getSecureFilePath($rel, false);
        if (is_string($full) && $full !== '' && file_exists($full)) {
            @unlink($full);
        }
    }
    $del = $db->prepare("DELETE FROM mh_finora_backups WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id LIMIT 1");
    $del->execute([':id' => $backupId, ':tenant_id' => $tenantId, ':user_id' => $userId]);
}

function finora_download_backup(PDO $db, object $paths, string $tenantId, string $userId, string $backupId): void
{
    if (!preg_match('/^\\d+$/', $backupId)) {
        finora_render_error('Invalid backup id');
    }
    $stmt = $db->prepare("
        SELECT file_name, file_rel_path
        FROM mh_finora_backups
        WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $backupId, ':tenant_id' => $tenantId, ':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        finora_render_error('Backup not found');
    }
    $fileName = (string)($row['file_name'] ?? 'backup.json');
    $rel = (string)($row['file_rel_path'] ?? '');
    if (!$paths || !method_exists($paths, 'getSecureFilePath')) {
        finora_render_error('Paths module unavailable');
    }
    $full = $paths->getSecureFilePath($rel, false);
    if (!is_string($full) || $full === '' || !file_exists($full)) {
        finora_render_error('Backup file missing');
    }
    mh_download_send_file($full, basename($fileName), 'application/json; charset=utf-8');
}

function finora_list_import_batches(PDO $db, string $tenantId, string $userId, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare("
        SELECT id, file_name, imported_rows, skipped_rows, created_at_utc
        FROM mh_finora_import_batches
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at_utc DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function finora_download_import_file(PDO $db, object $paths, string $tenantId, string $userId, string $importId): void
{
    if (!preg_match('/^\\d+$/', $importId)) {
        finora_render_error('Invalid import id');
    }
    $stmt = $db->prepare("
        SELECT file_name, file_rel_path
        FROM mh_finora_import_batches
        WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $importId, ':tenant_id' => $tenantId, ':user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        finora_render_error('Import not found');
    }
    $fileName = (string)($row['file_name'] ?? 'import.csv');
    $rel = (string)($row['file_rel_path'] ?? '');
    if (!$paths || !method_exists($paths, 'getSecureFilePath')) {
        finora_render_error('Paths module unavailable');
    }
    $full = $paths->getSecureFilePath($rel, false);
    if (!is_string($full) || $full === '' || !file_exists($full)) {
        finora_render_error('Import file missing');
    }
    mh_download_send_file($full, basename($fileName), 'application/octet-stream');
}

function finora_import_entries_file(
    PDO $db,
    object $paths,
    string $tenantId,
    string $userId,
    string $personaId,
    string $metaHumanId,
    string $sessionId,
    ?string $deviceId,
    array $file
): array {
    if (!$paths || !method_exists($paths, 'getSecureFilePath')) {
        throw new RuntimeException('Paths module unavailable');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $orig = (string)($file['name'] ?? 'import.csv');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload failed');
    }
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('File too large');
    }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Unsupported file type');
    }
    $tenantKey = finora_sanitize_key($tenantId);
    $safeName = 'finora_import_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $rel = 'tenants/' . $tenantKey . '/finance/finora/imports/' . $safeName;
    $full = $paths->getSecureFilePath($rel, true);
    if (!is_string($full) || $full === '') {
        throw new RuntimeException('Failed to allocate import path');
    }
    if (!move_uploaded_file($tmp, $full)) {
        throw new RuntimeException('Failed to store upload');
    }
    $bytes = file_get_contents($full);
    if (!is_string($bytes)) {
        throw new RuntimeException('Failed to read import file');
    }
    $sha = hash('sha256', $bytes);

    $insertBatch = $db->prepare("
        INSERT INTO mh_finora_import_batches (tenant_id, user_id, file_name, file_rel_path, file_sha256)
        VALUES (:tenant_id, :user_id, :file_name, :file_rel_path, :file_sha256)
    ");
    $insertBatch->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':file_name' => $orig,
        ':file_rel_path' => $rel,
        ':file_sha256' => $sha,
    ]);
    $batchId = (string)$db->lastInsertId();

    $rows = $ext === 'xlsx' ? finora_parse_xlsx_rows($full) : finora_parse_csv_rows($full);
    $imported = 0;
    $skipped = 0;
    foreach ($rows as $row) {
        try {
            finora_add_entry(
                $db,
                $tenantId,
                $userId,
                $personaId,
                $metaHumanId,
                $sessionId,
                $deviceId,
                (string)($row['entry_type'] ?? 'expense'),
                (string)($row['amount'] ?? '0'),
                strtoupper(trim((string)($row['currency'] ?? 'USD'))),
                (string)($row['occurred_on'] ?? ''),
                (isset($row['category']) && trim((string)$row['category']) !== '') ? trim((string)$row['category']) : null,
                (isset($row['method']) && trim((string)$row['method']) !== '') ? trim((string)$row['method']) : null,
                (isset($row['description']) && trim((string)$row['description']) !== '') ? trim((string)$row['description']) : null,
                'import',
                null,
                $batchId
            );
            $imported++;
        } catch (Throwable $e) {
            $skipped++;
        }
    }

    $upd = $db->prepare("
        UPDATE mh_finora_import_batches
        SET imported_rows = :imported, skipped_rows = :skipped
        WHERE id = :id AND tenant_id = :tenant_id AND user_id = :user_id
    ");
    $upd->execute([':imported' => $imported, ':skipped' => $skipped, ':id' => $batchId, ':tenant_id' => $tenantId, ':user_id' => $userId]);

    return ['import_batch_id' => $batchId, 'imported_rows' => $imported, 'skipped_rows' => $skipped];
}

function finora_parse_csv_rows(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) return [];
    $header = null;
    $out = [];
    while (($row = fgetcsv($fh)) !== false) {
        if ($header === null) {
            $header = [];
            foreach ($row as $h) {
                $header[] = strtolower(trim((string)$h));
            }
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $key) {
            if ($key === '') continue;
            $assoc[$key] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        if ($assoc) $out[] = $assoc;
    }
    fclose($fh);
    return $out;
}

function finora_parse_xlsx_rows(string $path): array
{
    $za = class_exists('ZipArchive') ? new ZipArchive() : null;
    if (!$za || $za->open($path) !== true) {
        return [];
    }
    $shared = [];
    $sharedXml = $za->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)($run->t ?? '');
                    }
                }
                $shared[] = $text;
            }
        }
    }
    $sheetXml = $za->getFromName('xl/worksheets/sheet1.xml');
    $za->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        return [];
    }
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx || !isset($sx->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $r = (string)($c['r'] ?? '');
            if ($r === '') continue;
            $col = preg_replace('/\\d+/', '', $r);
            $v = isset($c->v) ? (string)$c->v : '';
            $t = (string)($c['t'] ?? '');
            if ($t === 's') {
                $idx = (int)$v;
                $v = $shared[$idx] ?? '';
            }
            $cells[$col] = $v;
        }
        if ($cells) $rows[] = $cells;
    }
    if (!$rows) return [];

    $headerRow = array_shift($rows);
    $colKeys = [];
    foreach ($headerRow as $col => $val) {
        $key = strtolower(trim((string)$val));
        if ($key !== '') {
            $colKeys[$col] = $key;
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $assoc = [];
        foreach ($colKeys as $col => $key) {
            $assoc[$key] = isset($r[$col]) ? trim((string)$r[$col]) : '';
        }
        if ($assoc) $out[] = $assoc;
    }
    return $out;
}

function finora_log_activity(PDO $db, string $tenantId, string $userId, string $personaId, string $metaHumanId, string $sessionId, ?string $deviceId, string $eventType, mixed $eventData): void
{
    $eventType = preg_replace('/[^a-zA-Z0-9_\\.\\-]/', '', $eventType) ?: 'event';
    $json = null;
    if ($eventData !== null) {
        $enc = json_encode($eventData, JSON_UNESCAPED_SLASHES);
        $json = is_string($enc) ? $enc : null;
    }
    if (function_exists('cue_autoload')) {
        cue_autoload('memory');
    }
    if (function_exists('memory_write_event')) {
        $text = 'finora ' . $eventType;
        if (is_array($eventData)) {
            $bits = [];
            foreach (['name', 'amount', 'currency', 'category', 'method', 'goal_id', 'month'] as $k) {
                if (isset($eventData[$k]) && is_string($eventData[$k]) && trim((string)$eventData[$k]) !== '') {
                    $bits[] = $k . ':' . trim((string)$eventData[$k]);
                }
            }
            if ($bits) $text .= ' ' . implode(' ', $bits);
        }
        $ctx = [
            'tenant_id' => $tenantId,
            'persona_id' => $personaId,
            'meta_human_id' => $metaHumanId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'device_id' => $deviceId ?? '',
            'username' => $userId,
        ];
        try {
            memory_write_event($ctx, 'app_action', $text, ['source' => 'finora', 'event_type' => $eventType]);
        } catch (Throwable $e) {}
    }
    $stmt = $db->prepare("
        INSERT INTO mh_finora_activity (tenant_id, user_id, persona_id, meta_human_id, session_id, device_id, event_type, event_data_json)
        VALUES (:tenant_id, :user_id, :persona_id, :meta_human_id, :session_id, :device_id, :event_type, :event_data_json)
    ");
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':persona_id' => $personaId,
        ':meta_human_id' => $metaHumanId,
        ':session_id' => $sessionId,
        ':device_id' => $deviceId,
        ':event_type' => $eventType,
        ':event_data_json' => $json,
    ]);
}

function finora_list_activity(PDO $db, string $tenantId, string $userId, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare("
        SELECT created_at_utc, event_type, event_data_json
        FROM mh_finora_activity
        WHERE tenant_id = :tenant_id AND user_id = :user_id
        ORDER BY created_at_utc DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}
