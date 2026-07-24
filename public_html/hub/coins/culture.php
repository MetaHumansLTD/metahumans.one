<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
if (is_file(__DIR__ . '/../../auth/tokenomics.php')) {
    require_once __DIR__ . '/../../auth/tokenomics.php';
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/coins/culture.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/coins/culture.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$username = trim((string)($_SESSION['mh_auth_user'] ?? ''));

$champBalance = 0;
$superBalance = 0;
$champUsdPerCoin = 0.25;
$minUsd = 100;
$champName = 'Champion Coin';
$champTicker = 'mhc';
$champSupplyCap = 1000000;
$champIssueDateText = '';
$champCloseDateText = '';
$superName = 'Super Coin';
$superTicker = 'mhs';
$superSupplyCap = 300000;
$superIssueDateText = '';
$superCloseDateText = '';
$superLatestPrice = null;
$superLatestEffectiveFrom = '';
$superLatestEffectiveTo = '';
$champNextPrice = null;
$champNextEffectiveFrom = '';
$champNextEffectiveTo = '';
$champCurrentPrice = null;
$champCurrentEffectiveFrom = '';
$champCurrentEffectiveTo = '';
$superCurrentPrice = null;
$superCurrentEffectiveFrom = '';
$superCurrentEffectiveTo = '';
$champIsIssued = true;
$champIsClosed = false;
$superIsIssued = false;
$superIsClosed = false;
$champRemaining = null;
$superRemaining = null;

try {
    if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_seed_culture_coins')) {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $ids = mh_tokenomics_seed_culture_coins($pdoTok);
            $champId = (int)($ids['champcoin'] ?? 0);
            $superId = (int)($ids['supercoin'] ?? 0);
            if ($champId > 0) {
                $stmt = $pdoTok->prepare("SELECT display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$champId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    $dn = isset($row['display_name']) ? trim((string)$row['display_name']) : '';
                    if ($dn !== '') $champName = $dn;
                    $raw = isset($row['pricing_params_json']) ? trim((string)$row['pricing_params_json']) : '';
                    $meta = $raw !== '' ? json_decode($raw, true) : null;
                    if (is_array($meta)) {
                        $t = isset($meta['ticker']) ? trim((string)$meta['ticker']) : '';
                        if ($t !== '') $champTicker = $t;
                        if (isset($meta['supply_cap'])) $champSupplyCap = max(0, (int)$meta['supply_cap']);
                        $iss = isset($meta['issue_date']) ? trim((string)$meta['issue_date']) : '';
                        if ($iss !== '') $champIssueDateText = $iss;
                        $cls = isset($meta['close_date']) ? trim((string)$meta['close_date']) : '';
                        if ($cls !== '') $champCloseDateText = $cls;
                    }
                }
            }
            if ($superId > 0) {
                $stmt = $pdoTok->prepare("SELECT display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$superId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($row) && !empty($row)) {
                    $dn = isset($row['display_name']) ? trim((string)$row['display_name']) : '';
                    if ($dn !== '') $superName = $dn;
                    $raw = isset($row['pricing_params_json']) ? trim((string)$row['pricing_params_json']) : '';
                    $meta = $raw !== '' ? json_decode($raw, true) : null;
                    if (is_array($meta)) {
                        $t = isset($meta['ticker']) ? trim((string)$meta['ticker']) : '';
                        if ($t !== '') $superTicker = $t;
                        if (isset($meta['supply_cap'])) $superSupplyCap = max(0, (int)$meta['supply_cap']);
                        $iss = isset($meta['issue_date']) ? trim((string)$meta['issue_date']) : '';
                        if ($iss !== '') $superIssueDateText = $iss;
                        $cls = isset($meta['close_date']) ? trim((string)$meta['close_date']) : '';
                        if ($cls !== '') $superCloseDateText = $cls;
                    }
                }
            }
            if ($champId > 0 && function_exists('mh_tokenomics_get_current_price_usd')) {
                $p = mh_tokenomics_get_current_price_usd($pdoTok, $champId);
                if (is_float($p) && $p > 0) $champUsdPerCoin = $p;
            }

            if ($champId > 0) {
                $stmt = $pdoTok->prepare("SELECT price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
                $stmt->execute([$champId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && !empty($r)) {
                    $p = isset($r['price_usd_per_unit']) ? (float)$r['price_usd_per_unit'] : null;
                    if (is_float($p) && $p > 0) {
                        $champCurrentPrice = $p;
                        $champCurrentEffectiveFrom = isset($r['effective_from']) ? trim((string)$r['effective_from']) : '';
                        $champCurrentEffectiveTo = isset($r['effective_to']) ? trim((string)$r['effective_to']) : '';
                    }
                }
                $stmt = $pdoTok->prepare("SELECT price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from > NOW() ORDER BY effective_from ASC LIMIT 1");
                $stmt->execute([$champId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && !empty($r)) {
                    $p = isset($r['price_usd_per_unit']) ? (float)$r['price_usd_per_unit'] : null;
                    if (is_float($p) && $p > 0) $champNextPrice = $p;
                    $champNextEffectiveFrom = isset($r['effective_from']) ? trim((string)$r['effective_from']) : '';
                    $champNextEffectiveTo = isset($r['effective_to']) ? trim((string)$r['effective_to']) : '';
                }
            }
            if ($superId > 0) {
                $stmt = $pdoTok->prepare("SELECT price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
                $stmt->execute([$superId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && !empty($r)) {
                    $p = isset($r['price_usd_per_unit']) ? (float)$r['price_usd_per_unit'] : null;
                    if (is_float($p) && $p > 0) {
                        $superCurrentPrice = $p;
                        $superCurrentEffectiveFrom = isset($r['effective_from']) ? trim((string)$r['effective_from']) : '';
                        $superCurrentEffectiveTo = isset($r['effective_to']) ? trim((string)$r['effective_to']) : '';
                    }
                }
                $stmt = $pdoTok->prepare("SELECT price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from > NOW() ORDER BY effective_from ASC LIMIT 1");
                $stmt->execute([$superId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($r) && !empty($r)) {
                    $p = isset($r['price_usd_per_unit']) ? (float)$r['price_usd_per_unit'] : null;
                    if (is_float($p) && $p > 0) $superLatestPrice = $p;
                    $superLatestEffectiveFrom = isset($r['effective_from']) ? trim((string)$r['effective_from']) : '';
                    $superLatestEffectiveTo = isset($r['effective_to']) ? trim((string)$r['effective_to']) : '';
                }
            }
            if ($champId > 0 && function_exists('mh_tokenomics_get_balance')) {
                $b = mh_tokenomics_get_balance($pdoTok, $username, $champId);
                $champBalance = is_int($b) ? $b : 0;
            }
            if ($champId > 0) {
                $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
                $stmt->execute([$champId]);
                $issued = (int)$stmt->fetchColumn();
                $cap = max(0, (int)$champSupplyCap);
                $champRemaining = max(0, $cap - max(0, $issued));
            }
            if ($superId > 0) {
                $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
                $stmt->execute([$superId]);
                $issued = (int)$stmt->fetchColumn();
                $cap = max(0, (int)$superSupplyCap);
                $superRemaining = max(0, $cap - max(0, $issued));
            }
            if ($superId > 0 && function_exists('mh_tokenomics_get_balance')) {
                $b2 = mh_tokenomics_get_balance($pdoTok, $username, $superId);
                $superBalance = is_int($b2) ? $b2 : 0;
            }
        }
    }
} catch (Throwable) {
}

$champIssueDateText = trim((string)$champIssueDateText);
$champCloseDateText = trim((string)$champCloseDateText);
$superIssueDateText = trim((string)$superIssueDateText);
$superCloseDateText = trim((string)$superCloseDateText);
if ($champCurrentEffectiveFrom !== '') {
    $champIssueDateText = substr($champCurrentEffectiveFrom, 0, 10);
} elseif ($champIssueDateText === '' && $champNextEffectiveFrom !== '') {
    $champIssueDateText = substr($champNextEffectiveFrom, 0, 10);
}
if ($champCurrentEffectiveTo !== '') {
    $champCloseDateText = substr($champCurrentEffectiveTo, 0, 10);
} elseif ($champCloseDateText === '' && $champNextEffectiveTo !== '') {
    $champCloseDateText = substr($champNextEffectiveTo, 0, 10);
}
if ($superCurrentEffectiveFrom !== '') {
    $superIssueDateText = substr($superCurrentEffectiveFrom, 0, 10);
} elseif ($superIssueDateText === '' && $superLatestEffectiveFrom !== '') {
    $superIssueDateText = substr($superLatestEffectiveFrom, 0, 10);
}
if ($superCurrentEffectiveTo !== '') {
    $superCloseDateText = substr($superCurrentEffectiveTo, 0, 10);
} elseif ($superCloseDateText === '' && $superLatestEffectiveTo !== '') {
    $superCloseDateText = substr($superLatestEffectiveTo, 0, 10);
}

$nowTs = time();
$champIssueTs = $champIssueDateText !== '' ? strtotime($champIssueDateText) : false;
$champCloseTs = $champCloseDateText !== '' ? strtotime($champCloseDateText) : false;
$superIssueTs = $superIssueDateText !== '' ? strtotime($superIssueDateText) : false;
$superCloseTs = $superCloseDateText !== '' ? strtotime($superCloseDateText) : false;
$champIsIssued = ($champIssueTs !== false) ? ($nowTs >= (int)$champIssueTs) : ($champCurrentEffectiveFrom !== '');
$champIsClosed = ($champCloseTs !== false) ? ($nowTs >= (int)$champCloseTs) : false;
$superIsIssued = ($superIssueTs !== false) ? ($nowTs >= (int)$superIssueTs) : ($superCurrentEffectiveFrom !== '');
$superIsClosed = ($superCloseTs !== false) ? ($nowTs >= (int)$superCloseTs) : false;

$paymentMessage = null;
if (!empty($_GET['payment_success'])) {
    $paymentMessage = 'Payment successful.';
} elseif (!empty($_GET['payment_cancel'])) {
    $paymentMessage = 'Payment canceled.';
}

$minCoins = (int)floor(((float)$minUsd) / max(0.000001, (float)$champUsdPerCoin));
$superMinCoins = (is_float($superCurrentPrice) && $superCurrentPrice > 0) ? (int)floor(((float)$minUsd) / max(0.000001, (float)$superCurrentPrice)) : 0;
$superCanBuy = ($superIsIssued && !$superIsClosed && is_float($superCurrentPrice) && $superCurrentPrice > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Culture Coins</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.culture-coins main.main-content { padding: 0; }
        .culture-coins-page { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }
        .culture-coins-page h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 10px; }
        .culture-coins-page .subtitle { color: rgba(255,255,255,0.75); margin-bottom: 22px; line-height: 1.6; }
        .culture-coins-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .culture-card { background: rgba(20, 20, 25, 0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; padding: 24px; backdrop-filter: blur(12px); box-shadow: 0 8px 32px rgba(0,0,0,0.2); }
        .culture-card h2 { margin: 0 0 8px; font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
        .culture-card .ticker { color: rgba(255,255,255,0.7); font-size: 0.9rem; letter-spacing: 1px; }
        .culture-card .available-cyan { margin: 6px 0 12px; color: var(--theme-primary, #00d4ff); font-family: 'Orbitron', sans-serif; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; }
        .culture-card .meta { color: rgba(255,255,255,0.75); line-height: 1.7; }
        .culture-card .balance { margin-top: 14px; padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(0, 212, 255, 0.06); display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .culture-card .balance .label { color: rgba(255,255,255,0.7); font-size: 0.85rem; }
        .culture-card .balance .value { color: #fff; font-family: 'Orbitron', sans-serif; font-size: 1.1rem; }
        .culture-card .amount-row { margin-top: 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .culture-card .amount-row input { width: 140px; background: rgba(0, 212, 255, 0.06); border: 1px solid rgba(0, 212, 255, 0.25); border-radius: 10px; padding: 10px 12px; color: var(--theme-primary, #00d4ff); font-size: 1rem; outline: none; }
        .culture-card .amount-row .calc { color: rgba(255,255,255,0.75); }
        .culture-card button { width: 100%; margin-top: 14px; background: #635bff; border: none; padding: 14px 18px; color: #fff; border-radius: 12px; font-weight: 700; cursor: pointer; letter-spacing: 1px; }
        .culture-card button:disabled { opacity: 0.5; cursor: not-allowed; }
        .culture-card .secondary { background: transparent; border: 1px solid rgba(0, 212, 255, 0.4); color: var(--theme-primary, #00d4ff); }
        .culture-card .remainder-h1 {
            margin: 14px 0 0;
            text-align: center;
            font-family: 'Orbitron', sans-serif;
            font-weight: 900;
            color: var(--theme-primary, #00d4ff);
            letter-spacing: 1px;
            font-size: 1.15rem;
            text-shadow: 0 0 20px rgba(0, 212, 255, 0.22);
        }
        .culture-status { margin-bottom: 16px; padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(16,185,129,0.35); background: rgba(16,185,129,0.14); color: #10b981; }
        .mh-notice-modal { display:none; position:fixed; inset:0; background: rgba(0,0,0,0.78); z-index: 10010; padding: 18px; align-items:center; justify-content:center; }
        .mh-notice-card { max-width: 960px; width: 100%; background: radial-gradient(1200px 500px at 20% 0%, rgba(0,212,255,0.10), rgba(0,0,0,0)) , rgba(16,16,20,0.97); border: 1px solid rgba(0,212,255,0.28); border-radius: 18px; box-shadow: 0 18px 60px rgba(0,0,0,0.55); overflow: hidden; }
        .mh-notice-head { display:flex; justify-content:space-between; align-items:center; gap: 12px; padding: 18px 18px 14px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .mh-notice-title { margin:0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px; color: var(--theme-primary, #00d4ff); font-weight: 900; font-size: 1.35rem; }
        .mh-notice-close { cursor:pointer; background: rgba(0,0,0,0.18); color: var(--theme-primary, #00d4ff); border: 1px solid rgba(0,212,255,0.45); border-radius: 12px; padding: 10px 14px; font-weight: 900; letter-spacing: 1px; }
        .mh-notice-body { padding: 16px 18px 18px; color: rgba(255,255,255,0.90); line-height: 1.55; font-size: 1.05rem; max-height: 72vh; overflow: auto; }
        .mh-notice-body p { margin: 0 !important; }
        .mh-notice-body p + p { margin-top: 0.75em !important; }
        .mh-notice-footer { margin-top: 10px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); display:flex; gap: 12px; align-items:center; justify-content: flex-end; flex-wrap: wrap; }
        .mh-notice-play { cursor:pointer; background: var(--theme-primary, #00d4ff); color:#001018; border: 0; border-radius: 12px; padding: 10px 14px; font-weight: 900; letter-spacing: 1px; }
        .mh-notice-play.top { padding: 10px 14px; }
        @media (max-width: 700px) {
            .mh-notice-head { padding: 16px 14px 12px; }
            .mh-notice-body { padding: 14px; font-size: 1rem; max-height: 72vh; }
            .mh-notice-title { font-size: 1.15rem; }
        }
    </style>
</head>
<body class="culture-coins">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="culture-coins-page">
        <h1>CULTURE COINS</h1>
        <div class="subtitle">
            Reserve culture coins before launch. Issuance and registry will be deployed via Spark (spark.money).
        </div>

        <div id="mhCultureNoticeModal" class="mh-notice-modal" aria-hidden="true">
            <div class="mh-notice-card" role="dialog" aria-modal="true" aria-labelledby="mhCultureNoticeTitle">
                <div class="mh-notice-head">
                    <h2 id="mhCultureNoticeTitle" class="mh-notice-title">Important Notice from Meta Humans</h2>
                    <div style="display:flex; gap: 10px; align-items:center;">
                        <button type="button" id="mhCultureBankingVideoPlayTop" class="mh-notice-play top">Watch the Banking Grid Video</button>
                        <button type="button" id="mhCultureNoticeClose" class="mh-notice-close">Close</button>
                    </div>
                </div>
                <div class="mh-notice-body">
                    <p>Meta Humans sincerely apologizes for the recent disruption in coin acquisition caused by the transition to our proprietary banking grid and the discontinuation of Stripe and Brex as secondary payment gateways.</p>
                    <p>Our new banking grid will provide you with a dedicated international grid account that supports MTK tokens, stable coins, culture coins, and a wide range of other cryptocurrencies. This upgrade is scheduled for completion by June 8, 2026.</p>
                    <p>Once implemented, you will be able to seamlessly transact, redeem, trade, mint, and burn assets within your account, all with significantly reduced transaction costs. Additionally, you will have the capability to create virtual and physical Visa cards, accepted by over 175 million businesses across 36 countries.</p>
                    <p>The removal of excessive transaction fees will result in more competitive coin pricing, and digital assets will be traded in real time. Please note that at this stage, digital equity coins and equity are not yet classified as digital assets.</p>
                    <p>We appreciate your patience and continued support during this transition.</p>
                    <a id="mhCultureBankingVideoLink" href="https://metahumans.one/information/videos/meta-humans-banking-grid.mp4" style="display:none;">video</a>
                </div>
            </div>
        </div>

        <?php if ($paymentMessage): ?>
            <div id="payment-status-box" class="culture-status"><?php echo htmlspecialchars($paymentMessage); ?></div>
            <?php if (!empty($_GET['payment_success']) && !empty($_GET['session_id'])): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const box = document.getElementById('payment-status-box');
                        if (box) box.textContent = 'Verifying payment with Stripe...';
                        fetch('action.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'action=verify_my_culture_order&session_id=' + encodeURIComponent(<?php echo json_encode((string)($_GET['session_id'] ?? '')); ?>)
                        })
                        .then(r => r.json())
                        .then(d => {
                            if (!d || !d.success) {
                                const err = d && (d.error || d.message) ? String(d.error || d.message) : 'Payment verification failed.';
                                if (box) box.textContent = err;
                                return;
                            }
                            if (box) box.textContent = d.flagged ? 'Payment verified (flagged). Balance updated.' : 'Payment verified. Balance updated.';
                            setTimeout(() => { window.location.href = '/hub/coins/culture.php'; }, 800);
                        })
                        .catch(() => {
                            if (box) box.textContent = 'Payment verification failed.';
                        });
                    });
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <div class="culture-card" style="margin-bottom: 20px;">
            <h2 style="margin:0 0 10px; font-family:'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); display:flex; align-items:baseline; justify-content:space-between; gap:12px;">
                <span>YOUR PURCHASE STATUS</span>
                <span class="ticker">stripe</span>
            </h2>
            <div style="display:flex; gap: 10px; flex-wrap: wrap;">
                <button class="secondary" type="button" style="width:auto;" onclick="mhRefreshMyCultureOrders()">Refresh Status</button>
                <button type="button" style="width:auto;" onclick="mhReconcileMyCultureOrders()">Retry Credit</button>
            </div>
            <div id="mhMyCultureOrdersStatus" class="meta" style="margin-top:10px;"></div>
            <div id="mhMyCultureOrdersWrap" style="margin-top: 12px; overflow:auto; display:none;">
                <table style="width:100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align:left; border-bottom: 1px solid rgba(255,255,255,0.12);">
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Created</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">USD</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Qty</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Ticker</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Status</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Flag</th>
                            <th style="padding:10px 8px; color: rgba(255,255,255,0.7); font-weight:700; font-size: 12px; letter-spacing: 1px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="mhMyCultureOrdersBody"></tbody>
                </table>
            </div>
        </div>

        <div class="culture-coins-grid">
            <div class="culture-card" id="champcoinCard">
                <h2>
                    <span><?php echo htmlspecialchars((string)$champName, ENT_QUOTES); ?></span>
                    <span class="ticker"><?php echo htmlspecialchars((string)$champTicker, ENT_QUOTES); ?></span>
                </h2>
                <?php if (is_int($champRemaining)): ?>
                    <div class="available-cyan"><?php echo number_format((int)$champRemaining); ?> of <?php echo number_format((int)$champSupplyCap); ?> <?php echo htmlspecialchars((string)$champTicker, ENT_QUOTES); ?> available</div>
                <?php endif; ?>
                <div class="meta">
                    <div><?php echo number_format((int)$champSupplyCap); ?> max for <?php echo htmlspecialchars((string)$champName, ENT_QUOTES); ?> (<?php echo htmlspecialchars((string)$champTicker, ENT_QUOTES); ?>)</div>
                    <div>Issue date: <?php echo htmlspecialchars((string)$champIssueDateText, ENT_QUOTES); ?></div>
                    <?php if (trim((string)$champCloseDateText) !== ''): ?>
                        <div>Close date: <?php echo htmlspecialchars((string)$champCloseDateText, ENT_QUOTES); ?></div>
                    <?php endif; ?>
                    <div>Culture: marketing and fundraising (Champions)</div>
                </div>
                <div class="balance">
                    <div class="label">Your balance</div>
                    <div class="value"><?php echo number_format((int)$champBalance); ?> <?php echo htmlspecialchars((string)$champTicker, ENT_QUOTES); ?></div>
                </div>

                <div class="amount-row">
                    <input type="number" id="champAmount" value="<?php echo (int)$minUsd; ?>" min="<?php echo (int)$minUsd; ?>" step="1" inputmode="numeric" oninput="updateChampCalc()">
                    <div class="calc" id="champCalc"></div>
                </div>
                <button id="champBuyBtn" onclick="buyChampcoin()">RESERVE CHAMPION COINS</button>
                <?php if (is_int($champRemaining)): ?>
                    <h1 class="remainder-h1"><?php echo number_format((int)$champRemaining); ?> <?php echo htmlspecialchars((string)$champTicker, ENT_QUOTES); ?> REMAINING</h1>
                <?php endif; ?>
            </div>

            <div class="culture-card">
                <h2>
                    <span><?php echo htmlspecialchars((string)$superName, ENT_QUOTES); ?></span>
                    <span class="ticker"><?php echo htmlspecialchars((string)$superTicker, ENT_QUOTES); ?></span>
                </h2>
                <?php if (is_int($superRemaining)): ?>
                    <div class="available-cyan"><?php echo number_format((int)$superRemaining); ?> of <?php echo number_format((int)$superSupplyCap); ?> <?php echo htmlspecialchars((string)$superTicker, ENT_QUOTES); ?> available</div>
                <?php endif; ?>
                <div class="meta">
                    <div><?php echo number_format((int)$superSupplyCap); ?> max for <?php echo htmlspecialchars((string)$superName, ENT_QUOTES); ?> (<?php echo htmlspecialchars((string)$superTicker, ENT_QUOTES); ?>)</div>
                    <div>Issue date: <?php echo htmlspecialchars((string)$superIssueDateText, ENT_QUOTES); ?></div>
                    <?php if (trim((string)$superCloseDateText) !== ''): ?>
                        <div>Close date: <?php echo htmlspecialchars((string)$superCloseDateText, ENT_QUOTES); ?></div>
                    <?php endif; ?>
                    <?php if (is_float($superCurrentPrice) && $superCurrentPrice > 0 && $superCurrentEffectiveFrom !== ''): ?>
                        <div>Current price: $<?php echo number_format((float)$superCurrentPrice, 2, '.', ''); ?> (from <?php echo htmlspecialchars((string)$superCurrentEffectiveFrom, ENT_QUOTES); ?><?php echo ($superCurrentEffectiveTo !== '' ? (' to ' . htmlspecialchars((string)$superCurrentEffectiveTo, ENT_QUOTES)) : ''); ?>)</div>
                    <?php elseif (is_float($superLatestPrice) && $superLatestPrice > 0 && $superLatestEffectiveFrom !== ''): ?>
                        <div>Next price: $<?php echo number_format((float)$superLatestPrice, 2, '.', ''); ?> (from <?php echo htmlspecialchars((string)$superLatestEffectiveFrom, ENT_QUOTES); ?><?php echo ($superLatestEffectiveTo !== '' ? (' to ' . htmlspecialchars((string)$superLatestEffectiveTo, ENT_QUOTES)) : ''); ?>)</div>
                    <?php endif; ?>
                </div>
                <div class="balance">
                    <div class="label">Your balance</div>
                    <div class="value"><?php echo number_format((int)$superBalance); ?> <?php echo htmlspecialchars((string)$superTicker, ENT_QUOTES); ?></div>
                </div>
                <?php if ($superCanBuy): ?>
                    <div class="amount-row">
                        <input id="superAmount" type="number" min="<?php echo (int)$minUsd; ?>" step="1" value="<?php echo (int)$minUsd; ?>" oninput="updateSuperCalc()">
                        <div id="superCalc" class="calc"></div>
                    </div>
                    <button id="superBuyBtn" onclick="buySupercoin()">BUY SUPER COINS</button>
                <?php else: ?>
                    <button class="secondary" disabled><?php echo ($superIsIssued && !$superIsClosed) ? 'NOT PRICED' : 'COMING SOON'; ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    const MH_CHAMP_USD_PER_COIN = <?php echo json_encode((float)$champUsdPerCoin); ?>;
    const MH_CHAMP_MIN_USD = <?php echo json_encode((int)$minUsd); ?>;
    const MH_CHAMP_TICKER = <?php echo json_encode((string)$champTicker); ?>;
    const MH_SUPER_USD_PER_COIN = <?php echo json_encode((float)($superCurrentPrice ?? 0.0)); ?>;
    const MH_SUPER_MIN_USD = <?php echo json_encode((int)$minUsd); ?>;
    const MH_SUPER_MIN_COINS = <?php echo json_encode((int)$superMinCoins); ?>;
    const MH_SUPER_TICKER = <?php echo json_encode((string)$superTicker); ?>;

    function updateChampCalc() {
        const el = document.getElementById('champAmount');
        const calc = document.getElementById('champCalc');
        const amount = Math.max(0, parseInt(el && el.value ? el.value : '0', 10) || 0);
        const price = (typeof MH_CHAMP_USD_PER_COIN === 'number' && MH_CHAMP_USD_PER_COIN > 0) ? MH_CHAMP_USD_PER_COIN : 0.25;
        const coins = Math.floor(amount / price);
        const t = (typeof MH_CHAMP_TICKER === 'string' && MH_CHAMP_TICKER.trim() !== '') ? MH_CHAMP_TICKER.trim() : 'mhc';
        if (calc) calc.textContent = `$${amount} → ${coins.toLocaleString()} ${t} (${price.toFixed(2)}/coin)`;
    }

    function buyChampcoin() {
        const el = document.getElementById('champAmount');
        const btn = document.getElementById('champBuyBtn');
        const amount = Math.max(0, parseInt(el && el.value ? el.value : '0', 10) || 0);
        if (amount < MH_CHAMP_MIN_USD) {
            if (el) el.value = String(MH_CHAMP_MIN_USD);
            updateChampCalc();
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'PROCESSING...'; }
        fetch('action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=buy_champcoin&amount=' + encodeURIComponent(String(amount))
        })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success || !d.redirect_url) throw new Error('stripe_error');
            window.location.href = d.redirect_url;
        })
        .catch(() => {
            if (btn) { btn.disabled = false; btn.textContent = 'RESERVE CHAMPION COINS'; }
        });
    }

    function updateSuperCalc() {
        const el = document.getElementById('superAmount');
        const calc = document.getElementById('superCalc');
        if (!el || !calc) return;
        const amount = Math.max(0, parseInt(el && el.value ? el.value : '0', 10) || 0);
        const price = (typeof MH_SUPER_USD_PER_COIN === 'number' && MH_SUPER_USD_PER_COIN > 0) ? MH_SUPER_USD_PER_COIN : 0;
        const coins = price > 0 ? Math.floor(amount / price) : 0;
        const t = (typeof MH_SUPER_TICKER === 'string' && MH_SUPER_TICKER.trim() !== '') ? MH_SUPER_TICKER.trim() : 'mhs';
        calc.textContent = price > 0 ? `$${amount} → ${coins.toLocaleString()} ${t} (${price.toFixed(2)}/coin)` : 'Price not available';
    }

    function buySupercoin() {
        const el = document.getElementById('superAmount');
        const btn = document.getElementById('superBuyBtn');
        const amount = Math.max(0, parseInt(el && el.value ? el.value : '0', 10) || 0);
        if (amount < MH_SUPER_MIN_USD) {
            if (el) el.value = String(MH_SUPER_MIN_USD);
            updateSuperCalc();
            return;
        }
        if (typeof MH_SUPER_USD_PER_COIN !== 'number' || MH_SUPER_USD_PER_COIN <= 0) return;
        const coins = Math.floor(amount / MH_SUPER_USD_PER_COIN);
        if (coins < MH_SUPER_MIN_COINS || coins < 1) {
            if (el) el.value = String(MH_SUPER_MIN_USD);
            updateSuperCalc();
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = 'PROCESSING...'; }
        fetch('action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'action=buy_supercoin&amount=' + encodeURIComponent(String(amount))
        })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success || !d.redirect_url) throw new Error('stripe_error');
            window.location.href = d.redirect_url;
        })
        .catch(() => {
            if (btn) { btn.disabled = false; btn.textContent = 'BUY SUPER COINS'; }
        });
    }

    document.addEventListener('DOMContentLoaded', () => { updateChampCalc(); updateSuperCalc(); });
</script>
<script>
    function mhSetMyCultureOrdersStatus(kind, text) {
        const el = document.getElementById('mhMyCultureOrdersStatus');
        if (!el) return;
        el.style.color = (kind === 'bad') ? '#ff3b30' : (kind === 'ok' ? '#10b981' : 'rgba(255,255,255,0.75)');
        el.textContent = text || '';
    }

    function mhRenderMyCultureOrders(orders) {
        const wrap = document.getElementById('mhMyCultureOrdersWrap');
        const body = document.getElementById('mhMyCultureOrdersBody');
        if (!wrap || !body) return;
        body.innerHTML = '';
        if (!Array.isArray(orders) || orders.length === 0) {
            wrap.style.display = 'none';
            return;
        }
        wrap.style.display = 'block';
        for (const o of orders) {
            const createdAt = String((o && o.created_at) ? o.created_at : '');
            const usd = String((o && o.amount_usd) ? o.amount_usd : '');
            const qty = String((o && o.qty_expected) ? o.qty_expected : '');
            const ticker = String((o && o.ticker) ? o.ticker : '');
            const st = String((o && o.status) ? o.status : '');
            const flagged = (o && (o.flagged === 1 || o.flagged === '1' || o.flagged === true));
            const flagReason = String((o && o.flag_reason) ? o.flag_reason : '');
            const lastError = String((o && o.last_error) ? o.last_error : '');
            const sid = String((o && o.session_id) ? o.session_id : '');

            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid rgba(255,255,255,0.08)';

            const mk = (txt) => {
                const td = document.createElement('td');
                td.style.padding = '10px 8px';
                td.style.color = 'rgba(255,255,255,0.85)';
                td.textContent = txt;
                return td;
            };
            tr.appendChild(mk(createdAt));
            tr.appendChild(mk(usd));
            tr.appendChild(mk(qty ? Number(qty).toLocaleString() : ''));
            tr.appendChild(mk(ticker));
            tr.appendChild(mk(st));
            tr.appendChild(mk(flagged ? (flagReason || 'flagged') : ''));

            const tdAct = document.createElement('td');
            tdAct.style.padding = '10px 8px';
            if (st !== 'credited' && sid) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'secondary';
                btn.style.width = 'auto';
                btn.style.marginTop = '0';
                btn.textContent = 'Verify';
                btn.onclick = async function() {
                    mhSetMyCultureOrdersStatus('ok', 'Verifying payment...');
                    try {
                        const resp = await fetch('action.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'action=verify_my_culture_order&session_id=' + encodeURIComponent(sid)
                        });
                        const d = await resp.json();
                        if (d && d.success) {
                            mhSetMyCultureOrdersStatus('ok', d.flagged ? 'Payment verified and credited (flagged).' : 'Payment verified and credited.');
                            setTimeout(() => window.location.reload(), 1000);
                            return;
                        }
                        const err = (d && (d.error || d.message)) ? String(d.error || d.message) : 'verify_failed';
                        mhSetMyCultureOrdersStatus('bad', 'Verify failed: ' + err);
                        mhRefreshMyCultureOrders();
                    } catch (e) {
                        mhSetMyCultureOrdersStatus('bad', 'Verify failed.');
                    }
                };
                tdAct.appendChild(btn);
            } else {
                const span = document.createElement('span');
                span.style.color = 'rgba(255,255,255,0.65)';
                span.textContent = lastError ? lastError : '—';
                tdAct.appendChild(span);
            }
            tr.appendChild(tdAct);
            body.appendChild(tr);
        }
    }

    async function mhRefreshMyCultureOrders() {
        mhSetMyCultureOrdersStatus('ok', 'Loading purchase status...');
        try {
            const resp = await fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=my_culture_orders'
            });
            const d = await resp.json();
            if (d && d.success) {
                const orders = Array.isArray(d.orders) ? d.orders : [];
                mhRenderMyCultureOrders(orders);
                const pending = orders.filter(o => o && String(o.status || '') !== 'credited').length;
                if (orders.length === 0) mhSetMyCultureOrdersStatus('', '');
                else if (pending > 0) mhSetMyCultureOrdersStatus('bad', 'You have ' + pending + ' purchase(s) not credited yet. Use Verify/Retry Credit.');
                else mhSetMyCultureOrdersStatus('ok', 'All recent culture coin purchases are credited.');
                return;
            }
            mhSetMyCultureOrdersStatus('bad', 'Status unavailable.');
        } catch (e) {
            mhSetMyCultureOrdersStatus('bad', 'Status unavailable.');
        }
    }

    async function mhReconcileMyCultureOrders() {
        mhSetMyCultureOrdersStatus('ok', 'Retrying credit for pending purchases...');
        try {
            const resp = await fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=reconcile_my_culture_orders'
            });
            const d = await resp.json();
            if (d && d.success) {
                mhSetMyCultureOrdersStatus('ok', 'Checked ' + (d.checked || 0) + ' · Credited ' + (d.credited || 0) + (d.failed ? (' · Failed ' + d.failed) : '') + '. Reloading...');
                setTimeout(() => window.location.reload(), 1200);
                return;
            }
            mhSetMyCultureOrdersStatus('bad', 'Retry failed.');
            mhRefreshMyCultureOrders();
        } catch (e) {
            mhSetMyCultureOrdersStatus('bad', 'Retry failed.');
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        mhRefreshMyCultureOrders();
    });
</script>
<script src="/gear/players/mp4-modal.js"></script>
<script>
(function() {
    var modal = document.getElementById('mhCultureNoticeModal');
    var closeBtn = document.getElementById('mhCultureNoticeClose');
    var link = document.getElementById('mhCultureBankingVideoLink');
    var playBtn = document.getElementById('mhCultureBankingVideoPlay');
    var playTopBtn = document.getElementById('mhCultureBankingVideoPlayTop');
    if (modal) {
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }
        });
    }
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        });
    }
    function openVideo() {
        if (!link) return;
        var href = link.getAttribute('href') || '';
        if (window.MHPlayers && typeof window.MHPlayers.openMp4 === 'function') {
            window.MHPlayers.openMp4(href, { title: 'Meta Humans Banking Grid' });
        } else {
            window.open(href, '_blank', 'noopener');
        }
    }
    if (link) {
        link.addEventListener('click', function(e) { e.preventDefault(); openVideo(); });
    }
    if (playBtn) {
        playBtn.addEventListener('click', function() { openVideo(); });
    }
    if (playTopBtn) {
        playTopBtn.addEventListener('click', function() { openVideo(); });
    }
})();
</script>
</body>
</html>
