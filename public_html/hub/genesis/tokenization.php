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
    header('Location: /auth/login.php');
    exit;
}

$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
$tokenBalance = null;
if ($username !== '' && function_exists('mh_get_token_balance')) {
    $tokenBalance = mh_get_token_balance($username);
}
if (!is_int($tokenBalance)) {
    $tokenBalance = isset($_SESSION['tokens']) ? (int)$_SESSION['tokens'] : 0;
}

$paymentMessage = null;
if (!empty($_GET['payment_success'])) {
    $paymentMessage = 'Payment successful.';
} elseif (!empty($_GET['payment_cancel'])) {
    $paymentMessage = 'Payment canceled.';
}

$usdPerToken = 0.0;
$bonusStartUsd = 100;
$bonusBasePct = 5.0;
$bonusStepUsd = 50;
$bonusStepPct = 1.0;
try {
    if (function_exists('mh_tokenomics_get_tokenomics_pdo')) {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (function_exists('mh_tokenomics_ensure_schema')) {
                mh_tokenomics_ensure_schema($pdoTok);
            }
            $utilityId = function_exists('mh_tokenomics_seed_utility_token') ? (int)mh_tokenomics_seed_utility_token($pdoTok) : 0;
            if ($utilityId > 0) {
                $stmt = $pdoTok->prepare("SELECT price_usd_per_unit FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
                $stmt->execute([$utilityId]);
                $usdPerToken = (float)$stmt->fetchColumn();

                $stmt = $pdoTok->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$utilityId]);
                $raw = $stmt->fetchColumn();
                if ($raw !== false && is_string($raw) && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && isset($decoded['bonus_scale']) && is_array($decoded['bonus_scale'])) {
                        $bs = $decoded['bonus_scale'];
                        if (isset($bs['start_usd'])) $bonusStartUsd = max(1, (int)$bs['start_usd']);
                        if (isset($bs['base_bonus_pct'])) $bonusBasePct = max(0.0, min(100.0, (float)$bs['base_bonus_pct']));
                        if (isset($bs['step_usd'])) $bonusStepUsd = max(1, (int)$bs['step_usd']);
                        if (isset($bs['step_bonus_pct'])) $bonusStepPct = max(0.0, min(100.0, (float)$bs['step_bonus_pct']));
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    $usdPerToken = 0.0;
}
if ($usdPerToken <= 0) {
    $usdPerToken = 49.0 / 1500.0;
}

$defaultAmountUsd = 49;
$defaultTokens = (int)floor(((float)$defaultAmountUsd) / $usdPerToken);
if ($defaultTokens < 1) {
    $defaultTokens = 1500;
}
$minTokensRequired = max(1, (int)$defaultTokens);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genesis: Tokenization</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
    <style>
        body.genesis-tokenization main.main-content {
            display: flex;
            padding: 0;
        }
        body.genesis-tokenization .tokenization-page {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 15px;
            color: var(--theme-primary, #00d4ff);
            font-family: var(--font-primary, 'Rajdhani', sans-serif);
        }
        .tokenization-card {
            background: rgba(255,255,255,0.05);
            padding: 40px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            text-align: center;
            max-width: 520px;
            width: 100%;
            box-shadow: var(--shadow-card, 0 0 20px rgba(0, 212, 255, 0.1));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .tokenization-card h1 { margin-bottom: 20px; font-weight: 300; letter-spacing: 2px; }
        .tokenization-card .price { font-size: 32px; color: #fff; margin: 20px 0; }
        .tokenization-card .token-stats { margin-bottom: 30px; padding: 20px; background: rgba(0, 212, 255, 0.1); border-radius: 8px; border: 1px solid rgba(0, 212, 255, 0.3); }
        .tokenization-card .amount-input { display: inline-flex; align-items: center; gap: 8px; margin: 10px auto 0; background: rgba(0, 212, 255, 0.08); border: 1px solid rgba(0, 212, 255, 0.25); border-radius: 8px; padding: 8px 12px; }
        .tokenization-card .amount-input .currency { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 1.2em; }
        .tokenization-card .amount-input .amount-field { width: 100px; text-align: center; background: transparent; border: none; color: var(--theme-primary, #00d4ff); font-size: 1.2em; outline: none; }
        /* Remove number input spinners */
        .tokenization-card .amount-input .amount-field::-webkit-outer-spin-button,
        .tokenization-card .amount-input .amount-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .tokenization-card .amount-input .amount-field { -moz-appearance: textfield; -webkit-appearance: none; appearance: none; }
        .tokenization-card .amount-notes { margin-top: 10px; color: rgba(255,255,255,0.7); font-size: 0.95em; line-height: 1.4; }
        .tokenization-card .bonus-achievement {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(16, 185, 129, 0.45);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.12), rgba(0, 212, 255, 0.08));
            color: rgba(255,255,255,0.92);
            text-align: left;
            display: none;
            box-shadow: 0 0 25px rgba(16, 185, 129, 0.12);
        }
        .tokenization-card .bonus-achievement .title {
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 1px;
            color: rgba(120,255,180,.95);
            font-weight: 800;
            margin-bottom: 6px;
        }
        .tokenization-card .bonus-achievement .line {
            font-weight: 800;
            color: #fff;
            font-size: 1.05rem;
            margin-bottom: 2px;
        }
        .tokenization-card .bonus-achievement .sub {
            color: rgba(255,255,255,0.78);
            line-height: 1.55;
        }
        .tokenization-card p { color: rgba(255,255,255,0.7); margin-bottom: 30px; line-height: 1.6; }
        .tokenization-card .token-notice, .tokenization-card .token-notice * { color: var(--theme-primary, #00d4ff) !important; }
        .tokenization-card button { background: #635bff; border: none; padding: 15px 30px; color: white; font-weight: bold; border-radius: 6px; cursor: pointer; font-size: 16px; transition: transform 0.2s; width: 100%; margin-top: 10px; }
        .tokenization-card button:hover { transform: scale(1.05); }
        .tokenization-card .secondary-btn { background: transparent; border: 1px solid var(--theme-primary, #00d4ff); color: var(--theme-primary, #00d4ff); }
        .tokenization-card .secondary-btn:hover { background: rgba(0, 212, 255, 0.1); }
    </style>
</head>
<body class="genesis-tokenization">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="tokenization-page">
    <div class="tokenization-card">
        <h1>TOKENIZATION</h1>
        
        <?php
            // Refresh the auth-backed session user so the token balance is fresh.
            if (isset($_SESSION['mh_auth_user']) && function_exists('mh_auth_load_user_context')) {
                mh_auth_load_user_context($_SESSION['mh_auth_user']);
            }
        ?>
        
        <?php if ($paymentMessage): ?>
            <div id="payment-status-box" style="background: rgba(16, 185, 129, 0.2); color: #10b981; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid rgba(16, 185, 129, 0.4);">
                <?php echo htmlspecialchars($paymentMessage); ?>
            </div>
            
            <?php if (!empty($_GET['payment_success']) && !empty($_GET['session_id'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const statusBox = document.getElementById('payment-status-box');
                    statusBox.innerHTML = 'Verifying payment with Stripe...';
                    
                    fetch('action.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'action=verify_payment&session_id=<?php echo htmlspecialchars($_GET['session_id']); ?>'
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            statusBox.innerHTML = 'Payment verified! Your tokens have been credited. Activating your account...';
                            setTimeout(() => {
                                if (typeof window.completeGenesis === 'function') {
                                    window.completeGenesis();
                                } else {
                                    window.location.href = '/hub/index.php';
                                }
                            }, 600);
                        } else {
                            statusBox.style.background = 'rgba(239, 68, 68, 0.2)';
                            statusBox.style.color = '#ef4444';
                            statusBox.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                            statusBox.innerHTML = 'Payment verification failed: ' + (d.error || 'Unknown error');
                        }
                    })
                    .catch(e => {
                        statusBox.innerHTML = 'Error verifying payment. Please contact support.';
                    });
                });
            </script>
            <?php endif; ?>
        <?php endif; ?>

        <div class="token-stats">
            <div style="display:flex; justify-content:space-between; gap: 12px; flex-wrap:wrap; align-items:center; margin-bottom: 14px;">
                <div style="color: rgba(255,255,255,0.85); font-weight:700;">Your MTK (Tokens) Balance</div>
                <div style="color:#fff; font-family:'Orbitron', sans-serif; font-size: 1.05rem;"><?php echo number_format((int)$tokenBalance); ?> MTK</div>
            </div>

        <div class="price" id="priceDisplay">$<?php echo number_format((float)$defaultAmountUsd, 2, '.', ''); ?> USD <span style="font-size: 0.5em; color: #aaa;">/ <?php echo number_format((int)$defaultTokens); ?> MTK</span></div>

            <div class="amount-input">
                <span class="currency">$</span>
            <input type="number" id="amount" value="<?php echo (int)$defaultAmountUsd; ?>" min="<?php echo (int)$defaultAmountUsd; ?>" step="1" class="amount-field" oninput="updatePrice()">
            </div>
            <div class="amount-notes">
            <div>Minimum purchase: <strong>$<?php echo (int)$defaultAmountUsd; ?></strong> (<?php echo number_format((int)$defaultTokens); ?> MTK tokens)</div>
                <div>Buy more and get discount. For slots of $50 there is a discount, e.g. $100 +5% and every $50 thereafter the 5% + 1%. Maximum discount is 20% in total.</div>
                <div>Tokens do not expire and can be freely converted or traded</div>
            </div>
            <div class="bonus-achievement" id="bonusAchievement"></div>
        </div>

        <button onclick="purchaseTokens()">BUY TOKENS</button>
        <?php if ((int)$tokenBalance >= (int)$minTokensRequired): ?>
            <button class="secondary-btn" onclick="completeGenesis()">CONTINUE TO HUB</button>
        <?php endif; ?>

            <div class="token-notice" style="background: rgba(255, 120, 120, 0.16); border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.40); color: #00d4ff !important; line-height: 1.75; font-size: 0.95em; text-align: left; margin-top: 18px; padding: 16px 18px;">
                <p style="margin: 0 0 10px; color: #00d4ff !important;">
                    To start using your Meta Human ecosystem and create your Genesis Block, you need to have a minimum amount of tokens. These tokens are called MTK (Meta Tokens).
                </p>
                <p style="margin: 0 0 10px; color: #00d4ff !important;">
                    The MTK tokens give you full access to all the services offered by Meta Humans, including the ability to create your Meta Human persona, your use of a persona, and all of the other services on this platform.
                </p>
                <p style="margin: 0 0 10px; color: #00d4ff !important;">
                    Once you complete this payment, you will be able to use the platform to manage wallets, coins, shares, and other types of crypto assets.
                </p>
                <p style="margin: 0 0 10px; color: #00d4ff !important;">
                    The amount you pay is permanent — it never expires, and is not a monthly subscription, you just topup if you run out of tokens. 1 Token = 50 credits. Credits are used for every action you perform.
                </p>
                <p style="margin: 0; color: #00d4ff !important;">
                    You can also exchange or trade these tokens freely for popular meme or culture coins when available. Additionally, this payment will allow you to open your crypto bank account when the bank launches in September 2026. Your account will come with both virtual and physical cards.
                </p>
            </div>
    </div>
    </div>
    </main>
    <script src="/templates/widgets/notices/popup-notice.js"></script>
    <script>
        const MH_USD_PER_TOKEN = <?php echo json_encode((float)$usdPerToken); ?>;
        const MH_MIN_USD = <?php echo json_encode((int)$defaultAmountUsd); ?>;
        const MH_BONUS_START_USD = <?php echo json_encode((int)$bonusStartUsd); ?>;
        const MH_BONUS_BASE_PCT = <?php echo json_encode((float)$bonusBasePct); ?>;
        const MH_BONUS_STEP_USD = <?php echo json_encode((int)$bonusStepUsd); ?>;
        const MH_BONUS_STEP_PCT = <?php echo json_encode((float)$bonusStepPct); ?>;
        const MH_MAX_BONUS_PCT = 20;

        function mhNotice(message, type = 'info', options = {}) {
            try {
                if (!window._mhPopupNotice && typeof window.PopupNotice !== 'undefined') {
                    window._mhPopupNotice = new PopupNotice({ position: 'bottom-left', theme: 'minimal' });
                }
                if (window._mhPopupNotice) {
                    return window._mhPopupNotice.show(message, type, options);
                }
            } catch (_) {}
            return null;
        }

        function updatePrice() {
            const amount = parseInt(document.getElementById('amount').value) || 0;
            const per = (typeof MH_USD_PER_TOKEN === 'number' && MH_USD_PER_TOKEN > 0) ? MH_USD_PER_TOKEN : (49 / 1500);
            const baseTokens = Math.floor(amount / per);

            const startUsd = (typeof MH_BONUS_START_USD === 'number' && MH_BONUS_START_USD > 0) ? MH_BONUS_START_USD : 100;
            const basePct = (typeof MH_BONUS_BASE_PCT === 'number' && MH_BONUS_BASE_PCT >= 0) ? MH_BONUS_BASE_PCT : 5;
            const stepUsd = (typeof MH_BONUS_STEP_USD === 'number' && MH_BONUS_STEP_USD > 0) ? MH_BONUS_STEP_USD : 50;
            const stepPct = (typeof MH_BONUS_STEP_PCT === 'number' && MH_BONUS_STEP_PCT >= 0) ? MH_BONUS_STEP_PCT : 1;

            let bonusPct = 0;
            if (amount >= startUsd) {
                const steps = Math.floor((amount - startUsd) / Math.max(1, stepUsd));
                bonusPct = Math.max(0, basePct + (steps * stepPct));
            }
            bonusPct = Math.min(bonusPct, (typeof MH_MAX_BONUS_PCT === 'number' && MH_MAX_BONUS_PCT > 0) ? MH_MAX_BONUS_PCT : 20);
            const bonusTokens = Math.floor(baseTokens * (bonusPct / 100));
            const totalTokens = baseTokens + bonusTokens;

            const bonusSpan = bonusTokens > 0 ? ` <span style="font-size: 0.52em; color: rgba(120,255,180,.95); font-weight: 800;">(+${bonusTokens.toLocaleString()} bonus)</span>` : '';
            document.getElementById('priceDisplay').innerHTML = `$${amount}.00 USD <span style="font-size: 0.5em; color: #aaa;">/ ${totalTokens.toLocaleString()} MTK</span>${bonusSpan}`;

            const box = document.getElementById('bonusAchievement');
            if (box && bonusTokens > 0) {
                const bonusCredits = bonusTokens * 50;
                box.style.display = 'block';
                box.innerHTML = `
                    <div class="title">ACHIEVEMENT UNLOCKED</div>
                    <div class="line">+${bonusTokens.toLocaleString()} MTK BONUS (+${bonusCredits.toLocaleString()} credits)</div>
                    <div class="sub">You are receiving ${bonusPct.toFixed(1)}% more tokens at this level.</div>
                `;
            } else if (box) {
                box.style.display = 'none';
                box.innerHTML = '';
            }
        }

        function purchaseTokens() {
            const amount = parseInt(document.getElementById('amount').value) || 0;
            const minUsd = (typeof MH_MIN_USD === 'number' && MH_MIN_USD > 0) ? MH_MIN_USD : 49;
            if (amount < minUsd) {
                const el = document.getElementById('amount');
                if (el) el.value = String(minUsd);
                updatePrice();
                mhNotice(`Minimum purchase amount is $${minUsd}.`, 'warning');
                return;
            }
            
            // Call Action to Create Stripe Session
            const btn = document.querySelector('button');
            const originalText = btn.innerText;
            btn.innerText = 'PROCESSING...';
            btn.disabled = true;

            fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=buy_tokens&amount=${amount}`
            })
            .then(r => r.json())
            .then(d => {
                if(d.success && d.redirect_url) {
                    window.location.href = d.redirect_url;
                } else {
                    mhNotice('Error: ' + (d.error || 'Unknown error'), 'error');
                    btn.innerText = originalText;
                    btn.disabled = false;
                }
            })
            .catch(e => {
                console.error(e);
                mhNotice('Network error occurred.', 'error');
                btn.innerText = originalText;
                btn.disabled = false;
            });
        }

        function completeGenesis() {
            const btns = document.querySelectorAll('button');
            btns.forEach(b => b.disabled = true);
            fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=complete_tokenization'
            })
            .then(r => r.json())
            .then(d => {
                if (d && d.success && d.next) {
                    window.location.href = d.next;
                    return;
                }
                window.location.href = '/hub/index.php';
            })
            .catch(() => {
                window.location.href = '/hub/index.php';
            });
        }
        document.addEventListener('DOMContentLoaded', updatePrice);
        window.completeGenesis = completeGenesis;
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
