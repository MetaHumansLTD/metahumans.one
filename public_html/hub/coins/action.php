<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';
require_once dirname(__DIR__, 2) . '/auth/persona_registry.php';
if (is_file(dirname(__DIR__, 2) . '/auth/tokenomics.php')) {
    require_once dirname(__DIR__, 2) . '/auth/tokenomics.php';
}
if (is_file(dirname(__DIR__) . '/equity/db.php')) {
    require_once dirname(__DIR__) . '/equity/db.php';
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!function_exists('mh_culture_env')) {
    function mh_culture_env(string $key): string {
        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $envFile = getenv('MH_ENV_FILE');
            if (!is_string($envFile) || trim($envFile) === '') {
                $envFile = '/home/onemeta/.env';
            }
            $envFile = trim((string)$envFile);
            if ($envFile !== '' && is_file($envFile) && is_readable($envFile)) {
                $lines = @file($envFile, FILE_IGNORE_NEW_LINES);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        if (!is_string($line)) continue;
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) continue;
                        $pos = strpos($line, '=');
                        if ($pos === false) continue;
                        $k = trim(substr($line, 0, $pos));
                        $v = trim(substr($line, $pos + 1));
                        if ($k === '') continue;
                        if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) $v = substr($v, 1, -1);
                        if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) $v = substr($v, 1, -1);
                        if (getenv($k) === false) {
                            @putenv($k . '=' . $v);
                            $_ENV[$k] = $v;
                        }
                    }
                }
            }
        }
        $v = getenv($key);
        if (!is_string($v) || trim($v) === '') {
            $v = (string)($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
        }
        return trim((string)$v);
    }
}

if (!function_exists('mh_culture_secret_store_path')) {
    function mh_culture_secret_store_path(): string {
        $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
        $p = $paths && method_exists($paths, 'getSecureFilePath') ? $paths->getSecureFilePath('config/tokenomics-secrets.json', false) : null;
        if (is_string($p) && $p !== '') return $p;
        $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
        $base = $base !== '' ? rtrim($base, '/') : '/data';
        return $base . '/config/tokenomics-secrets.json';
    }
}

if (!function_exists('mh_culture_secret_store_key')) {
    function mh_culture_secret_store_key(): string {
        if (function_exists('cue_autoload')) {
            cue_autoload('paths');
            cue_autoload('security');
        }
        $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
        $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        return is_string($raw) ? trim($raw) : '';
    }
}

if (!function_exists('mh_culture_secret_store_get')) {
    function mh_culture_secret_store_get(string $key): string {
        $p = mh_culture_secret_store_path();
        if (!is_file($p) || !is_readable($p)) return '';
        $raw = @file_get_contents($p);
        $cfg = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($cfg)) return '';
        $enc = isset($cfg[$key]) && is_string($cfg[$key]) ? trim((string)$cfg[$key]) : '';
        if ($enc === '') return '';
        $k = mh_culture_secret_store_key();
        if ($k === '' || !function_exists('security_decryptValue')) return '';
        $plain = security_decryptValue($enc, $k);
        return is_string($plain) ? trim((string)$plain) : '';
    }
}

if (!function_exists('mh_culture_stripe_secret')) {
    function mh_culture_stripe_secret(): string {
        $k = mh_culture_env('STRIPE_SECRET_KEY');
        if ($k !== '') return $k;
        return mh_culture_secret_store_get('stripe_secret_key');
    }
}

if (!function_exists('mh_culture_already_credited')) {
    function mh_culture_already_credited(PDO $pdoTok, string $username, int $assetClassId, string $referenceId): bool {
        $stmt = $pdoTok->prepare("SELECT id FROM mh_asset_transactions WHERE username = ? AND asset_class_id = ? AND service_key = 'onramp:stripe:culture' AND reference_id = ? LIMIT 1");
        $stmt->execute([$username, $assetClassId, $referenceId]);
        return $stmt->fetchColumn() !== false;
    }
}

if (!function_exists('mh_culture_ensure_orders_schema')) {
    function mh_culture_ensure_orders_schema(PDO $pdoTok): void {
        if (function_exists('mh_tokenomics_ensure_schema')) {
            mh_tokenomics_ensure_schema($pdoTok);
        }
        $pdoTok->exec("CREATE TABLE IF NOT EXISTS mh_stripe_checkout_orders (
            session_id VARCHAR(255) NOT NULL PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            kind VARCHAR(32) NOT NULL DEFAULT 'mtk',
            status VARCHAR(16) NOT NULL DEFAULT 'created',
            amount_usd DECIMAL(12,2) NULL,
            tokens_expected BIGINT NULL,
            qty_expected BIGINT NULL,
            asset_key VARCHAR(64) NULL,
            ticker VARCHAR(16) NULL,
            payment_status VARCHAR(32) NULL,
            billing_name VARCHAR(255) NULL,
            billing_email VARCHAR(255) NULL,
            billing_match TINYINT NULL,
            flagged TINYINT NOT NULL DEFAULT 0,
            flag_reason VARCHAR(64) NULL,
            last_error VARCHAR(255) NULL,
            meta_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_username_status (username, status, created_at),
            KEY idx_kind_status (kind, status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $pdoTok->query("SELECT qty_expected FROM mh_stripe_checkout_orders LIMIT 1"); } catch (Throwable) { try { $pdoTok->exec("ALTER TABLE mh_stripe_checkout_orders ADD COLUMN qty_expected BIGINT NULL AFTER tokens_expected"); } catch (Throwable) {} }
        try { $pdoTok->query("SELECT asset_key FROM mh_stripe_checkout_orders LIMIT 1"); } catch (Throwable) { try { $pdoTok->exec("ALTER TABLE mh_stripe_checkout_orders ADD COLUMN asset_key VARCHAR(64) NULL AFTER qty_expected"); } catch (Throwable) {} }
        try { $pdoTok->query("SELECT ticker FROM mh_stripe_checkout_orders LIMIT 1"); } catch (Throwable) { try { $pdoTok->exec("ALTER TABLE mh_stripe_checkout_orders ADD COLUMN ticker VARCHAR(16) NULL AFTER asset_key"); } catch (Throwable) {} }
    }
}

if (!function_exists('mh_culture_order_upsert')) {
    function mh_culture_order_upsert(PDO $pdoTok, string $sessionId, string $username, array $fields): void {
        $sessionId = trim($sessionId);
        $username = trim($username);
        if ($sessionId === '' || $username === '') return;
        mh_culture_ensure_orders_schema($pdoTok);

        $cols = ['session_id', 'username'];
        $vals = [$sessionId, $username];
        $set = [];

        $allowed = [
            'kind', 'status', 'amount_usd', 'tokens_expected', 'qty_expected', 'asset_key', 'ticker', 'payment_status',
            'billing_name', 'billing_email', 'billing_match', 'flagged', 'flag_reason',
            'last_error', 'meta_json'
        ];
        foreach ($allowed as $k) {
            if (!array_key_exists($k, $fields)) continue;
            $cols[] = $k;
            $vals[] = $fields[$k];
            $set[] = $k . " = VALUES(" . $k . ")";
        }
        $sql = "INSERT INTO mh_stripe_checkout_orders (" . implode(',', $cols) . ")
                VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")
                ON DUPLICATE KEY UPDATE " . implode(', ', $set);
        $pdoTok->prepare($sql)->execute($vals);
    }
}

if (!function_exists('mh_culture_stripe_fetch_session')) {
    function mh_culture_stripe_fetch_session(string $stripeSecretKey, string $sessionId): array {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw new RuntimeException('missing_session_id');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId) . '?expand[]=customer_details');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');
        $result = curl_exec($ch);
        $ch = null;
        $session = json_decode((string)$result, true);
        if (!is_array($session)) throw new RuntimeException('stripe_invalid_response');
        return $session;
    }
}

if (!function_exists('mh_culture_verify_and_credit_session')) {
    function mh_culture_verify_and_credit_session(PDO $pdoTok, string $username, string $stripeSecretKey, string $sessionId): array {
        $username = trim($username);
        $sessionId = trim($sessionId);
        if ($username === '' || $sessionId === '') throw new RuntimeException('invalid_payload');

        $session = mh_culture_stripe_fetch_session($stripeSecretKey, $sessionId);
        $clientRef = isset($session['client_reference_id']) ? trim((string)$session['client_reference_id']) : '';
        $metaUser = '';
        if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['username'])) {
            $metaUser = trim((string)$session['metadata']['username']);
        }
        $refMatch = ($clientRef !== '' && strcasecmp($clientRef, $username) === 0) || ($metaUser !== '' && strcasecmp($metaUser, $username) === 0);
        if (!$refMatch) {
            try {
                $st = $pdoTok->prepare("SELECT username FROM mh_stripe_checkout_orders WHERE session_id = ? LIMIT 1");
                $st->execute([$sessionId]);
                $existingUser = $st->fetchColumn();
                if (is_string($existingUser) && $existingUser !== '' && strcasecmp(trim($existingUser), $username) === 0) {
                    try {
                        $pdoTok->prepare("DELETE FROM mh_stripe_checkout_orders WHERE session_id = ? AND username = ? LIMIT 1")->execute([$sessionId, $username]);
                    } catch (Throwable) {}
                }
            } catch (Throwable) {}
            return ['success' => false, 'error' => ($clientRef === '' && $metaUser === '') ? 'session_owner_missing' : 'session_owner_mismatch'];
        }

        $paymentStatus = (string)($session['payment_status'] ?? '');
        $amount = isset($session['amount_total']) ? ((float)$session['amount_total'] / 100.0) : 0.0;
        $coins = 0;
        $assetKey = 'culture:champcoin';
        $usdPerCoin = 0.25;
        $ticker = '';
        if (isset($session['metadata']) && is_array($session['metadata'])) {
            if (isset($session['metadata']['coins'])) $coins = (int)$session['metadata']['coins'];
            if (isset($session['metadata']['asset_key']) && is_string($session['metadata']['asset_key']) && trim((string)$session['metadata']['asset_key']) !== '') {
                $assetKey = trim((string)$session['metadata']['asset_key']);
            }
            if (isset($session['metadata']['usd_per_coin'])) $usdPerCoin = (float)$session['metadata']['usd_per_coin'];
            if (isset($session['metadata']['ticker']) && is_string($session['metadata']['ticker'])) $ticker = trim((string)$session['metadata']['ticker']);
        }
        if ($coins <= 0 && $amount > 0) {
            $coins = (int)floor($amount / max(0.000001, (float)$usdPerCoin));
        }
        if ($coins <= 0) {
            mh_culture_order_upsert($pdoTok, $sessionId, $username, [
                'kind' => 'culture',
                'status' => 'failed',
                'last_error' => 'invalid_coin_amount',
                'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            ]);
            return ['success' => false, 'error' => 'invalid_coin_amount'];
        }
        if ($usdPerCoin <= 0.0 && $amount > 0.0 && $coins > 0) {
            $usdPerCoin = $amount / (float)$coins;
        }

        $billingName = null;
        $billingEmail = null;
        if (isset($session['customer_details']) && is_array($session['customer_details'])) {
            $bn = $session['customer_details']['name'] ?? null;
            $be = $session['customer_details']['email'] ?? null;
            $billingName = is_string($bn) && trim($bn) !== '' ? trim((string)$bn) : null;
            $billingEmail = is_string($be) && trim($be) !== '' ? trim((string)$be) : null;
        }

        $realFirst = null;
        $realLast = null;
        try {
            $pdoReg = function_exists('mh_persona_registry_pdo') ? mh_persona_registry_pdo() : null;
            if ($pdoReg instanceof PDO && function_exists('mh_user_directory_get')) {
                $row = mh_user_directory_get($pdoReg, $username);
                if (is_array($row)) {
                    $rf = isset($row['real_first_name']) ? trim((string)$row['real_first_name']) : '';
                    $rl = isset($row['real_last_name']) ? trim((string)$row['real_last_name']) : '';
                    $realFirst = $rf !== '' ? $rf : null;
                    $realLast = $rl !== '' ? $rl : null;
                }
            }
        } catch (Throwable) {}
        $billingMatch = function_exists('mh_identity_billing_name_matches_user')
            ? mh_identity_billing_name_matches_user($billingName, $realFirst, $realLast)
            : null;
        $flagged = $billingMatch === false ? 1 : 0;
        $flagReason = $billingMatch === false ? 'billing_name_mismatch' : null;

        mh_culture_order_upsert($pdoTok, $sessionId, $username, [
            'kind' => 'culture',
            'status' => ($paymentStatus === 'paid') ? 'paid' : 'created',
            'amount_usd' => $amount > 0 ? number_format($amount, 2, '.', '') : null,
            'qty_expected' => $coins,
            'asset_key' => $assetKey,
            'ticker' => $ticker !== '' ? $ticker : null,
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            'billing_name' => $billingName,
            'billing_email' => $billingEmail,
            'billing_match' => is_bool($billingMatch) ? ($billingMatch ? 1 : 0) : null,
            'flagged' => $flagged,
            'flag_reason' => $flagReason,
            'last_error' => null,
            'meta_json' => json_encode([
                'amount_usd' => $amount,
                'session_id' => $sessionId,
                'asset_key' => $assetKey,
                'ticker' => $ticker,
                'coins' => $coins,
                'usd_per_coin' => (float)$usdPerCoin,
            ], JSON_UNESCAPED_SLASHES),
        ]);

        if ($paymentStatus !== 'paid') {
            return ['success' => false, 'error' => 'payment_not_verified', 'payment_status' => $paymentStatus];
        }

        if (function_exists('mh_tokenomics_seed_culture_coins')) {
            mh_tokenomics_seed_culture_coins($pdoTok);
        }
        $assetClassId = function_exists('mh_tokenomics_get_asset_class_id') ? mh_tokenomics_get_asset_class_id($pdoTok, $assetKey) : 0;
        if ($assetClassId < 1) {
            mh_culture_order_upsert($pdoTok, $sessionId, $username, [
                'kind' => 'culture',
                'status' => 'failed',
                'last_error' => 'asset_class_missing',
            ]);
            return ['success' => false, 'error' => 'asset_class_missing'];
        }

        if (!mh_culture_already_credited($pdoTok, $username, $assetClassId, $sessionId)) {
            mh_tokenomics_apply_delta($pdoTok, $username, $assetClassId, $coins, 'onramp:stripe:culture', $sessionId, [
                'amount_usd' => $amount,
                'session_id' => $sessionId,
                'asset_key' => $assetKey,
                'billing_name' => $billingName,
                'billing_email' => $billingEmail,
                'billing_match' => $billingMatch,
            ]);
        }

        try {
            if (function_exists('getEquityConnection')) {
                $pdoEq = getEquityConnection();
                if ($pdoEq instanceof PDO) {
                    $pdoEq->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) && trim((string)$_SESSION['mh_tenant_id']) !== ''
                        ? trim((string)$_SESSION['mh_tenant_id'])
                        : ('user:' . $username);
                    $userId = isset($_SESSION['mh_user_internal_id']) ? (int)$_SESSION['mh_user_internal_id'] : null;
                    $personaName = isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
                    $personaId = isset($_SESSION['mh_persona_tenant_id']) && is_string($_SESSION['mh_persona_tenant_id']) && trim((string)$_SESSION['mh_persona_tenant_id']) !== ''
                        ? trim((string)$_SESSION['mh_persona_tenant_id'])
                        : ($personaName !== '' ? ('persona:' . $personaName) : null);
                    $discountPct = 0.0;
                    $discountUsd = 0.0;
                    $metaJson = json_encode([
                        'stripe_session_id' => $sessionId,
                        'payment_status' => $session['payment_status'] ?? null,
                        'amount_total' => $session['amount_total'] ?? null,
                        'currency' => $session['currency'] ?? null,
                        'metadata' => $session['metadata'] ?? null,
                        'billing_name' => $billingName,
                        'billing_email' => $billingEmail,
                        'billing_match' => $billingMatch,
                        'flagged' => $flagged === 1,
                        'flag_reason' => $flagReason,
                    ], JSON_UNESCAPED_SLASHES);

                    $stmt = $pdoEq->prepare("
                        INSERT INTO equity_culture_coin_orders
                            (stripe_session_id, payment_provider, tenant_id, username, user_id, persona_id, persona_name, asset_key, ticker, qty, amount_paid_usd, usd_per_unit, discount_pct, discount_usd, status, meta_json)
                        VALUES
                            (?, 'stripe', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'credited', ?)
                        ON DUPLICATE KEY UPDATE
                            updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([
                        $sessionId,
                        $tenantId,
                        $username,
                        $userId > 0 ? $userId : null,
                        $personaId !== '' ? $personaId : null,
                        $personaName !== '' ? $personaName : null,
                        $assetKey,
                        $ticker !== '' ? $ticker : null,
                        $coins,
                        $amount,
                        (float)$usdPerCoin,
                        (float)$discountPct,
                        (float)$discountUsd,
                        is_string($metaJson) && $metaJson !== '' ? $metaJson : null,
                    ]);
                }
            }
        } catch (Throwable) {}

        mh_culture_order_upsert($pdoTok, $sessionId, $username, [
            'kind' => 'culture',
            'status' => 'credited',
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
        ]);
        return ['success' => true, 'flagged' => $flagged === 1];
    }
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$username = trim((string)$_SESSION['mh_auth_user']);

try {
    $stripeSecretKey = mh_culture_stripe_secret();
    if (($action === 'buy_champcoin' || $action === 'buy_supercoin' || $action === 'verify_culture_payment' || $action === 'my_culture_orders' || $action === 'verify_my_culture_order' || $action === 'reconcile_my_culture_orders') && $stripeSecretKey === '') {
        throw new RuntimeException('missing_stripe_secret');
    }

    if ($action === 'my_culture_orders') {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_culture_ensure_orders_schema($pdoTok);
        $stmt = $pdoTok->prepare("SELECT session_id, status, amount_usd, qty_expected, asset_key, ticker, payment_status, flagged, flag_reason, last_error, created_at, updated_at FROM mh_stripe_checkout_orders WHERE username = ? AND kind = 'culture' ORDER BY created_at DESC LIMIT 25");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'orders' => is_array($rows) ? $rows : []], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'buy_champcoin') {
        $amountUsd = (int)($_POST['amount'] ?? 0);
        if ($amountUsd < 100) {
            echo json_encode(['success' => false, 'error' => 'Minimum purchase amount is $100']);
            exit;
        }
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdoTok);
        $ids = mh_tokenomics_seed_culture_coins($pdoTok);
        $champId = (int)($ids['champcoin'] ?? 0);
        if ($champId < 1) throw new RuntimeException('champcoin_class_missing');
        $price = function_exists('mh_tokenomics_get_current_price_usd') ? mh_tokenomics_get_current_price_usd($pdoTok, $champId) : null;
        $usdPerCoin = (is_float($price) && $price > 0) ? $price : 0.25;
        $coins = (int)floor(((float)$amountUsd) / max(0.000001, (float)$usdPerCoin));
        if ($coins < 400) {
            echo json_encode(['success' => false, 'error' => 'Minimum reservation is 400 coins ($100)']);
            exit;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');

        $host = $_SERVER['HTTP_HOST'] ?? 'metahumans.one';
        $postFields = http_build_query([
            'payment_method_types' => ['card'],
            'billing_address_collection' => 'auto',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $coins . ' Champion Coins (mhc) (ChampCoin) Reservation'],
                    'unit_amount' => $amountUsd * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => 'https://' . $host . '/hub/coins/culture.php?payment_success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://' . $host . '/hub/coins/culture.php?payment_cancel=true',
            'client_reference_id' => $username,
            'metadata' => [
                'username' => $username,
                'asset_key' => 'culture:champcoin',
                'ticker' => 'mhc',
                'coins' => $coins,
                'amount_usd' => $amountUsd,
                'usd_per_coin' => $usdPerCoin,
            ],
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('stripe_connect_error');
        }
        $ch = null;

        $response = json_decode((string)$result, true);
        if (!is_array($response) || isset($response['error'])) {
            throw new RuntimeException('stripe_error');
        }
        if (!isset($response['url']) || !is_string($response['url'])) {
            throw new RuntimeException('stripe_missing_redirect');
        }
        $sessionId = isset($response['id']) ? trim((string)$response['id']) : '';
        if ($sessionId !== '') {
            try {
                $pdoTok = mh_tokenomics_get_tokenomics_pdo();
                mh_culture_order_upsert($pdoTok, $sessionId, $username, [
                    'kind' => 'culture',
                    'status' => 'created',
                    'amount_usd' => number_format((float)$amountUsd, 2, '.', ''),
                    'qty_expected' => $coins,
                    'asset_key' => 'culture:champcoin',
                    'ticker' => 'mhc',
                    'payment_status' => null,
                    'flagged' => 0,
                    'flag_reason' => null,
                    'last_error' => null,
                    'meta_json' => json_encode([
                        'amount_usd' => (float)$amountUsd,
                        'asset_key' => 'culture:champcoin',
                        'ticker' => 'mhc',
                        'coins' => $coins,
                        'usd_per_coin' => (float)$usdPerCoin,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {}
        }
        echo json_encode(['success' => true, 'redirect_url' => (string)$response['url']]);
        exit;
    }

    if ($action === 'buy_supercoin') {
        $amountUsd = (int)($_POST['amount'] ?? 0);
        if ($amountUsd < 100) {
            echo json_encode(['success' => false, 'error' => 'Minimum purchase amount is $100']);
            exit;
        }
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdoTok);
        $ids = mh_tokenomics_seed_culture_coins($pdoTok);
        $superId = (int)($ids['supercoin'] ?? 0);
        if ($superId < 1) throw new RuntimeException('supercoin_class_missing');

        $issueDate = '';
        $stmt = $pdoTok->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$superId]);
        $raw = $stmt->fetchColumn();
        if ($raw !== false && is_string($raw) && trim($raw) !== '') {
            $meta = json_decode($raw, true);
            if (is_array($meta) && isset($meta['issue_date'])) $issueDate = trim((string)$meta['issue_date']);
        }
        if ($issueDate !== '') {
            $ts = strtotime($issueDate);
            if ($ts !== false && time() < (int)$ts) {
                echo json_encode(['success' => false, 'error' => 'Super Coin is not available yet']);
                exit;
            }
        }

        $price = function_exists('mh_tokenomics_get_current_price_usd') ? mh_tokenomics_get_current_price_usd($pdoTok, $superId) : null;
        if (!is_float($price) || $price <= 0) {
            echo json_encode(['success' => false, 'error' => 'Super Coin is not priced for sale']);
            exit;
        }
        $usdPerCoin = (float)$price;
        $coins = (int)floor(((float)$amountUsd) / max(0.000001, (float)$usdPerCoin));
        if ($coins < 1) {
            echo json_encode(['success' => false, 'error' => 'Amount too small']);
            exit;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');

        $host = $_SERVER['HTTP_HOST'] ?? 'metahumans.one';
        $postFields = http_build_query([
            'payment_method_types' => ['card'],
            'billing_address_collection' => 'auto',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $coins . ' Super Coins (mhs) (SuperCoin)'],
                    'unit_amount' => $amountUsd * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => 'https://' . $host . '/hub/coins/culture.php?payment_success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://' . $host . '/hub/coins/culture.php?payment_cancel=true',
            'client_reference_id' => $username,
            'metadata' => [
                'username' => $username,
                'asset_key' => 'culture:supercoin',
                'ticker' => 'mhs',
                'coins' => $coins,
                'amount_usd' => $amountUsd,
                'usd_per_coin' => $usdPerCoin,
            ],
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('stripe_connect_error');
        }
        $ch = null;

        $response = json_decode((string)$result, true);
        if (!is_array($response) || isset($response['error'])) {
            throw new RuntimeException('stripe_error');
        }
        if (!isset($response['url']) || !is_string($response['url'])) {
            throw new RuntimeException('stripe_missing_redirect');
        }
        $sessionId = isset($response['id']) ? trim((string)$response['id']) : '';
        if ($sessionId !== '') {
            try {
                $pdoTok = mh_tokenomics_get_tokenomics_pdo();
                mh_culture_order_upsert($pdoTok, $sessionId, $username, [
                    'kind' => 'culture',
                    'status' => 'created',
                    'amount_usd' => number_format((float)$amountUsd, 2, '.', ''),
                    'qty_expected' => $coins,
                    'asset_key' => 'culture:supercoin',
                    'ticker' => 'mhs',
                    'payment_status' => null,
                    'flagged' => 0,
                    'flag_reason' => null,
                    'last_error' => null,
                    'meta_json' => json_encode([
                        'amount_usd' => (float)$amountUsd,
                        'asset_key' => 'culture:supercoin',
                        'ticker' => 'mhs',
                        'coins' => $coins,
                        'usd_per_coin' => (float)$usdPerCoin,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {}
        }
        echo json_encode(['success' => true, 'redirect_url' => (string)$response['url']]);
        exit;
    }

    if ($action === 'verify_culture_payment') {
        $sessionId = isset($_POST['session_id']) ? (string)$_POST['session_id'] : '';
        $sessionId = trim($sessionId);
        if ($sessionId === '') throw new RuntimeException('missing_session_id');
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        $res = mh_culture_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sessionId);
        if (isset($res['success']) && $res['success'] === true) {
            echo json_encode(['success' => true, 'flagged' => (bool)($res['flagged'] ?? false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $err = isset($res['error']) ? (string)$res['error'] : 'Payment not verified';
        echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'verify_my_culture_order') {
        $sessionId = isset($_POST['session_id']) ? (string)$_POST['session_id'] : '';
        $sessionId = trim($sessionId);
        if ($sessionId === '') throw new RuntimeException('missing_session_id');
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        $res = mh_culture_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sessionId);
        if (isset($res['success']) && $res['success'] === true) {
            echo json_encode(['success' => true, 'flagged' => (bool)($res['flagged'] ?? false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $err = isset($res['error']) ? (string)$res['error'] : 'verify_failed';
        echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reconcile_my_culture_orders') {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_culture_ensure_orders_schema($pdoTok);
        $stmt = $pdoTok->prepare("SELECT session_id FROM mh_stripe_checkout_orders WHERE username = ? AND kind = 'culture' AND status <> 'credited' ORDER BY created_at DESC LIMIT 25");
        $stmt->execute([$username]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $checked = 0;
        $credited = 0;
        $failed = 0;
        foreach ($ids as $sid) {
            $sid = is_string($sid) ? trim($sid) : '';
            if ($sid === '') continue;
            $checked++;
            try {
                $r = mh_culture_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sid);
                if (isset($r['success']) && $r['success'] === true) {
                    $credited++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }
        echo json_encode(['success' => true, 'checked' => $checked, 'credited' => $credited, 'failed' => $failed], JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
