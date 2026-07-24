<?php
declare(strict_types=1);

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/tokenomics.php';
require_once __DIR__ . '/../auth/kripz_gate.php';

$airdropIsAjax = (isset($_GET['ajax']) && is_string($_GET['ajax']) && $_GET['ajax'] !== '');
mh_kripz_require('airdrop', $airdropIsAjax);

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/control/kripzmaster-airdrop.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/control/kripzmaster-airdrop.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$actor = trim((string)$_SESSION['mh_auth_user']);
$role = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string)$_SESSION['mh_auth_role'])) : '';
$groupsRaw = $_SESSION['mh_auth_groups'] ?? '';
$groupsStr = is_array($groupsRaw) ? implode(';', array_map('strval', $groupsRaw)) : (is_string($groupsRaw) ? $groupsRaw : '');
$isKripz = (strpos($role, 'kripz') !== false) || (stripos((string)$groupsStr, 'KripzMasters') !== false);
if (!$isKripz) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$csrf = isset($_SESSION['mh_airdrop_csrf']) && is_string($_SESSION['mh_airdrop_csrf']) ? (string)$_SESSION['mh_airdrop_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_airdrop_csrf'] = $csrf;
}

$targets = [
    'Cristal Rubeus',
    'CristalR',
    'marli',
    'Pieter Rubeus',
];
$amount = 100000;
$result = null;
$manualResult = null;
$grants = [];
$error = '';
$availableCoins = [];
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/kripzmaster-airdrop.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/kripzmaster-airdrop.php';
}
$flash = $_SESSION['mh_airdrop_flash'] ?? null;
if (is_array($flash)) {
    $result = isset($flash['result']) && is_array($flash['result']) ? $flash['result'] : null;
    $manualResult = isset($flash['manualResult']) && is_array($flash['manualResult']) ? $flash['manualResult'] : null;
    $error = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
}
unset($_SESSION['mh_airdrop_flash']);

function mh_airdrop_resolve_user(PDO $pdoBio, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') return null;
    $stmt = $pdoBio->prepare("SELECT username, name, role FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$identifier]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) return $row;

    $stmt = $pdoBio->prepare("SELECT username, name, role FROM users WHERE LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 2");
    $stmt->execute([$identifier]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 1 && is_array($rows[0])) return $rows[0];
    return null;
}

function mh_airdrop_user_search(PDO $pdoBio, string $q, int $limit = 12): array
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

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'user_search') {
    try {
        $pdoBio = database_getConnectionById('biometrics');
        $q = isset($_GET['q']) ? (string)$_GET['q'] : '';
        $users = mh_airdrop_user_search($pdoBio, $q, 12);
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

function mh_airdrop_list_grants(PDO $pdoTok, PDO $pdoBio, int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $stmt = $pdoTok->prepare("
        SELECT
            username,
            SUM(CASE WHEN direction = 'credit' THEN units ELSE -units END) AS net_units,
            COUNT(*) AS txn_count,
            MAX(created_at) AS last_at
        FROM mh_asset_transactions
        WHERE (
            (service_key = 'admin:airdrop' AND reference_id = 'kripzmaster_airdrop')
            OR
            (service_key = 'admin:adjust' AND reference_id = 'kripzmaster_manual_adjust')
        )
        GROUP BY username
        ORDER BY last_at DESC
        LIMIT " . (int)$limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $u = isset($r['username']) ? trim((string)$r['username']) : '';
        if ($u === '') continue;
        $name = '';
        try {
            $s2 = $pdoBio->prepare("SELECT name FROM users WHERE username = ? LIMIT 1");
            $s2->execute([$u]);
            $name = trim((string)$s2->fetchColumn());
        } catch (Throwable $e) { $name = ''; }
        $out[] = [
            'username' => $u,
            'name' => $name,
            'total_units' => (int)($r['net_units'] ?? 0),
            'txn_count' => (int)($r['txn_count'] ?? 0),
            'last_at' => (string)($r['last_at'] ?? ''),
        ];
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $error = 'Invalid request';
    } else {
        try {
            $pdoBio = database_getConnectionById('biometrics');
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            mh_tokenomics_ensure_schema($pdoTok);
            $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
            if ($utilityClassId < 1) {
                throw new RuntimeException('tokenomics_not_ready');
            }
            if (function_exists('mh_tokenomics_seed_culture_coins')) {
                mh_tokenomics_seed_culture_coins($pdoTok);
            }

            $action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'bulk_airdrop';
            if ($action === 'manual_credit' || $action === 'manual_debit') {
                $targetUser = isset($_POST['target_username']) ? trim((string)$_POST['target_username']) : '';
                $amt = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;
                $assetKey = isset($_POST['asset_key']) ? trim((string)$_POST['asset_key']) : 'utility:meta';
                if ($assetKey === '') $assetKey = 'utility:meta';
                $assetClassId = mh_tokenomics_get_asset_class_id($pdoTok, $assetKey);
                if ($assetClassId < 1) {
                    $error = 'Invalid coin selection.';
                } else
                if ($targetUser === '' || $amt <= 0) {
                    $error = 'Invalid username or amount.';
                } else {
                    $delta = $action === 'manual_debit' ? -$amt : $amt;
                    $tenantId = 'user:' . $targetUser;
                    $prevTenant = null;
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $prevTenant = $_SESSION['mh_tenant_id'] ?? null;
                        $_SESSION['mh_tenant_id'] = $tenantId;
                    }
                    $ok = mh_tokenomics_apply_delta($pdoTok, $targetUser, $assetClassId, $delta, 'admin:adjust', 'kripzmaster_manual_adjust', [
                        'by' => $actor,
                        'action' => $action,
                        'amount' => $amt,
                        'asset_key' => $assetKey,
                        'source' => 'control/kripzmaster-airdrop.php',
                    ]);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if ($prevTenant === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant;
                    }
                    if (!$ok) {
                        $error = $action === 'manual_debit' ? 'Debit failed (insufficient tokens or ledger error).' : 'Credit failed.';
                    } else {
                        $prevTenant2 = null;
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            $prevTenant2 = $_SESSION['mh_tenant_id'] ?? null;
                            $_SESSION['mh_tenant_id'] = $tenantId;
                        }
                        $bal = null;
                        if (function_exists('mh_tokenomics_get_balance')) {
                            $bal = mh_tokenomics_get_balance($pdoTok, $targetUser, $assetClassId);
                        }
                        if (session_status() === PHP_SESSION_ACTIVE) {
                            if ($prevTenant2 === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant2;
                        }
                        $balInt = is_int($bal) ? $bal : null;
                        try {
                            if ($balInt !== null && $assetKey === 'utility:meta') {
                                $pdoBio->prepare("UPDATE users SET tokens = ? WHERE username = ?")->execute([$balInt, $targetUser]);
                            }
                        } catch (Throwable $e) {}
                        $manualResult = ['ok' => true, 'username' => $targetUser, 'delta' => $delta, 'asset_key' => $assetKey, 'units' => $balInt];
                    }
                }
            } else {
            $done = [];
            $rows = [];
            foreach ($targets as $t) {
                $u = mh_airdrop_resolve_user($pdoBio, (string)$t);
                if (!is_array($u)) {
                    $rows[] = ['input' => $t, 'ok' => false, 'error' => 'user_not_found'];
                    continue;
                }
                $username = trim((string)($u['username'] ?? ''));
                if ($username === '') {
                    $rows[] = ['input' => $t, 'ok' => false, 'error' => 'invalid_username'];
                    continue;
                }
                if (isset($done[$username])) {
                    $rows[] = ['input' => $t, 'ok' => true, 'username' => $username, 'skipped' => true];
                    continue;
                }

                $tenantId = 'user:' . $username;
                $prevTenant = null;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $prevTenant = $_SESSION['mh_tenant_id'] ?? null;
                    $_SESSION['mh_tenant_id'] = $tenantId;
                }

                $already = false;
                try {
                    $stmt = $pdoTok->prepare("SELECT id FROM mh_asset_transactions WHERE tenant_id = ? AND username = ? AND service_key = ? AND reference_id = ? AND direction = 'credit' AND units = ? LIMIT 1");
                    $stmt->execute([$tenantId, $username, 'admin:airdrop', 'kripzmaster_airdrop', $amount]);
                    $already = (int)$stmt->fetchColumn() > 0;
                } catch (Throwable $e) {
                    $already = false;
                }

                if ($already) {
                    $prevTenant2 = null;
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $prevTenant2 = $_SESSION['mh_tenant_id'] ?? null;
                        $_SESSION['mh_tenant_id'] = $tenantId;
                    }
                    $bal = mh_tokenomics_get_utility_balance($pdoTok, $username);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if ($prevTenant2 === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant2;
                    }
                    $balInt = is_int($bal) ? $bal : null;
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        if ($prevTenant === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant;
                    }
                    $rows[] = ['input' => $t, 'ok' => true, 'username' => $username, 'tokens' => $balInt, 'skipped' => true];
                    $done[$username] = true;
                    continue;
                }

                $ok = mh_tokenomics_apply_delta($pdoTok, $username, $utilityClassId, $amount, 'admin:airdrop', 'kripzmaster_airdrop', [
                    'by' => $actor,
                    'amount' => $amount,
                    'source' => 'control/kripzmaster-airdrop.php',
                ]);

                if (session_status() === PHP_SESSION_ACTIVE) {
                    if ($prevTenant === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant;
                }

                if (!$ok) {
                    $rows[] = ['input' => $t, 'ok' => false, 'username' => $username, 'error' => 'apply_delta_failed'];
                    continue;
                }
                $prevTenant2 = null;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $prevTenant2 = $_SESSION['mh_tenant_id'] ?? null;
                    $_SESSION['mh_tenant_id'] = $tenantId;
                }
                $bal = mh_tokenomics_get_utility_balance($pdoTok, $username);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    if ($prevTenant2 === null) unset($_SESSION['mh_tenant_id']); else $_SESSION['mh_tenant_id'] = $prevTenant2;
                }
                $balInt = is_int($bal) ? $bal : null;
                try {
                    if ($balInt !== null) {
                        $pdoBio->prepare("UPDATE users SET tokens = ? WHERE username = ?")->execute([$balInt, $username]);
                    }
                } catch (Throwable $e) {}
                $done[$username] = true;
                $rows[] = ['input' => $t, 'ok' => true, 'username' => $username, 'tokens' => $balInt];
            }
            $result = ['ok' => true, 'amount' => $amount, 'rows' => $rows];
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    if (!headers_sent()) {
        $_SESSION['mh_airdrop_flash'] = [
            'result' => is_array($result) ? $result : null,
            'manualResult' => is_array($manualResult) ? $manualResult : null,
            'error' => $error,
        ];
        header('Location: ' . $requestUri, true, 303);
        exit;
    }
}

try {
    $pdoBio = database_getConnectionById('biometrics');
    $pdoTok = mh_tokenomics_get_tokenomics_pdo();
    mh_tokenomics_ensure_schema($pdoTok);
    if (function_exists('mh_tokenomics_seed_culture_coins')) {
        mh_tokenomics_seed_culture_coins($pdoTok);
    }
    $grants = mh_airdrop_list_grants($pdoTok, $pdoBio, 200);

    $stmt = $pdoTok->query("SELECT asset_key, asset_type, display_name, pricing_params_json FROM mh_asset_classes WHERE asset_type IN ('utility','culture') ORDER BY asset_type ASC, display_name ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $k = isset($r['asset_key']) ? trim((string)$r['asset_key']) : '';
        if ($k === '') continue;
        $type = isset($r['asset_type']) ? trim((string)$r['asset_type']) : '';
        $name = isset($r['display_name']) ? trim((string)$r['display_name']) : $k;
        $ticker = '';
        if ($k === 'utility:meta') {
            $ticker = 'MTK';
        } else {
            $pp = isset($r['pricing_params_json']) ? (string)$r['pricing_params_json'] : '';
            $decoded = $pp !== '' ? json_decode($pp, true) : null;
            if (is_array($decoded) && isset($decoded['ticker']) && is_string($decoded['ticker'])) {
                $ticker = trim((string)$decoded['ticker']);
            }
        }
        $label = $name;
        if ($ticker !== '') $label .= ' (' . $ticker . ')';
        if ($type !== '' && $k !== 'utility:meta') $label .= ' — ' . $type;
        $availableCoins[] = ['asset_key' => $k, 'label' => $label];
    }
} catch (Throwable $e) {
    $grants = [];
    $availableCoins = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KripzMaster Airdrop</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .wrap { max-width: 980px; margin: 0 auto; padding: 28px 18px; color: rgba(255,255,255,0.92); }
        .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; overflow: hidden; }
        h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); margin: 0 0 10px; }
        .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
        .btn { display:inline-flex; gap: 8px; align-items:center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(239,68,68,0.45); background: rgba(239,68,68,0.14); color: rgba(255,255,255,0.92); text-decoration:none; cursor:pointer; font-weight: 800; }
        table { width:100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align:left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
        th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
        .ok { color: rgba(16,185,129,0.95); font-weight: 800; }
        .bad { color: rgba(239,68,68,0.95); font-weight: 800; }
        .pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px; margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px; }
        .row { display:flex; gap: 12px; flex-wrap: wrap; align-items: end; }
        .field { flex: 1; min-width: 220px; }
        .field label { display:block; margin: 0 0 6px 0; color: rgba(255,255,255,0.80); font-size: 12px; }
        .field input { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.22); background: rgba(0,0,0,0.28); color: rgba(255,255,255,0.92); }
        .field select { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.22); background: rgba(0,0,0,0.28); color: rgba(255,255,255,0.92); }
        .list { margin-top: 8px; border: 1px solid rgba(255,255,255,0.10); border-radius: 12px; overflow: hidden; }
        .list button { width:100%; text-align:left; padding: 10px 12px; background: rgba(0,0,0,0.20); border: 0; border-bottom: 1px solid rgba(255,255,255,0.08); color: rgba(255,255,255,0.92); cursor:pointer; }
        .list button:hover { background: rgba(0,212,255,0.08); }
        .btn2 { display:inline-flex; gap: 8px; align-items:center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.30); background: rgba(0,0,0,0.18); color: rgba(255,255,255,0.92); text-decoration:none; cursor:pointer; font-weight: 800; }
        .btnDanger { border-color: rgba(239,68,68,0.45); background: rgba(239,68,68,0.14); }
        .mh-amt-row { display:flex; gap: 10px; flex-wrap: wrap; }
        .mh-amt-row input { flex: 1 1 180px; min-width: 160px; }
        .mh-amt-row select { flex: 1 1 220px; min-width: 200px; }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <div class="wrap">
        <h1>KripzMaster Airdrop</h1>
        <div class="muted">Grants <?php echo number_format($amount); ?> MTK to each listed user (resolved by username or exact real name).</div>

        <div class="card" style="margin-top: 14px;">
            <?php if ($error !== ''): ?>
                <div class="bad"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <?php if (is_array($manualResult)): ?>
                <div class="ok" style="margin-top: 10px;">Updated: <?php echo htmlspecialchars((string)($manualResult['username'] ?? ''), ENT_QUOTES); ?> (<?php echo (int)($manualResult['delta'] ?? 0); ?> units, <?php echo htmlspecialchars((string)($manualResult['asset_key'] ?? ''), ENT_QUOTES); ?>)</div>
            <?php endif; ?>

            <div style="margin-top: 10px; border-bottom: 1px solid rgba(255,255,255,0.10); padding-bottom: 14px;">
                <div class="muted" style="margin-bottom: 10px;">Search a user and adjust coins.</div>
                <div class="row">
                    <div class="field">
                        <label>Search user (name or username)</label>
                        <input id="mhUserSearch" type="text" placeholder="Type to search...">
                        <div id="mhUserResults" class="list" style="display:none;"></div>
                    </div>
                    <div class="field">
                        <label>Selected Username</label>
                        <input id="mhSelectedUser" type="text" name="target_username" form="mhManualCredit" readonly value="">
                    </div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <form id="mhManualCredit" method="post" class="row" style="flex:1;">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                        <input type="hidden" name="action" value="manual_credit">
                        <input type="hidden" name="target_username" id="mhManualCreditUser" value="">
                        <div class="field">
                            <label>Add amount</label>
                            <div class="mh-amt-row">
                                <input type="text" name="amount" value="100000" inputmode="numeric">
                                <select name="asset_key">
                                    <?php foreach ($availableCoins as $c): ?>
                                        <option value="<?php echo htmlspecialchars((string)$c['asset_key'], ENT_QUOTES); ?>"<?php if ((string)$c['asset_key'] === 'utility:meta') echo ' selected'; ?>><?php echo htmlspecialchars((string)$c['label'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field" style="flex:0; min-width:220px;">
                            <button type="submit" class="btn2">Add</button>
                        </div>
                    </form>
                    <form id="mhManualDebit" method="post" class="row" style="flex:1;">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                        <input type="hidden" name="action" value="manual_debit">
                        <input type="hidden" name="target_username" id="mhManualDebitUser" value="">
                        <div class="field">
                            <label>Remove amount</label>
                            <div class="mh-amt-row">
                                <input type="text" name="amount" value="100000" inputmode="numeric">
                                <select name="asset_key">
                                    <?php foreach ($availableCoins as $c): ?>
                                        <option value="<?php echo htmlspecialchars((string)$c['asset_key'], ENT_QUOTES); ?>"<?php if ((string)$c['asset_key'] === 'utility:meta') echo ' selected'; ?>><?php echo htmlspecialchars((string)$c['label'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field" style="flex:0; min-width:220px;">
                            <button type="submit" class="btn2 btnDanger">Remove</button>
                        </div>
                    </form>
                </div>
            </div>

            <form method="post" style="margin-top: 14px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                <input type="hidden" name="action" value="bulk_airdrop">
                <button type="submit" class="btn">Grant <?php echo number_format($amount); ?> MTK</button>
            </form>

            <div class="muted" style="margin-top: 12px;">Targets:</div>
            <div class="pre"><?php echo htmlspecialchars(implode("\n", $targets), ENT_QUOTES); ?></div>

            <?php if (is_array($result)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Input</th>
                            <th>Status</th>
                            <th>Resolved Username</th>
                            <th>Tokens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($result['rows'] ?? []) as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($r['input'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo !empty($r['ok']) ? '<span class="ok">OK</span>' : '<span class="bad">FAIL</span>'; ?><?php if (!empty($r['skipped'])) echo ' <span class="muted">(skipped)</span>'; ?></td>
                                <td><?php echo htmlspecialchars((string)($r['username'] ?? ''), ENT_QUOTES); ?><?php if (!empty($r['error'])) echo ' <span class="bad">(' . htmlspecialchars((string)$r['error'], ENT_QUOTES) . ')</span>'; ?></td>
                                <td class="muted"><?php echo isset($r['tokens']) ? number_format((int)$r['tokens']) : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="muted" style="margin-top: 16px;">Users updated via this page (bulk airdrop + manual adjust):</div>
            <?php if (!empty($grants)): ?>
                <table style="margin-top: 8px;">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Net MTK</th>
                            <th>Txns</th>
                            <th>Last</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grants as $g): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES); ?>
                                    <div class="muted"><?php echo htmlspecialchars((string)($g['username'] ?? ''), ENT_QUOTES); ?></div>
                                </td>
                                <td class="muted"><?php echo number_format((int)($g['total_units'] ?? 0)); ?></td>
                                <td class="muted"><?php echo number_format((int)($g['txn_count'] ?? 0)); ?></td>
                                <td class="muted"><?php echo htmlspecialchars((string)($g['last_at'] ?? ''), ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="muted">No grants found.</div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
(function () {
  const q = document.getElementById('mhUserSearch');
  const list = document.getElementById('mhUserResults');
  const sel = document.getElementById('mhSelectedUser');
  const cUser = document.getElementById('mhManualCreditUser');
  const dUser = document.getElementById('mhManualDebitUser');
  if (!q || !list || !sel || !cUser || !dUser) return;

  let t = null;
  function setSelected(username) {
    sel.value = username;
    cUser.value = username;
    dUser.value = username;
  }

  async function run() {
    const term = String(q.value || '').trim();
    if (term.length < 2) {
      list.style.display = 'none';
      list.innerHTML = '';
      return;
    }
    const url = `/control/kripzmaster-airdrop.php?ajax=user_search&q=${encodeURIComponent(term)}`;
    const res = await fetch(url, { credentials: 'include', cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }});
    const txt = await res.text();
    let data = null;
    try { data = JSON.parse(txt); } catch (e) { data = null; }
    if (!res.ok || !data || data.ok !== true) {
      list.style.display = 'none';
      list.innerHTML = '';
      return;
    }
    const users = Array.isArray(data.users) ? data.users : [];
    if (!users.length) {
      list.style.display = 'none';
      list.innerHTML = '';
      return;
    }
    list.innerHTML = '';
    users.forEach(u => {
      const username = String(u.username || '').trim();
      if (!username) return;
      const name = String(u.name || '').trim();
      const label = name ? `${name} (${username})` : username;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = label;
      btn.addEventListener('click', () => {
        setSelected(username);
        list.style.display = 'none';
        list.innerHTML = '';
      });
      list.appendChild(btn);
    });
    list.style.display = 'block';
  }

  q.addEventListener('input', () => {
    if (t) clearTimeout(t);
    t = setTimeout(() => { run().catch(() => {}); }, 200);
  });
  document.addEventListener('click', (e) => {
    if (!list.contains(e.target) && e.target !== q) {
      list.style.display = 'none';
    }
  });
})();
</script>
</body>
</html>
