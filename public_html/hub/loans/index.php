<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../equity/db.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/loans/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/hub/loans/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$username = (string)$_SESSION['mh_auth_user'];
$message = '';
$error = '';

function mh_hub_loans_has_column(PDO $pdo, string $table, string $col): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$col]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function mh_hub_loans_summary(PDO $pdo, string $user): array
{
    $hasDir = mh_hub_loans_has_column($pdo, 'mh_loans', 'direction');
    $hasCp = mh_hub_loans_has_column($pdo, 'mh_loans', 'counterparty_username');
    $where = $hasCp ? "counterparty_username = ?" : "borrower_username = ?";
    $rows = [];
    try {
        $stmt = $pdo->prepare("SELECT id, " . ($hasDir ? "direction" : "NULL as direction") . ", principal_amount, principal_asset, status FROM mh_loans WHERE $where ORDER BY id DESC");
        $stmt->execute([$user]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [
            'loaned_to_company' => [],
            'borrowed_from_company' => [],
            'active_count' => 0,
        ];
    }

    $sum = [
        'loaned_to_company' => [],
        'borrowed_from_company' => [],
        'active_count' => 0,
    ];
    foreach ($rows as $r) {
        $st = strtolower(trim((string)($r['status'] ?? '')));
        if ($st !== '' && !in_array($st, ['draft', 'requested', 'active', 'closed'], true)) {
            continue;
        }
        if (in_array($st, ['requested', 'active'], true)) $sum['active_count']++;
        $amt = (float)($r['principal_amount'] ?? 0);
        $asset = trim((string)($r['principal_asset'] ?? 'cash'));
        if ($asset === '') $asset = 'cash';
        $dir = strtolower(trim((string)($r['direction'] ?? '')));
        if ($dir === '') $dir = 'company_lends';
        if ($dir === 'company_borrows') {
            $sum['loaned_to_company'][$asset] = ($sum['loaned_to_company'][$asset] ?? 0.0) + $amt;
        } else {
            $sum['borrowed_from_company'][$asset] = ($sum['borrowed_from_company'][$asset] ?? 0.0) + $amt;
        }
    }
    return $sum;
}

$repayAssets = [
    'cash' => 'Cash',
    'stable_coins' => 'Stable Coins',
    'meme_culture_coins' => 'Meme/Culture Coins',
    'equity' => 'Digital Equity',
    'equity_coins' => 'Equity Coins',
    'utility_token' => 'Utility Token (MTK)',
];

try {
    $pdo = getEquityConnectionStrict();
} catch (Throwable $e) {
    try { error_log('[Loans Hub] init failed: ' . $e->getMessage()); } catch (Throwable $e2) {}
    http_response_code(500);
    echo 'System Error';
    exit;
}

$csrf = isset($_SESSION['mh_hub_loans_csrf']) && is_string($_SESSION['mh_hub_loans_csrf']) ? (string)$_SESSION['mh_hub_loans_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_hub_loans_csrf'] = $csrf;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $error = 'Invalid request';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'request_loan' || $action === 'offer_loan') {
            $direction = $action === 'offer_loan' ? 'company_borrows' : 'company_lends';
            $loanType = isset($_POST['loan_type']) ? trim((string)$_POST['loan_type']) : '';
            $principalAmt = isset($_POST['principal_amount']) ? (float)$_POST['principal_amount'] : 0.0;
            $principalAsset = isset($_POST['principal_asset']) ? trim((string)$_POST['principal_asset']) : 'cash';
            $apr = isset($_POST['interest_apr']) ? (float)$_POST['interest_apr'] : 0.0;
            $termDays = isset($_POST['term_days']) ? (int)$_POST['term_days'] : 0;
            $freq = isset($_POST['repayment_frequency']) ? trim((string)$_POST['repayment_frequency']) : 'monthly';
            $repaymentAssets = isset($_POST['repayment_assets']) && is_array($_POST['repayment_assets']) ? array_values(array_unique(array_map('strval', $_POST['repayment_assets']))) : [];

            if ($loanType === '' || $principalAmt <= 0) {
                $error = 'Missing required fields.';
            } else {
                $principalAmt = round($principalAmt, 8);
                $apr = max(0.0, min(1000.0, round($apr, 6)));
                $termDays = max(0, min(36500, $termDays));
                if (!in_array($freq, ['weekly', 'biweekly', 'monthly', 'quarterly', 'at_maturity'], true)) $freq = 'monthly';

                $hasDir = mh_hub_loans_has_column($pdo, 'mh_loans', 'direction');
                $hasCp = mh_hub_loans_has_column($pdo, 'mh_loans', 'counterparty_username');
                if (!$hasDir || !$hasCp) {
                    $error = 'Loans schema not ready.';
                } else {
                    $ins = $pdo->prepare("INSERT INTO mh_loans (borrower_username, borrower_name, direction, counterparty_username, counterparty_name, loan_type, principal_amount, principal_asset, interest_apr, interest_type, term_days, repayment_frequency, repayment_assets_json, collateral_json, smart_contract_json, bank_integration_json, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'simple', ?, ?, ?, NULL, NULL, ?, 'requested', ?)");
                    $ins->execute([
                        $username,
                        null,
                        $direction,
                        $username,
                        null,
                        $loanType,
                        $principalAmt,
                        $principalAsset !== '' ? $principalAsset : 'cash',
                        $apr,
                        $termDays,
                        $freq,
                        json_encode($repaymentAssets, JSON_UNESCAPED_SLASHES),
                        json_encode(['provider' => 'mh_crypto_bank'], JSON_UNESCAPED_SLASHES),
                        $username,
                    ]);
                    $message = $action === 'offer_loan' ? 'Offer submitted.' : 'Loan request submitted.';
                }
            }
        } elseif ($action === 'cancel_loan') {
            $loanId = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
            if ($loanId < 1) {
                $error = 'Invalid loan.';
            } else {
                $stmt = $pdo->prepare("UPDATE mh_loans SET status = 'cancelled' WHERE id = ? AND counterparty_username = ? AND status IN ('draft','requested')");
                $stmt->execute([$loanId, $username]);
                $message = $stmt->rowCount() > 0 ? 'Cancelled.' : 'Nothing to cancel.';
            }
        }
    }
}

$summary = mh_hub_loans_summary($pdo, $username);
$hasDir = mh_hub_loans_has_column($pdo, 'mh_loans', 'direction');
$hasCp = mh_hub_loans_has_column($pdo, 'mh_loans', 'counterparty_username');
$where = $hasCp ? "counterparty_username = ?" : "borrower_username = ?";
$loans = [];
try {
    $stmt = $pdo->prepare("SELECT id, created_at, status, loan_type, principal_amount, principal_asset, interest_apr, term_days, repayment_frequency, " . ($hasDir ? "direction" : "NULL as direction") . " FROM mh_loans WHERE $where ORDER BY id DESC LIMIT 200");
    $stmt->execute([$username]);
    $loans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $loans = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Loans</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        .wrap { max-width: 1200px; margin: 0 auto; padding: 28px 18px; }
        .card { background: rgba(20, 20, 25, 0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; padding: 18px; }
        h1 { margin:0 0 8px 0; color: var(--theme-primary, #00d4ff); font-family:'Orbitron',sans-serif; }
        .muted { color:#9aa; font-size: 12px; }
        .grid { display:grid; grid-template-columns: 1fr; gap: 14px; }
        @media (min-width: 1000px) { .grid { grid-template-columns: 1fr 1fr; } }
        .mh-table { width: 100%; border-collapse: collapse; }
        .mh-table th, .mh-table td { text-align:left; padding: 10px 12px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); font-size: 0.95rem; color: rgba(255,255,255,0.9); vertical-align: top; }
        .mh-table th { color: var(--theme-primary, #00d4ff); font-weight:700; }
        .mh-btn { padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight:700; }
        .mh-btn-danger { background: rgba(239, 68, 68, 0.85); color:#fff; border-color: rgba(239,68,68,0.5); }
        .mh-field label { display:block; margin: 12px 0 6px; color:#cfefff; font-size: 12px; }
        .mh-field input, .mh-field select { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
        .mh-alert { margin-bottom: 12px; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.2); background: rgba(0,212,255,0.06); }
        .mh-alert.err { border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.10); }
        .mh-badge { display:inline-block; padding: 4px 10px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); font-size: 12px; margin: 2px 6px 2px 0; }
    </style>
</head>
<body class="hub-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <h1>Loans</h1>
        <div class="muted">Requests, offers, and summaries.</div>

        <?php if ($error !== ''): ?>
            <div class="mh-alert err"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
        <?php elseif ($message !== ''): ?>
            <div class="mh-alert"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Summary</div>
                <div class="muted">Borrowed from company:</div>
                <?php foreach (($summary['borrowed_from_company'] ?? []) as $asset => $amt): ?>
                    <div class="mh-badge"><?php echo htmlspecialchars((string)$asset, ENT_QUOTES); ?>: <?php echo number_format((float)$amt, 2); ?></div>
                <?php endforeach; ?>
                <div class="muted" style="margin-top: 10px;">Loaned to company:</div>
                <?php foreach (($summary['loaned_to_company'] ?? []) as $asset => $amt): ?>
                    <div class="mh-badge"><?php echo htmlspecialchars((string)$asset, ENT_QUOTES); ?>: <?php echo number_format((float)$amt, 2); ?></div>
                <?php endforeach; ?>
                <div class="muted" style="margin-top: 10px;">Active items: <?php echo number_format((int)($summary['active_count'] ?? 0)); ?></div>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Actions</div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="request_loan">
                    <div class="mh-field">
                        <label>Scenario (Company lends)</label>
                        <select name="loan_type" required>
                            <option value="loan_against_collateral">Loan against coins/equity collateral</option>
                        </select>
                    </div>
                    <div class="mh-field">
                        <label>Principal Amount</label>
                        <input name="principal_amount" type="text" value="0">
                    </div>
                    <div class="mh-field">
                        <label>Principal Asset</label>
                        <select name="principal_asset"><?php foreach ($repayAssets as $k => $v): ?><option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>"><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="mh-field">
                        <label>Interest APR (%)</label>
                        <input name="interest_apr" type="text" value="0">
                    </div>
                    <div class="mh-field">
                        <label>Term (days)</label>
                        <input name="term_days" type="text" value="365">
                    </div>
                    <div class="mh-field">
                        <label>Repayment Frequency</label>
                        <select name="repayment_frequency">
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="at_maturity">At maturity</option>
                        </select>
                    </div>
                    <button class="mh-btn" type="submit">Request Loan</button>
                </form>

                <form method="post" style="margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.10); padding-top: 14px;">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="offer_loan">
                    <div class="mh-field">
                        <label>Scenario (Company borrows)</label>
                        <select name="loan_type" required>
                            <option value="cash_deposit">Cash deposit loan</option>
                            <option value="equity_to_loan">Equity to loan</option>
                            <option value="equity_sale_leaseback">Sold equity then loaned back</option>
                        </select>
                    </div>
                    <div class="mh-field">
                        <label>Principal Amount</label>
                        <input name="principal_amount" type="text" value="0">
                    </div>
                    <div class="mh-field">
                        <label>Principal Asset</label>
                        <select name="principal_asset"><?php foreach ($repayAssets as $k => $v): ?><option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>"><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="mh-field">
                        <label>Interest APR (%)</label>
                        <input name="interest_apr" type="text" value="0">
                    </div>
                    <div class="mh-field">
                        <label>Term (days)</label>
                        <input name="term_days" type="text" value="365">
                    </div>
                    <div class="mh-field">
                        <label>Allowed Repayment Assets</label>
                        <select name="repayment_assets[]" multiple>
                            <?php foreach ($repayAssets as $k => $v): ?><option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>"><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button class="mh-btn" type="submit">Offer Loan to Company</button>
                </form>
            </div>
        </div>

        <div class="card" style="margin-top: 14px;">
            <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Your Loans</div>
            <table class="mh-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Direction</th>
                        <th>Scenario</th>
                        <th>Principal</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loans as $l): ?>
                        <?php
                            $dir = strtolower(trim((string)($l['direction'] ?? 'company_lends')));
                            $dirLabel = $dir === 'company_borrows' ? 'You loaned to company' : 'You borrowed from company';
                            $st = strtolower(trim((string)($l['status'] ?? '')));
                        ?>
                        <tr>
                            <td class="muted"><?php echo (int)($l['id'] ?? 0); ?></td>
                            <td class="muted"><?php echo htmlspecialchars($dirLabel, ENT_QUOTES); ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($l['loan_type'] ?? ''), ENT_QUOTES); ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($l['principal_amount'] ?? '0'), ENT_QUOTES); ?> <?php echo htmlspecialchars((string)($l['principal_asset'] ?? ''), ENT_QUOTES); ?></td>
                            <td class="muted"><?php echo htmlspecialchars((string)($l['status'] ?? ''), ENT_QUOTES); ?></td>
                            <td>
                                <?php if (in_array($st, ['draft','requested'], true)): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                        <input type="hidden" name="action" value="cancel_loan">
                                        <input type="hidden" name="loan_id" value="<?php echo (int)($l['id'] ?? 0); ?>">
                                        <button class="mh-btn mh-btn-danger" type="submit">Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
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
