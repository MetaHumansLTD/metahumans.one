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
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/grid/transactions.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/grid/transactions.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions | Meta Humans Banking Grid</title>
    <?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content { max-width: 1480px; margin: 0 auto; padding: 20px 16px 56px; }
        .tx-shell { display: grid; gap: 18px; }
        .tx-card {
            position: relative;
            overflow: hidden;
            border-radius: 22px;
            border: 1px solid rgba(255, 183, 77, 0.14);
            background: radial-gradient(circle at top, rgba(18, 13, 6, 0.92), rgba(5, 7, 12, 0.96));
            box-shadow: 0 18px 46px rgba(0, 0, 0, 0.30);
            backdrop-filter: blur(14px);
            padding: 20px;
        }
        .tx-card::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(140deg, rgba(255, 196, 84, 0.08), transparent 28%, transparent 68%, rgba(0, 163, 255, 0.08));
        }
        .tx-head,
        .section-head,
        .row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .tx-title,
        .section-title {
            margin: 0 0 8px;
            color: #fff;
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .section-title { font-size: 1rem; }
        .tx-copy,
        .muted {
            margin: 0;
            color: rgba(255,255,255,0.72);
            line-height: 1.6;
        }
        .tx-actions,
        .pagination,
        .filter-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .tx-grid-3,
        .tx-grid-2 {
            display: grid;
            gap: 18px;
        }
        .tx-grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .tx-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .tx-chipbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }
        .tx-chip,
        .filter-field,
        .empty-state,
        .metric-card {
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
        }
        .tx-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            color: rgba(255,255,255,0.88);
        }
        .tx-chip strong { color: #fff; }
        .tx-btn,
        .filter-field button,
        .filter-field select,
        .filter-field input {
            min-height: 42px;
            border-radius: 14px;
            font: inherit;
        }
        .tx-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 1px solid rgba(255, 183, 77, 0.28);
            background: rgba(255,255,255,0.03);
            color: rgba(255, 220, 171, 0.96);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }
        .tx-btn.secondary {
            border-color: rgba(255,255,255,0.14);
            color: rgba(255,255,255,0.88);
        }
        .filter-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .filter-field {
            display: grid;
            gap: 8px;
            padding: 12px;
        }
        .filter-field label,
        .metric-label {
            color: rgba(255,255,255,0.62);
            font-size: 0.88rem;
        }
        .filter-field input,
        .filter-field select {
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.24);
            color: #fff;
            padding: 10px 12px;
            box-sizing: border-box;
        }
        .metric-card { padding: 16px; }
        .metric-value {
            margin: 8px 0 0;
            color: #fff;
            font-size: 1.8rem;
            font-weight: 800;
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
        .pill.settlement { border-color: rgba(255, 183, 77, 0.20); color: rgba(255, 220, 171, 0.96); }
        .pill.platform { border-color: rgba(122, 175, 255, 0.20); color: rgba(185, 214, 255, 0.96); }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            word-break: break-word;
        }
        .bank-table-wrap {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        table.bank-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 860px;
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
        .empty-state {
            padding: 14px;
            color: rgba(255,255,255,0.76);
        }
        @media (max-width: 1260px) {
            .filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .tx-grid-3,
            .tx-grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .filter-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<main class="main-content">
    <div class="tx-shell">
        <section class="tx-card">
            <div class="tx-head">
                <div>
                    <h1 class="tx-title">Transactions</h1>
                    <p class="tx-copy">Server-filtered transaction workbench for settlement quotes and accounting handoff rows. This page now handles date filters, status filters, pagination, and export-ready ledger delivery.</p>
                </div>
                <div class="tx-actions">
                    <button class="tx-btn" id="refreshBtn" type="button">Refresh</button>
                    <a class="tx-btn secondary" href="/hub/grid/dashboard.php">Back To Dashboard</a>
                    <a class="tx-btn secondary" href="/hub/grid/passkey.php">Authorize Grid Session</a>
                </div>
            </div>
            <div class="tx-chipbar">
                <span class="tx-chip">Tenant <strong id="tenantChip">Loading...</strong></span>
                <span class="tx-chip">Account <strong id="accountChip">Loading...</strong></span>
                <span class="tx-chip">Session <strong id="sessionChip">Loading...</strong></span>
            </div>
        </section>

        <section class="tx-card">
            <div class="section-head">
                <div>
                    <h2 class="section-title">Filters</h2>
                    <p class="muted">Filters are applied on the server so exports and pagination match what finance sees.</p>
                </div>
                <div class="tx-actions">
                    <button class="tx-btn secondary" id="applyFiltersBtn" type="button">Apply Filters</button>
                    <button class="tx-btn secondary" id="resetFiltersBtn" type="button">Reset</button>
                </div>
            </div>
            <div class="filter-grid">
                <div class="filter-field">
                    <label for="startDateInput">Start date</label>
                    <input id="startDateInput" type="date">
                </div>
                <div class="filter-field">
                    <label for="endDateInput">End date</label>
                    <input id="endDateInput" type="date">
                </div>
                <div class="filter-field">
                    <label for="statusSelect">Status</label>
                    <select id="statusSelect">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="posted">Posted</option>
                        <option value="failed">Failed</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="kindSelect">Ledger view</label>
                    <select id="kindSelect">
                        <option value="">All activity</option>
                        <option value="settlement">Settlement only</option>
                        <option value="platform">Platform only</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="quotePageSizeSelect">Quote rows</label>
                    <select id="quotePageSizeSelect">
                        <option value="10">10 rows</option>
                        <option value="25">25 rows</option>
                        <option value="50">50 rows</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="activityPageSizeSelect">Accounting rows</label>
                    <select id="activityPageSizeSelect">
                        <option value="12">12 rows</option>
                        <option value="25">25 rows</option>
                        <option value="50">50 rows</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="tx-grid-3">
            <article class="metric-card">
                <p class="metric-label">Settlement Events</p>
                <p class="metric-value" id="settlementCount">0</p>
                <p class="muted">Filtered Grid quote and execution rows.</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Platform Finance Events</p>
                <p class="metric-value" id="platformCount">0</p>
                <p class="muted">Filtered MTK and platform-ledger rows.</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Accounting Rows</p>
                <p class="metric-value" id="accountingCount">0</p>
                <p class="muted">Rows eligible for accounting handoff export.</p>
            </article>
        </section>

        <section class="tx-grid-2">
            <article class="tx-card">
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Quote Ledger</h2>
                        <p class="muted">Settlement quote and execution history, paginated independently.</p>
                    </div>
                    <div class="pagination">
                        <span class="tx-chip">Page <strong id="quotePageInfo">1 / 1</strong></span>
                        <button class="tx-btn secondary" id="quotePrevBtn" type="button">Prev</button>
                        <button class="tx-btn secondary" id="quoteNextBtn" type="button">Next</button>
                        <a class="tx-btn secondary" id="quotesCsvLink" href="#" download>Export Quotes CSV</a>
                    </div>
                </div>
                <div class="bank-table-wrap">
                    <table class="bank-table">
                        <thead>
                            <tr>
                                <th>Quote</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Source</th>
                                <th>Destination</th>
                                <th>Transaction</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody id="quotesBody">
                            <tr><td colspan="7">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="tx-card">
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Accounting Handoff</h2>
                        <p class="muted">Normalized ledger rows for finance review and export.</p>
                    </div>
                    <div class="pagination">
                        <span class="tx-chip">Page <strong id="activityPageInfo">1 / 1</strong></span>
                        <button class="tx-btn secondary" id="activityPrevBtn" type="button">Prev</button>
                        <button class="tx-btn secondary" id="activityNextBtn" type="button">Next</button>
                        <a class="tx-btn secondary" id="accountingCsvLink" href="#" download>Export Accounting CSV</a>
                    </div>
                </div>
                <div class="bank-table-wrap">
                    <table class="bank-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Kind</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Reference</th>
                                <th>Source</th>
                                <th>Destination</th>
                            </tr>
                        </thead>
                        <tbody id="activityBody">
                            <tr><td colspan="8">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/footer.php'; ?>
<script>
    const endpoint = "/gear/grid/transactions_data.php";
    const refreshBtn = document.getElementById("refreshBtn");
    const applyFiltersBtn = document.getElementById("applyFiltersBtn");
    const resetFiltersBtn = document.getElementById("resetFiltersBtn");
    const quotePrevBtn = document.getElementById("quotePrevBtn");
    const quoteNextBtn = document.getElementById("quoteNextBtn");
    const activityPrevBtn = document.getElementById("activityPrevBtn");
    const activityNextBtn = document.getElementById("activityNextBtn");

    const startDateInput = document.getElementById("startDateInput");
    const endDateInput = document.getElementById("endDateInput");
    const statusSelect = document.getElementById("statusSelect");
    const kindSelect = document.getElementById("kindSelect");
    const quotePageSizeSelect = document.getElementById("quotePageSizeSelect");
    const activityPageSizeSelect = document.getElementById("activityPageSizeSelect");

    const tenantChip = document.getElementById("tenantChip");
    const accountChip = document.getElementById("accountChip");
    const sessionChip = document.getElementById("sessionChip");
    const settlementCount = document.getElementById("settlementCount");
    const platformCount = document.getElementById("platformCount");
    const accountingCount = document.getElementById("accountingCount");
    const quotePageInfo = document.getElementById("quotePageInfo");
    const activityPageInfo = document.getElementById("activityPageInfo");
    const quotesCsvLink = document.getElementById("quotesCsvLink");
    const accountingCsvLink = document.getElementById("accountingCsvLink");
    const quotesBody = document.getElementById("quotesBody");
    const activityBody = document.getElementById("activityBody");

    let state = {
        startDate: "",
        endDate: "",
        status: "",
        kind: "",
        quotePage: 1,
        quotePageSize: 10,
        activityPage: 1,
        activityPageSize: 12,
    };

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function buildQuery(overrides = {}) {
        const params = new URLSearchParams();
        const merged = { ...state, ...overrides };
        Object.entries(merged).forEach(([key, value]) => {
            if (value !== "" && value != null) {
                params.set(key, String(value));
            }
        });
        return params.toString();
    }

    async function fetchTransactions(overrides = {}) {
        const res = await fetch(`${endpoint}?${buildQuery(overrides)}`, { headers: { "Accept": "application/json" } });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data || data.ok !== true) {
            throw new Error((data && (data.error || data.message)) ? `${data.error || data.message}` : `HTTP ${res.status}`);
        }
        return data;
    }

    function syncFilterControls(filters) {
        startDateInput.value = String(filters.startDate || "");
        endDateInput.value = String(filters.endDate || "");
        statusSelect.value = String(filters.status || "");
        kindSelect.value = String(filters.kind || "");
        quotePageSizeSelect.value = String(filters.quotePageSize || "10");
        activityPageSizeSelect.value = String(filters.activityPageSize || "12");
    }

    function readFilterControls(resetPages) {
        state.startDate = String(startDateInput.value || "");
        state.endDate = String(endDateInput.value || "");
        state.status = String(statusSelect.value || "");
        state.kind = String(kindSelect.value || "");
        state.quotePageSize = Number(quotePageSizeSelect.value || 10);
        state.activityPageSize = Number(activityPageSizeSelect.value || 12);
        if (resetPages) {
            state.quotePage = 1;
            state.activityPage = 1;
        }
    }

    function renderQuotes(quotes, pagination) {
        const rows = Array.isArray(quotes) ? quotes : [];
        const meta = pagination || {};
        quotePageInfo.textContent = `${Number(meta.page || 1).toLocaleString()} / ${Number(meta.totalPages || 1).toLocaleString()}`;
        quotePrevBtn.disabled = !meta.hasPrev;
        quoteNextBtn.disabled = !meta.hasNext;
        if (!rows.length) {
            quotesBody.innerHTML = '<tr><td colspan="7">No quotes match the current filter set.</td></tr>';
            return;
        }
        quotesBody.innerHTML = rows.map((quote) => `
            <tr>
                <td class="mono">${escapeHtml(quote.quoteId || "")}</td>
                <td>${escapeHtml(quote.status || "")}</td>
                <td>${escapeHtml(quote.amountDisplay || "Unavailable")}</td>
                <td>${escapeHtml(quote.sourceType || "")}</td>
                <td>${escapeHtml(quote.destinationType || "")}</td>
                <td class="mono">${escapeHtml(quote.transactionId || "")}</td>
                <td>${escapeHtml(quote.happenedAt || quote.updatedAt || quote.createdAt || "")}</td>
            </tr>
        `).join("");
    }

    function renderActivity(rows, pagination) {
        const items = Array.isArray(rows) ? rows : [];
        const meta = pagination || {};
        activityPageInfo.textContent = `${Number(meta.page || 1).toLocaleString()} / ${Number(meta.totalPages || 1).toLocaleString()}`;
        activityPrevBtn.disabled = !meta.hasPrev;
        activityNextBtn.disabled = !meta.hasNext;
        if (!items.length) {
            activityBody.innerHTML = '<tr><td colspan="8">No accounting rows match the current filter set.</td></tr>';
            return;
        }
        activityBody.innerHTML = items.map((row) => `
            <tr>
                <td>${escapeHtml(row.happenedAt || "")}</td>
                <td><span class="pill ${escapeHtml(row.kind || "platform")}">${escapeHtml(row.kind || "platform")}</span></td>
                <td>${escapeHtml(row.category || row.title || "")}</td>
                <td>${escapeHtml(row.status || "")}</td>
                <td>${escapeHtml(row.amountDisplay || "Unavailable")}</td>
                <td class="mono">${escapeHtml(row.reference || "")}</td>
                <td>${escapeHtml(row.source || "")}</td>
                <td>${escapeHtml(row.destination || "")}</td>
            </tr>
        `).join("");
    }

    function updateExportLinks(payload) {
        const exportsObj = payload.exports || {};
        quotesCsvLink.href = exportsObj.quotesCsv || "#";
        accountingCsvLink.href = exportsObj.accountingCsv || "#";
    }

    async function refreshTransactions(overrides = {}) {
        refreshBtn.disabled = true;
        refreshBtn.textContent = "Refreshing...";
        try {
            const payload = await fetchTransactions(overrides);
            const filters = payload.filters || {};
            state = {
                startDate: String(filters.startDate || ""),
                endDate: String(filters.endDate || ""),
                status: String(filters.status || ""),
                kind: String(filters.kind || ""),
                quotePage: Number(filters.quotePage || 1),
                quotePageSize: Number(filters.quotePageSize || 10),
                activityPage: Number(filters.activityPage || 1),
                activityPageSize: Number(filters.activityPageSize || 12),
            };
            syncFilterControls(filters);
            tenantChip.textContent = String(payload.tenantId || "—");
            accountChip.textContent = String(payload.accountId || "—");
            sessionChip.textContent = payload.session && payload.session.expiresAt
                ? `${payload.session.status || "active"} until ${payload.session.expiresAt}`
                : (payload.session ? (payload.session.status || "active") : "No active session");
            settlementCount.textContent = Number((payload.counts || {}).settlement || 0).toLocaleString();
            platformCount.textContent = Number((payload.counts || {}).platform || 0).toLocaleString();
            accountingCount.textContent = Number((payload.counts || {}).accountingRows || 0).toLocaleString();
            renderQuotes(payload.quotes || [], payload.quotePagination || {});
            renderActivity(payload.activity || [], payload.activityPagination || {});
            updateExportLinks(payload);
        } catch (error) {
            const message = escapeHtml(error.message || error);
            quotesBody.innerHTML = `<tr><td colspan="7">${message}</td></tr>`;
            activityBody.innerHTML = `<tr><td colspan="8">${message}</td></tr>`;
        } finally {
            refreshBtn.disabled = false;
            refreshBtn.textContent = "Refresh";
        }
    }

    applyFiltersBtn.addEventListener("click", () => {
        readFilterControls(true);
        refreshTransactions();
    });
    resetFiltersBtn.addEventListener("click", () => {
        state = {
            startDate: "",
            endDate: "",
            status: "",
            kind: "",
            quotePage: 1,
            quotePageSize: 10,
            activityPage: 1,
            activityPageSize: 12,
        };
        syncFilterControls(state);
        refreshTransactions();
    });
    refreshBtn.addEventListener("click", () => refreshTransactions());
    quotePrevBtn.addEventListener("click", () => {
        if (state.quotePage > 1) {
            state.quotePage -= 1;
            refreshTransactions();
        }
    });
    quoteNextBtn.addEventListener("click", () => {
        state.quotePage += 1;
        refreshTransactions();
    });
    activityPrevBtn.addEventListener("click", () => {
        if (state.activityPage > 1) {
            state.activityPage -= 1;
            refreshTransactions();
        }
    });
    activityNextBtn.addEventListener("click", () => {
        state.activityPage += 1;
        refreshTransactions();
    });
    quotePageSizeSelect.addEventListener("change", () => {
        readFilterControls(true);
        refreshTransactions();
    });
    activityPageSizeSelect.addEventListener("change", () => {
        readFilterControls(true);
        refreshTransactions();
    });

    refreshTransactions();
</script>
</body>
</html>
