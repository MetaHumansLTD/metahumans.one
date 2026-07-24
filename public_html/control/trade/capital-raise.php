<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../hub/equity/db.php';

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/trade/capital-raise.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string)$_SESSION['mh_auth_role'])) : '';
if ($role === '' || strpos($role, 'kripzmaster') === false) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (__DIR__ . '/../../templates');

if (!function_exists('mh_equity_secret_store_path')) {
    function mh_equity_secret_store_path(): string
    {
        $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
        $p = $paths && method_exists($paths, 'getSecureFilePath') ? $paths->getSecureFilePath('config/tokenomics-secrets.json', false) : null;
        if (is_string($p) && $p !== '') return $p;
        $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
        $base = $base !== '' ? rtrim($base, '/') : '/data';
        return $base . '/config/tokenomics-secrets.json';
    }
}

if (!function_exists('mh_equity_secret_store_key')) {
    function mh_equity_secret_store_key(): string
    {
        if (function_exists('cue_autoload')) {
            cue_autoload('paths');
            cue_autoload('security');
        }
        $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
        $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        return is_string($raw) ? trim($raw) : '';
    }
}

if (!function_exists('mh_equity_secret_store_get')) {
    function mh_equity_secret_store_get(string $key): string
    {
        $p = mh_equity_secret_store_path();
        if (!is_file($p) || !is_readable($p)) return '';
        $raw = @file_get_contents($p);
        $cfg = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($cfg)) return '';
        $enc = isset($cfg[$key]) && is_string($cfg[$key]) ? trim((string)$cfg[$key]) : '';
        if ($enc === '') return '';
        $k = mh_equity_secret_store_key();
        if ($k === '' || !function_exists('security_decryptValue')) return '';
        $plain = security_decryptValue($enc, $k);
        return is_string($plain) ? trim((string)$plain) : '';
    }
}

if (!function_exists('mh_brex_get_access_token')) {
    function mh_brex_get_access_token(): string
    {
        $t = getenv('BREX_ACCESS_TOKEN');
        if (!is_string($t) || trim($t) === '') {
            $t = (string)($_ENV['BREX_ACCESS_TOKEN'] ?? ($_SERVER['BREX_ACCESS_TOKEN'] ?? ''));
        }
        $t = trim((string)$t);
        if ($t !== '') return $t;
        return mh_equity_secret_store_get('brex_access_token');
    }
}

if (!function_exists('mh_brex_get_cash_account_id')) {
    function mh_brex_get_cash_account_id(): string
    {
        $id = getenv('BREX_CASH_ACCOUNT_ID');
        if (!is_string($id) || trim($id) === '') {
            $id = (string)($_ENV['BREX_CASH_ACCOUNT_ID'] ?? ($_SERVER['BREX_CASH_ACCOUNT_ID'] ?? ''));
        }
        $id = trim((string)$id);
        if ($id !== '') return $id;
        return mh_equity_secret_store_get('brex_cash_account_id');
    }
}

if (!function_exists('mh_brex_list_cash_transactions')) {
    function mh_brex_list_cash_transactions(string $cashAccountId, ?string $postedAtStart = null, int $limit = 100): array
    {
        $cashAccountId = trim($cashAccountId);
        if ($cashAccountId === '') return [];
        $token = mh_brex_get_access_token();
        if ($token === '') return [];

        $limit = max(1, min(100, $limit));
        $qs = ['limit' => $limit];
        if (is_string($postedAtStart) && trim($postedAtStart) !== '') {
            $qs['posted_at_start'] = trim($postedAtStart);
        }
        $url = 'https://api.brex.com/v2/transactions/cash/' . rawurlencode($cashAccountId) . '?' . http_build_query($qs);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $ch = null;
        if ($body === false || $err) return [];
        if ($code < 200 || $code >= 300) return [];
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) return [];
        $items = $decoded['items'] ?? null;
        return is_array($items) ? $items : [];
    }
}

if (!function_exists('mh_brex_match_cash_transaction')) {
    function mh_brex_match_cash_transaction(array $txn, string $reference, float $amount): bool
    {
        $reference = trim($reference);
        if ($reference === '') return false;
        $blob = strtolower((string)json_encode($txn, JSON_UNESCAPED_SLASHES));
        if ($blob === '' || strpos($blob, strtolower($reference)) === false) return false;

        $amt = null;
        if (isset($txn['amount']) && is_array($txn['amount'])) {
            if (isset($txn['amount']['amount'])) $amt = $txn['amount']['amount'];
            if (isset($txn['amount']['value'])) $amt = $txn['amount']['value'];
        }
        if ($amt === null && isset($txn['amount'])) {
            $amt = $txn['amount'];
        }
        $amtF = is_string($amt) || is_numeric($amt) ? (float)$amt : null;
        if ($amtF === null) return false;
        return abs($amtF - $amount) < 0.01;
    }
}

$message = '';
if (isset($_SESSION['mh_cap_raise_flash']) && is_string($_SESSION['mh_cap_raise_flash'])) {
    $message = (string)$_SESSION['mh_cap_raise_flash'];
    unset($_SESSION['mh_cap_raise_flash']);
}

try {
    $pdo = getEquityConnection();
    mh_equity_ensure_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'System Error: ' . $e->getMessage();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if ($action === 'brex_check_primary_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        if ($orderId < 1) {
            $_SESSION['mh_cap_raise_flash'] = 'Invalid order.';
            header('Location: /control/trade/capital-raise.php');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM equity_primary_orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($order)) {
                throw new RuntimeException('Order not found.');
            }
            $status = isset($order['status']) ? (string)$order['status'] : '';
            if ($status === 'settled') {
                $pdo->commit();
                $_SESSION['mh_cap_raise_flash'] = 'Order already settled.';
                header('Location: /control/trade/capital-raise.php');
                exit;
            }

            $ref = (string)($order['payment_reference'] ?? '');
            $amount = (float)($order['total_amount'] ?? 0);
            $cashAccountId = (string)($order['brex_cash_account_id'] ?? '');
            if ($cashAccountId === '') {
                $cashAccountId = mh_brex_get_cash_account_id();
            }
            if ($cashAccountId === '') {
                throw new RuntimeException('Brex cash account not configured.');
            }

            $createdAt = isset($order['created_at']) ? (string)$order['created_at'] : '';
            $postedAtStart = '';
            if ($createdAt !== '') {
                $ts = strtotime($createdAt);
                if ($ts !== false) {
                    $postedAtStart = gmdate('c', max(0, $ts - 86400));
                }
            }
            $txns = mh_brex_list_cash_transactions($cashAccountId, $postedAtStart, 100);
            $matchId = '';
            foreach ($txns as $txn) {
                if (!is_array($txn)) continue;
                if (!mh_brex_match_cash_transaction($txn, $ref, $amount)) continue;
                $matchId = (string)($txn['id'] ?? ($txn['transaction_id'] ?? ($txn['transactionId'] ?? '')));
                break;
            }
            if ($matchId === '') {
                $pdo->rollBack();
                $_SESSION['mh_cap_raise_flash'] = 'No matching Brex payment found yet for ' . $ref . '.';
                header('Location: /control/trade/capital-raise.php');
                exit;
            }

            $buyer = (string)($order['buyer_username'] ?? '');
            $classId = (int)($order['class_id'] ?? 0);
            $sharesRequested = (int)($order['shares_requested'] ?? 0);
            $unitsRequested = (int)($order['units_requested'] ?? 0);
            $ppu = (float)($order['price_per_unit'] ?? 0);
            if ($buyer === '' || $classId < 1 || $sharesRequested < 1 || $unitsRequested < 1) {
                throw new RuntimeException('Order invalid.');
            }

            $stmt = $pdo->prepare("SELECT total_shares, fractional_units_per_share, name FROM equity_classes WHERE id = ? LIMIT 1");
            $stmt->execute([$classId]);
            $cls = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($cls)) {
                throw new RuntimeException('Equity class not found.');
            }
            $authorized = (int)($cls['total_shares'] ?? 0);
            $unitsPerShare = (int)($cls['fractional_units_per_share'] ?? 400);
            if ($unitsPerShare < 1) $unitsPerShare = 1;
            $issued = (float)mh_equity_get_total_shares_issued($pdo, $classId);
            $available = max(0, $authorized - (int)floor($issued));
            if ($authorized > 0 && $sharesRequested > $available) {
                throw new RuntimeException('Not enough unissued shares remain to settle.');
            }

            $stmt = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
            $stmt->execute([$buyer, $classId, $unitsRequested, $unitsRequested]);

            $stmt = $pdo->prepare("INSERT INTO equity_user_profiles (username, user_type, ordinary_votes_shareholder, ordinary_votes_founder) VALUES (?, 'shareholder', 1, 1000)
                ON DUPLICATE KEY UPDATE user_type = VALUES(user_type), ordinary_votes_shareholder = VALUES(ordinary_votes_shareholder)");
            $stmt->execute([$buyer]);

            $className = strtolower(trim((string)($cls['name'] ?? '')));
            $isPreference = $className !== '' && (strpos($className, 'preference') !== false || strpos($className, 'preferred') !== false);
            if ($isPreference) {
                $defs = [];
                try {
                    $rows = $pdo->query("SELECT code FROM equity_rights_definitions")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $code = isset($r['code']) ? trim((string)$r['code']) : '';
                        if ($code === '' || $code === 'super_vote') continue;
                        $defs[] = $code;
                    }
                } catch (Throwable $e) {
                    $defs = [];
                }
                $stmt = $pdo->prepare("INSERT INTO equity_share_rights (username, class_id, shares_covered, rights_json) VALUES (?, ?, ?, NULL)");
                $stmt->execute([$buyer, $classId, $sharesRequested]);
                if (!empty($defs)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO equity_share_rights_map (username, class_id, right_code) VALUES (?, ?, ?)");
                    foreach (array_values(array_unique($defs)) as $code) {
                        $ins->execute([$buyer, $classId, $code]);
                    }
                }
            }

            $stmt = $pdo->query("SELECT txn_hash FROM equity_transactions ORDER BY id DESC LIMIT 1");
            $lastHash = $stmt->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000';
            $timestamp = date('Y-m-d H:i:s');
            $dataString = $lastHash . 'PRIMARY_MINT' . $buyer . $classId . $unitsRequested . $timestamp . $ref;
            $newHash = hash('sha256', $dataString);
            $stmt = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, NULL, ?, ?, ?, 'primary_mint', ?)");
            $stmt->execute([$lastHash, $newHash, $classId, $buyer, $unitsRequested, $ppu, $timestamp]);

            $upd = $pdo->prepare("UPDATE equity_primary_orders SET status = 'settled', brex_transaction_id = ?, brex_cash_account_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$matchId, $cashAccountId, $orderId]);

            $pdo->commit();
            $_SESSION['mh_cap_raise_flash'] = 'Order settled for ' . $buyer . ' (ref ' . $ref . ').';
            header('Location: /control/trade/capital-raise.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['mh_cap_raise_flash'] = 'Settlement failed: ' . $e->getMessage();
            header('Location: /control/trade/capital-raise.php');
            exit;
        }
    }
}

$orders = [];
try {
    $stmt = $pdo->query("SELECT o.*, c.name AS class_name FROM equity_primary_orders o JOIN equity_classes c ON c.id = o.class_id WHERE o.status IN ('pending_payment','paid') ORDER BY o.id DESC LIMIT 200");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $orders = [];
    $message = $message !== '' ? $message : ('Query failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Capital Raise Orders | Meta Humans</title>
    <?php include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root { --primary: #00d4ff; --bg-dark: #1a1a1a; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); }
        body.cap-raise-page main.main-content { color: #e0e0e0; font-family: var(--font-primary, 'Rajdhani', sans-serif); }
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
        th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        .btn { background: var(--primary); color: #000; padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        .btn:hover { opacity: 0.9; }
        .alert { background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: var(--primary); padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .hint { color:#9aa; font-size:0.85rem; margin-top: 6px; }
    </style>
</head>
<body class="cap-raise-page">
<?php include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content cap-raise-page">
<div class="container">
    <h1>Admin: Pending Capital Raise Orders</h1>
    <div class="hint" style="display:flex; align-items:center; gap: 12px; flex-wrap: wrap;">
        <div>
            Brex Cash Account: <?php echo htmlspecialchars(mh_brex_get_cash_account_id() !== '' ? mh_brex_get_cash_account_id() : 'not_configured'); ?>
            · Brex Token: <?php echo htmlspecialchars(mh_brex_get_access_token() !== '' ? 'configured' : 'not_configured'); ?>
        </div>
        <a class="btn" href="/control/tokenomics-management.php" style="text-decoration:none; display:inline-block; font-size:0.8rem;">Configure APIs</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="panel">
        <h2>Orders</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Buyer</th>
                    <th>Class</th>
                    <th>Shares</th>
                    <th>Total</th>
                    <th>Reference</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?php echo (int)($o['id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars((string)($o['buyer_username'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($o['class_name'] ?? '')); ?></td>
                        <td><?php echo number_format((int)($o['shares_requested'] ?? 0)); ?></td>
                        <td>$<?php echo number_format((float)($o['total_amount'] ?? 0), 2); ?></td>
                        <td><?php echo htmlspecialchars((string)($o['payment_reference'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($o['status'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($o['created_at'] ?? '')); ?></td>
                        <td>
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="action" value="brex_check_primary_order">
                                <input type="hidden" name="order_id" value="<?php echo (int)($o['id'] ?? 0); ?>">
                                <button type="submit" class="btn" style="font-size:0.8rem;">Check Brex</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="9" style="text-align:center; color:#aaa;">No pending orders.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</main>
<?php include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
