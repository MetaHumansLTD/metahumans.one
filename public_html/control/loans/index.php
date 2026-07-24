<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../hub/equity/db.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/control/loans/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/control/loans/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$actor = (string)$_SESSION['mh_auth_user'];
$role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
if (stripos($role, 'kripzmaster') === false) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/loans/';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/loans/';
}

function mh_loans_ensure_schema(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_loans (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        borrower_username VARCHAR(255) NOT NULL,
        borrower_name VARCHAR(255) NULL,
        loan_type VARCHAR(64) NOT NULL,
        principal_amount DECIMAL(24,8) NOT NULL DEFAULT 0,
        principal_asset VARCHAR(64) NOT NULL DEFAULT 'cash',
        interest_apr DECIMAL(10,6) NOT NULL DEFAULT 0,
        interest_type VARCHAR(32) NOT NULL DEFAULT 'simple',
        term_days INT NOT NULL DEFAULT 0,
        repayment_frequency VARCHAR(32) NOT NULL DEFAULT 'monthly',
        repayment_assets_json LONGTEXT NULL,
        collateral_json LONGTEXT NULL,
        smart_contract_json LONGTEXT NULL,
        bank_integration_json LONGTEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        created_by VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_mh_loans_borrower (borrower_username),
        KEY idx_mh_loans_status (status),
        KEY idx_mh_loans_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_loan_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        loan_id BIGINT NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        amount DECIMAL(24,8) NULL,
        asset VARCHAR(64) NULL,
        meta_json LONGTEXT NULL,
        created_by VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mh_loan_events_loan (loan_id, created_at),
        CONSTRAINT fk_mh_loan_events_loan FOREIGN KEY (loan_id) REFERENCES mh_loans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mh_loans_has_column(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$col]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function mh_loans_ensure_columns(PDO $pdo): void
{
    try {
        if (!mh_loans_has_column($pdo, 'mh_loans', 'direction')) {
            $pdo->exec("ALTER TABLE mh_loans ADD COLUMN direction VARCHAR(32) NOT NULL DEFAULT 'company_lends' AFTER borrower_name");
            $pdo->exec("ALTER TABLE mh_loans ADD KEY idx_mh_loans_direction (direction)");
        }
    } catch (Throwable $e) {}
    try {
        if (!mh_loans_has_column($pdo, 'mh_loans', 'counterparty_username')) {
            $pdo->exec("ALTER TABLE mh_loans ADD COLUMN counterparty_username VARCHAR(255) NULL AFTER direction");
            $pdo->exec("ALTER TABLE mh_loans ADD COLUMN counterparty_name VARCHAR(255) NULL AFTER counterparty_username");
            $pdo->exec("ALTER TABLE mh_loans ADD KEY idx_mh_loans_counterparty (counterparty_username)");
        }
    } catch (Throwable $e) {}
}

function mh_loans_user_search(PDO $pdoBio, string $q, int $limit = 12): array
{
    $q = trim($q);
    if ($q === '') return [];
    $qLike = '%' . $q . '%';
    $limit = max(1, min(50, $limit));
    $stmt = $pdoBio->prepare("SELECT username, name, role, tokens FROM users WHERE (username LIKE ? OR name LIKE ?) ORDER BY name ASC, username ASC LIMIT " . (int)$limit);
    $stmt->execute([$qLike, $qLike]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $u = isset($r['username']) ? trim((string)$r['username']) : '';
        if ($u === '') continue;
        $out[] = [
            'username' => $u,
            'name' => isset($r['name']) ? trim((string)$r['name']) : '',
            'role' => isset($r['role']) ? trim((string)$r['role']) : '',
            'tokens' => isset($r['tokens']) ? (int)$r['tokens'] : null,
        ];
    }
    return $out;
}

$message = '';
$error = '';
$createDefaults = [
    'counterparty_username' => '',
    'counterparty_name' => '',
    'direction' => 'company_lends',
    'loan_type' => '',
    'principal_amount' => '0',
    'principal_asset' => 'cash',
    'interest_apr' => '0',
    'interest_type' => 'simple',
    'term_days' => '365',
    'repayment_frequency' => 'monthly',
    'repayment_assets' => [],
    'sc_chain' => '',
    'sc_type' => '',
    'sc_contract' => '',
    'bank_ref' => '',
];

if (isset($_SESSION['mh_loans_flash']) && is_array($_SESSION['mh_loans_flash'])) {
    $flash = $_SESSION['mh_loans_flash'];
    if (isset($flash['message']) && is_string($flash['message'])) {
        $message = $flash['message'];
    }
    if (isset($flash['error']) && is_string($flash['error'])) {
        $error = $flash['error'];
    }
    if (isset($flash['create']) && is_array($flash['create'])) {
        foreach ($createDefaults as $key => $defaultValue) {
            if (array_key_exists($key, $flash['create'])) {
                $createDefaults[$key] = $flash['create'][$key];
            }
        }
    }
    unset($_SESSION['mh_loans_flash']);
}

try {
    $pdo = function_exists('getEquityConnectionStrict') ? getEquityConnectionStrict() : getEquityConnection();
    mh_loans_ensure_schema($pdo);
    mh_loans_ensure_columns($pdo);
} catch (Throwable $e) {
    try { error_log('[Loans Control] init failed: ' . $e->getMessage()); } catch (Throwable $e2) {}
    http_response_code(500);
    echo 'System Error';
    exit;
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'user_search') {
    try {
        $pdoBio = database_getConnectionById('biometrics');
        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $users = mh_loans_user_search($pdoBio, $q, 12);
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'search_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$csrf = isset($_SESSION['mh_loans_csrf']) && is_string($_SESSION['mh_loans_csrf']) ? (string)$_SESSION['mh_loans_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_loans_csrf'] = $csrf;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $createFlash = [
        'counterparty_username' => trim((string)($_POST['counterparty_username'] ?? '')),
        'counterparty_name' => trim((string)($_POST['counterparty_name'] ?? '')),
        'direction' => trim((string)($_POST['direction'] ?? 'company_lends')),
        'loan_type' => trim((string)($_POST['loan_type'] ?? '')),
        'principal_amount' => trim((string)($_POST['principal_amount'] ?? '0')),
        'principal_asset' => trim((string)($_POST['principal_asset'] ?? 'cash')),
        'interest_apr' => trim((string)($_POST['interest_apr'] ?? '0')),
        'interest_type' => trim((string)($_POST['interest_type'] ?? 'simple')),
        'term_days' => trim((string)($_POST['term_days'] ?? '365')),
        'repayment_frequency' => trim((string)($_POST['repayment_frequency'] ?? 'monthly')),
        'repayment_assets' => isset($_POST['repayment_assets']) && is_array($_POST['repayment_assets']) ? array_values(array_map('strval', $_POST['repayment_assets'])) : [],
        'sc_chain' => trim((string)($_POST['sc_chain'] ?? '')),
        'sc_type' => trim((string)($_POST['sc_type'] ?? '')),
        'sc_contract' => trim((string)($_POST['sc_contract'] ?? '')),
        'bank_ref' => trim((string)($_POST['bank_ref'] ?? '')),
    ];
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $error = 'Invalid request';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'create_loan') {
            $counterparty = isset($_POST['counterparty_username']) ? trim((string)$_POST['counterparty_username']) : '';
            $counterpartyName = isset($_POST['counterparty_name']) ? trim((string)$_POST['counterparty_name']) : '';
            $direction = isset($_POST['direction']) ? trim((string)$_POST['direction']) : '';
            $loanType = isset($_POST['loan_type']) ? trim((string)$_POST['loan_type']) : '';
            $principalAmt = isset($_POST['principal_amount']) ? (float)$_POST['principal_amount'] : 0.0;
            $principalAsset = isset($_POST['principal_asset']) ? trim((string)$_POST['principal_asset']) : 'cash';
            $apr = isset($_POST['interest_apr']) ? (float)$_POST['interest_apr'] : 0.0;
            $interestType = isset($_POST['interest_type']) ? trim((string)$_POST['interest_type']) : 'simple';
            $termDays = isset($_POST['term_days']) ? (int)$_POST['term_days'] : 0;
            $freq = isset($_POST['repayment_frequency']) ? trim((string)$_POST['repayment_frequency']) : 'monthly';
            $repaymentAssets = isset($_POST['repayment_assets']) && is_array($_POST['repayment_assets']) ? array_values(array_unique(array_map('strval', $_POST['repayment_assets']))) : [];
            $smartContract = [
                'chain' => isset($_POST['sc_chain']) ? trim((string)$_POST['sc_chain']) : '',
                'contract' => isset($_POST['sc_contract']) ? trim((string)$_POST['sc_contract']) : '',
                'type' => isset($_POST['sc_type']) ? trim((string)$_POST['sc_type']) : '',
            ];
            $bankIntegration = [
                'provider' => 'mh_crypto_bank',
                'external_reference' => isset($_POST['bank_ref']) ? trim((string)$_POST['bank_ref']) : '',
            ];

            $validTypes = ['equity_sale_leaseback', 'cash_deposit', 'equity_to_loan', 'loan_against_collateral'];
            $validDirections = ['company_lends', 'company_borrows'];
            if (!in_array($direction, $validDirections, true)) {
                if (in_array($loanType, ['cash_deposit', 'equity_sale_leaseback', 'equity_to_loan'], true)) {
                    $direction = 'company_borrows';
                } else {
                    $direction = 'company_lends';
                }
            }

            if ($counterparty === '' || !in_array($loanType, $validTypes, true) || $principalAmt <= 0) {
                $error = 'Missing required fields.';
            } else {
                $principalAmt = round($principalAmt, 8);
                $apr = max(0.0, min(1000.0, round($apr, 6)));
                $termDays = max(0, min(36500, $termDays));
                if (!in_array($interestType, ['simple', 'compound'], true)) $interestType = 'simple';
                if (!in_array($freq, ['weekly', 'biweekly', 'monthly', 'quarterly', 'at_maturity'], true)) $freq = 'monthly';
                $borrowerForCompat = $counterparty;
                $borrowerNameForCompat = $counterpartyName;
                $ins = $pdo->prepare("INSERT INTO mh_loans (borrower_username, borrower_name, direction, counterparty_username, counterparty_name, loan_type, principal_amount, principal_asset, interest_apr, interest_type, term_days, repayment_frequency, repayment_assets_json, collateral_json, smart_contract_json, bank_integration_json, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
                $ins->execute([
                    $borrowerForCompat,
                    $borrowerNameForCompat !== '' ? $borrowerNameForCompat : null,
                    $direction,
                    $counterparty,
                    $counterpartyName !== '' ? $counterpartyName : null,
                    $loanType,
                    $principalAmt,
                    $principalAsset !== '' ? $principalAsset : 'cash',
                    $apr,
                    $interestType,
                    $termDays,
                    $freq,
                    json_encode($repaymentAssets, JSON_UNESCAPED_SLASHES),
                    null,
                    json_encode($smartContract, JSON_UNESCAPED_SLASHES),
                    json_encode($bankIntegration, JSON_UNESCAPED_SLASHES),
                    $actor,
                ]);
                $loanId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO mh_loan_events (loan_id, event_type, meta_json, created_by) VALUES (?, 'created', ?, ?)")->execute([$loanId, json_encode(['loan_type' => $loanType, 'direction' => $direction], JSON_UNESCAPED_SLASHES), $actor]);
                $message = 'Loan created.';
                $createFlash = $createDefaults;
            }
        } elseif ($action === 'add_event') {
            $loanId = isset($_POST['loan_id']) ? (int)$_POST['loan_id'] : 0;
            $eventType = isset($_POST['event_type']) ? trim((string)$_POST['event_type']) : '';
            $amt = isset($_POST['event_amount']) && $_POST['event_amount'] !== '' ? (float)$_POST['event_amount'] : null;
            $asset = isset($_POST['event_asset']) ? trim((string)$_POST['event_asset']) : null;
            if ($loanId < 1 || $eventType === '') {
                $error = 'Invalid event.';
            } else {
                $meta = isset($_POST['event_meta']) ? trim((string)$_POST['event_meta']) : '';
                $metaJson = $meta !== '' ? json_encode(['note' => $meta], JSON_UNESCAPED_SLASHES) : null;
                $pdo->prepare("INSERT INTO mh_loan_events (loan_id, event_type, amount, asset, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)")->execute([
                    $loanId,
                    $eventType,
                    $amt !== null ? round((float)$amt, 8) : null,
                    $asset !== '' ? $asset : null,
                    $metaJson,
                    $actor,
                ]);
                $message = 'Event added.';
            }
        }
    }
    $_SESSION['mh_loans_flash'] = [
        'message' => $message,
        'error' => $error,
        'create' => $createFlash,
    ];
    header('Location: ' . $requestUri, true, 303);
    exit;
}

$hasDir = false;
$hasCp = false;
try {
    $hasDir = mh_loans_has_column($pdo, 'mh_loans', 'direction');
    $hasCp = mh_loans_has_column($pdo, 'mh_loans', 'counterparty_username');
} catch (Throwable $e) {
    $hasDir = false;
    $hasCp = false;
}
$select = "SELECT id, "
    . ($hasDir ? "direction" : "NULL as direction") . ", "
    . ($hasCp ? "counterparty_username, counterparty_name" : "NULL as counterparty_username, NULL as counterparty_name") . ", "
    . "borrower_username, borrower_name, loan_type, principal_amount, principal_asset, interest_apr, term_days, repayment_frequency, status, created_at "
    . "FROM mh_loans ORDER BY id DESC LIMIT 200";
$loans = [];
try {
    $loans = $pdo->query($select)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    try { error_log('[Loans Control] list failed: ' . $e->getMessage()); } catch (Throwable $e2) {}
    $loans = [];
}

$repayAssets = [
    'cash' => 'Cash',
    'stable_coins' => 'Stable Coins',
    'meme_culture_coins' => 'Meme/Culture Coins',
    'equity' => 'Digital Equity',
    'equity_coins' => 'Equity Coins',
    'utility_token' => 'Utility Token (MTK)',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loans | Control</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root { --primary: #00d4ff; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); --text-main: #e0e0e0; }
        body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .page .container { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); margin: 0 0 10px; }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 18px; border-radius: 12px; margin-bottom: 18px; }
        .row { display:flex; gap: 14px; flex-wrap: wrap; align-items: end; }
        .field { min-width: 220px; flex: 1; }
        label { display:block; margin: 0 0 6px; color: rgba(255,255,255,0.82); font-size: 12px; }
        input, select, textarea { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
        textarea { min-height: 64px; }
        .btn { display:inline-flex; gap: 8px; align-items:center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight: 800; }
        table { width:100%; border-collapse: collapse; }
        th, td { text-align:left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
        th { color: var(--primary); font-weight: 700; font-size: 0.9rem; }
        .ok { color: rgba(16,185,129,0.95); font-weight: 800; }
        .bad { color: rgba(239,68,68,0.95); font-weight: 800; }
        .list { margin-top: 8px; border: 1px solid rgba(255,255,255,0.10); border-radius: 12px; overflow: hidden; display:none; }
        .list button { width:100%; text-align:left; padding: 10px 12px; background: rgba(0,0,0,0.20); border: 0; border-bottom: 1px solid rgba(255,255,255,0.08); color: rgba(255,255,255,0.92); cursor:pointer; }
        .list button:hover { background: rgba(0,212,255,0.08); }
    </style>
</head>
<body class="page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="container">
        <h1>Loans</h1>

        <?php if ($message !== ''): ?><div class="panel ok"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="panel bad"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>

        <div class="panel">
            <h2>Create Loan</h2>
            <form method="post" action="<?php echo htmlspecialchars($requestUri, ENT_QUOTES); ?>">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="create_loan">

                <div class="row">
                    <div class="field">
                        <label>User search (counterparty)</label>
                        <input id="mhBorrowerSearch" type="text" placeholder="Type name or username...">
                        <div id="mhBorrowerResults" class="list"></div>
                    </div>
                    <div class="field">
                        <label>Counterparty Username</label>
                        <input id="mhBorrowerUsername" name="counterparty_username" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['counterparty_username'], ENT_QUOTES); ?>" required>
                    </div>
                    <div class="field">
                        <label>Counterparty Real Name</label>
                        <input id="mhBorrowerName" name="counterparty_name" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['counterparty_name'], ENT_QUOTES); ?>">
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div class="field">
                        <label>Company Role</label>
                        <select id="mhDirection" name="direction" required>
                            <option value="company_lends" <?php echo ((string)$createDefaults['direction'] === 'company_lends') ? 'selected' : ''; ?>>Company lends to user</option>
                            <option value="company_borrows" <?php echo ((string)$createDefaults['direction'] === 'company_borrows') ? 'selected' : ''; ?>>Company borrows from user</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Scenario</label>
                        <select id="mhLoanType" name="loan_type" required>
                            <option value="">Select...</option>
                            <option value="equity_sale_leaseback" <?php echo ((string)$createDefaults['loan_type'] === 'equity_sale_leaseback') ? 'selected' : ''; ?>>Sold equity then loaned back</option>
                            <option value="cash_deposit" <?php echo ((string)$createDefaults['loan_type'] === 'cash_deposit') ? 'selected' : ''; ?>>Cash deposit loan</option>
                            <option value="equity_to_loan" <?php echo ((string)$createDefaults['loan_type'] === 'equity_to_loan') ? 'selected' : ''; ?>>Equity offered then converted to loan</option>
                            <option value="loan_against_collateral" <?php echo ((string)$createDefaults['loan_type'] === 'loan_against_collateral') ? 'selected' : ''; ?>>Loan against coins/equity collateral</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Principal Amount</label>
                        <input name="principal_amount" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['principal_amount'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label>Principal Asset</label>
                        <select name="principal_asset">
                            <?php foreach ($repayAssets as $k => $v): ?>
                                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>" <?php echo ((string)$createDefaults['principal_asset'] === $k) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div class="field">
                        <label>Interest APR (%)</label>
                        <input name="interest_apr" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['interest_apr'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label>Interest Type</label>
                        <select name="interest_type">
                            <option value="simple" <?php echo ((string)$createDefaults['interest_type'] === 'simple') ? 'selected' : ''; ?>>Simple</option>
                            <option value="compound" <?php echo ((string)$createDefaults['interest_type'] === 'compound') ? 'selected' : ''; ?>>Compound</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Term (days)</label>
                        <input name="term_days" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['term_days'], ENT_QUOTES); ?>">
                    </div>
                    <div class="field">
                        <label>Repayment Frequency</label>
                        <select name="repayment_frequency">
                            <option value="monthly" <?php echo ((string)$createDefaults['repayment_frequency'] === 'monthly') ? 'selected' : ''; ?>>Monthly</option>
                            <option value="weekly" <?php echo ((string)$createDefaults['repayment_frequency'] === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                            <option value="biweekly" <?php echo ((string)$createDefaults['repayment_frequency'] === 'biweekly') ? 'selected' : ''; ?>>Biweekly</option>
                            <option value="quarterly" <?php echo ((string)$createDefaults['repayment_frequency'] === 'quarterly') ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="at_maturity" <?php echo ((string)$createDefaults['repayment_frequency'] === 'at_maturity') ? 'selected' : ''; ?>>At maturity</option>
                        </select>
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div class="field">
                        <label>Allowed Repayment Assets</label>
                        <select name="repayment_assets[]" multiple>
                            <?php foreach ($repayAssets as $k => $v): ?>
                                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>" <?php echo in_array($k, is_array($createDefaults['repayment_assets']) ? $createDefaults['repayment_assets'] : [], true) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Smart Contract Chain</label>
                        <input name="sc_chain" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['sc_chain'], ENT_QUOTES); ?>" placeholder="ethereum / polygon / solana / ...">
                    </div>
                    <div class="field">
                        <label>Smart Contract Type</label>
                        <input name="sc_type" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['sc_type'], ENT_QUOTES); ?>" placeholder="loan / escrow / collateral / ...">
                    </div>
                    <div class="field">
                        <label>Smart Contract Address</label>
                        <input name="sc_contract" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['sc_contract'], ENT_QUOTES); ?>" placeholder="0x...">
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div class="field">
                        <label>Bank Reference (MH Crypto Bank)</label>
                        <input name="bank_ref" type="text" value="<?php echo htmlspecialchars((string)$createDefaults['bank_ref'], ENT_QUOTES); ?>" placeholder="optional external reference">
                    </div>
                    <div class="field" style="flex:0; min-width:220px;">
                        <button class="btn" type="submit">Create Loan</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Loans</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Company Role</th>
                        <th>Scenario</th>
                        <th>Principal</th>
                        <th>APR</th>
                        <th>Term</th>
                        <th>Status</th>
                        <th>Add Event</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loans as $l): ?>
                    <?php
                        $dir = (string)($l['direction'] ?? '');
                        $uName = (string)($l['counterparty_name'] ?? '');
                        $uUser = (string)($l['counterparty_username'] ?? '');
                        if ($uUser === '') $uUser = (string)($l['borrower_username'] ?? '');
                        if ($uName === '') $uName = (string)($l['borrower_name'] ?? '');
                        $roleLabel = $dir === 'company_borrows' ? 'Company borrows' : 'Company lends';
                    ?>
                    <tr>
                        <td class="muted"><?php echo (int)($l['id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($uName, ENT_QUOTES); ?><div class="muted"><?php echo htmlspecialchars($uUser, ENT_QUOTES); ?></div></td>
                        <td class="muted"><?php echo htmlspecialchars($roleLabel, ENT_QUOTES); ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string)($l['loan_type'] ?? ''), ENT_QUOTES); ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string)($l['principal_amount'] ?? '0'), ENT_QUOTES); ?> <?php echo htmlspecialchars((string)($l['principal_asset'] ?? ''), ENT_QUOTES); ?></td>
                        <td class="muted"><?php echo htmlspecialchars((string)($l['interest_apr'] ?? '0'), ENT_QUOTES); ?>%</td>
                        <td class="muted"><?php echo (int)($l['term_days'] ?? 0); ?> days</td>
                        <td class="muted"><?php echo htmlspecialchars((string)($l['status'] ?? ''), ENT_QUOTES); ?></td>
                        <td>
                            <form method="post" action="<?php echo htmlspecialchars($requestUri, ENT_QUOTES); ?>" class="row" style="gap: 8px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="add_event">
                                <input type="hidden" name="loan_id" value="<?php echo (int)($l['id'] ?? 0); ?>">
                                <div class="field" style="min-width: 140px;">
                                    <label>Type</label>
                                    <select name="event_type">
                                        <option value="repayment">Repayment</option>
                                        <option value="disbursement">Disbursement</option>
                                        <option value="collateral_posted">Collateral Posted</option>
                                        <option value="note">Note</option>
                                    </select>
                                </div>
                                <div class="field" style="min-width: 140px;">
                                    <label>Amount</label>
                                    <input name="event_amount" type="text" value="">
                                </div>
                                <div class="field" style="min-width: 160px;">
                                    <label>Asset</label>
                                    <select name="event_asset">
                                        <option value="">(none)</option>
                                        <?php foreach ($repayAssets as $k => $v): ?>
                                            <option value="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>"><?php echo htmlspecialchars($v, ENT_QUOTES); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field" style="min-width: 220px;">
                                    <label>Note</label>
                                    <input name="event_meta" type="text" value="">
                                </div>
                                <div class="field" style="flex:0; min-width: 140px;">
                                    <button class="btn" type="submit">Add</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
(function () {
  const q = document.getElementById('mhBorrowerSearch');
  const list = document.getElementById('mhBorrowerResults');
  const u = document.getElementById('mhBorrowerUsername');
  const n = document.getElementById('mhBorrowerName');
  if (!q || !list || !u || !n) return;
  let t = null;
  async function run() {
    const term = String(q.value || '').trim();
    if (term.length < 2) { list.style.display = 'none'; list.innerHTML=''; return; }
    const url = `/control/loans/?ajax=user_search&q=${encodeURIComponent(term)}`;
    const res = await fetch(url, { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }});
    const txt = await res.text();
    let data = null;
    try { data = JSON.parse(txt); } catch (e) { data = null; }
    if (!res.ok || !data || data.ok !== true) { list.style.display='none'; list.innerHTML=''; return; }
    const users = Array.isArray(data.users) ? data.users : [];
    list.innerHTML = '';
    if (!users.length) { list.style.display='none'; return; }
    users.forEach(r => {
      const username = String(r.username || '').trim();
      if (!username) return;
      const name = String(r.name || '').trim();
      const label = name ? `${name} (${username})` : username;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = label;
      btn.addEventListener('click', () => {
        u.value = username;
        n.value = name;
        list.style.display='none';
        list.innerHTML='';
      });
      list.appendChild(btn);
    });
    list.style.display='block';
  }
  q.addEventListener('input', () => { if (t) clearTimeout(t); t = setTimeout(() => { run().catch(() => {}); }, 200); });
  document.addEventListener('click', (e) => { if (!list.contains(e.target) && e.target !== q) { list.style.display='none'; }});
})();
</script>
<script>
(function () {
  const typeEl = document.getElementById('mhLoanType');
  const dirEl = document.getElementById('mhDirection');
  if (!typeEl || !dirEl) return;
  function auto() {
    const t = String(typeEl.value || '');
    if (!t) return;
    if (t === 'loan_against_collateral') {
      dirEl.value = 'company_lends';
      return;
    }
    if (t === 'cash_deposit' || t === 'equity_to_loan' || t === 'equity_sale_leaseback') {
      dirEl.value = 'company_borrows';
      return;
    }
  }
  typeEl.addEventListener('change', auto);
  setTimeout(auto, 0);
})();
</script>
</body>
</html>
