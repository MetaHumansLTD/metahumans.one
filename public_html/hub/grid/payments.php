<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../gear/grid/sr_client.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/grid/payments.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/grid/payments.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$cfg = mh_grid_read_cfg();
$baseUrlSet = isset($cfg['base_url']) && is_string($cfg['base_url']) && trim($cfg['base_url']) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grid Payments | Meta Humans Hub</title>
    <?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }
        .grid-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 18px 18px; }
        .grid-title { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); margin: 0 0 10px 0; letter-spacing: 1px; text-transform: uppercase; }
        .grid-sub { color: rgba(255,255,255,0.78); margin: 0 0 18px 0; }
        .grid-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid-item { background: rgba(0,0,0,0.18); border: 1px solid rgba(0, 212, 255, 0.12); border-radius: 12px; padding: 12px 12px; }
        .grid-k { color: rgba(255,255,255,0.72); font-size: 0.92rem; margin: 0 0 6px 0; }
        .grid-v { font-weight: 700; margin: 0; color: rgba(255,255,255,0.92); word-break: break-word; }
        .grid-v.ok { color: #8ff0a4; }
        .grid-v.no { color: #ffb4a2; }
        .grid-links { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
        .grid-btn { display: inline-block; padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.28); color: rgba(0, 212, 255, 0.95); text-decoration: none; font-weight: 700; letter-spacing: 0.6px; background: transparent; cursor: pointer; }
        .grid-btn:hover { background: rgba(0, 212, 255, 0.10); }
        .grid-btn.secondary { color: rgba(255,255,255,0.90); border-color: rgba(255,255,255,0.14); }
        .grid-btn.danger { color: rgba(255, 180, 162, 0.95); border-color: rgba(255, 180, 162, 0.25); }
        .grid-input { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.16); background: rgba(0,0,0,0.18); color: rgba(255,255,255,0.92); box-sizing: border-box; }
        .grid-textarea { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.16); background: rgba(0,0,0,0.18); color: rgba(255,255,255,0.92); box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .grid-note { border-radius: 12px; border: 1px solid rgba(0, 212, 255, 0.12); padding: 10px 12px; background: rgba(0,0,0,0.14); color: rgba(255,255,255,0.80); }
        .grid-note.err { border-color: rgba(255, 109, 109, 0.25); color: rgba(255, 180, 162, 0.92); }
        .grid-table { width: 100%; border-collapse: collapse; }
        .grid-table th { text-align: left; color: rgba(255,255,255,0.76); font-weight: 700; padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .grid-table td { padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
        .grid-actions { display:flex; gap:8px; flex-wrap:wrap; }
        @media (max-width: 900px) { .grid-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<main class="main-content">
    <div class="grid-card">
        <h1 class="grid-title">Grid Payments</h1>
        <p class="grid-sub">Create and execute quotes, then track transaction status via webhooks (with a query backstop).</p>

        <?php if (!$baseUrlSet): ?>
            <div class="grid-note err">Grid is not configured yet. Set the Grid base URL and credentials first.</div>
        <?php endif; ?>

        <div class="grid-row" style="margin-top:12px;">
            <div class="grid-item">
                <p class="grid-k">Tenant</p>
                <p class="grid-v" id="tenantId">—</p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Embedded Wallet Account</p>
                <p class="grid-v" id="accountId">—</p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Active Grid Session</p>
                <p class="grid-v" id="activeSession">—</p>
            </div>
            <div class="grid-item">
                <p class="grid-k">Local Signing Key</p>
                <p class="grid-v" id="localKeyStatus">—</p>
            </div>
        </div>

        <div class="grid-links">
            <button class="grid-btn" id="refreshBtn" type="button">Refresh Status</button>
            <button class="grid-btn secondary" id="autoRefreshBtn" type="button">Auto Refresh: Off</button>
            <a class="grid-btn secondary" href="/hub/grid/passkey.php">Authorize Grid Session</a>
            <a class="grid-btn secondary" href="/hub/grid/">Back to Grid</a>
        </div>

        <div style="margin-top:18px;">
            <div class="grid-item">
                <p class="grid-k">Create Quote (JSON)</p>
                <textarea class="grid-textarea" id="quoteRequestInput" rows="10"><?php echo htmlspecialchars(json_encode([
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
                        'description' => 'Test payment',
                    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), ENT_QUOTES); ?></textarea>
                <div class="grid-actions" style="margin-top:10px;">
                    <button class="grid-btn" id="createQuoteBtn" type="button">Create Quote</button>
                    <label style="display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.8);">
                        <input type="checkbox" id="useEmbeddedWalletSource" checked>
                        Use tenant embedded wallet as source
                    </label>
                </div>
                <div class="grid-note" id="createResult" style="margin-top:10px;">—</div>
            </div>
        </div>

        <div style="margin-top:18px;">
            <div class="grid-item">
                <p class="grid-k">Execute Quote</p>
                <input class="grid-input" id="executeQuoteId" type="text" placeholder="Quote:... (or paste quoteId)">
                <div class="grid-actions" style="margin-top:10px;">
                    <button class="grid-btn" id="executeBtn" type="button">Execute</button>
                    <button class="grid-btn secondary" id="autoFillLastBtn" type="button">Use Last Quote</button>
                </div>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;color:rgba(255,255,255,0.80);">Advanced</summary>
                    <div style="margin-top:10px;">
                        <p class="grid-k" style="margin:0 0 6px 0;">Manual Grid-Wallet-Signature override</p>
                        <textarea class="grid-textarea" id="signatureOverride" rows="4" placeholder="Leave blank to auto-sign with the local Grid session key."></textarea>
                    </div>
                </details>
                <div class="grid-note" id="executeResult" style="margin-top:10px;">—</div>
            </div>
        </div>

        <div style="margin-top:18px;">
            <div class="grid-item">
                <p class="grid-k">Recent Quotes</p>
                <div style="overflow:auto;">
                    <table class="grid-table">
                        <thead>
                        <tr>
                            <th>Quote</th>
                            <th>Status</th>
                            <th>Transaction</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="quotesBody">
                        <tr><td colspan="5" style="color:rgba(255,255,255,0.7);padding:12px 8px;">No quotes yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/footer.php'; ?>
<script>
    const apiBase = "/gear/grid/quotes.php";
    const tenantIdEl = document.getElementById("tenantId");
    const accountIdEl = document.getElementById("accountId");
    const activeSessionEl = document.getElementById("activeSession");
    const localKeyStatusEl = document.getElementById("localKeyStatus");
    const refreshBtn = document.getElementById("refreshBtn");
    const autoRefreshBtn = document.getElementById("autoRefreshBtn");
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
    const TURNKEY_STAMP_SCHEME = "SIGNATURE_SCHEME_TK_API_P256";

    let cryptoDepsPromise = null;
    let currentTenantId = "";
    let currentAccountId = "";
    let lastCreatedQuoteId = "";
    let lastPayloadToSign = "";
    let autoRefreshTimer = null;
    let autoRefreshUntilMs = 0;

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

    async function apiCall(action, method, payload) {
        const res = await fetch(`${apiBase}?action=${encodeURIComponent(action)}`, {
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

    function renderQuotes(quotes) {
        const rows = Array.isArray(quotes) ? quotes : [];
        if (rows.length === 0) {
            quotesBody.innerHTML = '<tr><td colspan="5" style="color:rgba(255,255,255,0.7);padding:12px 8px;">No quotes yet.</td></tr>';
            return;
        }
        quotesBody.innerHTML = "";
        rows.forEach((q) => {
            const tr = document.createElement("tr");
            const quoteId = String(q.quoteId || "");
            const status = String(q.status || "");
            const tx = String(q.transactionId || "");
            const updated = String(q.updatedAt || "");
            tr.innerHTML = `
                <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \\"Liberation Mono\\", \\"Courier New\\", monospace;">${quoteId}</td>
                <td>${status}</td>
                <td style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \\"Liberation Mono\\", \\"Courier New\\", monospace;">${tx}</td>
                <td>${updated}</td>
                <td><div class="grid-actions"><button class="grid-btn secondary" type="button" data-quote="${quoteId}">Execute</button></div></td>
            `;
            tr.querySelector("button").addEventListener("click", async () => {
                executeQuoteId.value = quoteId;
                await handleExecute();
            });
            quotesBody.appendChild(tr);
        });
    }

    async function refreshStatus() {
        const status = await apiCall("status", "GET");
        currentTenantId = String(status.tenantId || "");
        currentAccountId = String(status.accountId || "");
        tenantIdEl.textContent = currentTenantId || "—";
        accountIdEl.textContent = currentAccountId || "—";
        const s = status.activeSession || null;
        activeSessionEl.textContent = s && s.expiresAt ? `Active until ${String(s.expiresAt)}` : (s ? "Active" : "None");
        const localKey = readStoredSessionKey(currentTenantId);
        localKeyStatusEl.textContent = localKey ? (localKey.sandboxSignatureOnly ? "Sandbox key (signature-only)" : "Present") : "Missing";
        renderQuotes(status.recentQuotes || []);
    }

    function isTerminalStatus(value) {
        const status = String(value || "").trim().toUpperCase();
        return ["COMPLETED", "REJECTED", "FAILED", "REFUNDED", "EXPIRED"].includes(status);
    }

    function updateAutoRefreshButton() {
        const on = Boolean(autoRefreshTimer);
        autoRefreshBtn.textContent = `Auto Refresh: ${on ? "On" : "Off"}`;
    }

    function stopAutoRefresh() {
        if (autoRefreshTimer) {
            clearInterval(autoRefreshTimer);
            autoRefreshTimer = null;
        }
        autoRefreshUntilMs = 0;
        updateAutoRefreshButton();
    }

    function startAutoRefresh(durationMs) {
        const dur = typeof durationMs === "number" && durationMs > 0 ? durationMs : 0;
        if (dur > 0) {
            autoRefreshUntilMs = Math.max(autoRefreshUntilMs, Date.now() + dur);
        }
        if (autoRefreshTimer) {
            updateAutoRefreshButton();
            return;
        }
        autoRefreshTimer = setInterval(async () => {
            try {
                const status = await apiCall("status", "GET");
                currentTenantId = String(status.tenantId || currentTenantId);
                currentAccountId = String(status.accountId || currentAccountId);
                renderQuotes(status.recentQuotes || []);

                const quotes = Array.isArray(status.recentQuotes) ? status.recentQuotes : [];
                const match = lastCreatedQuoteId ? quotes.find((q) => String(q.quoteId || "") === lastCreatedQuoteId) : null;
                if (match && isTerminalStatus(match.status)) {
                    stopAutoRefresh();
                    return;
                }
                if (autoRefreshUntilMs && Date.now() >= autoRefreshUntilMs) {
                    stopAutoRefresh();
                }
            } catch (e) {
                stopAutoRefresh();
            }
        }, 5000);
        updateAutoRefreshButton();
    }

    async function handleCreateQuote() {
        setNote(createResult, "Creating quote...", false);
        let req = null;
        try {
            req = JSON.parse(String(quoteRequestInput.value || ""));
        } catch (e) {
            setNote(createResult, "Invalid JSON in quote request.", true);
            return;
        }
        const payload = {
            quoteRequest: req,
            useTenantEmbeddedWalletSource: Boolean(useEmbeddedWalletSource.checked),
        };
        try {
            const created = await apiCall("create_quote", "POST", payload);
            lastCreatedQuoteId = String(created.quoteId || "");
            lastPayloadToSign = String(created.payloadToSign || "");
            executeQuoteId.value = lastCreatedQuoteId;
            setNote(createResult, `Created ${lastCreatedQuoteId}${created.forcedImmediatelyExecuteFalse ? " (immediatelyExecute was forced to false)" : ""}`, false);
            await refreshStatus();
        } catch (e) {
            setNote(createResult, String(e && e.message ? e.message : e), true);
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
                const status = await apiCall("status", "GET");
                const recent = Array.isArray(status.recentQuotes) ? status.recentQuotes : [];
                const match = recent.find((q) => String(q.quoteId || "") === quoteId);
                lastPayloadToSign = match ? String(match.payloadToSign || "") : lastPayloadToSign;
            }
            sig = await buildGridWalletSignature(localKey, lastPayloadToSign);
        }
        try {
            const resp = await apiCall("execute_quote", "POST", { quoteId, gridWalletSignature: sig });
            setNote(executeResult, `Executed ${quoteId}`, false);
            renderQuotes(resp.recentQuotes || []);
            startAutoRefresh(60000);
        } catch (e) {
            setNote(executeResult, String(e && e.message ? e.message : e), true);
        }
    }

    refreshBtn.addEventListener("click", async () => {
        try {
            await refreshStatus();
        } catch (e) {
            setNote(executeResult, String(e && e.message ? e.message : e), true);
        }
    });
    autoRefreshBtn.addEventListener("click", () => {
        if (autoRefreshTimer) {
            stopAutoRefresh();
            return;
        }
        startAutoRefresh(0);
    });
    createQuoteBtn.addEventListener("click", handleCreateQuote);
    executeBtn.addEventListener("click", handleExecute);
    autoFillLastBtn.addEventListener("click", () => {
        if (lastCreatedQuoteId) {
            executeQuoteId.value = lastCreatedQuoteId;
        }
    });

    refreshStatus().catch((e) => {
        setNote(executeResult, String(e && e.message ? e.message : e), true);
    });
</script>
</body>
</html>
