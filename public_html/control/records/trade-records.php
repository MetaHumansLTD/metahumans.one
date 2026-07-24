<?php
if (isset($_GET['api']) && is_string($_GET['api']) && trim($_GET['api']) !== '') {
    if (!defined('CUE_DISABLE_AUTO_UI')) {
        define('CUE_DISABLE_AUTO_UI', true);
    }
    if (!defined('CUE_LAYOUT_MANUAL')) {
        define('CUE_LAYOUT_MANUAL', true);
    }
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../../hub/equity/db.php';
require_once __DIR__ . '/../../templates/global-ui/functions.php';

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/records/trade-records.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

function mh_control_records_normalize_per_page(?string $raw): array {
    $raw = $raw !== null ? strtolower(trim($raw)) : '';
    if ($raw === 'all') {
        return ['raw' => 'all', 'limit' => null];
    }
    $n = (int)$raw;
    if ($n <= 0) {
        $n = 50;
    }
    if (!in_array($n, [10, 50, 100], true)) {
        $n = 50;
    }
    return ['raw' => (string)$n, 'limit' => $n];
}

function mh_control_records_render_trade_rows(array $transactions, array $nameMap, array $holdingsMap): string {
    ob_start();
    foreach ($transactions as $txn) {
        $h = isset($txn['txn_hash']) ? (string)$txn['txn_hash'] : '';
        $sender = isset($txn['sender']) && is_string($txn['sender']) && trim($txn['sender']) !== '' ? trim((string)$txn['sender']) : '';
        $recipient = isset($txn['recipient']) && is_string($txn['recipient']) ? (string)$txn['recipient'] : '';
        $senderName = $sender !== '' && isset($nameMap[$sender]) ? (string)$nameMap[$sender] : '';
        $recipientName = $recipient !== '' && isset($nameMap[$recipient]) ? (string)$nameMap[$recipient] : '';
        $classId = (int)($txn['class_id'] ?? 0);
        $owner = trim($recipient) !== '' ? trim($recipient) : $sender;
        $heldUnits = 0;
        $unitsPerShare = 400;
        if ($owner !== '' && $classId > 0) {
            $hk = $owner . '|' . $classId;
            if (isset($holdingsMap[$hk]) && is_array($holdingsMap[$hk])) {
                $heldUnits = (int)($holdingsMap[$hk]['units_owned'] ?? 0);
                $unitsPerShare = (int)($holdingsMap[$hk]['units_per_share'] ?? 400);
                if ($unitsPerShare < 1) $unitsPerShare = 1;
            }
        }
        $equityOwned = ($owner !== '' && $classId > 0) ? ($heldUnits / max(1, $unitsPerShare)) : null;
        ?>
        <tr>
            <td><?php echo htmlspecialchars((string)($txn['timestamp'] ?? '')); ?></td>
            <td style="text-transform: uppercase; font-size: 0.8rem; font-weight: bold; color: var(--primary);"><?php echo htmlspecialchars((string)($txn['txn_type'] ?? '')); ?></td>
            <td>
                <?php
                    if ($sender === '') {
                        echo '<i>System</i>';
                    } elseif ($senderName !== '') {
                        $href = '/control/user-manager.php?q=' . rawurlencode($sender);
                        echo '<a class="btn secondary" href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($senderName, ENT_QUOTES) . '</a>';
                    } else {
                        $href = '/control/user-manager.php?q=' . rawurlencode($sender);
                        echo '<a class="btn secondary" href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener"><span class="muted">Incomplete profile</span></a>';
                    }
                ?>
            </td>
            <td>
                <?php
                    if (trim($recipient) === '') {
                        echo '<span class="muted">N/A</span>';
                    } elseif ($recipientName !== '') {
                        $href = '/control/user-manager.php?q=' . rawurlencode($recipient);
                        echo '<a class="btn secondary" href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($recipientName, ENT_QUOTES) . '</a>';
                    } else {
                        $href = '/control/user-manager.php?q=' . rawurlencode($recipient);
                        echo '<a class="btn secondary" href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener"><span class="muted">Incomplete profile</span></a>';
                    }
                ?>
            </td>
            <td><?php echo htmlspecialchars((string)($txn['class_name'] ?? '')); ?></td>
            <td><?php echo number_format((int)($txn['units'] ?? 0)); ?></td>
            <td><?php echo $equityOwned === null ? '<span class="muted">N/A</span>' : number_format((float)$equityOwned, 6); ?></td>
            <td class="hash" title="<?php echo htmlspecialchars($h); ?>"><?php echo $h !== '' ? htmlspecialchars(substr($h, 0, 8) . '...' . substr($h, -8)) : ''; ?></td>
        </tr>
        <?php
    }
    if (empty($transactions)) {
        ?>
        <tr><td colspan="8" style="text-align:center; color:#aaa;">No transactions recorded.</td></tr>
        <?php
    }
    return (string)ob_get_clean();
}

function mh_control_records_render_pager(int $page, int $totalPages, string $q, string $perPageRaw): string {
    $page = max(1, $page);
    $totalPages = max(1, $totalPages);
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);

    $mk = function (int $p) use ($q, $perPageRaw): string {
        $params = ['page' => $p];
        if ($q !== '') $params['q'] = $q;
        if ($perPageRaw !== '') $params['per_page'] = $perPageRaw;
        return '?' . http_build_query($params);
    };

    $pages = [];
    $pages[] = 1;
    $pages[] = $totalPages;
    for ($i = $page - 2; $i <= $page + 2; $i++) {
        if ($i >= 1 && $i <= $totalPages) $pages[] = $i;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    ob_start();
    ?>
    <a class="btn secondary" href="<?php echo htmlspecialchars($mk($prev)); ?>">Prev</a>
    <?php
    $last = 0;
    foreach ($pages as $p) {
        if ($last > 0 && $p > $last + 1) {
            ?>
            <span class="muted">…</span>
            <?php
        }
        $isCurrent = $p === $page;
        ?>
        <a class="btn secondary" href="<?php echo htmlspecialchars($mk($p)); ?>" style="<?php echo $isCurrent ? 'background: var(--primary); color:#000;' : ''; ?>"><?php echo (int)$p; ?></a>
        <?php
        $last = $p;
    }
    ?>
    <a class="btn secondary" href="<?php echo htmlspecialchars($mk($next)); ?>">Next</a>
    <?php
    return (string)ob_get_clean();
}

try {
    $pdo = getEquityConnection();
    mh_equity_ensure_schema($pdo);
    $pdoBio = null;
    try {
        $pdoBio = database_getConnectionById('biometrics');
    } catch (Throwable $e) {
        $pdoBio = null;
    }

    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $qSearch = trim(str_replace([',', '$', '%'], '', $q));
    $perPage = mh_control_records_normalize_per_page(isset($_GET['per_page']) ? (string)$_GET['per_page'] : null);
    $limit = $perPage['limit'];
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    if ($limit === null) {
        $page = 1;
    }
    $offset = $limit !== null ? (($page - 1) * $limit) : 0;

    $where = '';
    $params = [];
    if ($qSearch !== '') {
        $where = "WHERE (CAST(t.id AS CHAR) LIKE :q OR t.txn_hash LIKE :q OR t.prev_hash LIKE :q OR COALESCE(t.sender,'System') LIKE :q OR COALESCE(t.recipient,'') LIKE :q OR t.txn_type LIKE :q OR COALESCE(t.timestamp,'') LIKE :q OR CAST(t.units AS CHAR) LIKE :q OR c.name LIKE :q)";
        $params[':q'] = '%' . $qSearch . '%';
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM equity_transactions t JOIN equity_classes c ON c.id = t.class_id $where");
    $stmtCount->execute($params);
    $count = (int)$stmtCount->fetchColumn();

    $sql = "SELECT t.*, c.name AS class_name FROM equity_transactions t JOIN equity_classes c ON c.id = t.class_id $where ORDER BY t.id DESC";
    if ($limit !== null) {
        $sql .= " LIMIT :limit OFFSET :offset";
    }
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    if ($limit !== null) {
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nameMap = [];
    if ($pdoBio instanceof PDO && !empty($transactions)) {
        $usernames = [];
        foreach ($transactions as $t) {
            $s = isset($t['sender']) && is_string($t['sender']) ? trim((string)$t['sender']) : '';
            $r = isset($t['recipient']) && is_string($t['recipient']) ? trim((string)$t['recipient']) : '';
            if ($s !== '') $usernames[$s] = true;
            if ($r !== '') $usernames[$r] = true;
        }
        $usernames = array_keys($usernames);
        if (!empty($usernames)) {
            $in = implode(',', array_fill(0, count($usernames), '?'));
            $st = $pdoBio->prepare("SELECT username, name, real_first_name, real_last_name FROM users WHERE username IN ($in)");
            $st->execute($usernames);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $ur) {
                $u = isset($ur['username']) ? trim((string)$ur['username']) : '';
                if ($u === '') continue;
                $fn = isset($ur['real_first_name']) ? trim((string)$ur['real_first_name']) : '';
                $ln = isset($ur['real_last_name']) ? trim((string)$ur['real_last_name']) : '';
                $n = isset($ur['name']) ? trim((string)$ur['name']) : '';
                $full = '';
                if ($fn !== '' && $ln !== '') {
                    $full = trim($fn . ' ' . $ln);
                } elseif ($n !== '' && $n !== $u) {
                    $full = $n;
                }
                if ($full !== '') $nameMap[$u] = $full;
            }
        }
    }

    $holdingsMap = [];
    if (!empty($transactions)) {
        $usernames = [];
        $classIds = [];
        foreach ($transactions as $t) {
            $cid = (int)($t['class_id'] ?? 0);
            if ($cid > 0) $classIds[$cid] = true;
            $r = isset($t['recipient']) && is_string($t['recipient']) ? trim((string)$t['recipient']) : '';
            $s = isset($t['sender']) && is_string($t['sender']) ? trim((string)$t['sender']) : '';
            if ($r !== '') {
                $usernames[$r] = true;
            } elseif ($s !== '') {
                $usernames[$s] = true;
            }
        }
        $usernames = array_keys($usernames);
        $classIds = array_keys($classIds);
        if (!empty($usernames) && !empty($classIds)) {
            $inU = implode(',', array_fill(0, count($usernames), '?'));
            $inC = implode(',', array_fill(0, count($classIds), '?'));
            $st = $pdo->prepare("SELECT l.username, l.class_id, l.units_owned, c.fractional_units_per_share AS units_per_share
                FROM equity_ledger l
                JOIN equity_classes c ON c.id = l.class_id
                WHERE l.username IN ($inU) AND l.class_id IN ($inC)");
            $st->execute(array_merge($usernames, $classIds));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $u = isset($r['username']) ? trim((string)$r['username']) : '';
                $cid = (int)($r['class_id'] ?? 0);
                if ($u === '' || $cid < 1) continue;
                $holdingsMap[$u . '|' . $cid] = [
                    'units_owned' => (int)($r['units_owned'] ?? 0),
                    'units_per_share' => (int)($r['units_per_share'] ?? 400),
                ];
            }
        }
    }

    $totalPages = 1;
    if ($limit !== null && $limit > 0) {
        $totalPages = max(1, (int)ceil($count / $limit));
    }
    $page = min($page, $totalPages);

    if (isset($_GET['api']) && (string)$_GET['api'] === 'search') {
        if (ob_get_level()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'q' => $q,
            'per_page' => $perPage['raw'],
            'count' => $count,
            'page' => $page,
            'total_pages' => $totalPages,
            'tbody_html' => mh_control_records_render_trade_rows($transactions, $nameMap, $holdingsMap),
            'pager_html' => mh_control_records_render_pager($page, $totalPages, $q, $perPage['raw']),
        ]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'System Error: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Trade Records | Meta Humans</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700&family=Rajdhani:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/templates/global-ui/default/theme.css?v=<?php echo time(); ?>">
    <style>
        :root { --primary: #00d4ff; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); --text-main: #e0e0e0; }
        html, body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; padding: 40px 20px; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
        th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        .hash { font-family: monospace; font-size: 0.8rem; color: #aaa; }
        .toolbar { display:flex; gap: 12px; align-items:center; justify-content: space-between; flex-wrap: wrap; }
        .toolbar .left { flex: 1; min-width: 240px; }
        .toolbar .right { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap: wrap; }
        .toolbar input, .toolbar select { background: rgba(30,30,30,0.95); border: 1px solid var(--border); color: #fff; padding: 10px 12px; border-radius: 6px; box-sizing: border-box; }
        .toolbar input { width: 100%; }
        .pager { display:flex; gap: 10px; align-items:center; justify-content:flex-end; margin-top: 12px; flex-wrap: wrap; }
        .btn { background: var(--primary); color: #000; font-weight: bold; cursor: pointer; text-transform: uppercase; border: 1px solid var(--primary); padding: 8px 10px; border-radius: 6px; text-decoration: none; }
        .btn.secondary { background: transparent; color: var(--primary); }
        .muted { color:#9aa; }
    </style>
</head>
<body>
<?php renderGlobalHeader(); ?>
<div class="container">
    <h1>Trade Records (Immutable Ledger)</h1>
    <div class="muted">Cryptographic chain of title transfers.</div>

    <div class="panel">
        <div class="toolbar">
            <div class="left">
                <input id="searchInput" type="text" placeholder="Search by user, class, hash, type…" value="<?php echo htmlspecialchars($q); ?>">
            </div>
            <div class="right">
                <span class="muted">Show</span>
                <select id="perPageSelect">
                    <option value="10" <?php echo $perPage['raw'] === '10' ? 'selected' : ''; ?>>10</option>
                    <option value="50" <?php echo $perPage['raw'] === '50' ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $perPage['raw'] === '100' ? 'selected' : ''; ?>>100</option>
                    <option value="all" <?php echo $perPage['raw'] === 'all' ? 'selected' : ''; ?>>all</option>
                </select>
                <span class="muted" id="recordsMeta">Rows: <?php echo number_format($count); ?> · Page <?php echo number_format($page); ?>/<?php echo number_format($totalPages); ?></span>
            </div>
        </div>
        <div class="pager" id="recordsPager"><?php echo mh_control_records_render_pager($page, $totalPages, $q, $perPage['raw']); ?></div>

        <table>
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Class</th>
                    <th>Qty (Coins)</th>
                    <th>Equity Owned</th>
                    <th>Hash (Proof)</th>
                </tr>
            </thead>
            <tbody id="recordsTbody"><?php echo mh_control_records_render_trade_rows($transactions, $nameMap ?? [], $holdingsMap ?? []); ?></tbody>
        </table>
    </div>
</div>
<?php renderGlobalFooter(); ?>
<script>
    (function () {
        const searchInput = document.getElementById('searchInput');
        const perPageSelect = document.getElementById('perPageSelect');
        const tbody = document.getElementById('recordsTbody');
        const pager = document.getElementById('recordsPager');
        const meta = document.getElementById('recordsMeta');
        let t = null;

        function localFilter() {
            if (!tbody || !searchInput) return;
            const q = String(searchInput.value || '').trim().toLowerCase();
            const rows = tbody.querySelectorAll('tr');
            if (q === '') {
                rows.forEach(function (tr) { tr.style.display = ''; });
                return;
            }
            rows.forEach(function (tr) {
                const text = String(tr.textContent || '').toLowerCase();
                tr.style.display = text.indexOf(q) !== -1 ? '' : 'none';
            });
        }

        function buildUrl(page) {
            const url = new URL(window.location.href);
            url.searchParams.set('api', 'search');
            url.searchParams.set('page', String(page || 1));
            url.searchParams.set('per_page', String(perPageSelect ? perPageSelect.value : '50'));
            const qRaw = searchInput ? String(searchInput.value || '') : '';
            const q = String(qRaw).trim();
            if (q !== '') url.searchParams.set('q', q); else url.searchParams.delete('q');
            return url;
        }

        async function load(page) {
            const url = buildUrl(page);
            const res = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return;
            let data = null;
            try {
                data = await res.json();
            } catch (e) {
                return;
            }
            if (data && data.error === 'unauthenticated') {
                window.location.href = '/auth/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                return;
            }
            if (!data || !data.success) return;
            if (tbody && typeof data.tbody_html === 'string') tbody.innerHTML = data.tbody_html;
            if (pager && typeof data.pager_html === 'string') pager.innerHTML = data.pager_html;
            if (meta) meta.textContent = 'Rows: ' + Number(data.count || 0).toLocaleString() + ' · Page ' + String(data.page || 1) + '/' + String(data.total_pages || 1);

            const u = new URL(window.location.href);
            u.searchParams.set('page', String(data.page || 1));
            u.searchParams.set('per_page', String(data.per_page || (perPageSelect ? perPageSelect.value : '50')));
            const q = searchInput ? String(searchInput.value || '').trim() : '';
            if (q !== '') u.searchParams.set('q', q); else u.searchParams.delete('q');
            u.searchParams.delete('api');
            window.history.replaceState({}, '', u.toString());
        }

        if (searchInput) {
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    load(1);
                }
            });
            searchInput.addEventListener('input', function () {
                localFilter();
                if (t) window.clearTimeout(t);
                t = window.setTimeout(function () {
                    load(1);
                }, 250);
            });
            searchInput.addEventListener('keyup', function () {
                localFilter();
                if (t) window.clearTimeout(t);
                t = window.setTimeout(function () {
                    load(1);
                }, 250);
            });
            searchInput.addEventListener('change', function () {
                localFilter();
                load(1);
            });
        }
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                load(1);
            });
        }
        if (pager) {
            pager.addEventListener('click', function (e) {
                const a = e.target instanceof HTMLElement ? e.target.closest('a') : null;
                if (!a) return;
                const href = a.getAttribute('href') || '';
                if (href.indexOf('?') !== 0) return;
                e.preventDefault();
                const p = new URL(href, window.location.origin).searchParams.get('page');
                load(parseInt(p || '1', 10) || 1);
            });
        }
    })();
</script>
</body>
</html>
