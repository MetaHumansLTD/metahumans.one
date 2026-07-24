<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/auth_functions.php';
require_once dirname(__DIR__) . '/persona_registry.php';

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

function mh_genesis_stripe_secret(): string
{
    $k = mh_genesis_env('STRIPE_SECRET_KEY');
    if ($k !== '') return $k;
    return mh_genesis_secret_store_get('stripe_secret_key');
}

function mh_genesis_utility_usd_per_token(): float
{
    try {
        if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) return 0.0;
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        if (!$pdo instanceof PDO) return 0.0;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (function_exists('mh_tokenomics_ensure_schema')) {
            mh_tokenomics_ensure_schema($pdo);
        }
        $utilityId = function_exists('mh_tokenomics_seed_utility_token') ? (int)mh_tokenomics_seed_utility_token($pdo) : 0;
        if ($utilityId < 1) return 0.0;
        $stmt = $pdo->prepare("SELECT price_usd_per_unit FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$utilityId]);
        $price = (float)$stmt->fetchColumn();
        return $price > 0 ? $price : 0.0;
    } catch (Throwable) {
        return 0.0;
    }
}

function mh_genesis_utility_bonus_scale(): array
{
    $scale = [
        'start_usd' => 100,
        'base_bonus_pct' => 5.0,
        'step_usd' => 50,
        'step_bonus_pct' => 1.0,
    ];
    try {
        if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) return $scale;
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        if (!$pdo instanceof PDO) return $scale;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (function_exists('mh_tokenomics_ensure_schema')) {
            mh_tokenomics_ensure_schema($pdo);
        }
        $utilityId = function_exists('mh_tokenomics_seed_utility_token') ? (int)mh_tokenomics_seed_utility_token($pdo) : 0;
        if ($utilityId < 1) return $scale;
        $stmt = $pdo->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$utilityId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || !is_string($raw) || trim($raw) === '') return $scale;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['bonus_scale']) || !is_array($decoded['bonus_scale'])) return $scale;
        $bs = $decoded['bonus_scale'];
        if (isset($bs['start_usd'])) $scale['start_usd'] = max(1, (int)$bs['start_usd']);
        if (isset($bs['base_bonus_pct'])) $scale['base_bonus_pct'] = max(0.0, min(100.0, (float)$bs['base_bonus_pct']));
        if (isset($bs['step_usd'])) $scale['step_usd'] = max(1, (int)$bs['step_usd']);
        if (isset($bs['step_bonus_pct'])) $scale['step_bonus_pct'] = max(0.0, min(100.0, (float)$bs['step_bonus_pct']));
        return $scale;
    } catch (Throwable) {
        return $scale;
    }
}

function mh_genesis_bonus_pct_for_amount(float $amountUsd, array $scale): float
{
    $startUsd = isset($scale['start_usd']) ? (int)$scale['start_usd'] : 100;
    $basePct = isset($scale['base_bonus_pct']) ? (float)$scale['base_bonus_pct'] : 5.0;
    $stepUsd = isset($scale['step_usd']) ? (int)$scale['step_usd'] : 50;
    $stepPct = isset($scale['step_bonus_pct']) ? (float)$scale['step_bonus_pct'] : 1.0;

    $startUsd = max(1, $startUsd);
    $stepUsd = max(1, $stepUsd);
    $basePct = max(0.0, min(100.0, $basePct));
    $stepPct = max(0.0, min(100.0, $stepPct));

    if ($amountUsd < (float)$startUsd) return 0.0;
    $steps = (int)floor(((float)$amountUsd - (float)$startUsd) / (float)$stepUsd);
    $pct = max(0.0, $basePct + ((float)$steps * $stepPct));
    return min(20.0, $pct);
}

function mh_genesis_calc_tokens_bundle_from_usd(float $amountUsd): array
{
    $p = mh_genesis_utility_usd_per_token();
    if ($p <= 0) {
        $p = 49.0 / 1500.0;
    }
    $baseTokens = (int)floor($amountUsd / $p);
    $baseTokens = max(1, $baseTokens);
    $scale = mh_genesis_utility_bonus_scale();
    $bonusPct = mh_genesis_bonus_pct_for_amount($amountUsd, $scale);
    $bonusTokens = (int)floor(((float)$baseTokens) * ($bonusPct / 100.0));
    $bonusTokens = max(0, $bonusTokens);
    $totalTokens = $baseTokens + $bonusTokens;
    return [
        'usd_per_token' => $p,
        'base_tokens' => $baseTokens,
        'bonus_pct' => $bonusPct,
        'bonus_tokens' => $bonusTokens,
        'total_tokens' => $totalTokens,
        'scale' => $scale,
    ];
}

function mh_genesis_secret_store_path(): string
{
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $p = $paths && method_exists($paths, 'getSecureFilePath') ? $paths->getSecureFilePath('config/tokenomics-secrets.json', false) : null;
    if (is_string($p) && $p !== '') return $p;
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/config/tokenomics-secrets.json';
}

function mh_genesis_secret_store_key(): string
{
    if (function_exists('cue_autoload')) {
        cue_autoload('paths');
        cue_autoload('security');
    }
    $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
    $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
    return is_string($raw) ? trim($raw) : '';
}

function mh_genesis_secret_store_get(string $key): string
{
    $p = mh_genesis_secret_store_path();
    if (!is_file($p) || !is_readable($p)) return '';
    $raw = @file_get_contents($p);
    $cfg = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($cfg)) return '';
    $enc = isset($cfg[$key]) && is_string($cfg[$key]) ? trim((string)$cfg[$key]) : '';
    if ($enc === '') return '';
    $k = mh_genesis_secret_store_key();
    if ($k === '' || !function_exists('security_decryptValue')) return '';
    $plain = security_decryptValue($enc, $k);
    return is_string($plain) ? trim((string)$plain) : '';
}

function mh_genesis_tenant_pdo(): ?PDO
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_getContextAwareConnection')) {
        return null;
    }
    try {
        $pdo = database_getContextAwareConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_user_onboarding (
            username VARCHAR(255) NOT NULL PRIMARY KEY,
            genesis_status INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

function mh_genesis_set_status(PDO $pdo, string $username, int $status): void
{
    $pdo->prepare("INSERT INTO mh_user_onboarding (username, genesis_status) VALUES (?, ?) ON DUPLICATE KEY UPDATE genesis_status=VALUES(genesis_status)")
        ->execute([$username, $status]);
    $_SESSION['mh_genesis_status'] = $status;
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (function_exists('database_getConnectionById')) {
            $pdoBio = database_getConnectionById('biometrics');
            if ($pdoBio instanceof PDO) {
                $pdoBio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdoBio->prepare("UPDATE users SET genesis_status = ? WHERE username = ?")->execute([$status, $username]);
            }
        }
    } catch (Throwable) {}
}

function mh_genesis_already_credited(PDO $pdoTok, string $username, int $assetClassId, string $referenceId): bool
{
    $stmt = $pdoTok->prepare("SELECT id FROM mh_asset_transactions WHERE username = ? AND asset_class_id = ? AND service_key = 'onramp:stripe' AND reference_id = ? LIMIT 1");
    $stmt->execute([$username, $assetClassId, $referenceId]);
    return $stmt->fetchColumn() !== false;
}

function mh_genesis_ensure_stripe_orders_schema(PDO $pdoTok): void
{
    mh_tokenomics_ensure_schema($pdoTok);
    $pdoTok->exec("CREATE TABLE IF NOT EXISTS mh_stripe_checkout_orders (
        session_id VARCHAR(255) NOT NULL PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'mtk',
        status VARCHAR(16) NOT NULL DEFAULT 'created',
        amount_usd DECIMAL(12,2) NULL,
        tokens_expected BIGINT NULL,
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
}

function mh_genesis_order_upsert(PDO $pdoTok, string $sessionId, string $username, array $fields): void
{
    $sessionId = trim($sessionId);
    $username = trim($username);
    if ($sessionId === '' || $username === '') return;
    mh_genesis_ensure_stripe_orders_schema($pdoTok);

    $cols = ['session_id', 'username'];
    $vals = [$sessionId, $username];
    $set = [];

    $allowed = [
        'kind', 'status', 'amount_usd', 'tokens_expected', 'payment_status',
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

function mh_genesis_stripe_fetch_session(string $stripeSecretKey, string $sessionId): array
{
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

function mh_genesis_stripe_list_sessions(string $stripeSecretKey, int $limit = 100, ?string $startingAfter = null): array
{
    $limit = max(1, min(100, (int)$limit));
    $url = 'https://api.stripe.com/v1/checkout/sessions?limit=' . $limit;
    if (is_string($startingAfter) && trim($startingAfter) !== '') {
        $url .= '&starting_after=' . rawurlencode(trim((string)$startingAfter));
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');
    $result = curl_exec($ch);
    $ch = null;
    $payload = json_decode((string)$result, true);
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        throw new RuntimeException('stripe_invalid_response');
    }
    return $payload;
}

function mh_genesis_import_recent_orders_for_user(PDO $pdoTok, string $username, string $stripeSecretKey, int $maxPages = 2): array
{
    $username = trim($username);
    if ($username === '') return ['imported' => 0, 'matched' => 0];
    mh_genesis_ensure_stripe_orders_schema($pdoTok);

    $imported = 0;
    $matched = 0;
    $startingAfter = null;
    $maxPages = max(1, min(5, (int)$maxPages));

    for ($page = 0; $page < $maxPages; $page++) {
        $payload = mh_genesis_stripe_list_sessions($stripeSecretKey, 100, $startingAfter);
        $data = $payload['data'];
        $hasMore = !empty($payload['has_more']);

        if (!is_array($data) || count($data) === 0) break;

        foreach ($data as $session) {
            if (!is_array($session)) continue;
            $sid = trim((string)($session['id'] ?? ''));
            if ($sid === '') continue;
            $startingAfter = $sid;

            $ref = isset($session['client_reference_id']) ? trim((string)$session['client_reference_id']) : '';
            $metaUser = '';
            if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['username'])) {
                $metaUser = trim((string)$session['metadata']['username']);
            }
            if (strcasecmp($ref, $username) !== 0 && strcasecmp($metaUser, $username) !== 0) continue;
            $matched++;

            $paymentStatus = (string)($session['payment_status'] ?? '');
            $amount = isset($session['amount_total']) ? ((float)$session['amount_total'] / 100.0) : 0.0;
            $tokens = 0;
            $baseTokens = 0;
            $bonusTokens = 0;
            $bonusPct = 0.0;
            if (isset($session['metadata']) && is_array($session['metadata'])) {
                if (isset($session['metadata']['tokens'])) $tokens = (int)$session['metadata']['tokens'];
                if (isset($session['metadata']['base_tokens'])) $baseTokens = (int)$session['metadata']['base_tokens'];
                if (isset($session['metadata']['bonus_tokens'])) $bonusTokens = (int)$session['metadata']['bonus_tokens'];
                if (isset($session['metadata']['bonus_pct'])) $bonusPct = (float)$session['metadata']['bonus_pct'];
            }
            if (($tokens <= 0 || $baseTokens <= 0) && $amount > 0) {
                $bundle = mh_genesis_calc_tokens_bundle_from_usd((float)$amount);
                $tokens = (int)($bundle['total_tokens'] ?? 0);
                $baseTokens = (int)($bundle['base_tokens'] ?? 0);
                $bonusTokens = (int)($bundle['bonus_tokens'] ?? 0);
                $bonusPct = (float)($bundle['bonus_pct'] ?? 0.0);
            }

            mh_genesis_order_upsert($pdoTok, $sid, $username, [
                'kind' => 'mtk',
                'status' => ($paymentStatus === 'paid') ? 'paid' : 'created',
                'amount_usd' => $amount > 0 ? number_format($amount, 2, '.', '') : null,
                'tokens_expected' => $tokens > 0 ? $tokens : null,
                'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
                'last_error' => null,
                'meta_json' => json_encode([
                    'amount_usd' => $amount,
                    'session_id' => $sid,
                    'base_tokens' => $baseTokens,
                    'bonus_tokens' => $bonusTokens,
                    'bonus_pct' => $bonusPct,
                ], JSON_UNESCAPED_SLASHES),
            ]);
            $imported++;
        }

        if (!$hasMore) break;
        if (!is_string($startingAfter) || $startingAfter === '') break;
    }

    return ['imported' => $imported, 'matched' => $matched];
}

function mh_genesis_prune_orders_not_owned_by_user(PDO $pdoTok, string $username, string $stripeSecretKey, string $kind = 'mtk', int $limit = 50): array
{
    $username = trim($username);
    $kind = trim($kind);
    $limit = max(1, min(100, (int)$limit));
    if ($username === '' || $kind === '' || trim($stripeSecretKey) === '') return ['checked' => 0, 'deleted' => 0];

    mh_genesis_ensure_stripe_orders_schema($pdoTok);
    $st = $pdoTok->prepare("SELECT session_id FROM mh_stripe_checkout_orders WHERE username = ? AND kind = ? ORDER BY created_at DESC LIMIT " . (int)$limit);
    $st->execute([$username, $kind]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $checked = 0;
    $deleted = 0;
    foreach ($ids as $sid) {
        $sid = is_string($sid) ? trim($sid) : '';
        if ($sid === '') continue;
        $checked++;
        try {
            $session = mh_genesis_stripe_fetch_session($stripeSecretKey, $sid);
            $clientRef = isset($session['client_reference_id']) ? trim((string)$session['client_reference_id']) : '';
            $metaUser = '';
            if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['username'])) {
                $metaUser = trim((string)$session['metadata']['username']);
            }
            $refMatch = ($clientRef !== '' && strcasecmp($clientRef, $username) === 0) || ($metaUser !== '' && strcasecmp($metaUser, $username) === 0);
            if (!$refMatch) {
                $del = $pdoTok->prepare("DELETE FROM mh_stripe_checkout_orders WHERE session_id = ? AND username = ? LIMIT 1");
                $del->execute([$sid, $username]);
                if ($del->rowCount() > 0) $deleted++;
            }
        } catch (Throwable) {}
    }
    return ['checked' => $checked, 'deleted' => $deleted];
}

function mh_genesis_verify_and_credit_session(PDO $pdoTok, string $username, string $stripeSecretKey, string $sessionId): array
{
    $username = trim($username);
    $sessionId = trim($sessionId);
    if ($username === '' || $sessionId === '') throw new RuntimeException('invalid_payload');

    $session = mh_genesis_stripe_fetch_session($stripeSecretKey, $sessionId);
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
    $tokens = 0;
    $baseTokens = 0;
    $bonusTokens = 0;
    $bonusPct = 0.0;
    if (isset($session['metadata']) && is_array($session['metadata'])) {
        if (isset($session['metadata']['tokens'])) $tokens = (int)$session['metadata']['tokens'];
        if (isset($session['metadata']['base_tokens'])) $baseTokens = (int)$session['metadata']['base_tokens'];
        if (isset($session['metadata']['bonus_tokens'])) $bonusTokens = (int)$session['metadata']['bonus_tokens'];
        if (isset($session['metadata']['bonus_pct'])) $bonusPct = (float)$session['metadata']['bonus_pct'];
    }
    if (($tokens <= 0 || $baseTokens <= 0) && $amount > 0) {
        $bundle = mh_genesis_calc_tokens_bundle_from_usd((float)$amount);
        $tokens = (int)($bundle['total_tokens'] ?? 0);
        $baseTokens = (int)($bundle['base_tokens'] ?? 0);
        $bonusTokens = (int)($bundle['bonus_tokens'] ?? 0);
        $bonusPct = (float)($bundle['bonus_pct'] ?? 0.0);
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
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        $pdoBio = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
        if ($pdoBio instanceof PDO) {
            $pdoBio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (function_exists('mh_ensure_user_real_name_schema')) {
                mh_ensure_user_real_name_schema($pdoBio);
            }
            $stmt = $pdoBio->prepare("SELECT real_first_name, real_last_name FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
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

    mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
        'status' => ($paymentStatus === 'paid') ? 'paid' : 'created',
        'amount_usd' => $amount > 0 ? number_format($amount, 2, '.', '') : null,
        'tokens_expected' => $tokens > 0 ? $tokens : null,
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
            'base_tokens' => $baseTokens,
            'bonus_tokens' => $bonusTokens,
            'bonus_pct' => $bonusPct,
        ], JSON_UNESCAPED_SLASHES),
    ]);

    if ($paymentStatus !== 'paid') {
        return ['success' => false, 'error' => 'payment_not_verified', 'payment_status' => $paymentStatus];
    }
    if ($tokens <= 0) {
        mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
            'status' => 'failed',
            'last_error' => 'invalid_token_amount',
        ]);
        return ['success' => false, 'error' => 'invalid_token_amount'];
    }

    mh_tokenomics_ensure_schema($pdoTok);
    $tenantId = mh_tokenomics_tenant_id($username);
    mh_tokenomics_bootstrap_user_utility_balance($pdoTok, $tenantId, $username);
    $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
    if ($utilityClassId < 1) {
        mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
            'status' => 'failed',
            'last_error' => 'utility_class_missing',
        ]);
        return ['success' => false, 'error' => 'utility_class_missing'];
    }

    if (!mh_genesis_already_credited($pdoTok, $username, $utilityClassId, $sessionId)) {
        $ok = mh_tokenomics_apply_delta($pdoTok, $username, $utilityClassId, $tokens, 'onramp:stripe', $sessionId, [
            'amount_usd' => $amount,
            'session_id' => $sessionId,
            'base_tokens' => $baseTokens,
            'bonus_tokens' => $bonusTokens,
            'bonus_pct' => $bonusPct,
            'billing_name' => $billingName,
            'billing_email' => $billingEmail,
            'billing_match' => $billingMatch,
        ]);
        if (!$ok) {
            mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
                'status' => 'paid',
                'last_error' => 'credit_failed',
            ]);
            return ['success' => false, 'error' => 'credit_failed'];
        }
    }

    mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
        'status' => 'credited',
        'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
    ]);
    return ['success' => true, 'flagged' => $flagged === 1];
}

function mh_genesis_env(string $key): string
{
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

function mh_superhumans_base_url(): string
{
    static $cached = null;
    if (is_string($cached)) {
        return $cached;
    }
    $cfg = [];
    $raw = @file_get_contents('/data/config/superhumans.json');
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $cfg = $decoded;
    }
    $baseUrlEnv = getenv('SUPERHUMANS_BASE_URL');
    if (is_string($baseUrlEnv) && trim($baseUrlEnv) !== '') {
        $cfg['base_url'] = trim($baseUrlEnv);
    }
    $base = isset($cfg['base_url']) && is_string($cfg['base_url']) ? trim((string)$cfg['base_url']) : '';
    if ($base === '') {
        $base = 'https://meta.superhumans.one';
    }
    $cached = rtrim($base, '/');
    return $cached;
}

function mh_superhumans_url(string $path): string
{
    $path = '/' . ltrim((string)$path, '/');
    return mh_superhumans_base_url() . $path;
}

function mh_genesis_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s;
}

function mh_genesis_tenant_id(string $username): string
{
    $t = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($t === '') {
        $t = 'user:' . $username;
    }
    return $t;
}

function mh_genesis_persona_name(string $username): string
{
    $p = isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
    if ($p === '') {
        $p = $username;
    }
    return $p;
}

function mh_genesis_persona_id(string $personaName): string
{
    $safe = mh_genesis_safe_id($personaName);
    return $safe !== '' ? $safe : 'default';
}

function mh_genesis_sdxl_base_url(): string
{
    $u = mh_genesis_env('SDXL_TURBO_API_URL');
    if ($u === '') {
        $u = mh_superhumans_url('cortex-persona/sdxl-turbo');
    }
    return rtrim($u, '/');
}

function mh_genesis_sdxl_token(): string
{
    $t = mh_genesis_env('SDXL_TURBO_API_TOKEN');
    if ($t !== '') {
        return $t;
    }
    return mh_genesis_env('SDXL_API_TOKEN');
}

function mh_genesis_sdxl_generate(string $prompt, int $width = 512, int $height = 512, int $steps = 1, ?int $seed = null): array
{
    $base = mh_genesis_sdxl_base_url();
    $token = mh_genesis_sdxl_token();
    $payload = [
        'prompt' => $prompt,
        'width' => $width,
        'height' => $height,
        'num_inference_steps' => $steps,
    ];
    if (is_int($seed)) {
        $payload['seed'] = $seed;
    }
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('sdxl_payload_encode_failed');
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . '/v1/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1800);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new RuntimeException('sdxl_connect_error');
    }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;

    if (!is_string($result) || $result === '') {
        throw new RuntimeException('sdxl_empty_response');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('sdxl_http_' . $httpCode);
    }
    $resp = json_decode($result, true);
    if (!is_array($resp)) {
        throw new RuntimeException('sdxl_invalid_json');
    }
    return $resp;
}

function mh_genesis_write_b64_png(string $path, string $b64): void
{
    $b64 = trim((string)$b64);
    if ($b64 === '') {
        throw new RuntimeException('sdxl_missing_image');
    }
    $bin = base64_decode($b64, true);
    if (!is_string($bin) || $bin === '') {
        throw new RuntimeException('sdxl_invalid_base64');
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('mkdir_failed');
        }
    }
    if (file_put_contents($path, $bin) === false) {
        throw new RuntimeException('write_failed');
    }
}

$action = isset($_POST['action']) ? (string)$_POST['action'] : '';
$username = trim((string)$_SESSION['mh_auth_user']);

try {
    if ($action === 'complete_persona') {
        $tenantId = mh_genesis_tenant_id($username);
        $personaName = mh_genesis_persona_name($username);
        $personaId = mh_genesis_persona_id($personaName);

        $tenantProvisioning = dirname(__DIR__) . '/tenant_provisioning.php';
        if ($tenantId !== '' && !function_exists('mh_apply_tenant_context') && is_file($tenantProvisioning)) {
            require_once $tenantProvisioning;
        }
        if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
            try { mh_apply_tenant_context($tenantId); } catch (Throwable $e) {}
        }

        $pdoTenant = mh_genesis_tenant_pdo();
        if (!$pdoTenant) throw new RuntimeException('tenant_db_unavailable');

        if (function_exists('mh_provision_tenant_storage')) {
            try { mh_provision_tenant_storage($tenantId); } catch (Throwable $e) {}
        }

        $tenantSafe = mh_genesis_safe_id($tenantId);
        if ($tenantSafe === '') throw new RuntimeException('invalid_tenant_id');
        $personaSafe = mh_genesis_safe_id($personaId);
        if ($personaSafe === '') throw new RuntimeException('invalid_persona_id');

        $personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe;
        $imagesOriginalDir = $personaRoot . '/assets/images/original';
        $imagesNormDir = $personaRoot . '/assets/images/normalized';
        $manifestPath = $personaRoot . '/assets/manifest.json';
        $avatarPath = $imagesNormDir . '/avatar.png';
        $avatarOriginalPath = $imagesOriginalDir . '/avatar.png';

        @mkdir($imagesOriginalDir, 0700, true);
        @mkdir($imagesNormDir, 0700, true);

        $seed = null;
        $prompt = null;
        $engine = null;
        $engineError = null;
        if (!is_file($avatarPath) || filesize($avatarPath) < 1024) {
            $prompt = 'front-facing portrait photo, neutral expression, clean studio lighting, centered headshot, high detail, sharp focus, natural skin texture';
            $nameHint = trim((string)$personaName);
            if ($nameHint !== '' && $nameHint !== $username) {
                $prompt = $prompt . ', ' . $nameHint;
            }
            try {
                $resp = mh_genesis_sdxl_generate($prompt, 512, 512, 1, null);
                $seed = isset($resp['seed']) ? (int)$resp['seed'] : null;
                $b64 = isset($resp['image_png_base64']) ? (string)$resp['image_png_base64'] : '';
                mh_genesis_write_b64_png($avatarPath, $b64);
                mh_genesis_write_b64_png($avatarOriginalPath, $b64);
                $engine = 'sdxl-turbo';
            } catch (Throwable $e) {
                $engine = 'none';
                $engineError = $e->getMessage();
            }
        }

        $manifest = [
            'tenant_id' => $tenantId,
            'username' => $username,
            'persona_name' => $personaName,
            'persona_id' => $personaId,
            'created_at' => gmdate('c'),
            'assets' => [
                'images' => [
                    'avatar' => [
                        'normalized_path' => $avatarPath,
                        'original_path' => $avatarOriginalPath,
                        'seed' => $seed,
                        'prompt' => $prompt,
                        'engine' => $engine !== null ? $engine : 'sdxl-turbo',
                        'engine_url' => mh_genesis_sdxl_base_url(),
                        'engine_error' => $engineError,
                    ],
                ],
            ],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            if (function_exists('mh_persona_registry_pdo') && function_exists('mh_persona_registry_upsert')) {
                $pdoReg = mh_persona_registry_pdo();
                mh_persona_registry_upsert($pdoReg, $username, $personaName, $personaId, $tenantId, $username, ('meta:' . $personaId));
            }
        } catch (Throwable $e) {}

        mh_genesis_set_status($pdoTenant, $username, 1);
        echo json_encode(['success' => true, 'next' => '/hub/genesis/personas.php', 'persona_id' => $personaId, 'persona_name' => $personaName]);
        exit;
    }
    if ($action === 'complete_explanation') {
        $pdoTenant = mh_genesis_tenant_pdo();
        if (!$pdoTenant) throw new RuntimeException('tenant_db_unavailable');
        mh_genesis_set_status($pdoTenant, $username, 2);
        echo json_encode(['success' => true, 'next' => '/hub/genesis/tokenization.php']);
        exit;
    }
    if ($action === 'complete_tokenization' || $action === 'complete_genesis') {
        $pdoTenant = mh_genesis_tenant_pdo();
        if (!$pdoTenant) throw new RuntimeException('tenant_db_unavailable');
        $role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
        $isAdmin = stripos($role, 'kripzmaster') !== false;
        $bundle = mh_genesis_calc_tokens_bundle_from_usd(49.0);
        $minTokensRequired = (int)($bundle['total_tokens'] ?? 0);
        if ($minTokensRequired < 1) $minTokensRequired = 1;
        $bal = null;
        try {
            if (function_exists('mh_refresh_session_token_balance')) {
                $bal = mh_refresh_session_token_balance($username, 0);
            }
        } catch (Throwable) {
            $bal = null;
        }
        if (!is_int($bal)) {
            try {
                if (function_exists('mh_get_token_balance')) {
                    $bal = mh_get_token_balance($username);
                }
            } catch (Throwable) {
                $bal = null;
            }
        }
        if (!is_int($bal)) {
            $bal = isset($_SESSION['tokens']) ? (int)$_SESSION['tokens'] : 0;
        }
        if (!$isAdmin && (int)$bal < (int)$minTokensRequired) {
            echo json_encode(['success' => false, 'error' => 'insufficient_tokens', 'required' => (int)$minTokensRequired, 'balance' => (int)$bal]);
            exit;
        }
        mh_genesis_set_status($pdoTenant, $username, 3);
        echo json_encode(['success' => true, 'next' => '/hub/index.php']);
        exit;
    }

    $stripeSecretKey = mh_genesis_stripe_secret();
    if (($action === 'buy_tokens' || $action === 'verify_payment' || $action === 'reconcile_stripe' || $action === 'verify_my_order' || $action === 'reconcile_my_orders' || $action === 'my_mtk_orders') && $stripeSecretKey === '') {
        throw new RuntimeException('missing_stripe_secret');
    }

    if ($action === 'my_mtk_orders') {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_genesis_ensure_stripe_orders_schema($pdoTok);
        try { mh_genesis_prune_orders_not_owned_by_user($pdoTok, $username, $stripeSecretKey, 'mtk', 50); } catch (Throwable) {}
        try { mh_genesis_import_recent_orders_for_user($pdoTok, $username, $stripeSecretKey, 2); } catch (Throwable) {}
        $stmt = $pdoTok->prepare("SELECT session_id, status, amount_usd, tokens_expected, payment_status, flagged, flag_reason, last_error, created_at, updated_at FROM mh_stripe_checkout_orders WHERE username = ? AND kind = 'mtk' ORDER BY created_at DESC LIMIT 25");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];
        $recent = $_SESSION['mh_mtk_recent_sessions'] ?? null;
        $recent = is_array($recent) ? $recent : [];
        $nowTs = time();
        foreach (array_keys($recent) as $k) {
            $ts = isset($recent[$k]) ? (int)$recent[$k] : 0;
            if ($ts > 0 && ($nowTs - $ts) > 86400) {
                unset($recent[$k]);
            }
        }
        $_SESSION['mh_mtk_recent_sessions'] = $recent;
        $vis = [];
        foreach ($rows as $r) {
            $sid = is_array($r) && isset($r['session_id']) ? trim((string)$r['session_id']) : '';
            $st = is_array($r) && isset($r['status']) ? trim((string)$r['status']) : '';
            if ($sid === '') continue;
            if ($st === 'created' && !isset($recent[$sid])) continue;
            $vis[] = $r;
        }
        $rows = $vis;
        $filtered = [];
        $removed = [];
        $kept = [];
        $fetchErrors = 0;
        foreach ($rows as $r) {
            $sid = is_array($r) && isset($r['session_id']) ? trim((string)$r['session_id']) : '';
            if ($sid === '') continue;
            try {
                $session = mh_genesis_stripe_fetch_session($stripeSecretKey, $sid);
                $clientRef = isset($session['client_reference_id']) ? trim((string)$session['client_reference_id']) : '';
                $metaUser = '';
                if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['username'])) {
                    $metaUser = trim((string)$session['metadata']['username']);
                }
                $refMatch = ($clientRef !== '' && strcasecmp($clientRef, $username) === 0) || ($metaUser !== '' && strcasecmp($metaUser, $username) === 0);
                if ($refMatch) {
                    $filtered[] = $r;
                    $kept[] = [
                        'sid' => $sid,
                        'ref_h' => $clientRef !== '' ? substr(hash('sha256', strtolower($clientRef)), 0, 10) : null,
                        'meta_h' => $metaUser !== '' ? substr(hash('sha256', strtolower($metaUser)), 0, 10) : null,
                    ];
                } else {
                    try {
                        $pdoTok->prepare("DELETE FROM mh_stripe_checkout_orders WHERE session_id = ? AND username = ? LIMIT 1")->execute([$sid, $username]);
                    } catch (Throwable) {}
                    $removed[] = [
                        'sid' => $sid,
                        'ref_h' => $clientRef !== '' ? substr(hash('sha256', strtolower($clientRef)), 0, 10) : null,
                        'meta_h' => $metaUser !== '' ? substr(hash('sha256', strtolower($metaUser)), 0, 10) : null,
                    ];
                }
            } catch (Throwable) {
                $fetchErrors++;
            }
        }
        echo json_encode(['success' => true, 'orders' => $filtered], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'buy_tokens') {
        $amount = (int)($_POST['amount'] ?? 0);
        if ($amount < 20) {
            echo json_encode(['success' => false, 'error' => 'Minimum purchase amount is $20']);
            exit;
        }
        $bundle = mh_genesis_calc_tokens_bundle_from_usd((float)$amount);
        $tokens = (int)($bundle['total_tokens'] ?? 0);
        $baseTokens = (int)($bundle['base_tokens'] ?? 0);
        $bonusTokens = (int)($bundle['bonus_tokens'] ?? 0);
        $bonusPct = (float)($bundle['bonus_pct'] ?? 0.0);
        $usdPerToken = (float)($bundle['usd_per_token'] ?? 0.0);
        if ($tokens < 1) $tokens = max(1, $baseTokens);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');

        $productName = $tokens . ' Meta Tokens';
        if ($bonusTokens > 0) {
            $productName = $tokens . ' Meta Tokens (includes ' . $bonusTokens . ' bonus)';
        }
        $postFields = http_build_query([
            'payment_method_types' => ['card'],
            'billing_address_collection' => 'auto',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => $productName],
                    'unit_amount' => $amount * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'metahumans.one') . '/hub/genesis/tokenization.php?payment_success=true&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'metahumans.one') . '/hub/genesis/tokenization.php?payment_cancel=true',
            'client_reference_id' => $username,
            'metadata' => [
                'username' => $username,
                'tokens' => $tokens,
                'base_tokens' => $baseTokens,
                'bonus_tokens' => $bonusTokens,
                'bonus_pct' => $bonusPct,
                'amount_usd' => $amount,
                'usd_per_token' => $usdPerToken > 0 ? $usdPerToken : mh_genesis_utility_usd_per_token(),
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
                mh_genesis_order_upsert($pdoTok, $sessionId, $username, [
                    'kind' => 'mtk',
                    'status' => 'created',
                    'amount_usd' => number_format((float)$amount, 2, '.', ''),
                    'tokens_expected' => $tokens > 0 ? $tokens : null,
                    'payment_status' => null,
                    'flagged' => 0,
                    'flag_reason' => null,
                    'last_error' => null,
                    'meta_json' => json_encode([
                        'amount_usd' => (float)$amount,
                        'base_tokens' => $baseTokens,
                        'bonus_tokens' => $bonusTokens,
                        'bonus_pct' => $bonusPct,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
            } catch (Throwable) {}
            try {
                $recent = $_SESSION['mh_mtk_recent_sessions'] ?? null;
                $recent = is_array($recent) ? $recent : [];
                $recent[$sessionId] = time();
                if (count($recent) > 50) {
                    arsort($recent);
                    $recent = array_slice($recent, 0, 50, true);
                }
                $_SESSION['mh_mtk_recent_sessions'] = $recent;
            } catch (Throwable) {}
        }
        echo json_encode(['success' => true, 'redirect_url' => (string)$response['url']]);
        exit;
    }

    if ($action === 'verify_payment') {
        $sessionId = isset($_POST['session_id']) ? (string)$_POST['session_id'] : '';
        $sessionId = trim($sessionId);
        if ($sessionId === '') throw new RuntimeException('missing_session_id');
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        $res = mh_genesis_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sessionId);
        if (isset($res['success']) && $res['success'] === true) {
            if (function_exists('mh_get_token_balance')) {
                $bal = mh_get_token_balance($username);
                if (is_int($bal)) $_SESSION['tokens'] = $bal;
            }
            echo json_encode(['success' => true, 'flagged' => (bool)($res['flagged'] ?? false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $err = isset($res['error']) ? (string)$res['error'] : 'Payment not verified';
        echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'verify_my_order') {
        $sessionId = isset($_POST['session_id']) ? (string)$_POST['session_id'] : '';
        $sessionId = trim($sessionId);
        if ($sessionId === '') throw new RuntimeException('missing_session_id');
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        $res = mh_genesis_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sessionId);
        if (isset($res['success']) && $res['success'] === true) {
            if (function_exists('mh_get_token_balance')) {
                $bal = mh_get_token_balance($username);
                if (is_int($bal)) $_SESSION['tokens'] = $bal;
            }
            echo json_encode(['success' => true, 'flagged' => (bool)($res['flagged'] ?? false)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $err = isset($res['error']) ? (string)$res['error'] : 'verify_failed';
        echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reconcile_my_orders') {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_genesis_ensure_stripe_orders_schema($pdoTok);
        try { mh_genesis_prune_orders_not_owned_by_user($pdoTok, $username, $stripeSecretKey, 'mtk', 100); } catch (Throwable) {}
        try { mh_genesis_import_recent_orders_for_user($pdoTok, $username, $stripeSecretKey, 3); } catch (Throwable) {}
        $stmt = $pdoTok->prepare("SELECT session_id FROM mh_stripe_checkout_orders WHERE username = ? AND kind = 'mtk' AND status <> 'credited' ORDER BY created_at DESC LIMIT 25");
        $stmt->execute([$username]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $checked = 0;
        $credited = 0;
        $failed = 0;
        $notPaid = 0;
        foreach ($ids as $sid) {
            $sid = is_string($sid) ? trim($sid) : '';
            if ($sid === '') continue;
            $checked++;
            try {
                $r = mh_genesis_verify_and_credit_session($pdoTok, $username, $stripeSecretKey, $sid);
                if (isset($r['success']) && $r['success'] === true) {
                    $credited++;
                } elseif (isset($r['error']) && (string)$r['error'] === 'payment_not_verified') {
                    $notPaid++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }
        if (function_exists('mh_get_token_balance')) {
            $bal = mh_get_token_balance($username);
            if (is_int($bal)) $_SESSION['tokens'] = $bal;
        }
        echo json_encode(['success' => true, 'checked' => $checked, 'credited' => $credited, 'not_paid' => $notPaid, 'failed' => $failed], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reconcile_stripe') {
        $role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
        if (stripos($role, 'kripzmaster') === false) {
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
        $targetUser = $username;
        if (isset($_POST['username']) && is_string($_POST['username']) && trim((string)$_POST['username']) !== '') {
            $targetUser = trim((string)$_POST['username']);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/checkout/sessions?limit=100');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $stripeSecretKey . ':');
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new RuntimeException('stripe_connect_error');
        }
        $ch = null;
        $response = json_decode((string)$result, true);
        if (!is_array($response) || !isset($response['data']) || !is_array($response['data'])) {
            throw new RuntimeException('stripe_invalid_response');
        }

        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdoTok);
        $tenantId = mh_tokenomics_tenant_id($targetUser);
        mh_tokenomics_bootstrap_user_utility_balance($pdoTok, $tenantId, $targetUser);
        $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
        if ($utilityClassId < 1) throw new RuntimeException('utility_class_missing');

        $matched = 0;
        $credited = 0;
        foreach ($response['data'] as $session) {
            if (!is_array($session)) continue;
            $sid = trim((string)($session['id'] ?? ''));
            if ($sid === '') continue;
            if (($session['payment_status'] ?? '') !== 'paid') continue;
            $ref = (string)($session['client_reference_id'] ?? '');
            $metaUser = '';
            if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['username'])) {
                $metaUser = (string)$session['metadata']['username'];
            }
            if (strcasecmp($ref, $targetUser) !== 0 && strcasecmp($metaUser, $targetUser) !== 0) continue;
            $matched++;

            $amount = isset($session['amount_total']) ? ((float)$session['amount_total'] / 100.0) : 0.0;
            $tokens = 0;
            if (isset($session['metadata']) && is_array($session['metadata']) && isset($session['metadata']['tokens'])) {
                $tokens = (int)$session['metadata']['tokens'];
            }
            if ($tokens <= 0 && $amount > 0) {
                $bundle = mh_genesis_calc_tokens_bundle_from_usd((float)$amount);
                $tokens = (int)($bundle['total_tokens'] ?? 0);
            }
            if ($tokens <= 0) continue;

            if (!mh_genesis_already_credited($pdoTok, $targetUser, $utilityClassId, $sid)) {
                mh_tokenomics_apply_delta($pdoTok, $targetUser, $utilityClassId, $tokens, 'onramp:stripe', $sid, ['amount_usd' => $amount, 'session_id' => $sid]);
                $credited++;
            }
        }

        if ($targetUser === $username && function_exists('mh_get_token_balance')) {
            $bal = mh_get_token_balance($username);
            if (is_int($bal)) $_SESSION['tokens'] = $bal;
        }
        echo json_encode(['success' => true, 'matched' => $matched, 'credited' => $credited]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
