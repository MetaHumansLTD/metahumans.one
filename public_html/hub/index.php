<?php
/**
 * Meta Humans Hub Controller
 * Handles routing based on User Genesis Status
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
if (is_file(__DIR__ . '/../auth/tokenomics.php')) {
    require_once __DIR__ . '/../auth/tokenomics.php';
}
require_once __DIR__ . '/benefactors/lib.php';

// Force load theme module
if (function_exists('cue_autoload')) {
    cue_autoload('theme');
}

// Start Session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Auth Check
if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

// 2. KripzMaster Bypass
$userRole = $_SESSION['mh_auth_role'] ?? '';
if (strcasecmp($userRole, 'KripzMasters') === 0 || strcasecmp($userRole, 'KripzMaster') === 0) {
    // Admin View - Render Full Dashboard
    renderHubDashboard();
    exit;
}

// 3. Genesis Status Check
if (!isset($_SESSION['mh_genesis_status'])) {
    try {
        if (function_exists('mh_auth_load_user_context')) {
            mh_auth_load_user_context((string)($_SESSION['mh_auth_user'] ?? ''));
        }
    } catch (Throwable) {
    }
}
$genesisStatus = isset($_SESSION['mh_genesis_status']) ? (int)$_SESSION['mh_genesis_status'] : 3;

// 4. Routing Logic
switch ($genesisStatus) {
    case 0: // New User -> Create Persona
        header('Location: /hub/genesis/tokenization.php');
        exit;
    case 1: // Persona Created -> Explanation
        header('Location: /hub/genesis/tokenization.php');
        exit;
    case 2: // Explained -> Tokenization
        header('Location: /hub/genesis/tokenization.php');
        exit;
    case 3: // Active -> Full Hub
    default:
        renderHubDashboard();
        break;
}

function renderHubDashboard() {
    // Get current user data
    $persona = $_SESSION['mh_auth_persona'] ?? 'Not Set';
    $benefactorsOwnedCount = 0;
    $benefactorAppointmentsCount = 0;
    $benefactorPendingClaimsCount = 0;
    $loanBorrowedTotals = [];
    $loanLoanedTotals = [];
    $loanActiveCount = 0;
    try {
        $pdoBio = mh_benefactors_pdo();
        $u = (string)($_SESSION['mh_auth_user'] ?? '');
        if ($u !== '') {
            $stmt = $pdoBio->prepare("SELECT COUNT(*) FROM benefactors WHERE owner_username = ? AND status IN ('pending','active')");
            $stmt->execute([$u]);
            $benefactorsOwnedCount = (int)$stmt->fetchColumn();

            $stmt = $pdoBio->prepare("SELECT COUNT(*) FROM benefactors WHERE benefactor_username = ? AND status IN ('pending','active')");
            $stmt->execute([$u]);
            $benefactorAppointmentsCount = (int)$stmt->fetchColumn();

            $stmt = $pdoBio->prepare("SELECT COUNT(*) FROM benefactor_claim_responses r JOIN benefactor_claims c ON c.id = r.claim_id WHERE r.benefactor_username = ? AND r.status = 'pending' AND c.status = 'open'");
            $stmt->execute([$u]);
            $benefactorPendingClaimsCount = (int)$stmt->fetchColumn();
        }
    } catch (Throwable) {}

    try {
        $u = (string)($_SESSION['mh_auth_user'] ?? '');
        if ($u !== '') {
            $pdoEq = getEquityConnectionStrict();
            $stmt = $pdoEq->prepare("SHOW COLUMNS FROM mh_loans LIKE 'counterparty_username'");
            $stmt->execute();
            $hasCp = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdoEq->prepare("SHOW COLUMNS FROM mh_loans LIKE 'direction'");
            $stmt->execute();
            $hasDir = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
            if ($hasCp && $hasDir) {
                $stmt = $pdoEq->prepare("SELECT direction, principal_asset, SUM(principal_amount) AS total_amt, SUM(CASE WHEN status IN ('requested','active') THEN 1 ELSE 0 END) AS active_cnt FROM mh_loans WHERE counterparty_username = ? GROUP BY direction, principal_asset");
                $stmt->execute([$u]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) {
                    $dir = strtolower(trim((string)($r['direction'] ?? 'company_lends')));
                    $asset = trim((string)($r['principal_asset'] ?? 'cash'));
                    if ($asset === '') $asset = 'cash';
                    $amt = (float)($r['total_amt'] ?? 0);
                    if ($dir === 'company_borrows') {
                        $loanLoanedTotals[$asset] = ($loanLoanedTotals[$asset] ?? 0.0) + $amt;
                    } else {
                        $loanBorrowedTotals[$asset] = ($loanBorrowedTotals[$asset] ?? 0.0) + $amt;
                    }
                    $loanActiveCount += (int)($r['active_cnt'] ?? 0);
                }
            }
        }
    } catch (Throwable) {}

    $champ_coins = 0;
    $super_coins = 0;
    $culture_total_coins = 0;
    $champ_remaining = null;
    $super_remaining = null;
    $champ_ticker = 'mhc';
    $super_ticker = 'mhs';
    $champ_supply_cap = 1000000;
    $super_supply_cap = 300000;
    try {
        $u = (string)($_SESSION['mh_auth_user'] ?? '');
        if ($u !== '' && function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_seed_culture_coins') && function_exists('mh_tokenomics_get_balance')) {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            if ($pdoTok instanceof PDO) {
                $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $ids = mh_tokenomics_seed_culture_coins($pdoTok);
                $champId = (int)($ids['champcoin'] ?? 0);
                $superId = (int)($ids['supercoin'] ?? 0);
                if ($champId > 0) {
                    $b = mh_tokenomics_get_balance($pdoTok, $u, $champId);
                    $champ_coins = is_int($b) ? $b : 0;
                    $stmt = $pdoTok->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$champId]);
                    $raw = $stmt->fetchColumn();
                    if ($raw !== false && is_string($raw) && trim($raw) !== '') {
                        $meta = json_decode($raw, true);
                        if (is_array($meta)) {
                            if (isset($meta['ticker']) && is_string($meta['ticker']) && trim((string)$meta['ticker']) !== '') $champ_ticker = trim((string)$meta['ticker']);
                            if (isset($meta['supply_cap'])) $champ_supply_cap = max(0, (int)$meta['supply_cap']);
                        }
                    }
                    $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
                    $stmt->execute([$champId]);
                    $issued = (int)$stmt->fetchColumn();
                    $champ_remaining = max(0, (int)$champ_supply_cap - max(0, $issued));
                }
                if ($superId > 0) {
                    $b2 = mh_tokenomics_get_balance($pdoTok, $u, $superId);
                    $super_coins = is_int($b2) ? $b2 : 0;
                    $stmt = $pdoTok->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$superId]);
                    $raw = $stmt->fetchColumn();
                    if ($raw !== false && is_string($raw) && trim($raw) !== '') {
                        $meta = json_decode($raw, true);
                        if (is_array($meta)) {
                            if (isset($meta['ticker']) && is_string($meta['ticker']) && trim((string)$meta['ticker']) !== '') $super_ticker = trim((string)$meta['ticker']);
                            if (isset($meta['supply_cap'])) $super_supply_cap = max(0, (int)$meta['supply_cap']);
                        }
                    }
                    $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
                    $stmt->execute([$superId]);
                    $issued = (int)$stmt->fetchColumn();
                    $super_remaining = max(0, (int)$super_supply_cap - max(0, $issued));
                }
                $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(l.units_owned), 0) FROM mh_asset_ledger l JOIN mh_asset_classes c ON c.id = l.asset_class_id WHERE l.username = ? AND c.asset_type = 'culture'");
                $stmt->execute([$u]);
                $culture_total_coins = (int)$stmt->fetchColumn();
            }
        }
    } catch (Throwable) {}
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Human Hub</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@300;400;700;900&display=swap" rel="stylesheet">
    
    <style>
        .hub-page main.main-content {
            padding: 40px 0;
        }
        .hub-page .hub-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            box-sizing: border-box;
        }
        .hub-page .hub-shell {
            position: relative;
        }

        /* Token Balance Block */
        .hub-page .token-balance-block {
            position: absolute;
            top: 0;
            right: 0;
            background: rgba(0, 212, 255, 0.05);
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid rgba(0, 212, 255, 0.2);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            z-index: 10;
            transition: all 0.3s ease;
        }

        .hub-page .token-balance-block:hover {
            background: rgba(0, 212, 255, 0.1);
            box-shadow: 0 4px 25px rgba(0, 212, 255, 0.15);
            transform: translateY(-2px);
        }

        .hub-page .token-info {
            text-align: right;
        }

        .hub-page .token-label {
            font-family: 'Orbitron', sans-serif;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.7);
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .hub-page .token-value {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff;
            line-height: 1;
        }
        .hub-page .token-value-secondary {
            font-family: 'Rajdhani', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.15;
            margin-top: 4px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .hub-page .token-unit {
            font-size: 0.9rem;
            color: var(--theme-primary, #00d4ff);
        }

        .hub-page .token-icon {
            width: 36px;
            height: 36px;
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid var(--theme-primary, #00d4ff);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--theme-primary, #00d4ff);
        }

        .hub-page .token-buy-btn {
            margin-left: 10px;
            background: transparent;
            border: 1px solid var(--theme-primary, #00d4ff);
            color: var(--theme-primary, #00d4ff);
            padding: 6px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 1px;
        }

        .hub-page .token-buy-btn:hover {
            background: var(--theme-primary, #00d4ff);
            color: #000;
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
        }
        .hub-page .token-trade-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 12px;
            border-radius: 999px;
            border: 1px solid rgba(0, 212, 255, 0.35);
            color: var(--theme-primary, #00d4ff);
            text-decoration: none;
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 1px;
            font-size: 0.72rem;
            line-height: 1;
            white-space: nowrap;
            transition: all 0.3s;
        }
        .hub-page .token-trade-btn:hover {
            background: rgba(0, 212, 255, 0.12);
            box-shadow: 0 0 12px rgba(0, 212, 255, 0.18);
        }

        @media (max-width: 768px) {
            .hub-page .token-balance-block {
                position: static;
                margin-bottom: 20px;
                justify-content: space-between;
            }
            .hub-page .token-info { text-align: left; }
        }

        .hub-page h1 { 
            font-family: 'Orbitron', sans-serif;
            font-weight: 700; 
            letter-spacing: 2px;
            font-size: 2.5rem;
            margin-bottom: 10px;
            color: var(--theme-primary, #00d4ff);
            margin-top: 0;
        }

        .hub-page .subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 1.1rem;
            margin-bottom: 40px;
        }

        /* Dashboard Grid */
        .hub-page .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }

        /* Glassmorphism Cards */
        .hub-page .card { 
            background: rgba(20, 20, 25, 0.6);
            backdrop-filter: blur(12px);
            padding: 24px; 
            border-radius: 16px; 
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .hub-page .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 212, 255, 0.15);
            border-color: rgba(0, 212, 255, 0.3);
        }

        .hub-page .card h2 {
            font-family: 'Orbitron', sans-serif;
            font-size: 1.4rem;
            margin-top: 0;
            color: var(--theme-primary, #00d4ff);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .hub-page .card-stat {
            font-size: 2rem;
            font-weight: 600;
            color: var(--theme-primary, #00d4ff);
            margin: 10px 0;
        }

        .hub-page .card-label {
            color: rgba(255,255,255,0.7);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .hub-page .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            font-size: 0.8rem;
            font-weight: 600;
        }
    </style>
</head>
<body class="hub-page">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
        <div class="hub-container">
        <div class="hub-shell">
            <!-- Token Balance Display -->
            <div class="token-balance-block">
                <div class="token-info">
                    <div class="token-label">BALANCE</div>
                    <div class="token-value">
                        <?php echo number_format($_SESSION['tokens'] ?? 0); ?> <span class="token-unit">MTK</span>
                    </div>
                    <div class="token-value-secondary">
                        <div><?php echo number_format((int)$champ_coins); ?> <span class="token-unit"><?php echo htmlspecialchars((string)$champ_ticker, ENT_QUOTES); ?></span></div>
                        <a class="token-trade-btn" href="/hub/coins/culture.php">TRADE</a>
                    </div>
                    <div class="token-value-secondary">
                        <div><?php echo number_format((int)$super_coins); ?> <span class="token-unit"><?php echo htmlspecialchars((string)$super_ticker, ENT_QUOTES); ?></span></div>
                        <a class="token-trade-btn" href="/hub/coins/culture.php">TRADE</a>
                    </div>
                </div>
                <div class="token-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                </div>
                <a href="/hub/genesis/tokenization.php" class="token-buy-btn">TOP UP</a>
            </div>

            <h1>WELCOME TO THE HUB</h1>
            <div class="subtitle">Manage your digital presence, assets, and persona.</div>
            
            <div class="dashboard-grid">
                <?php
                    $role = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
                    $isKripzMaster = ($role !== '' && stripos($role, 'kripzmaster') !== false);
                ?>
                <?php if ($isKripzMaster): ?>
                <div class="card">
                    <h2>KripzMasters Tools</h2>
                    <div class="card-label">Administrative controls</div>
                    <div style="margin-top: 14px; display:flex; gap: 10px; flex-wrap: wrap;">
                        <a class="token-trade-btn" href="/gear/settings/database_allowlist.php">Database Allowlist</a>
                        <a class="token-trade-btn" href="/gear/settings/dbmanager.php">DB Manager</a>
                        <a class="token-trade-btn" href="/gear/settings/enterprise_monitor.php">Enterprise Monitor</a>
                        <a class="token-trade-btn" href="/templates/menus/navigator.php">Navigator</a>
                        <a class="token-trade-btn" href="/templates/menus/menu-permission-manager.php">Permission Manager</a>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Persona Card -->
                <div class="card">
                    <h2>Your Persona</h2>
                    <div class="card-stat"><?php echo htmlspecialchars($persona); ?></div>
                    <div class="card-label">Digital Twin Identity</div>
                    <div style="margin-top: 15px;">
                        <span class="status-badge">ONLINE</span>
                        <span class="status-badge" style="background: rgba(0, 212, 255, 0.2); color: var(--theme-primary, #00d4ff);">GENESIS COMPLETE</span>
                    </div>
                </div>

                <!-- Wallet Card -->
                <div class="card">
                    <h2>Asset Wallet</h2>
                    <?php
                        require_once __DIR__ . '/equity/db.php';
                        $equity_units = 0;
                        $share_holding = 0.0;
                        try {
                            $pdoEquity = getEquityConnection();
                            if ($pdoEquity) {
                                $stmt = $pdoEquity->prepare("SELECT SUM(units_owned) FROM equity_ledger WHERE username = ?");
                                $stmt->execute([$_SESSION['mh_auth_user']]);
                                $equity_units = (int)$stmt->fetchColumn();
                                $stmt = $pdoEquity->prepare("
                                    SELECT SUM(l.units_owned / c.fractional_units_per_share)
                                    FROM equity_ledger l
                                    JOIN equity_classes c ON l.class_id = c.id
                                    WHERE l.username = ?
                                ");
                                $stmt->execute([$_SESSION['mh_auth_user']]);
                                $share_holding = (float)$stmt->fetchColumn();
                            }
                        } catch (Throwable) {}
                        $utility_tokens = (int)($_SESSION['tokens'] ?? 0);
                        $wealth_coins = (int)$culture_total_coins;
                        $stablecoin = 0.00;
                    ?>
                    <div style="display:grid; gap: 12px; margin-top: 10px;">
                        <div>
                            <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Equity Coins</div>
                            <div style="font-weight: 700; color: #fff; font-size: 1.4rem;"><?php echo number_format($equity_units); ?> <span style="font-size:0.55em; color: rgba(255,255,255,0.7);">Units</span></div>
                            <div style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 2px;">Holding: <?php echo number_format($share_holding, 2); ?> Shares</div>
                        </div>
                        <div style="display:flex; gap: 16px; flex-wrap: wrap;">
                            <div>
                                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Utility Token</div>
                                <div style="font-weight: 700; color: #fff;"><?php echo number_format($utility_tokens); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Wealth Coins</div>
                                <div style="font-weight: 700; color: #fff;"><?php echo number_format($wealth_coins); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Stablecoin</div>
                                <div style="font-weight: 700; color: #fff;"><?php echo number_format($stablecoin, 2); ?></div>
                            </div>
                            <div>
                                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Champion Coin</div>
                                <div style="font-weight: 700; color: #fff;"><?php echo number_format((int)$champ_coins); ?> <span style="font-size:0.75em; color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars((string)$champ_ticker, ENT_QUOTES); ?></span></div>
                            </div>
                            <div>
                                <div style="font-size: 0.8rem; color: rgba(255,255,255,0.7);">Super Coin</div>
                                <div style="font-weight: 700; color: #fff;"><?php echo number_format((int)$super_coins); ?> <span style="font-size:0.75em; color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars((string)$super_ticker, ENT_QUOTES); ?></span></div>
                            </div>
                        </div>
                        <div>
                            <a href="/hub/wallet.php" class="token-buy-btn" style="display:inline-flex; margin-left:0;">Open Wallet</a>
                            <a href="/hub/coins/culture.php" class="token-buy-btn" style="display:inline-flex; margin-left:10px;">Reserve Coins</a>
                        </div>
                        <div style="font-size: 0.85rem; color: rgba(255,255,255,0.75); line-height: 1.6;">
                            <div><?php echo number_format((int)$champ_supply_cap); ?> max for Champion Coin(<?php echo htmlspecialchars((string)$champ_ticker, ENT_QUOTES); ?>) (ChampCoin)</div>
                            <div><?php echo number_format((int)$super_supply_cap); ?> max for Super Coin(<?php echo htmlspecialchars((string)$super_ticker, ENT_QUOTES); ?>) (SuperCoin)</div>
                        </div>
                    </div>
                </div>

                <!-- Benefactors Card -->
                <div class="card">
                    <h2>Benefactors</h2>
                    <div class="card-stat"><?php echo number_format((int)$benefactorPendingClaimsCount); ?></div>
                    <div class="card-label">Pending Claim Requests</div>
                    <ul style="margin-top: 15px; padding-left: 20px; color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                        <li>You appointed: <?php echo number_format((int)$benefactorsOwnedCount); ?></li>
                        <li>You are a benefactor for: <?php echo number_format((int)$benefactorAppointmentsCount); ?></li>
                    </ul>
                    <div style="margin-top: 12px;">
                        <a href="/hub/equity/benefactors.php" class="token-buy-btn" style="display:inline-flex; margin-left:0;">Open Benefactors</a>
                    </div>
                </div>

                <div class="card">
                    <h2>Loans</h2>
                    <div class="card-stat"><?php echo number_format((int)$loanActiveCount); ?></div>
                    <div class="card-label">Active / Requested</div>
                    <div style="margin-top: 15px; color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                        <div style="margin-bottom:6px;">Borrowed:</div>
                        <?php if (!empty($loanBorrowedTotals)): ?>
                            <?php foreach ($loanBorrowedTotals as $asset => $amt): ?>
                                <div><?php echo htmlspecialchars((string)$asset, ENT_QUOTES); ?>: <?php echo number_format((float)$amt, 2); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div>—</div>
                        <?php endif; ?>
                        <div style="margin:10px 0 6px;">Loaned to company:</div>
                        <?php if (!empty($loanLoanedTotals)): ?>
                            <?php foreach ($loanLoanedTotals as $asset => $amt): ?>
                                <div><?php echo htmlspecialchars((string)$asset, ENT_QUOTES); ?>: <?php echo number_format((float)$amt, 2); ?></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div>—</div>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 12px;">
                        <a href="/hub/loans/" class="token-buy-btn" style="display:inline-flex; margin-left:0;">Open Loans</a>
                    </div>
                </div>

            </div>
        </div>
        </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
<?php
}
