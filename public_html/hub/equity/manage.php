<?php
/**
 * User Equity Management Dashboard
 * 
 * Functions:
 * 1. View Personal Holdings (Coins + Share Equivalent)
 * 2. List Coins for Sale (Fractionalize)
 * 3. Buy Coins from Market (Other Users)
 * 4. Transaction History
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../templates/global-ui/functions.php';

// Force secure session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}
$user = $_SESSION['mh_auth_user'];
$role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
$isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
$message = '';
if (isset($_SESSION['mh_equity_flash']) && is_string($_SESSION['mh_equity_flash'])) {
    $message = (string)$_SESSION['mh_equity_flash'];
    unset($_SESSION['mh_equity_flash']);
}

function mh_brex_get_access_token(): string {
    $t = getenv('BREX_ACCESS_TOKEN');
    if (!is_string($t) || trim($t) === '') {
        $t = (string)($_ENV['BREX_ACCESS_TOKEN'] ?? ($_SERVER['BREX_ACCESS_TOKEN'] ?? ''));
    }
    $t = trim((string)$t);
    if ($t !== '') return $t;
    return mh_equity_secret_store_get('brex_access_token');
}

function mh_brex_get_cash_account_id(): string {
    $id = getenv('BREX_CASH_ACCOUNT_ID');
    if (!is_string($id) || trim($id) === '') {
        $id = (string)($_ENV['BREX_CASH_ACCOUNT_ID'] ?? ($_SERVER['BREX_CASH_ACCOUNT_ID'] ?? ''));
    }
    $id = trim((string)$id);
    if ($id !== '') return $id;
    return mh_equity_secret_store_get('brex_cash_account_id');
}

function mh_equity_secret_store_path(): string
{
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $p = $paths && method_exists($paths, 'getSecureFilePath') ? $paths->getSecureFilePath('config/tokenomics-secrets.json', false) : null;
    if (is_string($p) && $p !== '') return $p;
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/config/tokenomics-secrets.json';
}

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

function mh_stripe_get_secret_key(): string
{
    $k = getenv('STRIPE_SECRET_KEY');
    if (!is_string($k) || trim($k) === '') {
        $k = (string)($_ENV['STRIPE_SECRET_KEY'] ?? ($_SERVER['STRIPE_SECRET_KEY'] ?? ''));
    }
    $k = trim((string)$k);
    if ($k !== '') return $k;
    return mh_equity_secret_store_get('stripe_secret_key');
}

function mh_stripe_checkout_create(float $amountUsd, string $successUrl, string $cancelUrl, array $meta = [], string $clientReferenceId = ''): array
{
    $secret = mh_stripe_get_secret_key();
    if ($secret === '') return ['ok' => false, 'error' => 'Stripe not configured'];
    if ($amountUsd <= 0) return ['ok' => false, 'error' => 'Invalid amount'];

    $amountCents = (int)round($amountUsd * 100);
    if ($amountCents < 50) return ['ok' => false, 'error' => 'Amount too small'];

    $fields = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => 'usd',
                'product_data' => ['name' => 'Company Equity'],
                'unit_amount' => $amountCents,
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
    ];
    if ($clientReferenceId !== '') $fields['client_reference_id'] = $clientReferenceId;
    if (!empty($meta)) $fields['metadata'] = $meta;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $secret . ':');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $ch = null;
    if ($result === false || (is_string($err) && $err !== '')) return ['ok' => false, 'error' => 'Stripe connection error'];
    $decoded = json_decode((string)$result, true);
    if (!is_array($decoded) || isset($decoded['error'])) return ['ok' => false, 'error' => 'Stripe error'];
    $url = isset($decoded['url']) && is_string($decoded['url']) ? trim((string)$decoded['url']) : '';
    $sid = isset($decoded['id']) && is_string($decoded['id']) ? trim((string)$decoded['id']) : '';
    if ($url === '') return ['ok' => false, 'error' => 'Stripe missing redirect URL'];
    return ['ok' => true, 'url' => $url, 'id' => $sid];
}

function mh_stripe_checkout_get_session(string $sessionId): array
{
    $secret = mh_stripe_get_secret_key();
    if ($secret === '') return ['ok' => false, 'error' => 'Stripe not configured'];
    $sessionId = trim((string)$sessionId);
    if ($sessionId === '') return ['ok' => false, 'error' => 'Missing session id'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $secret . ':');
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $ch = null;
    if ($result === false || (is_string($err) && $err !== '')) return ['ok' => false, 'error' => 'Stripe connection error'];
    $decoded = json_decode((string)$result, true);
    if (!is_array($decoded) || isset($decoded['error'])) return ['ok' => false, 'error' => 'Stripe error'];
    return ['ok' => true, 'session' => $decoded];
}

function mh_brex_list_cash_transactions(string $cashAccountId, ?string $postedAtStart = null, int $limit = 100): array {
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

function mh_brex_match_cash_transaction(array $txn, string $reference, float $amount): bool {
    $reference = trim($reference);
    if ($reference === '') return false;
    $blob = strtolower(json_encode($txn, JSON_UNESCAPED_SLASHES));
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

try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    $pdo = getEquityConnection();
    mh_equity_ensure_schema($pdo);
    if (function_exists('mh_equity_migrate_trading_to_ordinary')) {
        mh_equity_migrate_trading_to_ordinary($pdo);
    }
    if (function_exists('mh_equity_ensure_conversion_audit_schema')) {
        mh_equity_ensure_conversion_audit_schema($pdo);
    }
    $fullName = isset($_SESSION['mh_auth_display']) ? trim((string)$_SESSION['mh_auth_display']) : '';
    if ($fullName === '') {
        $fullName = $user;
    }

    if (isset($_GET['stripe_cancel']) && (string)$_GET['stripe_cancel'] !== '') {
        $_SESSION['mh_equity_flash'] = 'Stripe payment cancelled.';
        header('Location: /hub/equity/manage.php');
        exit;
    }
    if (isset($_GET['stripe_success']) && (string)$_GET['stripe_success'] !== '') {
        $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
        $sessionId = isset($_GET['session_id']) ? trim((string)$_GET['session_id']) : '';
        if ($orderId > 0 && $sessionId !== '') {
            try {
                $stmt = $pdo->prepare("SELECT id, buyer_username, status FROM equity_primary_orders WHERE id = ? LIMIT 1");
                $stmt->execute([$orderId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row) || (string)($row['buyer_username'] ?? '') !== $user) {
                    throw new RuntimeException('Order not found.');
                }
                $status = (string)($row['status'] ?? '');
                if ($status !== 'paid' && $status !== 'settled') {
                    $res = mh_stripe_checkout_get_session($sessionId);
                    if (!is_array($res) || empty($res['ok'])) {
                        throw new RuntimeException(isset($res['error']) ? (string)$res['error'] : 'Stripe verification failed');
                    }
                    $session = is_array($res['session'] ?? null) ? (array)$res['session'] : [];
                    $paid = (string)($session['payment_status'] ?? '') === 'paid';
                    $meta = isset($session['metadata']) && is_array($session['metadata']) ? (array)$session['metadata'] : [];
                    $metaOrder = isset($meta['order_id']) ? (int)$meta['order_id'] : 0;
                    $metaUser = isset($meta['username']) ? (string)$meta['username'] : '';
                    if (!$paid) throw new RuntimeException('Payment not verified.');
                    if ($metaOrder !== $orderId || $metaUser !== $user) throw new RuntimeException('Payment metadata mismatch.');

                    $upd = $pdo->prepare("UPDATE equity_primary_orders SET status='paid', stripe_session_id=?, payment_provider='stripe', updated_at=CURRENT_TIMESTAMP WHERE id=? AND buyer_username=?");
                    $upd->execute([$sessionId, $orderId, $user]);
                }
                $_SESSION['mh_equity_flash'] = 'Payment recorded. Pending settlement.';
                header('Location: /hub/equity/manage.php');
                exit;
            } catch (Throwable $e) {
                $_SESSION['mh_equity_flash'] = 'Stripe verification failed: ' . $e->getMessage();
                header('Location: /hub/equity/manage.php');
                exit;
            }
        }
    }

    // --- Handle Actions ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'primary_order') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $qtyShares = isset($_POST['qty_shares']) ? (int)$_POST['qty_shares'] : 0;
            $qtyShares = max(0, $qtyShares);
            if ($classId < 1 || $qtyShares < 1) {
                $message = 'Invalid purchase request.';
            } else {
                try {
                    $unitsPerShare = 400;
                    $stmt = $pdo->prepare("SELECT total_shares, fractional_units_per_share, name FROM equity_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$classId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) {
                        throw new RuntimeException('Equity class not found.');
                    }
                    $authorized = (int)($row['total_shares'] ?? 0);
                    $unitsPerShare = (int)($row['fractional_units_per_share'] ?? 400);
                    if ($unitsPerShare < 1) $unitsPerShare = 1;

                    $issued = (float)mh_equity_get_total_shares_issued($pdo, $classId);
                    $available = max(0, $authorized - (int)floor($issued));
                    if ($authorized > 0 && $qtyShares > $available) {
                        throw new RuntimeException('Not enough unissued shares available.');
                    }

                    $unitsRequested = $qtyShares * $unitsPerShare;
                    $ppu = function_exists('mh_equity_get_price_per_unit') ? (float)mh_equity_get_price_per_unit($pdo, $classId) : 0.0;
                    $ppu = max(0.0, $ppu);
                    $totalAmount = $unitsRequested * $ppu;
                    $ref = 'MH-EQ-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('YmdHis');

                    $cashAccountId = mh_brex_get_cash_account_id();

                    $stmt = $pdo->prepare("INSERT INTO equity_primary_orders (buyer_username, class_id, shares_requested, units_requested, price_per_unit, total_amount, brex_cash_account_id, payment_reference, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment')");
                    $stmt->execute([$user, $classId, $qtyShares, $unitsRequested, $ppu, $totalAmount, $cashAccountId !== '' ? $cashAccountId : null, $ref]);

                    $_SESSION['mh_equity_flash'] = 'Order created. Reference: ' . $ref . '. Please complete Brex deposit to settle.';
                    header('Location: /hub/equity/manage.php');
                    exit;
                } catch (Throwable $e) {
                    $message = 'Order failed: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'cancel_primary_order') {
            $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
            if ($orderId < 1) {
                $message = 'Invalid order.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT id, buyer_username, status FROM equity_primary_orders WHERE id = ? FOR UPDATE");
                    $stmt->execute([$orderId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($row)) {
                        throw new RuntimeException('Order not found.');
                    }
                    if ((string)($row['buyer_username'] ?? '') !== $user) {
                        throw new RuntimeException('Access denied.');
                    }
                    $status = isset($row['status']) ? (string)$row['status'] : '';
                    if ($status !== 'pending_payment') {
                        throw new RuntimeException('Only pending orders can be cancelled.');
                    }
                    $stmt = $pdo->prepare("UPDATE equity_primary_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND buyer_username = ? AND status = 'pending_payment'");
                    $stmt->execute([$orderId, $user]);
                    $pdo->commit();
                    $_SESSION['mh_equity_flash'] = 'Order cancelled.';
                    header('Location: /hub/equity/manage.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = 'Cancel failed: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'stripe_pay_primary_order') {
            $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
            if ($orderId < 1) {
                $message = 'Invalid order.';
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, buyer_username, status, total_amount, payment_reference FROM equity_primary_orders WHERE id = ? LIMIT 1");
                    $stmt->execute([$orderId]);
                    $order = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($order) || (string)($order['buyer_username'] ?? '') !== $user) {
                        throw new RuntimeException('Order not found.');
                    }
                    $status = (string)($order['status'] ?? '');
                    if ($status !== 'pending_payment') {
                        throw new RuntimeException('Order is not payable.');
                    }
                    $amount = (float)($order['total_amount'] ?? 0);
                    if ($amount <= 0) {
                        throw new RuntimeException('Invalid order amount.');
                    }
                    $ref = (string)($order['payment_reference'] ?? '');
                    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one';
                    $success = 'https://' . $host . '/hub/equity/manage.php?stripe_success=1&order_id=' . rawurlencode((string)$orderId) . '&session_id={CHECKOUT_SESSION_ID}';
                    $cancel = 'https://' . $host . '/hub/equity/manage.php?stripe_cancel=1';

                    $meta = [
                        'username' => $user,
                        'order_id' => (string)$orderId,
                        'reference' => $ref,
                        'kind' => 'equity_primary_order',
                    ];
                    $created = mh_stripe_checkout_create($amount, $success, $cancel, $meta, $user);
                    if (!is_array($created) || empty($created['ok']) || !isset($created['url'])) {
                        throw new RuntimeException(isset($created['error']) ? (string)$created['error'] : 'Stripe session failed');
                    }
                    $sid = isset($created['id']) && is_string($created['id']) ? trim((string)$created['id']) : '';
                    if ($sid !== '') {
                        try {
                            $pdo->prepare("UPDATE equity_primary_orders SET stripe_session_id=?, payment_provider='stripe', updated_at=CURRENT_TIMESTAMP WHERE id=? AND buyer_username=?")
                                ->execute([$sid, $orderId, $user]);
                        } catch (Throwable $e) {}
                    }
                    header('Location: ' . (string)$created['url']);
                    exit;
                } catch (Throwable $e) {
                    $message = 'Stripe payment failed: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'submit_bid_offer') {
            $types = [
                'preferred_equity' => 'Preferred Equity',
                'ordinary_equity' => 'Ordinary Equity',
                'preferred_equity_coins' => 'Preferred Equity Coins',
                'ordinary_equity_coins' => 'Ordinary Equity Coins',
                'culture_meme_coins' => 'Culture / Meme Coins',
                'stable_coins' => 'Stable Coins',
            ];
            $inserted = 0;
            try {
                foreach ($types as $k => $label) {
                    $qty = isset($_POST['bid_qty'][$k]) ? (int)$_POST['bid_qty'][$k] : 0;
                    $price = isset($_POST['bid_price'][$k]) ? (float)$_POST['bid_price'][$k] : 0.0;
                    if ($qty < 1 || $price <= 0) continue;
                    $stmt = $pdo->prepare("INSERT INTO equity_bid_offers (username, offer_type, qty, offered_price, status) VALUES (?, ?, ?, ?, 'active')");
                    $stmt->execute([$user, $k, $qty, $price]);
                    $inserted++;
                }
                if ($inserted > 0) {
                    $_SESSION['mh_equity_flash'] = 'Bid offers submitted.';
                    header('Location: /hub/equity/manage.php');
                    exit;
                }
                $message = 'No bid offers submitted.';
            } catch (Throwable $e) {
                $message = 'Bid offer failed: ' . $e->getMessage();
            }
        }

        if ($action === 'brex_check_primary_order') {
            if (!$isKripz) {
                $message = 'Access denied.';
            } else {
                $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
                if ($orderId < 1) {
                    $message = 'Invalid order.';
                } else {
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
                            $_SESSION['mh_equity_flash'] = 'Order already settled.';
                            header('Location: /hub/equity/manage.php');
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
                            $_SESSION['mh_equity_flash'] = 'No matching Brex payment found yet for ' . $ref . '.';
                            header('Location: /hub/equity/manage.php');
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
                        $_SESSION['mh_equity_flash'] = 'Order settled for ' . $buyer . ' (ref ' . $ref . ').';
                        header('Location: /hub/equity/manage.php');
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $message = 'Settlement failed: ' . $e->getMessage();
                    }
                }
            }
        }
        
        // SELL COINS / SHARES
        if ($action === 'sell') {
            $classId = (int)$_POST['class_id'];
            $qty = (int)$_POST['qty'];
            $priceInput = (float)$_POST['price'];
            $type = $_POST['type'] ?? 'coin'; // 'coin' or 'share'
            
            $fractionalUnits = 400;
            if ($classId > 0) {
                try {
                    $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$classId]);
                    $fractionalUnits = max(1, (int)$stmt->fetchColumn());
                } catch (Throwable $e) {
                    $fractionalUnits = 400;
                }
            }
            
            if ($type === 'share') {
                $units = $qty * $fractionalUnits;
                $pricePerUnit = $priceInput / $fractionalUnits;
            } else {
                $units = $qty;
                $pricePerUnit = $priceInput;
            }
            
            // Validation
            if ($units <= 0 || $pricePerUnit <= 0) {
                $message = "Invalid amount or price.";
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("SELECT units_owned FROM equity_ledger WHERE username = ? AND class_id = ? FOR UPDATE");
                    $stmt->execute([$user, $classId]);
                    $owned = (int)$stmt->fetchColumn();

                    if ($owned < $units) {
                        throw new RuntimeException('Insufficient balance to sell.');
                    }

                    $stmt = $pdo->prepare("UPDATE equity_ledger SET units_owned = units_owned - ? WHERE username = ? AND class_id = ? AND units_owned >= ?");
                    $stmt->execute([$units, $user, $classId, $units]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Failed to lock equity units.');
                    }
                    
                    $listingType = $type === 'share' ? 'share' : 'coin';
                    $stmt = $pdo->prepare("INSERT INTO equity_market (seller_username, class_id, units_available, price_per_unit, listing_type, display_qty, display_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([$user, $classId, $units, $pricePerUnit, $listingType, $qty, $priceInput]);
                    
                    $pdo->commit();
                    $displayType = ($type === 'share') ? 'shares' : 'coins';
                    $_SESSION['mh_equity_flash'] = "Successfully listed $qty $displayType for sale.";
                    header('Location: /hub/equity/manage.php');
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $message = "Error listing items: " . $e->getMessage();
                }
            }
        }
        
        // BUY COINS
        if ($action === 'buy') {
            $listingId = (int)$_POST['listing_id'];
            $unitsToBuy = isset($_POST['units']) ? (int)$_POST['units'] : 0;
            $sharesToBuy = isset($_POST['shares']) ? (int)$_POST['shares'] : 0;
            $fractionalUnits = 400;
            
            // Check listing
            $stmt = $pdo->prepare("SELECT * FROM equity_market WHERE id = ? AND status = 'active' FOR UPDATE");
            $pdo->beginTransaction();
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($listing) {
                try {
                    $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([(int)$listing['class_id']]);
                    $fractionalUnits = max(1, (int)$stmt->fetchColumn());
                } catch (Throwable $e) {
                    $fractionalUnits = 400;
                }
                $listingType = isset($listing['listing_type']) ? (string)$listing['listing_type'] : 'coin';
                if ($listingType === 'share') {
                    $unitsToBuy = max(0, $sharesToBuy) * $fractionalUnits;
                } else {
                    $unitsToBuy = max(0, $unitsToBuy);
                }
            }

            if ($listing && $unitsToBuy > 0 && $listing['units_available'] >= $unitsToBuy) {
                $totalCost = $unitsToBuy * $listing['price_per_unit'];
                // Check Buyer Balance (Utility Tokens? Or USD? Usually USD via Stripe or internal balance)
                // For this MVP, let's assume "Utility Tokens" can be used or just record the trade if no currency logic yet.
                // The prompt says "price is $6000... buy/sell format". Implies currency.
                // We'll simulate a successful trade for now (assuming external payment or balance check passed).
                
                try {
                    // 1. Update Market Listing
                    $newAmount = $listing['units_available'] - $unitsToBuy;
                    if ($newAmount == 0) {
                        $stmt = $pdo->prepare("UPDATE equity_market SET units_available = 0, display_qty = 0, status = 'sold' WHERE id = ?");
                    } else {
                        $listingType = isset($listing['listing_type']) ? (string)$listing['listing_type'] : 'coin';
                        $newDisplayQty = $listingType === 'share' ? (int)floor($newAmount / $fractionalUnits) : (int)$newAmount;
                        $stmt = $pdo->prepare("UPDATE equity_market SET units_available = ?, display_qty = ? WHERE id = ?");
                    }
                    if ($newAmount == 0) {
                        $stmt->execute([$listingId]);
                    } else {
                        $stmt->execute([$newAmount, $newDisplayQty, $listingId]);
                    }
                    
                    // 2. Transfer Ownership (Ledger)
                    // Add to Buyer
                    $stmt = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
                    $stmt->execute([$user, $listing['class_id'], $unitsToBuy, $unitsToBuy]);
                    
                    // Seller already had units deducted when listing.
                    $listingType = isset($listing['listing_type']) ? (string)$listing['listing_type'] : 'coin';
                    if ($listingType === 'share') {
                        $sharesTraded = (int)floor($unitsToBuy / $fractionalUnits);
                        if ($sharesTraded > 0 && function_exists('mh_equity_transfer_preference_rights')) {
                            mh_equity_transfer_preference_rights($pdo, (string)$listing['seller_username'], (string)$user, (int)$listing['class_id'], $sharesTraded);
                        }
                    }

                    $seller = (string)($listing['seller_username'] ?? '');
                    $classId = (int)($listing['class_id'] ?? 0);
                    $sellerType = 'shareholder';
                    $buyerType = 'shareholder';
                    $className = '';
                    if ($seller !== '' && $classId > 0) {
                        try {
                            $st = $pdo->prepare("SELECT user_type FROM equity_user_profiles WHERE username = ? LIMIT 1");
                            $st->execute([$seller]);
                            $v = $st->fetchColumn();
                            if (is_string($v) && trim($v) !== '') {
                                $sellerType = strtolower(trim((string)$v));
                            }
                        } catch (Throwable $e) {
                            $sellerType = 'shareholder';
                        }
                        try {
                            $bt = $pdo->prepare("SELECT user_type FROM equity_user_profiles WHERE username = ? LIMIT 1");
                            $bt->execute([$user]);
                            $v = $bt->fetchColumn();
                            if (is_string($v) && trim($v) !== '') {
                                $buyerType = strtolower(trim((string)$v));
                            }
                        } catch (Throwable $e) {
                            $buyerType = 'shareholder';
                        }
                        try {
                            $cn = $pdo->prepare("SELECT name FROM equity_classes WHERE id = ? LIMIT 1");
                            $cn->execute([$classId]);
                            $className = strtolower(trim((string)$cn->fetchColumn()));
                        } catch (Throwable $e) {
                            $className = '';
                        }
                    }

                    $isOrdinary = $className !== '' && (strpos($className, 'ordinary') !== false || strpos($className, 'common') !== false);
                    $isPreference = $className !== '' && (strpos($className, 'preference') !== false || strpos($className, 'preferred') !== false);

                    if ($isOrdinary && $sellerType === 'founder' && $buyerType !== 'founder') {
                        $stmt = $pdo->prepare("INSERT INTO equity_user_profiles (username, user_type, ordinary_votes_shareholder, ordinary_votes_founder) VALUES (?, 'shareholder', 1, 1000)
                            ON DUPLICATE KEY UPDATE user_type = VALUES(user_type), ordinary_votes_shareholder = VALUES(ordinary_votes_shareholder), ordinary_votes_founder = VALUES(ordinary_votes_founder)");
                        $stmt->execute([$user]);
                        $buyerType = 'shareholder';
                    }

                    if ($isPreference && !($sellerType === 'founder' && $buyerType === 'founder')) {
                        $stmt = $pdo->prepare("INSERT INTO equity_user_profiles (username, user_type, ordinary_votes_shareholder, ordinary_votes_founder) VALUES (?, 'shareholder', 1, 1000)
                            ON DUPLICATE KEY UPDATE user_type = VALUES(user_type), ordinary_votes_shareholder = VALUES(ordinary_votes_shareholder), ordinary_votes_founder = VALUES(ordinary_votes_founder)");
                        $stmt->execute([$user]);
                        $buyerType = 'shareholder';

                        try {
                            $stmt = $pdo->prepare("DELETE FROM equity_share_rights_map WHERE username = ? AND class_id = ?");
                            $stmt->execute([$user, $classId]);
                            $stmt = $pdo->prepare("DELETE FROM equity_share_rights WHERE username = ? AND class_id = ?");
                            $stmt->execute([$user, $classId]);
                        } catch (Throwable $e) {
                        }
                    }
                    
                    // 3. Record Transaction (Blockchain Log)
                    $prevHash = '0000000000000000000000000000000000000000000000000000000000000000'; // Simplify for demo
                    $txnHash = hash('sha256', $listing['seller_username'] . $user . $listing['class_id'] . $unitsToBuy . time());
                    
                    $stmt = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type) VALUES (?, ?, ?, ?, ?, ?, ?, 'trade')");
                    $stmt->execute([$prevHash, $txnHash, $listing['class_id'], $listing['seller_username'], $user, $unitsToBuy, $listing['price_per_unit']]);
                    
                    $pdo->commit();
                    $listingType = isset($listing['listing_type']) ? (string)$listing['listing_type'] : 'coin';
                    if ($listingType === 'share') {
                        $_SESSION['mh_equity_flash'] = "Successfully purchased " . number_format((int)($unitsToBuy / $fractionalUnits)) . " shares!";
                    } else {
                        $_SESSION['mh_equity_flash'] = "Successfully purchased " . number_format($unitsToBuy) . " coins!";
                    }
                    header('Location: /hub/equity/manage.php');
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Trade failed: " . $e->getMessage();
                }
            } else {
                $pdo->rollBack();
                $message = "Listing not available or insufficient units.";
            }
        }
        // REMOVE LISTING (Cancel Sell Order)
        if ($action === 'remove') {
            $listingId = (int)$_POST['listing_id'];
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM equity_market WHERE id = ? AND seller_username = ? AND status = 'active' FOR UPDATE");
                $stmt->execute([$listingId, $user]);
                $listing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$listing) {
                    throw new RuntimeException('Listing not found or already processed.');
                }

                $unitsReturn = (int)($listing['units_available'] ?? 0);
                $classId = (int)($listing['class_id'] ?? 0);
                if ($unitsReturn < 1 || $classId < 1) {
                    throw new RuntimeException('Listing invalid.');
                }
                
                $stmt = $pdo->prepare("UPDATE equity_market SET status = 'cancelled', units_available = 0, display_qty = 0 WHERE id = ? AND status = 'active'");
                $stmt->execute([$listingId]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Failed to cancel listing.');
                }
                
                $stmt = $pdo->prepare("UPDATE equity_ledger SET units_owned = units_owned + ? WHERE username = ? AND class_id = ?");
                $stmt->execute([$unitsReturn, $user, $classId]);
                
                $pdo->commit();
                $_SESSION['mh_equity_flash'] = "Listing removed. " . number_format($unitsReturn) . " equity coins returned to your holdings.";
                header('Location: /hub/equity/manage.php');
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = "Error removing listing: " . $e->getMessage();
            }
        }

        if ($action === 'convert_preference') {
            $qtyShares = isset($_POST['qty_shares']) ? (int)$_POST['qty_shares'] : 0;
            $ack = isset($_POST['acknowledged']) ? (string)$_POST['acknowledged'] : '';
            $decl = isset($_POST['declaration_text']) ? (string)$_POST['declaration_text'] : '';

            if ($qtyShares < 1) {
                $message = 'Invalid conversion quantity.';
            } elseif ($ack !== '1') {
                $message = 'Acknowledgement required.';
            } elseif (trim($decl) === '') {
                $message = 'Acknowledgement text missing.';
            } else {
                $prefId = 0;
                try {
                    $stmt = $pdo->query("SELECT id, name FROM equity_classes");
                    $cls = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cls as $c) {
                        $n = isset($c['name']) ? strtolower(trim((string)$c['name'])) : '';
                        if ($n !== '' && (strpos($n, 'preference') !== false || strpos($n, 'preferred') !== false)) {
                            $prefId = (int)($c['id'] ?? 0);
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    $prefId = 0;
                }

                if ($prefId < 1) {
                    $message = 'Preference Equity class not found.';
                } else {
                    $fractionalUnits = 400;
                    try {
                        $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
                        $stmt->execute([$prefId]);
                        $v = $stmt->fetchColumn();
                        $fractionalUnits = max(1, (int)$v);
                    } catch (Throwable $e) {
                        $fractionalUnits = 400;
                    }
                    $unitsToConvert = $qtyShares * $fractionalUnits;

                    $currentUserType = 'shareholder';
                    try {
                        $stmt = $pdo->prepare("SELECT user_type FROM equity_user_profiles WHERE username = ? LIMIT 1");
                        $stmt->execute([$user]);
                        $v = $stmt->fetchColumn();
                        if (is_string($v) && trim($v) !== '') {
                            $currentUserType = strtolower(trim((string)$v));
                        }
                    } catch (Throwable $e) {
                        $currentUserType = 'shareholder';
                    }

                    $ordinaryId = 0;
                    try {
                        $stmt = $pdo->query("SELECT id, name FROM equity_classes");
                        $cls = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($cls as $c) {
                            $n = isset($c['name']) ? strtolower(trim((string)$c['name'])) : '';
                            if ($n !== '' && (strpos($n, 'ordinary') !== false || strpos($n, 'common') !== false)) {
                                $ordinaryId = (int)($c['id'] ?? 0);
                                break;
                            }
                        }
                    } catch (Throwable $e) {
                        $ordinaryId = 0;
                    }

                    $toClassId = $ordinaryId;

                    if ($toClassId < 1) {
                        $message = 'Ordinary Equity class not available.';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            $stmt = $pdo->prepare("SELECT units_owned FROM equity_ledger WHERE username = ? AND class_id = ? FOR UPDATE");
                            $stmt->execute([$user, $prefId]);
                            $prefUnitsOwned = (int)$stmt->fetchColumn();
                            $prefFullShares = (int)floor(max(0, $prefUnitsOwned) / $fractionalUnits);
                            if ($prefFullShares < $qtyShares) {
                                throw new RuntimeException('Insufficient full Preference shares available for conversion.');
                            }

                            $stmt = $pdo->prepare("UPDATE equity_ledger SET units_owned = units_owned - ? WHERE username = ? AND class_id = ?");
                            $stmt->execute([$unitsToConvert, $user, $prefId]);

                            $stmt = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
                            $stmt->execute([$user, $toClassId, $unitsToConvert, $unitsToConvert]);

                            $stmt = $pdo->prepare("UPDATE equity_classes SET total_shares = GREATEST(total_shares - ?, 0) WHERE id = ?");
                            $stmt->execute([$qtyShares, $prefId]);

                            $stmt = $pdo->prepare("UPDATE equity_classes SET total_shares = GREATEST(total_shares + ?, 0) WHERE id = ?");
                            $stmt->execute([$qtyShares, $toClassId]);

                            $remaining = $qtyShares;
                            $stmt = $pdo->prepare("SELECT id, shares_covered FROM equity_share_rights WHERE username = ? AND class_id = ? AND shares_covered > 0 ORDER BY id ASC FOR UPDATE");
                            $stmt->execute([$user, $prefId]);
                            $rightsLots = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($rightsLots as $r) {
                                if ($remaining <= 0) break;
                                $rid = (int)($r['id'] ?? 0);
                                $covered = (int)($r['shares_covered'] ?? 0);
                                if ($rid < 1 || $covered < 1) continue;
                                $take = min($covered, $remaining);
                                $upd = $pdo->prepare("UPDATE equity_share_rights SET shares_covered = shares_covered - ? WHERE id = ? AND username = ?");
                                $upd->execute([$take, $rid, $user]);
                                $remaining -= $take;
                            }
                            $pdo->prepare("DELETE FROM equity_share_rights WHERE username = ? AND class_id = ? AND shares_covered <= 0")->execute([$user, $prefId]);

                            $declText = $decl;
                            $declHash = hash('sha256', $declText);
                            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;
                            $stmt = $pdo->prepare("INSERT INTO equity_conversions (username, from_class_id, to_class_id, shares_converted, units_converted, declaration_text, declaration_sha256, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$user, $prefId, $toClassId, $qtyShares, $unitsToConvert, $declText, $declHash, $ip]);

                            $stmt = $pdo->query("SELECT txn_hash FROM equity_transactions ORDER BY id DESC LIMIT 1");
                            $lastHash = $stmt->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000';
                            $timestamp = date('Y-m-d H:i:s');
                            $pricePerUnitPref = function_exists('mh_equity_get_price_per_unit') ? (float)mh_equity_get_price_per_unit($pdo, $prefId, $timestamp) : 0.00;
                            $dataString = $lastHash . 'CONVERT_BURN' . $user . $prefId . $unitsToConvert . $timestamp;
                            $newHash = hash('sha256', $dataString);
                            $stmt = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, ?, NULL, ?, ?, 'convert_burn', ?)");
                            $stmt->execute([$lastHash, $newHash, $prefId, $user, $unitsToConvert, $pricePerUnitPref, $timestamp]);

                            $lastHash = $newHash;
                            $pricePerUnitTrade = function_exists('mh_equity_get_price_per_unit') ? (float)mh_equity_get_price_per_unit($pdo, $toClassId, $timestamp) : 0.00;
                            $dataString = $lastHash . 'CONVERT_MINT' . $user . $toClassId . $unitsToConvert . $timestamp;
                            $newHash = hash('sha256', $dataString);
                            $stmt = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, NULL, ?, ?, ?, 'convert_mint', ?)");
                            $stmt->execute([$lastHash, $newHash, $toClassId, $user, $unitsToConvert, $pricePerUnitTrade, $timestamp]);

                            $pdo->commit();
                            $_SESSION['mh_equity_flash'] = "Successfully converted $qtyShares Preference shares to Ordinary Equity.";
                            header('Location: /hub/equity/manage.php');
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $message = 'Conversion failed: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    // --- Fetch Data ---
    // 1. User Holdings
    $holdings = $pdo->prepare("
        SELECT l.*, c.name as class_name, c.total_shares, c.fractional_units_per_share, c.price_per_share 
        FROM equity_ledger l 
        JOIN equity_classes c ON l.class_id = c.id 
        WHERE l.username = ?
    ");
    $holdings->execute([$user]);
    $myAssets = $holdings->fetchAll();

    $userProfile = [
        'user_type' => 'shareholder',
        'ordinary_votes_shareholder' => 1,
        'ordinary_votes_founder' => 1000,
    ];
    try {
        $stmt = $pdo->prepare("SELECT user_type, ordinary_votes_shareholder, ordinary_votes_founder FROM equity_user_profiles WHERE username = ? LIMIT 1");
        $stmt->execute([$user]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($p)) {
            if (isset($p['user_type']) && is_string($p['user_type']) && trim($p['user_type']) !== '') {
                $userProfile['user_type'] = trim((string)$p['user_type']);
            }
            if (isset($p['ordinary_votes_shareholder'])) {
                $userProfile['ordinary_votes_shareholder'] = (int)$p['ordinary_votes_shareholder'];
            }
            if (isset($p['ordinary_votes_founder'])) {
                $userProfile['ordinary_votes_founder'] = (int)$p['ordinary_votes_founder'];
            }
        }
    } catch (Throwable $e) {
    }

    $equityHoldings = [];
    $coinHoldings = [];
    $tradeableCoinHoldings = [];
    $totalEstimatedValue = 0.0;
    $totalVotes = 0;
    $preferenceRights = [];
    $preferenceSharesFull = 0;
    $preferenceSharesFractionRemainder = 0;
    $preferenceClassId = 0;
    foreach ($myAssets as $row) {
        $cn = strtolower(trim((string)($row['class_name'] ?? '')));
        if ($preferenceClassId === 0 && ($cn !== '' && (strpos($cn, 'preference') !== false || strpos($cn, 'preferred') !== false))) {
            $preferenceClassId = (int)($row['class_id'] ?? 0);
        }
    }
    foreach ($myAssets as $row) {
        $unitsOwned = (int)($row['units_owned'] ?? 0);
        $fractionalUnits = max(1, (int)($row['fractional_units_per_share'] ?? 400));
        $classId = (int)($row['class_id'] ?? 0);
        $className = (string)($row['class_name'] ?? '');
        $authorizedShares = (int)($row['total_shares'] ?? 0);
        $pricePerShare = (float)($row['price_per_share'] ?? 0);
        if ($classId > 0 && function_exists('mh_equity_get_price_per_share')) {
            $pricePerShare = (float)mh_equity_get_price_per_share($pdo, $classId);
        }
        $pricePerCoin = $pricePerShare / $fractionalUnits;

        $sharesOwned = intdiv(max(0, $unitsOwned), $fractionalUnits);
        $coinsOwned = max(0, $unitsOwned) - ($sharesOwned * $fractionalUnits);
        $coinKey = function_exists('mh_equity_coin_key') ? mh_equity_coin_key($className) : 'equity-coin';
        $shareEquivalent = max(0, $unitsOwned) / $fractionalUnits;
        $pctOwned = $authorizedShares > 0 ? (($shareEquivalent / $authorizedShares) * 100.0) : null;

        $votes = 0;
        $cn = strtolower(trim($className));
        $isOrdinary = $cn !== '' && (strpos($cn, 'ordinary') !== false || strpos($cn, 'common') !== false);
        $userType = strtolower(trim((string)($userProfile['user_type'] ?? 'shareholder')));
        if ($isOrdinary) {
            if ($userType === 'founder') {
                $votesPerShare = max(0, (int)($userProfile['ordinary_votes_founder'] ?? 1000));
                $votes = $sharesOwned * $votesPerShare;
            } elseif ($userType === 'shareholder') {
                $votesPerShare = max(0, (int)($userProfile['ordinary_votes_shareholder'] ?? 1));
                $votes = $sharesOwned * $votesPerShare;
            } else {
                $votes = 0;
            }
        }
        $totalVotes += $votes;

        if ($sharesOwned > 0) {
            $equityHoldings[] = [
                'class_id' => $classId,
                'class_name' => $className,
                'coin_key' => $coinKey,
                'shares' => $sharesOwned,
                'pct_owned' => $pctOwned,
                'votes' => $votes,
                'value' => $sharesOwned * $pricePerShare,
                'price_per_share' => $pricePerShare,
            ];
        }
        if ($coinsOwned > 0) {
            $coinHoldings[] = [
                'class_id' => $classId,
                'class_name' => $className,
                'coin_key' => $coinKey,
                'coins' => $coinsOwned,
                'pct_owned' => $pctOwned,
                'votes' => 0,
                'value' => $coinsOwned * $pricePerCoin,
                'price_per_coin' => $pricePerCoin,
            ];
        }

        if ($unitsOwned > 0) {
            $tradeableCoinHoldings[] = [
                'class_id' => $classId,
                'class_name' => $className,
                'coin_key' => $coinKey,
                'coins' => max(0, $unitsOwned),
                'share_equivalent' => $shareEquivalent,
                'pct_owned' => $pctOwned,
                'votes' => 0,
                'value' => max(0, $unitsOwned) * $pricePerCoin,
                'price_per_coin' => $pricePerCoin,
            ];
        }

        $totalEstimatedValue += $unitsOwned * $pricePerCoin;
    }

        if ($preferenceClassId > 0) {
        $preferenceUnitsPerShare = 400;
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(units_owned, 0) FROM equity_ledger WHERE username = ? AND class_id = ? LIMIT 1");
            $stmt->execute([$user, $preferenceClassId]);
            $u = (int)$stmt->fetchColumn();
            try {
                $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$preferenceClassId]);
                $preferenceUnitsPerShare = max(1, (int)$stmt->fetchColumn());
            } catch (Throwable $e) {
                $preferenceUnitsPerShare = 400;
            }
            $preferenceSharesFull = (int)floor(max(0, $u) / $preferenceUnitsPerShare);
            $preferenceSharesFractionRemainder = max(0, $u) % $preferenceUnitsPerShare;
        } catch (Throwable $e) {}

        try {
                $defs = [];
                $drows = $pdo->query("SELECT code, name FROM equity_rights_definitions")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($drows as $d) {
                    $code = isset($d['code']) ? (string)$d['code'] : '';
                    $name = isset($d['name']) ? (string)$d['name'] : $code;
                    if ($code !== '') $defs[$code] = $name;
                }

                $stmt = $pdo->prepare("SELECT right_code FROM equity_share_rights_map WHERE username = ? AND class_id = ? ORDER BY right_code ASC");
                $stmt->execute([$user, $preferenceClassId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $names = [];
                foreach ($rows as $r) {
                    $code = isset($r['right_code']) ? trim((string)$r['right_code']) : '';
                    if ($code === '') continue;
                    $names[] = $defs[$code] ?? $code;
                }
                $names = array_values(array_unique($names));
                $preferenceRights = $names;
        } catch (Throwable $e) {}
    }

    // 2. Market Listings (Active, excluding own)
    $market = $pdo->prepare("
        SELECT m.*, c.name as class_name, c.fractional_units_per_share
        FROM equity_market m 
        JOIN equity_classes c ON m.class_id = c.id 
        WHERE m.status = 'active' AND m.units_available > 0 AND m.seller_username != ?
        ORDER BY m.price_per_unit ASC
    ");
    $market->execute([$user]);
    $listings = $market->fetchAll();
    
    // 3. My Active Listings
    $myListings = $pdo->prepare("
        SELECT m.*, c.name as class_name, c.fractional_units_per_share
        FROM equity_market m 
        JOIN equity_classes c ON m.class_id = c.id 
        WHERE m.seller_username = ? AND m.status = 'active'
    ");
    $myListings->execute([$user]);
    $selling = $myListings->fetchAll();

    $issuerAvailability = [];
    try {
        $classesAll = $pdo->query("SELECT id, name, total_shares, fractional_units_per_share FROM equity_classes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($classesAll as $c) {
            $classId = (int)($c['id'] ?? 0);
            if ($classId < 1) continue;
            $className = isset($c['name']) ? trim((string)$c['name']) : '';
            if (strcasecmp($className, 'Trading Equity') === 0) continue;
            $authorized = (int)($c['total_shares'] ?? 0);
            $issued = (float)mh_equity_get_total_shares_issued($pdo, $classId);
            $available = $authorized > 0 ? max(0, $authorized - (int)floor($issued)) : 0;
            $pps = function_exists('mh_equity_get_price_per_share') ? (float)mh_equity_get_price_per_share($pdo, $classId) : (float)($c['price_per_share'] ?? 0);
            $unitsPerShare = max(1, (int)($c['fractional_units_per_share'] ?? 400));
            $ppu = function_exists('mh_equity_get_price_per_unit') ? (float)mh_equity_get_price_per_unit($pdo, $classId) : ($pps / (float)$unitsPerShare);
            $issuerAvailability[] = [
                'class_id' => $classId,
                'class_name' => $className,
                'authorized' => $authorized,
                'issued' => $issued,
                'available' => $available,
                'price_per_share' => $pps,
                'price_per_unit' => $ppu,
                'units_per_share' => $unitsPerShare,
            ];
        }
    } catch (Throwable $e) {
        $issuerAvailability = [];
    }

    $primaryOrders = [];
    try {
        $stmt = $pdo->prepare("SELECT o.*, c.name AS class_name FROM equity_primary_orders o JOIN equity_classes c ON c.id = o.class_id WHERE o.buyer_username = ? AND o.status <> 'cancelled' ORDER BY o.id DESC LIMIT 50");
        $stmt->execute([$user]);
        $primaryOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $primaryOrders = [];
    }

    $brexBankDetails = mh_equity_secret_store_get('brex_bank_details');

    $bidTypes = [
        'preferred_equity' => 'Preferred Equity',
        'ordinary_equity' => 'Ordinary Equity',
        'preferred_equity_coins' => 'Preferred Equity Coins',
        'ordinary_equity_coins' => 'Ordinary Equity Coins',
        'culture_meme_coins' => 'Culture / Meme Coins',
        'stable_coins' => 'Stable Coins',
    ];

    $hasIssuerEquityAvailable = false;
    foreach ($issuerAvailability as $r) {
        $authorized = (int)($r['authorized'] ?? 0);
        $available = (int)($r['available'] ?? 0);
        if ($authorized > 0 && $available > 0) {
            $hasIssuerEquityAvailable = true;
            break;
        }
    }
    $hasPrimaryOrders = is_array($primaryOrders) && !empty($primaryOrders);

    $bidOffers = [];
    try {
        $stmt = $pdo->query("SELECT o.id, o.username, o.offer_type, o.qty, o.offered_price, o.status, o.created_at,
            COALESCE(p.user_type, 'shareholder') AS user_type,
            COALESCE(ap.accept_count, 0) AS accept_count
            FROM equity_bid_offers o
            LEFT JOIN equity_user_profiles p ON p.username = o.username
            LEFT JOIN (
                SELECT offer_id, SUM(CASE WHEN decision = 'accept' THEN 1 ELSE 0 END) AS accept_count
                FROM equity_bid_offer_approvals
                GROUP BY offer_id
            ) ap ON ap.offer_id = o.id
            WHERE LOWER(o.status) = 'active'
            ORDER BY o.id DESC
            LIMIT 200");
        $bidOffers = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $bidOffers = [];
    }

    $myBidCounts = ['accepted' => 0, 'awaiting' => 0];
    try {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM equity_bid_offers WHERE username = ? GROUP BY status");
        $stmt->execute([$user]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $st = strtolower(trim((string)($r['status'] ?? '')));
            $c = (int)($r['c'] ?? 0);
            if ($st === 'accepted') $myBidCounts['accepted'] = $c;
            if ($st === 'active') $myBidCounts['awaiting'] = $c;
        }
    } catch (Throwable $e) {
        $myBidCounts = ['accepted' => 0, 'awaiting' => 0];
    }

    $bidCountsByUser = [];
    try {
        $usersInList = [];
        foreach ($bidOffers as $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u !== '') $usersInList[$u] = true;
        }
        $usersInList = array_keys($usersInList);
        if (!empty($usersInList)) {
            $ph = implode(',', array_fill(0, count($usersInList), '?'));
            $stmt = $pdo->prepare("SELECT o.username,
                SUM(CASE WHEN LOWER(o.status) = 'accepted' THEN 1 ELSE 0 END) AS accepted,
                SUM(CASE WHEN LOWER(o.status) = 'active' THEN 1 ELSE 0 END) AS awaiting,
                SUM(CASE WHEN LOWER(o.status) = 'active' AND COALESCE(ap.accept_count, 0) > 0 THEN 1 ELSE 0 END) AS awaiting_approved
                FROM equity_bid_offers o
                LEFT JOIN (
                    SELECT offer_id, SUM(CASE WHEN decision = 'accept' THEN 1 ELSE 0 END) AS accept_count
                    FROM equity_bid_offer_approvals
                    GROUP BY offer_id
                ) ap ON ap.offer_id = o.id
                WHERE o.username IN ($ph)
                GROUP BY o.username");
            $stmt->execute($usersInList);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $u = isset($r['username']) ? trim((string)$r['username']) : '';
                if ($u === '') continue;
                $bidCountsByUser[$u] = [
                    'accepted' => (int)($r['accepted'] ?? 0),
                    'awaiting' => (int)($r['awaiting'] ?? 0),
                    'awaiting_approved' => (int)($r['awaiting_approved'] ?? 0),
                ];
            }
        }
    } catch (Throwable $e) {
        $bidCountsByUser = [];
    }

} catch (Exception $e) {
    die("System Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Equity | Meta Humans</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root { --primary: #00d4ff; --bg-dark: #1a1a1a; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); }
        body.equity-page main.main-content { color: #e0e0e0; font-family: var(--font-primary, 'Rajdhani', sans-serif); overflow-x: hidden; }
        .container { max-width: 1200px; width: 100%; margin: 0 auto; padding: 40px 20px; box-sizing: border-box; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden; }
        .table-wrap { width: 100%; max-width: 100%; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; margin-top: 15px; }
        table { width: 100%; min-width: 640px; border-collapse: collapse; margin-top: 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        .btn { background: var(--primary); color: #000; padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        .btn:hover { opacity: 0.9; }
        .input-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #aaa; font-size: 0.9rem; }
        input, select { background: rgba(0,0,0,0.3); border: 1px solid var(--border); color: #fff; padding: 10px; width: 100%; border-radius: 4px; box-sizing: border-box; }
        .alert { background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: var(--primary); padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        @media (max-width: 768px) {
            .container { padding: 18px 12px 28px; }
            .panel { padding: 16px; border-radius: 10px; }
            h1 { font-size: 1.5rem; line-height: 1.2; margin-bottom: 18px; }
            h2 { font-size: 1.05rem; line-height: 1.3; }
            th, td { padding: 10px 8px; font-size: 0.85rem; }
            .btn { padding: 10px 12px; font-size: 0.75rem; white-space: nowrap; }
            table { min-width: 560px; }
        }
        @media (max-width: 480px) {
            .container { padding: 14px 10px 24px; }
            .panel { padding: 14px; }
            table { min-width: 520px; }
            th, td { padding: 9px 7px; font-size: 0.8rem; }
        }
    </style>
</head>
<body class="equity-page">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="container">
        <h1>My Equity Management</h1>
        <?php if ($message): ?>
            <div class="alert"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Summary Block -->
        <div class="panel" style="background: linear-gradient(45deg, rgba(0,212,255,0.1), rgba(0,0,0,0)); border-color: var(--primary);">
            <h2 style="margin-bottom: 10px;">Portfolio Summary</h2>
            <div style="font-size: 2rem; font-family: 'Orbitron', sans-serif; color: #fff;">
                $<?php echo number_format($totalEstimatedValue, 2); ?>
            </div>
            <div style="color: #aaa; font-size: 0.9rem;">Total Estimated Value</div>
            <div style="margin-top: 12px; display:flex; gap: 24px; flex-wrap: wrap; color:#aaa; font-size: 0.95rem;">
                <div><span style="color:var(--primary); font-weight:700;">Status:</span> <?php echo htmlspecialchars((string)($userProfile['user_type'] ?? 'shareholder')); ?></div>
                <div><span style="color:var(--primary); font-weight:700;">Votes:</span> <?php echo number_format((int)$totalVotes); ?></div>
            </div>
        </div>

        <?php if ($hasIssuerEquityAvailable): ?>
            <div class="panel">
                <h2>Acquire Company Equity</h2>
                <div style="color:#aaa; font-size:0.9rem;">
                    Unissued shares are available for purchase. Settlement requires Brex direct deposit with the provided reference, or Stripe Pay Now.
                </div>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Authorized</th>
                            <th>Issued</th>
                            <th>Available</th>
                            <th>Price/Share</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issuerAvailability as $r): ?>
                            <?php
                                $authorized = (int)($r['authorized'] ?? 0);
                                $issued = (float)($r['issued'] ?? 0);
                                $available = (int)($r['available'] ?? 0);
                                $pps = (float)($r['price_per_share'] ?? 0);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($r['class_name'] ?? '')); ?></td>
                                <td><?php echo $authorized > 0 ? number_format($authorized) : 'Not set'; ?></td>
                                <td><?php echo number_format($issued, 2); ?></td>
                                <td><?php echo $authorized > 0 ? number_format($available) : 'N/A'; ?></td>
                                <td>$<?php echo number_format($pps, 2); ?></td>
                                <td>
                                    <?php if ($authorized > 0 && $available > 0): ?>
                                        <form method="POST" style="display:inline-flex; gap:10px; align-items:center;" onsubmit="return window.confirm('I confirm that I want to purchase the company equity.');">
                                            <input type="hidden" name="action" value="primary_order">
                                            <input type="hidden" name="class_id" value="<?php echo (int)($r['class_id'] ?? 0); ?>">
                                            <input type="number" name="qty_shares" min="1" max="<?php echo $available; ?>" value="1" style="width:90px; padding:5px;">
                                            <button type="submit" class="btn" style="font-size:0.8rem;">Request Purchase</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#9aa;">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($issuerAvailability)): ?>
                            <tr><td colspan="6" style="text-align:center; color:#aaa;">No equity classes found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasPrimaryOrders): ?>
            <div class="panel">
                <h2>Company Capital Equity Raise Orders</h2>
                <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
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
                        <?php foreach ($primaryOrders as $o): ?>
                            <tr>
                                <td><?php echo (int)($o['id'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars((string)($o['class_name'] ?? '')); ?></td>
                                <td><?php echo number_format((int)($o['shares_requested'] ?? 0)); ?></td>
                                <td>$<?php echo number_format((float)($o['total_amount'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars((string)($o['payment_reference'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($o['status'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($o['created_at'] ?? '')); ?></td>
                                <td>
                                    <?php if ((string)($o['status'] ?? '') === 'pending_payment'): ?>
                                        <div style="display:inline-flex; gap: 10px; align-items:center; flex-wrap: wrap;">
                                            <button
                                                type="button"
                                                class="btn stripePayBtn"
                                                style="font-size:0.8rem;"
                                                data-order-id="<?php echo (int)($o['id'] ?? 0); ?>"
                                                data-ref="<?php echo htmlspecialchars((string)($o['payment_reference'] ?? ''), ENT_QUOTES); ?>"
                                                data-amount="<?php echo htmlspecialchars((string)number_format((float)($o['total_amount'] ?? 0), 2, '.', ''), ENT_QUOTES); ?>"
                                            >PAY NOW</button>
                                            <form method="POST" style="display:inline-block;" onsubmit="return window.confirm('Are you sure you want to delete this order? If you made a payment there is no refund/trace mechanism and you will lose the money paid if not reconciled.');">
                                                <input type="hidden" name="action" value="cancel_primary_order">
                                                <input type="hidden" name="order_id" value="<?php echo (int)($o['id'] ?? 0); ?>">
                                                <button type="submit" class="btn" style="font-size:0.8rem; background:rgba(255,80,80,.14); border:1px solid rgba(255,80,80,.35); color:rgba(255,180,180,.95);">Delete</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9aa;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($primaryOrders)): ?>
                            <tr><td colspan="8" style="text-align:center; color:#aaa;">No orders yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <div style="margin-top: 10px; color:#aaa; font-size:0.9rem; display:flex; gap: 12px; align-items:center; flex-wrap: wrap;">
                    <div>Deposit to Brex and include the order reference in the transfer memo/description.</div>
                    <button type="button" class="btn" id="brexBankDetailsBtn" style="font-size:0.8rem;">Brex Bank Details</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$hasIssuerEquityAvailable && !$hasPrimaryOrders): ?>
            <div class="panel">
                <h2>Company Equity</h2>
                <div style="color:#aaa; font-size:0.95rem; line-height: 1.35;">
                    No Company Equity is available at the moment.
                </div>
                <div style="margin-top: 12px;">
                    <button type="button" class="btn openBidModalBtn" style="font-size:0.85rem;">Equity/Coins Bid</button>
                </div>
            </div>
        <?php endif; ?>

        <div id="stripePayModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; padding: 24px;">
            <div style="max-width:720px; margin: 0 auto; background: rgba(20,20,20,0.98); border:1px solid var(--border); border-radius: 12px; padding: 18px;">
                <div style="display:flex; justify-content: space-between; align-items:center; gap: 12px;">
                    <h2 style="margin:0;">Stripe Payment</h2>
                    <button type="button" class="btn" id="stripePayCloseBtn" style="font-size:0.8rem; background:rgba(255,255,255,0.08); border:1px solid var(--border); color:#fff;">Close</button>
                </div>
                <div style="margin-top: 12px; color:#e0e0e0; line-height:1.45;">
                    <div style="color:#aaa;">Reference: <span id="stripePayRef"></span></div>
                    <div style="color:#aaa;">Amount: $<span id="stripePayAmount"></span></div>
                    <div style="margin-top: 10px; color:#9aa; font-size:0.9rem;">
                        Paying with Stripe will mark the order as paid. Settlement still requires reconciliation.
                    </div>
                </div>
                <div style="margin-top: 14px; display:flex; gap: 10px; justify-content:flex-end; flex-wrap: wrap;">
                    <form method="POST" id="stripePayForm" onsubmit="return window.confirm('I confirm that I want to pay now via Stripe.');">
                        <input type="hidden" name="action" value="stripe_pay_primary_order">
                        <input type="hidden" name="order_id" id="stripePayOrderId" value="">
                        <button type="submit" class="btn" style="font-size:0.85rem;">PAY NOW</button>
                    </form>
                </div>
            </div>
        </div>

        <div id="bidModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; padding:12px; box-sizing:border-box; align-items:center; justify-content:center; overflow:auto; -webkit-overflow-scrolling:touch;">
            <div id="bidModalCard" style="width:min(860px, 100%); max-width:860px; max-height:calc(100vh - 24px); overflow:auto; margin:0 auto; background: rgba(20,20,20,0.98); border:1px solid var(--border); border-radius: 12px; padding: 18px; box-sizing:border-box;">
                <div style="display:flex; justify-content: space-between; align-items:center; gap: 12px;">
                    <h2 style="margin:0;">Equity/Coins Bid</h2>
                    <button type="button" class="btn" id="bidCloseBtn" style="font-size:0.8rem; background:rgba(255,255,255,0.08); border:1px solid var(--border); color:#fff;">Close</button>
                </div>
                <div style="margin-top: 10px; color:#9aa; font-size:0.9rem;">
                    Submit your bid offers below. Offers show publicly in the “Bidding Offers” block.
                </div>
                <form method="POST" style="margin-top: 14px;">
                    <input type="hidden" name="action" value="submit_bid_offer">
                    <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th style="width:160px;">Qty</th>
                                <th style="width:220px;">Per asset price (USD)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bidTypes as $k => $label): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($label); ?></td>
                                    <td><input type="number" name="bid_qty[<?php echo htmlspecialchars($k); ?>]" min="0" step="1" value="0" style="width:140px;"></td>
                                    <td><input type="number" name="bid_price[<?php echo htmlspecialchars($k); ?>]" min="0" step="0.01" value="0" style="width:200px;"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div style="margin-top: 14px; display:flex; justify-content:flex-end;">
                        <button type="submit" class="btn" style="font-size:0.85rem;">Submit Offers</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="brexBankModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; padding: 24px;">
            <div style="max-width:720px; margin: 0 auto; background: rgba(20,20,20,0.98); border:1px solid var(--border); border-radius: 12px; padding: 18px;">
                <div style="display:flex; justify-content: space-between; align-items:center; gap: 12px;">
                    <h2 style="margin:0;">Brex Bank Details</h2>
                    <button type="button" class="btn" id="brexBankCloseBtn" style="font-size:0.8rem; background:rgba(255,255,255,0.08); border:1px solid var(--border); color:#fff;">Close</button>
                </div>
                <div style="margin-top: 12px; color:#e0e0e0; line-height:1.45;">
                    <?php if (is_string($brexBankDetails) && trim($brexBankDetails) !== ''): ?>
                        <?php echo nl2br(htmlspecialchars((string)$brexBankDetails), false); ?>
                    <?php else: ?>
                        <div style="color:#9aa;">Bank details not configured.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Equity Block (Full Shares) -->
        <div class="panel">
            <h2>Equity (Full Shares)</h2>
            <p style="color:#aaa; font-size:0.9rem;">Shares sold in full units.</p>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Shares Owned</th>
                        <th>% Owned</th>
                        <th>Votes</th>
                        <th>Est. Value</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($equityHoldings as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['class_name']); ?></td>
                            <td><?php echo number_format($item['shares']); ?> Shares</td>
                            <td><?php echo $item['pct_owned'] === null ? 'N/A' : number_format((float)$item['pct_owned'], 2) . '%'; ?></td>
                            <td><?php echo number_format((int)($item['votes'] ?? 0)); ?></td>
                            <td>$<?php echo number_format($item['value'], 2); ?></td>
                            <td>
                                <form method="POST" style="display:inline-flex; gap:10px; align-items:center;" onsubmit="return window.confirm('I confirm that I want to sell my equity.');">
                                    <input type="hidden" name="action" value="sell">
                                    <input type="hidden" name="type" value="share">
                                    <input type="hidden" name="class_id" value="<?php echo $item['class_id']; ?>">
                                    <input type="number" name="qty" placeholder="Qty" min="1" max="<?php echo $item['shares']; ?>" style="width:70px; padding:5px;">
                                    <input type="number" name="price" placeholder="Price/Share" step="0.01" value="<?php echo $item['price_per_share']; ?>" style="width:100px; padding:5px;">
                                    <button type="submit" class="btn" style="font-size:0.8rem;">Sell Shares</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($equityHoldings)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#aaa;">No full equity shares owned.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Coin Block (Fractions) -->
        <div class="panel">
            <h2>Equity Coins</h2>
            <p style="color:#aaa; font-size:0.9rem;">Coins are the smallest tradable unit per equity class (coin key is per class).</p>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Coin Key</th>
                        <th>Coins Available</th>
                        <th>Share Equivalent</th>
                        <th>% Owned</th>
                        <th>Votes</th>
                        <th>Est. Value</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tradeableCoinHoldings as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['class_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['coin_key']); ?></td>
                            <td><?php echo number_format($item['coins']); ?></td>
                            <td><?php echo number_format((float)$item['share_equivalent'], 2); ?></td>
                            <td><?php echo $item['pct_owned'] === null ? 'N/A' : number_format((float)$item['pct_owned'], 2) . '%'; ?></td>
                            <td><?php echo number_format((int)($item['votes'] ?? 0)); ?></td>
                            <td>$<?php echo number_format($item['value'], 2); ?></td>
                            <td>
                                <form method="POST" style="display:inline-flex; gap:10px; align-items:center;" onsubmit="return window.confirm('I confirm I want to sell my equity coins and that the action will remove one full share and fractionalize it into the coins as stated on this page and that once a share is fractionalized it will stay fractionalized until the full quantity of coins are sold that make up one share. This action is irreversible.');">
                                    <input type="hidden" name="action" value="sell">
                                    <input type="hidden" name="type" value="coin">
                                    <input type="hidden" name="class_id" value="<?php echo $item['class_id']; ?>">
                                    <input type="number" name="qty" placeholder="Qty" min="1" max="<?php echo $item['coins']; ?>" style="width:90px; padding:5px;">
                                    <input type="number" name="price" placeholder="Price/Coin" step="0.01" value="<?php echo number_format($item['price_per_coin'], 2); ?>" style="width:100px; padding:5px;">
                                    <button type="submit" class="btn" style="font-size:0.8rem;">Sell Coins</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tradeableCoinHoldings)): ?>
                        <tr><td colspan="8" style="text-align:center; color:#aaa;">No equity holdings found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- My Active Listings -->
        <?php if (!empty($selling)): ?>
        <div class="panel">
            <h2>My Equity Listings</h2>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Type</th>
                        <th>Coin Key</th>
                        <th>Qty Listed</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($selling as $list): ?>
                        <?php
                            $listingType = isset($list['listing_type']) ? (string)$list['listing_type'] : 'coin';
                            $unitsPerShare = max(1, (int)($list['fractional_units_per_share'] ?? 400));
                            $coinKey = function_exists('mh_equity_coin_key') ? mh_equity_coin_key((string)($list['class_name'] ?? '')) : 'equity-coin';
                            $qtyListed = $listingType === 'share' ? (int)floor(((int)$list['units_available']) / $unitsPerShare) : (int)$list['units_available'];
                            $displayPrice = $listingType === 'share' ? ((float)$list['price_per_unit'] * (float)$unitsPerShare) : (float)$list['price_per_unit'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($list['class_name']); ?></td>
                            <td><?php echo $listingType === 'share' ? 'Share' : 'Coin'; ?></td>
                            <td><?php echo $listingType === 'coin' ? htmlspecialchars($coinKey) : '—'; ?></td>
                            <td><?php echo number_format($qtyListed); ?></td>
                            <td>$<?php echo number_format($displayPrice, 2); ?></td>
                            <td><?php echo ucfirst($list['status']); ?></td>
                            <td>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="listing_id" value="<?php echo $list['id']; ?>">
                                    <button type="submit" class="btn" style="background: #ff4444; font-size: 0.8rem;">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Marketplace -->
        <div class="panel">
            <h2>Equity / Coins on offer</h2>
            <p style="color:#aaa; font-size:0.9rem;">Equity / Coins available from other users.</p>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Seller</th>
                        <th>Class</th>
                        <th>Type</th>
                        <th>Coin Key</th>
                        <th>Qty Available</th>
                        <th>Price</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($listings as $list): ?>
                        <?php
                            $listingType = isset($list['listing_type']) ? (string)$list['listing_type'] : 'coin';
                            $unitsPerShare = max(1, (int)($list['fractional_units_per_share'] ?? 400));
                            $coinKey = function_exists('mh_equity_coin_key') ? mh_equity_coin_key((string)($list['class_name'] ?? '')) : 'equity-coin';
                            $qtyAvailable = $listingType === 'share' ? (int)floor(((int)$list['units_available']) / $unitsPerShare) : (int)$list['units_available'];
                            $displayPrice = $listingType === 'share' ? ((float)$list['price_per_unit'] * (float)$unitsPerShare) : (float)$list['price_per_unit'];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($list['seller_username']); ?></td>
                            <td><?php echo htmlspecialchars($list['class_name']); ?></td>
                            <td><?php echo $listingType === 'share' ? 'Share' : 'Coin'; ?></td>
                            <td><?php echo $listingType === 'coin' ? htmlspecialchars($coinKey) : '—'; ?></td>
                            <td><?php echo number_format($qtyAvailable); ?></td>
                            <td>$<?php echo number_format($displayPrice, 2); ?></td>
                            <td>
                                <form method="POST">
                                    <input type="hidden" name="action" value="buy">
                                    <input type="hidden" name="listing_id" value="<?php echo $list['id']; ?>">
                                    <?php if ($listingType === 'share'): ?>
                                        <input type="number" name="shares" min="1" max="<?php echo $qtyAvailable; ?>" value="1" style="width:80px; padding:5px; margin-right:10px;">
                                    <?php else: ?>
                                        <input type="number" name="units" min="1" max="<?php echo (int)$list['units_available']; ?>" value="1" style="width:80px; padding:5px; margin-right:10px;">
                                    <?php endif; ?>
                                    <button type="submit" class="btn">Buy</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($listings)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#aaa;">No equity listings currently for sale.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="panel">
            <div style="display:flex; justify-content: space-between; align-items:center; gap: 12px; flex-wrap: wrap;">
                <h2 style="margin:0;">Bidding Offers</h2>
                <div style="display:flex; gap: 12px; align-items:center; flex-wrap: wrap;">
                    <div style="color:#aaa; font-size:0.9rem;">
                        My status: <span style="color:var(--primary); font-weight:700;"><?php echo htmlspecialchars((string)($userProfile['user_type'] ?? 'shareholder')); ?></span> ·
                        My offers: <span style="color:var(--primary); font-weight:700;"><?php echo (int)($myBidCounts['accepted'] ?? 0); ?></span> accepted ·
                        <span style="color:var(--primary); font-weight:700;"><?php echo (int)($myBidCounts['awaiting'] ?? 0); ?></span> awaiting
                    </div>
                    <button type="button" class="btn openBidModalBtn" style="font-size:0.85rem;">Equity/Coins Bid</button>
                </div>
            </div>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Asset</th>
                        <th>Qty</th>
                        <th>Per asset price</th>
                        <th>Total (USD)</th>
                        <th>User</th>
                        <th>User status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bidOffers as $bo): ?>
                        <?php
                            $t = isset($bo['offer_type']) ? (string)$bo['offer_type'] : '';
                            $label = isset($bidTypes[$t]) ? (string)$bidTypes[$t] : $t;
                            $qty = (int)($bo['qty'] ?? 0);
                            $price = (float)($bo['offered_price'] ?? 0);
                            $total = (float)$qty * (float)$price;
                            $ut = (string)($bo['user_type'] ?? 'shareholder');
                            $acceptCount = (int)($bo['accept_count'] ?? 0);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($label); ?></td>
                            <td><?php echo number_format($qty); ?></td>
                            <td>$<?php echo number_format($price, 2); ?></td>
                            <td>$<?php echo number_format($total, 2); ?></td>
                            <td>
                                <?php $ou = (string)($bo['username'] ?? ''); ?>
                                <?php echo htmlspecialchars($ou); ?>
                                <?php
                                    $c = $ou !== '' && isset($bidCountsByUser[$ou]) ? $bidCountsByUser[$ou] : null;
                                    $acc = is_array($c) ? (int)($c['accepted'] ?? 0) : 0;
                                    $aw = is_array($c) ? (int)($c['awaiting'] ?? 0) : 0;
                                    $awAp = is_array($c) ? (int)($c['awaiting_approved'] ?? 0) : 0;
                                ?>
                                <div style="color:#9aa; font-size:0.85rem; margin-top:4px;">My offers: <?php echo $acc; ?> accepted · <?php echo $aw; ?> awaiting · <?php echo $awAp; ?> approved</div>
                                <div style="color:#9aa; font-size:0.85rem; margin-top:2px;">This offer approvals: <?php echo min(2, $acceptCount); ?>/2</div>
                            </td>
                            <td><?php echo htmlspecialchars($ut); ?></td>
                            <td><?php echo htmlspecialchars((string)($bo['created_at'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bidOffers)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#aaa;">No bidding offers yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($preferenceClassId > 0): ?>
        <div class="panel">
            <h2>Preference Equity Rights</h2>
            <p style="color:#aaa; font-size:0.9rem;">Rights attached to full Preference shares. Rights do not apply to fractional coins.</p>

            <?php if (!empty($preferenceRights)): ?>
                <div style="margin-top: 12px; color:#e0e0e0; line-height:1.35;">
                    <?php echo htmlspecialchars(implode(', ', (array)$preferenceRights)); ?>
                </div>
            <?php else: ?>
                <div style="color:#aaa; margin-top: 12px;">No preference rights recorded.</div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Convert Preference → Ordinary Equity</h2>
            <p style="color:#aaa; font-size:0.9rem;">Conversion is allowed for full Preference shares only. Fractional coins cannot be converted.</p>

            <div style="display:flex; gap: 24px; flex-wrap: wrap; color:#aaa; font-size: 0.95rem;">
                <div><span style="color:var(--primary); font-weight:700;">Full Preference shares available:</span> <?php echo number_format((int)$preferenceSharesFull); ?></div>
                <div><span style="color:var(--primary); font-weight:700;">Fractional remainder:</span> <?php echo number_format((int)$preferenceSharesFractionRemainder); ?>/<?php echo (int)($preferenceUnitsPerShare ?? 400); ?></div>
            </div>

            <form method="POST" id="convertPreferenceForm" style="margin-top: 16px;">
                <input type="hidden" name="action" value="convert_preference">
                <input type="hidden" name="acknowledged" id="convertAck" value="0">
                <input type="hidden" name="declaration_text" id="convertDeclaration" value="">

                <div style="display:flex; gap: 12px; align-items:flex-end; flex-wrap: wrap;">
                    <div style="min-width: 220px;">
                        <label>Qty Preference shares to convert</label>
                        <input type="number" name="qty_shares" id="convertQtyShares" min="1" max="<?php echo (int)$preferenceSharesFull; ?>" step="1" value="1">
                    </div>
                    <div>
                        <button type="button" class="btn" id="openConvertModal">Proceed to Convert</button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
    </main>
    <div id="convertModal" style="display:none; position:fixed; inset:0; background: rgba(0,0,0,0.7); z-index: 9999; align-items:center; justify-content:center; padding: 20px;">
        <div style="max-width: 900px; width: 100%; background: #1f1f1f; border: 1px solid rgba(0,212,255,0.25); border-radius: 12px; padding: 22px;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap: 12px;">
                <h2 style="margin:0;">Acknowledgement</h2>
                <button type="button" class="btn" id="closeConvertModal" style="background:#333; color:#fff;">Close</button>
            </div>
            <div style="margin-top: 14px; color:#ddd; line-height: 1.5;">
                <div id="convertDeclarationPreview" style="white-space: pre-wrap;"></div>
            </div>
            <div style="margin-top: 16px; display:flex; gap: 12px; align-items:center;">
                <input type="checkbox" id="convertTick" style="width:auto;">
                <label for="convertTick" style="margin:0;">I confirm and accept this declaration.</label>
            </div>
            <div style="margin-top: 18px; display:flex; justify-content:flex-end; gap: 12px;">
                <button type="button" class="btn" id="confirmConvert" disabled>Convert</button>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const openBtn = document.getElementById('openConvertModal');
            const modal = document.getElementById('convertModal');
            const closeBtn = document.getElementById('closeConvertModal');
            const tick = document.getElementById('convertTick');
            const confirmBtn = document.getElementById('confirmConvert');
            const qtyEl = document.getElementById('convertQtyShares');
            const form = document.getElementById('convertPreferenceForm');
            const ack = document.getElementById('convertAck');
            const decl = document.getElementById('convertDeclaration');
            const preview = document.getElementById('convertDeclarationPreview');

            function currentName() {
                const name = <?php echo json_encode($fullName !== '' ? $fullName : $user); ?>;
                return name || <?php echo json_encode($user); ?>;
            }

            function buildDeclaration(qty) {
                const n = currentName();
                const q = String(qty);
                return `I, ${n} hereby irrevocably withdraw the qty ${q} preference stock inserted and acknowledge that once I convert the preference equity to ordinary equity, that I loose all rights attached to the preference equity, including profit sharing and preference rights. I acknowledge that the converted shares become Ordinary shares. I confirm that the coins available will be transferred with the share in its entirety.`;
            }

            function open() {
                if (!modal || !qtyEl || !preview) return;
                if (!window.confirm('I confirm that I am aware of the implications of converting my preference equity to ordinary equity and that this is irreversible once accepted.')) return;
                const qty = parseInt(qtyEl.value || '0', 10) || 0;
                if (qty < 1) return;
                const max = parseInt(qtyEl.max || '0', 10) || 0;
                if (max > 0 && qty > max) return;
                const txt = buildDeclaration(qty);
                preview.textContent = txt;
                if (decl) decl.value = txt;
                if (ack) ack.value = '0';
                if (tick) tick.checked = false;
                if (confirmBtn) confirmBtn.disabled = true;
                modal.style.display = 'flex';
            }

            function close() {
                if (!modal) return;
                modal.style.display = 'none';
            }

            if (openBtn) openBtn.addEventListener('click', open);
            if (closeBtn) closeBtn.addEventListener('click', close);
            if (tick && confirmBtn && ack) {
                tick.addEventListener('change', function () {
                    confirmBtn.disabled = !tick.checked;
                    ack.value = tick.checked ? '1' : '0';
                });
            }
            if (confirmBtn && form) {
                confirmBtn.addEventListener('click', function () {
                    if (confirmBtn.disabled) return;
                    form.submit();
                });
            }
            if (modal) {
                modal.addEventListener('click', function (e) {
                    if (e.target === modal) close();
                });
            }

            const brexBtn = document.getElementById('brexBankDetailsBtn');
            const brexModal = document.getElementById('brexBankModal');
            const brexClose = document.getElementById('brexBankCloseBtn');

            function brexOpen() {
                if (!brexModal) return;
                brexModal.style.display = 'block';
            }

            function brexCloseFn() {
                if (!brexModal) return;
                brexModal.style.display = 'none';
            }

            if (brexBtn) brexBtn.addEventListener('click', brexOpen);
            if (brexClose) brexClose.addEventListener('click', brexCloseFn);
            if (brexModal) {
                brexModal.addEventListener('click', function (e) {
                    if (e.target === brexModal) brexCloseFn();
                });
            }

            const stripeModal = document.getElementById('stripePayModal');
            const stripeClose = document.getElementById('stripePayCloseBtn');
            const stripeRef = document.getElementById('stripePayRef');
            const stripeAmt = document.getElementById('stripePayAmount');
            const stripeOrderId = document.getElementById('stripePayOrderId');

            function stripeCloseFn() {
                if (!stripeModal) return;
                stripeModal.style.display = 'none';
            }

            window.openStripePayModal = function (orderId, ref, amount) {
                if (!stripeModal) return;
                if (stripeRef) stripeRef.textContent = String(ref || '');
                if (stripeAmt) stripeAmt.textContent = (typeof amount === 'number' ? amount.toFixed(2) : String(amount || ''));
                if (stripeOrderId) stripeOrderId.value = String(orderId || '');
                stripeModal.style.display = 'block';
            };

            document.querySelectorAll('.stripePayBtn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const oid = parseInt(btn.getAttribute('data-order-id') || '0', 10) || 0;
                    const ref = btn.getAttribute('data-ref') || '';
                    const amt = parseFloat(btn.getAttribute('data-amount') || '0') || 0;
                    window.openStripePayModal(oid, ref, amt);
                });
            });

            if (stripeClose) stripeClose.addEventListener('click', stripeCloseFn);
            if (stripeModal) {
                stripeModal.addEventListener('click', function (e) {
                    if (e.target === stripeModal) stripeCloseFn();
                });
            }

            const bidModal = document.getElementById('bidModal');
            const bidModalCard = document.getElementById('bidModalCard');
            const bidClose = document.getElementById('bidCloseBtn');

            function bidOpen() {
                if (!bidModal) return;
                bidModal.style.display = 'flex';
                bidModal.scrollTop = 0;
                if (bidModalCard) bidModalCard.scrollTop = 0;
            }

            function bidCloseFn() {
                if (!bidModal) return;
                bidModal.style.display = 'none';
            }

            document.querySelectorAll('.openBidModalBtn').forEach(function (btn) {
                btn.addEventListener('click', bidOpen);
            });
            if (bidClose) bidClose.addEventListener('click', bidCloseFn);
            if (bidModal) {
                bidModal.addEventListener('click', function (e) {
                    if (e.target === bidModal) bidCloseFn();
                });
            }
        })();
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
