<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/grid/dashboard.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/grid/dashboard.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$isAdmin = isset($_SESSION['mh_auth_role']) && is_string($_SESSION['mh_auth_role']) && stripos((string)$_SESSION['mh_auth_role'], 'kripzmaster') !== false;
$defaultQuoteRequest = json_encode([
    'source' => [
        'sourceType' => 'ACCOUNT',
    ],
    'destination' => [
        'destinationType' => 'UMA_ADDRESS',
        'umaAddress' => '$recipient@wallet.example',
        'currency' => 'USD',
    ],
    'lockedCurrencySide' => 'SENDING',
    'lockedCurrencyAmount' => 100,
    'immediatelyExecute' => false,
    'description' => 'Meta Humans Banking Grid transfer',
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banking Grid Dashboard | Meta Humans Hub</title>
    <?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content { max-width: 1500px; margin: 0 auto; padding: 18px 16px 56px; }
        .bank-layout {
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
            gap: 18px;
            align-items: start;
        }
        .bank-sidebar,
        .bank-card {
            border-radius: 22px;
            border: 1px solid rgba(255, 183, 77, 0.14);
            background: radial-gradient(circle at top, rgba(20, 15, 5, 0.92), rgba(5, 7, 12, 0.96));
            box-shadow: 0 18px 46px rgba(0, 0, 0, 0.30);
            backdrop-filter: blur(14px);
        }
        .bank-sidebar {
            position: sticky;
            top: 18px;
            padding: 22px 18px;
            overflow: hidden;
        }
        .bank-sidebar::before,
        .bank-card::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(140deg, rgba(255, 196, 84, 0.08), transparent 30%, transparent 70%, rgba(0, 163, 255, 0.08));
            border-radius: inherit;
        }
        .bank-sidebar,
        .bank-card { position: relative; }
        .brand-block {
            display: grid;
            gap: 12px;
            margin-bottom: 22px;
        }
        .brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-family: 'Orbitron', sans-serif;
            color: #fff;
            font-size: 1.15rem;
            letter-spacing: 1.2px;
        }
        .brand-mark .brand-logo {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, rgba(255, 184, 77, 0.18), rgba(0, 153, 255, 0.16));
            border: 1px solid rgba(255, 184, 77, 0.26);
            font-weight: 900;
        }
        .brand-copy {
            color: rgba(255,255,255,0.72);
            line-height: 1.55;
            margin: 0;
        }
        .sidebar-nav {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.86);
            text-decoration: none;
            background: rgba(255,255,255,0.03);
        }
        .sidebar-link.active {
            border-color: rgba(255, 183, 77, 0.34);
            box-shadow: inset 0 0 0 1px rgba(255, 183, 77, 0.10);
            color: #fff;
        }
        .sidebar-link span:last-child {
            color: rgba(255, 183, 77, 0.9);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .sidebar-footer {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.58);
            font-size: 0.86rem;
            line-height: 1.55;
        }
        .bank-content {
            display: grid;
            gap: 18px;
        }
        .hero-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .bank-card {
            padding: 18px;
            overflow: hidden;
        }
        .hero-card {
            min-height: 238px;
            display: grid;
            gap: 14px;
            align-content: space-between;
        }
        .hero-card.accent-gold {
            background: radial-gradient(circle at center, rgba(255, 183, 77, 0.14), rgba(5, 7, 12, 0.96));
        }
        .hero-card.accent-blue {
            background: radial-gradient(circle at center, rgba(38, 132, 255, 0.15), rgba(5, 7, 12, 0.96));
        }
        .hero-card.accent-world {
            background:
                radial-gradient(circle at center, rgba(255, 183, 77, 0.12), transparent 38%),
                linear-gradient(160deg, rgba(0, 0, 0, 0.12), rgba(0, 0, 0, 0.36)),
                radial-gradient(circle at top, rgba(24, 44, 86, 0.36), rgba(5, 7, 12, 0.96));
        }
        .card-index {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            color: rgba(255, 183, 77, 0.96);
            font-family: 'Orbitron', sans-serif;
        }
        .hero-title {
            margin: 0;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            font-size: 1.05rem;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .hero-subtitle {
            margin: 6px 0 0;
            color: rgba(255,255,255,0.72);
            line-height: 1.5;
        }
        .hero-visual {
            min-height: 110px;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            display: grid;
            place-items: center;
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.88);
            text-align: center;
            padding: 16px;
        }
        .hero-visual .money-ring {
            width: 110px;
            height: 110px;
            border-radius: 999px;
            border: 2px solid rgba(93, 173, 255, 0.36);
            box-shadow: 0 0 26px rgba(93, 173, 255, 0.22), inset 0 0 24px rgba(93, 173, 255, 0.08);
            display: grid;
            place-items: center;
            font-size: 2rem;
        }
        .hero-value {
            margin: 0;
            color: #fff;
            font-size: 2rem;
            font-weight: 800;
        }
        .hero-meta {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            color: rgba(255,255,255,0.64);
            font-size: 0.92rem;
        }
        .rail-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            gap: 18px;
        }
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .section-title {
            margin: 0;
            color: rgba(255,255,255,0.96);
            font-family: 'Orbitron', sans-serif;
            font-size: 1rem;
            letter-spacing: 0.9px;
            text-transform: uppercase;
        }
        .section-copy {
            margin: 0;
            color: rgba(255,255,255,0.70);
        }
        .bank-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .bank-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 10px 14px;
            border-radius: 14px;
            border: 1px solid rgba(255, 183, 77, 0.28);
            background: rgba(255,255,255,0.03);
            color: rgba(255, 220, 171, 0.96);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }
        .bank-btn:hover { background: rgba(255, 183, 77, 0.10); }
        .bank-btn.secondary {
            border-color: rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.88);
        }
        .bank-chipbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .bank-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.88);
        }
        .bank-chip strong { color: #fff; }
        .platform-note,
        .empty-state,
        .transfer-note {
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
            padding: 14px;
            color: rgba(255,255,255,0.76);
        }
        .platform-note.err,
        .transfer-note.err {
            border-color: rgba(255, 180, 162, 0.28);
            color: rgba(255, 180, 162, 0.96);
        }
        .spotlight-grid,
        .metric-grid,
        .module-grid,
        .bank-grid-2,
        .asset-panel-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .metric-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .module-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .metric-card,
        .module-card,
        .account-card,
        .capability-card,
        .activity-card,
        .transfer-card {
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            padding: 16px;
        }
        .metric-label,
        .label {
            margin: 0 0 6px;
            color: rgba(255,255,255,0.60);
            font-size: 0.9rem;
        }
        .metric-value {
            margin: 0;
            color: #fff;
            font-weight: 800;
            font-size: 1.7rem;
        }
        #assetsSection {
            align-items: stretch;
        }
        #assetsSection .metric-card {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 4px;
            padding: 14px 14px 12px;
        }
        #assetsSection .metric-label {
            margin-bottom: 2px;
            line-height: 1.25;
        }
        #assetsSection .metric-value {
            font-size: clamp(1.1rem, 1.7vw, 1.35rem);
            line-height: 1.15;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        #assetsSection .metric-hint {
            margin-top: 2px;
            font-size: 0.84rem;
            line-height: 1.4;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .metric-hint,
        .muted {
            margin: 8px 0 0;
            color: rgba(255,255,255,0.66);
            line-height: 1.55;
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            word-break: break-word;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 88px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.12);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: rgba(255,255,255,0.86);
        }
        .pill.live { border-color: rgba(143, 240, 164, 0.28); color: #8ff0a4; }
        .pill.action-required { border-color: rgba(255, 214, 102, 0.32); color: #ffd666; }
        .pill.blocked,
        .pill.sandbox-only { border-color: rgba(255, 180, 162, 0.28); color: #ffb4a2; }
        .pill.gated { border-color: rgba(170, 200, 255, 0.28); color: #aac8ff; }
        .pill.settlement { border-color: rgba(255, 183, 77, 0.20); color: rgba(255, 220, 171, 0.96); }
        .pill.platform { border-color: rgba(122, 175, 255, 0.20); color: rgba(185, 214, 255, 0.96); }
        .row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .module-title,
        .account-title,
        .capability-title,
        .activity-title,
        .transfer-title {
            margin: 0 0 6px;
            color: #fff;
            font-weight: 700;
        }
        .transfer-workspace {
            display: grid;
            grid-template-columns: 1.2fr 0.9fr;
            gap: 18px;
        }
        .asset-panel {
            display: grid;
            gap: 14px;
        }
        .asset-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .asset-row:last-child { padding-bottom: 0; border-bottom: 0; }
        .asset-list {
            display: grid;
            gap: 10px;
        }
        .asset-mini {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
        }
        .asset-mini strong { color: #fff; }
        .asset-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .asset-stat {
            margin: 0;
            color: #fff;
            font-size: 1.35rem;
            font-weight: 800;
        }
        .transfer-stack {
            display: grid;
            gap: 14px;
        }
        .bank-textarea,
        .bank-input {
            width: 100%;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.26);
            color: #fff;
            padding: 12px 14px;
            box-sizing: border-box;
            font: inherit;
        }
        .bank-textarea {
            min-height: 220px;
            resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            line-height: 1.45;
        }
        .bank-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        table.bank-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 620px;
        }
        .bank-table th,
        .bank-table td {
            text-align: left;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.84);
            vertical-align: top;
        }
        .bank-table th {
            color: rgba(255,255,255,0.58);
            font-size: 0.84rem;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }
        .bank-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 140px;
            color: rgba(255,255,255,0.72);
        }
        @media (max-width: 1260px) {
            .bank-layout { grid-template-columns: 1fr; }
            .bank-sidebar { position: static; }
            .hero-grid,
            .rail-grid,
            .transfer-workspace,
            .metric-grid,
            .module-grid,
            .asset-panel-grid,
            .bank-grid-2 { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 820px) {
            .hero-grid,
            .rail-grid,
            .transfer-workspace,
            .metric-grid,
            .module-grid,
            .asset-panel-grid,
            .bank-grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<main class="main-content">
    <div class="bank-layout">
        <aside class="bank-sidebar">
            <div class="brand-block">
                <div class="brand-mark">
                    <span class="brand-logo">N</span>
                    <span>Meta Humans</span>
                </div>
                <h1 class="section-title" style="font-size:1.3rem;">Banking Grid</h1>
                <p class="brand-copy">Unified financial infrastructure for the global digital economy, powered by Grid settlement rails and Meta Humans asset ledgers.</p>
            </div>

            <nav class="sidebar-nav">
                <a class="sidebar-link active" href="#overviewSection"><span>Overview</span><span>Now</span></a>
                <a class="sidebar-link" href="#accountsSection"><span>Accounts</span><span>Grid</span></a>
                <a class="sidebar-link" href="#paymentsSection"><span>Payments</span><span>Live</span></a>
                <a class="sidebar-link" href="#assetPanelsSection"><span>Assets</span><span>MTK</span></a>
                <a class="sidebar-link" href="/hub/grid/transactions.php"><span>Transactions</span><span>Ledger</span></a>
                <a class="sidebar-link" href="#capabilitiesSection"><span>Networks</span><span>State</span></a>
                <a class="sidebar-link" href="/hub/grid/passkey.php"><span>Authorize</span><span>Session</span></a>
            </nav>

            <div class="sidebar-footer">
                Current phase: dashboard cockpit plus direct transfer workspace. Vendor-gated products stay visible as state, not as fake live actions.
            </div>
        </aside>

        <div class="bank-content">
            <section class="hero-grid" id="overviewSection">
                <article class="bank-card hero-card accent-gold">
                    <div>
                        <span class="card-index">01</span>
                        <h2 class="hero-title">Meta Humans Account</h2>
                        <p class="hero-subtitle">Branded settlement account with live tenant and session context.</p>
                    </div>
                    <div class="hero-visual">
                        <div>
                            <p class="label">Tenant</p>
                            <p class="hero-value mono" id="tenantChip">Loading...</p>
                            <p class="muted" id="sessionChip">Loading session...</p>
                        </div>
                    </div>
                    <div class="hero-meta">
                        <span>Primary Account: <strong id="accountChip">Loading...</strong></span>
                        <span>Environment: <strong id="environmentChip">Loading...</strong></span>
                    </div>
                </article>

                <article class="bank-card hero-card accent-blue">
                    <div>
                        <span class="card-index">02</span>
                        <h2 class="hero-title">Stablecoin</h2>
                        <p class="hero-subtitle">Settlement liquidity across the discovered embedded wallet accounts.</p>
                    </div>
                    <div class="hero-visual">
                        <div class="money-ring">$</div>
                    </div>
                    <div>
                        <p class="hero-value" id="stablecoinMetric">Loading...</p>
                        <div class="hero-meta">
                            <span id="stablecoinHint">Loading account totals...</span>
                        </div>
                    </div>
                </article>

                <article class="bank-card hero-card accent-world">
                    <div>
                        <span class="card-index">03</span>
                        <h2 class="hero-title">Global Off-Ramp</h2>
                        <p class="hero-subtitle">Production truth for payouts, cards, rewards, ramps, and corridor features.</p>
                    </div>
                    <div class="hero-visual">
                        <div>
                            <p class="label">UMA Domain</p>
                            <p class="value mono" id="umaDomain">Loading...</p>
                            <p class="muted mono" id="proxySubdomain">Loading...</p>
                        </div>
                    </div>
                    <div class="hero-meta">
                        <span>Webhook: <strong id="webhookEndpoint">Loading...</strong></span>
                    </div>
                </article>
            </section>

            <section class="rail-grid">
                <article class="bank-card">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Platform Rail</h2>
                            <p class="section-copy">Live Grid platform state and environment truth from the backend broker.</p>
                        </div>
                        <div class="bank-actions">
                            <button class="bank-btn" id="refreshBtn" type="button">Refresh Dashboard</button>
                            <a class="bank-btn secondary" href="/hub/grid/passkey.php">Authorize Grid Session</a>
                        </div>
                    </div>
                    <div class="bank-chipbar" style="margin-bottom:14px;">
                        <span class="bank-chip">Tenant <strong id="tenantChipMirror">Loading...</strong></span>
                        <span class="bank-chip">Session <strong id="sessionChipMirror">Loading...</strong></span>
                        <span class="bank-chip">Config <strong id="configChip">Loading...</strong></span>
                    </div>
                    <div id="platformNotes" class="platform-note">Loading platform state...</div>
                </article>

                <article class="bank-card">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Assets Snapshot</h2>
                            <p class="section-copy">Wallet classes surfaced together in the banking cockpit.</p>
                        </div>
                    </div>
                    <div class="metric-grid" id="assetsSection">
                        <div class="metric-card">
                            <p class="metric-label">MTK Tokens</p>
                            <p class="metric-value" id="mtkMetric">Loading...</p>
                            <p class="metric-hint" id="mtkHint">Token requests and transfers.</p>
                        </div>
                        <div class="metric-card">
                            <p class="metric-label">Culture / Meme Coins</p>
                            <p class="metric-value" id="cultureMetric">Loading...</p>
                            <p class="metric-hint" id="cultureHint">Culture balances synced from tokenomics.</p>
                        </div>
                        <div class="metric-card">
                            <p class="metric-label">Equity Visibility</p>
                            <p class="metric-value" id="equityMetric">Loading...</p>
                            <p class="metric-hint" id="equityHint">Digitized share visibility only.</p>
                        </div>
                        <div class="metric-card">
                            <p class="metric-label">Settlement Accounts</p>
                            <p class="metric-value" id="accountsMetric">Loading...</p>
                            <p class="metric-hint">Embedded wallet account discovery and status.</p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="bank-card" id="assetPanelsSection">
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Asset Action Panels</h2>
                        <p class="section-copy">Operational asset modules for treasury, token balances, culture coins, and equity visibility.</p>
                    </div>
                </div>
                <div id="assetPanelGrid" class="asset-panel-grid">
                    <div class="bank-loading">Loading asset modules...</div>
                </div>
            </section>

            <section class="bank-card" id="paymentsSection">
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Transfer Center</h2>
                        <p class="section-copy">Direct quote creation and signed Grid execution now runs inside the dashboard.</p>
                    </div>
                    <div class="bank-actions">
                        <button class="bank-btn secondary" id="transferRefreshBtn" type="button">Refresh Transfers</button>
                        <button class="bank-btn secondary" id="transferAutoRefreshBtn" type="button">Auto Refresh: Off</button>
                        <a class="bank-btn secondary" href="/hub/grid/transactions.php">Open Transactions</a>
                    </div>
                </div>

                <div class="transfer-workspace">
                    <div class="transfer-stack">
                        <div class="transfer-card">
                            <p class="transfer-title">Create Quote</p>
                            <p class="muted">Edit the request JSON directly, keeping the tenant embedded wallet as the source when needed.</p>
                            <textarea class="bank-textarea" id="quoteRequestInput" rows="12"><?php echo htmlspecialchars((string)$defaultQuoteRequest, ENT_QUOTES); ?></textarea>
                            <div class="bank-actions" style="margin-top:12px;">
                                <button class="bank-btn" id="createQuoteBtn" type="button">Create Quote</button>
                                <label class="bank-chip" style="cursor:pointer;">
                                    <input type="checkbox" id="useEmbeddedWalletSource" checked style="margin:0 4px 0 0;">
                                    Use tenant embedded wallet as source
                                </label>
                            </div>
                            <div class="transfer-note" id="createResult" style="margin-top:12px;">Ready to create a quote.</div>
                        </div>

                        <div class="transfer-card">
                            <p class="transfer-title">Execute Quote</p>
                            <p class="muted">Signed execution reuses the same local Grid session key pattern from the dedicated payments flow.</p>
                            <input class="bank-input" id="executeQuoteId" type="text" placeholder="Quote:...">
                            <div class="bank-actions" style="margin-top:12px;">
                                <button class="bank-btn" id="executeBtn" type="button">Execute</button>
                                <button class="bank-btn secondary" id="autoFillLastBtn" type="button">Use Last Quote</button>
                            </div>
                            <details style="margin-top:12px;">
                                <summary style="cursor:pointer;color:rgba(255,255,255,0.82);">Advanced signature override</summary>
                                <div style="margin-top:10px;">
                                    <textarea class="bank-textarea" id="signatureOverride" rows="4" placeholder="Leave blank to auto-sign with the device-local Grid session key."></textarea>
                                </div>
                            </details>
                            <div class="transfer-note" id="executeResult" style="margin-top:12px;">Execution status will appear here.</div>
                        </div>
                    </div>

                    <div class="transfer-stack">
                        <div class="transfer-card">
                            <p class="transfer-title">Transfer Status</p>
                            <div class="bank-chipbar" style="margin-bottom:12px;">
                                <span class="bank-chip">Tenant <strong id="transferTenantId">Loading...</strong></span>
                                <span class="bank-chip">Account <strong id="transferAccountId">Loading...</strong></span>
                                <span class="bank-chip">Local Key <strong id="localKeyStatus">Loading...</strong></span>
                            </div>
                            <p class="muted">Create and execute quotes without leaving the banking cockpit. The table below updates from the same tenant-scoped quote store used by the Grid payments page.</p>
                        </div>

                        <div class="transfer-card">
                            <p class="transfer-title">Recent Quotes</p>
                            <div class="bank-table-wrap">
                                <table class="bank-table">
                                    <thead>
                                        <tr>
                                            <th>Quote</th>
                                            <th>Status</th>
                                            <th>Transaction</th>
                                            <th>Updated</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="quotesBody">
                                        <tr><td colspan="5">No quotes yet.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bank-grid-2" id="accountsSection">
                <article class="bank-card">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Accounts</h2>
                            <p class="section-copy">Embedded wallet and settlement account state.</p>
                        </div>
                    </div>
                    <div id="accountsList" class="bank-grid-2">
                        <div class="bank-loading">Loading account data...</div>
                    </div>
                </article>

                <article class="bank-card" id="capabilitiesSection">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Capabilities</h2>
                            <p class="section-copy">Live, gated, or blocked product surfaces.</p>
                        </div>
                    </div>
                    <div id="capabilitiesList" class="bank-grid-2">
                        <div class="bank-loading">Loading capability map...</div>
                    </div>
                </article>
            </section>

            <section class="bank-card">
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Unified Modules</h2>
                        <p class="section-copy">Specialist screens remain available, but the banking dashboard becomes the primary cockpit while transactions move to their own ledger page.</p>
                    </div>
                </div>
                <div id="moduleList" class="module-grid">
                    <div class="bank-loading">Loading module summaries...</div>
                </div>
            </section>

            <section class="bank-grid-2">
                <article class="bank-card">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Transactions</h2>
                            <p class="section-copy">Settlement and platform finance history now lives on a dedicated ledger page.</p>
                        </div>
                        <div class="bank-actions">
                            <a class="bank-btn secondary" href="/hub/grid/transactions.php">Open Transactions Page</a>
                        </div>
                    </div>
                    <div class="module-card">
                        <p class="module-title">Dedicated Ledger Surface</p>
                        <p class="muted">Use `transactions.php` for quote history, platform finance events, settlement timeline review, and audit-friendly transaction tracing.</p>
                    </div>
                </article>

                <?php if ($isAdmin): ?>
                <article class="bank-card">
                    <div class="section-head">
                        <div>
                            <h2 class="section-title">Admin Banking Controls</h2>
                            <p class="section-copy">Visible only to `KripzMaster` users. This block is not rendered at all for standard users.</p>
                        </div>
                    </div>
                    <div class="module-grid" style="grid-template-columns:1fr;">
                        <div class="module-card">
                            <p class="module-title">Tokenomics Management</p>
                            <p class="muted">Admin-only access to pricing, issuance, and finance control surfaces.</p>
                            <div class="bank-actions" style="margin-top:12px;">
                                <a class="bank-btn secondary" href="/control/tokenomics-management.php">Open Tokenomics Controls</a>
                            </div>
                        </div>
                        <div class="module-card">
                            <p class="module-title">Zero-Human Accounting</p>
                            <p class="muted">Runbook phases are now defined. Implementation follows after the transfer center and dashboard modules are stable.</p>
                        </div>
                    </div>
                </article>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/footer.php'; ?>
<script>
    const dashboardEndpoint = "/gear/grid/dashboard_data.php";
    const quoteApiBase = "/gear/grid/quotes.php";
    const TURNKEY_STAMP_SCHEME = "SIGNATURE_SCHEME_TK_API_P256";

    const refreshBtn = document.getElementById("refreshBtn");
    const transferRefreshBtn = document.getElementById("transferRefreshBtn");
    const transferAutoRefreshBtn = document.getElementById("transferAutoRefreshBtn");

    const tenantChip = document.getElementById("tenantChip");
    const tenantChipMirror = document.getElementById("tenantChipMirror");
    const accountChip = document.getElementById("accountChip");
    const environmentChip = document.getElementById("environmentChip");
    const sessionChip = document.getElementById("sessionChip");
    const sessionChipMirror = document.getElementById("sessionChipMirror");
    const configChip = document.getElementById("configChip");
    const umaDomain = document.getElementById("umaDomain");
    const proxySubdomain = document.getElementById("proxySubdomain");
    const webhookEndpoint = document.getElementById("webhookEndpoint");
    const platformNotes = document.getElementById("platformNotes");

    const stablecoinMetric = document.getElementById("stablecoinMetric");
    const stablecoinHint = document.getElementById("stablecoinHint");
    const mtkMetric = document.getElementById("mtkMetric");
    const mtkHint = document.getElementById("mtkHint");
    const cultureMetric = document.getElementById("cultureMetric");
    const cultureHint = document.getElementById("cultureHint");
    const equityMetric = document.getElementById("equityMetric");
    const equityHint = document.getElementById("equityHint");
    const accountsMetric = document.getElementById("accountsMetric");

    const accountsList = document.getElementById("accountsList");
    const capabilitiesList = document.getElementById("capabilitiesList");
    const moduleList = document.getElementById("moduleList");
    const assetPanelGrid = document.getElementById("assetPanelGrid");

    const transferTenantId = document.getElementById("transferTenantId");
    const transferAccountId = document.getElementById("transferAccountId");
    const localKeyStatus = document.getElementById("localKeyStatus");
    const quoteRequestInput = document.getElementById("quoteRequestInput");
    const useEmbeddedWalletSource = document.getElementById("useEmbeddedWalletSource");
    const createQuoteBtn = document.getElementById("createQuoteBtn");
    const createResult = document.getElementById("createResult");
    const executeQuoteId = document.getElementById("executeQuoteId");
    const executeBtn = document.getElementById("executeBtn");
    const autoFillLastBtn = document.getElementById("autoFillLastBtn");
    const signatureOverride = document.getElementById("signatureOverride");
    const executeResult = document.getElementById("executeResult");
    const quotesBody = document.getElementById("quotesBody");

    let dashboardState = null;
    let cryptoDepsPromise = null;
    let currentTenantId = "";
    let currentAccountId = "";
    let lastCreatedQuoteId = "";
    let lastPayloadToSign = "";
    let transferAutoRefreshTimer = null;
    let transferAutoRefreshUntilMs = 0;

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function dataSafe(obj, key) {
        if (!obj || typeof obj !== "object" || !(key in obj)) {
            return "";
        }
        return obj[key] == null ? "" : String(obj[key]);
    }

    function setNote(target, text, isError) {
        target.textContent = text;
        target.classList.toggle("err", Boolean(isError));
    }

    function bytesToBase64url(bytes) {
        let binary = "";
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function sessionKeyStorageKey(tenantId) {
        return `mh:grid:session-key:${tenantId}`;
    }

    function readStoredSessionKey(tenantId) {
        if (!tenantId) {
            return null;
        }
        try {
            const raw = sessionStorage.getItem(sessionKeyStorageKey(tenantId));
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== "object") {
                sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                return null;
            }
            if (parsed.expiresAt) {
                const expiresAt = Date.parse(String(parsed.expiresAt));
                if (!Number.isNaN(expiresAt) && expiresAt <= Date.now()) {
                    sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                    return null;
                }
            }
            return parsed;
        } catch (error) {
            sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
            return null;
        }
    }

    async function loadCryptoDeps() {
        if (!cryptoDepsPromise) {
            cryptoDepsPromise = Promise.all([
                import("https://esm.sh/@turnkey/api-key-stamper@0.4.4"),
            ]).then(([apiKeyStamper]) => ({
                signWithApiKey: apiKeyStamper.signWithApiKey,
            }));
        }
        return cryptoDepsPromise;
    }

    async function buildGridWalletSignature(sessionKey, payloadToSign) {
        if (sessionKey && sessionKey.sandboxSignatureOnly) {
            return "sandbox-valid-signature";
        }
        const deps = await loadCryptoDeps();
        const signature = await deps.signWithApiKey({
            content: String(payloadToSign || ""),
            publicKey: String(sessionKey.publicKeyCompressedHex || ""),
            privateKey: String(sessionKey.privateKeyHex || ""),
        });
        const stamp = JSON.stringify({
            publicKey: String(sessionKey.publicKeyCompressedHex || ""),
            scheme: TURNKEY_STAMP_SCHEME,
            signature,
        });
        return bytesToBase64url(new TextEncoder().encode(stamp));
    }

    async function fetchDashboard() {
        const res = await fetch(dashboardEndpoint, { headers: { "Accept": "application/json" } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data || data.ok !== true) {
            throw new Error((data && (data.error || data.message)) ? `${data.error || data.message}` : `HTTP ${res.status}`);
        }
        return data;
    }

    async function quoteApiCall(action, method, payload) {
        const res = await fetch(`${quoteApiBase}?action=${encodeURIComponent(action)}`, {
            method,
            headers: payload ? { "Content-Type": "application/json" } : {},
            body: payload ? JSON.stringify(payload) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data || data.ok !== true) {
            const msg = (data && (data.error || data.message)) ? `${data.error || data.message}` : `HTTP ${res.status}`;
            const detail = data && data.detail ? ` ${JSON.stringify(data.detail)}` : "";
            throw new Error(`${msg}${detail}`);
        }
        return data;
    }

    function renderPlatform(payload) {
        const platform = payload.platform || {};
        const grid = payload.grid || {};
        const session = grid.activeSession || null;
        currentTenantId = dataSafe(payload, "tenantId");
        currentAccountId = dataSafe(grid, "primaryAccountId");

        tenantChip.textContent = currentTenantId || "Unknown tenant";
        tenantChipMirror.textContent = currentTenantId || "Unknown tenant";
        accountChip.textContent = currentAccountId || "No embedded wallet";
        environmentChip.textContent = dataSafe(platform, "environment").toUpperCase() || "UNKNOWN";
        sessionChip.textContent = session && session.expiresAt ? `${session.status || "active"} until ${session.expiresAt}` : (session ? (session.status || "active") : "No active session");
        sessionChipMirror.textContent = session ? (session.status || "active") : "none";
        configChip.textContent = platform.configReachable ? "verified" : "pending";
        umaDomain.textContent = dataSafe(platform, "umaDomain") || "Unavailable";
        proxySubdomain.textContent = dataSafe(platform, "proxyUmaSubdomain") || "Unavailable";
        webhookEndpoint.textContent = dataSafe(platform, "webhookEndpoint") || "Unavailable";
        platformNotes.classList.toggle("err", platform.configReachable !== true);
        platformNotes.textContent = Array.isArray(platform.notes) && platform.notes.length
            ? platform.notes.join(" ")
            : (platform.configReachable ? "Live /config verified from the backend broker." : "Waiting for a successful /config response.");
    }

    function renderMetrics(payload) {
        const wallet = payload.wallet || {};
        const tokenFlow = payload.tokenFlow || {};
        stablecoinMetric.textContent = dataSafe(wallet, "stablecoinDisplay") || "Unavailable";
        stablecoinHint.textContent = `${Number(wallet.settlementAccountsCount || 0)} settlement account(s) discovered.`;
        mtkMetric.textContent = Number(wallet.utilityTokens || 0).toLocaleString();
        mtkHint.textContent = `${Number(tokenFlow.pendingRequests || 0)} pending requests, ${Number(tokenFlow.pendingTransfers || 0)} pending transfers.`;
        cultureMetric.textContent = Number(wallet.wealthCoins || 0).toLocaleString();
        const cultureRows = Array.isArray(tokenFlow.cultureBalances) ? tokenFlow.cultureBalances : [];
        cultureHint.textContent = cultureRows.length ? cultureRows.map((item) => `${item.key}: ${Number(item.balance || 0).toLocaleString()}`).join(" | ") : "No culture balances recorded yet.";
        equityMetric.textContent = Number(wallet.equityCoins || 0).toLocaleString();
        equityHint.textContent = `${Number(wallet.shareHolding || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} shares visible from the equity ledger.`;
        accountsMetric.textContent = Number(wallet.settlementAccountsCount || 0).toLocaleString();
    }

    function renderAccounts(grid) {
        const accounts = Array.isArray(grid.accounts) ? grid.accounts : [];
        if (!accounts.length) {
            accountsList.innerHTML = '<div class="empty-state">No embedded wallet accounts have been discovered for this tenant yet.</div>';
            return;
        }
        accountsList.innerHTML = accounts.map((account) => `
            <div class="account-card">
                <div class="row">
                    <div>
                        <p class="account-title">${escapeHtml(account.label || "Settlement Account")}</p>
                        <p class="muted mono">${escapeHtml(account.accountId || "")}</p>
                    </div>
                    <span class="pill ${escapeHtml(String(account.status || "unknown").toLowerCase())}">${escapeHtml(account.status || "unknown")}</span>
                </div>
                <div class="bank-grid-2" style="margin-top:12px;">
                    <div>
                        <p class="label">Currency</p>
                        <p class="value">${escapeHtml(account.currency || account.balanceCurrency || "Unknown")}</p>
                    </div>
                    <div>
                        <p class="label">Balance</p>
                        <p class="value">${escapeHtml(account.balanceDisplay || "Unavailable")}</p>
                    </div>
                </div>
            </div>
        `).join("");
    }

    function renderCapabilities(grid) {
        const capabilities = Array.isArray(grid.capabilities) ? grid.capabilities : [];
        if (!capabilities.length) {
            capabilitiesList.innerHTML = '<div class="empty-state">No capability state is available yet.</div>';
            return;
        }
        capabilitiesList.innerHTML = capabilities.map((item) => `
            <div class="capability-card">
                <div class="row">
                    <div>
                        <p class="capability-title">${escapeHtml(item.label || item.key || "Capability")}</p>
                        <p class="muted">${escapeHtml(item.reason || "")}</p>
                    </div>
                    <span class="pill ${escapeHtml(item.state || "gated")}">${escapeHtml(item.state || "gated")}</span>
                </div>
                ${item.href ? `<div class="bank-actions" style="margin-top:12px;"><a class="bank-btn secondary" href="${escapeHtml(item.href)}">Open</a></div>` : ""}
            </div>
        `).join("");
    }

    function renderModules(payload) {
        const wallet = payload.wallet || {};
        const tokenFlow = payload.tokenFlow || {};
        const links = payload.links || {};
        const modules = [
            {
                title: "Wallet + Stablecoins",
                copy: `${escapeHtml(wallet.stablecoinDisplay || "Unavailable")} across ${Number(wallet.settlementAccountsCount || 0)} settlement account(s).`,
                actions: [
                    { label: "Open Wallet", href: links.wallet },
                    { label: "Add Funds / Withdraw", href: "#paymentsSection" }
                ]
            },
            {
                title: "MTK Tokens",
                copy: `${Number(wallet.utilityTokens || 0).toLocaleString()} MTK available with ${Number(tokenFlow.pendingRequests || 0)} pending request(s).`,
                actions: [
                    { label: "MTK Dashboard", href: links.tokens },
                    { label: "Buy Tokens", href: links.tokenization }
                ]
            },
            {
                title: "Culture / Meme Coins",
                copy: `${Number(wallet.wealthCoins || 0).toLocaleString()} culture coins visible across the seeded culture assets.`,
                actions: [
                    { label: "Open Culture Desk", href: links.culture }
                ]
            },
            {
                title: "Equity Visibility",
                copy: `${Number(wallet.equityCoins || 0).toLocaleString()} equity coins mapped to ${Number(wallet.shareHolding || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} shares.`,
                actions: [
                    { label: "Manage Equity", href: links.equity }
                ]
            },
            {
                title: "Transfer Center",
                copy: "Create and execute Grid quotes directly in the dashboard while preserving the same signed execution path used by the dedicated payments surface.",
                actions: [
                    { label: "Jump to Transfers", href: "#paymentsSection" },
                    { label: "Transactions", href: links.transactions || "/hub/grid/transactions.php" }
                ]
            },
            {
                title: "Transactions Ledger",
                copy: "Full transaction history has its own page so the main dashboard stays focused on balances, controls, and live transfer actions.",
                actions: [
                    { label: "Open Transactions", href: links.transactions || "/hub/grid/transactions.php" }
                ]
            },
            {
                title: "Cards / Rewards / Ramps",
                copy: "These surfaces remain truthful and capability-gated. The cockpit shows their current state and only opens them when the environment and vendor enablement allow it.",
                actions: []
            }
        ];

        moduleList.innerHTML = modules.map((module) => `
            <div class="module-card">
                <p class="module-title">${escapeHtml(module.title)}</p>
                <p class="muted">${escapeHtml(module.copy)}</p>
                ${module.actions.length ? `
                    <div class="bank-actions" style="margin-top:12px;">
                        ${module.actions.map((action) => `<a class="bank-btn secondary" href="${escapeHtml(action.href)}">${escapeHtml(action.label)}</a>`).join("")}
                    </div>
                ` : ""}
            </div>
        `).join("");
    }

    function loadTransferPreset(presetKey) {
        const amountMap = {
            "uma-payout": 250,
            "treasury-rebalance": 1000,
            "vendor-payout": 175,
        };
        const descriptionMap = {
            "uma-payout": "Meta Humans stablecoin payout",
            "treasury-rebalance": "Meta Humans treasury rebalance",
            "vendor-payout": "Meta Humans vendor payout",
        };
        const request = {
            source: {
                sourceType: "ACCOUNT",
            },
            destination: {
                destinationType: "UMA_ADDRESS",
                umaAddress: "$recipient@wallet.example",
                currency: "USD",
            },
            lockedCurrencySide: "SENDING",
            lockedCurrencyAmount: amountMap[presetKey] || 100,
            immediatelyExecute: false,
            description: descriptionMap[presetKey] || "Meta Humans Banking Grid transfer",
        };
        quoteRequestInput.value = JSON.stringify(request, null, 2);
        window.location.hash = "#paymentsSection";
        setNote(createResult, "Preset loaded into the transfer center. Update the recipient and amount before creating the quote.", false);
    }

    function renderAssetPanels(payload) {
        const wallet = payload.wallet || {};
        const tokenFlow = payload.tokenFlow || {};
        const equity = payload.equity || {};
        const links = payload.links || {};
        const utilityTicker = dataSafe(tokenFlow, "utilityTicker") || "MTK";
        const utilityPriceUsd = Number(tokenFlow.utilityPriceUsd || 0);
        const utilityMeta = tokenFlow.utilityMeta || {};
        const cultureRows = Array.isArray(tokenFlow.cultureBalances) ? tokenFlow.cultureBalances : [];
        const equityPositions = Array.isArray(equity.positions) ? equity.positions : [];

        const bonusSummary = [
            `$${Number(utilityMeta.bonusStartUsd || 100).toLocaleString()} starts the bonus ladder`,
            `+${Number(utilityMeta.bonusBasePct || 5).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}% base`,
            `+${Number(utilityMeta.bonusStepPct || 1).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}% every $${Number(utilityMeta.bonusStepUsd || 50).toLocaleString()}`,
            `cap ${Number(utilityMeta.maxBonusPct || 20).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}%`,
        ].join(" | ");

        const stablecoinPanel = `
            <article class="module-card asset-panel">
                <div class="asset-row">
                    <div>
                        <p class="module-title">Stablecoin Treasury</p>
                        <p class="muted">Drive liquidity, prepare payouts, and move directly into signed settlement flows.</p>
                    </div>
                    <span class="pill settlement">USD Rail</span>
                </div>
                <div>
                    <p class="metric-label">Available Liquidity</p>
                    <p class="asset-stat">${escapeHtml(wallet.stablecoinDisplay || "Unavailable")}</p>
                    <p class="muted">${Number(wallet.settlementAccountsCount || 0).toLocaleString()} settlement account(s) discovered for this tenant.</p>
                </div>
                <div class="asset-list">
                    <div class="asset-mini"><span>Funding posture</span><strong>Wallet + settlement account driven</strong></div>
                    <div class="asset-mini"><span>Outbound execution</span><strong>Uses signed Grid quote execution</strong></div>
                </div>
                <div class="asset-actions">
                    <button class="bank-btn secondary" type="button" data-preset="uma-payout">Load UMA Payout</button>
                    <button class="bank-btn secondary" type="button" data-preset="treasury-rebalance">Load Treasury Rebalance</button>
                    <a class="bank-btn secondary" href="${escapeHtml(links.wallet || "/hub/wallet.php")}">Open Wallet</a>
                    <a class="bank-btn secondary" href="${escapeHtml(links.passkey || "/hub/grid/passkey.php")}">Authorize Session</a>
                </div>
            </article>
        `;

        const mtkPanel = `
            <article class="module-card asset-panel">
                <div class="asset-row">
                    <div>
                        <p class="module-title">${escapeHtml(utilityTicker)} Action Panel</p>
                        <p class="muted">Token top-up, pending transfer visibility, and current pricing guidance.</p>
                    </div>
                    <span class="pill platform">${escapeHtml(utilityTicker)}</span>
                </div>
                <div>
                    <p class="metric-label">${escapeHtml(utilityTicker)} Balance</p>
                    <p class="asset-stat">${Number(wallet.utilityTokens || 0).toLocaleString()}</p>
                    <p class="muted">${utilityPriceUsd > 0 ? `$${utilityPriceUsd.toFixed(4)} per ${escapeHtml(utilityTicker)} | ${escapeHtml(bonusSummary)}` : escapeHtml(bonusSummary)}</p>
                </div>
                <div class="asset-list">
                    <div class="asset-mini"><span>Pending requests</span><strong>${Number(tokenFlow.pendingRequests || 0).toLocaleString()}</strong></div>
                    <div class="asset-mini"><span>Pending transfers</span><strong>${Number(tokenFlow.pendingTransfers || 0).toLocaleString()}</strong></div>
                    <div class="asset-mini"><span>Minimum top-up</span><strong>$${Number(utilityMeta.minBuyUsd || 49).toLocaleString()}</strong></div>
                </div>
                <div class="asset-actions">
                    <a class="bank-btn secondary" href="${escapeHtml(links.tokens || "/hub/tokens/tokens.php")}">Open MTK Desk</a>
                    <a class="bank-btn secondary" href="${escapeHtml(links.tokenization || "/hub/genesis/tokenization.php")}">Buy ${escapeHtml(utilityTicker)}</a>
                    <a class="bank-btn secondary" href="${escapeHtml(links.transactions || "/hub/grid/transactions.php")}">Review Ledger</a>
                </div>
            </article>
        `;

        const cultureItems = cultureRows.length
            ? cultureRows.slice(0, 4).map((item) => `
                <div class="asset-mini">
                    <span>${escapeHtml(item.displayName || item.key || "Culture asset")} (${escapeHtml(item.ticker || item.key || "")})</span>
                    <strong>${Number(item.balance || 0).toLocaleString()}${item.currentPriceUsd ? ` | $${Number(item.currentPriceUsd).toFixed(2)}` : ""}</strong>
                </div>
            `).join("")
            : '<div class="empty-state">No culture / meme coin balances are currently recorded for this user.</div>';

        const culturePanel = `
            <article class="module-card asset-panel">
                <div class="asset-row">
                    <div>
                        <p class="module-title">Culture / Meme Coin Panel</p>
                        <p class="muted">Holdings, spot pricing, and quick routing into the culture coin desk.</p>
                    </div>
                    <span class="pill platform">Culture</span>
                </div>
                <div>
                    <p class="metric-label">Visible Culture Holdings</p>
                    <p class="asset-stat">${Number(wallet.wealthCoins || 0).toLocaleString()}</p>
                    <p class="muted">${cultureRows.length ? `${cultureRows.length} seeded culture asset(s) surfaced from tokenomics.` : "Culture balances will appear here as soon as they are funded."}</p>
                </div>
                <div class="asset-list">${cultureItems}</div>
                <div class="asset-actions">
                    <a class="bank-btn secondary" href="${escapeHtml(links.culture || "/hub/coins/culture.php")}">Open Culture Desk</a>
                    <a class="bank-btn secondary" href="${escapeHtml(links.tokenization || "/hub/genesis/tokenization.php")}">Top Up MTK First</a>
                </div>
            </article>
        `;

        const equityItems = equityPositions.length
            ? equityPositions.slice(0, 4).map((item) => `
                <div class="asset-mini">
                    <span>${escapeHtml(item.className || "Equity class")}</span>
                    <strong>${Number(item.shares || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} sh${item.estimatedValueUsd ? ` | $${Number(item.estimatedValueUsd).toFixed(2)}` : ""}</strong>
                </div>
            `).join("")
            : '<div class="empty-state">No equity positions are currently visible for this account.</div>';

        const equityPanel = `
            <article class="module-card asset-panel">
                <div class="asset-row">
                    <div>
                        <p class="module-title">Equity Visibility</p>
                        <p class="muted">Top classes, estimated share value, and direct routing into the equity manager.</p>
                    </div>
                    <span class="pill platform">Equity</span>
                </div>
                <div>
                    <p class="metric-label">Visible Share Holding</p>
                    <p class="asset-stat">${Number(wallet.shareHolding || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                    <p class="muted">${Number(equity.estimatedValueUsd || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} USD estimated across visible positions.</p>
                </div>
                <div class="asset-list">${equityItems}</div>
                <div class="asset-actions">
                    <a class="bank-btn secondary" href="${escapeHtml(links.equity || "/hub/equity/manage.php")}">Manage Equity</a>
                    <a class="bank-btn secondary" href="${escapeHtml(links.transactions || "/hub/grid/transactions.php")}">Accounting Handoff</a>
                </div>
            </article>
        `;

        assetPanelGrid.innerHTML = stablecoinPanel + mtkPanel + culturePanel + equityPanel;
        assetPanelGrid.querySelectorAll("[data-preset]").forEach((button) => {
            button.addEventListener("click", () => {
                loadTransferPreset(String(button.dataset.preset || ""));
            });
        });
    }

    function renderTransferQuotes(quotes) {
        const rows = Array.isArray(quotes) ? quotes : [];
        if (!rows.length) {
            quotesBody.innerHTML = '<tr><td colspan="5">No quotes yet.</td></tr>';
            return;
        }
        quotesBody.innerHTML = "";
        rows.forEach((q) => {
            const quoteId = String(q.quoteId || "");
            const status = String(q.status || "");
            const tx = String(q.transactionId || "");
            const updated = String(q.updatedAt || "");
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td class="mono">${escapeHtml(quoteId)}</td>
                <td>${escapeHtml(status)}</td>
                <td class="mono">${escapeHtml(tx)}</td>
                <td>${escapeHtml(updated)}</td>
                <td><button class="bank-btn secondary" type="button" data-quote="${escapeHtml(quoteId)}">Execute</button></td>
            `;
            tr.querySelector("button").addEventListener("click", async () => {
                executeQuoteId.value = quoteId;
                await handleExecute();
            });
            quotesBody.appendChild(tr);
        });
    }

    function isTerminalStatus(value) {
        const status = String(value || "").trim().toUpperCase();
        return ["COMPLETED", "REJECTED", "FAILED", "REFUNDED", "EXPIRED"].includes(status);
    }

    function updateTransferAutoRefreshButton() {
        const on = Boolean(transferAutoRefreshTimer);
        transferAutoRefreshBtn.textContent = `Auto Refresh: ${on ? "On" : "Off"}`;
    }

    function stopTransferAutoRefresh() {
        if (transferAutoRefreshTimer) {
            clearInterval(transferAutoRefreshTimer);
            transferAutoRefreshTimer = null;
        }
        transferAutoRefreshUntilMs = 0;
        updateTransferAutoRefreshButton();
    }

    function startTransferAutoRefresh(durationMs) {
        const dur = typeof durationMs === "number" && durationMs > 0 ? durationMs : 0;
        if (dur > 0) {
            transferAutoRefreshUntilMs = Math.max(transferAutoRefreshUntilMs, Date.now() + dur);
        }
        if (transferAutoRefreshTimer) {
            updateTransferAutoRefreshButton();
            return;
        }
        transferAutoRefreshTimer = setInterval(async () => {
            try {
                const status = await quoteApiCall("status", "GET");
                currentTenantId = String(status.tenantId || currentTenantId);
                currentAccountId = String(status.accountId || currentAccountId);
                renderTransferStatus(status);
                const quotes = Array.isArray(status.recentQuotes) ? status.recentQuotes : [];
                const match = lastCreatedQuoteId ? quotes.find((q) => String(q.quoteId || "") === lastCreatedQuoteId) : null;
                if (match && isTerminalStatus(match.status)) {
                    stopTransferAutoRefresh();
                    return;
                }
                if (transferAutoRefreshUntilMs && Date.now() >= transferAutoRefreshUntilMs) {
                    stopTransferAutoRefresh();
                }
            } catch (error) {
                stopTransferAutoRefresh();
            }
        }, 5000);
        updateTransferAutoRefreshButton();
    }

    function renderTransferStatus(status) {
        currentTenantId = String(status.tenantId || currentTenantId);
        currentAccountId = String(status.accountId || currentAccountId);
        transferTenantId.textContent = currentTenantId || "—";
        transferAccountId.textContent = currentAccountId || "—";
        const localKey = readStoredSessionKey(currentTenantId);
        localKeyStatus.textContent = localKey ? (localKey.sandboxSignatureOnly ? "Sandbox key" : "Present") : "Missing";
        renderTransferQuotes(status.recentQuotes || []);
    }

    async function refreshTransferStatus() {
        try {
            const status = await quoteApiCall("status", "GET");
            renderTransferStatus(status);
        } catch (error) {
            setNote(executeResult, String(error && error.message ? error.message : error), true);
        }
    }

    async function handleCreateQuote() {
        setNote(createResult, "Creating quote...", false);
        let req = null;
        try {
            req = JSON.parse(String(quoteRequestInput.value || ""));
        } catch (error) {
            setNote(createResult, "Invalid JSON in quote request.", true);
            return;
        }
        const payload = {
            quoteRequest: req,
            useTenantEmbeddedWalletSource: Boolean(useEmbeddedWalletSource.checked),
        };
        try {
            const created = await quoteApiCall("create_quote", "POST", payload);
            lastCreatedQuoteId = String(created.quoteId || "");
            lastPayloadToSign = String(created.payloadToSign || "");
            executeQuoteId.value = lastCreatedQuoteId;
            setNote(createResult, `Created ${lastCreatedQuoteId}${created.forcedImmediatelyExecuteFalse ? " (immediatelyExecute was forced to false)" : ""}`, false);
            await refreshTransferStatus();
        } catch (error) {
            setNote(createResult, String(error && error.message ? error.message : error), true);
        }
    }

    async function handleExecute() {
        setNote(executeResult, "Executing quote...", false);
        const quoteId = String(executeQuoteId.value || "").trim();
        if (!quoteId) {
            setNote(executeResult, "Missing quoteId.", true);
            return;
        }
        const manualSig = String(signatureOverride.value || "").trim();
        let sig = manualSig;
        if (!sig) {
            const localKey = readStoredSessionKey(currentTenantId);
            if (!localKey) {
                setNote(executeResult, "No local Grid signing key present. Use Authorize Grid Session first.", true);
                return;
            }
            if (!lastPayloadToSign) {
                const status = await quoteApiCall("status", "GET");
                const recent = Array.isArray(status.recentQuotes) ? status.recentQuotes : [];
                const match = recent.find((q) => String(q.quoteId || "") === quoteId);
                lastPayloadToSign = match ? String(match.payloadToSign || "") : lastPayloadToSign;
            }
            sig = await buildGridWalletSignature(localKey, lastPayloadToSign);
        }
        try {
            const resp = await quoteApiCall("execute_quote", "POST", { quoteId, gridWalletSignature: sig });
            setNote(executeResult, `Executed ${quoteId}`, false);
            renderTransferQuotes(resp.recentQuotes || []);
            startTransferAutoRefresh(60000);
            await refreshDashboard();
        } catch (error) {
            setNote(executeResult, String(error && error.message ? error.message : error), true);
        }
    }

    async function refreshDashboard() {
        refreshBtn.disabled = true;
        refreshBtn.textContent = "Refreshing...";
        try {
            const payload = await fetchDashboard();
            dashboardState = payload;
            renderPlatform(payload);
            renderMetrics(payload);
            renderAssetPanels(payload);
            renderAccounts(payload.grid || {});
            renderCapabilities(payload.grid || {});
            renderModules(payload);
        } catch (error) {
            platformNotes.classList.add("err");
            platformNotes.textContent = `Dashboard load failed: ${error.message}`;
            assetPanelGrid.innerHTML = '<div class="empty-state">Unable to load asset action panels.</div>';
            accountsList.innerHTML = '<div class="empty-state">Unable to load account state.</div>';
            capabilitiesList.innerHTML = '<div class="empty-state">Unable to load capability state.</div>';
            moduleList.innerHTML = '<div class="empty-state">Unable to load module summaries.</div>';
        } finally {
            refreshBtn.disabled = false;
            refreshBtn.textContent = "Refresh Dashboard";
        }
    }

    refreshBtn.addEventListener("click", refreshDashboard);
    transferRefreshBtn.addEventListener("click", refreshTransferStatus);
    transferAutoRefreshBtn.addEventListener("click", () => {
        if (transferAutoRefreshTimer) {
            stopTransferAutoRefresh();
            return;
        }
        startTransferAutoRefresh(0);
    });
    createQuoteBtn.addEventListener("click", handleCreateQuote);
    executeBtn.addEventListener("click", handleExecute);
    autoFillLastBtn.addEventListener("click", () => {
        if (lastCreatedQuoteId) {
            executeQuoteId.value = lastCreatedQuoteId;
        }
    });

    Promise.all([
        refreshDashboard(),
        refreshTransferStatus(),
    ]).catch((error) => {
        setNote(executeResult, String(error && error.message ? error.message : error), true);
    });
</script>
</body>
</html>
