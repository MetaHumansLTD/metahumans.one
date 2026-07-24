<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('dbmanager_monitor', false);

$csrf = '';
if (session_status() === PHP_SESSION_ACTIVE) {
    $csrf = isset($_SESSION['mh_dbmmon_csrf']) ? (string)$_SESSION['mh_dbmmon_csrf'] : '';
}
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_dbmmon_csrf'] = $csrf;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DB Manager Monitor</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-mon-wrap { max-width: 1180px; margin: 0 auto; }
        .mh-mon-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .mh-mon-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 16px; overflow: hidden; }
        .mh-mon-title { margin: 0 0 12px 0; }
        .mh-mon-muted { opacity: 0.8; font-size: 12px; margin-bottom: 12px; }
        .mh-mon-row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.06); }
        .mh-mon-row:last-child { border-bottom: 0; }
        .mh-mon-k { opacity: 0.85; }
        .mh-mon-v { font-weight: 700; text-align: right; }
        .mh-mon-bad { color: #ffb3b3; }
        .mh-mon-warn { color: #ffd27a; }
        .mh-mon-ok { color: #a6f3c6; }
        .mh-mon-actions { display:flex; gap: 10px; flex-wrap: wrap; margin: 12px 0 0; }
        .mh-mon-btn { padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color: #e6f6ff; cursor: pointer; font-weight: 700; }
        .mh-mon-btn:hover { border-color: rgba(0,212,255,0.5); }
        pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 12px; margin: 0; font-size: 12px; line-height: 1.35; max-height: 340px; overflow: auto; }
        @media (max-width: 980px) { .mh-mon-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <section style="padding: 24px 0;">
        <div class="container mh-mon-wrap">
            <h1 class="mh-mon-title">DB Manager Monitor</h1>
            <div class="mh-mon-muted">Restricted admin view.</div>
            <div class="mh-mon-actions">
                <button class="mh-mon-btn" id="refresh">Refresh</button>
                <button class="mh-mon-btn" id="scan">Run Guard Scan</button>
                <a class="mh-mon-btn" href="/gear/settings/dbmanager.php" style="text-decoration:none; display:inline-block;">Open DB Manager</a>
                <a class="mh-mon-btn" href="/gear/settings/ports.php" style="text-decoration:none; display:inline-block;">Ports</a>
            </div>
            <div style="height: 14px;"></div>
            <div class="mh-mon-grid">
                <div class="mh-mon-card">
                    <h2 style="margin:0 0 10px 0;">Status</h2>
                    <div id="status"></div>
                </div>
                <div class="mh-mon-card">
                    <h2 style="margin:0 0 10px 0;">Audit</h2>
                    <div id="audit"></div>
                </div>
                <div class="mh-mon-card" style="grid-column: 1 / -1;">
                    <h2 style="margin:0 0 10px 0;">Recent Errors</h2>
                    <pre id="errors">Loading...</pre>
                </div>
                <div class="mh-mon-card" style="grid-column: 1 / -1;">
                    <h2 style="margin:0 0 10px 0;">Guard Scan</h2>
                    <pre id="scanOut">Not run</pre>
                </div>
            </div>
        </div>
    </section>
</main>
<?php
if (function_exists('renderGlobalFooter')) {
    renderGlobalFooter(['ftr_position' => 'bottom', 'ftr_auto_offset' => false]);
}
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (strpos($uri, '/pdf-tools') !== 0) {
    if (function_exists('renderGlobalWidgets')) {
        renderGlobalWidgets();
    } elseif (function_exists('renderGlobalStatusBar')) {
        renderGlobalStatusBar();
    }
}
if (function_exists('includeGlobalUIScripts')) {
    includeGlobalUIScripts();
}
?>
<script>
    const csrf = <?php echo json_encode($csrf); ?>;
    const statusEl = document.getElementById('status');
    const auditEl = document.getElementById('audit');
    const errorsEl = document.getElementById('errors');
    const scanOutEl = document.getElementById('scanOut');

    function row(k, v, cls) {
        const d = document.createElement('div');
        d.className = 'mh-mon-row';
        const a = document.createElement('div');
        a.className = 'mh-mon-k';
        a.textContent = k;
        const b = document.createElement('div');
        b.className = 'mh-mon-v ' + (cls || '');
        b.textContent = v;
        d.appendChild(a);
        d.appendChild(b);
        return d;
    }

    async function api(action) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf', csrf);
        const resp = await fetch('/gear/settings/dbmanager_monitor_api.php', { method: 'POST', body: fd });
        const data = await resp.json().catch(() => null);
        if (!data || !data.success) throw new Error((data && data.error) ? data.error : 'request_failed');
        return data;
    }

    async function refresh() {
        statusEl.innerHTML = '';
        auditEl.innerHTML = '';
        errorsEl.textContent = 'Loading...';
        const data = await api('status');
        const s = data.status;
        statusEl.appendChild(row('Active DB configs', String((s.db_configs && s.db_configs.active) || 0), (s.db_configs && s.db_configs.ok) ? 'mh-mon-ok' : 'mh-mon-bad'));
        statusEl.appendChild(row('Tenant mappings', String((s.tenant_contexts && s.tenant_contexts.with_db_mapping) || 0) + ' / ' + String((s.tenant_contexts && s.tenant_contexts.count) || 0), (s.tenant_contexts && s.tenant_contexts.ok) ? 'mh-mon-ok' : 'mh-mon-bad'));
        statusEl.appendChild(row('DB contexts valid', (s.db_contexts && s.db_contexts.ok) ? 'YES' : 'NO', (s.db_contexts && s.db_contexts.ok) ? 'mh-mon-ok' : 'mh-mon-bad'));
        statusEl.appendChild(row('db_configs.json', (s.db_configs && s.db_configs.path) ? s.db_configs.path : 'unknown', ''));
        statusEl.appendChild(row('database-context', (s.db_contexts && s.db_contexts.path) ? s.db_contexts.path : 'unknown', ''));

        const ops = s.ops || {};
        const mon = ops.monitoring || null;
        const bkp = ops.backup || null;

        function runnerLabel(x) {
            if (!x || !x.present) return { v: 'MISSING', cls: 'mh-mon-bad' };
            if (x.stale) return { v: 'STALE (' + String(x.age_s || 0) + 's)', cls: 'mh-mon-warn' };
            if (x.ok === true) return { v: 'OK (' + String(x.age_s || 0) + 's)', cls: 'mh-mon-ok' };
            if (x.ok === false) return { v: 'FAIL (' + String(x.age_s || 0) + 's)', cls: 'mh-mon-bad' };
            return { v: 'UNKNOWN', cls: 'mh-mon-warn' };
        }

        const monLab = runnerLabel(mon);
        const bkpLab = runnerLabel(bkp);
        statusEl.appendChild(row('Monitoring runner', monLab.v, monLab.cls));
        statusEl.appendChild(row('Backup runner', bkpLab.v, bkpLab.cls));

        const audit = (s.logs && s.logs.audit) ? s.logs.audit : null;
        auditEl.appendChild(row('Audit events', String((audit && audit.count) || 0), ''));
        auditEl.appendChild(row('Audit errors', String((audit && audit.errors) || 0), ((audit && audit.errors) > 0) ? 'mh-mon-warn' : 'mh-mon-ok'));
        auditEl.appendChild(row('Audit log', (audit && audit.path) ? audit.path : 'unknown', ''));

        const errs = (s.logs && s.logs.error_log && s.logs.error_log.lines) ? s.logs.error_log.lines : [];
        errorsEl.textContent = errs.length ? errs.join("\n") : 'No recent dbmanager-related lines in error.log';
    }

    async function scan() {
        scanOutEl.textContent = 'Running...';
        const data = await api('guard_scan');
        const out = {
            pdo_violations: (data.pdo || []).length,
            pool_violations: (data.pool || []).length,
            pdo: data.pdo || [],
            pool: data.pool || [],
        };
        scanOutEl.textContent = JSON.stringify(out, null, 2);
    }

    document.getElementById('refresh').addEventListener('click', () => refresh().catch(e => { errorsEl.textContent = String(e); }));
    document.getElementById('scan').addEventListener('click', () => scan().catch(e => { scanOutEl.textContent = String(e); }));
    refresh().catch(e => { errorsEl.textContent = String(e); });
</script>
</body>
</html>
