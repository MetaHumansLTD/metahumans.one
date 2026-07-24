<?php
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
    $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/records/equity-classes.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$message = '';
$csrf = isset($_SESSION['mh_equity_classes_csrf']) && is_string($_SESSION['mh_equity_classes_csrf']) ? (string)$_SESSION['mh_equity_classes_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_equity_classes_csrf'] = $csrf;
}

try {
    $pdo = getEquityConnection();
    mh_equity_ensure_schema($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        if (!hash_equals($csrf, $postCsrf)) {
            $message = 'Invalid request.';
        } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'save_class') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
            $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
            $totalShares = isset($_POST['total_shares']) ? (int)$_POST['total_shares'] : 0;
            $coinsPerShare = isset($_POST['coins_per_share']) ? (int)$_POST['coins_per_share'] : (int)($_POST['fractional_units_per_share'] ?? 400);
            $pricePerShare = isset($_POST['price_per_share']) ? (float)$_POST['price_per_share'] : 0.0;
            $pricePerUnit = isset($_POST['price_per_unit']) ? (float)$_POST['price_per_unit'] : 0.0;
            $strategy = isset($_POST['pricing_strategy']) ? trim((string)$_POST['pricing_strategy']) : 'fixed';
            $params = isset($_POST['pricing_params_json']) ? trim((string)$_POST['pricing_params_json']) : '';

            if ($coinsPerShare < 1) {
                $coinsPerShare = 400;
            }
            if ($pricePerShare <= 0 && $pricePerUnit > 0) {
                $pricePerShare = $pricePerUnit * $coinsPerShare;
            }
            if ($classId < 1 || $name === '') {
                $message = 'Invalid class update.';
            } else {
                $stmt = $pdo->prepare("UPDATE equity_classes SET name = ?, description = ?, total_shares = ?, fractional_units_per_share = ?, price_per_share = ?, pricing_strategy = ?, pricing_params_json = ? WHERE id = ?");
                $stmt->execute([$name, $description !== '' ? $description : null, max(0, $totalShares), $coinsPerShare, $pricePerShare, $strategy !== '' ? $strategy : 'fixed', $params !== '' ? $params : null, $classId]);
                $message = 'Saved.';
            }
        } elseif ($action === 'add_class') {
            $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
            $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
            $totalShares = isset($_POST['total_shares']) ? (int)$_POST['total_shares'] : 0;
            $coinsPerShare = isset($_POST['coins_per_share']) ? (int)$_POST['coins_per_share'] : (int)($_POST['fractional_units_per_share'] ?? 400);
            $pricePerShare = isset($_POST['price_per_share']) ? (float)$_POST['price_per_share'] : 0.0;
            $pricePerUnit = isset($_POST['price_per_unit']) ? (float)$_POST['price_per_unit'] : 0.0;
            $strategy = isset($_POST['pricing_strategy']) ? trim((string)$_POST['pricing_strategy']) : 'fixed';
            $params = isset($_POST['pricing_params_json']) ? trim((string)$_POST['pricing_params_json']) : '';

            if ($coinsPerShare < 1) {
                $coinsPerShare = 400;
            }
            if ($pricePerShare <= 0 && $pricePerUnit > 0) {
                $pricePerShare = $pricePerUnit * $coinsPerShare;
            }
            if ($name === '') {
                $message = 'Invalid new class.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO equity_classes (name, description, total_shares, fractional_units_per_share, price_per_share, pricing_strategy, pricing_params_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description !== '' ? $description : null, max(0, $totalShares), $coinsPerShare, $pricePerShare, $strategy !== '' ? $strategy : 'fixed', $params !== '' ? $params : null]);
                $message = 'Created.';
            }
        } elseif ($action === 'delete_class') {
            $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
            if ($classId < 1) {
                $message = 'Invalid class.';
            } else {
                $blocked = false;
                $checks = [
                    ['table' => 'equity_ledger', 'col' => 'class_id'],
                    ['table' => 'equity_market', 'col' => 'class_id'],
                    ['table' => 'equity_primary_orders', 'col' => 'class_id'],
                    ['table' => 'equity_share_rights', 'col' => 'class_id'],
                    ['table' => 'equity_share_rights_map', 'col' => 'class_id'],
                ];
                foreach ($checks as $c) {
                    try {
                        $stmt = $pdo->prepare("SELECT 1 FROM `{$c['table']}` WHERE `{$c['col']}` = ? LIMIT 1");
                        $stmt->execute([$classId]);
                        if ($stmt->fetchColumn() !== false) {
                            $blocked = true;
                            break;
                        }
                    } catch (Throwable $e) {}
                }
                if ($blocked) {
                    $message = 'Cannot delete: class has records.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM equity_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$classId]);
                    $message = $stmt->rowCount() > 0 ? 'Deleted.' : 'Not found.';
                }
            }
        }
        }
    }

    $classes = $pdo->query("SELECT * FROM equity_classes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
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
    <title>Company Equity Classes | Meta Humans</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700&family=Rajdhani:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/templates/global-ui/default/theme.css?v=<?php echo time(); ?>">
    <style>
        :root { --primary: #00d4ff; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); --text-main: #e0e0e0; }
        html, body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .equity-classes-wrap { max-width: 1600px; margin: 0 auto; padding: 40px 20px; width: 100%; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); margin: 0 0 10px; }
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-top: 20px; width: 100%; }
        .alert { background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: var(--primary); padding: 15px; margin-top: 20px; border-radius: 4px; }
        label { display:block; margin: 12px 0 6px; font-weight: 600; }
        select, input, button { background: rgba(30,30,30,0.95); border: 1px solid var(--border); color: #fff; padding: 11px 12px; width: 100%; border-radius: 6px; box-sizing: border-box; }
        option { background: #222; color: #fff; }
        button { margin-top: 12px; background: var(--primary); color: #000; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        button:hover { opacity: 0.9; }
        .btn-secondary { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .dgcl-notice { font-size: 0.85rem; color: #9aa; margin-top: 8px; }
        #loaderOverlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.55); z-index: 9999; }
        #loaderOverlay .box { background: rgba(30,30,30,0.95); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; color: #fff; font-family: 'Orbitron', sans-serif; }
        @media (max-width: 600px) { .equity-classes-wrap { padding: 24px 14px; } }
    </style>
</head>
<body>
<?php renderGlobalHeader(); ?>
<div class="equity-classes-wrap">
    <h1>Company Equity Classes</h1>
    <div class="dgcl-notice" id="autosaveStatus">Autosave: idle</div>
    <div id="noticeWidget" class="alert" style="display:none;"></div>
    <?php if ($message !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="panel">
        <?php foreach ($classes as $c): ?>
            <form method="POST" class="classForm" data-class-id="<?php echo (int)($c['id'] ?? 0); ?>" style="border: 1px solid rgba(0, 212, 255, 0.15); border-radius: 10px; padding: 12px; margin-bottom: 12px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="save_class">
                <input type="hidden" name="class_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                <label>Name</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars((string)($c['name'] ?? '')); ?>" required>
                <label>Description</label>
                <input type="text" name="description" value="<?php echo htmlspecialchars((string)($c['description'] ?? '')); ?>">
                <label>Total Shares (Cap)</label>
                <input type="number" name="total_shares" min="0" step="1" value="<?php echo (int)($c['total_shares'] ?? 0); ?>">
                <label>Coins Per Share</label>
                <input type="number" name="coins_per_share" min="1" step="1" value="<?php echo (int)($c['fractional_units_per_share'] ?? 400); ?>">
                <label>Reference Price / Share</label>
                <input type="number" name="price_per_share" min="0" step="0.01" value="<?php echo number_format((float)($c['price_per_share'] ?? 0), 2, '.', ''); ?>">
                <label>Reference Price / Coin</label>
                <input type="number" name="price_per_unit" min="0" step="0.01" value="<?php echo number_format(((float)($c['price_per_share'] ?? 0)) / max(1, (float)($c['fractional_units_per_share'] ?? 400)), 2, '.', ''); ?>">
                <label>Pricing Strategy</label>
                <?php $s = (string)($c['pricing_strategy'] ?? 'fixed'); ?>
                <select name="pricing_strategy">
                    <option value="fixed" <?php echo $s === 'fixed' ? 'selected' : ''; ?>>fixed</option>
                    <option value="tiered" <?php echo $s === 'tiered' ? 'selected' : ''; ?>>tiered</option>
                    <option value="bonding_curve_linear" <?php echo $s === 'bonding_curve_linear' ? 'selected' : ''; ?>>bonding_curve_linear</option>
                </select>
                <label>Pricing Params (JSON)</label>
                <input type="text" name="pricing_params_json" value="<?php echo htmlspecialchars((string)($c['pricing_params_json'] ?? '')); ?>" placeholder="{}">
                <button type="submit">Save</button>
            </form>
            <form method="POST" style="margin: -2px 0 12px 0; padding: 0 12px 12px 12px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="delete_class">
                <input type="hidden" name="class_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                <button type="submit" class="btn-secondary" style="border-color: rgba(239,68,68,0.6); color: rgba(239,68,68,0.95); width:auto;">Delete Class</button>
            </form>
        <?php endforeach; ?>

        <form method="POST" class="classForm" data-class-id="new" style="border: 1px dashed rgba(0, 212, 255, 0.25); border-radius: 10px; padding: 12px;">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="action" value="add_class">
            <label>New Class Name</label>
            <input type="text" name="name" required>
            <label>Description</label>
            <input type="text" name="description">
            <label>Total Shares (Cap)</label>
            <input type="number" name="total_shares" min="0" step="1" value="0">
            <label>Coins Per Share</label>
            <input type="number" name="coins_per_share" min="1" step="1" value="400">
            <label>Reference Price / Share</label>
            <input type="number" name="price_per_share" min="0" step="0.01" value="15.00">
            <label>Reference Price / Coin</label>
            <input type="number" name="price_per_unit" min="0" step="0.01" value="0.04">
            <label>Pricing Strategy</label>
            <select name="pricing_strategy">
                <option value="fixed" selected>fixed</option>
                <option value="tiered">tiered</option>
                <option value="bonding_curve_linear">bonding_curve_linear</option>
            </select>
            <label>Pricing Params (JSON)</label>
            <input type="text" name="pricing_params_json" placeholder="{}">
            <button type="submit">Add Class</button>
        </form>
    </div>
</div>
<?php renderGlobalFooter(); ?>
<div id="loaderOverlay"><div class="box">Loading…</div></div>
<script>
    (function () {
        const autosaveStatus = document.getElementById('autosaveStatus');
        const loaderOverlay = document.getElementById('loaderOverlay');
        const noticeWidget = document.getElementById('noticeWidget');
        const classForms = document.querySelectorAll('form.classForm');
        const pending = new Map();

        function setAutosave(text) {
            if (autosaveStatus) autosaveStatus.textContent = text;
        }

        function setNotice(text) {
            if (!noticeWidget) return;
            if (!text || String(text).trim() === '') {
                noticeWidget.style.display = 'none';
                noticeWidget.textContent = '';
                return;
            }
            noticeWidget.textContent = String(text);
            noticeWidget.style.display = 'block';
        }

        function setLoading(isLoading, text) {
            if (loaderOverlay) loaderOverlay.style.display = isLoading ? 'flex' : 'none';
            if (isLoading && text) setNotice(text);
        }

        async function autosaveForm(form) {
            const fd = new FormData(form);
            setAutosave('Autosave: saving...');
            setLoading(true, 'Saving equity class…');
            const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
            if (!res.ok) {
                setAutosave('Autosave: error');
                setLoading(false);
                setNotice('Error saving. Please retry.');
                return;
            }
            setAutosave('Autosave: saved');
            setLoading(false);
            setNotice('Saved.');
            setTimeout(function () { setNotice(''); }, 2200);
        }

        classForms.forEach(function (form) {
            if (form.getAttribute('data-class-id') === 'new') return;
            let syncing = false;

            function syncPrices(changedName) {
                if (syncing) return;
                const coinsEl = form.querySelector('input[name="coins_per_share"]');
                const shareEl = form.querySelector('input[name="price_per_share"]');
                const unitEl = form.querySelector('input[name="price_per_unit"]');
                if (!coinsEl || !shareEl || !unitEl) return;
                const cps = parseInt(String(coinsEl.value || '0'), 10) || 0;
                if (cps < 1) return;
                const ps = parseFloat(String(shareEl.value || '0')) || 0;
                const pu = parseFloat(String(unitEl.value || '0')) || 0;
                syncing = true;
                try {
                    if (changedName === 'price_per_unit') {
                        if (pu > 0) shareEl.value = String((pu * cps).toFixed(2));
                    } else {
                        if (ps > 0) unitEl.value = String((ps / cps).toFixed(2));
                    }
                } finally {
                    syncing = false;
                }
            }

            const coinsEl = form.querySelector('input[name="coins_per_share"]');
            const shareEl = form.querySelector('input[name="price_per_share"]');
            const unitEl = form.querySelector('input[name="price_per_unit"]');
            if (coinsEl) coinsEl.addEventListener('input', function () { syncPrices('coins_per_share'); });
            if (shareEl) shareEl.addEventListener('input', function () { syncPrices('price_per_share'); });
            if (unitEl) unitEl.addEventListener('input', function () { syncPrices('price_per_unit'); });

            const handler = function () {
                const key = form.getAttribute('data-class-id') || '';
                if (pending.has(key)) {
                    clearTimeout(pending.get(key));
                }
                pending.set(key, setTimeout(function () {
                    autosaveForm(form);
                }, 900));
                setAutosave('Autosave: pending...');
                setNotice('Pending changes…');
            };
            form.querySelectorAll('input, select').forEach(function (el) {
                el.addEventListener('input', handler);
                el.addEventListener('change', handler);
            });
        });
    })();
</script>
</body>
</html>
