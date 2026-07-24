<?php
// Hub Wallet - Wallet Wealth
// Asset Classes:
// 1. Equity Coins (Security)
// 2. Utility Token (Access)
// 3. Wealth Coins (Meme/Culture)
// 4. Corporate Stablecoin (Settlement)

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
if (is_file(__DIR__ . '/../auth/tokenomics.php')) {
    require_once __DIR__ . '/../auth/tokenomics.php';
}

// Ensure user is logged in
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

if (function_exists('mh_refresh_session_token_balance')) {
    mh_refresh_session_token_balance((string)$_SESSION['mh_auth_user'], 30);
} elseif (function_exists('mh_get_token_balance')) {
    $bal = mh_get_token_balance((string)$_SESSION['mh_auth_user']);
    if (is_int($bal)) $_SESSION['tokens'] = $bal;
}

// Load Equity Helper
require_once __DIR__ . '/equity/db.php';

// Fetch Equity Balance from Ledger
$equity_units = 0;
$share_holding = 0;
try {
    // Connect to Dedicated Equity Database (Port 3307)
    $pdoEquity = getEquityConnection();
    
    if ($pdoEquity) {
        // Sum units owned by user
        $stmt = $pdoEquity->prepare("SELECT SUM(units_owned) FROM equity_ledger WHERE username = ?");
        $stmt->execute([$_SESSION['mh_auth_user']]);
        $equity_units = (int)$stmt->fetchColumn();
        
        // Calculate Share Holding
        $stmt = $pdoEquity->prepare("
            SELECT SUM(l.units_owned / c.fractional_units_per_share) 
            FROM equity_ledger l 
            JOIN equity_classes c ON l.class_id = c.id 
            WHERE l.username = ?
        ");
        $stmt->execute([$_SESSION['mh_auth_user']]);
        $share_holding = (float)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    // Fail silently or log
    error_log("Wallet Equity Fetch Error: " . $e->getMessage());
}

// Placeholder for fetching wallet data
$equity_coins = $equity_units; 
$utility_tokens = $_SESSION['tokens'] ?? 0;
$wealth_coins = 0;
$stablecoin = 0.00; // Fetch from DB

try {
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($u !== '' && function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_ensure_schema')) {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            mh_tokenomics_ensure_schema($pdoTok);
            if (function_exists('mh_tokenomics_seed_culture_coins')) {
                mh_tokenomics_seed_culture_coins($pdoTok);
            }
            $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(l.units_owned), 0) FROM mh_asset_ledger l JOIN mh_asset_classes c ON c.id = l.asset_class_id WHERE l.username = ? AND c.asset_type = 'culture'");
            $stmt->execute([$u]);
            $wealth_coins = (int)$stmt->fetchColumn();
        }
    }
} catch (Throwable) {
    $wealth_coins = 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wallet Wealth | Meta Humans Hub</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.wallet-page main.main-content {
            color: rgba(255,255,255,0.9);
            font-family: var(--font-primary, 'Rajdhani', sans-serif);
        }

        body.wallet-page .wallet-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
            width: 100%;
            background: transparent !important;
            box-sizing: border-box;
        }

        body.wallet-page h1 {
            font-family: 'Orbitron', sans-serif;
            color: var(--theme-primary, #00d4ff);
            text-transform: uppercase;
            letter-spacing: 2px;
            text-align: center;
            margin-bottom: 40px;
            text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
        }

        body.wallet-page .assets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }

        body.wallet-page .asset-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(0, 212, 255, 0.2);
            border-radius: 12px;
            padding: 25px;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        body.wallet-page .asset-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.15);
        }

        body.wallet-page .asset-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.2rem;
            color: var(--theme-primary, #00d4ff);
            margin-bottom: 10px;
            border-bottom: 1px solid rgba(0, 212, 255, 0.2);
            padding-bottom: 10px;
        }

        body.wallet-page .asset-type {
            font-size: 0.9rem;
            color: var(--theme-primary, #00d4ff);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }

        body.wallet-page .asset-balance {
            font-size: 2.5rem;
            font-weight: 700;
            color: #fff;
            margin: 15px 0;
        }

        body.wallet-page .asset-desc {
            font-size: 0.95rem;
            color: #aaa;
            line-height: 1.5;
        }

        body.wallet-page .action-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: transparent;
            border: 1px solid var(--theme-primary, #00d4ff);
            color: var(--theme-primary, #00d4ff);
            text-decoration: none;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 1px;
            transition: all 0.3s;
            cursor: pointer;
        }

        body.wallet-page .action-btn:hover {
            background: var(--theme-primary, #00d4ff);
            color: #000;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
        }

        body.wallet-page .txn-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        body.wallet-page .txn-table th, body.wallet-page .txn-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); font-size: 0.95rem; }
        body.wallet-page .txn-table th { color: var(--theme-primary, #00d4ff); font-weight: 600; }
        body.wallet-page .txn-muted { color: #9aa; font-size: 0.9rem; }
        body.wallet-page .recon-row { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        body.wallet-page .recon-row input { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.25); color: #fff; padding: 10px 12px; border-radius: 10px; min-width: 220px; }
        body.wallet-page .recon-status { margin-top: 10px; font-size: 0.9rem; color: #9aa; }
    </style>
</head>
<body class="wallet-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="wallet-container">
    <h1>Wallet Wealth</h1>
    
    <div class="assets-grid">
        <!-- 1. Equity Coins -->
        <div class="asset-card">
            <div class="asset-title">Equity Coins</div>
            <div class="asset-type">Security</div>
            <div class="asset-balance"><?php echo number_format($equity_coins); ?></div>
            <div class="asset-desc" style="margin-bottom: 10px;">
                <strong>Holding:</strong> <?php echo number_format($share_holding, 2); ?> Shares
            </div>
            <div class="asset-desc">
                Digitized Delaware C-Corp shares (Regulation D/S compliant). Represents ownership in the Meta Humans ecosystem.
            </div>
            <a href="/hub/equity/manage.php" class="action-btn">Manage Equity</a>
        </div>

        <!-- 2. Utility Token -->
        <div class="asset-card">
            <div class="asset-title">MTK Tokens <p>(Not yet tokenized on Dex)</p></div>
            <div class="asset-type">Access</div>
            <div class="asset-balance"><?php echo number_format($utility_tokens); ?></div>
            <div class="asset-desc">
                "Gas" token for accessing Meta Human AI services, creating personas, and platform interactions.
            </div>
            <a href="/hub/genesis/tokenization.php" class="action-btn">Buy Tokens</a>
            <a href="/hub/tokens/tokens.php" class="action-btn">MTK Token Dashboard</a>
        </div>

        <!-- 3. Wealth Coins -->
        <div class="asset-card">
            <div class="asset-title">Wealth Coins</div>
            <div class="asset-type">Meme / Culture</div>
            <div class="asset-balance"><?php echo number_format($wealth_coins); ?></div>
            <div class="asset-desc">
                High-volatility asset for community rewards, cultural engagement, and potential wealth generation.
            </div>
            <a href="/hub/coins/culture.php" class="action-btn">Trade</a>
        </div>

        <!-- 4. Corporate Stablecoin -->
        <div class="asset-card">
            <div class="asset-title">Corporate Stablecoin</div>
            <div class="asset-type">Settlement</div>
            <div class="asset-balance">$<?php echo number_format($stablecoin, 2); ?></div>
            <div class="asset-desc">
                Fiat-backed store of value for internal payments, settlements, and stable transactions.
            </div>
            <a href="#" class="action-btn">Top Up</a>
        </div>
    </div>
</div>
 </main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
