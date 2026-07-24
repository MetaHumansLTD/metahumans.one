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
    $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/records/stock-ledger.php';
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

function mh_control_records_render_stock_rows(array $ledger): string {
    ob_start();
    foreach ($ledger as $row) {
        $unitsOwned = (int)($row['units_owned'] ?? 0);
        $unitsPerShare = (int)($row['units_per_share'] ?? 400);
        if ($unitsPerShare < 1) $unitsPerShare = 1;
        $shareEq = $unitsOwned / $unitsPerShare;
        $classTotalUnits = isset($row['class_total_units']) ? (int)$row['class_total_units'] : 0;
        $pct = $classTotalUnits > 0 ? (($unitsOwned / $classTotalUnits) * 100.0) : null;
        $fullShares = (int)floor($unitsOwned / $unitsPerShare);
        $className = isset($row['class_name']) ? strtolower(trim((string)$row['class_name'])) : '';
        $isOrdinary = $className !== '' && (strpos($className, 'ordinary') !== false || strpos($className, 'common') !== false);
        $userType = isset($row['user_type']) && is_string($row['user_type']) && trim($row['user_type']) !== '' ? trim((string)$row['user_type']) : 'shareholder';
        $votes = 0;
        if ($isOrdinary) {
            if ($userType === 'founder') {
                $votes = $fullShares * max(0, (int)($row['ordinary_votes_founder'] ?? 1000));
            } elseif ($userType === 'mvi') {
                $votes = 0;
            } else {
                $votes = $fullShares * max(0, (int)($row['ordinary_votes_shareholder'] ?? 1));
            }
        }
        ?>
        <tr>
            <td><?php echo htmlspecialchars((string)($row['username'] ?? '')); ?></td>
            <td>
                <?php
                    $name = isset($row['real_name']) && is_string($row['real_name']) ? trim((string)$row['real_name']) : '';
                    if ($name === '') {
                        echo '<span class="muted">Incomplete profile</span>';
                    } else {
                        $u = isset($row['username']) ? (string)$row['username'] : '';
                        $href = '/control/user-manager.php?q=' . rawurlencode($u);
                        echo '<a class="btn secondary" href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($name, ENT_QUOTES) . '</a>';
                    }
                ?>
            </td>
            <td><?php echo htmlspecialchars($userType); ?></td>
            <td><?php echo htmlspecialchars((string)($row['class_name'] ?? '')); ?></td>
            <td><?php echo number_format($unitsOwned); ?></td>
            <td><?php echo number_format($shareEq, 6); ?></td>
            <td><?php echo $pct === null ? 'N/A' : number_format($pct, 2) . '%'; ?></td>
            <td><?php echo number_format($votes); ?></td>
            <td><a class="btn" href="/control/digital-equity-management.php?edit_user=<?php echo urlencode((string)($row['username'] ?? '')); ?>#allocateEquity" target="_blank" rel="noopener">Edit</a></td>
        </tr>
        <?php
    }
    if (empty($ledger)) {
        ?>
        <tr><td colspan="9" style="text-align:center; color:#aaa;">No equity issued yet.</td></tr>
        <?php
    }
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
        $where = "WHERE (
            l.username LIKE :q
            OR c.name LIKE :q
            OR COALESCE(p.user_type,'') LIKE :q
            OR CAST(l.units_owned AS CHAR) LIKE :q
            OR CAST(ROUND(l.units_owned / NULLIF(c.fractional_units_per_share, 0), 6) AS CHAR) LIKE :q
            OR CAST(ROUND(((l.units_owned / NULLIF(c.fractional_units_per_share, 0)) / NULLIF((ci.total_units / NULLIF(c.fractional_units_per_share, 0)), 0)) * 100), 2) AS CHAR) LIKE :q
            OR CAST((
                CASE
                    WHEN (LOWER(c.name) LIKE '%ordinary%' OR LOWER(c.name) LIKE '%common%') THEN
                        (FLOOR(l.units_owned / NULLIF(c.fractional_units_per_share, 0)) * (
                            CASE COALESCE(p.user_type, 'shareholder')
                                WHEN 'founder' THEN COALESCE(p.ordinary_votes_founder, 1000)
                                WHEN 'shareholder' THEN COALESCE(p.ordinary_votes_shareholder, 1)
                                ELSE 0
                            END
                        ))
                    ELSE 0
                END
            ) AS CHAR) LIKE :q
        )";
        $params[':q'] = '%' . $qSearch . '%';
    }

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM equity_ledger l JOIN equity_classes c ON l.class_id = c.id LEFT JOIN (SELECT class_id, COALESCE(SUM(units_owned),0) AS total_units FROM equity_ledger GROUP BY class_id) ci ON ci.class_id = c.id LEFT JOIN equity_user_profiles p ON p.username = l.username $where");
    $stmtCount->execute($params);
    $count = (int)$stmtCount->fetchColumn();

    $sql = "SELECT l.*, c.name as class_name, c.fractional_units_per_share AS units_per_share,
                ci.total_units AS class_total_units,
                p.user_type, p.ordinary_votes_shareholder, p.ordinary_votes_founder
            FROM equity_ledger l
            JOIN equity_classes c ON l.class_id = c.id
            LEFT JOIN (SELECT class_id, COALESCE(SUM(units_owned),0) AS total_units FROM equity_ledger GROUP BY class_id) ci ON ci.class_id = c.id
            LEFT JOIN equity_user_profiles p ON p.username = l.username
            $where
            ORDER BY l.username, c.name";
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
    $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($pdoBio instanceof PDO && !empty($ledger)) {
        $usernames = [];
        foreach ($ledger as $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u !== '') $usernames[$u] = true;
        }
        $usernames = array_keys($usernames);
        $map = [];
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
                if ($full !== '') $map[$u] = $full;
            }
        }
        foreach ($ledger as $i => $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u !== '' && isset($map[$u])) {
                $ledger[$i]['real_name'] = $map[$u];
            } else {
                $ledger[$i]['real_name'] = '';
            }
        }
    } elseif (!empty($ledger)) {
        foreach ($ledger as $i => $r) {
            $ledger[$i]['real_name'] = '';
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
            'tbody_html' => mh_control_records_render_stock_rows($ledger),
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
    <title>Stock Ledger | Meta Humans</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700&family=Rajdhani:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/templates/global-ui/default/theme.css?v=<?php echo time(); ?>">
    <style>
        :root { --primary: #00d4ff; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); --text-main: #e0e0e0; }
        html, body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .container { max-width: 1400px; margin: 0 auto; padding: 40px 20px; }
        h1 { font-family: 'Orbitron', sans-serif; color: var(--primary); margin: 0 0 10px; }
        .muted { color:#9aa; }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-top: 20px; }
        .toolbar { display:flex; gap: 12px; align-items:center; justify-content: space-between; flex-wrap: wrap; }
        .toolbar .left { flex: 1; min-width: 240px; }
        .toolbar .right { display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap: wrap; }
        .toolbar input, .toolbar select { background: rgba(30,30,30,0.95); border: 1px solid var(--border); color: #fff; padding: 10px 12px; border-radius: 6px; box-sizing: border-box; }
        .toolbar input { width: 100%; }
        .pager { display:flex; gap: 10px; align-items:center; justify-content:flex-end; margin-top: 12px; flex-wrap: wrap; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; }
        th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        .btn { background: transparent; color: var(--primary); border: 1px solid var(--primary); padding: 8px 10px; border-radius: 6px; text-decoration: none; display:inline-block; }
        .btn.secondary { background: transparent; color: var(--primary); }
    </style>
</head>
<body>
<?php renderGlobalHeader(); ?>
<div class="container">
    <h1>Stock Ledger (Ownership Record)</h1>
    <div class="muted">Official Record of Stockholder Identity</div>

    <div class="panel">
        <div class="toolbar">
            <div class="left">
                <input id="searchInput" type="text" placeholder="Search by stockholder, class, status…" value="<?php echo htmlspecialchars($q); ?>">
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
                    <th>Stockholder</th>
                    <th>Name</th>
                    <th>User Type</th>
                    <th>Class</th>
                    <th>Equity Coins</th>
                    <th>Equity</th>
                    <th>% of Class</th>
                    <th>Votes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="recordsTbody"><?php echo mh_control_records_render_stock_rows($ledger); ?></tbody>
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
